<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\TeamTemplateProfiles;
use OCA\TeamHub\Db\TeamImportMapper;
use OCA\TeamHub\Db\TeamTypeMapper;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Bulk team import from CSV — NC-admin only (v4.6.6).
 *
 * An admin uploads one row per team; TeamHub validates the whole file, shows a
 * dry-run preview, and on confirmation provisions each team exactly as the
 * create-team wizard does — plus it sets the intended owner, which the wizard
 * cannot do (its creator is always the owner).
 *
 * ## The `admin` column (v4.6.10)
 *
 * Multi-value, and position carries meaning: the **first** name becomes the
 * team's level-9 owner, every later name becomes a level-8 team admin. Circles
 * allows exactly one owner per circle, so a source system with several owners —
 * a Microsoft Teams export routinely has two or three — has to collapse to one
 * plus a set of admins. Document order is the rule because it is the only one
 * that survives a re-import unchanged and costs no extra column.
 *
 * Extra admins are folded into the member list before step 8, then raised to
 * level 8 in step 8b. That ordering is load-bearing; see the comment there.
 *
 * ## Why the run is durable
 *
 * `validate()` persists the import and every normalised row *before* returning
 * the preview. Confirming is then a state flip rather than a second upload, the
 * admin can close the tab without losing the parse, and {@see TeamImportJob}
 * can finish a run whose browser went away. The alternative — holding the
 * parsed file in the session or re-posting it on confirm — makes the preview a
 * promise about a file the server no longer has.
 *
 * ## Who does the provisioning
 *
 * The browser pumps `processNextChunk()` in a loop. That is deliberate: team
 * creation runs inside a real admin session, which is what Circles,
 * `ResourceService` and the Files/Talk/Deck services all read. The background
 * job is a safety net for an abandoned run, not the normal path — see the
 * session note on `TeamImportJob`.
 *
 * ## Row outcomes
 *
 * - **error**  — the row never runs. Bad name, unknown template, or the first
 *                name in the `admin` column resolving to no account (or to
 *                several).
 * - **skip**   — the row is valid but its team already exists (in the file, or
 *                on the instance). Recorded, not attempted.
 * - **warning** — the row runs with something adjusted: an advanced project
 *                downgraded because the licence does not allow it, an unknown
 *                member dropped, an unrecognised app or module token ignored.
 *                One typo in a member list must not cost a whole team.
 */
class TeamImportService {

    /** Upload ceiling, checked before a single byte is parsed. */
    public const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;

    /** Row ceiling, checked after splitting lines and before normalising them. */
    public const MAX_ROWS = 500;

    /** Rows provisioned per `processNextChunk()` call. */
    public const DEFAULT_CHUNK = 3;

    /** A run whose heartbeat is older than this is considered abandoned. */
    public const STALE_AFTER_SECONDS = 300;

    /** Canonical column order — also the order `sampleCsv()` writes. */
    public const COLUMNS = [
        'name', 'description', 'template', 'project_mode', 'admin', 'members', 'apps', 'modules',
    ];

    /** Header aliases, alias => canonical. Matched case-insensitively. */
    public const HEADER_ALIASES = ['team_type' => 'template'];

    /** Column delimiters we sniff for. Dutch and German Excel write ';'. */
    private const DELIMITERS = [',', ';', "\t"];

    public const PROJECT_MODES = ['advanced', 'basic'];

    /**
     * Memoised map of existing teams, keyed by lower-cased display name — see
     * {@see self::existingTeams()}. Built once per request: `validate()` asks
     * once for the whole file and each chunk asks once, and `circles_circle` is
     * small enough that one pass beats a query per row.
     */
    private ?array $existingNamesCache = null;

    public function __construct(
        private TeamImportMapper    $mapper,
        private TeamService         $teamService,
        private MemberService       $memberService,
        private ResourceService     $resourceService,
        private MaintenanceService  $maintenanceService,
        private TeamTypeMapper      $teamTypeMapper,
        private ProjectService      $projectService,
        private PresenceTeamService $presenceTeamService,
        private DecisionTeamService $decisionTeamService,
        private CollectivesService  $collectivesService,
        private IntravoxService     $intravoxService,
        private LicenseService      $licenseService,
        private IUserManager        $userManager,
        private IGroupManager       $groupManager,
        private IUserSession        $userSession,
        private IConfig             $config,
        private IDBConnection       $db,
        private LoggerInterface     $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Authorisation
    // -------------------------------------------------------------------------

    /**
     * Service-layer NC-admin gate, matching {@see MaintenanceService}: the
     * controller carries `#[AuthorizedAdminSetting]` and the service checks
     * again, so no caller can reach this logic through a route that forgot the
     * attribute.
     *
     * @return string The acting admin's uid.
     * @throws \RuntimeException when the caller is not an NC admin.
     */
    public function requireNcAdmin(): string {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \RuntimeException('Not authenticated');
        }
        if (!$this->groupManager->isAdmin($user->getUID())) {
            throw new \RuntimeException('NC admin privilege required');
        }

        return $user->getUID();
    }

    // -------------------------------------------------------------------------
    // Sample CSV
    // -------------------------------------------------------------------------

    /**
     * The downloadable sample, generated rather than shipped as a file.
     *
     * A `samples/` directory would have to be registered in `COVERED_DIRS` in
     * `scripts/generate-integrity.js`, the equivalent list in
     * `scripts/publish-to-release.js` and `verify-package.js` in the release
     * repo — miss one and it silently does not ship. Generating it also means
     * it cannot drift from the parser: the same class writes the file and reads
     * it back.
     *
     * Written with a BOM so Excel on Windows opens it as UTF-8 rather than
     * guessing the codepage. The parser tolerates a BOM, so the sample round-
     * trips through this service unmodified.
     */
    public function sampleCsv(): string {
        // Account names are written the way real ones look, not as tidy
        // lowercase tokens: NC uids routinely carry capitals, spaces and
        // accents. The v4.6.6 sample used `jdoek` / `alice` / `hr-lead`, which
        // quietly taught admins that a uid is a lowercase word — and the first
        // real import was typed `lieke adm` for an account called `Lieke Adm`.
        // fputcsv() quotes any cell containing the delimiter, so a name with a
        // comma round-trips through the parser untouched.
        //
        // v4.6.10 — the first row shows a multi-value `admin` cell, because
        // that is the shape a Microsoft Teams export lands in (several owners
        // per team) and the collapse rule — first name owns, the rest are team
        // admins — is not guessable from a single-value example.
        $rows = [
            self::COLUMNS,
            ['Website Redesign', 'Rebuild of the public website', 'project',       'advanced', 'Jane Doe;Bob Jones', 'Alice Smith;group:marketing', '',           ''],
            ['Design Guild',     'Cross-team design practice',    'collaboration', '',         'Alice Smith',        'Bob Jones;Renée Muñoz',       '',           ''],
            ['Human Resources',  'HR department team',            'department',    '',         'hr-lead',            'group:HR staff',              'talk;files', 'decisions;presence'],
        ];

        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            // Escape passed explicitly as '' — PHP's proprietary backslash
            // escape is not RFC 4180 and breaks round-tripping a field that
            // ends in a backslash. Passing it also avoids depending on a
            // default that has moved between PHP versions.
            fputcsv($handle, $row, ',', '"', '');
        }
        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        return "\xEF\xBB\xBF" . $csv;
    }

    // -------------------------------------------------------------------------
    // Validation (dry run)
    // -------------------------------------------------------------------------

    /**
     * Parse, normalise and persist an uploaded CSV, then return its preview.
     *
     * @param string $raw      Raw file contents.
     * @param string $filename Original name, for the run list.
     *
     * @return array The same shape `getImport()` returns.
     * @throws \InvalidArgumentException on anything that makes the file unusable
     *                                   as a whole (too big, too many rows, no
     *                                   header, missing required columns).
     */
    public function validate(string $raw, string $filename): array {
        $adminUid = $this->requireNcAdmin();

        if (strlen($raw) > self::MAX_UPLOAD_BYTES) {
            throw new \InvalidArgumentException('File is too large. Maximum size is 2 MB.');
        }
        if (trim($raw) === '') {
            throw new \InvalidArgumentException('The uploaded file is empty.');
        }

        $parsed = $this->parseCsv($raw);
        $header = $parsed['header'];
        $lines  = $parsed['rows'];

        if (!in_array('name', $header, true)) {
            throw new \InvalidArgumentException('Missing required column: name');
        }
        if (!in_array('template', $header, true)) {
            throw new \InvalidArgumentException('Missing required column: template (or team_type)');
        }
        if (!in_array('admin', $header, true)) {
            throw new \InvalidArgumentException('Missing required column: admin');
        }
        if ($lines === []) {
            throw new \InvalidArgumentException('The file has a header row but no team rows.');
        }
        if (count($lines) > self::MAX_ROWS) {
            throw new \InvalidArgumentException('Too many rows. The maximum is ' . self::MAX_ROWS . ' teams per import.');
        }

        // One lookup for the whole file rather than one per row.
        $existingNames  = $this->existingTeamNamesLower();
        $allowedInvites = $this->memberService->getAllowedInviteTypes();
        $advancedOk     = $this->licenseService->allowsAdvancedCreation();
        $seenNames      = [];

        $prepared = [];
        foreach ($lines as $index => $cells) {
            // Header is line 1, so the first data row is line 2 — the number the
            // admin can type straight into their spreadsheet's go-to-line box.
            $rowNum = $index + 2;
            $prepared[] = $this->normaliseRow(
                $header, $cells, $rowNum, $existingNames, $seenNames, $allowedInvites, $advancedOk
            );
        }

        $importId = $this->mapper->createImport($adminUid, $filename, count($prepared));
        foreach ($prepared as $row) {
            $this->mapper->insertRow(
                $importId,
                $row['row_num'],
                $row['payload'],
                $row['status'],
                $row['message'],
            );
        }

        $this->logger->info('[TeamHub][TeamImportService] Import validated', [
            'importId' => $importId,
            'rows'     => count($prepared),
            'app'      => Application::APP_ID,
        ]);

        return $this->getImport($importId);
    }

    /**
     * Normalise and validate one parsed CSV line.
     *
     * @param list<string>          $header
     * @param list<string>          $cells
     * @param array<string,int>     $existingNames  lower name => 1
     * @param array<string,int>     $seenNames      lower name => first row number; mutated
     * @param list<string>          $allowedInvites
     *
     * @return array{row_num:int, status:string, message:?string, payload:?array<string,mixed>}
     */
    private function normaliseRow(
        array $header,
        array $cells,
        int   $rowNum,
        array $existingNames,
        array &$seenNames,
        array $allowedInvites,
        bool  $advancedOk,
    ): array {
        $get = static function (string $column) use ($header, $cells): string {
            $at = array_search($column, $header, true);
            if ($at === false || !isset($cells[$at])) {
                return '';
            }

            return trim((string)$cells[$at]);
        };

        $errors   = [];
        $warnings = [];

        // ── name ─────────────────────────────────────────────────────────
        // assertValidTeamName() is the one rule — reused rather than restated
        // so the preview and the provisioning step can never disagree about
        // what a legal name is.
        $name = $get('name');
        try {
            $this->teamService->assertValidTeamName($name);
        } catch (\InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        // ── template ─────────────────────────────────────────────────────
        $template = mb_strtolower($get('template'));
        if ($template === '') {
            $errors[] = 'Template is required. Allowed: ' . implode(', ', TeamTypeService::ALLOWED) . '.';
        } elseif (!in_array($template, TeamTypeService::ALLOWED, true)) {
            $errors[] = 'Unknown template "' . $template . '". Allowed: ' . implode(', ', TeamTypeService::ALLOWED) . '.';
        }

        // ── project_mode ─────────────────────────────────────────────────
        // Only meaningful for the project template, but a bad value anywhere is
        // a typo worth reporting rather than silently discarding.
        $projectMode = mb_strtolower($get('project_mode'));
        if ($projectMode !== '' && !in_array($projectMode, self::PROJECT_MODES, true)) {
            $errors[] = 'Unknown project_mode "' . $projectMode . '". Allowed: advanced, basic.';
            $projectMode = '';
        }
        if ($template === 'project') {
            if ($projectMode === '') {
                $projectMode = 'advanced';
            }
            if ($projectMode === 'advanced' && !$advancedOk) {
                $projectMode = 'basic';
                $warnings[] = 'Advanced projects require a valid licence — created as a basic project instead.';
            }
        } else {
            $projectMode = '';
        }

        // ── admin ────────────────────────────────────────────────────────
        // v4.6.10 — multi-value, and the position in the cell carries meaning:
        // the FIRST name becomes the team's level-9 owner, every later name
        // becomes a level-8 team admin. Circles has exactly one owner per
        // circle, so a source system with several owners (Microsoft Teams
        // exports routinely carry two or three) has to collapse to one plus a
        // set of admins — doing that here, by document order, is the only rule
        // that is stable across re-imports and needs no extra column.
        //
        // Every name is resolved to the account's own spelling and never stored
        // as typed — see resolveUid(). Getting this wrong does not merely
        // mislabel the row: the first value becomes the team's owner identity
        // in Circles.
        $adminTokens = $this->splitMulti($get('admin'));
        $owner       = '';
        $extraAdmins = [];
        $rewritten   = [];
        if ($adminTokens === []) {
            $errors[] = 'Admin is required.';
        } else {
            $seenAdmins = [];
            foreach ($adminTokens as $position => $typed) {
                // The owner slot is the first *position*, not the first name
                // that happens to resolve. If position 0 is a typo, position 1
                // is not the owner the admin meant — it is the owner they would
                // get by accident, and the row failing is the only outcome that
                // makes them fix the typo. Keeping the slot empty also stops
                // the preview from showing a name under Owner on a row that
                // failed precisely because the owner could not be resolved.
                $isOwnerSlot = $position === 0;

                $resolved = $this->resolveUid($typed);
                if ($resolved === null) {
                    // Fatal in the owner slot; elsewhere it is one dropped
                    // admin on a team that is otherwise right — the same
                    // bargain the members column already strikes.
                    if ($isOwnerSlot) {
                        $errors[] = 'No account found for admin "' . $typed . '".';
                    } else {
                        $warnings[] = 'No account found for admin "' . $typed . '" — not made a team admin.';
                    }
                    continue;
                }
                if ($resolved === '') {
                    if ($isOwnerSlot) {
                        $errors[] = 'Admin "' . $typed . '" matches more than one account — use the exact account name.';
                    } else {
                        $warnings[] = 'Admin "' . $typed . '" matches more than one account — not made a team admin.';
                    }
                    continue;
                }
                if ($typed !== $resolved) {
                    $rewritten[] = '"' . $typed . '" → ' . $resolved;
                }

                $lower = mb_strtolower($resolved);
                if (isset($seenAdmins[$lower])) {
                    continue;   // same account named twice in one cell
                }
                $seenAdmins[$lower] = true;

                if ($isOwnerSlot) {
                    $owner = $resolved;
                } else {
                    $extraAdmins[] = $resolved;
                }
            }
        }

        // ── members ──────────────────────────────────────────────────────
        $members = [];
        foreach ($this->splitMulti($get('members')) as $token) {
            $resolved = $this->resolveMemberToken($token, $allowedInvites);
            if ($resolved['member'] !== null) {
                $members[] = $resolved['member'];
            }
            if ($resolved['warning'] !== null) {
                $warnings[] = $resolved['warning'];
            }
            if ($resolved['rewrote'] !== null) {
                $rewritten[] = $resolved['rewrote'];
            }
        }

        // Say so when a name was matched to a differently-spelled account.
        // This is the row's answer to "did it find the account I meant?", and
        // it is the check that would have caught the v4.6.6 case bug on sight.
        if ($rewritten !== []) {
            $warnings[] = 'Matched accounts: ' . implode(', ', $rewritten) . '.';
        }

        // ── apps ─────────────────────────────────────────────────────────
        // Empty cell = template default; the literal token `none` = create
        // nothing. Anything else is read as an explicit subset.
        $appTokens = $this->splitMulti(mb_strtolower($get('apps')));
        if ($appTokens === []) {
            $apps = $template === '' ? [] : TeamTemplateProfiles::appsToCreate($template);
        } elseif (count($appTokens) === 1 && $appTokens[0] === 'none') {
            $apps = [];
        } else {
            $apps = [];
            foreach ($appTokens as $token) {
                if (in_array($token, TeamTemplateProfiles::APPS, true)) {
                    $apps[] = $token;
                } else {
                    $warnings[] = 'Unknown app "' . $token . '" ignored.';
                }
            }
            $apps = array_values(array_unique($apps));
        }

        // ── modules ──────────────────────────────────────────────────────
        $moduleTokens = $this->splitMulti(mb_strtolower($get('modules')));
        $allOff       = array_fill_keys(TeamTemplateProfiles::MODULES, false);
        if ($moduleTokens === []) {
            $modules = $template === '' ? $allOff : TeamTemplateProfiles::modules($template);
        } elseif (count($moduleTokens) === 1 && $moduleTokens[0] === 'none') {
            $modules = $allOff;
        } else {
            $modules = $allOff;
            foreach ($moduleTokens as $token) {
                if (isset($modules[$token])) {
                    $modules[$token] = true;
                } else {
                    $warnings[] = 'Unknown module "' . $token . '" ignored.';
                }
            }
        }

        // ── duplicate / collision ────────────────────────────────────────
        // Checked after the field rules so a row that is both invalid and a
        // duplicate reports the invalidity — the more actionable of the two.
        $skipReason = null;
        $lowerName  = mb_strtolower($name);
        if ($errors === [] && $lowerName !== '') {
            if (isset($seenNames[$lowerName])) {
                $skipReason = 'Duplicate name in this file — first used on row ' . $seenNames[$lowerName] . '.';
            } elseif (isset($existingNames[$lowerName])) {
                $skipReason = 'Team name already exists.';
            } else {
                $seenNames[$lowerName] = $rowNum;
            }
        }

        $payload = [
            'name'         => $name,
            'description'  => $get('description'),
            'template'     => $template,
            'project_mode' => $projectMode,
            // First name in the admin column. Still called `owner` in the
            // payload because that is the role it fills — the CSV column was
            // renamed, the Circles concept was not.
            'owner'        => $owner,
            'admins'       => $extraAdmins,
            'members'      => $members,
            'apps'         => $apps,
            'modules'      => $modules,
            'warnings'     => $warnings,
        ];

        if ($errors !== []) {
            return [
                'row_num' => $rowNum,
                'status'  => 'failed',
                'message' => implode(' ', $errors),
                'payload' => $payload,
            ];
        }
        if ($skipReason !== null) {
            return [
                'row_num' => $rowNum,
                'status'  => 'skipped',
                'message' => $skipReason,
                'payload' => $payload,
            ];
        }

        return [
            'row_num' => $rowNum,
            'status'  => 'pending',
            'message' => $warnings === [] ? null : implode(' ', $warnings),
            'payload' => $payload,
        ];
    }

    /**
     * Resolve one member token into the `{id, type}` shape
     * {@see MemberService::inviteMembers()} takes.
     *
     * A bare token is a user uid, `group:<group>` an NC group, `team:<team>` a
     * sub-team. All three resolve by name, case-insensitively; `team:` also
     * accepts a raw Circles unique_id. An unresolvable token is dropped with a
     * warning rather than failing the row: one typo in a member list must not
     * cost a whole team.
     *
     * `rewrote` is set when the resolved id differs from what was written, so
     * the row can tell the admin which account or group a name landed on. It is
     * not a warning — nothing went wrong — but it is the line that would have
     * made the v4.6.6 case bug obvious in the preview.
     *
     * @param list<string> $allowedInvites
     * @return array{member: array{id:string, type:string}|null, warning: ?string, rewrote: ?string}
     */
    private function resolveMemberToken(string $token, array $allowedInvites): array {
        $drop = static fn (?string $warning): array
            => ['member' => null, 'warning' => $warning, 'rewrote' => null];

        $take = static function (string $id, string $type, string $typed): array {
            return [
                'member'  => ['id' => $id, 'type' => $type],
                'warning' => null,
                'rewrote' => $id === $typed ? null : '"' . $typed . '" → ' . $id,
            ];
        };

        if ($token === '') {
            return $drop(null);
        }

        // Prefix detection lower-cases a copy only. `substr` always runs on the
        // original, so an account or group id keeps every capital, accent and
        // space it was written with.
        $lower = mb_strtolower($token);

        if (str_starts_with($lower, 'group:')) {
            $gidTyped = trim(substr($token, 6));
            if ($gidTyped === '') {
                return $drop('Empty group in members ignored.');
            }
            if (!in_array('group', $allowedInvites, true)) {
                return $drop('Group invites are disabled — "' . $gidTyped . '" was not added.');
            }
            $gid = $this->resolveGid($gidTyped);
            if ($gid === null) {
                return $drop('No group found for "' . $gidTyped . '" — it was not added.');
            }
            if ($gid === '') {
                return $drop('Group "' . $gidTyped . '" matches more than one group — use the exact group name.');
            }

            return $take($gid, 'group', $gidTyped);
        }

        if (str_starts_with($lower, 'team:')) {
            $teamTyped = trim(substr($token, 5));
            if ($teamTyped === '') {
                return $drop('Empty team in members ignored.');
            }
            // 'circle' is what MemberService calls a team-as-member; the CSV
            // says `team:` because that is what the product calls it.
            if (!in_array('circle', $allowedInvites, true)) {
                return $drop('Team invites are disabled — "' . $teamTyped . '" was not added.');
            }
            // v4.6.11 — a name works here, not only an opaque Circles
            // unique_id. `group:` had always resolved by name and `team:` had
            // not, so `team:Marketing` was dropped as unknown while
            // `group:Marketing` worked — an asymmetry with no reason behind it
            // beyond the id being what MemberService wants at the far end.
            // Nobody hand-writes a 31-character hash into a spreadsheet.
            $team = $this->resolveTeamId($teamTyped);
            if ($team === null) {
                return $drop('No team found for "' . $teamTyped . '" — it was not added.');
            }
            if ($team['id'] === '') {
                return $drop('Team "' . $teamTyped . '" matches more than one team — use the team ID instead.');
            }

            // Built here rather than through $take(): that helper compares the
            // resolved id against what was typed, which for a team means the
            // note would read `"Marketing" → 3f9a…`. The admin needs to know
            // which team it landed on, and the id does not tell them that.
            return [
                'member'  => ['id' => $team['id'], 'type' => 'circle'],
                'warning' => null,
                'rewrote' => $team['display'] !== $teamTyped
                    ? '"' . $teamTyped . '" → ' . $team['display']
                    : null,
            ];
        }

        $uid = $this->resolveUid($token);
        if ($uid === null) {
            return $drop('No account found for "' . $token . '" — it was not added.');
        }
        if ($uid === '') {
            return $drop('"' . $token . '" matches more than one account — it was not added. Use the exact account name.');
        }

        return $take($uid, 'user', $token);
    }

    /**
     * Resolve a written account name to the account's own spelling of its uid.
     *
     * **This must never return the caller's string.** Nextcloud matches uids
     * case-insensitively (`oc_users.uid_lower`), so `userExists()` happily
     * confirms an account for `lieke adm` when the uid is `Lieke Adm` — and
     * the importer then used the typed string as an identity. That is how
     * v4.6.6 deleted a real owner row, minted a phantom Circles principal for
     * `user:lieke adm:…`, and left the owner reading "You are not a member":
     * `MemberService::getMemberLevelFromDb()` compares `user_id` with `=`,
     * which is case-sensitive on Postgres, and Circles resolves an initiator
     * by `single_id`. Both missed. Resolve once, store the canonical value,
     * and neither can miss again.
     *
     * Special characters need no handling of their own — spaces, accents,
     * apostrophes and non-Latin scripts all survive, because nothing here
     * rewrites the uid. `mb_strtolower()` is only ever applied to a throwaway
     * copy used for comparison.
     *
     * Two lookups, because `IUserManager::get()` is only case-insensitive on
     * backends that keep a folded column. The Database backend does; LDAP and
     * SSO backends may not, so a `search()` pass follows and is filtered to
     * exact case-insensitive uid equality — `search()` also matches substrings,
     * and `Jan` must not silently become `Janet`.
     *
     * @return string|null The canonical uid; `''` when the name is ambiguous
     *                     across backends; `null` when no account matches.
     */
    private function resolveUid(string $raw): ?string {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $user = $this->userManager->get($raw);
        if ($user !== null) {
            return $user->getUID();
        }

        $wanted  = mb_strtolower($raw);
        $matches = [];
        try {
            foreach ($this->userManager->search($raw, 50) as $candidate) {
                $uid = $candidate->getUID();
                if (mb_strtolower($uid) === $wanted) {
                    $matches[$uid] = true;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamImportService] Account search failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        if ($matches === []) {
            return null;
        }
        if (count($matches) > 1) {
            // Two backends disagreeing about case. Refusing is the only safe
            // answer — picking one would hand the team to a coin flip.
            return '';
        }

        return (string)array_key_first($matches);
    }

    /**
     * The group-id equivalent of {@see resolveUid()}.
     *
     * NC group ids are case-sensitive, so an exact `get()` is the answer
     * whenever it lands. The search fallback exists for the same reason it
     * does for accounts: a spreadsheet is typed by a human, and `Marketing`
     * for `marketing` should invite the group rather than silently drop it.
     *
     * @return string|null Canonical gid; `''` when ambiguous; `null` when none.
     */
    private function resolveGid(string $raw): ?string {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $group = $this->groupManager->get($raw);
        if ($group !== null) {
            return $group->getGID();
        }

        $wanted  = mb_strtolower($raw);
        $matches = [];
        try {
            foreach ($this->groupManager->search($raw, 50) as $candidate) {
                $gid = $candidate->getGID();
                if (mb_strtolower($gid) === $wanted) {
                    $matches[$gid] = true;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamImportService] Group search failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        if ($matches === []) {
            return null;
        }
        if (count($matches) > 1) {
            return '';
        }

        return (string)array_key_first($matches);
    }

    // -------------------------------------------------------------------------
    // Reading a run
    // -------------------------------------------------------------------------

    /**
     * Import plus its rows, in the shape the admin panel renders.
     *
     * @return array<string,mixed>
     * @throws \RuntimeException when the import does not exist.
     */
    public function getImport(int $importId): array {
        $this->requireNcAdmin();

        return $this->buildImportPayload($importId);
    }

    /**
     * `getImport()` without the gate, for callers that have already established
     * who is asking — the chunk loop, which gated on entry, and the background
     * job, which verified the import's `created_by` before impersonating them.
     *
     * @return array<string,mixed>
     */
    private function buildImportPayload(int $importId): array {
        $import = $this->mapper->findImport($importId);
        if ($import === null) {
            throw new \RuntimeException('Import not found');
        }

        $rows    = $this->mapper->findRows($importId);
        $summary = ['ready' => 0, 'skipped' => 0, 'errors' => 0, 'created' => 0, 'failed' => 0, 'warnings' => 0];

        $out = [];
        foreach ($rows as $row) {
            $payload = $row['payload'] ?? [];
            $status  = $row['status'];

            // 'pending' and 'running' are both "will be created"; the preview
            // does not distinguish a row that is queued from one in flight.
            if ($status === 'pending' || $status === 'running') {
                $summary['ready']++;
            } elseif ($status === 'created') {
                $summary['created']++;
            } elseif ($status === 'skipped') {
                $summary['skipped']++;
            } elseif ($status === 'failed') {
                // Before the run these are validation errors; after it they are
                // provisioning failures. Same column, same meaning to the admin:
                // this row produced no team.
                $import['status'] === 'validated' ? $summary['errors']++ : $summary['failed']++;
            }
            if (!empty($payload['warnings'])) {
                $summary['warnings']++;
            }

            $out[] = [
                'row_num'      => $row['row_num'],
                'name'         => (string)($payload['name'] ?? ''),
                'template'     => (string)($payload['template'] ?? ''),
                'project_mode' => (string)($payload['project_mode'] ?? ''),
                'owner'        => (string)($payload['owner'] ?? ''),
                // Extra admins only — the owner is already in `owner` and the
                // preview renders it first, so repeating it here would show a
                // name twice in the same cell.
                'admins'       => is_array($payload['admins'] ?? null) ? array_values($payload['admins']) : [],
                'member_count' => is_array($payload['members'] ?? null) ? count($payload['members']) : 0,
                'status'       => $status,
                'message'      => $row['message'],
                'team_id'      => $row['team_id'],
            ];
        }

        return [
            'import'  => $import,
            'summary' => $summary,
            'rows'    => $out,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listRecent(int $limit = 10): array {
        $this->requireNcAdmin();

        return $this->mapper->findRecentImports($limit);
    }

    // -------------------------------------------------------------------------
    // Running
    // -------------------------------------------------------------------------

    /**
     * Confirm a validated run. Idempotent: a second call on an already-running
     * import returns its current state rather than restarting it.
     *
     * @return array<string,mixed>
     */
    public function start(int $importId): array {
        $this->requireNcAdmin();

        $import = $this->mapper->findImport($importId);
        if ($import === null) {
            throw new \RuntimeException('Import not found');
        }
        if ($import['status'] === 'validated') {
            $this->mapper->startImport($importId);
            $this->logger->info('[TeamHub][TeamImportService] Import started', [
                'importId' => $importId, 'app' => Application::APP_ID,
            ]);
        }

        return $this->getImport($importId);
    }

    /**
     * Provision up to $limit pending rows and report progress.
     *
     * Small chunks by default: each row can create a Talk room, a group folder,
     * a calendar and a Deck board, so three rows is already a slow request. The
     * browser calls this in a loop, which also means a request that times out
     * costs at most one chunk.
     *
     * @return array<string,mixed>
     */
    public function processNextChunk(int $importId, int $limit = self::DEFAULT_CHUNK): array {
        $this->requireNcAdmin();

        return $this->runChunk($importId, $limit);
    }

    /**
     * The chunk body, without the admin gate.
     *
     * Split out for {@see \OCA\TeamHub\BackgroundJob\TeamImportJob}, which has
     * no session to check when it starts and installs one of its own before
     * calling this — re-checking `requireNcAdmin()` there would test the session
     * the job just faked rather than a real caller's rights. The job verifies
     * the import's `created_by` is still an admin before it impersonates them,
     * which is the check that matters.
     *
     * @return array<string,mixed>
     */
    public function runChunk(int $importId, int $limit = self::DEFAULT_CHUNK): array {
        $import = $this->mapper->findImport($importId);
        if ($import === null) {
            throw new \RuntimeException('Import not found');
        }
        if ($import['status'] !== 'running') {
            return $this->progress($importId);
        }

        $limit = max(1, min(10, $limit));
        $this->mapper->touchHeartbeat($importId);

        foreach ($this->mapper->findPendingRows($importId, $limit) as $row) {
            // Whoever wins the claim owns the row. A losing racer (the sweeper
            // deciding a run was stale while the tab was in fact alive) simply
            // moves on, so no team is created twice.
            if (!$this->mapper->claimRow($row['id'])) {
                continue;
            }

            $payload = $row['payload'];
            if (!is_array($payload)) {
                $this->mapper->finishRow($row['id'], 'failed', null, 'Row payload could not be read.');
                continue;
            }

            // Re-check the collision here as well as at validation time: a run
            // resumed hours later by the sweeper may find names that were free
            // when the file was uploaded and are not any more. The snapshot is
            // taken once per chunk, which is enough — duplicates *within* the
            // file were already skipped at validation time, so the only thing
            // this can miss is a name another session created seconds ago.
            $lowerName = mb_strtolower((string)($payload['name'] ?? ''));
            if ($lowerName !== '' && isset($this->existingTeamNamesLower()[$lowerName])) {
                $this->mapper->finishRow($row['id'], 'skipped', null, 'Team name already exists.');
                continue;
            }

            try {
                $result = $this->provisionRow($payload);
                $this->mapper->finishRow(
                    $row['id'],
                    $result['status'],
                    $result['teamId'],
                    $result['message'],
                );
            } catch (\Throwable $e) {
                $this->logger->error('[TeamHub][TeamImportService] Row provisioning failed', [
                    'importId' => $importId,
                    'rowNum'   => $row['row_num'],
                    'error'    => $e->getMessage(),
                    'app'      => Application::APP_ID,
                ]);
                $this->mapper->finishRow($row['id'], 'failed', null, $e->getMessage());
            }

            $this->mapper->touchHeartbeat($importId);
        }

        return $this->progress($importId);
    }

    /**
     * Recompute counters from the row table, mark the run finished when nothing
     * is left, and return the caller's progress payload.
     *
     * Counters are derived rather than incremented so a chunk retried after a
     * crashed request cannot double-count.
     *
     * @return array<string,mixed>
     */
    private function progress(int $importId): array {
        $counts  = $this->mapper->countRowsByStatus($importId);
        $created = $counts['created'] ?? 0;
        $skipped = $counts['skipped'] ?? 0;
        $failed  = $counts['failed']  ?? 0;
        $pending = ($counts['pending'] ?? 0) + ($counts['running'] ?? 0);

        $this->mapper->updateCounters($importId, $created, $skipped, $failed);

        $import = $this->mapper->findImport($importId);
        if ($import !== null && $import['status'] === 'running' && $pending === 0) {
            $this->mapper->finishImport($importId, 'completed');
            $this->logger->info('[TeamHub][TeamImportService] Import completed', [
                'importId' => $importId, 'created' => $created,
                'skipped'  => $skipped, 'failed' => $failed,
                'app'      => Application::APP_ID,
            ]);
        }

        return $this->buildImportPayload($importId);
    }

    /**
     * Discard a validated run, or cancel one in flight.
     *
     * A cancelled run's already-created teams are left alone — they are real
     * teams with real resources, and unwinding them is a different operation
     * with a different confirmation. Remaining pending rows simply never run.
     *
     * @return array{cancelled: bool, deleted: bool}
     */
    public function discard(int $importId): array {
        $this->requireNcAdmin();

        $import = $this->mapper->findImport($importId);
        if ($import === null) {
            throw new \RuntimeException('Import not found');
        }

        if ($import['status'] === 'validated') {
            $this->mapper->deleteImport($importId);
            $this->logger->info('[TeamHub][TeamImportService] Import discarded before start', [
                'importId' => $importId, 'app' => Application::APP_ID,
            ]);

            return ['cancelled' => false, 'deleted' => true];
        }

        $this->mapper->finishImport($importId, 'cancelled');
        $this->logger->info('[TeamHub][TeamImportService] Import cancelled', [
            'importId' => $importId, 'app' => Application::APP_ID,
        ]);

        return ['cancelled' => true, 'deleted' => false];
    }

    // -------------------------------------------------------------------------
    // Provisioning one row
    // -------------------------------------------------------------------------

    /**
     * Create one team, mirroring `CreateTeamView.vue`'s submit sequence.
     *
     * Step 1 failing fails the row — there is no team to carry on with. Every
     * later step is best-effort: it records into `message` and the run
     * continues, because a team that exists with a missing Deck board is a
     * better outcome for the admin than a team that does not exist at all.
     *
     * The admin is the Circles session user throughout, so every step's
     * `requireAdminLevel()` passes — which is precisely why step 9 is last.
     *
     * @param array<string,mixed> $payload
     * @return array{status:string, teamId:?string, message:?string}
     */
    private function provisionRow(array $payload): array {
        $name        = (string)$payload['name'];
        $description = (string)($payload['description'] ?? '');
        $template    = (string)$payload['template'];
        $projectMode = (string)($payload['project_mode'] ?? '');
        $owner       = (string)$payload['owner'];
        $extraAdmins = is_array($payload['admins'] ?? null) ? $payload['admins'] : [];
        $members     = is_array($payload['members'] ?? null) ? $payload['members'] : [];
        $apps        = is_array($payload['apps'] ?? null) ? $payload['apps'] : [];
        $modules     = is_array($payload['modules'] ?? null) ? $payload['modules'] : [];

        // Extra admins have to be members before they can be promoted, and the
        // admin column is a separate cell from the members column — so fold
        // them in here rather than making the admin list them twice. Dedup is
        // by uid: a name in both cells must not produce two invite attempts,
        // because inviteMembers() reads the second one's "already invited"
        // refusal as success and the row would report a member it never added.
        if ($extraAdmins !== []) {
            $seenMembers = [];
            foreach ($members as $member) {
                if (($member['type'] ?? '') === 'user') {
                    $seenMembers[mb_strtolower((string)($member['id'] ?? ''))] = true;
                }
            }
            foreach ($extraAdmins as $adminUidToAdd) {
                if (!isset($seenMembers[mb_strtolower($adminUidToAdd)])) {
                    $members[] = ['id' => $adminUidToAdd, 'type' => 'user'];
                    $seenMembers[mb_strtolower($adminUidToAdd)] = true;
                }
            }
        }

        // Warnings raised at validation time travel with the row so the results
        // CSV shows why a team has fewer members than its source line listed.
        $notes = is_array($payload['warnings'] ?? null) ? $payload['warnings'] : [];

        $adminUid = $this->userSession->getUser()?->getUID() ?? '';

        // ── 1. Create the team (fatal) ───────────────────────────────────
        $team   = $this->teamService->createTeam($name);
        $teamId = (string)($team['id'] ?? $team['unique_id'] ?? '');
        if ($teamId === '') {
            throw new \RuntimeException('Team was created but no id was returned.');
        }

        // ── 2. Privacy bitmask from the template ─────────────────────────
        $this->bestEffort($notes, 'privacy settings', function () use ($teamId, $template) {
            $this->teamService->updateTeamConfig($teamId, TeamTemplateProfiles::configBitmask($template));
        });

        // ── 3. Description ───────────────────────────────────────────────
        if ($description !== '') {
            $this->bestEffort($notes, 'description', function () use ($teamId, $description) {
                $this->teamService->updateTeamDescription($teamId, $description);
            });
        }

        // ── 4. Team type ─────────────────────────────────────────────────
        // Written through the mapper rather than TeamTypeService::setType(),
        // which re-checks requireAdminLevel() — true here, but the check costs a
        // round-trip per team and the value is already validated.
        $this->bestEffort($notes, 'team type', function () use ($teamId, $template, $adminUid) {
            $this->teamTypeMapper->upsert($teamId, $template, $adminUid);
        });

        // ── 5. App resources ─────────────────────────────────────────────
        if ($apps !== []) {
            $this->bestEffort($notes, 'app resources', function () use ($teamId, $apps, $name, $projectMode) {
                $results = $this->resourceService->createTeamResources(
                    $teamId, $apps, $name, [], $projectMode !== '' ? $projectMode : null
                );
                $failedApps = [];
                foreach ($results as $app => $result) {
                    if (!empty($result['error'])) {
                        $failedApps[] = (string)$app;
                    }
                }
                if ($failedApps !== []) {
                    throw new \RuntimeException('failed for ' . implode(', ', $failedApps));
                }
            });
        }

        // ── 6. Module configs ────────────────────────────────────────────
        $this->applyModules($teamId, $name, $modules, $adminUid, $notes);

        // ── 7. Project mode ──────────────────────────────────────────────
        if ($template === 'project' && $projectMode !== '') {
            $this->bestEffort($notes, 'project mode', function () use ($teamId, $projectMode, $adminUid) {
                $this->projectService->upsert($teamId, $projectMode, null, null, $adminUid);
            });
        }

        // ── 8. Members ───────────────────────────────────────────────────
        if ($members !== []) {
            $this->bestEffort($notes, 'members', function () use ($teamId, $members, $adminUid) {
                $results = $this->memberService->inviteMembers($teamId, $members);

                // Keep the reason, not just the name. inviteMembers() answers
                // 'failed: <what Circles said>', and v4.6.6 recorded only the
                // name — so diagnosing a dropped member meant reading
                // nextcloud.log instead of the results CSV, which is the one
                // artefact the admin actually has.
                $failedMembers = [];
                foreach ($results as $memberId => $outcome) {
                    if (is_string($outcome) && str_starts_with($outcome, 'failed')) {
                        $detail = trim(substr($outcome, strlen('failed:')));
                        $failedMembers[] = $detail === ''
                            ? (string)$memberId
                            : $memberId . ' (' . $detail . ')';
                    }
                }

                // A member the CSV asked for that produced no row at all, with
                // no failure reported either. inviteMembers() reads every
                // FederatedItemBadRequestException as "already invited" and
                // reports success, so a genuine refusal can arrive as silence.
                // Silence is the one outcome an admin cannot act on.
                $missing = [];
                foreach ($members as $member) {
                    $id = (string)$member['id'];
                    // inviteMembers() skips the acting user with no result
                    // entry — they are already the creator, so that silence is
                    // expected rather than a dropped member.
                    if (($member['type'] ?? '') === 'user' && mb_strtolower($id) === mb_strtolower($adminUid)) {
                        continue;
                    }
                    if (!array_key_exists($id, $results)) {
                        $missing[] = $id;
                    }
                }
                if ($missing !== []) {
                    $failedMembers[] = implode(', ', $missing) . ' (no result reported)';
                }

                if ($failedMembers !== []) {
                    throw new \RuntimeException('could not add ' . implode('; ', $failedMembers));
                }
            });
        }

        // ── 8b. Team admins ──────────────────────────────────────────────
        // Every name after the first in the admin column. They were folded into
        // $members above, so by now they have a row; this raises it to level 8.
        //
        // Runs BEFORE step 9 on purpose. `adminSetMemberLevel()` refuses a
        // level-9 row, and until the handoff the acting admin holds that row —
        // so anyone named here is guaranteed not to be the owner yet. Running
        // it after the handoff would also mean the new owner's row is level 9
        // and the acting admin may no longer be in the team at all.
        //
        // A name with no row is reported rather than swallowed: it means the
        // invite in step 8 did not land, which is exactly the failure HANDOFF
        // §0 is open on, and an admin promotion silently doing nothing is how
        // that bug stayed invisible for a version.
        if ($extraAdmins !== []) {
            $this->bestEffort($notes, 'team admins', function () use ($teamId, $extraAdmins) {
                $notPromoted = [];
                foreach ($extraAdmins as $adminToPromote) {
                    if (!$this->maintenanceService->adminSetMemberLevel($teamId, $adminToPromote, 8, false)) {
                        $notPromoted[] = $adminToPromote;
                    }
                }
                if ($notPromoted !== []) {
                    throw new \RuntimeException(
                        'could not make team admin: ' . implode(', ', $notPromoted)
                        . ' (no member row — the invite did not land)'
                    );
                }
            });
        }

        // ── 9. Owner — LAST ──────────────────────────────────────────────
        // Until this call the admin is the owner, which is what let every step
        // above pass its own admin gate. Afterwards they are not a member at
        // all, unless the CSV named them: an admin importing 200 teams should
        // not end up in 200 teams.
        $adminIsIntendedMember = $this->adminIsNamed($adminUid, $owner, $members);
        $ownerAssigned         = true;
        $this->bestEffort($notes, 'owner assignment', function () use ($teamId, $owner, $adminIsIntendedMember, &$ownerAssigned) {
            $ownerAssigned = false;
            $this->maintenanceService->assignOwner(
                $teamId,
                $owner,
                false,                      // $enforceNcAdmin — already gated above
                !$adminIsIntendedMember,    // $removePreviousOwner
            );
            $ownerAssigned = true;
        });

        // ── 10. Re-apply the privacy bitmask ─────────────────────────────
        // Not part of the wizard's sequence — the wizard has no step 9.
        //
        // `assignOwner()` step 4 resets `circles_circle.config` to 0, which is
        // right for the Maintenance tab it was written for (a team being
        // repaired is one whose config is suspect) and wrong here: it lands
        // after step 2 and silently takes the template's privacy settings with
        // it. Re-applying is a local fix, so the repair path keeps the reset it
        // was designed around.
        //
        // Note for the wizard-vs-import diff: `updateTeamConfig()` writes only
        // MANAGED_BITS, so any bit Circles set at creation time and the reset
        // cleared stays cleared — most visibly CFG_PERSONAL, which Circles sets
        // by default and which `CirclesConfig` documents as meaningless on a
        // multi-member team and fatal to Collectives' config writes (v4.5.35).
        // An imported team therefore ends up with a *cleaner* config integer
        // than a wizard-created one, not an equal one.
        if ($ownerAssigned) {
            $this->bestEffort($notes, 'privacy settings', function () use ($teamId, $template) {
                $this->teamService->updateTeamConfig($teamId, TeamTemplateProfiles::configBitmask($template));
            });
        }

        return [
            'status'  => 'created',
            'teamId'  => $teamId,
            'message' => $notes === [] ? null : implode(' ', $notes),
        ];
    }

    /**
     * Step 6 in the wizard's order: presence, decisions, timeline, messages,
     * IntraVox pages, Collectives wiki.
     *
     * Timeline and messages default to on server-side, so — exactly as the
     * wizard does — only an *off* value is written. Presence and decisions are
     * written only when the module is enabled instance-wide, because their
     * services are gated on that.
     *
     * @param array<string,bool> $modules
     * @param list<string>       $notes
     */
    private function applyModules(string $teamId, string $teamName, array $modules, string $adminUid, array &$notes): void {
        $presenceEnabledGlobally  = $this->config->getAppValue(Application::APP_ID, 'presence_module_enabled', '1') === '1';
        $decisionsEnabledGlobally = $this->config->getAppValue(Application::APP_ID, 'decisions_module_enabled', '1') === '1';

        if (!empty($modules['presence']) && $presenceEnabledGlobally) {
            $this->bestEffort($notes, 'presence module', function () use ($teamId) {
                $this->presenceTeamService->saveConfig($teamId, ['presence_enabled' => 1]);
            });
        }

        if (!empty($modules['decisions']) && $decisionsEnabledGlobally) {
            $this->bestEffort($notes, 'decisions module', function () use ($teamId) {
                $this->decisionTeamService->saveConfig($teamId, ['decisions_enabled' => 1]);
            });
        }

        if (empty($modules['timeline'])) {
            $this->config->setAppValue(Application::APP_ID, 'timeline_enabled_' . $teamId, '0');
        }
        if (empty($modules['messages'])) {
            $this->config->setAppValue(Application::APP_ID, 'messages_enabled_' . $teamId, '0');
        }

        // IntraVox documentation page. The team_apps row is what makes the
        // Pages widget visible, so it is written even when page creation
        // itself fails — same as the wizard, which sends appStates separately
        // from the page-create call.
        $intravoxInstalled = $this->intravoxService->isInstalled();
        if ($intravoxInstalled) {
            $wantsPages = !empty($modules['pages']);
            $this->bestEffort($notes, 'pages module', function () use ($teamId, $wantsPages) {
                $this->teamService->updateTeamApps($teamId, [
                    ['app_id' => 'intravox', 'enabled' => $wantsPages, 'config' => null],
                ]);
            });
            if ($wantsPages) {
                $this->bestEffort($notes, 'documentation page', function () use ($teamId, $teamName) {
                    $result = $this->intravoxService->createPage($teamId, $teamName, null);
                    if (isset($result['error'])) {
                        throw new \RuntimeException((string)$result['error']);
                    }
                    $this->intravoxService->invalidateSubPagesCache($teamId);
                });
            }
        }

        // Wiki (Collectives). Never on by default in any template profile, so
        // this only fires when the CSV asked for it.
        if (!empty($modules['wiki']) && $this->collectivesService->isInstalled()) {
            $this->bestEffort($notes, 'wiki', function () use ($teamId, $teamName, $adminUid) {
                $result = $this->collectivesService->enableForTeam($teamId, $teamName, $adminUid);
                if (empty($result['ok'])) {
                    throw new \RuntimeException((string)($result['error'] ?? 'enable failed'));
                }
            });
        }
    }

    /**
     * Run a best-effort provisioning step, recording a note instead of aborting.
     *
     * @param list<string> $notes
     */
    private function bestEffort(array &$notes, string $label, callable $step): void {
        try {
            $step();
        } catch (\Throwable $e) {
            $notes[] = ucfirst($label) . ': ' . $e->getMessage();
        }
    }

    /**
     * Whether the acting admin is meant to stay in this team — as its owner, or
     * because the CSV listed them among the members. Decides whether
     * `assignOwner()` deletes their member row or leaves it demoted.
     *
     * Group membership is deliberately not consulted: an admin who is in a
     * group the CSV invites is reachable through that group, so deleting their
     * direct row does not remove their access.
     *
     * @param list<array{id:string, type:string}> $members
     */
    private function adminIsNamed(string $adminUid, string $owner, array $members): bool {
        if ($adminUid === '') {
            return true;    // unknown actor — the safe answer is "leave them".
        }

        // Case-folded, even though both sides are canonical uids by the time
        // they get here. A false negative on this comparison does not mislabel
        // anything — it deletes the admin's own member row — so it is worth one
        // strtolower to be wrong-proof rather than merely correct.
        $admin = mb_strtolower($adminUid);
        if (mb_strtolower($owner) === $admin) {
            return true;
        }
        foreach ($members as $member) {
            if (($member['type'] ?? '') === 'user'
                && mb_strtolower((string)($member['id'] ?? '')) === $admin) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // CSV parsing
    // -------------------------------------------------------------------------

    /**
     * Split raw CSV into a canonical header and its data rows.
     *
     * Tolerates a UTF-8 BOM, sniffs the column delimiter from `,` `;` and tab,
     * matches header names case-insensitively and trimmed, and maps the
     * `team_type` alias onto `template`.
     *
     * Parsed through a stream with `fgetcsv()` rather than `str_getcsv()` per
     * line, so a quoted field containing a newline or the delimiter itself
     * survives — a description with a comma in it is entirely ordinary.
     *
     * @return array{header: list<string>, rows: list<list<string>>}
     */
    private function parseCsv(string $raw): array {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        // Normalise line endings so a file written on Windows or classic Mac
        // does not arrive as one enormous single row.
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        $delimiter = $this->sniffDelimiter($raw);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $raw);
        rewind($handle);

        $header = [];
        $rows   = [];
        // Escape '' for the same reason sampleCsv() writes with it: RFC 4180
        // behaviour, and no reliance on a default that has moved.
        while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            // fgetcsv returns [null] for a blank line.
            if ($cells === [null] || $cells === []) {
                continue;
            }
            if ($header === []) {
                foreach ($cells as $cell) {
                    $key = mb_strtolower(trim((string)$cell));
                    $header[] = self::HEADER_ALIASES[$key] ?? $key;
                }
                continue;
            }
            // Skip a row that is entirely empty — trailing newlines and the
            // blank separator rows spreadsheets like to leave behind.
            $nonEmpty = array_filter($cells, static fn ($c) => trim((string)$c) !== '');
            if ($nonEmpty === []) {
                continue;
            }
            $rows[] = array_map(static fn ($c) => (string)($c ?? ''), $cells);
        }
        fclose($handle);

        return ['header' => $header, 'rows' => $rows];
    }

    /**
     * Pick the delimiter by counting candidates in the header line.
     *
     * The header is the right line to sniff on: it is the one line guaranteed
     * to contain several fields and no free text, so a description containing
     * a semicolon cannot outvote the real delimiter.
     */
    private function sniffDelimiter(string $raw): string {
        $break     = strpos($raw, "\n");
        $firstLine = $break === false ? $raw : substr($raw, 0, $break);

        $best      = ',';
        $bestCount = 0;
        foreach (self::DELIMITERS as $candidate) {
            $count = substr_count($firstLine, $candidate);
            if ($count > $bestCount) {
                $best      = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * Split a multi-value cell on `;` or `|`.
     *
     * Both are accepted so one of the two always works whatever the column
     * delimiter turned out to be: a `;`-delimited file (Dutch and German Excel)
     * cannot also use `;` inside a cell, so those files use `|`.
     *
     * @return list<string>
     */
    private function splitMulti(string $value): array {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $parts = preg_split('/[;|]/', $value) ?: [];
        $out   = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Lookups
    // -------------------------------------------------------------------------

    /**
     * Every real team name on this instance, lower-cased, as a lookup set.
     *
     * The system-prefix filter is copied from
     * {@see MaintenanceService::getAllTeams()} so personal circles (`user:`),
     * group-backed circles (`group:`), mail and contact circles and `occ`-
     * created ones are not mistaken for teams — otherwise every uid on the
     * instance would read as a taken team name.
     *
     * Name resolution follows the same precedence: `display_name` →
     * `sanitized_name` → `name`.
     *
     * @return array<string,int> lower-cased name => 1
     */
    private function existingTeamNamesLower(): array {
        return array_fill_keys(array_keys($this->existingTeams()), 1);
    }

    /**
     * Every real team on this instance: lower-cased display name => the list of
     * `unique_id`s carrying it.
     *
     * One pass, memoised, feeding two callers that must agree — the duplicate-
     * name check and `team:` member resolution. They were two queries with two
     * copies of the display-name derivation until v4.6.11, which is how a name
     * could count as "already exists" for the collision check and still be
     * unresolvable as a sub-team.
     *
     * A list rather than a single id because Circles does not enforce unique
     * display names: two teams really can be called "Marketing", and picking
     * one of them silently is worse than refusing.
     *
     * The display is carried alongside the id so a resolved `team:` token can
     * report the team's own spelling back to the admin. Reporting the id would
     * be accurate and useless — a 31-character hash tells nobody which team
     * they just linked.
     *
     * @return array<string, list<array{id:string, display:string}>>
     */
    private function existingTeams(): array {
        if ($this->existingNamesCache !== null) {
            return $this->existingNamesCache;
        }

        $teams = [];
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('unique_id', 'name', 'display_name', 'sanitized_name')
                ->from('circles_circle');
            $result = $qb->executeQuery();

            // Personal, group-backed, mail and contact circles are Circles'
            // own plumbing, not teams. Filtering them here is what stops
            // `team:` resolving to somebody's personal circle.
            $systemPrefixes = ['user:', 'group:', 'mail:', 'app:occ:', 'contact:'];
            while ($row = $result->fetch()) {
                $name = (string)($row['name'] ?? '');
                foreach ($systemPrefixes as $prefix) {
                    if (str_starts_with($name, $prefix)) {
                        continue 2;
                    }
                }

                if (!empty($row['display_name'])) {
                    $display = (string)$row['display_name'];
                } elseif (!empty($row['sanitized_name'])) {
                    $display = (string)$row['sanitized_name'];
                } elseif (str_starts_with($name, 'app:circles:')) {
                    $display = substr($name, strlen('app:circles:'));
                } else {
                    $display = $name;
                }

                $display  = trim($display);
                $uniqueId = (string)($row['unique_id'] ?? '');
                if ($display !== '' && $uniqueId !== '') {
                    $teams[mb_strtolower($display)][] = ['id' => $uniqueId, 'display' => $display];
                }
            }
            $result->closeCursor();
        } catch (\Throwable $e) {
            // A failed lookup must not silently turn every collision into a
            // creation: log it and let the rows through, where Circles' own
            // uniqueness handling is the backstop.
            $this->logger->warning('[TeamHub][TeamImportService] Could not read existing teams', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        return $this->existingNamesCache = $teams;
    }

    /**
     * Resolve a `team:` token to a Circles `unique_id` (v4.6.11).
     *
     * Accepts either — an id matches literally, a name matches case-
     * insensitively against the same derived display name the duplicate check
     * uses. Ids are tried first because they are unambiguous and because a
     * team whose display name happens to look like an id should still resolve
     * to the id.
     *
     * Mirrors the three-state answer of `resolveUid()` / `resolveGid()` so the
     * caller can tell "no such team" from "which one did you mean", and adds
     * the team's own spelling for the row's matched-names note.
     *
     * @return array{id:string, display:string}|null  null when nothing matched;
     *         an entry with an empty `id` when more than one team matched
     */
    private function resolveTeamId(string $typed): ?array {
        $typed = trim($typed);
        if ($typed === '') {
            return null;
        }

        $teams = $this->existingTeams();

        // Exact id first — unambiguous, and a team whose display name happens
        // to look like an id must still resolve to the id.
        foreach ($teams as $entries) {
            foreach ($entries as $entry) {
                if ($entry['id'] === $typed) {
                    return $entry;
                }
            }
        }

        $matches = $teams[mb_strtolower($typed)] ?? [];

        // The same circle can appear twice only if the query ever returned it
        // twice; dedup on id so that cannot read as ambiguity.
        $byId = [];
        foreach ($matches as $entry) {
            $byId[$entry['id']] = $entry;
        }
        $byId = array_values($byId);

        if ($byId === []) {
            return null;
        }
        if (count($byId) > 1) {
            return ['id' => '', 'display' => ''];
        }

        return $byId[0];
    }
}
