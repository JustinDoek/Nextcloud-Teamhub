/**
 * TeamHub — UI token constants for JavaScript consumers.
 *
 * Mirror of the CSS custom properties in src/styles/widget-tokens.css.
 * KEEP THESE TWO FILES IN SYNC.
 *
 * Why two files?
 * vue-material-design-icons takes a numeric `:size="N"` prop at compile
 * time; it cannot read a CSS custom property. Every component that uses
 * an MDI icon therefore needs a JavaScript-visible constant. The CSS
 * variables in widget-tokens.css remain the source of truth for scoped
 * styles (SVG width/height, inline sizing); this file mirrors the icon
 * scale for the Vue prop side. Change a value here, change it there.
 *
 * Usage:
 *   import { ICON_BODY, ICON_NAV } from '@/constants/uiTokens.js'
 *   <Plus :size="ICON_BODY" />
 *
 * See gui.md § 10 for the audit that motivated the shared scale.
 */

// ── Icon size scale ─────────────────────────────────────────────────
// Role-named so consumers pick by intent, not by raw px. v4.10.10: NC 35's
// foundations put icons at 20px, so body / toolbar / nav share one value;
// the names survive so a call site still says what the icon is for.

/** Inline with supporting text (13px). Hint icons, chip glyphs. */
export const ICON_INLINE = 16

/** The NC icon size: buttons, rows, tabs, navigation, action menus. */
export const ICON_BODY = 20

/** Alias of ICON_BODY — kept so existing tab bars read as before. */
export const ICON_TOOLBAR = 20

/** Alias of ICON_BODY — NcAppNavigationItem's canonical size. */
export const ICON_NAV = 20

/** Decorative tile / step glyph. */
export const ICON_LARGE = 32

/** Section illustration (an NcEmptyContent inside a panel). */
export const ICON_XL = 48

/** NcEmptyContent hero icon. Only used inside the empty-state slot. */
export const ICON_HERO = 64

// ── Avatar scale (v4.10.7) ──────────────────────────────────────────
// Mirrors --th-avatar-sm / -md / -lg in widget-tokens.css — NC's 24 / 32 / 44.

/** Table cell, chip, compact person row. */
export const AVATAR_SM = 24

/** Picker result, member row. */
export const AVATAR_MD = 32

/** Header / profile. */
export const AVATAR_LG = 44
