# Changelog

All notable changes to TeamHub are documented in this file.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

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