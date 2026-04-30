# TeamHub Roadmap

> Living planning document. Updated whenever scope or sequencing changes.
> For per-session continuity see `HANDOFF.md`. For shipped changes see `CHANGELOG.md`.

**Last updated:** 2026-04-30 (planning session — no code changes)
**Current version:** 3.18.3

---

## Long-term mission roadmap

From the project Mission. Status as of 2026-04-30:

| Phase | Theme | Status |
|---|---|---|
| v2.5 | Codebase review, security baseline | ✅ Done — ongoing reinforcement (3.18 added iframe sandbox + URL re-validation) |
| v3 | API setup + API-driven development | ✅ Done — 104 routes, layered Controllers → Services → Mappers, integration registry |
| v4 | Optimize message stream | 🔜 In progress — sessions 3.23+ below |
| v5 | Admin settings | ✅ Largely done — 7 admin tabs incl. Audit governance (3.16). Loose ends folded into v4 sessions |
| v6 | GUI | ⬜ Not started — scope deliberately deferred until v4 lands |

Two new tracks added in this planning session, neither in the original Mission:

| Track | Theme | Status |
|---|---|---|
| Translation (i18n) | NC community Transifex pool, all UI + backend strings | 🔜 Sessions 3.19 |
| Accessibility (a11y) | WCAG 2.1 AA conformance via axe-core + Lighthouse | 🔜 Sessions 3.20 – 3.22 |

---

## Short-term roadmap (sessions 3.19 – 3.26)

Each row = one session = one minor version bump. Order is deliberate: translation and accessibility first because they're independent of feature work and unblock community visibility / EAA compliance; the security sweep before the markdown consolidation because both touch the same files; pagination before reactions because the latter inherits the former's UX patterns.

### 3.19 — Translation: plumbing + cleanup

Set up NC's official translation flow and fix the source strings so translators have something clean to work with.

**Plumbing:**
- Add `.tx/config` pointing at `o:nextcloud:p:nextcloud:r:teamhub`
- Add `.l10nignore` excluding `js/`, `node_modules/`, `package-lock.json`, etc.
- Delete stale `l10n/en.js` and `l10n/en.json` (8% coverage, will collide with bot output)

**String cleanup:**
- Convert all count-aware strings to `n('teamhub', 'singular', 'plural', count)` — currently zero plural forms across 644 strings
- Fix concatenation patterns (~85 cases) to use placeholder syntax
- Add `// TRANSLATORS:` hints for ambiguous strings (Share verb vs. noun, Pin verb vs. noun, etc.)

**Backend strings:**
- `Notifier.php`: wrap `setRichSubject` text in `$this->l10nFactory->get('teamhub', $languageCode)->t(...)` (the `$languageCode` arg is already passed in)
- `MessageService::sendPriorityEmailsWithName`: per-recipient translation using `IConfig::getUserValue($uid, 'core', 'lang')` — loop shape changes slightly to translate per user, not once per call
- `MessageService::sendNotificationsWithName`: same pattern

**Outside-Claude follow-up (Justin):**
- Invite `@nextcloud-bot` GitHub user with write access to the repo
- Post in [help.nextcloud.com → Translations](https://help.nextcloud.com/c/translations/23) requesting inclusion of `teamhub` in the NC community Transifex pool

### 3.20 — A11y audit + quick-wins pass

Justin runs axe-core + Lighthouse against a built TeamHub instance and shares the reports. Then I fix the items below that are independent of structural redesign.

**Quick wins:**
- Arrow-key navigation (Left/Right/Home/End) on the custom tab strips in `AdminSettings.vue` and `TeamTabBar.vue`
- `aria-live="polite"` region in `MessageStream.vue` announcing new messages
- Unique `document.title` per view (currently doesn't change when team or view changes)
- `<label>` elements on the 5 raw `<input>` tags (message-edit subject/body inline editors) — placeholder is not a label per WCAG 1.3.1
- Verify NC's skip-link isn't hidden by our chrome-stripping CSS added in 3.18; restore it if it is

**Reduced motion:**
- Wrap the `TransitionGroup` in `MessageStream.vue` and the comments expand/collapse animation in `@media (prefers-reduced-motion: reduce)` guards

### 3.21 — A11y: keyboard alternative for drag-to-reorder

`vue-grid-layout` (widget grid edit mode) and `vuedraggable` (tab reorder) provide drag UX with no keyboard equivalent. WCAG 2.1.1 hard fail for keyboard-only users.

- "Move up / move down" buttons on each widget tile in edit mode
- Same on each draggable tab in tab-bar edit mode
- Both surfaces wired to the same reorder handlers as drag

### 3.22 — A11y: color contrast sweep

Finish the v3.15 pass. Half session, can pair with something else.

- Convert remaining 17 `color-success`, 14 `color-error`, 12 `color-warning` usages on text to their `-text` variants
- Verify base variants stay on backgrounds, borders, and icon fills (where they're correct)

### 3.23 — Message-stream security sweep + comment delete

One-shot patch of all the same-shape membership-gate gaps in the message and comment endpoints. Unblocks every later message-stream feature.

**Membership gates:**
- `MessageController::createMessage` — replace `getCircle()` "membership check" with `MemberService::requireMemberLevel`
- `MessageController::votePoll`, `getPollResults`, `updateMessage`, `deleteMessage` — add `requireMemberLevel`
- `CommentController::listComments`, `createComment`, `updateComment` — inject `MessageMapper` + `MemberService`, look up message → team_id → require member level

**New endpoint:**
- `DELETE /api/v1/comments/{commentId}` — author or team admin can delete; audit-log `comment.deleted`
- Delete button in `CommentsSection.vue` (visible to author and admins only)

**Estimated:** ~150 lines PHP across 4 files, ~30 lines Vue. No schema migration. No new dependencies.

### 3.24 — Markdown renderer consolidation

Replace the three regex renderers (in `MessageCard.vue`, `CommentsSection.vue`, and `js/markdown.js`) with one safe pipeline. Closes the `<script>` and `javascript:` link XSS surface, and the related WCAG 1.3.1 concern around uncontrolled `v-html` structure.

- `npm install marked dompurify` (Path A — neither is currently in the bundle; ~70KB minified together)
- New `src/utils/markdown.js` exporting `render(text)` that pipes `marked.parse()` → `DOMPurify.sanitize()`
- Replace all three call sites
- Delete `js/markdown.js`

### 3.25 — Pagination + edited indicator + `probeCircles` aggregation fix

Stream optimization, no schema change.

- "Load older" button at the bottom of `MessageStream.vue`; backend already accepts `limit` / `offset`
- `hasMore` flag on the response (frontend infers `count(returned) === limit`)
- Store action `loadOlderMessages` that calls with `offset = state.messages.length`
- "Edited X ago" indicator on messages where `updated_at > created_at`
- Fix `MessageService::getAggregatedMessages` to use the direct DB pattern from `getTeamNameById` instead of `probeCircles()` (which silently drops teams with non-zero config bitmasks)

### 3.26 — Reactions

First v4 feature add. Scoped tightly to single emoji per user per message — Slack-style multi-emoji is explicitly out of scope (would force schema and UI patterns we don't need yet).

- Migration: `oc_teamhub_reactions` table — `id` BIGINT PK, `message_id` BIGINT, `user_id` STRING(64), `emoji` STRING(32), `created_at` BIGINT; unique index on `(message_id, user_id)`; index on `message_id`
- New `ReactionMapper` and `ReactionService`
- `POST /api/v1/messages/{messageId}/reaction` (set/replace), `DELETE /api/v1/messages/{messageId}/reaction` (clear), reactions included in message response
- `NcEmojiPicker` (NC component, in @nextcloud/vue 8.x) on `MessageCard.vue`
- Reaction pills under message body, click-to-toggle

---

## After 3.26

Decision point. v4 has a security base, real renderer, scaling, and the most-asked feature. Choose between:

- **More v4 features** — threading on comments, real-time updates (NC notify push or polling), moderation log surfaced to admins
- **Start v6 GUI** — pick which scope of "GUI" first (visual refresh, component decomposition of the 3 largest .vue files, mobile responsiveness, or accessibility pass beyond WCAG AA)

---

## Decisions locked in this planning session

| Decision | Choice | Rationale |
|---|---|---|
| Translation scope | Option B (plumb + clean) | Option A leaves real bugs in plurals; Option C (NC Mailer templates) is over-investing for now |
| Translation pool | NC community Transifex | Free volunteer pool, established process, NC's official path |
| Accessibility target | WCAG 2.1 AA | EAA enforceable since June 2025; covers EU public-sector and any large-org install |
| Audit tooling | axe-core + Lighthouse | Automated only — no manual NVDA/VoiceOver pass for now |
| Markdown library | Path A (marked + DOMPurify) | Path B (NcRichText) availability in @nextcloud/vue 8.20 unconfirmed; safer to add libraries we control |
| Reactions shape | Single emoji per user per message | Slack-style multi-emoji deferred; would change schema and UI patterns |

---

## Open decisions (for later sessions)

- **Real-time message updates** — push (NC notify) vs. polling vs. punt to a later phase
- **Threading on comments** — add `parent_id` and rework UI, or keep flat
- **v6 GUI scope** — visual refresh, component decomposition, mobile responsiveness, or all three
- **Email templates** — eventually move from inline string concat to `Mailer::createTemplate()` (Translation Option C); not gated by anything
- **NC translation pool inclusion** — depends on community team accepting the request after 3.19 ships

---

## Items I noticed but parked

Things worth knowing about but not on the immediate plan:

- **Integration kill-switch per app** — admin can manage the registry but can't globally disable a third-party widget for all teams short of uninstalling its provider. Useful for incident response. Small.
- **Audit retention UI exposure** — backend supports it (`/api/v1/admin/audit/retention`); worth confirming the Audit tab UI exposes the GET/PUT cleanly. Quick check, not a session.
- **`AdminSettings.vue` decomposition** (2,360 lines), **`ManageTeamView.vue`** (1,986), **`TeamWidgetGrid.vue`** (1,470) — natural targets when v6 starts.

---

## How this document is maintained

| When | Action |
|---|---|
| Planning session ends | Update sessions list, decisions table, open decisions |
| Implementation session ends | Move completed session out of the list; add anything newly discovered to "Open decisions" or "Items parked" |
| Mission changes | Update the long-term roadmap table at the top |

This file lives in the repo root alongside `HANDOFF.md`, `CHANGELOG.md`, `APIendpoints.md`, `developers.md`, and `SKILLS.md`. It is committed with the code.
