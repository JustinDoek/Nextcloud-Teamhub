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

    /** App-managed circle. DO NOT SET on user teams. */
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
          self::CFG_VISIBLE     //   8
        | self::CFG_OPEN        //  16
        | self::CFG_INVITE      //  32
        | self::CFG_REQUEST     //  64
        | self::CFG_PROTECTED;  // 256
    // = 376

    /**
     * System bits that must never appear on a source=16 user team.
     * Used by the integrity check and the migration to identify corruption.
     */
    public const SYSTEM_BITS_FORBIDDEN_ON_USER_TEAMS =
          self::CFG_SINGLE      //    1
        | self::CFG_SYSTEM      //    4
        | self::CFG_NO_OWNER    //  512
        | self::CFG_HIDDEN      // 1024
        | self::CFG_BACKEND     // 2048
        | self::CFG_APP;        // 131072
    // = 134669

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
