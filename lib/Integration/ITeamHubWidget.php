<?php
declare(strict_types=1);

namespace OCA\TeamHub\Integration;

/**
 * Interface for Nextcloud apps that integrate as TeamHub sidebar widgets.
 *
 * Implement this interface in your app and register the class name via
 * IntegrationService::registerIntegration() during your app's boot() phase.
 * TeamHub will resolve your class from Nextcloud's DI container and call
 * its methods directly — no HTTP round-trips, no loopback restrictions.
 *
 * -----------------------------------------------------------------------
 * METHODS
 * -----------------------------------------------------------------------
 *
 * getWidgetData()  — Required. Returns items shown in the sidebar card and
 *                    optional action descriptors for the 3-dot header menu.
 *
 * getActionForm()  — Optional. Return a form definition for a named action.
 *                    TeamHub renders the form in a native NC modal. If your
 *                    app does not implement this method (or returns an empty
 *                    fields array), TeamHub falls back to opening the action
 *                    url in a new browser tab.
 *
 * handleAction()   — Optional. Process a submitted action form. TeamHub
 *                    calls this after the user submits the form returned by
 *                    getActionForm(). Return a success/error shape; TeamHub
 *                    shows the result to the user and optionally refreshes
 *                    the widget data.
 *
 * -----------------------------------------------------------------------
 * RESPONSE SHAPES
 * -----------------------------------------------------------------------
 *
 * getWidgetData():
 *
 *   [
 *     'items' => [                          // required, may be empty
 *       [
 *         'label' => 'string',              // required — primary text
 *         'value' => 'string',              // required — secondary text
 *         'icon'  => 'MDI name',            // optional
 *         'url'   => '/apps/myapp/...',     // optional — makes item a link
 *       ],
 *       // max 20 items
 *     ],
 *     'actions' => [                        // optional — 3-dot header menu
 *       [
 *         'label'    => 'string',           // required
 *         'icon'     => 'MDI name',         // optional
 *         'actionId' => 'new_item',         // use this for native modal actions
 *         'url'      => '/apps/myapp/...',  // use this for plain link fallback
 *       ],
 *       // max 10 actions; actionId takes priority over url when both present
 *     ],
 *   ]
 *
 * getActionForm():
 *
 *   [
 *     'title'        => 'string',           // optional — modal title override
 *     'submit_label' => 'string',           // optional — submit button label
 *     'fields'       => [                   // required — empty = fall back to url
 *       [
 *         'name'        => 'field_name',    // required — key in handleAction $fields
 *         'label'       => 'Field Label',   // required — shown above the input
 *         'type'        => 'text',          // required — text|textarea|email|checkbox|date
 *         'required'    => true,            // optional — default false
 *         'value'       => '',              // optional — pre-filled value
 *         'placeholder' => 'hint text',     // optional
 *       ],
 *     ],
 *   ]
 *
 * handleAction():
 *
 *   [
 *     'success' => true,                    // required
 *     'message' => 'Item created',          // optional — shown as toast to user
 *     'refresh' => true,                    // optional — if true, widget data reloads
 *   ]
 *
 * -----------------------------------------------------------------------
 * SECURITY RULES
 * -----------------------------------------------------------------------
 *
 * - Always validate that $userId is a member of $teamId before returning
 *   any data or performing any action.
 * - Never return data the user should not have access to.
 * - Throw freely — any \Throwable is caught by TeamHub.
 * - Keep all methods fast (< 500 ms). Cache aggressively.
 *
 * -----------------------------------------------------------------------
 * COMPATIBILITY
 * -----------------------------------------------------------------------
 *
 * - Nextcloud 32+ / PHP 8.1+
 * - TeamHub 2.46.0+ (getActionForm / handleAction added)
 * - getWidgetData() is the only required method. Apps that do not implement
 *   getActionForm() / handleAction() continue to work; actions without
 *   actionId fall back to opening url in a new tab.
 */
interface ITeamHubWidget {

    /**
     * Return widget data for a team member.
     *
     * @param string $teamId The Circles UUID of the team.
     * @param string $userId The authenticated NC user ID.
     *
     * @return array{
     *   items: array<int, array{label: string, value: string, icon?: string, url?: string}>,
     *   actions?: array<int, array{label: string, icon?: string, actionId?: string, url?: string}>
     * }
     */
    public function getWidgetData(string $teamId, string $userId): array;

    /**
     * Return the form definition for a named action.
     *
     * TeamHub calls this when the user clicks a 3-dot menu action that has
     * an actionId. The returned fields are rendered in a native NC modal.
     * Implementing this method is optional — if absent or fields is empty,
     * TeamHub falls back to opening the action url in a new tab.
     *
     * @param string $actionId The actionId from getWidgetData() actions.
     * @param string $teamId   The team UUID.
     * @param string $userId   The authenticated NC user ID.
     *
     * @return array{
     *   title?: string,
     *   submit_label?: string,
     *   fields: array<int, array{name: string, label: string, type: string, required?: bool, value?: mixed, placeholder?: string}>
     * }
     */
    public function getActionForm(string $actionId, string $teamId, string $userId): array;

    /**
     * Handle a submitted action form.
     *
     * @param string $actionId The actionId being handled.
     * @param array  $fields   Submitted values keyed by field name.
     * @param string $teamId   The team UUID.
     * @param string $userId   The authenticated NC user ID.
     *
     * @return array{success: bool, message?: string, refresh?: bool}
     */
    public function handleAction(string $actionId, array $fields, string $teamId, string $userId): array;
}
