<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Provisioning;

use OCA\TeamHub\Constants\TeamApps;
use OCA\TeamHub\Exception\ValidationException;

/**
 * A team template's blueprint (v4.9.6, OpenProject Phase 2): what a new
 * workspace of this kind is made of, declared rather than coded.
 *
 * ## What a blueprint owns — and what it does not
 *
 * The blueprint is TeamHub's half of a two-template arrangement. **The
 * template row decides the applications and modules** (Justin, 2026-09-13:
 * the template decides what a team is connected to — there is one place for
 * that, Admin → Policy → the template's apps and modules, and the wizard has
 * no apps step). The blueprint adds what the row cannot say: which dashboard
 * widgets a workspace shows, whether it needs an OpenProject project and in
 * which modes, which OpenProject templates may seed one, what is copied from
 * such a template, how TeamHub roles map to OpenProject roles, and how the
 * project folder is coordinated with OpenProject's. Everything *inside* the
 * OpenProject project — work-package types, milestones, phases, custom
 * fields — belongs to the OpenProject template and is never copied in here;
 * the blueprint holds the template's stable identifier and nothing more.
 *
 * `apps` and `modules` are therefore always **derived from the row** when a
 * blueprint is read ({@see fromTemplateRow()}), whatever a stored blueprint
 * says: every listed app and module is required, none is optional. The keys
 * stay in the model (validated, carried) so the shape does not change if a
 * later phase gives them a meaning again.
 *
 * ## Backward compatibility
 *
 * A template row without `blueprint_json` (every row created before 4.9.6)
 * is read through {@see derived()}: its `apps` and `modules` lists, no
 * OpenProject, the shipped dashboard. That is exactly what such a template
 * provisioned before, so nothing changes for Collaboration, Project and
 * Department.
 *
 * ## Vocabulary
 *
 * Applications are named in the registry's canonical spelling
 * ({@see TeamApps::CANONICAL}); the aliases a template row uses (`wiki`,
 * `pages`) are normalised on the way in. Modules are the feature switches
 * ({@see TeamApps::FEATURE_MODULES}). Role keys are TeamHub's four levels
 * plus `guest` for members who are not local accounts.
 *
 * `governance` and `lifecycle` are carried and validated as objects but not
 * interpreted — reserved for Phase 4, and kept in the shape so a blueprint
 * written now is still valid then.
 */
final class Blueprint {

    public const VERSION = 1;

    /** TeamHub role keys, highest first. */
    public const ROLE_KEYS = ['owner', 'admin', 'moderator', 'member', 'guest'];

    /** Circles level per role key (guest has none — it is a member type). */
    public const ROLE_LEVELS = ['owner' => 9, 'admin' => 8, 'moderator' => 4, 'member' => 1];

    public const FOLDER_BEHAVIORS     = ['teamhub', 'openproject', 'both', 'none'];
    public const TALK_BEHAVIORS       = ['create', 'none'];
    public const CALENDAR_BEHAVIORS   = ['create', 'none'];
    public const COLLECTIVE_BEHAVIORS = ['create', 'optional', 'none'];
    public const OPENPROJECT_MODES    = ['create', 'link'];

    /** The copy switches OpenProject's copy endpoint understands (verified against OpenProject 17). */
    public const COPY_KEYS = [
        'members', 'versions', 'categories', 'workPackages', 'workPackageAttachments',
        'wiki', 'wikiPageAttachments', 'forums', 'queries', 'boards', 'overview', 'phases',
        'storages', 'storageProjectFolders', 'fileLinks', 'workPackageShares',
    ];

    /** Built-in widget ids a blueprint may name (mirrors LayoutController::ALLOWED_WIDGET_IDS minus the legacy ones). */
    public const WIDGET_IDS = [
        'msgstream', 'widget-teaminfo', 'widget-members', 'widget-calendar', 'widget-deck',
        'widget-activity', 'widget-pages', 'widget-files-center', 'widget-decisions',
        'widget-project-health', 'widget-openproject',
    ];

    /** @param array<string,mixed> $data a normalised blueprint (see validate()) */
    private function __construct(private array $data) {}

    // ─────────────────────────────────────────────────────────────────────
    // Construction
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The blueprint of a template row as the mapper hydrates it: the stored
     * one when there is one, the derived one otherwise. A stored blueprint
     * that no longer validates (an older shape, a hand edit) falls back to
     * the derived one rather than taking the wizard down, and says so.
     *
     * @param array<string,mixed> $row
     */
    public static function fromTemplateRow(array $row): self {
        $stored = $row['blueprint'] ?? null;
        if (is_array($stored)) {
            try {
                // The row's apps and modules win over anything stored — see
                // the class docblock. Overlaid before validation so the
                // behaviours derive from the same list.
                $bp = self::fromArray(self::rowAppsAndModules($row) + $stored, $row);
                $bp->data['stored'] = true;
                return $bp;
            } catch (ValidationException) {
                // fall through to derived
            }
        }
        return self::derived($row);
    }

    /**
     * The `apps`, `modules` and per-application behaviour keys a template
     * row implies: every listed application and module, required; a
     * behaviour of `create` for every listed application and `none`
     * otherwise. The folder behaviour is the one behaviour a stored
     * blueprint keeps (TeamHub folder, OpenProject's, both or none), so it
     * is not in here.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function rowAppsAndModules(array $row): array {
        $apps    = TeamApps::observableList(array_merge($row['apps'] ?? [], $row['modules'] ?? []));
        $modules = TeamApps::featureModules($row['modules'] ?? []);
        return [
            'apps'       => ['required' => $apps, 'optional' => []],
            'modules'    => ['required' => $modules, 'optional' => []],
            'talk'       => ['behavior' => in_array('talk', $apps, true) ? 'create' : 'none'],
            'calendar'   => ['behavior' => in_array('calendar', $apps, true) ? 'create' : 'none'],
            'collective' => ['behavior' => in_array('collectives', $apps, true) ? 'create' : 'none'],
        ];
    }

    /**
     * The blueprint a template row implies when it has none stored: its apps
     * and modules, every one of them required. This is the pre-4.9.6
     * behaviour, made explicit.
     *
     * @param array<string,mixed> $row
     */
    public static function derived(array $row): self {
        $fromRow = self::rowAppsAndModules($row);

        return new self(self::validate($fromRow + [
            'version'     => self::VERSION,
            'dashboard'   => ['widgets' => [], 'hidden' => []],
            'openproject' => ['required' => ($row['templateKey'] ?? '') === 'openproject'],
            'roles'       => [],
            'folder'      => ['behavior' => in_array('files', $fromRow['apps']['required'], true) ? 'teamhub' : 'none'],
            'governance'  => [],
            'lifecycle'   => [],
        ], $row) + ['stored' => false, 'derived' => true]);
    }

    /**
     * The shipped blueprint of the `openproject` template — the same values
     * Version000409006 seeds as literals. Used when an administrator resets
     * the blueprint, and by the tests.
     */
    public static function defaultsForOpenProject(): self {
        return new self(self::validate([
            'version'     => self::VERSION,
            // Apps and modules mirror the seeded template row (Version000409004:
            // Talk, Files, Calendar; Decisions, Messages, Pages) — the row is
            // what decides them; these values only matter to a reset and a test.
            'apps'        => ['required' => ['talk', 'files', 'calendar', 'intravox'], 'optional' => []],
            'modules'     => ['required' => ['decisions', 'messages'], 'optional' => []],
            'dashboard'   => [
                'widgets' => ['widget-openproject', 'widget-files-center', 'msgstream', 'widget-calendar', 'widget-pages', 'widget-deck', 'widget-members'],
                'hidden'  => [],
            ],
            'openproject' => [
                'required'          => true,
                'modes'             => ['create', 'link'],
                'approvedTemplates' => [],
                'allowParent'       => true,
                'copy'              => [
                    'members' => false, 'workPackages' => true, 'versions' => true, 'categories' => true,
                    'wiki' => true, 'queries' => true, 'boards' => true, 'overview' => true, 'phases' => true,
                ],
            ],
            'roles'       => [
                'mapping'  => ['owner' => 'Project admin', 'admin' => 'Project admin', 'moderator' => 'Member', 'member' => 'Member', 'guest' => null],
                'required' => ['owner'],
            ],
            'folder'      => ['behavior' => 'both'],
            'talk'        => ['behavior' => 'create'],
            'calendar'    => ['behavior' => 'create'],
            'collective'  => ['behavior' => 'none'],
            'governance'  => [],
            'lifecycle'   => [],
        ], ['templateKey' => 'openproject']) + ['stored' => true]);
    }

    /**
     * A blueprint from raw (admin-submitted or stored) data.
     *
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $row the template row, for the derived parts
     * @throws ValidationException
     */
    public static function fromArray(array $raw, array $row = []): self {
        return new self(self::validate($raw, $row) + ['stored' => true]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Validation
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Normalise and validate raw blueprint data. Unknown keys are dropped;
     * unknown vocabulary is refused with the key named, so an administrator
     * pasting a blueprint learns what is wrong rather than getting a silently
     * different workspace.
     *
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     * @throws ValidationException
     */
    public static function validate(array $raw, array $row = []): array {
        $version = (int)($raw['version'] ?? self::VERSION);
        if ($version !== self::VERSION) {
            throw new ValidationException('Unsupported blueprint version ' . $version);
        }

        $apps = $raw['apps'] ?? [];
        if (!is_array($apps)) {
            throw new ValidationException('blueprint.apps must be an object');
        }
        $requiredApps = self::appList($apps['required'] ?? [], 'apps.required');
        $optionalApps = self::appList($apps['optional'] ?? [], 'apps.optional');
        $optionalApps = array_values(array_diff($optionalApps, $requiredApps));

        $modules = $raw['modules'] ?? [];
        if (!is_array($modules)) {
            throw new ValidationException('blueprint.modules must be an object');
        }
        $requiredModules = self::moduleList($modules['required'] ?? [], 'modules.required');
        $optionalModules = self::moduleList($modules['optional'] ?? [], 'modules.optional');
        $optionalModules = array_values(array_diff($optionalModules, $requiredModules));

        $dashboard = $raw['dashboard'] ?? [];
        if (!is_array($dashboard)) {
            throw new ValidationException('blueprint.dashboard must be an object');
        }
        $widgets = self::widgetList($dashboard['widgets'] ?? [], 'dashboard.widgets');
        $hidden  = self::widgetList($dashboard['hidden'] ?? [], 'dashboard.hidden');
        $hidden  = array_values(array_diff($hidden, $widgets));

        $op = $raw['openproject'] ?? [];
        if (!is_array($op)) {
            throw new ValidationException('blueprint.openproject must be an object');
        }
        $opRequired = (bool)($op['required'] ?? false);
        $opModes    = self::enumList($op['modes'] ?? self::OPENPROJECT_MODES, self::OPENPROJECT_MODES, 'openproject.modes');
        if ($opRequired && $opModes === []) {
            throw new ValidationException('openproject.modes must allow at least one of create, link');
        }
        $approved = $op['approvedTemplates'] ?? [];
        if (!is_array($approved)) {
            throw new ValidationException('openproject.approvedTemplates must be a list');
        }
        $approvedTemplates = [];
        foreach ($approved as $tpl) {
            if (is_array($tpl)) {
                $id  = (int)($tpl['id'] ?? 0);
                $ident = self::identifierOrEmpty((string)($tpl['identifier'] ?? ''));
            } else {
                $id    = (int)$tpl;
                $ident = '';
            }
            if ($id <= 0) {
                throw new ValidationException('openproject.approvedTemplates entries need a numeric project id');
            }
            $approvedTemplates[$id] = ['id' => $id, 'identifier' => $ident, 'name' => self::plain((string)(is_array($tpl) ? ($tpl['name'] ?? '') : ''))];
        }
        $copy = $op['copy'] ?? [];
        if (!is_array($copy)) {
            throw new ValidationException('openproject.copy must be an object');
        }
        $copyOptions = [];
        foreach ($copy as $key => $value) {
            if (!in_array($key, self::COPY_KEYS, true)) {
                throw new ValidationException('Unknown openproject.copy key: ' . self::plain((string)$key));
            }
            $copyOptions[$key] = (bool)$value;
        }

        $roles = $raw['roles'] ?? [];
        if (!is_array($roles)) {
            throw new ValidationException('blueprint.roles must be an object');
        }
        $mapping = [];
        foreach ((array)($roles['mapping'] ?? []) as $key => $value) {
            if (!in_array($key, self::ROLE_KEYS, true)) {
                throw new ValidationException('Unknown role key in roles.mapping: ' . self::plain((string)$key));
            }
            if ($value === null || $value === '') {
                $mapping[$key] = null;
                continue;
            }
            if (!is_string($value) || mb_strlen($value) > 255) {
                throw new ValidationException('roles.mapping.' . $key . ' must be an OpenProject role name or null');
            }
            $mapping[$key] = self::plain($value);
        }
        $requiredRoles = self::enumList($roles['required'] ?? [], self::ROLE_KEYS, 'roles.required');

        $folder     = self::behavior($raw['folder'] ?? [], self::FOLDER_BEHAVIORS, 'folder', in_array('files', $requiredApps, true) ? 'teamhub' : 'none');
        $talk       = self::behavior($raw['talk'] ?? [], self::TALK_BEHAVIORS, 'talk', in_array('talk', $requiredApps, true) ? 'create' : 'none');
        $calendar   = self::behavior($raw['calendar'] ?? [], self::CALENDAR_BEHAVIORS, 'calendar', in_array('calendar', $requiredApps, true) ? 'create' : 'none');
        $collective = self::behavior($raw['collective'] ?? [], self::COLLECTIVE_BEHAVIORS, 'collective', in_array('collectives', $requiredApps, true) ? 'create' : (in_array('collectives', $optionalApps, true) ? 'optional' : 'none'));

        $governance = $raw['governance'] ?? [];
        $lifecycle  = $raw['lifecycle'] ?? [];
        if (!is_array($governance) || !is_array($lifecycle)) {
            throw new ValidationException('blueprint.governance and blueprint.lifecycle must be objects');
        }

        return [
            'version'     => self::VERSION,
            'templateKey' => (string)($row['templateKey'] ?? ''),
            'apps'        => ['required' => $requiredApps, 'optional' => $optionalApps],
            'modules'     => ['required' => $requiredModules, 'optional' => $optionalModules],
            'dashboard'   => ['widgets' => $widgets, 'hidden' => $hidden],
            'openproject' => [
                'required'          => $opRequired,
                'modes'             => $opModes,
                'approvedTemplates' => array_values($approvedTemplates),
                'allowParent'       => (bool)($op['allowParent'] ?? true),
                'copy'              => $copyOptions,
            ],
            'roles'       => ['mapping' => $mapping, 'required' => $requiredRoles],
            'folder'      => ['behavior' => $folder],
            'talk'        => ['behavior' => $talk],
            'calendar'    => ['behavior' => $calendar],
            'collective'  => ['behavior' => $collective],
            // Reserved for Phase 4 — carried, not read. Scalars only, so a
            // stored blueprint cannot smuggle structure nothing validates.
            'governance'  => self::scalarMap($governance, 'governance'),
            'lifecycle'   => self::scalarMap($lifecycle, 'lifecycle'),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Accessors
    // ─────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    public function requiredApps(): array { return $this->data['apps']['required']; }
    /** @return list<string> */
    public function optionalApps(): array { return $this->data['apps']['optional']; }
    /** @return list<string> */
    public function requiredModules(): array { return $this->data['modules']['required']; }
    /** @return list<string> */
    public function optionalModules(): array { return $this->data['modules']['optional']; }
    /** @return list<string> */
    public function dashboardWidgets(): array { return $this->data['dashboard']['widgets']; }
    /** @return list<string> */
    public function dashboardHidden(): array { return $this->data['dashboard']['hidden']; }
    public function requiresOpenProject(): bool { return $this->data['openproject']['required']; }
    /** @return list<string> */
    public function openProjectModes(): array { return $this->data['openproject']['modes']; }
    /** @return list<array{id:int, identifier:string, name:string}> */
    public function approvedTemplates(): array { return $this->data['openproject']['approvedTemplates']; }
    public function allowsParent(): bool { return $this->data['openproject']['allowParent']; }
    /** @return array<string,bool> */
    public function copyOptions(): array { return $this->data['openproject']['copy']; }
    /** @return array<string,?string> role key → OpenProject role name or null */
    public function roleMapping(): array { return $this->data['roles']['mapping']; }
    /** @return list<string> */
    public function requiredRoles(): array { return $this->data['roles']['required']; }
    public function folderBehavior(): string { return $this->data['folder']['behavior']; }
    public function talkBehavior(): string { return $this->data['talk']['behavior']; }
    public function calendarBehavior(): string { return $this->data['calendar']['behavior']; }
    public function collectiveBehavior(): string { return $this->data['collective']['behavior']; }
    /** @return array<string,scalar|null> */
    public function governance(): array { return $this->data['governance']; }
    /** @return array<string,scalar|null> */
    public function lifecycle(): array { return $this->data['lifecycle']; }
    public function isStored(): bool { return (bool)($this->data['stored'] ?? false); }
    public function templateKey(): string { return $this->data['templateKey']; }

    /** Is an OpenProject template approved for this blueprint (an empty list approves every accessible one). */
    public function isTemplateApproved(int $projectId): bool {
        $approved = $this->approvedTemplates();
        if ($approved === []) {
            return true;
        }
        foreach ($approved as $tpl) {
            if ($tpl['id'] === $projectId) {
                return true;
            }
        }
        return false;
    }

    /** Is this application part of the blueprint at all. */
    public function knowsApp(string $appId): bool {
        $appId = TeamApps::canonical($appId);
        return in_array($appId, $this->requiredApps(), true) || in_array($appId, $this->optionalApps(), true);
    }

    /** The stored/derived shape, for the API and for saving. */
    public function toArray(): array {
        $out = $this->data;
        unset($out['stored'], $out['derived'], $out['templateKey']);
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Validation helpers
    // ─────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private static function appList(mixed $list, string $where): array {
        if (!is_array($list)) {
            throw new ValidationException('blueprint.' . $where . ' must be a list');
        }
        $out = [];
        foreach ($list as $item) {
            $app = TeamApps::canonical(trim((string)$item));
            if (!in_array($app, TeamApps::CANONICAL, true)) {
                throw new ValidationException('Unknown application in ' . $where . ': ' . self::plain((string)$item));
            }
            if (!in_array($app, $out, true)) {
                $out[] = $app;
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function moduleList(mixed $list, string $where): array {
        if (!is_array($list)) {
            throw new ValidationException('blueprint.' . $where . ' must be a list');
        }
        $out = [];
        foreach ($list as $item) {
            $module = trim((string)$item);
            if (!in_array($module, TeamApps::FEATURE_MODULES, true)) {
                throw new ValidationException('Unknown module in ' . $where . ': ' . self::plain($module));
            }
            if (!in_array($module, $out, true)) {
                $out[] = $module;
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function widgetList(mixed $list, string $where): array {
        if (!is_array($list)) {
            throw new ValidationException('blueprint.' . $where . ' must be a list');
        }
        $out = [];
        foreach ($list as $item) {
            $id = trim((string)$item);
            // Integration widgets are dynamic ("widget-int-<registryId>") and
            // allowed by prefix, exactly as LayoutController accepts them.
            if (!in_array($id, self::WIDGET_IDS, true) && !preg_match('/^widget-int-\d+$/', $id)) {
                throw new ValidationException('Unknown widget in ' . $where . ': ' . self::plain($id));
            }
            if (!in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * @param list<string> $allowed
     * @return list<string>
     */
    private static function enumList(mixed $list, array $allowed, string $where): array {
        if (!is_array($list)) {
            throw new ValidationException('blueprint.' . $where . ' must be a list');
        }
        $out = [];
        foreach ($list as $item) {
            $value = trim((string)$item);
            if (!in_array($value, $allowed, true)) {
                throw new ValidationException('Unknown value in ' . $where . ': ' . self::plain($value));
            }
            if (!in_array($value, $out, true)) {
                $out[] = $value;
            }
        }
        return $out;
    }

    /** @param list<string> $allowed */
    private static function behavior(mixed $section, array $allowed, string $where, string $default): string {
        if (!is_array($section)) {
            throw new ValidationException('blueprint.' . $where . ' must be an object');
        }
        $value = trim((string)($section['behavior'] ?? $default));
        if (!in_array($value, $allowed, true)) {
            throw new ValidationException('Unknown ' . $where . '.behavior: ' . self::plain($value));
        }
        return $value;
    }

    /** @return array<string,scalar|null> */
    private static function scalarMap(array $map, string $where): array {
        $out = [];
        foreach ($map as $key => $value) {
            if (!is_string($key) || $key === '' || mb_strlen($key) > 64) {
                throw new ValidationException('blueprint.' . $where . ' keys must be short strings');
            }
            if ($value !== null && !is_scalar($value)) {
                throw new ValidationException('blueprint.' . $where . '.' . self::plain($key) . ' must be a scalar');
            }
            $out[$key] = is_string($value) ? mb_substr($value, 0, 255) : $value;
        }
        return $out;
    }

    private static function identifierOrEmpty(string $value): string {
        return preg_match('/^[a-z0-9][a-z0-9_-]*$/', $value) === 1 ? $value : '';
    }

    /** Plain text for messages: no tags, no control characters, bounded. */
    private static function plain(string $value): string {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', strip_tags($value)) ?? '';
        return mb_substr($value, 0, 120);
    }
}
