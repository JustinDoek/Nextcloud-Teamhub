# Changelog

All notable changes to TeamHub are documented in this file.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [3.104.0] — 2026-07-13 — Budget + Time iframes production pass + Team Apps relocation

Long polish session on top of v3.103.0's iframe rebuild. No API changes; no PHP touched; three new strings translated to all locales.

### Added

- `openBudgetSettings()` / `openTimeSettings()` methods in the two iframe views. Each stashes `SET_MANAGE_TEAM_DEEP_LINK({ tab: 'project', section: 'budget' | 'time' })` in Vuex before emitting `open-project-settings` so the destination section scrolls + highlights on arrival (same pattern the Compass uses).
- `canAddAnyExpense` computed + `editableLanes` computed in `ProjectBudgetView`. Gate the new global "Add expense" button in the Workstream-lanes widget header; populate the lane picker in the modal.
- Required lane `<select>` in the Add / Edit expense modal, disabled when editing (moving expenses between lanes is a different flow), auto-selecting when only one lane is editable.
- `resetScrollTop()` method in both iframe views — called from `mounted() → $nextTick` and from `watch: currentView`. Fixes the mobile bug where re-entering the Budget or Time tab landed the user partway down the container because `scrollTop` persisted across `display: none / block` toggles.
- `min-height: 52 px` on `.th-iframe-widget__header` so the widget-card header stays a stable height regardless of what any consumer puts in the `#actions` slot.
- Three new user-facing strings translated to nl/de/fr/da/es/it: `Choose a lane…`, `Open Manage Team — Project — Budget`, `Open Manage Team — Project — Time investment`. All 14 locale files updated and validated.

### Changed

- Vuex getter mapping in both iframe views: `mapGetters(['isTeamAdmin'])` → `mapGetters({ isTeamAdmin: 'currentUserIsTeamAdmin' })`. The store getter is `currentUserIsTeamAdmin`; the old alias silently produced `undefined`, so every `v-if="isTeamAdmin"` was silently falsy. Fixes the missing "Budget settings" / "Time settings" buttons and un-breaks other admin-gated surfaces in ProjectTimeView.
- Widget-header button variants unified: `Budget settings`, `Time settings`, `Add expense`, `Log time` all now `variant="primary"` (dark green). No more mixed primary/secondary in the same widget-card row.
- Workstream-lanes rebuilt to mirror the Time-report per-lane layout. Coloured swatch + lane title + real-spent total on the header row; then a table with `Member | Description (+ date sub-line) | Projected | Real | Options`. Retired the per-lane stats block and budget bar (the Utilisation widget's per-lane bar chart already carries that signal).
- Empty states (`.th-budget__lane-empty`, `.th-time__report-empty--small`) unified to `font-style: italic; font-size: var(--th-font-meta)` — matches every other widget's empty-state treatment.
- Member picker moved from the Time-report widget-header `#actions` slot to inside the report body's `.th-time__report-head` next to the Per-member toggle. Fixes the widget-header height jitter caused by the raw `<select>`'s intrinsic height differing from `NcButton`'s.
- Both modals rebuilt with a unified `.th-budget__input` / `.th-time__input` class — 36 px height, 8 px radius, primary-element focus ring — applied to every field regardless of type. Add-expense modal has a 2-column amount grid; Log-time modal has a 3-column hours/minutes/date grid; both collapse to 1 column below 480 px.
- Iframe scroll behaviour: `.th-budget` and `.th-time` both gained `-webkit-overflow-scrolling: touch`, `overscroll-behavior: contain`, and explicit `overflow-y: auto; overflow-x: hidden`. `.th-time` also gained `height: 100%; box-sizing: border-box` which it was missing entirely — that's why Time widgets 2 + 3 were unreachable on mobile.
- `.th-time__kpis` grid layout: `auto-fit, minmax(180 px, 1fr)` → explicit `4 / 2 / 1` breakpoint ladder (desktop 4-across, ≤ 900 px 2×2, ≤ 360 px single column). Was collapsing to 1 column on any phone width.
- `.th-budget__kpi-value` drops from 26 px → 20 px at ≤ 720 px so amounts like `€ 10.000,00` stay inside their tile. Both KPI value classes gain `min-width: 0` + `overflow-wrap: anywhere`.
- `ProjectPhaseStepper` and `ProjectCompassPanel` now `v-if`-gated on `!isMobile` in `TeamView`. On phones they used to eat vertical room above `TeamWidgetGrid` and push the `MobileWidgetView`'s icon bar + FAB below the viewport.
- Manage Team → **Settings tab**: Team Apps section removed. Manage Team → **Integrations tab**: Team Apps section added below the existing Integrations content — 394-line block relocated via an atomic Node splice preserving data flow, methods, and CSS classes. All Team Apps functionality (per-app resource lists, pending/at-risk sub-panels, connect / create / delete dialogs, ignored resources) now lives alongside internal and third-party integrations.

### Removed

- Per-lane `Add expense` buttons in each lane section's `row1` header — one global button in the workstream widget header now drives every expense, with lane picked in the modal.
- Speculative `min-height: 0 + max-height: 100%` cap on `.th-budget` / `.th-time` from a preceding iteration — the combination made widget-card children shrink to fit and lose their internal padding + KPI grid layout. The JS scroll-reset alone (`resetScrollTop`) is sufficient for the visible-widget bug.
- Dead widget-header inline strip `.th-time__report-actions` (the picker + Log-time button that used to live inside it moved elsewhere).
- Unused `NcTextField` imports and component registrations in both iframe views.

### Security

- No PHP touched, no new endpoints, no new `v-html`, no new `axios` sites, no debug logging added. Session-end scan clean.

---

## [3.103.0] — 2026-07-13 — Budget + Time iframes polish pass

Four follow-up asks landing on top of v3.102.0's iframe rebuild.

### Added

- Global "Add expense" `NcButton` in the Budget iframe's Workstream-lanes widget header, visible when the current user has any editable lane. Consolidates the four per-lane buttons this replaces.
- Required lane `<select>` in the Add / Edit expense modal, populated from the user's editable lanes. Disabled when editing an existing expense (moving an expense between lanes is a different flow). Preselects the single editable lane when there's only one.
- New `.th-budget__widget-row` and `.th-time__widget-row` grid containers wrapping widgets 2 + 3 so Utilisation sits next to Workstream lanes / Time report on wide viewports. Both containers carry a `--single` modifier that collapses back to one column when Utilisation is hidden.
- Three new user-facing strings — `Choose a lane…`, `Open Manage Team — Project — Budget`, `Open Manage Team — Project — Time investment` — translated to nl/de/fr/da/es/it. All 14 locale files updated and validated.

### Changed

- Iframe backdrop on both `.th-budget` and `.th-time` set to `var(--color-background-dark)` (theme-aware) so widget cards read as elevated surfaces on a subtly darker canvas — matches the dashboard-widget rhythm.
- Overview widget-header settings buttons swapped from icon-only `variant="tertiary"` to icon + label `variant="secondary"` with a `title` tooltip that spells out the destination (`Open Manage Team — Project — Budget` / `Open Manage Team — Project — Time investment`). Still gated on `isTeamAdmin`.
- Nested layouts inside the Utilisation widget (`.th-budget__charts` donut + bars) and Workstream-lanes widget (`.th-budget__lanes` 2-column grid) collapsed to single-column stacks — the halved widget width from the 2-column widget row would have squeezed both charts and lane cards to unreadable widths.

### Removed

- Per-lane "Add expense" `NcButton` in each lane section's `row1` header. The workstream-lanes widget-header now carries one button, and the modal picks the lane.
- `.th-budget__charts` `grid-template-columns: 280px 1fr` at 900 px+, and `.th-budget__lanes` `grid-template-columns: 1fr 1fr` at 900 px+. Both retired now that their parent widgets sit at half width on wide viewports.

### Security

- No PHP touched, no new endpoints. Same expense CRUD flow — the `POST /budget/lanes/{laneId}/expenses` endpoint receives the `laneId` picked in the modal instead of preselected by the button. Session-end security scan clean.

---

## [3.102.0] — 2026-07-13 — Budget + Time iframes rebuilt into widget cards

Follow-up to v3.101.0 GUI audit. The two Advanced-project full-tab iframes (`ProjectBudgetView`, `ProjectTimeView`) now use the dashboard's widget-card visual language instead of rendering as one long section. Each iframe is composed from three stacked `IframeWidgetCard` widgets so every information block has its own header + body.

### Added

- `src/components/IframeWidgetCard.vue` — shared widget-card chrome for any full-tab iframe view. Slots: `#icon`, `#actions`, default (body), `#footer`. Optional collapse chevron with `{widget}` placeholder aria-label (reuses the existing `Expand {widget}` / `Collapse {widget}` translation keys).
- Four new user-facing strings — `Budget overview`, `Time overview`, `Time report`, `Workstream lanes` — translated to nl/de/fr/da/es/it and committed to both `.json` and `.js` locale files.

### Changed

- `ProjectBudgetView.vue` restructured into three `IframeWidgetCard` widgets: Budget overview (KPIs, settings button in header actions), Utilisation (donut + per-workstream bars, rendered only when there's chart data), Workstream lanes (lane sections + expenses tables, with lane count as widget badge). Loading / error / empty states now render inside the Workstream-lanes widget body so the chrome stays consistent.
- `ProjectTimeView.vue` restructured the same way: Time overview (KPIs, settings), Utilisation (per-member + per-lane bars), Time report (view-switcher + tables). The member picker and Log-time button moved from the report's inline action row into the Time-report widget's header actions slot, matching every other widget's primary-action placement.
- Data flow, expense/time-log CRUD, modals, permission gating, and computed properties preserved in both views. This is a template + chrome refactor, not a behavioural change.

### Removed

- `.th-budget__kpis-wrap`, `.th-budget__settings-btn` CSS blocks — retired now that KPIs sit inside a widget-card body and the settings button lives in the widget header's actions slot.
- `.th-time__kpis-wrap`, `.th-time__settings-btn`, `.th-time__report-actions` — same reason.
- Redundant `margin-bottom: 24px` on `.th-budget__charts` — the enclosing widget card owns bottom spacing now.

### Security

- No PHP touched, no new endpoints, no new `v-html` or `axios` sites. Session-end security scan clean.

---

## [3.101.0] — 2026-07-13 — GUI audit + design-token migration

Session-long audit against the Nextcloud design guide and SKILLS.md § "State-coloured backgrounds". No API changes; no PHP files touched.

### Added

- App-wide typography, border-radius, and icon-size token scales in `src/styles/widget-tokens.css`, imported into all three entry points so admin and personal settings pages inherit the scale too.
- `src/constants/uiTokens.js` — JS mirror of the icon-size scale, for `vue-material-design-icons` `:size` props that need a number at compile time.
- `src/components/WidgetCollapseButton.vue` — micro-component extracted from the collapse-chevron pattern that was copy-pasted 12 times in `TeamWidgetGrid.vue`. `{widget}` placeholder aria-label lets translators reposition the widget name in their locale.
- Missing `aria-label` on `InviteMemberModal`'s chip-remove button.
- `gui.md` at the repository root — full frontend audit that documented every finding this session addressed and the ones still open.
- `SKILLS.md` gained six new rules under "Nextcloud design guidelines" — the design-token scale, hex restriction, focus-visibility standard, NcButton default with documented custom-`<button>` carve-outs, micro-component extraction threshold, and an expanded state-coloured-backgrounds section reflecting the retirement of `--th-color-*` in favour of NC theme tokens.

### Changed

- State-tinted backgrounds across the frontend migrated to SKILLS.md's canonical `--color-X` + `--color-X-text` pattern (chips, badges, selected/active states, warning/error/info banners, danger notices). Neutral surfaces for decorative avatars and category tiles switched to `--color-background-dark`.
- 286 exact-match `font-size: 11 / 12 / 14 / 16 / 20 px` sites converted to the new token scale (`--th-font-micro / -meta / -body / -heading / -heading-lg`). No visual change — every swap is value-preserving.
- 9 raw `<button>` sites converted to `NcButton` (App.vue help button, TeamWidgetGrid edit-banner Save/Reset default, ActivityFeedView refresh, ActivityWidget more/refresh, FilesSharedWidget pagination, AddEventModal Select all). Custom `-btn` CSS blocks retired.
- `⠿` Braille drag-handle character in `TeamTabBar.vue` replaced with the `DragVariant` MDI icon (12 sites). `×` multiplication-sign close-chip character in `AdminSettings`, `InviteMemberModal`, `ManageTeamView` replaced with the `Close` MDI icon.
- Presence dot colours in `MemberRow.vue` now return CSS variable references (`var(--color-success)` etc.) so they follow the NC theme in light/dark mode.
- `DecisionApprovalModal` Approve / Deny buttons: local `--color-primary-element` override now points at `--color-success` / `--color-error` tokens instead of pinned hex, so both follow the theme.
- Activity feed / activity widget source badges (Circles / Files / Deck / Calendar / Talk) collapsed from a Google-Material palette (raw hex) to one neutral surface with an MDI icon carrying the source distinction.
- Home widget-grid canvas backdrop switched from `#f4f4f4` to `--color-background-dark`. Dark-mode explicit override retired (base is theme-aware).
- `App.vue:6` inline `<div style="height: 44px …" />` sidebar spacer moved into a scoped class.
- `DecisionApprovalModal.vue:118` condensed single-line textarea rule expanded to one property per line with an explicit `:focus-visible` box-shadow ring.

### Fixed

- WCAG 2.4.7 regression in `MyPresencePanel.vue:510` — `:focus-visible { outline: none }` was silencing the keyboard focus ring entirely. Now gets a proper 2 px primary outline.
- Hover + focus-visible rules that were grouped and set `outline: none` (with no `:focus-visible` replacement) in `ProjectHealthWidget`, `ProjectCompassPanel` (3 sites), `TeamDecisionsView` (2 button sites). Split so `:focus-visible` keeps a visible ring while `:hover` handles the background feedback only.

### Removed

- Four `--th-color-*` (success / warning / error / neutral) hard-contrast hex tokens from `src/styles/widget-tokens.css`. All consumers migrated to NC's `--color-*` + `-text` pairs.
- Four `--th-color-*-soft` tokens (SKILLS.md § "State-coloured backgrounds" explicitly forbids project-local soft tint tokens).
- `--th-widget-success-strong` alias — no consumers.
- Google-Material activity-badge palette (`#e8f0fe / #3b5998` and 9 more Circles/Files/Deck/Calendar/Talk pairs).
- 132 → 32 raw hex colour declarations (75 % cut). Remaining hex is deliberate: categorical `LANE_PALETTE` values for swimlane distinction, JS luminance-based text-colour computations (return values), documentation comments, and placeholder text.
- 7 dead custom `-btn` CSS blocks whose raw `<button>` templates were converted to `NcButton`.

### Deliberately deferred

- `font-size: 13px` (199 sites) and other odd values (10 / 15 / 18 / 22 / 26 / 28 / 34 px) — need per-site design decision.
- Border-radius scale application (~111 sites); most sites need a per-site design decision.
- Icon-size scale application; needs a design decision on `import ICON_BODY` per file vs `app.config.globalProperties.$icon`.
- `timeline.php` dark-mode rewrite (1772-line standalone template) — needs an approach decision (`postMessage` bridge vs full Vue rewrite).
- Categorical `LANE_PALETTE` in `ProjectSwimlaneView` / `ProjectBudgetView` / `ProjectTimeView` — design decision.
- Custom tab bars in `TeamTabBar.vue` (975 lines) and `AdminSettings.vue` — need a check against `@nextcloud/vue 9.x` per SKILLS.md § "NC component uncertainty rule".

### Security

- No new endpoints, no PHP changes, no new `v-html` sites. Session-end security scan clean.

---

## [3.100.13] — 2026-07-12 — Fix: TalkService::connectExistingRoom type mismatch

### Fixed

- **`ResourceService::connectExistingResource` now casts Talk roomId to
  int.** `TalkService::connectExistingRoom` declares `int $roomId`, but
  the resourceId picked from the picker arrives at the switch statement
  as a string. The regression fired only for the Talk case; other apps
  handle the string→int conversion inside their own sub-services. Cast
  is done at the call site so the TalkService type remains strict.

### Server-side note (not a code change)

- If NC cron reports `Could not resolve OCA\TeamHub\BackgroundJob\ResourceDiscoveryJob!
  Class ... does not exist` after upgrade, that is a classloader cache
  stale on the server, not a code bug — the class file is present in
  this release. To clear it:
  1. Confirm the file exists at
     `apps/teamhub/lib/BackgroundJob/ResourceDiscoveryJob.php` on the
     server.
  2. Run `sudo -u www-data php occ app:disable teamhub && sudo -u
     www-data php occ app:enable teamhub` to force a re-registration.
  3. If step 2 doesn't clear it, restart PHP-FPM to drop OPcache: the
     autoloader map is cached in-process.

---

## [3.100.12] — 2026-07-12 — Fix: discovery reconciler wiping soft-deleted rows

### Fixed

- **`ResourceDiscoveryService::reconcileApp` no longer hard-deletes rows
  marked `disconnected`.** Root cause of the v3.100.11 "picker shows no
  reattach options" regression: after we soft-deleted the row in
  `removeTeamAccess` and stripped the ACL from the group folder, the
  next reconciliation pass saw a row with no matching NC-side resource
  and treated it as "external withdrawal", hard-deleting the row we
  intentionally kept. The reconciler now skips rows whose status is
  already `disconnected` — those are our own soft-deletes, not
  externally-withdrawn resources.

### Note

- `ResourceService::deleteTeamResource` (which HARD-deletes the
  underlying NC folder via `deleteGroupFolder`) still hard-clears the
  registry via `removeResourceRowsByTeamAndApp`. That's the correct
  behaviour — if the folder no longer exists in NC, keeping a
  "disconnected" registry row would just be dead history the picker
  would then filter out anyway (`listGroupFoldersAvailableToAttach`
  verifies the folder still exists in `group_folders`).

---

## [3.100.11] — 2026-07-12 — Scoped team-folder picker + resend-invite

### Fixed

- **W-4 privacy — Files picker no longer leaks every group folder on
  the instance.** Team admins previously saw every Team folder in the
  picker's "attach" section, even ones the team was never a member of.
  Now the picker only surfaces folders THIS team was previously
  connected to (present in `teamhub_team_app_resources` with
  `status='disconnected'`). Fresh-attach to a brand-new folder is a
  separate flow via Team Folders admin → Create.
- **W-4 soft-delete on resource remove.** `ResourceService::removeTeamAccess`
  now updates the row to `status='disconnected'` instead of hard-deleting.
  `upsertResourceRow` already promotes `pending`/`ignored` rows to
  `active` on reconnect; the list is extended to include `disconnected`
  so a reconnect resurrects the same row rather than inserting a
  duplicate.
- **W-5 resend invite when notification was lost.** Previously the
  invite endpoint returned a silent `already_invited` success when
  Circles refused with `FederatedItemBadRequestException`. If the target
  is still `Invited` (deleted the notification, missed it, etc.) they'd
  never get a chance to accept. The endpoint now inspects the current
  row status:
  - `Invited` → delete the stale row and re-invite; Circles' notifier
    fires a fresh notification. Result map returns `invite_resent`.
  - `Member`  → no-op success. Result map returns `already_invited`.
  Only user_type=1 targets take the resend path; groups/circles are
  handled by the existing auto-confirm block.

### Added

- **`MemberService::fetchAnyMemberStatus`** — helper that returns the
  raw circles_member.status for a (teamId, userId) row without
  filtering to `Member`. Used by the resend flow to distinguish
  `Invited` (resend) from `Member` (no-op).

### Changed

- **`MemberService::fetchMemberStatus` semantics.** Now returns
  `'Member'` or `null`, filtered to accepted members only. This matches
  the Talk-sync gate's call site (`if ($status === 'Member')`) and
  makes the intent clearer. The unrestricted variant is
  `fetchAnyMemberStatus`.

---

## [3.100.10] — 2026-07-12 — v3.100.9 test-round follow-ups (already-invited + team-folder reattach)

### Fixed

- **`MemberService::inviteMembers` treats "Already invited" as idempotent
  success.** Circles throws `FederatedItemBadRequestException` (code 123,
  message "Already invited into the team") when the target already has an
  Invited/Member row. Previously we let the exception bubble up as a
  failure; re-invite is a common admin flow ("resend invite" / "did the
  first one land?"), so the endpoint now returns `already_invited` in the
  per-target results map and does not error. Existing Talk-sync logic
  still runs when the row is already `Member`.

### Added

- **`GroupFolderService::listGroupFoldersAvailableToAttach($teamId)`** —
  returns every group folder the team is NOT currently a member of. Used
  by the picker's new "available" section.
- **`FilesService::listConnectableFileFolders` — third section for team
  admins.** When the caller is a team admin AND no group folder is
  currently attached to the team, the picker now lists every group
  folder the team could be attached to (fresh attach or reconnect after
  a previous disconnect). Non-admins are unaffected. The connect flow
  is idempotent — `assignCircleToFolder` treats "already assigned" as
  success — so reconnect works via the existing connect endpoint.
- **`MemberService::isCurrentUserTeamAdmin($teamId)`** — cheap bool
  helper mirroring `isCurrentUserDirectMember`, used to gate the new
  picker section. Write endpoints still call `requireAdminLevel` so the
  security boundary is unchanged.

### Note

- The new picker section items carry `type='group_folder'` (matching
  the existing shape) with an additional `available: true` flag. This
  keeps them renderable in the current frontend without a rebuild.
  A future GUI session can style available-vs-attached differently.

---

## [3.100.9] — 2026-07-12 — v3.100.8 test-round follow-ups

### Fixed

- **`MemberService::inviteMembers` no longer syncs invited users to Talk
  until they accept.** Previously, calling `ParticipantService::addUsers`
  on an `Invited`-status circle member bypassed Circles' invite flow —
  Talk added the user as a full participant and Circles' invite
  notification never fired. New behaviour: check the row's status after
  `addMember()`; sync to Talk only when status is `Member` (auto-accepted
  invite), defer otherwise. Test W-5.
- **`MemberService::requestJoinTeam` open-circle auto-approve now syncs
  to Talk** — mirroring what `approveRequest` already did. Fills the
  gap left by the inviteMembers gate above.
- **`TeamService::browseAllTeams` now surfaces open (CFG_OPEN) circles.**
  The browse-teams query previously only included CFG_VISIBLE circles.
  An open circle without CFG_VISIBLE was invisible in browse, which
  blocked test W-2 (auto-approve join). Any circle marked as
  auto-accept (CFG_OPEN) is now discoverable because hiding it serves
  no security purpose.
- **`TeamService::browseAllTeams` `requiresApproval` flag now reads the
  correct bit.** The check was `($config & 1) > 0`, which reads
  CFG_SINGLE, not CFG_OPEN (= 16). Every browsed team was reporting
  `requiresApproval=false` regardless of its actual config. Fixed to
  read the CFG_OPEN bit.
- **Reverted the `FilesService::suspendFilesAccess` +
  `removeFilesAccess` W-4 migration.** The IManager path leaves a row
  in `share` that the reconnect-duplicate check then hits, so users
  cannot reattach the same folder after disconnecting. The audit-log
  entry is already written by ResourceService so no observability is
  lost by returning to the raw DELETE. The `deleteSharedFolder` site
  (which is only used on full team deletion, not reconnect) keeps its
  pre-existing IManager-first pattern.

---

## [3.100.8] — 2026-07-12 — apps.md review: shift eligible cross-app writes/reads to OCP/OCA APIs

### Added

- **`lib/Exception/AppNotAvailableException.php`** — carries "app is not
  installed" as a 422 through the controller trait.
- **`lib/Exception/TrialRequestException.php`** — LicenseService now
  throws this with the licensing back-end's HTTP status attached; the
  controller stops substring-matching messages to determine status.

### Changed — cross-app writes now API-first with raw-DB fallback

- **`MemberService::approveRequest` + `requestJoinTeam` (open-circle
  auto-approve)** — try `CirclesManager::confirmMemberRequest()` first
  so `MemberConfirmedEvent` fires (activity/notifications pick it up),
  fall back to raw UPDATE on hidden circles. (apps.md W-2)
- **`FilesService::suspendFilesAccess` + `removeFilesAccess` +
  `deleteSharedFolder`** — all three now try `IManager::getShareById()
  + deleteShare()` first so `ShareDeletedEvent` fires; fall back to raw
  DELETE when the IManager can't hydrate the share row. (apps.md W-4)
- **`TalkService::syncUserToTeamTalkRoom` + `removeUserFromTeamTalkRoom`
  + `reconcileTalkRoomMembers` per-attendee eviction +
  `promoteTalkCircleToModerator`** — all now try Talk's
  `ParticipantService::addUsers / removeAttendee /
  updateParticipantType` first (gains system chat messages + push
  notifications), fall back to raw INSERT/UPDATE/DELETE when the API
  refuses. Room creation and initial circle expansion still use the
  documented QB fallback. (apps.md W-5)
- **`TaskService::createTeamTask`** — single path via
  `\OCP\Calendar\ICalendarManager` +
  `ICalendar::createFromString($uri, $icsData)` (fully public OCP API).
  Dropped the `CalDavBackend` + raw-QB dual path. `insertCalendarObject`
  private helper removed. (apps.md W-6)

### Changed — cross-app reads shifted where API supports it

- **`TeamService::getTeam`** — API-first via
  `CirclesManager::getCircle()`, raw SELECT fallback for hidden circles.
  Member-count query still direct (see note below). (apps.md R-1)
- **`DecisionCategoryService::findTeamOwnerUid`** — API-first via
  `CirclesManager::getCircle()->getOwner()->getUserId()`, raw SELECT
  fallback. Injected `ContainerInterface`. (apps.md R-1)
- **`CalendarService::listOwnedCalendars` +
  `connectExistingCalendar` ownership check** — try
  `\OCP\Calendar\ICalendarManager::getCalendarsForPrincipal()`, fall back
  to raw SELECT for older NC versions or unusual deployments. (apps.md
  R-2)
- **`ActivityService`** meeting-URL Talk-room lookup —
  `\OCA\Talk\Manager::getRoomForActor('circles', $teamId)->getToken()`
  first, raw QB fallback. Room resolution now goes through Talk's own
  visibility rules, matching R-4 of apps.md. (apps.md R-4)
- **`ArchiveService::estimateFolderSize`** — file size via
  `\OCP\Files\IRootFolder::getById($fileId)[0]->getSize()` (fully
  public OCP API), raw `filecache` SELECT retained as fallback. (apps.md
  R-7)

### Kept raw — with documented rationale

- **`MemberService::getMemberLevelFromDb`** — arbitrary-user support
  (target-user checks). CirclesManager exposes level only via
  `getInitiator()`.
- **`Search\TeamSearchProvider`** — SQL LIKE-search pushes filtering to
  the DB; `probeCircles()` + PHP filter would regress on every keystroke.
- **`CalendarService::resolveCalendarOwnerUri`** — no
  getCalendarById() on ICalendarManager; reverse lookup would walk every
  principal.
- **`TaskService::fetchVtodoRows`** — same reverse-lookup gap.
- **`AuditIngestionService::checkActivityAvailable` +
  `fetchActivityRows` + `collectCurrentShares` + `buildTeamFolderMap` +
  `listTeamIds`** — no OCP reader for `activity`; no reverse-recipient
  lookup on `IShare`; nightly system job needs global scope.
- **`AuditController::listTeams` + `exportTeam`** — admin needs display
  names for teams they may not be members of.
- **`DeckService::createStackOnTeamBoard` last_modified bump** — not an
  OCP gap. Deck's cache contract is the column value, not a method
  call. `BoardService::update()` would fire wrong events. (apps.md W-7)
- **`UserDeletedListener`** — NC doesn't expose "reassign" APIs; direct
  reassign UPDATEs are the only path. New docblock note about
  listener-ordering hazard on shared apps' deletion listeners. (apps.md
  W-8)

### Follow-up

- Every migrated path preserves the raw-DB path as fallback and logs a
  `debug` line when the API path refuses. No behavior regression on
  older NC versions.
- The `[TeamHub][ClassName]` log prefix is consistent everywhere.
- No `npm run build` needed — PHP-only changes.

---

## [3.99.4] — 2026-07-08 — Decisions integration for Advanced projects

### Added

- **`ProjectService::seedAdvancedProjectDecisions`** — new private helper called from both "created advanced" and "basic → advanced upgrade" upsert branches. Enables Decisions for the team via `DecisionTeamService::saveConfig` and creates a "Project management" category via `DecisionCategoryService::createCategory`. Localised per the creator's NC language. Idempotent — duplicate-name errors are swallowed at debug level.
- **`DeckService::maybeSeedCategoryForStack`** — new private helper called from `createStackOnTeamBoard` after a successful stack insert. Mirrors the new lane title into a same-named Decisions category, gated on the module being enabled globally + team-level. Silent skip on duplicate. Non-fatal to the primary stack write.

### Changed

- **`ProjectService` constructor** — added `DecisionTeamService`, `DecisionCategoryService`, `IConfig`, `IFactory`.
- **`DeckService` constructor** — added `DecisionCategoryService`, `DecisionTeamConfigMapper`, `IConfig`.

### Follow-up

- Closes the spawn task from the 3.97.5 session ("Add Decisions integration to Advanced projects").

---

## [3.99.1] — 2026-07-08 — Phase model reshuffle + Execution advisory cleanup + workstreams Deck-refresh fix + Compass refetch on Home return

Post-session-end patch based on Justin's feedback on 3.99.0.

### Changed

- **Phase model reshuffled.** Advanced projects now open on `initiation` (was `planning`). Initiation carries the pure-config items — project dates, invite members, budget total, time capacity — moved from Planning. Planning becomes the "plan the actual work" phase: charter configuration, kickoff meeting, workstreams, milestones. `ProjectService::DEFAULT_PHASE = 'initiation'`.
- **Execution advisories removed** from the Compass — `milestones_on_track` and `within_bounds` are gone. Justin's rationale: those signals already live in the project-health widget; duplicating them in the Compass added noise without action. Execution keeps only `first_expense` + `first_timelog`.

### Added

- **Two new Planning items** with manual-mark UX:
  - `charter_configured` — links to the Pages (IntraVox) tab. Inline "Mark done" button records `teamhub_project.charter_configured_at`.
  - `kickoff_meeting` — links to the Add Meeting wizard. Inline "Mark done" button records `teamhub_project.kickoff_meeting_at`.
- **New migration** `Version000399001Date20260708100000` adds both columns.
- **New endpoint** `PUT /api/v1/teams/{teamId}/project/marks/{markType}` (admin-gated). Body `{ done: bool }`. Sets or clears the mark timestamp.
- **`ProjectReadinessService::setMark`** — validates `markType` against `VALID_MARK_TYPES = ['charter_configured', 'kickoff_meeting']`, then persists.
- **10 new strings** translated to nl/de/fr/da/es/it.

### Fixed

- **Workstreams modal now actually refreshes the Deck board.** `DeckService::createStackOnTeamBoard` bumps `deck_boards.last_modified = time()` alongside the stack insert. Deck's frontend keys off that timestamp when deciding whether to refetch the stack list; without the bump, admins added lanes via the Compass but they stayed invisible in Deck until an unrelated write or a hard reload.
- **Compass refetches on Home return.** `ProjectCompassPanel.vue` watches `currentView` and refetches when the user returns to `msgstream` from another tab. Fixes the pattern where logging the first expense required a team reload before the Compass reflected it.

### Version

- Bump 3.99.0 → 3.99.1.

---

## [3.99.0] — 2026-07-08 — Closing phase: readable artifact + archive-policy warning (Track E Session 7, closed)

Session-end major bump. Track E Session 7 wraps the Project Teams arc. Advanced projects can now export a human-readable snapshot of their decisions/budget/time/milestones into the team's Files before archiving; the artifact stays behind after the team itself is gone.

### Added

- **`ClosingArtifactService`** — generates `Project Closing/` folder in the team's Files with five markdown files:
  - `summary.md` — one-page overview (mode, phase, dates, currency, counts)
  - `decisions.md` — every decision with status, impact, category, proposer, date
  - `budget.md` — project total, per-lane allocation vs real spent, expense line items
  - `time.md` — per-member available vs logged hours, per-lane roll-up
  - `milestones.md` — dated milestones with reached / open status
- **New endpoints** on `ProjectController`:
  - `POST /api/v1/teams/{teamId}/project/closing/generate` — admin-gated, produces the artifact, stamps `closing_artifact_at`
  - `GET  /api/v1/teams/{teamId}/project/closing/status` — member-gated, returns `{ generated, generatedAt, filePath }`
  - `GET  /api/v1/teams/{teamId}/project/closing/archive-policy` — member-gated, returns `{ archiveBeforeDelete, archiveMode, dataLossWarning }`
- **Migration** — `teamhub_project.closing_artifact_at BIGINT NULL`. Set by the service on success; presence gates the Compass readiness item.
- **Closing-phase Compass items** (2):
  - `closing_artifact` — required. Done when `closing_artifact_at` is set. Emits `open-generate-modal` when clicked.
  - `team_archive` — advisory. Prompts the owner to archive from Compass footer OR Manage Team → Danger.
- **`ClosingArtifactModal.vue`** — confirmation + progress + success dialog for the Generate action. Shows the resulting file path.
- **`ArchivePolicyWarningModal.vue`** — fetches `/archive-policy` on open. If the admin configured archive-off + hard-delete (`dataLossWarning: true`), shows the "All project data will be lost" warning with Cancel / Continue anyway. Otherwise shows a plain policy description and Continue.
- **~30 new user-facing strings** wrapped in `t()` and translated to nl/de/fr/da/es/it.

### Changed

- **Version bump** 3.98.4 → 3.99.0. Track E Project Teams arc is now complete.

### Security

- All endpoints go through `MemberService::requireAdminLevel` / `requireMemberLevel`. The archive endpoint itself is unchanged — `ArchiveService::produceTeamArchive` still enforces owner-only (level 9) at its own layer. The warning modal doesn't bypass any gate; it just fetches the policy so the frontend can render an informed confirmation.

### Follow-up

- The `Manage Team → Danger` archive flow could also route through `ArchivePolicyWarningModal` for consistency (the Compass path already does). Small follow-up; not blocking.
- Closing-phase retro artifact (a formal retrospective doc) is deliberately out of scope — teams handle retros however they like today and the Compass already surfaces slipping milestones + over-budget lanes as advisory items for that conversation.

---

## [3.98.0] — 2026-07-08 — Project Compass: guided setup checklist + Next-up prompt (Track E follow-on)

Guides users through the Advanced project workflow with a persistent setup panel on the team Home view. Folds the pre-3.98.0 one-shot ProjectPhaseGuide dialog into a permanent companion.

### Added

- **`ProjectCompassPanel.vue`** — new panel between the phase stepper and the widget grid, Advanced projects only. Renders a phase-appropriate setup checklist (5 Planning items, 4 Execution items) plus a big "Next up" prompt showing the topmost incomplete item with a jump-link. When every item is done: "Ready to enter {nextPhase}" CTA that fires the setPhase endpoint (admin only). Collapsible (state persisted in `localStorage`). Refetches on window focus, tab visibility change, and Vuex `project` / `budgetConfig` / `timeConfig` mutations.
- **`ProjectReadinessService`** — computes the per-phase checklist. Planning: project dates set, members invited, milestones added, budget total set (if budget integration enabled), time capacity per member (if time integration enabled). Execution: first expense, first time entry, milestones on track, budget within bounds. All checks read existing tables; no new schema.
- **`GET /api/v1/teams/{teamId}/project/readiness`** — member-gated endpoint returning `{ isProject, phase, nextPhase, readyToAdvance, items: [{id, done, label, hint, link}] }`. Non-Advanced teams get `{ isProject: false }` and the panel hides.
- **`SET_MANAGE_TEAM_DEEP_LINK` Vuex mutation** — new deep-link mechanism `{tab, section, nonce}`. ManageTeamView watches it, switches to the requested tab, and scrolls to `[data-section="…"]` anchors on the Project tab. Nonce forces re-fire on identical successive clicks. Compass items with `link.target === 'manage-team'` route through this mutation.
- **`data-section` anchors** on Manage Team → Project tab: `milestones`, `budget`, `time`. Enables the smooth-scroll + highlight animation used by resource-warning focus.
- **New mapper helpers** — `ExpenseMapper::hasAnyForTeam`, `TimeLogMapper::hasAnyForTeam`. Cheap existence checks used by the Execution-phase readiness signals.
- **~38 new user-facing strings** wrapped in `t()` and translated to nl/de/fr/da/es/it.

### Changed

- **`TeamView::maybeShowProjectGuideForNewTeam`** no longer auto-opens the phase Guide dialog. Kept as a legacy cleanup step for the one-shot store flag. The Guide remains accessible on demand via the Compass "Walkthrough" button and the phase stepper info button.
- **Version bump** 3.97.5 → 3.98.0.

### Removed

- Auto-opening of the ProjectPhaseGuide dialog on new Advanced project creation. The persistent Compass panel takes over the "guide the owner" role.

### Security

- Readiness endpoint is member-gated. Every check reads data the caller already has access to via other endpoints (project fact / milestones / expenses / time logs). The `advance-phase` CTA calls the existing `setPhase` endpoint, which still enforces admin level backend-side.

### Follow-up

- Phase-transition readiness modal ("You should complete these first" list) — Component 3 in the design proposal. Deliberately out of scope this bump.
- Contextual empty-state banners on Budget / Time tabs — Component 4. Small; would spawn as a separate patch.
- Session 7 (Closing phase) will define the Closing checklist items when it lands.

---

## [3.97.5] — 2026-07-07 — Milestone linkage on decisions (Advanced projects, follow-on)

Small extension of Session 6. Advanced project teams can link a proposal to a specific milestone at propose time. Existing decision behaviour is untouched — the milestone linkage is optional and hidden entirely on non-project / Basic-mode teams.

### Added

- **`teamhub_decisions.milestone_id`** (BIGINT NULL) — soft link to `teamhub_milestones.id`. Nullable on every existing row.
- **`DecisionService::propose()`** gains an optional `?int $milestoneId = null` parameter. Rejects with 400 if the passed milestone doesn't belong to the team or the team isn't an Advanced project. `MessageService::createMessage` reads `decisionData['milestoneId']` and passes it through.
- **Decision serialiser** now includes `milestoneId`, `milestoneLabel`, `milestoneDate` — resolved once per row via `MilestoneMapper::findById`. Soft link: if the milestone is later deleted, label/date resolve to null and the frontend hides the chip.
- **`GET /api/v1/teams/{teamId}/milestones/pick`** — member-gated read used by the compose picker. Same shape as the admin-only `/milestones` endpoint. Data is already visible to every member via Timeline + project-health widget, so exposing id/label/date at member scope adds no new leak.
- **PostMessageForm** milestone picker (`NcSelect`), gated on `messageType === 'decision' && project.isProject && project.mode === 'advanced' && milestones.length > 0`. Present on both compose surfaces (inline stream + `ComposeDecisionModal`).
- **DecisionsList + TeamDecisionsView detail panel** show a small milestone chip (`FlagOutline` icon + label, `--color-primary-element` background) when the decision has a milestone linked. Title attribute carries the full label + date on hover.

### Changed

- **Version bump** 3.97.4 → 3.97.5.

### Security

- New endpoint is member-gated via `MilestoneService::listForTeamAsMember` → `MemberService::requireMemberLevel`. The old admin-only `listForTeam` path is unchanged.
- Backend rejects any `milestoneId` on non-Advanced or non-project teams — the frontend gate is defence-in-depth, not the sole check.

### Follow-up

- Filter the Decisions tab by milestone, and roll up decision counts per milestone into the project-health widget's Milestones pillar — both deliberately out of scope this bump.

---

## [3.97.0] — 2026-07-07 — Execution-phase project-health widget (Track E Session 6, closed)

Session-end bump. Track E Session 6 closes the Execution-phase arc with a small draggable **Project health** widget summarising three pillars — Budget & Time bounds, Milestones vs Deck-card ownership, and Quality (open decisions + unsolved questions). Plus an hourly milestone auto-post job that announces reached milestones to the team stream.

### Added

- **`ProjectHealthWidget.vue`** — new draggable widget in the team layout grid. Three tiles, each colour-banded by state (green ok / amber at-risk / red over-bound):
  - **Budget & Time** — lanes over budget + members over hours + project-level over-budget flag. Sourced from `BudgetService::getProjectBudget` and `TimeService::getProjectTime` rollups (single source of truth).
  - **Milestones** — up to 5 upcoming (or most-recent past) dated milestones, each with owned-card counts derived from Deck. A milestone M owns every Deck card whose `duedate` falls in `(previous_milestone.date, M.date]` (or `(project.startDate, M.date]` for the first milestone). Status: on-track (all owned done), at-risk (some open, none overdue), slipping (some open and past their duedate).
  - **Quality** — open decisions (`teamhub_decisions.status IN ('open','finalized')`) plus unsolved question-type messages. Decisions count is only shown when the Decisions module is enabled for the team; a follow-up task tracks wiring Decisions into the Advanced project workflow as a first-class artifact.
- **`GET /api/v1/teams/{teamId}/project/health`** — new endpoint returning the widget envelope. Membership-gated + tab-visibility-gated (canView=false when either can_view_budget or can_view_time is false); the frontend hides the widget in that case.
- **`ProjectHealthService`** — orchestrates the three pillars. Reuses BudgetService + TimeService for the roll-up counters. Deck cards fetched with a hardened direct query mirroring the TimelineService pattern (three-column select with try/catch fallback, handles Deck-version drift).
- **`widget-project-health`** in `LayoutController::DEFAULT_LAYOUT` + `ALLOWED_WIDGET_IDS`. Auto-appears in every user's layout via `mergeNewWidgets`; frontend gate on `showProjectHealthWidget` computed prop hides the render for non-eligible teams so no dead slot appears in normal use.
- **Mobile + tablet layouts** for the widget — matches the existing Decisions widget pattern in both `MobileWidgetView.vue` (icon-bar entry) and the tablet block in `TeamWidgetGrid.vue`.
- **`teamhub_milestones.posted_at`** column (BIGINT, nullable) — set once the auto-post has fired for a milestone.
- **`MilestoneAutoPostJob`** — hourly `TimedJob` that walks milestones where `milestone_date <= now AND posted_at IS NULL`, posts "Milestone reached: {label}" to the team stream (authored by the milestone's `created_by`), and stamps `posted_at`. Advanced projects only; Basic/non-project teams get their milestones stamped-and-skipped so they don't linger.
- **`MilestoneAutoPostService`** — sweep body. Bypasses `MessageService::createMessage` (which requires an authenticated userSession) by writing directly through `MessageMapper::create`. Author attribution goes to the milestone's `created_by` so the post carries a real user on the stream.
- **Two mapper helpers**: `DecisionMapper::countByTeamAndStatus`, `MessageMapper::countUnsolvedQuestions`, `MilestoneMapper::findDueUnposted`.
- **27 new user-facing strings** wrapped in `t()`/`n()` and translated to all 6 project locales (nl/de/fr/da/es/it), plus 2 backend strings for the milestone auto-post message.

### Changed

- **Version bump** from 3.96.0 to 3.97.0 in `appinfo/info.xml` and `package.json`.
- **`ProjectController`** gains a `getHealth` action + a `ProjectHealthService` constructor dependency. No behaviour change to existing project/get, project/save, project/setPhase routes.

### Security

- Widget endpoint is member-gated (`requireMemberLevel`) and additionally visibility-gated (both `canUserViewBudgetTab` and `canUserViewTimeTab` must be true). Non-members get 403 via the standard mapper; below-floor viewers get `canView: false` and no data. Same defence-in-depth as Budget/Time endpoints.
- Milestone auto-post writes bypass `MessageService::createMessage` gate checks (no post-min-level enforcement) because the job runs in a system context, but the message is scoped to the milestone's own team and authored by the admin who created the milestone — so the write is trivially authorised.

### Follow-up (out of scope this session)

- Wire the Decisions module into the Advanced project workflow so the Quality signal has real data by default. Spawn-task chip logged during the session.

---

## [3.96.0] — 2026-07-06 — Execution-phase Time investment page (Track E Session 5, closed)

Session-end bump. Track E Session 5 is complete. Advanced-mode project teams get a new **Time** tab on the team home mirroring the Budget arc's shape.

### Added

- **Time investment tab** (`ProjectTimeView.vue`, new). 4 coloured KPI cards (Available / Logged / Remaining / Utilisation), horizontal member bar chart, horizontal lane bar chart, then a report section with a **view switcher**:
  - **Per lane** (default) — section per Deck stack, table `Member / Activity / Hours` per row.
  - **Per member** — dropdown picker (default = self) + summary strip (threshold-coloured at 70% warn, 100% error) + table `Activity / Lane / Hours`.
- **Time settings section** in Manage Team → Project — view-level dropdown + per-member available-hours grid (`<input type="number" step="0.5">`). No add/remove: every team member is auto-populated via reconcile-on-read.
- **Time investment toggle** row in Manage Team → Integrations (Advanced projects only, default on).
- **9 new endpoints** under `/api/v1/teams/{teamId}/time/…`. See APIendpoints.md for the full contract.
- **Activity widget integration**. Time-log and budget-expense CRUD events surface in the team activity feed with role-gated visibility (Time events only for users who can see the Time tab; expense events only for users who can see the Budget tab). New `if (item.app === 'teamhub')` branch in `formatSubject` on both `ActivityFeedView.vue` and `ActivityWidget.vue`. `ClockOutline`/`WalletOutline` added to icon map; `teamhub → 'Project'` in APP_LABELS.
- **Reconcile-on-read pattern** in `TimeService::getProjectTime` — every fetch walks `MemberService::getAllEffectiveMembers` and inserts `teamhub_project_member` rows (with `available_minutes = 0`) for any team member without one. Owner + newly-added members can log immediately; admins fill in the hours budget later.

### Changed

- **Budget tab visibility model — floor is now authoritative.** `BudgetService::canUserViewBudgetTab` no longer bypasses the role floor when the caller is a named editor of any lane. Named editors still get elevated *edit* rights via `requireLaneWithEdit`, but tab visibility is now a clean single-role check matching Time. This was needed because reconcile-on-read on the Time side would otherwise silently defeat any floor restriction, and having Budget and Time behave the same way is the simpler mental model.
- **Renamed "Logged per workstream" → "Logged per lane"** in the per-lane chart title.
- **State colour tokens** on the Time tab now use `--color-text-success` / `--color-text-warning` / `--color-text-error` instead of the soft `--color-success` etc. `--color-text-warning` is not shipped by NC — defined locally in `.th-time` as `#b45309` (dark amber). Utilisation state thresholds: `< 70%` neutral, `70–100%` warn, `> 100%` over.

### Fixed

- **Sidebar re-click wiping advanced-project state.** Clicking the currently open team's name in the sidebar a second time called `selectTeam` unconditionally, which reset `SET_PROJECT` to `isProject: false`. TeamView's `currentTeamId` watcher didn't re-fire (same teamId), so `loadLayout` never re-ran and the phase stepper, Budget/Time tabs, and Manage Team → Project tab all disappeared until page reload. `App.vue::selectTeamFromSidebar` now guards `if (this.currentTeamId !== teamId)` before dispatching. Pre-existing bug — only became visible once we had four advanced-project affordances hanging off the project fact.

### Schema

- `teamhub_project` — new column `time_view_min_level` SMALLINT DEFAULT 1.
- `teamhub_project_member` (new) — `(team_id, user_id)` unique, `available_minutes` INT DEFAULT 0. Presence in this table means "project participant". Reconciled on read against the team's effective member set.
- `teamhub_time_log` (new) — `card_id` + `stack_id` (denormalised at write for lane-rollup stability) + `user_id` (target) + `created_by` (submitter) + `minutes` INT + `worked_at` UTC-midnight BIGINT + `description` STRING(255). Four indexes covering the report queries.

### Frontend build required

`npm run build`: `ProjectTimeView.vue` (new), `TeamView.vue`, `TeamTabBar.vue`, `ManageTeamView.vue`, `ActivityFeedView.vue`, `ActivityWidget.vue`, `App.vue`, `store/index.js` all touched.

### Translations

~70 new strings translated to Dutch, German, French, Danish, Spanish, Italian across two passes (initial + follow-up for the view switcher and activity subjects).

## [3.95.0] — 2026-07-05 — Execution-phase Budget page (Track E Session 4, closed)

Session-end bump rolling up 3.92.0 → 3.94.3. Track E Session 4 is complete. The full arc is captured in the entries below; this entry is the one-paragraph summary of what shipped.

### Summary

Advanced-mode project teams get a new **Budget** tab on the team home, plus a Budget config section in Manage Team → Project. The tab shows four coloured KPI cards (Project Budget / Allocated / Spent-projected + Real-spent subtitle / Remaining-real that flips red when over budget), a utilisation donut, a per-lane horizontal bar chart, and one card per Deck stack with expenses. The permission model is two clean layers: a project-level "who sees the tab" role floor plus a per-lane edit floor and named-editor override. Named editors on any lane implicitly see the tab (edit implies view). All money as BIGINT minor units — no floating-point drift, portable MySQL/MariaDB/Postgres. Every "real" figure is coloured red / green / neutral against its projected value (over / under / equal). Currency picker: 10 curated ISO codes, backend permissive to any 3-letter code. Lane sync is reconcile-on-read against `TimelineService::getDeckStacks` — no Deck event listeners needed; deleted-stack lanes are retained (expense history survives) but hidden until the stack reappears.

### SKILLS.md — new durable rule

**"UI shapes: circles, not ellipses"** — a six-lock CSS recipe (width + height + min-width + min-height + max-width + max-height + `flex: 0 0 auto` + `padding: 0` + `box-sizing: border-box`) required for round icon buttons. NC's global button reset applies **both** `min-width: 44px` and `min-height: 44px`; fixing only one axis leaves the button an oval on the other. Reference implementation: `.phase-stepper__info`. Grep this before writing any new round icon button.

### Frontend build required

`npm run build`: `ProjectBudgetView.vue` (new), `TeamView.vue`, `TeamTabBar.vue`, `ManageTeamView.vue` all touched.

## [3.94.0] — 2026-07-05 — Layered budget permissions, professional charts, no ellipses

Session-end bump. Three things Justin flagged after using 3.93.0:

### Changed

- **Permission model simplified into two clear layers.** Removed the per-workstream View floor (`view_min_level` on `teamhub_budget_lane`). It's now a single project-level setting: `teamhub_project.budget_view_min_level`. A member sees the Budget tab when their team role is at or above that floor OR they are a named editor on any of the project's workstreams. Once they see the tab, all workstreams are visible; the per-workstream Edit floor + named-editors list still gate who can add/change/remove expenses in each workstream. This maps directly to Justin's two-layer ask: "1. A setting where we set the required role to see the budget tab. 2. A setting where we can choose a role or editor(s) per swimming lane to add/edit/remove expenses. If somebody is added as an editor that member can also see the budget tab." Old column stays in the schema (no destructive migration on live installs); new code doesn't read it.
- **Budget tab visibility signalled via layout bundle.** `LayoutController::getLayout` now returns `budgetConfig.can_view_budget` — a per-request precomputed bool combining the project-level floor and the named-editor override. Frontend gate at `TeamView.buildAllTabDescriptors` now checks `isAdvancedProject && budget_enabled && can_view_budget`. Avoids a second endpoint round-trip on team open.
- **Manage Team → Project → Budget config.** Total-budget row gained a "Who sees the Budget tab" dropdown (member / moderator+ / admin-only). Per-lane table dropped the "View from" column — it was never used at the DB level after this session, and having it in the UI was confusing. `?` popup rewritten around the new two-layer model.

### Added

- **Utilisation donut** at the top of the Budget tab — big SVG circle with dash-array arc showing real spent as % of allocated. Colour follows the same over / under / equal semantics as the numeric colouring: red past 100%, green under, neutral at exactly 100% or when no allocation is set. Percentage label sits in the center of the donut; the arc animates on data change.
- **Per-workstream horizontal bar chart** — one row per workstream: fixed-width name column, a track that goes from 0 to the global project max (allocated OR real), a light "allocated" background bar, and a coloured "real" overlay bar. Numbers to the right. Bars scale against the global max so a small workstream reads as "smaller" at a glance next to a big one, not just "same-sized-with-a-tiny-fill." Replaces the tiny, mostly-invisible grouped bar chart from 3.93.0 (which was also stretched horizontally by a `preserveAspectRatio="none"` I've since removed).
- **`BudgetService::canUserViewBudgetTab($teamId, $userId): bool`** — lightweight visibility precheck used by LayoutController. Never throws; returns false on any failure so a broken read never breaks the layout endpoint.

### Removed / deprecated

- Removed `viewMinLevel` argument from `BudgetService::upsertLane` and `BudgetController::updateLane` (still accepted in the body silently for one release, but unused). Response no longer carries per-lane `viewMinLevel`.
- `teamhub_budget_lane.view_min_level` column stays in the schema — dropping columns risks losing user data on installs I can't see. Backend stops reading it as of 3.94.0.

### Writing style

- **SKILLS.md** — new rule: no ellipsis (`…`) or three-dot (`...`) truncation in UI text (button labels, placeholders, dialog titles, aria-labels, toast messages). Use complete words instead: `Saving`, `Add editor`, not `Saving…` or `Add editor…`. Applied to every string I introduced this session; a broader sweep of pre-existing `Saving…`s elsewhere in the codebase is out of scope for this session.

### Frontend build required

`npm run build`: `ProjectBudgetView.vue`, `TeamView.vue`, `ManageTeamView.vue` all touched.

## [3.93.0] — 2026-07-05 — Budget page polish: graphs, colored real, named editors

Session-end bump. Follow-up on 3.92.x: adds the graphs the budget page was missing, colours the "real spent" figures against the projected baseline, restructures each lane card into three clear rows, and reworks the permission model so specific team members can be granted edit access independent of their role.

### Added

- **Per-lane bar chart (project overview)** — inline SVG grouped bar chart at the top of the Budget page: one group per workstream, three bars per group (allocated / projected / real). The real bar follows the over/under/equal state colouring (see below). Axisless — precise numbers stay in the stats row above; the chart's job is visual comparison at a glance.
- **Per-lane budget bar (in each card)** — horizontal fill showing real spent against the lane's own scale, with a marker line at projected spent. Fill colour follows the state rule. Track uses `--color-background-dark` so it stays legible in both themes.
- **Colored real amount** — `real > projected` renders red (`--color-error-text`), `real < projected` renders green (`--color-success-text`), `real === projected` renders default text colour. Applied to expense rows, per-lane rollup, and the project rollup. A single Vue helper (`realColorClass`) returns one of three modifier classes; CSS specificity fans it out to `color` / `background` / `fill` depending on element type. State colours only fire when there is a projected amount to compare against — a lane with 0 projected renders neutral rather than incorrectly green.
- **Additional editors per lane (`teamhub_budget_lane_editor` table)** — an admin can name specific team members who can edit a workstream's expenses regardless of their team role. Every additional editor implicitly also has view access (edit implies view), so hiding a lane from the general membership while keeping specific members able to work on it is now a two-click configuration in Manage Team → Project → Budget.
- **`?` popup in the Budget config table** — explains View from / Edit from / Additional editors and how they combine.

### Changed

- **Lane card structure** — was one horizontal header row + a table. Now three distinct rows: (1) swatch + name + Add expense button, (2) budget stats + budget bar, (3) expenses table. Reads more clearly and gives the graph a clean home.
- **Responsive grid** — lane cards now sit in a CSS grid: 1 column on narrow viewports, 2 columns at ≥ 900px. Was a single flex column previously.
- **`BudgetService::getProjectBudget`** — `canView = canEdit OR level >= view_min_level`, `canEdit = level >= edit_min_level OR user is an additional editor`. Editors override the role floors entirely. Editor UIDs are resolved to `{uid, displayName}` in the response so the config UI can show real names.
- **`BudgetService::upsertLane`** — accepts `editorUids: string[]`, atomically replaces the editor set for the lane via `BudgetLaneEditorMapper::replaceForLane` (diff-based — kept `created_at` semantics of "first time this UID was added"). Unknown UIDs are refused (validation via `IUserManager::get`).
- **Route `PUT /budget/lanes/{laneId}`** — body now accepts `editor_uids: string[]` alongside the existing fields. Absent field == empty set. See APIendpoints.md.
- **Audit event `project.budget_lane_changed`** — diff payload now includes an `editors` field (comma-separated + sorted UIDs) so an audit trail records who was added/removed.

### Frontend build required

`npm run build`: `ProjectBudgetView.vue`, `ManageTeamView.vue` both touched.

## [3.92.0] — 2026-07-04 — Execution-phase Budget page

Session-end bump. Track E Session 4: Advanced-mode project teams get a Budget tab on the team home. The tab shows the project total + currency, plus one card per Deck stack ("workstream") with allocated / spent-projected / spent-real / remaining plus a list of expenses. Admins configure the project total, currency, per-lane allocations, and per-lane view/edit role gates from Manage Team → Project.

### Added

- **Budget tab (`ProjectBudgetView.vue`)** — new team-home tab, auto-registered when `project.mode === 'advanced'`. Per-lane cards; add / edit / delete expense dialog gated on `lane.canEdit`; auto-refetch on window focus / tab visibilitychange so admin edits made in another tab reflect on return.
- **Per-Deck-stack budget lanes** — every Advanced project's Deck stacks are surfaced as budget lanes (workstreams). Each lane records its own `allocated_minor` (share of the project total), `view_min_level` (member/moderator/admin — controls who sees the lane on the Budget page), and `edit_min_level` (controls who can add or change expenses in the lane). Lane rows are auto-inserted on first read for any current Deck stack that doesn't have one; lanes for deleted stacks are retained in the DB but hidden from the response so expense history survives a stack delete/restore.
- **Expenses** — one row per line item (`teamhub_expense`), scoped to a lane. `projected_minor` is always set, `real_minor` is nullable (null = not yet incurred). Optional `incurred_at` UTC-midnight timestamp.
- **Manage Team → Project → Budget config section** — total budget input + currency picker (curated list of 10 common ISO-4217 codes, backend accepts any valid 3-letter code) + a per-lane table with allocation + view/edit level dropdowns and a Save button per row.
- **Migration `Version000392000Date20260704000000.php`** — atomic schema change covering all three touches: two new columns on `teamhub_project` (`currency`, `budget_total_minor`), new `teamhub_budget_lane` table, new `teamhub_expense` table. All money stored as BIGINT minor units (cents) for portable, drift-free arithmetic on MySQL/MariaDB/Postgres.
- **New endpoints**: `GET /budget`, `PUT /budget`, `PUT /budget/lanes/{laneId}`, `POST /budget/lanes/{laneId}/expenses`, `PUT /budget/lanes/{laneId}/expenses/{expenseId}`, `DELETE /budget/lanes/{laneId}/expenses/{expenseId}`. See APIendpoints.md.
- **Audit events**: `project.budget_total_changed`, `project.budget_lane_changed`, `project.expense_added`, `project.expense_updated`, `project.expense_deleted`.
- **52 translation strings × 6 locales** (nl, de, fr, da, es, it).

### Design

Per-lane role gates for view + edit; sum-of-lane-allocations ≤ project total; permissive backend currency (any 3-letter code) with a curated frontend picker; lane sync reconciles against live Deck stacks on every read (no Deck event listeners); `Intl.NumberFormat` for display with a `toFixed(2)` fallback for exotic currencies. Full rationale in DESIGN.md §2.44.

### Frontend build required

`npm run build`: new `ProjectBudgetView.vue`; `TeamView.vue`, `TeamTabBar.vue`, `ManageTeamView.vue` all touched.

## [3.91.0] — 2026-07-04 — Planning-phase swimlane view

Session-end bump. Track E Session 3: the Timeline tab, for Advanced-mode project teams only, is replaced by a new swimlane component with Deck stacks as workstream lanes, one card per row, single-bar-per-card Gantt display, and orthogonal cross-lane dependency connectors. Basic-mode teams are unaffected — they still get the classic iframe Timeline.

### Added

- **`ProjectSwimlaneView.vue`** — new native Vue component, auto-selected in the Timeline tab whenever `project.mode === 'advanced'`. Deck stacks become horizontal swimlanes, ordered by Deck's own stack `order`; one row per card inside each lane (no collision packing, per user direction — "in the advanced project view we don't work with multiple events in one row"). Every card renders as a single filled bar spanning its real `start`→`due` dates when both are set, or a bar exactly one calendar day wide anchored on the due date otherwise (falling back further to start/created/completed on stacks without a due date). Two view styles toggle in the toolbar: **Lanes** (subject inside the bar) and **List** (fixed 200px left name column + timeline pane). Zoom levels: 1 month / 3 months / 6 months. Milestones remain full-height vertical marker lines across all lanes.
- **New `start` timeline event type** — surfaced from Deck 1.16+'s new `deck_cards.startdate` column (added by Deck migration `Version11002Date20260312000000.php`, nullable `datetime`). Absent on older Deck installs; degrades gracefully (bar falls back to due-only marker). Never proxied from `last_modified` — we don't invent a start Deck itself doesn't have.
- **Per-lane auto-colours** — 8-entry palette keyed by Deck stack `order` (falling back to `stackId` when `order` is null, e.g. installs predating `BackfillDeckStackOrder`). Small colour swatch chip renders left of each lane name in both views. State colours (overdue/completed) still override to `--color-error`/`--color-success` per the SKILLS.md full-saturation rule.
- **Orthogonal cross-lane dependency connectors** — dark grey (`var(--color-text-maxcontrast)`), 90° corners only (no diagonals). Line leaves the blocker's right edge, turns down/up at a midpoint corner, enters the successor's left edge. Uses SVG polyline. Gated by `timelineConfig.card_dependencies_supported` (NC 34 / Deck 1.18+ `deck_dependent_cards` table).
- **In-canvas popover** — clicking a bar opens a small panel positioned adjacent to it (inside the same scrolling canvas as the bar), showing title, start/due dates, board, column, assignees, description snippet, and an "Open in Deck" link. Escape/click-outside close it, focus returns to the trigger. Deliberately not `NcPopover` — see DESIGN.md §2.42.
- **Auto-refetch on window focus / tab visibilitychange** — so edits made to a card in Deck (in another tab) reflect on return to the swimlane without a full page reload.
- **`stackId` / `stackOrder` in Deck event meta** — every `source: 'deck'` event now carries its stack identifier so the frontend can group events into lanes without string-matching `stackName` (which isn't guaranteed unique across boards).
- **`TimelineService::getDeckStacks(string $teamId): array`** — public method returning the full, date-independent, order-sorted list of stacks connected to a team, so a stack with zero cards in the currently-viewed window still renders as an empty lane. Shares board/stack resolution with `fetchDeckEvents()` via a new private `resolveTeamDeckStacks()` helper.

### Changed

- **`GET /api/v1/teams/{teamId}/timeline`** — response envelope is now `{events: [...], stacks: [...]}`. `stacks` is Deck-specific and independently try-caught; a Deck-side failure returns `stacks: []` without breaking `events`. Existing consumers see the additional key and can ignore it — no breaking change.
- **`TimelineService::fetchDeckEvents()`** — now introspects `deck_stacks` for an `order` column (safe on both MySQL/MariaDB and Postgres via `IQueryBuilder`, since `order` is a reserved word — never quoted or interpolated as a raw SQL string) and includes the value in each event's `meta`. NULL orders sort last, tie-broken by `stackId` — deterministic on both DB backends without relying on default NULL ordering.
- **`TeamView.vue`** — the Timeline tab now branches on `isAdvancedProject`: Advanced-mode projects render `<ProjectSwimlaneView>`; Basic-mode teams keep the existing `<AppEmbed>`-based iframe Timeline exactly as before. The classic Timeline's control state (period nav, source filters, print, menu toggles) is unchanged and untouched by this session.

### Frontend build required

`TeamView.vue` touched (single conditional branch); new `ProjectSwimlaneView.vue` component.

## [3.90.0] — 2026-07-03 — Project-owner onboarding + Deck Project management stack

Session-end bump. Two features on Track E (Project Teams roadmap) plus three live bug fixes found and fixed against Justin's instance during the same session.

### Added

- **Project-owner onboarding guide** — a one-time dialog (`ProjectPhaseGuide.vue`) auto-opens for the owner right after an Advanced project team is created, explaining what to do in the current phase (a full checklist for Planning; a short "coming soon" note for Execution/Closing, since dedicated tooling for those phases doesn't exist yet) and how to advance a phase via Manage Team → Project. Reopenable any time via a new "About this phase" info button on the phase stepper. Triggered by a one-shot, non-persisted Vuex flag — see DESIGN.md §2.39.
- **Deck "Project management" stack** — every Advanced project's Deck board now always gets a 4th stack (alongside To do / In progress / Done), pre-populated with 4 starter cards: Invite project members, Create project contract, Add project milestones, Schedule the planning kickoff meeting. Each card is assigned to the team creator with a due date 7 days out, so the board isn't empty on day one. Basic/Collaboration/Department teams are unaffected — unchanged 3-stack, no-card behaviour. See DESIGN.md §2.40.
- **`GET /api/v1/teams/{teamId}/intravox/team-page`** — new endpoint returning a team's own IntraVox page, resolved unambiguously by path rather than title alone. See Fixed, below, and APIendpoints.md.
- **`GET /api/v1/admin/deck-diagnostic`** — new admin-only, read-only diagnostic (reflection on `CardMapper` + defensive probes for the assignee-write API), kept permanently alongside the existing `intravox-diagnostic` as a reusable discovery tool for future Deck-API work.

### Fixed

- **IntraVox page lookup was ambiguous across multiple Advanced-project teams.** Every Advanced project's IntraVox page is titled "Contract" (translated) since 3.89.0 — once more than one such team exists, the widget's old title-only match (a client-side bulk fetch + `.find()`) could resolve to the *wrong* team's page, or none at all (reported by Justin: team "Flow 6"'s page existed on disk but never appeared in the widget). Fixed properly: new `IntravoxService::getTeamPage()` shortlists title-candidates then confirms each one's real folder **path** via a follow-up `getPage()` call, returning only the exact match. `getSubPages()` had the identical latent bug in its own root-page lookup and now shares the same fix. See DESIGN.md §2.41.
- **Team-type selector coloring** — reverted the 3.88.0 soft-tint selection-tile exception (DESIGN.md §2.37) back to full-saturation `var(--color-success)`/`var(--color-primary-element)` at Justin's request ("It should use the darker green"). No scoped exception to the SKILLS.md full-saturation rule remains.
- **Phase stepper visual polish** — the bar now spans the full width of the canvas (phase connectors stretch to fill the available space, instead of the whole group clustering on the left); the "About this phase" info button — a real `<button>`, unlike the stepper's other `<span>`-based circular markers — now renders as a true circle instead of an oval, caused by Nextcloud's global button `min-width` overriding an unqualified `width`. See DESIGN.md §2.36 addendum.

### Performance

- Added a 5-minute cache to `getTeamPage()` (`teamhub_intravox_teampage_{teamId}`), matching `getSubPages()`'s existing cache — needed because the path-based lookup now does real backend work (up to a few `getPage()` calls) on every widget mount, where the old (incorrect) approach made none.

### Frontend build required

`ProjectPhaseStepper.vue`, `IntravoxWidget.vue`, `CreateTeamView.vue`, `TeamView.vue`, `ManageTeamView.vue` all touched; new components `ProjectPhaseGuide.vue`.

## [3.89.0] — 2026-07-03 — IntraVox project charter

Advanced Project teams' auto-created IntraVox page is no longer a blank canvas — it's seeded with the 9-element PMC (Projectmatig Creëren) project-definition charter, translated to all 6 locales and rendered in the creating user's own NC language.

### Added

- **9-element project-definition charter**, seeded automatically when an Advanced Project team is created with the Pages module enabled. Rendered as IntraVox's native collapsible/FAQ-style sections — one per element (Challenge or Problem, Urgency, Objective, Result, Scope, Effects, Users of the end result, Constraints, Relationship to other projects and programmes), each with a guiding question. Source: [pmc-online.nl](https://pmc-online.nl/structuur/projectdefinitie/), translated to English then localised into nl/de/fr/da/es/it.
- **`IntravoxService::buildProjectCharterLayout()`** — resolves the creating user's NC language the same way `DeckService::translateDefaultStackTitles` does (per-user `IConfig` lookup → `default_language` → English), so the charter renders in the creator's language automatically.
- Basic/Collaboration/Department teams are unaffected — they keep today's blank-page behaviour exactly.

### Fixed

- **`IntravoxService::createPage()` was silently ignoring seeded content.** Confirmed empirically (via a diagnostic write/read-back probe) that `createPage()`'s `$data['layout']` is discarded — the page comes back with an empty layout regardless. Content must be set via a follow-up `updatePage()` call, which itself rebuilds the whole page from `$data` rather than merging — `title` must always be included or IntraVox's internal `sanitizeText($data['title'])` throws on a null value. See DESIGN.md §2.38.
- **Section titles containing an apostrophe rendered as literal `&apos;`** (e.g. Dutch "programma's", French "d'autres"). Root cause is in IntraVox itself — `sanitizeText()` HTML-encodes apostrophes for `title`/`sectionTitle` fields, but IntraVox's frontend renders them as plain text without decoding. Not something TeamHub can configure around; fixed by rephrasing the two affected charter titles to avoid apostrophes. Question/body text is unaffected (uses `sanitizeHtml()`, confirmed safe).
- **"Scope (out of scope)"** simplified to **"Scope"** across all 7 locales — matches the terse-noun style of the other 8 titles; the "what's excluded" framing already lives in the guiding question.

### Frontend build required

`CreateTeamView.vue` — sends `projectMode` in the IntraVox page-creation request.

## [3.88.0] — 2026-07-03 — Project Teams keystone

Session-end bump. Rolls up 3.87.1 (the in-session minor). Persists project-ness for teams created from the "Project" template — the previously-cosmetic template choice ([CreateTeamView.vue](src/components/CreateTeamView.vue) `form.teamType`) is now recorded server-side and drives a Basic/Advanced lifecycle. Foundational session: no charter/swimlane/budget/dashboard tooling yet — see DESIGN.md §2.36 and HANDOFF.md for the deferred roadmap.

### Added

- **`teamhub_project` table** (new migration) — one row per team created from the Project template. `mode` (`basic`|`advanced`) is the lifecycle discriminator; `phase` (`initiation`|`planning`|`execution`|`closing`) is meaningful only for `advanced` projects and starts on `planning` (Initiation is assumed already cleared by the time a team exists). `start_date`/`target_end` reserved for future phase-aware tooling.
- **`ProjectService`** — `getForTeam` (member-gated read), `upsert` (admin-gated create/mode-change, including Basic → Advanced upgrade), `setPhase` (admin-gated, advanced-only). Audit events: `project.created`, `project.mode_changed`, `project.updated`, `project.phase_changed`.
- **3 new endpoints**: `GET/PUT /api/v1/teams/{teamId}/project`, `PUT /api/v1/teams/{teamId}/project/phase`. See APIendpoints.md.
- **Project mode selector** in the create wizard (Step 1, Project template only) — Basic (cosmetic, no lifecycle UI) vs Advanced (full PMC phase lifecycle). Persists on team creation.
- **`ProjectPhaseStepper.vue`** — read-only 4-phase stepper (Initiation → Planning → Execution → Closing) shown on the team Home view for Advanced projects. Visible to every member; phase changes are admin-only.
- **Manage Team → Project tab** — new standalone tab (Project-template teams only): phase selector for Advanced projects, "Upgrade to Advanced" action for Basic ones. Previously would have lived under Integration settings, but a project isn't an integration that can be toggled on/off, so it got its own tab instead.
- Project fact rides the existing `/layout` bundle response (`LayoutController::projectFacts()`) rather than a dedicated fetch — no extra HTTP round-trip on team open.

### Fixed

- **Unreadable hover state on a selected Basic/Advanced tile** — `.ctv__mode:hover` and `.ctv__mode--selected` had a CSS-specificity tie that `:hover` won, reverting the background to grey while the selected tile's white text stayed, making it unreadable. Added `.ctv__mode--selected:hover` to re-assert the selected colours.
- **Team-type selector card colours** — Project/Collaboration/Department cards previously had per-type accent colours (yellow, blue, green) that changed on hover. Unified to a single scheme: white background + green edge when unselected, bolder green edge + light-green fill when selected, dark text throughout. Documented as a scoped exception to the SKILLS.md full-saturation state-colour rule (DESIGN.md §2.37) — selection tiles, not chips/pills/banners.

### Changed

- **SKILLS.md** — corrected stale frontend standards: Vue 2.7 → 3.x, Vuex 3.x → 4.x, `@nextcloud/vue` 8.x → 9.x. The codebase migrated to Vue 3 (Options API) some time ago; the working-agreement doc hadn't caught up.

### Frontend build required

`CreateTeamView.vue`, `ManageTeamView.vue`, `TeamView.vue` all touched — new component (`ProjectPhaseStepper.vue`) and store wiring (`src/store/index.js`).

## [3.87.0] — 2026-07-01 — Session-end rollup

Second session boundary of the 2026-06-30/07-01 window. Rolls up two post-3.86.0 patches (3.86.1 and 3.86.2). Nothing new landed here beyond the version bump and the docs sweep — see the entries below for per-change detail.

### Rolled up

- **3.86.1** — Timeline Deck-assignee fetch: skip `DbIntrospectionService` entirely, try (table, participantColumn) variants directly with try/catch. Introspection was silently returning `[]` on installs where the table exists but is empty (Strategy 2 `SELECT * LIMIT 1` fails on empty tables + Strategy 3 `INFORMATION_SCHEMA` can be access-restricted).
- **3.86.2** — Shared-files tab in Filecenter widget always empty. Backend was still gating on the `shared_files` toggle that the frontend removed when the widget was folded in. Removed the gate and swept the remaining plumbing from `ResourceService`, `TeamController` (`$allowed`, `$toggleOnlyAppIds`), `TelemetryService::getBuiltinIntegrationUsage`, and dead comments in `ManageTeamView.vue`.

### Changed

- Deck-assignee logging in `TimelineService` demoted from `info` to `debug`. The info level was appropriate during the diagnostic phase but fires on every timeline fetch — would bloat production logs. Admins can set `loglevel=0` for the diagnostic capability when needed.

### Frontend build required

`ManageTeamView.vue` was touched at comment level only during 3.86.2. If deploying from the previous session's 3.86.0 baseline you already needed `npm run build` for `AdminSettings.vue` / `BrowseTeamsView.vue` / `TeamTabBar.vue`; this rollup adds nothing to that.

## [3.86.2] — 2026-07-01 — Shared files widget: unblock + toggle sweep

### Fixed

- **Shared-files tab in the Filecenter widget was always empty on every team.** When the standalone Shared-files widget was folded into the Filecenter widget as an always-on tab, its per-team `shared_files` enable/disable toggle was removed from the frontend. But the backend endpoint `GET /api/v1/teams/{teamId}/files/shared` still short-circuited to `{items:[], total:0}` when the toggle was off — and since the toggle defaults to `false` for teams without an explicit row, that path was hit for every team. Removed the toggle gate in [lib/Controller/TeamController.php:753-760](lib/Controller/TeamController.php:753); shares now appear whenever the current user is a team member.

### Removed

- **`shared_files` toggle plumbing swept from backend.** The toggle no longer serves a purpose (the widget is always on when Files is enabled); leaving it in place would only invite future confusion.
  - `ResourceService::getTeamResources` — dropped the `shared_files` key from the returned array and the DB lookup that populated it (`lib/Service/ResourceService.php` ~L125, ~L144-167).
  - `TeamController::deleteTeamResource` — dropped `'shared_files'` from the `$allowed` app-id allowlist (line 311).
  - `TeamController::updateTeamApps` — dropped `'shared_files'` from `$toggleOnlyAppIds` (line 1250).
  - `TelemetryService::getBuiltinIntegrationUsage` — dropped the `$defaultDisabled = ['shared_files']` path and simplified the aggregation to default-enabled apps only. `builtin_integrations` telemetry no longer emits a `shared_files` key.
  - `ManageTeamView.vue` — dead-comment references to `shared_files` in `toggleApp()` and CSS section header removed.
- Legacy rows for `app_id='shared_files'` in `teamhub_team_apps` are left in place and simply ignored. No migration is added — the rows are harmless.

### Frontend build required

`ManageTeamView.vue` was touched (comment-only change), so a rebuild isn't strictly necessary for correctness. But if you're deploying the PHP changes anyway, run `npm run build` for cleanliness.

## [3.86.1] — 2026-06-30 — Timeline Deck assignees: skip introspection

### Fixed

- **Deck assignees still didn't appear in the timeline popover after 3.85.11**, because the fetch was still gated on `DbIntrospectionService::getTableColumns()`. On the failing install, no `[TimelineService] Deck assignees` log line was emitted at all — meaning introspection returned `[]` for BOTH candidate tables even though the data (and the tables) exist. Root cause: DbIntrospection Strategy 2 (`SELECT * LIMIT 1`) fails on empty tables and Strategy 3 (`INFORMATION_SCHEMA`) can be access-restricted, so the introspection silently returns `[]` and every downstream lookup that gates on it silently skips.
- Rewrote the fetch to skip introspection entirely and try (table, participantColumn) pairs directly with `try`/`catch`, same pattern the existing ACL fallback in `fetchDeckEvents` already uses. A missing-table SQLSTATE[42S02] just bumps to the next variant. Four variants tried: `deck_card_assigned_users` × (`participant_uid`, `participant`), then `deck_assigned_users` × (`participant_uid`, `participant`). Type filter is tried optimistically and dropped if the column doesn't exist. Log line emitted at `info` level so it's visible without debug loglevel: `Deck assignees: lookup done hit=<table/col or <none>> cardsWithAssignees=N`. New private helper `fetchDeckAssigneesForVariant()`. PHP only — no `npm run build`.

## [3.86.0] — 2026-06-30 — Session-end rollup

Session boundary per SKILLS.md §session-end §3. Rolls up 3.85.1 → 3.85.11 — eleven incremental fixes and features in one bug-focused session.

### Headline changes

- **Deck stack `order` bug** (3.85.1) — TeamHub-created Deck columns landed with NULL `order`, blocking renaming in the Deck UI. `DeckService::createDeckBoard` now prefers `\OCA\Deck\Db\StackMapper::insert()` (mirrors the BoardMapper pattern); QB fallback always sets `order` + `last_modified` with `PARAM_INT`. New `BackfillDeckStackOrder` post-migration repair step heals existing NULL rows per board.
- **TeamTabBar "More…" dropdown fixes** (3.85.2 / 3.85.3) — viewport clamping (was spilling past the right edge), dynamic max-height from space below trigger, and explicit `overflow-x: hidden` to suppress the CSS-spec-mandated horizontal scrollbar promotion.
- **Browse Teams regressions** (3.85.4) — `gap: 8px` added to `.team-card__actions` (Open/Leave were flush); removed the `<Magnify>` icon from the search `<NcTextField>` slot because `@nextcloud/vue 8.x` turns it into a trailing button that overlaid the input and stole pointer events (input was unfocusable).
- **Default Deck stack titles localised** (3.85.5 / 3.85.6) — "To do" / "In progress" / "Done" now translated to the board creator's NC language using TeamHub's own catalogue. 2 new strings × 6 locales.
- **Talk reconciler federated-user blind spot** (3.85.7) — `reconcileEffectiveTalkRoomMembers` was direct-only on `actor_type='users'`; rewrote to handle `'users'` AND `'federated_users'` with `(actor_type|actor_id)` keying. `removeUserFromTeamTalkRoom` deletes from both. `MemberService::removeMember` line 1238 swapped from legacy `reconcileTalkRoomMembers` to effective-aware variant (closes a long-standing HANDOFF open issue).
- **Timeline item popover** (3.85.8 / 3.85.9 / 3.85.11) — click any chip → small dialog with title, labelled date row, source-specific detail rows, optional description/snippet, and "Open in [app] →" button. Modifier-clicks still navigate natively. 15 inline MDI SVG icons replace the initial emoji set. `TimelineService` extended with description, organizer, attendee count, proposedBy / decidedBy, deck card description, message body snippet, milestone createdBy, and Deck assignees. New `resolveDisplayNames()` resolves UIDs via `IUserManager` with a per-request cache. Deck assignee fetch hardened with `DbIntrospectionService` to handle both `deck_card_assigned_users.participant_uid` (newer Deck) and `deck_assigned_users.participant` (older).
- **Admin Statistics → Instance summary** (3.85.10) — two prominent stat cards at the top of the Statistics tab: Teams (total source=16 circles) and Unique team members (distinct effective people across all teams, `circles_membership` ↦ `circles_member user_type IN (1, 4)`). New `TelemetryService::countUniqueTeamMembers()`. `unique_team_members` added to telemetry payload. Designed as the per-seat license metric a future commercial-license model can key off.

### Added

- 5 new user-facing strings translated to **nl / de / fr / da / es / it**: `To do`, `In progress`, `Instance summary`, `Unique team members`, and the Instance-summary description.
- New PHP file: `lib/Migration/RepairSteps/BackfillDeckStackOrder.php` (registered in `appinfo/info.xml`).
- New private service methods: `DeckService::translateDefaultStackTitles`, `TelemetryService::countUniqueTeamMembers`, `TimelineService::truncateForPopup`, `TimelineService::resolveDisplayNames`.
- `unique_team_members` added to the telemetry response shape (documented in `APIendpoints.md`).
- Documented the per-source `meta.*` additions for the timeline endpoint in `APIendpoints.md`.

### Frontend build required

`AdminSettings.vue`, `BrowseTeamsView.vue`, `TeamTabBar.vue` all touched. One `npm run build` covers everything. `templates/timeline.php` is server-rendered (no build step).

### Security

- No new endpoints added. All existing auth gates unchanged.
- Talk reconciler's broader actor-type handling preserves the room OWNER (`participant_type=1`) protection — owners can never be evicted by either the immediate or the hourly path.
- Telemetry's `unique_team_members` is an aggregate count; no UIDs or content leave the instance.
- Timeline popover renders every user-supplied string through the existing `esc()` HTML-escaping helper (titles, descriptions, snippets, display names). The "Open in [app] →" anchor uses server-generated URLs only (calendar edit URL, deck card URL, TeamHub deep link) — not user-controlled input. `target="_top"` + `rel="noopener noreferrer"` retained.

## [3.85.11] — 2026-06-30 — Timeline popover: Deck assignee fetch hardened

### Fixed

- **Deck card assignees never appeared in the timeline popover** even when the card had an assignee. The 3.85.9 fetch hard-coded `deck_card_assigned_users.participant_uid`, but Deck has shipped two table names and two participant-column names for the same data over its history (`deck_card_assigned_users.participant_uid` newer, `deck_assigned_users.participant` older). On installs where either pair didn't match, the query silently returned zero rows and the popover never rendered the "Assigned to" row. Reworked the fetch to detect both tables and both column names via `DbIntrospectionService` and use whichever exists. Also: when a `type` column is present (Deck's 0=user / 1=group / 2=circle convention), filter to `type=0` so group/circle assignment labels don't get rendered as if they were people. Debug log line emits `table=… participantCol=… cardsWithAssignees=…` so future installs make the lookup state observable. No `npm run build` required (PHP only).

## [3.85.10] — 2026-06-30 — Admin Statistics: team + unique-user counts

### Added

- **"Instance summary" section at the top of admin Statistics tab.** Two prominent stat cards: **Teams** (total source=16 circles) and **Unique team members** (distinct people across every team's effective membership). Designed as the headline metric a future per-seat licence model can key off.
- **New `TelemetryService::countUniqueTeamMembers()`** walks `circles_membership` (the denormalised cache, so users reaching a team via group / sub-team are counted once), joins to `circles_circle` filtered on `source=16`, and to `circles_member` filtered on `user_type IN (1, 4)` — local + federated users, same set treated as "people" elsewhere (Talk reconciler, etc). DISTINCT applied in PHP for cross-DB portability (no `COUNT(DISTINCT col)` shortcut in NC's IFunctionBuilder).
- `unique_team_members` added to the telemetry payload (alongside the existing `team_count`). Docblock payload-shape comment updated.
- 3 new user-facing strings translated to **nl / de / fr / da / es / it / en**: `Instance summary`, `Unique team members`, and the section description.

### Frontend build required

`AdminSettings.vue` was touched — run `npm run build`.

## [3.85.9] — 2026-06-30 — Timeline popover: polish pass

### Changed

- **Emoji icons replaced with inline Material Design Icon SVGs.** The 3.85.8 popover used emoji glyphs (📍 📅 👤 👥 📋 📑 ⚡ 🎯 🏷 ✅ ⚠ 📌 ✓ ↩) as row markers; replaced with a curated set of 15 inline MDI SVGs (`map-marker`, `calendar`, `calendar-clock`, `account`, `account-multiple`, `view-dashboard`, `list-square`, `flash`, `target`, `tag`, `check`, `check-decagram`, `undo-variant`, `pin`, `alert`, `plus-circle`, `circle-small`). Sized 14×14, `fill="currentColor"` so they pick up the surrounding text colour. Same visual language Nextcloud itself uses across the UI.
- **Date row now always labelled.** Previous build showed "do 2 jul 2026" alone under the title — ambiguous: created? due? completed? Replaced the standalone `.th-popover__when` block with an in-grid detail row whose label is computed from `(source, type)`: calendar→When, decisions→Proposed/Decided/Withdrawn, deck→Due/Created/Completed, messages→Posted, milestone→Date. Deck "due" rows additionally use the `calendar-clock` icon to reinforce the deadline framing.

### Added

- **Deck card assignees in the popover.** `TimelineService::fetchDeckEvents` now does a single batched query against `deck_card_assigned_users` (`participant_uid`, `card_id`) for every card that emitted at least one event, and attaches `meta.assignees` (raw UIDs). `resolveDisplayNames()` was extended to handle array-of-UID meta keys and adds an `assigneeNames` companion via `IUserManager`. Popover renders "Assigned to {name}" (single) or "Assignees {a, b, c}" (multiple). Falls back silently if `deck_card_assigned_users` doesn't exist on this Deck version. No new schema, no `npm run build` required.

## [3.85.8] — 2026-06-30 — Timeline item popover

### Added

- **Click any timeline chip to open a small popover with the key fields for that item before navigating to the source app.** The chip's `<a href>` is preserved, so middle-click / ctrl-click / cmd-click / shift-click still open the underlying URL the way the browser normally would; only plain left-click is intercepted. The popover ends with an "Open in [Calendar / Deck / TeamHub] →" button that follows the same URL. Click outside or press Esc to dismiss. Position auto-flips above the chip when there's no room below, and clamps horizontally inside the viewport.
- **`TimelineService` now returns rich per-event details** so the popover can render meaningfully without a second roundtrip:
  - Calendar events: `description` (truncated), `organizer` (CN or mailto), `attendeeCount`, plus the existing `location` / `calendarName`.
  - Decisions: `proposedBy` + `decidedBy` raw UIDs (already had status / impact / level / category).
  - Deck cards: `description` (truncated).
  - Messages: `snippet` (truncated body) + the existing `authorId` / `pinned`.
  - Milestones: `createdBy`.
- **Display-name resolution.** A new private `TimelineService::resolveDisplayNames()` walks the event list once after assembly and adds `*Name` companions (`proposedByName`, `decidedByName`, `authorName`, `createdByName`) via `IUserManager::get()->getDisplayName()` with an in-method cache. Missing/deleted users fall back to the raw UID. One pass per request, not per event.
- New private `TimelineService::truncateForPopup()` helper normalises whitespace and caps free-form text at 280 characters with an ellipsis.

### Changed

- Timeline chip's `title` attribute updated from "click to open" to "click for details" to set the right expectation.

### No frontend build required

`templates/timeline.php` is rendered server-side and shipped as-is; the popover CSS + JS live inline in that template. `TimelineService.php` is PHP. **No `npm run build` needed** for this change.

## [3.85.7] — 2026-06-30 — Talk reconciler: federated user eviction

### Fixed

- **Removed members could persist in a team's Talk room indefinitely if they were federated users.** Symptom: TeamHub member list shows the user is gone, Talk participant list still shows them after the hourly cron has run multiple times. Three coordinated changes:
  1. `TalkService::reconcileEffectiveTalkRoomMembers` now considers BOTH `actor_type='users'` AND `actor_type='federated_users'`. Previously it only fetched local-user attendee rows, so federated drift was invisible to the eviction loop. The effective member set is now computed for `circles_member.user_type IN (1, 4)` and keyed by `actor_type|actor_id` so a local `justin` and a federated `justin@host.com` never collide.
  2. `TalkService::removeUserFromTeamTalkRoom` (called on direct-user removal) now deletes from both `'users'` and `'federated_users'` for the given actor_id, so the right row is always cleaned up regardless of how Talk's circle-expansion originally categorised the attendee.
  3. `MemberService::removeMember` (group/circle removal path, line 1238) swapped from the legacy direct-only `reconcileTalkRoomMembers` to the effective-aware `reconcileEffectiveTalkRoomMembers`. Resolves a long-standing HANDOFF open issue — users still reachable via another attached group are now correctly preserved instead of being evicted.

  Room OWNER preservation (`participant_type=1`) and all other behaviour unchanged. PHP-only — no `npm run build` required.

## [3.85.6] — 2026-06-30 — Default Deck stacks: own the translations

### Fixed

- **Only "Done" was translated in Dutch boards after 3.85.5; "To do" and "In progress" stayed in English.** Deck's translation coverage for these three strings is uneven — Dutch had `Done` → `Klaar` but not `To do` / `In progress`, so the bare source strings leaked through.
- Switched `DeckService::translateDefaultStackTitles` to use **TeamHub's own catalogue** (`Application::APP_ID`) instead of Deck's. Added `To do` and `In progress` to all seven locale files (en + nl/de/fr/da/es/it) in both `.js` and `.json`; `Done` already had translations in every locale. Result: every locale now renders all three columns consistently. `TRANSLATORS:` hints added to the three `t()` calls to disambiguate context for future translators. PHP-only change — no `npm run build` required.

### Added

- 2 new user-facing strings (`To do`, `In progress`) translated to **nl / de / fr / da / es / it**.

## [3.85.5] — 2026-06-30 — Default Deck stacks created in the user's language

### Changed

- **Default Deck stacks ("To do", "In progress", "Done") created by TeamHub are now translated into the board creator's NC language.** Previously hard-coded English regardless of user locale. `DeckService::createDeckBoard` now resolves the creator's language via `IConfig::getUserValue($uid, 'core', 'lang')` (falling back to `default_language` then English) and pulls the three titles from **Deck's own translation catalogue** via `IFactory::get('deck', $lang)` — these exact strings have been part of Deck since v1.0, so its `.po` files already carry them in every language Deck ships. No duplication into TeamHub's PO files. New private helper `DeckService::translateDefaultStackTitles($uid)`; falls back to English if anything in the resolution chain fails (`IL10N::t()` returns the source string when a catalogue lacks an entry, which is the correct visible default). PHP-only change — no `npm run build` required.

## [3.85.4] — 2026-06-30 — Browse Teams: button spacing + search input regressions

### Fixed

- **No gap between Open and Leave buttons** on member team cards after the 3.84.3 Open-button addition. `.team-card__actions` was `display: flex; justify-content: flex-end;` with no `gap`, so the buttons sat flush against each other. Added `gap: 8px` (NC default action-row spacing).
- **Search input could not be focused or typed in.** The `<NcTextField>` had a `<Magnify>` icon in its default slot. In `@nextcloud/vue 8.x` the NcTextField default slot is rendered as a trailing icon button that overlays the input area and intercepts pointer events — so clicks landed on the icon button instead of the field. The proven maintenance-tab pattern omits the slot; matched it here. Removed the now-unused `Magnify` import. Frontend rebuild (`npm run build`) required.

## [3.85.3] — 2026-06-30 — More-menu horizontal scrollbar suppressed

### Fixed

- **Spurious horizontal scrollbar at the bottom of the "More…" dropdown.** Follow-up to 3.85.2. Per CSS spec, when `overflow-y` is set to a non-`visible` value while `overflow-x` is left at its default `visible`, `overflow-x` is promoted to `auto` — which produced a horizontal scrollbar whenever sub-pixel rounding nudged any item edge past the container. Added explicit `overflow-x: hidden` to `.teamhub-tab-more-menu` so the horizontal axis is truly non-scrolling. Frontend rebuild (`npm run build`) required.

## [3.85.2] — 2026-06-30 — More-menu viewport clamping

### Fixed

- **TeamTabBar "More…" dropdown could fall partly off the right edge of the viewport and showed a scrollbar that wasn't needed.** The menu was anchored to `rect.left` of the trigger (which sits near the right edge of the tab bar) with `min-width: 180px`, so on narrower viewports it overflowed the window. The static `max-height: 300px` combined with `overflow-y: auto` could also reserve a scrollbar gutter even when content fit. `repositionMoreMenu()` now clamps `left` to `min(rect.left, window.innerWidth − menuWidth − 8)` so the menu always stays inside the viewport with an 8px margin, and computes `max-height` dynamically from the space remaining below the trigger so the inline `overflow-y: auto` only engages when content genuinely exceeds that space. Frontend rebuild (`npm run build`) required.

## [3.85.1] — 2026-06-30 — Deck stack `order` bug

### Fixed

- **Deck columns created via TeamHub could not be renamed.** When `DbIntrospectionService::getTableColumns('deck_stacks')` fell through to a degraded path (HANDOFF.md notes this is possible on some installs), the `order` value was silently omitted from the stack INSERT — rows landed with NULL `order`, and Deck's UI refuses to rename a stack with NULL order until the user manually reshuffles columns. `DeckService::createDeckBoard` now mirrors the BoardMapper pattern: tries `\OCA\Deck\Db\StackMapper::insert()` first (uses Deck's own setters, always sets order). On exception falls back to a hardened QB insert that always sets `order` + `last_modified` (not gated on introspection) and binds integer columns as `PARAM_INT`.

### Added

- New post-migration repair step `BackfillDeckStackOrder` (registered in `appinfo/info.xml`). Idempotent: finds existing `deck_stacks` rows with NULL `order` and assigns sequential values per board starting at `MAX(existing order) + 1`. Skips cleanly if the Deck app isn't installed. Heals teams created in 3.85.0 and earlier without user intervention.

## [3.85.0] — 2026-06-30 — Session-end rollup

Session boundary per SKILLS.md §session-end §3. Rolls up 3.84.1 → 3.84.8.

### Headline changes

- **Audit-tab "Find teams for a user"** (3.84.1) — new admin panel finds every team a user belongs to (direct + group + sub-team inherited) with role, owner, source classification, and bulk remove. Two new `MaintenanceController` endpoints; new service methods; per-row audit events.
- **SSO empty-state hint** (3.84.2) in `InviteMemberModal` — explains why lazy-provisioned Microsoft Entra / SAML / OIDC users only appear after first login.
- **Browse Teams "Open" button + TeamSearchProvider** (3.84.3) — direct-jump to a team you're already a member of; new unified-search provider surfaces TeamHub teams with deep links.
- **Hourly Talk-membership reconciler** (3.84.4) — new `TalkMembershipReconcileJob` + `TalkService::reconcileEffectiveTalkRoomMembers` close long-standing drift gaps for group/sub-team membership changes.
- **Invite-action role guards** (3.84.5) — three Invite-user buttons gated by `isTeamModerator`. Audit pass mapped every TeamWidgetGrid action emit to its backend role requirement.
- **Audit-tab user search wiring fix** (3.84.6) — autocomplete now fires per keystroke; Enter flushes the debounce.
- **Two small fixes** (3.84.7 / 3.84.8) — column alias whitespace bug; grid CSS selector targeting the actual grid container.

### Added

- 26 new user-facing strings, all translated to **nl / de / fr / da / es / it**.
- New PHP files: `lib/Search/TeamSearchProvider.php`, `lib/BackgroundJob/TalkMembershipReconcileJob.php`.
- New service methods: `MaintenanceService::listTeamsForUser`, `MaintenanceService::adminRemoveUserFromTeam`, `MaintenanceService::resolveUserSingleId` (private), `TalkService::reconcileEffectiveTalkRoomMembers`.
- New `MaintenanceController` endpoints: `GET /api/v1/admin/maintenance/users/{userId}/teams` and `POST /api/v1/admin/maintenance/users/{userId}/remove-from-teams`.
- New audit event type: `member.removed_by_admin`.

### Frontend build required

All Vue components touched (AdminSettings, InviteMemberModal, BrowseTeamsView, App, TeamWidgetGrid, MobileWidgetView). One `npm run build` covers everything.

### Security

- Both new admin endpoints gate at `#[AuthorizedAdminSetting]` attribute + service `requireNcAdmin()` defence in depth.
- `TeamSearchProvider` membership-filters every result via `circles_membership` + direct rows; non-member teams are unreachable.
- `adminRemoveUserFromTeam` refuses to remove owners (level≥9) and refuses non-direct memberships before any DB write; emits per-removal audit events with actor UID.

## [3.84.8] — 2026-06-30 — Audit-tab grid columns sized properly

### Fixed

- **`AdminSettings.vue` audit tab "Find teams for a user"** — Role and Membership columns were squashed. Root cause: my `.audit-user-grid { grid-template-columns: ... }` targeted the wrapper element, but the base `.maint-grid` CSS sets `display: grid` on `.maint-grid__head` and `.maint-grid__row`. The override had no effect and the cells inherited the maintenance grid's 6-column template (52px "members count" → Role, 100px "created" → Membership). Override now correctly selects `.audit-user-grid.maint-grid > .maint-grid__head, > .maint-grid__row`. New widths: 44px checkbox · `minmax(160px, 2.2fr)` team · 110px role · `minmax(170px, 1.4fr)` owner · `minmax(150px, 1.8fr)` membership.

## [3.84.7] — 2026-06-30 — Audit-tab list-teams query fixed on MySQL/MariaDB

### Fixed

- **`MaintenanceService::listTeamsForUser`** — "Unknown column 'c.unique_id ' in 'field list'" on MySQL/MariaDB. Multi-space `AS` alignment padding was being passed through verbatim to the driver, which read the trailing whitespace as part of the column name. Collapsed to single-space `AS`. Same lesson previously documented in `TeamIntegrationMapper::findAllWithEnabledStateForTeam`.

## [3.84.6] — 2026-06-30 — Audit-tab user search autocomplete wiring

### Fixed

- **`AdminSettings.vue` audit-tab user search** — the field accepted typed input but no search ever fired. `:value` + `@update:value` on `NcTextField` did not reliably emit per keystroke. Switched to `v-model` + `@input` (native DOM event) — the proven pattern from the maintenance-tab "Assign owner" picker. Added `@keydown.enter.prevent` flush via new `runAuditUserSearchNow()` so Enter forces an immediate search by clearing the 300ms debounce. Hoisted the fetch into a small `fetchAuditUserResults()` helper so both debounce and Enter share one path.

## [3.84.5] — 2026-06-30 — Invite-user role guards

### Fixed

- **Members were seeing the "Invite user" action and getting a 400 "Insufficient permissions" warning when they clicked.** `MemberService::inviteMembers` requires moderator level (4+) but three frontend affordances showed the button to everyone: `TeamWidgetGrid.vue` desktop teaminfo widget, `TeamWidgetGrid.vue` tablet teaminfo widget, `MobileWidgetView.vue` `widget-teaminfo` action list. All three now guarded by `isTeamModerator`. Audit pass on every other TeamWidgetGrid action emit confirmed: `manage-team` already guarded by `isTeamAdmin`; `copy-link` is no-op; `add-event` / `propose-decision` are member-level; `add-meeting` is configurable (default member); `add-deck-task` calls Deck's API; `create-page` / `delete-page` call IntraVox's API; `set-as-default` / `reset-to-default` / `add-personal-task` are per-user. Only Invite was the leak.

## [3.84.4] — 2026-06-30 — Hourly Talk-membership reconciler

### Added

- **`lib/BackgroundJob/TalkMembershipReconcileJob.php`** — new `TimedJob`, interval 3600s, `setAllowParallelRuns(false)`, `TIME_INSENSITIVE`. Skips entirely when Talk (spreed) isn't installed. Lists every team with a connected Talk room (`talk_attendees.actor_type='circles'` DISTINCT `actor_id`) and calls `TalkService::reconcileEffectiveTalkRoomMembers` per team. Logs aggregate counts only when at least one team needed reconciliation. Registered in `appinfo/info.xml` `<background-jobs>`.
- **`TalkService::reconcileEffectiveTalkRoomMembers(string $teamId): array{added,removed}`** — walks `circles_membership` for the team's effective member set (direct + group-inherited + sub-team-inherited), folds in `circles_member` direct rows as a cache-lag safety net, diffs against current `talk_attendees` user rows, inserts missing ones (reusing the column-detection pattern from `expandCircleMembersToTalk`), deletes orphans. Preserves `participant_type=1` owners so the room can never be orphaned. Idempotent.

### Fixed

- **Talk-room membership drift on group/sub-team changes** — when a group was added to a team, its existing members were never pulled into the Talk room. When a group was removed, the legacy `reconcileTalkRoomMembers` (which only knows about direct members) would evict users still reachable via another attached group. When an NC admin changed a group's membership outside TeamHub, nothing fired. The hourly cron now closes all four variants within a 60-minute window.

### Notes

- Existing direct-user paths (`syncUserToTeamTalkRoom` on invite, `removeUserFromTeamTalkRoom` on leave) remain unchanged — they handle the common cases instantly. The cron is the safety net.
- `MemberService::removeMember` line 1238 still calls the legacy `reconcileTalkRoomMembers` — swapping to the new effective-aware method is logged as an open issue for next session.

## [3.84.3] — 2026-06-30 — Browse Teams "Open" button + TeamSearchProvider

### Added

- **`BrowseTeamsView.vue` "Open" button** — direct member and indirect member team cards now show a secondary "Open" button (OpenInApp icon) before the existing destructive Leave / disabled-Leave-with-tooltip control. Click emits `team-opened`. `App.vue` listens and routes it to `selectTeamFromSidebar(teamId)` — same code path as clicking the team in the sidebar.
- **`lib/Search/TeamSearchProvider.php`** — implements `IProvider`, registered in `Application::register()` (order 49, just before MessageSearchProvider at 50 and DecisionSearchProvider at 51). Surfaces matching TeamHub teams in NC's unified search with deep links to `/apps/teamhub/#/team/{teamId}`. Membership-filtered: LEFT JOINs `circles_member` (direct) and `circles_membership` (inherited) and only returns teams the searcher belongs to. Filter to user-created teams via `c.source = 16`. Case-insensitive LIKE via `LOWER()` + `escapeLikeParameter()` + `mb_strtolower()`. Excludes pending-deletion teams. Subline is description truncated at 140 chars.

## [3.84.2] — 2026-06-30 — SSO empty-state hint in invite picker

### Added

- **`InviteMemberModal.vue` empty-state hint** — when the user search returns zero results, the modal now shows a two-line empty state: bold headline + smaller-text hint explaining lazy-provisioned SSO users only appear after first login. New CSS classes `.invite-modal__empty-headline` and `.invite-modal__empty-hint`. New string translated to all six locales.

## [3.84.1] — 2026-06-30 — Audit-tab "Find teams for a user" admin panel

### Added

- **Audit-tab admin panel: find every team a user belongs to** — new `NcSettingsSection` at the top of the audit tab. Search for any NC user; result is a table of every team they're a member of with role, team owner, and source (Direct / Via group: X / Via team: Y). Bulk-remove checkboxes for direct non-owner rows. NcDialog confirmation. Per-row outcome toasts.
- **`MaintenanceService::listTeamsForUser(string $userId): array`** — resolves user's single_id, queries `circles_membership` for accessible circles, filters to user-teams, classifies each row as `direct` (with role/level from `circles_member`) or inherited (walking the team's group/sub-team members and checking the user against each).
- **`MaintenanceService::adminRemoveUserFromTeam(string $teamId, string $userId): void`** — NC-admin gated. Refuses owners (level≥9), refuses inherited memberships. Deletes `circles_member` row, rebuilds `circles_membership` cache, emits `member.removed_by_admin` audit event.
- **`MaintenanceController` endpoints** `GET /api/v1/admin/maintenance/users/{userId}/teams` and `POST /api/v1/admin/maintenance/users/{userId}/remove-from-teams`. Per-row result objects returned so partial successes are visible.
- **`member.removed_by_admin` audit event** added to the audit catalogue.

### Security

- Both endpoints gate twice — `#[AuthorizedAdminSetting]` attribute + service `requireNcAdmin()`.
- List endpoint returns only data NC admins can already see elsewhere; no new PII exposure.
- Remove endpoint emits an audit event for every removal with actor UID — proper attribution.

## [3.84.0] — 2026-06-26 — Session-end rollup

Session boundary per SKILLS.md §session-end §3. Rolls up 3.83.0 → 3.83.3 (see those entries for per-change detail).

### Headline changes

- **Issue #41 investigation** (3.83.0) — calendar event write path now routes through NC's `ICreateFromString` public API (`EmbeddedCalDavServer` + sabre `Schedule\Plugin`). UID is bare RFC-4122 UUIDv4 (was `<hex>@teamhub`). `CREATED`, `LAST-MODIFIED`, explicit `TRANSP:OPAQUE` added. VTIMEZONE + TZID-based DTSTART/DTEND for CET/CEST timezones. `SCHEDULE-AGENT=CLIENT` dropped from organiser ATTENDEE. LOCATION fix — no longer leaks stale free-text when a room is picked. Root cause of user-visible "Availability will be checked" hang traced to upstream [calendar_resource_management#192](https://github.com/nextcloud/calendar_resource_management/issues/192).
- **More… overflow menu fix** (3.83.1) — `TeamTabBar.vue` overflow menu no longer closes when the cursor traverses the 4px gap between the trigger button and the `position: fixed` menu. 180ms delayed close, cancelled by `@mouseenter` on either element.
- **Spanish (es) translation** (3.83.2) — full 1639-string translation added. `SKILLS.md` lists Spanish as a supported project language.
- **Italian (it) translation** (3.83.3) — full 1639-string translation added. Project now ships six languages besides English (nl/de/fr/da/es/it).

### Frontend build required

`TeamTabBar.vue` was modified in 3.83.1 — Justin runs `npm run build` before deploying.

## [3.83.3] — 2026-06-26 — Italian (it) translation added

### Added

- **Italian (it) translation files** — `l10n/it.json` + `l10n/it.js`. All 1639 strings translated (machine translation; native-speaker review pass welcome via PR). Uses informal "tu" register (modern app convention). Plural form: `nplurals=2; plural=(n != 1);`.
- **SKILLS.md** updated to list Italian as the sixth supported project language alongside Dutch, German, French, Danish, and Spanish.

## [3.83.2] — 2026-06-26 — Spanish (es) translation added

### Added

- **Spanish (es) translation files** — `l10n/es.json` + `l10n/es.js`. All 1639 strings translated (machine translation; native-speaker review pass welcome via PR). Uses neutral imperative forms, Spain Spanish (es_ES) conventions. Plural form: `nplurals=2; plural=(n != 1);`.
- **SKILLS.md** updated to list Spanish alongside Dutch, German, French, and Danish as a supported project language.

## [3.83.1] — 2026-06-26 — More… overflow menu no longer closes when cursor traverses the gap

### Fixed

- **TeamTabBar.vue** — overflow "More…" menu closed instantly when the cursor moved off the trigger button, before it could reach a menu item. Caused by `position: fixed` on the menu removing it from the parent's bounding box, so the 4px gap between button and menu fired `mouseleave` on the container. Fix: 180ms delayed close via `scheduleMoreMenuClose`, cancelled by `@mouseenter` on either the container or the menu. Timer cleared on `beforeDestroy` to avoid a leak.

## [3.83.0] — 2026-06-26 — Calendar event write goes through sabre scheduling middleware (Issue #41 investigation)

Session rollup of 3.82.1 through 3.82.10. Investigated [issue #41](https://github.com/JustinDoek/Nextcloud-Teamhub/issues/41) — *Room resource event not visible in resource's calendar*. Root cause traced to upstream [calendar_resource_management#192](https://github.com/nextcloud/calendar_resource_management/issues/192) (intermittent auto-acceptance + missing CalDAV scheduling properties on CLI-created room principals). TeamHub-side improvements: write path now routes through NC's `EmbeddedCalDavServer` so sabre's `Schedule\Plugin` fires reliably — same code path a DAV PUT takes — plus several iCal-format alignments with NC Calendar's own emit.

### Headline changes

- **Calendar event write path** — `ActivityService::createCalendarEvent` now uses `\OCP\Calendar\IManager::getCalendarsForPrincipal()` → `ICreateFromString::createFromString()` for any event with scheduling needs (room or invitees). Falls back to direct `CalDavBackend::createCalendarObject()` on error or for non-scheduled events. Public API is documented in NC's developer manual; routes through `EmbeddedCalDavServer` which registers `Sabre\CalDAV\Schedule\Plugin` — same as a real DAV PUT.
- **UID format — RFC 4122 UUIDv4.** Was uppercase hex + `@teamhub`. Now bare `xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx`. Matches NC Calendar's own emit. No consumer in the codebase parses the `@teamhub` suffix (verified: `CalendarObjectDeletedListener` keys off `X-TEAMHUB-ROOMVOX-BOOKING-UID`; `DecisionMeetingService` stores the UID as an opaque string).
- **iCal property additions** — `CREATED`, `LAST-MODIFIED`, explicit `TRANSP:OPAQUE`. RFC 5545 §3.6.1 / §3.8.2.7 compliant. Matches NC Calendar's emit.
- **VTIMEZONE + TZID-based DTSTART/DTEND** — for users whose NC timezone setting is a CET/CEST zone (Europe/Amsterdam, Europe/Paris, etc.). Other timezones fall back to UTC `Z`-suffix as before. Adds a standard EU DST VTIMEZONE block.
- **`SCHEDULE-AGENT=CLIENT` removed** from organiser-as-attendee line. Was a workaround for sabre double-materialising when writes went through direct backend; with the new write path sabre handles this correctly. Defaults to `SERVER` per RFC 6638 §7.1.
- **LOCATION fix** — when a room is picked, `$location` (user-typed) is no longer used as a fallback. Was leaking stale free-text values into the iCal (Issue #41 secondary symptom: `LOCATION:room-kamer116` for an event booking kamer114). Always uses `$roomName`, falls back to `$roomEmail` if name is empty.

### Issue #41 status

The user-visible "Availability will be checked" / room resource not auto-booking is **downstream of TeamHub** — confirmed by:
- Sabre's `Schedule\Plugin` extracts the iTIP REQUEST from our write and stores it (new row in `oc_schedulingobjects`)
- For some events the REPLY comes back from CRM (room shows ACCEPTED with `SCHEDULE-STATUS=2.0`); for others it never does (event stays at `NEEDS-ACTION`)
- PROPFIND on the room's principal returns 404 for `calendar-user-address-set`, `calendar-home-set`, `schedule-inbox-URL`, `schedule-outbox-URL`, `schedule-default-calendar-URL` — every CalDAV scheduling property
- Matches [calendar_resource_management#192](https://github.com/nextcloud/calendar_resource_management/issues/192) verbatim

Workaround for affected users: open the event in NC Calendar's editor and shift its time / move it on the calendar — that triggers a fresh DAV PUT and CRM sometimes catches the auto-accept.

### Security & privacy

- **No new logging of PII.** The only addition is a warning-level log line in the new write path that records the exception class and message when `ICreateFromString` falls back to backend — no event content, no email addresses, no calendar data.
- **No new endpoints.** Pure refactor of the existing `ActivityService::createCalendarEvent` write step.
- **No new database queries.** The public Calendar API does its own query through `oc_calendars`; we don't add ours.

### Design

- **DESIGN.md §2.24** — calendar event writes go through `ICreateFromString` (`EmbeddedCalDavServer`), not `CalDavBackend` directly. Documents *why*: direct backend writes bypass sabre's scheduling middleware, leading to inconsistent iTIP delivery and the "Availability will be checked" UI hang reported in #41.

## [3.82.0] — 2026-06-23 — Add Meeting consolidation, AppEmbed labelled toolbar, design rules

Session rollup of 3.81.2 through 3.81.10. See those entries for the per-change detail; this row marks the session boundary per SKILLS.md.

### Headline changes

- **Upcoming Events widget actions: 3 → 2** (Add event + Add Meeting). The Meeting Wizard and Team Meeting modal merged into a 2-step wizard.
- **Add Meeting wizard** writes meeting notes plus calendar event in one go; optional `## Tasks` (overdue + unscheduled Deck cards) and `## Proposals` (open/finalized decisions) sections rendered into the notes file. Category multi-select to scope which proposals get included. Group-folder-backed teams supported. Manual date/start/end fallback when the Presence module is off. Talk agenda-request message localised to the meeting organiser's UI language.
- **AppEmbed toolbar** — every button now shows a short label (Today/Add/Remove/Suggest/Reload/Open in new tab) under the icon. Fixed the "all icons look alike" complaint.
- **Team-list unread badge** — fixed the literal "NaN" output from `NcCounterBubble` (prop-driven, not slot-driven in `@nextcloud/vue 8.x`).

### Security & privacy

- Redacted four `logger->debug` lines in `MeetingService.php` that were logging Talk-room tokens and share tokens (pre-existing; in-scope this session). Now log `tokenLen` only.
- `MeetingController` adds bound-checks on all new free-text fields (title ≤200, location ≤200, description ≤4000, categories ≤500, attendees ≤500, proposalCategories ≤200) to limit payload abuse.
- All new queries (deck cards, decisions for proposals) use `OCP\DB\QueryBuilder` only.
- User-supplied text rendered into the notes Markdown goes through a new `mdEscape()` helper that escapes `[`, `]`, `(`, `)`, backslash and friends.
- Group-folder team-folder resolution delegates to `ResourceService::getTeamResources()` which performs its own membership check — adds defence-in-depth on top of `MeetingService::enforceMinLevel`.

### Design

- **SKILLS.md** — new rule: state-coloured backgrounds use full saturation paired with the matching `-text` token. Soft variants (`-light`, `-hover`, `color-mix(... transparent)`) reserved for non-state surfaces.
- **DESIGN.md §2.22** — two calendar widget actions, one shared wizard component, `MeetingService` delegates calendar writes to `ActivityService`, agenda lives in the notes file rather than the iCal record.
- **DESIGN.md §2.23** — AppEmbed toolbar buttons are stacked icon + label (custom `<button>` instead of `NcButton`, bar wraps rather than scrolls).

## [3.81.10] — 2026-06-23 — Shorter visible labels on calendar toolbar buttons

### Changed

- **Calendar toolbar visible labels shortened to a single verb.** "Add event" → "Add", "Delete events" → "Remove", "Suggest meeting times" → "Suggest". Tooltip + accessible name keep the full descriptive label so hover and screen readers still read "Add event" / "Delete events" / "Suggest meeting times". New `shortLabel` field on the action descriptor for this; falls back to the full label when not set (Previous / Next / Today were already short enough to skip).

### Translations

- New strings `Add` and `Suggest` added to en/nl/de/fr/da (`.json` + `.js`). `Remove` and `Today` reused from existing strings.
- "Open in new tab" deliberately kept full-length: the existing `Open` translation in nl/de/fr/da is the **adjective** ("open status" — Offen/Ouvert/Åben), used elsewhere for decision status pills. Reusing it as a verb would cause a context collision. Single-word shortening costs more than the row width buys.

## [3.81.9] — 2026-06-23 — Unread badge "NaN" fix

### Fixed

- **Team-list unread badge showed "NaN" instead of the count.** `NcCounterBubble` in `@nextcloud/vue 8.x` reads its value from the `count` prop, not from its default slot. We were passing the count via slot interpolation (`<NcCounterBubble>{{ team.unread }}</NcCounterBubble>`) — the prop stayed undefined, and `Intl.NumberFormat.format(undefined)` rendered the literal string "NaN". Switched to `:count="team.unread"`.

## [3.81.8] — 2026-06-23 — AppEmbed toolbar: icon + label buttons

### Changed

- **AppEmbed toolbar buttons now show a small label under each icon.** Prev/Next/Today/Add event/Delete events/Suggest meeting times on the Calendar tab were reported as indistinguishable when icon-only at 16px. Buttons now stack a 20px icon over an 11px secondary-coloured label. Reload and Open-in-new-tab follow the same pattern. The bar wraps to a second row on narrow viewports rather than overflow. Toggle buttons (Timeline source filters) use the full-saturation primary background per the SKILLS.md state-colour rule when active.

### Removed

- `NcButton` use inside the AppEmbed toolbar — replaced by lightweight `<button>` / `<a>` elements styled directly. NcButton's horizontal icon+label layout fought against the stacked design.

### Design

- New §2.23 in DESIGN.md: *"AppEmbed toolbar buttons are stacked icon + label, not icon-only."* Records the trade-off (~14px taller bar) and the reasoning (icon-only was unreadable).

## [3.81.7] — 2026-06-23 — Talk agenda-request message is translated

### Fixed

- **"Ask team for agenda items" Talk message was always English.** `MeetingService::postAgendaRequest` now resolves the meeting organizer's UI language via `IConfig::getUserValue($uid, 'core', 'lang')` and renders the message through `IFactory::get('teamhub', $language)->t(...)`. Falls back to the instance `default_language`, then English. A Talk channel is a single string surface (can't be per-recipient), so the organizer's own language is the natural choice — matches what they'd have typed themselves.

### Translations

- New translation unit `📅 Team meeting scheduled: **%1$s** on %2$s at %3$s.\nPlease add your agenda items to the meeting notes: %4$s` added to en/nl/de/fr/da (`.json` + `.js`). Numbered placeholders so translators can reorder the title/date/time/URL slots if their grammar requires it.

## [3.81.6] — 2026-06-23 — Wizard uses full-saturation state colours

### Changed

- **Wizard state surfaces no longer use soft-tint backgrounds.** Active step pill, selected meeting-type pill, selected category chip, presence-off info hint, and approver-meeting prefill banner now use the regular `--color-X` background paired with `--color-X-text` — per the new SKILLS.md rule. Soft variants (`--color-X-light`, `--color-X-hover`, `color-mix(... transparent)`) wash out against the canvas and were obscuring what was actually selected.

### Docs

- **SKILLS.md** — added the *"State-coloured backgrounds use full saturation"* rule under Nextcloud design guidelines, with the do/don't list and the carve-out for neutral grouping panels.

## [3.81.5] — 2026-06-23 — Manual date/time fallback when Presence is off

### Added

- **Manual schedule pickers** in the Add Meeting wizard when the Presence module is disabled (globally or for this team). Date, start time, and end time inputs replace the half-day suggestion block. A primary-coloured info hint reads "Enable the Presence module for date/time suggestions." pointing at the team setting.

### Fixed

- **Noisy "Cannot retrieve suggestions: Presence module is not enabled" toast** that fired every time the wizard opened on a Presence-disabled team. The wizard now short-circuits the suggestion fetch when `presenceModuleEnabled && presenceConfig.presence_enabled` is false.

## [3.81.4] — 2026-06-23 — Add Meeting: group folder + category multiselect fixes

### Fixed

- **"Team files folder not found" on group-folder-backed teams.** `MeetingService::resolveTeamFolder` only checked `oc_share` for `share_type=7` shares, so teams whose Files resource is a Group Folder (not a circle share) couldn't create meeting notes. Now delegates to `ResourceService::getTeamResources()` and `IRootFolder::getById()` — the same canonical pattern as `DecisionService::writeProposalDocument` — which handles both shared-folder and Group Folder cases per DESIGN.md §2.19.
- **Proposal category multiselect didn't appear.** `SuggestMeetingWizard.loadProposalCategories` parsed `data.items` but the `/decisions/categories` endpoint returns `{categories: [strings]}`. Switched to that shape with a fallback for the `{items: [{name}]}` shape so a future endpoint swap doesn't silently break the UI. Chips are deduped and sorted by locale.

## [3.81.3] — 2026-06-23 — Proposal category multi-select in the wizard

### Added

- **Proposal category filter** in the Add Meeting wizard. When `Discuss proposals awaiting a decision` is on, a chip list of the team's decision categories appears below it. Default: every category ticked. Selecting a subset narrows the rendered `## Proposals` section to those categories only. Selecting all or none falls back to "no filter" (every awaiting proposal).

### API

- **`POST /api/v1/teams/{teamId}/meetings`** — new optional field `proposalCategories` (comma-separated string or array of category names, ≤200 items). Empty means no filter.

## [3.81.2] — 2026-06-23 — Upcoming Events widget actions consolidated

### Added

- **`Add Meeting` action** — replaces both `Meeting wizard` and `Team meeting` in the Upcoming Events widget (desktop, tablet, mobile). The wizard always creates a meeting-notes file plus calendar event, with optional agenda sources.
- **Agenda checkboxes in the wizard** — four new toggles render extra sections inside the meeting-notes file:
  - `Ask team for agenda items` (posts a request in the team Talk room — gated on Talk being on)
  - `Add overdue Deck tasks` — `## Tasks` section, format `[title](deck card url) — due {date}`
  - `Add unscheduled Deck tasks` — same section, `no due date`
  - `Discuss proposals awaiting a decision` — `## Proposals` section, format `[question](deep-link) — {category}`, status open/finalized
- **Wizard `description` and `categories` in notes** — the wizard's description is now woven into the notes file as a preamble block, and `CATEGORIES` is set on the calendar event.

### Changed

- **Wizard compacted to two steps** — Step 1 "Who & When" (title, attendees, online/office, target date, half-day suggestions, timeslot picker — all inline). Step 2 "Setup" (description, room/location, category, Talk toggle, meeting-notes block with the four agenda toggles). Suggestions auto-fetch on attendee/date/type change; first timeslot auto-selected. Default selection in step 1: all team members ticked (was: none).
- **Meeting-notes template** — adds `{tasksSection}` and `{proposalsSection}` placeholders between `## Agenda` and `## Notes`. Existing on-disk `template.md` files are not rewritten; the PHP render uses the constant.
- **`MeetingService.createTeamMeeting`** — now delegates calendar-event creation to `ActivityService.createCalendarEvent`, picking up its room-booking (RoomVox + CRM), per-attendee invite, and ORGANIZER/ATTENDEE iTIP-correct shape. Removed the duplicate VEVENT writer.

### Removed

- **`TeamMeetingModal.vue`** — its functionality is fully merged into the wizard. The component file and the `showTeamMeeting`/`team-meeting` state and emit chain are gone.

### API

- **`POST /api/v1/teams/{teamId}/meetings`** — accepts additional optional fields:
  - `description`, `categories` — applied to both calendar event and notes
  - `roomEmail`, `roomName`, `roomId` — RoomVox / CRM room booking
  - `attendees` — comma-separated user ids (or array); previously the service auto-invited all team members
  - `includeOverdueTasks`, `includeUnscheduledTasks`, `includeProposals` — agenda toggles
  - Response now also includes `eventUid`.

### Security & privacy

- New PHP queries (deck cards, decisions for proposals) use `OCP\DB\QueryBuilder` only, all parameter-bound. Membership/min-level checks reuse `MemberService` via `MeetingService::enforceMinLevel` (unchanged). User-supplied text rendered into Markdown links is escaped through a new `mdEscape()` helper. Free-text payload fields are bound-checked at the controller (title ≤200, location ≤200, description ≤4000, categories ≤500, attendees ≤500).

## [3.80.0] — 2026-06-20 — Tab bar overflow, decision search, proposal filenames, PostgreSQL fix

### Added

- **Tab bar responsive overflow** — when tabs exceed available bar width, excess tabs are collected into a "More…" dropdown. Uses ResizeObserver + cached tab widths for flicker-free recalculation. Supports hover and click, handles all tab types (built-in, ext-*, link-* NC-relative, link-* external). Active-tab highlighting in overflow dropdown.
- **Decision unified search** — decisions are now searchable via Nextcloud's unified search bar. New `DecisionSearchProvider` searches across question and selected_answer text, membership-filtered. Results show decision question as title, status/impact/team name as subline, with deep link to the team view.

### Changed

- **Proposal filenames** (GitHub Issue #37) — proposal documents in `.proposals/{id}/` now use a sanitized slug of the decision question (e.g. `Should-we-migrate-to-PostgreSQL.md`) instead of `{id}.md`. Slugs are truncated to 80 chars, with ID fallback for empty questions. Old `.md` files are cleaned up on regen. Legacy flat layout (`{id}.md` at `.proposals/` root) unchanged.

### Fixed

- **PostgreSQL migration error** — fresh installs on PostgreSQL failed with `SQLSTATE[42703]: Undefined column: 7 ERROR: column "level"` when creating a decision. Root cause: ALTER migrations added columns (`level`, `decisions_level_enabled`, `decisions_action_min_level`, `team_id`, `icon`, `description`) but the base createTable migrations weren't updated to include them. Fixed by updating `Version000364000Date20260602000000.php` and `Version000367000Date20260603000000.php` per SKILLS.md §2.17 migration discipline.

### Security & privacy

No new authorisation gaps. `DecisionSearchProvider` enforces team membership via `MemberService::getMemberLevelFromDb()` per result — same enforcement pattern as `MessageSearchProvider`. Search uses prepared statements via QueryBuilder. `proposalFilename()` sanitizes input with regex before filesystem use.

## [3.79.0] — 2026-06-19 — Timeline Milestones, connector overlays, Deck card dependencies

### Added

- **Timeline Milestones** — team admins can define named, optionally-dated markers from Manage Team → Integration settings → Timeline. Dated milestones render on the Timeline as a full-height red line with their label, always plotted (not a filterable source — admins who set a milestone want it visible unconditionally). Undated milestones are listed in the management UI but not plotted (no x-position to plot them at).
  - New table `teamhub_milestones` (`id`, `team_id`, `label`, `milestone_date` nullable, `created_by`, `created_at`).
  - Full CRUD: `lib/Db/Milestone.php`, `lib/Db/MilestoneMapper.php`, `lib/Service/MilestoneService.php` — all operations admin-gated via `MemberService::requireAdminLevel`.
  - Display ordering (dated-ascending, undated last) computed in PHP, not SQL — cross-database NULL-ordering consistency (MySQL and PostgreSQL default NULL ordering differs).
- **Manage Team "Decisions" tab renamed to "Integration settings"** — now always shown (previously hidden entirely when the Decisions module was off instance-wide). Contains a Decisions block (existing settings/categories, conditional on the module being enabled) followed by a Timeline block with full Milestone management.
- **Timeline crowding count-badges** (3M/6M views) — when a single section has 4+ chips on the same calendar day, they collapse into one count-badge per section instead of stacking that many lanes deep. Click jumps to the 1-Week view, snapped to the week containing that day, via a `postMessage` bridge from the iframe to `TeamView.vue` (the iframe has no navigation state of its own). Threshold purely density-driven, not tied to zoom level.
- **Timeline 1-Week view: full per-item Gantt mode for every section** — every distinct item (Deck card, decision, calendar event, message) gets its own row; connected lifecycle chips (created+due, proposed+decided) share a row with a connecting bar. Crowding badges are skipped entirely at this zoom level — the point of zooming in is to see everything.
- **Three Timeline connector overlays**, opt-in toggles in the filter menu, all on by default:
  - **Decision ↔ task links** (v3.78.5) — arrow from a decision's outcome chip (decided/withdrawn) to each Deck card linked via "Link tasks." Anchors on the outcome, not the proposal, so the arrow reads causally: decision → resulting task.
  - **Deck card dependencies** (v3.78.8) — NC 34 / Deck 1.18+ only. Detects the new `deck_dependent_cards` table via `DbIntrospectionService`; the toggle is entirely absent from the filter menu on older installs rather than shown disabled. Arrow runs prerequisite card → blocked card, inferred from Deck's own `CardMapper::addDependency()` semantics and the "Assign dependent cards" UI (not officially documented by Nextcloud — flagged in code as reversible if it turns out backwards in practice).
  - **Message ↔ decision links** (v3.78.9) — arrow from a `messageType='decision'` stream post to the decision's "proposed" chip it announced. Uses the decision row's existing `message_id` — no new schema or query needed.
- **Share button on decision detail panel** — icon button next to the status pill copies a `?team=…&decision=…` deep-link to the clipboard (`TeamDecisionsView.vue`), matching the existing meeting-invite link pattern.

### Changed

- **Timeline section order**: Deck → Decisions → Messages → Calendar (top-to-bottom), was Deck → Calendar → Decisions → Messages. Filter menu item order updated to match.
- **All Timeline sources, sub-filters, and connector overlays now default to enabled** — maximizes visible relationships out of the box. Previously Deck/Decisions "created"/"proposed" sub-filters and all three connector overlays defaulted off.
- **`DecisionTaskService::extractDeckCardId`** changed from `private` to `public static` so `TimelineService` can resolve `task_path` → Deck card ID using the exact same logic as the decision-task link feature, rather than re-deriving the regex separately.

### Fixed

- **Activity widget showing literal `&quot;` instead of a quote mark** (`ActivityWidget.vue`, `ActivityFeedView.vue`) — `@nextcloud/l10n`'s `t()` HTML-escapes named placeholder values by default (safe for `v-html` insertion), but `formatSubject()`'s output is always rendered via plain `{{ }}` text interpolation, which Vue already escapes safely on its own. Double-escaping left raw entities visible. Fixed by wrapping every dynamic placeholder value (`user`, `card`, `board`, `file`, `detail`) with `{ value, escape: false }` in both files — the same workaround pattern already used elsewhere in this codebase for meeting-invite links.

### Security & privacy

No new authorisation gaps. Every new endpoint (`getMilestones`/`createMilestone`/`updateMilestone`/`deleteMilestone`) is gated through `MilestoneService`'s `requireAdminLevel` checks at the service layer, consistent with the controller-thin/service-fat pattern. The card-dependency and decision-task bulk lookups are scoped to card/decision IDs already derived from the requesting team's own resources — not user-suppliable IDs — so neither introduces a cross-team data exposure path. No raw SQL introduced; all new queries go through `OCP\DB\QueryBuilder` with parameterized binds.

### Translations

20 new strings (Milestones UI, Timeline filter-menu connector labels, decision share button) translated into all 5 locales (en/nl/de/fr/da); `.js` locale files regenerated to match. See `HANDOFF.md` for a separately-discovered, pre-existing 60-key discrepancy between `en.json` and the other four locales (unrelated to this session's strings) flagged for a future dedicated translation-sync session.

## [3.78.1] — 2026-06-17 — Timeline translations

### Added

- **17 Timeline-related strings translated** into all 5 supported locales (en/nl/de/fr/da). Covers: `Timeline`, `Filter`, `Timeline period`, `Print timeline`, `Enable Timeline for this team`, the four period-length options (`1 week` / `1 month` / `3 months` / `6 months`), the two filter-menu captions (`Decisions events` / `Deck events`), the five sub-filter labels (`Proposed date` / `Approved date` / `Created date` / `Due date` / `Completed date`), and the Manage Team integration row description.
- Locale `.js` files regenerated to match the updated `.json` sources. Existing plural arrays preserved untouched.

### Known untranslated (deferred to next translation sync session)

- A handful of pre-existing strings from earlier sessions are still missing from `en.json` and the matched locales — including `Show a Presence tab on the team home so members can see each other's schedules.`, `Show Operational / Tactical / Strategic level on decisions. ...`, `Image cache cleared (%n file removed)`, `Show %n ignored resource`, and `The team will be permanently deleted in {n} day.`. These pre-date 3.78.0 and are tracked in `HANDOFF.md` for a focused translation sync.

## [3.78.0] — 2026-06-16 — Timeline tab (visual aggregate of team activity)

### Added

- **Timeline tab** — a new built-in team tab presenting a horizontal visual timeline of team activity. Four stacked source bands (Deck, Calendar, Decisions, Messages), each with its own background tint, label and dotted time-axis. Chips render at proportional x-positions; vertical lanes within a band auto-stack only where chips truly overlap horizontally.
  - **Backend**: `lib/Service/TimelineService.php` — new service that aggregates events from Deck cards (uses TeamHub's `teamhub_team_app_resources` registry to locate team boards; ACL fallback for installs without registry rows), Calendar events (CalDAV principal scan for team-circle calendars), Decisions (`teamhub_decisions` with `created_at`/`decided_at`/`withdrawn_at` anchors), and Messages (`teamhub_messages`). Uses `SELECT *` on `deck_cards` with `?? null` field reads — robust against schema variance across Deck versions where DbIntrospectionService can silently return `[]`.
  - **Iframe page**: `templates/timeline.php` renders the canvas as a standalone same-origin iframe (`PageController::timeline`). Uses inline `<script>` stamped with the per-request CSP nonce via `\OC::$server->getContentSecurityPolicyNonceManager()->getNonce()` — `RENDER_AS_BLANK` bypasses the `<jsfiles>` pipeline so `Util::addScript()` produces no output. Vanilla JS; no Vue.
  - **View modes**: 1W (180 px/day), 1M (60), 3M (32), 6M (18). Period navigation always steps by exactly one month (or one week in 1W view), regardless of view width — clicking `›` in the 3M view advances by 1 month, not 3.
  - **Per-source sub-filters**: Deck (created/due/completed) and Decisions (proposed/decided). Defaults emit only the "resolved" events (Deck due+completed, Decisions decided). Enabling "created" or "proposed" adds a second chip per item and the timeline draws a Gantt-style connecting bar between the lifecycle endpoints. Sub-filters grey out when their parent source is off.
  - **Per-item Gantt mode**: when only one source is active (Deck only OR Decisions only), each distinct card/decision gets its own dedicated lane, ordered by earliest event. Combined with the connecting bar this turns the view into a true Gantt chart with one row per task.
  - **Filter dropdown**: single Filter button in the embed bar opens an `NcActions` popover with grouped section captions and indented sub-checkboxes. Replaces the original inline toggle pills.
  - **Clickable chips**: each chip carries an in-app deep-link (`target="_top"`). Calendar → edit sidebar, Deck → board+card detail, Decisions → `/apps/teamhub?team=…&decision=…` (existing TeamDecisionsView reads the query), Messages → team home.
  - **Print button**: printer icon in the embed bar calls `frame.contentWindow.print()` on the same-origin iframe. Dedicated `@media print` rules in `templates/timeline.php` drop the loading overlay, lift overflow constraints so the full natural canvas width is printed, drop chip shadows, and use `print-color-adjust: exact` so chip colours survive.

- **Per-team Timeline toggle** (Manage Team → Integrations → Internal integrations) — admins can enable or disable the Timeline tab per team via a standard `NcCheckboxRadioSwitch`. Default is on. Persisted as a single boolean via `IConfig::setAppValue('teamhub', 'timeline_enabled_<teamId>', '1'|'0')` — no migration needed.

- **Generic `embedMenu` + `embedToggles` props** on `src/components/AppEmbed.vue` — reusable mechanisms for adding dropdown filter menus and inline toggle-state buttons to any AppEmbed-hosted iframe tab. Menu items support `isCaption: true` for grouping section headers using `NcActionCaption`, and `disabled` to grey out items when a parent is off.

- **`mergeNewTabs()` helper** in `LayoutController` — mirrors `mergeNewWidgets()`. Appends any keys in `DEFAULT_TAB_ORDER` that are missing from a saved `tab_order_json` row, so existing teams pick up the new Timeline tab automatically without ever re-saving their layout. Applied in both the team-specific-row and cascade-to-personal-default response paths.

### Changed

- **Section order on the timeline canvas**: Deck → Calendar → Decisions → Messages (top-to-bottom). Reorderable via the `SECTIONS` array in `templates/timeline.php`.
- **`fetchDeckEvents` board lookup is registry-first**: uses `TeamAppResourceMapper::findActiveByTeamAndApp($teamId, 'deck')` as the primary source (matches `ResourceService`'s approach), with the Deck ACL tables (`deck_board_acl` and `deck_acl`) as a fallback. Earlier code's ACL-only lookup found zero boards on installs where boards were connected via the resource flow but lacked corresponding circle ACL rows.
- **Per-section axis lines** sit directly under the section's label row instead of through the section's vertical centre — chips visually read as "sits on the axis below the label".

### Fixed

- **Deck cards failing to appear on the timeline** — three independent bugs in `fetchDeckEvents`:
  1. SQL was selecting `due_date` (with underscore) and `created_at`; Deck's actual columns are `duedate` (one word) and `last_modified` (no `created_at` exists). Queries threw at the DB level. Fixed.
  2. Inner `join` to `deck_boards` with `b.archived = 0` filter silently dropped all stacks when `archived` was NULL rather than 0. Changed to a left-join and removed the redundant filter (registry already encodes "active for this team").
  3. `DbIntrospectionService::getTableColumns('deck_cards')` returns `[]` on at least one production install, so the introspected SELECT list was missing the optional columns and the data wasn't fetched. Switched to `SELECT *` with `?? null` reads.
- **TimelineService log output not appearing in `nextcloud.log`** — all `error_log()` calls converted to `$this->logger->info(...)`. PHP's `error_log` writes to the PHP error log (or stderr / syslog depending on config), not to `data/nextcloud.log`. Subsequently downgraded to `debug` level for production cleanliness — flip the per-app log level to debug to see them.
- **Filter dropdown active state showing bold text** — added a `:deep()` selector targeting NcButton's internal `.button-vue__text` span to force `font-weight: 400`. The button itself uses 400 by default but the inner text span was inheriting a heavier weight.
- **Timeline tab not appearing on existing teams** — two-part fix. Backend: new `mergeNewTabs()` ensures saved `tab_order_json` rows pick up new built-in tabs on every GET. Frontend: `TeamView.buildAllTabDescriptors()` is the authoritative frontend tab registry and silently drops any key not present; `'timeline'` was missing. Added the entry plus matching entries in `syncExtTabs`'s `builtinKeys` and the preload warm-up list.

### Removed

- **Deck cards `archived` filter** (board-side) — the registry-first lookup already encodes "active for this team", and an `archived` column with NULL values was incorrectly excluding live boards.

## [3.77.0] — 2026-06-12 — Task completion pill + translation sync

### Added

- **Task completion pill on Deck card links (3.76.1)** — each Deck card task in the decision detail panel now displays a compact Open/Complete status pill fetched live from the Deck app.
  - `DeckService::getCardsByIds()` detects whether `deck_stacks` has a `done_status` column (Deck ≥1.9) via `DbIntrospectionService`. If present, uses `done_status = 1` for the done check. Falls back to stack title `= 'done'` (case-insensitive) for older Deck versions. `archived` flag is always checked as a primary condition.
  - `DecisionTaskService::listForDecision()` now batch-fetches card metadata for all Deck card links in a single `IN()` query (no N+1). A new private `extractDeckCardId(string $taskPath): ?int` helper extracts card IDs from stored paths via regex `#(?:apps/)?deck/board/\d+/card/(\d+)#`.
  - Non-Deck tasks (plain URLs, other app paths) receive `isDone: null` in the API response — the frontend suppresses the pill for these.
  - Frontend pill: green "Complete" (`#e6f4ec` background, `#1a6633` text, `#b3dcc4` border — 7.2:1 contrast ✅) and grey "Open" (NC background-dark + maxcontrast text). Pill has `aria-label` with translated text ("Completed" / "Open") for screen-reader accessibility.

### Changed / Fixed

- **Translation sync (3.76.2)** — `en.json` was severely out of date (698 keys vs ~1,526 source strings across the app). Full sync performed:
  - **579 passthrough strings** added to `en.json` (value = key, standard NC pattern). These strings were already translated in nl/de/fr/da but had never been added to the English source template.
  - **281 new strings** fully translated into all five locales (en + nl + de + fr + da), covering all Decisions module UI added in recent sessions, File Center header, mobile filter toggle, external/internal link UI, approver-meeting wizard, and the new task completion pill strings ("Open", "Complete", "Completed").
  - **Result:** `en.json` 698 → 1,558 keys; `nl/de/fr/da` 1,337 → 1,618 keys each.
  - All `.js` locale files regenerated to match. Existing plural arrays (`%n has a calendar conflict`, etc.) preserved.

## [3.76.0] — 2026-06-12 — Approver-meeting polish, external links, admin defaults, mobile filters, markdown final proposal

This release rolls up the 3.75.x patch series into a single shippable minor.

### Added

- **External decision links (3.75.2)** — attach outbound URLs to a decision pointing to decisions held in other tools (org-management escalation, prior decisions in legacy systems). Backend: new table `teamhub_dec_ext_links` (21 chars ✓), `DecisionExternalLink` entity + mapper, `DecisionExternalLinkService` with URL validation (http/https only, ≤2048 chars, scheme check post-`parse_url`), 3 endpoints (`GET/POST/DELETE /external-links`). Audit transitions `external_link_added` / `external_link_removed` (host only in payload). Migration `Version000375020Date20260611100000`. Frontend: inline URL+label form in the proposal detail panel, action-level gated.
- **Deep-linking to a specific proposal (3.75.1)** — `?team=…&decision=…` URL params consumed by `App.vue` on mount; selects the team, fetches the decision, switches to Decisions tab, pre-selects the card via existing `decisionsTargetMessageId` mechanism. Defensive — stale URLs warn-log and return without hanging the UI.
- **Mobile filter toggle (3.75.4)** — Decisions toolbar chip filters + sort collapse behind a "Filters" button on viewports <720px. Active-filter count badge on the toggle. Desktop layout unchanged via `display:contents` on the wrapper.

### Changed

- **Final proposal renders as markdown (3.75.5)** — was rendering raw text via `{{ }}`, hiding the proposer's formatting. Now uses the existing `renderViewerMarkdown` (same renderer as the .md file viewer; DOMPurified with a tight tag/attribute allowlist). New `.th-dv__detail-answer-text--md` CSS modifier tightens nested-element spacing for the compact detail panel.
- **Presence + Decisions modules default ON at NC admin level (3.75.4)** — flipped 11 `getAppValue` defaults from `'0'` to `'1'` across 6 files. Affects only fresh installs where the key has never been set; existing installs that have explicitly toggled either value keep their stored choice per `getAppValue` contract. Per-team default stays off, to be wired into the create-team template in a future session.
- **Linked decisions section unified (3.75.3)** — internal and external decision links now live in one section with per-row kind pills (Internal/External). Backend tables stay separate (auth boundaries differ); only the UI merges them.
- **Approver-meeting wizard locks attendees (3.75.1)** — new `lockAttendees: Boolean` prop on `SuggestMeetingWizard`. When true: Select-all toolbar hidden, intro text changes to explain the lock, list filtered to only the pre-checked members, checkboxes disabled. Attendees become the category's approver list, no manual changes.
- **Approver-meeting button shows only when meaningful (3.75.1)** — gated additionally on category having >1 approver. No point scheduling a meeting with yourself.
- **Approver-meeting title is now "Proposal meeting" (3.75.1)** — was `Discuss proposal: {title}` with interpolation.
- **Approver-meeting description link is now a deep link (3.75.1+3.75.2)** — was a team-home link `?team=…`, now `?team=…&decision=…`. Label changed from "Open in TeamHub:" to "Link:". The URL placeholder now uses `{value, escape: false}` because NC's `t()` defaults `escape:true` and was turning `&` into `&amp;` in the calendar event's plain-text DESCRIPTION.

### Fixed

- **`approver_meeting_scheduled` audit events were being silently dropped (3.75.2)** — the transition string wasn't in `DecisionAuditService::TRANSITIONS`, so the service's whitelist check rejected every write with a warning log. Now registered. Retroactively fixes a v3.75.0 regression.
- **Banner contrast on locked attendee list (3.75.2)** — was using `--color-primary-element-light` (a tint) paired with `--color-primary-element-text` (designed for the solid fill, hence white). Switched to soft-success background with main-text foreground.
- **Duplicate `decisionsModuleEnabled` array key in `TeamService::getAdminSettings()` (3.75.4)** — the same key was being set twice in the same array literal. Removed.

### Security

- All new endpoints gated on team membership for reads, `decisions_action_min_level` for writes (external links). URL validation rejects everything except http(s)://, length-capped at 2048. External link rendering uses `target="_blank" rel="noopener noreferrer"`. Final-proposal markdown rendering reuses the existing DOMPurify-sanitized pipeline.
- `consumeDeepLink` in `App.vue` verifies the team is in the current user's team list before selecting; a stale URL pointing at a team the user is no longer a member of warn-logs and returns rather than leaving the UI in a loading state.

### Known issues

- **Translation gap.** ~327 source strings (Decisions module + File Center header + recent home-page work) are missing from all four locale files (`nl.json`, `de.json`, `fr.json`, `da.json` — all four are exactly 1411 lines, last synced together). The Transifex pipeline (`.tx/config`) needs the new `.pot` template pushed; next session's focus per Justin.

## [3.75.0] — 2026-06-11 — Schedule approver meeting + home-page polish + decision-detail polish

### Added

- **Schedule approver meeting on a proposal (v3.74.10 → 3.75.0)** — approvers can open the suggest-meeting wizard pre-filled to discuss the proposal before deciding.
  - New table `teamhub_dec_meetings` (20 chars ✓): one row per scheduled meeting with `decision_id`, `event_uid`, denormalised `meeting_title` + `meeting_start`, `scheduled_by`, `created_at`. Indexes on `decision_id`, `team_id`, `event_uid`.
  - `DecisionMeeting` entity, `DecisionMeetingMapper` with `findByDecisionId`, `insertMeeting`, `deleteByDecisionId`, `deleteByTeamId`.
  - `DecisionMeetingService` with three public methods: `listApproversForDecision` (looks up the approver list via `DecisionCategoryService::listForTeam` matching on category name), `listForDecision` (membership-gated read with display-name resolution), `recordMeeting` (approver-gated write; validates `eventUid`, `meetingTitle`, positive `meetingStart`; truncates `meetingTitle` to 255; emits audit event `approver_meeting_scheduled`).
  - 3 new endpoints: `GET /decisions/{decisionId}/approvers`, `GET /decisions/{decisionId}/meetings`, `POST /decisions/{decisionId}/meetings`.
  - `SuggestMeetingWizard` accepts 5 new optional props: `prefilledAttendees`, `prefilledTitle`, `prefilledDescription`, `prefilledCategory`, `prefillBanner`. Prefill applied after `loadMembers` returns (so users not in the team are silently skipped). `created` event now emits `{eventUid, start, title}` (was no-arg) so callers can record back-references — old listeners continue to work.
  - UI: "Schedule meeting" button (short label, descriptive `title=` tooltip) appears in two places — the approval block of the proposal detail panel, and the `DecisionApprovalModal` opened from the widget's approve tab. Both gated on `resources.calendar.length > 0` and approver-status. Description prefills `<title>\n\n<first 400 chars of body>\n\nOpen in TeamHub: <team-home url>`. Category prefills "Proposals". Banner reads "Pre-filled with approvers for this category — you can adjust the list before continuing."
  - "Scheduled meetings" section added to the proposal detail panel below "Linked tasks". Shows scheduled meetings with title + formatted start time (using `toLocaleString`). Hidden entirely when no meetings exist to keep the panel clean.
  - Migration `Version000374010Date20260611000000`.

### Changed

- **`ActivityService::createCalendarEvent` return type** — was `void`, now returns the generated iCal `UID` (string). The `POST /calendar/events` endpoint response shape extends from `{success: true}` to `{success: true, eventUid, start, title}` — additive only, old callers ignore the new fields.
- **MessageStream filters direct-proposal decisions** — proposals created via the compose modal or "Propose decision" button now set `sourceType='direct'` and are excluded from the message stream. Discussion-first proposals (`sourceType='message'`) continue to show in the stream. `'direct'` added to the backend's `ALLOWED_SOURCE_TYPES` list — the missing entry was causing a 400 on every direct proposal.
- **Decision detail panel — "Decided by" row** — was previously gated on `(status === 'approved' || 'decided') && answeredBy` and showed the user from `answeredBy`. `answeredBy` is the *proposer* finalizing their own proposal, not the approver/denier. Now uses `resolvedBy` (set by `approve()` and `deny()`) and is gated on `status === 'approved' || 'denied' || 'decided'`.
- **Decision detail panel — Level and Category** — render as plain text in the detail meta table (no chips). The chip styling is kept in the card list view where it still serves a hierarchy purpose.
- **Decision detail panel — Proposed by / Decided by** — render `NcAvatar` (size 20) + display name. Clicking the avatar opens NC's built-in user card (View profile, email, Talk to, Show availability). `.th-dv__detail-meta` no longer has `overflow: hidden` so the popup is not clipped.
- **Decision detail panel — filters and sort buttons hidden when a decision is open** — only the breadcrumb + Propose button remain in the toolbar in detail view.
- **Decision detail panel — 16px top padding** added so content doesn't sit flush against the header rule.
- **Decisions "Create task" button** — hidden when the team has no Deck configured (was always shown, leading to a no-op).
- **Feedback button → docs link** — the sidebar feedback icon now opens `https://tldr.host/teamhub/docs/` in a new tab. Icon changed from `MessageAlert` to `HelpCircleOutline`. `FeedbackModal.vue` is no longer mounted (file kept for now).
- **Widget grid — root-cause fixes**:
  - `grid-layout-plus` `vertical-compact` set to `false` — VGL was running its own compaction on top of `applySnap`, causing snap-back and gaps. `applySnap` is now the sole vertical authority.
  - `editMode` watcher in `TeamView` — on edit-mode exit, immediately runs `applySnap` then `saveLayout` (no waiting for 1.2 s debounce).
  - `layoutDiffersFromDefault` now compares `y` as well — vertical reordering is a real user preference. Without this the Save-as-default / Reset buttons were invisible after the most common kind of edit.
  - Save-as-default / Reset buttons unconditionally shown in edit mode — discoverability over conditional rendering.
  - `getActiveWidgetIds` now includes `widget-files-center` and `widget-decisions` — they were being parked at y=9999 by snap.
  - `TeamWidgetGrid.visibleLayout` filters the layout passed to `<grid-layout>` so VGL only sees active items; total grid height (and the scrollbar) stay honest while inactive items remain in `gridLayout` for position memory.

### Fixed

- **Direct proposal 400** — `sourceType='direct'` was being sent by `PostMessageForm` but the backend's `ALLOWED_SOURCE_TYPES` list didn't include it; every direct proposal failed with "Failed to post — server error 400". Added `'direct'` to the allowed list.
- **Approval block CSS missing** — the `.th-dv__approval-*` classes existed only in the template (label, required marker, textarea, counter, actions row) with no CSS definitions, so the approve/deny UI rendered unstyled. Full block added.
- **Files and Decisions widgets parked off-screen after snap** — fixed by adding both to `getActiveWidgetIds`. Bug was pre-existing but masked by VGL's own compaction; surfaced when we turned VGL compaction off.

### Security

- All three new endpoints gated on team membership. `POST /meetings` additionally requires CSRF (no `NoCSRFRequired`) and checks the approver list via `DecisionMeetingService::assertCanScheduleMeeting`. Input validation: `eventUid` ≤ 255, `meetingTitle` truncated to 255, `meetingStart` must be a positive timestamp. No proposal body content logged.

## [3.74.0] — 2026-06-09 — Decisions: link decision + cross-app design unification

### Added

- **Link Decision (Session C)** — bidirectional decision ↔ decision linking with full UI in the detail panel.
  - New table `teamhub_dec_links` (17 chars ✓): one row per link with canonical ordering (`decision_id_a < decision_id_b`), unique index on the pair, indexes on team + both sides.
  - `DecisionLink` entity, `DecisionLinkMapper` (with `findByDecisionId` OR-query covering both sides), `DecisionLinkService` enforcing membership for read and `decisions_action_min_level` for create/delete.
  - 3 new endpoints: `GET/POST/DELETE /api/v1/teams/{teamId}/decisions/{decisionId}/links[/{linkId}]`.
  - Detail-panel UI: linked-decisions section with peer title + level pill + status pill, click-to-jump navigation, gated "Link decision" button, search-as-you-type decision picker modal (reuses `/decisions?q=` endpoint with 250ms debounce, excludes self + already-linked peers).
  - Migration `Version000373010Date20260609080000`.
- **Audit events for task + decision link/unlink** — 4 new transitions in `DecisionAuditService::TRANSITIONS`: `task_linked`, `task_unlinked`, `decision_linked`, `decision_unlinked`. Decision link/unlink events written to both decisions' audit trails.
- **Shared widget design tokens** (`src/styles/widget-tokens.css`) — single source of truth loaded once from `main.js`. Defines hard-contrast brand palette (`--th-color-{success,warning,error,neutral}` + soft variants), typography scale (title 14/600, row primary 14/500, row meta 12/400, pill 10/700), spacing tokens (row padding 10px 14px, gap 12px), and shared utility classes: `.th-widget__panel`, `.th-widget__title`, `.th-widget__rows`, `.th-widget__row` (+ `--clickable`), `.th-widget__row-icon`, `.th-widget__row-title`, `.th-widget__row-meta`, `.th-widget__state` (+ `--empty`, `--error`), `.th-widget__spinner`, `.th-widget__pill` (+ `--primary`/`--success`/`--warning`/`--error`/`--neutral`, plus `--outline` variant).
- **`peer_level` field** in decision-link list/create responses.

### Changed

- **All 13 widgets refactored** to consume the shared tokens — `DecisionsWidget`, `DecisionsList`, `ActivityWidget`, `CalendarWidget`, `DeckWidget`, `FilesWidget`, `FilesFavoritesWidget`, `FilesRecentWidget`, `FilesSharedWidget`, `IntegrationWidget`, `IntravoxWidget`, `MembersWidget`, `MemberRow`, `MemberPresenceRow`, `ExternalWidgetItem`. Loading/empty/error states unified to compact `.th-widget__state` rows with shared spinner. Hardcoded font-sizes replaced with tokens (kept only for genuinely widget-specific elements: calendar date badge, tab counter, action button). Soft NC colour vars (`--color-success`, `--color-warning`, `--color-error` and their text variants) replaced with hard-contrast `--th-color-*` tokens app-wide.
- **DecisionsList row** simplified — impact and level pills removed from the meta line, category now rendered as plain text with small uppercase `CATEGORY` label prefix. Primary line uses size-driven hierarchy (14px medium) instead of bold.
- **Activity subject** uses regular weight (400) — sentences read better unbolded; the rest of the app keeps medium weight on primary lines.
- **Detail-panel "Linked tasks", "Linked decisions", and "Source files"** unified under one `.th-dv__link-*` row pattern — same border, hover, focus ring, spacing across all three. Solid-filled pills (no transparency / soft tints).
- **`--th-color-warning` darkened to `#a05a00`** for WCAG-AA 4.5:1 contrast against white pill text (was `#c97a00`, 3.34:1).
- **Removed dead deny-modal CSS** (~70 lines) from `DecisionsWidget` — replaced by `DecisionApprovalModal` in Session B.
- **Link task button** now uses `LinkVariantIcon` (matching Link decision) instead of `PlusIcon`.

### Fixed

- **Focus indicators** on `.th-dv__link-row`, `.th-dv__link-remove`, `.th-dv__dec-picker-item-btn`, and `.th-widget__row--clickable` — removed redundant `outline: none` that was followed by a re-set rule, then ensured each `:focus-visible` has a 2px primary-coloured outline visible (WCAG SC 2.4.7).
- **`aria-live="polite"`** added to the linked-decisions list and decision-picker results so screen reader users hear async updates.

### Security

- New decision-link endpoints follow the membership + min-level pattern. `listDecisionLinks` requires team membership; `createDecisionLink` and `deleteDecisionLink` require `decisions_action_min_level`. Peer decisions must belong to the same team — cross-team linking rejected at the service boundary. All queries use `OCP\DB\QueryBuilder` with explicit `PARAM_INT`/`PARAM_STR` typing.

---

## [3.73.0] — 2026-06-09 — Decisions: compose modal, task links, approval modal

### Added

- **Compose decision modal** — single shared instance at TeamView level, opened by widget header `+` and Decisions tab Propose button. Wraps PostMessageForm with `forceDecision` prop; auto-finalizes proposals (status='finalized') and writes `.proposals/{id}/{id}.md` via new `refresh-proposal` endpoint. Attachments fully supported.
- **`POST /decisions/{id}/refresh-proposal`** endpoint — idempotent re-run of `writeProposalDocument` after attachment registration (avoids in-transaction timing issues).
- **320 verified MDI icons** — shared library `src/lib/decisionCategoryIcons.js` auto-generated from `vue-material-design-icons@5.3.1`. 13 groups (Business, Tech, People, etc). Searchable grouped picker in ManageTeamView with scroll and category headers.
- **Decision search on landing** — search bar queries decisions globally (backend `q` filter, 250ms debounce) instead of filtering categories client-side.
- **Task links (Session B)** — new `teamhub_dec_tasks` table columns (`team_id`, `task_path`, `label`), `DecisionTaskMapper`, `DecisionTaskService` with action-level gating, controller endpoints for CRUD (`GET/POST/DELETE /decisions/{id}/tasks`).
- **Min-role for actions** — `decisions_action_min_level` column on `teamhub_decision_team`; dropdown in ManageTeamView Decisions tab (Member/Moderator/Admin). Controls Link task, Create task, Link decision buttons.
- **Linked tasks section** in detail panel right column — task list with NC-styled button links, inline "Link task" URL form, "Create task" button opening AddTaskModal with auto-linking.
- **Decision approval modal** (`DecisionApprovalModal.vue`) — replaces widget's inline Approve/Deny buttons with single "Decision" button that opens modal with reason field + Approve (green) + Deny (red).
- **Level badge** in widget card meta line (DecisionsList) — shows Operational/Tactical/Strategic when level feature is enabled.

### Changed

- **Landing page redesign** — 2-column responsive grid (1-col on mobile <720px), NC-native card styling with 42px icon bubbles, 15px category names (regular weight descriptions), "All decisions" shortcut row.
- **Detail panel** — 2-column layout (content left, approval + tasks + audit right; stacks on <920px). "View in stream" button removed.
- **Level badges** now shown for ALL levels including Operational (was hidden for operational).
- **Breadcrumb** renders MDI icon component instead of icon name as text.
- **Decision settings** (level field toggle, min-role dropdown) moved from Integrations tab to Decisions tab in ManageTeamView.
- **Approve badge** strengthened to `#c8253f !important` with white text and inset border to override NC theme bleed.
- **AddTaskModal** now emits `{ boardId, stackId, cardId, title, path }` on creation for auto-linking.
- **Widget approve tab** loads on mount (badge count visible on page load without clicking tab).

### Fixed

- **400 error** on compose modal submit: `MessageService::createMessage` was passing 8 args to `propose()` which expected 9 after `level` was added.
- **Missing source heading** for compose-modal decisions: `writeProposalDocument` was called inside the DB transaction before commit; now deferred to `refresh-proposal` endpoint.
- **Widget item click** going to landing instead of detail: `SET_DECISIONS_TARGET` was set before TeamDecisionsView mounted; added `mounted()` check for pending target.
- **`deck_card_id` NOT NULL** error: old column made nullable so new `task_path` rows can coexist.
- **Duplicate `listTasks()` method**: old partial Session B code from previous session cleaned up (old entity, mapper, service methods, routes removed).
- **`CodeBraces` duplicate** in icon library causing build failure.
- **Missing imports** (NcButton, axios, etc.) swept by greedy regex during icon-map cleanup.
- **`<template v-for>` key error**: replaced with `<div v-for>` wrapper in icon picker.

### Removed

- Old `DecisionTaskLink.php` and `DecisionTaskLinkMapper.php` (replaced by `DecisionTaskMapper`).
- Old `linkTask`, `unlinkTask`, `listTasks`, `hydrateTasks` methods from `DecisionService` (replaced by `DecisionTaskService`).
- Old deny modal from `DecisionsWidget` (replaced by `DecisionApprovalModal`).
- `src/constants/decisionCategoryIcons.js` (replaced by `src/lib/decisionCategoryIcons.js`).

## [3.72.0] — 2026-06-04 — Decisions: polish pass (Session L)

### Added

- **Decisions tab in Manage Team**: dedicated tab (gavel icon) gated on `decisionsModuleEnabled`. Hosts category management; module-enable toggle continues to live under Integrations.
- **Approval block** in the Decisions tab detail panel: replaces the previous inline Approve button + separate Deny modal. Shared mandatory-reason textarea drives both Approve (success-variant green) and Deny (error-variant red); reason joins the audit trail.
- **In-app read-only file viewer** with type-aware rendering: `.md` rendered via DOMPurify-sanitized markdown; plain text via `<pre>`; images via `/core/preview`; PDFs via native `<embed>`; everything else gets a "Preview not available — Download" panel.
- **New `GET /api/v1/files/{fileId}/content`** endpoint streams raw bytes by file id; authorisation requires the file live inside a `.proposals/` subtree the calling user can natively access. `?download=1` sets `Content-Disposition: attachment`.
- **`approve()` now requires a mandatory reason** (mirrors `deny()`); stored in the audit-event payload.
- **2-column category grid** in the Decisions iframe layout; **full-overlay detail view** with a prominent Back chip (was a 360px sidebar that left no room at iframe widths).
- **Message attachment registration** — `PostMessageForm` calls `POST /messages/{id}/attachments` after posting; sidecar table populated; attachments are copied into `.proposals/{decisionId}/` on finalize.

### Changed

- **Status pill "Finalized" → "Awaits approval"** everywhere (detail panel, filter chips, MessageCard, DecisionsList).
- **Audit verb "Finalized" → "Finalized proposal"**.
- **Selected comment label "Decided answer" → "Final proposal"** (detail panel + CommentsSection badge).
- **Approver picker** reads from `allEffectiveMembers` (was `manageMembers.direct`-only) so members added via groups/sub-teams appear; labels show `displayName` (was UID).
- **Subject placement**: moved from above the metadata grid to directly above the Final proposal block.
- **Status filter** matches legacy DB values: `'open'` also matches `'proposed'`, `'approved'` also matches `'decided'`. Fixes empty Open tab for pre-rename decisions.
- **Widget Latest tab** now refreshes immediately after approve/deny (was emptied without re-fetching, requiring a page reload).
- **Manage view** dropped `max-width: 900px`; now uses full column width.
- **`finalize()` no longer overwrites `sourceRef`** with the proposal `.md` path — that path is already presented in the source-files list, so the duplicated text line was removed from the Source heading.
- **No-preview panel**: removed "Open in Files" button to keep members out of the hidden `.proposals/` folder; Download remains.

### Fixed

- **`memberUserOptions` accidentally in `watch`** (instead of `computed`) — was returning `undefined` and crashing `formatApprovers` with `TypeError: undefined.find(...)`, producing a white screen after creating a category.
- **Decisions tab not rendering on team home** — `TeamTabBar::isTabRenderable()` was missing a `case 'decisions': return true` even though the tab descriptor was being built. One-line fix.
- **`.md` viewer showed `<!DOCTYPE html>`** — was fetching via NC's `/f/{id}` redirect which returns an HTML "Files app shell", not the raw file content. Now uses the new TeamHub endpoint.
- **Attachment upload failed with MKCOL 405** when the team folder is a Group Folder — ACLs forbid create-folder for normal members. Now falls back to personal `TeamHub Attachments/` for the GF case; the proposal-finalize copy step still copies them into `.proposals/` by file-id.
- **Categories shown in two places** (Integrations tab + Decisions tab) — removed from Integrations; lives only in Decisions tab.
- **`this.$set` Vue 3 incompatibility** — replaced with array `splice` in `applyDecisionUpdate`.
- **`window.alert` popups on approve/deny errors** — replaced with NC `showError` toasts in both `TeamDecisionsView` and `DecisionsWidget`. Attachment registration failures now also surface as toasts (were silent `console.warn`).
- **Recovery**: prior session had added message-attachment plumbing references but never shipped the `Db/MessageAttachment.php` + `Db/MessageAttachmentMapper.php` class files. Caused `Could not resolve …MessageAttachmentMapper` 500s on `/teams`. Recovery zip (3.71.5) shipped the missing files.

### Security

- New `fileContent` endpoint has defense-in-depth: NC's per-user ACL (via `getUserFolder->getById`) plus an explicit `.proposals/` ancestor check before serving bytes.
- Defensive filename-length cap (200 chars) on `Content-Disposition` header to harden against header-injection via crafted filenames.
- DOMPurify sanitizes all viewer-rendered markdown with an explicit allowlist.

## [3.71.0] — 2026-06-03 — Decisions: supersede wire-up, telemetry, translation pass (Session K close)

### Added

- **Supersede flow end-to-end**: `PostMessageForm` reads `window.__teamhubDecisionCompose` on mount and on stream-view entry, pre-selects Decision type and stashes `supersedesId`. A banner shows above the form ("This proposal will supersede decision #X…") with a dismiss button. Submit sends the id; backend `DecisionService::propose` auto-withdraws the prior decision when it was still `open`, with the audit transition `withdrawn` payload carrying `superseded_by` so the timeline reads clearly on both sides.
- **Telemetry: decision lifecycle metrics**: `TelemetryService::collectStats` now emits `decisions_by_status` (aggregate counts per `open|finalized|approved|denied|withdrawn`) and `decision_categories_count`. No team-id, no proposer-id, no content — aggregate-only per DESIGN.md §2.8.

### Changed

- **Translation pass**: `TRANSLATORS:` hints added to status filter chips, sort-order labels, audit verbs. `n()` plural-form used for `{n} day(s) ago` in `DecisionsList` and `TeamDecisionsView` (was `t()` with the plural form hardcoded). `n` imported alongside `t` in `DecisionsList`.

## [3.70.0] — 2026-06-03 — Decisions: audit trail (Session J)

### Added

- **New table `teamhub_dec_audit`** — append-only timeline log, one row per state transition.
- **`DecisionAuditService`** with the six-transition vocabulary (`proposed/commented/finalized/withdrawn/approved/denied`). Best-effort: log failure never aborts the originating action.
- **Audit hooks** wired into `DecisionService::propose|finalize|withdraw|approve|deny` and into `CommentController::createComment` (logs `commented` when the parent is a decision; no-op otherwise).
- **`GET /api/v1/teams/{teamId}/decisions/{decisionId}/audit`** — member-only read endpoint returning the full timeline.
- **Timeline UI** in `TeamDecisionsView` detail panel — vertical timeline with colour-coded transition dots; reloads after approve/deny so new events appear immediately.
- **Proposal document regeneration** on approve/deny — `.proposals/{id}.md` now appends an `## Audit trail` section reflecting the full lifecycle.

## [3.69.0] — 2026-06-03 — Decisions: approver UX in widget (Session I)

### Added

- **Approve tab** in `DecisionsWidget` — only visible when the current user is in at least one category's approver list. Shows finalized decisions filtered to the user's approver scope, with a badge count.
- **Inline approve / deny** per row via `DecisionsList`'s new `show-approver-actions` prop. Deny opens a widget-local modal requiring a reason.

## [3.68.0] — 2026-06-03 — Decisions: lifecycle overhaul + proposal documents (Session H)

### Changed

- **Status vocabulary**: `proposed → open`, `decided → approved`. New states `finalized` and `denied`. `withdrawn` retained. Existing test data should be wiped — there is no data migration.
- **`finalize()` replaces `markBest()`** — proposer-only; uses their own most-recent comment as the canonical final wording; locks the comment thread.
- **`writeProposalDocument`** — when a decision is finalized, generate `{team-folder}/.proposals/{id}.md` containing the question, the original message, every comment with author + timestamp, and the final wording. Sets the decision's `source_type='document'` and `source_ref` to the file path.

### Added

- **`approve()` and `deny()`** service methods + matching controller endpoints (`POST /decisions/{id}/approve`, `POST /decisions/{id}/deny`). Gated on the m:n category-approver list from 3.67.0; admin fallback when the category isn't predefined.
- **`canFinalizeDecision`** computed in `MessageCard`; the gavel-finalize button is shown only on the proposer's own comments while the decision is `open`.
- **Banners** for `finalized` / `approved` / `denied` / `withdrawn` states in `MessageCard`. Comment-lock placeholder text updated.

### Removed

- **Legacy `POST /decisions/{id}/mark` route** removed (no data migration; existing rows in old states will read but not transition cleanly).

## [3.67.0] — 2026-06-03 — Decisions: category management foundation (Session G)

### Added

- **Two new tables**: `teamhub_dec_categories` (predefined per-team categories with creator metadata) and `teamhub_dec_cat_apprs` (m:n approver list per category).
- **`DecisionCategoryService`** with team-owner-as-default-approver rule and never-empty-approver-list invariant.
- **Four controller endpoints**: `GET|POST /decisions/manage/categories`, `PUT|DELETE /decisions/manage/categories/{categoryId}`.
- **Manage Team → Decisions sub-panel** with category CRUD list, inline edit/create forms, NcSelect multiselect for approvers.
- **`PostMessageForm` category picker** — free-text input replaced with required NcSelect from the team's predefined list. Empty-team warning when no categories are set up.

## [3.66.7] — 2026-06-03 — Decisions tab in tab bar + UX redesign

### Fixed

- **Decisions tab not appearing in the tab bar** — `TeamTabBar` requires explicit per-key blocks (same as Presence); the `icon: 'Gavel'` descriptor string in `buildAllTabDescriptors` is not used for rendering. Added the `v-else-if="tab.key === 'decisions'"` block and `GavelIcon` import.

### Changed

- **`TeamDecisionsView` redesigned**: category-first layout (decisions grouped by category section with count badges), modern card style with status-coloured left accent, slide-in detail panel (360px, max 40%) with definition-list meta grid, decided-answer block, withdrawn reason, source link, action buttons.

## [3.66.6] — 2026-06-03 — Build fix + widget click destination

### Fixed

- **Build error** from deep import paths in `TeamDecisionsView` (`@nextcloud/vue/dist/Components/NcButton.js`). Switched to the named-import pattern used elsewhere: `import { NcButton, NcModal, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'`.
- **Widget click did nothing** — `DecisionsList::navigateToMessage` was calling `SET_VIEW('msgstream')` but the widget already renders on that view. Now sets `SET_DECISIONS_TARGET(messageId)` and `SET_VIEW('decisions')` so the row highlights in the Decisions tab.

## [3.66.5] — 2026-06-03 — Decisions full canvas tab (Session E)

### Added

- **`TeamDecisionsView`** — full tab canvas with status filter chips, impact filter, sort toggle, expandable rows with detail panels (decided answer, withdrawn reason, source ref, Supersede action), paginated load-more, target-message highlight for navigation from the widget.
- **`store/index.js`**: new `decisionsTargetMessageId` state + `SET_DECISIONS_TARGET` mutation, used by widget click and MessageCard's "View in Decisions tab" button to scroll/expand the matching row in the tab.
- **`MessageCard`**: "View in Decisions tab" button enabled (was disabled placeholder), now calls `SET_DECISIONS_TARGET` + `SET_VIEW('decisions')`.
- **`LayoutController::ALLOWED_TAB_KEYS`**: `decisions` added so saved tab orders accept it.

## [3.66.4] — 2026-06-03 — Widget row content + click navigation

### Fixed

- **Subject missing** in widget rows — `DecisionsList` was reading `d.title` which doesn't exist in the API response; the field is `d.question`. Now renders `d.question` with a fallback for empty rows.
- **Row click destination** — now navigates to the message stream via `SET_VIEW('msgstream')`. (Superseded in 3.66.6 — destination changed to the Decisions tab.)

### Changed

- **Impact display** moved out of the header chip and into the meta line as full text ("Low" / "Medium" / "High"), colour-coded.

## [3.66.3] — 2026-06-03 — Decisions widget on team home (Session D)

### Added

- **`DecisionsWidget`** with tabbed view (Latest 5 / Open). Lazy-loads the Open tab on first click.
- **`DecisionsList`** — reusable list-row component with status accent bar, impact chip, status pill, category tag, proposer attribution, and relative date.
- **`store/index.js`**: new `fetchWidgetDecisions({ status, limit })` action.
- **`LayoutController::DEFAULT_LAYOUT`** + **`ALLOWED_WIDGET_IDS`**: `widget-decisions` registered; existing installs get it merged in via the existing `mergeNewWidgets` logic on next layout GET.
- **`TeamWidgetGrid` + `MobileWidgetView`**: widget block gated on `decisionsModuleEnabled && decisionsConfig.decisions_enabled`. Header has a + button emitting `propose-decision`.

## [3.66.2] — 2026-06-02 — Decisions module: drop source-ref picker from compose

### Changed

- **`PostMessageForm`**: removed the Source reference picker (Document / External URL) from the decision compose form. The picker was misconceived — NC Files paths are scoped to the user's personal files, so storing one as a team-visible source was structurally broken; external URLs are better placed in the message body itself, which already supports markdown links. The `teamhub_decisions.source_type` and `source_ref` columns remain on the schema, reserved for future entry points (meeting-notes conversion, API-driven creation, message-conversion). Decisions created via the in-stream compose form now get `source_type='message'` set automatically server-side with `source_ref` left null.
- **`MessageCard`**: removed the source-link rendering from the decision meta strip; only the optional category chip remains.
- **DESIGN.md §2.52**: rewrote to document the corrected reasoning.

## [3.66.1] — 2026-06-02 — Decisions module: file picker + decided-state contrast

### Fixed

- **`PostMessageForm`** Files picker showed folders only because the legacy `OC.dialogs.filepicker` treats the `['*']` mime filter as "no file types allowed". Switched to the modern `getFilePickerBuilder` from `@nextcloud/dialogs` with `FilePickerType.Choose` and `allowDirectories(false)`. (Superseded in 3.66.2 — the picker is no longer used.)
- **Decided-state contrast** in `MessageCard` and `CommentsSection` violated the project rule that text uses `--color-success-text` on a contrasting tinted surface, not `--color-main-background` on a saturated `--color-success`. Both `decision-banner--decided` and `comment__decision-answer-badge` (plus `comment--decided-answer`) now follow the rule with `color-mix(in srgb, var(--color-success) 14%, var(--color-main-background))` backgrounds, `--color-success` borders, and `--color-success-text` foreground.

## [3.66.0] — 2026-06-02 — Decisions module Session C: in-stream entry point

Users can now propose, mark, and withdraw decisions entirely from the message stream. Decisions render with distinctive framing. The comment-lock UI matches the backend lock from v3.65.0.

### Added

- **Decision compose option in `PostMessageForm`** — fourth message type alongside Message / Poll / Question. Gated on `decisionsModuleEnabled && decisionsConfig.decisions_enabled`. Required Impact picker (low/medium/high), optional Category autocomplete (suggestions from `/decisions/categories`), optional Source picker (None / Document via NC Files picker / External URL with validation).
- **Decision rendering in `MessageCard`** — distinctive header badge (gavel + impact + status), card-left accent border colour-coded by status, meta strip (category + source ref), and per-state UI (proposed: Withdraw button + mark hint; decided: success banner with disabled "View in Decisions tab" button + tooltip; withdrawn: muted banner with reason).
- **Comment lock UI in `CommentsSection`** — terminal-state decisions disable comment input, edit, and delete for non-admins. The `selected_comment_id` comment is locked from edits/deletes even in proposed state. Admins retain delete-for-moderation capability. Placeholder text explains the lock reason.
- **"Mark as decided answer" button** — gavel icon affordance on each comment, visible only to proposer/admin when decision status is 'proposed'. Triggers a confirmation modal warning the action is irreversible.
- **Withdraw modal** — required reason textarea (max 1000 chars) with irreversible-action warning.
- **Decision-answer styling** — the marked comment renders with a success-coloured border and "Decided answer" badge.
- **Vuex actions:** `fetchDecisionCategories`, `markDecisionBest`, `withdrawDecision`.
- **Vuex mutation:** `SET_MESSAGE_DECISION` — targeted patch of a single message's embedded decision payload, preserving the rest of the message row.

### Changed

- **`MessageService::createMessage`** now accepts a `?array $decisionData` parameter. When `messageType === 'decision'`, the method opens a DB transaction, inserts the message, then calls `DecisionService::propose`. If `propose` throws, the transaction rolls back — no orphan decision-typed messages.
- **`MessageService::createMessage`** refuses `messageType='decision'` when the module isn't active for the team, instead of silently coercing to `'normal'`. Defensive guard against misconfigured frontends.
- **`MessageService::getTeamMessages`** now hydrates the `decision` payload onto each decision-typed message in one batch query (no per-message round-trip).
- **`MessageController::createMessage`** signature extended with `?array $decision = null`.
- **Vuex `postMessage` action** accepts a `decision` field in its payload and forwards it.

### Added (data layer)

- **`DecisionMapper::findByMessageIds(int[])`** — batch lookup keyed by `message_id`.
- **`DecisionService::hydrateForMessages(int[])`** — public batch serialiser used by `MessageService::getTeamMessages`. Explicitly no gate check; callers have already authorised via the team-scoped message-list query.

### Security

- **Transaction guarantee:** decision-typed message creates are atomic. A `propose()` failure (invalid impact, bad supersedes target, etc.) rolls back the message insert so the user can correct input without leaving behind an orphan message.
- **Frontend gate respects backend:** Decision option only renders when both module-level and team-level flags are on. Backend re-validates regardless.
- **Comment lock UI matches server:** non-admins see no edit/delete affordances on locked threads; admins retain moderation. Mirrors `DecisionService::isCommentLocked` exactly.

## [3.65.0] — 2026-06-02 — Decisions module Session B: data layer + API + comment locking

Backend-only session. Builds the full Decisions API on top of the Session A foundation. No frontend changes — `npm run build` not required.

### Added

- **`Decision` entity + `DecisionMapper`** — full type-mapped entity for `teamhub_decisions`; mapper exposes `findById`, `findByMessageId`, `list` (with filters / sort / cursor pagination), `countByTeam`, `distinctCategoriesByTeam`. The `list` method's `'recent'` sort uses `COALESCE(decided_at, withdrawn_at, created_at) DESC` — verified compatible with both PostgreSQL and MySQL/MariaDB.
- **`DecisionTaskLink` entity + `DecisionTaskLinkMapper`** — entity and CRUD for `teamhub_dec_tasks`. Includes `findPair` (duplicate guard) and `findByDeckCardIds` (for the Deck-side card action surface planned in Session E.5).
- **`DecisionService`** — full implementation, replacing Session A stubs:
  - Gate methods: `assertModuleEnabledGlobally()` (config endpoints), `assertModuleEnabledForTeam($teamId)` (feature endpoints), `isModuleActiveForTeam($teamId)` (predicate used by comment locking).
  - `propose(teamId, messageId, impact, category?, supersedesId?, sourceType?, sourceRef?, actingUserId)`
  - `markBest(teamId, decisionId, commentId, actingUserId)` — captures participants via `MemberService::getAllEffectiveMembers`.
  - `withdraw(teamId, decisionId, reason, actingUserId)`
  - `list(teamId, filters, sort, before, limit)` — paginated; supports status / impact / category / proposedBy / q filters; `'recent'` or `'created'` sort.
  - `get(teamId, decisionId)` — returns decision with hydrated `tasks` array.
  - `categories(teamId)` — distinct category list for filter picker.
  - `linkTask`, `unlinkTask`, `listTasks` — Deck card link CRUD with display-metadata hydration.
  - `isCommentLocked(teamId, messageId)` — predicate consumed by CommentController.
- **`DecisionController`** — full API surface (9 new endpoints in addition to Session A's config pair):
  - `GET /api/v1/teams/{teamId}/decisions` — list
  - `POST /api/v1/teams/{teamId}/decisions` — propose
  - `GET /api/v1/teams/{teamId}/decisions/categories`
  - `GET /api/v1/teams/{teamId}/decisions/{decisionId}` — show with hydrated tasks
  - `POST /api/v1/teams/{teamId}/decisions/{decisionId}/mark` — mark best comment
  - `POST /api/v1/teams/{teamId}/decisions/{decisionId}/withdraw`
  - `POST /api/v1/teams/{teamId}/decisions/{decisionId}/tasks` — link Deck card
  - `GET /api/v1/teams/{teamId}/decisions/{decisionId}/tasks` — list linked tasks
  - `DELETE /api/v1/teams/{teamId}/decisions/{decisionId}/tasks/{linkId}` — unlink
  
  Read endpoints carry `#[NoCSRFRequired]`; write endpoints require CSRF tokens.
- **`DeckService::getCardsByIds(int[]): array`** — single-query JOIN over `deck_cards → deck_stacks → deck_boards`, returns card title / archived flag / deleted timestamp / stack title / board id+title+color / direct URL, keyed by card id. Cards absent from the result are treated as deleted by callers. No card body/description returned (no content exposure).
- **`DeckService::cardExists(int): bool`** — minimal existence probe used by `linkTask` validation.
- **Telemetry: `decisions_count`** — total decisions across all teams (anonymous count).

### Changed

- **`CommentController`** — `createComment`, `updateComment`, `deleteComment` now consult `DecisionService::isCommentLocked` and refuse non-admin writes when the parent message has a decided or withdrawn decision attached. Admin override is enforced at the controller level via `MemberService::requireAdminLevel`. New private helper: `checkDecisionLockForMessage`.
- **`MessageService::createMessage`** — `messageType` whitelist now accepts `'decision'`. Session C will use this to mark messages that carry a decision proposal.

### Security

- Cross-team data leak prevention: `DecisionService::loadDecisionInTeam` and `propose`'s message-team check both treat "belongs to a different team" as 404 — never reveal existence of decisions/messages outside the caller's team.
- `DecisionMapper::list` uses `escapeLikeParameter` on user-supplied `q` filter; the service additionally caps `q` at 200 chars before reaching SQL.
- Length caps in `DecisionService`: question 4000, selected answer 4000, withdrawn reason 1000, category 128, source_ref 512.
- `meeting_notes` source type is reserved for a future feature and rejected at the API layer with 400 — prevents accidental writes that would be hard to migrate later.

## [3.64.2] — 2026-06-02 — Hotfix: per-team Decisions toggle was unreachable

### Fixed

- **`DecisionController` / `DecisionService`** — `saveConfig` (PUT `/decisions/config`) called `assertModuleEnabledForTeam()`, which required the team's `decisions_enabled` flag to already be `1`. Since the entire purpose of the endpoint is to set that flag for the first time, the first PUT always returned `404 "Decisions module is not enabled for this team"`, making the per-team toggle unreachable.
  - Split the gate into two methods. `assertModuleEnabledGlobally()` checks only the global app-config flag and is used by both config endpoints (`getConfig`, `saveConfig`). `assertModuleEnabledForTeam()` still checks both global + per-team flags and will be used by all Session B feature endpoints.

## [3.64.1] — 2026-06-02 — Hotfix: stray brace in LayoutController

### Fixed

- **`LayoutController.php`** — stray extra `}` after the new `isDecisionsModuleEnabled()` helper caused a PHP parse error preventing the app from loading. The helper was correctly added but the original closing brace of `isPresenceModuleEnabled()` was duplicated, leaving the file with one extra `}` before `currentUserId()`. Removed.

## [3.64.0] — 2026-06-02 — Decisions module Session A: activation foundation

Session focus: the on/off framework for the Decisions module. No decisions can be created or viewed yet — this session wires the global admin toggle, per-team toggle, database tables, backend gate enforcement, telemetry, and all frontend state. Sessions B–E add the data layer and UI surfaces.

### Added

- **Migration `Version000364000Date20260602000000`** — creates three new tables in a single migration (safe for fresh installs and existing deployments):
  - `teamhub_decision_team` (21 chars) — per-team Decisions module config: `id`, `team_id` (unique), `decisions_enabled` (SMALLINT, default 0), `created_at`, `updated_at`.
  - `teamhub_decisions` (17 chars) — full decision record schema: `id`, `team_id`, `message_id`, `proposed_by`, `answered_by`, `selected_comment_id`, `category`, `impact`, `question`, `selected_answer`, `participants`, `status`, `withdrawn_reason`, `resolved_by`, `supersedes_id`, `source_type`, `source_ref`, `created_at`, `decided_at`, `withdrawn_at`. Indexes on `team_id`, `message_id`, `status`, `supersedes_id`.
  - `teamhub_dec_tasks` (16 chars) — decision↔Deck card link table: `id`, `decision_id`, `deck_card_id`, `created_at`, `created_by`. Unique index on `(decision_id, deck_card_id)`; index on `deck_card_id`.
- **`DecisionTeamConfig` entity + `DecisionTeamConfigMapper`** — `findByTeam()` returns null (= disabled) when no row exists; `countEnabledTeams()` for telemetry.
- **`DecisionTeamService`** — `getConfig()` / `saveConfig()` with create-on-first-write. Mirrors `PresenceTeamService` config pattern.
- **`DecisionService` skeleton** — `assertModuleEnabledForTeam(string $teamId)` implemented as the security boundary; all Session B methods are stubs throwing `RuntimeException`.
- **`DecisionController`** — `GET /api/v1/teams/{teamId}/decisions/config` (member-auth) and `PUT /api/v1/teams/{teamId}/decisions/config` (team-admin only). Both call `assertModuleEnabledForTeam` first.
- **Admin settings** — "Decisions module" toggle section added to NC admin → Settings → TeamHub → Integrations tab. Persisted as `decisions_module_enabled` app-config key (default `'0'`).
- **Manage Team** — "Decisions" row added to the Internal integrations subsection (Manage Team → Integrations). Visible only when global module flag is on and viewer is a team admin. Toggle persists to `teamhub_decision_team`.
- **Vuex store** — `decisionsConfig: { decisions_enabled: false }`, `decisionsModuleEnabled: false`, `SET_DECISIONS_CONFIG`, `SET_DECISIONS_MODULE_ENABLED` mutations.
- **Telemetry** — `decisions_module` (bool) and `teams_with_decisions_enabled` (count) added to the anonymous telemetry payload.

### Changed

- **`TeamService::getAdminSettings()`** — returns `decisionsModuleEnabled` boolean.
- **`TeamService::getTeam()`** — returns `decisionsModuleEnabled` boolean.
- **`TeamService::saveAdminSettings()`** — persists `decisionsModuleEnabled` → `decisions_module_enabled` app-config key.
- **`LayoutController::getLayout()`** — bundles `decisionsConfig` and `decisionsModuleEnabled` in the layout GET response (both code paths: team-specific and cascaded default), avoiding a separate frontend request on team switch.
- **`ManageTeamView.vue`** — Internal integrations subsection `v-if` updated from `isTeamAdmin && presenceModuleEnabled` to `isTeamAdmin && (presenceModuleEnabled || decisionsModuleEnabled)` so the subsection stays visible when either module is on.
- **`TeamView.vue`** — resets `decisionsConfig` on team switch; reads both `decisionsModuleEnabled` and `decisionsConfig` from layout response; `decisionsConfig` watcher added to rebuild tab list (ready for Session D tab addition).



Session focus: replacing the flat-avatar-stack members widget with a tabbed widget that gives every member their own row, surfaces live status + contact actions, and adds a visualisation of tomorrow's scheduled presence.

### Added

- **`MembersWidget.vue`** — new tabbed members widget mounted on the team home. Three tabs:
  - **Members** — vertical scrollable list, one row per effective team member (direct + indirect via groups/sub-teams, deduplicated). Each row shows avatar + presence dot, name + "Current Status: …" text, and right-aligned Talk / phone / email icons. Each contact icon renders only when the corresponding data exists for that user. Talk launches a 1:1 conversation via `generateUrl('/apps/spreed/') + '?callUser=' + encodeURIComponent(uid)`. Sorted by live presence rank, then by display name.
  - **Tomorrow** — same row shape but right-side shows two presence pills (morning / afternoon) using the colour and label from the team's presence schedule for tomorrow. Pill text colour computed from background luminance so admin-chosen colours stay legible. Footer link "View Full Presence Calendar" emits `view-presence-calendar` → parent emits `set-view='presence'`. Hidden when the presence module is disabled instance-wide or for the team.
  - **Search** — single search input that filters the member list by displayName/userId. Auto-focuses on tab open.
- **`MemberRow.vue`** (`src/components/members/`) — row component used by Members and Search tabs.
- **`MemberPresenceRow.vue`** (`src/components/members/`) — row component used by the Tomorrow tab.
- **Backend enrichment** — `MemberService::getAllEffectiveMembers` now returns each row with `email` (when account-property scope permits), `phone` (when account-property scope permits), and `ncStatus` (live NC user status with `{status, message, icon}`, batched via `IUserStatusManager::getUserStatuses($uids)` in a single call).
- **`MemberService::isTalkAvailableForCurrentUser()`** — helper indicating whether the Spreed app is enabled for the viewer. Surfaced in the `/members/all` envelope as `talkAvailable`.

### Changed

- **`GET /api/v1/teams/{teamId}/members/all`** response envelope is now `{ members: [...], talkAvailable: bool }`. The `members` field continues to carry `userId` and `displayName` (legacy consumers unchanged); new fields `email`, `phone`, `ncStatus` are additive. Store unwrap tolerates a bare array for forward/backward compatibility.
- **Vuex store** gained `allEffectiveMembersTalkAvailable` state and a matching mutation. `fetchAllEffectiveMembers` reads the new envelope.
- **`TeamWidgetGrid.vue`** — desktop grid and tablet members blocks now render `<MembersWidget />` instead of inline avatar/membership content. The "Show all N members" modal removed (Search tab replaces it).
- **`MobileWidgetView.vue`** — mobile members canvas renders `<MembersWidget />` and re-emits `set-view` to the parent. `show-all-members` emit removed.

### Removed

- **All Members modal** in `TeamWidgetGrid.vue` (template + `NcModal`/`NcTextField`/`NcLoadingIcon` imports + `allMembersModalOpen`/`allMembersList`/`allMembersLoading`/`allMembersSearch` data + `openAllMembersModal`/`closeAllMembersModal`/`filteredAllMembers` methods/computeds + associated CSS).
- **Avatar-stack rendering and helpers** in `TeamWidgetGrid.vue` (`membersWithPresence`, `visibleMembersWithPresence`, `tabletAvatarMembers`, `ncStatusColor`, `ncStatusLabel`, `presenceSortRank`, `loadTodayPresence`, `presenceSlots`/`ncStatusByUser` data, today-presence watcher, associated CSS). MembersWidget owns its own status/presence logic now.
- **Inline group/team memberships list** from `TeamWidgetGrid.vue` and `MobileWidgetView.vue`. The Members tab now lists the effective users directly (deduplicated across direct membership and indirect via groups/sub-teams). The Manage Team → Members view continues to show how each user was added (direct vs. via group/team) for administrative clarity.
- **Unused state from MobileWidgetView**: `members`/`memberships`/`effectiveMemberCount` mapState entries, `visibleMembers` computed, `show-all-members` emit, `AccountMultipleIcon` import, and `.teamhub-mobile-memberships*` CSS.
- **Unused `intravoxAvailable` mapState** in both `TeamWidgetGrid.vue` and `MobileWidgetView.vue` (was no longer referenced after earlier sessions).
- **Unused `axios` import** in `TeamWidgetGrid.vue` (was used only by removed methods).

### Security

- **Email visibility scope respected.** Email is now read via `IAccountManager` and skipped when the user's account-property scope is `SCOPE_PRIVATE`, matching the existing phone-field behaviour. Fallback to `IUser::getEMailAddress()` (which has no scope) only when `IAccountManager` is unavailable. Phone-field behaviour unchanged (already respected scope).
- **Authorization gate unchanged**: `getAllEffectiveMembers` still calls `requireMemberLevel($teamId)`. Non-members cannot reach the enriched data.
- **Talk URL** uses `encodeURIComponent(uid)` to neutralise any URL-injection vector from a userId carrying control characters.
- **No new endpoints.** Existing endpoint, additive fields only.

## [3.62.0] — 2026-06-01 — Files Center consolidation + Decisions feature design

Session focus: consolidating the three files widgets into one tabbed widget; locking the design for the upcoming Decisions feature (module-gated, mirroring the Presence pattern).

### Added

- **`FilesWidget` (Files Center)** — new consolidated widget on the team home with three internal tabs: Favourite Files / Recently Modified / Shared Files. Default tab: Recently Modified. Replaces the three previously-separate widgets. Adds a `+` button in the header that opens the team folder in NC Files. Renders on desktop (grid), tablet, and mobile layouts.
- **`ROADMAP.md`** — forward-looking proposal log, distinct from CHANGELOG (shipped) and HANDOFF (per-session). Five proposed features captured: Team Decisions (locked, next), Team Timeline (Gantt-like view), Team Workload, Team Pulse digest, Team Objectives.
- **`docs/design/decisions.md`** — full v1 specification for the Decisions feature. Module-gated mirroring Presence (global `decisions_module_enabled` app-config + per-team `teamhub_decision_team.decisions_enabled`). Three-state lifecycle (`proposed | decided | withdrawn`). One-shot marking; no modal — refinement happens by posting a synthesised comment before marking. Supersession link-only via `supersedes_id`. Snapshots question text and selected-answer text on the decision row for audit durability. Impact required (low/medium/high); category optional with autocomplete from team history.
- **`docs/design/decisions-session-plan.md`** — implementation slicing for Decisions across five sessions (A: module gate, B: data layer, C: in-stream entry point, D: widget, E: tab view) plus an optional polish session.

### Changed

- **Files widgets consolidated.** `widget-files-favorites`, `widget-files-recent`, and `widget-files-shared` replaced by a single `widget-files-center`. Existing saved layouts auto-migrate via `LayoutController::mergeNewWidgets` — the three legacy IDs are pruned on the first GET and the new consolidated widget is added in their place. Old IDs kept in `ALLOWED_WIDGET_IDS` temporarily so in-flight saves from cached clients aren't rejected mid-upgrade.

### Removed

- **`shared_files` per-team toggle.** Manage-team's Team Apps section no longer carries the toggle; create-team flow no longer pushes a `shared_files: false` app state; mobile view's "Shared files" widget entry consolidated into the new Files Center entry. The Shared Files tab is always available in the consolidated widget when the team has any files resource. `FolderAccountIcon` import removed from `FilesSharedWidget` and `ManageTeamView` (was only used for the now-removed empty state).
- **`FilesFavoritesWidget`, `FilesRecentWidget`, `FilesSharedWidget`** are no longer registered as standalone grid widgets — they're now internal sub-components of `FilesWidget`, mounted only when their tab is active (no wasted API calls for off-screen tabs).

### Security

- No new endpoints. No new API responses. No new fields exposed. No authorisation gates weakened. `teamFolderUrl` constructed via `generateUrl()` + `encodeURIComponent(path)`; path source is server-derived (`ResourceService`), not user input.

## [3.61.0] — 2026-05-31 — Image cache, consolidated event modal, widget cleanup

### Added
- **Message image cache.** When a user inserts an image from their personal files, the new `POST /api/v1/teams/{teamId}/messages/cache-image` endpoint copies the file into `.teamhub-cache/` inside the team folder. The cached copy is accessible to all team members via `/core/preview` (team folder is circle-shared). Survives if the posting user leaves the team.
- **Clear image cache.** New `DELETE /api/v1/teams/{teamId}/messages/image-cache` endpoint (admin level). Exposed as a button in Manage Team → Messages tab with a count of files removed.
- **Attendees in AddEventModal.** Team members can now be selected as attendees directly from the add-event modal. Server sends iTIP invitations identically to the meeting wizard. Self is excluded from the picker.
- **RoomVox room picker in AddEventModal.** When RoomVox is installed and configured, the location field becomes a room picker; free-text fallback when no rooms are available.
- **Category field in AddEventModal.** Comma-separated category tags, passed through to the calendar event.
- **Talk meeting toggle in AddEventModal.**

### Changed
- **`AddEventModal` consolidates `ScheduleMeetingModal`.** Both modals used the same backend endpoint with minor field differences. Single modal now covers all simple event creation. `ScheduleMeetingModal.vue` is unused and can be removed.
- **`@schedule-meeting` event** in `TeamView` now routes to `showAddEvent` (same consolidated modal).
- **Schedule Meeting action removed** from the upcoming events calendar widget (desktop and tablet layouts).

### Fixed
- **Images inserted from personal files were invisible to other team members.** `/core/preview?fileId=...` enforces per-user ACL — other members got 404. Fixed by caching the file in the circle-shared team folder and using that copy's fileId.
- **Uploaded/attached images were invisible to other team members.** Circle share was created correctly but the URL still used `/core/preview`. Fixed by using `/apps/files_sharing/publicpreview?token=...` (share-token-based, no per-user ACL check).
- **Pluralisation of "files removed" in cache clear confirmation** now uses `n()` instead of a conditional string.

## [3.60.0] — 2026-05-30 — Meeting wizard: RoomVox booking, personal-calendar availability, recurring events

A major iteration on the meeting wizard introduced in 3.59. Real room booking via RoomVox's public API; availability checks that span every calendar an attendee can attend; recurring events expanded properly; 15-minute reminder on every created event; attendees materialised into invitee calendars via iTIP; ironed out a deterministic UID collision that was masquerading as several other bugs.

### Added
- **RoomVox integration.** The wizard's step-5 location field becomes a room picker when RoomVox is installed and an admin has configured an API token in TeamHub admin settings. Picking a room books it via RoomVox's public REST API (`POST /api/v1/rooms/{id}/bookings`) BEFORE the calendar event is written; booking failure aborts the whole creation with RoomVox's own error message surfaced to the user. New service `RoomVoxClient` (typed exception, Bearer token from admin config). New `RoomDiscoveryService` reads bookable rooms via both `OCP\Calendar\Room\IManager` and `OCP\Calendar\Resource\IManager`.
- **RoomVox token admin setting.** New "RoomVox integration" section in admin settings: write-only token field (password-type, never echoed back), `__CLEAR__` sentinel to remove, format validation (`rvx_…`). Picker is hidden when no token is configured (avoids dead-end UX).
- **Booking cancellation hook.** `CalendarObjectDeletedListener` listens for `CalendarObjectDeletedEvent` and `CalendarObjectMovedToTrashEvent` (both classes guarded with `class_exists`). On delete, extracts `X-TEAMHUB-ROOMVOX-BOOKING-UID` / `X-TEAMHUB-ROOMVOX-ROOM-ID` from the deleted ical and calls `DELETE /api/v1/rooms/{id}/bookings/{uid}` to free the RoomVox reservation.
- **Per-attendee invitations.** Selected wizard members are emitted as `CUTYPE=INDIVIDUAL` ATTENDEEs on the event with `PARTSTAT=NEEDS-ACTION` and `RSVP=TRUE`. Sabre's scheduling plugin delivers the event into each invitee's personal calendar (and email via iMIP when configured at the dav app level).
- **15-min DISPLAY VALARM** on every event created through `createCalendarEvent`. RFC 5545 compliant: `ACTION:DISPLAY`, `TRIGGER:-PT15M`, `DESCRIPTION:<event title>`.
- **`PersonalAndTeamBusyProvider`.** New busy-provider implementation that walks each attendee's personal calendars (`principals/users/{uid}/…`) AND every calendar of every team they're a member of (`principals/circles/{teamId}/…`). Solves cross-team scheduling: when user X is in team Y and team Z, a wizard run from team Z sees X as busy at any time X is committed on team Y.
- **RRULE expansion in both busy paths.** `PersonalAndTeamBusyProvider` and `TimeslotSuggestionService::readPersonalBusy` now expand recurring events via `\Sabre\VObject\Recur\EventIterator` with `fastForward()` to the window start. A weekly meeting whose master DTSTART is months in the past is now correctly reported busy on every recurrence inside the search window. Safety cap at 1000 iterations protects against malformed `FREQ=MINUTELY` events.
- New endpoint `GET /api/v1/teams/{teamId}/rooms` — returns the bookable room list for the wizard's picker. Member-auth.
- New endpoint `GET /api/v1/teams/{teamId}/presence/suggest-timeslots` — within a half-day, returns top-3 free 15-minute-grid windows that fit a requested duration, accounting for every attendee's personal+team-membership calendar conflicts. Member-auth.
- New optional fields on `POST /api/v1/teams/{teamId}/calendar/events`: `attendees` (comma-separated uids or array), `roomEmail`, `roomName`, `roomId`, `includeTalk`, `categories`. Defaults preserve prior behaviour for non-wizard callers (AddEventModal, ScheduleMeetingModal, TeamMeetingModal).
- Default-resource selectors for the wizard: duration (30/45/60/90/120 min), Talk meeting toggle (auto-on and disabled for online meetings), category (free-text comma-separated CATEGORIES).
- Anonymous telemetry: room picks per team (count only, no room identifiers).

### Changed
- **Wizard label.** "Suggest meeting times" → "Meeting wizard" in both widget headers (calendar widget and presence widget).
- **"In office" → "Office"** in the meeting-type radio.
- **Stage 1 (half-day scorer) is now presence-only.** Calendar consultation moves entirely to stage 2 (timeslot picker), where the precise free-window search happens. A 30-min team meeting at 09:00 no longer eliminates the whole morning from suggestions. `MeetingSuggestionService` is wired with an empty providers array in `Application.php`; `TeamCalendarBusyProvider` and `PersonalAndTeamBusyProvider` remain registered for stage-2 consumers.
- **Office-meeting score formula.** Was `bestBuildingCount` (gated on at least one attendee assigning a specific room); now `inOfficeCount` (anyone whose presence type has `requiresLocation=true`, regardless of room assignment). Eliminates the "everyone's at the office but nobody set a room → half-day scored 0" failure pattern. Best-building name still surfaced for descriptive purposes when known.
- **Timeslot descriptions include the most-suitable building** for office meetings: "2 of 2 available · most suitable location: HQ". Pass-through from the picked half-day; no recomputation per timeslot.
- **Wizard picker hides the organiser.** The current user is filtered out of the team-member list — they're on the meeting by definition. Case-insensitive match (LDAP backends can return inconsistent casing). The organiser is still counted in suggestion scoring and "N of M available" denominators.
- **`TimeslotSuggestionService::readPersonalBusy`** now reads from every calendar the user can attend events on (personal-principal-owned + team-membership), matching `PersonalAndTeamBusyProvider`'s scope. Old `personal`-only behaviour caused availability checks to lie when an attendee's only conflict was on a different team's calendar.
- **All form fields in wizard step 5** unified to a single label-on-top pattern; replaces `NcTextField`/`NcTextArea` mix that rendered with inconsistent label styles.
- **iCalendar emission** for any event with attendees: emits `ORGANIZER` (with organiser email), `SEQUENCE:0`, `STATUS:CONFIRMED`, organiser as `ROLE=CHAIR` ATTENDEE with `SCHEDULE-AGENT=CLIENT`, per-invitee ATTENDEEs, room ATTENDEE as `CUTYPE=ROOM` when applicable. RFC 5546 §3.2.1 compliant. `METHOD:REQUEST` is no longer included in stored data (that property belongs to iTIP transport, not stored events) — fixes RoomVox "checking availability" forever.

### Fixed
- **Calendar object with uid already exists in this calendar collection.** A foreach loop that re-used `$uid` as its iterator was shadowing the random event UID we'd generated 60 lines earlier. After the loop, `$uid` held the last attendee's name, and `$objUri` became `<last_attendee>.ics` — deterministic across calls. Second meeting with the same final attendee collided on UID. Loop variable renamed to `$attendeeUid`. (This single bug masqueraded as Sabre scheduling weirdness for three sessions worth of attempted fixes.)
- **NC's IClient SSRF guard blocked localhost RoomVox calls.** The flag is `$options['nextcloud']['allow_local_address']` — a nested array, not a flat `nextcloud-allow-local-address` key. NC silently ignored the flat form. Fixed in both booking and cancellation paths.
- **Missing membership gate on `POST /calendar/events`.** Pre-existing endpoint that lacked `requireMemberLevel()` — any logged-in NC user could write events into any team's calendar, and (after this session's wiring) book RoomVox rooms via any team's token. Fixed; matches every other team-scoped endpoint.
- **PHP errors when picker re-rendered with no rooms** — defensive null-fallbacks in `slotAriaLabel` / `slotTitle` in `PresenceCalendarView.vue`.

### Security
- RoomVox API token is **write-only** in the admin API. The load endpoint returns a boolean `roomvoxTokenConfigured` indicator only; the token itself is never echoed back to the browser. Same pattern NC uses for SMTP passwords.
- New `requireMemberLevel` gate on `createCalendarEvent` (see Fixed).
- All new endpoints (`listRooms`, `suggestTimeslots`) gated on team membership before any data is read.
- Diagnostic logging that captured user emails / uids during this session has been stripped before ship. Remaining info-level logs in `RoomVoxClient` (booking created/cancelled) log only `roomId` and the RoomVox booking UID — no user data. The synthetic-mailto log in `ActivityService` logs the uid (an actionable admin signal: user needs an email on their profile) but not the synthetic address.

### Notes
- Loopback HTTP to RoomVox's documented public API v1 is a deliberate architectural exception, recorded in `DESIGN.md`. `IClient` allow-local-address flag is per-request; SSRF protection remains in force for every other outbound HTTP call.
- Cross-team busy visibility is by design: a wizard run from team Z that sees user X as conflicting at 10:00 Thursday — because of an event on team Y — surfaces only the conflict count, never the event details. Time commitments are a coordination signal, not a secret.
- The half-day scorer assumes that finding zero free timeslots inside an accepted half-day is rare; we do not pre-filter half-days against "can a meeting actually fit." If real-world misses surface, we'd add a "at-least-N-free-minutes" check at stage 1 as a follow-up.

## [3.59.0] — 2026-05-28 — Suggest meeting times (presence-powered)

### Added
- **"Suggest meeting times" wizard.** A presence-powered planning tool that proposes the best half-days (AM/PM) for an online or in-office meeting, scored on who is working, where (home/office), and which office most attendees share. Multi-step wizard: pick attendees (checklist with "Select all"), choose online vs in-office, pick a target date, review the top three scored half-days, then set a title/time/details and create the event directly.
- New endpoint `GET /api/v1/teams/{teamId}/presence/suggest-times` returning ranked suggestions. Member-auth, and gated server-side on the presence module being enabled **both** globally and per-team (403 if either is off).
- Two entry points, both shown only when the presence module is enabled globally and for the team: a button on the calendar-view toolbar, and an action in the upcoming-events widget header (desktop and tablet layouts).
- Pluggable busy-provider interface (`BusyProviderInterface`); v1 ships `TeamCalendarBusyProvider` (team-calendar conflicts). Personal-calendar free/busy can be added later as another provider with no scorer change.
- Anonymous telemetry counter `suggest_wizard_uses`.

### Notes
- Scoring is half-day granular and timezone-aware: each member's presence slots are floating local time, so a candidate instant is mapped into each attendee's own NC timezone before lookup. Suggestions are anchored/displayed in the organiser's timezone.
- The existing AddEventModal is untouched — the wizard creates events via the existing `/calendar/events` endpoint.

## [3.58.3] — 2026-05-27 — Files picker: missing confirm button

### Fixed
- **FilePicker had no "Choose" button.** `@nextcloud/dialogs` v7 requires `.addButton(...)` to be called explicitly on the builder, otherwise the picker mounts with no confirm action (highlight works, nothing happens on selection). Added a primary "Choose" button to both insertion paths (Post Message dialog and message edit dialog).

## [3.58.2] — 2026-05-27 — Inline images: internal preview URLs, Files picker, lightbox above widgets, no redundant preview cards

Four fixes for issues that surfaced when testing 3.58.1 on a live install.

### Added
- **"Browse Files…" in the insert-image dialog.** A new button next to the URL field opens NC's FilePicker filtered to image MIME types. The chosen file's numeric id is resolved via PROPFIND and the dialog's URL field is pre-filled with the NC core preview URL — same flow in both the Post Message dialog and the message edit dialog.

### Changed
- **Image uploads now insert the NC core preview URL.** The dav PUT response's `OC-FileId` header is captured to build `/index.php/core/preview?fileId=<id>&x=1024&y=1024&a=true` for image content types. This is the URL form an `<img>` element can actually render (the previous share landing URL served HTML, and the dav download URL needed WebDAV auth that browsers don't attach for `<img>` requests).
- **Image-proxy content-type sniffing.** When upstream returns no Content-Type or one that isn't `image/*` (some CDNs return `application/octet-stream`), the body is sniffed with `finfo`. If it's actually an image, we serve it with the sniffed type; otherwise we still reject. Fixes "not all external images load" without weakening the not-an-image guard.

### Fixed
- **Lightbox opened behind NC widgets.** The lightbox lived inside `MessageCard.vue`'s subtree, which sits inside the widget grid's stacking context, so a `z-index` alone couldn't lift it above sibling widgets. Now teleported to `<body>` (Vue 3 `<Teleport>`), so it escapes every parent stacking context. z-index bumped to 100000 defensively.
- **Redundant link-preview card under inline images.** `extractUrlObjects` now strips `![alt](url)` spans before scanning for preview targets — the image is the content, a card under it was just noise.

## [3.58.1] — 2026-05-27 — Inline images: fix disabled Insert button + corrupted render

Two bugs in the 3.58.0 inline-images feature, reported from a live test install.

### Fixed
- **Insert-image dialog "Insert" button stayed disabled and fields didn't bind.** The dialog used Vue 2 `:value.sync` / `:open.sync` on `NcTextField` / `NcDialog`, but the app is on `@nextcloud/vue 9.x` (Vue 3) where these are `v-model`-based. Typed text never reached `imageDialogUrl`, so the button's `:disabled` guard never cleared. Switched to `v-model` on the fields and `:open="true"` + `@closing` on the dialog (matching `CreateTeamModal.vue`).
- **`![alt](https://…)` rendered a broken image followed by raw text.** The image rule emitted the `<img>` tag inline, then the step-5 bare-URL autolinker matched the `https://` *inside* the emitted `src="…"` attribute (its negative lookbehind only guarded `href="`, not `src="`), splitting the tag. The image rule now stashes its output behind a placeholder (like fenced code blocks) and restores it after all link/text passes, so no later regex can touch it.

## [3.58.0] — 2026-05-27 — Inline images in messages (URL + upload), proxied & sanitised

User-requested feature: insert images into messages via URL and via file upload, with a frame that scales to fit without cropping. Security-sensitive (touches the message renderer/sanitizer and elevates the image proxy to per-message user input) — built as a single feature this session.

### Added
- **Inline images in message bodies.** `![alt](url)` markdown now renders as an inline `<img>` where it is typed, in both new messages and edits. Optional width: `![alt|320](url)` (clamped to 1–2000 px; non-numeric dropped).
- **Insert-image-by-URL dialog** on the Post Message toolbar and the edit toolbar (URL + alt text + optional width; alt requested for accessibility).
- **Image uploads render inline.** The existing attachment flow now emits `![name](shareUrl)` for image content types (rendered inline) while non-image attachments keep the `📎` link behaviour.
- **Click-to-zoom lightbox.** Clicking (or keyboard-activating) an inline image opens a full-size, focus-trapped, Escape-closable overlay reusing the already-sanitised src.

### Changed
- **Smart frame for inline images.** `max-width: 100%; height: auto; max-height: 400px; object-fit: contain;` so images scale to the message column without cropping or distortion.

### Security
- **Renderer/sanitizer hardening (`MessageCard.vue`).** `img` added to the DOMPurify allowlist behind a new `afterSanitizeAttributes` hook that rewrites every image `src`: remote `https://` → backend image proxy; same-origin NC paths → pass through; **everything else (`data:`, `http:`, `javascript:`, protocol-relative, cross-origin) is dropped**. Alt/URL are attribute-escaped at the markdown layer; inline images carry `referrerpolicy="no-referrer"`.
- **SSRF hardening of the image proxy (`LinkPreviewService::isAllowedUrl`).** The allowlist now resolves the host to its IP addresses and rejects any private/loopback/link-local target — including the cloud metadata IP `169.254.169.254` (and its IPv4-mapped IPv6 form), IPv6 unique-local `fc00::/7`, IPv6 loopback/unspecified, and decimal/octal/hex-encoded IPv4 forms (e.g. `http://2130706433`). Fails closed when a host does not resolve.
- **Per-redirect re-validation (`LinkPreviewController::proxyImage`).** The proxy no longer lets the HTTP client follow redirects blindly; it walks up to 3 hops manually and re-resolves and re-checks each hop's IP before fetching it, defeating redirect-to-internal SSRF after the initial allowlist pass.

## [3.57.2] — 2026-05-26 — Admin tab-bar active-tab fix + maintenance date fix

### Fixed
- **Admin settings: clicked tab no longer shows white-on-white.** The active tab matched both the active-state override and the inactive-focus rule at equal CSS specificity; being later in source order, the inactive rule's white background won while the active rule's `!important` white text stayed — rendering an empty white box until focus moved elsewhere. The inactive-focus rule now excludes the active tab via `:not(.teamhub-admin-tab--active)`.
- **Admin settings → Maintenance: team "Created" date no longer shows "Invalid Date".** Two methods named `formatDate` existed in `AdminSettings.vue`; the second (expecting a Unix timestamp) silently overrode the first (for datetime strings), so the teams table's datetime-string `creation` value was multiplied as a number → `NaN` → "Invalid Date". Split into `formatDate` (datetime string, hardened to also accept ISO and numeric input) and `formatUnixDate` (Unix timestamp, used by the archive table).

## [3.57.1] — 2026-05-26 — Translation catalog sync (nl/de/fr/da) + admin tab-bar hover removal

### Added
- **Full translation catalog sync.** 489 previously-untranslated strings added to each of the four supported languages (Dutch, German, French, Danish). Recent sessions had wrapped new strings in `t()`/`n()` but not added them to the `l10n` catalogs, leaving large parts of the UI English-only — Permissions tab, Integrations tab, Personal settings (My Presence), Admin settings (Create team / Group Folders, Integrations, Maintenance, Presence module, Archive), and more. German uses formal register (Sie/Ihr) to match the existing catalog; French `pluralForm` (`n > 1`) preserved. All plural strings stored as NC `[singular, plural]` arrays; `.js` and `.json` regenerated in sync for all four languages. Placeholder parity validated across every entry.

### Changed
- **Admin tab bar: removed all hover styling.** Hovering off the active tab caused a transient z-index/seam repaint that looked wrong. The `:hover` rules on the admin settings tab bar were removed entirely; tabs stay plain until active. Keyboard focus states (`:focus-visible`, `:active`) retained for accessibility.

## [3.57.0] — 2026-05-26 — Members presence sort, Presence as internal integration, presence-module telemetry (app + receiver)

Three minor items plus the telemetry back-end. Intermediate minors 3.56.1–3.56.3 were the in-session iterations.

### Added
- **Members widget sorts by presence status.** Direct-member avatars now sort by their current merged presence: NC online → schedule-busy → NC dnd/busy → NC away → schedule-free → no status, with a stable display-name tie-break within each rank. The schedule busy/free distinction is now driven by a backend `is_busy` flag on each presence slot (correct for custom presence types and in hide-reasons mode), replacing the previous hardcoded `home`/`office` slug guess. (3.56.1)
- **Presence-module telemetry.** The anonymous telemetry payload now includes `presence_module` (boolean) — whether the Presence internal integration is enabled instance-wide. No content, no per-team or per-user data; reads the same `presence_module_enabled` app value the frontend uses. (3.56.3)
- **Telemetry receiver + dashboard (tldr.host, outside the app repo).** The receiver validates and persists `presence_module` to the `installations` and `daily_snapshots` tables; the dashboard surfaces a "Presence module" adoption panel (enabled count, % of active installs, bar) under App integrations. Ships with a one-time `ALTER TABLE` migration adding `presence_module TINYINT(1) NOT NULL DEFAULT 0` to both tables.

### Changed
- **Presence module presented as an internal integration.** The "Presence tab" and "Hide status details" toggles moved out of *Manage team → Settings → Team apps* into *Manage team → Integrations*, under a dedicated **Internal integrations** subsection (with a "Built-in" badge), separated from the **Third-party integrations** list. The integrations-tab description was updated to cover both internal and third-party integrations. Behaviour and endpoints unchanged — markup relocation only. (3.56.2)

### Security
- No new endpoints. The `is_busy` slot field is returned by the existing membership-gated `/teams/{id}/presence` endpoint and exposes nothing beyond what the slot colour already conveys (busy/free), with the reason/label/slug still suppressed in hide-reasons mode. The presence toggles keep their existing admin gate. Telemetry remains anonymous and aggregated.

## [3.56.0] — 2026-05-26 — Admin settings visual pass, CSS delivery fix, presence/status merge, bug fixes

Session covering admin-settings presentation, a build-system CSS-delivery fix, the presence/NC-status merge, and several bug fixes. The intermediate minor versions (3.55.2–3.55.8) were the in-session iterations; this is the shipped roll-up.

### Fixed
- **CSS not delivered to admin & personal settings pages.** `@nextcloud/vite-config`'s `inlineCSS` only attached component styles to the main entry, so `admin.mjs`/`personal.mjs` shipped with no CSS — the admin and personal settings rendered as unstyled HTML (broken tab bar, grids/tables collapsing to stacked text). Switched to per-entry CSS extraction into the app `css/` directory, loaded explicitly from each template via `Util::addStyle`. Build now roots at the app dir (`outDir: '.'`, `emptyOutDir: false`) routing JS→`js/` and CSS→`css/`. (3.55.2)
- **Post Message form showed an empty grey box.** A stray directiveless `<template>` wrapper in `PostMessageForm.vue` was swallowed as an empty fragment under Vue 3, hiding the entire form body. Removed the wrapper. (3.55.3)
- **New (non-admin) users could not open "My Presence".** `MyPresencePanel` called two admin-only endpoints (`/admin/presence/types`, `/admin/presence/locations`) → 403 → "Failed to load presence settings". Added non-admin read-only mirrors (`/presence/types`, `/presence/locations`) and repointed the panel. (3.55.5)
- **Admin tab bar active tab flickered to a soft colour on mouse-leave.** The clicked tab retained `:focus`, and NC's global focus background repainted the active tab light green until focus moved away. Active tab now holds its hard-green fill across hover/focus/focus-visible/active. (3.55.8)

### Added
- **Presence ↔ NC user-status merge in the members widget.** Each member avatar now shows a single merged presence dot. The TeamHub scheduled presence is the baseline; live NC status overrides it when it is `dnd`/`busy`/`online` or a user-set `away` (automatic idle-away reverts to the schedule, classified via the concrete status object's `getIsUserDefined()`, falling back to the `availability` message id). Backend fetches statuses via `IUserStatusManager` and returns `nc_status` on the team-grid response. (3.55.4)
- **Open files from widgets inside the app.** Clicking a file in the shared/favourites/recent files widgets now opens it in TeamHub's files-view iframe instead of a new browser tab. Modified clicks (ctrl/cmd/shift/middle) keep the native new-tab. New store action `openFileInEmbed`; the files view also renders for a file override even when the team has no team folder. (3.55.7)

### Changed
- **Admin settings visual pass.** Tab bar reworked to a classic folder-tab style (butted tabs, hard-coloured active tab with the baseline broken beneath it); removed the soft hover background; removed the redundant "Wizard introduction text" label; fixed the floating group-chip icon under Creation permissions; defensive CSS so the broken admin grids/tables render correctly once CSS loads. (3.55.2, 3.55.6, 3.55.8)
- **Hard-contrast presence status colours** (online `#00c853`, away `#ffab00`, dnd/busy `#d50000`) replacing soft theme vars that washed out at dot size. (3.55.6)

### Security
- New `/presence/types` and `/presence/locations` endpoints are `#[NoAdminRequired]` read-only and require an authenticated session; all mutation stays admin-gated. The team-grid `nc_status` addition exposes only the status enum and an override boolean — no personal content — and remains behind the existing team-membership gate.

## [3.55.1] — 2026-05-25 — Reconciliation: close out upload-race + activity/poll translation fixes

The upload-race fix and the activity/poll-string translations described below were
already present in the working tree but had not been version-stamped, recorded in the
CHANGELOG, or removed from HANDOFF's open-issues list. This release verifies them,
strips a leftover debug log, and brings the records back in sync with the code.

### Fixed
- **`PostMessageForm.vue` — attachment upload write-to-wrong-row race.** `uploadFile` previously captured a positional `idx` before its `await`s and wrote the result back with `this.attachments[idx] = …`. If a concurrent upload (`Promise.all`) reordered the list, or the user removed an earlier attachment mid-upload (`removeAttachment` splices the array), that index went stale and the result landed on the wrong row. Writes now resolve the row by stable id (`findIndex(a => a.id === att.id)`) at write time via a `writeAttachment(patch)` helper, and silently drop the result if the row was removed while the upload was in flight. The API payload is unchanged.

### Changed
- **`MessageCard.vue` — poll vote count now pluralised via `n()`.** `getPollVotes` returned a hardcoded `'1 vote'` / `'{n} votes'`; now uses `n('teamhub', '{n} vote', '{n} votes', votes, { n: votes })` with a `TRANSLATORS:` hint.
- **`ActivityFeedView.vue` / `ActivityWidget.vue` — activity subject lines fully translatable.** Every branch of `formatSubject` (Circles, Files, Deck, Calendar/DAV, Talk, and the fallback) now returns a `t('teamhub', …)` string with named placeholders (`{user}`, `{file}`, `{detail}`) instead of interpolated template literals, so translators control word order. Deck's optional decorated fragments are passed as `{card}` / `{board}` named placeholders for repositioning. Per-line `TRANSLATORS:` hints added, including disambiguation ("list" = Deck column/stack; "board" = a Deck board). No concatenated returns remain. **Translators: nl/de/fr/da should review word order and the trailing `{card}{board}` fragment placement, which is English-order in the source strings.**

### Removed
- Leftover development `console.log` in `PostMessageForm.uploadFile`'s row-gone guard.

## [3.55.0] — 2026-05-25 — V6 performance pass (render-cost reduction)

### Changed
- **`CommentsSection.vue` — memoized comment-body rendering.** The template called `renderMarkdown(c.comment)` inside the comments `v-for`, so the full markdown regex pipeline plus DOMPurify ran for every comment on every re-render (including while typing a new comment). Replaced with a memoized `renderedComments` computed (comment id → sanitized HTML); the template now reads `renderedComments[c.id]`. Rendering runs once per comment and only re-runs when the comment list changes. Sanitization (DOMPurify with the same `ALLOWED_TAGS`/`ALLOWED_ATTR` allowlists) is unchanged. Mirrors the existing `renderedMessage` computed in `MessageCard.vue`.
- **`ActivityFeedView.vue` / `ActivityWidget.vue` — memoized subject formatting.** `{{ formatSubject(item) }}` ran a long branch ladder of string operations for every activity item on every render. Folded into the `grouped` / `visibleActivities` computeds respectively; each item now carries a precomputed `subjectText`. Runs once per item, only when `activities` changes.
- **`PostMessageForm.vue` — stable keys for poll options and attachments.** Poll options were plain strings keyed by array index with `v-model="pollOptions[index]"`; removing an option from the middle made Vue reuse the wrong input DOM node. Options are now `{ id, text }` objects keyed on a stable `id` (from a `pollOptionSeq` counter), with `v-model="option.text"`. The submitted API payload is unchanged (still an array of trimmed strings). The attachments list's `:key="i"` (same index-as-key anti-pattern) is now `:key="att.id"` via an `attachmentSeq` counter.
- **Inline `v-for` filters moved to computed properties (5 sites)** to stop re-allocating a filtered array on every render: `inviteConfigOptions` / `privacyConfigOptions` (`CreateTeamView.vue`), `visibleMembersWithPresence` / `tabletAvatarMembers` (`TeamWidgetGrid.vue`), `visibleMembers` (`MobileWidgetView.vue`).

## [3.54.0] — 2026-05-25 — V6 hardening tail (WCAG + translation) and @nextcloud/logger adoption

### Fixed
- **`TeamTabBar.vue` unbound `aria-label`:** the calendar count badge used a static `aria-label="t('teamhub', '{n} calendars', ...)"` (missing the `:` binding), so screen readers announced the literal source code and the string was never translated. Now correctly bound, matching the already-correct boards-count sibling. This was the last remaining `attr="t('teamhub'` instance in the app.
- **Accessible names on three icon-only buttons** (WCAG 2.2 SC 4.1.2 / 1.1.1): `ActivityFeedView.vue` refresh button (now "Refresh activity"), and the chip-remove buttons in `CreateTeamModal.vue` and `CreateTeamView.vue` (now per-member "Remove {name}"). Each gained an `aria-label` + `title`, and its icon now carries `aria-hidden="true"`.

### Changed
- **Adopted `@nextcloud/logger` for frontend logging.** New shared `src/logger.js` exposes a single `getLoggerBuilder().setApp('teamhub').detectUser().build()` instance for the whole app. The 2 `console.error` catch-block calls in `FolderMigrationModal.vue` now use `logger.error('...', { error: e })`. The app is free of raw `console.*`.

### Added
- **`@nextcloud/logger` (`^3.0.3`) as a direct dependency** in `package.json` (previously present only transitively).

### Removed
- Stale repo-root `info.xml` (orphaned, pinned at `3.28.1`; the canonical manifest is `appinfo/info.xml`) and orphaned `HANDOFF-3.51.0.md`. Both were dead files and desync traps; removed so the repo and the delivered package carry a single source of truth.



### Fixed
- **All component styling restored after the Vue 3 / Vite migration.** Since 3.49.0, the entire scoped-style layer was silently absent (broken tab bar, overlapping/un-gridded widgets, missing card and panel styling) because `@nextcloud/vite-config` **extracts** SFC `<style scoped>` blocks and third-party library CSS into `js/css/*.css` files that nothing loaded — no `Util::addStyle()` and no JS-entry `import`. The previous webpack build used `vue-style-loader`, which injected those same styles through the JS bundle at runtime, so no explicit loading was ever needed. Fixed by setting `inlineCSS: true` in `vite.config.mjs`, which (verified against `@nextcloud/vite-config@2.5.2`) routes all CSS through `vite-plugin-css-injected-by-js`, restoring the webpack-era injection behaviour. Also set `build.cssCodeSplit: false` for version robustness. Single-file change; fixes scoped component styles and the `grid-layout-plus` widget-grid library CSS together. Confirmed on a running instance.

## [3.52.0] — 2026-05-25 — Vue 3 migration V4 + V5 (component-API completion & verification) + V6 first slice

### Fixed
- **Input-field labels (`NcTextField` / `NcTextArea`) for `@nextcloud/vue` 9:** v9's `NcInputField` base no longer uses `label` as a placeholder fallback and produces an input with no accessible name (plus a runtime warning) when neither `label` nor `labelOutside` is set. The 3 affected sites — all in `CreateTeamModal.vue` (team name, description, member search) — now use `label-outside` with an explicit `id` per input and `for="<id>"` on the existing visible label, both silencing the warning and establishing a programmatic label association that was previously absent. Verified against `@nextcloud/vue` 9.8.0 source. (41 of 44 input sites were already conformant.)
- **`FolderMigrationModal.vue` unbound `aria-label`:** the space-check table's `aria-label` was a static string literal (`aria-label="t('teamhub', 'Space check')"`, missing the `:` binding) — screen readers announced the literal code and the string was never translated. Now correctly bound and translatable.

### Added
- **`ExternalWidgetItem.vue` widget-name fallback:** since `NcAppNavigationItem.name` is required in v9, a registered external widget with an empty title now falls back to a generic translated "Widget" label instead of rendering a blank required prop.
- **`role="status"` on `FolderMigrationModal.vue` dynamic screens** (preflight loading + migrating) so screen-reader users are announced when those states change.

### Removed
- 4 leftover debug `console.log` statements in `FolderMigrationModal.vue` (standing cleanup item from the V3 handoff). The 2 `console.error` calls in `catch` blocks are retained as production error handling.

### Verified (no change)
- **`@nextcloud/vue` 9 component-API migration is complete.** All 18 NC components used by the app were audited against 9.8.0: `NcAppNavigationItem`/`NcAppNavigationCaption` (`name` required), `NcEmptyContent`/`NcModal`/`NcDialog` (`title`→`name`), `NcRichContenteditable` (`modelValue` required), and the remainder — all already conformant. No code change required for V5.

## [3.51.0] — 2026-05-24 — Vue 3 migration V3 (component-API: model props + NcButton variant)

### Fixed
- **Model-prop migration to `@nextcloud/vue` 9 (`modelValue`):** `NcCheckboxRadioSwitch`, `NcTextField`, and `NcTextArea` switched from the v8 `checked`/`value` props to v9 `modelValue`. This restores two-way binding on every toggle/input the V2 `.sync` syntax conversion left rendering-but-inert — including the confirmed-broken Presence module toggle in *Admin settings → Integrations*. Verified against `@nextcloud/vue` 9.8.0 source. Sites: 9 `v-model:checked`→`v-model` + 8 `:checked`→`:model-value` + 11 `@update:checked`→`@update:model-value` (handler logic preserved); 9 `v-model:value`→`v-model` + 1 `NcTextArea :value`/`@input`→`v-model`.
- **`NcAppNavigation` open binding:** v9 removed the `open` prop (open state is now internal). The dead V2 `v-model:open="navOpen"` is removed and the mobile sidebar auto-close rewired to v9's `toggle-navigation` event bus. Added the now-required `aria-label`.

### Changed
- **`NcButton` `type` → `variant` (214 sites):** v9 split the single `type` prop into `type` (native button type) and `variant` (visual style). All button-style values (`primary`, `secondary`, `tertiary`, `tertiary-no-background`, `error`, `warning`) renamed to `variant`; 5 dynamic `:type` ternaries → `:variant`. Scoped structurally to `NcButton` opening tags only — `NcCheckboxRadioSwitch` (`type="switch/checkbox/radio"`), `NcCounterBubble` (`type="highlighted"`), `NcTextField` (`:type="field.type"`), and all native element types verified untouched.
- **`ResourcePicker` (custom component)** migrated to the Vue 3 `modelValue`/`update:modelValue` convention; both call sites (`CreateTeamView`, `ManageTeamView`) updated to `v-model`.
- Removed one redundant dead `@update:checked` handler on an already-`v-model`'d switch (`AdminSettings`).

### Added
- `@nextcloud/event-bus` `^3.3.3` promoted from transitive to direct dependency (used for the nav toggle). **Run `npm install` to formalize in the lock.**

## [3.50.0] — 2026-05-24 — Vue 3 migration V2 (mechanical sweep)

### Fixed
- **`this.$set` / `this.$delete` removed (34 sites, 8 components):** converted to direct property/index assignment. Vue 3's proxy reactivity makes these reactive without `$set`. Resolves the `TeamView.vue` render crash (`this.$set is not a function`) flagged in V1. Affected: `TeamView`, `BrowseTeamsView`, `AdminSettings`, `PostMessageForm`, `MyPresencePanel`, `CreateTeamView`, `TeamTabBar`, `PresenceLocationsManager`.

### Changed
- **`.sync` modifier → `v-model:prop` (19 sites, 9 files):** `:checked.sync` → `v-model:checked`, `:value.sync` → `v-model:value`, `:open.sync` → `v-model:open`. Pure Vue 3 syntax migration. NOTE: these bind to the v8-era `checked`/`value` props; on the installed `@nextcloud/vue` 9.8.0 they render but do not two-way bind until the prop names are migrated to `modelValue` in V3. Affected: `PresenceTypesManager`, `ManageTeamView`, `TeamMeetingModal`, `CreateTeamView`, `PresenceLocationsManager`, `TeamWidgetGrid`, `AdminSettings`, `PresenceHolidaysManager`, `App.vue`.

### Removed
- **5 dead duplicate `.vue` files** at `src/` root (`TeamTabBar`, `PostMessageForm`, `TeamWidgetGrid`, `MobileWidgetView`, `ArchiveTeamModal`) — byte-identical to the `src/components/` copies and imported by nothing.

### Verified (no change)
- `v-for` + `v-if` same-element: 0 found. `:deep()` selectors: 14, all modern, 0 legacy. No `$listeners`/`$children`/`.native`/`$scopedSlots`/`$on`/`new Vue()`/template-filters anywhere. CSS auto-injection present in build output.

## [3.49.0] — 2026-05-24 — Vue 3 migration V1 (foundation)

### Changed
- **Build system:** migrated from hand-rolled webpack to `@nextcloud/vite-config` v2 (Vite). Three-entrypoint setup via `vite.config.mjs`. Output as `.mjs` files; NC auto-adds `type="module"`.
- **Vue runtime:** upgraded from Vue 2.7 to Vue 3.5. Entrypoints rewritten (`createApp`, `globalProperties`).
- **Vuex:** upgraded from 3 to 4 (`createStore`, `app.use(store)`). All `Vue.set` calls in store converted to direct proxy assignment.
- **Grid layout library:** replaced `vue-grid-layout` (Vue 2, unmaintained) with `grid-layout-plus` (Vue 3 port, identical API). `:layout.sync` → `:layout` + `@update:layout`.
- **Draggable library:** upgraded `vuedraggable` from 2 to 4. `v-for` template → `#item` slot with `item-key`.
- **NC component library:** upgraded `@nextcloud/vue` from 8 to 9, `@nextcloud/dialogs` from 5 to 7.
- **Minimum NC version:** raised from 31 to 32.
- **CSS loading:** `Util::addStyle` removed — Vite auto-injects CSS via the JS entry point.
- **Bundle sizes:** teamhub.js 5.1MB → 619KB, admin.js 3.8MB → 113KB, personal.js 3.4MB → 20KB.

### Fixed
- **`v-for` + `v-if` priority crash:** fixed 3 instances where Vue 3's reversed priority (v-if before v-for) caused `Cannot read properties of undefined` render crashes.
- **`v-model` on props:** `gridLayout` is a read-only prop; replaced illegal `v-model:layout` with one-way bind + event forwarding.
- **Tab bar resource gating:** moved resource-existence checks from slot `v-if` to `renderableTabs` computed — vuedraggable v4 requires exactly one rendered node per item.
- **Grid CSS class names:** remapped `.vue-resizable-handle` → `.vgl-item__resizer`, `.vue-grid-placeholder` → `.vgl-item--placeholder` for grid-layout-plus.
- **`@nextcloud/vue` import paths:** fixed 4 old `/dist/Components/` paths that don't exist in v9.

### Removed
- `vue-grid-layout` dependency (replaced by `grid-layout-plus`)
- `webpack.config.js` (replaced by `vite.config.mjs`)
- `src/store_index.js` (dead code, never imported)
- Webpack/Babel devDependencies: `babel-loader`, `@babel/core`, `@babel/preset-env`, `webpack`, `webpack-cli`, `vue-loader`, `vue-style-loader`, `vue-template-compiler`, `css-loader`, `style-loader`, `loader-utils`
- Stale `window.appVersion = '3.15.0'` hardcode in main.js


## [3.48.0] — 2026-05-20 — Presence admin tab visibility + warning alignment + docs

### Fixed
- **Presence module admin tab still visible when module is disabled** — `tabs()` computed in `AdminSettings.vue` now conditionally includes the Presence module tab only when `form.presenceModuleEnabled` is true. When the toggle is turned off, the tab is removed immediately and the active tab switches to Integrations if needed.
- **Team Info warning strip height and chevron** — replaced "Open settings →" text with a `ChevronRightIcon` icon (consistent with DeckWidget unassigned-card nudge). Updated CSS to use `line-height: 1.3` and icon-only button layout so the strip height matches the DeckWidget row exactly.

### Added
- `docs/USER_GUIDE.md` — comprehensive user guide covering: team navigation, tab bar, presence tab (team view), My Presence personal settings (weekly template + date overrides + calendar integration), team admin actions (members, settings, presence toggles, links, danger tab), and FAQ.

### Changed
- `README.md` — updated to include Presence module, Members widget presence dots, and updated admin settings section.
- `INSTALL.md` — rewritten as a full NC Admin guide: installation, all admin settings tabs (including Presence module on/off, status types, locations, holidays), optional integrations table, background jobs table.
- `src/components/AdminSettings.vue` — `tabs()` gates presence tab on `presenceModuleEnabled`; toggle redirects away from presence tab when disabled.
- `src/components/TeamWidgetGrid.vue` — warning button uses `ChevronRightIcon`; CSS tightened to match DeckWidget strip height.

## [3.47.9] — 2026-05-20 — Presence module global toggle + warning widget alignment

### Added
- **Presence module on/off toggle in NC Admin Settings → TeamHub → Integrations**. Default: off. When disabled: personal settings panel (My Presence) renders nothing; Manage Team → Settings presence toggles are hidden; the Presence tab never appears in the team tab bar. NC admin switches it on to enable for the whole app; team admins then activate it per team as before.
- `presenceModuleEnabled` in `GET /api/v1/admin/settings` response and `POST /api/v1/admin/settings` body.
- `presenceModuleEnabled` in `GET /teams/{teamId}/layout` response (alongside `presenceConfig`).
- `SET_PRESENCE_MODULE_ENABLED` Vuex mutation + `presenceModuleEnabled` state.

### Changed
- `lib/Service/TeamService.php` — `getAdminSettings` returns `presenceModuleEnabled`; `saveAdminSettings` writes `presence_module_enabled` IConfig key.
- `lib/Controller/LayoutController.php` — `getLayout` returns `presenceModuleEnabled` from IConfig; `isPresenceModuleEnabled()` helper added.
- `lib/Settings/PersonalSettings.php` — passes `presenceModuleEnabled` to template.
- `templates/personal.php` — exposes flag as `data-presence-module-enabled` attribute.
- `src/personal.js` — reads flag; only mounts `MyPresencePanel` when enabled.
- `src/store/index.js` — `presenceModuleEnabled` state + `SET_PRESENCE_MODULE_ENABLED` mutation.
- `src/components/TeamView.vue` — `loadLayout` commits `presenceModuleEnabled`; `buildAllTabDescriptors` gates presence tab on both module flag AND per-team flag; `mapState`/`mapMutations` updated.
- `src/components/ManageTeamView.vue` — presence toggles gated on `presenceModuleEnabled`.
- `src/components/AdminSettings.vue` — `presenceModuleEnabled` in `form` data, loaded, saved; toggle switch in integrations tab.
- **Widget warning alignment** — `teamhub-resource-warning` (Team Info widget) moved from inside the content body to directly under the widget header, matching the DeckWidget unassigned-card strip: amber soft background, bottom border only, no surrounding border. `NcButton` replaced with a plain `<button>` for layout control. CSS rewritten to match DeckWidget's `.th-unassigned__row` pattern.

## [3.47.8] — 2026-05-20 — Fix: calendar events not updating (NC soft-delete URI bug)

### Fixed
- **Calendar events not updating on override or template save** — root cause identified via DB query: NC's CalDAV stack soft-deletes calendar objects by appending `-deleted` to the URI rather than removing the row. `findUriByUid` was finding these stale soft-deleted rows, seeing the URI didn't match the canonical target URI, calling `CalDavBackend::deleteCalendarObject` (which appended another `-deleted`), then trying to `createCalendarObject` — which failed with a UID unique-constraint error because the row was still in the table. The URI was growing with repeated `-deleted` suffixes on every sync.
- **Fix:** `findUriByUid` now excludes rows whose URI contains `-deleted`. Additionally, `upsertByUid` calls new `purgeDeletedRowsForUid` before every upsert, which hard-deletes (direct DB `DELETE`) any stale soft-deleted rows for that UID so they cannot block `createCalendarObject`.

### Changed
- `lib/Service/PresenceCalendarService.php` — `findUriByUid` adds `NOT LIKE '%-deleted%'` filter; `upsertByUid` calls `purgeDeletedRowsForUid` first; new `purgeDeletedRowsForUid` private method.

### Manual DB cleanup required for existing installs
Run this once to remove already-accumulated stale rows:
```sql
DELETE FROM oc_calendarobjects 
WHERE uid LIKE '%teamhub-presence%' 
AND uri LIKE '%-deleted%';
```

## [3.47.7] — 2026-05-20 — Presence fixes VIII + Members widget presence

### Fixed
- **Holiday delete 404** — `DELETE /api/v1/admin/presence/holidays/{id}` route was simply missing from `routes.php`. Added.
- **Calendar not updating on override** — added `debug` logging to `findDefaultCalendarId`, `syncSlotsForDate`, and `upsertByUid` to expose exactly where the write fails. Check Nextcloud log (level=debug) after doing an override to see the trace.

### Added
- **Members widget presence status** — `TeamWidgetGrid` now fetches today's team presence grid on mount and team switch. Members are sorted: home/office first, then others. Each avatar shows a coloured dot (bottom-right) reflecting their current half-day presence status. Members with no presence data show no dot.

### Changed
- `appinfo/routes.php` — added `presenceAdmin#deleteHoliday` route.
- `lib/Service/PresenceCalendarService.php` — debug logging added to `findDefaultCalendarId`, `syncSlotsForDate`, `upsertByUid`.
- `src/components/TeamWidgetGrid.vue` — `presenceSlots` data field, `currentTeamId` watcher calling `loadTodayPresence`, `membersWithPresence` computed, `loadTodayPresence` method, `teamhub-stacked-avatar-wrap` + `teamhub-presence-dot` CSS.

## [3.47.6] — 2026-05-20 — Presence fixes VII

### Fixed
- **Override not updating calendar** — `overrideSlot` now calls `syncSlotsForDate($userId, $date)` synchronously (inline, non-fatal) after saving the slot. This syncs only the 2 slots for the affected date, handling the all-day merge/split logic immediately. The background job queue is no longer used for overrides.
- **Holidays not appearing in calendar / date override view** — `addHoliday` now calls `createMissingHolidaySlots` after the bulk overwrite. This inserts `source='holiday'` AM+PM rows for every user who has template cells but no slot on the holiday date, ensuring the date shows as locked in the calendar view immediately (without waiting for the nightly cron).
- **Holiday dates overridable by user** — `overrideSlot` now checks `teamhub_holidays` table first (via `HolidayMapper::findByDate`), rejecting with 409 even when no slot row exists for the user yet. The existing slot-source check is kept as a belt-and-suspenders guard.

### Added
- `PresenceCalendarService::syncSlotsForDate(string $userId, string $isoDate)` — targeted sync of one date's AM+PM calendar events.
- `PresenceTemplateMapper::getAllUserIds()` — returns distinct user IDs of all users with at least one template cell.
- `PresenceHolidayService::createMissingHolidaySlots()` — private method that inserts holiday slot rows for active users with no slot on a holiday date.

### Changed
- `lib/Service/PresenceSlotService.php` — `HolidayMapper` and `PresenceCalendarService` re-injected; `overrideSlot` rewritten with holiday table check and inline `syncSlotsForDate` call.
- `lib/Service/PresenceHolidayService.php` — `PresenceSlotMapper` and `PresenceTemplateMapper` injected; `addHoliday` calls `createMissingHolidaySlots`.

## [3.47.5] — 2026-05-20 — Presence fixes VI

### Fixed
- **Presence tab reload race (definitive fix)** — `presenceConfig` is now returned directly from `GET /teams/{teamId}/layout` (embedded in the response). `loadLayout` commits it to the Vuex store before calling `buildOrderedTabs`, so the tab is always present or absent correctly with zero race conditions. The separate `loadPresenceConfig` HTTP request on team switch is eliminated. The `presenceConfig` watcher in `TeamView` still handles the ManageTeamView toggle case.
- **"Presence" section heading in Manage Team Settings** — removed the unnecessary section title. Presence tab toggle and hide-details sub-item now appear inline with other team app toggles without a header, consistent with other feature toggles.
- **Override shows old state until reload** — `saveOverride` in `MyPresencePanel` now applies an optimistic UI update immediately (before the API call returns), then reloads slots silently in the background. On PHP side, `overrideSlot` queues `PresenceCalendarSyncJob` and returns immediately without blocking on calendar writes.

### Changed
- `lib/Controller/LayoutController.php` — injected `PresenceTeamService`; `getLayout` now returns `presenceConfig` in both response paths. `ALLOWED_TAB_KEYS` extended to include `'presence'`.
- `src/components/TeamView.vue` — `loadLayout` reads `data.presenceConfig` and commits it before `buildOrderedTabs`. `currentTeamId` watcher simplified to call only `loadLayout`. All debug `console.log` calls removed.
- `src/components/ManageTeamView.vue` — removed `team-app-section-title` for Presence.
- `src/components/MyPresencePanel.vue` — `saveOverride` does optimistic update, queues background reload.
- `lib/Service/PresenceSlotService.php` — removed inline `syncAllSlotsForUser` call; queues `PresenceCalendarSyncJob` instead. Removed unused `PresenceCalendarService` dependency.

## [3.47.4] — 2026-05-20 — Presence fixes V

### Fixed
- **Calendar duplicate-key on override** — `syncAllSlotsForUser` now looks up existing calendar events by UID in `oc_calendarobjects` directly (via `findUriByUid`) instead of by URI via `CalDavBackend::getCalendarObject`. This prevents the `calobjects_by_uid_index` constraint violation that occurred when an existing event had a different URI than the one being derived (e.g. after a code change or failed previous sync). New private methods: `upsertByUid`, `deleteByUid`, `findUriByUid`.
- **Team grid blocks missing colour/status** — `dayBlockStyle` was checking `slot.presence_type_color` but the team grid API returns `slot.color`. Fixed field name in `dayBlockStyle`, `blockTitle`, `sameTypeDay`, and all three template label spans.
- **Presence tab: async watcher not awaited in Vue 2** — Vue 2 watchers do not await async functions; the `await loadPresenceConfig` in the `currentTeamId` watcher was silently ignored, so `loadLayout` ran before config arrived. Changed to `.then()` chain which correctly sequences the two async calls.
- **Date overrides calendar: only 2 months shown** — expanded to 4 months (2×2 grid). `loadSlots` now fetches 4 months forward. If all slots are empty on load (e.g. cron hasn't run yet), `triggerMaterialise` is called automatically.

### Added
- `POST /api/v1/presence/slots/materialise` — triggers immediate `rematerialiseForUser` for the current user. Called by `MyPresencePanel` when the slot list is empty on first load.
- `PresenceCalendarService::upsertByUid`, `deleteByUid`, `findUriByUid` — UID-based calendar object management.

## [3.47.3] — 2026-05-19 — Presence fixes IV

### Fixed
- **Team presence tab disappearing on reload** — added `console.log` tracing to `buildOrderedTabs`, `loadPresenceConfig`, `presenceConfig` watcher, and `syncExtTabs` to expose the exact call sequence causing the tab to be dropped. Open browser devtools console and reload to see the trace.
- **Team presence grid: restored week view** — re-added `weekDays` computed (Mon–Sun for current week), week grid template (members × 7 day columns), stacked 30px/60px blocks per day cell. Navigation moves by week. Previous/next aria-labels updated.
- **My Presence save: single bulk request** — new `PUT /api/v1/presence/template/bulk` endpoint saves all cells in one DB transaction then materialises once. Frontend calls this instead of 14 sequential requests. Calendar sync queued via `PresenceCalendarSyncJob` as before.
- **Override not updating calendar for both halves** — `PresenceSlotService::overrideSlot` now calls `syncAllSlotsForUser` inline after the override, which handles the full-day merge/split logic (e.g. all-day event split into two half-day events when AM ≠ PM). The background job is no longer queued from override — full sync handles it.

### Added
- `PUT /api/v1/presence/template/bulk` route + `PresenceUserController::saveTemplateBulk` + `PresenceTemplateService::setBulk`.

## [3.47.2] — 2026-05-19 — Presence fixes III

### Fixed
- **Duplicate key error on template save** — `saveTemplate` sent all 14 cells concurrently via `Promise.all`, causing multiple simultaneous `rematerialiseForUser` calls that raced on `teamhub_presence_slots`. Changed to sequential `for`/`await` loop; `rematerialiseForUser` runs once per cell, never concurrently.
- **Presence tab not consistently visible** — `loadPresenceConfig` now runs before `loadLayout` (awaited) in the `currentTeamId` watcher. This ensures `presenceConfig.presence_enabled` is correct when `buildAllTabDescriptors` first runs, eliminating the race that caused the tab to appear/disappear on team switches.
- **Override not updating calendar** — `PresenceSlotService::overrideSlot` now calls `syncSlot` directly and inline for the single affected slot, in addition to queuing the full `PresenceCalendarSyncJob`. One slot write is fast; it no longer waits for cron.
- **Team presence grid: week mode removed** — The crowded 14-column week grid is removed. The view is now day-only (same date navigation, one day at a time). Stacked half-day blocks are now 30px/60px (up from 25px/50px).

## [3.47.1] — 2026-05-19 — Hotfix: migration boot crash

### Fixed
- **`occ app:enable teamhub` crash** — `Version000346000::postSchemaChange` called `$this->db->getPrefix()` which does not exist on `IDBConnection`. Fixed to use `$this->config->getSystemValue('dbtableprefix', 'oc_')`, matching the established pattern in `Version000336200` and `Version000300901`. No other changes.

## [3.47.0] — 2026-05-19 — Presence UI overhaul + day_of_week fix

### Fixed
- **Monday morning/afternoon still failing (day_of_week DEFAULT)** — `Version000346000` uses direct `ALTER TABLE ... MODIFY COLUMN` SQL (instead of the unreliable DBAL `setDefault` approach) to reliably add `DEFAULT 0` to `day_of_week` and `half_day` on MySQL/MariaDB. PostgreSQL is skipped (not affected). The migration is new so it runs on every install that hasn't seen it yet, regardless of previous repair attempts.

### Added
- **`PresenceCalendarView.vue`** — new sub-component: two-month calendar (current + next) showing materialised presence slots as coloured AM/PM blocks per day. Holidays shown with diagonal stripe and lock icon. Clickable for today+future non-holiday dates; past dates and holiday slots are display-only.
- **`lib/Migration/Version000346000Date20260519000000.php`** — direct SQL migration to fix MySQL column defaults reliably.

### Changed
- **`MyPresencePanel.vue`** — redesigned with draft/save model and calendar override section:
  - Week template grid now uses draft state (`draft` object). Cells update locally; a **Save** button commits all 14 cells at once via parallel PUT requests. Dirty cells are highlighted with an amber outline. Discard button resets to server state.
  - Below the grid: 2-month `PresenceCalendarView` showing materialised slots. Clicking a future date cell opens the picker to set a per-date override (source='override'), saved immediately. Holidays remain locked.
- **`PresenceCell.vue`** — added `dirty` prop; when true, shows amber outline indicating an unsaved draft change.
- **`TeamPresenceView.vue`** — day mode redesigned:
  - Two half-day blocks stacked vertically (25px each) per member row instead of two side-by-side columns.
  - When Morning and Afternoon share the same presence type, they merge into a single 50px block.
  - Added `sameTypeDay`, `dayBlockStyle`, `slotTitle`, `dayBlocksAriaLabel` computed/methods.

## [3.46.0] — 2026-05-19 — Presence bugfixes II

### Fixed
- **Monday morning/afternoon fails to save** — `day_of_week` column on `teamhub_presence_template` lacked `DEFAULT 0`, causing MySQL 1364 when day=0 (Monday). Fixed in base migration and added to `Version000344001` repair migration (also runs on existing installs via `occ upgrade`).
- **Presence tab not appearing after enabling** — race condition in `loadPresenceConfig`: the `if (this.layoutLoaded)` guard blocked the tab rebuild when presence config arrived before layout finished loading. Also: presence config is now stored in Vuex (`presenceConfig` state + `SET_PRESENCE_CONFIG` mutation) so toggling the switch in Manage Team → Settings immediately shows/hides the tab in the tab bar without a reload.
- **Calendar sync blocking HTTP response** — `syncSlot` and `syncAllSlotsForUser` are no longer called inline during slot saves. Instead a `PresenceCalendarSyncJob` (one-shot `QueuedJob`) is queued via `IJobList`. The slot write returns immediately; calendar propagation happens on the next cron run (typically within a minute).
- **AM + PM same status creates two calendar events** — `syncAllSlotsForUser` now detects when both halves of a date share the same `presence_type_id` and writes a single all-day `VEVENT` (`DTSTART;VALUE=DATE`) instead of two timed half-day events. Existing half-day events for that date are deleted. If they differ, two half-day events are written as before.
- **"Hide status details" appears as a peer item instead of a sub-item** — the toggle is now visually nested under "Presence tab" with left-indent, smaller icon, and a bordered background to communicate the parent–child relationship.

### Added
- **`PresenceCalendarSyncJob`** — new `QueuedJob` that calls `PresenceCalendarService::syncAllSlotsForUser` for a given user. Queued by `PresenceSlotService` and `PresenceMaterialisationService` instead of synchronous inline calls.
- **`presenceConfig` Vuex state + `SET_PRESENCE_CONFIG` mutation** — presence config is now shared state; both `TeamView` and `ManageTeamView` read and write through the store.

### Changed
- `lib/Migration/Version000342000Date20260518000000.php` — added `'default' => 0` to `day_of_week` column (fresh installs).
- `lib/Migration/Version000344001Date20260519000000.php` — extended to also add `DEFAULT 0` to `day_of_week` (existing installs).

## [3.45.0] — 2026-05-19 — Session B4 (Calendar propagation)

### Added
- **`PresenceCalendarService`** — one-way presence → calendar propagation. Writes, updates, and deletes VEVENTs in the user's default calendar when slots change.
  - Stable UID scheme: `teamhub-presence-{userId}-{date}-{AM|PM}@teamhub-presence`. Derived from slot data — no extra storage needed. Also persisted in `slot.calendar_event_uid` for re-pointing on holiday overwrite.
  - Custom property `X-TEAMHUB-PRESENCE:1` on every written VEVENT — secondary marker so we never touch events we didn't create.
  - `TRANSP:OPAQUE` (busy) when `is_busy=1`, `TRANSP:TRANSPARENT` (free) when 0. Drives NC 28+ calendar-status integration.
  - `STATUS:CONFIRMED` when busy, `TENTATIVE` when free.
  - Floating-time DTSTART/DTEND (no TZID, no Z) — Morning 00:00–12:00, Afternoon 12:00–00:00 next day. Sits at the correct local slot regardless of user timezone.
  - Calendar lookup: user's oldest calendar by id, excluding `contact_birthdays` and soft-deleted calendars.
  - Three public methods: `syncSlot(PresenceSlot)`, `deleteSlotEvent(PresenceSlot)`, `syncAllSlotsForUser(string)`.
  - All CalDAV failures are non-fatal (logged as warnings). Slot writes always succeed regardless of calendar availability.
  - Uses `ContainerInterface` injection for `CalDavBackend` — consistent with `CalendarService` and `MeetingService`. No `\OC::$server`.

- **`PresenceSlotService`** — wired `syncSlot()` after every slot write (`overrideSlot`). No API changes.
- **`PresenceMaterialisationService`** — wired `syncSlot()` per new slot in the nightly cron inner loop; `syncAllSlotsForUser()` after `rematerialiseForUser()` (on-save, one bulk pass per user).
- **`PresenceTemplateService`** — wired `syncSlot()` on holiday-slot revert; `deleteSlotEvent()` before holiday-slot deletion in `recomputeSlotsForDate()`.

### Changed
- `appinfo/info.xml` — version 3.44.2 → 3.45.0.

## [3.44.2] — 2026-05-19 — Bugfix: built-in type flags + half_day SQL error

### Fixed
- **`office` presence type was incorrectly marked `is_busy=1`** (busy). Office means you are reachable — corrected to `is_busy=0` (free). Migration `Version000344001` updates existing rows.
- **`non_working_day` presence type was incorrectly marked `is_busy=0`** (free). A non-working day means you are unavailable — corrected to `is_busy=1` (busy). Migration `Version000344001` updates existing rows.
- **MySQL error 1364 "Field 'half_day' doesn't have a default value"** when saving a Morning (half_day=0) template cell. The `half_day` column on `teamhub_presence_template` and `teamhub_presence_slots` lacked a `DEFAULT 0`, causing MySQL to reject INSERT statements where QBMapper omitted the column (which it does when the PHP-level property matches the entity zero-default). Fixed by adding `DEFAULT 0` to both columns in both the base migration (for fresh installs) and a new repair migration `Version000344001` (for existing installs via `ALTER COLUMN`).

### Changed
- `lib/Migration/RepairSteps/SeedPresenceTypes.php` — corrected `is_busy` values in the BUILTINS array.
- `lib/Migration/Version000342000Date20260518000000.php` — added `'default' => 0` to both `half_day` column definitions.

## [3.44.0] — 2026-05-19 — Session B3 (Presence team view)

### Added
- **Schema migration `Version000344000`** — adds `presence_enabled SMALLINT DEFAULT 0` column to `teamhub_presence_team`. Idempotent (hasColumn guard).
- **`PresenceTeamConfig` entity + `PresenceTeamConfigMapper`** — per-team presence config row (presence_enabled, hide_reasons). Absence of a row = all defaults.
- **`PresenceTeamService`** — team grid payload (`getTeamGrid`) and per-team config read/write (`getConfig`, `saveConfig`). Privacy filter applied server-side: when `hide_reasons=1`, slot cells are replaced with a 3-tone palette (busy `#EF5350` / free `#66BB6A` / off `#BDBDBD`); status type, icon, and location are withheld.
- **`PresenceTeamController`** — 3 team-member-gated endpoints: `GET /teams/{teamId}/presence`, `GET /teams/{teamId}/presence/config`, `PUT /teams/{teamId}/presence/config` (admin-only write).
- **`TeamPresenceView.vue`** — team presence grid. Week mode (all members × 7 days × AM/PM, 14 columns, horizontally scrollable) and Day mode (all members × Morning + Afternoon for a single date). Navigation by week/day. Anchored to today on open. Lazy-loads via `v-if="currentView === 'presence'"`.
- **`PresenceGridCell.vue`** — read-only display cell for the team grid. Colour-blocked with luminance-based text colour for WCAG contrast. Diagonal stripe overlay for holiday-locked slots.
- **Presence tab in `TeamTabBar.vue`** — `OfficeBuildingIcon`, appears only when `presence_enabled=true` for the team. Draggable/reorderable.
- **Presence wiring in `TeamView.vue`** — imports + registers `TeamPresenceView`, adds `presenceConfig` data field, loads config on team switch, adds `presence` to `buildAllTabDescriptors()` (gated on `presence_enabled`), adds to `syncExtTabs` builtinKeys set.
- **Presence config toggles in `ManageTeamView.vue`** — "Presence tab" (enable/disable) and "Hide status details" (hide_reasons) switches in Manage Team → Settings, visible to team admins only. Loaded on mount and on settings tab open.

### Changed
- `appinfo/info.xml` — version 3.43.0 → 3.44.0.
- `appinfo/routes.php` — 3 new presence team routes added.

## [3.43.0] — 2026-05-19 — Session B2 (Presence user-side foundation)

### Added
- **`PresenceTemplate` entity + `PresenceTemplateMapper`** — full CRUD for `teamhub_presence_template`. Includes `findByUser`, `findCell`, `deleteByUser`.
- **`PresenceSlot` entity + `PresenceSlotMapper`** — full CRUD for `teamhub_presence_slots`. Includes range queries, `findHolidaySlotsOnDate`, `findExistingDatesForUser`, `deleteTemplateSlotsByUserFromDate`.
- **`PresenceTemplateService`** (real implementation, replacing B1 stub) — 14-cell week template CRUD. `setCell` upserts one cell and triggers immediate re-materialisation. `recomputeSlotsForDate` is the real holiday-revert implementation: for each `source='holiday'` slot on the given date, reverts to template values if a template row exists, otherwise deletes the slot.
- **`PresenceMaterialisationService`** — rolling slot materialisation. `materialiseAll()` (cron) ensures every user with template rows has slots through end of next year. `rematerialiseForUser()` (on-save) wipes `source='template'` slots from today onward and rebuilds, preserving `source='override'` and `source='holiday'` slots.
- **`PresenceSlotService`** — user slot reads (enriched with type metadata) and single-slot overrides. Enforces holiday lock: slots with `source='holiday'` reject edits with 409.
- **`PresenceMaterialisationJob`** — nightly `TimedJob` calling `materialiseAll()`. Registered in `info.xml`.
- **`PresenceUserController`** — 4 authenticated user endpoints: `GET /presence/template`, `PUT /presence/template/cell`, `GET /presence/slots`, `PUT /presence/slots/override`.
- **`PersonalSection` + `PersonalSettings`** — NC personal settings page (Settings → Personal → TeamHub). Registered in `info.xml`.
- **`templates/personal.php`** — renders `#teamhub-personal-settings` div, loads `personal.js`.
- **`src/personal.js`** — new webpack entry point mounting `MyPresencePanel.vue`.
- **`MyPresencePanel.vue`** — user-facing 7×2 half-day template grid. Loads template, type catalogue, and location tree on mount. Clicking a cell opens a type picker. Selecting a type triggers optimistic save with revert on failure. Supports location picker for types with `requires_location=true`.
- **`PresenceCell.vue`** — stateless single-cell component. Displays type colour + label, empty placeholder, or saving spinner. Luminance-based text-colour calculation for WCAG contrast on coloured backgrounds.
- **`webpack.config.js`** — added `personal` entry point.

### Changed
- `PresenceTemplateService` — was a no-op stub in 3.42.0. Now fully implemented.
- `appinfo/info.xml` — registered `PresenceMaterialisationJob`, `PersonalSettings`, `PersonalSection`.

## [3.42.0] — 2026-05-19 — Session B1 / L (Presence module foundation)

This release lays the admin-only foundation for the Presence module: schema, catalogues, and admin sub-panels. There is no user-facing presence UI yet — that lands in Session B2 (v3.43.0). Fresh installs and upgrades both get the new schema and the five built-in status types seeded on first run.

### Added
- **Presence module schema (8 tables).** `teamhub_presence_types`, `teamhub_buildings`, `teamhub_floors`, `teamhub_rooms`, `teamhub_holidays`, `teamhub_presence_template`, `teamhub_presence_slots`, `teamhub_presence_team`. All cross-DB safe (SMALLINT for booleans, VARCHAR(10) ISO strings for dates, BIGINT unix for timestamps). Every PK has an explicit ≤30-char name per the v3.36.2 PostgreSQL constraint-name lesson. Table-name non-prefix portions all ≤27 chars per the DBAL limit.
- **Idempotent built-in seed.** New `SeedPresenceTypes` repair step (`<post-migration>` in `info.xml`) seeds five canonical built-in presence types on every NC update: Office, Home, Vacation, Holiday, Non-working day. Slug-keyed upsert preserves admin label/icon/color customisations across upgrades.
- **`PresenceAdminController`** — 18 new admin endpoints under `/api/v1/admin/presence/*`, all gated by `#[AuthorizedAdminSetting]`. Status types, buildings, floors, rooms, holidays — full CRUD where appropriate. Two-step holiday-add flow (preview → confirm) with destructive-overwrite count surfaced in the response payload.
- **Admin sub-panel: Status types** (`PresenceTypesManager.vue`) — sortable catalogue with built-in lock badges, keyboard-accessible reorder via chevron buttons, swatch preview, create/edit dialog with structural flags locked for built-ins, delete-with-confirm dialog. Server-side 409 surfaces `affectedCount`.
- **Admin sub-panel: Locations** (`PresenceLocationsManager.vue`) — building → floor → room tree, expanded by default, inline per-level add/edit/delete buttons, subtree-count warning on delete, 409 affectedCount surfaced via plural `n()`.
- **Admin sub-panel: Holidays** (`PresenceHolidaysManager.vue`) — year-selector list (current-2 to current+5), two-step add flow showing "N entries will be overwritten" before commit, delete dialog mentions revert-to-template semantics.
- **'Presence module' tab** added to `AdminSettings.vue` between Maintenance and Audit, with the `OfficeBuildingIcon`.
- **`PresenceConflictException`** — dedicated 409 exception carrying an optional `affectedCount` so admin UI can render "in use by N entries" rather than a bare conflict message.

### Changed
- `appinfo/info.xml` — added `<repair-steps><post-migration>` block registering `SeedPresenceTypes`.

### Notes for B2 (v3.43.0)
The `teamhub_presence_template`, `teamhub_presence_slots`, and `teamhub_presence_team` tables are created with their final schema but remain empty in B1. `PresenceTemplateService::recomputeSlotsForDate()` is a stub (logs only); the real implementation lands in B2 alongside the user-side week-template editor and the slot-materialisation cron. The narrow `PresenceSlotQueryMapper` and `PresenceTemplateQueryMapper` helpers exist so the holiday-delete reference-integrity gates work unmodified once B2 starts populating those tables.

## [3.41.0] — 2026-05-18 — Session K

### Added
- **Unassigned card nudge in Upcoming Tasks widget.** Each connected Deck board now shows an amber row at the top of the widget listing the count of cards that have no assignee and are not yet overdue (no due date counts as not overdue). Clicking the row opens that board in the embedded Deck iframe — same behaviour as clicking the Deck tab. With multiple boards each board gets its own row, sorted by count descending.
- **Team-as-member support.** One team can now be added as a sub-member of another team. A per-team toggle ("Prevent this team from being a member of another team") appears in Manage Team → Settings, using the same CFG_ROOT (8192) bit that Nextcloud Contacts uses — the two UIs are now in sync. Admins must enable the "Teams" invite type in Admin Settings → Invite Types before the invite picker shows teams. Teams with the prevention toggle checked are excluded from invite search results. The admin integrity check flags only the contradictory state (team is nested but also has prevention active).
- **Deck board activity in team activity widget.** Deck board and card events now appear in the team activity feed. Includes board name and card title extracted from Nextcloud's own `subjectparams` JSON, producing descriptions like "Justin created card 'Fix login bug' — Sprint board".

### Fixed
- **Critical: Deck activity was completely missing.** `ActivityService` was checking `$resources['deck']['board_id']` but `deck` became an array of board objects in 3.28.0, so this check silently failed for all installs. No Deck activity appeared in the widget regardless of how many boards were connected.
- **Critical: `updateTeamConfig()` used wrong bit mask.** `TeamService::updateTeamConfig()` still had `$MANAGED_BITS = 1|2|4|16|512` (pre-3.39.1 wrong values) as a local variable, overriding the canonical constant. Every toggle other than "Anyone can join" (CFG_OPEN=16 was in both old and new masks by coincidence) was silently discarded on save. Now uses `CirclesConfig::MANAGED_BITS`.
- **Critical: ManageTeamView config constants were the old pre-3.39.1 wrong values.** Identical to the 3.39.1 bit-encoding bug: `ManageTeamView.vue` had its own local `const CFG_OPEN = 1` block instead of importing from `circlesConfig.js`. Enabling "Anyone can join" was writing CFG_SINGLE (1) again, hiding the team from Contacts. Fixed by replacing the local constants with a proper import.
- **`searchUsers()` circle search used `iLike()` which does not exist on NC's QueryBuilder.** The exception was caught silently, returning zero results for the circle type every time.
- **Deck board picker always opened the first board.** Moving `selectedDeckBoard` to Vuex introduced a Vue 2 data/computed shadowing bug — the local `data()` property with value `null` won the name collision against the `mapState` computed getter, so `deckUrl` always fell back to `resources.deck[0]`. Fixed by removing the dead `data` declaration.
- **Integrity check falsely flagged all team-as-member relationships.** The check now only flags nesting as an issue when the sub-team has CFG_ROOT set (prevention active) but is nested anyway — a genuinely contradictory state. Valid nesting (CFG_ROOT not set) is silently skipped.
- **PHP parse error in MemberService (3.40.1–3.40.3).** The `str_replace` tool double-escaped backslashes when embedding PHP namespace separators, producing `\\OCP\\DB\\...` instead of `\OCP\DB\...`. Caused 500 on every MemberService-dependent endpoint.

### Changed
- **"Prevent teams from being a member of another team" toggle** now uses CFG_ROOT (8192) — the same bit Nextcloud Contacts uses — instead of CFG_CIRCLE_INVITE (16384). The two UIs now write the same bit with the same meaning and stay in sync.
- **`fetchDeckTasks` card-ID lookup** changed from one OR clause per card to a single `IN (board_ids)` query + one `IN (card_ids)` clause in the main activity query. Scales better with many boards and large boards.
- **Activity `formatSubject` for Deck events** now includes board name and card title when available (e.g. "Justin created card 'Fix login bug' — Sprint board").

## [3.40.0] — 2026-05-18 — Session J close

### Fixed

#### Circles config bit encoding (was 3.39.1)
- **Critical: Circles config bit encoding.** Every TeamHub release prior to 3.39.1 wrote the wrong Circles config bits when users toggled checkboxes in the Manage Team settings panel. Each TeamHub label was mapped to a bit that meant something completely different in Circles' real encoding. Consequences observed in production:
  - "Anyone can join" wrote bit 1 (real `CFG_SINGLE`) → Circles tagged the team as a personal identity circle → Contacts hid it.
  - "Visible to everyone" wrote bit 512 (real `CFG_NO_OWNER`) → Contacts refused config edits.
  - "Enforce password protection" wrote bit 16 (real `CFG_OPEN`) → team became open-join.
  - The always-on "Prevent sub-team membership" hint wrote bit 1024 (real `CFG_HIDDEN`) → team disappeared from listings.
  - Settings made via Contacts and TeamHub no longer round-tripped — each side read a different field of meaning from the same column.

  This release corrects the bit encoding in both PHP and Vue, introduces canonical constants in `lib/Constants/CirclesConfig.php` (mirrored in `src/constants/circlesConfig.js`) so the drift cannot recur, and ships a one-shot migration (`Version000339001`) that decodes admin intent from the old (wrong) encoding and re-encodes with correct Circles bit values. Admin sees the same checkbox states before and after — only the underlying storage changes.

- **`resolveUserSingleId()` DB-join fallback** was checking `config & 2048` (which is `CFG_BACKEND`) thinking it was `CFG_SINGLE`. Now correctly uses `config & 1`.
- **`browseAllTeams()` CFG_VISIBLE filter** was filtering on bit 512 (`CFG_NO_OWNER`) instead of bit 8 (real `CFG_VISIBLE`).
- **`isOpen` checks in `browseAllTeams()` and `MemberService::requestJoinTeam()`** read bit 1 (`CFG_SINGLE`) instead of bit 16 (real `CFG_OPEN`).
- **Manage team → Settings tab now always reloads from the database** when activated. Previously the checkboxes showed cached state and external changes (e.g. via Contacts) were not reflected until page refresh.

#### Unread message counter (was 3.39.2)
- **Unread message counter restored in sidebar.** The `NcCounterBubble` badge next to team names was effectively dead: no polling caused `team.unread` to go stale immediately after page load, the counter was hardcoded to display `"1"` regardless of count, and `team.unread` was a boolean not a count. Fixed: backend returns a real per-team count, the badge displays the actual number, a 60-second background poll keeps badges current, and posting a message triggers an immediate refresh. Excludes own messages from the count.

#### Group invitations (was 3.39.6 → 3.39.14)
- **Inviting a group to a team now works correctly.** Circles' `addMember()` was creating an `Invited` row with `level=0` for non-user types (groups, circles), and Circles has no working notification path for group invitations — so groups stayed in permanent limbo and TeamHub's filters silently hid them. Fixed by auto-confirming group/circle membership immediately after `addMember()` succeeds (UPDATE to `status='Member', level=1`) and triggering a Circles membership cache rebuild so users in the group get immediate access to team resources.
- **@mention now works for indirect members (users added via a group).** Multiple cascading bugs fixed:
  - `getAllEffectiveMembers` now correctly reads from `circles_membership` (Circles' denormalized cache) which contains every reachable user including those via groups, instead of attempting unreliable `IGroupManager` lookups by GID labels.
  - Frontend store correctly unwraps the `{members: [...]}` response shape (was treating it as a bare array and discarding the data).
  - Mention autocomplete supplements OCS results with team members that NC's user-enumeration privacy settings would normally hide.
  - Manage team → invite flow refreshes `allEffectiveMembers` in the store after adding a group so mentions work immediately.

### Added
- **`lib/Constants/CirclesConfig.php`** — single source of truth for Circles bit values, plus `MANAGED_BITS`, `SYSTEM_BITS_FORBIDDEN_ON_USER_TEAMS`, and the `migrateLegacyConfig()` decoder.
- **`src/constants/circlesConfig.js`** — JS mirror of the same constants, imported by `ManageTeamView.vue`, `CreateTeamView.vue`, and `TeamWidgetGrid.vue`.
- **Reset config action** (icon button) in admin settings → maintenance → per-team row. Clears all user-managed and forbidden-system bits to clean defaults. Confirmation dialog before applying.
- **Config bitmask integrity check** in admin settings → maintenance. Scans every user-created team for forbidden system bits (`CFG_SINGLE`, `CFG_SYSTEM`, `CFG_NO_OWNER`, `CFG_HIDDEN`, `CFG_BACKEND`, `CFG_APP`). Per-team Repair button calls `resetTeamConfig()`.
- **Three new API endpoints:**
  - `POST /api/v1/admin/maintenance/reset-team-config/{teamId}` — clears user-managed and forbidden-system bits, returns `{ oldConfig, newConfig }`. Logs to `teamhub_audit_log`.
  - `GET /api/v1/admin/maintenance/config-check` — returns array of teams with corrupted bits.
- **New Vuex state `allEffectiveMembers`** + `fetchAllEffectiveMembers` action + `UPDATE_UNREAD_COUNTS` mutation + `refreshUnreadCounts` action.

### Changed
- **`repairMembershipCache()`** now strips every bit in `SYSTEM_BITS_FORBIDDEN_ON_USER_TEAMS` before rebuilding the cache.
- **`updateTeamConfig()`** `MANAGED_BITS` mask updated to the correct Circles bit values (`8 | 16 | 32 | 64 | 256` = 376).
- **`TeamWidgetGrid.vue::teamLabels`** — labels now read from real Circles bits. The misleading "No nested teams" label removed.
- **`CreateTeamView.vue`** — the "Prevent sub-team membership" checkbox removed entirely. It controlled nothing real and wrote `CFG_HIDDEN`.
- **Audit log event types:** new `team.config_reset`, `team.config_migrated_3_39_1`, `team.config_migrated_3_40_0`.

### Migration
- **`Version000339001Date20260518000000`** — one-shot data migration. For every `source=16` team where any legacy-damage bit (1, 4, 512, 1024) is set, decodes admin intent from the old encoding and re-encodes with real Circles bits. Skips teams that have no legacy-damage bits. Logs every change to nextcloud.log and writes an audit log entry per team. Bursts Circles' APCu cache when done.

## [3.39.0] — 2026-05-15 — Session I

### Added
- **Integrity check: nested team detection.** Flags any `circles_member` row where a user-created team (source=16) is a member of another. Repair removes the offending row.
- **Integrity check: CFG_SINGLE corruption detection.** Flags source=16 circles with bit 1024 set (causes Circles to hide the team from its own API). Excludes legitimate personal/system circles. Repair: clears the bit.
- **Integrity check: duplicate member detection.** Flags circles where the same user_id appears more than once as a direct member. Repair: keeps highest-level row, deletes rest.
- **Integrity check: no-owner detection.** Flags source=16 teams with no level=9 member row. Repair: promotes highest-level existing member, or inserts calling NC admin if team is empty.
- **Integrity check: wrong display_name detection.** Flags circles where display_name ≠ sanitized_name — this causes Circles to misclassify user-created teams as personal circles. Repair: sets display_name = sanitized_name.
- **Link permissions.** New `linkMinLevel` setting per team (member/moderator/admin, default admin). The `+` button in the tab bar is hidden for users below the required level. Configurable in Manage team → Permissions.
- **`getTeamMemberUids()` in MessageService.** Direct DB member lookup for notifications — replaces Circles API `getCircle()` call in the message write path. Eliminates "Circle not found" 500 errors when posting to teams with config issues.

### Changed
- **Manage team tab renamed: Messages → Permissions.** Pin level, post level, and new link level settings consolidated here.
- **`updateTeamConfig()` no longer calls `getCircle()` for cache flush.** The Circles API was triggering internal sync that re-applied CFG_SINGLE (1024) after every config write. Only APCu cache is flushed now.
- **CFG_SINGLE (1024) removed from `MANAGED_BITS`.** This bit marks personal circles and must not be written to user-created teams. Frontend no longer sends it; backend no longer includes it in the write mask.
- **`repairMembershipCache()` auto-clears CFG_SINGLE** before rebuilding the membership cache.
- **`searchUsers()` no longer returns teams/circles** in invite search results. Inviting a team into another team corrupts Circles' visibility queries.
- **Orphaned teams query** no longer requires `app:circles:` name prefix — compatible with NC33 which stores plain team names.
- **`getAllTeams()` deduplicates** by `unique_id` — prevents duplicate rows when a circle has multiple level=9 member rows.
- **Ghost cleanup** moved from its own tab into the Maintenance tab.

### Fixed
- **PostgreSQL: backtick quoting** in `resolveUserSingleId()` (`c.\`config\`` → `c.config`) caused syntax errors on PostgreSQL, breaking indirect member detection.
- **SQL HAVING clause** for duplicate member detection used aliased `COUNT()` which MySQL rejects. Replaced with `createFunction('COUNT(cm.id)')`.
- **`InviteMemberModal`** no longer shows teams in search results (`AccountMultiple` icon removed, circle type branch removed).
- **`ArchiveTeamModal`** displays resolved folder name instead of raw `/f/{id}` link.
- **Announcement banner** (mohamedsakhri/nextcloud-announcementbanner) suppressed in iframes via `.announcementbanner-stack` CSS selector.
- **share_folder config.php** respected when creating team folders (AIO and similar installations).


### Added
- **Ghost member cleanup.** New "Ghost cleanup" tab in Admin settings. Scans all team memberships for users whose NC account has been deleted, grouped by uid. Admin can remove a ghost from a single team or from all teams at once. Includes a live-account safety guard. Endpoint: `GET /api/v1/admin/maintenance/ghost-members`, `DELETE /api/v1/admin/maintenance/ghost-members/{userId}`.
- **share_folder config.php support.** When an NC instance sets `'share_folder' => '/Shared'` (or any path) in config.php (common in AIO installations), TeamHub creates the shared team folder inside that path. Falls back to the user root when the path is absent, missing, or not a folder.
- **Invite button in Manage team → Members tab.** Team admins and owners now have an "Invite members" button directly on the Members tab, opening the existing InviteMemberModal. Member list refreshes after invite completes.
- **Archive location name resolution.** The archive/delete confirmation modal now shows the human-readable folder name (e.g. "TeamHub Archives") instead of the raw `/f/150770` file ID link. Resolved server-side in `ArchiveService::getAdminSettings()`.
- **Announcement banner suppression in iframe.** The CSS injected into embedded iframes now hides banners rendered by the `announcementbanner` app (mohamedsakhri/nextcloud-announcementbanner). The banner remains visible on the parent TeamHub page.

## [3.37.0] — 2026-05-14 — Session G

### Added
- **Message @mentions.** `PostMessageForm` and `MessageCard` edit mode use `NcRichContenteditable` with the NC core OCS autocomplete API (`/ocs/v2.php/core/autocomplete/get`), scoped to team members. Mentions render as styled highlight pills in the message body. Backend sends a `message_mention` NC notification to each mentioned team member (on create and edit).
- **Message pagination.** 5 messages per page with prev/next controls in the message stream. Page resets to 1 on team switch and after posting. `MessageMapper::countByTeamId()` added. `listMessages` now returns `total`, `page`, and `limit` alongside messages.
- **Per-team message settings.** New Messages tab in Manage team for team admins. Configures minimum role to pin messages and minimum role to post messages, stored as per-team `IConfig` keys. Post Message button hidden (not just disabled) when the user lacks the post role.
- **Calendar view dropdown.** Embed bar now has a native select for Month / Week / Day / List variants; selecting reloads the iframe with the chosen view in the URL.
- **Calendar embed auto-reload.** After adding or deleting events, the calendar iframe reloads automatically so changes appear immediately.
- **NC-relative team links.** Custom team links now accept `apps/...` or `/apps/...` paths (e.g. `apps/collectives/s5`) and open in an iframe tab, just like built-in app tabs. External `https://` links continue to open in a new browser tab.
- **VitePress documentation site** in `docs/`. Covers Nextcloud admins, Team management, Developers, and Users — 20 pages total.
- **New migration `Version000336200`** — remediates auto-generated primary key name on `oc_teamhub_team_app_resources` for existing PostgreSQL installs.
- **`message_mention` notifier subject** in `Notifier.php`.
- **`getMessageSettings` / `saveMessageSettings`** endpoints (`GET/POST /api/v1/teams/{teamId}/messages/settings`).
- **`getCalendarEventsForWeek`** endpoint (`GET /api/v1/teams/{teamId}/calendar/events/week`).
- **`deleteCalendarEvents`** endpoint (`DELETE /api/v1/teams/{teamId}/calendar/events`).

### Changed
- **Calendar iframe URL** now uses the public share token path `/apps/calendar/p/{token}/{view}/now` (team-calendar-only, no personal calendars). Falls back to full app when no token available.
- **Calendar connect error handling** in `ManageTeamView` no longer logs the full HTML 500 response body to the console.
- **`getPinMinLevel`** reads per-team `IConfig` key first, falls back to global key. Admin settings Messages tab removed (settings are now entirely per-team).
- **`activeFilesIsGf` / `activeFilesIsShared`** in `ManageTeamView` use `.some()` across all active files rows so the GF connect buttons correctly hide when a GF is active even if a shared folder row appears first.
- **`dav_shares` access filter** in `getRealCalendarIds` broadened from `IN (1,2)` to `IN (1,2,3)` for compatibility with NC Calendar 5.x circle shares.
- **`resumeCalendarAccess`** corrected from `access=1` (read-only) to `access=2` (read-write).
- **Select dropdowns** in `AppEmbed` bar and Manage team Messages tab have no background color (theme-transparent).

### Fixed
- **MariaDB migration failure** on NC 32.0.9: `Version000328200` now uses explicit `'th_tar_pk'` for `setPrimaryKey()` — auto-generated name was 31 chars, one over the 30-char DBAL limit.
- **Calendar `connectExistingCalendar` TypeError** — `ResourceService` was passing `$resourceId` as `string` to a method expecting `int`; cast to `(int)` at the call site.
- **GF connect buttons visible when GF already active** — `activeFilesIsGf` now uses `.some()` instead of `.find()` so ordering of rows doesn't affect the result.



### Added
- **Strict 1:1 enforcement for files resources.** `ResourceDiscoveryService::reconcileApp` now snapshots the team's active files state and routes newly discovered rows accordingly: active shared + incoming GF → pending (with `isDualFolderPending` flag); active GF + anything → ignored (GF precedence); active shared + another shared → ignored. `acceptResource` and `unignoreResource` apply the same guard. All refusals write `resource.suppressed_duplicate` audit entries with reason codes.
- **Group folder takes precedence in `getTeamResources`.** When both shared and GF rows are active (dual state during manual migration), the loop explicitly picks the `gf:` row so widgets and the team home always read from the group folder.
- **Dual-folder informational notice** in Manage Team → Settings → Team Apps. When a GF is discovered alongside an active shared folder, a blue panel explains the situation and directs the admin to connect the group folder via the existing buttons and migrate files manually.
- **Resource-type badge** ("Group folder" / "Shared folder") on each active files row in the settings panel.
- **Picker filtering by active files type.** `GET /api/v1/pickers/files` now accepts `activeFilesType=shared|gf|none`. Shared folders are suppressed when one is already active; both types hidden when a GF is active.
- **`isDualFolderPending` flag** on panel data rows.
- **`normalPendingResources`, `dualFolderPendingRow`, `dualFolderSharedRow`, `activeFilesRow`, `activeFilesIsShared`, `activeFilesIsGf`** computed properties in `ManageTeamView.vue`.
- **Create New button for Talk** — shown in empty state alongside Connect existing.
- **Create New group folder button for Files** — shown only when Group Folders is installed; switches label to "+ Create new group folder" when a shared folder is active (signals the workflow).
- **Both 1:1 buttons hidden** once a resource is connected, except when a shared folder is active and Group Folders is available — then the GF buttons remain so admin can attach a GF for manual migration.

### Changed
- `ResourceService::upsertResourceRow()` — now promotes `pending`/`ignored` rows to `active` on explicit connect instead of skipping. Fixes silent failure when the discovery reconciler had already inserted the resource as pending.
- `ResourceService::getTeamResources()` — files block prefers `gf:` row when multiple active rows exist.
- `ResourceDiscoveryService::getSettingsPanelData()` — adds dual-folder detection and tagging.
- `ResourceDiscoveryService::resolveFileName()` — falls back to `basename(path)` when `filecache.name` is empty (some storage backends).
- `FilesService::listConnectableFileFolders()` — accepts `activeFilesType` parameter, filters output accordingly. Also falls back to `basename(path)` for shared folder names.
- `ResourceStateController::getPanelData()` — now triggers `reconcileTeam` before returning panel data, so externally added GF resources appear immediately.
- `ManageTeamView::connectExisting()` — empty catch block replaced with `showError()`.

### Fixed
- **Critical pre-existing bug** in `ArchiveService.php`: stray extra `}` (line ~2770) caused `ParseError: unexpected token "try"` on every admin archive request. Removed. Archive settings save works again.
- **`AdminSettings.vue` archive form**: `archiveBeforeDelete` was missing from the `data()` default and from `loadArchiveSettings` — the toggle had no reactive backing. Both fixed.

### Removed
- The auto-migration system (`FolderMigrationService`, `FolderMigrationController`, `FolderMigrationModal.vue`, two endpoints) was scoped, built, and removed per user direction. NcDialog wiring proved unreliable; manual file migration is the supported path. The dual-folder notice remains as an informational signal only.

### Security
- All new endpoints check team admin level (≥8) before any action.
- No raw SQL anywhere; no `\OC::$server`; constructor DI throughout.

## [3.33.8] — 2026-05-10

### Added
- **Group Folders integration (Session E, v21.0.7).** New `GroupFolderService` wraps `FolderManager` via lazy DI container resolution. When Group Folders is installed, new teams automatically get a server-owned Group Folder instead of a personal shared folder.
- **`GET /api/v1/pickers/files?teamId={id}`** — new endpoint listing connectable file folders: group folders where the team's circle is a member (type `group_folder`, shown first) and shared folders owned by the current user not yet connected to any team (type `shared_folder`). Requires team membership.
- **`gf:` resource ID convention.** Group Folder-backed files resources stored in `teamhub_team_app_resources` with `resource_id = 'gf:{folderId}'` — distinct from legacy share-based integer IDs.
- **`folder_type` field** in `getTeamResources` files response: `'group'` or `'shared'`.
- **`groupfolders` key** in `GET /api/v1/teams/apps/check` response.
- **`groupFoldersDelegation` key** in `GET /api/v1/admin/settings` response.
- **Admin Settings — Group Folders status section.** Read-only ✓/⚠ indicators for app installed and team-creator group configured.
- **`docs/design/team-folder-via-groupfolders.md`** — full design document.
- **Soft-delete suspend/resume for group folders.** `suspendConnectedAppResources` detects `gf:` resources and calls `removeCircleFromFolder`; `restoreConnectedAppResources` calls `assignCircleToFolder`. Folder ID stored in `suspended_resources` JSON.
- **Archive extraction for group folders.** `extractFilesData` routes `gf:` resources to new `extractGroupFolderData` helper that resolves the folder via a team member's file tree.
- **Type badges in Connect picker.** Group folder items show a blue `[Group Folder]` badge; shared folder items show a grey `[Shared]` badge.
- **Pending resources section moved to top of Team-apps panel**, above app rows.

### Changed
- `ResourceService::createTeamResources` — files branch prefers Group Folders when available; falls back to shared folder silently.
- `ResourceService::connectExistingResource` — files case handles `gf:` prefix; already-assigned circle treated as success (not 400).
- `ResourceDiscoveryService::getRealFileIds` — includes `gf:` IDs from `group_folders_groups`.
- `ResourceDiscoveryService::getFilesOwner` — returns null for `gf:` resources (always pending review on discovery).
- `ResourceDiscoveryService::resolveFileName` — handles `gf:` prefix via `group_folders.mount_point`.
- `FilesService::getTeamResources` — resolves filecache ID for group folders so favourites/recent widgets work.
- `FilesService` — added `getGroupFolderFilecacheId`, `listConnectableFileFolders`.
- `ResourcePicker.vue` — all apps now use server-driven list; NC file picker removed.
- `ManageTeamView.vue` — files connect picker uses `/pickers/files` endpoint; type badges on picker items; pending section repositioned.
- `AdminSettings.vue` — team-creator group auto-saves on add/remove (no separate Save click required).
- `TeamService::getAdminSettings` — includes `groupFoldersDelegation`; wrapped in `safeGetDelegationStatus` so failures cannot break the settings load.

### Fixed
- `group_folders` table primary key is `folder_id`, not `id` — fixed all QB queries in `GroupFolderService`.
- `FolderManager::addFolder()` → `createFolder()` — correct method name for GroupFolders v21.
- `getDelegationStatus` — removed bogus `group_folders_applicable` query (table does not exist in v21); simplified to two-field response.
- Security: `GET /api/v1/pickers/files` now validates team membership before exposing group folder names.



### Added
- **Multi-resource support for Calendar and Deck.** Teams can now connect multiple calendars and multiple Deck boards. `getTeamResources()` returns arrays for both apps.
- **Tab bar picker.** When a team has 2+ active calendars or boards, clicking the tab opens a picker. Count badge shown on the tab.
- **Per-app resource list in Manage Team → Settings.** Replaces app toggles for Talk/Files/Calendar/Deck with a live resource list showing connected resources with Disconnect and Delete actions, plus Connect existing and Create new buttons.
- **Create new resource name dialog.** Admin enters a name before creating a new calendar or Deck board.
- **Connect existing uses NC file picker for Files.** Other apps use TeamHub's own picker endpoint.
- **At-risk block at top of Team Apps section.** Resources with `risk_status != none` shown prominently before the per-app lists.
- **Disconnect action** — strips team's circle ACL, resource survives. Replaces "Remove".
- **Delete action with confirmation dialog** — destroys the NC resource permanently (hard delete).
- **Two-pill source labels on widget items.** DeckWidget shows board name (truncated 20 chars, full name on hover) + "Deck". CalendarWidget shows calendar name + "Calendar". Only shown when team has 2+ resources of that type.
- **Multi-resource widget aggregation.** DeckWidget and CalendarWidget aggregate across all connected resources. `fetchDeckTasks` loops all boards. `getTeamCalendarEvents` loops all calendars. `getTeamTasks` loops all connected calendars.
- **`calendarId` and `boardId` in create-event/create-task requests.** When team has 2+ resources, inline picker in modal lets admin choose target. `calendarId`/`boardId` sent in POST body and used by backend.
- **`calendarName` in calendar events response** and **`boardName` in deck task data** for pill display.
- Per-app remove methods: `removeRoomAccess`, `removeCalendarAccess`, `removeFilesAccess`, `removeBoardAccess`.
- Per-resource delete methods: `deleteRoomById`, `deleteCalendarById`, `deleteBoardById`, `deleteFolderById`.
- `deleteResource` endpoint: `DELETE /api/v1/teams/{teamId}/resources/{app}/{resourceId}/delete`.

### Changed
- Calendar and Deck connect guard now blocks only duplicate of the **same** resource, not any resource of that app type (multi-resource fix).
- `TaskService::resolveCalendarId()` updated for array shape. New `resolveAllCalendarIds()` helper.
- `TeamView` passes `calendars` and `boards` arrays as props to `AddEventModal` and `AddTaskModal`.
- `AddTaskModal` — `boardId` prop replaced with `boards` array; stack re-fetch on board switch.
- `AddEventModal` — `calendars` prop added.

### Fixed
- `ArchiveService::readIntegrations()` — `ir.integration_id` corrected to `ir.id AS ir_id` (column does not exist).
- `FilesService::suspendFilesAccess` and `removeFilesAccess` — removed `IManager::getShareById` for circle shares (wrong prefix). Now uses QB delete directly, eliminating spurious "Share not found" warnings on team deletion.
- Multiple PHP brace-balance issues in `ActivityService` and `ResourceService` caused by partial str_replace replacements.
- At-risk resource block text readability — header text now uses `var(--color-main-text)` instead of `var(--color-error)` on dark background.
- Standalone duplicate at-risk section removed from bottom of settings tab.
- Scroll target for warning block link updated to `.manage-section--atrisk-inline`.
- `DeckWidget` calendar check updated for array shape (`resources.calendar.length > 0`).
- `DeckService::deleteBoardByIdInternal` — each QB operation now uses its own instance (fixes MySQL syntax error on board delete).

## [3.31.0] — 2026-05-09

### Added
- **`owner_uid` column** on `teamhub_team_app_resources` (migration `Version000330000Date20260509000000`). Tracks the NC uid of the resource owner for risk monitoring.
- **`UserStatusListener`** — flags all resources owned by a disabled user as `risk_status=owner_disabled`; clears on re-enable. Writes `resource.risk_flagged` / `resource.risk_cleared` audit events.
- **`UserDeletedListener`** — attempts ownership transfer to the most recently active team admin before a user account is deleted. Sets `risk_status=transfer_failed` when no eligible admin exists. Writes `resource.owner_transferred` / `resource.transfer_failed` audit events.
- **"Resources at risk" section** in Manage Team → Settings. Red-bordered, read-only list of active resources with `risk_status != none`. Shows app, resource name, risk reason, and owner uid.
- **Deep-link scroll** — "Open settings →" in the Teaminfo warning block now auto-switches to the Settings tab and smooth-scrolls to the at-risk section with a highlight pulse.
- **`resourceWarningFocus`** state in Vuex store — coordinates the warning block button with ManageTeamView scroll behaviour.

### Changed
- `ResourceDiscoveryService::refreshRiskStatus()` — completed from stub. Always re-resolves live resource owner from NC tables on every reconcile, catching external ownership changes (e.g. Deck board transferred directly in Deck). Backfills or corrects `owner_uid` when it differs from the stored value.
- `ResourceDiscoveryService::insertDiscoveredRow()` — now populates `owner_uid` on insert.
- `TeamAppResourceMapper::insertResource()` — gains `ownerUid` parameter.

## [3.30.0] — 2026-05-08

### Added
- **Resource name resolution in settings panel.** The panel endpoint now returns a `displayName` field for each resource row, resolved from NC native tables: Files (`filecache.name`), Talk (`talk_rooms.name`, falls back to token), Calendar (`calendars.displayname` then `uri`), Deck (`deck_boards.title`). Falls back to raw resource ID on any lookup failure.
- `ManageTeamView` pending and ignored resource rows now show the resolved name with the raw ID as a tooltip.

### Changed
- CSS class `.pending-resource-id` renamed to `.pending-resource-name` in `ManageTeamView.vue`.

## [3.29.0] — 2026-05-08

### Added
- **Resource discovery reconciliation.** `ResourceDiscoveryService` compares live NC ACL/share tables against `teamhub_team_app_resources` on every team page load (render-time) and hourly via cron (`ResourceDiscoveryJob`). Externally added resources are auto-accepted if the owner is a team admin, otherwise inserted as pending.
- **Pending/ignored resource management.** New endpoints `GET /panel`, `POST /accept`, `POST /ignore`, `POST /unignore` under `/api/v1/teams/{teamId}/resources/`. All require team admin level ≥ 8.
- **Teaminfo widget warning block.** Admin-only banner shows combined count of pending + at-risk resources with a link to Manage Team → Settings.
- **Settings panel — pending resources section.** Lists externally discovered resources awaiting review with Accept/Ignore actions. Ignored resources shown in a collapsible section with Un-ignore action.
- **`ResourceDiscoveryJob`** registered as hourly background job in `appinfo/info.xml`.
- **Audit log events** for all resource lifecycle transitions: `resource.discovered`, `resource.auto_accepted`, `resource.external_withdrawn`, `resource.accepted`, `resource.ignored`, `resource.unignored`.
- **`_warnings`** key added to `GET /api/v1/teams/{teamId}/resources` response: `{ pending: int, atRisk: int }`.

### Fixed
- `OCP\IAppManager` corrected to `OCP\App\IAppManager` in `ResourceDiscoveryService` — wrong namespace caused DI resolution failure and broke team listing on page load.

## [3.28.1] — 2026-05-08

### Added
- **Design doc: `docs/design/connected-resources.md`.** Locked design for the upcoming "Connected Resources" overhaul, which moves TeamHub from a one-resource-per-app-per-team model to a hybrid discovery model with explicit team-admin acceptance, multi-resource support for Deck and Calendar, three-action off-semantics (ignore / remove access / delete), and automatic ownership transfer on `BeforeUserDeletedEvent` to preserve team continuity. Implementation split across Sessions A → D. No code changes this session.
- **Project-level design reference: `DESIGN.md`.** First creation. Captures durable architectural choices and principles for future-session reference, seeded with both pre-existing decisions inferred from the codebase and the choices made this session. Per SKILLS.md step 10, this document is appended to whenever a non-trivial design choice is made.
- **Group Folders integration principles** (DESIGN.md §2.18 and §2.19). Locked the principle that TeamHub aligns permissions with other NC apps at configuration time rather than bypassing their security models, and the dual-folder migration semantics for the future Session E that will integrate Group Folders as a preferred team-folder backend. Implementation deferred; pre-Session-E spike documented in HANDOFF.

## [3.28.0] — 2026-05-07

### Added
- **Connect existing app resources to a team.** Team owners can now choose, per app, to connect a Calendar / Files folder / Deck board / Talk room they already own instead of creating a new one. Available in the Create-team wizard step 4 and in Manage Team → Settings → Apps.
- **Resource pickers** (`GET /api/v1/pickers/{calendar|deck|talk}`) listing the current user's owned resources, scoped to the caller's UID.
- **Connect endpoint** `POST /api/v1/teams/{teamId}/resources/{app}/connect` (team-admin required) that inserts the share/ACL row granting the team's circle access to the selected resource.
- **`ResourcePicker.vue`** — unified picker component used by both the wizard and the manage-team dialog. Files mode opens NC's standard `getFilePickerBuilder` dialog; the other three apps render a populated `<select>` populated from the picker endpoint.
- **Connected-resource warning** under "Delete team" in Manage Team → Maintenance, explaining that connected resources are deleted with the team and how to preserve them.
- **Archive-before-delete admin toggle.** New checkbox in Archive Policy controls whether team deletion produces an archive ZIP first or skips archiving entirely. Default OFF for new and existing installs. Same three deletion modes (`hard` / `soft30` / `soft60`) apply to both archive-on and archive-off paths.
- **`POST /api/v1/teams/{teamId}/soft-delete`** endpoint for soft-delete without archive — creates a pending-deletion row and suspends connected app resources but skips archive production.
- **Owner-side delete dialog** when archive-before-delete is OFF, with description and confirmation text adapted to the chosen deletion mode (immediate hard delete vs 30/60 day grace period without archive).

### Changed
- The Delete-team button in Manage Team → Maintenance now branches based on admin policy: archive ON opens the existing archive modal; archive OFF opens a plain `NcDialog` confirmation.
- Description text for the Delete-team row dynamically reflects the active archive policy and deletion mode.

### Fixed
- **PostgreSQL `SQLSTATE[22P02]: invalid input syntax for type smallint: "f"` on team creation.** The `enabled` (in `teamhub_team_apps`) and `is_builtin` (in `teamhub_integ_registry`) columns are SMALLINT (per the v3.9.0 cross-database fix), but their bind parameters were still using `IQueryBuilder::PARAM_BOOL`. PostgreSQL refuses the boolean-to-smallint coercion at the wire-protocol level; MySQL accepted it silently. Fixed by casting to int and binding as `PARAM_INT` in `TeamAppMapper::upsert()` and `IntegrationRegistryMapper::register()`.

### Security
- Every connect endpoint re-verifies that the user owns the specified resource (`WHERE owner = currentUid` or `IRootFolder::getById()` for Files), preventing forged-`resourceId` attacks across the four supported apps.
- Each app refuses to connect a second resource if one is already linked to the team (one-resource-per-team invariant).
- Picker endpoints scope listing to the caller's UID — never accept a UID from request parameters.

## [3.27.0] — 2026-05-07

### Added
- **Calendar extractor (`apps/calendar/`).** Archive includes `calendar.json` (metadata: name, colour, timezone, event count), `events.json` (structured VEVENT/VTODO/VJOURNAL array with organizer, attendees, recurrence rules), and `events.ics` (merged ICS file openable in any calendar client). Looked up via `dav_shares` → `CalDavBackend::getCalendarObjects()`. Pseudonymizes organizer/attendee mailto: addresses when policy is on.
- **Files extractor (`apps/files/`).** Archive includes a full recursive copy of the team's shared folder. Uses `getLocalFile()` + `copy()` for local storage (no memory overhead); falls back to `getContent()` for external storage with a 100 MB per-file skip guard. Files folder and share left completely intact during grace period — only destroyed at hard-delete. `apps/files/index.json` lists all files with sizes and any skip reasons.
- **Talk extractor (`apps/talk/`).** Archive includes `messages.json` (all chat messages from `oc_comments` where `object_type='chat'` and `object_id={room_id}`) and `transcript.html` (self-contained offline viewer with date separators, system message rendering, and rich-object placeholder highlighting). Fixed: `object_id` stores the integer room ID, not the room token.
- **Deck extractor (`apps/deck/`).** Archive includes `board.json` (full board in Deck's import-compatible format: stacks, cards with labels/assignees/comments nested inline) and `board.html` (self-contained offline kanban view with label colour chips, due date highlighting, assignee badges). Card comments sourced from `oc_comments` where `object_type='deckCard'`.
- **Resource suspension on soft-delete.** When a team is archived in soft-delete mode, the team circle is removed from each connected NC app resource (Talk room attendee, Files circle share, Calendar dav_shares row, Deck ACL row) so members lose access immediately. Content stays intact for restore.
- **Resource resume on admin restore.** `restorePendingDeletion` re-adds the circle to each suspended resource using IDs stored in `suspended_resources` on the pending_dels row. Idempotent — skips re-insert if row already exists.
- **`suspended_resources` column** added to `teamhub_pending_dels` (migration `Version000326000`). JSON blob storing the IDs needed to resume each app resource.
- **Pre-flight size check includes destination free space.** Archive is refused if the estimated size exceeds either the admin cap or 90% of the free space at the archive destination, whichever is more restrictive. Error message specifies which constraint was hit.
- **`oc_filecache` folder size in pre-flight.** Real folder size read from NC's file cache (accurate recursive total) rather than estimated from row counts.
- **Audit team list filters deleted teams.** `GET /admin/audit/teams` no longer returns hard-deleted teams (circle gone) or soft-deleted teams (pending grace period). Both are excluded from the dropdown.
- **`PendingDeletionJob` destroys app resources at grace period expiry.** Calls `ResourceService::deleteTeamResource()` for each enabled app before `deleteTeam()`, ensuring the Files folder and other content is fully removed at the scheduled time.

### Fixed
- **Deck `resumeDeckAccess` permissions.** Re-inserted circle ACL row now uses `dbIntrospection->getTableColumns()` for column detection, matching the creation pattern. Handles `permission_edit`, `permission_share`, `permission_manage` (Deck 1.x) and `permissions` bitmask (Deck 2.x). `enforceAclEditPermissions()` called after insert as a belt-and-braces check.
- **Talk message extraction.** `oc_comments.object_id` stores the integer room ID as a string, not the room token. Query now uses `(string)$roomId` which correctly returns messages.
- **`fclose()` warning on ZIP write.** NC's `putContent()` closes stream handles internally; switched to passing file content as string to avoid double-close.
- **`OC_Util::getVersion()` and `OC::$server` removed.** Replaced with `IConfig::getSystemValue('version')` and injected `IAppManager::getAppVersion()`.

### Security
- Resource suspension removes circle access immediately on archive initiation — members cannot use Talk, Files, Calendar, or Deck during the grace period.
- `suspended_resources` JSON stored on the DB row; access is admin-only via `AuthorizedAdminSetting`.

## [3.25.0] — 2026-05-06

### Added
- **Team archiving — Session A (foundation).** Two-action danger zone: "Archive team" (amber) produces a ZIP archive of all team data before deleting; "Delete team" (red, unchanged) deletes immediately without an archive.
- **`teamhub_pending_dels` table.** Shadow table tracking teams in each phase of archiving. Status='pending' hides the team from all member-facing list endpoints. Grace period is immutable once set.
- **Archive bundle format v1.0.** ZIP containing `manifest.json`, `index.html` (self-contained client-side viewer), `teamhub/` (messages, comments, poll votes, web links, widget layouts, integrations, audit log), and `circles/` (team metadata, members, effective users). `apps/` folder is reserved for Sessions B–E.
- **`ArchivePseudonymizer`.** Admin-policy-driven per-archive UID → alias replacement. Alias map is never written to the archive. Message and comment body text is not processed.
- **Admin "Archive" settings tab.** Deletion mode (soft30 / soft60 / hard), archive storage location (owner + folder path with fallback to team owner's Files), max archive size cap (default 5 GB), pseudonymize toggle.
- **Admin archived-teams table.** Lists all pending-deletion rows with Restore (within grace period) and Force-delete actions.
- **`PendingDeletionJob`.** Daily background job finalizing teams whose soft-delete grace period has expired.
- **7 new API endpoints.** `POST /teams/{teamId}/archive`, `GET /teams/{teamId}/archive/status`, `GET|PUT /admin/archive/settings`, `GET /admin/archive/pending`, `POST /admin/archive/pending/{id}/restore`, `POST /admin/archive/pending/{id}/purge`.
- **Write guard on all MemberService mutation methods.** Attempting to invite, remove, level-change, or approve members on a pending-deletion team returns 409 Conflict.

### Security
- All admin archive endpoints carry `#[AuthorizedAdminSetting]` — NC framework enforces admin-only.
- Archive ZIP written atomically (`.zip.tmp` → rename); on failure the partial directory is deleted and the team is not deleted.
- Archive storage writes through `IRootFolder` exclusively — no raw filesystem access outside NC's abstraction layer.

## [3.24.0] — 2026-05-05

### Added
- **Mobile single-canvas layout** for viewports ≤ 768px and tablet portrait (≤ 1024px portrait). New `MobileWidgetView.vue`: scrollable canvas, collapsible icon bar at bottom with one icon per accessible widget, FAB action button.
- **FAB widget actions** — in-canvas action button rows removed; actions surfaced via FAB: single action fires directly, multiple actions open a slide-up sheet.
- **Tablet landscape layout** for viewports ≤ 1200px landscape: 60/40 split with message stream left and collapsible widget column right. Widget cards have spacing and rounded borders.
- **NC sidebar auto-close on mobile/tablet-portrait** — uses `NcAppNavigation :open.sync` prop to close reactively after selecting a team or action, instead of fragile DOM manipulation.

### Changed
- Embedded app iframe content height set to 100% (previously 90%).
- Edit layout button hidden on both mobile and tablet layouts (editing not available in these modes).
- Seven modals (`ManageLinksModal`, `AddEventModal`, `AddTaskModal`, `AddPersonalTaskModal`, `InviteMemberModal`, `ScheduleMeetingModal`, `TeamMeetingModal`) now set `min-width: 0` on viewports ≤ 768px to prevent horizontal overflow on phones.
- `MessageStream` accepts `hide-header` prop and exposes `openPostForm()` method for FAB integration.

## [3.23.0] — 2026-05-04

### Added
- **DELETE `/api/v1/comments/{commentId}`** — hard-delete a comment. Author may always delete their own; team admins (Circles level ≥ 8) may delete any comment. Audit event `comment.deleted` written with metadata `{ message_id, author_id, deleted_by_admin, cleared_solved }`.
- **Solved-question revert on answer deletion.** If the deleted comment is the marked answer to a question, the parent message is automatically reverted to unsolved (`question_solved=0, solved_comment_id=NULL`). The confirmation dialog warns the user before proceeding.
- **Delete button on comments.** Visible to the comment author and team admins. Confirmation dialog; disabled/spinner during async delete. Error messages are HTTP-status-aware (403, 404, generic).
- **`currentUserIsTeamAdmin` Vuex getter** (level ≥ 8) — derived from `current_user_level` now returned by `GET /api/v1/teams/{teamId}/members`.
- **Markdown formatting toolbar** in `PostMessageForm.vue` (new messages) and `CommentsSection.vue` (comments): Bold, Italic, Inline code, Code block, Heading (H2), Bullet list, Link. `@mousedown.prevent` preserves contenteditable selection; `execCommand('insertText')` fires at cursor.
- **Markdown toolbar on edit message.** Same seven buttons in `MessageCard.vue` edit mode. Uses native `selectionStart/End` + `setSelectionRange` (plain textarea — no `execCommand` needed).

### Fixed
- **XSS via `v-html` in message and comment bodies.** Both `renderMarkdown` functions now pass output through `DOMPurify.sanitize()` with an explicit `ALLOWED_TAGS`/`ALLOWED_ATTR` allowlist before binding to `v-html`.
- **Headings (`## text`) and bullet lists (`- item`) rendered as literal text.** `renderMarkdown` was a flat `.replace()` chain ending with `\n → <br>`, so heading and list regexes (which need multiline anchors) never matched. Rewrote using a null-byte placeholder pattern: code blocks and inline code are stashed before block-level rules run; restored after `<br>` conversion. Applied to `MessageCard.vue` and `CommentsSection.vue`.
- **Deck boards created with `permission_edit = 0`.** Deck's `AclMapper` does not mark entity fields dirty when set via `__call` magic, so `setPermissionEdit(true)` was a no-op. Added `enforceAclEditPermissions()`: one independent QB `UPDATE` per column (`permission_edit`, `permission_share`, `permission_manage`), each try/caught so a missing column throws silently without blocking the others. Schema confirmed from live DB.
- **All Deck boards and Calendars provisioned in the same blue colour.** `createTeamResources()` now picks one random colour per team (`$teamColour = self::randomTeamColour()`) and passes the same value to both `createCalendar()` and `createDeckBoard()`.
- **Provisioned resources (Talk, Files, Calendar, Deck) not deleted when a team is deleted.** `deleteTeam()` now fetches the team's app list from `teamhub_team_apps` before destroying the circle, then calls `deleteTeamResource()` for each app. Resources are deleted before `circleService->destroy()` so CalDAV/Talk can still resolve the circle principal. All apps are cleaned regardless of their `enabled` flag.

### Changed
- `GET /api/v1/teams/{teamId}/members` response now includes `current_user_level` (integer) alongside `is_direct_member`.
- `DELETE /teams/{teamId}` now deletes all provisioned Nextcloud app resources before destroying the circle.
- `ResourceService::TEAM_COLOUR_PALETTE` — 12-colour curated palette for NC-friendly team colours.

## [3.22.0] — 2026-05-01

### Fixed
- **Indirect members (added via NC group/sub-team) could not see built-in app tabs (Talk, Files, Calendar, Deck).** `ResourceService::getTeamResources()` checked only for a direct `circles_member` row; indirect members have none, so the method threw and the controller returned all-null resources. Added `isEffectiveTeamMember()` helper in `ResourceService` that mirrors the two-step indirect-membership check (circles_member → circles_membership) used elsewhere, without introducing a circular dependency on `MemberService`.
- **Member count in members widget was inflated when groups or sub-teams were present.** `getEffectiveMemberCount()` used `COUNT(*) FROM circles_membership`, which includes group-proxy and sub-team-proxy circles as rows alongside individual users. Replaced with a query that inner-joins `circles_member` on `user_type=1, level=9` to isolate personal user circles, and uses `COUNT(DISTINCT user_id)` to deduplicate users who appear via multiple membership paths.
- **Pages widget hidden after team creation even when Intravox page was successfully created.** `create-resources` did not write to `teamhub_team_apps`, so `getTeamResources` found no `intravox` row and returned `resources.intravox = false`.
- **Manage team → Settings → Team apps showed all apps enabled after creation, regardless of wizard selections.** Same missing write: `ManageTeamView` fell back to `defaultEnabled = true` for every app when no rows existed. The wizard now sends a complete `appStates` payload (all apps, enabled and disabled) with `create-resources`; the backend validates and persists these via `updateTeamApps()`.

## [3.21.0] — 2026-05-01

### Added
- **WCAG 2.2 accessibility audit and remediation (Sessions 1–3).** Full codebase reviewed against all A and AA criteria. The following fixes were applied:

#### 1.1.1 Non-text content
- `AppEmbed.vue`: `<iframe>` now carries `:title="label"` so screen readers identify embedded apps (Chat, Files, Calendar, Deck).
- `MessageCard.vue`: poll options now carry `role="button"`, `aria-pressed`, `aria-label`, `tabindex`, and `@keydown.enter/space` handlers — keyboard and AT users can vote in polls.

#### 1.3.1 Info and relationships
- `TeamWidgetGrid.vue`: all 11 widget title `<span>` elements replaced with `<h2>` (margin/padding reset added to prevent browser defaults from breaking layout). Screen reader users can now navigate widgets by heading.
- `MessageCard.vue` edit mode: bare `<input>` and `<textarea>` now have associated `<label>` elements linked by unique per-message `id`.

#### 1.4.1 Use of color
- `MessageCard.vue`: voted poll option now shows a `CheckCircleOutline` icon alongside the background highlight — vote state is no longer conveyed by colour alone.

#### 1.4.3 Contrast — hardcoded colours
- `DeckWidget.vue`: `#0e7490` teal replaced with `var(--color-info-text, var(--color-main-text))`.
- `FilesFavoritesWidget.vue`: `#f6c342` gold replaced with `var(--color-warning, #f6c342)`.
- `TeamWidgetGrid.vue`: `#1a1a1a` on success/warning badges replaced with `var(--color-success-text, #1a1a1a)` and `var(--color-warning-text, #1a1a1a)`.

#### 2.1.1 / 2.4.7 Keyboard access and focus visible
- All 10 components with `outline: none` on `:focus` migrated to `:focus-visible` with `2px solid var(--color-primary-element)` ring. Mouse/touch users are unaffected; keyboard users now see focus indicators.
- `App.vue`: duplicate `:focus-visible` blocks consolidated; `outline: none` removed.
- `TeamTabBar.vue`: `role="tablist"`, `role="tab"`, and `aria-selected` added to all tab buttons. Tab/Shift+Tab moves focus; Left/Right arrow reorders the focused tab and restores focus after re-render via `$nextTick`.
- `TeamWidgetGrid.vue` (edit mode): all 11 drag handles gain `tabindex="0"` and `@keydown` handlers for ↑ ↓ ← → to move widgets on the grid. `moveWidget()` swaps positions with the neighbour in sorted order (fixes vue-grid-layout vertical compaction cancelling `y ± 1` nudges).

#### 2.4.6 Headings and labels
- Same as 1.3.1 widget `<h2>` and edit input `<label>` changes above.

#### 2.5.7 Dragging movements
- **Tab bar**: Left/Right arrow keys on focused tab provide a keyboard alternative to drag-to-reorder (WCAG requires a pointer/keyboard alternative).
- **Widget grid**: ↑ ↓ ← → on focused drag handle provide a keyboard alternative to grid drag-and-drop.

#### 4.1.2 Name, role, value
- `TeamTabBar.vue`: `role="tablist"` + `aria-label="Team navigation"` on wrapper; `role="tab"` + `aria-selected` on each button tab; web link tabs correctly excluded from tab role.
- `TeamWidgetGrid.vue`: all 11 collapse/expand buttons now include the widget name in their `aria-label` (e.g. "Collapse Team Messages" instead of "Collapse").
- `AppEmbed.vue`: `<iframe title>` fix (see 1.1.1).

#### 4.1.3 Status messages
- `PostMessageForm.vue`: attachment list wrapped in `aria-live="polite" aria-atomic="false"` — upload status changes (Uploading…, ✓, error) are now announced to screen readers. Checkmark symbol `✓` given `:aria-label="Upload complete"`.

### Security
- `renderMarkdown` (pre-existing): `v-html` binding in `MessageCard.vue` and `CommentsSection.vue` renders user content without HTML sanitization. Logged as open issue for a dedicated security session — fix requires `DOMPurify.sanitize()` before return.

### Removed
- Debug `console.log` calls in `TeamWidgetGrid.vue` (`moveWidget`) and `TeamTabBar.vue` (`moveTabLeft`, `moveTabRight`).



### Fixed
- **Double margin-top gap below NC top bar.** NC page frame and `NcContent` both applied `margin-top: var(--header-height)` to the same element. Added `#content-vue.app-teamhub { margin-top: 0 }` to zero the page-frame copy only.
- **`TypeError: e.n is not a function` on team pages.** `translatePlural` imported at module scope is invisible to Vue 2 templates — added `n` to `methods: { t, n }` in all five affected components; `AdminSettings` gets an inline `n()` method matching its existing `t()` pattern.

### Changed
- **All error messages use `{error}` named placeholder** instead of string concatenation. Allows translators to reposition the error detail within the sentence (22 call sites across 10 components).
- **All count-bearing strings converted to `n()` plural forms** (14 strings across 5 components). Translators can now supply correct plural rules per language.

### Added
- **Transifex plumbing.** `.tx/config` and `.l10nignore` added. Stale `l10n/en.js` / `l10n/en.json` removed. Ready for NC community bot once `@nextcloud-bot` is invited to the repo.
- **`TRANSLATORS:` hints** on ambiguous strings: `Comment`, `Leave`, `Join` (team vs. meeting), poll vote labels.
- **Translation standards** added to `SKILLS.md` — every string written in future sessions must be translation-ready immediately.

### Removed
- **Debug logging purged.** 23 JS (`console.log` / `console.error`) and 15 PHP (`error_log`) calls removed across `App.vue`, `FeedbackModal.vue`, `FilesSharedWidget.vue`, `TeamView.vue`, `FeedbackController.php`, `FeedbackService.php`, `TeamService.php`, `TelemetryService.php`. The `console.warn` in `TeamView.menuItemUrl()` is intentionally kept as a security signal.

## [3.19.0] — 2026-04-30

### Added
- **Transifex plumbing.** Added `.tx/config` pointing at `o:nextcloud:p:nextcloud:r:teamhub` and `.l10nignore` excluding non-translatable paths. Enables NC community bot to open translation PRs.
- **Plural forms for all count strings.** Converted all 14 count-bearing `t()` calls to `n()` (`translatePlural`): comment count, vote count, user count on group/sub-team pills, team count, member invited confirmation, "Show all members" button. Translators can now supply correct plural rules per language.
- **`TRANSLATORS:` hints** on ambiguous strings: `Comment` (verb), `Leave` (depart team), `Join` (team vs. meeting context distinguished), poll vote labels.

### Fixed
- **String concatenation in error messages.** All 22 instances of `t('teamhub', 'Msg') + (msg ? ': ' + msg : '')` replaced with `msg ? t('teamhub', 'Msg: {error}', { error: msg }) : t('teamhub', 'Msg')` — server error detail is now a named placeholder translators can reposition.
- **`margin-top` on main content area.** NC 32 applies a default `margin-top` to `.content` — overridden to `0` on `.app-teamhub` so the full viewport height is used.

### Removed
- Stale `l10n/en.js` and `l10n/en.json` (48 keys, 8% coverage) — these collide with the NC translation bot output and were not being maintained.

## [3.18.0] — 2026-04-29

### Added
- **Audit tab info banner.** A permanent informational banner sits at the top of the Audit tab explaining that external activity is mirrored hourly, new events may take up to an hour to surface, and TeamHub-internal actions are recorded immediately. Always visible, no dismiss.
- **Iframe loading skeleton.** `AppEmbed` shows an `NcLoadingIcon` overlay until the embedded app fires its `load` event.
- **Iframe reload button.** New refresh button in the embed toolbar fully tears down and recreates the iframe element (`:key` bump), equivalent to a hard reload.
- **Iframe error state.** When `iframe_url` fails frontend validation, `AppEmbed` shows an explicit `AlertCircleOutline` error message instead of an indefinite spinner.

### Fixed
- **NC 32 chrome stripping.** NC 32 renamed `#app-navigation` → `#app-navigation-vue`, `#content` → `#content-vue`, `#app-sidebar` → `#app-sidebar-vue`, `.app-menu-main` → `#app-menu-container`. Our injected CSS was targeting the old names. All old + new selectors now covered.
- **NC 32 layout offset.** NC 32 drives the body container position from `--body-container-margin` / `--body-container-radius` CSS variables rather than fixed pixels. Now zeroed on `:root`.
- **Custom Menu app chrome visible.** The "Custom Menu" NC app (`side_menu`) injects `#side-menu-container` and `.cm--topwidemenu` that no prior rule targeted. Now explicitly hidden.
- **Files share dialog and details panel blocked.** `#app-sidebar-vue` was hidden globally — NC apps use it for share panels, file details, Calendar event editors, Deck card details. Removed from hide rules; the sidebar starts closed on page load so no chrome shows.
- **App-internal modals trapped.** `position: fixed; inset: 0` on `#content-vue` flattened the stacking context, preventing app-internal modals from rendering above the content. Replaced with `width/height: 100%` only.
- **Talk chat textarea cut off.** `.app-embed__viewport` used `height: 85%` which truncated the bottom of the Chat view. Replaced with `flex: 1 1 auto; min-height: 0` to fill available column height correctly.
- **`onLoad` never fired / injection never ran.** `loading="lazy"` on the iframe caused the browser to defer `load` events (confirmed in console: *"Load events are deferred"*). Removed.
- **`TypeError: Cannot read properties of undefined (reading 'forEach')`** in `clearRetryTimers()`. Vue 2 doesn't reliably maintain underscore-prefixed `data()` properties. Added `Array.isArray()` guard.
- **MutationObserver infinite loop.** `injectCss()` removed and re-appended the `<style>` tag on every call — each DOM write re-triggered the observer. Now bails early if tag already present.

### Security
- **Origin-aware iframe sandbox.** Cross-origin `menu_item` integrations get `sandbox="allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox"` — without `allow-same-origin` (blocks cookie/localStorage abuse) and without `allow-top-navigation` (blocks parent-window redirect). Same-origin built-ins unsandboxed (DOM access required for CSS injection).
- **`referrerpolicy="strict-origin-when-cross-origin"`** on every iframe — prevents team-scoped URL leaking via `Referer` header.
- **`allow=""`** (empty Permissions Policy) on every iframe — denies camera, mic, geolocation, payment, USB by default.
- **`rel="noopener noreferrer"`** added to "Open in new tab" link (was `target="_blank"` only — leaked `window.opener`).
- **Frontend URL re-validation** in `TeamView.menuItemUrl()`: rejects anything outside `https://`, `/apps/`, `/index.php/`. Defence-in-depth alongside backend validation in `IntegrationService`.



### Added
- **Audit tab info banner.** A permanent informational banner now sits at the top of the Audit tab (above the activity-disabled warning) explaining that external activity is mirrored hourly and that new events may take up to an hour to surface. Internal events (team creation, join requests) continue to be recorded immediately.
- **Iframe loading state.** `AppEmbed` now shows an `NcLoadingIcon` skeleton until the iframe fires its `load` event — replaces the previous blank pane during reloads and tab switches.
- **Iframe reload button.** New refresh control in the embed toolbar bumps a Vue `:key` to fully tear down and recreate the iframe element, equivalent to a hard reload (no cached app state).
- **Iframe error state.** When the URL fails frontend validation (only `https://`, `/apps/`, `/index.php/` are accepted), `AppEmbed` shows an explicit error message with `AlertCircleOutline` icon instead of spinning indefinitely.

### Security
- **Origin-aware iframe sandbox.** Cross-origin iframes (external `menu_item` integrations) now ship with `sandbox="allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox"` — deliberately *without* `allow-same-origin` (blocks cookie/localStorage abuse) and *without* `allow-top-navigation` (blocks parent-window phishing). Same-origin built-ins (Talk/Files/Calendar/Deck) remain unsandboxed because the chrome-stripping CSS injection requires DOM access.
- **Iframe `referrerpolicy="strict-origin-when-cross-origin"`** on every embed — prevents the team-scoped TeamHub URL (which contains `?team={teamId}`) from leaking to third-party origins via the `Referer` header.
- **Iframe `allow=""`** (empty Permissions Policy) on every embed — denies camera, microphone, geolocation, payment, USB, MIDI, and other powerful features by default.
- **Frontend URL re-validation** in `TeamView.menuItemUrl()`: rejects anything not `https://`, `/apps/`, or `/index.php/`. Defence-in-depth alongside the existing backend validation in `IntegrationService`. Rejected URLs are logged with their `registry_id` so admins can locate poisoned rows.
- **`rel="noopener noreferrer"`** added to the "Open in new tab" anchor in `AppEmbed`. The previous `target="_blank"` alone leaked `window.opener`.

### Changed
- Cross-origin iframes now skip the CSS-injection retry loop entirely (was running 4 doomed `setTimeout`s on every cross-origin load — silent throws but wasteful).
- "Open in new tab" button is hidden when the URL is empty (rejected by validation); Reload button is disabled in the same case.

## [3.16.0] — 2026-04-28

### Added
- **Admin governance — audit log.** New "Audit" tab in Admin Settings provides per-team activity logs with team picker, event-type filter, date range, paginated table (50/page, max 200), and ZIP export. NC admin only.
- New table `oc_teamhub_audit_log` (immutable from the application layer — only insert, read, and bulk-purge are exposed) created by migration `Version000316000`.
- `AuditService` — single write entry point with non-fatal failure handling and a 500-char-per-field metadata cap.
- `AuditIngestionService` — hourly mirror from `oc_activity` (Circles + files, 14 mapped subjects across both apps) plus snapshot-diff against `oc_share` for `share.created` / `share.permissions_changed` / `share.deleted`.
- `AuditMirrorJob` — hourly `TimedJob` orchestrating the mirror and the retention purge.
- Direct audit logging in `TeamService` (`team.created`, `team.deleted`, `team.config_changed`, `team.app_enabled`, `team.app_disabled`), `MemberService` (`join.requested`, `join.approved`, `join.rejected`, `member.joined` for open-circle self-join), and `MaintenanceService` (`team.owner_transferred`).
- 5 new admin endpoints under `/api/v1/admin/audit/...` for teams summary, paginated events, ZIP export, retention GET/PUT.
- Configurable retention (7–3650 days, default 90) stored in `IAppConfig`. Mirror job clamps and enforces on every cycle.
- Activity-app-disabled detection: when the NC Activity app is unavailable, the Audit tab shows a warning banner and only direct-logged events continue to be captured.

### Changed
- `AuditLogMapper` exposes a separate `insertWithTimestamp()` so the mirror job can preserve the original `oc_activity` timestamp on backdated rows without a follow-up UPDATE — keeps the immutability contract clean.

## [3.15.0] — 2026-04-28

### Fixed
- Calendar widget now reloads automatically after adding an event, scheduling a meeting, or creating a team meeting — all three modal close handlers now call `refreshCalendar()` via the widget grid ref.
- Meeting notes public share link now grants read+write access (was read-only), so attendees can edit the notes file directly from the shared link.
- `@nextcloud/vue` no longer logs "missing appName / appVersion" console errors — `webpack.DefinePlugin` now injects `appName` and `appVersion` as compile-time bare globals, which is what the library reads at module evaluation time.
- Members widget: removed redundant `border-top` from `.teamhub-memberships-list`; `Show all` button width set to 90%; left-side padding unified to 12px across avatar stack, membership rows, and show-all button.
- Removed redundant "Team Messages" heading from the message stream body (the accordion header already shows this label).
- Removed duplicate "Post First Message" button from the empty-state — the header-level "+ Post Message" button already handles this.
- All semantic color text uses (`--color-error`, `--color-success`, `--color-warning`) replaced with their high-contrast `-text` variants across 21 components, improving readability. Backgrounds and borders retain the base variables.

## [3.14.0] — 2026-04-28

### Added
- **Team meeting action** — new "Team meeting" button in the Calendar widget header (distinct from "Add event" and "Schedule meeting"). Creates a `Meetings/` folder in the team files folder, writes a `template.md` if not present, generates a named meeting-notes `.md` file, creates a public share link, schedules the event in the team calendar with Talk URL in the `LOCATION` field (so it appears in Talk's scheduled meetings panel), and adds all team members as ATTENDEE lines.
- **Schedule in Talk checkbox** — opt-in (default on) in the Team meeting modal; uses the team's existing Talk room token or falls back to creating a new room.
- **Ask for agenda items checkbox** — opt-in (default off); posts a message to the Talk room linking to the meeting notes and asking members to add agenda items. Uses `TalkService::postChatMessage`.
- **Meeting permissions setting** in Manage Team → Settings tab (above Team Apps): dropdown to restrict who can trigger the Team meeting action — Any member / Moderator or above / Admin or above. Stored in `teamhub_team_apps` with `app_id = 'meeting'`.
- **Schedule meeting now links to Talk room** — `ActivityService::createCalendarEvent` automatically resolves the team's Talk room and writes the URL to the `LOCATION` and `URL` iCal fields, making the meeting appear in Talk's scheduled meetings panel.
- **Clickable event titles** in the Calendar widget — each event title is now a link that opens the NC Calendar app directly to the event's edit sidebar using the confirmed direct-edit URL format.

### Fixed
- Calendar widget no longer shows soft-deleted events — NC CalDAV renames deleted events to `*-deleted.ics` without removing the DB row; the query now excludes these with a `NOT LIKE '%-deleted.ics'` filter.
- `resolveAttendees` was joining against `oc_accounts` (wrong table); corrected to join `oc_users` matching the proven `MemberService` pattern.
- `resolveUserEmail` was querying a non-existent `email` column on `oc_accounts`; corrected to use `IConfig::getUserValue('settings', 'email')`.
- `TalkService::postChatMessage` — `getParticipant()` was incorrectly passed a `User` object; corrected to pass the UID string as required by Talk's API.



### Added
- **Group and team members are now fully recognised.** When a Nextcloud group or another team is added to a team, its users count towards the team's member total and gain access to the team. The members widget shows direct users as avatars (up to 16, sorted by role then last activity), followed by a flat list of added groups and teams with a `GROUP` or `TEAM` pill and their user count. A "Show all N members" link opens a searchable modal listing every effective user, deduplicated.
- **Manage Team → Members tab** displays three buckets: Direct Members, Groups & Teams (with name and effective user count), and Pending Join Requests. Admins can remove whole groups or teams, which also clears their users' indirect access.
- **Invite modal** can now search for and add other user-created teams (circles) in addition to users, groups, email invites, and federated contacts.
- New `GET /api/v1/teams/{teamId}/members/all` endpoint — returns the flat deduplicated list of all effective users (direct plus expanded from groups and sub-teams) for the Show All modal. Requires member-level access.
- New `GET /api/v1/teams/{teamId}/members/manage` endpoint — structured response (direct, groups, circles, effective_count) for the Manage Team members tab. Requires admin-level access.
- `BrowseTeamsView` teams now return an `isDirectMember` flag so indirect members see a disabled Leave button with an explanatory tooltip rather than being allowed to "leave" a team they were never directly added to.
- `leaveTeam` now detects indirect membership and returns a 403 with an `indirect_member` sentinel so the UI can show the tooltip explanation.

### Changed
- The `GET /api/v1/teams/{teamId}/members` response shape changed from a flat array to `{members, memberships, effective_count, has_more, is_direct_member}`. `members` is limited to the top 16 direct users (sorted by role then last login), `memberships` is the flat list of added groups and teams for the widget.
- Admin Settings → Maintenance team member count column now reflects effective membership (direct users plus users from added groups and sub-teams) instead of only the three top-level rows in `circles_member`.
- `removeMember()` now correctly handles groups (`user_type=2`) and teams (`user_type=16`) by using `single_id` as the delete key. It also calls `MembershipService::onUpdate()` after deletion so removed indirect users actually disappear from share pickers.
- Pending Join Requests in Manage Team has extra top padding to separate it from the membership summary.
- Group and Team icons/pills use the primary-element (blue) and warning (amber) tones respectively — the previous success-green was too low-contrast.

### Fixed
- Integrity check in Admin Settings → Maintenance no longer flags teams as mismatched just because they have a group or sub-team as a member. It now flags only teams whose `circles_membership` cache is genuinely empty while direct members exist.
- `getTeamMembers` no longer fails on the `u.last_login` column (which does not exist on `oc_users`); last-login sorting now reads from `oc_user_preferences` / `oc_preferences`.
- `browseAllTeams` correctly detects membership via groups or sub-teams in addition to direct rows.

### Security
- `getTeamMembers` now enforces `requireMemberLevel` — previously any authenticated user could enumerate any team's member list by guessing a circle ID.
- `lastLogin` timestamps (used internally for sort order) are stripped from the `members` response so they are never exposed to the client.

## [3.11.0] — 2026-04-22

### Added
- **Upcoming Tasks widget now shows personal tasks alongside Deck tasks.** When the NC Tasks app is installed and the team has a calendar, VTODO tasks from the team calendar are fetched server-side (Sabre/VObject, direct DB query on `calendarobjects`) and merged with Deck cards into a single sorted list. Each task row shows a source pill — blue "Deck" or teal "Personal task" — so users can distinguish at a glance. The two task types also use different badge icons.
- New `GET /api/v1/teams/{teamId}/tasks` endpoint — returns upcoming (≤14 days, non-completed) VTODO tasks from the team calendar.
- New `POST /api/v1/teams/{teamId}/tasks` endpoint — creates a VTODO in the team calendar via `CalDavBackend` (QB fallback if unavailable).
- New **Create personal task** action in the Upcoming Tasks widget header, which opens a modal (title, optional description, optional due date/time). Shown only when Tasks app is installed and team has a calendar.
- The existing **Add task** action renamed to **Create Deck task** to distinguish it from personal tasks. Shown only when team has a Deck board.
- `resources` payload from `GET /teams/{teamId}/resources` now includes a `tasks: bool` flag indicating whether the NC Tasks app is installed.
- New `AddPersonalTaskModal.vue` component.
- New `lib/Service/TaskService.php` service.
- New migration `Version000310001` — ensures `teamhub_integ_registry` exists and drops the legacy `teamhub_integration_registry` table if it survived an NC uninstall. Fixes a scenario where NC's "delete all data" uninstall keeps migration history, causing the new-name table to never be created on reinstall.

### Fixed
- Fixed `oc_teamhub_integ_registry does not exist` error on installs where NC's uninstall-with-delete-data flow preserved migration history, causing migration 000209000 to be skipped on reinstall while the old `teamhub_integration_registry` table survived.

## [3.10.0] — 2026-04-21

### Fixed
- Renamed `teamhub_integration_registry` (28 chars) to `teamhub_integ_registry` (22 chars) across all migrations, mappers, and services to comply with NC's 27-character table name limit.
- Added explicit primary key constraint names to `teamhub_integ_registry`, `teamhub_team_integrations`, and `teamhub_widget_layouts` — auto-generated PostgreSQL names (`oc_{table}_pkey`) exceeded 27 chars and failed NC schema validation.
- Added migration `Version000300901` to rename auto-generated PK constraints on existing PostgreSQL installs.
- Retired `Version000300900` rename logic after discovering `IDBConnection::getPrefix()` does not exist on NC 33's `ConnectionAdapter`; now a safe no-op.

## [3.9.0] — 2026-04-21

### Fixed
- Fixed fresh-install failure: `teamhub_team_apps.enabled` was declared `BOOLEAN NOT NULL` which Doctrine rejects when storing `false` on MySQL/MariaDB; changed to `SMALLINT NOT NULL DEFAULT 1`.
- Fixed same BOOLEAN/NOT NULL issue on `teamhub_integration_registry.is_builtin`; changed to `SMALLINT NOT NULL DEFAULT 0`.
- Added migration `Version000300801` to apply both column type fixes to existing installations.

## [3.8.0] — 2026-04-20

### Added
- Telemetry payload expanded with six new anonymous metrics: `nc_version`, `user_count`, `member_total`, `message_count`, `builtin_integrations` (per-builtin-app team counts), and `link_domains` (custom-link hostname frequency map).
- `link_domains` aggregates custom web-link URLs down to their bare lowercase hostname before sending — no paths, query strings, ports, fragments, localhost entries, or numeric IPs leave the instance.

### Changed
- `GET /api/v1/admin/telemetry` preview object now includes all new fields; admin UI automatically renders them via the existing JSON preview.
- `TelemetryService` now depends on `IUserManager` for user counting.

### Security
- All new collection paths are read-only DB queries using `QueryBuilder` with named parameters — no new user-input surface.
- No new endpoints; existing telemetry endpoint remains `#[AuthorizedAdminSetting]`-guarded.

---

# TeamHub v3.5 — Changes


## Admin Maintenance tab — full teams grid

Replaced the old "Orphaned teams" section with a full teams management grid covering every user-created team on the NC instance.
**What it does:** Paginated table with search by name, "orphans only" toggle, and per-page selector (10/20/50/100). Each row shows team name, description, member count, owner (display name + uid), and creation date. Two icon-only action buttons per row: set owner and delete.

---

## Set owner

Admin can assign any NC user as owner of any team — whether or not that user is currently a member.

## Delete team (admin)

Admin can delete any team regardless of ownership. Cleans up all associated data before destroying the circle.


TeamHub v3.6 — Changes
## Activity widget

Deck activity now scoped to the team's board only — card events (deck_card) and board events (deck_board) handled separately
Talk activity scoped to the team's room via numeric room ID — eliminates cross-team bleed
Calendar/DAV activity subject strings corrected to match real oc_activity values
Friendly human-readable labels for all Deck, Calendar, and Circles activity subjects

## Manage Team — Maintenance tab

"Danger Zone" tab renamed to "Maintenance"
Transfer ownership added — team owner can promote any current team member to owner
Ownership transfer requires two-step confirmation and demotes the current owner to admin
Leave team now shows the real server error message (e.g. "Transfer ownership before leaving")

## Admin Settings — Membership cache integrity

New section in the Maintenance tab: scan all teams for stale membership cache
Compares circles_member (source of truth) against circles_membership (share picker cache)
Per-team Repair button rebuilds the cache — fixes teams invisible to Files, Calendar and Deck share pickers

## Files 

Re-enabling the Files app for a team now works correctly
Favourite Files and Recently Modified widgets no longer appear on teams without a connected Files resource