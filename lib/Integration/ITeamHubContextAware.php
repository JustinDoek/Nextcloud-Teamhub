<?php
declare(strict_types=1);

namespace OCA\TeamHub\Integration;

/**
 * Opt-in: a widget that wants the verified context (v4.13.23).
 *
 * {@see ITeamHubWidget} passes `(string $teamId, string $userId)` and is not
 * changing — every app that implements it keeps working exactly as before.
 * A widget that wants more than those two strings — the caller's role in the
 * team, their locale and time zone, which capability of the package is being
 * called — implements this interface *as well*, and TeamHub prefers this
 * method when it is present.
 *
 *     class OverviewWidget implements ITeamHubWidget, ITeamHubContextAware {
 *
 *         public function getWidgetDataFor(TeamHubContext $context): array {
 *             return $this->render($context->teamId, $context->userId, $context->locale);
 *         }
 *
 *         public function getWidgetData(string $teamId, string $userId): array {
 *             // Still required by ITeamHubWidget. Keep it working for
 *             // an older TeamHub that will never call the context method.
 *             return $this->render($teamId, $userId, 'en');
 *         }
 *
 *         …
 *     }
 *
 * Discovered with `instanceof`, so a package registering one widget that
 * wants the context and one that does not needs no flag and no metadata.
 *
 * The return shape is unchanged — the `items` / `actions` array
 * {@see ITeamHubWidget} documents, with the same limits and the same
 * sanitisation on the way out.
 */
interface ITeamHubContextAware {

    /**
     * Widget data for the verified context.
     *
     * The same contract as `ITeamHubWidget::getWidgetData()`: return the
     * `items` / `actions` shape, throw freely (TeamHub catches every
     * `\Throwable` and isolates the failure), and keep it fast.
     *
     * The context's membership is already verified. Authorisation of the
     * provider's *own* records remains the provider's responsibility.
     *
     * @return array{
     *   items: array<int, array{label: string, value: string, icon?: string, url?: string}>,
     *   actions?: array<int, array{label: string, icon?: string, actionId?: string, url?: string}>
     * }
     */
    public function getWidgetDataFor(TeamHubContext $context): array;
}
