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
// Role-named so consumers pick by intent, not by raw px.

/** Inline with meta text (12px body). Used for inline hint icons. */
export const ICON_INLINE = 14

/** Inline with body text (14px). The default for most button icons. */
export const ICON_BODY = 16

/** Toolbar / tab bar. Slightly larger than body so tab targets read at a glance. */
export const ICON_TOOLBAR = 18

/** NcAppNavigationItem canonical size. Matches NC core's sidebar icon size. */
export const ICON_NAV = 20

/** NcEmptyContent hero icon. Only used inside the empty-state slot. */
export const ICON_HERO = 64
