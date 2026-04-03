# TeamHub API Endpoints — v2.34.0

All endpoints are prefixed with `/apps/teamhub/api/v1`.
All endpoints require an authenticated Nextcloud session (cookie or token).
All responses are JSON.

---

## Teams

### GET `/teams`
Returns all teams the current user is a member of.
**Response:** `[{ id, name, description, memberCount, level }]`

### POST `/teams`
Create a new team (Circle).
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

---

## Members

### GET `/teams/{teamId}/members`
Returns all members of the team.
**Response:** `[{ userId, displayName, level }]`

### POST `/teams/{teamId}/members`
Invite a user or group to the team. Requires team moderator+.
**Body:** `{ type: 'user'|'group', id }`
**Response:** `{ success: true }`

### DELETE `/teams/{teamId}/members/{memberId}`
Remove a member. Requires team moderator+.
**Response:** `{ success: true }`

---

## Resources

### GET `/teams/{teamId}/resources`
Returns all provisioned resources for the team (talk, files, calendar, deck).
**Response:** `{ talk: { token }|null, files: { path }|null, calendar: { public_token }|null, deck: { board_id }|null }`

### POST `/teams/{teamId}/resources/{app}`
Enable an app resource for the team. `{app}` is one of: `spreed`, `files`, `calendar`, `deck`.
**Response:** `{ success: true }`

### DELETE `/teams/{teamId}/resources/{app}`
Disable (hard delete) an app resource. Requires team admin/owner.
**Response:** `{ success: true }`

---

## Messages

### GET `/teams/{teamId}/messages`
Returns pinned message and all messages.
**Response:** `{ pinned: object|null, messages: [] }`

### POST `/teams/{teamId}/messages`
Post a new message.
**Body:** `{ content }`
**Response:** `{ id, content, author, created_at }`

### DELETE `/teams/{teamId}/messages/{messageId}`
Delete a message. Author or team admin/owner only.
**Response:** `{ success: true }`

### POST `/teams/{teamId}/messages/{messageId}/pin`
Pin a message. Requires pinMinLevel (admin setting).
**Response:** `{ success: true }`

### DELETE `/teams/{teamId}/messages/{messageId}/pin`
Unpin the currently pinned message.
**Response:** `{ success: true }`

---

## Comments

### GET `/teams/{teamId}/messages/{messageId}/comments`
Returns all comments on a message.
**Response:** `[{ id, content, author, created_at }]`

### POST `/teams/{teamId}/messages/{messageId}/comments`
Post a comment.
**Body:** `{ content }`
**Response:** `{ id, content, author, created_at }`

### DELETE `/teams/{teamId}/messages/{messageId}/comments/{commentId}`
Delete a comment. Author or team admin/owner only.
**Response:** `{ success: true }`

---

## Web Links

### GET `/teams/{teamId}/links`
Returns all web links for the team.
**Response:** `[{ id, title, url }]`

### POST `/teams/{teamId}/links`
Add a web link. Requires team admin/owner.
**Body:** `{ title, url }`
**Response:** `{ id, title, url }`

### DELETE `/teams/{teamId}/links/{linkId}`
Remove a web link. Requires team admin/owner.
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
**Validation:**
- Widget IDs allowlisted (`msgstream`, `widget-teaminfo`, `widget-members`, `widget-calendar`, `widget-deck`, `widget-activity`, `widget-pages`, `widget-int-{int}`)
- Tab keys allowlisted (`home`, `talk`, `files`, `calendar`, `deck`, `ext-{int}`, `link-{int}`)
- Numeric values clamped (x/y ≥ 0, w 1–12, h 1–50)
- `collapsed` cast to bool, `hSaved` cast to int (clamped 1–50)
- Payload size limit: 64 KB combined
**Response:** `{ success: true }`

---

## Integrations

### GET `/integrations`
Returns all registered integrations with enabled state for the current team.
**Response:** `{ widgets: [...], menu_items: [...] }`

### POST `/integrations/register`
Register a new integration. Requires NC admin.
**Body:** `{ app_id, title, type: 'widget'|'menu_item', data_url?, action_url?, iframe_url?, icon? }`
**Response:** `{ id }`

### DELETE `/integrations/{registryId}`
Deregister an integration. Requires NC admin.
**Response:** `{ success: true }`

### POST `/teams/{teamId}/integrations/{registryId}/enable`
Enable an integration for a team. Requires team admin/owner.
**Response:** `{ success: true }`

### DELETE `/teams/{teamId}/integrations/{registryId}/enable`
Disable an integration for a team. Requires team admin/owner.
**Response:** `{ success: true }`

### GET `/teams/{teamId}/integrations/{registryId}/data`
Fetch widget data from the integration's data_url (server-side proxy). Never called client-side.
**Response:** Integration-defined JSON.

---

## Activity

### GET `/teams/{teamId}/activity`
Returns recent activity for the team.
**Response:** `[{ id, type, subject, object_type, object_id, timestamp, author }]`

---

## User / Permissions

### GET `/user/can-create-team`
Returns whether the current user is allowed to create teams (based on createTeamGroup admin setting).
**Response:** `{ canCreate: bool }`

### GET `/admin/settings`
Returns current admin settings. Requires NC admin.
**Response:** `{ wizardDescription, createTeamGroup, inviteTypes, pinMinLevel }`

### PUT `/admin/settings`
Update admin settings. Requires NC admin.
**Body:** `{ wizardDescription?, createTeamGroup?, inviteTypes?, pinMinLevel? }`
**Response:** `{ success: true }`
