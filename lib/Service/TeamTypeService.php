<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\TeamTemplateMapper;
use OCA\TeamHub\Db\TeamTypeMapper;
use OCA\TeamHub\Exception\ValidationException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Per-team template label — **any key in `teamhub_template`**: the five
 * TeamHub seeds, plus whatever an administrator has created since v4.13.24.
 * It was documented here as a closed list of five until v4.13.28, and
 * `setType()` enforced that list; the note there records what it cost.
 *
 * Written once by CreateTeamView after team creation. Read by the Team info
 * widget (via the layout bundle) and by BrowseTeamsView (via the browse
 * endpoint). Absence of a row => null => no template label shown (legacy
 * teams predating this feature).
 *
 * **`service` (v4.10.20) is a label, not the feature.** A team is a Service
 * Team because `teamhub_service_team` says so — the owner, the agents and
 * the catalogue live there, and `ServiceTeamService` is the only thing that
 * decides eligibility. This value exists so a desk reads as one in the team
 * list and the wizard, and setting it by hand on a team with no service-team
 * row changes nothing about what that team can do.
 *
 * **It is a template** since v4.10.23 (`TeamTemplates::TEMPLATES`) — the text
 * here said the opposite until v4.11.6, describing the v4.10.20 rule that a
 * desk is only ever declared on a team that already exists.
 *
 * Two things follow from this value being written, and both live in
 * `TeamController::saveTeamType()` rather than here, so this class stays a
 * label writer: the expiry set at creation, and (v4.11.6) the default issue
 * process seeded as a draft on a new service team
 * (`TeamServiceBuilder::seedDefault()`).
 */
class TeamTypeService {

    /**
     * The templates TeamHub seeds — **the floor, not the set.**
     *
     * Kept as a constant because an empty `teamhub_template` means the seed
     * migration has not run, and refusing every write with "Allowed: " and
     * nothing after it is a worse answer than the five keys the instance is
     * about to be seeded with. `TeamImportService::templateKeys()` uses it the
     * same way and for the same reason.
     *
     * **Do not read this as "the valid types".** That question is answered by
     * {@see allowedTypes()}, from the table. See the class docblock.
     */
    public const ALLOWED = ['collaboration', 'project', 'department', 'openproject', 'service'];

    /** @var list<string>|null the live template keys, read once per request */
    private ?array $allowedCache = null;

    public function __construct(
        private TeamTypeMapper     $mapper,
        private TeamTemplateMapper $templates,
        private MemberService      $memberService,
        private IUserSession       $userSession,
        private LoggerInterface    $logger,
    ) {}

    /**
     * @return string|null Membership check is delegated to the caller — this
     *                     is invoked from LayoutController (already gated) and
     *                     from the type controller (gates explicitly).
     */
    public function getType(string $teamId): ?string {
        return $this->mapper->findTypeByTeam($teamId);
    }

    /** @param string[] $teamIds */
    public function getTypesForTeams(array $teamIds): array {
        return $this->mapper->findTypesByTeams($teamIds);
    }

    /**
     * The template keys a team may carry: the table's own, with
     * {@see ALLOWED} as the floor on an unseeded instance.
     *
     * Cached for the request. `setType()` is called once per team creation and
     * the table holds a handful of rows, so this is about not reading it twice
     * in one request rather than about cost.
     *
     * @return list<string>
     */
    private function allowedTypes(): array {
        if ($this->allowedCache === null) {
            $keys = array_values(array_filter(array_map(
                static fn (array $row): string => (string)($row['templateKey'] ?? ''),
                $this->templates->findAll(),
            )));

            $this->allowedCache = $keys === [] ? self::ALLOWED : $keys;
        }

        return $this->allowedCache;
    }

    /**
     * Admin-gated writer. Validates the type against the **live template
     * table** so a bad value from a rogue caller can't land in the DB.
     *
     * v4.13.28 — it validated against {@see ALLOWED}, a constant naming the
     * five seeded keys, and that made every administrator-created template
     * unusable. The wizard's last step sends the chosen template key here,
     * this threw `ValidationException`, and `CreateTeamView` caught it and
     * carried on silently. The team came out with **no row in
     * `teamhub_team_type`** — the state `IntegrationStateService` reports as
     * `REASON_NO_TEMPLATE` — so every integration the template included was
     * ineffective, Manage team listed no packages, no widget or tab rendered,
     * and the expiry riding the same call was dropped. Justin hit it on the
     * `topdesk` template the day after v4.13.25 shipped the ability to choose
     * a created template at all.
     *
     * The table is the set the wizard renders, the set
     * `TeamImportService::templateKeys()` validates against (v4.8.25 — the
     * same fix, on the importer's side, for the same reason), the set
     * `TeamAdoptionDecisionService` offers, and the set
     * `IntegrationStateService` resolves an inclusion from. A constant here
     * could only ever disagree with all four.
     */
    /**
     * Validate a selected template BEFORE creating the Circle. Team creation
     * must not persist an unknown key, or create an orphan when it fails.
     */
    public function assertAllowedType(string $type): void {
        $allowed = $this->allowedTypes();
        if (!in_array($type, $allowed, true)) {
            throw new ValidationException('Invalid team type. Allowed: ' . implode(', ', $allowed));
        }
    }

    public function setType(string $teamId, string $type): string {
        $this->memberService->requireAdminLevel($teamId);
        $this->assertAllowedType($type);
        $user = $this->userSession->getUser();
        $uid  = $user ? $user->getUID() : '';
        $this->mapper->upsert($teamId, $type, $uid);
        return $type;
    }

    /**
     * The team's template as a badge needs it: the key, the label as stored,
     * and whether TeamHub seeded it. Null for a team with no row.
     *
     * Why all three rather than a finished string: a seeded template's label
     * is translated client-side by key (`seededTemplateLabel()`), an
     * administrator's own label is shown as they wrote it, and
     * `templateDisplayName()` already encodes that rule for the admin panels.
     * A label the server picked would either lose the translation or override
     * the administrator — so the frontend is handed the facts and applies the
     * one rule it already has.
     *
     * v4.13.28 — before this, `BrowseTeamsView` and `TeamWidgetGrid` each
     * mapped the key to a label with a four-arm `if` chain over seeded keys.
     * Both were missing `service` (shipped v4.10.23), and neither could ever
     * name a template an administrator created, or one they renamed.
     *
     * @return array{key: string, label: string, isSeeded: bool}|null
     */
    public function getTemplateFor(string $teamId): ?array {
        $key = $this->mapper->findTypeByTeam($teamId);
        if ($key === null || $key === '') {
            return null;
        }

        return $this->templates->describe($key);
    }

    /**
     * The same descriptor for many teams.
     *
     * @param array<string,string> $keysByTeam team id => template key
     * @return array<string,array{key: string, label: string, isSeeded: bool}>
     */
    public function describeTemplates(array $keysByTeam): array {
        return $this->templates->describeMany($keysByTeam);
    }
}
