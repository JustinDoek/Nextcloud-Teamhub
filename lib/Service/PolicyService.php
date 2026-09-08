<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\CirclesConfig;
use OCA\TeamHub\Constants\PolicyField;
use OCA\TeamHub\Constants\TeamApps;
use OCA\TeamHub\Constants\TeamTemplates;
use OCA\TeamHub\Db\PolicyObservationMapper;
use OCA\TeamHub\Db\PolicyProfileMapper;
use OCA\TeamHub\Db\PolicyValueMapper;
use OCA\TeamHub\Db\TeamAppPresenceMapper;
use OCA\TeamHub\Db\TeamPolicyMapper;
use OCA\TeamHub\Db\TeamTemplateMapper;
use OCA\TeamHub\Db\TeamTypeMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Templates and policy profiles (v4.8.2, Track F2a).
 *
 * Full design: `TRACK-F2-DESIGN.md`. Reasoning: `DESIGN.md` §2.104–§2.108.
 *
 * **This service still writes no team setting.** It reads and writes what a
 * profile *is*, records which profile a team carries (`assignAtCreation`, v4.8.4)
 * and — since v4.8.15 — compares a classified team against its profile
 * (`complianceSummary`). What it does not do is write `circles_circle` or any
 * other team-owned value: `TeamService::updateTeamConfig()` owns that write, and
 * a second place doing it is how the `CFG_PERSONAL` class of bug happens.
 *
 * The Track F constraint holds throughout: define profiles all day and nothing
 * changes until one is assigned, and the comparison reads classified teams only.
 *
 * Every method is NC-admin gated via `requireNcAdmin()`. That is one of the six
 * membership-gate idioms already in the codebase (HANDOFF §00sec) — the one
 * `TeamImportService` uses — chosen deliberately rather than adding a seventh.
 */
class PolicyService {

    /**
     * Reserved, and not a row in `teamhub_policy_profile`.
     *
     * A team with no assignment resolves to an empty policy: it governs
     * nothing, locks nothing and is compared against nothing. Kept synthetic
     * rather than seeded because a real `unclassified` row could have values
     * added to it, and those values would silently begin governing every
     * unassigned team on the instance — inertness undone by one admin edit.
     */
    public const UNCLASSIFIED = 'unclassified';

    /** Profile and template keys: lowercase, digits, underscore. */
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{1,31}$/';

    private const LABEL_MAX       = 64;
    private const DESCRIPTION_MAX = 500;
    /** `sort_index` is a SMALLINT; stay inside the signed range on every engine. */
    private const SORT_MAX = 32000;
    /** Ten years. A default expiration period longer than this is a typo. */
    private const EXPIRY_DAYS_MAX = 3650;

    /**
     * Drift findings carried in the compliance payload. The *count* is always
     * exact; only the per-team detail is capped, and `findingsTruncated` says
     * when it was. Matches `MaintenanceService::findGhostMembers()`'s 200.
     */
    private const FINDINGS_MAX = 200;

    public function __construct(
        private PolicyProfileMapper $profileMapper,
        private PolicyValueMapper   $valueMapper,
        private TeamTemplateMapper  $templateMapper,
        private TeamPolicyMapper    $teamPolicyMapper,
        // v4.8.15 — the observed side of the compliance comparison. Mappers
        // only: this service still writes no team setting of its own, and the
        // sweep reads without writing anything at all.
        private PolicyObservationMapper $observationMapper,
        // v4.8.27 — the one reader for "which apps does this team have".
        // Replaces `PolicyObservationMapper::enabledAppsByTeam()`, which read a
        // table nothing writes any more; see that class for what it cost.
        private TeamAppPresenceMapper $appPresenceMapper,
        // v4.8.15 — the per-team template, for the Maintenance grid's chips.
        // `teamhub_team_type.type` is where a team's template key has lived
        // since v4.0.2; `teamhub_template` holds only the definitions.
        private TeamTypeMapper      $teamTypeMapper,
        // v4.8.24 — `confidential_tag`. Both are leaves in the DI graph (neither
        // injects a TeamHub service), so this stays clear of the shape that took
        // the app down in 4.8.7; `npm run check:di` is what proves it.
        private ConfidentialFilesService $confidentialFiles,
        // Only ever asked `isGroupFoldersAvailable()` here. The team-folder rows
        // themselves come from `PolicyObservationMapper` as one set query, but
        // those are GroupFolders' tables and do not exist without the app.
        private GroupFolderService  $groupFolderService,
        private AuditService        $auditService,
        private IUserSession        $userSession,
        private IGroupManager       $groupManager,
        private IConfig             $config,
        private LoggerInterface     $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // Gate
    // -------------------------------------------------------------------------

    /**
     * Nextcloud administrator, returning their uid.
     *
     * Same shape as `TeamImportService::requireNcAdmin()`. Profiles are an
     * instance-wide control: a team admin must never be able to define or
     * assign one, which is the bypass DESIGN §2.103 records as the reason tags
     * were removed — `TeamTagService` gated on a *team* check, so a team admin
     * could reclassify their own team by deleting a chip.
     *
     * `AccessDeniedException`, not `ValidationException` — the trait maps the
     * first to 403 and the second to 400, and "you are not an administrator" is
     * a forbidden, not a bad request. HANDOFF has an open issue about
     * `TeamController` answering an `AccessDeniedException` with a 500; this is
     * the same class of mistake and is cheaper to not make than to fix later.
     *
     * @throws AccessDeniedException when not authenticated or not an admin.
     */
    private function requireNcAdmin(): string {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new AccessDeniedException('Not authenticated.');
        }
        if (!$this->groupManager->isAdmin($user->getUID())) {
            throw new AccessDeniedException('Nextcloud administrator privilege required.');
        }

        return $user->getUID();
    }

    // -------------------------------------------------------------------------
    // Field catalogue — what a profile *may* govern
    // -------------------------------------------------------------------------

    /**
     * The field registry, as the admin UI needs it.
     *
     * No labels: user-facing names live in `src/constants/policy.js` and go
     * through `t('teamhub', …)`, because `npm run check:l10n` scans `src/`
     * only. Shipping English labels from PHP is the pipeline gap that left the
     * `lib/MyWork/` provider strings untranslated for months.
     *
     * @return array{
     *     fields: list<array<string,mixed>>,
     *     confidentialFiles: array{appAvailable: bool, labelsConfigured: bool, tags: list<array<string,mixed>>}
     * }
     */
    public function fieldCatalogue(): array {
        $fields = [];
        foreach (PolicyField::FIELDS as $key => $def) {
            $fields[] = [
                'fieldKey'  => $key,
                'tag'       => $def['tag'],
                'type'      => $def['type'],
                'source'    => $def['source'],
                // The panel greys a dependent field out until its dependency is
                // governed and true, rather than letting an admin set a value
                // the platform will ignore.
                'dependsOn' => $def['dependsOn'] ?? null,
                // v4.8.24 — an app that must be installed for the field to mean
                // anything. Unlike `dependsOn` no edit to the profile can
                // satisfy it, so the panel greys the field out with a different
                // reason and names the app.
                'requiresApp' => $def['requiresApp'] ?? null,
            ];
        }

        return [
            'fields' => $fields,
            // Sent with the catalogue rather than from an endpoint of its own:
            // the panel needs it at exactly the moment it renders the field
            // list, and a second request would let the two disagree while one
            // is in flight.
            'confidentialFiles' => $this->confidentialFiles->availability(),
        ];
    }

    // -------------------------------------------------------------------------
    // Profiles
    // -------------------------------------------------------------------------

    /**
     * Every profile with a summary of what it governs.
     *
     * `teams` is the number of teams carrying the profile. **It is 0 for every
     * profile in F2a** and that is correct rather than unfinished: nothing can
     * be assigned yet.
     *
     * @return array{profiles: list<array<string,mixed>>, unclassifiedKey: string}
     */
    public function listProfiles(): array {
        $this->requireNcAdmin();

        $profiles = $this->profileMapper->findAll();
        $keys     = array_column($profiles, 'profileKey');
        $values   = $this->valueMapper->findByProfiles($keys);
        $counts   = $this->profileMapper->countAssignmentsByProfile();

        foreach ($profiles as &$profile) {
            $own = $values[$profile['profileKey']] ?? [];
            $profile['fieldCount'] = count($own);
            $profile['teams']      = $counts[$profile['profileKey']] ?? 0;
        }
        unset($profile);

        return [
            'profiles'        => $profiles,
            'unclassifiedKey' => self::UNCLASSIFIED,
        ];
    }

    /**
     * One profile with its full value set.
     *
     * @return array<string,mixed>
     * @throws NotFoundException
     */
    public function getProfile(string $profileKey): array {
        $this->requireNcAdmin();

        $profile = $this->profileMapper->find($profileKey);
        if ($profile === null) {
            throw new NotFoundException('No such profile.');
        }

        $profile['values'] = $this->readableValues($this->valueMapper->findByProfile($profileKey));
        $profile['teams']  = $this->profileMapper->countAssignmentsByProfile()[$profileKey] ?? 0;

        return $profile;
    }

    /**
     * @param array<string,mixed> $values field key => value
     * @return array<string,mixed> the created profile
     */
    public function createProfile(
        string  $profileKey,
        string  $label,
        ?string $description,
        int     $sortIndex,
        array   $values = [],
    ): array {
        $actor = $this->requireNcAdmin();
        $now   = time();

        $profileKey = strtolower(trim($profileKey));
        $this->assertValidKey($profileKey);
        if ($profileKey === self::UNCLASSIFIED) {
            throw new ValidationException(
                'The key "' . self::UNCLASSIFIED . '" is reserved for teams with no profile.',
            );
        }
        if ($this->profileMapper->exists($profileKey)) {
            throw new ValidationException('A profile with that key already exists.');
        }

        $label       = $this->assertValidLabel($label);
        $description = $this->normaliseDescription($description);
        $sortIndex   = $this->assertValidSortIndex($sortIndex);
        $normalised  = $this->normaliseValues($values);

        $this->profileMapper->insert($profileKey, $label, $description, $sortIndex, $actor, $now);
        if ($normalised !== []) {
            $this->valueMapper->replaceForProfile($profileKey, $normalised);
        }

        $this->auditInstance('policy.profile_created', $actor, $profileKey, [
            'label'  => $label,
            'fields' => array_keys($normalised),
        ]);

        return $this->getProfile($profileKey);
    }

    /**
     * Update a profile's presentation and, when `$values` is provided, its whole
     * value set.
     *
     * `$values === null` leaves the values untouched, so the admin UI can rename
     * a profile without resubmitting what it governs.
     *
     * @param array<string,mixed>|null $values
     * @return array<string,mixed>
     */
    public function updateProfile(
        string  $profileKey,
        string  $label,
        ?string $description,
        int     $sortIndex,
        ?array  $values = null,
    ): array {
        $actor = $this->requireNcAdmin();
        $now   = time();

        $existing = $this->profileMapper->find($profileKey);
        if ($existing === null) {
            throw new NotFoundException('No such profile.');
        }

        $label       = $this->assertValidLabel($label);
        $description = $this->normaliseDescription($description);
        $sortIndex   = $this->assertValidSortIndex($sortIndex);

        $this->profileMapper->update($profileKey, $label, $description, $sortIndex, $actor, $now);

        $changedFields = null;
        $propagation   = null;
        if ($values !== null) {
            $before     = $this->valueMapper->findByProfile($profileKey);
            $normalised = $this->normaliseValues($values);

            // v4.8.27 — the plan is computed **here**, between reading the old
            // values and writing the new ones, because that is the only moment
            // both exist. Compliance means "does this team still match what the
            // profile said before", and after the write there is nothing left to
            // ask it against. Nothing is applied: the plan is returned and an
            // administrator decides.
            $changedFields = $this->diffFields($before, $normalised);
            if ($changedFields !== []) {
                $propagation = $this->propagationPlanForProfile($profileKey, $before);
            }

            $this->valueMapper->replaceForProfile($profileKey, $normalised);
        }

        // Naming the changed fields is the whole reason values are rows rather
        // than a JSON blob — this track exists to produce audit evidence, and
        // "the profile changed" is not evidence.
        $this->auditInstance('policy.profile_updated', $actor, $profileKey, [
            'label'         => $label,
            'changedFields' => $changedFields,
        ]);

        $profile = $this->getProfile($profileKey);
        // Absent when nothing changed or no team carries the profile, so the
        // panel can test one key rather than a count it has to interpret.
        if ($propagation !== null && $propagation['total'] > 0) {
            $profile['propagation'] = $propagation + ['changedFields' => $changedFields];
        }

        return $profile;
    }

    public function deleteProfile(string $profileKey): void {
        $actor = $this->requireNcAdmin();

        $existing = $this->profileMapper->find($profileKey);
        if ($existing === null) {
            throw new NotFoundException('No such profile.');
        }

        // Deleting a profile teams are assigned to would leave those teams
        // pointing at nothing, which is not the same as unclassified — it is a
        // team whose policy identity has been silently erased. Always false in
        // F2a; the check exists because F2b makes it reachable.
        $assigned = $this->profileMapper->countAssignmentsByProfile()[$profileKey] ?? 0;
        if ($assigned > 0) {
            throw new ValidationException(
                'This profile is assigned to ' . $assigned . ' team(s). Reassign them before deleting it.',
            );
        }

        // v4.8.5 — a template pointing at a deleted profile would leave the
        // wizard preselecting nothing for that kind of team. Refused rather
        // than silently nulled: which policy a template starts from is an
        // administrator's decision, and deleting one elsewhere is not the
        // place to change it.
        $usedBy = [];
        foreach ($this->templateMapper->findAll() as $template) {
            if (($template['defaultProfileKey'] ?? null) === $profileKey) {
                $usedBy[] = $template['label'];
            }
        }
        if ($usedBy !== []) {
            throw new ValidationException(
                'This profile is the default for: ' . implode(', ', $usedBy)
                . '. Change those templates before deleting it.',
            );
        }

        // The create-team wizard requires a policy, so an instance with none
        // could not create a team at all. Refusing the last deletion is the
        // cheapest place to keep that promise — the alternative is a wizard
        // that fails at submit with nothing the user can do about it.
        if (count($this->profileMapper->findAll()) <= 1) {
            throw new ValidationException(
                'This is the only profile. Teams cannot be created without one, so it cannot be deleted.',
            );
        }

        $this->valueMapper->deleteByProfile($profileKey);
        $this->profileMapper->delete($profileKey);

        $this->auditInstance('policy.profile_deleted', $actor, $profileKey, [
            'label' => $existing['label'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Templates
    // -------------------------------------------------------------------------

    /**
     * The template set, for the admin screen.
     *
     * v4.8.3 — `liveAtCreation` is now **true**: the create-team wizard and the
     * CSV importer both read this table, so an edit here reaches team creation.
     * The flag stays in the payload rather than being deleted, because the
     * panel's warning banner is keyed off it and a future stage that moves
     * another creation path will want to say the same thing again.
     *
     * @return array{templates: list<array<string,mixed>>, liveAtCreation: bool,
     *               apps: list<string>, modules: list<string>, managedBits: int}
     */
    public function listTemplates(): array {
        $this->requireNcAdmin();

        return [
            'templates'      => $this->templateMapper->findAll(),
            'liveAtCreation' => true,
            'apps'           => TeamTemplates::APPS,
            'modules'        => TeamTemplates::MODULES,
            'managedBits'    => CirclesConfig::MANAGED_BITS,
        ];
    }

    /**
     * The template set for the create-team wizard.
     *
     * **Deliberately not admin-gated** — every user who may create a team needs
     * it, and it carries no more than the wizard already renders: which apps
     * and modules a template starts with, which privacy boxes are pre-ticked,
     * and whether an expiration date is offered. No profile, no assignment, no
     * instance configuration.
     *
     * This is what kills the `TeamTemplates` ↔ `CreateTeamView.templateProfile()`
     * mirror. The original objection to removing it was that fetching would put
     * a round trip in the critical path of every template switch; fetching the
     * **whole set once on mount** does not — switching stays a local lookup.
     *
     * @return array{templates: list<array<string,mixed>>}
     */
    public function templatesForCreation(): array {
        return ['templates' => $this->templateMapper->findAll()];
    }

    /**
     * @param list<string> $apps
     * @param list<string> $modules
     * @return array<string,mixed>
     */
    public function updateTemplate(
        string  $templateKey,
        string  $label,
        ?string $description,
        array   $apps,
        array   $modules,
        bool    $expiryEnabled,
        int     $expiryDefaultDays,
        int     $preselectConfig,
        int     $sortIndex,
        ?string $defaultProfileKey = null,
    ): array {
        $actor = $this->requireNcAdmin();
        $now   = time();

        $existing = $this->templateMapper->find($templateKey);
        if ($existing === null) {
            throw new NotFoundException('No such template.');
        }

        // v4.8.5 — the policy new teams of this kind start from. Empty means
        // none, which is what every template ships with.
        $defaultProfileKey = $defaultProfileKey === null ? '' : trim($defaultProfileKey);
        if ($defaultProfileKey !== '' && !$this->profileMapper->exists($defaultProfileKey)) {
            throw new ValidationException('No such profile.');
        }
        $defaultProfileKey = $defaultProfileKey === '' ? null : $defaultProfileKey;

        $label       = $this->assertValidLabel($label);
        $description = $this->normaliseDescription($description);
        $sortIndex   = $this->assertValidSortIndex($sortIndex);

        $apps    = $this->filterToVocabulary($apps, TeamTemplates::APPS, 'app');
        $modules = $this->filterToVocabulary($modules, TeamTemplates::MODULES, 'module');

        // Mask to MANAGED_BITS. A system bit reaching circles_circle.config on
        // a user team corrupts it — the CirclesConfig docblock lists the ways —
        // and this value is admin-supplied, so it is masked here rather than
        // trusted. CFG_PERSONAL in particular makes the circle permanently
        // unwritable through Circles' own API (DESIGN §2.4 / v4.5.35).
        $preselectConfig &= CirclesConfig::MANAGED_BITS;

        // Whole days, and bounded. 0 means "no default" and is the shipped
        // value. The cap is ten years: a default longer than that is a typo,
        // and the picker it feeds has to land on a real date.
        if ($expiryDefaultDays < 0 || $expiryDefaultDays > self::EXPIRY_DAYS_MAX) {
            throw new ValidationException(
                'The default expiration period must be between 0 and ' . self::EXPIRY_DAYS_MAX . ' days.',
            );
        }
        // A default period on a template that cannot expire is a value nothing
        // reads. Cleared rather than refused — unticking the box is a normal
        // edit, and refusing the save would make the admin clear a field they
        // can no longer see.
        if (!$expiryEnabled) {
            $expiryDefaultDays = 0;
        }

        // v4.8.27 — the plan, computed before the write for the same reason the
        // profile's is: compliance is "does this team still have what the
        // template gave it", and once the new app list is stored the old one is
        // gone. Only apps propagate — see `propagationPlanForTemplate()` for why
        // expiry and the default profile cannot.
        $previousApps = array_merge(
            $existing['apps'] ?? [],
            $existing['modules'] ?? [],
        );
        // v4.8.32 — feature modules count as a change too. They were left out
        // when only resources propagated, so a template edit that switched
        // Decisions on for every team of that kind produced no rollout offer at
        // all — the one case an administrator would most expect it.
        $appsChanged = TeamApps::observableList($previousApps)
                !== TeamApps::observableList(array_merge($apps, $modules))
            || TeamApps::featureModules($existing['modules'] ?? [])
                !== TeamApps::featureModules($modules);

        $propagation = $appsChanged
            ? $this->propagationPlanForTemplate($templateKey, $previousApps)
            : null;

        $this->templateMapper->update(
            $templateKey, $label, $description, $apps, $modules,
            $expiryEnabled, $expiryDefaultDays, $preselectConfig, $sortIndex,
            $defaultProfileKey, $actor, $now,
        );

        $this->auditInstance('policy.template_updated', $actor, $templateKey, [
            'label'             => $label,
            'apps'              => $apps,
            'modules'           => $modules,
            'expiryEnabled'     => $expiryEnabled,
            'expiryDefaultDays' => $expiryDefaultDays,
            'preselectConfig'   => $preselectConfig,
            'defaultProfileKey' => $defaultProfileKey,
        ]);

        $updated = $this->templateMapper->find($templateKey) ?? [];

        if ($propagation !== null && $propagation['total'] > 0) {
            // v4.8.27b — what a rollout would destroy, so the confirm step can
            // say it before the click rather than the report saying it after.
            // Removing an app deletes its resource: a Talk room takes its
            // messages, a Deck board its cards. The team folder is never in
            // this list — see PolicyPropagationService::DESTRUCTIVE_ON_REMOVE.
            $propagation['removes'] = array_values(array_diff(
                TeamApps::observableList($previousApps),
                TeamApps::observableList(array_merge($apps, $modules)),
            ));
            $propagation['adds'] = array_values(array_diff(
                TeamApps::observableList(array_merge($apps, $modules)),
                TeamApps::observableList($previousApps),
            ));
            $updated['propagation'] = $propagation;
        }

        return $updated;
    }

    // -------------------------------------------------------------------------
    // Creation: which profile a new team gets, and what it governs
    // -------------------------------------------------------------------------

    /**
     * Does this profile key exist? (v4.8.9)
     *
     * Ungated on purpose — it answers yes/no about a key the caller already
     * holds and discloses nothing. Used by the bulk/CSV importer to reject an
     * unknown policy at preview time rather than at provisioning time.
     */
    public function profileExists(string $profileKey): bool {
        return $profileKey !== '' && $this->profileMapper->exists($profileKey);
    }

    /**
     * The policy a template starts its teams on, or null when it has none.
     *
     * **v4.8.5 — per template, not per instance.** A Project team and a
     * Department team are different kinds of thing and start from different
     * postures; one instance-wide value could not say that. Same reasoning that
     * moved expiry onto the template in 4.8.3.
     */
    public function defaultProfileForTemplate(?string $templateKey): ?string {
        if ($templateKey === null || $templateKey === '') {
            return null;
        }
        $row = $this->templateMapper->find($templateKey);
        $key = $row['defaultProfileKey'] ?? null;

        // A default pointing at a deleted profile is the same as no default.
        // Resolved on read — `deleteProfile()` refuses while a template points
        // at it, so this only fires if something got past that.
        return ($key !== null && $this->profileMapper->exists($key)) ? $key : null;
    }

    /**
     * What the create-team wizard needs to render itself.
     *
     * **Every profile, for every caller who may create a team.** Decided by
     * Justin 2026-09-01: the policy is a required field in the wizard and the
     * person creating the team picks it.
     *
     * That reverses what DESIGN §2.107 recorded — see §2.110 for the reasoning
     * and for what it costs. The boundary that remains is **reclassification**:
     * choosing at creation is the creator's, changing it afterwards is a
     * Nextcloud administrator's.
     *
     * The values ride along because the wizard shows what each policy will do
     * before it is chosen.
     *
     * @return array{profiles: list<array<string,mixed>>}
     */
    public function creationContext(): array {
        $rows   = $this->profileMapper->findAll();
        $values = $this->valueMapper->findByProfiles(array_column($rows, 'profileKey'));

        $profiles = [];
        foreach ($rows as $row) {
            $profiles[] = [
                'profileKey'  => $row['profileKey'],
                'label'       => $row['label'],
                'description' => $row['description'],
                'isSeeded'    => $row['isSeeded'],
                'values'      => $this->readableValues($values[$row['profileKey']] ?? []),
            ];
        }

        return ['profiles' => $profiles];
    }

    /**
     * Assign a policy to a team being created, and say what it governs.
     *
     * Resolution order: the key the creator picked, then the template's
     * default, then nothing. The wizard makes the field required so the first
     * arm is the normal case; the other two exist so a caller that is not the
     * wizard — a script, an older client — cannot end up with a team pointing
     * at a policy that does not exist.
     *
     * **Any creator may pick.** The admin check that used to be here is gone
     * (Justin, 2026-09-01). Reclassification afterwards is still NC-admin only,
     * which is where the boundary now sits.
     *
     * Returns the governed values so the caller can apply them — this service
     * deliberately does not write `circles_circle` itself. `TeamService` owns
     * that write (`updateTeamConfig`), and a second place doing it is how the
     * `CFG_PERSONAL` class of bug happens.
     *
     * @return array<string,mixed> the governed values, empty when unclassified
     */
    public function assignAtCreation(string $teamId, ?string $requestedKey, ?string $templateKey = null): array {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return [];
        }
        $actor = $user->getUID();

        $requestedKey = $requestedKey === null ? '' : trim($requestedKey);

        $key = ($requestedKey !== '' && $this->profileMapper->exists($requestedKey))
            ? $requestedKey
            : $this->defaultProfileForTemplate($templateKey);

        if ($key === null) {
            // Unclassified. No row, no audit event, nothing applied — the team
            // behaves exactly as it did before Track F. Existing teams are in
            // this state until an administrator classifies them.
            return [];
        }

        $this->teamPolicyMapper->assign($teamId, $key, $actor, time(), 'creation');

        $values = $this->readableValues($this->valueMapper->findByProfile($key));

        $this->auditService->log($teamId, 'team.policy_applied_at_creation', $actor, 'policy', $key, [
            'profileKey' => $key,
            'chosen'     => $requestedKey !== '',
            'fields'     => array_keys($values),
        ]);

        return $values;
    }

    // -------------------------------------------------------------------------
    // Enforcement reads — used by the services that own each write
    // -------------------------------------------------------------------------

    /**
     * The governed values in force for a team, cast and ready to compare.
     *
     * Empty for an unclassified team, which is every team until somebody
     * assigns one. Callers treat empty as "no opinion" and change nothing.
     *
     * @return array<string,mixed>
     */
    public function governedForTeam(string $teamId): array {
        $assignment = $this->teamPolicyMapper->findByTeam($teamId);
        if ($assignment === null) {
            return [];
        }

        return $this->readableValuesFor($assignment['profileKey']);
    }

    /**
     * How one field is governed for one team, and by which profile (v4.8.17).
     *
     * `governedForTeam()` answers *what* the value is. A §4.4 refusal has to
     * name the profile as well, because the point of that section is that a team
     * admin sees what their classification is doing rather than meeting an
     * unexplained 403 — and the read-only control on their own screen needs the
     * same name for the same reason.
     *
     * **The label is returned raw, with `isSeeded` beside it, not translated.**
     * A seeded profile's display name is resolved by `profileDisplayName()` in
     * `src/constants/policy.js`; a PHP label would sit outside `check:l10n`'s
     * reach, which is the pipeline gap that left every `lib/MyWork/` string
     * English for months.
     *
     * **Ungated**, and every caller has already established who is asking —
     * HANDOFF's 4.8.13 rule. Nothing here is sensitive on its own: §4.1 puts
     * reading a team's own profile at team-member level.
     *
     * @return array{profileKey: string, label: string, isSeeded: bool, value: mixed}|null
     *         null when the team is unclassified OR when its profile leaves this
     *         field ungoverned. Both mean "no opinion" and every caller treats
     *         them identically, which is why they are not distinguished.
     */
    public function governanceFor(string $teamId, string $fieldKey): ?array {
        $summary = $this->governanceSummaryFor($teamId);
        // array_key_exists, not isset() — a governed field whose value is false
        // is the whole point of the third state, and isset() would read it as
        // ungoverned and quietly stop enforcing exactly the locks that matter.
        if ($summary === null || !array_key_exists($fieldKey, $summary['values'])) {
            return null;
        }

        return [
            'profileKey' => $summary['profileKey'],
            'label'      => $summary['label'],
            'isSeeded'   => $summary['isSeeded'],
            'value'      => $summary['values'][$fieldKey],
        ];
    }

    // -------------------------------------------------------------------------
    // Propagation plans (v4.8.27)
    // -------------------------------------------------------------------------

    /**
     * Which teams carrying `$profileKey` should receive a change to it.
     *
     * **Compliance is measured against `$previousStored`, never against what is
     * now in the table.** By the time anything can ask, the new values are
     * already saved, and comparing a team to the values it has not received yet
     * would mark every team drifted the moment the profile changed. The caller
     * captures the old rows before writing — `updateProfile()` already did, for
     * the audit diff.
     *
     * `eligible` is the set the admin is offered by default. `drifted` carries
     * its findings so the report can say *why* a team was left out, which is the
     * difference between a report and a number.
     *
     * **Nothing here writes.** The plan is computed and handed back; applying it
     * is `PolicyPropagationService`, and it happens only if an administrator
     * says so.
     *
     * @param array<string,string> $previousStored raw stored values, pre-change
     * @return array{
     *     total: int,
     *     eligible: list<array{teamId: string, teamName: string}>,
     *     drifted: list<array{teamId: string, teamName: string, fields: list<array<string,mixed>>}>
     * }
     */
    public function propagationPlanForProfile(string $profileKey, array $previousStored): array {
        $assignments = array_filter(
            $this->teamPolicyMapper->findAllAssignments(),
            static fn (string $key): bool => $key === $profileKey,
        );

        if ($assignments === []) {
            return ['total' => 0, 'eligible' => [], 'drifted' => []];
        }

        $teamIds = array_keys($assignments);
        $names   = $this->observationMapper->circlesByTeam($teamIds);

        $drift = $this->compareTeams($assignments, [
            $profileKey => $this->readableValues($previousStored),
        ]);

        $eligible = [];
        $drifted  = [];
        foreach ($teamIds as $teamId) {
            // A team whose circle is gone is an assignment row that outlived its
            // team. Counted in neither list and absent from the report: there is
            // nothing to apply to, and calling it drifted would blame a team
            // that does not exist for a mismatch nobody can fix.
            if (!isset($names[$teamId])) {
                continue;
            }
            $row = ['teamId' => $teamId, 'teamName' => $names[$teamId]['name']];
            if (isset($drift[$teamId])) {
                $row['fields'] = $drift[$teamId]['fields'];
                $drifted[] = $row;
            } else {
                $eligible[] = $row;
            }
        }

        return [
            'total'    => count($eligible) + count($drifted),
            'eligible' => $eligible,
            'drifted'  => $drifted,
        ];
    }

    /**
     * Which teams made from `$templateKey` should receive a change to it.
     *
     * **This is the first time a template has had a compliance question at all**,
     * and DESIGN §2.112 is revised rather than contradicted: that entry says a
     * template "leaves nothing on the team to check afterwards", which was true
     * while the only reader of a team's apps was a table nothing writes. The
     * resource registry does record what a team was given, so the question is
     * answerable — but only for apps. Expiry defaults and the default profile
     * are consumed once at creation and leave no state, so they are propagated
     * to nobody and no team can drift from them.
     *
     * **Compliant means the team has not *lost* anything the template gave it**,
     * not that its apps match exactly. Extra apps are not drift: on a real
     * estate they arrive through discovery — a Deck board somebody made in Deck
     * — and treating that as working around the template would stop a team
     * receiving changes forever for having done nothing wrong.
     *
     * @param list<string> $previousApps template apps + modules, pre-change
     * @return array{
     *     total: int,
     *     eligible: list<array{teamId: string, teamName: string}>,
     *     drifted: list<array{teamId: string, teamName: string, fields: list<array<string,mixed>>}>
     * }
     */
    public function propagationPlanForTemplate(string $templateKey, array $previousApps): array {
        $teamIds = $this->teamTypeMapper->findTeamsByType($templateKey);
        if ($teamIds === []) {
            return ['total' => 0, 'eligible' => [], 'drifted' => []];
        }

        // Observable only. A template's modules list carries feature switches
        // (`decisions`, `timeline`, …) that leave nothing on a team to compare,
        // and including them would report every team on the instance as
        // drifted. An empty result means the template governs nothing
        // measurable, so every team of that kind comes out eligible — the same
        // answer `compareTeams()` gives a profile that governs no field.
        $expected = TeamApps::observableList($previousApps);
        $names    = $this->observationMapper->circlesByTeam($teamIds);
        $presence = $this->appPresenceMapper->presenceForTeams($teamIds);

        $eligible = [];
        $drifted  = [];
        foreach ($teamIds as $teamId) {
            if (!isset($names[$teamId])) {
                continue;
            }

            $has     = $presence[$teamId] ?? [];
            $missing = array_values(array_diff($expected, $has));
            $row     = ['teamId' => $teamId, 'teamName' => $names[$teamId]['name']];

            if ($missing === []) {
                $eligible[] = $row;
                continue;
            }

            // Shaped like a profile finding so the report renders one table
            // rather than two. `field` is the template's own word for what
            // differs; there is no PolicyField for it and inventing one would
            // put a template setting into the profile registry.
            $row['fields'] = [[
                'field'    => 'template_apps',
                'expected' => $expected,
                'observed' => $has,
                'missing'  => $missing,
            ]];
            $drifted[] = $row;
        }

        return [
            'total'    => count($eligible) + count($drifted),
            'eligible' => $eligible,
            'drifted'  => $drifted,
        ];
    }

    /**
     * Every classification tag any profile can assign (v4.8.28).
     *
     * **The exclusivity set.** A classification is a single answer — a folder is
     * Confidential *or* Internal, never both — so applying one has to take the
     * others off. This is the bounded definition of "the others": a tag is part
     * of the scheme if a profile governs it or a Confidential files label names
     * it. Anything else on the folder is somebody's own tag and is not touched.
     *
     * Not just the outgoing profile's tag, which is what v4.8.24 removed and why
     * a folder could end up carrying two classifications: a tag left by a
     * profile that no longer governs one, or applied by hand, survived every
     * reassignment. Two classifications is worse than the wrong one — an access
     * rule keyed on the weaker tag still matches.
     *
     * @return list<string>
     */
    public function classificationTagIds(): array {
        $keys = array_column($this->profileMapper->findAll(), 'profileKey');

        $tags = [];
        foreach ($this->valueMapper->findByProfiles($keys) as $stored) {
            $tag = $stored[PolicyField::CONFIDENTIAL_TAG] ?? null;
            if (is_string($tag) && $tag !== '' && !in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }

        foreach ($this->confidentialFiles->tagOptions() as $option) {
            if (!in_array($option['id'], $tags, true)) {
                $tags[] = $option['id'];
            }
        }

        return $tags;
    }

    /**
     * The classification tag this team's profile governs, or null (v4.8.24).
     *
     * Exists so `ResourceService` can ask the one question it needs when a team
     * folder appears, without reaching into the value map and repeating the
     * "governed means present, not truthy" rule that `governanceFor()` already
     * gets right.
     *
     * **Ungated**, on the same terms as `governanceFor()`: the caller has
     * established who is asking, and a team's own classification is readable at
     * member level by §4.1.
     */
    public function confidentialTagForTeam(string $teamId): ?string {
        $governance = $this->governanceFor($teamId, PolicyField::CONFIDENTIAL_TAG);
        $value      = $governance['value'] ?? null;

        return (is_string($value) && $value !== '') ? $value : null;
    }

    /**
     * A team's whole classification, for its own screens (v4.8.17).
     *
     * The all-fields twin of `governanceFor()`. Manage Team needs every governed
     * field at once — it renders six Circles config toggles from one payload, and
     * asking per field would be six reads of the same two rows.
     *
     * **`values` holds only the fields the profile actually governs.** A key's
     * presence is the fact; its value is what the field is fixed to. That is the
     * same three-state shape the profile editor stores, and the reason the client
     * tests for the key rather than for a truthy value.
     *
     * Same label rule as `governanceFor()`: raw, with `isSeeded`, resolved
     * client-side. Same gate rule: ungated here, gated by every caller — §4.1
     * puts reading a team's own profile at team-member level.
     *
     * @return array{profileKey: string, label: string, isSeeded: bool, values: array<string,mixed>}|null
     *         null for an unclassified team.
     */
    public function governanceSummaryFor(string $teamId): ?array {
        $assignment = $this->teamPolicyMapper->findByTeam($teamId);
        if ($assignment === null) {
            return null;
        }

        $profile = $this->profileMapper->find($assignment['profileKey']);

        return [
            'profileKey' => $assignment['profileKey'],
            'label'      => (string)($profile['label'] ?? $assignment['profileKey']),
            'isSeeded'   => (bool)($profile['isSeeded'] ?? false),
            'values'     => $this->readableValuesFor($assignment['profileKey']),
        ];
    }

    /**
     * The governed values a profile defines, cast and ready to compare (v4.8.16).
     *
     * The by-key twin of `governedForTeam()`, for the apply engine: a preview has
     * to read what a profile *would* impose on a team that does not carry it yet,
     * which no team-keyed reader can answer.
     *
     * **Ungated on purpose.** `PolicyApplyService` runs its own NC-admin check
     * before it gets here, and HANDOFF's 4.8.13 rule is that a method which has
     * already established who is asking calls the ungated reader — two gates
     * inside one call is not defence in depth, it is a contradiction waiting for
     * a second caller type. Nothing here is sensitive on its own: it is the
     * definition of a profile, which every team creator can already fetch through
     * `creationContext()`.
     *
     * @return array<string,mixed> empty for an unknown key, same as for a profile
     *                             that governs nothing — neither has an opinion
     */
    public function readableValuesFor(string $profileKey): array {
        return $this->readableValues($this->valueMapper->findByProfile($profileKey));
    }

    /**
     * One profile's row, without the gate and without its values (v4.8.16).
     *
     * `getProfile()` is the gated, fully-decorated reader the admin panel uses.
     * This is what a caller that has already gated needs in order to resolve a
     * label and an `isSeeded` flag, and it returns null rather than throwing
     * because "no such profile" is a 404 the caller shapes, not an exception this
     * layer should choose the wording for.
     *
     * @return array<string,mixed>|null
     */
    public function getProfileRow(string $profileKey): ?array {
        return $this->profileMapper->find($profileKey);
    }

    /**
     * One template's row, without the gate (v4.8.27).
     *
     * The template twin of `getProfileRow()`, on the same terms: the caller has
     * already established who is asking, and null is a 404 the caller words.
     * `apps` and `modules` come back as arrays, not the stored `;` string.
     *
     * @return array<string,mixed>|null
     */
    public function getTemplateRow(string $templateKey): ?array {
        return $this->templateMapper->find($templateKey);
    }

    /**
     * The Circles config bits a team's profile governs, as a mask and a value.
     *
     * `TeamService::updateTeamConfig` overlays these on top of whatever the
     * caller asked for, which is what makes a governed bit actually stick
     * rather than merely being hidden in the wizard — DESIGN §2.103 is explicit
     * that hiding our own UI produces no control.
     *
     * @return array{mask: int, value: int} zero mask when nothing is governed
     */
    public function configOverlayForTeam(string $teamId): array {
        $governed = $this->governedForTeam($teamId);
        if ($governed === []) {
            return ['mask' => 0, 'value' => 0];
        }

        $mask  = 0;
        $value = 0;
        foreach (PolicyField::configBits() as $fieldKey => $bit) {
            if (!array_key_exists($fieldKey, $governed)) {
                continue;
            }
            $mask |= $bit;
            if ($governed[$fieldKey] === true) {
                $value |= $bit;
            }
        }

        return ['mask' => $mask, 'value' => $value];
    }

    /**
     * The integrations a team's profile permits, or null when it does not say.
     *
     * Null and the empty list mean different things and both mean "allow
     * everything": null is *ungoverned*, the empty list is the field's own
     * inert setting. Neither filters anything, which is why the caller only
     * has to handle null.
     *
     * @return list<string>|null
     */
    public function allowedIntegrationsForTeam(string $teamId): ?array {
        $governed = $this->governedForTeam($teamId);
        $allowed  = $governed[PolicyField::INTEGRATIONS_ALLOWED] ?? null;

        if (!is_array($allowed) || $allowed === []) {
            return null;
        }

        return $allowed;
    }

    // -------------------------------------------------------------------------
    // Admin summary — the setup checklist's two rows
    // -------------------------------------------------------------------------

    /**
     * Counts for the Team-creation tab's setup checklist (v4.8.15).
     *
     * Deliberately counts rather than lists. The checklist's contract
     * (`AdminSettings.vue`, § setupChecklist) is that every row is derived from
     * live state on each render and says one thing, so a row cannot claim
     * something is configured after an admin changes it back. Sending the full
     * template and profile payloads here would tempt the next reader to render
     * a second, smaller editor on a tab that is not the editor.
     *
     * **"Adjusted" is `updated_by IS NOT NULL`, and that works because of how
     * the seed writes.** `Version000408002` inserts every seeded row with
     * `updated_by => null` and `updated_at => $now`; both mappers' `update()`
     * stamps the acting uid, and `PolicyProfileMapper::insert()` stamps the
     * creator. So a null `updated_by` means "exactly as TeamHub shipped it" and
     * a non-null one means a human has been here — for an edited seeded row and
     * an admin-created one alike. Comparing `updated_at` against the install
     * timestamp instead would have been the fragile version of the same test.
     *
     * @return array{
     *     templates: int, templatesAdjusted: int,
     *     profiles: int, profilesAdjusted: int,
     *     teamsClassified: int
     * }
     */
    public function adminSummary(): array {
        $this->requireNcAdmin();

        $templates = $this->templateMapper->findAll();
        $profiles  = $this->profileMapper->findAll();

        $adjusted = static fn (array $rows): int => count(array_filter(
            $rows,
            static fn (array $row): bool => ($row['updatedBy'] ?? null) !== null,
        ));

        return [
            'templates'         => count($templates),
            'templatesAdjusted' => $adjusted($templates),
            'profiles'          => count($profiles),
            'profilesAdjusted'  => $adjusted($profiles),
            'teamsClassified'   => array_sum($this->profileMapper->countAssignmentsByProfile()),
        ];
    }

    // -------------------------------------------------------------------------
    // Profile compliance — the Compliance tab's row
    // -------------------------------------------------------------------------

    /**
     * How many classified teams still match the profile they carry (v4.8.15).
     *
     * **Only teams with a `teamhub_team_policy` row are read.** An unclassified
     * team has no expectation to depart from, so it is not conformant, not
     * drifted, and not counted — `TRACK-F2-DESIGN.md` §5.2. The census of
     * unclassified teams belongs to F2c's full report, where it is rendered
     * beside the other two and never summed with them.
     *
     * **`classified === 0` is a state of its own, not a clean bill of health.**
     * An instance that has defined profiles but assigned none would otherwise
     * report "0 drifted", which §5.3 names as the most dangerous false statement
     * this feature can make. The caller renders the zero case as its own neutral
     * pill; the payload keeps the counts honest by making it visible.
     *
     * **What a finding can and cannot tell you.** Eight of the nine governed
     * fields are ASSERTED — Contacts and the Teams app write them too and
     * dispatch no event doing it (DESIGN §2.103) — so a finding records *that*
     * the team no longer matches, never *who* changed it. `public_messages` is
     * the one ENFORCED field: a finding there means the value was set before the
     * profile was applied, or through a route since closed, because no open path
     * writes it against a governing profile.
     *
     * Cost is a fixed four reads for the whole instance — three set queries and
     * one app-config load — not one per team. See `PolicyObservationMapper`.
     *
     * v4.8.15 — `sample_team` / `sample_field` were here and are gone. The tab's
     * info menu showed one example finding; the Maintenance grid now shows every
     * team's own state on its own row, which is both more use and the place an
     * admin can act. `findings[0]` is still the same sample for any caller that
     * wants one, so nothing was lost but two derived fields nothing read.
     *
     * @return array{
     *     classified: int, conformant: int, drifted: int,
     *     findings: list<array<string,mixed>>, findingsTruncated: bool,
     *     checked_at: int
     * }
     */
    public function complianceSummary(): array {
        $this->requireNcAdmin();

        $assignments = $this->teamPolicyMapper->findAllAssignments();
        $classified  = count($assignments);

        if ($classified === 0) {
            return [
                'classified'        => 0,
                'conformant'        => 0,
                'drifted'           => 0,
                'findings'          => [],
                'findingsTruncated' => false,
                'checked_at'        => time(),
            ];
        }

        $findings = $this->compareTeams($assignments);

        // The count is exact; the list is capped. Same shape as
        // `findGhostMembers()`, which slices to 200 — an instance where every
        // classified team has drifted would otherwise put one entry per team
        // through a payload that today feeds a single info-menu line. The count
        // is taken before the slice so the pill never under-reports.
        $drifted = count($findings);
        $capped  = array_slice(array_values($findings), 0, self::FINDINGS_MAX);

        return [
            'classified'        => $classified,
            'conformant'        => $classified - $drifted,
            'drifted'           => $drifted,
            'findings'          => $capped,
            'findingsTruncated' => $drifted > self::FINDINGS_MAX,
            'checked_at'        => time(),
        ];
    }

    /**
     * The comparison itself, for a given set of assignments (v4.8.15).
     *
     * Shared by `complianceSummary()` (every classified team on the instance)
     * and `classificationForTeams()` (one page of the Maintenance grid). One
     * implementation deliberately: two readings of "is this team conformant"
     * that could disagree would put a green chip on the Maintenance tab beside a
     * drift count on the Compliance tab, and an administrator would have no way
     * to tell which one was lying.
     *
     * Cost is the same four reads whatever the set size — three set queries and
     * one app-config load — so calling it per page is not per-team fan-out.
     *
     * **A team is absent from the result when it is conformant, when its profile
     * governs nothing, or when Circles no longer has the circle.** The last of
     * those is an assignment row that outlived its team: not drift, because
     * there is nothing left to be non-conformant, and a data-consistency problem
     * the Compliance tab's orphan-teams row already covers.
     *
     * `$expectedOverride` (v4.8.27) supplies the expected side instead of
     * reading it, keyed by profile key exactly as the internal read produces it.
     * Propagation needs it: "did this team drift" has to be asked against the
     * profile's **previous** values, and by the time anything can ask, the new
     * ones are already stored. There is no second comparison — the same code
     * answers both questions, which is the point of the paragraph above.
     *
     * @param array<string,string> $assignments teamId => profileKey
     * @param array<string, array<string,mixed>>|null $expectedOverride
     * @return array<string, array{teamId: string, teamName: string, profileKey: string,
     *                             fields: list<array<string,mixed>>}> keyed by team id
     */
    private function compareTeams(array $assignments, ?array $expectedOverride = null): array {
        if ($assignments === []) {
            return [];
        }

        $teamIds = array_keys($assignments);

        // The expected side: one read covering every profile actually in use,
        // not every profile defined — unless the caller supplied it, in which
        // case it is already in readable form and must not be re-read.
        $expectedByProfile = $expectedOverride;
        if ($expectedByProfile === null) {
            $expectedByProfile = [];
            $inUse = array_values(array_unique(array_values($assignments)));
            foreach ($this->valueMapper->findByProfiles($inUse) as $profileKey => $stored) {
                $expectedByProfile[$profileKey] = $this->readableValues($stored);
            }
        }

        // The observed side: §5.2's three set queries.
        $circles  = $this->observationMapper->circlesByTeam($teamIds);
        $external = $this->observationMapper->externalMemberTeams($teamIds);
        $apps     = $this->appPresenceMapper->presenceForTeams($teamIds);

        // v4.8.24 — a fourth set, and it is skipped entirely unless some profile
        // actually in use governs the tag. Two queries for a field nobody has
        // configured is the cost that turns a scan cadence into a scan budget,
        // and every install that never opens this feature pays nothing.
        $folderRoots = [];
        $folderTags  = [];
        $tagGoverned = false;
        foreach ($expectedByProfile as $values) {
            if (array_key_exists(PolicyField::CONFIDENTIAL_TAG, $values)) {
                $tagGoverned = true;
                break;
            }
        }
        if ($tagGoverned && $this->groupFolderService->isGroupFoldersAvailable()) {
            $folderRoots = $this->observationMapper->teamFolderRootsByTeam($teamIds);
            $folderTags  = $this->confidentialFiles->tagsForFiles(array_values($folderRoots));
        }

        $configBits = PolicyField::configBits();
        $findings   = [];

        foreach ($assignments as $teamId => $profileKey) {
            $expected = $expectedByProfile[$profileKey] ?? [];
            if ($expected === []) {
                // A profile that governs no field cannot be departed from. The
                // team counts as conformant, which is the honest answer: it
                // satisfies everything asked of it.
                continue;
            }

            $circle = $circles[$teamId] ?? null;
            if ($circle === null) {
                continue;
            }

            $fields = [];

            foreach ($configBits as $fieldKey => $bit) {
                if (!array_key_exists($fieldKey, $expected)) {
                    continue;
                }
                $observed = ($circle['config'] & $bit) !== 0;
                if ($observed !== (bool)$expected[$fieldKey]) {
                    $fields[] = [
                        'field'    => $fieldKey,
                        'expected' => (bool)$expected[$fieldKey],
                        'observed' => $observed,
                    ];
                }
            }

            // External members: only a `false` expectation can be contradicted.
            // "Allowed" does not require anybody external to be present, so a
            // team with none is not drifting from a profile that permits them.
            if (array_key_exists(PolicyField::EXTERNAL_MEMBERS, $expected)
                && $expected[PolicyField::EXTERNAL_MEMBERS] === false
                && isset($external[$teamId])
            ) {
                $fields[] = [
                    'field'    => PolicyField::EXTERNAL_MEMBERS,
                    'expected' => false,
                    'observed' => true,
                ];
            }

            // Integrations: an allow-list, and the empty list is the field's own
            // inert setting — it allows everything rather than nothing. Same
            // asymmetry `allowedIntegrationsForTeam()` collapses to null.
            $allowed = $expected[PolicyField::INTEGRATIONS_ALLOWED] ?? null;
            if (is_array($allowed) && $allowed !== []) {
                $extra = array_values(array_diff($apps[$teamId] ?? [], $allowed));
                if ($extra !== []) {
                    $fields[] = [
                        'field'    => PolicyField::INTEGRATIONS_ALLOWED,
                        'expected' => $allowed,
                        'observed' => $extra,
                    ];
                }
            }

            if (array_key_exists(PolicyField::PUBLIC_MESSAGES, $expected)) {
                $observed = $this->config->getAppValue(
                    Application::APP_ID,
                    MessageService::CONFIG_ALLOW_PUBLIC_PREFIX . $teamId,
                    '0',
                ) === '1';
                if ($observed !== (bool)$expected[PolicyField::PUBLIC_MESSAGES]) {
                    $fields[] = [
                        'field'    => PolicyField::PUBLIC_MESSAGES,
                        'expected' => (bool)$expected[PolicyField::PUBLIC_MESSAGES],
                        'observed' => $observed,
                        // The one ENFORCED field. Flagged in the payload so the
                        // report can say what a finding here means, rather than
                        // rendering it identically to a Contacts edit.
                        'enforced' => true,
                    ];
                }
            }

            // Confidential tag (v4.8.24). Absent from the folder is the only
            // finding: a folder carrying the profile's tag *and* others is not
            // drifting, because TeamHub never claimed to own every tag on it —
            // Confidential files assigns its own by content, and an
            // administrator may tag a folder by hand.
            $expectedTag = $expected[PolicyField::CONFIDENTIAL_TAG] ?? null;
            if (is_string($expectedTag) && $expectedTag !== '') {
                $rootId = $folderRoots[$teamId] ?? 0;
                // **A team with no team folder is not a finding.** It has
                // nowhere to carry a tag, so there is nothing to have drifted
                // from. Reporting it would turn a classification control into
                // pressure to create group folders, which is a different
                // decision and not one a profile should make on an admin's
                // behalf. `TRACK-F2-DESIGN.md` §3.2 rejected a mandatory
                // team-folder field outright; this must not smuggle one in.
                if ($rootId > 0 && !in_array($expectedTag, $folderTags[$rootId] ?? [], true)) {
                    $fields[] = [
                        'field'    => PolicyField::CONFIDENTIAL_TAG,
                        'expected' => $expectedTag,
                        // Absent, rather than "some other tag". The report says
                        // the classification is missing; which other tags the
                        // folder happens to carry is not this field's business.
                        'observed' => null,
                        // The id is the value; the name is what a person reads.
                        // Resolved here because the scan already has the app
                        // service open and the drift dialog has no way to look
                        // a tag id up for itself.
                        'expectedLabel' => $this->confidentialFiles->tagName($expectedTag),
                    ];
                }
            }

            if ($fields !== []) {
                $findings[$teamId] = [
                    'teamId'     => $teamId,
                    'teamName'   => $circle['name'],
                    'profileKey' => $profileKey,
                    'fields'     => $fields,
                ];
            }
        }

        return $findings;
    }

    /**
     * Per-team classification for one page of the Maintenance grid (v4.8.15).
     *
     * What the grid renders under each team name: the template it was made
     * from, the profile it carries, and whether that profile still matches.
     *
     * **`compliant` is `null` for the template and for an unclassified team, and
     * that is not the same as `false`.** Only a profile has an expected state to
     * compare against — a template decides what a new team is *made of* and
     * leaves nothing on the team to check afterwards (`TRACK-F2-DESIGN.md` §5.2,
     * which is why expiry moving to the template removed a scan query rather
     * than adding one). The grid therefore colours the profile chip and renders
     * the template chip neutral. Colouring both would claim a check that does
     * not exist, which is the exact failure DESIGN §2.104 records for calling
     * asserted fields "locked".
     *
     * Teams with neither a profile nor a template are absent from the result;
     * the grid renders nothing for them rather than an "unclassified" chip on
     * every row of an instance that uses no profiles.
     *
     * @param list<string> $teamIds one page, not the instance
     * @return array<string, array{
     *     profileKey: ?string, profileLabel: ?string, profileSeeded: bool,
     *     compliant: ?bool, driftedFields: list<array<string,mixed>>,
     *     templateKey: ?string, templateLabel: ?string, templateSeeded: bool
     * }>
     */
    public function classificationForTeams(array $teamIds): array {
        $this->requireNcAdmin();

        if ($teamIds === []) {
            return [];
        }

        $assignments = $this->teamPolicyMapper->findByTeams($teamIds);
        $types       = $this->teamTypeMapper->findTypesByTeams($teamIds);
        $drift       = $this->compareTeams($assignments);

        // Label lookups: both tables are a handful of rows, so read them whole
        // rather than adding a keyed batch method to each mapper.
        $profiles = [];
        foreach ($this->profileMapper->findAll() as $row) {
            $profiles[$row['profileKey']] = $row;
        }
        $templates = [];
        foreach ($this->templateMapper->findAll() as $row) {
            $templates[$row['templateKey']] = $row;
        }

        $out = [];
        foreach ($teamIds as $teamId) {
            $profileKey  = $assignments[$teamId] ?? null;
            $templateKey = $types[$teamId] ?? null;

            if ($profileKey === null && $templateKey === null) {
                continue;
            }

            // A profile key with no row is a deleted profile an assignment still
            // points at. `deleteProfile()` refuses while any team carries it, so
            // this only fires if something got past that — resolved on read the
            // same way `defaultProfileForTemplate()` resolves it.
            $profile  = $profileKey !== null ? ($profiles[$profileKey] ?? null) : null;
            $template = $templateKey !== null ? ($templates[$templateKey] ?? null) : null;

            $out[$teamId] = [
                'profileKey'     => $profile !== null ? $profileKey : null,
                'profileLabel'   => $profile['label'] ?? null,
                'profileSeeded'  => (bool)($profile['isSeeded'] ?? false),
                'compliant'      => $profile !== null ? !isset($drift[$teamId]) : null,
                // v4.8.15 — the full finding objects, not just their keys: the
                // grid's drift dialog shows what the profile expects beside what
                // the team has, and "Visible to everyone is wrong" without those
                // two values leaves the admin to go and look them up anyway.
                'driftedFields'  => $drift[$teamId]['fields'] ?? [],
                // A legacy team (created before v4.0.2) has no type row, and a
                // stored type naming a template an admin has since removed
                // resolves to its key rather than to nothing — the key is still
                // what the team was made from and is more use than a blank.
                'templateKey'    => $templateKey,
                'templateLabel'  => $template['label'] ?? $templateKey,
                'templateSeeded' => (bool)($template['isSeeded'] ?? false),
            ];
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Conflict matrix
    // -------------------------------------------------------------------------

    /**
     * Every template × profile pairing that would produce a team the drift
     * report immediately flags.
     *
     * The profile wins at creation, so a conflicting pairing does not create a
     * non-conformant team — it silently strips something the admin thought the
     * template provided. That is the thing worth surfacing, and it is why this
     * is a warning surface rather than a save-blocker: blocking would make
     * adding a Restricted profile fail against templates nobody pairs it with.
     *
     * One conflict kind, which is all the current field set can produce:
     *
     *   `app_not_allowed` — the template provisions an app the profile's
     *                       integration allow-list excludes.
     *
     * There was a second, `expiry_forbidden`, until v4.8.3. It cannot happen
     * any more: expiry moved out of profiles and into templates, so one side
     * of that disagreement no longer exists.
     *
     * @return list<array<string,mixed>>
     */
    public function conflictMatrix(): array {
        $this->requireNcAdmin();

        $templates = $this->templateMapper->findAll();
        $profiles  = $this->profileMapper->findAll();
        $values    = $this->valueMapper->findByProfiles(array_column($profiles, 'profileKey'));

        $out = [];
        foreach ($profiles as $profile) {
            $own = $values[$profile['profileKey']] ?? [];

            if (!isset($own[PolicyField::INTEGRATIONS_ALLOWED])) {
                continue;
            }
            $allowed = PolicyField::cast(
                PolicyField::INTEGRATIONS_ALLOWED,
                $own[PolicyField::INTEGRATIONS_ALLOWED],
            );
            // An empty allow-list means ALL allowed — the field ships inert by
            // construction — so it can never conflict.
            if (!is_array($allowed) || $allowed === []) {
                continue;
            }

            foreach ($templates as $template) {
                $blocked = array_values(array_diff($template['apps'], $allowed));
                if ($blocked !== []) {
                    $out[] = [
                        'kind'        => 'app_not_allowed',
                        'templateKey' => $template['templateKey'],
                        'profileKey'  => $profile['profileKey'],
                        'fieldKey'    => PolicyField::INTEGRATIONS_ALLOWED,
                        'detail'      => $blocked,
                    ];
                }
            }
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Validation helpers
    // -------------------------------------------------------------------------

    private function assertValidKey(string $key): void {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new ValidationException(
                'A profile key must be 2–32 characters, start with a letter, and use only lowercase letters, digits and underscores.',
            );
        }
    }

    private function assertValidLabel(string $label): string {
        $label = trim($label);
        if ($label === '') {
            throw new ValidationException('A name is required.');
        }
        if (mb_strlen($label) > self::LABEL_MAX) {
            throw new ValidationException('The name must be at most ' . self::LABEL_MAX . ' characters.');
        }

        return $label;
    }

    private function normaliseDescription(?string $description): ?string {
        if ($description === null) {
            return null;
        }
        $description = trim($description);
        if ($description === '') {
            return null;
        }

        return mb_substr($description, 0, self::DESCRIPTION_MAX);
    }

    private function assertValidSortIndex(int $sortIndex): int {
        if ($sortIndex < 0 || $sortIndex > self::SORT_MAX) {
            throw new ValidationException('The order must be between 0 and ' . self::SORT_MAX . '.');
        }

        return $sortIndex;
    }

    /**
     * Validate and serialise a submitted value set.
     *
     * A field the caller omits is *ungoverned*, which is a third state distinct
     * from false — so omission is not an error and is not filled in.
     *
     * **A dependent field is dropped when its dependency is not satisfied**
     * rather than rejected. The panel greys such a field out, so a value
     * arriving here means the admin turned the dependency off after ticking
     * the dependent one; storing it would keep a setting the platform ignores
     * (`CFG_REQUEST` without `CFG_OPEN` is simply "closed"), and refusing the
     * save would make an admin hunt for a control that is already disabled.
     *
     * @param array<string,mixed> $values field key => scalar, or { value: … }
     * @return array<string, string> field key => serialised value
     */
    private function normaliseValues(array $values): array {
        $out = [];
        foreach ($values as $fieldKey => $spec) {
            $fieldKey = (string)$fieldKey;
            if (!PolicyField::isValid($fieldKey)) {
                throw new ValidationException('Unknown policy field: ' . $fieldKey);
            }

            // Accept both the bare value and the { value: … } envelope the
            // 4.8.2 API used, so a client that has not reloaded still saves.
            $raw = (is_array($spec) && array_key_exists('value', $spec)) ? $spec['value'] : $spec;

            try {
                $out[$fieldKey] = PolicyField::serialize($fieldKey, $raw);
            } catch (\InvalidArgumentException $e) {
                throw new ValidationException($e->getMessage());
            }
        }

        foreach (array_keys($out) as $fieldKey) {
            $needs = PolicyField::dependsOn($fieldKey);
            if ($needs !== null && ($out[$needs] ?? '0') !== '1') {
                unset($out[$fieldKey]);
            }
        }

        // v4.8.24 — `confidential_tag` carries an id from a vocabulary the
        // registry cannot know, so its membership check lives here, where the
        // service that can read the label list is injected.
        //
        // **A ValidationException, not a silent drop.** The dependency loop
        // above unsets a field whose condition another *field* fails, because
        // there the administrator has expressed two settings that contradict
        // each other and the profile is still coherent without one. This is not
        // that: an administrator who picked a tag and is told nothing while it
        // is discarded would go on believing the team folder gets classified.
        // Governance that silently does not exist is the exact failure DESIGN
        // §2.104 is written against.
        if (isset($out[PolicyField::CONFIDENTIAL_TAG])) {
            if (!$this->confidentialFiles->isAppAvailable()) {
                throw new ValidationException(
                    'The Confidential files app is not installed, so a classification tag cannot be set.',
                );
            }
            if (!$this->confidentialFiles->isSelectableTag($out[PolicyField::CONFIDENTIAL_TAG])) {
                throw new ValidationException(
                    'That tag is not used by any Confidential files classification label.',
                );
            }
        }

        return $out;
    }

    /**
     * Cast stored values back for the API payload.
     *
     * @param array<string, string> $stored
     * @return array<string, mixed>
     */
    private function readableValues(array $stored): array {
        $out = [];
        foreach ($stored as $fieldKey => $value) {
            // A retired field can still have a row on an instance that ran
            // 4.8.2 — Version000408003 deletes the two known ones, but skipping
            // anything the registry no longer knows means a stale row can never
            // reach the panel as a field it cannot render.
            if (PolicyField::isValid($fieldKey)) {
                $out[$fieldKey] = PolicyField::cast($fieldKey, $value);
            }
        }

        return $out;
    }

    /**
     * Field keys whose value differs between two value sets, including ones
     * added or removed.
     *
     * @param array<string, string> $before
     * @param array<string, string> $after
     * @return list<string>
     */
    private function diffFields(array $before, array $after): array {
        $keys    = array_unique(array_merge(array_keys($before), array_keys($after)));
        $changed = [];
        foreach ($keys as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changed[] = $key;
            }
        }

        return array_values($changed);
    }

    /**
     * @param list<string> $submitted
     * @param list<string> $vocabulary
     * @return list<string>
     */
    private function filterToVocabulary(array $submitted, array $vocabulary, string $noun): array {
        $out = [];
        foreach ($submitted as $item) {
            $item = trim((string)$item);
            if ($item === '') {
                continue;
            }
            if (!in_array($item, $vocabulary, true)) {
                throw new ValidationException('Unknown ' . $noun . ': ' . $item);
            }
            $out[$item] = true;
        }

        // Emitted in vocabulary order rather than submission order, so two
        // admins submitting the same set produce the same stored string and the
        // audit diff does not fire on a reordering.
        return array_values(array_filter($vocabulary, static fn (string $k): bool => isset($out[$k])));
    }

    /**
     * Write an instance-scoped audit row.
     *
     * `AuditService::INSTANCE_SCOPE` rather than a team id — a profile
     * definition belongs to no team, and the sentinel is invisible to every
     * team-scoped reader. See that constant's docblock.
     *
     * @param array<string,mixed> $metadata
     */
    private function auditInstance(string $eventType, string $actor, string $targetId, array $metadata): void {
        $this->auditService->log(
            AuditService::INSTANCE_SCOPE,
            $eventType,
            $actor,
            'policy',
            $targetId,
            $metadata,
        );

        $this->logger->info('[TeamHub][PolicyService] ' . $eventType, [
            'target' => $targetId,
            'actor'  => $actor,
            'app'    => Application::APP_ID,
        ]);
    }
}
