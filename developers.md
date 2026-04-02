# TeamHub Developer Guide

TeamHub is a Nextcloud 32+ app that gives each Nextcloud Team (Circle) a unified workspace. This guide explains how to build integrations that plug into TeamHub.

---

## Overview of integration types

TeamHub supports two ways to extend a team workspace:

| Type | Where it appears | How data is delivered |
|---|---|---|
| **Sidebar widget** | Right-hand sidebar on the Home and Activity views | TeamHub calls your API server-side; you return a structured list of items |
| **Menu item** | Tab bar alongside Talk, Files, Calendar, Deck | Your app opens in a sandboxed iframe in the main canvas |

Both types are registered once via a REST call from your app. Team admins then enable each integration per team from **Manage Team → Integrations**.

---

## Registering an integration

Your app calls TeamHub's registration endpoint when it is first enabled or configured. The call is idempotent — calling it again updates the existing registration.

### Endpoint

```
POST /apps/teamhub/api/v1/ext/integrations/register
Content-Type: application/json
```

### Parameters

| Field | Type | Required | Description |
|---|---|---|---|
| `app_id` | string | ✅ | Your Nextcloud app ID (must match an installed, enabled app) |
| `integration_type` | string | ✅ | `widget` or `menu_item` |
| `title` | string | ✅ | Label shown in the sidebar header or tab bar (max 255 chars) |
| `description` | string | — | Short description shown in Manage Team → Integrations (max 500 chars) |
| `icon` | string | — | MDI icon name (e.g. `ChartBar`, `Bell`, `AccountGroup`). Defaults to `Puzzle`. |

**For `widget` only:**

| Field | Type | Required | Description |
|---|---|---|---|
| `data_url` | string | ✅ | URL TeamHub calls server-side to fetch widget data. Relative NC path (`/apps/myapp/api/…`) or absolute `https://` |
| `action_url` | string | — | URL TeamHub calls to get the action modal definition. Same rules as `data_url` |
| `action_label` | string | — | Label for the 3-dot action menu item (max 64 chars) |

**For `menu_item` only:**

| Field | Type | Required | Description |
|---|---|---|---|
| `iframe_url` | string | ✅ | URL loaded in the sandboxed canvas iframe. Must be `https://` |

### Example — registering a widget

```php
// In your app's setup or settings save handler:
$client = \OC::$server->get(\OCP\Http\Client\IClientService::class)->newClient();
$client->post(
    \OCP\Server::get(\OCP\IURLGenerator::class)->getAbsoluteURL(
        '/apps/teamhub/api/v1/ext/integrations/register'
    ),
    [
        'json' => [
            'app_id'          => 'myapp',
            'integration_type'=> 'widget',
            'title'           => 'My Widget',
            'description'     => 'Shows recent items from My App',
            'icon'            => 'ChartBar',
            'data_url'        => '/apps/myapp/api/teamhub/widget-data',
            'action_url'      => '/apps/myapp/api/teamhub/widget-action',
            'action_label'    => 'Create item',
        ],
    ]
);
```

### Example — registering a menu item

```php
$client->post($registerUrl, [
    'json' => [
        'app_id'          => 'myapp',
        'integration_type'=> 'menu_item',
        'title'           => 'My App',
        'description'     => 'Open My App for this team',
        'icon'            => 'ViewDashboard',
        'iframe_url'      => 'https://my-nextcloud.example.com/apps/myapp/team-view',
    ],
]);
```

### Deregistering

When your app is uninstalled or the integration is removed, call:

```
DELETE /apps/teamhub/api/v1/ext/integrations/{appId}
```

Requires the calling user to be a Nextcloud admin. This cascade-deletes all per-team opt-ins.

---

## Sidebar widgets — data endpoint

When a team member opens TeamHub, the sidebar fetches data from your `data_url` for each enabled widget.

### What TeamHub sends

TeamHub makes a server-side `GET` request to your endpoint with `teamId` appended as a query parameter:

```
GET /apps/myapp/api/teamhub/widget-data?teamId=<circle-id>
```

### What you must return

```json
{
  "items": [
    {
      "label": "Ticket #42",
      "value": "Open",
      "icon": "AlertCircle",
      "url": "https://my-nextcloud.example.com/apps/myapp/tickets/42"
    },
    {
      "label": "Ticket #38",
      "value": "In Progress",
      "icon": "CheckCircle"
    }
  ]
}
```

**Item fields:**

| Field | Type | Required | Description |
|---|---|---|---|
| `label` | string | ✅ | Primary text displayed |
| `value` | string | ✅ | Secondary text (status, count, date…) |
| `icon` | string | — | MDI icon name rendered next to the label |
| `url` | string | — | If present, the item becomes a link (opens in new tab) |

**Rules:**
- Maximum 20 items returned. Items beyond 20 are silently dropped.
- Respond within 5 seconds or TeamHub will show an error state.
- Return HTTP 200 even for empty results: `{ "items": [] }`

### Authentication

TeamHub calls your endpoint as a server-to-server request. Validate the request is coming from within the same Nextcloud instance by checking for a valid NC session or using an internal shared secret you configure in your app's settings.

---

## Sidebar widgets — action modal

If you register an `action_url`, a 3-dot menu appears in the widget header with your `action_label`. When clicked, TeamHub fetches the action definition from your endpoint and renders a modal.

### What TeamHub sends

```
GET /apps/myapp/api/teamhub/widget-action?teamId=<circle-id>
```

### What you must return

```json
{
  "title": "Create Ticket",
  "submit_label": "Create",
  "fields": [
    { "name": "subject",  "label": "Subject",      "type": "text"     },
    { "name": "priority", "label": "Priority",     "type": "text",  "value": "normal" },
    { "name": "body",     "label": "Description",  "type": "textarea" }
  ]
}
```

**Field types supported:** `text`, `email`, `textarea`, `checkbox`

When the user submits the modal, TeamHub POSTs to your `action_url`:

```
POST /apps/myapp/api/teamhub/widget-action
Content-Type: application/json

{
  "teamId": "<circle-id>",
  "fields": {
    "subject":  "My new ticket",
    "priority": "high",
    "body":     "Details here"
  }
}
```

After a successful POST, TeamHub automatically refreshes the widget data.

---

## Menu items — iframe

When a user clicks your menu item tab, TeamHub loads your `iframe_url` in the main canvas with `teamId` appended:

```
https://my-nextcloud.example.com/apps/myapp/team-view?teamId=<circle-id>
```

### iframe sandbox policy

Your iframe runs under this sandbox attribute:

```
sandbox="allow-scripts allow-forms allow-popups allow-same-origin"
```

`allow-same-origin` is granted for menu items (unlike the old widget iframe design) because your app is a first-party NC app hosted on the same origin. This lets you use NC's session cookie. Cross-origin `iframe_url` values are still accepted but will have limited cookie access per browser SameSite policy.

### Referrer policy

```
referrerpolicy="strict-origin-when-cross-origin"
```

---

## Icon reference

These MDI icon names are supported in TeamHub. Pass the name string exactly as shown.

| Name | Usage |
|---|---|
| `Puzzle` | Default (unknown icon) |
| `CalendarMonth` | Calendar-related |
| `ViewDashboard` | Dashboards, overviews |
| `AccountGroup` | People, teams |
| `ChartBar` | Analytics, metrics |
| `Bell` | Notifications, alerts |
| `FileDocument` | Documents, files |
| `CheckCircle` | Completed, done |
| `AlertCircle` | Warnings, issues |
| `Message` | Chat, messages |
| `Folder` | Files, storage |

---

## Team admin flow

1. Your app is installed and calls `POST /apps/teamhub/api/v1/ext/integrations/register`.
2. The integration appears in **Manage Team → Integrations** for every team admin.
3. The admin checks the checkbox next to your integration to enable it for their team.
4. TeamHub immediately starts rendering your widget in the sidebar or showing your tab.

Built-in integrations (Talk, Files, Calendar, Deck) follow the same enable/disable flow — team admins can turn them off from the same screen.

---

## Versioning and compatibility

| TeamHub version | API version | NC min |
|---|---|---|
| 2.27.0+ | v1 | NC32 |

The integration API is versioned at `/api/v1/`. Non-breaking additions (new optional fields) will not bump the version. Breaking changes will introduce `/api/v2/`.

---

## Error handling

All registration and data endpoints return JSON. On error:

```json
{ "error": "Human-readable message" }
```

HTTP status codes:

| Code | Meaning |
|---|---|
| 200 | Success (including upsert on re-registration) |
| 400 | Validation error (see `error` field) |
| 403 | Forbidden (admin required for deregister) |
| 500 | Server error |

---

## Security checklist for your integration

- ✅ Validate `teamId` in your data/action endpoints — only return data the requesting user has access to in that team.
- ✅ Use NC's `IUserSession` to verify the user is authenticated before serving data.
- ✅ For `data_url` and `action_url`: accept only `GET`/`POST` from within the NC instance.
- ✅ For `iframe_url`: serve with `Content-Security-Policy: frame-ancestors 'self'` to prevent your page being embedded elsewhere.
- ✅ Treat `teamId` as untrusted input — validate it is a real circle the user belongs to before returning sensitive data.
- ✅ Never return data from `data_url` that the current NC user should not see.

---

## Questions and support

- GitHub: https://github.com/justindoek/teamhub
- Issues: https://github.com/justindoek/teamhub/issues
