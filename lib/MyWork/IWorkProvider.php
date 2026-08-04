<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork;

/**
 * The My Work provider contract (v4.5.21).
 *
 * A provider turns one source of work — a Nextcloud app, a TeamHub module, a
 * third-party integration — into normalized WorkItems. My Work has no
 * knowledge of Deck, Approval, or anything else beyond this interface; adding
 * a source means writing one class and registering it in Application.php.
 *
 * ## Contract rules
 *
 * 1. **Never leak across teams.** `fetchItems()` receives the resolved team
 *    set on the query and may only return items inside it. MyWorkService
 *    re-filters anyway, but a provider that over-returns is a bug.
 * 2. **Never throw for "nothing to do".** Return an empty page. Throw only for
 *    genuine faults — the registry catches those and isolates the provider so
 *    the rest of My Work still renders.
 * 3. **Be honest about availability.** `isAvailable()` must return false when
 *    the backing app is missing or its schema is not what this provider was
 *    written against. A provider that fails silently produces an empty queue
 *    that looks like "you're all caught up", which is the worst possible lie
 *    for this feature.
 * 4. **Authorise in both directions.** `getAvailableActions()` reports what the
 *    user may do; `executeAction()` re-checks it. The frontend's copy of the
 *    action list is a rendering hint and nothing more.
 * 5. **Be idempotent-friendly.** Completing an already-complete item returns a
 *    `conflict` ActionResult, not an exception and not a silent success.
 * 6. **Say how the item opens** (v4.5.24). Set `openTarget` to one of the
 *    OpenTarget mechanisms — the team's Deck tab, the team's Files tab, a
 *    TeamHub tab, or the item's own app. Do not expect the frontend to infer it
 *    from `resourceType`: that inference is exactly what made a TeamHub-native
 *    provider cost a UI change, which this file's opening claim says it must
 *    not.
 *    Omitting it is legal and means "open `resourceUrl` in a new browser tab".
 */
interface IWorkProvider {

    /**
     * Stable machine id, e.g. `deck`. Forms the first half of every WorkItem
     * id, is persisted in the snooze table and in admin config, and is used in
     * filter URLs — so it must never change once shipped.
     */
    public function getId(): string;

    /** Translated display name, e.g. "Deck". */
    public function getName(): string;

    /**
     * Icon key the frontend maps to a `vue-material-design-icons` component.
     * Providers name an icon; they do not ship markup.
     */
    public function getIcon(): string;

    /**
     * What this provider can do, independent of any specific item:
     *
     *   [
     *     'actions'       => string[]  subset of ActionType::ALL this provider
     *                                  can ever perform (native actions are
     *                                  added by TeamHub, not declared here),
     *     'resourceTypes' => string[]  resource types it can emit,
     *     'statuses'      => string[]  source statuses it can emit, for the
     *                                  status filter and the admin mapping UI,
     *     'categories'    => string[]  categories it can place items in,
     *     'pagination'    => bool      supports offset/limit natively,
     *     'incremental'   => bool      supports `updatedSince` narrowing,
     *   ]
     *
     * @return array<string,mixed>
     */
    public function getCapabilities(): array;

    /**
     * Is the backing source usable right now? Checked before every fetch and
     * surfaced on the admin status page.
     */
    public function isAvailable(): bool;

    /**
     * Human-readable reason `isAvailable()` returned false, for the admin
     * page. Null when available.
     */
    public function getUnavailableReason(): ?string;

    /**
     * The user's work from this source.
     *
     * Providers set `category`, `priority`, `reason` and `permissions`.
     * TeamHub may afterwards re-derive `TODAY`, promote overdue items to
     * `URGENT`, and append its native actions.
     */
    public function fetchItems(WorkQuery $query): WorkItemPage;

    /**
     * One item, re-read from source. This is the authorisation path used
     * before executing any action — never trust a client-supplied item.
     *
     * @param string[] $allowedTeamIds teams the caller may see
     */
    public function getItem(string $userId, string $providerItemId, array $allowedTeamIds): ?WorkItem;

    /**
     * Actions this user may perform on this item right now, as a subset of
     * `getCapabilities()['actions']`. Must already account for permissions,
     * source status, and resource existence — the four conditions under which
     * TeamHub must never show an action.
     *
     * @return string[]
     */
    public function getAvailableActions(string $userId, WorkItem $item): array;

    /**
     * Perform the action against the source application.
     *
     * @param array<string,mixed> $params action payload (e.g. comment text)
     */
    public function executeAction(string $userId, WorkItem $item, string $action, array $params): ActionResult;

    /**
     * Filter keys this provider narrows server-side, for documentation and so
     * the UI can indicate which filters are cheap. Purely informational —
     * MyWorkService applies every filter regardless.
     *
     * @return string[]
     */
    public function getSupportedFilters(): array;

    /**
     * Optional administrator configuration schema.
     *
     *   [ ['key' => …, 'type' => 'bool'|'int'|'string', 'label' => …,
     *      'default' => …, 'min' => …, 'max' => …], … ]
     *
     * Return `[]` for providers with nothing to configure.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getConfigSchema(): array;
}
