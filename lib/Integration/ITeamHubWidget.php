<?php
declare(strict_types=1);

namespace OCA\TeamHub\Integration;

/**
 * Interface for Nextcloud apps that integrate as TeamHub sidebar widgets.
 *
 * Implement this interface in your app and register the class name via
 * IntegrationService::registerIntegration() during your app's boot() phase.
 * TeamHub will resolve your class from Nextcloud's DI container and call
 * getWidgetData() directly — no HTTP requests, no routing issues, no
 * loopback restrictions.
 *
 * -----------------------------------------------------------------------
 * QUICK START
 * -----------------------------------------------------------------------
 *
 * 1. Implement this interface in your app:
 *
 *    namespace OCA\MyApp\Integration;
 *
 *    use OCA\TeamHub\Integration\ITeamHubWidget;
 *
 *    class TeamHubWidget implements ITeamHubWidget {
 *
 *        public function __construct(
 *            private MyService $myService,
 *        ) {}
 *
 *        public function getWidgetData(string $teamId, string $userId): array {
 *            $items = $this->myService->getItemsForTeam($teamId, $userId);
 *            return [
 *                'items' => array_map(fn($i) => [
 *                    'label' => $i->title,
 *                    'value' => $i->status,
 *                    'icon'  => 'CheckCircle',
 *                    'url'   => '/apps/myapp/items/' . $i->id,
 *                ], $items),
 *            ];
 *        }
 *    }
 *
 * 2. Register your class in your Application::boot():
 *
 *    public function boot(IBootContext $context): void {
 *        try {
 *            $teamHub = $context->getServerContainer()
 *                ->get(\OCA\TeamHub\Service\IntegrationService::class);
 *
 *            $teamHub->registerIntegration(
 *                appId:           'myapp',
 *                integrationType: 'widget',
 *                title:           'My Widget',
 *                description:     'Shows recent items from My App',
 *                icon:            'ChartBar',
 *                phpClass:        \OCA\MyApp\Integration\TeamHubWidget::class,
 *                calledInProcess: true,
 *            );
 *        } catch (\Throwable $e) {
 *            // TeamHub not installed — fail silently.
 *        }
 *    }
 *
 * -----------------------------------------------------------------------
 * RESPONSE SHAPE
 * -----------------------------------------------------------------------
 *
 * getWidgetData() must return an array with the following structure:
 *
 *   [
 *     'items' => [                      // required, may be empty
 *       [
 *         'label' => 'string',          // required — primary text
 *         'value' => 'string',          // required — secondary text (status, count, date…)
 *         'icon'  => 'MDI name',        // optional — MDI icon name e.g. 'CheckCircle'
 *         'url'   => '/apps/myapp/…',   // optional — makes item a clickable link
 *       ],
 *       // …up to 20 items (additional items are silently dropped by TeamHub)
 *     ],
 *     'actions' => [                    // optional — populates the widget 3-dot menu
 *       [
 *         'label' => 'string',          // required
 *         'icon'  => 'MDI name',        // optional
 *         'url'   => '/apps/myapp/…',   // required — relative NC path or https://
 *       ],
 *       // …up to 10 actions
 *     ],
 *   ]
 *
 * -----------------------------------------------------------------------
 * SECURITY RULES
 * -----------------------------------------------------------------------
 *
 * - Always validate that the requesting $userId is a member of $teamId
 *   before returning any data. TeamHub passes the currently authenticated
 *   user's ID — treat it as trusted (it comes from IUserSession, not from
 *   an HTTP request parameter).
 * - Never return data the user should not have access to.
 * - You may throw any \Throwable on error — TeamHub will catch it, log it,
 *   and return an empty widget rather than crashing the page.
 *
 * -----------------------------------------------------------------------
 * COMPATIBILITY
 * -----------------------------------------------------------------------
 *
 * - Nextcloud 32+ / PHP 8.1+
 * - Your class is resolved via NC's DI container — constructor injection
 *   works exactly as in any other NC service. No special registration needed
 *   beyond implementing this interface.
 * - TeamHub version: 2.41+
 */
interface ITeamHubWidget {

    /**
     * Return widget data for a team member.
     *
     * Called by TeamHub whenever a user opens a team view that has this
     * widget enabled. The call is made in the same PHP process — there is
     * no HTTP round-trip.
     *
     * @param string $teamId The Circles/Teams UUID of the team being viewed.
     * @param string $userId The NC user ID of the currently authenticated user.
     *
     * @return array{
     *   items: array<int, array{label: string, value: string, icon?: string, url?: string}>,
     *   actions?: array<int, array{label: string, icon?: string, url: string}>
     * }
     *
     * @throws \Throwable Any exception is caught by TeamHub — the widget
     *                    renders an empty/error state rather than crashing.
     */
    public function getWidgetData(string $teamId, string $userId): array;
}
