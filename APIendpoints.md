# TeamHub API Endpoints — v3.23.0

> No endpoint surface changes between 3.17.0 and 3.18.0.
> v3.18.0 is a frontend-only release (iframe overhaul + Audit tab info banner).

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
**Response:** `{ activities: [ { id, type, subject, timestamp, user, icon, url } ] }`

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
Search Nextcloud users and groups for the invite member picker.
**Auth:** Authenticated.
**Query:** `?q=searchterm` (minimum 2 characters)
**Response:** `[ { id, displayName, type, icon } ]` — type: `user` | `group` | `email` | `federated`

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
