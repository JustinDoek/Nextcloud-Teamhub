# TeamHub API Endpoints — v3.2.0

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
**Response:** `[ { id, name, description, members, unread, image_url } ]`

### POST `/teams`
Create a new team.
**Auth:** Authenticated. Subject to `createTeamGroup` admin setting (empty = everyone allowed).
**Body:** `{ name, description? }`
**Response:** `{ id, name, description, members, image_url }`

### GET `/teams/{teamId}`
Get details for a single team.
**Auth:** Authenticated (Circles API verifies membership via `getCircle()`).
**Response:** `{ id, name, description, members, image_url }`

### PUT `/teams/{teamId}`
Update team description and/or config bitmask.
**Auth:** Team admin (enforced in service when `description` or `config` is present in body).
**Body:** `{ description?, config? }`
**Response:** `{ success: true, id }`

### DELETE `/teams/{teamId}`
Delete a team. Does **not** delete provisioned resources (Talk room, Files folder, etc.).
**Auth:** Team owner.

### GET `/teams/{teamId}/members`
List all members of a team.
**Auth:** Team member.
**Response:** `[ { userId, displayName, level, role } ]`

### PUT `/teams/{teamId}/members/{userId}/level`
Change a member's role.
**Auth:** Team admin. Cannot change owner's level. Cannot promote to Admin unless caller is Owner.
**Body:** `{ level: 1|4|8 }`

### DELETE `/teams/{teamId}/members/{userId}`
Remove a member from the team. Cannot remove the owner.
**Auth:** Team admin.

### POST `/teams/{teamId}/invite-members`
Invite users/groups/emails/federated users to the team.
**Auth:** Team moderator (level >= 4).
**Body:** `{ members: [ { id, type } ] }` — type: `user` | `group` | `email` | `federated`
**Response:** `{ userId: 'invited'|'failed: ...' }`

### POST `/teams/{teamId}/leave`
Leave the team. Owner cannot leave if other members remain.
**Auth:** Team member.

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
Provision resources for the specified apps.
**Auth:** Team admin.
**Body:** `{ apps: ['talk','files','calendar','deck'], teamName: string }`
**Response:** Per-app results map. Each value has either resource details or `{ error: string }`.

### DELETE `/teams/{teamId}/resources/{app}`
Hard-delete the resource for a specific app. `app` is allowlisted: `spreed`, `files`, `calendar`, `deck`, `intravox`.
**Auth:** Team admin.

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

### DELETE `/teams/{teamId}/messages/{messageId}/comments/{commentId}`
Delete a comment.
**Auth:** Comment author OR team admin.

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
