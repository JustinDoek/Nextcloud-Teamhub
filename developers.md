# TeamHub Developer Guide

TeamHub is a Nextcloud 34+ app that gives each Nextcloud Team (Circle) a unified workspace. This guide explains how to build integrations that plug into TeamHub.

Since **4.13.23** an app registers **one integration package** holding as many widgets, team tabs and My Work sources as it needs. Since **4.13.24** a widget may be a blank canvas your app draws itself. The single-registration API (`registerIntegration()`) still works and is documented under [Legacy API](#legacy-api-registerintegration) — it is adapted into a package automatically, so nothing has to be migrated on a deadline.

---

## The shape of an integration

Two levels:

| Level | What it is |
|---|---|
| **Integration package** | Your app's TeamHub integration as a whole. One per app, normally. Identified by `app_id` + `integration_key`. |
| **Capability** | One thing the package supplies. Identified by `app_id` + `integration_key` + `capability_key`. |

Four capability types ship today:

| Type | Where it appears | Backed by |
|---|---|---|
| `widget` | A card in the team's home view, drawn by TeamHub from your rows | a class implementing `ITeamHubWidget` |
| `widget_iframe` | A card in the team's home view, drawn by you | an iframe URL, and optionally up to ten header link actions |
| `menu_item` | A tab in the team's tab bar | an iframe URL |
| `my_work_provider` | A source in My Work | a class implementing `OCA\TeamHub\MyWork\IWorkProvider` |

A package may register several of each.

**Which widget type.** `widget` is a list: you return rows and TeamHub draws
them in its own design, so your card matches every other card on the view and
costs one shared request. `widget_iframe` (since **4.13.24**) is a blank
canvas: TeamHub draws the card and you draw its interior, the same bargain a
team tab makes, at card size. Reach for the canvas when your content genuinely
is not a list — a chart, a board, a small form. Reach for rows otherwise: a
frame is a whole document, several of them on one home view is several
documents, and three numbers and a link do not need one.

---

## Governance: four levels, and only one is yours

This is the part worth reading before writing code, because it decides when your integration runs.

| Level | Who decides | What it means |
|---|---|---|
| **Available** | nobody — it is observed | Your app is installed, enabled, compatible, and registered successfully. |
| **Enabled** | a Nextcloud administrator | Administration → TeamHub → Integrations. **A newly discovered integration is disabled.** |
| **Included** | a Nextcloud administrator | The integration is included in a team template, under Administration → TeamHub → Policy → Team templates → Edit. This is what decides which teams take part. |
| **Shown / hidden** | a team administrator | Whether an individual widget or tab appears in their own team. |

Consequences you should design for:

- **Registering does not put you in front of anybody.** Installing your app changes no existing team. An administrator enables the integration on the Integrations tab, then includes it in the templates that should have it — which is done in each template's own editor on the Policy tab.
- **A team administrator cannot add your integration to their team**, and cannot remove it. They choose what to show.
- **Hiding a widget is a layout choice only.** It does not disable your integration, does not stop your My Work provider, does not stop background work and does not call any hook. If your app needs to know whether a user can *see* something, do not infer it from TeamHub.
- **Nothing is deleted when your integration is switched off.** Disabling it, disabling your app, removing it from a template or deregistering it all preserve the administrator's enablement, the template inclusions, every team's shown/hidden choices, the ordering, and of course all of your own data. Register the same identifiers again and the configuration is simply in effect once more.

---

## Stable identifiers

```
app_id + integration_key                   identifies the package
app_id + integration_key + capability_key  identifies a capability
```

Every durable decision hangs off these: the administrator's enablement, the template inclusions, each team's shown/hidden preference, and each team's grid position for your widgets.

**Never change them once shipped.** In particular, do not use as an identifier:

- a translated title — it changes per language and per release;
- a database id — it changes on reinstall;
- a class name — it changes when you refactor;
- an iframe URL — it changes when you reorganise your routes.

The old model had no stable key, which is exactly why configuration used to be lost on an app update.

---

## A complete example

One package: two widgets with actions, a My Work source and a team tab.

### 1. Register it in `boot()`

**Where the call goes, and why there.** `boot()`, not `register()`: the
registration resolves TeamHub's registry out of the server container and
writes to the database, and `register()` runs too early for either. Nothing
else in your app needs to change — there is no `info.xml` entry, no event
listener and no TeamHub dependency to declare. An app that TeamHub has never
heard of becomes an integration by making this one call.

```php
<?php
declare(strict_types=1);

namespace OCA\MyApp\AppInfo;

use OCA\MyApp\Integration\OpenItemsWidget;
use OCA\MyApp\Integration\TeamOverviewWidget;
use OCA\MyApp\MyWork\MyAppWorkProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {

    public function register(IRegistrationContext $context): void {
    }

    public function boot(IBootContext $context): void {
        // Always wrapped: TeamHub may not be installed, may be older than
        // this package needs, or may refuse the definition. None of that may
        // break your own app's boot.
        try {
            $registry = $context->getServerContainer()->get(
                \OCA\TeamHub\Integration\ITeamHubIntegrationRegistry::class,
            );

            $registry->registerPackage(
                new \OCA\TeamHub\Integration\IntegrationPackageDefinition(
                    appId:             'myapp',
                    integrationKey:    'collaboration',
                    title:             'My App',
                    description:       'Team collaboration around My App',
                    icon:              'ViewDashboard',
                    version:           '1.0.0',
                    minTeamHubVersion: '4.13.23',
                    capabilities:      [
                        new \OCA\TeamHub\Integration\WidgetCapabilityDefinition(
                            key:           'overview',
                            title:         'Overview',
                            description:   'Summary for this team',
                            icon:          'ViewDashboard',
                            providerClass: TeamOverviewWidget::class,
                            defaultOrder:  0,
                        ),
                        new \OCA\TeamHub\Integration\WidgetCapabilityDefinition(
                            key:           'open-items',
                            title:         'Open items',
                            description:   'Items requiring attention',
                            icon:          'FormatListBulleted',
                            providerClass: OpenItemsWidget::class,
                            defaultOrder:  1,
                        ),
                        new \OCA\TeamHub\Integration\MyWorkCapabilityDefinition(
                            key:           'assigned-work',
                            title:         'My App',
                            icon:          'CheckCircle',
                            providerClass: MyAppWorkProvider::class,
                        ),
                        new \OCA\TeamHub\Integration\MenuItemCapabilityDefinition(
                            key:         'team-view',
                            title:       'My App',
                            description: 'Open the application workspace',
                            icon:        'ViewDashboard',
                            iframeUrl:   '/apps/myapp/team-view',
                        ),
                    ],
                ),
                calledInProcess: true,
            );
        } catch (\Throwable $e) {
            // TeamHub is absent or unhappy. Carry on.
        }
    }
}
```

`calledInProcess: true` is **required**. A capability names a PHP class
TeamHub will instantiate, so registration over HTTP is refused — see
[Security](#security-what-teamhub-enforces-and-what-you-owe). It is an
assertion, not a switch: pass it from real in-process code, never from a
controller handling a request.

**Every boot re-registers.** The call is idempotent on
`app_id + integration_key`, so running it on every boot is the intended
pattern, not a waste — it is how a capability you add in a new release reaches
teams that already have the package, and how your integration comes back after
your app is disabled and re-enabled. It never resets an administrator's
enablement, a template inclusion, a team's shown/hidden choices or anyone's
grid positions.

#### The package fields

| Field | Required | Rules | What it does |
|---|---|---|---|
| `appId` | yes | your Nextcloud app id; `[a-z0-9]` then `[a-z0-9_-]`, ≤64 | first half of the package identity |
| `integrationKey` | yes | same shape, ≤64 | second half. Lets one app ship more than one package; use `'default'` or similar if you only ever need one |
| `title` | yes | non-blank, ≤255 | shown to administrators and team admins. Translate it in **your** app — TeamHub stores the string you give it |
| `capabilities` | yes | ≥1, keys unique within the package | duplicate keys are **refused**, not deduplicated: one of the two would silently never appear and you could not tell which |
| `description` | no | ≤500 | one sentence, shown beside the title |
| `icon` | no | ≤64 | a `vue-material-design-icons` name — see [Icon reference](#icon-reference) |
| `version` | no | ≤32 | **your** integration's version. Recorded and shown to administrators; TeamHub never branches on it |
| `minTeamHubVersion` | no | ≤32 | TeamHub **does** branch on this — see below |
| `maxTeamHubVersion` | no | ≤32 | likewise |
| `metadata` | no | array | recorded verbatim and shown to administrators. Put support URLs or build ids here |

**What happens outside the version range.** The package still registers and is
still stored; it is shown to the administrator as *incompatible* and is
effective nowhere. That is deliberate: refusing the registration outright
would show the administrator nothing at all, and they would have no way to
tell an incompatible integration from an app that never registered.

#### The fields every capability takes

All four capability classes extend the same base, so these are shared; each
type then adds its own (`providerClass`, `iframeUrl`, `actions`).

| Field | Required | Rules | What it does |
|---|---|---|---|
| `key` | yes | `[a-z0-9]` then `[a-z0-9_-]`, ≤64 | the third part of the identity. **Never change it once shipped** — every team's shown/hidden choice and grid position hangs off it |
| `title` | yes | non-blank, ≤255 | the card header, the tab label or the My Work source name. Translate it in your app |
| `description` | no | ≤500 | shown in Manage team and in the admin panel |
| `icon` | no | ≤64 | a `vue-material-design-icons` name |
| `defaultOrder` | no | int, default `0` | position among capabilities **of its own type** before a team arranges them. Lower first |
| `surfaces` | no | list of strings | **reserved — stored and returned, but nothing reads it yet.** Leave it out |
| `presentation` | no | array | **reserved in the same way.** A `size` hint is accepted and stored, but no surface renders differently for it today. Leave it out and size your content to the card |

Validation happens in the constructor, so a mistake throws at your own
registration call site rather than three layers inside TeamHub. That is also
why the whole block is wrapped in `try/catch`.

#### Did it register?

1. **Administration → TeamHub → Integrations** (or
   `GET /api/v1/admin/integrations`). A successful registration appears here,
   **disabled**, with its capabilities listed. This is the check that
   matters: if your package is not on this list, it did not register.
2. **Your own log line.** This is the important one, and it is yours to add.
   A malformed definition throws `ValidationException` **from the
   constructor**, in your `boot()`, before TeamHub is called at all — so
   TeamHub never sees it and logs nothing. A bare
   `catch (\Throwable $e) {}` therefore hides the one message that names the
   field you got wrong. Log it:

   ```php
   } catch (\Throwable $e) {
       $this->logger->debug('TeamHub registration skipped: ' . $e->getMessage());
   }
   ```

   Keep it at `debug` or `info`: on an instance without TeamHub this throws
   every boot, and that is normal rather than an error.
3. **`nextcloud.log`** covers the other half — a definition TeamHub accepted
   but could not store is logged by TeamHub with `app_id`,
   `integration_key` and the error class.

(`GET /api/v1/admin/integrations/register` sounds like it belongs here and
does not: it is a usage report over integrations TeamHub already knows —
which teams use what — not a registration diagnostic.)

Remember that appearing here is the *first* of four governance levels. A newly
discovered integration is **disabled**, and an administrator must then enable
it and include it in a team template before any team sees it.

### 2. A widget

```php
<?php
declare(strict_types=1);

namespace OCA\MyApp\Integration;

use OCA\MyApp\Service\MyService;
use OCA\TeamHub\Integration\ITeamHubWidget;

class TeamOverviewWidget implements ITeamHubWidget {

    public function __construct(
        private MyService $myService,
    ) {}

    public function getWidgetData(string $teamId, string $userId): array {
        // Authorise against YOUR records. TeamHub has established that
        // $userId is a member of $teamId and nothing more.
        $items = $this->myService->overviewForTeam($teamId, $userId);

        return [
            'items' => array_map(static fn (array $item): array => [
                'label' => $item['title'],
                'value' => $item['status'],
                'icon'  => 'CheckCircle',
                'url'   => '/apps/myapp/items/' . $item['id'],
            ], $items),
            'actions' => [
                ['label' => 'New item', 'icon' => 'Plus', 'actionId' => 'new_item'],
                ['label' => 'Open My App', 'icon' => 'ArrowRight', 'url' => '/apps/myapp/teams/' . $teamId],
            ],
        ];
    }

    public function getActionForm(string $actionId, string $teamId, string $userId): array {
        return match ($actionId) {
            'new_item' => [
                'title'        => 'New item',
                'submit_label' => 'Create',
                'fields'       => [
                    ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true],
                    ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
                ],
            ],
            default => ['fields' => []],
        };
    }

    public function handleAction(string $actionId, array $fields, string $teamId, string $userId): array {
        if ($actionId !== 'new_item') {
            return ['success' => false, 'message' => 'Unknown action'];
        }
        $title = trim((string)($fields['title'] ?? ''));
        if ($title === '') {
            return ['success' => false, 'message' => 'Title is required'];
        }
        // Re-check permission here. The form was rendered by TeamHub; the
        // write is yours.
        $this->myService->create($teamId, $userId, $title, (string)($fields['notes'] ?? ''));

        return ['success' => true, 'message' => 'Item created', 'refresh' => true];
    }
}
```

`OpenItemsWidget` is the same shape with a different query — that is the whole point of a package carrying two.

#### What `getWidgetData()` may return

TeamHub draws the card, so the payload is a shape rather than markup. It is
**sanitised, not trusted**: the shape is enforced, counts are capped and every
URL is re-validated before anything reaches a browser.

```php
[
    'items'   => [ /* … */ ],   // required, may be empty
    'actions' => [ /* … */ ],   // optional
]
```

**`items`** — at most **20**. Extra items are **dropped silently** from the
end, so cap and sort in your own query rather than relying on it.

| Key | Required | What it does |
|---|---|---|
| `label` | yes | the row's primary text |
| `value` | yes | the secondary text beside it. Pass `''` if the row has none |
| `icon` | no | a `vue-material-design-icons` name; unknown names fall back to a puzzle piece |
| `url` | no | makes the whole row a link |

An item `url` takes the **same rule as an iframe URL** — `/apps/…`,
`/index.php/…` or `https://…`. A URL that fails it is replaced with `null`, so
the row renders as plain text rather than as a dangerous link. (`javascript:`
in a list row is exactly as dangerous as one registered as a tab; the legacy
path validated actions but not items, which is the bug this closes.)

**`actions`** — at most **10**, also truncated silently. See
[Actions](#actions) below.

**Returning no items.** Return `['items' => []]`. TeamHub renders its own
empty state with your app's icon. Do not return a single row saying "nothing
to show" — it will look like data.

**Empty state, error state and your own exceptions** are three different
cards. Throwing is safe and is the right move when you genuinely cannot
answer: TeamHub catches every `\Throwable`, renders an error card for that one
widget, and leaves the rest of the home view alone.

### 3. A widget that wants more than two strings

`ITeamHubWidget` passes `$teamId` and `$userId`. A widget that wants the caller's role in the team, their locale or their time zone implements `ITeamHubContextAware` **as well**, and TeamHub prefers that method:

```php
use OCA\TeamHub\Integration\ITeamHubContextAware;
use OCA\TeamHub\Integration\ITeamHubWidget;
use OCA\TeamHub\Integration\TeamHubContext;

class OpenItemsWidget implements ITeamHubWidget, ITeamHubContextAware {

    public function getWidgetDataFor(TeamHubContext $context): array {
        return $this->render(
            $context->teamId,
            $context->userId,
            $context->locale,
            $context->isTeamAdmin(),   // level >= 8
        );
    }

    // Still required by ITeamHubWidget, and still called by an older TeamHub.
    public function getWidgetData(string $teamId, string $userId): array {
        return $this->render($teamId, $userId, 'en', false);
    }

    // … getActionForm() / handleAction() as above
}
```

### 4. A My Work source

My Work has **one** provider contract and external sources use it unchanged:

```php
<?php
declare(strict_types=1);

namespace OCA\MyApp\MyWork;

use OCA\TeamHub\MyWork\ActionResult;
use OCA\TeamHub\MyWork\IWorkProvider;
use OCA\TeamHub\MyWork\WorkItem;
use OCA\TeamHub\MyWork\WorkItemPage;
use OCA\TeamHub\MyWork\WorkQuery;

class MyAppWorkProvider implements IWorkProvider {

    // TeamHub assigns the source id (<app_id>_<capability_key>) and wraps this
    // class, so getId() here is only a fallback for direct use.
    public function getId(): string { return 'myapp_assigned-work'; }
    public function getName(): string { return 'My App'; }
    public function getIcon(): string { return 'CheckCircle'; }

    public function isAvailable(): bool { return true; }
    public function getUnavailableReason(): ?string { return null; }

    public function getCapabilities(): array {
        return [
            'actions'       => ['open'],
            'resourceTypes' => ['myapp_item'],
            'statuses'      => ['open', 'done'],
            'categories'    => ['action_required', 'upcoming'],
            'pagination'    => false,
            'incremental'   => false,
        ];
    }

    public function fetchItems(WorkQuery $query): WorkItemPage {
        // $query->teamIds is ALREADY narrowed to the teams where your package
        // is effective — available, enabled, and included in that team's
        // template. Return nothing outside it.
        $items = [];
        foreach ($this->repo->assignedTo($query->userId, $query->teamIds) as $row) {
            $items[] = WorkItem::make([
                'providerId'     => 'myapp_assigned-work',
                'providerItemId' => (string)$row['id'],
                'teamId'         => $row['team_id'],
                'title'          => $row['title'],
                'resourceType'   => 'myapp_item',
                'resourceUrl'    => '/apps/myapp/items/' . $row['id'],
                'dueAt'          => $row['due_at'],
                'status'         => $row['status'],
            ]);
        }

        return new WorkItemPage($items, count($items));
    }

    public function getItem(string $userId, string $providerItemId, array $allowedTeamIds): ?WorkItem {
        // The authorisation path before any action. Re-read from source.
        return $this->repo->findFor($userId, $providerItemId, $allowedTeamIds);
    }

    public function getAvailableActions(string $userId, WorkItem $item): array { return ['open']; }

    public function executeAction(string $userId, WorkItem $item, string $action, array $params): ActionResult {
        return ActionResult::success('Opened');
    }

    public function getSupportedFilters(): array { return ['teamIds', 'dueTo']; }
    public function getConfigSchema(): array { return []; }
}
```

Contract rules that matter:

1. **Never leak across teams.** Honour `$query->teamIds`. TeamHub re-filters afterwards, but over-returning is a bug.
2. **Never throw for "nothing to do."** Return an empty page. Throw only for a genuine fault — TeamHub isolates it and shows a per-source notice.
3. **Be honest about availability.** A provider that fails silently produces an empty queue that reads as "you're all caught up", which is the worst possible answer for this feature.
4. **Authorise in both directions.** `getAvailableActions()` reports what the user may do; `executeAction()` re-checks it.

Your provider knows nothing about templates or enablement. TeamHub wraps it, narrows the team set, and stops calling it when the governance says so.

### 5. A team tab

```php
new MenuItemCapabilityDefinition(
    key:       'team-view',
    title:     'My App',
    iframeUrl: '/apps/myapp/team-view',
)
```

TeamHub loads the URL in a sandboxed iframe with **its own verified team id** appended:

```
/apps/myapp/team-view?teamId=<circle-uuid>
```

In your controller:

```php
#[NoAdminRequired]
public function teamView(string $teamId = ''): TemplateResponse {
    // Read it, then check membership yourself. TeamHub appends a team id it
    // has verified for the current user, but your page is a route like any
    // other and somebody can call it directly.
    if (!$this->myMembership->maySee($teamId, $this->userId)) {
        throw new \OCP\AppFramework\Http\NotFoundException();
    }

    return new TemplateResponse('myapp', 'team-view', ['teamId' => $teamId]);
}
```

The tab is a full-width canvas with its own TeamHub toolbar above it
(*Reload* and *New tab*). Everything below that is yours.

#### The iframe contract

This applies identically to a **team tab** (`menu_item`) and a
**blank-canvas widget** (`widget_iframe`). The two differ in size and in what
surrounds them, never in the terms.

**The URL rule.**

| | |
|---|---|
| Accepted | `/apps/…`, `/index.php/…`, `https://…` |
| Refused | `http://`, `javascript:`, `data:`, protocol-relative `//host`, anything with a control character or a NUL, anything empty |

Applied at registration **and again every time the surface is rendered**, so a
URL stored before a tightening of the rule is dropped rather than served.
Dropped means exactly that: the tab or the card is **absent from the payload
and logged**, not rendered with an empty `src`. Nothing tells the viewer why,
so if a surface of yours vanishes after a TeamHub upgrade, check
`nextcloud.log` for the drop warning before suspecting governance.

**What TeamHub appends.** Its own verified team id, as a Circles UUID:

```
/apps/myapp/team-view?teamId=<circle-uuid>
```

It is appended with `&` when your URL already has a query string, so
`/apps/myapp/view?mode=compact` arrives as
`/apps/myapp/view?mode=compact&teamId=…`. The id comes from the membership
check that produced the surface — never from the stored URL and never from
request input.

**The sandbox depends on origin, and not the way you might expect.**

| Your URL | What TeamHub does |
|---|---|
| Same origin (`/apps/…` on this instance) | **No `sandbox` attribute at all.** Your app is installed here and is already trusted code; sandboxing it without `allow-same-origin` would break it, and with `allow-same-origin` it would be theatre |
| Cross origin (`https://` elsewhere) | `sandbox="allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox"` |

Note what a cross-origin embed does **not** get: `allow-same-origin` is
**denied**, so your page has no cookies, no `localStorage` and no access to
its own origin's storage inside the frame. Also denied:
`allow-top-navigation` (you cannot redirect the TeamHub window),
`allow-modals` (no `alert`/`confirm`/`prompt`), `allow-downloads`,
`allow-pointer-lock` and `allow-presentation`. `referrerpolicy` is
`strict-origin-when-cross-origin` and `allow=""` disables every permission
feature. **Design a cross-origin embed to carry its own session in the URL or
in memory**, because browser storage is not available to it.

**CSP.** Send `Content-Security-Policy: frame-ancestors 'self'` from your
page, or the browser refuses to frame it.

**Membership is yours to check.** TeamHub appends a team id it verified for
the signed-in viewer, but your page is an ordinary route and anybody can call
it with any team id. This is not optional, and it is the single most common
mistake in an integration.

### 6. A blank-canvas widget

Since **4.13.24**. A card on the team's home view whose inside is yours.

```php
new \OCA\TeamHub\Integration\IframeWidgetCapabilityDefinition(
    key:       'board',
    title:     'Board',
    iframeUrl: '/apps/myapp/team-card',
)
```

No provider class: there is nothing for TeamHub to call. The URL is loaded in
a frame with TeamHub's own verified team id appended, exactly as a team tab is:

```
/apps/myapp/team-card?teamId=<circle-uuid>
```

**What TeamHub keeps** — the card's position and size in the grid, the team's
arrangement of it, the header with your app's icon and your `title`, the
collapse control, and the team administrator's shown/hidden switch. A canvas
card is ordered together with your row widgets, because a team arranges one set
of cards and does not care which side of a frame each one is drawn on.

**What you get** — everything inside the body.

**Render for the size, and own your height and your scroll.** The card is a few
hundred pixels wide, a team can resize it or collapse it to its header, and on a
phone the same page gets the whole canvas. TeamHub gives the frame a definite
height on every surface — a row count on the desktop grid, a stated height on
the tablet stack, the remaining canvas on mobile — and your page lays itself out
against it:

```css
html, body { height: 100%; margin: 0; }
body       { overflow: hidden; }   /* the document itself never scrolls */
.my-pane   { flex: 1; min-height: 0; overflow-y: auto; }  /* this does */
```

That is the whole contract. **An iframe scrolls perfectly well** — before
4.13.26 this section said a page needing a scrollbar at card size belonged on a
tab, which was over-cautious and wrong: what does not work is a page that is
taller than the frame *and* has given no pane an `overflow-y`, because nothing
is then left that can scroll. A page laid out as above fits any height TeamHub
hands it, including a small one.

**The surface is TeamHub's.** Since 4.13.27 TeamHub sets `margin`, `padding`,
`height` and `background` on the `html` and `body` of a **same-origin** embed —
the card already has a background, and Nextcloud would otherwise paint the
instance's wallpaper inside it. Note it is the `background` shorthand: setting
only `background-color` in your own stylesheet does not displace a wallpaper
*image*, which is why this is ours rather than yours. Nothing else of your page
is touched. A page on another origin is never reached and owns its own
background — give it one.

**Your frame goes inert while the team rearranges the grid.** Since **4.13.32**
TeamHub sets `pointer-events: none` on the frame for as long as the home view is
in edit mode, and clears it on the way out. A frame is a separate document, so
while it was taking pointer events a drag or a resize that crossed it stopped
dead, and shrinking a card by its bottom-right handle was close to impossible.
Nothing is required of your page and nothing about it changes: it stays loaded,
it keeps its state, and it is not told. If you are debugging why a click does
nothing, check whether the team is in edit mode first.

Two symptoms are worth knowing, because they look like your bug and are not
always yours. **Content rendered tiny, and nothing scrollable** was TeamHub's
own: until 4.13.26 the tablet and mobile surfaces wrapped the frame in a body
with no height, so it fell back to an iframe's intrinsic 150px whatever the card
measured. If you see it on 4.13.26 or later, the frame has a real height and the
suspect is a pane of yours without an `overflow-y`.

**Header actions: links only.** Since **4.13.29** a canvas card may put items
in the card's own 3-dot menu, declared at registration:

```php
new \OCA\TeamHub\Integration\IframeWidgetCapabilityDefinition(
    key:       'board',
    title:     'Board',
    iframeUrl: '/apps/myapp/team-card',
    actions:   [
        ['label' => 'New item', 'icon' => 'Plus', 'url' => '/apps/myapp/item/new'],
        ['label' => 'Open in MyApp',               'url' => 'https://myapp.example.com/'],
    ],
)
```

Each one is **a label, an optional icon and a URL** — ten at most. TeamHub
renders them in the header menu and sends the person to the URL in a new tab,
with the verified team id appended exactly as the frame's own URL gets it.

That is the whole contract, and the limit is the point rather than an
omission. A `widget` capability's actions open a TeamHub modal built from
`getActionForm()`, because TeamHub is drawing that card and knows its state. A
canvas owns its interior and TeamHub cannot see inside it, so there is no
channel in either direction: nothing is posted into your frame and nothing is
read out of it, and the two can therefore never disagree about what the card
is showing.

**So put a destination here, not a verb.** `New ticket`, `All my assets`,
`Open in MyApp` are right. `Refresh`, `Save`, `Next page` are not — those act
on what your frame is currently showing, and they belong to a control inside
your frame, which is the only place with the state to carry them out.

The URL takes the same rule as `iframeUrl` (`/apps/…`, `/index.php/…` or
`https://…`), checked at registration and again at every render: a link
TeamHub draws is a link TeamHub is answerable for. A malformed action refuses
the whole capability at registration rather than rendering a dead menu item.

Until 4.13.29 there were no header actions at all, on the argument that a
second set of controls on a card whose body you draw would be two apps drawing
one card. The argument was sound and is what shaped the restriction above; what
it missed is that an app's menu drawn *inside* the body reads as part of the
content rather than as the card's controls, and ends up sitting beside the
collapse control TeamHub already drew.

**Same obligations as a tab.** The URL rule, the origin-dependent sandbox,
the appended team id and the membership check are all identical — see
[The iframe contract](#the-iframe-contract) in section 5. A canvas is a tab's
bargain at card size, not a looser one.

**No widget-data endpoint.** `…/packages/<app>/<key>/<capability>/data` returns
404 for a `widget_iframe`, and the batched `all-widget-data` skips it. Nothing
is wrong — there is no data to fetch, because your page fetches its own.

---

## Actions

Three kinds, and which you get depends on the capability type. The difference
is not cosmetic: it follows from who is drawing the card.

| Kind | Declared | Where | What a click does |
|---|---|---|---|
| **Modal action** (`widget`) | returned by `getWidgetData()` at render time | the card's 3-dot menu | opens a TeamHub-drawn form from `getActionForm()`, submits to `handleAction()` |
| **Link action** (`widget`) | returned by `getWidgetData()` at render time | the card's 3-dot menu | opens the URL in a new tab |
| **Header link** (`widget_iframe`) | declared at **registration** | the card's 3-dot menu | opens the URL in a new tab, with the verified `?teamId=` appended |

A `menu_item` (team tab) has no TeamHub-drawn actions at all: the tab is a
full-width canvas with its own toolbar, and its controls are yours, inside it.

### Actions on a `widget`

```php
'actions' => [
    ['label' => 'New item',    'icon' => 'Plus',      'actionId' => 'new_item'],
    ['label' => 'Open My App', 'icon' => 'ArrowRight', 'url'     => '/apps/myapp/teams/' . $teamId],
],
```

| Key | Required | What it does |
|---|---|---|
| `label` | yes | the menu item's text. An action with a blank label is dropped |
| `icon` | no | a `vue-material-design-icons` name |
| `actionId` | either this or `url` | the id handed back to `getActionForm()` and `handleAction()` |
| `url` | either this or `actionId` | a plain link, same URL rule as everywhere else |

**`actionId` wins when both are present.** An app that sets both as a
"fallback" gets the modal, not the link.

Because these come back from `getWidgetData()`, they can depend on what you
found: hide *Approve* when there is nothing to approve, and it is simply not
in the menu that render.

#### The action form

`getActionForm()` returns the form TeamHub draws:

| Key | Required | What it does |
|---|---|---|
| `title` | no | the modal's heading; defaults to the action's label |
| `submit_label` | no | the submit button's text; defaults to *Submit* |
| `fields` | yes | the inputs. **An empty list means "fall back to the `url`"** |

Each field:

| Key | Required | What it does |
|---|---|---|
| `name` | yes | the key this field's value arrives under in `handleAction()` |
| `label` | yes | shown above the input |
| `type` | yes | `text`, `email`, `textarea` or `checkbox` |
| `required` | no | default `false` |
| `value` | no | pre-filled value |
| `placeholder` | no | hint text |

> **`date` is accepted by the server and not yet drawn.** It passes the
> server's field-type check but no input renders for it, so the field is
> invisible and its value arrives empty. Use `text` until this is fixed.

#### Handling the submission

```php
return ['success' => true, 'message' => 'Item created', 'refresh' => true];
```

| Key | Required | What it does |
|---|---|---|
| `success` | yes | chooses the toast: `true` shows `message` as a success, `false` shows it as an error |
| `message` | no | shown to the user as a toast. With no `message`, a failure is **silent** — always send one when `success` is `false` |
| `refresh` | no | `true` reloads this widget's data after the action |

**The modal closes either way.** A `success: false` response is a completed
request, so TeamHub closes the form and reports your `message`; it does not
hold the form open for a correction. The form stays open only when the request
itself fails (a thrown exception, a non-2xx). So validate before you write,
and return `success: false` with a message the user can act on from
elsewhere — or accept the input and let them fix it in your own app.

**Re-check permission here.** TeamHub drew the form and verified that the
caller is a member of the team. It has not checked that they may perform
*your* action on *your* record — nobody but you can.

### Header links on a `widget_iframe`

Declared at registration, because TeamHub cannot see inside your frame. See
[section 6](#6-a-blank-canvas-widget) for the full rules; the short version is
that they are links only, ten at most, each taking the standard URL rule and
carrying the verified team id.

---

## The provider context

`TeamHubContext` is what TeamHub hands a provider. Every field has one source and a caller cannot supply any of them:

| Field | Where it comes from |
|---|---|
| `teamId` | the verified route parameter, after the membership check |
| `userId` | the session — never request input |
| `level` | the caller's Circles level (1 member, 4 moderator, 8 admin, 9 owner) |
| `locale`, `timezone` | the viewer's own Nextcloud settings |
| `appId`, `integrationKey`, `capabilityKey` | which capability is being called |
| `teamConfig` | per-team configuration, when the calling surface has it |

It is immutable, and it is **not** an authorisation result for your own records: TeamHub states who is asking and about which team. Whether that person may see a particular record of yours is your question.

---

## Registration lifecycle

| Event | What TeamHub does |
|---|---|
| Your app boots and registers | Stores/updates the package and its capabilities. Nothing becomes visible. |
| Your app boots and registers again | Idempotent. Same row, same ids. No duplicate, no reset of enablement, templates, visibility or ordering. |
| You add a capability in a new release | It appears automatically for every team whose template already includes the package, shown by default, at its `defaultOrder`. No administrator action needed. |
| You stop registering a capability | Its row is kept and marked unregistered, so it renders nowhere. Bring it back under the same key and every team's preference is intact. |
| Your app is disabled | The integration becomes unavailable immediately — TeamHub reads app state live. Nothing is deleted. |
| Your app is re-enabled | It registers on its next boot and everything is in effect again. |
| You call `deregisterPackage()` | The package stops operating. Nothing is deleted. Register the same identifiers to restore it. |

**Graceful handling when TeamHub is absent.** Wrap the whole `boot()` block in `try/catch (\Throwable)`. Do not check `IAppManager` instead — TeamHub can be installed and still refuse your definition, and a `ValidationException` must not reach your users.

**Graceful handling when your app is disabled.** Nothing to do. TeamHub stops calling you and keeps your configuration.

---

## Deregistering

```php
$registry->deregisterPackage('myapp', 'collaboration', calledInProcess: true);
```

Only for a deliberate withdrawal. An app being disabled needs no call at all.

---

## Performance

- **Keep `getWidgetData()` under 500 ms** and cache aggressively. It is on the critical path of a team's home view.
- **A hidden widget's provider is never constructed**, so a card a team does not want costs nothing.
- **My Work runs providers under a shared wall-clock budget.** Once it is spent, the remaining providers are skipped and reported as `timeout` rather than being started. Bound your SQL; there is a per-provider row cap but a provider that hangs forever still hangs the request.
- Items are capped at **20** and actions at **10** per widget. Extra entries are dropped silently.

---

## Error isolation

TeamHub catches every `\Throwable` from your code.

- A widget that throws yields an error card. The team home, the other widgets and every other integration are unaffected.
- A My Work provider that throws is marked `error` for that one source; the rest of the queue renders.
- A lifecycle hook that throws is logged and ignored. It cannot veto a team's creation or deletion.
- A provider that cannot be constructed is skipped and logged.

Log lines carry `app_id`, `integration_key`, `capability_key`, `team_id` and the error class — **never** your payload. Your own messages are not passed through to users either, because they can name internals.

---

## Security: what TeamHub enforces, and what you owe

TeamHub enforces, server-side, on every entry point:

- only a Nextcloud administrator enables an integration or changes template inclusion;
- only a team admin or owner changes a visual preference; an ordinary member cannot;
- a non-member cannot render your capability data or open your team tab;
- a team administrator cannot activate an integration outside their template;
- provider classes come only from in-process registration — the HTTP route refuses one;
- iframe URLs are validated at registration and again at render;
- your widget output is sanitised: shape enforced, counts capped, every URL re-validated;
- no visual preference can disable a My Work provider;
- no disable, hide or template removal deletes anything of yours.

You owe:

- **authorisation of your own records**, in `getWidgetData()`, `handleAction()`, `fetchItems()`, `getItem()` and `executeAction()`;
- **membership re-checking in your iframe page**, because it is an ordinary route;
- **no secrets in widget output** — it goes to every member who can see the card.

---

## Legacy API: `registerIntegration()`

The single-registration API from 2.41 onwards. It still works and is still supported.

```php
$teamHub = $context->getServerContainer()->get(\OCA\TeamHub\Service\IntegrationService::class);

$teamHub->registerIntegration(
    appId:           'myapp',
    integrationType: 'widget',        // or 'menu_item'
    title:           'My Widget',
    description:     'Shows recent items from My App',
    icon:            'ChartBar',
    phpClass:        \OCA\MyApp\Integration\TeamHubWidget::class,
    calledInProcess: true,
);
```

**What happens now.** Each call is additionally adapted into a package:

```
app_id          your app
integration_key 'legacy'
capability_key  'widget' or 'menu_item'
```

So an app that registered **both** a widget and a menu item used to produce two unrelated switches a team administrator toggled separately; it now produces **one package with two capabilities**, grouped under your app's name — with no change on your side. It is governed like any other package: an administrator enables it and includes it in templates.

**One behaviour change, in 4.13.23.** `php_class` is no longer accepted over HTTP (`POST /api/v1/ext/integrations/register`). A widget names a class TeamHub executes, so it must be registered in-process. `menu_item` over HTTP is unaffected.

**Migrating to the package API.** Register with `integrationKey: 'legacy'` and your new package **is** the old one — the administrator's enablement, the template inclusions and every team's layout carry over, and the "Legacy API" badge clears. Any other key creates a separate package that an administrator must enable and include from scratch.

`ITeamHubWidget` is unchanged and is not deprecated: it is the widget contract for packages too.

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
| 4.13.32 | **A canvas widget's frame goes inert while the team rearranges the home grid** (`pointer-events: none`, cleared when edit mode ends). A frame is a separate document, so while it took pointer events a drag or a resize that crossed the card body stopped dead and shrinking a card by its corner handle was close to impossible. No API change and nothing required of your page: it stays loaded and keeps its state. The one thing to know while debugging: a click that does nothing inside a canvas card may mean the team is in edit mode. |
| 4.13.27 | **A same-origin embed's `html`/`body` surface is normalised** — margin, padding, height and the `background` shorthand — in a canvas widget as it already was in a team tab, so a card never shows the instance wallpaper behind your page. No API change, and nothing else of your page is touched. A cross-origin page is not reached and should set its own background. |
| 4.13.26 | **A blank-canvas widget's frame is guaranteed a definite height on every surface** — a row count on the desktop grid, a stated height on the tablet stack, the remaining canvas on mobile. Before this the tablet and mobile surfaces gave the frame no height at all and it fell back to an iframe's intrinsic 150px, so your page rendered for a 150px viewport inside a card several hundred pixels tall. No API change: lay your page out against `html, body { height: 100% }` and give the pane that can overflow its own `overflow-y: auto`. A canvas card also opens larger than a row card now (five grid rows, floor of three) because a frame is the one card TeamHub cannot measure. §6 has the contract. |
| 4.13.29 | **Header actions on a blank-canvas widget.** `IframeWidgetCapabilityDefinition` takes `actions`: up to ten `['label' => …, 'url' => …, 'icon' => …?]` entries, rendered in the card's 3-dot menu and opened in a new tab with the verified team id appended. Links only — there is no channel into or out of the frame, which is what keeps the header and the canvas from disagreeing about state. Each URL takes the `iframeUrl` rule and is re-checked at every render. Reverses the "No actions" rule of 4.13.24 for this capability type only: a team tab's toolbar is unchanged, and a `widget` capability's provider-supplied actions work exactly as before. Nothing to migrate — a capability that declares none behaves as it always did. |
| 4.13.24 | **Blank-canvas widgets.** A fourth capability type, `widget_iframe`, via `IframeWidgetCapabilityDefinition`: a card on the team's home view whose body your app draws in a sandboxed iframe, on the same terms as a team tab. TeamHub keeps the card — position, size, header, collapse, the team's shown/hidden switch — and orders canvas cards together with row widgets. No provider class and no `actions`; the widget-data endpoints return 404 for one. `ITeamHubWidget` and the `widget` type are unchanged. Nothing to migrate. Also in this release, three fixes to how a registered package reaches a team: a package widget is now part of the home grid's layout rather than a card sitting outside it, a package team tab renders in the tab bar and survives a tab rebuild, and template inclusion moved to Administration → TeamHub → Policy → Team templates → Edit (the API routes are unchanged). |
| 4.13.23 | **Integration packages.** One registration carries many widgets, team tabs and My Work sources: `ITeamHubIntegrationRegistry::registerPackage()` with `IntegrationPackageDefinition` plus `WidgetCapabilityDefinition` / `MenuItemCapabilityDefinition` / `MyWorkCapabilityDefinition`. Stable identity is `app_id` + `integration_key` (+ `capability_key`). Four governance levels — available, administrator-enabled, included in a team template, shown per team — and nothing is deleted when any of them says no. `ITeamHubContextAware` (opt-in) hands a widget the verified `TeamHubContext`. `ILifecycleProvider` (opt-in) is documented, and its hooks fire for any registered provider class that implements it. `registerIntegration()` still works and is adapted into a package; its one behaviour change is that `php_class` is no longer accepted over HTTP. |
| 4.9.7 | My Work provider contract (`OCA\TeamHub\MyWork\IWorkProvider`, internal — registered in `Application.php`) extended, all additive: `WorkItemPage::$warnings` (`auth_required` \| `partial` \| `budget` \| `not_connected` — a provider that answered but not cleanly; the registry copies them to `providerStatus[].warnings` and the frontend shows one notice each); `WorkQuery::$metadataFilters` (`{ metadataKey: [values] }`, applied centrally — today `projectId` and `type`); `facets` on the payload from any item carrying `metadata.projectId` + `projectName` / `metadata.type`; `groupBy=project` (an item that names a project groups under it, others under their team); `metadata.opensIn` (an app name — the row renders an "opens in {app}" chip). `OPENPROJECT.md` §6.2. |
| 4.9.6 | Workspace provisioning steps (`OCA\TeamHub\Service\Provisioning\Step\StepInterface`): an app that provisions a resource for a new team can register a step in `StepRegistry` — idempotent `run()`, a per-resource `rollback()`, `applies()` per blueprint — and record it in the `teamhub_resource_link` ledger. Internal for now (constructor-registered, not a public registration API); `OPENPROJECT.md` §5.3 "Extension points". |
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
| Asking the widget-data endpoint for a `widget_iframe` | 404 "no widget data to fetch" | Nothing to fix — a canvas card's body is fetched by the browser from your own URL |
| A `widget_iframe` with a `providerClass` | Refused at registration | A canvas card has no provider; use `widget` if you want TeamHub to draw rows |
| Clicking inside a canvas card and nothing happening | The frame ignores the pointer | The team is in the home view's edit mode, where TeamHub makes the frame inert so a drag or resize can cross it (4.13.32). Leave edit mode |
| A `widget_iframe` page whose content is taller than the card | Content cut off, nothing scrolls | Give one pane `flex: 1; min-height: 0; overflow-y: auto`. The frame has a real height on every surface since 4.13.26 — the document can't scroll if your CSS sets `body { overflow: hidden }`, so a pane must |
| A `widget_iframe` page with no height of its own | Content sits in a strip at the top of the card | `html, body { height: 100% }`; the frame is as tall as the card, your document has to take that height |
| A cross-origin embed expecting cookies or `localStorage` | Silent auth failures, nothing persists | `allow-same-origin` is **denied** for cross-origin frames. Carry the session in the URL or in memory, or serve the page from this instance |
| A `date` field in `getActionForm()` | The field is invisible and arrives empty | Known gap — the server accepts the type, no input is drawn for it. Use `text` |
| Expecting `presentation` or `surfaces` to change anything | No effect | Both are stored and returned but nothing reads them yet. Size your content to the card instead |
| A `widget_iframe` action with an `actionId` | Refused at registration | A canvas has no provider to call. Header actions are **links only** — see [Actions](#actions) |
| More than 20 items or 10 actions from `getWidgetData()` | The extras just disappear | They are truncated silently. Cap and sort in your own query |
| A tab or card that vanished after a TeamHub upgrade | No error anywhere the user can see | Its stored URL stopped passing the rule and was dropped. `nextcloud.log` carries the warning with your `app_id` and `capability_key` |

---

## Questions and support

- GitHub: https://github.com/justindoek/teamhub
- Issues: https://github.com/justindoek/teamhub/issues
