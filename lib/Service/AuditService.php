<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\AuditLogMapper;
use Psr\Log\LoggerInterface;

/**
 * AuditService — single write entry point for the TeamHub audit log.
 *
 * Why a service wrapper around the mapper?
 *   - Keeps logging non-fatal: a failed audit insert must NEVER bubble up and
 *     break the user-facing action (creating a team, removing a member, etc).
 *     Failures are logged via the NC LoggerInterface and swallowed.
 *   - Centralises metadata-size capping (see capStringValues).
 *   - Provides a single grep target — "AuditService::log" — so it's easy to
 *     audit which actions are covered.
 *
 * Event types are documented in HANDOFF.md and CHANGELOG.md.
 *
 * IMMUTABILITY
 * ------------
 * The mapper exposes only insert + bulk purge. There is no path through this
 * service to update or delete an individual row.
 */
class AuditService {

    /**
     * Per-field length cap for string values inside the metadata blob.
     * Prevents an audit row from bloating when an action includes long text
     * (e.g. a multi-paragraph description in a `team.config_changed` diff).
     */
    public const METADATA_VALUE_CAP = 500;

    public function __construct(
        private AuditLogMapper $mapper,
        private LoggerInterface $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // Write path.
    // -------------------------------------------------------------------------

    /**
     * Append a single audit event.
     *
     * Failures are caught and logged — never thrown — so an audit-write problem
     * cannot cascade into a user-facing error.
     *
     * @param string      $teamId      Team unique_id.
     * @param string      $eventType   Namespaced event string. See HANDOFF.md.
     * @param string|null $actorUid    Caller UID, or null for system actions.
     * @param string|null $targetType  'team' | 'member' | 'file' | 'share' | 'invite' | 'app'
     * @param string|null $targetId    UID, fileid, shareid, app id, etc.
     * @param array|null  $metadata    Optional payload.
     */
    public function log(
        string $teamId,
        string $eventType,
        ?string $actorUid,
        ?string $targetType = null,
        ?string $targetId = null,
        ?array $metadata = null,
    ): void {
        try {
            $sanitized = $metadata !== null ? $this->capStringValues($metadata) : null;
            $this->mapper->insert($teamId, $eventType, $actorUid, $targetType, $targetId, $sanitized);
        } catch (\Throwable $e) {
            // Never propagate — audit failure must not break the user-visible action.
            $this->logger->warning('[TeamHub][AuditService] Failed to write audit event', [
                'teamId'    => $teamId,
                'eventType' => $eventType,
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Bulk purge — wired to the (later) hourly background job.
    // -------------------------------------------------------------------------

    /**
     * Delete all rows older than the given retention horizon (in days).
     * Returns the number of rows removed.
     *
     * @param int $retentionDays  Minimum 1.
     */
    public function purgeOlderThan(int $retentionDays): int {
        if ($retentionDays < 1) {
            $retentionDays = 1;
        }
        $boundaryTs = time() - ($retentionDays * 86400);
        return $this->mapper->purgeOlderThan($boundaryTs);
    }

    // -------------------------------------------------------------------------
    // Metadata helpers.
    // -------------------------------------------------------------------------

    /**
     * Walk the metadata array and trim every string value to METADATA_VALUE_CAP
     * bytes. Recursive — handles nested diff payloads.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function capStringValues(array $data): array {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                if (strlen($value) > self::METADATA_VALUE_CAP) {
                    $data[$key] = substr($value, 0, self::METADATA_VALUE_CAP) . '…[truncated]';
                }
            } elseif (is_array($value)) {
                $data[$key] = $this->capStringValues($value);
            }
            // leave ints, bools, nulls, floats untouched
        }
        return $data;
    }

    // -------------------------------------------------------------------------
    // Diff helper — used by callers that want to log a `team.config_changed`
    // event with an old/new payload.
    // -------------------------------------------------------------------------

    /**
     * Build a diff payload for a 'changed' event:
     *   ['changed' => ['field' => ['old' => ..., 'new' => ...], ...]]
     *
     * Fields whose old and new values are identical are excluded from the diff.
     * Returns null when nothing actually changed — caller should skip logging
     * in that case.
     *
     * @param array<string,mixed> $oldValues
     * @param array<string,mixed> $newValues
     * @return array<string,mixed>|null
     */
    public function buildDiff(array $oldValues, array $newValues): ?array {
        $changed = [];
        $allKeys = array_unique(array_merge(array_keys($oldValues), array_keys($newValues)));

        foreach ($allKeys as $key) {
            $old = $oldValues[$key] ?? null;
            $new = $newValues[$key] ?? null;
            if ($old === $new) {
                continue;
            }
            $changed[$key] = ['old' => $old, 'new' => $new];
        }

        if (empty($changed)) {
            return null;
        }

        return ['changed' => $changed];
    }
}
