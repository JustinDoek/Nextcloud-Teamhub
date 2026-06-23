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

*Update this file in place at the end of any session that adds, removes, or changes an endpoint.*
