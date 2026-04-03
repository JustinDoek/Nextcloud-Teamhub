# TeamHub API Endpoints — v2.31.0

All endpoints are prefixed with `/apps/teamhub`. Authentication via Nextcloud session cookie (CSRF required unless noted). All responses are JSON.

---

## Teams

| Method | URL | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/teams` | User | List teams the current user belongs to |
| POST | `/api/v1/teams` | User | Create a new team (Circle) |
| GET | `/api/v1/teams/browse` | User | Browse all visible/joinable teams |
| GET | `/api/v1/teams/{teamId}` | User | Get a single team |
| PUT | `/api/v1/teams/{teamId}` | User | Update team description or config bitmask |
| DELETE | `/api/v1/teams/{teamId}` | Owner | Delete team (owner only) |

## Team Members

| Method | URL | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/teams/{teamId}/members` | User | List team members with levels |
| DELETE | `/api/v1/teams/{teamId}/members/{userId}` | Admin | Remove a member |
| PUT | `/api/v1/teams/{teamId}/members/{userId}/level` | Admin | Change member role level |
| GET | `/api/v1/teams/{teamId}/pending-requests` | User | List pending join requests |
| POST | `/api/v1/teams/{teamId}/approve/{userId}` | Admin | Approve a join request |
| POST | `/api/v1/teams/{teamId}/reject/{userId}` | Admin | Reject a join request |

## Team Config & Description

| Method | URL | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/teams/{teamId}/config` | User | Get Circles config bitmask |
| PUT | `/api/v1/teams/{teamId}/config` | Admin | Update Circles config bitmask |
| PUT | `/api/v1/teams/{teamId}/description` | Admin | Update team description |

## Team Resources

| Method | URL | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/teams/{teamId}/resources` | User | Get Talk/Files/Calendar/Deck resource IDs |
| POST | `/api/v1/teams/{teamId}/create-resources` | Admin | Provision resources for selected apps |

## Team Apps (built-in app visibility per team)

| Method | URL | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/teams/{teamId}/apps` | User | Get enabled/disabled state per app |
| PUT | `/api/v1/teams/{teamId}/apps` | **Team Admin** | Toggle app on/off; creates or hard-deletes resource |
| DELETE | `/api/v1/teams/{teamId}/resources/{app}` | **Team Admin** | Hard-delete a single resource (`spreed`, `files`, `calendar`, `deck`, `intravox`) |

> **Note:** Disabling an app via PUT /apps triggers full hard-deletion of the resource (Option B). All data is permanently removed.

## Team Actions

| Method | URL | Auth | Description |
|---|---|---|---|
| POST | `/api/v1/teams/{teamId}/join` | User | Request to join a team |
| POST | `/api/v1/teams/{teamId}/leave` | User | Leave a team |
| POST | `/api/v1/teams/{teamId}/seen` | User | Mark team messages as seen |
| POST | `/api/v1/teams/{teamId}/invite-members` | Admin | Invite users/groups to team |

## Calendar

| Method | URL | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/teams/{teamId}/calendar/events` | User | Get upcoming calendar events |
| POST | `/api/v1/teams/{teamId}/calendar/events` | User | Create calendar event |

## Activity

| Method | URL | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/teams/{teamId}/activity` | User | Get recent team activity |

## Messages

| Method | URL | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/teams/{teamId}/messages` | User | List messages `{pinned, messages[]}` |
| POST | `/api/v1/teams/{teamId}/messages` | User | Post a message |
| PUT | `/api/v1/teams/{teamId}/messages/{messageId}` | Author | Edit a message |
| DELETE | `/api/v1/teams/{teamId}/messages/{messageId}` | Author/Admin | Delete a message |
| POST | `/api/v1/teams/{teamId}/messages/{messageId}/pin` | Admin | Pin a message |
| POST | `/api/v1/teams/{teamId}/messages/{messageId}/unpin` | Admin | Unpin a message |
| GET | `/api/v1/messages/aggregated` | User | Aggregated messages across all teams |
| POST | `/api/v1/messages/{messageId}/vote` | User | Vote on a poll |
| GET | `/api/v1/messages/{messageId}/poll-results` | User | Get poll results |
| POST | `/api/v1/messages/{messageId}/close-poll` | Admin | Close a poll |
| POST | `/api/v1/messages/{messageId}/mark-solved` | Admin | Mark question as solved |
| POST | `/api/v1/messages/{messageId}/unmark-solved` | Admin | Unmark question as solved |

## Comments

| Method | URL | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/messages/{messageId}/comments` | User | List comments on a message |
| POST | `/api/v1/messages/{messageId}/comments` | User | Post a comment |
| PUT | `/api/v1/comments/{commentId}` | Author | Edit a comment |

## Web Links

| Method | URL | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/teams/{teamId}/links` | User | List web links for a team |
| POST | `/api/v1/teams/{teamId}/links` | User | Add a web link |
| PUT | `/api/v1/teams/{teamId}/links/{linkId}` | User | Update a web link |
| DELETE | `/api/v1/teams/{teamId}/links/{linkId}` | User | Delete a web link |

## Integration API (External Apps)

| Method | URL | Auth | Description |
|---|---|---|---|
| POST | `/api/v1/ext/integrations/register` | User | Register a widget or menu_item integration |
| DELETE | `/api/v1/ext/integrations/{appId}` | **NC Admin** | Deregister an integration |

## Integration — Team Rendering

| Method | URL | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/teams/{teamId}/integrations` | User | Get enabled integrations `{widgets[], menu_items[]}` |
| GET | `/api/v1/teams/{teamId}/integrations/widget-data/{registryId}` | User | Fetch server-side widget data |
| GET | `/api/v1/teams/{teamId}/integrations/action/{registryId}` | User | Trigger widget action |

## Integration — Manage Team

| Method | URL | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/teams/{teamId}/integrations/registry` | User | Full registry with per-team enabled state |
| POST | `/api/v1/teams/{teamId}/integrations/{registryId}/toggle` | Admin | Enable/disable an integration for a team |
| PUT | `/api/v1/teams/{teamId}/integrations/reorder` | Admin | Reorder integrations |

## Admin

| Method | URL | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/admin/settings` | **NC Admin** | Get admin settings |
| POST | `/api/v1/admin/settings` | **NC Admin** | Save admin settings |
| GET | `/api/v1/admin/groups/search?q=` | **NC Admin** | Search NC groups for the group picker |

## User / Utility

| Method | URL | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/users/search?q=` | User | Search NC users (for invite picker) |
| GET | `/api/v1/apps/check` | User | Check which optional apps are installed `{talk, calendar, deck, intravox}` |
| GET | `/api/v1/user/can-create-team` | User | Whether current user may create teams |
| GET | `/api/v1/invite-types` | User | Get allowed invite types from admin config |

---

## Auth legend

| Label | Meaning |
|---|---|
| User | Any authenticated NC user |
| Admin | Team admin or owner (level ≥ 8 in circles_member) |
| Owner | Team owner only (level = 9) |
| NC Admin | Nextcloud instance administrator |
| Team Admin | Team admin/owner — enforced via `requireAdminLevel()` in MemberService |
