<?php
declare(strict_types=1);

namespace OCA\TeamHub\Constants;

/**
 * Canonical Nextcloud Circles member type values.
 *
 * These match `OCA\Circles\Model\Member::TYPE_*` in the Circles app. Defined
 * here so TeamHub never has to reference Circles' internal classes directly,
 * and — the reason this file exists — so there is exactly one place that says
 * what each number means.
 *
 * **DO NOT INVENT NEW VALUES.** The numeric values are dictated by Circles.
 *
 * ## v4.9.2 — why this file was added
 *
 * TeamHub carried two incompatible beliefs about `circles_member.user_type`,
 * both written down, in different subsystems:
 *
 *   - `PolicyField::EXTERNAL_MEMBERS` declared `user_type in (4 mail, 8 contact)`
 *   - `TelemetryService` and `TalkService` declared `user_type IN (1, 4)` to be
 *     "local + federated users", and `MemberService::inviteMembers()` mapped
 *     `'federated' => 4` and `'email' => 7`
 *
 * Circles settles it: **4 is TYPE_MAIL**, and **there is no type 7 at all.**
 * PolicyField was right; the rest of the app was wrong. The consequences were
 * live on the test instance, where `inviteTypes` had every type enabled:
 *
 *   - a "federated" invite asked Circles for a *mail* member
 *   - an "email" invite passed a type matching no constant
 *   - unique-member telemetry counted mail invitees as people
 *   - the Talk reconciler mapped type 4 to `federated_users` attendees, a row
 *     shape that member type never produces
 *
 * ## Federation is not a member type
 *
 * The misconception that drove all of it: there is no `TYPE_FEDERATED`. A
 * federated Nextcloud account is `TYPE_USER` with a **remote `instance`**.
 * `FederatedUserService::generateFederatedUser()` splits `alice@remote.tld` on
 * the last `@` via `extractIdAndInstance()` and stores `user_id='alice'`,
 * `instance='remote.tld'` — the type stays 1. Ask `isLocalInstance()` below,
 * never the type, when the question is "is this person on another server".
 *
 * @see CirclesConfig for the circle-level config bits, same pattern.
 */
final class CirclesMemberType {
    /** Circles' own single-entity type. Not used for team membership rows. */
    public const TYPE_SINGLE = 0;

    /** A Nextcloud account. Local OR federated — see isLocalInstance(). */
    public const TYPE_USER = 1;

    /** A Nextcloud group. */
    public const TYPE_GROUP = 2;

    /**
     * An email address invited into the team ("mail member" in Circles).
     *
     * This is what TeamHub's 'email' invite type produces. It is NOT a
     * federated user — that mistake is the reason this file exists.
     */
    public const TYPE_MAIL = 4;

    /** A contact from an address book. TeamHub does not offer these. */
    public const TYPE_CONTACT = 8;

    /** Another circle/team, nested as a member. */
    public const TYPE_CIRCLE = 16;

    /** A circle owned by a Nextcloud app rather than a person. */
    public const TYPE_APP = 10000;

    // -------------------------------------------------------------------------
    // Derived sets
    // -------------------------------------------------------------------------

    /**
     * Types that represent one identifiable person holding a member row.
     *
     * Excludes groups and nested teams, which expand to people via
     * `circles_membership`, and TYPE_APP, which is not a person at all.
     *
     * TYPE_MAIL and TYPE_CONTACT are people, but people **without an account
     * on any Nextcloud** — so they belong here only when the question is
     * "who is attached to this team", never when it is "how many accounts".
     * See PEOPLE_WITH_ACCOUNTS.
     */
    public const PEOPLE = [self::TYPE_USER, self::TYPE_MAIL, self::TYPE_CONTACT];

    /**
     * Types backed by a real Nextcloud account, local or federated.
     *
     * This is the set a per-seat licence metric wants: TYPE_USER covers both
     * local and federated accounts, because federation lives in `instance`.
     * Before v4.9.2 the telemetry query used `[1, 4]` believing 4 added
     * federated users; it added mail invitees, who hold no account anywhere.
     */
    public const PEOPLE_WITH_ACCOUNTS = [self::TYPE_USER];

    /**
     * The two "external member" types the policy layer watches.
     *
     * Matches `PolicyField::EXTERNAL_MEMBERS`, whose declared source
     * (`user_type in (4 mail, 8 contact)`) was the one correct reading of
     * these numbers in the codebase before v4.9.2.
     */
    public const EXTERNAL = [self::TYPE_MAIL, self::TYPE_CONTACT];

    /** Types that expand into other members rather than naming one person. */
    public const CONTAINERS = [self::TYPE_GROUP, self::TYPE_CIRCLE];

    // -------------------------------------------------------------------------
    // Locality
    // -------------------------------------------------------------------------

    /**
     * Whether a `circles_member.instance` value refers to this server.
     *
     * Mirrors Circles' `ConfigService::isLocalInstance()`: an empty instance is
     * local, and so is one matching the internal or frontal instance name. Every
     * member row created on a single-instance deployment carries `''`.
     *
     * Callers that can reach Circles' ConfigService should prefer it, because it
     * knows the instance's configured aliases. This exists for the query layer,
     * where the comparison happens in SQL against a value we already hold, and
     * for callers that must not depend on the Circles container being resolvable.
     *
     * @param string $instance      the member row's instance column
     * @param string[] $localAliases extra names this server answers to, when known
     */
    public static function isLocalInstance(string $instance, array $localAliases = []): bool {
        if ($instance === '') {
            return true;
        }

        $instance = strtolower($instance);
        foreach ($localAliases as $alias) {
            if ($instance === strtolower((string)$alias)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a member row describes a federated (remote) Nextcloud account.
     *
     * The only correct way to ask this question. `user_type` alone cannot
     * answer it, and the type that was previously used to answer it (4) means
     * something else entirely.
     *
     * @param int $userType        the member row's user_type column
     * @param string $instance     the member row's instance column
     * @param string[] $localAliases extra names this server answers to, when known
     */
    public static function isFederatedUser(int $userType, string $instance, array $localAliases = []): bool {
        return $userType === self::TYPE_USER
            && !self::isLocalInstance($instance, $localAliases);
    }
}
