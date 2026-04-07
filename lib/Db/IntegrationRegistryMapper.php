<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Data-access layer for teamhub_integration_registry.
 *
 * One row per registered integration. External apps register via
 * IntegrationService::registerIntegration() called in-process from their
 * Application::boot(). Built-in integrations (Talk, Files, Calendar, Deck)
 * are seeded by IntegrationService::seedBuiltins() on first boot.
 *
 * Widget integrations deliver data via php_class — a fully-qualified class
 * name implementing ITeamHubWidget, resolved from NC's DI container at
 * render time. HTTP-based data_url / action_url / action_label have been
 * removed. Menu item integrations still use iframe_url (browser-side).
 */
class IntegrationRegistryMapper {

    // Valid integration types — enforced in service layer.
    public const TYPE_WIDGET    = 'widget';
    public const TYPE_MENU_ITEM = 'menu_item';

    // Built-in app IDs (cannot be deregistered via the external API).
    public const BUILTIN_APP_IDS = ['spreed', 'files', 'calendar', 'deck'];

    public function __construct(
        private IDBConnection $db,
    ) {}

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    /**
     * Return all registry entries ordered by is_builtin DESC, then created_at ASC.
     * Built-ins appear first so the Manage Team UI can render them at the top.
     *
     * @return array<int, array>
     */
    public function findAll(): array {

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_integration_registry')
            ->orderBy('is_builtin', 'DESC')
            ->addOrderBy('created_at', 'ASC');

        $result = $qb->executeQuery();
        $rows   = [];
        while ($row = $result->fetch()) {
            $rows[] = $this->hydrate($row);
        }
        $result->closeCursor();

        return $rows;
    }

    /**
     * Return all integrations of a specific type.
     *
     * @param string $type One of TYPE_WIDGET | TYPE_MENU_ITEM
     * @return array<int, array>
     */
    public function findByType(string $type): array {

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_integration_registry')
            ->where($qb->expr()->eq('integration_type', $qb->createNamedParameter($type)))
            ->orderBy('is_builtin', 'DESC')
            ->addOrderBy('created_at', 'ASC');

        $result = $qb->executeQuery();
        $rows   = [];
        while ($row = $result->fetch()) {
            $rows[] = $this->hydrate($row);
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * Find a single registry entry by primary key.
     *
     * @throws \Exception When no row exists for the given ID.
     */
    public function findById(int $id): array {

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_integration_registry')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if (!$row) {
            throw new \Exception("Integration registry entry not found: {$id}");
        }
        return $this->hydrate($row);
    }

    /**
     * Find a registry entry by app ID. Returns null when not registered.
     */
    public function findByAppId(string $appId): ?array {

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_integration_registry')
            ->where($qb->expr()->eq('app_id', $qb->createNamedParameter($appId)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return $row ? $this->hydrate($row) : null;
    }

    // ------------------------------------------------------------------
    // Write
    // ------------------------------------------------------------------

    /**
     * Insert a new registry entry.
     *
     * @return array The newly created row.
     */
    public function create(
        string  $appId,
        string  $integrationType,
        string  $title,
        ?string $description,
        ?string $icon,
        ?string $phpClass,
        ?string $iframeUrl,
        bool    $isBuiltin = false,
    ): array {

        $qb  = $this->db->getQueryBuilder();
        $now = time();

        $qb->insert('teamhub_integration_registry')
            ->values([
                'app_id'           => $qb->createNamedParameter($appId),
                'integration_type' => $qb->createNamedParameter($integrationType),
                'title'            => $qb->createNamedParameter($title),
                'description'      => $qb->createNamedParameter($description),
                'icon'             => $qb->createNamedParameter($icon),
                'php_class'        => $qb->createNamedParameter($phpClass),
                'iframe_url'       => $qb->createNamedParameter($iframeUrl),
                'is_builtin'       => $qb->createNamedParameter($isBuiltin, IQueryBuilder::PARAM_BOOL),
                'created_at'       => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ]);

        $qb->executeStatement();
        $id = (int)$qb->getLastInsertId();

        $this->logger_debug("IntegrationRegistryMapper::create — inserted id={$id} app_id={$appId}");

        return $this->findById($id);
    }

    /**
     * Update an existing registry entry (upsert pattern — called by registerIntegration).
     */
    public function update(
        int     $id,
        string  $title,
        ?string $description,
        ?string $icon,
        ?string $phpClass,
        ?string $iframeUrl,
    ): array {

        $qb = $this->db->getQueryBuilder();
        $qb->update('teamhub_integration_registry')
            ->set('title',       $qb->createNamedParameter($title))
            ->set('description', $qb->createNamedParameter($description))
            ->set('icon',        $qb->createNamedParameter($icon))
            ->set('php_class',   $qb->createNamedParameter($phpClass))
            ->set('iframe_url',  $qb->createNamedParameter($iframeUrl))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
        return $this->findById($id);
    }

    /**
     * Suspend a registry entry by clearing php_class and iframe_url.
     *
     * Called when the registering app is disabled (not uninstalled). The row
     * and all team opt-ins are preserved — the ID never changes. When the app
     * is re-enabled its boot() calls registerIntegration() which upserts the
     * class/url back in. Team admins never need to re-enable the widget.
     *
     * A suspended widget returns a 400 from fetchWidgetData() because php_class
     * is empty — which is the correct safe behaviour while the app is down.
     */
    public function suspendByAppId(string $appId): void {

        $this->logger_debug("IntegrationRegistryMapper::suspendByAppId — suspending app_id={$appId}");

        $qb = $this->db->getQueryBuilder();
        $qb->update('teamhub_integration_registry')
            ->set('php_class',  $qb->createNamedParameter(null))
            ->set('iframe_url', $qb->createNamedParameter(null))
            ->where($qb->expr()->eq('app_id', $qb->createNamedParameter($appId)));
        $qb->executeStatement();
    }

    /**
     * Delete a registry entry by app ID.
     * Only used for permanent removal (app uninstall / NC admin action).
     * Caller is responsible for cascading into teamhub_team_integrations first.
     */
    public function deleteByAppId(string $appId): void {

        $qb = $this->db->getQueryBuilder();
        $qb->delete('teamhub_integration_registry')
            ->where($qb->expr()->eq('app_id', $qb->createNamedParameter($appId)));
        $qb->executeStatement();
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private function hydrate(array $row): array {
        return [
            'id'               => (int)$row['id'],
            'app_id'           => (string)$row['app_id'],
            'integration_type' => (string)$row['integration_type'],
            'title'            => (string)$row['title'],
            'description'      => isset($row['description']) ? (string)$row['description'] : null,
            'icon'             => isset($row['icon'])        ? (string)$row['icon']        : null,
            'php_class'        => isset($row['php_class'])   ? (string)$row['php_class']   : null,
            'iframe_url'       => isset($row['iframe_url'])  ? (string)$row['iframe_url']  : null,
            'is_builtin'       => (bool)$row['is_builtin'],
            'created_at'       => (int)$row['created_at'],
        ];
    }

    /** Temporary debug helper — removed at session end. */
    private function logger_debug(string $msg): void {
        \OC::$server->get(\Psr\Log\LoggerInterface::class)->debug($msg, ['app' => 'teamhub']);
    }
}
