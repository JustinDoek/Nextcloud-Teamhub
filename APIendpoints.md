# TeamHub API Endpoints — v3.42.0

> v3.42.0 adds 18 admin endpoints under `/api/v1/admin/presence/*` for the Presence module foundation (status types, locations, holidays).
> v3.37.0 adds 4 new endpoints: 2 calendar event endpoints (week query, delete), 2 message settings endpoints (get, save).
> v3.28.0 added 5 endpoints: 3 picker endpoints, 1 connect endpoint, 1 soft-delete endpoint.

All endpoints are prefixed with `/apps/teamhub/api/v1`.
All endpoints require an authenticated Nextcloud session unless noted.
CSRF protection is disabled (`#[NoCSRFRequired]`) on all listed endpoints.

### Auth levels used throughout this document

| Term | Meaning |
|---|---|
| **Authenticated** | Any valid NC session |
| **Team member** | `circles_member.level >= 1` for the given `teamId` |
| **Team moderator** | `circles_member.level >= 4` |
| **Team admin** | `circles_member.level >= 8` (Admin or Owner) |
| **Team owner** | `circles_member.level = 9` |
| **NC admin** | Nextcloud server administrator (no `#[NoAdminRequired]` attribute) |

All team-scoped membership checks use a direct indexed DB query against `circles_member` — no Circles API session overhead.

---

## Message settings (added v3.37.0)

### GET `/teams/{teamId}/messages/settings`
Return per-team message permission settings.

**Auth:** Team member.
**Response 200:** `{ pinMinLevel: 'member'|'moderator'|'admin', postMinLevel: 'member'|'moderator'|'admin' }`

### POST `/teams/{teamId}/messages/settings`
Save per-team message permission settings.

**Auth:** Team admin.
**Body:** `{ pinMinLevel: 'member'|'moderator'|'admin', postMinLevel: 'member'|'moderator'|'admin' }`
**Response 200:** `{ success: true }`

---

## Calendar events — week query (added v3.37.0)

### GET `/teams/{teamId}/calendar/events/week`
Return VEVENT objects whose DTSTART falls within the week identified by `weekStart`. Used by the Delete events modal to show the current week's events.

**Auth:** Team member.
**Query:** `weekStart` — ISO 8601 datetime string for Monday 00:00:00 of the desired week (defaults to current week).
**Response 200:** `[{ id, uri, calendarId, title, start, end, allDay, calendarName }]`

### DELETE `/teams/{teamId}/calendar/events`
Delete one or more calendar events. Each deletion is audit-logged as `calendar.event_deleted`.

**Auth:** Team member.
**Body:** `{ events: [{ calendarId: int, uri: string, title: string }] }`
**Response 200:** `{ deleted: int, errors: int }`

---



## Resource pickers (added v3.28.0)

### GET `/pickers/calendar`
List calendars owned by the current user that can be connected to a team. Excludes the contact birthday calendar and soft-deleted calendars.

**Auth:** Authenticated.
**Response 200:** `{ resources: [{ id, uri, name, color }] }`

### GET `/pickers/deck`
List Deck boards owned by the current user. Excludes archived and soft-deleted boards.

**Auth:** Authenticated.
**Response 200:** `{ resources: [{ id, name, color }] }`

### GET `/pickers/talk`
List Talk rooms where the current user is owner or moderator. Excludes one-to-one, changelog, and note-to-self rooms.

**Auth:** Authenticated.
**Response 200:** `{ resources: [{ id, token, name, type }] }`

### GET `/pickers/files`
List file folders available to connect to a team.
**Auth:** Authenticated. Requires team membership (verified server-side).
**Query:** `?teamId={teamId}&activeFilesType={shared|gf|none}`
- `activeFilesType=none` (default): returns both group folders and shared folders.
- `activeFilesType=shared`: returns group folders only (the team already has a shared folder; only GFs are connect targets).
- `activeFilesType=gf`: returns nothing (team already has a GF; 1:1 enforced).
**Response:** `{ resources: [{ id: string, name: string, type: 'group_folder'|'shared_folder' }] }`
Group folders where the team's circle is a member appear first (id = `gf:{folderId}`). Shared folders owned by the current user and not yet connected to any team follow (id = filecache integer string).

---

## Connect existing resource (added v3.28.0)

### POST `/teams/{teamId}/resources/{app}/connect`
Connect an existing app resource to a team by inserting the appropriate share/ACL row. The user must own the resource — each sub-service re-verifies ownership before any insert. For files, refuses to connect when a duplicate type is already active (1:1 rule); accepts a GF connect when a shared folder is active (dual state allowed for manual migration window).

**Path params:** `app` ∈ `{talk, files, calendar, deck}`.
**Body:** `{ resourceId: <int|string> }` — the calendar ID / file ID / board ID / room ID. For files, `gf:{folderId}` for group folders or integer string for shared folders.
**Auth:** Team admin (level 8+).
**Response 200:** `{ success: true, ...app-specific fields }`.
**Response 400:** Unknown app, missing `resourceId`, or duplicate file resource type when 1:1 applies.
**Response 403:** Caller is not team admin.

---

### GET `/teams/{teamId}/resources/panel`
Return all resource rows for the team grouped by `app_id`. Used by Manage Team → Settings to populate the pending/ignored resource review UI.

**Auth:** Team admin (level 8+).
**Response 200:** `{ [appId]: [ { id, teamId, appId, resourceId, displayName, ownerUid, origin, status, riskStatus, displayOrder, decidedBy, decidedAt, riskSetAt, createdAt, updatedAt } ] }`. `displayName` is resolved from the native app table (filecache/talk_rooms/calendars/deck_boards); falls back to `resourceId` if the lookup fails.
**Response 403:** Caller is not team admin.

---

### POST `/teams/{teamId}/resources/{app}/{resourceId}/accept`
Accept a pending resource row — sets `status=active`, records `decided_by` and `decided_at`. Writes `resource.accepted` audit event.

**Path params:** `app` ∈ `{talk, files, calendar, deck}`. `resourceId` = the NC resource identifier (file_source / room token / calendar id / board id).
**Auth:** Team admin (level 8+).
**Response 200:** `{ status: 'ok' }`.
**Response 400:** Row not found, or row is not in pending state.
**Response 403:** Caller is not team admin.

---

### POST `/teams/{teamId}/resources/{app}/{resourceId}/ignore`
Ignore a pending or active resource row — sets `status=ignored`. Reversible. Does not remove the underlying NC ACL. Writes `resource.ignored` audit event.

**Auth:** Team admin (level 8+).
**Response 200:** `{ status: 'ok' }`.
**Response 400:** Row not found, or row is already ignored.
**Response 403:** Caller is not team admin.

---

### POST `/teams/{teamId}/resources/{app}/{resourceId}/unignore`
Restore an ignored resource to active status. Writes `resource.unignored` audit event.

**Auth:** Team admin (level 8+).
**Response 200:** `{ status: 'ok' }`.
**Response 400:** Row not found, or row is not in ignored state.
**Response 403:** Caller is not team admin.

---

### DELETE `/teams/{teamId}/resources/{app}/{resourceId}/remove`
Strip the team's circle ACL from a resource and delete the registry row. The underlying resource (board, calendar, room, folder) is preserved and remains accessible to its owner. Writes `resource.access_removed` audit event with `shared_with_other_teams` and `other_team_count` metadata.

**Auth:** Team admin (level 8+).
**Response 200:** `{ status: 'ok', aclStripped: bool }`.
**Response 400:** Row not found.
**Response 403:** Caller is not team admin.

---

### DELETE `/teams/{teamId}/resources/{app}/{resourceId}/delete`
Permanently destroy the underlying NC resource (Deck board, calendar, Talk room, or shared folder) and delete the registry row. This is irreversible. Writes `resource.deleted` audit event.

**Auth:** Team admin (level 8+).
**Response 200:** `{ status: 'ok' }`.
**Response 400:** Row not found or delete failed.
**Response 403:** Caller is not team admin.

---

## Archive (added v3.25.0)

### POST `/teams/{teamId}/archive`
Initiate an archive-and-delete for the team. Produces a ZIP archive of all TeamHub and Circles
data synchronously within the request, registers a `teamhub_pending_dels` row, then either
hard-deletes immediately (mode=hard) or leaves deletion for the daily cron (mode=soft30/soft60).

**Auth:** Team owner (level 9).
**Response 200:** `{ id, teamId, teamName, archivedAt, hardDeleteAt, daysRemaining, archivePath, archiveBytes, archivedBy, status, failureReason }`
**Response 403:** Caller is not team owner.
**Response 409:** Team already has a pending-deletion row.
**Response 413:** Estimated archive size exceeds configured cap.
**Response 500:** Archive production failed. Team is NOT deleted. Row is set to status='failed'. Retry is possible.

### POST `/teams/{teamId}/soft-delete` *(added v3.28.0)*
Soft-delete a team WITHOUT producing an archive. Used when the admin has set `archiveBeforeDelete=false` and chosen `soft30` or `soft60`. Creates a pending-deletion row, suspends connected app resources, but skips archive production.

For `archiveBeforeDelete=false` + `mode=hard`, the frontend calls `DELETE /teams/{teamId}` directly — no pending row is needed.

**Auth:** Team owner (level 9).
**Response 200:** Pending-deletion row metadata (same shape as `/teams/{teamId}/archive`); `archivePath=''` and `archiveBytes=0` since no archive is produced.
**Response 403:** Caller is not team owner.
**Response 409:** Team already has a pending-deletion row.
**Response 500:** Operation failed (e.g. invalid mode for this endpoint, or DB error).

---

### GET `/teams/{teamId}/archive/status`
Returns the pending-deletion row for this team, or `{ "pending": null }` if none exists.

**Auth:** Authenticated.
**Response 200:** `{ pending: { ...row } | null }`

---

### GET `/admin/archive/pending`
Returns a paginated list of all pending-deletion rows for the admin archived-teams table.

**Auth:** NC admin.
**Query params:** `limit` (default 50, max 200), `offset` (default 0).
**Response 200:** `{ rows: [ ...pendingRow ], total: int }`

---

### POST `/admin/archive/pending/{id}/restore`
Restore a pending-deletion team within its grace period. Sets `status='restored'`, making
the team visible again. The archive ZIP is retained.

**Auth:** NC admin.
**Response 200:** `{ restored: true, teamId, teamName }`
**Response 404:** Row not found.
**Response 409:** Status is not 'pending' (already completed/failed/restored).

---

### POST `/admin/archive/pending/{id}/purge`
Force immediate hard-delete of a pending-deletion team, ignoring remaining grace period.

**Auth:** NC admin.
**Response 200:** `{ purged: true, teamId, teamName }`
**Response 404:** Row not found.

---

### GET `/admin/archive/settings`
Returns the current archive configuration.

**Auth:** NC admin.
**Response 200:** `{ archiveMode: 'soft30'|'soft60'|'hard', archiveOwner: string, archiveLocation: string, archiveLocationName: string, archiveMaxMb: int, anonymizeData: bool }`

`archiveLocation` — the raw stored value (e.g. `/f/150770` or empty string).
`archiveLocationName` — the resolved human-readable folder name (e.g. `TeamHub Archives`). Equals `archiveLocation` if resolution fails or the value is not a `/f/{id}` link.

---

### PUT `/admin/archive/settings`
Save archive configuration. All fields are optional — only provided fields are updated.

**Auth:** NC admin.
**Body (JSON):** `{ archiveMode?, archiveOwner?, archivePath?, archiveMaxMb?, anonymizeData? }`
**Response 200:** `{ saved: true }`

---


## Teams

### GET `/teams`
List all teams the current user is a member of.
**Auth:** Authenticated.
**Response:** `[ { id, name, description, members, unread, image_url, config } ]`
`config` is the raw Circles bitmask integer (see GET `/teams/{teamId}/config` for bit definitions).

### POST `/teams`
Create a new team.
**Auth:** Authenticated. Subject to `createTeamGroup` admin setting (empty = everyone allowed).
**Body:** `{ name, description? }`
**Response:** `{ id, name, description, members, image_url }`

### GET `/teams/{teamId}`
Get details for a single team.
**Auth:** Team member (enforced via direct DB query against `circles_member` — does not use Circles API session).
**Response:** `{ id, name, description, members, image_url, config }`
`config` is the raw Circles bitmask integer.

### PUT `/teams/{teamId}`
Update team description and/or config bitmask.
**Auth:** Team admin (enforced in service when `description` or `config` is present in body).
**Body:** `{ description?, config? }`
**Response:** `{ success: true, id }`

### DELETE `/teams/{teamId}`
Delete a team and all its provisioned Nextcloud resources (Talk room, Files folder, Calendar, Deck board). Resources are deleted before the circle is destroyed so sub-services can still resolve the circle principal during cleanup.
**Auth:** Team owner.

### POST `/teams/{teamId}/transfer-owner`
Transfer team ownership to an existing team member. The caller is demoted to admin.
**Auth:** Team owner. Target must already be a member of the team (non-members require the NC admin path).
**Body:** `application/x-www-form-urlencoded` — `userId=<uid>`
**Response:** `{ success: true }`

### GET `/teams/{teamId}/members`
List members of a team for the home-page members widget.
**Auth:** Team member (direct or indirect via group/sub-team).
**Response:**
```
{
  members:          [ { userId, displayName, level, role, status } ],   // top 16 direct users, sorted by role then last login desc
  memberships:      [ { type: 'group'|'circle', displayName, memberCount } ],  // flat list of added groups/teams
  effective_count:  int,     // total users with access (direct + expanded, deduplicated)
  has_more:         bool,    // true when effective_count > len(members)
  is_direct_member: bool,    // false when caller is only in the team via a group/sub-team
  current_user_level: int,   // caller's Circles level on this team (0 = indirect member only, 8 = admin, 9 = owner)
}
```

### GET `/teams/{teamId}/members/all`
Flat deduplicated list of every effective user of the team (direct + expanded from groups/sub-teams). Used by the "Show all members" modal.
**Auth:** Team member.
**Response:** `{ members: [ { userId, displayName } ] }` — sorted by displayName case-insensitive.

### GET `/teams/{teamId}/members/manage`
Structured member breakdown for the Manage Team → Members tab.
**Auth:** Team admin.
**Response:**
```
{
  direct:          [ { userId, displayName, role, level, status } ],
  groups:          [ { groupId, singleId, userType, displayName, memberCount } ],
  circles:         [ { circleId, singleId, userType, displayName, memberCount } ],
  effective_count: int,
}
```
`singleId` is the handle to pass to DELETE for groups and teams.

### PUT `/teams/{teamId}/members/{userId}/level`
Change a member's role.
**Auth:** Team admin. Cannot change owner's level. Cannot promote to Admin unless caller is Owner.
**Body:** `{ level: 1|4|8 }`

### DELETE `/teams/{teamId}/members/{userId}`
Remove a member, group, or team from the team.
**Auth:** Team admin. Cannot remove the team owner.
**Query:** `?type=user|group|circle` — default `user`.
- For `type=user` the URL `userId` is the NC uid.
- For `type=group` or `type=circle` the URL `userId` is the `singleId` returned from the manage endpoint.

### POST `/teams/{teamId}/invite-members`
Invite users/groups/teams/emails/federated users to the team.
**Auth:** Team moderator (level >= 4).
**Body:** `{ members: [ { id, type } ] }` — type: `user` | `group` | `circle` | `email` | `federated`
**Response:** `{ userId: 'invited'|'failed: ...' }`

### POST `/teams/{teamId}/leave`
Leave the team. Owner cannot leave if other members remain.
**Auth:** Team member.
**Errors:** Returns HTTP 403 with `{ error: 'indirect_member' }` when the caller is only in the team via a group or sub-team (they cannot leave such access themselves).

### POST `/teams/{teamId}/join`
Request to join a team. Creates a pending membership request.
**Auth:** Authenticated.

### GET `/teams/{teamId}/pending`
List pending membership requests.
**Auth:** Team admin.
**Response:** `[ { userId, displayName, level, role } ]`

### POST `/teams/{teamId}/pending/{userId}/approve`
Approve a pending membership request.
**Auth:** Team admin.

### POST `/teams/{teamId}/pending/{userId}/reject`
Reject a pending membership request.
**Auth:** Team admin.

### GET `/teams/browse`
Discover visible/joinable teams. Returns teams where the user is a member or the team has CFG_VISIBLE (bitmask bit 512) set. SQL-filtered — does not load all circles into PHP.
**Auth:** Authenticated.
**Response:** `[ { id, name, description, isMember, image_url } ]`

### GET `/teams/can-create`
Check whether the current user is permitted to create teams.
**Auth:** Authenticated.
**Response:** `{ canCreate: bool }`

### GET `/teams/invite-types`
Get the allowed invite types as configured by the NC admin.
**Auth:** Authenticated.
**Response:** `{ types: ['user', 'group', ...] }`

---

## Team apps (built-in NC app toggles)

### GET `/teams/{teamId}/apps`
Get the enabled/disabled state of built-in apps (Talk, Files, Calendar, Deck, Intravox) for a team.
**Auth:** Team member.
**Response:** `[ { app_id, enabled, config } ]`

### PUT `/teams/{teamId}/apps`
Enable or disable one or more built-in apps for a team. Enabling provisions the resource; disabling hard-deletes it (irreversible).
**Auth:** Team admin.
**Body:** `{ apps: [ { app_id, enabled } ] }`
**Response:** `{ success: true, results: { app_id: { ... } } }`

### GET `/teams/apps/check`
Report which optional apps (Talk, Calendar, Deck, Intravox) are currently installed.
**Auth:** Authenticated.
**Response:** `{ talk: bool, calendar: bool, deck: bool, intravox: bool }`

---

## Team resources

### GET `/teams/{teamId}/resources`
Get provisioned resources (Talk token, Files path, Calendar id, Deck board id).
**Auth:** Team member (membership enforced before returning any resource identifiers).
**Response:** `{ talk: { token, name }|null, files: { folder_id, path }|null, calendar: { id, uri, name, public_token }|null, deck: { board_id, name, color }|null }`

### POST `/teams/{teamId}/resources`
Provision resources for the specified apps and persist the full app enabled/disabled state.
**Auth:** Team admin.
**Body:** `{ apps: string[], teamName: string, appStates?: [{ app_id: string, enabled: bool }] }`
- `apps` — resource keys to provision: `talk`, `files`, `calendar`, `deck`, `intravox`
- `appStates` — optional; full state for all apps including disabled ones. Used by the create-team wizard to seed `teamhub_team_apps` immediately. Each `app_id` is validated against the allowlist `spreed`, `files`, `shared_files`, `calendar`, `deck`, `intravox`.
**Response:** Per-app results map. Each value has either resource details or `{ error: string }`.

### DELETE `/teams/{teamId}/resources/{app}`
Hard-delete the resource for a specific app. `app` is allowlisted: `spreed`, `files`, `calendar`, `deck`, `intravox`.
**Auth:** Team admin.

---

## Team tasks (NC Tasks app — VTODO)

### GET `/teams/{teamId}/tasks`
Fetch upcoming VTODO tasks from the team's shared calendar. Only works when the NC Tasks app (`tasks`) is installed and the team has a provisioned calendar. Returns tasks due within the next 14 days that are not completed or cancelled, sorted by due date ascending.
**Auth:** Team member.
**Response:** `[ { id, title, duedate, priority, status, url } ]`
- `duedate` — ISO 8601 string or `null`
- `priority` — iCal integer (0 = none, 1 = highest, 9 = lowest)
- `status` — iCal STATUS value (e.g. `NEEDS-ACTION`)
- `url` — always `/apps/tasks` (deep-links not yet supported by the Tasks app)

### POST `/teams/{teamId}/tasks`
Create a VTODO task in the team's shared calendar. Persisted via `CalDavBackend::createCalendarObject()` (QB insert fallback if CalDavBackend is unavailable). CSRF protection active.
**Auth:** Team member.
**Body:** `{ title: string, duedate?: string (ISO 8601), description?: string }`
**Response:** `{ uri: string, title: string }` — HTTP 201 on success.

---

## Team config

### GET `/teams/{teamId}/config`
Get the raw config bitmask for a team circle.
**Auth:** Authenticated.
**Response:** `{ config: int }`

### PUT `/teams/{teamId}/config`
Update managed config bits (open, invite, request, visible, protected, single). Unmanaged bits are preserved.
**Auth:** Team admin.
**Body:** `{ config: int }`

---

## Team activity

### GET `/teams/{teamId}/activity`
Get the activity feed for a team (files, calendar, deck, circles events).
**Auth:** Team member.
**Query:** `?limit=25&since=0` (limit capped at 100)
**Response:** `{ activities: [ { activity_id, app, type, subject, datetime, user, icon, link, object_type, object_id, file, board_name, card_title } ] }`
**Note:** `board_name` and `card_title` are non-empty only for `app=deck` events, parsed from Nextcloud's `subjectparams` JSON. All connected Deck boards are included (multi-board support since 3.41.0).

### GET `/teams/{teamId}/calendar-events`
Get upcoming calendar events for the team (next 30 days).
**Auth:** Team member.

### POST `/teams/{teamId}/calendar`
Create a calendar event on the team calendar.
**Auth:** Team member.
**Body:** `{ title, start, end, location?, description? }`

---

## Team image

### POST `/teams/{teamId}/image`
Upload a team image (logo). Accepted: JPEG, PNG, GIF, WebP. Max 5 MB. Stored as JPEG.
**Auth:** Team admin.
**Body:** multipart/form-data with `image` field.

### DELETE `/teams/{teamId}/image`
Remove the team image.
**Auth:** Team admin.

---

## Messages

### GET `/teams/{teamId}/messages`
Get team messages and the pinned message.
**Auth:** Team member.
**Response:** `{ pinned: object|null, messages: [] }`

### POST `/teams/{teamId}/messages`
Post a new message. Sends notifications to all team members.
**Auth:** Team member.
**Body:** `{ subject, message, priority?: 'normal'|'priority', messageType?: 'normal'|'poll'|'question', pollOptions?: string[] }`

### PUT `/teams/{teamId}/messages/{messageId}`
Edit a message. Only the author may edit.
**Auth:** Team member (author check enforced in service).

### DELETE `/teams/{teamId}/messages/{messageId}`
Delete a message.
**Auth:** Message author OR team admin.

### POST `/teams/{teamId}/messages/{messageId}/pin`
Pin a message. Requires the level configured in `pinMinLevel` admin setting (default: moderator).
**Auth:** Team member meeting `pinMinLevel`.

### POST `/teams/{teamId}/messages/{messageId}/unpin`
Unpin a message.
**Auth:** Team member meeting `pinMinLevel`.

### POST `/teams/{teamId}/messages/{messageId}/vote`
Vote in a poll.
**Auth:** Team member.
**Body:** `{ optionIndex: int }`

### GET `/teams/{teamId}/messages/{messageId}/poll-results`
Get current poll results.
**Auth:** Authenticated.

### POST `/teams/{teamId}/messages/{messageId}/close-poll`
Close a poll. Author only.
**Auth:** Team member (author check in service).

### POST `/teams/{teamId}/messages/{messageId}/mark-solved`
Mark a question message as solved.
**Auth:** Team member.
**Body:** `{ commentId: int }`

### POST `/teams/{teamId}/messages/{messageId}/unmark-solved`
Unmark a question as solved.
**Auth:** Team member.

### GET `/teams/messages/aggregated`
Get recent messages across all the current user's teams (used by the NC Dashboard widget).
**Auth:** Authenticated.

---

## Comments

### GET `/teams/{teamId}/messages/{messageId}/comments`
Get comments on a message.
**Auth:** Team member.

### POST `/teams/{teamId}/messages/{messageId}/comments`
Post a comment.
**Auth:** Team member.

### DELETE `/api/v1/comments/{commentId}`
Hard-delete a comment.
**Auth:** Comment author OR team admin (Circles level ≥ 8). Backend enforces both checks.
**Side effects:**
- If the comment was the marked answer to a solved question, the parent message is reverted to unsolved (`question_solved=0, solved_comment_id=NULL`).
- Audit event `comment.deleted` written with metadata `{ message_id, author_id, deleted_by_admin, cleared_solved }`.
**Response:** `{ success: true, commentId, messageId, message: <updated parent message>, cleared_solved: bool }`
The response includes the full updated parent message so the caller can refresh `comment_count` and solved-state UI in one round trip.
**Error responses:** `401` unauthenticated, `403` insufficient permissions, `404` comment or parent message not found.

---

## Web links (custom tab bar links)

### GET `/teams/{teamId}/links`
List all web links for the team.
**Auth:** Team member.

### POST `/teams/{teamId}/links`
Create a web link.
**Auth:** Team admin.
**Body:** `{ title: string, url: string }`

### PUT `/teams/{teamId}/links/{linkId}`
Update a web link.
**Auth:** Team admin.

### DELETE `/teams/{teamId}/links/{linkId}`
Delete a web link.
**Auth:** Team admin.

### PUT `/teams/{teamId}/links/reorder`
Reorder web links.
**Auth:** Team admin.
**Body:** `{ ordered_ids: [int] }`

---

## Files widgets

### GET `/teams/{teamId}/files/favorites`
Return files in the team's shared folder (and subfolders) that the current user has starred (⭐ favourites).
**Auth:** Team member. Results are scoped to the requesting user — each user sees only their own starred files.
**Response:** `[ { id, name, path, mtime, size, mimetype, extension } ]`
- `path` is relative to the team folder root (e.g. `subfolder/report.pdf`)
- `mtime` is a Unix timestamp in seconds
- Returns `[]` if the team has no files resource configured

### GET `/teams/{teamId}/files/recent`
Return the 5 most recently modified files in the team's shared folder (and subfolders), newest first.
**Auth:** Team member.
**Response:** `[ { id, name, path, mtime, size, mimetype, extension } ]`
- Sorted by `mtime` descending
- Maximum 5 items
- Returns `[]` if the team has no files resource configured

**Frontend file URL:** Both widgets open files via `generateUrl('/f/{id}')` — NC resolves the correct editor/viewer by file ID.

---

## Layout

### GET `/teams/{teamId}/layout`
Get the current user's widget grid layout and tab order for a team.
**Auth:** Team member.
**Response:** `{ layout: [...], tabOrder: [...] }`

### PUT `/teams/{teamId}/layout`
Save the current user's widget grid layout and tab order.
**Auth:** Team member.
**Body:** `{ layout: [...], tabOrder: [...] }`

---

## Seen / unread

### POST `/teams/{teamId}/seen`
Mark the team as seen by the current user (clears the unread indicator).
**Auth:** Authenticated.

---

## Search

### GET `/users/search`
Search Nextcloud users, groups, and teams for the invite member picker.
**Auth:** Authenticated.
**Query:** `?q=searchterm&teamId=` — `q` minimum 2 characters; optional `teamId` excludes the current team and prevents circular nesting in results.
**Response:** `[ { id, displayName, type, icon } ]` — type: `user` | `group` | `circle` | `email` | `federated`
**Note:** `circle` results only returned when the `circle` invite type is enabled in admin settings, and only for teams that have NOT checked "Prevent this team from being a member of another team" (CFG_ROOT not set).

---

## Repair

### GET `/debug/repair-membership/{teamId}`
Re-insert or repair the current user's member row in a circle that exists in DB but is invisible to the Circles API. Emergency recovery tool — operates only on the current user's own membership row.
**Auth:** Authenticated.

---

## Integrations — team render

### GET `/teams/{teamId}/integrations`
Get all enabled integrations for a team, split by type.
**Auth:** Team member.
**Response:** `{ widgets: [...], menu_items: [...] }`

### GET `/teams/{teamId}/integrations/widget-data/{registryId}`
Fetch widget data by calling `ITeamHubWidget::getWidgetData()` in-process via NC's DI container. No HTTP call is made.
**Auth:** Team member.
**Response:**
```json
{
  "items": [
    { "label": "string", "value": "string", "icon": "MDI name?", "url": "string?" }
  ],
  "actions": [
    { "label": "string", "icon": "MDI name?", "actionId": "string?", "url": "string?" }
  ]
}
```
`items` is required (may be empty). `actions` is optional — renders as a 3-dot menu in the widget card header.

### GET `/teams/{teamId}/integrations/action-form/{registryId}?actionId=xxx`
Get the form definition for a named widget action.
**Auth:** Team member.
**Response:** `{ title?, submit_label?, fields: [...] }`

### POST `/teams/{teamId}/integrations/action-submit/{registryId}`
Submit a completed action form.
**Auth:** Team member.
**Body:** `{ actionId: string, fields: { ... } }`
**Response:** `{ success: bool, message?: string, refresh?: bool }`

---

## Integrations — team management

### GET `/teams/{teamId}/integrations/registry`
List all registered integrations with their enabled state for this team.
**Auth:** Team member.

### POST `/teams/{teamId}/integrations/{registryId}/toggle`
Enable or disable a single integration for this team.
**Auth:** Team admin.
**Body:** `{ enable: bool }`

### PUT `/teams/{teamId}/integrations/reorder`
Reorder enabled integrations.
**Auth:** Team admin.
**Body:** `{ order: [registryId, ...] }`

---

## Integrations — external app registration (NC admin only)

### GET `/ext/integrations`
List all registered integrations (built-ins + external).
**Auth:** NC admin.

### POST `/ext/integrations/register`
Register or update an external app integration. One call = one `integration_type`. Call twice to register both `widget` and `menu_item`.
**Auth:** NC admin, or in-process PHP with `calledInProcess: true`.
**Body:**
```json
{
  "app_id": "myapp",
  "integration_type": "widget",
  "title": "string",
  "description": "string?",
  "icon": "MDI icon name?",
  "php_class": "OCA\\MyApp\\Integration\\TeamHubWidget",
  "iframe_url": null
}
```
`php_class` and `iframe_url` are mutually exclusive. `php_class` required for `widget`; `iframe_url` required for `menu_item`.

### DELETE `/ext/integrations/{appId}`
Deregister all integrations for an external app. Cascade-deletes all per-team opt-ins.
**Auth:** NC admin.

---

## Admin settings

### GET `/admin/settings`
Get all admin configuration values.
**Auth:** NC admin (framework blocks non-admins — no `#[NoAdminRequired]`).

### PUT `/admin/settings`
Save admin configuration.
**Auth:** NC admin (framework-level + service-level `isAdmin()` double-check).
**Body:** `{ wizardDescription?, createTeamGroup?, inviteTypes?, pinMinLevel? }`

### GET `/admin/groups/search`
Search NC groups for the admin group picker.
**Auth:** NC admin.
**Query:** `?q=searchterm`
**Response:** `[ { id, displayName } ]`

---

## Notes for external app developers

See `developers.md` for the full integration guide. Key points:

- Register from `Application::boot()` using `calledInProcess: true`.
- Widget implementations must return `{ items: [...], actions?: [...] }` — the root key is always `items`.
- `getWidgetData()` is called in-process via NC's DI container — no HTTP involved at any layer.
- `iframe_url` accepts relative NC paths (`/apps/...`) or absolute `https://` URLs. TeamHub appends `?teamId=<uuid>` when loading the iframe.
- `php_class` and `iframe_url` are mutually exclusive within a single registration call.

---

## Maintenance (NC admin only)

### GET `/admin/maintenance/teams`
Paginated list of all real user-created teams on this NC instance.
**Auth:** NC admin.
**Query params:**
- `search` (string, default `''`) — substring filter on team display name
- `page` (int, default `1`) — 1-based page number
- `per_page` (int: 10|20|50|100, default `20`) — rows per page
- `orphans_only` (int: 0|1, default `0`) — when 1, only return teams with no owner
**Response:**
```json
{
  "total": 42,
  "page": 1,
  "per_page": 20,
  "teams": [
    {
      "id": "unique_id",
      "name": "Team display name",
      "description": "...",
      "member_count": 5,
      "owner": "uid or null",
      "owner_display_name": "Display Name or null",
      "creation": "2026-01-20 14:30:00"
    }
  ]
}
```

### GET `/admin/maintenance/orphaned-teams`
Legacy endpoint — returns teams with no owner. Kept for backward compat.
**Auth:** NC admin.

### DELETE `/admin/maintenance/orphaned-teams/{teamId}`
Delete any team (not just orphaned ones). Deletes all resources (Talk, Files, Calendar, Deck, IntraVox), all TeamHub DB rows, then destroys the Circles circle. Falls back to raw DB delete if CircleService::destroy() fails.
**Auth:** NC admin.

### POST `/admin/maintenance/orphaned-teams/{teamId}/assign-owner`
Assign a new owner to any team. Works for existing members and non-members. Demotes current owner to moderator first. Sends a Nextcloud notification to the new owner.
**Auth:** NC admin.
**Body:** `userId=<uid>` (form-encoded)
**Response:** `{ success: true }`

### GET `/admin/users/search`
Search NC users for the owner picker.
**Auth:** NC admin.
**Query:** `?q=searchterm`
**Response:** `[ { uid, displayName } ]`

### GET `/admin/maintenance/membership-check`
Scan all user-created teams and compare `circles_member` active member count against `circles_membership` cache row count. Returns a list of mismatched teams — these teams will be invisible to share pickers (Files, Calendar, Deck).
**Auth:** NC admin.
**Response:** `{ total_teams, healthy, mismatched, issues: [ { id, name, member_count, membership_count } ] }`

### POST `/admin/maintenance/membership-repair/{teamId}`
Rebuild the `circles_membership` cache for a single team. Equivalent to `occ circles:memberships --force <teamId>`. Should be called after `membership-check` identifies a mismatch.
**Auth:** NC admin.
**Response:** `{ success: true }`

### GET `/admin/maintenance/ghost-members`
Scan all team memberships (`user_type=1, status=Member`) for users whose NC account no longer exists. Results are grouped by uid, each entry listing the teams the ghost appears in. Capped at 200 results.
**Auth:** NC admin.
**Query params:** `search` (string, optional) — substring filter on uid.
**Response:** `{ ghosts: [ { userId, displayName, teams: [ { teamId, teamName } ] } ], total: int }`

### DELETE `/admin/maintenance/ghost-members/{userId}`
Remove a deleted user from team memberships. Only removes `user_type=1` (direct user) rows; group and sub-team rows are unaffected. Refuses with 400 if the user account still exists (live-account safety guard).
**Auth:** NC admin.
**Body (JSON, optional):** `{ teamId: string }` — if provided, removes from that team only; if omitted, removes from all teams.
**Response:** `{ removed: int }` — number of `circles_member` rows deleted.

### DELETE `/admin/maintenance/nested-team`
Remove a nested-team row from `circles_member` (a user-created team invited into another team).
**Auth:** NC admin.
**Body (JSON):** `{ parentTeamId: string, childTeamId: string }`
**Response:** `{ success: true }`

### POST `/admin/maintenance/clear-cfg-single/{teamId}`
Clear the CFG_SINGLE (1024) bit from a user-created team's config. Restores Circles API visibility.
**Auth:** NC admin.
**Response:** `{ success: true }`

### POST `/admin/maintenance/repair-duplicate-member/{teamId}`
Remove duplicate `circles_member` rows for the same user_id. Keeps the highest-level row (owner survives).
**Auth:** NC admin.
**Body (JSON):** `{ userId: string }`
**Response:** `{ success: true, removed: int }`

### POST `/admin/maintenance/assign-owner/{teamId}`
Assign an owner to a team with no level=9 member row. Promotes the highest-level existing member, or inserts the calling NC admin if the team is empty.
**Auth:** NC admin.
**Response:** `{ success: true, newOwner: string }` — uid of the new owner.

### POST `/admin/maintenance/fix-display-name/{teamId}`
Fix a circle's `display_name` to match `sanitized_name`. Corrects misclassification by Circles when display_name was set to the owner's name instead of the team name.
**Auth:** NC admin.
**Response:** `{ success: true, newName: string }`

### POST `/admin/maintenance/reset-team-config/{teamId}`
Reset a team's user-managed config bits AND all forbidden system bits to clean defaults. Use when a team's `circles_circle.config` is corrupted (e.g. by an external tool, or by TeamHub <= 3.39.0 before the bit-encoding fix). Writes an audit log entry (`team.config_reset`). Bursts Circles' APCu cache.
**Auth:** NC admin.
**Response:** `{ oldConfig: int, newConfig: int }`

### GET `/admin/maintenance/config-check`
Scan every user-created team (`source=16`) for forbidden system bits in `circles_circle.config` (`CFG_SINGLE`, `CFG_SYSTEM`, `CFG_NO_OWNER`, `CFG_HIDDEN`, `CFG_BACKEND`, `CFG_APP`). Returns one entry per affected team. The admin can call `reset-team-config` per-team to repair.
**Auth:** NC admin.
**Response:** `{ issues: [{ id: string, name: string, config: int, badBits: int }] }`

### GET `/admin/telemetry`
Current telemetry settings and payload preview.
**Auth:** NC admin.
**Response:** `{ enabled: bool, report_url: string, preview: object }`

The `preview` object reflects the exact JSON payload (minus the per-call `event` field) that will be POSTed to the remote receiver on installed/daily/uninstalled events. Shape:

```
{
  uuid:                 string (anonymous v4 UUID),
  app_version:          string (e.g. "3.8.0"),
  nc_version:           string (e.g. "32.0.4.1"),
  team_count:           int,
  user_count:           int,      // total NC users across all backends
  member_total:         int,      // sum of team memberships (not unique users)
  message_count:        int,      // total rows in teamhub_messages
  integrations:         string[], // non-builtin registered integration app IDs
  builtin_integrations: object,   // { appId: teamCount } for teams that have enabled each builtin app
  link_domains:         object    // { domain: count } for custom web links, aggregated by bare hostname
}
```

Privacy: no URLs, no IDs, no content, no hostnames/instance URLs are included. Link domains have scheme, path, query, port, fragment, localhost, and numeric IPs stripped before aggregation.

### PUT `/admin/telemetry`
Enable or disable daily usage reporting.
**Auth:** NC admin.
**Body:** `enabled=1|0` (form-encoded)

---

## Team Meeting Action

### POST `/api/v1/teams/{teamId}/meetings`
Execute the full team meeting workflow: create meeting notes file, calendar event, and optional Talk integration.
**Auth:** Team member at `meeting_min_level` or above (default: any member).
**Body (JSON):**
| Field | Type | Required | Description |
|---|---|---|---|
| `title` | string | yes | Meeting title |
| `date` | string | yes | Date in `YYYY-MM-DD` format |
| `startTime` | string | yes | Start time in `HH:MM` format |
| `endTime` | string | yes | End time in `HH:MM` format |
| `location` | string | no | Optional physical location |
| `filename` | string | no | Base filename for the notes `.md` file (defaults to title) |
| `includeTalk` | int | no | `1` to link the team Talk room (default `1`) |
| `talkToken` | string | no | Talk room token from frontend resources (avoids extra DB lookup) |
| `askAgenda` | int | no | `1` to post an agenda request message in Talk (default `0`) |

**Response 201:**
```json
{ "notesUrl": "string", "talkUrl": "string|null", "calendarEventCreated": true }
```

### GET `/api/v1/teams/{teamId}/meetings/settings`
Get the `meeting_min_level` for this team.
**Auth:** Team admin.
**Response 200:** `{ "minLevel": 1|4|8 }`

### PUT `/api/v1/teams/{teamId}/meetings/settings`
Save the `meeting_min_level` for this team.
**Auth:** Team admin.
**Body:** `{ "minLevel": 1|4|8 }`
**Response 200:** `{ "minLevel": 1|4|8 }`

---

## Audit log (NC admin only)

All audit endpoints require an NC server administrator session, enforced via the
`#[AuthorizedAdminSetting]` controller attribute. The audit log can contain
user-identifying data (uids, file paths, group names) — exposing any of it to
non-admins would be a privacy violation.

### GET `/admin/audit/teams`
Summary list of teams that have at least one audit row.
**Auth:** NC admin.
**Response 200:**
```
{
  "teams": [
    { "team_id": "...", "display_name": "...", "event_count": N, "last_event_at": <unix-seconds> },
    ...
  ],
  "activity_missing": false
}
```
Sorted by `last_event_at` descending. `activity_missing` is `true` when the NC
Activity app is disabled — used by the frontend to render a warning banner.

### GET `/admin/audit/teams/{teamId}/events`
Paginated audit rows for a single team.
**Auth:** NC admin.
**Query params:**
| name | type | default | notes |
|---|---|---|---|
| `page` | int | 1 | 1-based |
| `perPage` | int | 50 | Capped at 200 |
| `eventTypes` | string | `""` | Comma-separated list. Empty = all |
| `from` | int | 0 | Unix seconds, inclusive lower bound. 0 = no filter |
| `to` | int | 0 | Unix seconds, inclusive upper bound. 0 = no filter |

**Response 200:**
```
{
  "rows": [
    {
      "id": 12345,
      "team_id": "...",
      "event_type": "member.joined",
      "actor_uid": "alice",
      "target_type": "member",
      "target_id": "bob",
      "metadata": { ... or null ... },
      "created_at": 1714305600
    },
    ...
  ],
  "total": 412,
  "page": 1,
  "per_page": 50,
  "total_pages": 9
}
```

### GET `/admin/audit/teams/{teamId}/export`
Stream a ZIP archive containing the team's full audit history.
**Auth:** NC admin.
**Response 200:** `application/zip`. Contents:
- `team-info.json` — `{ team_id, display_name, exported_at, event_count }`
- `events.json` — full rows array, ordered ASC by `created_at`, pretty-printed.

Filename: `teamhub-audit-{slug}-{YYYY-MM-DD}.zip` via `Content-Disposition`. Built with PHP `ZipArchive`.

### GET `/admin/audit/retention`
Current retention policy.
**Auth:** NC admin.
**Response 200:** `{ "retention_days": 90, "min": 7, "max": 3650, "default": 90 }`

### PUT `/admin/audit/retention`
Save the retention policy. Mirror job applies it on the next cycle.
**Auth:** NC admin.
**Body:** `{ "retentionDays": 30 }`
**Response 200:** `{ "retention_days": 30 }`
**Response 400:** `retentionDays must be between 7 and 3650`

### Event types written to the audit log

| Event | Source |
|---|---|
| `team.created` | Direct from `TeamService::createTeam` |
| `team.deleted` | Direct from `TeamService::deleteTeam`; also from oc_activity `circle_delete` (deduped) |
| `team.config_changed` | Direct from `TeamService::updateTeamDescription` and `updateTeamConfig` |
| `team.owner_transferred` | Direct from `MaintenanceService::assignOwner` |
| `team.app_enabled` / `team.app_disabled` | Direct from `TeamService::updateTeamApps` (per-app, only on transition) |
| `member.joined` | oc_activity `member_added` and `member_circle_added`; also direct on open-circle self-join |
| `member.left` | oc_activity `member_left` |
| `member.removed` | oc_activity `member_remove` |
| `member.level_changed` | oc_activity `member_level` |
| `invite.sent` | oc_activity `member_invited` |
| `join.requested` / `join.approved` / `join.rejected` | Direct from `MemberService` |
| `file.created` | oc_activity `created_self`, `created_by`, `created_public` (path matched against team folders) |
| `file.edited` | oc_activity `changed_self`, `changed_by` |
| `file.deleted` | oc_activity `deleted_self`, `deleted_by` |
| `share.created` / `share.permissions_changed` / `share.deleted` | Snapshot diff against `oc_share` |

---

## Presence module — admin (added v3.42.0)

> v3.42.0 adds 18 admin endpoints under `/api/v1/admin/presence/*`. All require `#[AuthorizedAdminSetting(settings: AdminSettings::class)]` — either NC admin or a TeamHub-delegated admin group can call them. GET endpoints carry `#[NoCSRFRequired]`; all writes require CSRF.

**Error mapping shared by every endpoint in this section:**

| HTTP | When | Body |
|---|---|---|
| 200 / 201 | Success | Resource payload |
| 400 | `InvalidArgumentException` (validation failure) | `{ "error": "..." }` |
| 404 | `DoesNotExistException` | `{ "error": "..." }` |
| 409 | `PresenceConflictException` (built-in delete, in-use delete, duplicate holiday, …) | `{ "error": "...", "affectedCount": N }` — `affectedCount` is present when the conflict relates to in-use rows |
| 500 | Unexpected | `{ "error": "..." }` |

### Status types — `/admin/presence/types`

#### GET `/admin/presence/types`
List all presence types, sorted by `sort_order` ascending then `label` ascending.

**Auth:** NC admin or delegated admin.
**Response 200:** Array of type rows:
```json
[
  {
    "id": 1,
    "slug": "office",
    "label": "Office",
    "icon": "OfficeBuilding",
    "color": "#1976D2",
    "requires_location": true,
    "is_busy": true,
    "selectable_by_user": true,
    "is_builtin": true,
    "sort_order": 10,
    "created_at": 1747600000
  }
]
```

#### POST `/admin/presence/types`
Create a custom (`is_builtin=false`) presence type. Slug is generated server-side as `custom_<label-slug>[_n]`.

**Body:** `{ label, icon?, color?, requires_location?, is_busy?, selectable_by_user?, sort_order? }`
- `label` required, ≤128 chars.
- `icon` optional, `[A-Za-z0-9-]{1,64}`.
- `color` optional, `#RGB` or `#RRGGBB`.
- Numeric booleans accepted as 0/1.
**Response 201:** The created row.

#### PUT `/admin/presence/types/{id}`
Partial update. Built-ins accept only `label`, `icon`, `color`, `sort_order`. Custom types accept everything except `slug` and `is_builtin`. Unknown/rejected fields are silently ignored and logged.

#### DELETE `/admin/presence/types/{id}`
Delete a custom type. **409** if built-in, or if any template or slot row references the type (with `affectedCount`).

### Locations — `/admin/presence/{locations,buildings,floors,rooms}`

#### GET `/admin/presence/locations`
Return the full nested tree.

**Response 200:**
```json
[
  {
    "id": 1, "name": "HQ", "address": "Amsterdam", "sort_order": 0, "created_at": ...,
    "floors": [
      { "id": 1, "building_id": 1, "name": "3rd floor", "sort_order": 0, "created_at": ...,
        "rooms": [
          { "id": 7, "floor_id": 1, "name": "Conf A", "sort_order": 0, "created_at": ... }
        ]
      }
    ]
  }
]
```
Three queries total regardless of tree size — bucketed in PHP.

#### POST `/admin/presence/buildings`
**Body:** `{ name, address?, sort_order? }`. `name` ≤255 chars, `address` ≤255 chars or null.

#### PUT `/admin/presence/buildings/{id}`
Partial update; same fields as POST.

#### DELETE `/admin/presence/buildings/{id}`
Cascading delete: removes every room in the subtree, then every floor, then the building. **409 with `affectedCount`** if any room in the subtree is referenced by an active template or slot row.

#### POST `/admin/presence/floors`
**Body:** `{ building_id, name, sort_order? }`. **404** if `building_id` does not exist.

#### PUT `/admin/presence/floors/{id}`
Partial update of `name` and `sort_order`. `building_id` is intentionally **non-updatable** — the field is logged-ignored if present (moves not supported; delete+recreate instead).

#### DELETE `/admin/presence/floors/{id}`
Cascading delete: removes every room on the floor, then the floor. **409 with `affectedCount`** if any room is referenced.

#### POST `/admin/presence/rooms`
**Body:** `{ floor_id, name, sort_order? }`. **404** if `floor_id` does not exist.

#### PUT `/admin/presence/rooms/{id}`
Partial update of `name` and `sort_order`. `floor_id` is non-updatable (same rationale as floor's `building_id`).

#### DELETE `/admin/presence/rooms/{id}`
**409 with `affectedCount`** if any template or slot row references the room.

### Holidays — `/admin/presence/holidays`

#### GET `/admin/presence/holidays?year=YYYY`
List holidays, optionally filtered to a single year.

**Response 200:**
```json
[
  { "id": 1, "holiday_date": "2026-04-27", "name": "King's Day", "created_at": ... }
]
```

#### POST `/admin/presence/holidays/preview`
First half of the two-step add flow — drives the "this will overwrite N entries" confirmation.

**Body:** `{ date: "YYYY-MM-DD" }`. Strict ISO regex + `checkdate()` validation. **400** on invalid date.
**Response 200:** `{ "affectedSlots": N }`. No state mutation.

#### POST `/admin/presence/holidays`
Second half of the two-step add flow. Inserts the holiday row and overwrites every slot on that date with `presence_type=holiday`, `source='holiday'`, `location_room_id=null`. The `calendar_event_uid` column is preserved on overwrite so B4's calendar propagation can re-point existing VEVENTs.

**Body:** `{ date: "YYYY-MM-DD", name: "..." }`. Name ≤128 chars, required.
**Response 201:** `{ holiday: { ... }, affectedSlots: N }`.
**409** if a holiday already exists for this date.

#### DELETE `/admin/presence/holidays/{id}`
Deletes the holiday row and invokes `PresenceTemplateService::recomputeSlotsForDate()` to revert every `source='holiday'` slot on that date back to its template-driven value.

> **B1 note:** `recomputeSlotsForDate()` is a no-op stub in v3.42.0 (logs only). The real implementation lands in v3.43.0 (Session B2) alongside the week-template editor. Callers do not change when B2 lands.

---

## Presence module — user (added v3.43.0)

> v3.43.0 adds 4 user-facing presence endpoints. All require `#[NoAdminRequired]` (authenticated NC session). Data is always scoped to the calling user — no userId parameter is accepted. GET endpoints carry `#[NoCSRFRequired]`; writes require CSRF.

**Error mapping:** identical to the admin section (400/404/409/500). 409 is returned when trying to override a `source='holiday'` slot.

### GET `/presence/template`
Return the current user's week template as 14 cells (all combinations of day_of_week 0–6 × half_day 0–1, including unset cells with `presence_type_id=null`).

**Response 200:**
```json
[
  { "day_of_week": 0, "half_day": 0, "presence_type_id": 1, "location_room_id": 7, "updated_at": 1747600000 },
  { "day_of_week": 0, "half_day": 1, "presence_type_id": null, "location_room_id": null, "updated_at": null }
]
```

### PUT `/presence/template/cell`
Upsert one template cell. Pass `presence_type_id=null` to clear the cell. Triggers immediate re-materialisation of `source='template'` slots for the rolling window (today → end of next year).

**Body:** `{ day_of_week: 0–6, half_day: 0|1, presence_type_id: int|null, location_room_id: int|null }`

**Response 200:** The saved cell in the same shape as the GET above.

### GET `/presence/slots?from=YYYY-MM-DD&to=YYYY-MM-DD`
Return the current user's materialised slots in the given date range. Enriched with type metadata.

**Response 200:**
```json
[
  {
    "id": 42, "slot_date": "2026-05-19", "half_day": 0,
    "half_day_label": "Morning",
    "presence_type_id": 1, "presence_type_slug": "office",
    "presence_type_label": "Office", "presence_type_icon": "OfficeBuilding",
    "presence_type_color": "#1976D2", "requires_location": true,
    "is_locked": false, "location_room_id": 7, "source": "template",
    "updated_at": 1747600000
  }
]
```
`is_locked: true` when `source='holiday'` — the frontend should render these as non-editable.

### PUT `/presence/slots/override`
Override a single slot. Changes `source` to `'override'`. Returns **409** if the slot is `source='holiday'`.

**Body:** `{ slot_date: "YYYY-MM-DD", half_day: 0|1, presence_type_id: int|null, location_room_id: int|null }`

**Response 200:** Full enriched slot row (same shape as GET above).

---

## Presence module — team (added v3.44.0)

> v3.44.0 adds 3 team-facing presence endpoints. All require an authenticated NC session (`#[NoAdminRequired]`) and team membership (`requireMemberLevel`). The config write additionally requires team admin (`requireAdminLevel`, level ≥ 8). GET endpoints carry `#[NoCSRFRequired]`.

**Error mapping:** 400 bad input, 403 not a member / insufficient level, 500 unexpected.

### GET `/teams/{teamId}/presence?from=YYYY-MM-DD&to=YYYY-MM-DD`
Return the team presence grid for the given date range.

Privacy filter (`hide_reasons`) is applied **server-side**. Members never see raw slot data when `hide_reasons=true`.

**Response 200:**
```json
{
  "members": [{ "userId": "alice", "displayName": "Alice" }],
  "slots": {
    "alice": {
      "2026-05-19_0": {
        "color": "#1976D2",
        "label": "Office",
        "icon": "OfficeBuilding",
        "slug": "office",
        "requires_location": true,
        "location_room_id": 7,
        "source": "template",
        "is_locked": false
      }
    }
  },
  "hide_reasons": false,
  "from": "2026-05-19",
  "to": "2026-05-25"
}
```

When `hide_reasons=true`, each cell is `{ color: "#EF5350"|"#66BB6A"|"#BDBDBD", label: null, icon: null, slug: null, requires_location: false, location_room_id: null, source: null, is_locked: false }`. Absent keys in `slots[userId]` mean no slot on that date/half.

### GET `/teams/{teamId}/presence/config`
Return per-team presence config.

**Response 200:** `{ "presence_enabled": false, "hide_reasons": false }`

### PUT `/teams/{teamId}/presence/config`
Write one or both config flags. **Team admin only.** Only keys present in the body are changed.

**Body:** `{ presence_enabled?: 0|1, hide_reasons?: 0|1 }`

**Response 200:** Updated config `{ presence_enabled: bool, hide_reasons: bool }`.
