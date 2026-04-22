# Changelog

All notable changes to TeamHub are documented in this file.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

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