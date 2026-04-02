# TeamHub API Endpoints — v2.27.0

All endpoints are prefixed with `/apps/teamhub`. Authentication is required for all endpoints (NC session cookie or app password). All responses are JSON unless noted.

---

## Teams

### GET /api/v1/teams
List all teams the current user is a member of.

**Response:** `Array<Team>`
```json
[{ "id": "circle-uuid", "name": "My Team", "description": "...", "unread": 0, "member_count": 5 }]
```

### GET /api/v1/teams/browse
Browse all visible teams (for discovery / join requests).

**Response:** `Array<Team>`

### GET /api/v1/teams/{teamId}
Get a single team by ID.

**Response:** `Team`

### POST /api/v1/teams
Create a new team.

**Body:** `{ "name": string, "description"?: string }`
**Response:** `Team`

### PUT /api/v1/teams/{teamId}
Update team name.

**Body:** `{ "name": string }`
**Response:** `Team`

### DELETE /api/v1/teams/{teamId}
Delete a team (owner only). Does not delete provisioned resources.

**Response:** `{ "success": true }`

### PUT /api/v1/teams/{teamId}/description
Update team description.

**Body:** `{ "description": string }`
**Response:** `{ "success": true }`

### GET /api/v1/teams/{teamId}/config
Get team Circle configuration bitmask.

**Response:** `{ "config": number }`

### PUT /api/v1/teams/{teamId}/config
Update team Circle configuration. Only MANAGED_BITS (1|2|4|16|512|1024) are written; other bits are preserved.

**Body:** `{ "config": number }`
**Response:** `{ "config": number }`

### GET /api/v1/teams/{teamId}/apps
Get which built-in apps are enabled for this team (legacy — Talk/Files/Cal/Deck).

**Response:** `Array<{ app_id: string, enabled: boolean }>`

### PUT /api/v1/teams/{teamId}/apps
Update which built-in apps are enabled (legacy).

**Body:** `{ "apps": [{ "app_id": string, "enabled": boolean }] }`
**Response:** `Array<{ app_id: string, enabled: boolean }>`

---

## Team members

### GET /api/v1/teams/{teamId}/members
Get all members of a team.

**Response:** `Array<Member>`
```json
[{ "userId": "alice", "displayName": "Alice", "level": 4, "email": "alice@example.com" }]
```

Member levels: `1` = Member, `4` = Moderator, `8` = Admin, `9` = Owner.

### PUT /api/v1/teams/{teamId}/members/{userId}/level
Change a member's role. Requires Moderator (4) or above.

**Body:** `{ "level": 1|4|8 }`
**Response:** `Array<Member>` (full updated list)

### DELETE /api/v1/teams/{teamId}/members/{userId}
Remove a member. Cannot remove the owner.

**Response:** `{ "success": true }`

### POST /api/v1/teams/{teamId}/invite-members
Invite one or more users/groups/emails.

**Body:** `{ "invites": [{ "type": "user"|"group"|"email"|"federated", "id": string }] }`
**Response:** `{ "success": true, "invited": number, "failed": [] }`

### GET /api/v1/teams/{teamId}/pending-requests
Get pending join requests (Moderator+ only).

**Response:** `Array<Member>`

### POST /api/v1/teams/{teamId}/approve/{userId}
Approve a join request.

**Response:** `{ "success": true }`

### POST /api/v1/teams/{teamId}/reject/{userId}
Reject a join request.

**Response:** `{ "success": true }`

### POST /api/v1/teams/{teamId}/join
Request to join a team.

**Response:** `{ "success": true }`

### POST /api/v1/teams/{teamId}/leave
Leave a team. Cannot leave if you are the owner.

**Response:** `{ "success": true }`

---

## Team resources

### GET /api/v1/teams/{teamId}/resources
Get all provisioned resources for a team (Talk token, Files path, Calendar token, Deck board ID).

**Response:**
```json
{
  "talk":     { "token": "abc123", "name": "Team Chat" },
  "files":    { "path": "/TeamHub/MyTeam", "share_id": 42 },
  "calendar": { "id": 7, "public_token": "xyz...", "name": "My Team" },
  "deck":     { "board_id": 3, "name": "My Team" }
}
```
Any key may be `null` if the app is not installed or the resource has not been provisioned.

### POST /api/v1/teams/{teamId}/create-resources
Provision missing resources (Talk room, Files folder, Calendar, Deck board). Idempotent.

**Response:** Same shape as getTeamResources.

---

## Activity & Calendar

### GET /api/v1/teams/{teamId}/activity
Get recent activity for all team resources. Returns up to 30 days.

**Response:** `Array<ActivityItem>`
```json
[{ "id": 1, "type": "file_created", "subject": "Alice created report.pdf", "timestamp": 1712000000, "link": "..." }]
```

### GET /api/v1/teams/{teamId}/calendar/events
Get upcoming calendar events for the team (next 14 days).

**Response:** `Array<CalendarEvent>`
```json
[{ "id": "uid-123", "title": "Sprint Planning", "start": "2026-04-10T10:00:00Z", "end": "2026-04-10T11:00:00Z", "allDay": false }]
```

### POST /api/v1/teams/{teamId}/calendar/events
Create a calendar event on the team calendar.

**Body:** `{ "title": string, "start": ISO8601, "end": ISO8601, "allDay"?: boolean, "description"?: string }`
**Response:** `CalendarEvent`

---

## Messages

### GET /api/v1/teams/{teamId}/messages
List messages for a team.

**Response:** `{ "pinned": Message|null, "messages": Array<Message> }`

Message shape:
```json
{
  "id": 1, "team_id": "circle-uuid", "author_id": "alice", "subject": "Announcement",
  "message": "Body text", "priority": "normal", "message_type": "normal",
  "pinned": false, "poll_options": null, "poll_closed": false,
  "question_solved": false, "solved_comment_id": null,
  "created_at": 1712000000, "updated_at": 1712000000
}
```

`message_type`: `"normal"` | `"poll"` | `"question"`

### POST /api/v1/teams/{teamId}/messages
Post a message.

**Body:** `{ "subject": string, "message": string, "priority"?: string, "messageType"?: string, "pollOptions"?: string[] }`
**Response:** `Message`

### PUT /api/v1/teams/{teamId}/messages/{messageId}
Edit a message (author only).

**Body:** `{ "subject": string, "message": string }`
**Response:** `Message`

### DELETE /api/v1/teams/{teamId}/messages/{messageId}
Delete a message (author or Moderator+).

**Response:** `{ "success": true }`

### POST /api/v1/teams/{teamId}/messages/{messageId}/pin
Pin a message (requires pinMinLevel).

**Response:** `Message`

### POST /api/v1/teams/{teamId}/messages/{messageId}/unpin
Unpin the currently pinned message.

**Response:** `{ "success": true }`

### POST /api/v1/messages/{messageId}/vote
Vote on a poll.

**Body:** `{ "option_index": number }`
**Response:** `{ "success": true }`

### GET /api/v1/messages/{messageId}/poll-results
Get poll vote counts.

**Response:** `Array<{ option_index: number, count: number }>`

### POST /api/v1/messages/{messageId}/close-poll
Close a poll (author or Moderator+).

**Response:** `Message`

### POST /api/v1/messages/{messageId}/mark-solved
Mark a question message as solved.

**Body:** `{ "comment_id"?: number }`
**Response:** `Message`

### POST /api/v1/messages/{messageId}/unmark-solved
Unmark a question as solved.

**Response:** `Message`

### GET /api/v1/messages/aggregated
Get recent messages across all teams the user is a member of.

**Response:** `Array<Message>`

---

## Comments

### GET /api/v1/messages/{messageId}/comments
List comments on a message.

**Response:** `Array<Comment>`
```json
[{ "id": 1, "message_id": 1, "author_id": "alice", "comment": "Text", "created_at": 1712000000 }]
```

### POST /api/v1/messages/{messageId}/comments
Post a comment.

**Body:** `{ "comment": string }`
**Response:** `Comment`

### PUT /api/v1/comments/{commentId}
Edit a comment (author only).

**Body:** `{ "comment": string }`
**Response:** `Comment`

---

## Web links

Custom links shown in the tab bar (open in new browser tab).

### GET /api/v1/teams/{teamId}/links
**Response:** `Array<WebLink>`
```json
[{ "id": 1, "team_id": "...", "title": "Our Wiki", "url": "https://wiki.example.com", "sort_order": 0 }]
```

### POST /api/v1/teams/{teamId}/links
**Body:** `{ "title": string, "url": string }`
**Response:** `WebLink`

### PUT /api/v1/teams/{teamId}/links/{linkId}
**Body:** `{ "title"?: string, "url"?: string, "sort_order"?: number }`
**Response:** `WebLink`

### DELETE /api/v1/teams/{teamId}/links/{linkId}
**Response:** `{ "success": true }`

---

## Integrations

### External-app registration

#### POST /api/v1/ext/integrations/register
Register or update an integration. The calling user must be authenticated and `app_id` must be an installed NC app.

**Body:**
```json
{
  "app_id":           "myapp",
  "integration_type": "widget",
  "title":            "My Widget",
  "description":      "Shows recent items",
  "icon":             "ChartBar",
  "data_url":         "/apps/myapp/api/teamhub/data",
  "action_url":       "/apps/myapp/api/teamhub/action",
  "action_label":     "Create item"
}
```

For `menu_item` instead of `widget`, replace the widget fields with:
```json
{
  "iframe_url": "https://my-nc.example.com/apps/myapp/team-view"
}
```

**Response:** `IntegrationRegistryEntry` (the upserted row)

#### DELETE /api/v1/ext/integrations/{appId}
Deregister an integration and cascade-delete all team opt-ins. NC admin required.

**Response:** `{ "success": true }`

---

### Team render endpoints (called on team select)

#### GET /api/v1/teams/{teamId}/integrations
Returns all enabled integrations for a team, split by type.

**Response:**
```json
{
  "widgets": [
    {
      "id": 1, "registry_id": 3, "team_id": "circle-uuid",
      "sort_order": 0, "enabled_at": 1712000000,
      "app_id": "myapp", "integration_type": "widget",
      "title": "My Widget", "icon": "ChartBar",
      "data_url": "/apps/myapp/api/teamhub/data",
      "action_url": "/apps/myapp/api/teamhub/action",
      "action_label": "Create item",
      "iframe_url": null, "is_builtin": false
    }
  ],
  "menu_items": [
    {
      "registry_id": 1, "app_id": "spreed", "integration_type": "menu_item",
      "title": "Talk", "icon": "Message", "iframe_url": null, "is_builtin": true
    }
  ]
}
```

#### GET /api/v1/teams/{teamId}/integrations/widget-data/{registryId}
Fetch widget data. TeamHub calls the widget's `data_url` server-side and returns the result.

**Response:**
```json
{
  "items": [
    { "label": "Ticket #42", "value": "Open", "icon": "AlertCircle", "url": "https://..." }
  ],
  "error": "optional error message if data fetch failed"
}
```

#### GET /api/v1/teams/{teamId}/integrations/action/{registryId}
Get the action modal definition for a widget.

**Response:**
```json
{
  "title": "Create Ticket",
  "submit_label": "Create",
  "fields": [
    { "name": "subject",  "label": "Subject",     "type": "text"     },
    { "name": "body",     "label": "Description", "type": "textarea" }
  ]
}
```

---

### Manage Team → Integrations tab

#### GET /api/v1/teams/{teamId}/integrations/registry
Returns all registered integrations annotated with enabled state and sort_order for this team.

**Response:** `Array<IntegrationRegistryEntry & { enabled: boolean, sort_order: number }>`

#### POST /api/v1/teams/{teamId}/integrations/{registryId}/toggle
Enable or disable an integration for a team.

**Body:** `{ "enable": true|false }`
**Response:** Same as `/integrations/registry` — full annotated list.

#### PUT /api/v1/teams/{teamId}/integrations/reorder
Persist drag-and-drop sort order.

**Body:** `{ "order": [3, 1, 2] }` — registry IDs in desired display order.
**Response:** Updated enabled integration list.

---

## Users & utility

### GET /api/v1/users/search
Search NC users (for member picker).

**Query:** `?term=alice`
**Response:** `Array<{ id: string, displayName: string, email: string }>`

### GET /api/v1/apps/check
Check which optional apps (Intravox) are available.

**Response:** `{ "intravox": boolean }`

### GET /api/v1/invite-types
Get allowed invite types from admin settings.

**Response:** `{ "types": ["user", "group"] }`

### GET /api/v1/user/can-create-team
Check if the current user is allowed to create teams.

**Response:** `{ "canCreate": true }`

### POST /api/v1/teams/{teamId}/seen
Mark a team as read (updates last_seen_at).

**Response:** `{ "success": true }`

---

## Admin settings

### GET /api/v1/admin/settings
Get current admin settings. NC admin required.

**Response:**
```json
{
  "wizardDescription": "",
  "createTeamGroup":   "",
  "inviteTypes":       "user,group",
  "pinMinLevel":       "moderator"
}
```

### POST /api/v1/admin/settings
Save admin settings. NC admin required. Body is `application/x-www-form-urlencoded`.

**Body fields:** `wizardDescription`, `createTeamGroup`, `inviteTypes`, `pinMinLevel`
**Response:** `{ "success": true }`

---

## Error responses

All endpoints return JSON errors:

```json
{ "error": "Human-readable message" }
```

| HTTP Status | Meaning |
|---|---|
| 400 | Validation error |
| 403 | Forbidden (insufficient privilege) |
| 404 | Resource not found |
| 500 | Server error |
