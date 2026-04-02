# Changelog

## 2.23.0 (2026-03)

### Features
- **Pin messages** — moderators (or the level configured by admins) can pin one message per team; it appears above the stream with a highlighted border and a "Pinned" label. Pinning a new message automatically unpins the previous one.
- **Unread indicators** — a badge dot appears next to each team in the sidebar when a new message has been posted since the user last visited. The indicator clears immediately when the team is selected.
- **Fixed notifications** — new-message notifications now correctly show the team name and author for all teams, including those with custom Circles config bitmasks. Notifications also link directly to the correct team (`?team=<id>`).
- **Admin setting: minimum pin level** — a new dropdown in the TeamHub admin panel controls the minimum Circles member role required to pin messages (Member / Moderator / Admin–Owner; default: Moderator).

### Internal
- `createMessage()` in `MessageService` no longer uses `probeCircles()` to look up the circle for notification dispatch. Team name is now resolved via a direct `circles_circle` DB query, consistent with all other read operations.
- New migration `Version000208000Date20260330000000`: adds `pinned SMALLINT` to `teamhub_messages` and creates the `teamhub_last_seen` table.

## 2.22.x (2026-03)

Initial public release.

### Features
- Team workspace with tab bar (Home, Chat, Files, Calendar, Deck, custom links)
- Message stream with comments, polls and questions
- Activity feed widget (5 items) and full 30-day canvas view grouped by day
- Sidebar widgets: upcoming events, open tasks, Pages, team info, member avatars
- Team creation wizard with description, visibility and config options
- Member management: invite by user / group / email / federated account
- Browse teams view with join request flow
- Admin settings: wizard description, allowed invite types
- Direct DB-based team listing (bypasses Circles API visibility filter)
- Config bitmask preservation (internal Circles bits never modified)
- PostgreSQL and MySQL/MariaDB support
