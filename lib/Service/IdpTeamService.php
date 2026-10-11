<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Which Nextcloud groups should become TeamHub teams, and the rule the
 * customer sets to decide it (v4.11.32, DESIGN §2.15x).
 *
 * ## The problem this exists for
 *
 * A customer whose accounts come from a directory wants a team per department
 * without creating each one by hand. Nextcloud gives us the department as a
 * **group** — `user_ldap` natively, `user_oidc --group-provisioning=1` from an
 * OIDC claim — and TeamHub already keeps a team's roster in step with a group
 * it holds ({@see GroupMirrorSyncService}). What was missing is the decision
 * that the team should exist at all. That decision is a creation policy, which
 * is TeamHub's job and nobody else's, so it lives here.
 *
 * **TeamHub never talks to an identity provider.** No SCIM client, no OIDC
 * client, no provider URL or token in this app. Every directory reaches us
 * through Nextcloud's own group backends, which is one integration seam
 * instead of three.
 *
 * ## Telling an IdP group from a hand-made one
 *
 * Only partly possible, and the asymmetry drives the whole settings shape:
 *
 *   - **LDAP/AD: yes.** `IGroup::getBackendNames()` (public, since NC 22)
 *     returns `['LDAP']`; user_ldap's `Group_Proxy` implements `INamedBackend`
 *     and its `getBackendName()` returns that literal.
 *   - **OIDC: no.** `user_oidc`'s group provisioning calls
 *     `IGroupManager::createGroup($gid)` and `setDisplayName()` and nothing
 *     else. The group lands in the **Database** backend with no mapping table,
 *     no metadata and no marker — it is indistinguishable from a group an
 *     administrator typed in by hand. Same for anything provisioned through
 *     the OCS Provisioning API.
 *
 * So "comes from an IdP" cannot be the only test. The rule is up to three
 * filters, ANDed, and a group must pass every one that is set:
 *
 *   1. **backend** — `MODE_*`/`SETTING_BACKENDS`, default `LDAP`
 *   2. **LDAP sub-OU** — optional, LDAP only, {@see dnIsBelow()}
 *   3. **name pattern** — optional, and the *only* filter available for an
 *      OIDC or Database group, which is how `user_oidc` solves the same
 *      problem for itself (`--group-whitelist-regex`)
 *
 * With neither optional filter set, every group from a listed backend
 * qualifies.
 *
 * ## Why the sub-OU is a suffix match and not a DN query
 *
 * The *overall* set of LDAP groups Nextcloud knows is already chosen in
 * Nextcloud's own LDAP settings — **Directory Settings → Base Group Tree**
 * ("the base DN of LDAP, from where all groups can be reached") plus the
 * Groups tab's filter, which "determine which groups will be available in
 * Nextcloud". A customer who only ever wants one subtree sets it there and
 * needs nothing from us.
 *
 * `SETTING_LDAP_OU` serves the other customer: all LDAP groups in Nextcloud
 * for sharing, but only a subtree as teams. That needs the group's DN, which
 * **is not available through OCP**. It is available from
 * `OCA\User_LDAP\Mapping\GroupMapping::getDNByName()`, a plain lookup against
 * user_ldap's own mapping table with no LDAP round trip — the same kind of
 * guarded, lazily-resolved dependency TeamHub already takes on Circles'
 * `SyncService` and `FederatedUserService`. {@see groupDn()} degrades to null
 * when it is unavailable, and {@see ldapDnAvailable()} exists so the admin UI
 * can refuse to offer a filter that cannot run: a filter that silently stops
 * filtering would provision teams for the groups the customer excluded.
 */
class IdpTeamService {

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    /** Nothing is detected and nothing is created. The default. */
    public const MODE_OFF = 'off';

    /** Qualifying groups are offered to administrators to decide. */
    public const MODE_REVIEW = 'review';

    /** Qualifying groups get a team without being asked. */
    public const MODE_AUTO = 'auto';

    public const MODES = [self::MODE_OFF, self::MODE_REVIEW, self::MODE_AUTO];

    public const SETTING_MODE            = 'idpTeamsMode';
    public const SETTING_BACKENDS        = 'idpTeamsBackends';
    public const SETTING_LDAP_OU         = 'idpTeamsLdapOu';
    public const SETTING_PATTERN         = 'idpTeamsPattern';
    public const SETTING_TEMPLATE        = 'idpTeamsTemplate';
    public const SETTING_PROFILE         = 'idpTeamsProfile';
    public const SETTING_SERVICE_ACCOUNT = 'idpTeamsServiceAccount';
    public const SETTING_ON_GROUP_GONE   = 'idpTeamsOnGroupGone';

    /**
     * The template and policy profile a directory group's team gets when the
     * administrator has not chosen (v4.11.34). Both are stated in the picker
     * rather than offered as an empty "whatever the default is" option — see
     * {@see template()} and {@see profile()} for why that option had to go.
     */
    public const DEFAULT_TEMPLATE = 'collaboration';
    public const DEFAULT_PROFILE  = 'internal';

    /** What happens to a team whose source group left the directory. */
    /**
     * Archive the team, then delete it — regardless of the Archive tab's own
     * `archiveBeforeDelete`. Choosing this here *is* the instruction to
     * archive, so it routes through `ArchiveService::produceTeamArchive()`
     * and does not consult that switch.
     */
    public const GONE_ARCHIVE_THEN_DELETE = 'archive_then_delete';

    /** Delete it, with no archive. */
    public const GONE_DELETE = 'delete';

    public const GONE_MODES = [self::GONE_ARCHIVE_THEN_DELETE, self::GONE_DELETE];

    /**
     * The backend name user_ldap reports. `Group_Proxy::getBackendName()`
     * returns this literal; it is the registered group backend, so it is what
     * `IGroup::getBackendNames()` carries for every LDAP group.
     */
    public const BACKEND_LDAP = 'LDAP';

    /** Nextcloud's own group backend — what an OIDC-provisioned group gets. */
    public const BACKEND_DATABASE = 'Database';

    /** Default: LDAP only. An OIDC group needs a pattern, deliberately. */
    public const DEFAULT_BACKENDS = [self::BACKEND_LDAP];

    /**
     * user_ldap's name → DN mapper. String class name with no leading
     * backslash, resolved lazily — the same convention as the Circles
     * dependencies in GroupMirrorSyncService.
     */
    private const LDAP_GROUP_MAPPING = 'OCA\User_LDAP\Mapping\GroupMapping';

    /** A pattern longer than this is refused rather than compiled. */
    private const MAX_PATTERN_LENGTH = 512;

    public function __construct(
        private IConfig            $config,
        private IGroupManager      $groupManager,
        private ContainerInterface $container,
        private LoggerInterface    $logger,
    ) {
    }

    // ------------------------------------------------------------------
    // Reading the rule
    // ------------------------------------------------------------------

    public function mode(): string {
        $mode = $this->config->getAppValue(Application::APP_ID, self::SETTING_MODE, self::MODE_OFF);
        return in_array($mode, self::MODES, true) ? $mode : self::MODE_OFF;
    }

    public function isEnabled(): bool {
        return $this->mode() !== self::MODE_OFF;
    }

    /**
     * What becomes of a team whose source group left the directory.
     *
     * Both choices delete; the default is the one that keeps the contents,
     * so an unset or unrecognised value archives first rather than deleting
     * outright. The old `keep_and_report` value is deliberately no longer
     * accepted — it reads as the default here, which still archives.
     */
    public function onGroupGone(): string {
        $value = $this->config->getAppValue(
            Application::APP_ID,
            self::SETTING_ON_GROUP_GONE,
            self::GONE_ARCHIVE_THEN_DELETE,
        );
        return in_array($value, self::GONE_MODES, true) ? $value : self::GONE_ARCHIVE_THEN_DELETE;
    }

    /**
     * Backend names that qualify, stored comma-separated.
     *
     * @return list<string>
     */
    public function backends(): array {
        $raw = trim($this->config->getAppValue(
            Application::APP_ID,
            self::SETTING_BACKENDS,
            implode(',', self::DEFAULT_BACKENDS),
        ));
        if ($raw === '') {
            return [];
        }
        $names = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $n): bool => $n !== ''));
        return array_values(array_unique($names));
    }

    /** The configured sub-OU, normalised, or '' when unset or unusable. */
    public function ldapOu(): string {
        $raw = trim($this->config->getAppValue(Application::APP_ID, self::SETTING_LDAP_OU, ''));
        if ($raw === '' || !$this->ldapDnAvailable()) {
            // A filter that cannot be evaluated must not be treated as set —
            // see the class note. The admin UI refuses to offer it in the
            // first place; this is the server-side half of the same rule.
            return '';
        }
        return self::normaliseDn($raw);
    }

    public function pattern(): string {
        $raw = trim($this->config->getAppValue(Application::APP_ID, self::SETTING_PATTERN, ''));
        return strlen($raw) > self::MAX_PATTERN_LENGTH ? '' : $raw;
    }

    public function serviceAccount(): string {
        return trim($this->config->getAppValue(Application::APP_ID, self::SETTING_SERVICE_ACCOUNT, ''));
    }

    /**
     * The template a directory group's team is built from.
     *
     * v4.11.34 — **Collaboration, never empty.** The picker used to carry a
     * *The default template* option with an empty value, which told the
     * administrator nothing (there is no such thing as a default template)
     * and resolved through {@see TeamAdoptionDecisionService::DEFAULT_TEMPLATE}
     * two layers down. The default is now stated in the picker and is the
     * same `collaboration` it always resolved to. An empty stored value —
     * what every instance that never touched the picker has — reads as the
     * default too, so the panel and the provisioner agree.
     */
    public function template(): string {
        $value = trim($this->config->getAppValue(Application::APP_ID, self::SETTING_TEMPLATE, self::DEFAULT_TEMPLATE));
        return $value !== '' ? $value : self::DEFAULT_TEMPLATE;
    }

    /**
     * The policy profile the team is classified with.
     *
     * v4.11.34 — **Internal, never empty.** The picker used to carry
     * *Whatever the template sets*, and on a default install the template
     * sets nothing: `teamhub_team_template.default_profile_key` is not
     * seeded, so `PolicyService::defaultProfileForTemplate()` answered null
     * and the team came out **unclassified**. An option whose effect is "no
     * policy at all" must not be the one an administrator lands on.
     */
    public function profile(): string {
        $value = trim($this->config->getAppValue(Application::APP_ID, self::SETTING_PROFILE, self::DEFAULT_PROFILE));
        return $value !== '' ? $value : self::DEFAULT_PROFILE;
    }

    // ------------------------------------------------------------------
    // Applying the rule
    // ------------------------------------------------------------------

    /**
     * Does this group qualify for a team under the current rule?
     *
     * Cheap tests first: most groups on an instance qualify for nothing, and
     * this runs from a listener on every group membership change.
     */
    public function qualifies(string $groupId): bool {
        if ($groupId === '' || !$this->isEnabled()) {
            return false;
        }
        $group = $this->groupManager->get($groupId);
        return $group !== null && $this->qualifiesGroup($group);
    }

    public function qualifiesGroup(IGroup $group): bool {
        if (!$this->isEnabled()) {
            return false;
        }

        $backends = $this->backends();
        if ($backends === []) {
            // No backend listed means no group can qualify. Treating it as
            // "any backend" would turn an emptied setting into a rule that
            // provisions a team for every group on the instance.
            return false;
        }
        if (array_intersect($group->getBackendNames(), $backends) === []) {
            return false;
        }

        $pattern = $this->pattern();
        if ($pattern !== '' && !$this->matchesPattern($group->getGID(), $pattern)) {
            return false;
        }

        $ou = $this->ldapOu();
        if ($ou !== '') {
            $dn = $this->groupDn($group->getGID());
            if ($dn === null || !self::dnIsBelow($dn, $ou)) {
                // No DN and a sub-OU filter set means "cannot prove it belongs"
                // — which must read as "does not qualify". An OIDC group has no
                // DN at all and is excluded here by design: it is selected by
                // pattern or not at all.
                return false;
            }
        }

        return true;
    }

    /**
     * Every group that qualifies right now. The sweep's input.
     *
     * @return list<string>
     */
    public function qualifyingGroupIds(): array {
        if (!$this->isEnabled()) {
            return [];
        }
        $ids = [];
        // search('') enumerates every group the backends know. Expensive on a
        // large directory, which is why this is the hourly backstop and the
        // listener carries the normal case.
        foreach ($this->groupManager->search('') as $group) {
            try {
                if ($this->qualifiesGroup($group)) {
                    $ids[] = $group->getGID();
                }
            } catch (\Throwable $e) {
                // One unreadable group must not stop the sweep.
                $this->logger->warning('[TeamHub][IdpTeams] could not evaluate a group', [
                    'groupId' => $group->getGID(),
                    'error'   => $e->getMessage(),
                    'app'     => Application::APP_ID,
                ]);
            }
        }
        return array_values(array_unique($ids));
    }

    // ------------------------------------------------------------------
    // The name pattern
    // ------------------------------------------------------------------

    /**
     * A stored pattern is either a delimited regex (`/^team-/i`) or a plain
     * prefix. A regex that does not compile is treated as "matches nothing"
     * and logged: the alternative is provisioning teams the customer never
     * asked for because their typo silently disabled the filter.
     */
    public function matchesPattern(string $groupId, string $pattern): bool {
        if ($pattern === '') {
            return true;
        }

        if (self::looksLikeRegex($pattern)) {
            $previous = set_error_handler(static fn (): bool => true);
            try {
                $result = preg_match($pattern, $groupId);
            } finally {
                set_error_handler($previous);
            }
            if ($result === false) {
                $this->logger->warning('[TeamHub][IdpTeams] group pattern is not a valid expression; nothing matches it', [
                    'app' => Application::APP_ID,
                ]);
                return false;
            }
            return $result === 1;
        }

        // Plain text: a case-insensitive prefix, which is what an
        // administrator who types "team-" means.
        return stripos($groupId, $pattern) === 0;
    }

    /** A delimited expression: same opening and closing delimiter, modifiers allowed. */
    private static function looksLikeRegex(string $pattern): bool {
        if (strlen($pattern) < 2) {
            return false;
        }
        $delimiter = $pattern[0];
        if (ctype_alnum($delimiter) || $delimiter === '\\' || ctype_space($delimiter)) {
            return false;
        }
        $closing = match ($delimiter) {
            '(' => ')',
            '[' => ']',
            '{' => '}',
            '<' => '>',
            default => $delimiter,
        };
        return strrpos($pattern, $closing) > 0;
    }

    // ------------------------------------------------------------------
    // The LDAP DN
    // ------------------------------------------------------------------

    /**
     * Is the user_ldap DN lookup usable on this instance? The admin UI asks
     * before offering the sub-OU filter.
     */
    public function ldapDnAvailable(): bool {
        return $this->ldapGroupMapping() !== null;
    }

    /**
     * This group's LDAP DN, or null when it has none or user_ldap cannot be
     * reached. A DB lookup against user_ldap's mapping table — no LDAP
     * traffic, so it is cheap enough to call per group in a sweep.
     */
    public function groupDn(string $groupId): ?string {
        $mapping = $this->ldapGroupMapping();
        if ($mapping === null || $groupId === '') {
            return null;
        }
        try {
            $dn = $mapping->getDNByName($groupId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][IdpTeams] LDAP DN lookup failed', [
                'groupId' => $groupId,
                'error'   => $e->getMessage(),
                'app'     => Application::APP_ID,
            ]);
            return null;
        }
        // getDNByName() answers false for a group it does not map — every
        // Database-backend group, for one.
        return is_string($dn) && $dn !== '' ? $dn : null;
    }

    /**
     * Normalise a DN for comparison: collapse the whitespace directories are
     * inconsistent about, and lowercase it. DN attribute types and the
     * commas between them are case-insensitive, and `CN=A, OU=B` and
     * `CN=A,OU=B` are the same name.
     */
    public static function normaliseDn(string $dn): string {
        $dn = trim($dn);
        // Spaces around the separators only. Spaces *inside* a value are
        // significant and left alone.
        $dn = (string)preg_replace('/\s*,\s*/', ',', $dn);
        $dn = (string)preg_replace('/\s*=\s*/', '=', $dn);
        return mb_strtolower($dn);
    }

    /**
     * Does `$dn` sit at or below `$base`?
     *
     * A suffix match on the normalised forms, with the boundary checked so
     * `OU=Teams,DC=x` does not swallow `OU=NotTeams,DC=x`. No DN parser: the
     * question is containment in a tree whose levels are comma-separated, and
     * a parser would add a dependency and a new way to be wrong.
     */
    public static function dnIsBelow(string $dn, string $base): bool {
        $dn   = self::normaliseDn($dn);
        $base = self::normaliseDn($base);
        if ($dn === '' || $base === '') {
            return false;
        }
        if ($dn === $base) {
            return true;
        }
        $suffix = ',' . $base;
        return str_ends_with($dn, $suffix);
    }

    /** @return object|null user_ldap's GroupMapping, or null when unavailable */
    private function ldapGroupMapping(): ?object {
        if (!class_exists(self::LDAP_GROUP_MAPPING)) {
            return null;
        }
        try {
            $mapping = $this->container->get(self::LDAP_GROUP_MAPPING);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][IdpTeams] user_ldap GroupMapping unavailable', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
            return null;
        }
        return method_exists($mapping, 'getDNByName') ? $mapping : null;
    }
}
