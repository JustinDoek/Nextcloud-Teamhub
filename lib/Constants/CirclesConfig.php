<?php
declare(strict_types=1);

namespace OCA\TeamHub\Constants;

/**
 * Canonical Nextcloud Circles config bit values.
 *
 * These match the constants in `OCA\Circles\Model\Circle::CFG_*` (Circles app).
 * Defined here so TeamHub never has to reference Circles' internal classes
 * directly, and to provide a single source of truth that both PHP and JS
 * sides of TeamHub agree on.
 *
 * **DO NOT INVENT NEW VALUES.** The numeric values are dictated by Circles.
 *
 * **DO NOT TOUCH SYSTEM-FLAGS** (CFG_SINGLE, CFG_SYSTEM, CFG_NO_OWNER,
 * CFG_HIDDEN, CFG_BACKEND, CFG_ROOT, CFG_APP, CFG_LOCAL, CFG_MOUNTPOINT)
 * on user-created teams (source=16). These bits are managed internally by
 * Circles and toggling them on a regular team corrupts its state — for
 * example, CFG_SINGLE marks a circle as a personal user-identity circle,
 * causing Contacts to hide it and the Circles API to refuse edits.
 *
 * The bits TeamHub exposes in the UI are MANAGED_BITS below — only those.
 */
final class CirclesConfig {
    /** Personal user-identity circle (system-managed). DO NOT SET on teams. */
    public const CFG_SINGLE = 1;

    /** Personal flag (Circles default on new teams). */
    public const CFG_PERSONAL = 2;

    /** System-managed circle (system-managed). DO NOT SET on user teams. */
    public const CFG_SYSTEM = 4;

    /** Team is discoverable in public listings. User-facing toggle. */
    public const CFG_VISIBLE = 8;

    /** Anyone can join without invitation. User-facing toggle. */
    public const CFG_OPEN = 16;

    /** Invited members must confirm. User-facing toggle. */
    public const CFG_INVITE = 32;

    /** Join requests require moderator approval. User-facing toggle. */
    public const CFG_REQUEST = 64;

    /** Friend-of-friend visibility (rare). */
    public const CFG_FRIEND = 128;

    /** Password-protected file shares. User-facing toggle. */
    public const CFG_PROTECTED = 256;

    /** Team has no personal owner (system-managed). DO NOT SET on user teams. */
    public const CFG_NO_OWNER = 512;

    /** Hidden from listings (system-managed). DO NOT SET on user teams. */
    public const CFG_HIDDEN = 1024;

    /** Backend-managed circle (system-managed). DO NOT SET on user teams. */
    public const CFG_BACKEND = 2048;

    /** Local-only circle. */
    public const CFG_LOCAL = 4096;

    /** Top-level federated circle. */
    public const CFG_ROOT = 8192;

    /** Circle can be invited into other circles. */
    public const CFG_CIRCLE_INVITE = 16384;

    /** Federated members allowed. */
    public const CFG_FEDERATED = 32768;

    /** Used as a mountpoint. */
    public const CFG_MOUNTPOINT = 65536;

    /**
     * Circle is claimed by a Nextcloud app.
     *
     * v4.5.37 — the old note here read "DO NOT SET on user teams", which was
     * only ever half true: TeamHub must not set it, but other apps legitimately
     * do, on teams that are otherwise perfectly ordinary. See APP_OWNED_BITS.
     */
    public const CFG_APP = 131072;

    // -------------------------------------------------------------------------
    // Derived sets — referenced throughout TeamHub
    // -------------------------------------------------------------------------

    /**
     * Bits that TeamHub exposes as user-facing toggles in the Manage Team UI.
     * `updateTeamConfig()` masks writes to these bits only — every other bit
     * in the existing config value is preserved verbatim.
     *
     * Note: CFG_VISIBLE is included here (not in SYSTEM_BITS) because it is a
     * legitimate user-facing visibility toggle, distinct from the system-managed
     * CFG_HIDDEN flag.
     */
    public const MANAGED_BITS =
          self::CFG_VISIBLE   //    8
        | self::CFG_OPEN      //   16
        | self::CFG_INVITE    //   32
        | self::CFG_REQUEST   //   64
        | self::CFG_PROTECTED // 256
        | self::CFG_ROOT;     // 8192 — Contacts uses CFG_ROOT to implement
                              //        "Prevent teams from being a member of
                              //        another team". TeamHub syncs with this.
    // = 8568

    /**
     * System bits that must never appear on a source=16 user team.
     * Used by the integrity check and the migration to identify corruption.
     *
     * v4.5.35 — **CFG_PERSONAL joined this set, and it is the reason the Wiki
     * could not be enabled on one of Justin's teams.**
     *
     * Circles' `FederatedItems\CircleConfig::verify()` builds a rejection list
     * from `Circle::$DEF_CFG_CORE_FILTER = [1, 2, 4]` and refuses the *entire*
     * update when any of those bits appears in the proposed config:
     *
     *     $confirmed = true;
     *     foreach ($listing as $item) {
     *         if ($circle->isConfig($item, $config)) { $confirmed = false; }
     *     }
     *     if (!$confirmed || $config > Circle::$DEF_CFG_MAX) {
     *         throw new FederatedItemBadRequestException('Configuration value is not valid');
     *     }
     *
     * They are not masked out — their presence is fatal. So a circle carrying
     * bit 1, 2 or 4 can never have its config written through Circles' own API
     * again. TeamHub does not notice, because `updateTeamConfig` writes
     * `circles_circle` by raw SQL; Collectives does, because
     * `flagCircleAsAppManaged` goes through `CirclesManager::flagAsAppManaged`
     * → `updateConfig`.
     *
     * Two of the three core-filter bits were already listed here. CFG_PERSONAL
     * was left out because the 3.39.1 migration read it as "Circles' own
     * default, preserve verbatim" — true, and harmless right up until an app
     * tries to update that circle. It is also meaningless on a multi-member
     * team: it means owner-visibility-only.
     *
     * v4.5.37 — **CFG_APP left this set. It was never corruption.**
     *
     * The integrity check reported twelve of Justin's teams as corrupt, all of
     * them for bit 131072 alone, on an instance where nothing was misbehaving.
     * Eleven had a collective; the twelfth had had one deleted. That is exactly
     * the footprint of Collectives' `flagCircleAsAppManaged` →
     * `CirclesManager::flagAsAppManaged`, which sets CFG_APP on purpose to lock
     * the circle against user tampering — and which never clears it on
     * `deleteCollective(…, deleteCircle: false)`, which is why the twelfth team
     * still carried it. Nothing in TeamHub can set the bit: every write to
     * `circles_circle.config` either zeroes it, clears bits, or masks through
     * MANAGED_BITS, and 131072 is in none of those.
     *
     * The decisive argument was already written down one file away.
     * `CollectivesService::forbiddenConfigBitsOnTeam()` (v4.5.35) deliberately
     * omits CFG_APP, because Circles' `CircleConfig::verify()` rejects on
     * `$DEF_CFG_CORE_FILTER` (1/2/4) and `$DEF_CFG_SYSTEM_FILTER`
     * (512/1024/2048) and CFG_APP is in neither. So the six bits below break
     * Circles' own config API and CFG_APP does not — two lists in one codebase
     * disagreeing about one bit, with the reason recorded next to the one that
     * had it right.
     *
     * The cost of the disagreement was not just a noisy report: `resetTeamConfig`
     * clears this whole mask, so Repair was **stripping another app's claim on
     * the circle** — and v4.5.35's enable-failure message points admins straight
     * at that button.
     */
    public const SYSTEM_BITS_FORBIDDEN_ON_USER_TEAMS =
          self::CFG_SINGLE      //    1
        | self::CFG_PERSONAL    //    2 — see the v4.5.35 note above
        | self::CFG_SYSTEM      //    4
        | self::CFG_NO_OWNER    //  512
        | self::CFG_HIDDEN      // 1024
        | self::CFG_BACKEND;    // 2048
    // = 3591. (The pre-4.5.35 comment here read "134669"; the value it
    // described was actually 134661, so the note was already wrong by 8.
    // Nothing consumed it — every caller uses the constant — but it is the
    // third stale comment found in this subsystem, so: verified by arithmetic.)

    /**
     * Bits that mark a circle as claimed by another Nextcloud app.
     *
     * Not corruption and not ours to clear. Reported by the integrity check as
     * information — an admin seeing an app-claimed team should know why, and
     * "which app" is a question only the claiming app can answer — but never
     * counted as an issue and never touched by reset or repair.
     *
     * Known setter: Collectives, via `CircleHelper::flagCircleAsAppManaged`
     * when a collective binds to the circle. Talk and Deck do not flag the
     * circles they attach to; they hold ACL rows instead.
     */
    public const APP_OWNED_BITS = self::CFG_APP; // = 131072

    /**
     * Legacy bit mask used by TeamHub <= 3.39.0 for `updateTeamConfig`.
     * Held here for the migration's decode step only — DO NOT use anywhere else.
     */
    public const LEGACY_MANAGED_BITS_PRE_3_39_1 = 1 | 2 | 4 | 16 | 512 | 1024; // = 1559

    /**
     * Decode a config integer that was written by TeamHub <= 3.39.0 using
     * the wrong bit values, returning the equivalent integer in correct
     * Circles encoding for the same admin intent.
     *
     * Preserves any bits OUTSIDE the legacy managed range (CFG_PERSONAL,
     * CFG_FRIEND, CFG_ROOT, CFG_FEDERATED, etc.) verbatim — those came from
     * Circles itself or from Contacts and must not be touched.
     */
    public static function migrateLegacyConfig(int $legacyConfig): int {
        // Legacy → real mapping (what the admin clicked → what bit they meant):
        //   bit 1   (legacy "OPEN")      → bit 16  CFG_OPEN
        //   bit 2   (legacy "INVITE")    → bit 32  CFG_INVITE
        //   bit 4   (legacy "REQUEST")   → bit 64  CFG_REQUEST
        //   bit 16  (legacy "PROTECTED") → bit 256 CFG_PROTECTED
        //   bit 512 (legacy "VISIBLE")   → bit 8   CFG_VISIBLE
        //   bit 1024 (legacy "SINGLE")   → DROP (was always-on UI hint, not a real setting)
        $intended = 0;
        if ($legacyConfig & 1)   $intended |= self::CFG_OPEN;
        if ($legacyConfig & 2)   $intended |= self::CFG_INVITE;
        if ($legacyConfig & 4)   $intended |= self::CFG_REQUEST;
        if ($legacyConfig & 16)  $intended |= self::CFG_PROTECTED;
        if ($legacyConfig & 512) $intended |= self::CFG_VISIBLE;

        // Preserve every bit OUTSIDE the legacy managed range — these came from
        // Circles itself (CFG_PERSONAL default) or from Contacts (real bits that
        // TeamHub's old broken mask happened to leave alone).
        $preserved = $legacyConfig & ~self::LEGACY_MANAGED_BITS_PRE_3_39_1;

        return $preserved | $intended;
    }
}
