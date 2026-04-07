# TeamHub API Endpoints — v2.42

All endpoints are prefixed with `/apps/teamhub/api/v1`.
All endpoints require an authenticated Nextcloud session unless noted.
CSRF protection is disabled (`#[NoCSRFRequired]`) on all endpoints — authentication is via NC session cookie.

---

## Teams

### GET `/teams`
List all teams the current user is a member of.
**Auth:** Any authenticated user.
**Response:** `[ { id, name, description, memberCount, level } ]`

### POST `/teams`
Create a new team.
**Auth:** Any user allowed by `createTeamGroup` admin setting (empty = everyone).
**Body:** `{ name, description? }`
**Response:** `{ id, name, description }`

### GET `/teams/{teamId}`
Get details for a single team.
**Auth:** Team member (Circles session verifies membership).
**Response:** `{ id, name, description, config }`

### PUT `/teams/{teamId}`
Update team description and/or config flags.
**Auth:** Team admin/owner (level >= 8). ← enforced v2.42
**Body:** `{ description?, config? }`

### DELETE `/teams/{teamId}`
Delete a team and all its resources.
**Auth:** Team owner (level 9).

### GET `/teams/{teamId}/members`
List all members of a team.
**Auth:** Team member.
**Response:** `[ { userId, displayName, level, role } ]`

### PUT `/teams/{teamId}/members/{userId}/level`
Change a member's role.
**Auth:** Team admin/owner. Cannot demote someone at or above your own level.
**Body:** `{ level: 1|4|8 }`

### DELETE `/teams/{teamId}/members/{userId}`
Remove a member from the team.
**Auth:** Team admin/owner.

### POST `/teams/{teamId}/invite-members`
Invite users/groups to the team.
**Auth:** Team moderator/admin/owner (level >= 4). Enforced in both controller and service.
**Body:** `{ members: [ { id, type } ] }` — type: `user` | `group` | `email` | `federated`
**Response:** `{ invited: [], failed: [] }`

### GET `/teams/{teamId}/resources`
Get provisioned resources (Talk token, Files path, Calendar id, Deck board id).
**Auth:** Team member. ← membership check enforced v2.42
**Response:** `{ talk: { token }, files: { path }, calendar: { id }, deck: { board_id } }`

### POST `/teams/{teamId}/resources`
Provision resources for enabled team apps.
**Auth:** Team admin/owner. ← enforced v2.42
**Body:** `{ apps: ['spreed','files','calendar','deck'], teamName: string }`

### DELETE `/teams/{teamId}/resources/{app}`
Delete resources for a specific app. `app` is allowlisted.
**Auth:** Team admin/owner.

### PUT `/teams/{teamId}/description`
Update team description.
**Auth:** Team admin/owner. ← enforced v2.42

### PUT `/teams/{teamId}/config`
Update team configuration flags.
**Auth:** Team admin/owner.

---

## Messages

### GET `/teams/{teamId}/messages`
Get team messages and pinned message.
**Auth:** Team member.
**Response:** `{ pinned: object|null, messages: [] }`

### POST `/teams/{teamId}/messages`
Post a new message.
**Auth:** Team member.
**Body:** `{ message: string }`

### DELETE `/teams/{teamId}/messages/{messageId}`
Delete a message.
**Auth:** Message author OR team admin/owner.

### POST `/teams/{teamId}/messages/{messageId}/pin`
Pin a message.
**Auth:** Team admin/owner per `pinMinLevel` admin setting.

### DELETE `/teams/{teamId}/messages/{messageId}/unpin`
Unpin a message.
**Auth:** Team admin/owner per `pinMinLevel` admin setting.

### GET `/teams/{teamId}/messages/{messageId}/comments`
Get comments on a message.
**Auth:** Team member.

### POST `/teams/{teamId}/messages/{messageId}/comments`
Post a comment.
**Auth:** Team member.

### DELETE `/teams/{teamId}/messages/{messageId}/comments/{commentId}`
Delete a comment.
**Auth:** Comment author OR team admin/owner.

---

## Web Links (custom tab bar links)

### GET `/teams/{teamId}/links`
List all web links for the team.
**Auth:** Team member.

### POST `/teams/{teamId}/links`
Create a web link.
**Auth:** Team admin/owner.
**Body:** `{ label: string, url: string }`

### PUT `/teams/{teamId}/links/{linkId}`
Update a web link.
**Auth:** Team admin/owner.

### DELETE `/teams/{teamId}/links/{linkId}`
Delete a web link.
**Auth:** Team admin/owner.

### PUT `/teams/{teamId}/links/reorder`
Reorder web links.
**Auth:** Team admin/owner.
**Body:** `{ ordered_ids: [int] }`

---

## Layout

### GET `/teams/{teamId}/layout`
Get the current user's grid layout and tab order for a team.
**Auth:** Team member. ← membership check enforced v2.42
**Response:** `{ layout_json: [...], tab_order: [...] }`

### PUT `/teams/{teamId}/layout`
Save the current user's grid layout and tab order.
**Auth:** Team member. ← membership check enforced v2.42
**Body:** `{ layout: [...], tabOrder: [...] }`

---

## Integrations — Team management

### GET `/teams/{teamId}/integrations/registry`
List all integrations with their enabled state for a team.
**Auth:** Team admin/owner.
**Response:** `[ { registry_id, app_id, integration_type, title, icon, php_class, iframe_url, is_builtin, enabled } ]`

### POST `/teams/{teamId}/integrations/{registryId}/toggle`
Enable or disable an integration for a team.
**Auth:** Team admin/owner.
**Body:** `{ enable: bool }`

### PUT `/teams/{teamId}/integrations/reorder`
Reorder enabled integrations for a team.
**Auth:** Team admin/owner.
**Body:** `{ ordered_ids: [int] }`

---

## Integrations — Render endpoints

### GET `/teams/{teamId}/integrations`
Get all enabled integrations split by type. Called on team select.
**Auth:** Team member.
**Response:** `{ widgets: [...], menu_items: [...] }`

### GET `/teams/{teamId}/integrations/widget-data/{registryId}`
Fetch widget data by calling the registered `ITeamHubWidget::getWidgetData()` implementation
directly in-process via NC's DI container. No HTTP call is made.
**Auth:** Team member (widget must be enabled for the team).
**Response:**
```json
{
  "items": [ { "label": "string", "value": "string", "icon": "MDI name?", "url": "string?" } ],
  "actions": [
    { "label": "string", "icon": "MDI name?", "url": "string (relative NC path or https://)" }
  ]
}
```
`items` is required (may be empty). `actions` is optional — when present, TeamHub renders a 3-dot menu
in the widget card header. Action URLs open in a new browser tab (`window.open`, `noopener,noreferrer`).
Actions are capped at 10 entries; URLs must be a relative NC path or `https://`.

---

## Integrations — External app registration (NC admin required)

### GET `/ext/integrations`
List all registered integrations (built-ins + external).
**Auth:** NC admin.

### POST `/ext/integrations/register`
Register or update an external app's integration.
**Auth:** NC admin (or in-process PHP with `calledInProcess: true`).
**Body:**
```json
{
  "app_id": "myapp",
  "integration_type": "widget|menu_item",
  "title": "string",
  "description": "string?",
  "icon": "MDI icon name?",
  "php_class": "OCA\\MyApp\\Integration\\TeamHubWidget",
  "iframe_url": "https://..."
}
```
Note: `php_class` is required for `widget` type. `iframe_url` is required for `menu_item` type.
These two fields are mutually exclusive. Preferred registration method is always in-process
from `Application::boot()` — see `developers.md`.

### DELETE `/ext/integrations/{appId}`
Deregister an external app's integration (cascades team opt-ins).
**Auth:** NC admin.

---

## Team Apps (built-in NC app toggles)

### GET `/teams/{teamId}/apps`
Get enabled/disabled state of built-in apps for a team.
**Auth:** Team member.
**Response:** `[ { app_id, enabled } ]`

### POST `/teams/{teamId}/apps/{appId}/toggle`
Enable or disable a built-in app for a team.
**Auth:** Team admin/owner.
**Body:** `{ enable: bool }`

---

## Activity

### GET `/teams/{teamId}/activity`
Get activity feed for a team.
**Auth:** Team member.
**Response:** `[ { id, type, subject, timestamp, user } ]`

---

## Search / Invite

### GET `/users/search`
Search Nextcloud users and groups for invite autocomplete.
**Auth:** Any authenticated user.
**Query:** `?q=searchterm`
**Response:** `[ { id, displayName, type } ]` — type: `user` | `group` | `email` | `federated`
**Note:** Uses `IUserManager::searchDisplayName()` + `search()` — DB-backed, limited. Does not iterate all users.

### GET `/invite-types`
Get allowed invite types from admin settings.
**Auth:** Any authenticated user.
**Response:** `{ types: ['user', 'group', ...] }`

---

## Admin settings

### GET `/admin/settings`
Get all admin configuration values.
**Auth:** NC admin (framework-level; no `#[NoAdminRequired]`).

### PUT `/admin/settings`
Save admin configuration.
**Auth:** NC admin (framework-level + service-level `isAdmin()` check).
**Body:** `{ wizardDescription?, createTeamGroup?, inviteTypes?, pinMinLevel? }`

---

## Notes for external app developers

- Register your integration in-process from your app's `Application::boot()` — see `developers.md`.
- Widget integrations must implement `OCA\TeamHub\Integration\ITeamHubWidget` and pass the
  fully-qualified class name as `php_class`. TeamHub resolves the class via NC's DI container
  and calls `getWidgetData(teamId, userId)` directly — no HTTP involved at any layer.
- `iframe_url` for `menu_item`: must be absolute `https://` — loaded in the browser in a sandboxed iframe.
- `php_class` and `iframe_url` are mutually exclusive.
- Widget `getWidgetData()` must return `{ items: [...], actions?: [...] }`. Actions populate the
  3-dot menu in the widget header. Action URLs open in a new tab via `window.open` — they are
  browser-navigation links, not server-side calls. Relative NC paths or `https://` only.
- See `developers.md` for the full integration guide, interface definition, and PHP examples.
