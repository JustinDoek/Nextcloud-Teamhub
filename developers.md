# TeamHub Developer Guide

TeamHub is a Nextcloud 32+ app that gives each Nextcloud Team (Circle) a unified workspace. This guide explains how to build integrations that plug into TeamHub.

---

## Overview of integration types

TeamHub supports two ways to extend a team workspace. An app may register **one or both** types — they are fully independent and do not interfere with each other.

| Type | Where it appears | What it does |
|---|---|---|
| **Sidebar widget** | Widget grid on the Home view | Your app provides a live list of items and optional actions scoped to the current team |
| **Menu item** | Tab bar alongside Talk, Files, Calendar, Deck | Your app opens in a sandboxed iframe in the main canvas, scoped to the current team |

Both types are registered once from your app's `Application::boot()`. Team admins then enable each independently per team from **Manage Team → Integrations**.

---

## Architecture — why no HTTP calls

All TeamHub integrations are Nextcloud apps installed on the **same NC instance** as TeamHub. TeamHub resolves your PHP class from NC's DI container and calls it directly in the same PHP process. There is no HTTP, no loopback, no credentials forwarded over the network.

---

## Registering both types

Each call to `registerIntegration()` registers exactly **one** `integration_type` for your `app_id`. Call it twice to register both types.

```php
public function boot(IBootContext $context): void {
    try {
        /** @var \OCA\TeamHub\Service\IntegrationService $teamHub */
        $teamHub = $context->getServerContainer()->get(
            \OCA\TeamHub\Service\IntegrationService::class
        );

        // Register the sidebar widget.
        $teamHub->registerIntegration(
            appId:           'myapp',
            integrationType: 'widget',
            title:           'My Widget',
            description:     'Shows recent items from My App',
            icon:            'ChartBar',
            phpClass:        \OCA\MyApp\Integration\TeamHubWidget::class,
            calledInProcess: true,
        );

        // Register the tab bar menu item.
        $teamHub->registerIntegration(
            appId:           'myapp',
            integrationType: 'menu_item',
            title:           'My App',
            description:     'Open My App for this team',
            icon:            'ChartBar',
            iframeUrl:       '/apps/myapp/team-view',
            calledInProcess: true,
        );

    } catch (\Throwable $e) {
        // TeamHub may not be installed — always fail silently.
    }
}
```

Registration is **idempotent** — calling it again on re-enable updates the existing record without changing the `registry_id` or any team opt-ins.

---

## Sidebar widgets — PHP interface

### Step 1 — Implement `ITeamHubWidget`

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

    // ── Required ──────────────────────────────────────────────────────

    public function getWidgetData(string $teamId, string $userId): array {
        $items = $this->myService->getItemsForTeam($teamId, $userId);

        return [
            'items' => array_map(fn($item) => [
                'label' => $item->title,
                'value' => $item->status,
                'icon'  => 'CheckCircle',
                'url'   => '/apps/myapp/items/' . $item->id,
            ], array_slice($items, 0, 20)),

            // Actions appear in the widget 3-dot header menu.
            // Use actionId for native TeamHub modal forms.
            // Use url-only for simple link actions (opens in new tab).
            'actions' => [
                [
                    'label'    => 'New Item',
                    'icon'     => 'Plus',
                    'actionId' => 'new_item',   // triggers native modal
                ],
                [
                    'label' => 'View All',
                    'icon'  => 'ArrowRight',
                    'url'   => '/apps/myapp/teams/' . $teamId,  // link only
                ],
            ],
        ];
    }

    // ── Optional — native action modal ────────────────────────────────

    public function getActionForm(string $actionId, string $teamId, string $userId): array {
        return match ($actionId) {
            'new_item' => [
                'title'        => 'New Item',
                'submit_label' => 'Create',
                'fields'       => [
                    [
                        'name'     => 'title',
                        'label'    => 'Title',
                        'type'     => 'text',
                        'required' => true,
                    ],
                    [
                        'name'  => 'description',
                        'label' => 'Description',
                        'type'  => 'textarea',
                    ],
                ],
            ],
            default => ['fields' => []],  // empty fields → fall back to url
        };
    }

    public function handleAction(string $actionId, array $fields, string $teamId, string $userId): array {
        return match ($actionId) {
            'new_item' => $this->createItem($fields, $teamId, $userId),
            default    => ['success' => false, 'message' => 'Unknown action'],
        };
    }

    private function createItem(array $fields, string $teamId, string $userId): array {
        $title = trim($fields['title'] ?? '');
        if ($title === '') {
            return ['success' => false, 'message' => 'Title is required'];
        }
        $this->myService->create($title, $fields['description'] ?? '', $teamId, $userId);
        return [
            'success' => true,
            'message' => 'Item created',
            'refresh' => true,   // tells TeamHub to reload widget data
        ];
    }
}
```

---

## `getWidgetData()` — response shape

```php
return [
    'items' => [                           // required, may be empty array
        [
            'label' => 'string',           // required — primary text
            'value' => 'string',           // required — secondary text
            'icon'  => 'MDI name',         // optional — see Icon reference
            'url'   => '/apps/myapp/...',  // optional — makes item clickable
        ],
        // Maximum 20 items. Additional items are silently dropped.
    ],

    'actions' => [                         // optional — 3-dot header menu
        [
            'label'    => 'string',        // required
            'icon'     => 'MDI name',      // optional
            'actionId' => 'my_action',     // use this for native modal actions
            'url'      => '/apps/...',     // use this for plain link fallback
        ],
        // Maximum 10 actions.
        // actionId takes priority over url when both are present.
        // url-only (no actionId) opens in a new tab.
    ],
];
```

---

## `getActionForm()` — form definition shape

```php
return [
    'title'        => 'string',           // optional — modal title (defaults to action label)
    'submit_label' => 'string',           // optional — submit button text (defaults to 'Submit')
    'fields'       => [                   // required — empty array = fall back to url
        [
            'name'        => 'field_key', // required — used as key in handleAction $fields
            'label'       => 'My Field',  // required — shown as input label
            'type'        => 'text',      // required — text | textarea | email | checkbox | date
            'required'    => true,        // optional — default false
            'value'       => '',          // optional — pre-filled default value
            'placeholder' => 'hint',      // optional
        ],
    ],
];
```

**If `getActionForm()` is not implemented** on your class, or returns `['fields' => []]`, TeamHub falls back to opening the action's `url` in a new browser tab.

---

## `handleAction()` — result shape

```php
return [
    'success' => true,            // required — true or false
    'message' => 'Item created',  // optional — shown as NC toast notification
    'refresh' => true,            // optional — if true, TeamHub reloads widget data
];
```

---

## Menu items — iframe

```php
$teamHub->registerIntegration(
    appId:           'myapp',
    integrationType: 'menu_item',
    title:           'My App',
    description:     'Open My App for this team',
    icon:            'ViewDashboard',
    iframeUrl:       '/apps/myapp/team-view',
    calledInProcess: true,
);
```

TeamHub appends `?teamId=<circle-uuid>` to `iframe_url` when loading the iframe. Read it in your controller and validate the user is a member before rendering.

### `iframe_url` format

| Format | Example |
|---|---|
| Relative NC path | `/apps/myapp/team-view` |
| Relative with index.php | `/index.php/apps/myapp/team-view` |
| Absolute https | `https://my.service.example.com/embed` |

### iframe sandbox policy

```
sandbox="allow-scripts allow-forms allow-popups allow-same-origin"
```

Include `Content-Security-Policy: frame-ancestors 'self'` in your iframe page response.

---

## `registerIntegration()` parameters

| PHP named parameter | REST JSON key | Type | Required | Description |
|---|---|---|---|---|
| `appId` | `app_id` | string | YES | Your NC app ID |
| `integrationType` | `integration_type` | string | YES | `widget` or `menu_item` |
| `title` | `title` | string | YES | Label shown in sidebar or tab bar |
| `description` | `description` | string | no | Shown in Manage Team → Integrations |
| `icon` | `icon` | string | no | MDI icon name. Defaults to `Puzzle` |
| `phpClass` | `php_class` | string | YES for widget | FQN of class implementing `ITeamHubWidget` |
| `iframeUrl` | `iframe_url` | string | YES for menu_item | Relative NC path or `https://` |
| `calledInProcess` | *(not in REST)* | bool | YES | Always `true` from `boot()` |

---

## Deregistering

```php
$teamHub->deregisterIntegration('myapp', calledInProcess: true);
```

Removes all registry rows for your `app_id` and cascade-deletes all per-team opt-ins.

---

## Icon reference

| Name | Usage |
|---|---|
| `Puzzle` | Default |
| `CalendarMonth` | Calendar |
| `ViewDashboard` | Dashboards |
| `AccountGroup` | People, teams |
| `ChartBar` | Analytics |
| `Bell` | Notifications |
| `FileDocument` | Documents |
| `CheckCircle` | Done, completed |
| `AlertCircle` | Warnings |
| `Message` | Chat |
| `Folder` | Files |
| `Plus` | Add, create |
| `ArrowRight` | Navigate |
| `FormatListBulleted` | Lists |
| `Delete` | Destructive actions |
| `Minus` | Remove |

---

## Versioning

| TeamHub version | Changes |
|---|---|
| 3.0.0 – 3.2.0 | Security hardening: all team-scoped read endpoints now enforce membership. No interface changes for external apps. |
| 2.46.0 | `getActionForm()` + `handleAction()` added to `ITeamHubWidget`. Actions use `actionId` for native modals. |
| 2.42.3 – 2.45.x | Widget + menu_item both supported. `iframe_url` accepts relative NC paths. |
| 2.41 – 2.42.2 | `ITeamHubWidget` introduced. `php_class` column added to integration registry. |
| 2.27 – 2.40 | HTTP `data_url` model (removed — do not use) |

---

## Common mistakes

| Mistake | Symptom | Fix |
|---|---|---|
| Using `url` instead of `actionId` for modal actions | Action opens in new tab or iframe | Use `actionId` in the action descriptor; implement `getActionForm()` |
| `getActionForm()` returns `['fields' => []]` | Falls back to new tab | Return at least one field in the `fields` array |
| `handleAction()` not implemented | Submit fails with 400 | Implement `handleAction()` alongside `getActionForm()` |
| Using `php_class` (snake_case) as PHP named param | `Unknown named argument` error | Use `phpClass` (camelCase) in PHP; `php_class` in REST JSON |
| Omitting `calledInProcess: true` from `boot()` | 403 Forbidden | Always pass `calledInProcess: true` from `boot()` |
| Not wrapping `boot()` in `try/catch` | Fatal error when TeamHub disabled | Always wrap in `try { ... } catch (\Throwable $e) {}` |
| `getWidgetData()` returning `{lists:[]}` | Empty widget | Root key must be `items`, not any other name |
| `iframe_url` using `http://` | 400 on registration | Use `https://` or a relative NC path |
| Not reading `?teamId=` in iframe page | Shows global state | Read `$request->getParam('teamId')` and scope the view |

---

## Questions and support

- GitHub: https://github.com/justindoek/teamhub
- Issues: https://github.com/justindoek/teamhub/issues
