<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Constants\LayoutSchema;
use OCA\TeamHub\Db\LayoutDefaultMapper;
use OCA\TeamHub\Exception\ValidationException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The default home layout, at three widths (v4.13.34 — issue #109, phases 2
 * and 3).
 *
 * ── What a default is, and what it is not ────────────────────────────────
 *
 * A template default defines a new team's initial dashboard, until a member
 * arranges their own. It is not a lock and it is not a copy: nothing is
 * written into anybody's layout row when the template default is saved, so
 * members who later customise their dashboard retain that arrangement. That is the whole mechanism v4.13.33 built, and
 * every scope here rides on it.
 *
 * The one exception is a team default in `enforce` mode, which is above a
 * member's own arrangement. It is still not a copy — the arrangement stays in
 * the member's row, untouched, and comes back the moment enforcement is lifted.
 *
 * ── The cascade this service feeds ──────────────────────────────────────
 *
 * `LayoutController::resolveTeamLayout()` asks, in order:
 *
 *   1. the team's default, if it is **enforced**
 *   2. the member's own arrangement made since the last type-default save
 *   3. the member's personal default for this **template** (`oc_preferences`)
 *   4. the team's default, if it is **apply_once** and no type default is set
 *   5. the administrator's default for this **kind** of team (its template)
 *   6. the system default (`LayoutSchema::DEFAULT_LAYOUT`)
 *
 * A `suggest` team default appears nowhere in that list on purpose: it is an
 * offer, so it reaches the member as a notice they can act on and changes
 * nothing until they do.
 *
 * Step 4 is the answer to "make the dashboard suit a wide array of use cases"
 * (Justin, 2026-10-27). A Service team, a Project and a Department want
 * different homes, and the template is what already knows which a team is — so
 * the default belongs beside the apps and modules the template already
 * carries. v4.13.36 removed the instance-wide width that used to sit behind
 * it, for the same reason: one arrangement that has to suit everybody suits
 * nobody, and it was a third thing to keep right between the two that are
 * actually wanted.
 *
 * ── Who may write one ───────────────────────────────────────────────────
 *
 * The instance and template defaults are instance governance and are gated
 * with `#[AuthorizedAdminSetting]` on the controller **and nothing further
 * here**, which is a deliberate departure from `PolicyService::requireNcAdmin()`
 * and the pattern every other governance control in this app follows. Justin's
 * decision, 2026-10-27: *"If we have delegated admins set for TeamHub they
 * should be able to change all settings in TeamHub."* This is the first
 * surface built to that rule; the existing controls still refuse a delegated
 * admin in their services, and bringing them across is a session of its own
 * (HANDOFF).
 *
 * A team's own default is written by a **team admin** (level ≥ 8), which the
 * caller checks — the same gate as every other Manage team setting, including
 * the hidden-widgets list this sits beside.
 *
 * v4.13.35 — this service asks no permission question of its own any more. It
 * had one, `mayWriteInstanceDefaults()`, to decide whether to offer an
 * administrator the two wider defaults from inside a team's home; that whole
 * surface moved to Admin → TeamHub → Policy, where the controller attribute is
 * the gate. The delegated-admin uncertainty went with it: nothing here has to
 * guess at an OCP method, because nothing here asks.
 */
class LayoutDefaultService {

    public function __construct(
        private LayoutDefaultMapper $mapper,
        private IUserSession        $userSession,
        private AuditService        $audit,
        private LoggerInterface     $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // Reading — what the cascade calls
    // -------------------------------------------------------------------------

    /**
     * The default for one kind of team, or null.
     *
     * @return array{layout: array, tabOrder: array, mode: string, updatedBy: ?string, updatedAt: ?int}|null
     */
    public function forTemplate(?string $templateKey): ?array {
        if ($templateKey === null || $templateKey === '') {
            return null;
        }
        return $this->decode($this->mapper->find(LayoutDefaultMapper::SCOPE_TEMPLATE, $templateKey));
    }

    /**
     * One team's own default, or null.
     *
     * @return array{layout: array, tabOrder: array, mode: string, updatedBy: ?string, updatedAt: ?int}|null
     */
    public function forTeam(string $teamId): ?array {
        $row = $this->decode($this->mapper->find(LayoutDefaultMapper::SCOPE_TEAM, $teamId));
        if ($row === null) {
            return null;
        }
        // A row with an unrecognised mode is treated as a suggestion — the
        // weakest of the three. A stored value nothing recognises must never
        // read as `enforce`, which is the one mode that takes a member's own
        // home away from them.
        if (!in_array($row['mode'], self::modes(), true)) {
            $this->logger->warning('[TeamHub][LayoutDefaultService] forTeam — unknown mode, treating as a suggestion', [
                'teamId' => $teamId, 'mode' => $row['mode'],
            ]);
            $row['mode'] = LayoutDefaultMapper::MODE_SUGGEST;
        }
        // Older enforced presets may have been stored with collapsed cards.
        // Expand them on read as well; waiting for an admin to republish must
        // not leave content permanently inaccessible to existing members.
        if ($row['mode'] === LayoutDefaultMapper::MODE_ENFORCE) {
            $row['layout'] = self::expandForEnforcement(LayoutSchema::sanitiseLayout($row['layout']));
        }
        return $row;
    }

    /** The three modes a team default can be in. */
    public static function modes(): array {
        return [
            LayoutDefaultMapper::MODE_SUGGEST,
            LayoutDefaultMapper::MODE_APPLY_ONCE,
            LayoutDefaultMapper::MODE_ENFORCE,
        ];
    }

    // -------------------------------------------------------------------------
    // Writing
    // -------------------------------------------------------------------------

    /** @param array<int, array<string, mixed>> $layout Sanitised layout items. */
    private static function expandForEnforcement(array $layout): array {
        foreach ($layout as &$item) {
            if ($item['collapsed']) {
                $item['h'] = max($item['minH'], $item['hSaved']);
                $item['collapsed'] = false;
            }
        }
        unset($item);
        return $layout;
    }

    /** Set the default for one kind of team. */
    public function saveTemplate(string $templateKey, mixed $layout, mixed $tabOrder): array {
        if (preg_match('/^[a-z][a-z0-9_]{1,31}$/', $templateKey) !== 1) {
            throw new ValidationException('Invalid template key.');
        }
        return $this->save(LayoutDefaultMapper::SCOPE_TEMPLATE, $templateKey, $layout, $tabOrder, '');
    }

    /**
     * Set one team's own default, in a mode.
     *
     * The caller has already established that the actor is an admin of this
     * team — this service never sees a team id it could check membership
     * against without taking a dependency on MemberService, which takes one on
     * nothing it needs.
     */
    public function saveTeam(string $teamId, mixed $layout, mixed $tabOrder, string $mode): array {
        if (!in_array($mode, self::modes(), true)) {
            throw new ValidationException('Unknown team layout mode.');
        }
        return $this->save(LayoutDefaultMapper::SCOPE_TEAM, $teamId, $layout, $tabOrder, $mode);
    }

    /**
     * Change a team default's mode without touching its layout.
     *
     * @throws ValidationException when the mode is unknown, or when the team
     *         has no default for a mode to apply to
     */
    public function setTeamMode(string $teamId, string $mode): void {
        if (!in_array($mode, self::modes(), true)) {
            throw new ValidationException('Unknown team layout mode.');
        }
        // When an existing flexible layout becomes enforced, reopen any
        // collapsed widgets before locking members out of collapse controls.
        // Otherwise the team would publish cards nobody could expand.
        $existing = $this->forTeam($teamId);
        if ($existing === null) {
            throw new ValidationException('This team has no layout to apply a mode to.');
        }
        $expanded = $mode === LayoutDefaultMapper::MODE_ENFORCE
            ? self::expandForEnforcement(LayoutSchema::sanitiseLayout($existing['layout']))
            : null;
        if ($expanded !== null && $expanded !== LayoutSchema::sanitiseLayout($existing['layout'])) {
            $this->mapper->upsert(
                LayoutDefaultMapper::SCOPE_TEAM,
                $teamId,
                json_encode($expanded, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                json_encode($existing['tabOrder'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $mode,
                $this->actor(),
            );
        } else {
            $this->mapper->setMode($teamId, $mode);
        }

        $this->audit->log(
            $teamId,
            'team.layout_default_mode_set',
            $this->actor(),
            'layout_default',
            LayoutDefaultMapper::SCOPE_TEAM,
            ['mode' => $mode],
        );
    }

    /** Remove a kind of team's default. */
    public function clearTemplate(string $templateKey): void {
        $this->mapper->delete(LayoutDefaultMapper::SCOPE_TEMPLATE, $templateKey);
        $this->audit->log(
            AuditService::INSTANCE_SCOPE,
            'instance.layout_default_cleared',
            $this->actor(),
            'layout_default',
            LayoutDefaultMapper::SCOPE_TEMPLATE . ':' . $templateKey,
        );
    }

    /** Remove one team's own default. */
    public function clearTeam(string $teamId): void {
        $this->mapper->delete(LayoutDefaultMapper::SCOPE_TEAM, $teamId);
        $this->audit->log(
            $teamId,
            'team.layout_default_cleared',
            $this->actor(),
            'layout_default',
            LayoutDefaultMapper::SCOPE_TEAM,
        );
    }

    /**
     * Housekeeping: a deleted team or template leaves no default behind.
     *
     * Called from the delete paths rather than left to a background sweep. A
     * template key can be reused — an administrator may delete `practice` and
     * create it again — and an orphaned row would then silently govern the new
     * one's teams.
     */
    public function forgetTeam(string $teamId): void {
        $this->mapper->delete(LayoutDefaultMapper::SCOPE_TEAM, $teamId);
    }

    public function forgetTemplate(string $templateKey): void {
        $this->mapper->delete(LayoutDefaultMapper::SCOPE_TEMPLATE, $templateKey);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Clean, size-check and store one scope's default.
     *
     * @return array{layout: array, tabOrder: array}
     *
     * @throws ValidationException on an empty or oversized layout
     */
    private function save(string $scope, string $scopeId, mixed $layout, mixed $tabOrder, string $mode): array {
        $cleanLayout   = LayoutSchema::sanitiseLayout($layout);
        $cleanTabOrder = LayoutSchema::sanitiseTabOrder($tabOrder);

        // Enforced dashboards cannot be expanded by members. Do not publish
        // a pre-collapsed card whose content would then be inaccessible.
        if ($scope === LayoutDefaultMapper::SCOPE_TEAM && $mode === LayoutDefaultMapper::MODE_ENFORCE) {
            $cleanLayout = self::expandForEnforcement($cleanLayout);
        }

        // An empty layout is refused rather than stored. Stored, it would be a
        // default of no widgets at all — and in `enforce` mode, a home nobody
        // on the team can put anything back on. "Show no widgets" is already
        // expressible, and belongs where it is: the per-team hidden-widgets
        // list, which a team admin can undo one switch at a time.
        if ($cleanLayout === []) {
            throw new ValidationException('A default layout needs at least one widget.');
        }

        if (!LayoutSchema::fitsPayloadLimit($cleanLayout, $cleanTabOrder)) {
            throw new ValidationException('This layout is too large to store.');
        }

        $this->mapper->upsert(
            $scope,
            $scopeId,
            json_encode($cleanLayout,   JSON_UNESCAPED_UNICODE),
            json_encode($cleanTabOrder, JSON_UNESCAPED_UNICODE),
            $mode,
            $this->actor(),
        );

        // A team default is a change to that team and is audited against it;
        // the instance and template defaults change many teams and have no one
        // team to belong to, so they go to the instance scope beside the other
        // governance changes (DESIGN §2.172).
        $this->audit->log(
            $scope === LayoutDefaultMapper::SCOPE_TEAM ? $scopeId : AuditService::INSTANCE_SCOPE,
            $scope === LayoutDefaultMapper::SCOPE_TEAM ? 'team.layout_default_set' : 'instance.layout_default_set',
            $this->actor(),
            'layout_default',
            $scope === LayoutDefaultMapper::SCOPE_TEAM ? $scope : $scope . ($scopeId === '' ? '' : ':' . $scopeId),
            ['mode' => $mode, 'widgets' => count($cleanLayout)],
        );

        return ['layout' => $cleanLayout, 'tabOrder' => $cleanTabOrder];
    }

    /**
     * @param array{layout_json: string, tab_order_json: string, mode: string, updated_by: ?string, updated_at: ?int}|null $row
     *
     * @return array{layout: array, tabOrder: array, mode: string, updatedBy: ?string, updatedAt: ?int}|null
     */
    private function decode(?array $row): ?array {
        if ($row === null) {
            return null;
        }

        $layout = json_decode($row['layout_json'], true);
        if (!is_array($layout) || $layout === []) {
            // A row whose JSON will not decode is no default at all. Returning
            // null lets the cascade fall through to the next width, which is
            // always a layout that renders — rather than handing the client an
            // empty grid it would draw as a blank page.
            $this->logger->warning('[TeamHub][LayoutDefaultService] decode — stored layout is unusable, ignoring it', [
                'mode' => $row['mode'],
            ]);
            return null;
        }

        $tabOrder = json_decode($row['tab_order_json'], true);

        return [
            // Cleaned on the way out as well as in. The row may predate a
            // widget's retirement, and the reader is a grid that will try to
            // render whatever it is given.
            'layout'    => LayoutSchema::sanitiseLayout($layout),
            'tabOrder'  => LayoutSchema::sanitiseTabOrder($tabOrder),
            'mode'      => $row['mode'],
            'updatedBy' => $row['updated_by'],
            'updatedAt' => $row['updated_at'],
        ];
    }

    /** The acting account, or '' for a background context. */
    private function actor(): string {
        return $this->userSession->getUser()?->getUID() ?? '';
    }
}
