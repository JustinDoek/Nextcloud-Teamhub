<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * DbIntrospectionService — lightweight DB schema utility.
 *
 * Extracted from ResourceService in v3.2.0 to break the circular dependency
 * that would arise if TalkService and DeckService both injected ResourceService
 * solely to call getTableColumns().
 *
 * Dependency graph (no cycles):
 *   TalkService    → DbIntrospectionService
 *   DeckService    → DbIntrospectionService
 *   ResourceService → DbIntrospectionService (+ TalkService, FilesService, etc.)
 *
 * Provides getTableColumns() with a static in-process cache so the same table
 * is never introspected more than once per request, regardless of which service
 * calls it first.
 */
class DbIntrospectionService {

    /**
     * In-process cache keyed by un-prefixed table name.
     * Static so the cache is shared across all injected instances within a request.
     *
     * @var array<string, string[]>
     */
    private static array $columnCache = [];

    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {}

    /**
     * Return the column names for an un-prefixed table name.
     *
     * Three strategies in descending preference:
     *   1. DBAL SchemaManager via inner Doctrine connection (NC32 compatible)
     *   2. SELECT * LIMIT 1 — column names from result set metadata
     *   3. INFORMATION_SCHEMA query (MySQL/MariaDB/PostgreSQL fallback)
     *
     * Returns an empty array when the table doesn't exist — callers skip
     * optional columns gracefully.
     *
     * @param string $table Un-prefixed table name, e.g. 'circles_member'
     * @return string[]
     */
    public function getTableColumns(string $table): array {
        if (isset(self::$columnCache[$table])) {
            return self::$columnCache[$table];
        }

        $db = $this->container->get(\OCP\IDBConnection::class);

        // ── Strategy 1: DBAL SchemaManager via inner connection ───────────────
        try {
            $inner = method_exists($db, 'getInner') ? $db->getInner() : null;
            if ($inner instanceof \Doctrine\DBAL\Connection) {
                $sm = $inner->createSchemaManager();
                $qb      = $db->getQueryBuilder();
                $testSql = $qb->select('*')->from($table)->setMaxResults(0)->getSQL();
                if (preg_match('/FROM\s+["`]?(\S+?)["`]?\s/i', $testSql, $m)) {
                    $fullTable = trim($m[1], '"`');
                    $columns   = $sm->listTableColumns($fullTable);
                    $cols = array_keys($columns);
                    self::$columnCache[$table] = $cols;
                    return $cols;
                }
            }
        } catch (\Throwable $e) {
            // Fall through
        }

        // ── Strategy 2: SELECT * LIMIT 1 — column names from result set ───────
        try {
            $qb     = $db->getQueryBuilder();
            $result = $qb->select('*')->from($table)->setMaxResults(1)->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();
            if (is_array($row)) {
                $cols = array_keys($row);
                self::$columnCache[$table] = $cols;
                return $cols;
            }
        } catch (\Throwable $e) {
            // Fall through
        }

        // ── Strategy 3: INFORMATION_SCHEMA (MySQL/MariaDB/PostgreSQL) ─────────
        try {
            $qb        = $db->getQueryBuilder();
            $sql       = $qb->select('id')->from($table)->setMaxResults(0)->getSQL();
            $fullTable = 'UNKNOWN';
            if (preg_match('/FROM\s+["`]?(\S+?)["`]?(?:\s|$)/i', $sql, $m)) {
                $fullTable = trim($m[1], '"`');
            }
            $result = $db->executeQuery(
                'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ?',
                [$fullTable]
            );
            $cols = [];
            while ($row = $result->fetch()) {
                $col = $row['COLUMN_NAME'] ?? $row['column_name'] ?? null;
                if ($col !== null) {
                    $cols[] = $col;
                }
            }
            $result->closeCursor();
            if (!empty($cols)) {
                self::$columnCache[$table] = $cols;
                return $cols;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[DbIntrospectionService] getTableColumns: all strategies failed', [
                'table' => $table,
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
        }

        return [];
    }
}
