# TeamHub API Endpoints — v2.26.0

All endpoints are prefixed with `/apps/teamhub` and require an authenticated Nextcloud session or app password unless noted otherwise.

Response format: JSON. Error responses always include an `error` string field.

---

## Teams

### List teams
`GET /api/v1/teams`

Returns all teams the current user is a member of.

**Response** `200`
```json
[
  { "id": "abc123", "name": "Engineering", "description": "...", "unread": false }
]
```

---

### Get a single team
`GET /api/v1/teams/{teamId}`

**Response** `200` — team object. `404` if not found.

---

### Create a team
`POST /api/v1/teams`

**Body**
| Field | Type | Required | Description |
|---|---|---|---|
| `name` | string | yes | Team display name |
| `description` | string | no | Short description |

**Response** `201` — created team object.

---

### Update a team
`PUT /api/v1/teams/{teamId}`

**Body** (one or both fields)
| Field | Type | Description |
|---|---|---|
| `description` | string | New description |
| `config` | int | Circles config bitmask (see Team Config) |

**Response** `200` `{ "success": true, "id": "..." }`

---

### Delete a team
`DELETE /api/v1/teams/{teamId}`

Permanently deletes the Circles group. Resources (files, calendar, chat) are **not** deleted.

**Response** `200` `{ "success": true }`. `403` if caller is not the owner.

---

### Browse all teams
`GET /api/v1/teams/browse`

Returns all visible teams on the instance (for the Discover view).

**Response** `200` — array of team objects.

---

## Team Configuration

### Get config
`GET /api/v1/teams/{teamId}/config`

Returns the raw Circles bitmask config for the team.

**Response** `200` `{ "config": 531 }`

---

### Update config
`PUT /api/v1/teams/{teamId}/config`

**Body**
| Field | Type | Required |
|---|---|---|
| `config` | int | yes |

**Response** `200` `{ "success": true, "config": 531 }`

---

### Update description
`PUT /api/v1/teams/{teamId}/description`

**Body** `{ "description": "..." }`

**Response** `200` `{ "success": true }`

---

## Team Apps

### Get team apps
`GET /api/v1/teams/{teamId}/apps`

Returns enabled app flags for the team (Talk, Files, Calendar, Deck).

**Response** `200` — app config object.

---

### Update team apps
`PUT /api/v1/teams/{teamId}/apps`

**Body** `{ "apps": ["talk", "files", "calendar", "deck"] }`

**Response** `200` `{ "success": true }`

---

## Members

### Get members
`GET /api/v1/teams/{teamId}/members`

**Response** `200`
```json
[
  { "userId": "alice", "displayName": "Alice", "level": 8 }
]
```

Circles member levels: `1` = Member, `4` = Moderator, `8` = Admin, `9` = Owner.

---

### Update member role
`PUT /api/v1/teams/{teamId}/members/{userId}/level`

**Body** `{ "level": 4 }`

**Response** `200` — updated full member list.

---

### Remove member
`DELETE /api/v1/teams/{teamId}/members/{userId}`

**Response** `200` `{ "success": true }`

---

### Invite members
`POST /api/v1/teams/{teamId}/invite-members`

**Body**
```json
{
  "members": [
    { "type": "user", "id": "alice" },
    { "type": "group", "id": "developers" },
    { "type": "email", "id": "bob@example.com" }
  ]
}
```

**Response** `200` — per-member result array.

---

### Get pending join requests
`GET /api/v1/teams/{teamId}/pending-requests`

**Response** `200` — array of user objects.

---

### Approve join request
`POST /api/v1/teams/{teamId}/approve/{userId}`

**Response** `200` `{ "success": true }`

---

### Reject join request
`POST /api/v1/teams/{teamId}/reject/{userId}`

**Response** `200` `{ "success": true }`

---

### Request to join a team
`POST /api/v1/teams/{teamId}/join`

**Response** `200` `{ "success": true }`

---

### Leave a team
`POST /api/v1/teams/{teamId}/leave`

**Response** `200` `{ "success": true }`

---

## Resources

### Get team resources
`GET /api/v1/teams/{teamId}/resources`

Returns linked app resources for the team.

**Response** `200`
```json
{
  "talk":     { "token": "abc", "url": "..." },
  "files":    { "path": "/Teams/Engineering", "url": "..." },
  "calendar": { "id": 42, "token": "...", "url": "..." },
  "deck":     { "board_id": 7, "url": "..." }
}
```

---

### Create team resources
`POST /api/v1/teams/{teamId}/create-resources`

Provisions selected app resources for the team.

**Body**
```json
{
  "apps": ["talk", "files", "calendar", "deck"],
  "teamName": "Engineering"
}
```

**Response** `200` — per-app result map. HTTP status is always 200; check each app's `success` field.

---

## Activity

### Get team activity
`GET /api/v1/teams/{teamId}/activity`

**Query params**
| Param | Type | Default | Description |
|---|---|---|---|
| `limit` | int | 25 | Number of items (1–100) |
| `since` | int | 0 | Unix timestamp lower bound |

**Response** `200` `{ "activities": [...] }`

---

## Calendar Events

### Get calendar events
`GET /api/v1/teams/{teamId}/calendar/events`

Returns upcoming events from the team calendar.

**Response** `200` — array of event objects.

---

### Create calendar event
`POST /api/v1/teams/{teamId}/calendar/events`

**Body**
| Field | Type | Required |
|---|---|---|
| `title` | string | yes |
| `start` | string (ISO 8601) | yes |
| `end` | string (ISO 8601) | yes |
| `location` | string | no |
| `description` | string | no |

**Response** `201` `{ "success": true }`

---

## Messages

### List messages
`GET /api/v1/teams/{teamId}/messages`

**Response** `200`
```json
{
  "pinned": { ...messageObject } | null,
  "messages": [ ...messageObjects ]
}
```

---

### Create message
`POST /api/v1/teams/{teamId}/messages`

**Body**
| Field | Type | Required | Description |
|---|---|---|---|
| `subject` | string | yes | Message subject |
| `message` | string | yes | Body (Markdown) |
| `priority` | string | no | `normal` / `high` |
| `messageType` | string | no | `message` / `poll` / `question` |
| `pollOptions` | array | no | Array of option strings (poll only) |

**Response** `201` — created message object.

---

### Update message
`PUT /api/v1/teams/{teamId}/messages/{messageId}`

**Body** `{ "subject": "...", "message": "..." }`

**Response** `200` — updated message object.

---

### Delete message
`DELETE /api/v1/teams/{teamId}/messages/{messageId}`

**Response** `200` `{ "success": true }`

---

### Pin message
`POST /api/v1/teams/{teamId}/messages/{messageId}/pin`

Requires the caller's Circles level to meet the `pinMinLevel` admin setting.

**Response** `200` — pinned message object.

---

### Unpin message
`POST /api/v1/teams/{teamId}/messages/{messageId}/unpin`

**Response** `200` — unpinned message object.

---

### Get aggregated messages (all teams)
`GET /api/v1/messages/aggregated`

Returns recent messages across all of the user's teams.

**Response** `200` — array of message objects with `teamId` field.

---

### Vote on poll
`POST /api/v1/messages/{messageId}/vote`

**Body** `{ "optionIndex": 2 }`

**Response** `200` — updated poll results.

---

### Get poll results
`GET /api/v1/messages/{messageId}/poll-results`

**Response** `200` — vote counts per option.

---

### Close poll
`POST /api/v1/messages/{messageId}/close-poll`

**Response** `200` `{ "success": true }`

---

### Mark question solved
`POST /api/v1/messages/{messageId}/mark-solved`

**Body** `{ "commentId": 42 }` (optional — links to the solving comment)

**Response** `200` `{ "success": true }`

---

### Unmark question solved
`POST /api/v1/messages/{messageId}/unmark-solved`

**Response** `200` `{ "success": true }`

---

## Comments

### List comments
`GET /api/v1/messages/{messageId}/comments`

**Response** `200` — array of comment objects.

---

### Create comment
`POST /api/v1/messages/{messageId}/comments`

**Body** `{ "comment": "..." }`

**Response** `201` — created comment object.

---

### Update comment
`PUT /api/v1/comments/{commentId}`

**Body** `{ "comment": "..." }`

**Response** `200` — updated comment object.

---

## Web Links (custom tab bar links)

### List links
`GET /api/v1/teams/{teamId}/links`

**Response** `200` — array of link objects `{ id, team_id, title, url, sort_order }`.

---

### Create link
`POST /api/v1/teams/{teamId}/links`

**Body** `{ "title": "...", "url": "https://..." }`

**Response** `201` — created link object.

---

### Update link
`PUT /api/v1/teams/{teamId}/links/{linkId}`

**Body** `{ "title": "...", "url": "https://...", "sortOrder": 2 }`

**Response** `200` — updated link object.

---

### Delete link
`DELETE /api/v1/teams/{teamId}/links/{linkId}`

**Response** `200` `{ "success": true }`

---

## Widgets (External App API) — v2.26.0

The widget API allows other Nextcloud apps to register a widget that team admins can enable for their team. Widgets are displayed as collapsible iframes in the TeamHub sidebar.

### Authentication requirement
All widget registration endpoints require an authenticated Nextcloud session. The `app_id` in the request must correspond to an **installed and enabled** Nextcloud app on the same instance. TeamHub verifies this server-side before accepting a registration.

---

### Register a widget
`POST /api/v1/ext/widgets/register`

Registers a widget for an external app, or updates an existing registration. An app can only have one widget registered at a time (upsert).

**Body**
| Field | Type | Required | Constraints |
|---|---|---|---|
| `app_id` | string | yes | Must match an installed and enabled NC app. Max 64 chars. |
| `title` | string | yes | Displayed in sidebar header and Manage Team tab. Max 255 chars. |
| `iframe_url` | string | yes | Must start with `https://`. Max 2048 chars. TeamHub will append `?teamId={id}` when rendering. |
| `description` | string | no | Shown in the Manage Team → Widgets tab only. Max 500 chars. |
| `icon` | string | no | MDI icon name (e.g. `Widgets`). Max 64 chars. Defaults to generic widget icon. |

**Response** `200` — widget registry object.
```json
{
  "id": 1,
  "app_id": "myplugin",
  "title": "My Widget",
  "description": "Shows plugin data for this team.",
  "icon": "Widgets",
  "iframe_url": "https://example.com/teamhub-widget",
  "created_at": 1743600000
}
```

**Errors**
| Status | Condition |
|---|---|
| `400` | Missing required fields, invalid URL, field too long |
| `400` | `app_id` is not an installed/enabled NC app |

---

### Deregister a widget
`DELETE /api/v1/ext/widgets/{appId}`

Removes the widget registration for the given app and cascade-deletes all team opt-ins. Idempotent — returns `200` even if no registration existed.

**Requires NC admin privilege.** This prevents any authenticated user from removing another app's widget registration.

Called when an app is uninstalled (via the app's uninstall hook), or by an NC admin manually.

**Response** `200` `{ "success": true }`

---

### Get enabled widgets for a team (sidebar)
`GET /api/v1/teams/{teamId}/widgets`

Returns the list of widgets enabled for a specific team, ordered by `sort_order`. Used internally by TeamHub's frontend when loading a team.

**Response** `200`
```json
[
  {
    "id": 3,
    "registry_id": 1,
    "team_id": "abc123",
    "sort_order": 0,
    "enabled_at": 1743600000,
    "app_id": "myplugin",
    "title": "My Widget",
    "description": "...",
    "icon": "Widgets",
    "iframe_url": "https://example.com/teamhub-widget"
  }
]
```

The rendered iframe src will be: `https://example.com/teamhub-widget?teamId=abc123`

---

### Get widget registry for a team (Manage Team tab)
`GET /api/v1/teams/{teamId}/widget-registry`

Returns all globally registered widgets annotated with their enabled/disabled state for this team. Used to populate the Manage Team → Widgets tab.

**Response** `200`
```json
[
  {
    "registry_id": 1,
    "app_id": "myplugin",
    "title": "My Widget",
    "description": "Shows plugin data for this team.",
    "icon": "Widgets",
    "iframe_url": "https://example.com/teamhub-widget",
    "enabled": false,
    "sort_order": 0
  }
]
```

---

### Enable or disable a widget for a team
`POST /api/v1/teams/{teamId}/widget-registry/{registryId}/toggle`

**Body** `{ "enable": true }` or `{ "enable": false }`

Returns the updated full registry list for the team (same shape as the registry endpoint).

**Response** `200` — updated registry array.

**Errors**
| Status | Condition |
|---|---|
| `400` | `registryId` does not exist |

---

### Reorder widgets for a team
`PUT /api/v1/teams/{teamId}/widget-registry/reorder`

Persists the drag-and-drop sort order for a team's enabled widgets.

**Body**
```json
{ "order": [3, 1, 2] }
```

`order` is an array of `registry_id` values in the desired top-to-bottom display order. Only IDs for widgets that are enabled for this team need to be included; unrecognised IDs are silently ignored.

**Response** `200` — updated enabled widget list (same shape as the sidebar endpoint).

**Errors**
| Status | Condition |
|---|---|
| `400` | `order` array is missing or empty |

---

## Users & Utility

### Search users
`GET /api/v1/users/search`

**Query params** `?q=alice` (minimum 2 characters)

**Response** `200` — array of `{ uid, displayName }` objects.

---

### Check installed apps
`GET /api/v1/apps/check`

Returns which optional Nextcloud apps (Talk, Files, Calendar, Deck, etc.) are installed and available for resource provisioning.

**Response** `200` — map of app IDs to boolean.

---

### Get allowed invite types
`GET /api/v1/invite-types`

Returns the invite types permitted by the admin setting.

**Response** `200` `{ "types": ["user", "group"] }`

---

### Check if current user can create teams
`GET /api/v1/user/can-create-team`

**Response** `200` `{ "canCreate": true }`

---

### Mark team seen
`POST /api/v1/teams/{teamId}/seen`

Records that the current user has viewed this team's messages, clearing the unread badge.

**Response** `200` `{ "success": true }`

---

## Admin Settings

### Get admin settings
`GET /api/v1/admin/settings`

NC admin role required.

**Response** `200`
```json
{
  "wizardDescription": "",
  "createTeamGroup": "",
  "inviteTypes": "user,group",
  "pinMinLevel": "moderator"
}
```

---

### Save admin settings
`POST /api/v1/admin/settings`

NC admin role required. Body is `application/x-www-form-urlencoded`.

**Body**
| Field | Type | Description |
|---|---|---|
| `wizardDescription` | string | Text shown at top of Create Team wizard |
| `createTeamGroup` | string | NC group allowed to create teams (empty = everyone) |
| `inviteTypes` | string | Comma-separated: `user`, `group`, `email`, `federated` |
| `pinMinLevel` | string | `member`, `moderator`, or `admin` |

**Response** `200` `{ "success": true }`

---

## Widget Integration Guide (for external app developers)

### Registering your widget

Call the register endpoint from your app's PHP backend after your app boots, or on demand:

```php
// In your app's service or controller
$client = \OC::$server->getHTTPClientService()->newClient();
$response = $client->post(
    \OC::$server->getURLGenerator()->getAbsoluteURL(
        '/apps/teamhub/api/v1/ext/widgets/register'
    ),
    [
        'json' => [
            'app_id'      => 'myapp',
            'title'       => 'My App Widget',
            'iframe_url'  => 'https://myapp.example.com/teamhub-widget',
            'description' => 'Shows My App data for this team.',
            'icon'        => 'Widgets',
        ],
    ]
);
```

### Receiving the teamId

TeamHub appends `?teamId=<circleId>` to your `iframe_url`. Use this to scope your widget content to the correct team:

```
GET https://myapp.example.com/teamhub-widget?teamId=abc123def456
```

### Deregistering on uninstall

Call the deregister endpoint from your app's `appinfo/Hooks.php` or uninstall handler:

```php
// On app uninstall
$client->delete(
    \OC::$server->getURLGenerator()->getAbsoluteURL(
        '/apps/teamhub/api/v1/ext/widgets/myapp'
    )
);
```

### iframe sandbox policy

TeamHub renders your widget iframe with:
```
sandbox="allow-scripts allow-forms allow-popups"
```

`allow-same-origin` is intentionally excluded. Combining `allow-same-origin` with `allow-scripts` would allow the iframe to escape its sandbox if served from the same origin as the Nextcloud instance. Design your widget to work without same-origin access.
