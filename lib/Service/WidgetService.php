<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\TeamWidgetMapper;
use OCA\TeamHub\Db\WidgetRegistryMapper;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Business logic for TeamHub's external widget system.
 *
 * Registration (called by external apps):
 *   - registerWidget()   — upsert a registry entry; only accepted if the
 *                          calling app's app_id is installed and enabled.
 *   - deregisterWidget() — remove registry entry + cascade-delete all
 *                          team opt-ins for that widget. NC admin required.
 *
 * Team management (called by ManageTeamView -> Widgets tab):
 *   - getWidgetRegistryForTeam() — full list + enabled state, for the tab.
 *   - toggleWidget()             — enable or disable a widget for a team.
 *   - reorderWidgets()           — persist drag-and-drop sort order.
 *
 * Sidebar rendering (called by TeamView on team select):
 *   - getEnabledWidgets() — only the enabled+joined rows a team needs.
 */
class WidgetService {

    public function __construct(
        private WidgetRegistryMapper $registryMapper,
        private TeamWidgetMapper     $teamWidgetMapper,
        private IAppManager          $appManager,
        private IGroupManager        $groupManager,
        private IUserSession         $userSession,
        private LoggerInterface      $logger,
    ) {}

    // ------------------------------------------------------------------
    // Registration API (external apps)
    // ------------------------------------------------------------------

    /**
     * Register or update a widget for an external app.
     *
     * Security rules:
     *   1. Caller must be authenticated.
     *   2. $appId must match an installed and enabled NC app.
     *   3. $iframeUrl must use https://.
     *   4. $title must be non-empty (max 255 chars).
     *   5. $description optional, max 500 chars.
     *   6. $icon optional, max 64 chars (MDI icon name).
     *
     * @throws \Exception On any validation failure.
     */
    public function registerWidget(
        string  $appId,
        string  $title,
        string  $iframeUrl,
        ?string $description = null,
        ?string $icon        = null,
    ): array {
        // 1. Authenticated caller.
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('Not authenticated');
        }

        // 2. app_id must be an installed and enabled NC app.
        if (!$this->appManager->isInstalled($appId)) {
            $this->logger->warning('WidgetService::registerWidget — app_id not installed', [
                'app_id'    => $appId,
                'called_by' => $user->getUID(),
            ]);
            throw new \Exception("App '{$appId}' is not installed or not enabled on this instance");
        }

        // 3. iframe_url must be https://.
        $trimmedUrl = trim($iframeUrl);
        if (!str_starts_with($trimmedUrl, 'https://')) {
            throw new \Exception('iframe_url must start with https://');
        }
        if (strlen($trimmedUrl) > 2048) {
            throw new \Exception('iframe_url exceeds maximum length of 2048 characters');
        }

        // 4. title validation.
        $trimmedTitle = trim($title);
        if ($trimmedTitle === '') {
            throw new \Exception('title cannot be empty');
        }
        if (strlen($trimmedTitle) > 255) {
            throw new \Exception('title exceeds maximum length of 255 characters');
        }

        // 5. description validation.
        $trimmedDesc = $description !== null ? trim($description) : null;
        if ($trimmedDesc !== null && strlen($trimmedDesc) > 500) {
            throw new \Exception('description exceeds maximum length of 500 characters');
        }

        // 6. icon validation.
        $trimmedIcon = $icon !== null ? trim($icon) : null;
        if ($trimmedIcon !== null && strlen($trimmedIcon) > 64) {
            throw new \Exception('icon name exceeds maximum length of 64 characters');
        }

        // Upsert: update if app already has a registration, insert otherwise.
        $existing = $this->registryMapper->findByAppId($appId);
        if ($existing !== null) {
            return $this->registryMapper->update(
                $existing['id'],
                $trimmedTitle,
                $trimmedDesc,
                $trimmedIcon,
                $trimmedUrl,
            );
        }

        return $this->registryMapper->create(
            $appId,
            $trimmedTitle,
            $trimmedDesc,
            $trimmedIcon,
            $trimmedUrl,
        );
    }

    /**
     * Deregister a widget by app ID.
     *
     * Cascades: all team opt-ins for this widget are removed first, so no
     * orphaned teamhub_team_widgets rows are left behind.
     *
     * Security: NC admin privilege required. This prevents any authenticated
     * user from removing another app's widget registration.
     *
     * Idempotent — no error if the app was never registered.
     *
     * @throws \Exception When caller is not an NC admin.
     */
    public function deregisterWidget(string $appId): void {
        $user = $this->userSession->getUser();
        if (!$user || !$this->groupManager->isAdmin($user->getUID())) {
            throw new \Exception('NC admin privilege required to deregister a widget');
        }

        $existing = $this->registryMapper->findByAppId($appId);
        if ($existing === null) {
            $this->logger->info('WidgetService::deregisterWidget — no registration found, skipping', [
                'app_id' => $appId,
            ]);
            return;
        }

        // Cascade: remove all team opt-ins first.
        $this->teamWidgetMapper->deleteByRegistryId($existing['id']);

        // Remove the registry entry.
        $this->registryMapper->deleteByAppId($appId);

        $this->logger->info('WidgetService::deregisterWidget — widget deregistered', [
            'app_id'      => $appId,
            'registry_id' => $existing['id'],
        ]);
    }

    // ------------------------------------------------------------------
    // Team management (Manage Team -> Widgets tab)
    // ------------------------------------------------------------------

    /**
     * Return all registered widgets with their enabled state for a team.
     * Used to populate the Manage Team -> Widgets tab.
     */
    public function getWidgetRegistryForTeam(string $teamId): array {
        return $this->teamWidgetMapper->findAllWithEnabledStateForTeam($teamId);
    }

    /**
     * Enable or disable a widget for a team.
     *
     * @param bool $enable True = enable (insert row), false = disable (delete row).
     * @return array Updated full registry list for the team (same shape as getWidgetRegistryForTeam).
     * @throws \Exception When registry_id does not exist.
     */
    public function toggleWidget(string $teamId, int $registryId, bool $enable): array {
        // Verify the registry entry exists before touching team_widgets.
        $this->registryMapper->findById($registryId); // throws if not found

        if ($enable) {
            $this->teamWidgetMapper->enable($registryId, $teamId);
        } else {
            $this->teamWidgetMapper->disable($registryId, $teamId);
        }

        return $this->teamWidgetMapper->findAllWithEnabledStateForTeam($teamId);
    }

    /**
     * Persist a new sort order for a team's enabled widgets.
     *
     * $orderedRegistryIds must contain registry IDs in the desired display
     * order (index 0 = top of sidebar).
     *
     * @param int[] $orderedRegistryIds
     * @return array Updated enabled widget list for the team.
     */
    public function reorderWidgets(string $teamId, array $orderedRegistryIds): array {
        // Sanitise: each element must be a positive integer.
        $clean = array_values(array_filter(
            array_map('intval', $orderedRegistryIds),
            fn(int $id) => $id > 0
        ));

        $this->teamWidgetMapper->reorder($teamId, $clean);
        return $this->teamWidgetMapper->findEnabledForTeam($teamId);
    }

    // ------------------------------------------------------------------
    // Sidebar rendering (TeamView on team select)
    // ------------------------------------------------------------------

    /**
     * Return only the enabled widgets for a team, joined with registry
     * metadata, ordered by sort_order. This is what TeamView fetches.
     */
    public function getEnabledWidgets(string $teamId): array {
        return $this->teamWidgetMapper->findEnabledForTeam($teamId);
    }
}
