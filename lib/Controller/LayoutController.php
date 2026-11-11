<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Workflow\Definition\TeamAdoptionDefinition;
use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\DashboardWidgets;
use OCA\TeamHub\Constants\LayoutSchema;
use OCA\TeamHub\Constants\TeamLayoutPolicy;
use OCA\TeamHub\Constants\LayoutCascadePolicy;
use OCA\TeamHub\Constants\UserTemplateLayoutKeys;
use OCA\TeamHub\Db\LayoutDefaultMapper;
use OCA\TeamHub\Db\LayoutMapper;
use OCA\TeamHub\Db\ProvisioningMapper;
use OCA\TeamHub\Service\BudgetService;
use OCA\TeamHub\Service\CollectivesService;
use OCA\TeamHub\Service\LayoutDefaultService;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\OpenProject\TeamOpenProjectLinkService;
use OCA\TeamHub\Service\DecisionTeamService;
use OCA\TeamHub\Service\PresenceTeamService;
use OCA\TeamHub\Service\ProjectService;
use OCA\TeamHub\Service\TeamBannerService;
use OCA\TeamHub\Service\TeamExpiryService;
use OCA\TeamHub\Service\TeamTypeService;
use OCA\TeamHub\Service\TimeService;
use OCA\TeamHub\Service\TimelineService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * LayoutController — per-user, per-team Home-view grid layout + tab order.
 *
 * GET  /api/v1/teams/{teamId}/layout  → team's effective layout
 * PUT  /api/v1/teams/{teamId}/layout  → member's layout (unless enforced)
 * GET  /api/v1/layout/default?teamId=... → personal default for that template
 * PUT  /api/v1/layout/default         → save personal type default; teamId required
 *
 * Effective layout precedence:
 *   1. Enforced team-admin layout (all members; independent of saved grid)
 *   2. Personal per-team layout (arranged since the last type-default rollout,
 *      or explicitly reset to this team's template)
 *   3. User's personal default for this team's template (all unlocked teams,
 *      when the user selects "Save as my type default")
 *   4. Team-admin Apply once, when there is no personal type default
 *   5. Nextcloud admin's template default (starting recipe)
 *   6. Built-in default layout
 *
 * Personal defaults are scoped to the server-resolved team type. Global
 * defaults stored before this change are deliberately not migrated across
 * types, because different templates expose different widgets.
 *
 * The qualifier on step 1 is the point of v4.13.33. A row used to be written
 * on first team open by a measurement pass, before the member had touched
 * anything, and from then on steps 2 and 3 could never be reached again: a
 * personal default set afterwards reached no team already visited, and a
 * changed system default reached nobody at all. A row TeamHub wrote for itself
 * is now a cache that the cascade walks straight past. See
 * `resolveTeamLayout()` and `classifyStoredLayout()`.
 *
 * A cascaded layout is **packed in the client** to the widgets the team
 * actually shows (`src/lib/packLayout.js`), which is why the response says
 * `isDefault`. It has to happen there: whether a widget is active depends on
 * registered integration packages that arrive on a different request, so this
 * controller cannot know the visible set.
 *
 * All endpoints require a logged-in user (NoAdminRequired).
 * User default is stored in oc_preferences — no migration needed.
 */
class LayoutController extends Controller {

    // Personal defaults are stored in oc_preferences per template. The
    // previous global keys remain untouched but are never used as fallbacks:
    // doing that would apply a Project widget arrangement to other types.

    public function __construct(
        string $appName,
        IRequest $request,
        private LayoutMapper $layoutMapper,
        private IUserSession $userSession,
        private IDBConnection $db,
        private IConfig $config,
        private LoggerInterface $logger,
        private MemberService $memberService,
        // v4.13.34 — the instance, template and team defaults the cascade
        // reads. A DI leaf (mapper, session, group manager, audit, logger).
        private LayoutDefaultService $layoutDefaults,
        // v4.10.27 — the service-desk facts in the bundle.
        private ServiceTeamService $serviceTeams,
        private PresenceTeamService $presenceTeamService,
        private DecisionTeamService $decisionTeamService,
        private TimelineService $timelineService,
        private ProjectService $projectService,
        private BudgetService $budgetService,
        private TimeService $timeService,
        private TeamTypeService $teamTypeService,
        private TeamExpiryService $teamExpiryService,
        private CollectivesService $collectivesService,
        private TeamOpenProjectLinkService $openProjectLinks,
        // v4.9.6 — a DI leaf (IDBConnection only); the summary read is two
        // cheap queries, so it rides the bundle rather than a second fetch.
        private ProvisioningMapper $provisioningMapper,
        // v4.13.1 — a DI leaf (app data, URL generator, logger). One app-data
        // stat per team open; the banner strip needs the URL before it can
        // render anything, so a second request would show as a flash.
        private TeamBannerService $teamBannerService,
    ) {
        parent::__construct($appName, $request);
    }

    // ----------------------------------------------------------------
    // GET /api/v1/teams/{teamId}/layout
    //
    // Cascades: team-specific → user personal default → system default.
    // Always returns userDefault so the client can show/hide the layout
    // default buttons without a second request.
    // ----------------------------------------------------------------

    #[NoAdminRequired]
    public function getLayout(string $teamId): JSONResponse {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if (!$this->isValidId($teamId)) {
            $this->logger->warning('[TeamHub][LayoutController] getLayout — invalid teamId', [
                'teamId' => $teamId, 'userId' => $userId,
            ]);
            return new JSONResponse(['error' => 'Invalid team ID'], Http::STATUS_BAD_REQUEST);
        }

        if (!$this->isMember($teamId, $userId)) {
            $this->logger->warning('[TeamHub][LayoutController] getLayout — non-member access attempt', [
                'teamId' => $teamId, 'userId' => $userId,
            ]);
            return new JSONResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
        }

        // Resolve user default once — used for cascade and for client comparison.
        $userDefault = $this->resolveUserDefault($userId, $this->teamTypeService->getType($teamId));

        // v4.13.34 — the team's own default, which is the only scope with a
        // mode and therefore the only one that can sit above a member's own
        // arrangement. Read once: resolveTeamLayout needs it for the cascade
        // and the response needs it for the client.
        $teamDefault = $this->layoutDefaults->forTeam($teamId);

        [$layout, $tabOrder, $isDefault] = $this->resolveTeamLayout($teamId, $userId, $userDefault, $teamDefault);

        // v4.13.33 — one response, not two. Until this release `getLayout()`
        // returned the same twenty-field bundle from two branches, and the two
        // copies had already drifted once: the cascade branch sent the *merged*
        // default as `userDefault` and the saved-row branch sent the raw one,
        // so "Reset to default" on a customised team restored a default with
        // none of the widgets added since that default was saved. Both send the
        // merged one now, and there is one place for the next field to be
        // added to.
        return new JSONResponse(array_merge(
            [
                'layout'      => $layout,
                'tabOrder'    => $tabOrder,
                // True when this layout came from the cascade rather than from
                // an arrangement of this member's own. The client packs a
                // cascaded layout to the widgets the team actually shows
                // (src/lib/packLayout.js) and does not save the result.
                'isDefault'   => $isDefault,
                'userDefault' => $this->mergeNewWidgets($userDefault['layout']),
                // v4.13.34 — what this team's own default is doing to this
                // member, and what they may do about it.
                //
                // `mode` is null when the team has no default of its own. The
                // client greys the Edit layout control and labels it *Team
                // preset* on `enforce`, and offers the layout in a notice on
                // `suggest`; `apply_once` needs nothing of it, because the
                // cascade has already served it.
                'teamPreset'  => [
                    'mode'      => $teamDefault['mode'] ?? null,
                    'updatedAt' => $teamDefault['updatedAt'] ?? null,
                    // Whether a published team layout exists, independent of
                    // whether the caller may receive the layout coordinates.
                    // An enforced preset deliberately withholds `layout` from
                    // members, but admins still need its existence to unlock,
                    // reset or remove it in the Home editor.
                    'hasLayout' => $teamDefault !== null
                        && is_array($teamDefault['layout'] ?? null)
                        && count($teamDefault['layout']) > 0,
                    // The layout itself, but only for the one mode that asks
                    // the member to choose: a suggestion they cannot see is
                    // not a suggestion. Withheld otherwise so the payload does
                    // not carry a second copy of what `layout` already is.
                    'layout'    => ($teamDefault !== null && $teamDefault['mode'] === LayoutDefaultMapper::MODE_SUGGEST)
                        ? $this->mergeNewWidgets($teamDefault['layout'])
                        : null,
                ],
                // v4.13.35 — whether to offer *Save as the layout for this
                // team* on the home. Hidden, not disabled (CLAUDE.md), so the
                // server answers rather than the client guessing from a role.
                //
                // It was three booleans until this release. The other two
                // offered a Nextcloud administrator the instance and
                // per-template defaults from inside whichever team they
                // happened to be in; those moved to Admin -> TeamHub -> Policy
                // (Justin, 2026-10-28), and the delegated-admin question they
                // raised went with them — the attribute on those routes is now
                // the only gate, and it already admits a delegated TeamHub
                // admin, so nothing here has to guess at one.
                'canSetTeamDefault' => $this->isTeamAdmin($teamId, $userId),
            ],
            $this->teamBundle($teamId, $userId),
        ));
    }

    /**
     * Which layout this member sees on this team, and whether it came from the
     * cascade (v4.13.33).
     *
     * A member's own arrangement wins over template and personal defaults,
     * while an enforced team preset takes precedence over all personal rows. A row TeamHub
     * wrote by itself is a cache, and a cache that would hide a changed default
     * is worse than no cache, so it is skipped and the cascade runs.
     *
     * ## v4.13.35 — the newest decision wins, and that is the whole rule
     *
     * v4.13.34 put every administrator-set default *behind* the member's own
     * arrangement, so setting one changed nothing for anybody who had ever
     * dragged a card — which on a real instance is almost everybody. Justin,
     * 2026-10-28, on a team layout he had set five times: *"team template if
     * set takes precedence over a member's own."* He is right, and the same
     * answer has to hold at every width or "I set it and nothing happened"
     * just moves to a different screen.
     *
     * A template is the starting layout for a new team, not a rollout:
     * member edits always win over it. A team administrator can instead
     * publish an enforced team layout, which is authoritative for every
     * member. Apply once retains its separate timestamp-based rollout rule.
     * The former timestamp-based template override is intentionally removed.
     *
     * `enforce` skips the comparison entirely — that is the difference between
     * applying a layout and enforcing one. A `suggest` default is in none of
     * this: it reaches the member as a notice (`teamPreset` on the bundle) and
     * changes nothing until they take it.
     *
     * Nothing is ever copied into a member's row by any of this, so an
     * arrangement that loses to a default is not gone — it is in their row,
     * and it comes back the moment the default is cleared or enforcement is
     * lifted.
     *
     * @param array{layout: array, tabOrder: array, isSystemDefault: bool} $userDefault
     * @param array{layout: array, tabOrder: array, mode: string}|null     $teamDefault
     *
     * @return array{0: array, 1: array, 2: bool} [layout, tabOrder, isDefault]
     */
    private function resolveTeamLayout(string $teamId, string $userId, array $userDefault, ?array $teamDefault): array {
        $row = $this->layoutMapper->find($userId, $teamId);

        // An enforced team default wins over everything, including a member's
        // own arrangement, and without consulting any timestamp. Their tab
        // order is still theirs: enforcing the grid is what a team admin asked
        // for, and the tab bar is navigation rather than layout. Said out loud
        // because the issue asks whether enforcing should lock the tab order
        // too, and this is the answer until somebody decides otherwise.
        if (TeamLayoutPolicy::locksPersonalGrid($teamDefault)) {
            $tabOrder = $row !== null
                ? $this->mergeNewTabs(json_decode($row['tab_order_json'], true) ?? LayoutSchema::DEFAULT_TAB_ORDER)
                : $this->mergeNewTabs($teamDefault['tabOrder'] ?: $userDefault['tabOrder']);

            $this->logger->debug('[TeamHub][LayoutController] getLayout — enforced team layout', [
                'teamId' => $teamId, 'userId' => $userId,
            ]);

            // An enforced team preset is a positioned layout, NOT a template
            // recipe. Only missing future widgets are appended below it.
            return [$this->mergeDefaultWidgets($teamDefault['layout'], true), $tabOrder, true];
        }

        // Resolve once for both precedence and rendered layout. Personal
        // defaults are per template, never global; team-admin enforcement above
        // remains the absolute highest authority.
        $cascade = $this->resolveCascadeSource($teamId, $userDefault, $teamDefault);

        if ($row !== null) {
            $layout   = json_decode($row['layout_json'],    true) ?? LayoutSchema::DEFAULT_LAYOUT;
            $tabOrder = json_decode($row['tab_order_json'], true) ?? LayoutSchema::DEFAULT_TAB_ORDER;

            // An explicit Reset to template is a recipe, not an old personal
            // grid. Preserve its template ordering and let the client pack
            // the active widgets; the legacy personal-layout merge would
            // insert new widgets at fixed coordinates and disturb the recipe.
            $isTemplateReset = ($row['origin'] ?? null) === LayoutMapper::ORIGIN_TEMPLATE;
            $layout   = $isTemplateReset ? $this->mergeDefaultWidgets($layout) : $this->mergeNewWidgets($layout);
            $tabOrder = $this->mergeNewTabs($tabOrder);

            // A row written before v4.13.33 carries no origin. Classified on
            // the spot, every time, rather than written back: this is a GET,
            // and in this app a GET does not mutate (CLAUDE.md). The
            // comparison is over the fifteen items of one layout, so paying it
            // per team open is cheaper than the rule it would cost.
            $origin = $row['origin'] ?? $this->classifyStoredLayout($layout);

            // v4.13.35 — a member's own arrangement wins only if they made it
            // *after* the strongest applicable default was set. See the
            // docblock: this one comparison is what makes "I set a default and
            // it applied" true without ever overwriting anybody's work.
            if ($origin !== LayoutMapper::ORIGIN_AUTO
                && (int)$row['updated_at'] >= $cascade['updatedAt']) {
                $this->logger->debug('[TeamHub][LayoutController] getLayout — member arrangement', [
                    'teamId' => $teamId, 'userId' => $userId, 'items' => count($layout),
                ]);
                return [$layout, $tabOrder, $isTemplateReset];
            }

            // Either nobody arranged the grid, or a default has been set since
            // they did — so the grid cascades, but **the tab order from this
            // row is kept**. The two live in one row and are
            // not one decision: reordering tabs is deliberately not an
            // arrangement of the home (it saves with `origin = 'auto'`, so it
            // cannot take a member off the default cascade), and this row is
            // the only place that reorder is stored. Cascading both would
            // throw the tab order away on the very next load.
            $this->logger->debug('[TeamHub][LayoutController] getLayout — cascading the grid, keeping the row tab order', [
                'teamId' => $teamId, 'userId' => $userId,
                'isSystemDefault' => $userDefault['isSystemDefault'],
            ]);

            [$cascaded, $cascadedTabs] = $this->mergedPair($cascade['source'], $userDefault);
            return [$cascaded, $tabOrder ?: $cascadedTabs, true];
        }

        $this->logger->debug('[TeamHub][LayoutController] getLayout — no row, cascading to default', [
            'teamId' => $teamId, 'userId' => $userId,
            'isSystemDefault' => $userDefault['isSystemDefault'],
        ]);

        [$cascaded, $cascadedTabs] = $this->mergedPair($cascade['source'], $userDefault);
        return [$cascaded, $cascadedTabs, true];
    }

    /**
     * Decide once which fallback wins and when it was published.
     * Team enforcement is handled before this method: it is a policy, not a
     * fallback. In flexible mode, the precedence is team apply-once,
     * personal default for this template, admin template, shipped default.
     * A member's explicit team edit made
     * after the winning administrator default still takes precedence.
     *
     * @return array{source: array, updatedAt: int}
     */
    private function resolveCascadeSource(string $teamId, array $userDefault, ?array $teamDefault): array {
        // The template key lives in teamhub_team_type; the creation wizard
        // persists it before offering to open the new team.
        $template = $this->layoutDefaults->forTemplate($this->teamTypeService->getType($teamId));
        return LayoutCascadePolicy::resolve($teamDefault, $template, $userDefault);
    }

    /**
     * Merge a default's layout and tab order for this release's widget set,
     * falling back to the member's own tab order when the default carries none
     * — an administrator who arranged a grid has not necessarily arranged a
     * tab bar, and an empty tab order would read as "this team has no tabs".
     *
     * @return array{0: array, 1: array}
     */
    private function mergedPair(array $source, array $userDefault): array {
        return [
            // Keep the administrator's recipe in its declared order; the
            // client packs only the active widgets. Legacy insertion logic
            // would inject project-health at y=2 and scramble this order.
            $this->mergeDefaultWidgets($source['layout']),
            $this->mergeNewTabs($source['tabOrder'] ?: $userDefault['tabOrder']),
        ];
    }

    /**
     * Add newly shipped widgets WITHOUT changing the explicitly chosen order
     * or positions of a template recipe / enforced team preset.
     *
     * Historical mergeNewWidgets() also moves project-health up to y=2. That
     * is appropriate when healing an old personal row, but it interrupts a
     * template whose editor intentionally orders widgets by y=0, 1, 2, …
     * Newly introduced widgets belong AFTER the configured ones instead.
     *
     * @param array<int,array<string,mixed>> $layout
     * @return array<int,array<string,mixed>>
     */
    private function mergeDefaultWidgets(array $layout, bool $keepCoordinates = false): array {
        $existing = [];
        $lastPosition = 0;
        foreach ($layout as $item) {
            $existing[$item['i']] = true;
            $lastPosition = max($lastPosition, (int)$item['y'] + ($keepCoordinates ? (int)$item['h'] : 0));
        }
        foreach (LayoutSchema::DEFAULT_LAYOUT as $defaultItem) {
            if (isset($existing[$defaultItem['i']])) {
                continue;
            }
            $next = $defaultItem;
            $next['x'] = 0;
            $next['y'] = $keepCoordinates ? $lastPosition : ++$lastPosition;
            if ($keepCoordinates) {
                $lastPosition += (int)$next['h'];
            }
            $layout[] = $next;
        }
        return $layout;
    }

    /**
     * Decide what a row written before v4.13.33 is, and record it.
     *
     * ## Why there is anything to decide
     *
     * Every row written between v4.0.8 and v4.13.32 came from the auto-fit
     * pass, which ran on **first team open** and saved unconditionally — before
     * the member had touched a card. Those rows are indistinguishable from a
     * real arrangement by their existence alone, which is what the `origin`
     * column fixes going forward and what this method settles for the rows
     * already in the table.
     *
     * ## What it compares, and what it deliberately does not
     *
     * The auto-fit pass could only ever change a widget's **height**: it grew
     * `h` and `hSaved` and stripped its own flag, and it never moved, widened
     * or collapsed anything. (In fact it never grew anything either — the node
     * it measured, `.teamhub-widget-card`, carries `overflow: hidden`, so its
     * `scrollHeight` always equalled its `clientHeight`. That is the bug this
     * release fixes; see DESIGN §2.206.) So a row that still stands where the
     * system default puts it is a row nobody has arranged, and it is safe to
     * recompute.
     *
     * **Height is excluded from the comparison on purpose**, and it is the one
     * judgement in here. This release changes the default height of every
     * row-listing widget, so a comparison that included `h` would call every
     * single pre-existing row a customisation and the fix would reach nobody —
     * which is precisely the complaint it answers. The cost is narrow and real:
     * a member who resized a widget's height and never moved, widened,
     * reordered or collapsed anything is read as unarranged and gets the
     * packed default. Everything else they could have done is preserved.
     *
     * ## Why the answer is not written back
     *
     * It would be written on a GET, and a GET in this app does not mutate
     * (CLAUDE.md). It is also not needed: the comparison is pure and
     * deterministic over the items of one layout, so recomputing it per team
     * open gives the same answer every time for a fraction of the cost of the
     * request it rides on. The column is filled the moment the member
     * arranges something, which is the only transition that matters — and a
     * later rollout that wants to find unarranged rows can run this same
     * comparison rather than trusting a value a read once guessed.
     *
     * @param array $layout the stored layout, already merged
     *
     * @return string LayoutMapper::ORIGIN_AUTO or ::ORIGIN_USER
     */
    private function classifyStoredLayout(array $layout): string {
        return $this->looksUnarranged($layout)
            ? LayoutMapper::ORIGIN_AUTO
            : LayoutMapper::ORIGIN_USER;
    }

    /**
     * Whether every item in $layout still stands where the system default puts
     * it. See classifyStoredLayout() for what this is for and why height is not
     * part of it.
     *
     * ## Absolute `y` is not compared, relative order is
     *
     * `mergeNewWidgets()` appends a widget that did not exist when a row was
     * saved at the bottom of its column, while `DEFAULT_LAYOUT` carries the
     * coordinate a fresh team gets. Those two are the same arrangement and
     * different numbers, and they drift further apart with every widget the
     * product adds — so comparing `y` would read "this row is older than the
     * Timeline widget" as "this member rearranged their home".
     *
     * What survives that and still catches every edit a member can make is:
     * the same widgets, each in the same **column** (`x`) at the same
     * **width** (`w`) and the same collapsed state, in the same **order** down
     * each column.
     *
     * The two TOP_INSERTED_WIDGETS are left out of the order comparison, and
     * only out of that one. Their position comes from an insertion that shifts
     * whatever is already in the column, so where they land depends on which
     * version the row was written at and in which order the two were inserted
     * — it says nothing about the member. Their column, width and collapsed
     * state are compared like everything else, so dragging one out of the
     * right-hand column still counts as arranging.
     */
    private function looksUnarranged(array $layout): bool {
        $place = static function (array $items): array {
            $map = [];
            foreach ($items as $item) {
                if (!is_array($item) || !isset($item['i'])) {
                    continue;
                }
                $map[(string)$item['i']] = [
                    'x'         => (int)($item['x'] ?? 0),
                    'y'         => (int)($item['y'] ?? 0),
                    'w'         => (int)($item['w'] ?? 0),
                    'collapsed' => !empty($item['collapsed']),
                ];
            }
            ksort($map);
            return $map;
        };

        // The default goes through the same merge the stored layout did, so
        // both sides have had the same legacy ids pruned and the same
        // top-of-column insertion applied.
        $stored  = $place($layout);
        $default = $place($this->mergeNewWidgets(LayoutSchema::DEFAULT_LAYOUT));

        // v4.13.35 — an integration's widget is not evidence of arranging, and
        // treating it as evidence took every team that has one off the cascade.
        //
        // A card for a registered integration (`widget-int-…`) or an
        // integration package's capability (`widget-int-cap-…`) is created by
        // the **client**, in `getOrCreateIntegrationItem()`, at the bottom of
        // the right-hand column, with nobody choosing anything. It is in the
        // stored row and it is not in DEFAULT_LAYOUT, so the key-set comparison
        // below used to fail on its account alone — and every member of every
        // team with a TOPdesk card read as having arranged their home. That is
        // what "I set the default and it didn't change anything" was: the
        // default was fine and nobody was on the cascade to receive it.
        //
        // They are dropped from the comparison rather than compared loosely,
        // because there is nothing to compare them against: the default has no
        // opinion about where they go. Moving one is still caught — it changes
        // the column or the order of the cards that *are* compared, which is
        // what arranging a grid does.
        $isComparable = static fn (string $id): bool => !str_starts_with($id, 'widget-int-');
        $stored = array_filter($stored, $isComparable, ARRAY_FILTER_USE_KEY);

        if (array_keys($stored) !== array_keys($default)) {
            return false;
        }

        foreach ($default as $id => $expected) {
            if ($stored[$id]['x'] !== $expected['x']
                || $stored[$id]['w'] !== $expected['w']
                || $stored[$id]['collapsed'] !== $expected['collapsed']) {
                return false;
            }
        }

        return $this->columnOrder($stored) === $this->columnOrder($default);
    }

    /**
     * The widget ids of each column, top to bottom, keyed by column.
     *
     * Ties on `y` are broken by id so the result is a function of the layout
     * and not of array order — two rows holding the same arrangement must
     * produce the same answer whichever way round they were built.
     *
     * @param array<string, array{x:int, y:int, w:int, collapsed:bool}> $placed
     *
     * @return array<int, string[]>
     */
    private function columnOrder(array $placed): array {
        $columns = [];
        foreach ($placed as $id => $item) {
            if (in_array($id, LayoutSchema::TOP_INSERTED_WIDGETS, true)) {
                continue;
            }
            // v4.13.35 — and an integration's card, for the reason in
            // looksUnarranged(): the client placed it, so where it sits in a
            // column says nothing about the member. Dropped here as well as
            // there, because one left in a column shifts everything below it
            // and the order comparison would fail on that alone.
            if (str_starts_with($id, 'widget-int-')) {
                continue;
            }
            $columns[$item['x']][] = ['id' => $id, 'y' => $item['y']];
        }

        ksort($columns);
        foreach ($columns as $x => $items) {
            usort($items, static fn (array $a, array $b): int
                => [$a['y'], $a['id']] <=> [$b['y'], $b['id']]);
            $columns[$x] = array_column($items, 'id');
        }

        return $columns;
    }

    /**
     * Everything on the layout bundle that is not the layout itself
     * (v4.13.33 — extracted from the two copies `getLayout()` used to hold).
     *
     * Per-team facts the team page needs before it can render anything, which
     * is why they ride this one request rather than getting their own: each
     * added one would otherwise show as a flash on team open. Membership is
     * verified by the caller.
     */
    private function teamBundle(string $teamId, string $userId): array {
        return [
            'presenceConfig'         => $this->presenceTeamService->getConfig($teamId),
            'presenceModuleEnabled'  => $this->isPresenceModuleEnabled(),
            'decisionsConfig'        => $this->decisionTeamService->getConfig($teamId),
            'decisionsModuleEnabled' => $this->isDecisionsModuleEnabled(),
            'timelineConfig'         => [
                'timeline_enabled' => $this->config->getAppValue(Application::APP_ID, 'timeline_enabled_' . $teamId, '1') === '1',
                // NC 34 / Deck 1.18+ only — gates the "Deck card dependencies"
                // connector toggle on the Timeline tab (v3.78.8). Detected via
                // DbIntrospectionService; absent on older Deck installs.
                'card_dependencies_supported' => $this->timelineService->isCardDependencySupported(),
            ],
            'messagesConfig'         => [
                'messages_enabled' => $this->config->getAppValue(Application::APP_ID, 'messages_enabled_' . $teamId, '1') === '1',
            ],
            // v4.3.3 — Collectives team-app. Default OFF (teams opt in), same
            // shape as messagesConfig/timelineConfig so the frontend can gate
            // the Wiki widget + tab without a second fetch. `installed` lets
            // the Manage Team toggle show "Not installed" instead of erroring
            // when the Collectives NC app isn't present.
            'collectivesConfig'      => [
                'collectives_enabled'   => $this->collectivesService->isEnabledForTeam($teamId),
                'collectives_installed' => $this->collectivesService->isInstalled(),
            ],
            // Team-wide dashboard customization: owner/admin-hidden widgets +
            // the default tab opened on team entry. Read by every member;
            // written only by admins (TeamController::saveDashboardConfig).
            'dashboardConfig'        => $this->dashboardConfigFor($teamId),
            // v4.0.2 — the team's template key, or null for a legacy team
            // (pre-4.0.2) with no row, which hides the badge.
            //
            // v4.13.28 — any key in `teamhub_template`, not one of five: an
            // administrator can create a template since v4.13.24. The key
            // alone is no longer enough to render a badge from, so the row's
            // facts ride along beside it; `TeamWidgetGrid` applies
            // `templateDisplayName()` to them. See TeamTypeService.
            'teamType'               => $this->teamTypeService->getType($teamId),
            'teamTemplate'           => $this->teamTypeService->getTemplateFor($teamId),
            // v4.6.13 — optional expiration date, or null when the team has
            // none: every team predating the feature, and every Department.
            // Rides this bundle rather than getting its own endpoint, for the
            // reason HANDOFF.md records about the project fact — the Team info
            // banner needs it on every team load, and a second request would
            // put a round trip in that path.
            'teamExpiry'             => $this->teamExpiryService->getExpiry($teamId),
            'budgetConfig'           => [
                'budget_enabled'   => $this->config->getAppValue(Application::APP_ID, 'budget_enabled_' . $teamId, '1') === '1',
                // v3.94.0 — tab visibility uses a project-level role floor
                // (project.budget_view_min_level) with an editor-override
                // exception. Precomputed here so the frontend can gate the
                // tab without a second fetch.
                'can_view_budget'  => $this->budgetService->canUserViewBudgetTab($teamId, $userId),
            ],
            'timeConfig'             => [
                'time_enabled'   => $this->config->getAppValue(Application::APP_ID, 'time_enabled_' . $teamId, '1') === '1',
                // v3.96.0 — same precomputed-can-view pattern as budgetConfig.
                // Tab visibility: level ≥ project.time_view_min_level OR user
                // is a named project participant (teamhub_project_member row).
                'can_view_time'  => $this->timeService->canUserViewTimeTab($teamId, $userId),
            ],
            'project'                => $this->projectFacts($teamId),
            // v4.9.3 — OpenProject link facts. Cheap (one row, no HTTP) and
            // per team, so the Project info widget can gate itself
            // the same way the Wiki widget does on collectivesConfig.
            'openProjectConfig'      => $this->openProjectLinks->factsForBundle($teamId),
            // v4.9.6 — is this team's provisioning finished (null for a team
            // that was never provisioned). The team page's banner reads it.
            'provisioning'           => $this->provisioningMapper->latestSummaryForTeam($teamId),
            // v4.10.27 — is this team a desk the viewer works? Gates the
            // queue and statistics widgets (src/lib/activeWidgets.js); the
            // routes behind them check the same rule again.
            'serviceDeskConfig'      => $this->serviceDeskFacts($teamId, $userId),
            // v4.13.1 — the team banner's URL, or null when the team has
            // none. Rides this bundle for the reason teamExpiry does: the
            // strip above the content needs it on every team open, and the
            // teams *list* deliberately does not carry it — one app-data
            // lookup per open is cheap, one per team in the sidebar is not.
            'teamBanner'             => ['url' => $this->teamBannerService->getBannerUrl($teamId)],
        ];
    }

    /**
     * v4.10.27 — whether this team is a service desk the viewer works, and
     * whether they administer it. `isDesk` is the eligibility rule itself
     * (licensed, an active service team, a member of it), so a widget gated
     * on it can never show to somebody the queue route would refuse.
     *
     * v4.10.34 — `isServiceTeam`: licensed and a team created from the
     * Service template, desk or not yet — what the *Services* widget gates
     * on, because publishing the first service is what makes a team a desk.
     * The bundle is only built for a member of the team, which is the
     * builder route's read gate. `canBuild`: a team admin of it, the
     * builder's write gate.
     *
     * v4.10.50 — `handlesAdoption`: a desk member of the team that holds
     * the adoption service. The teams-made-outside-TeamHub grid is a widget
     * on that team's home; its routes ask the same rule
     * (`TeamAdoptionDecisionService::mayDecide()` — which also lets a
     * Nextcloud administrator in, from Admin → TeamHub).
     *
     * @return array{isDesk: bool, isAdmin: bool, isServiceTeam: bool, canBuild: bool, handlesAdoption: bool}
     */
    private function serviceDeskFacts(string $teamId, string $userId): array {
        try {
            $isDesk        = $this->serviceTeams->isEligibleAgent($userId, $teamId);
            $isServiceTeam = $this->serviceTeams->isAvailable()
                && $this->teamTypeService->getType($teamId) === 'service';
            $isOwner       = ($isDesk || $isServiceTeam) && $this->serviceTeams->isServiceOwner($userId, $teamId);
            return [
                'isDesk'        => $isDesk,
                'isAdmin'       => $isDesk && $isOwner,
                'isServiceTeam' => $isServiceTeam,
                'canBuild'      => $isServiceTeam && $isOwner,
                'handlesAdoption' => $isDesk
                    && $this->serviceTeams->serviceTeamForDefinition(TeamAdoptionDefinition::KEY) === $teamId,
            ];
        } catch (\Throwable $e) {
            return ['isDesk' => false, 'isAdmin' => false, 'isServiceTeam' => false, 'canBuild' => false, 'handlesAdoption' => false];
        }
    }

    /**
     * Project facts for the layout bundle. Membership is already verified above,
     * so this is best-effort: any failure degrades to "not a project" rather than
     * breaking the whole layout response (mirrors the other config here).
     *
     * @return array{isProject:bool, mode:?string, phase:?string, startDate:?int, targetEnd:?int}
     */
    private function projectFacts(string $teamId): array {
        try {
            return $this->projectService->getForTeam($teamId);
        } catch (\Throwable $e) {
            return ['isProject' => false, 'mode' => null, 'phase' => null, 'startDate' => null, 'targetEnd' => null];
        }
    }

    // ----------------------------------------------------------------
    // PUT /api/v1/teams/{teamId}/layout
    // ----------------------------------------------------------------

    #[NoAdminRequired]
    public function saveLayout(string $teamId): JSONResponse {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if (!$this->isValidId($teamId)) {
            $this->logger->warning('[TeamHub][LayoutController] saveLayout — invalid teamId', [
                'teamId' => $teamId, 'userId' => $userId,
            ]);
            return new JSONResponse(['error' => 'Invalid team ID'], Http::STATUS_BAD_REQUEST);
        }

        if (!$this->isMember($teamId, $userId)) {
            $this->logger->warning('[TeamHub][LayoutController] saveLayout — non-member access attempt', [
                'teamId' => $teamId, 'userId' => $userId,
            ]);
            return new JSONResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
        }

        // A member (including a team admin acting as a member) cannot save a
        // private grid while the team publishes an enforced layout. The
        // team's authorised dashboard-config route is the only writer for that
        // grid. Check here, not only in Vue: stale tabs and direct API calls
        // must not overwrite a personal arrangement that will reappear later.
        if (TeamLayoutPolicy::locksPersonalGrid($this->layoutDefaults->forTeam($teamId))) {
            return new JSONResponse(['error' => 'This team layout is managed by a team administrator'], Http::STATUS_FORBIDDEN);
        }

        $params = $this->request->getParams();
        [$cleanLayout, $cleanTabOrder, $error] = $this->validateAndClean($params, $userId, 'saveLayout');
        if ($error !== null) {
            return $error;
        }

        $layoutJson   = json_encode($cleanLayout,   JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $tabOrderJson = json_encode($cleanTabOrder, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (strlen($layoutJson) + strlen($tabOrderJson) > LayoutSchema::MAX_PAYLOAD_BYTES) {
            $this->logger->warning('[TeamHub][LayoutController] saveLayout — payload too large', [
                'userId' => $userId, 'teamId' => $teamId,
            ]);
            return new JSONResponse(['error' => 'Payload too large'], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
        }

        // v4.13.33 — who is arranging. `user` marks the row as a member's own
        // arrangement, which nothing afterwards overwrites; anything else is a
        // write TeamHub made on their behalf and leaves the row where it was on
        // the cascade. The mapper only ever promotes, so a tab reorder (which
        // says nothing about the grid) cannot undo a drag.
        $origin = $this->request->getParam('origin') === LayoutMapper::ORIGIN_USER
            ? LayoutMapper::ORIGIN_USER
            : LayoutMapper::ORIGIN_AUTO;

        try {
            $this->layoutMapper->upsert($userId, $teamId, $layoutJson, $tabOrderJson, $origin);
            $this->logger->debug('[TeamHub][LayoutController] saveLayout — saved', [
                'teamId' => $teamId, 'userId' => $userId, 'items' => count($cleanLayout),
                'origin' => $origin,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][LayoutController] saveLayout — DB error: ' . $e->getMessage(), [
                'userId' => $userId, 'teamId' => $teamId,
            ]);
            return new JSONResponse(['error' => 'Failed to save layout'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(['status' => 'ok']);
    }

    // ----------------------------------------------------------------
    // DELETE /api/v1/teams/{teamId}/layout
    //
    // Reset this member's current team to the NC admin template recipe,
    // explicitly bypassing their type default without affecting other teams.
    // ----------------------------------------------------------------

    #[NoAdminRequired]
    public function resetLayout(string $teamId): JSONResponse {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if (!$this->isValidId($teamId)) {
            $this->logger->warning('[TeamHub][LayoutController] resetLayout — invalid teamId', [
                'teamId' => $teamId, 'userId' => $userId,
            ]);
            return new JSONResponse(['error' => 'Invalid team ID'], Http::STATUS_BAD_REQUEST);
        }

        if (!$this->isMember($teamId, $userId)) {
            $this->logger->warning('[TeamHub][LayoutController] resetLayout — non-member access attempt', [
                'teamId' => $teamId, 'userId' => $userId,
            ]);
            return new JSONResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
        }

        if (TeamLayoutPolicy::locksPersonalGrid($this->layoutDefaults->forTeam($teamId))) {
            return new JSONResponse(['error' => 'This team layout is managed by a team administrator'], Http::STATUS_FORBIDDEN);
        }

        try {
            // Reset explicitly to the Nextcloud admin's template recipe,
            // not to the personal *type* default. Saving this as the member's
            // own grid means a reset stays put until they change it or publish
            // a new type default. Tab navigation remains a personal choice.
            $template = $this->layoutDefaults->forTemplate($this->teamTypeService->getType($teamId));
            $layout = $template['layout'] ?? LayoutSchema::DEFAULT_LAYOUT;
            $existing = $this->layoutMapper->find($userId, $teamId);
            $tabOrder = $existing !== null
                ? (json_decode($existing['tab_order_json'], true) ?? LayoutSchema::DEFAULT_TAB_ORDER)
                : ($template['tabOrder'] ?? LayoutSchema::DEFAULT_TAB_ORDER);
            $this->layoutMapper->upsert(
                $userId,
                $teamId,
                json_encode(LayoutSchema::sanitiseLayout($layout), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                json_encode(LayoutSchema::sanitiseTabOrder($tabOrder), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                LayoutMapper::ORIGIN_TEMPLATE,
            );
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][LayoutController] resetLayout — DB error: ' . $e->getMessage(), [
                'userId' => $userId, 'teamId' => $teamId,
            ]);
            return new JSONResponse(['error' => 'Failed to reset layout'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $this->logger->debug('[TeamHub][LayoutController] resetLayout — reset to template layout', [
            'teamId' => $teamId, 'userId' => $userId,
        ]);

        // Deliberately no layout in the response. The client refetches and
        // packs the exact template recipe for the widgets in this team.
        return new JSONResponse(['status' => 'ok']);
    }

    // ----------------------------------------------------------------
    // PUT /api/v1/teams/{teamId}/layout/tabs
    // ----------------------------------------------------------------

    /**
     * Save navigation order without touching the grid, its origin or its
     * updated_at. This works even in enforce mode: navigating between tabs
     * is a personal preference, not a widget placement decision.
     */
    #[NoAdminRequired]
    public function saveTabOrder(string $teamId): JSONResponse {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->isValidId($teamId)) {
            return new JSONResponse(['error' => 'Invalid team ID'], Http::STATUS_BAD_REQUEST);
        }
        if (!$this->isMember($teamId, $userId)) {
            return new JSONResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
        }

        $order = $this->request->getParam('tabOrder');
        if (!is_array($order)) {
            return new JSONResponse(['error' => 'tabOrder must be an array'], Http::STATUS_BAD_REQUEST);
        }
        $cleanOrder = LayoutSchema::sanitiseTabOrder($order);
        $tabJson = json_encode($cleanOrder, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($tabJson) > LayoutSchema::MAX_PAYLOAD_BYTES) {
            return new JSONResponse(['error' => 'Payload too large'], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
        }
        try {
            $this->layoutMapper->saveTabOrder($userId, $teamId, $tabJson);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][LayoutController] saveTabOrder — failed', [
                'teamId' => $teamId, 'userId' => $userId, 'exception' => $e,
            ]);
            return new JSONResponse(['error' => 'Failed to save tab order'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        return new JSONResponse(['status' => 'ok']);
    }

    // ----------------------------------------------------------------
    // GET /api/v1/layout/default?teamId={teamId}
    // ----------------------------------------------------------------

    #[NoAdminRequired]
    public function getDefaultLayout(): JSONResponse {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $teamId = (string)$this->request->getParam('teamId', '');
        $error = $this->validateDefaultScope($teamId, $userId);
        if ($error !== null) {
            return $error;
        }
        $userDefault = $this->resolveUserDefault($userId, $this->teamTypeService->getType($teamId));

        // Same merge as getLayout() — a saved personal default predates new
        // widgets/tabs just as easily as a team-specific layout does.
        $mergedLayout   = $this->mergeNewWidgets($userDefault['layout']);
        $mergedTabOrder = $this->mergeNewTabs($userDefault['tabOrder']);

        $this->logger->debug('[TeamHub][LayoutController] getDefaultLayout — fetched', [
            'userId' => $userId, 'isSystemDefault' => $userDefault['isSystemDefault'],
            'items'  => count($mergedLayout),
        ]);

        return new JSONResponse([
            'layout'          => $mergedLayout,
            'tabOrder'        => $mergedTabOrder,
            'isSystemDefault' => $userDefault['isSystemDefault'],
        ]);
    }

    // ----------------------------------------------------------------
    // PUT /api/v1/layout/default   (requires teamId in request payload)
    //
    // Saves this user's default for teams of the SAME template only.
    // Stored in oc_preferences via IConfig — no DB migration required.
    // ----------------------------------------------------------------

    #[NoAdminRequired]
    public function saveDefaultLayout(): JSONResponse {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        // Derive the type from this member's actual team. A client-provided
        // templateKey cannot be trusted as the scope of a preference write.
        $teamId = (string)$this->request->getParam('teamId', '');
        $error = $this->validateDefaultScope($teamId, $userId);
        if ($error !== null) {
            return $error;
        }
        if (TeamLayoutPolicy::locksPersonalGrid($this->layoutDefaults->forTeam($teamId))) {
            return new JSONResponse(['error' => 'This team layout is managed by a team administrator'], Http::STATUS_FORBIDDEN);
        }
        $templateKey = $this->teamTypeService->getType($teamId);
        $params = $this->request->getParams();
        [$cleanLayout, $cleanTabOrder, $error] = $this->validateAndClean($params, $userId, 'saveDefaultLayout');
        if ($error !== null) {
            return $error;
        }

        try {
            $layoutJson   = json_encode($cleanLayout,   JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $tabOrderJson = json_encode($cleanTabOrder, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new JSONResponse(['error' => 'Invalid layout data'], Http::STATUS_BAD_REQUEST);
        }

        if (strlen($layoutJson) + strlen($tabOrderJson) > LayoutSchema::MAX_PAYLOAD_BYTES) {
            $this->logger->warning('[TeamHub][LayoutController] saveDefaultLayout — payload too large', [
                'userId' => $userId,
            ]);
            return new JSONResponse(['error' => 'Payload too large'], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
        }

        $this->config->setUserValue($userId, 'teamhub', UserTemplateLayoutKeys::layout($templateKey), $layoutJson);
        $this->config->setUserValue($userId, 'teamhub', UserTemplateLayoutKeys::tabOrder($templateKey), $tabOrderJson);

        // A type default is an explicit rollout by this user, NOT just a
        // fallback for teams they have never opened. Release their existing
        // per-team grid overrides for all teams of this type that are not
        // locked by a team admin; preserve their personal tab orders.
        // Never modify other users' rows or an enforced team's saved row.
        try {
            $unlocked = [];
            $userTeamIds = $this->layoutMapper->findTeamIdsForUser($userId);
            // Chunk the type lookup to keep query parameters bounded even
            // for members of hundreds of teams. No row means the new default
            // is already effective without a database write.
            foreach (array_chunk($userTeamIds, 200) as $teamIds) {
                $types = $this->teamTypeService->getTypesForTeams($teamIds);
                foreach ($teamIds as $matchingTeamId) {
                    if (($types[$matchingTeamId] ?? null) === $templateKey
                        && !TeamLayoutPolicy::locksPersonalGrid($this->layoutDefaults->forTeam($matchingTeamId))) {
                        $unlocked[] = $matchingTeamId;
                    }
                }
            }
            $this->layoutMapper->releasePersonalGrids($userId, $unlocked);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][LayoutController] saveDefaultLayout — failed to propagate type default', [
                'userId' => $userId, 'templateKey' => $templateKey, 'exception' => $e,
            ]);
            return new JSONResponse(['error' => 'Default saved but could not be applied to existing teams'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $this->logger->debug('[TeamHub][LayoutController] saveDefaultLayout — saved template-scoped user default', [
            'userId' => $userId, 'templateKey' => $templateKey, 'items' => count($cleanLayout),
        ]);

        return new JSONResponse(['status' => 'ok', 'templateKey' => $templateKey]);
    }

    // ----------------------------------------------------------------
    // Private helpers
    // ----------------------------------------------------------------

    /**
     * Check the user's membership and resolve a template from the server,
     * for both personal-default endpoints. Untyped legacy teams cannot have
     * a "type" default: don't silently write another global preference.
     */
    private function validateDefaultScope(string $teamId, string $userId): ?JSONResponse {
        if (!$this->isValidId($teamId)) {
            return new JSONResponse(['error' => 'A valid teamId is required'], Http::STATUS_BAD_REQUEST);
        }
        if (!$this->isMember($teamId, $userId)) {
            return new JSONResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
        }
        $key = $this->teamTypeService->getType($teamId);
        if ($key === null || $key === '') {
            return new JSONResponse(['error' => 'This team has no template'], Http::STATUS_BAD_REQUEST);
        }
        try {
            UserTemplateLayoutKeys::layout($key);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => 'Invalid team template'], Http::STATUS_BAD_REQUEST);
        }
        return null;
    }

    /**
     * Resolve the user's default for exactly one team template. Legacy
     * global preferences are deliberately NOT used across template types.
     *
     * @return array{layout: array, tabOrder: array, isSystemDefault: bool}
     */
    private function resolveUserDefault(string $userId, ?string $templateKey): array {
        $layoutJson = '';
        $tabOrderJson = '';
        if ($templateKey !== null && $templateKey !== '') {
            try {
                $layoutJson = $this->config->getUserValue($userId, 'teamhub', UserTemplateLayoutKeys::layout($templateKey), '');
                $tabOrderJson = $this->config->getUserValue($userId, 'teamhub', UserTemplateLayoutKeys::tabOrder($templateKey), '');
            } catch (\InvalidArgumentException $e) {
                // Old, unrecognised team types still receive system defaults.
            }
        }

        if ($layoutJson === '') {
            return [
                'layout'          => LayoutSchema::DEFAULT_LAYOUT,
                'tabOrder'        => LayoutSchema::DEFAULT_TAB_ORDER,
                'isSystemDefault' => true,
            ];
        }

        return [
            'layout'          => json_decode($layoutJson,   true) ?? LayoutSchema::DEFAULT_LAYOUT,
            'tabOrder'        => json_decode($tabOrderJson, true) ?? LayoutSchema::DEFAULT_TAB_ORDER,
            'isSystemDefault' => false,
        ];
    }

    /**
     * Shared validation and sanitisation for layout + tabOrder payloads.
     * Returns [$cleanLayout, $cleanTabOrder, $errorResponse].
     * $errorResponse is non-null on validation failure.
     */
    private function validateAndClean(array $params, string $userId, string $context): array {
        $layout   = $params['layout']   ?? null;
        $tabOrder = $params['tabOrder'] ?? null;

        // The two 400s are this endpoint's own and not the cleaner's. A member
        // saving their home posts both keys or has a bug; a default's writer
        // posts whatever an administrator's editor produced, and refusing that
        // outright would lose the arrangement over one malformed field. So the
        // shape is checked here and the contents are cleaned there.
        if (!is_array($layout)) {
            return [[], [], new JSONResponse(['error' => 'layout must be an array'], Http::STATUS_BAD_REQUEST)];
        }
        if (!is_array($tabOrder)) {
            return [[], [], new JSONResponse(['error' => 'tabOrder must be an array'], Http::STATUS_BAD_REQUEST)];
        }

        // v4.13.35 — one cleaner, in LayoutSchema. This method had its own
        // copy of the widget vocabulary, the field clamping and the tab-key
        // test, beside the copy v4.13.34 put in that class for the three
        // default writers. Four writers, two cleaners, and a widget id one
        // accepted and the other dropped would have been a card an
        // administrator could put in a default that then vanished from every
        // team that took it. Nothing announces that.
        $cleanLayout   = LayoutSchema::sanitiseLayout($layout);
        $cleanTabOrder = LayoutSchema::sanitiseTabOrder($tabOrder);

        $dropped = count($layout) - count($cleanLayout);
        if ($dropped > 0) {
            $this->logger->debug('[TeamHub][LayoutController] ' . $context . ' — dropped unrecognised layout items', [
                'dropped' => $dropped, 'userId' => $userId,
            ]);
        }

        return [$cleanLayout, $cleanTabOrder, null];
    }

    /**
     * Team-wide dashboard config emitted in the layout bundle. Kept in sync
     * with TeamController::readDashboardConfig (same app-config keys/defaults).
     *
     * @return array{hidden_widgets: string[], default_tab: string}
     */
    private function dashboardConfigFor(string $teamId): array {
        // v4.13.35 — the team layout's two fields, because this reader and
        // `TeamController::readDashboardConfig()` feed the **same** store
        // value and the Manage team screen reads it from here.
        //
        // v4.13.34 added them to that reader only. The team layout saved
        // correctly and the screen that administers it said "This team has no
        // layout of its own" and offered no mode picker, because
        // `saveAsTeamDefault` refetches the layout bundle and the bundle's copy
        // of `dashboardConfig` had never heard of them. Justin, after saving
        // one five times. The two readers are documented as "kept in sync";
        // that is what the words were for.
        // The empty-string default on `dashboard_hidden_` is load-bearing: it
        // is how "never configured" (which takes
        // DashboardWidgets::DEFAULT_HIDDEN) is told apart from an administrator
        // who stored an empty list because they want every widget shown. See
        // that class's docblock.
        return DashboardWidgets::configFor(
            $this->config->getAppValue(Application::APP_ID, 'dashboard_hidden_' . $teamId, ''),
            $this->config->getAppValue(Application::APP_ID, 'dashboard_tab_' . $teamId, 'msgstream'),
            $this->layoutDefaults->forTeam($teamId),
            $this->config->getAppValue(Application::APP_ID, 'people_anniversary_enabled_' . $teamId, '1') === '1',
        );
    }

    /**
     * Merge any system DEFAULT_LAYOUT widgets that are missing from $layout.
     *
     * Called on every GET so users who saved their layout before a new widget
     * was introduced automatically receive it appended at the bottom of the
     * right-hand column without losing any of their existing positions.
     *
     * New widgets are stacked below the lowest existing item in the same
     * x-column as specified in DEFAULT_LAYOUT, so they do not overlap anything.
     */
    private function mergeNewWidgets(array $layout): array {
        // v4.6.1 — heal parked rows before anything else reads a y coordinate.
        // Every placement calculation below (and in healProjectHealthPosition)
        // takes the max bottom edge of a column; a single item left at y=9999
        // by the old applySnap() would put the next new widget at y≈10002.
        $layout = $this->unparkWidgets($layout);

        // Prune legacy widget IDs that have been consolidated into new ones.
        // widget-files-{favorites,recent,shared} → widget-files-center (v3.62).
        // widget-collectives → widget-pages (v4.3.7 — Wiki content now renders
        // inside the unified Pages widget instead of a standalone card).
        // Filter them out before merging so the new consolidated widget is added.
        // widget-openproject-work → gone (v4.9.5 — its rows moved to My Work
        // and to the Upcoming tasks widget).
        // widget-services → gone (v4.10.44 — the team's services and the
        // builder moved to the Services tab).
        $legacyIds = ['widget-files-favorites', 'widget-files-recent', 'widget-files-shared', 'widget-collectives', 'widget-openproject-work', 'widget-services'];
        $pruned = false;
        $layout = array_values(array_filter($layout, static function (array $item) use ($legacyIds, &$pruned): bool {
            if (in_array($item['i'], $legacyIds, true)) {
                $pruned = true;
                return false;
            }
            return true;
        }));
        if ($pruned) {
            $this->logger->debug('[TeamHub][LayoutController] mergeNewWidgets — pruned legacy files widgets, will add widget-files-center');
        }

        // Build a set of widget IDs already present.
        $existing = [];
        foreach ($layout as $item) {
            $existing[$item['i']] = true;
        }

        $added = false;
        foreach (LayoutSchema::DEFAULT_LAYOUT as $defaultItem) {
            if (isset($existing[$defaultItem['i']])) {
                continue; // Already in the layout — skip.
            }

            // v3.97.0 — widget-project-health is a top-of-column cockpit widget,
            // not a bottom-of-stack append. Insert it at its DEFAULT_LAYOUT y
            // (2, right below widget-teaminfo) and shift every other x=9
            // widget with y >= 2 down by this widget's height. One-time
            // reflow the first time an existing user's layout receives the
            // widget; matches the visual position new users get from
            // DEFAULT_LAYOUT verbatim.
            if (in_array($defaultItem['i'], LayoutSchema::TOP_INSERTED_WIDGETS, true)) {
                $targetY = (int)$defaultItem['y'];
                $shift   = (int)$defaultItem['h'];
                foreach ($layout as &$item) {
                    if ((int)$item['x'] === (int)$defaultItem['x']
                        && (int)$item['y'] >= $targetY) {
                        $item['y'] = (int)$item['y'] + $shift;
                    }
                }
                unset($item);

                $layout[] = $defaultItem;
                $added    = true;

                $this->logger->debug('[TeamHub][LayoutController] mergeNewWidgets — inserted a top-of-column widget', [
                    'widgetId' => $defaultItem['i'], 'atY' => $targetY, 'shiftedBy' => $shift,
                ]);
                continue;
            }

            // Find the lowest y + h in the same x-column so we don't overlap.
            $targetX = $defaultItem['x'];
            $maxBottom = 0;
            foreach ($layout as $item) {
                if ((int)$item['x'] === $targetX) {
                    $bottom = (int)$item['y'] + (int)$item['h'];
                    if ($bottom > $maxBottom) {
                        $maxBottom = $bottom;
                    }
                }
            }

            $newItem = $defaultItem;
            $newItem['y'] = $maxBottom;

            $this->logger->debug('[TeamHub][LayoutController] mergeNewWidgets — adding missing widget', [
                'widgetId' => $defaultItem['i'], 'atY' => $maxBottom,
            ]);

            $layout[] = $newItem;
            $added    = true;
        }

        if ($added) {
            $this->logger->debug('[TeamHub][LayoutController] mergeNewWidgets — layout updated with new widgets', [
                'totalItems' => count($layout),
            ]);
        }

        // v3.97.1 healing pass — the 3.97.0 mergeNewWidgets appended
        // widget-project-health at the max-bottom of column x=9, which
        // could easily be y=24 (below files/decisions). If we still find it
        // there (i.e. its y equals the highest y+h of any OTHER x=9 widget),
        // snap it up to y=2 and shift the rest down. Idempotent: after the
        // first fetch snaps it up, the widget's y is no longer the max in
        // its column and subsequent fetches skip the reflow. Users who
        // intentionally moved the widget somewhere non-bottom keep their
        // placement.
        $layout = $this->healProjectHealthPosition($layout);

        return $layout;
    }

    /**
     * v4.6.1 — pull parked widgets back into the grid.
     *
     * Until 4.6.0, TeamView.applySnap() parked every inactive widget at
     * y=9999 so it would not occupy space, and that value was persisted. The
     * snap is gone (grid-layout-plus compacts on its own now), but saved rows
     * still carry the sentinel, and it poisons every "bottom of this column"
     * calculation that decides where a newly-added widget goes.
     *
     * Each parked item is stacked at the bottom of its own x-column, below the
     * real content. The grid compacts it upward on render, so the exact value
     * only has to be sane, not perfect. Idempotent: once healed, no item is at
     * or above the threshold and this is a no-op.
     */
    private function unparkWidgets(array $layout): array {
        // Bottom edge per x-column, counting only unparked items.
        $columnBottom = [];
        foreach ($layout as $item) {
            $y = (int)($item['y'] ?? 0);
            if ($y >= LayoutSchema::PARK_Y_THRESHOLD) {
                continue;
            }
            $x      = (int)($item['x'] ?? 0);
            $bottom = $y + (int)($item['h'] ?? 3);
            if (!isset($columnBottom[$x]) || $bottom > $columnBottom[$x]) {
                $columnBottom[$x] = $bottom;
            }
        }

        $unparked = 0;
        foreach ($layout as &$item) {
            if ((int)($item['y'] ?? 0) < LayoutSchema::PARK_Y_THRESHOLD) {
                continue;
            }
            $x = (int)($item['x'] ?? 0);
            // Stack rather than pile: two parked widgets in one column must not
            // land on the same y.
            $item['y']        = $columnBottom[$x] ?? 0;
            $columnBottom[$x] = (int)$item['y'] + (int)($item['h'] ?? 3);
            $unparked++;
        }
        unset($item);

        if ($unparked > 0) {
            $this->logger->debug('[TeamHub][LayoutController] unparkWidgets — healed parked widgets', [
                'count' => $unparked,
            ]);
        }

        return $layout;
    }

    /**
     * One-shot heal: if widget-project-health is saved at the very bottom of
     * column x=9 (auto-appended by the pre-3.97.1 mergeNewWidgets), lift it
     * to y=2 and shift every other x=9 widget down by its height.
     *
     * Skips silently when:
     *   - widget-project-health is not in the layout at all (no-op)
     *   - widget-project-health is not at the bottom of column x=9 (user has
     *     moved it, respect their placement)
     */
    private function healProjectHealthPosition(array $layout): array {
        $targetIdx = null;
        foreach ($layout as $idx => $item) {
            if (($item['i'] ?? '') === 'widget-project-health') {
                $targetIdx = $idx;
                break;
            }
        }
        if ($targetIdx === null) {
            return $layout;
        }

        $target  = $layout[$targetIdx];
        $targetX = (int)($target['x'] ?? 0);
        $targetY = (int)($target['y'] ?? 0);
        $targetH = (int)($target['h'] ?? 0);

        if ($targetX !== 9) {
            // User moved it out of the right column entirely — respect that.
            return $layout;
        }

        // Compute the max bottom edge of every OTHER widget in column x=9.
        $maxOtherBottom = 0;
        foreach ($layout as $idx => $item) {
            if ($idx === $targetIdx) continue;
            if ((int)($item['x'] ?? -1) !== 9) continue;
            $bottom = (int)($item['y'] ?? 0) + (int)($item['h'] ?? 0);
            if ($bottom > $maxOtherBottom) {
                $maxOtherBottom = $bottom;
            }
        }

        // Only heal when the widget is sitting at (or below) that max
        // bottom — i.e. was auto-appended by mergeNewWidgets. If its top
        // edge is above that line, it's already been repositioned or the
        // user moved it — leave it alone.
        if ($targetY < $maxOtherBottom) {
            return $layout;
        }

        // Snap to y=2 and shift every other x=9 widget with y >= 2 down.
        foreach ($layout as $idx => &$item) {
            if ($idx === $targetIdx) continue;
            if ((int)($item['x'] ?? -1) !== 9) continue;
            if ((int)($item['y'] ?? 0) >= 2) {
                $item['y'] = (int)$item['y'] + $targetH;
            }
        }
        unset($item);

        $layout[$targetIdx]['y'] = 2;

        $this->logger->debug('[TeamHub][LayoutController] healProjectHealthPosition — snapped widget-project-health to y=2', [
            'wasAtY'      => $targetY,
            'shiftedBy'   => $targetH,
        ]);

        return $layout;
    }

    /**
     * Append any DEFAULT_TAB_ORDER keys that are missing from $tabOrder.
     *
     * Mirrors mergeNewWidgets() — same problem, same fix. A saved tab_order_json
     * row (per-team or per-user-default) is a frozen snapshot from whenever it
     * was last written. When a new built-in tab is introduced (e.g. 'timeline'
     * in v3.77.8), every existing saved row predates it and would never show
     * the new tab without this merge running on every GET.
     *
     * New keys are appended at the end, preserving the user's chosen order for
     * everything else. Order among newly-added keys follows DEFAULT_TAB_ORDER.
     */
    private function mergeNewTabs(array $tabOrder): array {
        $existing = array_flip($tabOrder);
        $added    = false;

        foreach (LayoutSchema::DEFAULT_TAB_ORDER as $key) {
            if (isset($existing[$key])) {
                continue;
            }
            $tabOrder[] = $key;
            $added      = true;
            $this->logger->debug('[TeamHub][LayoutController] mergeNewTabs — adding missing tab', [
                'tabKey' => $key,
            ]);
        }

        if ($added) {
            $this->logger->debug('[TeamHub][LayoutController] mergeNewTabs — tabOrder updated with new tabs', [
                'totalTabs' => count($tabOrder),
            ]);
        }

        return $tabOrder;
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    private function isPresenceModuleEnabled(): bool {
        return $this->config->getAppValue(Application::APP_ID, 'presence_module_enabled', '1') === '1';
    }

    private function isDecisionsModuleEnabled(): bool {
        return $this->config->getAppValue(Application::APP_ID, 'decisions_module_enabled', '1') === '1';
    }

    private function currentUserId(): ?string {
        return $this->userSession->getUser()?->getUID();
    }

    private function isValidId(string $id): bool {
        return $id !== '' && preg_match('/^[a-zA-Z0-9\-]+$/', $id) === 1;
    }



    /**
     * Returns true when the user has direct OR indirect membership in the team.
     * Delegates to MemberService so both circles_member (direct) and
     * circles_membership (indirect via group/sub-team) are checked.
     */
    private function isMember(string $teamId, string $userId): bool {
        $this->logger->debug('[TeamHub][LayoutController] isMember check', [
            'teamId' => $teamId, 'userId' => $userId,
        ]);
        return $this->memberService->isEffectiveMember($teamId, $userId, $this->db);
    }

    /**
     * v4.13.34 — whether this member administers this team (Circles level ≥ 8),
     * which is the gate on every other Manage team setting and therefore the
     * gate on setting the team's default home.
     *
     * Read here rather than asserted: this answer decides whether to *offer* a
     * control, and the write itself is checked again by
     * `TeamController::saveDashboardConfig` through `requireAdminLevel()`.
     * "The frontend won't call this" is not a boundary (CLAUDE.md).
     */
    private function isTeamAdmin(string $teamId, string $userId): bool {
        try {
            return $this->memberService->getEffectiveMemberLevel($this->db, $teamId, $userId) >= 8;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
