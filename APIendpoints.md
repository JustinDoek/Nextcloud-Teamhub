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
        "cardId": 42,
        "eventRole": "due",
        "overdue": false,
        "completed": false,
        "blockedByCardIds": [17]
      }
    }
  ]
}
```

**Sources** emitted: `calendar`, `decisions`, `deck`, `messages`, `milestone`.

**Event types per source**:
- `calendar`: `event`
- `decisions`: `proposed` | `decided` | `withdrawn`
- `deck`: `created` | `due` | `completed`
- `messages`: `posted`
- `milestone`: `milestone` (dated milestones only — see Milestone endpoints above)

**`meta` additions since 3.78.0** (all optional, presence depends on data):
- `decisions` events: `linkedCardIds` (int[], 3.78.5) — Deck cards linked via "Link tasks", resolved from `task_path`. `sourceMessageId` (int, 3.78.9) — the `teamhub_messages` row that announced this proposal; `0` if none.
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

## Telemetry endpoint (response shape extended 3.86.0)

### `GET /api/v1/admin/telemetry`

Returns the admin telemetry settings plus a live preview of the next outgoing report.

**Auth**: NC admin required (`#[AuthorizedAdminSetting]` on `AdminSettings`).

**Response 200**:
```json
{
  "enabled":    true,
  "report_url": "https://tldr.host/teamhub/report/",
  "preview":    { /* TelemetryService::collectStats() output */ }
}
```

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

*Update this file in place at the end of any session that adds, removes, or changes an endpoint.*
