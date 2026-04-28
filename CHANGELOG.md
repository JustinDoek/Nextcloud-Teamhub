# Changelog

All notable changes to TeamHub are documented in this file.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

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