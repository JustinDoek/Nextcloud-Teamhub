# TeamHub — API Endpoints

> Updated per `SKILLS.md` step 6 whenever a session adds, removes, or changes an endpoint.
> Endpoints below cover the changes made this session (3.78.0 through 3.79.0). For a complete catalog of all TeamHub endpoints see `appinfo/routes.php`.

---

## Milestone endpoints (added 3.78.2)

Timeline Milestones — team-admin-defined, optionally-dated markers shown as a red line on the Timeline. Managed from Manage Team → Integration settings → Timeline.

### `GET /api/v1/teams/{teamId}/milestones`

List milestones for a team, ordered dated-ascending then undated-last.

**Auth**: team **admin** required (`MilestoneService` calls `MemberService::requireAdminLevel`).

**Response 200**:
```json
{
  "items": [
    {
      "id": 7,
      "label": "Beta launch",
      "date": "2026-08-01",
      "createdBy": "jdoek",
      "createdAt": 1750000000
    }
  ]
}
```
`date` is `null` for a milestone with no date set — valid state, just not plotted on the Timeline.

**Failures**: `403` — not a team admin.

---

### `GET /api/v1/teams/{teamId}/milestones/pick` (v3.97.5)

Member-gated variant of the list endpoint used by the decision-compose milestone picker. Same response shape as `/milestones`, no write side effects.

**Auth**: team **member** required. Milestones are already visible to every member via Timeline red-marker rendering and the project-health widget's Milestones pillar, so exposing id/label/date at member scope adds no new leak — it just avoids escalating the compose caller to admin-only just to render the picker.

**Response 200**: `{ "items": [ ... ] }` — same item shape as `/milestones`.

**Failures**: `403` — not a team member.

---

### `POST /api/v1/teams/{teamId}/milestones`

Create a milestone.

**Auth**: team admin required.

**Body**: `{ "label": "Beta launch", "date": "2026-08-01" }` — `date` optional, `YYYY-MM-DD`.

**Response 201**: the created milestone (same shape as the list item above).

**Failures**: `400` — empty label or malformed date. `403` — not a team admin.

---

### `PUT /api/v1/teams/{teamId}/milestones/{milestoneId}`

Update a milestone's label and/or date.

**Auth**: team admin required.

**Body**: same as create.

**Response 200**: the updated milestone.

**Failures**: `400` — empty label, malformed date, or milestone not found / not in this team. `403` — not a team admin.

---

### `DELETE /api/v1/teams/{teamId}/milestones/{milestoneId}`

**Auth**: team admin required.

**Response 200**: `{ "ok": true }`

**Failures**: `400` — milestone not found / not in this team. `403` — not a team admin.

---

## Timeline endpoints (added 3.78.0, response enriched through 3.78.9)

### `GET /api/v1/teams/{teamId}/timeline`

Fetch aggregated timeline events for a team within a date window.

**Auth**: team member required (`MemberService::requireMemberLevel`).

**Query params**:
- `from` (int, required) — Unix timestamp of window start
- `to` (int, required) — Unix timestamp of window end

**Response 200**:
```json
{
  "events": [
    {
      "id": "deck-42-due",
      "source": "deck",
      "type": "due",
      "title": "Card title",
      "date": "2026-06-17T14:00:00+00:00",
      "endDate": null,
      "allDay": true,
      "url": "/apps/deck/board/3/card/42",
      "meta": {
        "boardName": "Q3 Planning",
        "stackName": "In Progress",
        "stackId": 17,
        "stackOrder": 2,
        "cardId": 42,
        "eventRole": "due",
        "overdue": false,
        "completed": false,
        "blockedByCardIds": [17]
      }
    }
  ],
  "stacks": [
    {
      "stackId": 17,
      "boardId": 3,
      "boardTitle": "Q3 Planning",
      "stackTitle": "In Progress",
      "order": 2
    }
  ]
}
```

**`stacks`** (added 3.91.0) is the full, date-independent, order-sorted list of Deck stacks connected to this team — used by the Planning-phase swimlane view so a stack with zero cards in the requested window still renders as an empty lane. NULL `order` values sort last, tie-broken by `stackId`. Deck-specific and independently try-caught server-side; if Deck fails, `stacks: []` is returned without breaking `events`.

**Sources** emitted: `calendar`, `decisions`, `deck`, `messages`, `milestone`.

**Event types per source**:
- `calendar`: `event`
- `decisions`: `proposed` | `decided` | `withdrawn`
- `deck`: `created` | `start` | `due` | `completed`
- `messages`: `posted`
- `milestone`: `milestone` (dated milestones only — see Milestone endpoints above)

**`start` event** (added 3.91.0) — Deck 1.16+ (NC 34+) only — emitted when `deck_cards.startdate` is non-null. Absent on older Deck installs. The swimlane view uses `start` + `due` to draw a bar spanning both; falls back to a due-only single-day marker when `start` is missing.

**`meta` additions since 3.78.0** (all optional, presence depends on data):
- `decisions` events: `linkedCardIds` (int[], 3.78.5) — Deck cards linked via "Link tasks", resolved from `task_path`. `sourceMessageId` (int, 3.78.9) — the `teamhub_messages` row that announced this proposal; `0` if none.
- `deck` events (all types, 3.91.0): `stackId` (int) and `stackOrder` (int|null) — the card's Deck stack identifier and its ordering position, so the frontend can group events into lanes without string-matching `stackName` (which isn't guaranteed unique across boards). `stackOrder` may be null on installs predating the Deck `BackfillDeckStackOrder` repair step.
- `deck` events (`eventRole: 'created'` only): `blockedByCardIds` (int[], 3.78.8) — Deck card IDs this card depends on. **NC 34 / Deck 1.18+ only** — absent entirely on older installs, never an empty array as a false signal of "checked, no dependencies."
- **Popover-detail additions (3.86.0)** — all optional, present only when data exists:
  - `calendar` events: `description` (string, truncated 280), `organizer` (string — `CN` or mailto-stripped), `attendeeCount` (int).
  - `decisions` events: `proposedBy` / `decidedBy` (string UID) + `proposedByName` / `decidedByName` (string display name).
  - `deck` events: `description` (string, truncated 280), `assignees` (string[] of UIDs) + `assigneeNames` (string[] of display names — user-type rows only on Deck installs with a `type` column).
  - `messages` events: `snippet` (string, truncated 280 of message body), `authorName` (string display name companion to existing `authorId`).
  - `milestone` events: `createdBy` (string UID) + `createdByName` (string display name).
  - Display names are resolved via `IUserManager` in a single per-request pass; unknown/deleted users fall back to the raw UID.

**Failures**:
- `403` — not a member of the team
- `500` — internal error (logged via `$this->logger->warning`)

---

### `GET /api/v1/teams/{teamId}/timeline/config`

Fetch the per-team Timeline enabled flag.

**Auth**: team member required.

**Response 200**:
```json
{ "timeline_enabled": true }
```

**Storage**: NC app-config keyed `timeline_enabled_<teamId>` = `"1"` (enabled) or `"0"` (disabled). Default `"1"`.

**Failures**: `403` if not a member.

---

### `PUT /api/v1/teams/{teamId}/timeline/config`

Toggle the per-team Timeline visibility.

**Auth**: team **admin** required (`MemberService::requireAdminLevel`).

**Body**:
```json
{ "timeline_enabled": 1 }
```
Accepts any truthy/falsy representation (bool, 0/1, "0"/"1"). Coerced to a strict `"0"`/`"1"` string for storage.

**Response 200**:
```json
{ "timeline_enabled": true }
```

**Failures**:
- `403` — not a team admin
- `500` — internal error (logged)

---

### `GET /api/v1/teams/{teamId}/messages/config`  *(added 4.0.0)*

Fetch the per-team Messages integration enabled flag.

**Auth**: team member required.

**Response 200**:
```json
{ "messages_enabled": true }
```

**Storage**: NC app-config keyed `messages_enabled_<teamId>` = `"1"` (enabled) or `"0"` (disabled). Default `"1"`.

**Routing note**: this route is registered *before* the `PUT /messages/{messageId}` catchall so the literal `config` segment wins over `{messageId}=config`. Any future route with a fixed segment under `/messages/` must be positioned the same way in `appinfo/routes.php`.

**Failures**: `403` if not a member.

---

### `PUT /api/v1/teams/{teamId}/messages/config`  *(added 4.0.0)*

Toggle the per-team Messages integration. Disabling hides the message stream widget, the post form, and the Home entry in the mobile bottom bar.

**Auth**: team **admin** required (`MemberService::requireAdminLevel`).

**Body**:
```json
{ "messages_enabled": 1 }
```
Accepts any truthy/falsy representation (bool, 0/1, "0"/"1"). Coerced to a strict `"0"`/`"1"` string for storage.

**Response 200**:
```json
{ "messages_enabled": true }
```

**Failures**:
- `403` — not a team admin
- `500` — internal error (logged)

---

## Public messages (added 4.2.11)

The Public flag on a message opts it out of team scope. Public messages surface on `GET /api/v1/messages/public` and (in a follow-up session) on the personal aggregated feed.

Publishing is admin-gated per-team: `MessageService::createMessage` force-strips `is_public` when the team's `allowPublicMessages_<teamId>` setting is off or when `messageType !== 'normal'`. Polls, questions, and decisions are always team-scoped.

### `POST /api/v1/teams/{teamId}/messages`  *(field added 4.2.11)*

Same endpoint as before with one added optional field:

**Body addition**:
```json
{ "isPublic": true }
```

`isPublic` defaults to `false`. Backend forces it to `false` when the team admin has not enabled the toggle or when the message type is anything other than `normal`, so an API caller cannot bypass the gate by hand-crafting the request.

---

### `GET /api/v1/teams/{teamId}/messages/settings`  *(response addition 4.2.11)*

The settings envelope gains `allowPublicMessages` (bool), so the frontend knows whether to render the Public checkbox on the compose form.

**Response 200**:
```json
{
  "pinMinLevel": "moderator",
  "postMinLevel": "member",
  "linkMinLevel": "admin",
  "allowPublicMessages": false
}
```

### `POST /api/v1/teams/{teamId}/messages/settings`  *(field added 4.2.11)*

Body accepts `allowPublicMessages` (bool/int/string coerced). Team admin required — same as before.

---

### `GET /api/v1/messages/feed`  *(added 4.2.12, extended 4.2.13/4.2.14, licensed 4.3.0)*

The personal "What’s new" feed. Combines team messages from every team the caller is a member of, public messages from other teams, and Talk polls + thread starters from rooms connected to any of the caller's teams. One paginated call, chronological.

**Auth**: authenticated NC user (`#[NoAdminRequired]`). **License**: requires an active TeamHub license (`enforcementLevel` = `none` or `grace`). Unlicensed / soft-locked instances receive `403 { error, licenseGate: true, enforcementLevel }` — frontend surfaces a license-specific error and the sidebar entry is hidden.

**Query params**:
- `includeTeam` (bool, default `1`) — accepts `1/0/true/false/yes/no/on/off`.
- `includePublic` (bool, default `1`) — same coercions.
- `includeTalk` (bool, default `1`) — same coercions. When on, adds Talk polls (`source: 'talk-poll'`) and thread starters (`source: 'talk-thread'`) from rooms the caller can reach via a team membership. Talk items carry `room_id`, `room_token`, `room_name`, and the resolved `team_id` (first-matching own-team via `talk_attendees`).
- `limit` (int, 1–100, default 20)
- `offset` (int, ≥0, default 0)

**Response 200**:
```json
{
  "items": [
    {
      "id": 1234,
      "team_id": "abc123",
      "team_name": "Marketing",
      "source": "public",
      "author_id": "jdoek",
      "author_display_name": "Justin Doek",
      "subject": "Q3 goals",
      "message": "…",
      "priority": "normal",
      "messageType": "normal",
      "pinned": false,
      "isPublic": true,
      "created_at": 1750000000,
      "updated_at": 1750000000,
      "comment_count": 0
    }
  ],
  "hasMore": true,
  "limit": 20,
  "offset": 0
}
```

`source` is a synthetic per-row field: `'team'` when the row's `team_id` is in the caller's own team memberships, `'public'` otherwise. The classification is done server-side so the frontend doesn't need to re-check membership. A user's own-team public post shows once, classified as `'team'`.

`hasMore` is computed by asking the DB for `limit + 1` rows and stripping the extra one — no follow-up COUNT query.

**Failures**: `403` — not authenticated. `500` — internal error (logged; generic body returned).

**Routing note**: registered above the `/messages/{messageId}` catchalls in `appinfo/routes.php` so the literal `feed` segment wins over `{messageId}=feed`.

---

### `GET /api/v1/messages/public`  *(added 4.2.11)*

Return the most recent public messages across every team on this NC instance. Any authenticated user may call it — a message the poster marked public has opted out of team-scope confidentiality.

**Auth**: authenticated NC user (`#[NoAdminRequired]`).

**Query params**:
- `limit` (int, 1–100, default 20)
- `offset` (int, ≥0, default 0)
- `excludeTeamIds` (comma-separated team ids to skip; capped at 500 to keep the `NOT IN` clause tractable). The personal aggregated feed passes the caller's own team memberships so a message doesn't render twice.

**Response 200**:
```json
{
  "messages": [
    {
      "id": 1234,
      "team_id": "abc123",
      "team_name": "Marketing",
      "author_id": "jdoek",
      "author_display_name": "Justin Doek",
      "subject": "Q3 goals",
      "message": "…",
      "priority": "normal",
      "messageType": "normal",
      "pinned": false,
      "isPublic": true,
      "created_at": 1750000000,
      "updated_at": 1750000000,
      "comment_count": 0
    }
  ],
  "limit": 20,
  "offset": 0,
  "count": 1
}
```

**Failures**: `500` — internal error (logged with `[TeamHub][MessageController]` prefix; generic body returned).

**Routing note**: registered above the `/messages/{messageId}` catchalls in `appinfo/routes.php` so the literal `public` segment wins over `{messageId}=public`.

---

### `GET /api/v1/teams/{teamId}/type`  *(added 4.1.0)*

Fetch the team's template label as chosen in the create-team wizard.

**Auth**: team member required.

**Response 200**:
```json
{ "type": "collaboration" }
```

Values: `"collaboration"` | `"project"` | `"department"` | `null`. Legacy teams created before 4.1.0 have no row in `teamhub_team_type` and return `null` — the frontend renders no template badge for those.

**Storage**: `teamhub_team_type` — one row per team, `team_id` primary key, `type` (STRING 32), `created_by`, `created_at`. Not extending `teamhub_project.type` because doing so would flip the `isProject` gate across 14+ services.

**Failures**: `403` if not a member.

---

### `PUT /api/v1/teams/{teamId}/type`  *(added 4.1.0)*

Set the team's template label. Called once by `CreateTeamView` after team creation.

**Auth**: team **admin** required (`TeamTypeService::setType`).

**Body**:
```json
{ "type": "project" }
```

Server-side validated against `TeamTypeService::ALLOWED = ['collaboration','project','department']` — anything else returns 400.

**Response 200**:
```json
{ "type": "project" }
```

**Failures**:
- `400` — value not in the allowed enum
- `403` — not a team admin
- `500` — internal error (logged)

---

### `GET /api/v1/teams/{teamId}/dashboard/config`  *(added 4.1.2)*

Fetch the team-wide dashboard customization: which widgets the owner/admin has hidden from every member's Home dashboard, and which tab opens when a member enters the team.

**Auth**: team member required.

**Response 200**:
```json
{ "hidden_widgets": ["widget-activity", "widget-files-center"], "default_tab": "decisions" }
```

- `hidden_widgets` — array of widget ids removed from every member's grid (desktop, tablet, mobile). Their grid positions are preserved, so un-hiding restores placement.
- `default_tab` — tab key opened on team entry; `"msgstream"` (Home) is the default. Falls back to Home if the configured tab isn't currently available.

**Storage**: NC app-config — `dashboard_hidden_<teamId>` (JSON array) and `dashboard_tab_<teamId>` (string). Same per-team pattern as the messages/timeline toggles; no table. Also emitted inline on the layout bundle as `dashboardConfig`.

**Failures**: `403` if not a member.

---

### `PUT /api/v1/teams/{teamId}/dashboard/config`  *(added 4.1.2)*

Update the team-wide dashboard customization. Changes the dashboard for **every** member.

**Auth**: team **admin** required (level ≥ 8).

**Body** (either or both keys; a missing key is left unchanged, so the frontend persists one field at a time):
```json
{ "hidden_widgets": ["widget-activity"], "default_tab": "budget" }
```

- `hidden_widgets` — full replacement list (array of widget-id strings, or a JSON-encoded string). Deduped, capped at 100, each ≤ 128 chars.
- `default_tab` — tab key string (≤ 64 chars; blank/oversized coerces to `"msgstream"`).

**Response 200**: the full stored config (same shape as GET).

**Failures**:
- `403` — not a team admin
- `500` — internal error (logged)

---

## Teams-list endpoint (extended 4.1.2)

### `GET /api/v1/teams`

Existing endpoint — response objects now include a `level` field carrying the current user's role in each team, so the sidebar 3-dot menu can gate its actions (Manage/Invite/Leave) per team without a second fetch.

**Response addition (4.1.2)** — per-team object:
```json
{
  "id": "...", "name": "...", "description": "...",
  "members": 5, "unread": 0, "image_url": "...",
  "config": 8,
  "level": 8
}
```

- `level` — current user's Circles level in this team. Values: `0` (indirect member via a group or sub-team), `1` (member), `4` (moderator), `8` (admin), `9` (owner). Sourced from the existing SELECT on `circles_member.level` — no extra query. Indirect access maps to `0` because the underlying LEFT JOIN returns NULL for those rows.

---

## Browse-teams endpoint (extended 4.1.0)

### `GET /api/v1/teams/browse`

Existing endpoint — response objects now include a `type` field carrying the template label so `BrowseTeamsView` can render a per-card badge and search on the localized label.

**Response addition (4.1.0)** — per-team object:
```json
{
  "id": "...", "name": "...", "description": "...",
  "isMember": true, "isDirectMember": true,
  "requiresApproval": false, "image_url": "...",
  "type": "collaboration"
}
```

Fetched via `TeamTypeMapper::findTypesByTeams` in one batch, keeping the endpoint at a single extra SQL call regardless of team count. `type` is `null` for legacy teams.

---

## Layout endpoint (updated 4.1.0)

### `GET /api/v1/teams/{teamId}/layout`

Response additions in both team-row and cascade-to-default branches (in addition to earlier ones):

- **`messagesConfig`** (4.0.0) — `{ messages_enabled: bool }`, so the frontend can gate the message stream widget without a second fetch.
- **`teamType`** (4.1.0) — `"collaboration" | "project" | "department" | null`. Populated from `teamhub_team_type` via `TeamTypeService::getType`. `null` for legacy teams so the badge renders nothing.
- **`autoFit` on DEFAULT_LAYOUT items** (4.1.0) — new grid items carry `autoFit: true`. The frontend measures rendered content on first mount and grows `h` to fit, then strips the flag and re-saves. Persisted in `teamhub_layouts` if still set at save time so the pass survives a page reload.
- **`dashboardConfig`** (4.1.2) — `{ hidden_widgets: string[], default_tab: string }`. Team-wide customization set by owner/admin in Manage Team → Settings → Dashboard. Drives the widget grid on desktop, tablet, and mobile plus the on-open tab selection. Same shape and semantics as `GET /teams/{teamId}/dashboard/config`.

---

## Timeline iframe page (added 3.78.0, params extended through 3.78.9)

### `GET /apps/teamhub/timeline/{teamId}`

Standalone same-origin iframe page rendering the visual timeline canvas. Loaded by `AppEmbed` on the Timeline tab.

**Query params** (read by the iframe's vanilla-JS controller, not server-side):
- `view`: `1W` | `1M` | `3M` | `6M` — period length (default `1W`)
- `from`: Unix timestamp of window start (default = start of current week)
- `sources`: comma list of `calendar`, `decisions`, `deck`, `messages` (default all — milestones are not a filterable source, always plotted)
- `sub`: comma list of `<source>:<type>` pairs enabling per-source sub-filters. Default (3.78.9): all — `deck:created,deck:due,deck:completed,decisions:proposed,decisions:decided`
- `links`: `1` | `0` — Decision ↔ task connector overlay. Default `1` (param absent reads as `1`).
- `depLinks`: `1` | `0` — Deck card-dependency connector overlay. Default `1`. No-op on installs without `deck_dependent_cards`.
- `msgLinks`: `1` | `0` — Message ↔ decision connector overlay. Default `1`.

**Auth**: handled by `PageController::timeline` — non-members get an error overlay page rather than a forbidden error response, since the page itself is non-API.

**Rendering**: blank-layout template (`templates/timeline.php`) with an inline CSP-nonce-stamped script. Calls the GET timeline API above with the resolved date window. Renders chips, section bands (order: Deck, Decisions, Messages, Calendar), axis lines, milestone marker lines, crowding count-badges, connector overlays, and Gantt-style connecting bars client-side.

**Parent↔iframe messaging**: the iframe has no navigation state of its own. Clicking a crowding count-badge posts `{app:'teamhub', type:'timeline-navigate', from: <unix ts>}` to the parent window; `TeamView.vue` listens and switches to 1-Week view snapped to that day's week.

---

## Layout endpoint (updated 3.78.0, capability flag added 3.78.8)

### `GET /api/v1/teams/{teamId}/layout`

Existing endpoint — now also returns `timelineConfig` in its response so the frontend can gate the Timeline tab on the per-team toggle, and detect Deck card-dependency support, without a separate fetch.

**Response additions** (in both team-row and cascade-to-default branches):
```json
{
  "...": "...",
  "timelineConfig": {
    "timeline_enabled": true,
    "card_dependencies_supported": false
  }
}
```

`card_dependencies_supported` (3.78.8) — whether `deck_dependent_cards` exists on this install (`TimelineService::isCardDependencySupported()`, via `DbIntrospectionService`). Gates whether the "Deck card dependencies" connector toggle appears in the Timeline filter menu at all.

Also added `mergeNewTabs()` post-processing on `tabOrder` — saved `tab_order_json` rows automatically pick up new built-in tabs (Timeline included) on every GET without ever needing a re-save.

**Response addition (3.88.0)** — `project` (in both team-row and cascade-to-default branches), same shape as the Project Teams endpoints below. Lets the frontend show the project phase stepper immediately on team open without a second request; membership is already verified earlier in `getLayout()`, so `LayoutController::projectFacts()` degrades to `{isProject:false, ...}` on any failure rather than breaking the whole layout response.
```json
{
  "...": "...",
  "project": { "isProject": true, "mode": "advanced", "phase": "planning", "startDate": null, "targetEnd": null }
}
```

**Response addition (4.0.0)** — `messagesConfig` (in both team-row and cascade-to-default branches). Lets the frontend gate the message-stream widget, the mobile Home entry, and the post form on the per-team toggle without a second fetch — same pattern as `timelineConfig`. Default `true` so a team that never touches the setting keeps its stream.
```json
{
  "...": "...",
  "messagesConfig": { "messages_enabled": true }
}
```

---

## Meeting endpoints (extended 3.81.2)

### `POST /api/v1/teams/{teamId}/meetings`

Create a team meeting — writes a notes file in the team's `Meetings/` folder, then writes a calendar event linked to that notes file. The wizard's Add Meeting button is the primary caller. Existing fields are unchanged; the additions below are all optional and default to safe behaviour.

**Auth**: caller must meet the team's `meeting_min_level` (1/4/8). Enforced inside `MeetingService::enforceMinLevel`.

**Body (additions in 3.81.2 — bold fields are new):**

| Field | Type | Notes |
| --- | --- | --- |
| `title` | string | Required. ≤200 chars. |
| `date` | string | `YYYY-MM-DD`. |
| `startTime`/`endTime` | string | `HH:MM` 24h. |
| `location` | string | Free-text. ≤200. |
| `filename` | string | Base filename, no extension. |
| `includeTalk` | bool/int | Link the team Talk room into the calendar event. |
| `talkToken` | string | Pre-resolved Talk token; skips DB lookup. |
| `askAgenda` | bool/int | Post a one-shot message in the Talk room with the notes link (requires `includeTalk`). |
| **`attendees`** | string/array | Comma-separated user ids, or array. Empty = no per-attendee invitations (event lives only in the team calendar). ≤500. |
| **`description`** | string | Inserted as a preamble in the notes file and stored on the calendar event. ≤4000. |
| **`categories`** | string | CSV CATEGORIES on the calendar event. ≤500. |
| **`roomEmail` / `roomName` / `roomId`** | string | Room booking. Same shape as `POST /calendar/events`. RoomVox rooms send `roomId`; CRM rooms leave it empty. |
| **`includeOverdueTasks`** | bool/int | Render `## Tasks` section with Deck cards whose `duedate < meetingStart`, `done=0`, `archived=0`, not deleted. |
| **`includeUnscheduledTasks`** | bool/int | Same `## Tasks` section, cards with no duedate. |
| **`includeProposals`** | bool/int | Render `## Proposals` section with team decisions in status `open` or `finalized`. Each link uses `?team={teamId}&decision={id}`. |
| **`proposalCategories`** | string/array | Optional. Comma-separated list of category names (or array). When non-empty, narrows the Proposals section to decisions in those categories. Empty = no filter (all). ≤200 items. *(3.81.3)* |

**Response 201**:
```json
{
  "notesUrl":             "https://nc/index.php/s/abc",
  "talkUrl":              "https://nc/call/xyz" /* or null */,
  "calendarEventCreated": true,
  "eventUid":             "ABCDEF..."          /* new in 3.81.2 */
}
```

**Failures**: `400` validation, `403` not a member / insufficient level, `422` setup incomplete (e.g. no team folder), `500` other.

---

## Audit-tab "Find teams for a user" endpoints (added 3.84.1)

Drives the new admin panel that finds every team a user belongs to and supports bulk removal. Backed by `MaintenanceService::listTeamsForUser` and `MaintenanceService::adminRemoveUserFromTeam`.

### `GET /api/v1/admin/maintenance/users/{userId}/teams`

Return every user-created team the given NC user is a member of (direct or via group / sub-team), with role, owner, and source classification.

**Auth**: NC admin required. Gated twice — `#[AuthorizedAdminSetting(settings: AdminSettings::class)]` attribute + `MaintenanceService::requireNcAdmin()` inside the service.

**Path params**: `userId` — the NC uid of the user to look up.

**Response 200**:
```json
{
  "teams": [
    {
      "teamId":           "abc123...",
      "teamName":         "Sugar",
      "teamDescription":  "Honey ice tea",
      "ownerUid":         "JDoek",
      "ownerDisplayName": "Doek, Justin",
      "role":             "Member",
      "level":            1,
      "isOwner":          false,
      "source":           "group",
      "sourceName":       "Sugar 2",
      "removable":        false
    }
  ]
}
```

Field reference:
- `source` — `"direct"` (direct member row in `circles_member` user_type=1), `"group"` (inherited via a group attached to the team), `"team"` (inherited via a sub-team), or `"inherited"` (cache says they belong but the source can't be traced — rare).
- `sourceName` — display name of the granting group or sub-team. `null` for direct memberships.
- `removable` — `true` only when `source === "direct"` AND `!isOwner`. The UI uses this to enable / disable the per-row checkbox.

**Failures**: `400` empty `userId`, `404` user not found in NC, `500` other.

### `POST /api/v1/admin/maintenance/users/{userId}/remove-from-teams`

Remove the user from each of the given teams (direct memberships only, non-owner only). Per-row result so partial successes are visible.

**Auth**: NC admin required, same dual gate as the GET above.

**Path params**: `userId`.

**Body** (form-encoded — the audit-tab UI sends `URLSearchParams` with repeated `teamIds[]` entries):
```
teamIds[]=abc123...&teamIds[]=def456...
```

**Response 200**:
```json
{
  "results": [
    { "teamId": "abc123...", "ok": true },
    { "teamId": "def456...", "ok": false, "error": "Cannot remove the team owner — reassign ownership first in the Maintenance tab" }
  ]
}
```

Per-team behaviour:
- Refuses to remove the team owner (level≥9) with the message above.
- Refuses to remove a non-direct member (no row in `circles_member` user_type=1) with "User is not a direct member of this team — remove them from the source group or sub-team instead".
- On success: deletes the row, rebuilds `circles_membership` via `MembershipService::onUpdate`, emits a `member.removed_by_admin` audit event with the admin's UID as actor.

**Failures**: `400` empty `userId` / empty `teamIds`, `404` user not found. Per-team failures land in `results[].error` rather than as an HTTP error so the batch can keep going.

---

## Telemetry endpoint (response shape extended 3.86.0; v4.3.0 makes `enabled` license-derived + PUT deprecated)

### `GET /api/v1/admin/telemetry`

Returns the current telemetry state plus a live preview of the next outgoing report.

**Auth**: NC admin required (`#[AuthorizedAdminSetting]` on `AdminSettings`).

**Response 200**:
```json
{
  "enabled":    true,
  "report_url": "https://tldr.host/teamhub/report/",
  "preview":    { /* TelemetryService::collectStats() output */ }
}
```

**`enabled` semantics (changed 4.3.0)**: was a manual admin toggle stored in `appconfig.telemetry_enabled`. Since 4.3.0 it is DERIVED from `LicenseService::getEnforcementLevel()` — `false` when the enforcement level is `none` or `grace` (a paying customer we already know), `true` otherwise (unlicensed instances contribute to the free-tier usage view). The `PUT /api/v1/admin/telemetry` endpoint is kept for API back-compat but is a no-op — the underlying `TelemetryService::setEnabled()` is marked `@deprecated`.

**`preview` field added 3.86.0**:
- `unique_team_members` (int) — distinct effective people across every TeamHub team (`circles_membership` ↦ `circles_circle source=16` ↦ `circles_member user_type IN (1, 4)`). Same metric the admin Statistics tab's "Unique team members" card displays. Intended as the per-seat license counter for any future commercial-license model.

**`preview.builtin_integrations` field removed 3.87.0**:
- `shared_files` — the per-team toggle behind this metric was removed when the Shared-files widget was folded into the Filecenter widget as an always-on tab. The `builtin_integrations` map no longer emits a `shared_files` key. Legacy `teamhub_team_apps` rows for that `app_id` are ignored.

Other `preview` fields are unchanged from earlier versions: `team_count`, `user_count`, `member_total`, `message_count`, `integrations`, `builtin_integrations`, `presence_module`, `decisions_module`, `teams_with_decisions_enabled`, `decisions_count`, `decisions_by_status`, `decision_categories_count`, `suggest_wizard_uses`, `link_domains`. See `TelemetryService::collectStats()` for the authoritative list.

---

## Unified-search providers (added 3.84.3 / pre-existing)

NC's unified search calls `IProvider::search` on every registered provider. TeamHub registers three:

| Provider | ID | `getOrder` | Surfaces |
|---|---|---|---|
| `TeamSearchProvider` (new in 3.84.3) | `teamhub-teams` | 49 | Teams the searcher is a member of (direct or via group / sub-team), filtered to user-created teams (`circles_circle.source=16`), pending-deletion excluded. Result entry deep-links to `/apps/teamhub/#/team/{teamId}`. |
| `MessageSearchProvider` (pre-existing) | `teamhub-messages` | 50 | Messages in teams the searcher belongs to. |
| `DecisionSearchProvider` (pre-existing) | `teamhub-decisions` | 51 | Decisions in teams the searcher belongs to. |

Order values are hardcoded in each provider's `getOrder()`. NC has no admin UI to reorder providers — to change ordering, edit the values in source.

---

## Project Teams endpoints (added 3.88.0)

Persisted project-ness for teams created from the "Project" template — the keystone that later phase-aware tooling (charter template, swimlane board, budget page, dashboard) hangs off. A team without a `teamhub_project` row is not a project; `mode` (`basic`|`advanced`) is the lifecycle discriminator; `phase` is meaningful only for `advanced` and walks `initiation → planning → execution → closing`. See DESIGN.md §2.36.

### `GET /api/v1/teams/{teamId}/project`

Project facts for a team.

**Auth**: team **member** required (`ProjectService::getForTeam` calls `MemberService::requireMemberLevel`).

**Response 200**:
```json
{ "isProject": true, "mode": "advanced", "phase": "planning", "startDate": null, "targetEnd": null }
```
For a non-project team: `{ "isProject": false, "mode": null, "phase": null, "startDate": null, "targetEnd": null }`.

**Failures**: `401` — not authenticated. `403` — not a team member.

---

### `PUT /api/v1/teams/{teamId}/project`

Create or update the project record. Called by the create wizard (Project template, any mode) and by the "Upgrade to Advanced" action in Manage Team → Project.

**Auth**: team **admin** required (`ProjectService::upsert` calls `MemberService::requireAdminLevel`).

**Body**: `{ "mode": "basic" | "advanced", "start_date": 1754006400, "target_end": null }` — `mode` required; `start_date`/`target_end` optional Unix timestamps (UTC midnight), omit or `null` to leave/clear.

**Response 200**: same shape as `GET`. Creating with `mode="advanced"` and no existing phase seeds `phase="planning"`. Updating an existing `advanced` row to `mode="basic"` clears `phase` to `null`; updating `basic` → `advanced` seeds `phase="planning"` if none was set.

**Failures**: `400` — `mode` missing or not one of `basic`/`advanced`. `403` — not a team admin.

**Audit**: `project.created` (first row) or `project.mode_changed` / `project.updated` (subsequent writes, diff-gated — no event if nothing changed).

---

### `PUT /api/v1/teams/{teamId}/project/phase`

Advance/set the lifecycle phase. Advanced projects only.

**Auth**: team **admin** required.

**Body**: `{ "phase": "initiation" | "planning" | "execution" | "closing" }`.

**Response 200**: same shape as `GET`.

**Failures**: `400` — `phase` missing, not a valid phase, or the project is `mode="basic"` (phase only applies to advanced projects). `403` — not a team admin.

**Audit**: `project.phase_changed` (skipped as a no-op if the phase is unchanged — no audit noise).

---

### `GET /api/v1/teams/{teamId}/project/health` (v3.97.0)

Aggregation endpoint feeding the Execution-phase **Project health** widget (Track E Session 6). Membership + tab-visibility gated.

**Auth**: team **member** required (`MemberService::requireMemberLevel`). Additionally requires both `budgetService::canUserViewBudgetTab` and `timeService::canUserViewTimeTab` to be true — non-eligible viewers get a `canView: false` envelope with zero counts (the widget hides itself in that case; no 403 for viewer denial). Non-members still 403 via the standard mapper.

**Body**: none.

**Response 200** (eligible viewer, Advanced project in Execution phase):
```json
{
    "canView": true,
    "phase": "execution",
    "budgetTime": {
        "lanesOverBudget": 2,
        "projectOverBudget": false,
        "membersOverHours": 1
    },
    "milestones": {
        "total": 3,
        "upcoming": [
            {
                "id": 42,
                "label": "Beta launch",
                "date": "2026-08-15",
                "dateTs": 1755216000,
                "ownedTotal": 8,
                "ownedDone": 5,
                "ownedSlipping": 1,
                "status": "slipping",
                "isPast": false
            }
        ]
    },
    "quality": {
        "openDecisions": 2,
        "unsolvedQuestions": 4,
        "decisionsEnabled": true
    }
}
```

**Response 200** (non-eligible viewer — below either floor, non-project team, Basic mode, or wrong phase):
```json
{
    "canView": false,
    "phase": null,
    "budgetTime": { "lanesOverBudget": 0, "projectOverBudget": false, "membersOverHours": 0 },
    "milestones": { "total": 0, "upcoming": [] },
    "quality": { "openDecisions": 0, "unsolvedQuestions": 0, "decisionsEnabled": false }
}
```

**Rules**:
- `budgetTime.lanesOverBudget` — count of Budget lanes where `spentRealMinor > allocatedMinor` (lanes without an allocation are ignored).
- `budgetTime.projectOverBudget` — `true` when `spentRealMinor > totalMinor` at the project level, else `false` (also `false` when there's no total set).
- `budgetTime.membersOverHours` — count of members where `loggedMinutes > availableMinutes`. Uncapped members (`availableMinutes = 0`) are excluded.
- `milestones.upcoming` — up to 5 milestones, ordered future-first (nearest first), padded with the most-recent past milestones if fewer than 5 future milestones exist. Only *dated* milestones are considered (undated milestones have no interval to own cards from).
- **Milestone ownership rule**: milestone M owns every Deck card whose `duedate` falls in the range `(previous_milestone.date, M.date]`. For the first milestone, "previous" is `project.startDate`; if `startDate` is unset, the first milestone owns every card up to its own date.
- **Milestone status**: `on-track` — all owned cards are `done`. `at-risk` — some owned cards still open but none past their own `duedate`. `slipping` — some owned cards open AND at least one is past its `duedate`.
- `quality.openDecisions` — count of decisions with `status IN ('open', 'finalized')`. Always `0` when the Decisions module isn't enabled for the team (see `quality.decisionsEnabled`).
- `quality.unsolvedQuestions` — count of question-type messages where `question_solved = 0`.

**Failures**: `401` — not authenticated. `403` — not a team member. `500` — mapper/DB error.

**Frontend contract**: `ProjectHealthWidget.vue` fetches on mount, on `currentTeamId` change, and on window focus / tab visibility change. Errors are shown inline with the last successful payload preserved so admins know figures may be stale. Each tile has quick-jump buttons emitting `open-tab` → `set-view` to Budget / Time / Timeline / Messages.

---

### `GET /api/v1/teams/{teamId}/project/readiness` (v3.98.0)

Powers the Project Compass panel on the team Home view. Returns the phase-appropriate setup checklist with per-item done/pending status and jump-link targets.

**Auth**: team **member** required. Every check reads data the caller already has access to via other endpoints — no privilege escalation.

**Body**: none.

**Response 200** (Advanced project, Planning phase):
```json
{
    "isProject": true,
    "phase": "planning",
    "nextPhase": "execution",
    "readyToAdvance": false,
    "items": [
        {
            "id": "project_dates",
            "done": true,
            "label": "Set project start and target end dates",
            "hint": "Anchors the timeline so milestones and the health widget have a range to report against.",
            "link": { "target": "manage-team", "tab": "project", "section": "top" }
        },
        {
            "id": "members_invited",
            "done": true,
            "label": "Invite the project team",
            "hint": "At least one other member so work has someone to be assigned to.",
            "link": { "target": "invite-modal" }
        },
        {
            "id": "milestones_added",
            "done": false,
            "label": "Add at least one dated milestone",
            "hint": "Milestones own the Deck cards due before them and feed the project-health widget.",
            "link": { "target": "manage-team", "tab": "project", "section": "milestones" }
        }
    ]
}
```

**Response 200** (non-project / non-Advanced team):
```json
{
    "isProject": false,
    "phase": null,
    "nextPhase": null,
    "readyToAdvance": false,
    "items": []
}
```

**Item shape**:
- `id` — stable string identifier per check.
- `done` — boolean; the "Next up" prompt shows the first `done: false` item.
- `label` — one-line action title.
- `hint` — one-line explanation of why this matters.
- `link.target` — routing hint: `manage-team` (set `SET_MANAGE_TEAM_DEEP_LINK`), `set-view` (emit `set-view`), or `invite-modal` (emit `invite`).
- `link.tab` + `link.section` — set only when `target === 'manage-team'`. `section` matches a `data-section` anchor on ManageTeamView; use `"top"` for no scroll.
- `link.view` — set only when `target === 'set-view'` (e.g. `budget`, `time`, `timeline`, `msgstream`).

**Phase items**:

| Phase | Items |
|---|---|
| planning | project_dates, members_invited, milestones_added, budget_total (if budget integration enabled), time_capacity (if time integration enabled) |
| execution | first_expense (if budget), first_timelog (if time), milestones_on_track, within_bounds |
| closing | none (Session 7 will define) |

**`readyToAdvance`** is `true` when every item is `done` and `nextPhase !== null`. The frontend surfaces this as an "Advance phase" CTA; the actual advance calls the existing `PUT /project/phase` endpoint, which still requires admin level.

**Failures**: `401` — not authenticated. `403` — not a team member. `500` — mapper/DB error.

**Frontend contract**: `ProjectCompassPanel.vue` fetches on mount, on `currentTeamId` change, on window focus, on tab visibility change, and on any `project` / `budgetConfig` / `timeConfig` Vuex mutation.

---

### `POST /api/v1/teams/{teamId}/project/closing/generate` (v3.99.0)

Renders the Closing artifact into the team's Files folder (`Project Closing/`). Overwrites existing files if re-run. On success stamps `teamhub_project.closing_artifact_at`, which flips the Compass `closing_artifact` readiness item to done.

**Auth**: team **admin** required.

**Body**: none.

**Response 200**: `{ "filePath": "/…/Project Closing", "generatedAt": 1720444800 }`

**Failures**: `400` — team has no Files folder to write into, or write failed. `403` — not admin. `500` — mapper/DB error stamping timestamp.

---

### `GET /api/v1/teams/{teamId}/project/closing/status` (v3.99.0)

**Auth**: team **member** required.

**Response 200**: `{ "generated": bool, "generatedAt": int|null, "filePath": string|null }`. Never throws — returns `generated=false` on any read failure.

---

### `GET /api/v1/teams/{teamId}/project/closing/archive-policy` (v3.99.0)

Returns the effective admin archive settings so the frontend can render an informed confirmation before team archival.

**Auth**: team **member** required.

**Response 200**: `{ "archiveBeforeDelete": bool, "archiveMode": "hard"|"soft30"|"soft60", "dataLossWarning": bool }`

`dataLossWarning` is `true` when `archiveBeforeDelete === false && archiveMode === 'hard'` — no archive bundle AND immediate hard delete. The `ArchivePolicyWarningModal` renders a red alert in that case; otherwise a plain policy description.

---

## IntraVox page creation (`projectMode` param added 3.89.0)

### `POST /api/v1/teams/{teamId}/intravox/page`

Creates the team's IntraVox documentation page. Pre-existing endpoint; not previously documented here.

**Auth**: team **admin** required (`MemberService::requireAdminLevel`).

**Body** (3.89.0): `{ "projectMode": "basic" | "advanced" | null }` — optional. When `"advanced"`, the page is seeded with the 9-element PMC project-definition charter (`IntravoxService::buildProjectCharterLayout`), rendered in the creating user's NC language. Any other value (including absent/`null`/`"basic"`) creates the page exactly as before this session — a blank canvas with just a title.

**Response 200**: `{ "success": true, "result": { "page_created": true, "page_id": "..." } }`.

**Failures**: `400` — IntraVox not installed, or `PageService` threw (message passed through).

**Note**: creation and content-seeding are two internal calls (`PageService::createPage` then `updatePage`) — `createPage()` does not accept `layout` inline. See DESIGN.md §2.38.

---

## IntraVox diagnostic (content probe added 3.88.x)

### `GET /api/v1/admin/intravox-diagnostic`

Admin-only diagnostic — lists `PageService`'s public method signatures via PHP reflection. Pre-existing endpoint.

**Auth**: NC **server admin** required (`IGroupManager::isAdmin`).

**Query params** (3.88.x addition): `?pageId=<id>` — when present, additionally calls `getPage($pageId)` and `getCurrentPageContent($pageId)` on that page and includes the raw output under `contentProbe`. Read-only; does not create, modify, or delete anything. Useful for inspecting IntraVox's real content/layout shape when extending the integration further.

**Response 200**: `{ installed, methods: [...], class, file, contentProbe?: { pageId, getPage, getCurrentPageContent } }`.

---

## IntraVox team-page lookup (added 3.90.0)

### `GET /api/v1/teams/{teamId}/intravox/team-page`

Returns the requesting team's own IntraVox page — the same underlying data the widget used to get by fetching `/apps/intravox/api/pages` in bulk and matching by title client-side. That approach became ambiguous once every Advanced project's page shares the literal title "Contract" (`IntravoxService::CONTRACT_TITLES`, §2.38): with two or more Advanced project teams, title alone can no longer tell them apart. This endpoint disambiguates server-side by confirming each title-candidate's actual folder **path** via `IntravoxService::getTeamPage()` — see DESIGN.md §2.41.

**Auth**: team **member** required (`MemberService::requireMemberLevel`).

**Response 200**: the full IntraVox `getPage()` payload for the team's own page (`{ uniqueId, title, path, id, layout, ... }`), or `null` if the team has no page yet.

**Failures**: `400` — not a team member, or an internal error (message passed through).

**Caching**: 5 minutes server-side (`teamhub_intravox_teampage_{teamId}`), invalidated by `IntravoxService::invalidateSubPagesCache()` on page create/delete — same cache lifecycle as the existing sub-pages endpoint below.

**Consumed by**: `src/components/IntravoxWidget.vue` (`initDocumentationPage()`), replacing the old bulk-fetch-and-guess call.

---

## Deck diagnostic (added 3.90.0)

### `GET /api/v1/admin/deck-diagnostic`

Admin-only diagnostic — lists `\OCA\Deck\Db\CardMapper`'s public method signatures via PHP reflection, plus defensive probes (each failure reported independently, not fatal to the response) for `AssignedUsersMapper`, `CardService`, and `AssignmentService` — whichever of these actually writes a card assignee had no prior precedent anywhere in TeamHub. Same pattern as `intravox-diagnostic` above; kept permanently as a reusable discovery tool for future Deck-API questions.

**Auth**: NC **server admin** required (`IGroupManager::isAdmin`). Read-only — creates, modifies, and deletes nothing.

**Response 200**: `{ installed, CardMapper?: { class, methods }, CardMapper_error?, AssignedUsersMapper?: {...}, AssignedUsersMapper_error?, CardService?: {...}, CardService_error?, AssignmentService?: {...}, AssignmentService_error? }`.

---

## Deck resource creation (`projectMode` param added 3.90.0)

### `POST /api/v1/teams/{teamId}/create-resources`

Provisions a team's Talk room, Deck board, Calendar, and/or Files resources on creation. Pre-existing endpoint; not previously documented here.

**Auth**: team **admin** required (`MemberService::requireAdminLevel`).

**Body** (3.90.0 addition): `{ "apps": [...], "teamName": "...", "names": {...}, "appStates": [...], "projectMode": "basic" | "advanced" | null }` — `projectMode` is optional. When `"advanced"` and `apps` includes `"deck"`, the created Deck board's 3 default stacks (To do / In progress / Done) are followed by a 4th "Project management" stack pre-populated with 4 starter cards (Invite project members, Create project contract, Add project milestones, Schedule the planning kickoff meeting), each assigned to the team creator with a due date 7 days out. Any other value (including absent/`null`/`"basic"`) creates the Deck board exactly as before this session. See DESIGN.md §2.40.

**Response 200**: per-app results object; always HTTP 200, per-app errors surface inside the payload rather than as an HTTP failure status.

---

## Budget endpoints (added 3.92.0)

Execution-phase project budget — a project-wide total + currency plus one budget lane per Deck stack ("workstream"). Each lane records `allocated_minor`, `view_min_level` (who sees the lane), and `edit_min_level` (who can add or change expenses in the lane). Expenses live under a lane.

All amounts are BIGINT minor units (cents) — safe integer arithmetic, portable across MySQL/MariaDB/Postgres.

### `GET /api/v1/teams/{teamId}/budget`

Full Budget page payload. Membership-gated. Per-lane `view_min_level` filters lanes the caller cannot see — hidden lanes never appear in the response and never contribute to the rollup.

**Auth**: team **member** required (`BudgetService::getProjectBudget` → `MemberService::requireMemberLevel`). Non-project teams get an "empty envelope" response with `isProject: false` and no lanes.

**Side effect (read-only from the caller's perspective)**: reconciles `teamhub_budget_lane` rows against the team's live Deck stacks — auto-inserts a lane row with defaults (`view_min_level=1`, `edit_min_level=8`, `allocated_minor=null`) for every stack that doesn't have one. Lanes for deleted stacks are retained but hidden from the response.

**Response 200**:
```json
{
  "isProject": true,
  "currency": "EUR",
  "totalMinor": 1000000,
  "budgetViewMinLevel": 1,
  "allocatedMinor": 750000,
  "spentProjectedMinor": 320000,
  "spentRealMinor": 240000,
  "lanes": [
    {
      "laneId": 42,
      "deckStackId": 100,
      "stackTitle": "To do",
      "stackOrder": 999,
      "boardId": 5,
      "boardTitle": "Project team ABC",
      "allocatedMinor": 250000,
      "viewMinLevel": 1,
      "editMinLevel": 8,
      "editors": [
        { "uid": "alice", "displayName": "Alice Anderson" },
        { "uid": "bob",   "displayName": "Bob Bakker" }
      ],

      "canView": true,
      "canEdit": false,
      "spentProjectedMinor": 100000,
      "spentRealMinor": 80000,
      "remainingProjectedMinor": 150000,
      "remainingRealMinor": 170000,
      "expenses": [
        { "id": 7, "laneId": 42, "description": "Software licence", "projectedMinor": 20000, "realMinor": 19999, "incurredAt": 1751328000, "createdBy": "jane", "createdAt": 1751328123, "updatedAt": 1751328123 }
      ]
    }
  ]
}
```

### `PUT /api/v1/teams/{teamId}/budget`

Set the project's total budget and currency. Only valid on `mode === 'advanced'` projects. Refuses when `total_minor` is less than the current sum of lane allocations.

**Auth**: team **admin** required.

**Body**: `{ "total_minor": 1000000 | null, "currency": "EUR" | null, "budget_view_min_level": 1 | 4 | 8 }`. `total_minor` and `currency` are nullable (null = clear). `budget_view_min_level` (added 3.94.0) is the project-level role floor for Budget-tab visibility — a member sees the tab when their team role is at or above this level OR they are a named editor on any workstream.

**Response 200**: full budget envelope, same shape as GET.

**Errors**: 400 for invalid currency (not a 3-letter code) or negative total or lane-sum-exceeds-total; 403 for non-admin.

### `PUT /api/v1/teams/{teamId}/budget/lanes/{laneId}`

Update one lane's allocation + view/edit min-levels. The lane must belong to `{teamId}` and its Deck stack must still exist on the team's board.

**Auth**: team **admin** required.

**Body**: `{ "allocated_minor": 250000 | null, "edit_min_level": 1 | 4 | 8, "editor_uids": ["alice", "bob"] }`. Level values map to TeamHub team roles: 1 = every member, 4 = moderator+, 8 = admin only. `editor_uids` (added 3.93.0) is a full-replace list of team members who get edit access to this lane regardless of role. Absent or `null` == empty set. Unknown UIDs are refused; string entries only. `view_min_level` was removed in 3.94.0 — tab visibility is now project-level (see PUT /budget); any `view_min_level` in the body is silently ignored for back-compat.

**Response 200**: full budget envelope.

**Errors**: 400 for unknown lane, deleted-stack, invalid level (not in `{1,4,8}`), negative allocation, sum-of-allocations-exceeds-total, unknown editor UID, or non-array `editor_uids`; 403 for non-admin.

### `POST /api/v1/teams/{teamId}/budget/lanes/{laneId}/expenses`

Add an expense to a lane.

**Auth**: caller must have team level ≥ the lane's `edit_min_level`.

**Body**: `{ "description": "…", "projected_minor": 20000, "real_minor": 20500 | null, "incurred_at": 1751328000 | null }`. `real_minor` null = not yet realised. `incurred_at` is a Unix timestamp (UTC-midnight convention).

**Response 200**: full budget envelope.

**Errors**: 400 for empty description or negative amounts; 403 for insufficient lane edit level.

### `PUT /api/v1/teams/{teamId}/budget/lanes/{laneId}/expenses/{expenseId}`

Update an expense. Same auth + body + response as POST above. The expense must currently belong to `{laneId}` (moving between lanes is not supported).

### `DELETE /api/v1/teams/{teamId}/budget/lanes/{laneId}/expenses/{expenseId}`

Delete an expense.

**Auth**: caller must have team level ≥ the lane's `edit_min_level`.

**Response 200**: full budget envelope.

---

## Time investment endpoints (added 3.96.0)

Execution-phase per-member time investment — each project participant gets an `available_minutes` figure (0 = uncapped); logs attach to a Deck card, roll up by member and by lane (Deck stack) inside the report. Only meaningful on `mode === 'advanced'` projects.

### `GET /api/v1/teams/{teamId}/time/config`

Per-team on/off toggle. Body: `{ "time_enabled": bool }`. Member-gated read.

### `PUT /api/v1/teams/{teamId}/time/config`

Set the toggle. Body: `{ "time_enabled": 0|1 }`. Admin-gated.

### `GET /api/v1/teams/{teamId}/time`

Full Time page payload. Member-gated, tab-visibility gated:
- caller's team role ≥ `project.time_view_min_level` OR
- caller has a `teamhub_project_member` row on this team (a named project participant).

If gated out, returns an empty envelope with `isProject: false`. Response envelope: `{ isProject, timeViewMinLevel, totalAvailableMinutes, totalLoggedMinutes, members: [{ userId, displayName, availableMinutes, loggedMinutes, remainingMinutes }], lanes: [{ stackId, stackTitle, stackOrder, boardId, boardTitle, loggedMinutes }] }`. `remainingMinutes` is `null` when the member is uncapped.

### `PUT /api/v1/teams/{teamId}/time`

Set the project-level Time-tab view floor.

**Body**: `{ "time_view_min_level": 1|4|8 }`. Admin-gated.

**Response 200**: full time envelope.

### `GET /api/v1/teams/{teamId}/time/loggable-cards?user_id=…`

Cards the given user is currently assigned to inside this team's Deck boards. Powers the "Log time" picker. `user_id` defaults to the caller; non-admins can only query themselves.

**Response 200**: `[{ cardId, cardTitle, stackId, stackTitle, boardTitle }]` sorted by card title.

### `PUT /api/v1/teams/{teamId}/time/members/{userId}`

Add or update a project participant's available-minutes budget. Admin-gated.

**Body**: `{ "available_minutes": int }`. `0` = uncapped (report accumulates without a Remaining column for this user).

**Response 200**: full time envelope.

### `DELETE /api/v1/teams/{teamId}/time/members/{userId}`

Remove someone from the project. Their existing time logs are retained (audit trail); they drop off the report grid. Admin-gated.

**Response 200**: full time envelope.

### `GET /api/v1/teams/{teamId}/time/members/{userId}/logs`

Drill-down: every raw log row for `userId`. Non-admins can only see their own logs. Member-gated.

**Response 200**: `[{ id, cardId, stackId, userId, minutes, description, workedAt, createdBy, createdAt, updatedAt }]`.

### `POST /api/v1/teams/{teamId}/time/logs`

Record a block of time on a Deck card.

**Body**: `{ card_id, user_id (optional — defaults to caller), minutes, description, worked_at }`.

**Auth**: caller must be a team member, the `user_id` (the person the time is *for*) must currently be a Deck-card assignee of `card_id`, and if `user_id` differs from the caller then the caller must be a team admin (on-behalf logging).

**Errors**: 400 for missing card, wrong project, or invalid minutes (0 < minutes ≤ 43200); 403 for non-assignee or unauthorised on-behalf.

**Response 200**: full time envelope.

### `PUT /api/v1/teams/{teamId}/time/logs/{logId}`

Update a log row. **Auth**: the row's `created_by` OR a team admin.

### `DELETE /api/v1/teams/{teamId}/time/logs/{logId}`

Delete a log row. **Auth**: same as PUT.

---

## Compliance — code-integrity endpoint (added 4.2.0)

Drives the "Code integrity" section at the top of the Compliance admin tab (renamed from `Audit` in the same session). Backed by `IntegrityService`, which reads `appinfo/integrity.json` (a SHA-256 manifest generated at build time by `scripts/generate-integrity.js`) and compares it against files on disk.

### `GET /api/v1/admin/integrity`

Runs the integrity check and returns a full report.

**Auth**: `#[AuthorizedAdminSetting(settings: AdminSettings::class)]` — TeamHub-delegated admin (same trust boundary as the rest of AdminSettings). `#[NoCSRFRequired]` because it is a read-only GET.

**Response 200**:
```json
{
  "status":               "compliant",
  "manifest_version":     1,
  "app_version":          "4.2.0",
  "generated_at":         "2026-07-19T10:15:00Z",
  "algorithm":            "sha256",
  "files_checked":        847,
  "altered":              [],
  "missing":              [],
  "unexpected":           [],
  "altered_truncated":    false,
  "missing_truncated":    false,
  "unexpected_truncated": false,
  "checked_at":           "2026-07-19T10:16:22Z"
}
```

Field reference:
- `status` — `"compliant"` when altered + missing + unexpected are all empty AND the manifest was found; `"not_compliant"` when any list is non-empty; `"manifest_missing"` when `appinfo/integrity.json` is absent or unparseable (installs older than 4.2.0 that were not rebuilt with the new build script).
- `manifest_version` / `app_version` / `generated_at` / `algorithm` — echoed from the manifest header; `null` when the manifest is missing.
- `files_checked` — total number of file entries in the manifest that were compared (does not include the "unexpected" walk).
- `altered` — relative paths whose current SHA-256 differs from the expected value.
- `missing` — relative paths listed in the manifest but no longer present on disk.
- `unexpected` — files present on disk under a covered directory (`appinfo`, `lib`, `js`, `css`, `templates`, `img`, `l10n`, `sql`) but not listed in the manifest. `.map` sourcemaps and dotfiles are skipped to avoid false positives from dev leftovers.
- `*_truncated` — `true` when the corresponding list was capped at 500 entries. The list still renders; the flag surfaces that not everything is shown.
- `checked_at` — ISO-8601 timestamp of when this response was computed (server time).

**Failures**: `500` on any unexpected `\Throwable` from the service — the response body is `{"error": "Integrity check failed"}` and the full exception is written to the NC log with the `[TeamHub][IntegrityController]` prefix. There is no per-file failure mode; unreadable files are counted as `altered` (since `hash_file` returns `false`).

---

*Update this file in place at the end of any session that adds, removes, or changes an endpoint.*
