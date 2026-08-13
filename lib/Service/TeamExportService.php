<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\TeamTemplateProfiles;
use OCA\TeamHub\Db\ProjectMapper;
use OCA\TeamHub\Db\TeamAppMapper;
use OCA\TeamHub\Db\TeamAppResourceMapper;
use OCA\TeamHub\Db\TeamTypeMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\ValidationException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Bulk team export (v4.6.14) — the inverse of {@see TeamImportService}.
 *
 * Writes the **same CSV contract the importer reads**, column for column, so a
 * file exported here can be edited in a spreadsheet and fed back in. That is
 * the whole design constraint: every decision below is settled by asking what
 * `TeamImportService` would do with the cell, not by what would be pleasant to
 * read.
 *
 * ## Three places that constraint bites
 *
 * **Account tokens are uids, not display names.** `TeamImportService::
 * resolveUid()` matches `IUserManager::get()` first and then a case-insensitive
 * search over *uids*. It never looks at display names. Writing "Jane Doe" for
 * an account whose uid is `jdoe` would produce a file that silently drops that
 * member on re-import. The sample CSV looks like it uses display names only
 * because Nextcloud uids routinely contain capitals and spaces.
 *
 * **`apps` and `modules` are written explicitly, never left blank.** An empty
 * cell means "use the template's default" to the importer, not "none" — so
 * exporting a Project team that had Deck removed would silently put Deck back
 * on re-import. Every row therefore carries the full resolved list, or the
 * literal `none` when the team has nothing enabled. This is the one place the
 * export is deliberately more verbose than a hand-written file would be.
 *
 * **Order carries meaning in `admin`.** The first name becomes the level-9
 * owner and the rest become level-8 admins, so the owner is written first and
 * the remaining admins follow in a stable order.
 *
 * ## Rows that cannot come back
 *
 * A faithful export includes teams whose row the importer would reject, and
 * says so rather than hiding them:
 *
 *   - a **legacy team** (created before v4.0.2) has no `teamhub_team_type` row,
 *     and `template` is a required import column;
 *   - an **orphaned team** has no level-9 owner, so `admin` is empty and that
 *     is required too;
 *   - a team whose **expiry has already passed** carries a date the importer
 *     refuses, because `TeamExpiryService::parseDate()` rejects the past.
 *
 * Each is reported per row through {@see self::preview()} so the admin sees
 * "3 of 42 rows will not re-import" before downloading. Filling in a default
 * instead would write facts that were never true — a legacy team is not known
 * to be a Collaboration team, it is a team whose template nobody recorded.
 */
class TeamExportService {

    /** Hard ceiling on teams per export. */
    public const MAX_TEAMS = 1000;

    /** Written into `apps` / `modules` when a team has none enabled. */
    public const NONE_TOKEN = 'none';

    /** Audit event written once per exported team. */
    public const AUDIT_EVENT = 'team.exported';

    /** Warning codes {@see self::preview()} can attach to a row. */
    public const WARN_NO_TEMPLATE   = 'no_template';
    public const WARN_NO_OWNER      = 'no_owner';
    public const WARN_EXPIRY_PAST   = 'expiry_past';
    public const WARN_DELIMITER     = 'delimiter_in_name';
    public const WARN_NAME_INVALID  = 'name_invalid';

    public function __construct(
        private TeamTypeMapper        $teamTypeMapper,
        // Only for assertValidTeamName() — the authoritative name rule, asked
        // rather than mirrored.
        private TeamService           $teamService,
        private TeamAppResourceMapper $resourceMapper,
        private TeamAppMapper         $teamAppMapper,
        private ProjectMapper         $projectMapper,
        private TeamExpiryService     $expiryService,
        private PresenceTeamService   $presenceTeamService,
        private DecisionTeamService   $decisionTeamService,
        private CollectivesService    $collectivesService,
        private AuditService          $auditService,
        private IConfig               $config,
        private IDBConnection         $db,
        private IUserSession          $userSession,
        private IGroupManager         $groupManager,
        private TimezoneService       $timezoneService,
        private LoggerInterface       $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // Selection
    // -------------------------------------------------------------------------

    /**
     * Every exportable team, for the panel's multiselect.
     *
     * Same definition of "a real team" the All-teams grid uses — anything
     * without a system prefix — so the two lists cannot disagree about what
     * exists. Deliberately not paginated: this feeds a picker, and a picker
     * that only knows about the first page cannot select from the rest.
     *
     * @return list<array{id:string, name:string, template:?string, memberCount:int}>
     */
    public function listSelectableTeams(): array {
        $this->requireNcAdmin();

        $qb = $this->db->getQueryBuilder();
        $qb->select('unique_id', 'name', 'display_name', 'sanitized_name')
            ->from('circles_circle')
            ->orderBy('name', 'ASC');

        $res  = $qb->executeQuery();
        $rows = [];
        while ($row = $res->fetch()) {
            $name = (string)($row['name'] ?? '');
            if ($this->isSystemCircle($name)) {
                continue;
            }
            $rows[(string)$row['unique_id']] = $this->displayNameOf($row);
        }
        $res->closeCursor();

        if ($rows === []) {
            return [];
        }

        $ids    = array_keys($rows);
        $types  = $this->teamTypeMapper->findTypesByTeams($ids);
        $counts = $this->memberCounts($ids);

        $out = [];
        foreach ($rows as $id => $name) {
            $out[] = [
                'id'          => $id,
                'name'        => $name,
                'template'    => $types[$id] ?? null,
                'memberCount' => $counts[$id] ?? 0,
            ];
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Preview + build
    // -------------------------------------------------------------------------

    /**
     * What the download would contain, without producing it.
     *
     * Writes no audit event: nothing has left the instance yet, and logging a
     * disclosure that did not happen is as wrong as missing one that did.
     *
     * @param string[] $teamIds Empty selects every exportable team.
     * @return array{
     *     total:int,
     *     rows: list<array{teamId:string, name:string, warnings: list<array{code:string, message:string}>}>,
     *     reimportable:int,
     *     blocked:int
     * }
     */
    public function preview(array $teamIds = []): array {
        $this->requireNcAdmin();

        $built   = $this->buildRows($teamIds);
        $rows    = [];
        $blocked = 0;

        foreach ($built as $row) {
            $blocking = array_filter(
                $row['warnings'],
                static fn (array $w): bool => $w['code'] !== self::WARN_DELIMITER,
            );
            if ($blocking !== []) {
                $blocked++;
            }
            $rows[] = [
                'teamId'   => $row['teamId'],
                'name'     => $row['cells']['name'],
                'warnings' => $row['warnings'],
            ];
        }

        return [
            'total'        => count($rows),
            'rows'         => $rows,
            'reimportable' => count($rows) - $blocked,
            'blocked'      => $blocked,
        ];
    }

    /**
     * The CSV itself.
     *
     * Writes one `team.exported` audit event per team, before returning the
     * body: this is the moment member lists leave the instance as a file, and
     * the compliance tab should be able to answer who took which ones and when.
     *
     * @param string[] $teamIds Empty selects every exportable team.
     */
    public function exportCsv(array $teamIds = []): string {
        $this->requireNcAdmin();

        $built = $this->buildRows($teamIds);
        $actor = $this->userSession->getUser()?->getUID() ?? '';

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Could not open a buffer for the export.');
        }

        // Same header the importer expects, in the canonical order, so the
        // exported file and the downloadable template are the same shape.
        $this->putRow($handle, TeamImportService::COLUMNS);

        foreach ($built as $row) {
            $ordered = [];
            foreach (TeamImportService::COLUMNS as $column) {
                $ordered[] = $row['cells'][$column] ?? '';
            }
            $this->putRow($handle, $ordered);

            $this->auditService->log(
                $row['teamId'],
                self::AUDIT_EVENT,
                $actor,
                'team',
                $row['teamId'],
                [
                    'format'      => 'csv',
                    'memberCount' => $row['memberCount'],
                    // Which columns could not be filled — the same codes the
                    // preview showed, so a later reader of the audit log can
                    // tell an incomplete export from a complete one.
                    'warnings'    => array_column($row['warnings'], 'code'),
                ],
            );
        }

        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Filename for the download. Dated, because these get kept — and dated in
     * the downloader's timezone, so a file saved at 00:30 in Amsterdam is not
     * stamped with yesterday.
     */
    public function exportFilename(): string {
        $uid = $this->userSession->getUser()?->getUID() ?? '';
        return 'teamhub-teams-' . $this->timezoneService->today($uid) . '.csv';
    }

    // -------------------------------------------------------------------------
    // Row construction
    // -------------------------------------------------------------------------

    /**
     * Resolve every selected team into its CSV cells plus any round-trip
     * warnings.
     *
     * @param string[] $teamIds
     * @return list<array{teamId:string, cells:array<string,string>, warnings:list<array{code:string,message:string}>, memberCount:int}>
     */
    private function buildRows(array $teamIds): array {
        $available = $this->listSelectableTeamsRaw();

        if ($teamIds !== []) {
            $wanted = array_flip(array_values(array_unique($teamIds)));
            $available = array_filter(
                $available,
                static fn (string $name, string $id): bool => isset($wanted[$id]),
                ARRAY_FILTER_USE_BOTH,
            );
            if ($available === []) {
                throw new ValidationException('None of the selected teams could be found.');
            }
        }

        if (count($available) > self::MAX_TEAMS) {
            throw new ValidationException(sprintf(
                'This export covers %d teams; the limit is %d. Narrow the selection.',
                count($available),
                self::MAX_TEAMS,
            ));
        }

        $ids         = array_keys($available);
        $types       = $this->teamTypeMapper->findTypesByTeams($ids);
        $expiries    = $this->expiryService->getExpiryForTeams($ids);
        $descriptions = $this->descriptions($ids);
        $now         = time();

        $out = [];
        foreach ($available as $teamId => $teamName) {
            $warnings = [];

            // ── name ─────────────────────────────────────────────────────
            // A team older than the name rule can carry characters the rule
            // now forbids — `assertValidTeamName()` applies to NEW teams only,
            // so an existing team is never retro-rejected, but an import is a
            // new team and would be. Asked of the authoritative validator
            // rather than a regex mirrored here (DESIGN.md §2.85: three copies
            // was already one too many).
            try {
                $this->teamService->assertValidTeamName($teamName);
            } catch (\Throwable $e) {
                $warnings[] = [
                    'code'    => self::WARN_NAME_INVALID,
                    'message' => 'The team name uses characters a new team cannot: ' . $e->getMessage(),
                ];
            }

            // ── template ─────────────────────────────────────────────────
            $template = $types[$teamId] ?? '';
            if ($template === '') {
                $warnings[] = [
                    'code'    => self::WARN_NO_TEMPLATE,
                    'message' => 'No template recorded — this team predates template labels. The row will not re-import until a template is filled in.',
                ];
            }

            // ── people ───────────────────────────────────────────────────
            $people = $this->people($teamId);
            if ($people['admins'] === []) {
                $warnings[] = [
                    'code'    => self::WARN_NO_OWNER,
                    'message' => 'No owner or team admin found. The row will not re-import until an account is filled in.',
                ];
            }
            foreach (array_merge($people['admins'], $people['members']) as $token) {
                if (preg_match('/[;|]/', $token)) {
                    $warnings[] = [
                        'code'    => self::WARN_DELIMITER,
                        'message' => 'A name contains a semicolon or vertical bar ("' . $token . '"), which the CSV uses to separate values. That entry will not survive a re-import.',
                    ];
                    break;
                }
            }

            // ── expiry ───────────────────────────────────────────────────
            $expires = '';
            $expiry  = $expiries[$teamId] ?? null;
            if (is_array($expiry)) {
                $expires = (string)$expiry['expiresOn'];
                if ((int)$expiry['expiresAt'] <= $now) {
                    $warnings[] = [
                        'code'    => self::WARN_EXPIRY_PAST,
                        'message' => 'The expiration date has already passed. Re-importing rejects a date in the past — clear it or move it forward.',
                    ];
                }
            }

            $out[] = [
                'teamId'      => $teamId,
                'memberCount' => count($people['admins']) + count($people['members']),
                'warnings'    => $warnings,
                'cells'       => [
                    'name'         => $teamName,
                    'description'  => $descriptions[$teamId] ?? '',
                    'template'     => $template,
                    'project_mode' => $this->projectMode($teamId, $template),
                    'admin'        => implode(';', $people['admins']),
                    'members'      => implode(';', $people['members']),
                    'apps'         => $this->apps($teamId),
                    'modules'      => $this->modules($teamId),
                    'expires'      => $expires,
                ],
            ];
        }

        return $out;
    }

    /**
     * Owner + team admins (level ≥ 8) and everyone else, as importer tokens.
     *
     * `admins` is ordered owner-first because the importer reads position: the
     * first name becomes the level-9 owner. Below that, level descending then
     * uid, so two exports of an unchanged team produce identical files —
     * which is what makes diffing them useful.
     *
     * Users are written as bare uids; groups as `group:<gid>`; sub-teams as
     * `team:<display name>`. A sub-team is written by name rather than by its
     * Circles unique_id because the id is instance-local and the name is not,
     * and `resolveTeamToken()` accepts either.
     *
     * @return array{admins: list<string>, members: list<string>}
     */
    private function people(string $teamId): array {
        $admins  = [];
        $members = [];

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('user_id', 'single_id', 'level', 'user_type')
                ->from('circles_member')
                ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('Member')))
                ->orderBy('level', 'DESC')
                ->addOrderBy('user_id', 'ASC');

            $res = $qb->executeQuery();
            while ($row = $res->fetch()) {
                $userType = (int)$row['user_type'];
                $level    = (int)$row['level'];
                $label    = (string)$row['user_id'];
                if ($label === '') {
                    continue;
                }

                // user_type 1 = local account, 2 = NC group, 16 = another team.
                // For 2 and 16 `user_id` is a human-readable *label*, not a
                // lookup key (HANDOFF.md § Circles) — which is exactly the form
                // the importer's `group:` / `team:` tokens resolve by name, so
                // it is the right thing to write here.
                if ($userType === 1) {
                    if ($level >= 8) {
                        $admins[] = $label;
                    } else {
                        $members[] = $label;
                    }
                    continue;
                }
                if ($userType === 2) {
                    $members[] = 'group:' . $label;
                    continue;
                }
                if ($userType === 16) {
                    $members[] = 'team:' . $label;
                }
                // Anything else — mail and contact circle members — has no
                // importer token, so it is left out rather than written in a
                // form the parser would drop with a warning.
            }
            $res->closeCursor();
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamExportService] Member lookup failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        return ['admins' => $admins, 'members' => $members];
    }

    /**
     * Connected app resources, as the importer's `apps` tokens.
     *
     * Only the four apps the importer can provision. A team may legitimately
     * have others connected (Collectives arrives through the module column),
     * and writing a token the parser does not know would produce a warning on
     * every re-import.
     *
     * Returns the literal `none` rather than an empty string — see the class
     * docblock: empty means "template default" to the importer, which is not
     * what a team with no apps means.
     */
    private function apps(string $teamId): string {
        $found = [];
        try {
            foreach ($this->resourceMapper->findAllByTeam($teamId) as $resource) {
                $appId = $resource->getAppId();
                if (in_array($appId, TeamTemplateProfiles::APPS, true)
                    && $resource->getStatus() === 'active'
                ) {
                    $found[$appId] = true;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamExportService] Resource lookup failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        // Emitted in TeamTemplateProfiles::APPS order rather than discovery
        // order, so the cell is stable across exports.
        $ordered = array_values(array_filter(
            TeamTemplateProfiles::APPS,
            static fn (string $app): bool => isset($found[$app]),
        ));

        return $ordered === [] ? self::NONE_TOKEN : implode(';', $ordered);
    }

    /**
     * Enabled modules, as the importer's `modules` tokens.
     *
     * Each key is read from wherever `TeamImportService::applyModules()` writes
     * it, so the two stay symmetric:
     *   presence / decisions — their own per-team config tables
     *   timeline / messages  — appconfig, default '1' (enabled unless switched off)
     *   pages                — the `intravox` row in teamhub_team_apps
     *   wiki                 — CollectivesService
     */
    private function modules(string $teamId): string {
        $enabled = [];

        try {
            if (($this->decisionTeamService->getConfig($teamId)['decisions_enabled'] ?? false) === true) {
                $enabled['decisions'] = true;
            }
        } catch (\Throwable) {
            // A module whose config cannot be read is reported as off rather
            // than guessed at — a wrong `on` would switch something on for a
            // team that never had it.
        }

        try {
            if (($this->presenceTeamService->getConfig($teamId)['presence_enabled'] ?? false) === true) {
                $enabled['presence'] = true;
            }
        } catch (\Throwable) {
        }

        if ($this->config->getAppValue(Application::APP_ID, 'timeline_enabled_' . $teamId, '1') === '1') {
            $enabled['timeline'] = true;
        }
        if ($this->config->getAppValue(Application::APP_ID, 'messages_enabled_' . $teamId, '1') === '1') {
            $enabled['messages'] = true;
        }

        try {
            foreach ($this->teamAppMapper->findByTeamId($teamId) as $row) {
                $appId = (string)($row['app_id'] ?? '');
                if ($appId === 'intravox' && !empty($row['enabled'])) {
                    $enabled['pages'] = true;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][TeamExportService] team_apps lookup failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        try {
            if ($this->collectivesService->isEnabledForTeam($teamId)) {
                $enabled['wiki'] = true;
            }
        } catch (\Throwable) {
        }

        $ordered = array_values(array_filter(
            TeamTemplateProfiles::MODULES,
            static fn (string $module): bool => isset($enabled[$module]),
        ));

        return $ordered === [] ? self::NONE_TOKEN : implode(';', $ordered);
    }

    /** `advanced` / `basic`, and empty for anything that is not a project. */
    private function projectMode(string $teamId, string $template): string {
        if ($template !== 'project') {
            return '';
        }
        try {
            $row = $this->projectMapper->findByTeam($teamId);
            if ($row === null) {
                return '';
            }
            $mode = (string)$row->getMode();
            return in_array($mode, TeamImportService::PROJECT_MODES, true) ? $mode : '';
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][TeamExportService] project lookup failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return '';
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * teamId => display name for every non-system circle.
     *
     * @return array<string,string>
     */
    private function listSelectableTeamsRaw(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('unique_id', 'name', 'display_name', 'sanitized_name')
            ->from('circles_circle')
            ->orderBy('name', 'ASC');

        $res = $qb->executeQuery();
        $out = [];
        while ($row = $res->fetch()) {
            $name = (string)($row['name'] ?? '');
            if ($this->isSystemCircle($name)) {
                continue;
            }
            $out[(string)$row['unique_id']] = $this->displayNameOf($row);
        }
        $res->closeCursor();

        return $out;
    }

    /**
     * @param string[] $teamIds
     * @return array<string,string>
     */
    private function descriptions(array $teamIds): array {
        if ($teamIds === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('unique_id', 'description')
            ->from('circles_circle')
            ->where($qb->expr()->in(
                'unique_id',
                $qb->createNamedParameter($teamIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY),
            ));

        $res = $qb->executeQuery();
        $out = [];
        while ($row = $res->fetch()) {
            $out[(string)$row['unique_id']] = (string)($row['description'] ?? '');
        }
        $res->closeCursor();

        return $out;
    }

    /**
     * Effective member counts from the Circles membership cache — the same
     * source the All-teams grid counts from, so the picker and the table agree.
     *
     * @param string[] $teamIds
     * @return array<string,int>
     */
    private function memberCounts(array $teamIds): array {
        if ($teamIds === []) {
            return [];
        }
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('circle_id')
                ->selectAlias($qb->func()->count('*'), 'cnt')
                ->from('circles_membership')
                ->where($qb->expr()->in(
                    'circle_id',
                    $qb->createNamedParameter($teamIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY),
                ))
                ->groupBy('circle_id');

            $res = $qb->executeQuery();
            $out = [];
            while ($row = $res->fetch()) {
                $out[(string)$row['circle_id']] = (int)$row['cnt'];
            }
            $res->closeCursor();
            return $out;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamExportService] Member count failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    /** Same system-circle filter the All-teams grid applies. */
    private function isSystemCircle(string $name): bool {
        foreach (['user:', 'group:', 'mail:', 'app:occ:', 'contact:'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $row */
    private function displayNameOf(array $row): string {
        if (!empty($row['display_name'])) {
            return (string)$row['display_name'];
        }
        if (!empty($row['sanitized_name'])) {
            return (string)$row['sanitized_name'];
        }
        $name = (string)($row['name'] ?? '');
        if (str_starts_with($name, 'app:circles:')) {
            return substr($name, strlen('app:circles:'));
        }
        return $name;
    }

    /**
     * @param resource $handle
     * @param list<string> $row
     */
    private function putRow($handle, array $row): void {
        // Escape passed explicitly as '' for the reason sampleCsv() gives:
        // PHP's proprietary backslash escape is not RFC 4180 and breaks
        // round-tripping a field ending in a backslash.
        fputcsv($handle, $row, ',', '"', '');
    }

    /**
     * Defence in depth. Every route into this service already carries
     * `#[AuthorizedAdminSetting]`; this is the second gate SKILLS.md §
     * Security standards asks for, on a service whose whole output is member
     * lists.
     */
    private function requireNcAdmin(): void {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new AccessDeniedException('Not authenticated');
        }
        if (!$this->groupManager->isAdmin($user->getUID())) {
            throw new AccessDeniedException('Nextcloud administrator privileges are required.');
        }
    }
}
