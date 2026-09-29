<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * WorkflowLedgerService — where a My Work workflow keeps its state (v4.10.2).
 *
 * My Work is TeamHub's service layer for anything that passes between
 * people in different roles — a team admin asking a Nextcloud administrator
 * for something, an administrator handing a task to a team owner
 * (`.claude/skills/mywork-workflows`). Each such workflow is small, bounded
 * (one open instance per team per kind) and short-lived, so its state is one
 * JSON document per kind in app config, keyed by team: no table, no
 * migration, nothing to purge but closed entries.
 *
 * Kinds are strings the owning service chooses (`teamspace_quota`); the
 * entries are whatever that service stores. This class only reads, writes
 * and removes them atomically enough for the traffic they see — a decision a
 * person clicks once, not a queue.
 *
 * When a workflow outgrows this (thousands of entries, history that must be
 * kept), it moves to a table; nothing in the providers changes, because they
 * only ever talk to the owning service.
 */
class WorkflowLedgerService {

    private const KEY_PREFIX = 'workflow_';

    public function __construct(
        private IConfig         $config,
        private LoggerInterface $logger,
    ) {}

    /**
     * Every entry of a kind, keyed by team id.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(string $kind): array {
        $raw = $this->config->getAppValue(Application::APP_ID, self::KEY_PREFIX . $kind, '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->logger->warning('[TeamHub][WorkflowLedger] unreadable ledger, starting empty', [
                'kind' => $kind, 'app' => Application::APP_ID,
            ]);
            return [];
        }
        return array_filter($decoded, 'is_array');
    }

    /** @return array<string, mixed>|null */
    public function get(string $kind, string $key): ?array {
        return $this->all($kind)[$key] ?? null;
    }

    /** @param array<string, mixed> $entry */
    public function put(string $kind, string $key, array $entry): void {
        $all = $this->all($kind);
        $all[$key] = $entry;
        $this->save($kind, $all);
    }

    public function remove(string $kind, string $key): void {
        $all = $this->all($kind);
        if (!isset($all[$key])) {
            return;
        }
        unset($all[$key]);
        $this->save($kind, $all);
    }

    /** @param array<string, array<string, mixed>> $all */
    private function save(string $kind, array $all): void {
        if ($all === []) {
            $this->config->deleteAppValue(Application::APP_ID, self::KEY_PREFIX . $kind);
            return;
        }
        $this->config->setAppValue(
            Application::APP_ID,
            self::KEY_PREFIX . $kind,
            json_encode($all, JSON_UNESCAPED_UNICODE) ?: '{}',
        );
    }
}
