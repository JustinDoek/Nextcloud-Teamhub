<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\IntegrationCapabilityMapper;
use OCA\TeamHub\Db\IntegrationPackageMapper;
use OCA\TeamHub\Db\IntegrationVisibilityMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Integration\CapabilityType;
use OCA\TeamHub\Integration\IframeUrl;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * A team administrator's presentation of the integrations their template gave
 * them (v4.13.23).
 *
 * ## What a team administrator may do, and what they may not
 *
 * May: show a widget, hide a widget, show an iframe tab, hide an iframe tab,
 * reorder the visible ones. That is the complete list.
 *
 * May not — and there is no method here for any of it, which is the point:
 * enable or disable the package, add it to the team, remove it from the team,
 * change the template, stop a My Work provider, disable lifecycle behaviour,
 * deregister a capability, touch a provider class, or delete a mapping. Those
 * are a Nextcloud administrator's decisions
 * ({@see IntegrationAdminService}, {@see IntegrationTemplateService}) or
 * nobody's.
 *
 * So Manage Team → Integrations stops being an activation screen. It is a
 * presentation manager: *these are the integrations your template gave you,
 * here is what each one puts on your workspace, choose what to show and in
 * what order.*
 *
 * ## Hiding is a layout decision and nothing else
 *
 * {@see hide()} writes one row with `hidden = 1` against one capability and
 * one team. It does not disable the integration, does not affect My Work, does
 * not stop synchronisation, does not invoke a disconnect hook, does not remove
 * a mapping, does not touch another team, does not touch the template and does
 * not touch another capability of the same package.
 *
 * Two things enforce that rather than promise it. A non-visual capability is
 * **refused** here — {@see assertVisual()} — so there is no way to write a
 * preference against a My Work provider in the first place; and
 * `IntegrationStateService::isCapabilityShown()` returns true for a non-visual
 * capability whatever rows exist, so even a row written by some future
 * mistake could not silence a queue.
 *
 * Deliberately **no lifecycle hook fires** on a show or a hide. A layout
 * preference is not an integration lifecycle event, and giving it a hook is
 * how an external app would start treating a hidden widget as a disconnect.
 *
 * ## A hidden widget's provider is not called
 *
 * {@see renderableWidgetsForTeam()} returns only the shown ones, so rendering
 * a team home never asks a hidden widget's provider for data. That is a
 * performance property and a correctness one: a provider that is not on screen
 * should not be spending the request's budget.
 */
class IntegrationPresentationService {

    public function __construct(
        private IntegrationPackageMapper    $packages,
        private IntegrationCapabilityMapper $capabilities,
        private IntegrationVisibilityMapper $visibility,
        private IntegrationStateService     $stateService,
        private MemberService               $memberService,
        private AuditService                $auditService,
        private IUserSession                $userSession,
        private LoggerInterface             $logger,
    ) {}

    // ------------------------------------------------------------------
    // Team administrator — the management view
    // ------------------------------------------------------------------

    /**
     * The team's integration packages, grouped, with each capability's state.
     *
     * Only packages that are **effective** for this team appear: included in
     * its template, enabled by a Nextcloud administrator, owned by an
     * available app, registered and compatible. A package the template does
     * not include is not listed at all — a team administrator cannot add it,
     * so offering it would be offering a control that does not exist.
     *
     * Non-visual capabilities are listed as informational, never as a toggle.
     * A team administrator should be able to see that an integration also
     * feeds My Work — it is the honest answer to "what is this integration
     * doing" — without being given a switch for it.
     *
     * @return array<string,mixed>
     */
    public function listForTeam(string $teamId): array {
        $actor = $this->requireTeamAdmin($teamId);

        $out = [];
        foreach ($this->stateService->effectivePackagesForTeam($teamId, $actor, accessAlreadyVerified: true) as $entry) {
            $pkgRow = $entry['package'];
            $state  = $entry['state'];

            $widgets       = [];
            $menuItems     = [];
            $informational = [];

            foreach ($this->stateService->capabilitiesForTeam($pkgRow, $teamId, $state) as $row) {
                $capability = $row['capability'];
                $capType    = (string)$capability['cap_type'];

                $descriptor = [
                    'cap_id'         => (int)$capability['id'],
                    'capability_key' => (string)$capability['capability_key'],
                    'cap_type'       => $capType,
                    'title'          => (string)$capability['title'],
                    'description'    => $capability['description'],
                    'icon'           => $capability['icon'],
                    'sort_order'     => (int)$capability['effective_order'],
                    'shown_for_team' => $row['state']->shownForTeam,
                    'effective'      => $row['state']->isEffective(),
                ];

                match (true) {
                    // v4.13.24 — `isGridCard()`, not `=== WIDGET`: a
                    // blank-canvas widget is a card on the same grid with the
                    // same shown/hidden switch, so it belongs in the same
                    // group of the presentation manager.
                    CapabilityType::isGridCard($capType) => $widgets[]   = $descriptor,
                    $capType === CapabilityType::MENU_ITEM => $menuItems[] = $descriptor,
                    // No `shown_for_team` for a non-visual capability: the key
                    // would imply a control exists.
                    default => $informational[] = [
                        'capability_key' => $descriptor['capability_key'],
                        'cap_type'       => $capType,
                        'title'          => $descriptor['title'],
                        'description'    => $descriptor['description'],
                        'icon'           => $descriptor['icon'],
                    ],
                };
            }

            $out[] = [
                'pkg_id'          => (int)$pkgRow['id'],
                'app_id'          => (string)$pkgRow['app_id'],
                'integration_key' => (string)$pkgRow['integration_key'],
                'title'           => (string)$pkgRow['title'],
                'description'     => $pkgRow['description'],
                'icon'            => $pkgRow['icon'],
                'widgets'         => $widgets,
                'menu_items'      => $menuItems,
                'informational'   => $informational,
                // Stated so the interface can say it rather than imply it:
                // this screen has no package-level switch, by design.
                'package_switch_available' => false,
            ];
        }

        return ['packages' => $out];
    }

    // ------------------------------------------------------------------
    // Team administrator — the three write operations
    // ------------------------------------------------------------------

    /** Show one visual capability for this team. */
    public function show(string $teamId, int $capId): array {
        return $this->setHidden($teamId, $capId, false);
    }

    /** Hide one visual capability for this team. */
    public function hide(string $teamId, int $capId): array {
        return $this->setHidden($teamId, $capId, true);
    }

    /**
     * @return array<string,mixed>
     */
    private function setHidden(string $teamId, int $capId, bool $hidden): array {
        $actor      = $this->requireTeamAdmin($teamId);
        $capability = $this->capability($capId);
        $pkgRow     = $this->packageOf($capability);

        $this->assertVisual($capability);
        $this->assertEffective($pkgRow, $teamId, $actor);

        $this->visibility->setHidden($teamId, $capId, $hidden, $actor, time());
        $this->stateService->resetCaches();

        // Audited against the team, unlike the administrator's decisions:
        // TeamHub already audits team configuration changes, and this is one.
        $this->auditService->log(
            $teamId,
            $hidden ? 'integration_capability_hidden' : 'integration_capability_shown',
            $actor,
            'integration',
            (string)$pkgRow['app_id'] . '/' . (string)$pkgRow['integration_key']
                . '/' . (string)$capability['capability_key'],
            [
                'cap_type' => (string)$capability['cap_type'],
                'title'    => (string)$capability['title'],
                // Recorded because somebody reading this row later will want
                // to know whether a hide broke anything. It did not.
                'presentation_only' => true,
            ],
        );

        return [
            'cap_id'         => $capId,
            'shown_for_team' => !$hidden,
        ];
    }

    /**
     * Reorder the capabilities of one surface for this team.
     *
     * Widgets and tabs are ordered **separately** — they are two different
     * surfaces and a single sequence across both would make reordering a
     * widget move a tab. The caller names one type and the capability ids in
     * the order they want.
     *
     * v4.13.24 — a *surface*, not a type, and the distinction now matters.
     * `widget` and `widget_iframe` are two types on one grid: the
     * presentation manager lists them together because a team arranges one
     * set of cards, so naming either type here reorders both. Matching on the
     * raw type instead would silently skip every canvas card in the list the
     * administrator just dragged, writing a partial order and reporting
     * success.
     *
     * Only writes `sort_order`, so reordering a hidden capability leaves it
     * hidden: a team administrator can arrange a list before revealing
     * anything in it.
     *
     * @param list<int> $orderedCapIds
     * @return array<string,mixed>
     */
    public function reorder(string $teamId, string $capType, array $orderedCapIds): array {
        $actor = $this->requireTeamAdmin($teamId);

        if (!CapabilityType::isVisual($capType)) {
            throw new ValidationException(
                "Only visual capabilities have an order. '{$capType}' is not one.",
            );
        }

        $clean = array_values(array_unique(array_filter(
            array_map('intval', $orderedCapIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($clean === []) {
            throw new ValidationException('An order needs at least one capability.');
        }

        $now      = time();
        $position = 0;
        $applied  = [];

        foreach ($clean as $capId) {
            $capability = $this->capabilities->findById($capId);
            if ($capability === null) {
                continue;
            }
            // A caller naming a capability of the wrong type, or of a package
            // their template does not include, is skipped rather than
            // throwing: a stale list from a browser tab should not fail the
            // whole reorder, and what it names is never trusted for
            // authorisation.
            if (!self::sameSurface((string)$capability['cap_type'], $capType)) {
                continue;
            }

            $pkgRow = $this->packages->findById((int)$capability['pkg_id']);
            if ($pkgRow === null) {
                continue;
            }
            $state = $this->stateService->forTeam($pkgRow, $teamId, $actor, accessAlreadyVerified: true);
            if (!$state->isEffective()) {
                continue;
            }

            $this->visibility->setOrder($teamId, $capId, $position, $actor, $now);
            $applied[] = $capId;
            $position++;
        }

        $this->stateService->resetCaches();

        if ($applied !== []) {
            $this->auditService->log(
                $teamId,
                'integration_capability_reordered',
                $actor,
                'integration',
                $capType,
                ['cap_ids' => $applied, 'presentation_only' => true],
            );
        }

        return ['cap_type' => $capType, 'ordered' => $applied];
    }

    // ------------------------------------------------------------------
    // Team member — what actually renders
    // ------------------------------------------------------------------

    /**
     * The widgets this team shows, in order, for a member.
     *
     * Hidden widgets are absent, so nothing downstream calls their provider.
     * No provider class is in the payload: an ordinary member has no use for
     * one and publishing it tells them about the owning app's internals.
     *
     * v4.13.24 — both kinds of card, in one list. A `widget` carries no URL
     * and its body is fetched from `IntegrationWidgetService`; a
     * `widget_iframe` carries `url`, already validated again and already
     * carrying TeamHub's own verified team id, and its body is a sandboxed
     * frame. `cap_type` is what the frontend reads to tell them apart.
     *
     * @return list<array<string,mixed>>
     */
    public function renderableWidgetsForTeam(string $teamId): array {
        $widgets = $this->renderableOfType($teamId, CapabilityType::WIDGET);

        // v4.13.24 — the blank-canvas widgets join them, in one list sorted
        // by the same effective order. The frontend reads `cap_type` to decide
        // whether a card's body is the row list it fetches from
        // `IntegrationWidgetService` or a sandboxed frame, so the two must
        // arrive interleaved by position rather than as two blocks: a team
        // that dragged a canvas card above a row card means it.
        foreach ($this->renderableOfType($teamId, CapabilityType::WIDGET_IFRAME) as $canvas) {
            $url = IframeUrl::withTeamId((string)($canvas['iframe_url'] ?? ''), $teamId);
            if ($url === '') {
                // A stored URL that no longer passes the rule. Dropped rather
                // than rendered with an empty src — the rule is re-applied on
                // the way out because a row can predate a tightening of it.
                $this->logger->warning('[TeamHub][Integration] Dropped a canvas widget whose stored URL is not acceptable', [
                    'app_id'         => (string)$canvas['app_id'],
                    'capability_key' => (string)$canvas['capability_key'],
                    'app'            => Application::APP_ID,
                ]);
                continue;
            }
            unset($canvas['iframe_url']);
            $canvas['url'] = $url;

            // v4.13.29 — the header links get the same treatment as the
            // frame's URL and for the same two reasons: the rule may have
            // tightened since the row was written, and the destination is an
            // ordinary route that has no idea which team the person was
            // looking at unless TeamHub tells it. An action whose URL no
            // longer passes is dropped — the rest of the menu still works,
            // and a dead item in a menu TeamHub drew is TeamHub's fault.
            $actions = [];
            foreach ((array)($canvas['actions'] ?? []) as $action) {
                $actionUrl = IframeUrl::withTeamId((string)($action['url'] ?? ''), $teamId);
                if ($actionUrl === '') {
                    $this->logger->warning('[TeamHub][Integration] Dropped a canvas widget action whose stored URL is not acceptable', [
                        'app_id'         => (string)$canvas['app_id'],
                        'capability_key' => (string)$canvas['capability_key'],
                        'action_label'   => (string)($action['label'] ?? ''),
                        'app'            => Application::APP_ID,
                    ]);
                    continue;
                }
                $actions[] = [
                    'label' => (string)($action['label'] ?? ''),
                    'icon'  => $action['icon'] ?? null,
                    'url'   => $actionUrl,
                ];
            }
            $canvas['actions'] = $actions;

            $widgets[]     = $canvas;
        }

        usort($widgets, static function (array $a, array $b): int {
            return [$a['sort_order'], $a['app_id'], $a['capability_key']]
               <=> [$b['sort_order'], $b['app_id'], $b['capability_key']];
        });

        return $widgets;
    }

    /**
     * The iframe tabs this team shows, in order, for a member.
     *
     * Each carries the URL with TeamHub's own verified team id appended. The
     * team id comes from the membership check this method just made and never
     * from the stored URL or from request input.
     *
     * @return list<array<string,mixed>>
     */
    public function renderableTabsForTeam(string $teamId): array {
        $tabs = $this->renderableOfType($teamId, CapabilityType::MENU_ITEM);

        $out = [];
        foreach ($tabs as $tab) {
            $url = IframeUrl::withTeamId((string)($tab['iframe_url'] ?? ''), $teamId);
            if ($url === '') {
                // A stored URL that no longer passes the rule. Dropped rather
                // than rendered with an empty src — the rule is re-applied on
                // the way out because a row can predate a tightening of it.
                $this->logger->warning('[TeamHub][Integration] Dropped an iframe tab whose stored URL is not acceptable', [
                    'app_id'         => (string)$tab['app_id'],
                    'capability_key' => (string)$tab['capability_key'],
                    'app'            => Application::APP_ID,
                ]);
                continue;
            }
            unset($tab['iframe_url']);
            $tab['url'] = $url;
            $out[]      = $tab;
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function renderableOfType(string $teamId, string $capType): array {
        $this->memberService->requireMemberLevel($teamId);
        $actor = $this->currentUserId();

        $out = [];
        foreach ($this->stateService->effectivePackagesForTeam($teamId, $actor, accessAlreadyVerified: true) as $entry) {
            $pkgRow = $entry['package'];

            foreach ($this->stateService->capabilitiesForTeam($pkgRow, $teamId, $entry['state']) as $row) {
                $capability = $row['capability'];
                if ((string)$capability['cap_type'] !== $capType) {
                    continue;
                }
                if (!$row['state']->mayRender()) {
                    continue;
                }

                $descriptor = [
                    'cap_id'          => (int)$capability['id'],
                    'capability_key'  => (string)$capability['capability_key'],
                    'pkg_id'          => (int)$pkgRow['id'],
                    'app_id'          => (string)$pkgRow['app_id'],
                    'integration_key' => (string)$pkgRow['integration_key'],
                    'package_title'   => (string)$pkgRow['title'],
                    'title'           => (string)$capability['title'],
                    'description'     => $capability['description'],
                    'icon'            => $capability['icon'] ?? $pkgRow['icon'],
                    'sort_order'      => (int)$capability['effective_order'],
                    'presentation'    => $capability['presentation'],
                    // v4.13.24 — the frontend reads this to decide whether a
                    // card's body is the row list it fetches or a sandboxed
                    // frame. Both widget types arrive in one list, ordered by
                    // the team's own arrangement, so the list itself cannot
                    // say which is which.
                    'cap_type'        => $capType,
                ];

                // v4.13.24 — both iframe-backed types carry the stored URL
                // this far. `renderableTabsForTeam()` and
                // `renderableWidgetsForTeam()` each re-validate it and append
                // the verified team id; neither ever hands the raw stored
                // value to a caller.
                if ($capType === CapabilityType::MENU_ITEM || $capType === CapabilityType::WIDGET_IFRAME) {
                    $descriptor['iframe_url'] = (string)($capability['iframe_url'] ?? '');
                }

                // v4.13.29 — a canvas widget's declared header links, raw.
                // `renderableWidgetsForTeam()` re-validates each one and
                // appends the verified team id, exactly as it does for the
                // frame's own URL; nothing hands the stored value to a caller.
                if ($capType === CapabilityType::WIDGET_IFRAME) {
                    $descriptor['actions'] = is_array($capability['actions'] ?? null)
                        ? $capability['actions']
                        : [];
                }

                $out[] = $descriptor;
            }
        }

        // Across packages, by effective order, then by package and capability
        // key so the sequence is stable on every request.
        usort($out, static function (array $a, array $b): int {
            return [$a['sort_order'], $a['app_id'], $a['capability_key']]
                <=> [$b['sort_order'], $b['app_id'], $b['capability_key']];
        });

        return $out;
    }

    // ------------------------------------------------------------------
    // Guards
    // ------------------------------------------------------------------

    /**
     * A preference may only be written against a visual capability.
     *
     * The bottom half of "no visual preference can disable My Work". The top
     * half is `IntegrationStateService::isCapabilityShown()`, which ignores
     * any such row anyway. Both exist because one of them being removed by a
     * later refactor must not open the hole.
     *
     * @param array<string,mixed> $capability
     * @throws ValidationException
     */
    private function assertVisual(array $capability): void {
        $capType = (string)$capability['cap_type'];
        if (CapabilityType::isVisual($capType)) {
            return;
        }

        throw new ValidationException(
            "'{$capability['title']}' is a {$capType} capability. It has no visual presentation, so it "
            . 'cannot be shown or hidden — and hiding it would be a way to stop My Work, which a team '
            . 'administrator does not control.',
        );
    }

    /**
     * Do these two capability types share one arrangeable surface?
     * v4.13.24 — see {@see reorder()} for why this is not `===`.
     */
    private static function sameSurface(string $capType, string $requested): bool {
        if (CapabilityType::isGridCard($requested)) {
            return CapabilityType::isGridCard($capType);
        }

        return $capType === $requested;
    }

    /**
     * The package must be effective for this team.
     *
     * A team administrator may only arrange what their template gave them.
     * Without this, a direct API call could write a preference for a package
     * the team does not have — harmless today, but it is the shape of
     * "activate an integration outside the template" and it does not get a
     * foothold.
     *
     * @param array<string,mixed> $pkgRow
     * @throws AccessDeniedException
     */
    private function assertEffective(array $pkgRow, string $teamId, string $actor): void {
        $state = $this->stateService->forTeam($pkgRow, $teamId, $actor, accessAlreadyVerified: true);
        if ($state->isEffective()) {
            return;
        }

        throw new AccessDeniedException(
            'This integration is not active for this team. A Nextcloud administrator controls which '
            . 'integrations a team template includes.',
        );
    }

    /**
     * @return array<string,mixed>
     * @throws NotFoundException
     */
    private function capability(int $capId): array {
        $row = $this->capabilities->findById($capId);
        if ($row === null) {
            throw new NotFoundException('No such integration capability.');
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $capability
     * @return array<string,mixed>
     * @throws NotFoundException
     */
    private function packageOf(array $capability): array {
        $row = $this->packages->findById((int)$capability['pkg_id']);
        if ($row === null) {
            throw new NotFoundException('No such integration package.');
        }

        return $row;
    }

    /**
     * Team admin or owner. The gate for every write here, and for the
     * management view — an ordinary member cannot change presentation and is
     * not shown the controls.
     *
     * @throws AccessDeniedException
     */
    private function requireTeamAdmin(string $teamId): string {
        $this->memberService->requireAdminLevel($teamId);

        return $this->currentUserId();
    }

    /** @throws AccessDeniedException */
    private function currentUserId(): string {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new AccessDeniedException('Not authenticated.');
        }

        return $user->getUID();
    }
}
