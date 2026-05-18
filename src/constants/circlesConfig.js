/**
 * Canonical Nextcloud Circles config bit values.
 *
 * Mirror of `lib/Constants/CirclesConfig.php` for the Vue side.
 * KEEP THESE TWO FILES IN SYNC. The numeric values are dictated by the
 * Circles app; do not invent new values.
 *
 * Only the bits in MANAGED_BITS are exposed as user toggles in the
 * Manage Team / Create Team UI. System bits (CFG_SINGLE, CFG_NO_OWNER,
 * CFG_HIDDEN, CFG_SYSTEM, CFG_BACKEND, etc.) must never be set on a
 * source=16 user team — doing so corrupts the team's visibility,
 * editability, and message-posting behaviour.
 */

export const CFG_SINGLE        = 1
export const CFG_PERSONAL      = 2
export const CFG_SYSTEM        = 4
export const CFG_VISIBLE       = 8
export const CFG_OPEN          = 16
export const CFG_INVITE        = 32
export const CFG_REQUEST       = 64
export const CFG_FRIEND        = 128
export const CFG_PROTECTED     = 256
export const CFG_NO_OWNER      = 512
export const CFG_HIDDEN        = 1024
export const CFG_BACKEND       = 2048
export const CFG_LOCAL         = 4096
export const CFG_ROOT          = 8192
export const CFG_CIRCLE_INVITE = 16384
export const CFG_FEDERATED     = 32768
export const CFG_MOUNTPOINT    = 65536
export const CFG_APP           = 131072

/**
 * Bits TeamHub exposes as user toggles. Writes are masked to these only.
 */
export const MANAGED_BITS =
      CFG_VISIBLE   //    8
    | CFG_OPEN      //   16
    | CFG_INVITE    //   32
    | CFG_REQUEST   //   64
    | CFG_PROTECTED // 256
    | CFG_ROOT      // 8192 — same bit Contacts uses for "Prevent sub-membership"
// = 8568
