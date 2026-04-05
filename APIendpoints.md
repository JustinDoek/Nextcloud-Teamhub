# TeamHub API Endpoints — v2.37.0

All endpoints are prefixed with `/apps/teamhub/api/v1`.
All endpoints require an authenticated Nextcloud session (cookie or token).
All responses are JSON.

---

## Teams

### GET `/teams`
Returns all teams the current user is a member of.
**Response:** `[{ id, name, description, memberCount, level }]`

### POST `/teams`
Create a new team (Circle). Requires createTeamGroup permission.
**Body:** `{ name, description }`
**Response:** `{ id, name, description }`

### DELETE `/teams/{teamId}`
Delete a team. Requires team owner level.
**Response:** `{ success: true }`

### GET `/teams/{teamId}`
Get a single team's details.
**Response:** `{ id, name, description }`

### PUT `/teams/{teamId}/description`
Update team description. Requires team admin/owner.
**Body:** `{ description }`
**Response:** `{ success: true }`

### GET `/teams/{teamId}/config`
Get team Circle config bitmask.
**Response:** `{ config: int }`

### PUT `/teams/{teamId}/config`
Update team Circle config bitmask. **Requires team admin/owner.**
**Body:** `{ config: int }`
**Response:** `{ success: true, config: int }`

---

## Members

### GET `/teams/{teamId}/members`
Returns all members of the team.
**Response:** `[{ userId, displayName, level }]`

### POST `/teams/{teamId}/invite-members`
Invite a user or group to the team. Requires team moderator+.
**Body:** `{ type: 'user'|'group', id }`
**Response:** `{ success: true }`

### DELETE `/teams/{teamId}/members/{userId}`
Remove a member. Requires team moderator+.
**Response:** `{ success: true }`

### PUT `/teams/{teamId}/members/{userId}/level`
Change a member's level. Requires team admin/owner.
**Body:** `{ level: int }`
**Response:** `{ success: true }`

### GET `/teams/{teamId}/pending-requests`
List pending join requests. Requires team moderator+.
**Response:** `[{ userId, displayName }]`

### POST `/teams/{teamId}/approve/{userId}`
Approve a join request. Requires team moderator+.
**Response:** `{ success: true }`

### POST `/teams/{teamId}/reject/{userId}`
Reject a join request. Requires team moderator+.
**Response:** `{ success: true }`

---

## Resources

### GET `/teams/{teamId}/resources`
Returns all provisioned resources for the team (talk, files, calendar, deck).
**Response:** `{ talk: { token }|null, files: { path }|null, calendar: { public_token }|null, deck: { board_id }|null }`

### POST `/teams/{teamId}/create-resources`
Provision all app resources for the team. Requires team admin/owner.
**Response:** `{ success: true }`

### DELETE `/teams/{teamId}/resources/{app}`
Disable (hard delete) an app resource. `{app}` one of: `spreed`, `files`, `calendar`, `deck`, `intravox`. **Requires team admin/owner.**
**Response:** `{ success: true }`

---

## Apps

### GET `/teams/{teamId}/apps`
Returns enabled/disabled state of each app for the team.
**Response:** `[{ app_id, enabled }]`

### PUT `/teams/{teamId}/apps`
Enable or disable apps for the team. **Requires team admin/owner.**
**Body:** `{ apps: [{ app_id, enabled }] }`
**Response:** `{ success: true }`

---

## Messages

### GET `/teams/{teamId}/messages`
Returns pinned message and all messages.
**Response:** `{ pinned: object|null, messages: [] }`

### POST `/teams/{teamId}/messages`
Post a new message. Any team member.
**Body:** `{ subject, message, priority?, messageType?, pollOptions? }`
**Response:** `{ id, content, author, created_at }`

### PUT `/teams/{teamId}/messages/{messageId}`
Edit a message. Author only.
**Body:** `{ subject, message }`
**Response:** updated message object

### DELETE `/teams/{teamId}/messages/{messageId}`
Delete a message. **Author or team admin/owner.**
**Response:** `{ success: true }`

### POST `/teams/{teamId}/messages/{messageId}/pin`
Pin a message. Requires pinMinLevel (admin setting).
**Response:** `{ success: true }`

### POST `/teams/{teamId}/messages/{messageId}/unpin`
Unpin the currently pinned message.
**Response:** `{ success: true }`

### GET `/messages/aggregated`
Returns messages across all of the current user's teams.
**Response:** `[{ teamId, teamName, messages: [] }]`

---

## Polls

### POST `/messages/{messageId}/vote`
Vote on a poll option.
**Body:** `{ optionIndex: int }`
**Response:** updated poll state

### GET `/messages/{messageId}/poll-results`
Get poll results.
**Response:** `{ options: [], votes: [] }`

### POST `/messages/{messageId}/close-poll`
Close a poll. Author only.
**Response:** updated message object

### POST `/messages/{messageId}/mark-solved`
Mark a question message as solved.
**Body:** `{ commentId: int }`
**Response:** updated message object

### POST `/messages/{messageId}/unmark-solved`
Unmark a question as solved. Author only.
**Response:** updated message object

---

## Comments

### GET `/messages/{messageId}/comments`
Returns all comments on a message.
**Response:** `[{ id, content, author, created_at }]`

### POST `/messages/{messageId}/comments`
Post a comment. Any team member.
**Body:** `{ comment }`
**Response:** `{ id, content, author, created_at }`

### PUT `/comments/{commentId}`
Edit a comment. **Author only** (enforced via DB WHERE clause).
**Body:** `{ comment }`
**Response:** updated comment object

---

## Web Links

### GET `/teams/{teamId}/links`
Returns all web links for the team.
**Response:** `[{ id, title, url }]`

### POST `/teams/{teamId}/links`
Add a web link. **Requires team admin/owner.**
**Body:** `{ title, url }`
**Response:** `{ id, title, url }`

### PUT `/teams/{teamId}/links/{linkId}`
Update a web link. **Requires team admin/owner.**
**Body:** `{ title, url, sortOrder }`
**Response:** updated link object

### DELETE `/teams/{teamId}/links/{linkId}`
Remove a web link. **Requires team admin/owner.**
**Response:** `{ success: true }`

---

## Layout

### GET `/teams/{teamId}/layout`
Returns the saved grid layout and tab order for the current user + team.
If no saved layout exists, returns the server-side default.
**Response:** `{ layout: [...gridItems], tabOrder: [...keys] }`

Each grid item shape: `{ i, x, y, w, h, minW, minH, isResizable, collapsed, hSaved }`

### PUT `/teams/{teamId}/layout`
Save the current grid layout and tab order.
**Body:** `{ layout: [...gridItems], tabOrder: [...keys] }`
**Validation:** widget IDs allowlisted; tab keys allowlisted; numeric values clamped; payload limit 64 KB
**Response:** `{ success: true }`

---

## Integrations — external-app registration (NC admin only)

These three endpoints require the calling user to be a **Nextcloud admin**. No `#[NoAdminRequired]` — NC framework gate applies. Service layer also enforces this as defence-in-depth.

> ⚠️ Do not call these via HTTP from within PHP — NC blocks loopback. Use in-process service injection. See `developers.md`.

### GET `/ext/integrations`
Returns all registry entries (built-ins first, then external).
**Response:** `[{ registry_id, app_id, integration_type, title, description, icon, data_url, action_url, action_label, iframe_url, is_builtin, created_at }]`

### POST `/ext/integrations/register`
Register or update (upsert) an integration. `app_id` must match an installed NC app; built-in IDs rejected.

| Field | Type | Required | Notes |
|---|---|---|---|
| `app_id` | string | ✅ | Must match installed NC app |
| `integration_type` | string | ✅ | `widget` or `menu_item` |
| `title` | string | ✅ | Max 255 chars |
| `description` | string | — | Max 500 chars |
| `icon` | string | — | MDI icon name, max 64 chars |
| `data_url` | string | widget ✅ | Relative NC path or `https://` |
| `action_url` | string | — | Same URL rules as data_url |
| `action_label` | string | — | 3-dot menu label, max 64 chars |
| `iframe_url` | string | menu_item ✅ | Must be `https://` |

**Response:** Full registry row

### DELETE `/ext/integrations/{appId}`
Deregister an integration. Cascade-deletes all team opt-ins. Idempotent. Built-ins cannot be deregistered.
**Response:** `{ success: true }`

---

## Integrations — team render (any authenticated user)

### GET `/teams/{teamId}/integrations`
Returns enabled integrations split by type.
**Response:** `{ widgets: [...], menu_items: [...] }`

### GET `/teams/{teamId}/integrations/widget-data/{registryId}`
TeamHub fetches data from the external app's `data_url` server-side. Widget must be enabled for the team.
**Response:** `{ items: [{ label, value, icon?, url? }], error? }`

### GET `/teams/{teamId}/integrations/action/{registryId}`
Returns the action modal definition from the external app's `action_url`.
**Response:** `{ title, fields: [{ label, type, name, value? }], submit_label? }`

---

## Integrations — team management (team admin required)

### GET `/teams/{teamId}/integrations/registry`
Returns all integrations with their enabled state for this team.
**Response:** `[{ registry_id, app_id, integration_type, title, description, icon, data_url, action_url, action_label, iframe_url, is_builtin, enabled, sort_order }]`

### POST `/teams/{teamId}/integrations/{registryId}/toggle`
Enable or disable an integration for a team. **Requires team admin/owner.**
**Body:** `{ enable: true|false }`
**Response:** Updated full registry list.

### PUT `/teams/{teamId}/integrations/reorder`
Persist display order for a team's enabled integrations. **Requires team admin/owner.**
**Body:** `{ order: [3, 1, 2] }` — registry IDs in desired order.
**Response:** Updated enabled integration list.

---

## Activity

### GET `/teams/{teamId}/activity`
Returns recent activity for the team.
**Response:** `[{ id, type, subject, object_type, object_id, timestamp, author }]`

---

## User / Permissions

### GET `/user/can-create-team`
Returns whether the current user is allowed to create teams.
**Response:** `{ canCreate: bool }`

### GET `/invite-types`
Returns allowed invite types for the invite modal.
**Response:** `{ types: ['user', 'group', ...] }`

### GET `/users/search`
Search NC users. Used by invite modal.
**Response:** `[{ uid, displayName }]`

### GET `/apps/check`
Returns installed state of Talk, Calendar, Deck.
**Response:** `{ talk: bool, calendar: bool, deck: bool }`

### GET `/admin/settings`
Returns current admin settings. **Requires NC admin.**
**Response:** `{ wizardDescription, createTeamGroup, inviteTypes, pinMinLevel }`

### POST `/admin/settings`
Update admin settings. **Requires NC admin.**
**Body:** `{ wizardDescription?, createTeamGroup?, inviteTypes?, pinMinLevel? }`
**Response:** `{ success: true }`

### GET `/admin/groups/search`
Search NC groups for the createTeamGroup picker. **Requires NC admin.**
**Response:** `[{ id, displayName }]`
