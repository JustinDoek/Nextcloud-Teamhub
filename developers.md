# TeamHub Developer Guide

TeamHub is a Nextcloud 32+ app that gives each Nextcloud Team (Circle) a unified workspace. This guide explains how to build integrations that plug into TeamHub.

---

## Overview of integration types

TeamHub supports two ways to extend a team workspace:

| Type | Where it appears | How data is delivered |
|---|---|---|
| **Sidebar widget** | Right-hand sidebar on the Home and Activity views | Your app implements `ITeamHubWidget` — TeamHub calls it directly in-process via NC's DI container. No HTTP. |
| **Menu item** | Tab bar alongside Talk, Files, Calendar, Deck | Your app opens in a sandboxed iframe in the main canvas |

Both types are registered once from your app's `Application::boot()`. Team admins then enable each integration per team from **Manage Team → Integrations**.

---

## Architecture — why no HTTP calls

All TeamHub integrations are Nextcloud apps installed on the **same NC instance** as TeamHub. This means your code and TeamHub's code run in the same PHP process on the same server. There is no need to make HTTP calls between them — and in fact, Nextcloud 28+ actively blocks such calls via its loopback guard (`LocalAddressChecker`).

TeamHub solves this cleanly: widget integrations deliver data by implementing a PHP interface (`ITeamHubWidget`). TeamHub resolves your class from NC's DI container and calls it directly. This approach is:

- **Simple** — implement one interface method, register one class name
- **Robust** — no network, no ports, no DNS, no TLS, no routing
- **Secure** — no credentials forwarded over HTTP, no SSRF surface
- **Scalable** — works identically on single-server and complex load-balanced NC deployments

---

## Sidebar widgets — PHP interface

### Step 1 — Implement `ITeamHubWidget`

Create a class in your app that implements `OCA\TeamHub\Integration\ITeamHubWidget`:

```php
<?php
declare(strict_types=1);

namespace OCA\MyApp\Integration;

use OCA\TeamHub\Integration\ITeamHubWidget;
use OCA\MyApp\Service\MyService;

class TeamHubWidget implements ITeamHubWidget {

    public function __construct(
        private MyService $myService,
    ) {}

    public function getWidgetData(string $teamId, string $userId): array {

        // Always validate access before returning data.
        $items = $this->myService->getItemsForTeam($teamId, $userId);

        return [
            'items' => array_map(fn($item) => [
                'label' => $item->title,
                'value' => $item->status,
                'icon'  => 'CheckCircle',
                'url'   => '/apps/myapp/items/' . $item->id,
            ], array_slice($items, 0, 20)),

            // Optional: populate the 3-dot action menu in the widget header.
            'actions' => [
                [
                    'label' => 'New Item',
                    'icon'  => 'Plus',
                    'url'   => '/apps/myapp/teams/' . $teamId . '/new',
                ],
                [
                    'label' => 'View All',
                    'icon'  => 'ArrowRight',
                    'url'   => '/apps/myapp/teams/' . $teamId,
                ],
            ],
        ];
    }
}
```

### Step 2 — Register in `Application::boot()`

```php
<?php
declare(strict_types=1);

namespace OCA\MyApp\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {

    public function __construct() {
        parent::__construct('myapp');
    }

    public function register(IRegistrationContext $context): void {
        // Register your own services here as normal.
    }

    public function boot(IBootContext $context): void {
        try {
            /** @var \OCA\TeamHub\Service\IntegrationService $teamHub */
            $teamHub = $context->getServerContainer()->get(
                \OCA\TeamHub\Service\IntegrationService::class
            );

            $teamHub->registerIntegration(
                appId:           'myapp',
                integrationType: 'widget',
                title:           'My Widget',
                description:     'Shows recent items from My App',
                icon:            'ChartBar',
                phpClass:        \OCA\MyApp\Integration\TeamHubWidget::class,
                calledInProcess: true,  // required — no web session exists during boot()
            );

        } catch (\Throwable $e) {
            // TeamHub may not be installed — always fail silently.
            \OC::$server->get(\Psr\Log\LoggerInterface::class)->debug(
                'MyApp: TeamHub registration skipped: ' . $e->getMessage(),
                ['app' => 'myapp']
            );
        }
    }
}
```

### `getWidgetData()` response shape

```php
return [
    'items' => [                           // required, may be empty array
        [
            'label' => 'string',           // required — primary text
            'value' => 'string',           // required — secondary text (status, count, date...)
            'icon'  => 'MDI name',         // optional — see Icon reference below
            'url'   => '/apps/myapp/...',  // optional — makes the item a clickable link
        ],
        // Maximum 20 items. Additional items are silently dropped by TeamHub.
    ],

    'actions' => [                         // optional — populates the widget 3-dot header menu
        [
            'label' => 'string',           // required
            'icon'  => 'MDI name',         // optional
            'url'   => '/apps/myapp/...',  // required — relative NC path or https://
        ],
        // Maximum 10 actions.
    ],
];
```

**Action URLs** in the `actions[]` array are browser-navigation links — they open in a new tab when clicked. They may be relative NC paths (`/apps/myapp/...`) or absolute `https://` URLs. These are not called server-side.

### Security rules for `getWidgetData()`

- **Always validate** that `$userId` is a member of `$teamId` before returning data. TeamHub passes the currently authenticated user's NC ID — treat it as trusted.
- **Never return data** the user should not have access to.
- **Throw freely** — any `\Throwable` is caught by TeamHub. The widget renders an empty/error state rather than crashing the page.
- TeamHub calls `getWidgetData()` synchronously. Keep it fast — aim for under 500ms. Cache aggressively where possible.

### Deregistering

Call `deregisterIntegration()` from your app's disable/uninstall hook or `AppDisabledListener`:

```php
$teamHub->deregisterIntegration('myapp', calledInProcess: true);
```

This cascade-deletes all per-team opt-ins.

---

## Menu items — iframe

Menu items load your app in a sandboxed iframe in the main canvas when the user clicks your tab.

### Registering a menu item

```php
$teamHub->registerIntegration(
    appId:           'myapp',
    integrationType: 'menu_item',
    title:           'My App',
    description:     'Open My App for this team',
    icon:            'ViewDashboard',
    iframeUrl:       'https://my-nextcloud.example.com/apps/myapp/team-view',
    calledInProcess: true,
);
```

`iframe_url` must be absolute `https://`. TeamHub appends `?teamId=<circle-id>` when loading the iframe.

### iframe sandbox policy

```
sandbox="allow-scripts allow-forms allow-popups allow-same-origin"
referrerpolicy="strict-origin-when-cross-origin"
```

Serve your iframe page with:

```php
header("Content-Security-Policy: frame-ancestors 'self'");
```

---

## `registerIntegration()` parameters

> **Naming: PHP vs REST API**
> The PHP method uses **camelCase named parameters** (`phpClass`, `iframeUrl`).
> The REST API JSON body uses **snake_case keys** (`php_class`, `iframe_url`).
> These refer to the same fields. Always use camelCase when calling from PHP.

| PHP named parameter | REST API JSON key | Type | Required | Description |
|---|---|---|---|---|
| `appId` | `app_id` | string | YES | Your NC app ID (must match an installed, enabled app) |
| `integrationType` | `integration_type` | string | YES | `widget` or `menu_item` |
| `title` | `title` | string | YES | Label shown in the sidebar header or tab bar (max 255 chars) |
| `description` | `description` | string | no | Short description in Manage Team → Integrations (max 500 chars) |
| `icon` | `icon` | string | no | MDI icon name (e.g. `ChartBar`). Defaults to `Puzzle`. |
| `phpClass` | `php_class` | string | YES for widget | Fully-qualified class name implementing `ITeamHubWidget` |
| `iframeUrl` | `iframe_url` | string | YES for menu_item | Must be absolute `https://` |
| `calledInProcess` | *(not in REST API)* | bool | YES | Always `true` when called from `boot()` |

`phpClass` and `iframeUrl` are mutually exclusive. Passing both throws immediately.

---

## Team admin flow

1. Your app is installed and `boot()` runs — `registerIntegration()` is called.
2. The integration appears in **Manage Team → Integrations** for every team admin.
3. The admin enables your integration for their team.
4. TeamHub immediately renders your widget or shows your tab for that team.

Registration is **idempotent** — calling it again on re-enable updates the existing record.

---

## Icon reference

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
| `Plus` | Add, create |
| `ArrowRight` | Navigate, view all |
| `FormatListBulleted` | Lists |
| `Delete` | Delete, remove (destructive actions) |
| `Minus` | Remove, subtract |

---

## Versioning and compatibility

| TeamHub version | Integration model | NC min |
|---|---|---|
| 2.41+ | PHP interface (`ITeamHubWidget`) | NC32 |
| 2.27-2.40 | HTTP `data_url` (removed) | NC32 |

Apps registered with the old `data_url` model must be updated to implement `ITeamHubWidget`.

---

## Common mistakes

| Mistake | Symptom | Fix |
|---|---|---|
| Using `php_class` (snake_case) as the PHP named parameter | PHP `Unknown named argument` error | Use `phpClass` (camelCase) in your PHP call |
| Using `phpClass` (camelCase) as the REST API JSON key | 400 — widget has no `php_class` configured | Use `php_class` (snake_case) in JSON body |
| Omitting `calledInProcess: true` from `boot()` | 403 Forbidden — no NC admin session exists during boot | Always pass `calledInProcess: true` from `boot()` |
| `phpClass` pointing to a class that does not implement `ITeamHubWidget` | 400 on widget-data load | Ensure your class `implements ITeamHubWidget` |
| Not wrapping `boot()` registration in `try/catch` | Fatal error when TeamHub is disabled or not installed | Always wrap in `try { ... } catch (\Throwable $e) {}` |
| Registering a `widget` without `phpClass` | 400 — widget has no `php_class` configured | `widget` type requires `phpClass`; `menu_item` requires `iframeUrl` |
| `getWidgetData()` returning `{lists: [...]}` instead of `{items: [...]}` | Widget renders empty | Response root key must be `items`, not any other name |

---

## Questions and support

- GitHub: https://github.com/justindoek/teamhub
- Issues: https://github.com/justindoek/teamhub/issues
