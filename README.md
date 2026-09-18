<p align="center">
  <img src="img/logo.svg" width="64" height="64" alt="TeamHub icon">
</p>

<h1 align="center">TeamHub</h1>

<p align="center">
  A team collaboration hub for Nextcloud — a shared home for every Nextcloud Team (Circle), with messaging, widgets, an activity feed, and an open integration layer for other apps.
</p>

<p align="center">
  <img alt="Nextcloud 33-34" src="https://img.shields.io/badge/Nextcloud-33%E2%80%9334-0082c9">
  <img alt="PHP 8.1-8.4" src="https://img.shields.io/badge/PHP-8.1%E2%80%938.4-777bb4">
  <img alt="License: AGPL-3.0" src="https://img.shields.io/badge/license-AGPL--3.0-blue">
</p>

<p align="center">
  <img src="screenshots/teamhub-main.jpg" alt="TeamHub team home view" width="800">
</p>

---

## What is TeamHub?

Nextcloud's built-in Dashboard is personal — it's about *you*. TeamHub is the team-scoped equivalent: every Nextcloud Team (Circle) gets its own home with a message stream, a widget-driven overview, and quick access to the apps that team actually uses — Talk, Files, Calendar, Deck — all in one place.

It started as a visual mock-up to discuss what team-based working could look like inside Nextcloud, and grew into a full workspace layer built on top of Nextcloud Teams. TeamHub works entirely within your own Nextcloud instance — no external services, no data leaving your server.

## Features

- **My Work** — one personal queue of what every team needs from you, across sources. Deck cards assigned to you, file approvals waiting on you, and decisions awaiting your approval land in the same list, grouped into Action required, Today, Upcoming, Waiting for others and Completed. Every row says why it is there, which team it belongs to, and who is waiting on whom — and you can approve, complete, snooze or open it without leaving TeamHub. New sources plug in behind one provider contract.
- **What's new** — one feed of everything happening across the teams you're in, plus public posts from teams you're not. Comments and Talk replies show on each item and you can answer, or vote in a Talk poll, without leaving the page. Filter by source, period, team, message type, or just what mentions you — and save that as your default.
- **Message stream** — post announcements, questions, and polls to the team. Pin important messages; reply in threads.
- **Activity feed** — one view of recent file, calendar, task, and member changes across everything the team has access to.
- **App tabs** — quick links to the team's shared Talk chat, Files folder, Calendar, and Deck board, opened inline.
- **Collaboration-first file opening** — open a file from TeamHub and the sidebar opens with it on the team's conversation about that file, one *Join conversation* click away. No hunting through Details → Chat first — and nothing lands in your Talk conversation list until you join.
- **Timeline** — a visual, zoomable timeline aggregating Deck cards, Calendar events, Decisions, and Messages on one canvas, with admin-defined milestones and connector lines showing how items relate to each other.
- **Decisions** — propose, discuss, and formally approve team decisions, with categories, approvers, linked tasks, and a full audit trail.
- **Sidebar widgets** — upcoming calendar events, open Deck tasks, pages, presence/schedule, and an activity snapshot. Layout is per-user and drag-to-rearrange.
- **Team management** — invite by user, group, email, or federated account; manage roles and pending requests.
- **OpenProject** — an *OpenProject project* team template: create the team, pick an OpenProject project you administer (or a public one), and every member gets a concise cockpit on the team home: status, open / overdue / due-soon counts, the next milestone, recently completed work, and their own work packages — assigned to them, overdue, due this week, recently updated — each a click from OpenProject itself. Everything is read as you, through the official OpenProject Integration app, so you only ever see what OpenProject would show you. The project stays managed in OpenProject; TeamHub is the workspace around it.
- **OpenProject workspaces** (4.9.6) — the same template can also **build the whole workspace**: create a new OpenProject project from one of your OpenProject templates (or connect an existing one), and TeamHub sets up the team, its files, Talk conversation, calendar, knowledge space and modules, adds the members on both sides with roles your administrator mapped, and lays out the dashboard — as one recorded operation you can watch, retry step by step, or roll back safely. The OpenProject project stays OpenProject's; TeamHub never deletes it.
- **OpenProject across your teams** (4.9.7) — your OpenProject work follows you into **My Work**: overdue, due today, due this week, recently assigned, changed since your last visit, created by you and still unassigned, completed, and the projects' upcoming milestones — one row per work package, with OpenProject's own type, status and priority, filterable by project and work type, grouped by project if you like, with one-click views. **What's new** shows your projects' news — and each news item is posted once into the team's stream with a *Source: OpenProject* pill that opens it there, so members without an OpenProject account read it too. **Upcoming events** shows the project's meetings, and (4.9.10) copies them one way into the team calendar — created, updated and removed as OpenProject changes them, so they are in every member's calendar client. The team home's Project info widget says what needs *your* attention. Everything is read live as you, and a slow or unreachable OpenProject never takes the rest of TeamHub with it.
- **Create work packages from the team home** (4.9.15) — the Upcoming tasks widget's menu offers *Create OpenProject work package* to everyone OpenProject lets add work packages to the project: subject, type, assignee, due date and description, with the type and assignee lists OpenProject offers *you*. It is created as you, OpenProject validates it, and it appears in the widget and the counts right away. When OpenProject cannot be reached, the Project info widget shows one *connection lost* notice and the other widgets stay quiet (4.9.13).
- **OpenProject is a licensed module** (4.9.16) — switched on by a Nextcloud administrator under Admin → TeamHub → Integrations → Modules when the instance has an OpenProject to talk to, off by default. Off, every OpenProject surface is hidden and nothing is deleted; existing teams keep their link.
- **My Work sources, clustered** (4.9.17) — the source tabs read *All · Deck · Files · Decisions · Meetings · Teams · Administration · OpenProject*: everything about files under Files, a team admin's own housekeeping under Teams, and the Nextcloud administrator's queue under Administration — shown only to administrators.
- **Open integration layer** — other Nextcloud apps can register their own sidebar widgets or sandboxed iframe tabs into a team's home. See [`developers.md`](developers.md).
- **Bulk team import** — admins can roll out many teams at once from a CSV, one row per team. Each row names its template, its policy, its members and its owner; the apps, modules and privacy settings follow from the template and the policy, exactly as they would in the wizard — so imported teams come out identical to wizard-created ones, and each one lands with the right owner rather than the admin who ran the import. Upload gives you a dry run first: every row is checked, conflicts and errors are shown with reasons, and nothing is created until you confirm.
- **Admin controls** — org-wide rollout settings, creation permissions, optional modules (Presence, IntraVox), audit and archive tooling. The Compliance tab reports the instance's state against the ISO/IEC 27001:2022 controls TeamHub can evidence, and exports it as a printable report — including the controls it cannot evidence.

## Requirements

- Nextcloud **33 – 34**
- PHP **8.1 – 8.4**
- MySQL/MariaDB or PostgreSQL
- The **Teams (Circles)** app, enabled

## Installation

Grab the latest release zip from the [Releases](../../releases) page, extract it into your Nextcloud `apps/` directory, and enable it:

```bash
unzip teamhub-x.y.z.zip -d /path/to/nextcloud/apps/
chown -R www-data:www-data /path/to/nextcloud/apps/teamhub
sudo -u www-data php occ app:enable teamhub
```

Database tables are created automatically on first enable. For building from source, admin settings, optional integrations, and background jobs, see **[INSTALL.md](INSTALL.md)**.

## Documentation

| Doc | What's in it |
|---|---|
| [INSTALL.md](INSTALL.md) | Installation, upgrading, admin settings, background jobs |
| [developers.md](developers.md) | Building widgets and tab integrations that plug into a team's home |
| [DESIGN.md](DESIGN.md) | Architecture decisions and the reasoning behind them |
| [ROADMAP.md](ROADMAP.md) | Forward-looking feature proposals |
| [CHANGELOG.md](CHANGELOG.md) | What shipped, by version |

## Building an integration

Other Nextcloud apps can add a sidebar widget or a tab-bar menu item to every team's home — resolved and called in-process via Nextcloud's DI container, no HTTP round-trip required:

```php
$teamHub->registerIntegration(
    appId:           'myapp',
    integrationType: 'widget',
    title:           'My Widget',
    description:     'Shows recent items from My App',
    icon:            'ChartBar',
    phpClass:        \OCA\MyApp\Integration\TeamHubWidget::class,
    calledInProcess: true,
);
```

Full guide, including the menu-item (iframe tab) integration type: **[developers.md](developers.md)**.

## Contributing

Issues and feature ideas are welcome on the [issue tracker](https://github.com/JustinDoek/nextcloud-teamhub/issues). Check [ROADMAP.md](ROADMAP.md) for what's already being considered.

## License

TeamHub is licensed under the [GNU Affero General Public License v3.0](LICENSE).
