<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\PropertyDoesNotExistException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * The line under a person's name that says *which* person this is (v4.10.7).
 *
 * Two people called Jari Feet work at the same company. Nextcloud's own answer
 * to that — `shareWithDisplayNameUnique`, the email or the uid — tells the
 * picker `jfeet2@company.com`, which says nothing to the colleague who needs
 * *Jari Feet from Finance* (Justin, 2026-09-20). What does say it lives on the
 * account profile: the job title, the organisation, the headline, the
 * manager — and the groups, which in most directories *are* the departments.
 *
 * Which of those fields, and in which order, is a property of the
 * organisation, not of the app: one fills in job titles, another only has
 * groups, a third does use email domains meaningfully. So the list is an
 * administrator's setting (`person_subline_fields`, Admin → TeamHub), with
 * *job title · organisation* as the default. Groups were in the default for
 * one version (4.10.7) and came out on Justin's first look at a real row:
 * "JaapAgent · TeamHub creators" — a group name says which club somebody
 * joined, not who they are. They stay available for the administrator whose
 * directory maps departments to groups. There is no *department* profile
 * field in Nextcloud; *organisation* is where most instances put it.
 *
 * **Rule.** Walk the configured fields in order and collect non-empty values
 * until there are two; groups count as one value (up to three names). Join
 * with ` · `. Nothing configured or nothing filled in gives an empty line —
 * deliberately no implicit uid fallback, because a uid under every name in a
 * team of distinct people is noise, and an administrator who wants it adds it.
 *
 * **Scope.** An account property the person set to *private* is not shown to
 * other members — the same rule the members widget applies to email and phone
 * — unless the reader is a Nextcloud administrator using an admin picker.
 * Groups and the uid have no scope; showing groups is the administrator's
 * decision in the field list.
 *
 * Every consumer of a person row (the invite picker, the wizard, the bulk
 * cell, the admin pickers, the members list behind @mentions) reads this one
 * method, so the same person is described the same way everywhere. A DI leaf.
 */
class PersonSublineService {

    public const CONFIG_KEY = 'person_subline_fields';

    /** The stored value for "no fields at all", as opposed to "never set" (= the default). */
    public const NONE = 'none';

    /** Every field an administrator may pick, in the order the settings panel lists them. */
    public const FIELDS = ['role', 'organisation', 'headline', 'manager', 'groups', 'email', 'uid'];

    public const DEFAULT_FIELDS = ['role', 'organisation'];

    /** How many values make a line. */
    private const MAX_PARTS = 2;

    /** How many group names the groups value carries. */
    private const MAX_GROUPS = 3;

    private const SEPARATOR = ' · ';

    /** @var array<string,string>|null memoised per request */
    private ?array $fields = null;

    public function __construct(
        private IConfig         $config,
        private IAccountManager $accountManager,
        private IGroupManager   $groupManager,
        private IUserManager    $userManager,
        private LoggerInterface $logger,
    ) {}

    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------

    /**
     * The configured fields, in order, validated against {@see FIELDS}.
     *
     * @return string[]
     */
    public function configuredFields(): array {
        if ($this->fields !== null) {
            return $this->fields;
        }
        $raw = $this->config->getAppValue(Application::APP_ID, self::CONFIG_KEY, '');
        if ($raw === '') {
            return $this->fields = self::DEFAULT_FIELDS;
        }
        // An administrator who unticked every field chose "no subline"; that
        // is stored as NONE because an unset key and an empty value read the
        // same through getAppValue(), and unset means "the default".
        if ($raw === self::NONE) {
            return $this->fields = [];
        }
        return $this->fields = self::sanitizeFields(explode(',', $raw));
    }

    /** What to store for an administrator's list. */
    public static function storableValue(array $fields): string {
        $fields = self::sanitizeFields($fields);
        return $fields === [] ? self::NONE : implode(',', $fields);
    }

    /**
     * Normalise an administrator's submission to a storable list.
     * Unknown names are dropped, duplicates collapsed, order kept.
     *
     * @param string[] $fields
     * @return string[]
     */
    public static function sanitizeFields(array $fields): array {
        $out = [];
        foreach ($fields as $field) {
            $field = trim((string)$field);
            if (in_array($field, self::FIELDS, true) && !in_array($field, $out, true)) {
                $out[] = $field;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Sublines
    // ------------------------------------------------------------------

    /** The subline for one person; '' when nothing configured is filled in. */
    public function sublineFor(IUser $user, bool $adminContext = false): string {
        $parts = [];
        foreach ($this->configuredFields() as $field) {
            if (count($parts) >= self::MAX_PARTS) {
                break;
            }
            $value = $this->valueFor($user, $field, $adminContext);
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        return implode(self::SEPARATOR, $parts);
    }

    /**
     * Sublines for many people at once. Unknown uids are left out.
     *
     * @param string[] $uids
     * @return array<string,string> uid => subline
     */
    public function sublinesFor(array $uids, bool $adminContext = false): array {
        if ($this->configuredFields() === []) {
            return [];
        }
        $out = [];
        foreach (array_unique($uids) as $uid) {
            $user = $this->userManager->get((string)$uid);
            if ($user === null) {
                continue;
            }
            $out[(string)$uid] = $this->sublineFor($user, $adminContext);
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Field values
    // ------------------------------------------------------------------

    private function valueFor(IUser $user, string $field, bool $adminContext): string {
        try {
            return match ($field) {
                'role'         => $this->property($user, IAccountManager::PROPERTY_ROLE, $adminContext),
                'organisation' => $this->property($user, IAccountManager::PROPERTY_ORGANISATION, $adminContext),
                'headline'     => $this->property($user, IAccountManager::PROPERTY_HEADLINE, $adminContext),
                'email'        => $this->property($user, IAccountManager::PROPERTY_EMAIL, $adminContext),
                'manager'      => $this->manager($user),
                'groups'       => $this->groups($user),
                'uid'          => $user->getUID(),
                default        => '',
            };
        } catch (\Throwable $e) {
            // One unreadable field never costs the row.
            $this->logger->debug('[TeamHub][PersonSublineService] field unreadable', [
                'field' => $field, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return '';
        }
    }

    /** An account property, honouring the scope the person chose. */
    private function property(IUser $user, string $property, bool $adminContext): string {
        try {
            $prop = $this->accountManager->getAccount($user)->getProperty($property);
        } catch (PropertyDoesNotExistException $e) {
            return '';
        }
        $value = $prop->getValue();
        if (!is_string($value) || trim($value) === '') {
            return '';
        }
        if (!$adminContext && $prop->getScope() === IAccountManager::SCOPE_PRIVATE) {
            return '';
        }
        return trim($value);
    }

    /** The manager's display name (Nextcloud 27+ profile field). */
    private function manager(IUser $user): string {
        if (!method_exists($user, 'getManagerUids')) {
            return '';
        }
        foreach ($user->getManagerUids() as $managerUid) {
            $manager = $this->userManager->get((string)$managerUid);
            if ($manager !== null) {
                return $manager->getDisplayName() ?: (string)$managerUid;
            }
        }
        return '';
    }

    /** Up to three group display names, as the backend orders them. */
    private function groups(IUser $user): string {
        $names = [];
        foreach ($this->groupManager->getUserGroups($user) as $group) {
            $name = $group->getDisplayName() ?: $group->getGID();
            if ($name === '') {
                continue;
            }
            $names[] = $name;
            if (count($names) >= self::MAX_GROUPS) {
                break;
            }
        }
        return implode(', ', $names);
    }
}
