<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\AuditAnchorMapper;
use OCA\TeamHub\Db\AuditLogMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Util\AuditHash;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Is the audit trail still what it was? (v4.13.17, DESIGN §2.191)
 *
 * Justin asked for an append-only audit trail. An application cannot make a
 * table append-only — whoever reaches the database can write it, and a trigger
 * is one `DROP TRIGGER` away from gone. What an application *can* do is make
 * every change provable, so this makes the trail **tamper-evident**:
 *
 *   - every row carries a keyed digest of its own content, written at insert
 *     ({@see AuditLogMapper}, {@see AuditHash});
 *   - an hourly pass anchors each new range of row ids: the digests of the
 *     range, in id order, plus the previous anchor's chain digest;
 *   - {@see verify()} recomputes both and names the first anchor that no longer
 *     reconciles, with the range it covers.
 *
 * An edited row breaks its own digest. A deleted row breaks its anchor's count
 * and digest. An anchor rewritten to cover the change breaks every later
 * anchor, because each one hashes the one before it. Forging the lot needs the
 * signing key, which is derived from `config.php`'s `secret` and is therefore
 * not in the database — the whole point of keying it.
 *
 * ## The two honest limits, reported rather than buried
 *
 * **The tail is unanchored.** Rows written since the last hourly pass are
 * hashed but not yet chained, so a deletion inside that window leaves no
 * evidence. `verify()` reports `unanchoredRows` so the window is a number on
 * screen rather than a footnote. Anchoring on every insert would mean reading
 * the chain tip inside every audited action — a write lock on the busiest
 * table in the app, to shorten a one-hour window.
 *
 * **Rows written before v4.13.17 have no digest.** They are counted
 * (`rowsWithoutHash`) and never claimed to be verified. Backfilling would mean
 * hashing rows whose integrity nobody can vouch for, which is worse than
 * saying so.
 *
 * ## Retention and the chain
 *
 * The purge deletes rows on purpose, which is indistinguishable from an attack
 * unless it says so. {@see recordPurge()} writes a `purge` anchor first — the
 * boundary, how many rows went, the id range — and only then are the anchors
 * whose ranges it emptied removed. A `purge` anchor is never deleted, so the
 * record of what the instance removed, and when, outlives the rows themselves.
 */
class AuditIntegrityService {

    /** How many rows one `verify()` call will re-hash. */
    public const VERIFY_ROW_BUDGET = 50_000;

    /** Verdicts. `ok` includes "nothing to verify yet". */
    public const OK            = 'ok';
    public const BROKEN        = 'broken';
    public const UNKEYED       = 'unkeyed';
    public const KEY_ROTATED   = 'key_rotated';
    public const NOT_ANCHORED  = 'not_anchored';
    public const TRUNCATED     = 'truncated';

    public function __construct(
        private AuditLogMapper    $rows,
        private AuditAnchorMapper $anchors,
        private IConfig           $config,
        private IUserSession      $userSession,
        private IGroupManager     $groupManager,
        private LoggerInterface   $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // Writing the chain — the hourly job's half
    // -------------------------------------------------------------------------

    /**
     * Anchor every row appended since the last anchor.
     *
     * Ungated: the caller is `AuditMirrorJob`, not a person. Idempotent in the
     * sense that matters — a pass with no new rows writes nothing, so a job
     * that runs twice in an hour does not fill the table with empty anchors.
     *
     * @return array{anchored: bool, fromId: int, toId: int, rows: int}
     */
    public function anchor(): array {
        $key = AuditHash::keyFrom($this->config);
        if (!AuditHash::isAvailable($key)) {
            // No key, no chain. Logged once per pass rather than silently
            // skipped: an instance in this state has no tamper evidence and
            // somebody should be able to find out why from the log.
            $this->logger->warning('[TeamHub][AuditIntegrityService] No instance secret — the audit trail cannot be anchored', [
                'app' => Application::APP_ID,
            ]);
            return ['anchored' => false, 'fromId' => 0, 'toId' => 0, 'rows' => 0];
        }

        $latest = $this->anchors->latest();
        $fromId = $latest === null ? max(1, $this->rows->minId()) : $latest['toId'] + 1;
        $toId   = $this->rows->maxId();

        if ($toId < $fromId) {
            return ['anchored' => false, 'fromId' => $fromId, 'toId' => $toId, 'rows' => 0];
        }

        $rows      = $this->rows->rowsForVerification($fromId, $toId);
        $rowHashes = array_map(static fn (array $r): string => (string)($r['row_hash'] ?? ''), $rows);

        $rowsHash  = AuditHash::rowsHash($key, $fromId, $toId, $rowHashes);
        $chainHash = AuditHash::chainHash($key, $latest === null ? '' : $latest['chainHash'], $rowsHash);

        $this->anchors->insert(
            $fromId,
            $toId,
            count($rows),
            $rowsHash,
            $chainHash,
            AuditAnchorMapper::KIND_SEGMENT,
            AuditHash::fingerprint($key),
            time(),
        );

        return ['anchored' => true, 'fromId' => $fromId, 'toId' => $toId, 'rows' => count($rows)];
    }

    /**
     * Record a retention purge in the chain, replacing the anchors it emptied.
     *
     * Called by the retention job **after** its delete, with what the delete
     * actually did.
     *
     * ## Why the anchors go first and the record second
     *
     * A retention boundary is a timestamp; an anchor is an id range. The
     * boundary therefore lands *inside* a range almost every time, and a
     * half-emptied anchor can never reconcile again. So every segment anchor
     * overlapping the purged region is dropped and one `purge` anchor takes
     * their place, chained from whatever anchor survives.
     *
     * The order is forced by the chain: an anchor hashes the previous anchor's
     * chain digest, so the purge record has to be computed *after* the
     * deletions, against the anchor that will still be there. Appending first
     * and deleting after would leave the purge anchor chained to an anchor that
     * no longer exists — a `chain_link` break on every instance that ever
     * purged. A crash between the two steps loses the record of this one purge
     * and leaves a chain that still verifies, which is the better failure.
     */
    public function recordPurge(int $boundaryTs, int $deletedRows): void {
        $key = AuditHash::keyFrom($this->config);
        if (!AuditHash::isAvailable($key) || $deletedRows < 1) {
            return;
        }

        // The lowest surviving row id; everything below it is what went. 0 when
        // the purge emptied the table, in which case the whole id space so far
        // is purged and `maxId()` names its top.
        $survivingFrom = $this->rows->minId();
        $purgedTo      = $survivingFrom > 0 ? $survivingFrom - 1 : $this->rows->maxId();

        $dropped = $this->anchors->purgeOverlapping($purgedTo);
        $latest  = $this->anchors->latest();

        $rowsHash  = AuditHash::rowsHash($key, 0, $purgedTo, [
            'purge', (string)$boundaryTs, (string)$deletedRows,
        ]);
        $chainHash = AuditHash::chainHash($key, $latest === null ? '' : $latest['chainHash'], $rowsHash);

        $this->anchors->insert(
            0,
            $purgedTo,
            $deletedRows,
            $rowsHash,
            $chainHash,
            AuditAnchorMapper::KIND_PURGE,
            AuditHash::fingerprint($key),
            time(),
        );

        $this->logger->info('[TeamHub][AuditIntegrityService] Retention purge recorded in the audit chain', [
            'boundary_ts'      => $boundaryTs,
            'deleted_rows'     => $deletedRows,
            'anchors_dropped'  => $dropped,
            'app'              => Application::APP_ID,
        ]);
    }

    // -------------------------------------------------------------------------
    // Reading the chain — the Compliance tab's half
    // -------------------------------------------------------------------------

    /**
     * Recompute the whole chain and report.
     *
     * @return array{
     *     verdict: string,
     *     anchors: int,
     *     verifiedAnchors: int,
     *     verifiedRows: int,
     *     unanchoredRows: int,
     *     rowsWithoutHash: int,
     *     firstBreak: array{anchorId: int, fromId: int, toId: int, reason: string}|null,
     *     lastAnchoredAt: int|null,
     *     keyFingerprint: string,
     * }
     */
    public function verify(): array {
        $this->requireNcAdmin();

        $key         = AuditHash::keyFrom($this->config);
        $fingerprint = AuditHash::fingerprint($key);
        $anchors     = $this->anchors->all();
        $maxId       = $this->rows->maxId();

        $report = [
            'verdict'         => self::OK,
            'anchors'         => count($anchors),
            'verifiedAnchors' => 0,
            'verifiedRows'    => 0,
            'unanchoredRows'  => 0,
            'rowsWithoutHash' => $this->rows->countWithoutHash(),
            'firstBreak'      => null,
            'lastAnchoredAt'  => null,
            'keyFingerprint'  => $fingerprint,
        ];

        if (!AuditHash::isAvailable($key)) {
            $report['verdict'] = self::UNKEYED;
            return $report;
        }

        if ($anchors === []) {
            $report['verdict']        = self::NOT_ANCHORED;
            $report['unanchoredRows'] = $maxId > 0 ? count($this->rows->rowsForVerification(1, $maxId)) : 0;
            return $report;
        }

        $previousChain = '';
        $budget        = self::VERIFY_ROW_BUDGET;
        $lastTo        = 0;

        foreach ($anchors as $anchor) {
            $lastTo = max($lastTo, $anchor['toId']);
            $report['lastAnchoredAt'] = $anchor['createdAt'];

            if ($anchor['keyFingerprint'] !== '' && $anchor['keyFingerprint'] !== $fingerprint) {
                // Signed under a key this instance no longer has. Not a break:
                // the chain cannot be followed from here, and saying
                // "tampered" would be a false accusation against whoever
                // rotated the secret.
                $report['verdict']    = self::KEY_ROTATED;
                $report['firstBreak'] = [
                    'anchorId' => $anchor['id'],
                    'fromId'   => $anchor['fromId'],
                    'toId'     => $anchor['toId'],
                    'reason'   => 'key_fingerprint',
                ];
                return $report;
            }

            if ($anchor['kind'] === AuditAnchorMapper::KIND_PURGE) {
                // A purge anchor stands for rows that are deliberately gone,
                // so there is nothing left to re-hash. Its boundary timestamp
                // and row count are not recoverable from the row table either,
                // so its own `rows_hash` is taken as given and only its chain
                // link is verified. **This is the one place the chain trusts a
                // stored value**, and it is why a `purge` anchor can only ever
                // be appended by the retention job and never removed: rewriting
                // one would not be caught here, but it would break every later
                // anchor's link, which is.
                $rowsHash = $anchor['rowsHash'];
            } else {
                if ($budget <= 0) {
                    $report['verdict'] = self::TRUNCATED;
                    return $report;
                }

                $rows   = $this->rows->rowsForVerification($anchor['fromId'], $anchor['toId']);
                $budget -= count($rows);

                $rowHashes = [];
                foreach ($rows as $row) {
                    $expected = AuditHash::rowHash(
                        $key,
                        (string)$row['team_id'],
                        (string)$row['event_type'],
                        $row['actor_uid'] === null ? null : (string)$row['actor_uid'],
                        $row['target_type'] === null ? null : (string)$row['target_type'],
                        $row['target_id'] === null ? null : (string)$row['target_id'],
                        $row['metadata'] === null ? null : (string)$row['metadata'],
                        (int)$row['created_at'],
                    );
                    $stored = (string)($row['row_hash'] ?? '');

                    // A row with no digest is unverifiable, not broken: it
                    // predates the feature. It still takes part in the range
                    // digest as '' — which is what was hashed when the anchor
                    // was written, so the range still reconciles.
                    if ($stored !== '' && $expected !== null && !hash_equals($stored, $expected)) {
                        $report['verdict']    = self::BROKEN;
                        $report['firstBreak'] = [
                            'anchorId' => $anchor['id'],
                            'fromId'   => $anchor['fromId'],
                            'toId'     => $anchor['toId'],
                            'reason'   => 'row_content',
                        ];
                        return $report;
                    }

                    $rowHashes[] = $stored;
                }

                if (count($rows) !== $anchor['rowCount']) {
                    $report['verdict']    = self::BROKEN;
                    $report['firstBreak'] = [
                        'anchorId' => $anchor['id'],
                        'fromId'   => $anchor['fromId'],
                        'toId'     => $anchor['toId'],
                        'reason'   => 'row_count',
                    ];
                    return $report;
                }

                $rowsHash = AuditHash::rowsHash($key, $anchor['fromId'], $anchor['toId'], $rowHashes);
                if (!hash_equals($anchor['rowsHash'], $rowsHash)) {
                    $report['verdict']    = self::BROKEN;
                    $report['firstBreak'] = [
                        'anchorId' => $anchor['id'],
                        'fromId'   => $anchor['fromId'],
                        'toId'     => $anchor['toId'],
                        'reason'   => 'range_digest',
                    ];
                    return $report;
                }

                $report['verifiedRows'] += count($rows);
            }

            $chainHash = AuditHash::chainHash($key, $previousChain, $rowsHash);
            if (!hash_equals($anchor['chainHash'], $chainHash)) {
                $report['verdict']    = self::BROKEN;
                $report['firstBreak'] = [
                    'anchorId' => $anchor['id'],
                    'fromId'   => $anchor['fromId'],
                    'toId'     => $anchor['toId'],
                    'reason'   => 'chain_link',
                ];
                return $report;
            }

            $previousChain = $anchor['chainHash'];
            $report['verifiedAnchors']++;
        }

        if ($maxId > $lastTo) {
            $report['unanchoredRows'] = count($this->rows->rowsForVerification($lastTo + 1, $maxId));
        }

        return $report;
    }

    /**
     * The same verdict without the re-hashing, for the Compliance tab's
     * summary row: whether a chain exists, how far behind it is, and when it
     * last ran. Deliberately cheap — the tab loads five checks at once and the
     * full walk is a button.
     *
     * @return array{anchors: int, lastAnchoredAt: int|null, unanchoredRows: int, rowsWithoutHash: int, keyed: bool}
     */
    public function summary(): array {
        $this->requireNcAdmin();

        $latest = $this->anchors->latest();
        $maxId  = $this->rows->maxId();
        $lastTo = $latest === null ? 0 : $latest['toId'];

        return [
            'anchors'         => $this->anchors->count(),
            'lastAnchoredAt'  => $latest === null ? null : $latest['createdAt'],
            'unanchoredRows'  => $maxId > $lastTo ? $maxId - $lastTo : 0,
            'rowsWithoutHash' => $this->rows->countWithoutHash(),
            'keyed'           => AuditHash::isAvailable(AuditHash::keyFrom($this->config)),
        ];
    }

    private function requireNcAdmin(): string {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new AccessDeniedException('Not authenticated.');
        }
        if (!$this->groupManager->isAdmin($user->getUID())) {
            throw new AccessDeniedException('Nextcloud administrator privilege required.');
        }

        return $user->getUID();
    }
}
