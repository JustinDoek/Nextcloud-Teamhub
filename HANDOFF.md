# TeamHub — Handoff Document

> Read this file at the start of every session alongside SKILLS.md.
> It describes current state, open issues, and the immediate next priority.

---

## Current version: 3.18.0

Released: 2026-04-29

---

## What was built this session (3.18.0)

This session had two goals: add an Audit tab info banner, and improve the iframe embed layer. The iframe work turned into a multi-iteration diagnostic and fix cycle.

### 1. Audit tab — always-visible info banner

A permanent informational banner now sits at the top of the Audit tab (above the existing "Activity app disabled" warning). It explains:

- External activity (member, file, share events) is mirrored hourly by `AuditMirrorJob`
- New events may take up to an hour to surface
- TeamHub-internal actions (team creation, join requests) are recorded immediately

Implementation: new `audit-banner--info` CSS variant + `audit-banner__head` flex helper. Uses `InformationOutline` icon. No dismiss — deliberately permanent.

### 2. Iframe embed overhaul — `AppEmbed.vue` + `TeamView.vue`

Six separate fixes applied across multiple test iterations.

#### Security hardening
- **Origin-aware sandbox**: cross-origin `iframe_url` integrations get `sandbox="allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox"`. No `allow-same-origin` (blocks cookie/localStorage abuse), no `allow-top-navigation` (blocks parent-window phishing). Same-origin built-ins unsandboxed — DOM access required for CSS injection.
- **`referrerpolicy="strict-origin-when-cross-origin"`** on every iframe.
- **`allow=""`** (empty Permissions Policy) on every iframe — denies camera, mic, geolocation, payment, USB etc.
- **`rel="noopener noreferrer"`** on "Open in new tab" anchor.
- **Frontend URL re-validation** in `TeamView.menuItemUrl()`: only `https://`, `/apps/`, `/index.php/` accepted. Returns `''` otherwise, triggers error state. `console.warn` with `registry_id` kept as security signal.

#### UX additions
- Loading skeleton (`NcLoadingIcon`) until `onLoad` fires.
- Reload button — bumps `:key`, forces full iframe teardown+recreate.
- Error state (`AlertCircleOutline`) when `iframeSrc` is empty.

#### Bug fixes from live testing
- **Removed `loading="lazy"`**: browser defers `onLoad` when set, breaking injection timing entirely.
- **Fixed `clearRetryTimers()` crash** (`TypeError: Cannot read properties of undefined (reading 'forEach')`): Vue 2 unreliable with underscore-prefixed `data()` properties. Added `Array.isArray()` guard.
- **Stopped MutationObserver feedback loop**: `injectCss()` was removing+re-appending `<style>` on every call, each DOM mutation re-triggering the observer. Now bails early if tag already exists.

#### NC 32 selector updates (from live DOM outerHTML capture)

NC 32 renamed key DOM IDs — our old CSS missed every one:

| Old (targeted) | NC 32 (actual) |
|---|---|
| `#app-navigation` | `#app-navigation-vue` |
| `#app-sidebar` | `#app-sidebar-vue` |
| `#content` | `#content-vue` |
| `.app-menu-main` | `#app-menu-container` |

All old + new selectors now listed. NC 32 also drives layout offsets from `--body-container-margin` / `--body-container-radius` CSS variables — now zeroed on `:root`.

**Third-party "Custom Menu" app** (`side_menu`) injects `#side-menu-container` / `.cm--topwidemenu` — now explicitly hidden.

#### Viewport height fix
`.app-embed__viewport` had `height: 85%` — cut off bottom of Talk's chat textarea. Replaced with `flex: 1 1 auto; min-height: 0`.

#### Share dialog / file details panel fix
Two root causes were blocking NC modals and panels:
1. We hid `#app-sidebar-vue` globally — NC apps use this for Files share panel, file details, Calendar event editor, Deck card details. **Removed from hide rules.** Sidebar starts closed on page load so no chrome shows without user action.
2. `position: fixed; inset: 0` on `#content-vue` flattened the stacking context, trapping modals. Replaced with `width/height: 100%` only.

### Modified files

| File | Change |
|---|---|
| `src/components/AppEmbed.vue` | `buildCss()` rewritten for NC 32, sidebar fix, variable resets, viewport height, `loading="lazy"` removed, `clearRetryTimers()` guarded, observer loop fixed, security attributes, loading/reload/error UX |
| `src/components/TeamView.vue` | `menuItemUrl()` frontend URL re-validation |
| `src/components/AdminSettings.vue` | Audit tab info banner + `--info` CSS variant |
| `appinfo/info.xml` | Version bump |
| `package.json` | Version bump |

---

## Open issues

- **NC app chrome stripping is best-effort**: CSS injection is inherently fragile — NC updates can introduce new selectors. Fix is always to capture live DOM (outerHTML) and update `buildCss()`.
- **Cross-origin external integrations show full app chrome**: Browser security limit. The embedded app must implement its own embedded mode (e.g. `?embedded=1`). Document in `developers.md`.
- **`teamhub_integration_registry` rename migration**: 28-char table name exceeds NC's 27-char DBAL limit. Needs `RENAME TABLE` migration + mapper/service/controller updates. Long-deferred.
- **Activity-app dependency for audit mirror**: NC Activity app disabled → only TeamHub-internal events captured. Audit tab banners this already.
- **NC Teams UI member-removed flow**: `member_remove` activity row is mirrored but unverified that NC Teams UI always writes it on circles 33.0.0.
- **Telemetry receiver at `tldr.host`**: Needs updating for v3.8.0 fields. Deferred.
- **`sendMessage` signature assumption**: `TalkService::postChatMessage` calls `ChatManager::sendMessage` with 9 args. Future Talk versions may break this.

---

## Immediate next priority

1. **Verify iframe fixes on live install** — confirm:
   - Talk chat textarea fully visible (no bottom cutoff)
   - Files share dialog opens and works
   - Files right details panel opens correctly
   - Calendar event editor opens correctly
   - Deck card details open correctly
   - No NC navigation chrome in any built-in tab
2. **`teamhub_integration_registry` rename migration** — pick up after iframe verification.
3. **Telemetry receiver update at `tldr.host`** — still deferred.

---

## Session history (recent)

| Version | Date | Summary |
|---|---|---|
| 3.18.0 | 2026-04-29 | Audit info banner; iframe overhaul: NC 32 selectors, sidebar/modal fix, viewport height, security hardening, UX additions, bug fixes |
| 3.16.0 | 2026-04-28 | Admin governance: audit log table, hourly mirror job, admin Audit tab, configurable retention |
| 3.15.0 | 2026-04-28 | Calendar reload fix, meeting notes write access, appName errors, MessageStream cleanup |
| 3.14.0 | 2026-04-28 | Team meeting action, Talk scheduling, agenda chat post, clickable calendar events |
| 3.13.0 | 2026-04-24 | Membership integrity overhaul, members widget, leave team button, security fixes |
| 3.9.0  | 2026-04-21 | Boolean column compatibility fix (SMALLINT) |
| 3.8.0  | 2026-04-21 | Anonymous telemetry expansion |

---

## Key technical reminders

### Iframe CSS injection
- **Always cover both old and NC 32 selectors** — check `#*-vue` variants alongside old `#*` IDs.
- **Never hide `#app-sidebar-vue`** — NC apps use it for share panels, details, editors. It starts closed.
- **Never use `position: fixed` on `#content-vue`** — flattens stacking context, traps modals.
- **Never use `overflow: hidden` on `html/body`** inside the iframe — clips internal scroll areas.
- **Zero NC 32 CSS variables on `:root`**: `--body-container-margin` and `--body-container-radius`.
- **`loading="lazy"` is banned on the iframe** — defers `onLoad`, breaks injection.
- **Observer loop prevention**: `injectCss()` bails if style tag already present.
- **`clearRetryTimers()` needs `Array.isArray()` guard** — Vue 2 unreliable with `_`-prefixed data.
- **Custom Menu app** hides via `#side-menu-container`, `.cm--topwidemenu`, `.cm-standardmenu`.

### Security
- **Iframe URL validated twice**: registration (`IntegrationService`) + render (`TeamView.menuItemUrl()`).
- **Sandbox is origin-aware**: no `allow-same-origin`, no `allow-top-navigation` for cross-origin.
- **`console.warn` in `TeamView.menuItemUrl()`** is a kept security signal — do not strip.

### General
- **Circles schema**: `user_id` in `circles_member` is a label — always use `single_id` → `circles_circle.unique_id` for JOINs on non-user member types.
- **Widget location**: Members widget is inside `TeamWidgetGrid.vue`, not `MembersWidget.vue`.
- **DBAL constraints**: `Types::BOOLEAN` + `notnull` fails on MySQL/MariaDB — use `Types::SMALLINT`. Table name non-prefix ≤27 chars.
- **Audit immutability**: `AuditLogMapper` — only insert / read / bulk-purge. No `update()` or single-row `delete()`.
- **Color variables**: `--color-error-text`, `--color-success-text`, `--color-warning-text` for text only.
- **`@nextcloud/vue` appName**: inject via `webpack.DefinePlugin`, not `window.appName`.
- **`oc_users` not `oc_accounts`**: display names in `oc_users.displayname`.
- **Soft-deleted calendar objects**: filter `NOT LIKE '%-deleted.ics'` on `co.uri`.
- **Talk LOCATION field**: Talk detects meetings via `LOCATION` iCal field — URL must go there.
