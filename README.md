# TeamHub for Nextcloud

TeamHub gives every Nextcloud Team a proper home. It wraps the existing Teams (Circles) infrastructure and provisions a shared workspace — messages, Talk chat, Files folder, Calendar, Deck board — all accessible from one place.

![TeamHub screenshot](screenshots/teamhub-main.svg)

## Features

### Team workspace
Each team gets a tab bar linking directly to its shared apps:
- **Home** — team messages, comments, polls and questions
- **Chat** — opens the team's shared Talk conversation
- **Files** — opens the team's shared Files folder
- **Calendar** — opens the team's shared calendar
- **Deck** — opens the team's shared Deck board
- Custom links can be added to the tab bar by team admins

### Sidebar widgets
Always visible next to the message stream:
- **Team info** — description, owner, member avatar stack, admin actions
- **Upcoming events** — next events pulled from the team calendar
- **Open tasks** — cards from the team Deck board
- **Pages** — pages from the team's IntraVox space (if installed)
- **Activity snapshot** — 5 most recent events across all team resources, with a "More" link

### Activity feed
A dedicated full-canvas view showing everything that happened in the team over the past 30 days, grouped by day — file uploads and edits, calendar events, Deck card changes, member joins and leaves.

### Team messages
Post announcements, questions and polls to your team. Members are notified. Messages support inline editing and threaded comments.

### Team management
- Create teams with name, description and visibility settings
- Invite members by local user, group, email address or federated account (configurable per instance by admins)
- Remove members, approve or reject join requests
- Configure team options (open join, invite-only, protected, visible)
- Browse and request access to teams you're not a member of

### Admin settings
- Set a custom wizard description shown during team creation
- Control which invite types (user / group / email / federated) are available to team admins

## Requirements

- Nextcloud 32 or later
- PHP 8.1 – 8.4
- Nextcloud Teams (Circles) app — included with Nextcloud, must be enabled
- PostgreSQL or MySQL/MariaDB

Optional integrations (TeamHub auto-detects what is installed):
- Nextcloud Talk
- Nextcloud Calendar
- Nextcloud Deck
- IntraVox (Pages)

## Installation

### From a release zip

1. Download the latest release zip from the [Releases](../../releases) page
2. Extract into your Nextcloud apps directory:
   ```bash
   cd /path/to/nextcloud/apps
   unzip teamhub-x.y.z.zip
   ```
3. Enable the app:
   ```bash
   sudo -u www-data php occ app:enable teamhub
   ```

The release zip contains pre-compiled JavaScript. You do not need npm.

### From source

```bash
cd /path/to/nextcloud/apps
git clone https://github.com/jdoek/teamhub.git teamhub
cd teamhub
npm install
npm run build
sudo -u www-data php occ app:enable teamhub
```

## Upgrading

After replacing the app files, run:
```bash
sudo -u www-data php occ upgrade
sudo -u www-data php occ maintenance:mode --off
```

## Development

```bash
git clone https://github.com/jdoek/teamhub.git
cd teamhub
npm install
npm run dev       # development build with watch
npm run build     # production build
```

PHP files in `lib/` take effect immediately. Vue components require a rebuild.

### Project structure

```
teamhub/
├── appinfo/
│   ├── info.xml            # App metadata and version
│   └── routes.php          # All API route definitions
├── lib/
│   ├── AppInfo/
│   │   └── Application.php # Bootstrap — registers integrations, jobs, listeners
│   ├── Controller/         # REST API controllers (one per domain)
│   ├── Service/
│   │   ├── TeamService.php          # Team CRUD, config, admin settings
│   │   ├── MemberService.php        # Membership, invite, roles
│   │   ├── ResourceService.php      # Resource lookup + provisioning orchestrator
│   │   ├── TalkService.php          # Talk room create/delete
│   │   ├── FilesService.php         # Shared folder create/delete
│   │   ├── CalendarService.php      # Calendar create/delete
│   │   ├── DeckService.php          # Deck board + Intravox page create/delete
│   │   ├── DbIntrospectionService.php # DB schema introspection utility
│   │   ├── MessageService.php       # Messages, polls, questions
│   │   ├── ActivityService.php      # Activity feed + calendar events
│   │   ├── IntegrationService.php   # Integration registry + widget data
│   │   ├── MaintenanceService.php   # Orphaned team cleanup
│   │   ├── TelemetryService.php     # Anonymous usage telemetry
│   │   ├── LinkPreviewService.php   # URL metadata for message previews
│   │   ├── TeamImageService.php     # Team logo upload/storage
│   │   └── WebLinkService.php       # Custom tab bar links
│   ├── Db/                 # Database mappers (QueryBuilder only, no raw SQL)
│   ├── Integration/        # ITeamHubWidget interface
│   ├── Migration/          # DB schema migrations (one file per schema change)
│   ├── Notification/       # NC push notification handler
│   ├── Listener/           # App disabled listener (auto-deregisters integrations)
│   └── Settings/           # Admin settings panel
├── src/
│   ├── App.vue             # Root component + NC sidebar shell
│   ├── store/index.js      # Vuex state management
│   └── components/
│       ├── TeamView.vue         # Team shell — mounts tab bar + widget grid
│       ├── TeamTabBar.vue       # Draggable tab bar
│       ├── TeamWidgetGrid.vue   # Home-view drag-and-drop widget grid
│       ├── ManageTeamView.vue   # Team settings panel
│       └── ...                  # Widgets, modals, activity views
├── js/                     # Compiled output (do not edit directly)
├── templates/              # PHP page templates
└── webpack.config.js
```

### Key design decisions

**No probeCircles()** — Nextcloud's `CirclesManager::probeCircles()` filters out circles whose config bitmask is non-zero, which hides any team with custom settings. TeamHub reads the team list directly from the `circles_circle` database table and only uses the Circles API for write operations.

**Config bitmask preservation** — When saving team settings, TeamHub reads the existing config from the database, applies only the bits it manages (open, invite, request, protected, visible, single), and writes back. Internal Circles bits (hidden, personal, root) are never touched.

**Batch DB queries** — `getUserTeams()` uses 4 queries regardless of how many teams the user belongs to (one for team list, one batch member count, two batch unread checks). The old per-team query loop has been removed.

**SQL-filtered team browsing** — `browseAllTeams()` uses a LEFT JOIN with a WHERE clause to filter by CFG_VISIBLE and membership in the database. No full table scan into PHP.

**Resource provisioning via sub-services** — `ResourceService` is an orchestrator only. Each app (Talk, Files, Calendar, Deck/Intravox) has its own service class with create and delete methods, keeping each file under 400 lines.

**No circular DI** — `TalkService` and `DeckService` need DB schema introspection (`getTableColumns`). They inject `DbIntrospectionService` directly rather than `ResourceService`, avoiding a circular dependency.

**PostgreSQL compatibility** — The `activity` table's `object_id` column is `bigint` in PostgreSQL. Non-numeric values (circle IDs, Talk tokens) are handled by matching on `object_type` alone rather than attempting a string-to-bigint comparison.

**Membership checks on all team-scoped endpoints** — Every endpoint that returns team data (messages, resources, widgets, activity) verifies the caller is a team member before responding. This uses a direct indexed DB query against `circles_member`, not the full Circles API.

## License

[AGPL-3.0](LICENSE)
