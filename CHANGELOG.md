# Changelog

All notable changes to TeamHub are documented in this file.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [3.63.0] — 2026-06-02 — Members widget overhaul

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