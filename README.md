# TeamHub for Nextcloud

TeamHub gives every Nextcloud Team a proper home. It wraps the existing Teams (Circles) infrastructure and provisions a shared workspace — messages, Talk chat, Files folder, Calendar, Deck board, and Presence — all from one place.

## Features

### Team workspace
Each team gets a tab bar linking directly to its shared apps:
- **Home** — team messages, comments, polls and questions
- **Chat** — opens the team's shared Talk conversation
- **Files** — opens the team's shared Files folder
- **Calendar** — opens the team's shared calendar
- **Deck** — opens the team's shared Deck board
- **Presence** — team presence view showing who is working where today (when enabled)
- Custom links can be added to the tab bar by team admins

### Sidebar widgets
Always visible next to the message stream:
- **Team info** — description, team type labels, owner, member avatar stack with today's presence indicators
- **Upcoming events** — next events pulled from the team calendar
- **Open tasks** — cards from the team Deck board
- **Pages** — pages from the team's IntraVox space (if installed)
- **Activity snapshot** — 5 most recent events across all team resources

### Presence module
When enabled by the NC admin, team members can set a weekly presence template (Home / Office / Vacation / Non-working day) and override individual dates. The schedule is published to their default Nextcloud Calendar as VEVENT entries (TRANSP:OPAQUE for busy, TRANSPARENT for free), driving NC's calendar-status integration. Team admins can activate a Presence tab per team and optionally hide status details so the team view shows only a three-tone busy / free / off palette.

**Enabling presence:** NC Admin Settings → TeamHub → Integrations → Presence module (default: off).

### Team messages
Post announcements, questions and polls to your team. Members are notified. Messages support inline editing and threaded comments.

### Team management
- Create teams with name, description and visibility settings
- Invite members by local user, group, email address or federated account
- Remove members, approve join requests, transfer ownership
- Configure team options (open join, invite-only, approval required, password-protected)
- Browse and request access to teams you are not a member of

### Admin settings
- Control team creation permissions (restrict to specific groups)
- Configure invite types available to team admins
- Set minimum member level required to pin messages
- Enable/disable the Presence module for the whole instance
- Manage presence types, office locations, and admin holidays
- Telemetry, maintenance, audit, and archive tools

## Requirements

- Nextcloud 32 or later
- PHP 8.1 – 8.4
- Nextcloud Teams (Circles) app — included with Nextcloud, must be enabled
- PostgreSQL or MySQL/MariaDB

Optional integrations (auto-detected):
- Nextcloud Talk
- Nextcloud Calendar
- Nextcloud Deck
- IntraVox (Pages)

## Installation

See [INSTALL.md](INSTALL.md).

## License

[AGPL-3.0](LICENSE)
