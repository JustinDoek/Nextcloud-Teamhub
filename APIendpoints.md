# TeamHub — API Endpoints

> Updated per `/session-end` step 6 whenever a session adds, removes, or changes an endpoint.
> Endpoints below cover the changes made this session (3.78.0 through 3.79.0). For a complete catalog of all TeamHub endpoints see `appinfo/routes.php`.

---

## My Work endpoints (added 4.5.21)

Personal, cross-team work queue. **No route carries a `{teamId}`** — My Work is cross-team by definition, and the team boundary is resolved server-side from the session user's own memberships (`TeamService::getUserTeams()`). There is deliberately no team parameter for a caller to tamper with.

Every member endpoint is licence-gated on the same ladder as What's New: enforcement level `none` or `grace` passes; anything else returns `403` with `{"licenseGate": true, "enforcementLevel": "…"}`.

### `GET /api/v1/mywork`

The whole queue for one page.

**Auth**: any authenticated user (`#[NoAdminRequired]`), licence-gated.

**Query parameters** (all optional). List parameters accept a comma-separated string or a repeated array param:

| Parameter | Type | Notes |
|---|---|---|
| `categories` | list | `action_required` \| `today` \| `upcoming` \| `waiting_for_others` \| `completed` |
| `providerIds` | list | `deck`, `approval`, … |
| `resourceTypes` | list | `deck_card`, `file`, … |
| `priorities` | list | `urgent` \| `high` \| `normal` \| `low` |
| `statuses` | list | provider-declared source statuses |
| `teamIds` | list | can only **narrow** the caller's own teams, never widen |
| `search` | string | matched against title, subtitle, team name and reason |
| `dueFrom`, `dueTo` | int | Unix seconds |
| `includeSnoozed` | bool | default `0` |
| `groupBy` | string | `category` (default) \| `date` \| `team` \| `resource_type` \| `project` (v4.9.7 — an item that names a project, e.g. an OpenProject work package, groups under it; everything else under its team) |
| `projectIds`, `workTypes` | list | v4.9.7 — narrow to items whose `metadata.projectId` / `metadata.type` is one of the values (OpenProject project ids; OpenProject work-package type names). An item without the key is excluded when the key is filtered on. At most 50 values each, 120 characters each |
| `sortBy` | string | v4.5.25 — `deadline` (default) \| `priority` \| `team` \| `recent`. Orders items **within** a group; never reorders the groups. An unrecognised value falls back to `deadline` rather than erroring |
| `limit` | int | 1–200, default 50 |
| `offset` | int | ≥ 0 |
| `nocache` | bool | v4.5.22 — bypass the server-side result cache. Sent by the Refresh button and after every action; a user who explicitly asks for fresh data must not be answered from cache |

**Response 200**:
```json
{
  "items": [ { "id": "deck:42", "providerId": "deck", "providerItemId": "42",
               "teamId": "…", "teamName": "Marketing", "category": "action_required",
               "title": "Review Q3 Project Plan", "subtitle": "ProjectPlan_Q3.docx",
               "resourceType": "file", "resourceId": "1180", "resourceUrl": "/f/1180",
               "openTarget": { "kind": "file", "fileId": 1180 },
               "priority": "urgent", "status": "approval_requested",
               "reason": "You have been designated as an approver",
               "createdAt": 0, "updatedAt": 0, "dueAt": 0, "completedAt": null,
               "assignee": { "uid": "…", "displayName": "…" },
               "waitingFor": { "type": "user", "id": "…", "displayName": "…" },
               "availableActions": ["open", "approve", "reject", "snooze"],
               "metadata": {}, "permissions": { "canApprove": true } } ],
  "groups": [ { "key": "action_required", "label": "Action required", "itemIds": ["…"] } ],
  "counts": { "action_required": 3, "today": 1, "upcoming": 6, "waiting_for_others": 2, "completed": 4 },
  "breakdown": { "action_required": [ { "providerId": "deck", "count": 2 },
                                      { "providerId": "approval", "count": 1 } ] },
  "highlights": { "today": [ … ], "waiting_for_others": [ … ], "completed": [ … ] },
  "sourceCounts": { "deck": 4, "decisions": 6, "approval": 1, "meetings": 1 },
  "sortBy": "deadline",
  "total": 16, "limit": 50, "offset": 0, "hasMore": false,
  "providerStatus": [ { "id": "deck", "name": "Deck", "state": "ok",
                        "message": null, "durationMs": 42, "count": 9, "total": 9 } ],
  "truncated": [],
  "teams": [ { "id": "…", "name": "Marketing" } ],
  "config": { "upcomingDays": 7, "completedDays": 7 },
  "generatedAt": 0, "cached": false
}
```

`openTarget` (v4.5.24) is how the row opens, named by the provider rather than inferred by the client from `resourceType`. `kind` is one of:

| `kind` | Other keys | Means |
|---|---|---|
| `deck_card` | `boardId`, `cardId`, `boardName` | the team's own Deck tab, with the card open |
| `file` | `fileId` | the team's own Files tab, with the file open |
| `teamhub_view` | `view`, `targetId` (nullable) | a TeamHub tab, optionally pre-selecting a row. Which views exist is the frontend's registry (`TEAMHUB_VIEW_TARGETS` in `src/constants/myWork.js`), deliberately not duplicated server-side |
| `calendar_event` | `url`, `calendarId` (nullable) | v4.5.25 — the team's Calendar tab with the event open. Same shape `openEventInEmbed` has taken since 4.5.15 |
| `external` | — | `resourceUrl` in a new browser tab |

`null` means `external`, so a provider written against the 4.5.21 contract keeps working. An unrecognised `kind` — or a `teamhub_view` naming a view the client does not have — falls back to `resourceUrl` rather than failing to open.

`providerStatus[].state` is one of `ok` | `disabled` | `unavailable` | `error` | `timeout` | `skipped`. **A failing provider never fails the request** — the response is still `200` with partial results, and the client renders a non-blocking notice from this array.

`providerStatus[].warnings` (v4.9.7) — a list of codes from a provider that answered, but not cleanly: `auth_required` (the viewer's source account is refused; the client offers the personal settings), `partial` (some of the source's scopes — OpenProject projects — could not be read), `budget` (the provider stopped early to keep inside its time budget), `not_connected` (informational). The state stays `ok`: the rows that arrived are real. Empty for every other row.

`facets` (v4.9.7) — `{ "projects": [ { id, name, teamId, count } ], "workTypes": [ { id, count } ] }`: the projects and work types present in the **pre-filter** set, from any item that carries `metadata.projectId` + `projectName` / `metadata.type` (today: OpenProject work packages). What the filter bar's Project and Work type selects offer; empty lists when no source contributes them.

**OpenProject rows (v4.9.5, extended v4.9.7)** — `providerId: "openproject"`, `resourceType` `openproject_work_package` or `openproject_milestone`, `status` one of `overdue` | `due_today` | `due_soon` | `assigned` | `authored` (created by the viewer, nobody assigned) | `updated` | `milestone` | `completed` (the attention state; OpenProject's own status label is in `subtitle` and `metadata.openProjectStatus`), `openTarget.kind: "external"`, `resourceUrl` absolute on the configured OpenProject host. `metadata`: `workPackageId`, `connection` (a hash of the host), `projectId`, `projectName`, `projectUrl`, `type`, `openProjectStatus`, `openProjectPriority`, `assigneeName`, `dueDate`, `startDate`, `percentageDone`, `opensIn: "OpenProject"`, `opensInOpenProject: true`, `reasons` (every attention code that applied), `updatedSinceVisit`, `additionalTeamIds` (other teams whose link points at the same project — only possible if the unique index were bypassed), and for a milestone `informational: true`, `daysUntil`.

`breakdown` (v4.5.23) is the same numbers split by source, ordered by count descending — it is what the summary cards render under their totals, as one chip per source carrying that source's glyph and its count. Same key set as `counts`, same Today lens rule. **The `critical` sub-count was removed in v4.5.25**: it repeated per card what the deadline column says per row and the category says per section.

`highlights` (v4.5.25) is what the side panels render — up to four items each for `today`, `waiting_for_others` and `completed`, in the same item shape as `items`. Computed from the **pre-category-filter** set, like `counts`: the panels are navigation, so selecting a summary card must not empty the panel whose count is still showing a number. `today` is ordered chronologically (it is a schedule), `completed` newest-first.

`sourceCounts` (v4.5.25) is the per-provider total behind the source tab bar, computed with every filter applied **except** `providerIds` — the same principle as `counts` below, applied to the control that sets the provider filter. The category filter deliberately *does* still apply, so with `categories=action_required` the numbers say how much of that category each source is responsible for. Providers with nothing are absent from the map rather than present at zero; the client already knows which sources exist from `/mywork/providers`.

`counts` is computed with every filter applied *except* the category filter, so the summary cards keep working as navigation while one category is selected. **`counts.today` is a lens, not a bucket** (v4.5.22): it counts every open item due today whatever category it landed in, because the action-required lead time correctly places actionable work due today under `action_required`. The five counts therefore overlap and do not sum to `total`, and a client filtering on the Today card should send `dueFrom`/`dueTo` rather than `categories=today`.

### `GET /api/v1/mywork/counts`

The five category counts only — the cheap call behind the sidebar badge.

**Response 200**: `{ "counts": { "action_required": 3, … } }`

### `GET /api/v1/mywork/providers`

Provider descriptors for the filter bar: `id`, translated `name`, `icon` key, `enabled`, `available`, `unavailableReason`, `capabilities`, `allowedActions`, `supportedFilters`, and (4.9.17) `group` — `files` / `teams` / `administration` / `null` for a provider that is its own tab (`lib/MyWork/SourceGroup.php`). Operational fields (last sync, last error, config schema) are stripped — those are admin-only and live on the admin status endpoint.

**The list is the caller's (4.9.17).** The instance-scoped providers (today `teamexpiry_admin`, the *Administration* group) are left out for a caller who is not a Nextcloud administrator — they return no rows to such a caller, and listing them showed every member an empty *Team lifecycle* tab. `GET /api/v1/admin/mywork/status` keeps the unfiltered list.

**Group keys as `providerIds` (4.9.17).** `GET /api/v1/mywork` accepts a source-group key wherever it accepts a provider id — `providerIds=files` is `providerIds=approval,file_review` — expanded server-side before the cache key is built. The counts in `sourceCounts` stay per provider; a client sums them per group.

### `POST /api/v1/mywork/action`

Execute one action. CSRF-protected (no `#[NoCSRFRequired]`).

The target travels in the **body, not the path**: provider item ids are opaque strings, and Nextcloud's routing strips trailing suffixes from path segments as format hints — the same trap the Announcements endpoints document below.

**Body**:
```json
{ "providerId": "deck", "itemId": "42", "action": "complete", "params": {} }
```

`action` is one of `open`, `complete`, `approve`, `reject`, `request_changes`, `comment`, `delegate`, `snooze`, `unsnooze`, `request_extension`. `params` carries `{ "message": "…" }` for `comment`, `{ "preset": "later_today|tomorrow|next_week|custom", "until": <unix> }` for `snooze`, and `{ "proposedOn": "YYYY-MM-DD", "reason": "…" }` for `request_extension`.

**`request_extension` became a server action in 4.6.17.** It was frontend-only navigation that followed the item's `openTarget` to Manage team → Maintenance; it now posts here, so it is restrictable per provider and written to the audit log like the rest of `ActionType::SOURCE_MUTATING`. `TeamExpiryService::requestExtension()` enforces admin level, template eligibility, no request already in flight, and a date later than the current expiration — each of which comes back as `409 conflict` with a message written for the reader.

**Removed in 4.5.40:** `follow` and `unfollow`. Both now return `unsupported`. A client still sending them gets an error rather than silently hiding an item — which is what `unfollow` did, permanently and with no way back.

**Snooze presets resolve in the caller's timezone, not the server's** (v4.5.24). TeamHub's own frontend never sends a named preset: it resolves `later_today` / `tomorrow` / `next_week` against the browser's clock and sends `{"preset": "custom", "until": <unix>}`, because a preset resolved server-side is the *server's* tomorrow morning. The named presets remain supported for callers with no clock of their own, and resolve against the server's timezone as before. `until` is rejected if it is in the past or more than 365 days out — a snooze that far out is a mute, and mute is a different action.

**Response 200**: `{ "ok": true, "message": "Card marked as completed.", "errorCode": null, "item": null, "removed": true }`

**Non-200**: the same envelope with `ok: false` and a stable `errorCode`, returned with a matching status — `403 forbidden`, `404 gone`, `409 conflict`, `400 unsupported`, `502 failed`. `400` also covers a malformed `providerId`, `itemId` or `action`.

Server-side, every action re-reads the item from its provider, re-checks the caller's team access and the source app's own permissions, and checks the administrator's per-provider allow-list. Source-mutating actions are written to the audit log as `mywork.action.{action}`.

**`teamexpiry_team` declares two categories from 4.6.17** — `team_admin` while the team can still act, and `waiting_for_others` from the moment an extension request is in flight. The second is not cosmetic: `MyWorkService::applyUrgency()` promotes any dated item into `action_required` as its deadline nears, and `waiting_for_others` is the one category it skips, so a team awaiting a decision is no longer escalated daily for something it cannot do. Those rows carry `waitingFor` naming the Nextcloud administrators group.

**`GET /api/v1/admin/maintenance/teams` gains `owner_mailto` in 4.6.17** — an absolute compose URL for writing to the team's owner, or `null` when the team has no owner or the owner has no address on their account. Nextcloud Mail's `/apps/mail/compose?uri=…` when the **viewer** has an account configured in Mail, a plain `mailto:` otherwise; see `TeamExpiryService::mailComposeUrl()`. The frontend hides the button on `null` rather than rendering one that opens an empty compose window.

**`GET /api/v1/admin/maintenance/teams` gains `classification` in 4.8.15** — per row, `{ profileKey, profileLabel, profileSeeded, compliant, driftedFields, templateKey, templateLabel, templateSeeded }`, or `null` for a team with neither a profile nor a template (a legacy team predating v4.0.2, on an instance using no profiles).

`compliant` is `true` / `false` for a classified team and **`null` when the team carries only a template** — that is a third state, not a `false`. Only a profile has an expected value set to compare against; a template decides what a new team is *made of* and leaves nothing on the team to check afterwards. The grid therefore colours the profile chip green or red and renders the template chip neutral.

`driftedFields` carries the finding objects, `[{ field, expected, observed, enforced? }]` — the same shape `profile_compliance.findings[].fields` uses, because the grid's drift dialog shows what the profile expects beside what the team has. `field` is a **key**; both the setting name and the values are rendered client-side from `src/constants/policy.js`. `enforced: true` appears only on `public_messages`.

Labels are the **stored** ones plus `*Seeded`, so the client can apply the naming rule (`profileDisplayName()` / `templateDisplayName()` in `src/constants/policy.js`): a seeded key is translated, a renamed one is shown as the admin typed it.

**`GET /api/v1/admin/maintenance/teams` gains `openproject` in 4.9.4** — per row, `{ projectId, projectIdentifier, projectName, host, stale, url }` for a team linked to an OpenProject project, `null` otherwise. `stale` is true when the link was made against a host other than the one `integration_openproject` is configured with now; `url` is the project's deep link on the link's host, or `null` without a host. Reported for any team holding a link row, whatever its template today. The grid shows it under the name and offers Unlink only on a linked row.

### `DELETE /api/v1/admin/maintenance/teams/{teamId}/openproject-link` (4.9.4)

NC admin only. Removes the team's link to its OpenProject project — **the only route that does**: the wizard links once, at creation (`POST /api/v1/teams`, `openProjectId`), and Manage team is read-only. Idempotent. Nothing in OpenProject changes; the project becomes linkable by a new team; the team cannot be linked again. Audited on the team as `openproject.unlinked_by_admin`.

**Response 200**: `{ "removed": { projectId, projectIdentifier, projectName, host, stale, … } | null }` — `null` when the team had no link.

Computed by `PolicyService::classificationForTeams()` — one batch for the page through the same `compareTeams()` the Compliance tab uses, four reads regardless of page size. It degrades to `null` on every row rather than failing the grid, matching the expiry block beside it.

**`teamadmin` gains a second resource type in 4.6.17** — `team_join_request`, status `join_requested`, alongside the existing `team_resource` / `resource_pending_review`. It carries somebody's pending request to join a team into that team's admins' queue, under `team_admin`, with `approve` and `reject`. Item ids are `joinreq:{teamId}:{uid}`, told apart from a resource's `{teamId}:{appId}:{resourceId}` by the leading marker. Both verbs route through `MemberService::approveRequest` / `rejectRequest`, which re-check the caller's team level independently — a request already decided returns `409 conflict`, not an error.

### `GET|PUT /api/v1/mywork/preferences`

Per-user view state: `groupBy`, `sortBy` (v4.5.25), `showSnoozed`, `collapsedGroups` (v4.5.39), `compact`, `pageSize`, `filters`. **Presentation only** — there is no key that can remove a category or change what My Work means, because the structure must survive personalisation.

The GET is called once on mount (v4.5.25). It existed from 4.5.21 and nothing called it, so preferences were written and never read — every new browser started from the defaults. `PUT` ignores any key not already in the stored shape, so an unknown key is dropped rather than persisted.

`collapsedGroups` *(replaced `completedExpanded` in 4.5.39)* — a list of the section keys the user has folded shut in the main column, now that every section collapses rather than only Completed. **Collapsed, not expanded**, so the default empty list is a queue that arrives open.

- **Not migrated.** The old key was one boolean about one category; carrying a stored `false` across would arrive meaning "everything collapsed". A blob written by an older client loses the key on its next write, and the client ignores it — same handling as `mentionsOnly` in 4.5.29.
- The keys follow whatever `groupBy` currently produces (category names, team ids, priorities), so they are **not** validated against a fixed list — which groupings exist is the client's fact. The server bounds them instead: strings only, 64 characters each, 60 entries, duplicates dropped. Worst case for a bad value is a section that stays folded.
- A pre-4.5.39 client PUTting `completedExpanded` changes nothing, because `saveUserPreferences` intersects the body against the stored shape.

### Administration

All four require an instance admin. Enforced by the absence of `#[NoAdminRequired]` on every method of `MyWorkAdminController`, which is a separate controller from the member one precisely so the gate is a property of the file. Deliberately **not** licence-gated: an admin on a lapsed instance still needs to see why My Work is dark.

| Endpoint | Purpose |
|---|---|
| `GET /api/v1/admin/mywork/config` | Horizons (including `actionRequiredDays`), cache TTL, request budget, approval expiry thresholds, effective category map, shipped defaults, and the bounds the UI constrains its inputs to |
| `PUT /api/v1/admin/mywork/config` | Any subset of the above. Out-of-range numbers are **clamped, not rejected** |
| `GET /api/v1/admin/mywork/status` | Every registered provider with availability, unavailability reason, capabilities, allowed actions, last successful sync, last error, and `diagnostics`. Also carries **`talkThreading`** — not a My Work fact, but this is the only admin-gated status endpoint that exists: `sendMessageSignature` (the installed `ChatManager::sendMessage()` parameter list), `threadTitlePlacement` (`metadata` / `parameter` / `none`), `threadServiceExists`, `threadServiceMethods`, `talkThreadsTable`. Reflection and schema only — nothing is sent to Talk |
| `PUT /api/v1/admin/mywork/providers/{providerId}` | `{ "enabled": bool, "actions": string[] }`. Native actions (snooze, unsnooze) are dropped from the list — they touch no source app, so there is nothing to govern |

### Resource review — availability (added 4.5.41)

Rows returned by `GET /api/v1/teams/{teamId}/resources/panel` with `status: "pending"` carry an extra `availability` field:

| Value | Meaning |
|---|---|
| `available` | The resource exists and the team is still attached. Accept / Ignore apply |
| `resource_gone` | The resource itself is deleted or in its app's trash |
| `team_detached` | The resource exists but nothing connects it to this team any more |
| `unknown` | Could not be determined — the owning app is not installed, or the lookup failed |

`unknown` is **not** `resource_gone`. A row that could not be verified keeps its Accept / Ignore buttons, because wrongly hiding a live connection costs more than one confusing row.

Only `pending` rows carry the field. Active rows are a known gap — see HANDOFF.

`POST /api/v1/teams/{teamId}/resources/{app}/{resourceId}/dismiss` deletes a pending row whose resource is gone or detached. Team admin required. The verdict is **recomputed server-side**, not taken from the request: a row that has become available again is refused with `400` and `{"error": "resource_available_again"}` so the client can refetch and offer Accept / Ignore instead. Dismissing drops the row from the `pending` count that feeds the Team info "N resources need review" strip.

---

## Decision proposals — the discussion phase (added 4.5.42)

Before 4.5.42 a proposal had two shapes with two workflows: created from the message stream it stayed `open` and was finalized by picking a comment; created from the compose modal it was finalized in the same request. The stream entry point is gone, and `open` is now a state you can actually work in.

### `share_mode`

Every decision carries one, and it is on every serialised decision payload:

| Value | Meaning |
|---|---|
| `immediate` | Finalized on creation. What the compose modal always did, and the **backfill value for every pre-4.5.42 row** |
| `selected` | Open, discussed in a Talk group conversation with a named set of people |
| `team` | Open, discussed in a thread in the team conversation |

Serialised decisions also carry `talkToken`, `talkThreadId` (both nullable), and `audience` — a list of user ids, or `null` for any mode other than `selected`, because the other two restrict nothing and `[]` would read as "shared with nobody".

### Visibility

**An open proposal with `share_mode = selected` is visible only to its proposer and its audience.** Enforced in four places, because a list filter alone is not a gate:

- `MessageService::applyDecisionFilter` — What's New
- `MessageService::getTeamMessages` — the team message stream (the row is dropped entirely, not just unstamped: the message body *is* the proposal text)
- `DecisionService::list` — the Decisions tab
- `DecisionService::get` — the authoritative one, on direct fetch by id. Refuses with **"Decision not found"**, not `403`, since a 403 would confirm the proposal exists

Category approvers are **not** admitted. They keep the early-sight `WAITING_FOR_OTHERS` My Work row for the `team` and `immediate` proposals, and first see a `selected` proposal at finalize.

Once finalized, the restriction lifts — the decision is a team record and the audience rows stop being consulted (they are kept, as the only record of who was invited).

### Endpoints

| Endpoint | Notes |
|---|---|
| `PUT /api/v1/teams/{teamId}/decisions/{decisionId}/proposal` | `{ question?, body? }`. Proposer only, `open` only. Omitting a field leaves it unchanged; sending neither is a `400`. Writes `message.subject` + `message.message` in one call and mirrors the subject into `decision.question` |
| `POST /api/v1/teams/{teamId}/decisions/{decisionId}/finalize-proposal` | Finalizes using the proposal body as the final wording. The Talk-discussion counterpart to `/finalize`, which requires a comment id a Talk discussion never produces. Leaves `selectedCommentId` null |
| `POST /api/v1/teams/{teamId}/decisions/{decisionId}/share` | `{ mode: 'selected'\|'team', userIds?: string[] }`. Returns `{ decision, share }`. `share` is `{ ok, error, invited }` for `selected` and `{ ok, error, threaded }` for `team` — **`threaded` is a looked-up `talk_threads` row, not an inference from having posted**, so a caller may believe it. Absent on `selected`, where the concept does not apply: a private room is not a thread |

`GET /decisions/{id}` additionally returns **`proposalBody`** — the current message body, for the proposer's editor. Deliberately not on `serialize()`: it would cost one extra query per row in `list()` and in every feed hydration for a field only the detail panel reads. `selectedAnswer` is not a substitute — it is null until finalize, which is exactly the window the editor is for.

**`share.ok = false` is not a failed share.** The proposal is created and open either way; a Talk failure costs the discussion venue, not the proposal.

On `mode: team` the proposal is posted as an ordinary chat message and `talkThreadId` is that message's id. **That is not a claim that a thread exists** — in Talk a thread *is* a message that has been replied to, and `talk_threads.id` is the root message's id, so the row appears on the first reply. Same mechanism as "Ask team for agenda items" (`MeetingService::postAgendaRequest`), which posts one chat message and nothing else. A `share.threaded` flag existed briefly in 4.5.42–4.5.44 and reported "this version of Talk does not support threads" on every instance; it was wrong and is gone.

`userIds` is filtered to **effective team members** server-side. The audience is a visibility grant, so accepting an arbitrary uid would hand a team proposal to someone outside the team.

### My Work

`ActionType::FINALIZE` (`finalize`) is new. Offered on `decision_awaiting_finalize` — the proposer's own open proposal — and calls `finalizeProposal`. Not `COMPLETE`: finalizing hands the proposal to an approver rather than finishing it, and a row labelled "Complete" for that is the kind of label that teaches people to distrust the buttons.

Editing the text is **not** a My Work action. Queue rows are one-click verbs; a proposal body is rich text with attachments, so `OPEN` takes the proposer to the Decisions tab where that editor lives.

`diagnostics` (v4.5.22) is `[{ label, value }]` and is **admin-only** — it is stripped from the member `/mywork/providers` response. A provider opts in by implementing `getDiagnostics()`; `ProviderRegistry` picks it up via `method_exists` rather than through `IWorkProvider`, so a provider integrating against a schema it can verify need not implement it. `ApprovalWorkProvider` reports which tables and columns it matched, whether the activity table is readable, and the reflected signature of `ApprovalService::approve|reject`. Values are structural only — table and column names, counts, method signatures — never file names, user ids or document content.

---

## Announcement endpoints (added 4.5.0)

In-app announcements from TeamHub HQ to **unlicensed** instances. Registry (`announcements/registry.json`) + `.md` files are source-controlled in the app. The service layer enforces license state, exact version match, role (`admin` | `everyone`), and per-user dismissal filtering; the controller only checks that a user is authenticated. Filenames travel as **query/body parameters**, never as URL segments (a trailing `.md` on a `{filename}` segment gets stripped as a request-format hint by NC's Symfony routing).

### `GET /api/v1/announcements`

List unread announcements visible to the current user.

**Auth**: any authenticated user (`#[NoAdminRequired]`).

**Response 200**:
```json
{
  "announcements": [
    { "filename": "welcome-4.5.0.md", "role": "admin", "version": "4.5.0" }
  ]
}
```

On a licensed instance the array is always empty. On an unlicensed instance the array only contains entries where (a) the registry version matches the running TeamHub version, (b) the entry's role is `everyone` or the user is an NC instance admin, and (c) the user has not dismissed it. Body text is not included — clients fetch bodies separately.

### `GET /api/v1/announcements/body?filename=<filename>`

Full markdown body for a single announcement.

**Auth**: any authenticated user (`#[NoAdminRequired]`).

**Response 200**:
```json
{ "filename": "welcome-4.5.0.md", "body": "# Welcome…" }
```

**Response 400**: `filename` missing / empty.

**Response 404**: filename is not in the registry, does not match the running version, requires admin and the caller is not admin, licensed instance, or the `.md` file is missing from disk. The single 404 case is deliberate — clients cannot distinguish "no such announcement" from "you're not allowed to see it" from "instance is licensed", which is the correct posture.

### `POST /api/v1/announcements/dismiss` (body: `{ filename }`)

Mark an announcement as read for the current user.

**Auth**: any authenticated user (`#[NoAdminRequired]`).

**Body**:
```json
{ "filename": "welcome-4.5.0.md" }
```

**Response 200**: `{ "dismissed": true }` — including the case where a row already existed (idempotent).

**Response 400 / 404**: same rules as the GET body endpoint.

### Security notes

- Filename is validated twice: once against the registry whitelist (`findVisibleEntry`), then again by shape (`str_contains` for `/`, `\`, `..`) before any filesystem read. A malformed registry entry cannot become a traversal.
- Body file size is capped at 512 KiB.
- No user content is written back — the registry is source-controlled and the dismissal ledger only stores the caller's own UID against a whitelisted filename.
- CSRF: the POST relies on NC's built-in token, forwarded by `@nextcloud/axios`. No new `#[NoCSRFRequired]`.

---

## Team app endpoints

### `GET /api/v1/teams/{teamId}/apps`

Which apps the team has. Drives Manage Team → apps.

**Response 200**: `[ { "app_id", "enabled", "config" } ]` — **one row per canonical app**, not only the ones carrying a stored toggle.

**Changed in 4.8.27, and the old behaviour was wrong.** This returned raw `teamhub_team_apps` rows, and the client fell back to `enabled = true` for any app with no row. Since resources became registry-driven that table is written only for toggle-driven apps, so most teams have no rows and Manage Team showed every installed app as switched on regardless of what the team had. Presence is now derived: an active row in `teamhub_team_app_resources`, or a toggle-only app switched on in `teamhub_team_apps`, with an explicit `enabled = 0` overriding a live resource row.

`app_id` is canonical (`lib/Constants/TeamApps.php`) — **Talk is `talk`, not `spreed`**. `PUT` on the same URL still accepts either; `TeamController::appIdToResourceKey()` maps them.

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

### `GET /api/v1/teams/{teamId}/messages`  *(`aroundMessageId` added 4.5.27)*

The team's message stream, paginated. **Auth**: member of `{teamId}`.

**Query params**: `page` (int, ≥1, default 1), `limit` (int, 1–50, default 5), and:

- `aroundMessageId` (int, optional) — *4.5.27.* Return the page that **contains** this message, overriding `page`. Added so the Open button in "What's new" can land on a specific message: the page size and the stream's ordering belong to this endpoint, so it resolves the page rather than making the client walk pages looking for the row.

  Resolution is scoped to `{teamId}`, so an id from another team behaves exactly like an unknown one. It falls back to the requested `page` — without an error — when the message has been deleted, belongs to another team, or **is the pinned message** (which renders above the stream and has no page of its own). The response's `page` field always says where you actually landed.

  The stream orders by `created_at DESC, id DESC`; the `id` tie-break was added in the same version, because without a total order the page containing a message is not well defined (and paging could repeat or skip messages posted in the same second).

**Response addition (4.9.9)**: a message the OpenProject news mirror wrote carries `origin: { kind: "openproject", newsId, url }` — `url` is `/news/{id}` on the configured host, or `null` when the post was mirrored from a previous host (the label stays, the link does not). Resolved from the ledger `teamhub_op_news_mirror` in one query per page; every other row has no `origin` key. The card renders it as *Source:* with an **OpenProject** pill.

**Response addition (4.8.7)**: every message row — and the `pinned` slot — carries `subscribed` (bool), whether the *calling* user gets a notification when somebody comments on it. Resolved in one batched query for the whole page, not per row. See the subscription endpoints below for what determines it.

---

## Comment-notification subscriptions (added 4.8.7, GitHub #95)

Per-message control over whether the caller is notified when somebody comments. **The author of a message is subscribed by default**; everybody else is not. Neither default is stored — `teamhub_msg_subscription` holds only explicit overrides, which is what makes the rule true for messages that already existed before 4.8.7 without a backfill.

There is deliberately **no GET**. The current state ships on every message row (above), and both writes answer with the state they produced.

### `PUT /api/v1/messages/{messageId}/subscription`

Subscribe to comment notifications. **Auth**: member of the message's team. CSRF-protected.

### `DELETE /api/v1/messages/{messageId}/subscription`

Unsubscribe. **Auth**: identical. An author unsubscribing from their own message is an ordinary use of this route, not a special case.

**Both** return `{ "messageId": int, "subscribed": bool }`, `404` when the message does not exist, `403` for a non-member, `401` unauthenticated.

**Who actually receives a notification** is decided when a comment is posted, not when the row was written. `MessageSubscriptionService::resolveRecipients()` takes the author (unless they opted out) plus everyone who opted in, and drops the person who just commented. What happens next depends on the message:

- **Non-public** — the result is intersected with `MemberService::isEffectiveMember()`, so a subscriber who has since left or been removed receives nothing.
- **Public** — no membership filter. A public message and its thread are readable by everyone (see below), so a subscriber who has left the team can still open exactly what the notification points at.

**Subscriptions are dropped when membership ends.** `leaveTeam`, `removeMember` (type 1 only) and the NC-admin `adminRemoveUserFromTeam` all call `MessageSubscriptionService::forgetTeamSubscriptions()`, which deletes that user's overrides for the team's **non-public** messages. Public-message subscriptions are deliberately kept.

Two guards, and they are not redundant. The deletion is hygiene — it stops dead rows accumulating. The send-time membership check is the boundary, and it is what holds when membership ends by a route that runs none of those three methods (a group membership change, or the stuck Circles event in HANDOFF §0000). `forgetTeamSubscriptions()` re-tests effective membership first and does nothing if the user is still in the team through a group or sub-team.

The notification is subject `message_comment`, one per comment, linking to `?team={teamId}&message={messageId}`.

### `GET /api/v1/messages/{messageId}/comments`  *(public branch added 4.8.7)*

**Auth**: member of the message's team — **unless the message is `is_public = 1`, in which case any authenticated user may read the thread.**

Before 4.8.7 this required membership unconditionally, so a public message's replies were visible only to the team that posted it. Publishing a message publishes the discussion under it; a public post whose thread only its own team can read is half a conversation, and the reader has no way to tell there is a rest of it.

The widening is bounded: only `is_public = 1` rows, which a team admin enables per team (`allowPublicMessages`) and an author opts into per message, and which `createMessage` refuses for every type except `normal`.

**Writing is unchanged and still requires membership** — `POST /api/v1/messages/{messageId}/comments` calls `requireMemberLevel` as it always has. In the feed, `can_view_comments` is now true for a public row while `can_comment` stays false, so the thread renders read-only rather than offering a box the server would refuse.

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

### `GET /api/v1/teams/{teamId}/messages/settings`  *(response addition 4.2.11; `publicMessagesPolicy` added 4.8.17)*

The settings envelope gains `allowPublicMessages` (bool), so the frontend knows whether to render the Public checkbox on the compose form.

**Response 200**:
```json
{
  "pinMinLevel": "moderator",
  "postMinLevel": "member",
  "linkMinLevel": "admin",
  "commentMinLevel": "member",
  "commentsEnabled": { "normal": true, "poll": true, "question": true, "decision": true },
  "allowPublicMessages": false,
  "publicMessagesPolicy": null
}
```

`publicMessagesPolicy` *(added 4.8.17)* — `null` unless a policy profile governs this team's `public_messages` field, otherwise `{ profileKey, label, isSeeded, value }`. It is what makes the Manage Team switch render read-only and name the classification; a non-null value means `POST` will refuse a differing `allowPublicMessages` with a **403**.

The `label` travels raw with `isSeeded` beside it rather than translated — a seeded profile's display name is resolved client-side by `profileDisplayName()` in `src/constants/policy.js`, because a PHP label sits outside `check:l10n`'s reach. Readable by any team member (§4.1 of `TRACK-F2-DESIGN.md` puts reading a team's own profile at member level).

`commentMinLevel` *(added 4.3.1)* — role floor for `POST /api/v1/comments`. `'member'` (default, level ≥ 1) is a no-op; `'moderator'` requires direct level ≥ 4; `'admin'` requires direct level ≥ 8. Indirect members (via group / sub-team) have no direct row and count as level 1, so they always pass the default and are refused above it — same shape as `postMinLevel`.

`commentsEnabled` *(added 4.5.38)* — whether the team takes comments on each message type at all. **This is a different question from `commentMinLevel` and produces different UI.** The role floor leaves the thread readable and disables the composer; a `false` here removes the comment count and the comment section entirely, so the reader sees the message only.

- Always carries all four keys. `question` **and, since 4.6.0, `decision`** are always `true`: neither is offered a checkbox and the server refuses to store a `false` for either. A question's comments are its answers; a decision stopped being a type the composer can create in 4.5.42, so a switch in *message* settings governed something nobody could make there. The always-on list is filtered when the stored value is **read** as well as written, so a `decision: false` saved before 4.6.0 stops having an effect on upgrade rather than being stranded with no control left to clear it.
- Default is all-`true`, which is the behaviour every team had before 4.5.38. Stored as the **disabled** set (appconfig `commentDisabledTypes_<teamId>`, comma-separated, empty by default) so no migration and no write are needed for teams that never touch it.
- Enforced on comment **writes** only — `POST` and `PUT /api/v1/comments` return **403**. `GET .../comments` and `DELETE /api/v1/comments/{id}` are deliberately unaffected: switching the setting off is a "no new comments here" policy, not a retraction, so existing rows stay listable (flipping it back is lossless) and an admin can still clean up.

### `POST /api/v1/teams/{teamId}/messages/settings`  *(field added 4.2.11; commentMinLevel added 4.3.1; commentsEnabled added 4.5.38; policy refusal 4.8.17)*

Body accepts `allowPublicMessages` (bool/int/string coerced), `commentMinLevel` (string: `'member'`/`'moderator'`/`'admin'`, default `'member'`) and `commentsEnabled` (object: type → bool). Team admin required — same as before.

**403 — policy refusal** *(added 4.8.17, `TRACK-F2-DESIGN.md` §4.4)*. When a policy profile governs the team's `public_messages` field and the body's `allowPublicMessages` **differs** from the governed value, the whole request is refused before any setting is written, with `{"error": "Public messages are set by the \"…\" classification and cannot be changed for this team."}`. A `team.policy_write_refused` audit row records the caller, the profile, the expected value and the attempted one.

Only a *differing* value is refused: a client echoing the governed value back while editing the four level floors is attempting nothing, and failing that save would let one governed field lock the entire settings form. Note that an absent `allowPublicMessages` is read as `false`, which **is** a differing value against a profile governing it as on — a client must send the governed value rather than omit the key. `GET`'s `publicMessagesPolicy` is where that value comes from.

This is the only endpoint in the app that refuses a write on policy grounds: `public_messages` is the sole `ENFORCED` field in `PolicyField`, and the Circles config bits are forced by `TeamService::updateTeamConfig()`'s overlay rather than refused. The refusal applies to Nextcloud administrators too — the way to change a governed value is to change the profile or clear the team's classification, not to write past it.

`commentsEnabled` is **omission-safe in both directions**: leaving the field out leaves the stored policy untouched (a pre-4.5.38 client PUTting the form it knows about cannot wipe a policy it never rendered), and a partial map turns nothing off by omission — only an explicit `false` disables a type. `question` and `decision` are ignored if sent (4.6.0). Values are coerced like `allowPublicMessages`: `false`/`0`/`"0"`/`"false"`/`""` are off, anything else is on.

---

### `GET /api/v1/messages/feed`  *(added 4.2.12, extended 4.2.13/4.2.14, licensed 4.3.0, filters + interaction rights 4.5.26)*

The personal "What’s new" feed. Combines team messages from every team the caller is a member of, public messages from other teams, and Talk polls + thread starters from rooms connected to any of the caller's teams. One paginated call, chronological.

**Auth**: authenticated NC user (`#[NoAdminRequired]`). **License**: requires an active TeamHub license (`enforcementLevel` = `none` or `grace`). Unlicensed / soft-locked instances receive `403 { error, licenseGate: true, enforcementLevel }` — frontend surfaces a license-specific error and the sidebar entry is hidden.

**Query params**:
- `includeTeam` (bool, default `1`) — accepts `1/0/true/false/yes/no/on/off`.
- `includePublic` (bool, default `1`) — same coercions.
- `includeTalk` (bool, default `1`) — same coercions. When on, adds Talk polls (`source: 'talk-poll'`) and thread starters (`source: 'talk-thread'`) from rooms the caller can reach via a team membership. Talk items carry `room_id`, `room_token`, `room_name`, and the resolved `team_id` (first-matching own-team via `talk_attendees`).
- `limit` (int, 1–100, default 20)
- `offset` (int, ≥0, default 0)
- `from` / `to` (int, unix seconds, default 0 = unbounded) — *4.5.26.* Inclusive bounds on `created_at`. A reversed pair is swapped rather than rejected. **The caller resolves the range**: "today" is the viewer's today and the server does not know their timezone.
- `teamIds` (repeatable, or comma-separated; max 100) — *4.5.26.* Narrows to these teams. It is ANDed onto the membership/public visibility clause, never substituted for it, so naming a team you are not in returns that team's *public* messages and nothing more.
- `types` (repeatable, or comma-separated; max 10) — *4.5.26.* One or more of `normal`, `question`, `poll`, `decision`. Unknown values are dropped, not rejected. Talk rows have no `message_type`, so they are excluded entirely while this is set. **API-only since 4.5.29** — the rail's Types section was removed (it duplicated the Show switches) and the parameter is no longer sent by the frontend or stored in preferences.
- `includeSystem` (bool, default `1`) — *4.5.26.* `0` drops rows with `is_system = 1` (currently only milestone auto-posts). **API-only since 4.5.31** — the switch was removed from the rail and the frontend no longer sends it.
- `includeDecisions` (bool, default `1`) — *4.5.29.* Decisions are their own Show switch, and **since 4.5.31 they have their own branch in the WHERE** (`message_type = 'decision' AND team_id IN (caller's teams)`) rather than arriving through the team branch — so this switch governs them alone. Before that, turning `includeTeam` off hid every decision too. `0` drops every decision row regardless of `includeTeam` / `includePublic`. With it on, **only decisions that are still open** are listed — status `open` **or `proposed`**, which are two names for the same state (rows written by earlier versions use the latter, and `TeamDecisionsView` has always accepted both). Everything else: a finalized, approved, denied or withdrawn decision is a record, and the Decisions tab is where records live. Surviving rows carry a hydrated `decision` object. A decision-typed message with no decision row is dropped — it has no state to show. If hydration fails, decisions are returned **unfiltered** rather than dropped, because an empty result reads as "there are none".
- `includeMentions` (bool, default `1`) — *4.5.28, replacing `mentionsOnly`.* `0` **excludes** messages that mention the caller, **and drops the `talk-mention` source entirely** (4.5.33). It is an inclusion switch like the three above it; showing *only* mentions is the frontend's Mentions tab, computed from `sourceCounts.mentions`. The exclusion is applied in PHP, not SQL: `MessageService::parseMentionCandidates()` tokenises the body properly, and the mapper's `LIKE` is only ever a pre-filter, so using it to exclude would drop rows that merely *look* like a mention. Talk rows are never affected — TeamHub does not parse mentions inside Talk, so a Talk row is neither a mention nor not-a-mention.

  Mention matching lives in `Mentions\MentionParser` and uses `@nextcloud/vue`'s own grammar (`MENTION_START` + `MENTION_SIMPLE`) rather than a pattern of ours, so the reader and the writer cannot disagree. It is **case-insensitive**, understands both forms (bare `@<id>`, quoted `@"<id>"`), matches `@JDoek@aaenhunze.nl` — a uid containing an `@` — does not match `@jaapt` for `jaap`, does not read a URL's path as a mention, and does not read a plain e-mail address in prose as a mention of its domain.

**Mirrored news rows (4.9.9).** A `team` / `public` row the OpenProject news mirror wrote carries the same `origin` block as on `GET …/teams/{teamId}/messages`; Talk and `openproject` rows never do. The All tab shows such a row only when its live `openproject` news card is not on the same page (client-side, `dedupeMirroredNews()`).

**`talk-mention` rows (4.5.33).** Talk chat messages that name the caller, from rooms reachable through their teams — the rest of a conversation is not carried. Shaped like the other Talk sources (`id` is the `oc_comments` id, plus `room_id` / `room_token` / `room_name` / `team_id`, `actor_id`, `actor_type`, `message`, `created_at`) and carrying `can_reply`. Governed by `includeMentions`, **not** `includeTalk`: a message naming you is a mention first and a chat message second. Counted under `sourceCounts.mentions` only — not `talk`, not `team` — so one tab covers both kinds of mention.

**Teams that have been removed are excluded (4.5.27).** The `is_public = 1` branch used to match any team, so a public post outlived its team. Rows are now dropped when the team has no `circles_circle` row (hard-deleted) or has a `pending` row in `teamhub_pending_dels` (archived or queued for deletion). The team branch was already safe — `getCurrentUserTeamIds()` has excluded pending-deletion teams since before this endpoint existed.

**Talk polls may have no date (4.5.27).** Where Talk's `talk_polls` has no recognised timestamp column, the row carries `created_at: 0` and `date_unknown: true` rather than a fabricated value, and is **excluded whenever `from` or `to` is set** — it cannot be shown to satisfy a window there is no way to test it against.

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
      "isSystem": false,
      "created_at": 1750000000,
      "updated_at": 1750000000,
      "comment_count": 3,
      "can_view_comments": true,
      "can_comment": true
    }
  ],
  "hasMore": true,
  "total": 41,
  "limit": 20,
  "offset": 0,
  "sourceCounts": { "all": 41, "team": 22, "public": 7, "talk": 12, "mentions": 3, "decisions": 2 },
  "facets": {
    "teams": [{ "id": "abc123", "name": "Marketing", "count": 9 }],
    "types": [{ "id": "decision", "count": 4 }]
  }
}
```

`source` is a synthetic per-row field: `'team'` when the row's `team_id` is in the caller's own team memberships, `'public'` otherwise. The classification is done server-side so the frontend doesn't need to re-check membership. A user's own-team public post shows once, classified as `'team'`.

**Interaction rights (4.5.26).** The feed spans many teams and comment permission is per-team plus per-message, so each row states what this caller may do with it. These are for rendering only — `CommentController` and `FeedTalkController` re-check on every write.

| Field | On | Meaning |
|---|---|---|
| `can_open_team` | every row | Caller is a member of the row's team (4.5.39). `false` only for a public post from a team they are not in — the one row the feed carries without membership. The card hides its team link, its Open button and its overflow menu when this is false: every one of those destinations lands inside the team, where a non-member gets an empty page. **Not derivable from `can_view_comments`** — since 4.5.38 that is also false for a member of a team that switched the type's thread off, and such a member can open their team perfectly well. Absent (pre-4.5.39 server) means yes. |
| `can_view_comments` | message rows | Caller is a member of the row's team **and** the team has comments enabled for the row's `messageType` (4.5.38). `false` for a public post from a team they are not in — `listComments` would refuse — and `false` for a type the team switched off, which is what drops the count with it. |
| `can_comment` | message rows | Also passes the team's `commentMinLevel` and the message is not decision-locked. |
| `comments_locked` | message rows | Present and `true` when a decision's terminal state froze its thread. |
| `comment_count` | message rows | Filled only where `can_view_comments` is true; `0` otherwise. |
| `can_reply` | `talk-thread` | Room reachable through one of the caller's teams **and** Talk accepts them as a participant who may post. |
| `can_vote` | `talk-poll` | As `can_reply`, plus the poll is open (`status === 0`). |
| `my_votes` | `talk-poll` | Option indices this caller has already picked. Empty when Talk's vote table doesn't answer. |

`sourceCounts` and `facets` are computed on the merged result **before** the source tab narrows anything, so selecting one tab leaves every other tab's number intact. `team` / `public` / `talk` / `openproject` partition the feed; `mentions` and `decisions` are lenses over those same rows, so the numbers are **not** expected to sum to `all`.

**OpenProject news rows (v4.9.7).** `includeOpenProject` (bool, default `1`) adds one row per OpenProject **news** item written inside the period, from every project the caller's teams are linked to, read live as the caller (OPENPROJECT.md §6.3) — and, as a side effect of the read, mirrors each item once into its team's message stream (§6.1). `projectIds` (list, max 50, digits only) narrows them; `teamIds` and `from`/`to` apply too ("all time" = the last 90 days for OpenProject); `types` excludes them wholesale like Talk rows. Shape: `{ "id": "op:<connection>:<projectId>:news:<newsId>", "source": "openproject", "activityType": "news", "subject" (the title), "message" (the summary, or the first 400 characters of the body, plain text), "created_at", "team_id", "team_name", "author_id": "" (not a Nextcloud account), "actor_name" (the author as OpenProject names them), "project": { id, name, url }, "news": { id, url }, "opens_externally": true, "can_open_team", "can_view_comments": false, "can_comment": false, "comment_count": 0 }`. Counted under `sourceCounts.openproject` only. `facets.projects` lists `{ id, name, teamId, count }` for the projects present before the `projectIds` narrowing. The payload also carries `sources.openproject` — `{ state: ok | partial | error | auth_required | not_connected | unavailable | skipped, code, message, covered, skipped }` — so the client can render one notice while every other source stands; a failing OpenProject never fails the feed. Work-package edits are **not** feed rows (Justin, 2026-09-14: the feed is for news and messages).

`hasMore` is derived from the merged list length against the page window, so it can no longer offer a next page that turns out to be empty.

**Failures**: `403` — not authenticated. `500` — internal error (logged; generic body returned).

**Routing note**: registered above the `/messages/{messageId}` catchalls in `appinfo/routes.php` so the literal `feed` segment wins over `{messageId}=feed`.

---

### `GET|PUT /api/v1/messages/feed/preferences`  *(added 4.5.26)*

The viewer's saved Feed control defaults — the "Save as default" button on the rail. Personal, no team scope; stored as one JSON blob in `oc_preferences` under the `teamhub` app id, the same shape My Work's personal preferences use.

**Auth**: authenticated NC user (`#[NoAdminRequired]`). No licence gate — reading and writing your own presentation preference is inert without the feed, and refusing it would surface a confusing error on an instance whose licence lapsed.

**Body (PUT) / Response (both)**:
```json
{
  "includeTeam": true, "includePublic": true, "includeTalk": true,
  "includeMentions": true, "includeDecisions": true,
  "period": "all", "customFrom": 0, "customTo": 0,
  "teamIds": [], "perPage": 20
}
```

`types` (4.5.29) and `includeSystem` (4.5.31) are **not** part of this shape. A blob written earlier still carries them; both are ignored on read and dropped on the next save, because the controls that could clear them no longer exist — a stored `false` with no switch left to flip would filter someone's feed permanently.

A blob stored before 4.5.28 carries `mentionsOnly` instead. It is **not** migrated — the old key meant the opposite thing, so `includeMentions` falls back to its `true` default rather than inheriting a value that would turn "show me only my mentions" into "hide my mentions".

`period` is one of `all | today | week | month | custom`. The server stores the **name**, never a resolved range — the browser resolves it, for the timezone reason above.

Every field is validated on write **and on read**: a stored blob is user-controlled, and one edited by hand (or written by a version whose vocabulary has since changed) must not be able to widen a query. A field that fails validation falls back to its own default without costing the rest.

`PUT` returns the state as stored, so the caller never has to guess what survived validation.

**Failures**: `401` — not authenticated. `500` — internal error.

**Routing note**: same fixed-segment rule as `/messages/feed` — registered above the `{messageId}` catchalls.

---

## "What’s new" Talk interaction (added 4.5.26)

Replying inside a Talk thread and voting on a Talk poll without leaving the feed.

**Three independent gates on every one of these**, none of which is the frontend:

1. **Licence** — "What's new" is a licensed feature and these endpoints exist only to serve it. Same ladder as the feed: `403 { licenseGate: true }`.
2. **Room → team** — `MessageService::resolveFeedRoomTeam` requires the room to be connected to a team the caller is a member of, resolved through the *same* `talk_attendees` mapping that put the row in their feed. A token they can reach some other way (a private chat) is refused: reachable is not the same as reachable through TeamHub.
3. **Talk** — every write builds a real `Participant` through Talk's own services, so read-only rooms, lobbies, moderation and bans apply without TeamHub reimplementing any of them.

Writes never touch Talk's tables. Reads do, following the existing "read what's actually there" pattern for Talk's shifting schema (DESIGN §2.68). Because Talk's method signatures move between versions, both write paths match arguments **by reflection** against the real method rather than assuming an order — the same approach `ApprovalWorkProvider` adopted after v4.5.21 shipped a guessed argument list. A parameter that cannot be filled throws **with its name**, so the next Talk change is a one-line fix rather than an investigation.

TeamHub's `commentMinLevel` deliberately does **not** apply here: it governs comments on TeamHub messages, and applying it to a Talk conversation would invent a restriction the team never configured — the same member can say the same thing in the Talk tab one click away.

### `GET /api/v1/feed/talk/{token}/threads/{threadId}/replies`

Replies inside a thread, oldest first. `limit` (int, 1–200, default 50). Talk system messages (joins, calls, shares) are dropped — they are chrome, not replies.

```json
{ "replies": [{ "id": 91, "actor_id": "jdoek", "actor_type": "users",
                "actor_display_name": "Justin Doek", "message": "…",
                "created_at": 1750000000 }], "count": 1 }
```

Only `users` actors get a display name; a guest or federated actor keeps its raw id rather than being resolved as a local user who happens to hold that uid.

### `POST /api/v1/feed/talk/{token}/threads/{threadId}/replies`

Body: `{ "message": "…", "thread": 1 }` — message non-empty, max 8000 characters. Returns `201` with the refreshed thread (`replies`, `count`) so the card updates in one round trip.

`thread` (bool, default `1`) — *4.5.33.* Send `0` when replying to a **`talk-mention`**: that is an ordinary chat message with no thread of its own, so handing its comment id to Talk's `$threadId` parameter would assert an association that does not exist. With it off, Talk derives whatever threading it wants from `replyTo`. It is a hint about threading and grants nothing — a client getting it wrong costs threading, never access.

`threadId` is checked to belong to `{token}`'s room before anything is written, so a thread id from another conversation cannot be threaded into one the caller can reach.

**Failures**: `400` with **Talk's own message** when Talk refuses — surfaced rather than swallowed, because a generic string is what made the 4.5.22 Approval signature mismatch take a whole session to diagnose.

### `POST /api/v1/feed/talk/{token}/polls/{pollId}/vote`

Body: `{ "optionIds": [0, 2] }` — indices into the poll's option list, max 64. **An empty list is valid**: that is how Talk expresses retracting a vote.

```json
{ "success": true, "my_votes": [0, 2],
  "votes": { "0": 4, "2": 1 }, "num_voters": 5, "status": 0 }
```

The fresh tallies come back with the vote so the card updates its bars in place; a full feed refresh would collapse every expanded thread on the page to move one percentage. `votes` / `num_voters` / `status` are `null` when Talk's schema didn't answer, and the caller then leaves the tallies as they were.

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

## Members-widget endpoint (response extended 4.5.34)

### `GET /api/v1/teams/{teamId}/members/all`

Flat, de-duplicated list of everyone with effective access to the team — direct members plus anyone reaching it through a group or sub-team. Feeds the members widget (Members / Tomorrow / Search tabs) and `@mention` autocomplete.

**Auth**: team **member** required — `MemberService::getAllEffectiveMembers` opens with `requireMemberLevel($teamId)`, and it runs before either availability check, so a non-member never reaches them.

**Response 200**:
```json
{
  "members": [ { "userId": "...", "displayName": "...", "email": "...", "phone": "...", "ncStatus": {} } ],
  "talkAvailable": true,
  "mailAvailable": true
}
```

- `talkAvailable` — Talk (`spreed`) is enabled for the **caller**. Gates the Talk contact icon on every row.
- `mailAvailable` *(added 4.5.34)* — the caller can compose in Nextcloud Mail: the `mail` app is enabled for them **and** they have at least one row in `mail_accounts`. When true the members widget points a member's email icon at `/apps/mail/mailto?to=…`; when false it keeps the plain `mailto:` link that hands off to the OS handler.

Both flags describe the **caller**, never a member in the list — they decide which affordances render, not who may be contacted. The account check is required rather than merely nice: Mail's own `/mailto` route verifies an account exists before opening its composer, so linking a user with none there drops them on the setup screen and discards the address.

`email` and `phone` are only present when the member's profile scope permits it (`IAccountManager`, private scope excluded). A malformed address is dropped by the client rather than rendered as a link — see `MemberRow.safeEmail`.

**Failures**:
- `500` — **including the not-a-member case.** `MemberService::getAllEffectiveMembers` raises `AccessDeniedException`, but this method's catch block predates `ExceptionResponseTrait` and maps every `\Throwable` to `500` with `$e->getMessage()` in the body. Denial still works; the status code is simply wrong. Logged in `HANDOFF.md` as an open issue — documented here as-is rather than as the `403` it ought to be.
- Both availability flags degrade to `false` independently rather than failing the request, so a broken Mail install cannot take the members widget down with it.

---

## Team creation (extended 4.9.4)

### `POST /api/v1/teams`

**Body**: `{ name, description?, profileKey?, templateKey?, openProjectId? }`.

`openProjectId` (int, 4.9.4) is **required with, and only with, `templateKey: "openproject"`**: the OpenProject project the team is the workspace for. The link is part of creating the team — everything that can refuse it is checked **before** the circle exists (the caller's OpenProject connection; the project read as the caller; the caller administers it or it is public; no other team links it), the team is created, typed `openproject` and linked in the same request, and if the link still fails (the `th_opl_proj_uq` race) the team is deleted again. So an OpenProject team never exists without its project, and there is no later route to link one. **Response 201** carries the link as `openProject` (`{ projectId, projectIdentifier, projectName, host, stale, createdBy, createdAt, updatedAt, lastValidatedAt, urls }`).

**Failures** for the OpenProject part use the OpenProject status/`code` table (§ OpenProject endpoints): **409** `project_already_linked` with `teams: [ { teamId, name|null } ]` (`name` only when the caller is a member of that team); **403** with the sentence when the caller neither administers the project nor is it public; **404** `project_not_found`; **412** not connected; **422** integration unusable; **400** `templateKey: "openproject"` without a project, or a project with another template. The wizard returns the creator to the project picker on any of them. Everything else as before.

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
  "level": 8,
  "nc_avatar_supported": true
}
```

- `nc_avatar_supported` (4.5.4) — `true` when the installed Circles app is version ≥ 34, i.e. the Nextcloud Teams per-circle avatar OCS API exists. When true the frontend shows the Teams-native avatar (fetched from `GET /ocs/v2.php/apps/circles/circles/{id}/avatar` and swapped into `image_url`) and routes set/remove there; when false it uses TeamHub's own `image_url` storage. Instance-global value, surfaced per team to avoid a separate mount-time round-trip. Also present on `GET /api/v1/teams/browse`.
- `level` — current user's Circles level in this team. Values: `0` (indirect member via a group or sub-team), `1` (member), `4` (moderator), `8` (admin), `9` (owner). Sourced from the existing SELECT on `circles_member.level` — no extra query. Indirect access maps to `0` because the underlying LEFT JOIN returns NULL for those rows.

---

## Per-user preferences (added 4.4.12)

User-scoped UI preferences stored in `oc_preferences` under the `teamhub` app id. Not team-scoped; no membership check applies because every value is the caller's own.

### `GET /api/v1/preferences`

**Auth**: any logged-in user (`#[NoAdminRequired]`).

**Response 200**:
```json
{ "gettingStartedHint": true }
```

- `gettingStartedHint` — whether to render the "Need help getting started?" callout above the sidebar help button. Defaults to `true` for every user including existing ones. The callout is additionally gated on the instance being unlicensed, since the help button it points at is itself licence-gated.

### `PUT /api/v1/preferences`

**Auth**: any logged-in user (`#[NoAdminRequired]`). CSRF-protected — no `#[NoCSRFRequired]`.

**Body**: `{ "gettingStartedHint": bool }`. Only keys present in the body are written, so a future preference can be added without every caller sending the whole set. A non-boolean value returns `400`; `true`/`false`/`1`/`0`/`"true"`/`"false"` are all accepted.

**Response 200**: the same envelope as the GET, reflecting the stored state.

---

## Leave-team endpoint (response extended 4.4.8)

### `POST /api/v1/teams/{teamId}/leave`

Removes the caller's own direct membership. Requires a direct member row (`level` 1–8); the owner (`level` 9) must transfer ownership or delete the team instead.

**Response 200 (4.4.8)**:
```json
{ "success": true, "stillMember": false }
```

- `stillMember` — `true` when the caller can still reach the team after their direct row is deleted, because a group or sub-team also grants them access. The team stays in their sidebar at `level: 0`, so the client must not report "you have left the team". `false` is the clean-exit case.

**Errors**: `403` with `{"error": "indirect_member"}` when the caller has no direct row but does have indirect access — a sentinel, not a display string; the client maps it to its own message. `400` with `{"error": "..."}` for owner-leave and not-a-member.

**Behaviour change (4.4.8)**: the endpoint now rebuilds Circles' `circles_membership` cache after the delete. Before this, the raw `circles_member` DELETE left the caller's cache row intact, so they kept full access to a team they had just left while `level: 0` hid the Leave action from the sidebar menu.

---

## Ownership transfer (eligibility widened 4.9.0)

### `POST /api/v1/teams/{teamId}/transfer-owner`

Hands the team's owner role (Circles level 9) to another member. This is the **team owner's** route; promoting an outsider is a separate NC-admin action in `MaintenanceController`.

**Auth**: `#[NoAdminRequired]`, and the caller must be the current owner — `MemberService::requireOwnerLevel()`. CSRF-protected.

**Body**: `application/x-www-form-urlencoded`, `userId=<uid>`. Must be form-encoded, not JSON, so NC's dispatcher can inject `$userId` as a typed argument.

**Response 200**: `{ "success": true }`

**Errors**: `400` `userId is required` (empty), `Invalid userId` (over 64 characters), `Target user is not a member of this team` (not a member by any route).

**Eligibility change (4.9.0)** — the target may now be a member **by any route**. The gate was `getMemberLevelFromDb()`, which reads direct `circles_member` rows only, so somebody who reached the team through an attached group or a nested team was refused as "not a member of this team" while the Members tab listed them as one. It now uses `MemberService::getEffectiveMemberLevel()`, which takes the higher of the direct row and what the `circles_membership` cache credits through a group.

The boundary being defended is **member vs outsider**, and an inherited member is on the member side of it. Genuine outsiders are still refused, so this widens who can receive the role without widening it beyond the team.

`MaintenanceService::assignOwner()` writes the direct `circles_member` row as part of the transfer, so an inherited member becomes a direct member in the same call — no separate promotion step, and no second request.

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

**Response addition (4.6.17)** — `joinPolicy`: `"open" | "request" | "closed"`, from `CirclesConfig::joinPolicy()`. `requiresApproval` is retained and now means only what its name says (`joinPolicy === "request"`); it previously returned `true` for invite-only teams as well, which is why those cards offered a Request Access button that Circles refuses.

---

## Team preview endpoint (added 4.6.17)

### `GET /api/v1/teams/{teamId}/preview`

The non-member view of a team, for somebody following a shared team link (`?team=<id>`, written by the Copy link action). **Deliberately not membership-gated** — `GET /api/v1/teams/{teamId}` is, and returns 404 to non-members, which is what made a copied link a dead end.

Authenticated users only. Returns 404 for a malformed id, an unknown id, and for any circle that is not a TeamHub team — the query gates on `circles_circle.source = 16`, so personal (1), group (2) and app-owned (10001) circles are never described here.

```json
{
  "id": "...",
  "name": "Design Guild",
  "description": "...",
  "image_url": "/index.php/apps/teamhub/teams/.../image",
  "joinPolicy": "open",
  "membership": "none"
}
```

- `joinPolicy` — `open` (join lands you in the team), `request` (a moderator approves first), `closed` (invite only; nothing to press).
- `membership` — `member` (direct or via a group/sub-team), `requesting` (a join request or an invitation is outstanding), `none`.
- **`description` is `""` and `image_url` is `null` when `joinPolicy` is `closed`.** On an invite-only team both are content; the name alone is what the link holder needs in order to learn the link is not for them.

Disclosure note: any authenticated user who supplies a team id learns that team's name and how to get in. That is the feature — a link is worth nothing if the recipient cannot see what they are being offered — and it is not an enumeration surface, since a Circles `unique_id` is a 31-character random token.

---

### `GET /api/v1/teams/{teamId}/config`  *(undocumented until 4.8.17; membership gate and `policy` added 4.8.17)*

The team's Circles config bitmask, and which of its bits the team's policy profile fixes. Read by Manage Team → Settings, its only caller.

**Response 200**:
```json
{
  "config": 40,
  "policy": {
    "profileKey": "confidential",
    "label": "Confidential",
    "isSeeded": true,
    "values": { "cfg_visible": false, "cfg_open": false, "public_messages": false }
  }
}
```

- `config` — the raw `circles_circle.config` integer. Bits per `CirclesConfig`: 8 visible, 16 open, 32 invite, 64 request, 256 protected, 8192 root.
- `policy` — **`null` for an unclassified team**, which is every team until an administrator assigns a profile. `values` carries **only the fields the profile governs**; a key's presence is the fact and its value is what the field is fixed to, so a client must test for the key rather than for a truthy value — a field fixed to *off* is `false`.

**Team member required** *(gate added 4.8.17)*. This previously checked authentication only, so any authenticated user could read any team's privacy bitmask by id. The one caller is a team screen, so nothing legitimate lost access.

The governed Circles bits are `ASSERTED`, not enforced: `PUT` does not refuse them — `TeamService::updateTeamConfig()` masks them through the profile's overlay and the write is silently normalised — and Contacts or the Teams app can change them without passing through TeamHub at all. `policy` exists so our own screens can render them read-only, which `TRACK-F2-DESIGN.md` §4.4 requires; detection of a change made elsewhere is the drift report's job, not this endpoint's.

---

## Join endpoint (behaviour change 4.6.17)

### `POST /api/v1/teams/{teamId}/join`

Unchanged shape, two behaviour fixes:

- **403 `{"error": "invite_only"}`** when the team does not carry `CFG_OPEN`. Circles already refused these, but `MemberService::requestJoinTeam` caught that refusal and answered it with a direct DB insert, so an invite-only team could be joined-by-request from any crafted POST and its admins were notified about a request the team's configuration does not permit.
- **A team with `CFG_OPEN` *and* `CFG_REQUEST` now stays `Requesting`** and notifies moderators. The fallback previously tested `CFG_OPEN` alone and flipped the row straight to `Member`, so the moderator-approval setting existed in Manage Team and nowhere else.

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

## Team calendar events (range reader added 4.6.20)

### `GET /api/v1/teams/{teamId}/calendar/events/range`

Every event **instance** on the team's calendars overlapping a window. Added in 4.6.20 as the data source for the team calendar grid, which replaced the NC Calendar iframe on the Calendar tab.

**Auth**: `requireMemberLevel($teamId)` — team membership. There is no unauthenticated path to this data, which is the point of the endpoint: before 4.6.20 the tab showed a single calendar by iframing `/apps/calendar/p/{token}`, and that required publishing every team calendar as an `access = 4` public share.

**Query parameters:**

| Param | Type | Notes |
|---|---|---|
| `start` | string | Required. Anything PHP `DateTime` parses; the grid sends ISO-8601. Inclusive. |
| `end` | string | Required. Exclusive. Must be later than `start`, and no more than **400 days** after it — the cap bounds recurrence expansion, not the query. |

**Response:**

```json
{
  "events": [
    {
      "id": "abc.ics#1786100400",
      "uid": "…", "uri": "abc.ics",
      "title": "Standup",
      "start": "2026-08-03T07:00:00+00:00",
      "end": "2026-08-03T07:30:00+00:00",
      "startTs": 1786100400,
      "allDay": false,
      "recurring": true,
      "location": null, "description": null, "status": null, "organiser": null,
      "attendees": [{ "name": "…", "email": "…", "partstat": "ACCEPTED" }],
      "calendarId": 4, "calendarName": "Design lab", "calendarColor": "#d81b60",
      "editUrl": "/apps/calendar/timeGridWeek/now/edit/sidebar/{base64}/1786100400"
    }
  ],
  "truncated": false
}
```

Notes that matter to a caller:

- **`id` is per instance, not per event** (`{uri}#{startTs}`). A recurring series shares one `uid` and one `uri` across every occurrence, so keying on either collapses them.
- **All times are UTC.** `VCalendar::expand()` converts and strips VTIMEZONE. All-day events are emitted as `YYYY-MM-DD` with `allDay: true`.
- **Recurrence is expanded server-side** and `RECURRENCE-ID` overrides are honoured, so a moved occurrence reports its own time and title. `recurring` is read from the master object *before* expansion — every expanded instance carries a `RECURRENCE-ID`, so it cannot be derived afterwards.
- **Range filtering is by overlap**, so an event starting before `start` and ending inside the window is included.
- **`truncated: true`** means the 2000-instance cap was reached and the list is incomplete.
- **`editUrl`** points at NC Calendar, since the grid is read-only. Its last segment is the instance start, so the link opens the occurrence rather than the series start.

Sibling endpoints on the same prefix are pre-existing and were not previously documented here: `GET …/calendar/events` (upcoming feed for the home widget, capped and ordered by `lastmodified`, **does not** expand recurrence), `GET …/calendar/events/week`, `POST …/calendar/events`, `DELETE …/calendar/events`. **4.9.10:** each `GET …/calendar/events` row carries `openProjectMeetingId` — the OpenProject meeting id when the event is a copy the meeting sync wrote (from its `X-TEAMHUB-OPENPROJECT-MEETING` property), else `null`.

---

## Meeting endpoints (extended 3.81.2)

### `POST /api/v1/teams/{teamId}/meetings`

Create a team meeting — writes a notes file in the team's `Meetings/` folder, then writes a calendar event linked to that notes file. The wizard's Add Meeting button is the primary caller. Existing fields are unchanged; the additions below are all optional and default to safe behaviour.

**Auth**: caller must meet the team's `meeting_min_level` (1/4/8). Enforced inside `MeetingService::enforceMinLevel`.

**Body (additions in 3.81.2 — bold fields are new):**

| Field | Type | Notes |
| --- | --- | --- |
| `title` | string | Required. ≤200 chars. |
| `date` | string | `YYYY-MM-DD`. Wall-clock, in the **caller's** timezone. |
| `startTime`/`endTime` | string | `HH:MM` 24h. Wall-clock, in the **caller's** timezone — resolved against their `core`/`timezone` preference (v4.6.21). Before 4.6.21 these were read as UTC, so the event landed at the caller's UTC offset. An API client that was compensating for that must stop. |
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

## Config bitmask integrity (response shape changed 4.5.37)

### `GET /api/v1/admin/maintenance/config-check`

**Auth**: NC admin (`MaintenanceService::requireNcAdmin()`). Scans every `source = 16` team.

**Response 200** — was a bare `{ issues: [...] }`; now carries two independent findings:
```json
{
  "issues":     [ { "id": "…", "name": "…", "config": 1026, "badBits": 1026 } ],
  "appClaimed": [ { "id": "…", "name": "…", "config": 131104, "appBits": 131072 } ]
}
```

| Field | Meaning |
|---|---|
| `issues` | `config & SYSTEM_BITS_FORBIDDEN_ON_USER_TEAMS` — CFG_SINGLE 1 / CFG_PERSONAL 2 / CFG_SYSTEM 4 / CFG_NO_OWNER 512 / CFG_HIDDEN 1024 / CFG_BACKEND 2048 (= 3591). Real corruption: Circles' `CircleConfig::verify()` rejects any config update on a circle carrying one, so no app can change it again. Repairable via `POST …/reset-team-config/{teamId}`. |
| `appClaimed` | `config & APP_OWNED_BITS` — CFG_APP (131072). Another Nextcloud app has claimed the circle; Collectives sets it via `flagCircleAsAppManaged` when a collective binds. **Informational, never an issue, and not repairable here.** |

**Why they split (4.5.37).** CFG_APP used to sit in the forbidden mask, so twelve healthy teams on Justin's instance reported as corrupt — every one for bit 131072 alone, eleven with a collective and the twelfth with one that had been deleted (`deleteCollective` does not clear the flag). Worse, `resetTeamConfig` clears the whole forbidden mask, so **Repair was stripping the other app's claim** — and 4.5.35's Wiki-enable error message points admins at that button. `CollectivesService::forbiddenConfigBitsOnTeam()` had already excluded CFG_APP for the right reason: it is in neither `$DEF_CFG_CORE_FILTER` nor `$DEF_CFG_SYSTEM_FILTER`, so it does not break Circles' config API. The two lists now agree.

A team can appear in both arrays — corruption and an app claim are independent facts about it. Reset no longer touches CFG_APP, so repairing a corrupt wiki-enabled team leaves its collective binding intact.

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

**Note (v4.4.0)**: the outbound daily-report path (`SendTelemetryJob` + `DailyReportJob`) that used to POST the `preview` payload to `tldr.host/business/telemetry.php` was removed with the air-gapped-only licensing pivot. `TelemetryService::sendDailyReport()` remains as a defensive no-op. The `GET /api/v1/admin/telemetry` endpoint above stays live as a self-inspection tool — nothing leaves the instance.

---

## License endpoints (v3.100.0, Track F — Licensing. Removed `refresh` in 4.3.20 and `trial` in 4.4.0)

TeamHub uses fully manual, air-gapped licensing (v4.4.0+). Trials and paid licenses are issued by hand from the licensing dashboard in response to email; the customer pastes the returned JWT into the License tab. No outbound calls to the licensing back-end; no daily check-ins; no install/uninstall pings; no renewed-JWT downloads.

### `GET /api/v1/admin/license`

Returns the full license status envelope for the License tab and the enforcement gates.

**Auth**: NC admin required (`#[AuthorizedAdminSetting]` on `AdminSettings`). `#[NoCSRFRequired]` (GET, read-only).

**Response 200**:
```json
{
  "hasKey":               true,
  "valid":                true,
  "kind":                 "airgapped",
  "seats":                100,
  "isTrial":              false,
  "licenseId":            "lic_2026_abc123",
  "customer":             "customer@example.com",
  "uuid":                 "abcd1234efgh",
  "expiresAt":            1735689600,
  "daysRemaining":        45,
  "paidUntil":            1733097600,
  "paidDaysRemaining":    15,
  "graceDays":            30,
  "enforcementLevel":     "none",
  "graceRemaining":       null,
  "invalidReason":        null,
  "instanceUuid":         "abcd1234efgh",
  "seatsUsed":            42,
  "seatsOverBy":          0,
  "seatEnforcement":      "none",
  "seatCap":              100,
  "seatLockAt":           120,
  "lastTelemetryAt":      null,
  "lastTelemetryPayload": null
}
```

**Field notes**:
- **`enforcementLevel`** (`none` / `grace` / `soft-lock` / `unlicensed`) — temporal state, driven by JWT `exp` vs now. `grace` runs for `GRACE_DAYS = 30` past `exp`.
- **`seatEnforcement`** (added 4.4.0; `none` / `over-warn` / `over-lock`) — seat-count state. `count > seats` → `over-warn` (banner only); `count > ceil(1.2 × seats)` → `over-lock` (blocks new-Advanced creation + writes on existing Advanced surfaces). Unlimited tier (999999), missing `seats` claim on legacy JWTs, and unlicensed instances all resolve to `none`.
- **`paidUntil`, `paidDaysRemaining`, `graceDays`** (added 4.3.21) — new-model JWTs carry `paid_until` + `grace_days` claims. Legacy JWTs without them fall back to `paid_until = exp` and `graceDays = 0` so the API shape stays valid.
- **`lastTelemetryAt`, `lastTelemetryPayload`** — always `null` from 4.4.0 onward (the daily-report writer was deleted). Fields kept for API back-compat; frontend does not read them.

### `PUT /api/v1/admin/license`

Save a new license JWT (verified before persist).

**Auth**: NC admin required (`#[AuthorizedAdminSetting]`).

**Request body**:
```json
{ "jwt": "<full JWT string>" }
```

**Response 200**: same envelope as `GET /api/v1/admin/license` (the frontend refreshes without a second roundtrip).

**Failures**: `400` invalid JWT (bad signature, expired, UUID mismatch, missing claims). The specific reason surfaces in `invalidReason` on the status envelope so the admin sees exactly what to fix.

### `GET /api/v1/license/entitlements` *(added 3.100.1)*

Slim response for the CreateTeamView wizard so it can grey out the Advanced-project tile upfront rather than failing on submit.

**Auth**: any logged-in user (`#[NoAdminRequired]`, `#[NoCSRFRequired]`).

**Response 200**:
```json
{
  "canCreateAdvanced": true,
  "enforcementLevel":  "none"
}
```

Deliberately does NOT expose seats, customer, license id, or any admin-only field. `canCreateAdvanced` factors in both `enforcementLevel` and `seatEnforcement` (returns `false` for `over-lock` too, per `allowsAdvancedCreation()`).

### Removed license endpoints

- **`POST /api/v1/admin/license/refresh`** — removed 4.3.20. Manually scheduled the (now-deleted) `SendTelemetryJob`. The "Refresh now" button it powered was already gone from the UI.
- **`POST /api/v1/admin/license/trial`** — removed 4.4.0. Server-to-server trial-request endpoint. Superseded by the "Request trial by email" mailto in the License tab, which opens the admin's mail client and sends the instance UUID to `teamhub@tldr.host`; Justin issues the JWT by hand from the licensing dashboard and replies.

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

### `GET /api/v1/admin/compliance/summary` *(added 4.2.10, documented 4.8.15)*

The remaining Compliance-tab pills, on one fetch behind one refresh button.

**Auth**: `#[AuthorizedAdminSetting(settings: AdminSettings::class)]` plus `MaintenanceService::requireNcAdmin()`. `#[NoCSRFRequired]` — read-only GET.

**Response 200**:
```json
{
  "ghost_memberships": { "count": 0, "sample_uid": null },
  "orphan_teams":      { "count": 0, "sample_name": null },
  "profile_compliance": {
    "classified":   12,
    "conformant":   11,
    "drifted":      1,
    "findings":     [ { "teamId": "…", "teamName": "…", "profileKey": "confidential",
                        "fields": [ { "field": "cfg_visible", "expected": false,
                                      "observed": true } ] } ],
    "findingsTruncated": false,
    "checked_at":   1757160000
  }
}
```

`profile_compliance` (4.8.15, Track F) compares every team carrying a `teamhub_team_policy` row against the values its profile defines. **Unclassified teams are not read and not counted** — there is no expectation for them to depart from. `classified === 0` is a state of its own, not a pass: the UI renders it as *No teams classified* rather than a green zero, per `TRACK-F2-DESIGN.md` §5.3.

`fields[].field` is a **key**, not a label — names are translated client-side from `src/constants/policy.js`. A finding on `public_messages` additionally carries `"enforced": true`: it is the one ENFORCED field, so a difference there means the value was set before the profile was applied, or through a route since closed, rather than somebody editing around us in Contacts.

`drifted` is exact; `findings` is capped at 200 entries with `findingsTruncated` saying when it was, the same shape `findGhostMembers()` uses. (`sample_team` / `sample_field` were here briefly in 4.8.15 development and are **not** in the shipped shape — the tab's info menu showed one example finding, and the Maintenance grid showing every team's own state replaced it. `findings[0]` is the same sample for any caller that wants one.)

**Cost is fixed, not per team**: three set queries (`circles_circle`, `circles_member`, `teamhub_team_apps`) plus one app-config load, chunked at 500 team ids per `IN` clause. See `PolicyObservationMapper`.

**Licence**: drift detection is the licensed half of Track F (`TRACK-F2-DESIGN.md` §5.4). The gate is the Compliance tab's existing `complianceUnlocked`, which hides every check on the tab behind the licence banner — **not** a second gate on this endpoint, which would make an unlicensed instance see four checks and one error.

**Failures**: `500` with `{"error": "Compliance summary failed"}`.

---

## Collectives team-app endpoints *(added 4.3.3)*

The Wiki (Collectives) team-app — parallel to the Intranet (Intravox) endpoints under `/api/v1/teams/{teamId}/intravox/*` in shape, but talks to `\OCA\Collectives\Service\CollectiveService` + `PageService` in-process. See `lib/Service/CollectivesService.php` for the dispatch logic; toggle-off routes to Collectives' hard-delete or trash based on the admin archive policy.

### `GET /api/v1/teams/{teamId}/collectives/config`

**Auth**: team member. **Response 200**:
```json
{ "collectives_enabled": false, "collectives_installed": true }
```
`collectives_installed` reflects `IAppManager::isInstalled('collectives')` so the Manage Team toggle can render "Not installed" instead of erroring when the NC app isn't present.

### `PUT /api/v1/teams/{teamId}/collectives/config`

**Auth**: team **admin**. **Body**: `{ "collectives_enabled": 1 }` (accepts bool / int / string). **Response 200 on enable**:
```json
{ "collectives_enabled": true, "collectives_installed": true, "created": true, "collectiveId": 42 }
```
On first-time enable the service auto-creates a collective bound to the team-circle (via Collectives' `CircleExistsException` fallback). `created` is `false` on subsequent re-enables.

**Response 200 on disable**:
```json
{ "collectives_enabled": false, "collectives_installed": true, "action": "hard", "error": null }
```
`action` is `hard` (`deleteCollective(deleteCircle=false)`) when `archiveMode="hard"`, `trash` (`trashCollective`) when `archiveMode="soft30"` or `"soft60"`, or `noop` when the team never had a bound collective. `deleteCircle` is force-false on every hard path — the Circle IS the team.

**Failures**: `403` — not admin; `400` — service error (message passed through).

**v4.5.36** — both branches run a resource reconcile before responding, so the team's `collectives` registry row tracks the toggle in the same request instead of on whichever page load next triggers reconciliation. Best-effort: a reconcile failure is logged and does not change the response, because the toggle itself already succeeded.

### Collectives as a discovered resource *(added 4.5.36)*

`collectives` is now a valid `{app}` on the resource-state endpoints, alongside `files` / `talk` / `calendar` / `deck`:

| Endpoint | Effect on a `collectives` row |
|---|---|
| `GET /api/v1/teams/{teamId}/resources/panel` | Returns the row under a `collectives` key. `resourceId` is the collective id; `displayName` is its emoji + name. |
| `POST …/resources/collectives/{id}/accept` | Writes the `collectives_collective_id_*` + `collectives_enabled_*` appconfig pair **before** marking the row active — the Wiki tab reads that pair, so this is what makes "accept" connect anything. A bind failure leaves the row pending. |
| `POST …/resources/collectives/{id}/ignore` | Clears `collectives_enabled_*` only. The collective is **not** deleted or trashed — ignore has always been reversible — and the id pointer is kept so un-ignore rebinds the same collective. |
| `POST …/resources/collectives/{id}/unignore` | Rebinds, same as accept. |

**Auth** is unchanged: team admin (level ≥ 8, direct membership) on all four.

A collective is "real" for reconciliation when a non-trashed `oc_collectives_collectives` row carries `circle_unique_id = {teamId}`, read through Collectives' own `CollectiveMapper`. It has no owner column — the access set is the circle's member set — so `getResourceOwner` returns `null` and every newly discovered collective lands in `pending`, the same way group-folder-backed (`gf:`) files rows do. The one exception is a team whose `collectives_enabled_*` flag is already `1`: that flag is only ever written behind `requireAdminLevel`, so it counts as an accept already given and the row is backfilled `active` with no review. That is what stops existing wikis from raising a review request on upgrade; there is no migration.

### `GET /api/v1/teams/{teamId}/collectives/team-collective`

**Auth**: team member. **Response 200**:
```json
{ "id": 42, "name": "Marketing", "emoji": "💡", "url": "/apps/collectives/marketing" }
```
Or `null` if the team-app is off, the caller can't see the collective, or the resolver failed. Cached per-team, per-user (ACL views differ) for 5 minutes.

### `GET /api/v1/teams/{teamId}/collectives/subpages`

**Auth**: team member. **Response 200**:
```json
[ { "id": 128, "title": "Getting started", "url": "/apps/collectives/marketing?fileId=128" } ]
```
Top-level pages of the team's collective, sorted alphabetically, capped at 20.

### `DELETE /api/v1/teams/{teamId}/collectives/subpages/cache`

**Auth**: team member. **Response 200**: `{ "success": true }`. Busts the per-team resolver cache; useful after a page create/delete performed inside Collectives' UI to force a widget refresh on next read.

### `GET /api/v1/admin/collectives-diagnostic`

**Auth**: NC admin (`IGroupManager::isAdmin`). Same shape as `intravox-diagnostic` / `deck-diagnostic`. Reflection over `\OCA\Collectives\Service\CollectiveService` and `PageService` — reports the public method signatures on THIS install. Read-only.

**Response 200**: `{ installed, CollectiveService?: {class, methods}, CollectiveService_error?, PageService?: {class, methods}, PageService_error? }`.

### `GET /api/v1/teams/{teamId}/layout` — response addition *(4.3.3)*

```json
"collectivesConfig": { "collectives_enabled": true, "collectives_installed": true }
```

Both branches (team-row and cascade-to-default), so the frontend can gate the Wiki widget + tab without a second fetch.

---

## Bulk team import endpoints (added 4.6.6)

Create many teams at once from a CSV. Backed by `TeamImportService` and `TeamImportController`.

**Auth on every route below**: NC admin, gated twice — `#[AuthorizedAdminSetting(settings: AdminSettings::class)]` on the controller method **plus** `TeamImportService::requireNcAdmin()` inside the service. There is no `#[NoAdminRequired]` anywhere in `TeamImportController`. `#[NoCSRFRequired]` is present only on the three read-only GETs, matching `MaintenanceController`; every state-changing verb stays CSRF-protected.

**Non-admin callers get `403` from all seven.**

### The CSV contract

UTF-8, header row required, BOM tolerated. The column delimiter is sniffed from `,` `;` and tab (Dutch and German Excel write `;`). Multi-value cells split on `;` **or** `|`, so one of the two always works whatever the column delimiter is. Header names match case-insensitively and trimmed.

| Column | Required | Accepted values |
|---|---|---|
| `name` | yes | Validated by `TeamService::assertValidTeamName()` — letters, digits, spaces, hyphens, underscores; ≤255 chars |
| `description` | no | Free text |
| `template` | yes | A key from `teamhub_template` — `collaboration` \| `project` \| `department` as seeded. Header alias: `team_type` |
| `project_mode` | no | `advanced` \| `basic`. Only read when `template=project`; defaults to `advanced` |
| `admin` | yes | **Multi-value, position significant (4.6.10).** The first account name becomes the level-9 owner; every later one becomes a level-8 team admin. Matched case-insensitively and stored in the account's own spelling (4.6.7). Was called `owner` and single-valued before 4.6.10 — the old name is **not** accepted |
| `members` | no | Multi-value. Bare token = account name, `group:<group>` = NC group, `team:<team>` = sub-team. All three resolve **by name**, case-insensitively; `team:` also accepts a raw Circles `unique_id` (tried first). Accounts and groups resolve the same way as `admin` |
| `expires` | no | A date as `YYYY-MM-DD`, in the future. Empty = no end date. Read only when the row's template has *Enable team expiration* set (`teamhub_template.offer_expiry`); a date on any other row is dropped with a warning |
| `policy` | no | A policy profile key. Empty = the profile the row's template starts its teams on (`teamhub_template.default_profile_key`), which may itself be none. An unknown key **fails the row** |

**`apps` and `modules` were columns until 4.8.24 and are not read from 4.8.25 on.** What a team is provisioned with comes from its template (`teamhub_template.apps` / `.modules`), and what it is permitted to have comes from its profile's `integrations_allowed`, applied inside `ResourceService::createTeamResources()`. A file that still carries either column imports unchanged; a row with a **value** in one gets a warning saying it was ignored. The privacy bitmask has never been a column either: it comes from the template's `preselect_config`, with the profile's governed bits overlaid by `TeamService::updateTeamConfig()`.

The template set is read from `teamhub_template`, not from a constant — an administrator's edits on Admin → TeamHub → Policy reach the importer the same way they reach the create-team wizard. `TeamTypeService::ALLOWED` is used only if that table reads empty, which means the seed migration has not run.

**How several owners collapse (4.6.10).** Circles allows exactly one owner per circle, so a source system that permits several — a Microsoft Teams export routinely carries two or three — is collapsed by document order: first name owns, the rest are team admins. Provisioning folds the extra admins into the member list (deduplicated by uid against `members`, so a name in both cells produces one invite, not two), invites them in step 8, and raises them to level 8 in step 8b via `MaintenanceService::adminSetMemberLevel()`. Step 8b runs **before** the owner handoff in step 9, because that method refuses a level-9 row and until the handoff the acting admin holds it. An extra admin with no member row afterwards is reported on the row rather than swallowed — that state means the invite did not land.

**Account and group resolution (4.6.7).** Every `admin` name and every bare `members` token is resolved to the account's own spelling before the row is persisted — `IUserManager::get()` first, then a `search()` pass filtered to exact case-insensitive uid equality for backends without a folded column. Capitals, spaces, accents and non-Latin scripts are preserved verbatim; nothing rewrites a uid. A name matching **no** account fails the row (first `admin`) or drops the entry with a warning (later `admin`, any member); a name matching **more than one** account across backends is refused rather than guessed. Groups behave identically via `IGroupManager`. When a resolved name differs from what was typed, the row's message records the mapping. The team **name** column is unaffected by this and still obeys `assertValidTeamName()`'s ASCII rule.

**Sub-team resolution (4.6.11).** `team:` resolves by display name against the same derived name the duplicate check uses, so a `team:` token and a `name` collision can never disagree about what a team is called. A raw `unique_id` is tried first and wins. Two teams sharing a display name is refused rather than guessed — Circles does not enforce uniqueness there — and the message says to use the ID instead. The row's matched-names note reports the team's own spelling, not its id.

**Known limitation.** Multi-value cells split on `;` and `|`, so an account, group or team name containing either character cannot be expressed in `members` or `admin`.

Row outcomes: **error** (name empty/too long/bad characters, template not in `teamhub_template`, unknown `policy`, `project_mode` not `advanced|basic`, `admin` empty, no matching account for the first `admin` name, or an ambiguous first `admin` name) — never runs. The owner slot is the first *position*, not the first name that happens to resolve: if position 0 fails the row fails, and a later name is never promoted into it. **skip** (name duplicated in the file, keeping the first; name collides with an existing team) — recorded, not attempted. **warning** (advanced project while the licence disallows it, downgraded to basic; a later `admin` name dropped; unknown member dropped; `group:`/`team:` member while that invite type is disabled; an `expires` date the template does not allow; a value in the retired `apps`/`modules` columns) — the team is still created.

### `GET /api/v1/admin/import/teams/template`

The sample CSV. Generated in PHP by `TeamImportService::sampleCsv()` rather than shipped as a file — a top-level `samples/` directory would have to be registered in `COVERED_DIRS` in `scripts/generate-integrity.js`, the equivalent list in `scripts/publish-to-release.js`, and `verify-package.js` in the release repo, or it would silently not ship. Generating it also means it cannot drift from the parser.

**Response 200**: `DataDownloadResponse`, `text/csv; charset=utf-8`, filename `teamhub-team-import-template.csv`, UTF-8 BOM so Excel does not guess the codepage.

### `POST /api/v1/admin/import/teams/validate`

Multipart upload, file field `file`. Parses, normalises and **persists** the run and every row, then returns the preview. Nothing is created until `start`. Persisting up front is what makes confirming a state flip rather than a second upload, and what lets `TeamImportJob` finish a run whose tab went away.

Guards, in order: ≤2 MB (checked before the file is read), header row present with `name`, `template` and `admin`, ≥1 data row, ≤500 rows.

**Response 200**: the import payload — see the shape under `GET …/{id}` below.

**Failures**: `400` too large / empty / no header / missing required column / no rows / too many rows, `403` not an admin, `500` other.

### `POST /api/v1/admin/import/teams/{id}/start`

Flips `validated` → `running`. Idempotent: a second call reports current state rather than restarting. The flip is conditional on the current status in SQL, so two clicks cannot both start it.

**Response 200**: the import payload.

### `POST /api/v1/admin/import/teams/{id}/process`

Provisions the next chunk and returns progress. The admin panel calls this in a loop until `import.status` is `completed`.

**Query/body**: `limit` — rows per call, clamped 1–10, default 3.

Each row is claimed with an `UPDATE … WHERE status = 'pending'`, which is the lock: an open tab and the background sweeper cannot both provision the same row.

**Response 200**: the import payload.

### `GET /api/v1/admin/import/teams/{id}`

Polling. Read-only.

**Response 200**:
```json
{
  "import": { "id": 7, "created_by": "jdoek", "filename": "teams.csv",
              "status": "running", "total_rows": 24,
              "created_count": 16, "skipped_count": 3, "failed_count": 2,
              "created_at": 0, "started_at": 0, "finished_at": null, "heartbeat_at": 0 },
  "summary": { "ready": 3, "skipped": 3, "errors": 0, "created": 16, "failed": 2, "warnings": 4 },
  "rows": [ { "row_num": 2, "name": "Website Redesign", "template": "project",
              "project_mode": "advanced", "owner": "Jane Doe", "admins": ["Bob Jones"],
              "member_count": 3,
              "status": "created", "message": null, "team_id": "abc123…" } ]
}
```

`rows[].owner` is the first name from the `admin` column; `rows[].admins` is every later one, resolved and deduplicated — the owner is **not** repeated there. `member_count` counts the `members` column only, as parsed: the extra admins are folded into the member list at provisioning time, not at validation, so the team ends up with `member_count` + however many of `admins` were not already listed under `members`.

`import.status` is `validated` \| `running` \| `completed` \| `cancelled`. `rows[].status` is `pending` \| `running` \| `created` \| `skipped` \| `failed`. `summary.errors` counts validation errors before the run; `summary.failed` counts provisioning failures after it — both render as "errors" to the admin, since either way the row produced no team.

**Failures**: `403` not an admin, `404` unknown import.

### `DELETE /api/v1/admin/import/teams/{id}`

Discards a `validated` run outright (deleting it and its rows); cancels one already `running`.

**Response 200**: `{ "cancelled": false, "deleted": true }` for a discard, `{ "cancelled": true, "deleted": false }` for a cancel.

A cancelled run **keeps the teams it already created** — they are real teams with real resources, and unwinding them is a different operation with its own confirmation. Only the remaining pending rows never run.

### `GET /api/v1/admin/import/teams`

Recent runs, newest first.

**Query**: `limit` — clamped 1–50, default 10.

**Response 200**: `{ "imports": [ … ] }`, each entry the same shape as `import` above.

---

## Bulk team export endpoints (added 4.6.14, documented 4.6.28)

The read side of the CSV contract the importer above writes. Backed by `TeamExportService` and `TeamExportController`.

**Column set, from 4.8.25**: the export writes exactly `TeamImportService::COLUMNS`, so it gained `policy` — a team's classification, empty when it is unclassified — and lost `apps` and `modules` with the importer. Losing them changes what a round trip preserves and is deliberate: a re-imported team is provisioned from its template and filtered by its policy, which is what creating it through the wizard would have done. Gaining `policy` closes the opposite gap — without it, re-importing a team deliberately moved from *Internal* to *Confidential* would silently hand it back its template's default.

**Auth on every route below**: NC admin, gated twice — `#[AuthorizedAdminSetting(settings: AdminSettings::class)]` on the controller method **plus** `TeamExportService::requireNcAdmin()` inside the service. There is no `#[NoAdminRequired]` in `TeamExportController` and there must never be one: every response is a list of who is in which team, across teams the caller may not be a member of. `#[NoCSRFRequired]` is on the two GETs only.

**License gate (4.6.28)**: all three routes are behind `TeamExportController::licenceGate()`. Enforcement levels `none` (Active or Trial) and `grace` pass; `soft-lock` and `unlicensed` refuse with **403** and

```json
{ "error": "Bulk team export requires an active TeamHub license.", "licenseGate": true, "enforcementLevel": "soft-lock" }
```

Same ladder and same envelope as `GET /api/v1/messages/personal-feed` and the What's-new routes, so a client can tell "your license does not cover this" apart from a generic failure. The admin panel hides the section too, but the controller is the boundary. **Bulk import is deliberately not gated** — a lapsed instance must still be able to get data in.

### `GET /api/v1/admin/export/teams/selectable`

Every exportable team, for the panel's multiselect. Not paginated — a picker that only knows about the first page cannot select from the rest.

**Response 200**: `{ "teams": [ … ] }`.

### `POST /api/v1/admin/export/teams/preview`

**Body**: `{ "teamIds": ["…"] }` — omit or send `[]` for every team.

What the download would contain and which rows could not be re-imported, without producing the file or writing an audit event. Ids that are not non-empty strings are dropped rather than coerced.

### `GET /api/v1/admin/export/teams/download`

**Query**: `teamIds[]=…`, or omit for every team.

The CSV, as a `DataDownloadResponse`. A GET so `window.location` can trigger it and the browser owns the download — which is why the selection rides the query string and carries team ids only, nothing about people. Writes one `team.exported` audit event per team; the license gate runs **before** the service, so a locked instance audits nothing for a file it never produced. Failures answer JSON rather than a 200 whose body is an error message.

---

## Bulk team creation (added 4.8.9)

A second front end onto `TeamImportService` — **not a second importer**. Rows typed into the *Several teams* table go through the same `normaliseRow()` validation, the same durable run, the same chunked provisioning and the same per-row results as a CSV upload. A CSV is one wire format of two.

**Auth on all five**: `#[NoAdminRequired]`, and the service resolves `$enforceNcAdmin = false` — which requires the licence **and** `MemberService::canCurrentUserBulkCreateTeams()` **and** that the run belongs to the caller. That last check is not redundant: permission to use the feature is not permission to drive somebody else's run.

### `POST /api/v1/teams/bulk/validate`

**Body**: `{ rows: [ { name, description?, template, policy?, admin, members?, expires?, project_mode? } ] }`. `apps` and `modules` were accepted until 4.8.24 — never sent by the table, only reachable because rows are flattened against `TeamImportService::COLUMNS` — and left with those columns in 4.8.25.

Dry run. Creates the durable run and returns the preview; nothing is provisioned until `start`. Keys map to the same columns a CSV carries — an omitted key behaves exactly like an absent column.

**Response 200**: the same shape `GET /api/v1/admin/import/{id}` returns — `{ import, summary, rows }`.

### `GET /api/v1/teams/bulk/{importId}`

The run's current state. 404-equivalent when the run is not the caller's.

### `POST /api/v1/teams/bulk/{importId}/start`

Moves the run to `running`. Nothing is created by this call itself.

### `POST /api/v1/teams/bulk/{importId}/process`

**Query/body**: `limit` (default `TeamImportService::DEFAULT_CHUNK`).

One chunk. The browser calls it in a loop until the status leaves `running`. Closing the tab is survivable — the background job adopts a run whose heartbeat goes quiet.

### `DELETE /api/v1/teams/bulk/{importId}`

Discards a validated run. Used when the user goes back from the preview to edit the table, so a run nobody will provision is not left stranded in the shared recent-runs list.

---

## Team creation — roles and handover (added 4.8.7)

### `POST /api/v1/teams/{teamId}/creation-roles`

**Body**: `{ roles: [ { id, type?, level } ], newOwner?: "uid" }`.

The last step of team creation: give invited members their roles, and hand the team over if somebody else was appointed owner.

**Auth**: `#[NoAdminRequired]`, and the service requires the caller to be **the team's owner** (level 9) — not an administrator. Handing over something you just created is not an administrative act, and it is the one ownership-transfer path with real-world exercise behind it.

`level` is 1 (member), 4 (moderator) or 8 (team admin); 9 is not a level you write, it is a transfer and it rides `newOwner`. **Level 1 is skipped** — it is what the invite already wrote, and re-writing it churns the member row and the Circles membership cache for nothing. A group may carry a level but cannot be `newOwner`.

**The appointed owner becomes a full member whether or not they accepted an invitation.** `assignOwner()` updates an existing row to `level 9, status Member` and inserts one where there is none, so an invitee still at `Invited` is carried through. This overrides the team's own join policy for exactly one person.

**Never throws on a failed handover.** The team exists and is usable, so the response reports per-step status and the wizard renders it:

```
{ "levels": { "alice": "ok", "bob": "failed" },
  "owner":  { "uid": "alice", "status": "transferred" } }
```

`owner.status` is `transferred` or `failed` (with `error`). `levels` values are `ok`, `failed`, `invalid-level` or `not-a-user`.

### `GET /api/v1/teams-bulk-entitlement`

**Auth**: any authenticated user.

**Response 200**: `{ canBulkCreate, licensed, permitted }`.

Whether this user may use the bulk create tab. Two real gates: the licence, and `MemberService::canCurrentUserBulkCreateTeams()` — the team-creator group, or NC admins where no such group is configured. **Deliberately stricter than ordinary team creation**, which permits everyone when no creator group is set. The three fields are separate so the UI can say which gate closed rather than hiding the tab unexplained.

---

## Policy endpoints (added 4.8.2, Track F2a)

Templates and classification profiles. Design: `TRACK-F2-DESIGN.md`.

**Definitions only, except the three assignment routes added in 4.8.16.** Everything else here defines what a profile *is* and is inert with respect to team behaviour; `policy#applyProfile` and `policy#clearProfile` write a team's assignment, and the first of those writes the team's settings as well. Reclassification is still F2d.

**Auth on all thirteen**: `#[AuthorizedAdminSetting(settings: AdminSettings::class)]` on the controller **and** `PolicyService::requireNcAdmin()` / `PolicyApplyService::requireNcAdmin()` in the service. Note the consequence, which `TeamImportController` shares: a *delegated* TeamHub admin passes the attribute and is then refused by the service, which requires full `IGroupManager::isAdmin`. Deliberate for F2a — a profile is an instance governance control. Widening it to delegated admins is a permission-model change, not a default.

Errors go through `ExceptionResponseTrait`: `400` for validation, `404` for an unknown key, `403` for access denied, `500` otherwise.

### `GET /api/v1/admin/policy/fields`

What a profile may govern.

**Response 200**: `{ "fields": [ { "fieldKey", "tag", "type", "source", "dependsOn", "requiresApp" } ], "confidentialFiles": { "appAvailable", "labelsConfigured", "tags" } }`.

`tag` is `enforced` (TeamHub is the only writer; a locked value is refused) or `asserted` (something outside TeamHub can also write it; a change is detected and reported, never prevented). `type` is `bool` \| `int` \| `list` \| `string`. `source` names the table and column the F2c drift scan will read.

`dependsOn` (4.8.3) names a field that must itself be governed **and** true for this one to mean anything — `cfg_request` depends on `cfg_open`, because Circles treats a team without `CFG_OPEN` as simply closed whatever `CFG_REQUEST` says. The admin panel greys the dependent field out; the server drops it on save if the dependency is not met.

`requiresApp` (4.8.24) names a Nextcloud app that must be installed for the field to mean anything. Unlike `dependsOn` no edit to the profile can satisfy it, so the panel greys the field out with a different reason and names the app. `confidential_tag` is its only member today.

`confidentialFiles` (4.8.24) is sent with the catalogue rather than from an endpoint of its own, so the panel cannot render the field list and its availability from two reads that disagree. `appAvailable` is `files_confidential` being enabled for anyone; `labelsConfigured` is whether it has any classification label at all; `tags` is `[{ id, name, userAssignable }]` for the system tags those labels point at — **not** every system tag on the instance. All three are needed because the three failure states have different fixes.

`confidential_tag` values are **system tag ids as strings**, matching how Confidential files itself stores them. A `POST`/`PUT` to a profile is refused with 400 if the tag is empty, if the app is unavailable, or if no classification label points at that tag.

Expiry left this catalogue in 4.8.3 — it is a template setting now, not a profile one.

**No labels.** They are translated client-side from `src/constants/policy.js`, because `npm run check:l10n` scans `src/` only.

### `POST /api/v1/admin/policy/profiles/{profileKey}/propagate` (4.8.27)

Apply the profile's current values to the named teams, and report per team.

**Body**: `{ "teamIds": ["…"] }`.

**Response 200**: `{ "kind": "profile", "key", "applied": [ { "teamId", "teamName", "applied", "notApplied" } ], "failed": [ { "teamId", "reason", "error?" } ], "overflow": int }`.

The teams come from the `propagation` plan the `PUT` returned. They are sent rather than re-derived because compliance was measured against the profile's **previous** values, which the save has already overwritten — the list carries the administrator's decision, not their authorisation. Every id is still checked to carry the profile before anything is written; one that no longer does comes back under `failed` with `reason: "not_carrying"`.

Each team goes through the same `PolicyApplyService::apply()` an administrator invokes by hand, with `reapply` set, so the audit trail reads `team.policy_reapplied`. One team failing does not abandon the rest. Capped at 200 teams per run; `overflow` is how many were left.

### `POST /api/v1/admin/policy/templates/{templateKey}/propagate` (4.8.27)

Bring the named teams' apps in line with the template.

**Body**: `{ "teamIds": ["…"] }`.

**Response 200**: `{ "kind": "template", "key", "applied": [ { "teamId", "teamName", "added", "removed", "manual" } ], "failed": […], "overflow": int }`.

`added` is provisioned through `createTeamResources()`. `removed` is switched off **only where TeamHub created the resource** (`teamhub_team_app_resources.origin = 'teamhub_create'`) — a discovered Deck board is never taken away by a template edit. `manual` names apps the template wants that this path cannot provision; Collectives is the one today, because it has its own provisioning path.

### `GET /api/v1/admin/policy/summary` (4.8.15)

Counts for the setup checklist on the Team creation tab.

**Response 200**: `{ "templates", "templatesAdjusted", "profiles", "profilesAdjusted", "teamsClassified" }` — all integers.

`*Adjusted` is `updated_by IS NOT NULL`, which works because the seed migration writes every shipped row with a null `updated_by`: null means "exactly as TeamHub shipped it", non-null means a human has edited it (or, for a profile, created it). `teamsClassified` is the number of `teamhub_team_policy` rows.

Its own endpoint rather than `listProfiles` + `listTemplates`, which return every profile's value set and every template's app list. The checklist loads on mount and needs five integers.

### Assignment to an existing team *(added 4.8.16, Track F2b)*

`TRACK-F2-DESIGN.md` §4.3. **Assignment is a write, not a label** — applying a profile writes its values into the team. Only a Nextcloud administrator, and only from admin settings: a team admin able to reclassify their own team could lift every restriction placed on it.

#### `GET /api/v1/admin/policy/teams/{teamId}/preview?profileKey=…`

What applying that profile would change. **Writes nothing** — the preview is mandatory, not optional, and the UI keeps Apply disabled until it has one.

**Response 200**: `{ teamId, teamName, profileKey, profileLabel, profileSeeded, currentProfileKey, reassignment, changes, unchanged, governsNothing }`.

`changes` holds only fields that would differ: `{ field, current, next, applied, disables?, enforced? }`. `unchanged` is a list of governed field **keys** the team already satisfies — worth rendering, because it is how an admin sees the profile covers more than the lines that happen to differ today. `governsNothing` is true for a profile with no values: applying it classifies the team and changes no setting, which is legal.

**`applied: false` is the field this route exists to be honest about.** Today only `external_members` carries it: applying never removes anybody, so a profile forbidding external members leaves them in place and the team is reported non-conformant straight afterwards. `disables` on the integrations row is the destructive half — the apps that get switched off.

#### `POST /api/v1/admin/policy/teams/{teamId}`

**Body**: `{ profileKey, reapply? }`. CSRF-protected.

Assigns the profile and writes its values. **Response 200**: `{ applied, notApplied, preview }` — two lists of field keys plus the diff that was acted on.

Order is load-bearing: the assignment row is written **first**, so `TeamService::updateTeamConfig()`'s existing policy overlay is the new profile's rather than the old one's. The config write then passes the team's current value and lets the overlay force the governed bits — no second `circles_circle` writer, and no target bitmask computed anywhere.

`reapply` only selects the audit event (`team.policy_reapplied` rather than `team.policy_applied`, §8.1) so a report can tell a first rollout from a remediation. It is not a different operation and relaxes nothing.

#### `DELETE /api/v1/admin/policy/teams/{teamId}`

Back to unclassified. **Writes no team setting**: the team keeps every value it has and stops being compared. Audited `team.policy_cleared`; a team that carries no profile is a no-op with no audit row, not an error.

### `GET /api/v1/admin/policy/profiles`

**Response 200**: `{ "profiles": [ … ], "unclassifiedKey": "unclassified", "defaultKey": null }`.

Each profile carries `fieldCount` and `teams`. Since 4.8.4 `teams` is a real count — teams are assigned a profile at creation. `defaultKey` is the classification new teams get, `null` when unset. (`lockedCount` was dropped in 4.8.3: every governed setting is locked, so it always equalled `fieldCount`.)

`unclassified` is reserved and is **not** a row: a team with no assignment resolves to a synthetic empty profile. A real row could have values added to it, which would silently govern every unassigned team on the instance.

### `POST /api/v1/admin/policy/profiles`

**Body**: `{ profileKey, label, description?, sortIndex?, values? }`.

`profileKey` must match `^[a-z][a-z0-9_]{1,31}$` and may not be `unclassified`. **A field omitted from `values` is ungoverned**, which is a third state distinct from `false`.

`values` maps field key → the bare value (4.8.3). The 4.8.2 envelope `{ value, mode }` is still accepted so a client that has not reloaded still saves; `mode` is ignored, because a governed setting is now always locked. A field whose `dependsOn` is not satisfied is dropped rather than rejected — the panel greys it out, so a value arriving here means the dependency was switched off after it was ticked.

**Response 200**: the created profile, as `GET profiles/{profileKey}`.

### `GET /api/v1/admin/policy/profiles/{profileKey}`

**Response 200**: the profile plus `values` (cast back to real types) and `teams`. `404` when the key is unknown.

### `PUT /api/v1/admin/policy/profiles/{profileKey}`

**Body**: `{ label, description?, sortIndex?, values? }`.

`profileKey` is never updated — it is the identity, and changing it would silently rewrite what every assignment and drift finding points at. **Omitting `values` leaves the value set untouched** so a rename need not resubmit it; sending `{}` clears it, and an empty profile is legal.

### `DELETE /api/v1/admin/policy/profiles/{profileKey}`

**Response 200**: `{ "status": "deleted" }`. Refused with `400` when teams are assigned to it — always false in F2a; the check exists because F2b makes it reachable.

### `GET /api/v1/admin/policy/templates`

**Response 200**: `{ "templates": [ … ], "liveAtCreation": true, "apps": [ … ], "modules": [ … ], "managedBits": 8568 }`.

Each template carries `expiryEnabled` and `expiryDefaultDays` (4.8.3, replacing `offerExpiry`). `liveAtCreation` became `true` in 4.8.3: the wizard and the importer read this table, so an edit here reaches team creation.

### `GET /api/v1/templates`

**Auth**: any authenticated user (`#[NoAdminRequired]`) — deliberately not admin-gated. Every user who may create a team needs it, and it carries no more than the wizard already renders: which apps and modules a template starts with, which privacy boxes are pre-ticked, and whether an expiration date is offered. No profile, no assignment, no instance configuration.

**Response 200**: `{ "templates": [ … ] }`.

Added 4.8.3, and it is what retired the `TeamTemplates` ↔ `CreateTeamView.templateProfile()` mirror. The wizard fetches the **whole set once on mount**, so switching template stays a local lookup — the round trip is not in the critical path, which was the objection that kept the mirror alive.

### `GET /api/v1/policy/creation`

**Auth**: any authenticated user (`#[NoAdminRequired]`). Added 4.8.4.

**Response 200**: `{ profiles: [ { profileKey, label, description, isSeeded, values } ] }`.

**Every policy, for every caller who may create a team** (4.8.5). The policy is a required field in the wizard and the person creating the team picks it; `canChoose` and `defaultKey` were dropped along with the admin-only rule they encoded. `DESIGN.md` §2.110 records the reversal — the boundary moved to reclassification, which stays NC-admin only.

Which policy is *preselected* comes from the chosen template's `defaultProfileKey`, not from here.

`PUT /api/v1/admin/policy/default` existed in 4.8.4 and is **removed**: the default policy is a per-template setting now, edited through `updateTemplate`.

### `PUT /api/v1/admin/policy/templates/{templateKey}`

**Body**: `{ label, description?, apps?, modules?, expiryEnabled?, expiryDefaultDays?, preselectConfig?, sortIndex?, defaultProfileKey? }`.

`defaultProfileKey` (4.8.5) is the policy preselected in the wizard for this kind of team; an empty string means none. The creator can pick a different one.

`apps` and `modules` are validated against `TeamTemplates::APPS` / `::MODULES` and stored in vocabulary order, so two admins submitting the same set produce the same string and the audit diff does not fire on a reordering. **`preselectConfig` is masked to `CirclesConfig::MANAGED_BITS`** — it is admin-supplied, and a system bit on a user team corrupts the circle. It is wizard preselection, not policy.

`expiryDefaultDays` is 0–3650 and is forced to 0 when `expiryEnabled` is false — a default period on a template that cannot expire is a value nothing reads. Cleared rather than refused, because unticking the box is a normal edit.

`templateKey` is never updated: `teamhub_team_type.type` holds it for every team ever created and there is no migration behind a rename.

### `GET /api/v1/admin/policy/conflicts`

**Response 200**: `{ "conflicts": [ { "kind", "templateKey", "profileKey", "fieldKey", "detail" } ] }`.

`kind` is `app_not_allowed` — the template provisions an app the profile's allow-list excludes; `detail` lists them. An empty `integrations_allowed` means *all allowed* and can never conflict. There was a second kind, `expiry_forbidden`, until 4.8.3; it cannot happen any more, because expiry moved out of profiles and one side of that disagreement no longer exists.

A warning surface, not a save-blocker: the profile wins at creation, so a conflicting pair still produces a conformant team — it just silently drops something the template offered.

---

## File review endpoints (added 4.8.18)

Asking teammates to review a file. Design: `FILE-REVIEW-PLAN.md`; the module's own reasoning lives in `FileReviewService`.

**Two of these are called from the Nextcloud Files app**, by the file action TeamHub registers there — a page TeamHub does not render. They are gated exactly like the rest: every method re-establishes the caller and their membership, and none trusts a team or file id because it arrived in a URL. All are `#[NoAdminRequired]` and none carries `#[NoCSRFRequired]`.

Every endpoint requires the module to be on **globally and for the team**; a team with it off answers `403`.

### `GET /api/v1/file-reviews/scopes`

The caller's team-folder path prefixes, so the Files app's **synchronous** `enabled()` callback can decide whether to offer the action without a round trip per file.

**Response 200**: `{ "scopes": [ { "teamId", "teamName", "paths": ["/Team Alpha"] } ] }`

`paths` are **root-relative** — `/Team Alpha`, not `/alice/files/Team Alpha` — because that is what the Files app's client-side `Node.path` is. Teams with the module off are omitted rather than flagged. A user in no team gets `[]`, and the action never appears for them.

### `GET /api/v1/files/{fileId}/review-context`

Everything the request modal needs, in one call.

**Response 200**: `{ "eligible", "teamId", "teamName", "fileName", "fileId", "members": [{ "uid", "displayName" }], "openReviews": [ … ] }`

`eligible: false` (with empty everything else) is returned — **not** an error — when the file is not in a team folder, is a folder, or cannot be read by the caller. The client's prefix test can legitimately disagree with the server if a folder was detached between page load and click, and that is a "no" to render rather than a fault. `members` excludes the caller.

### `GET /api/v1/teams/{teamId}/file-reviews`

**Query**: `status` — `open` \| `closed`, optional.

**Response 200**: `{ "reviews": [ … ] }`, newest first.

### `POST /api/v1/teams/{teamId}/file-reviews`

**Body**: `{ fileId, reviewers?: string[], message?: string, dueAt?: int }`

On success the request is also posted into the **file's own Talk conversation** — the one the Files sidebar opens — which is created if nobody has opened it yet. The post carries the message and, when one was set, the due date. That is best-effort: a review whose chat could not be reached is still created, with `talkToken` null.

An empty or absent `reviewers` means **everyone in the team except the requester**, resolved server-side at submit time. Clients must not expand it themselves: a roster the browser happened to know would freeze a set that may have changed since the modal opened.

The reviewer set is a **snapshot**. Somebody who joins the team the next day is not added.

**Response 201**: `{ "review": { … } }`

**`400`** when: the reviewer list is empty after removing the requester; somebody named is not a team member; a folder was passed; `message` exceeds 4000 characters; `dueAt` is not a plausible date; or — the one worth naming — **a reviewer cannot open the file**, in which case the error names them. TeamHub never shares the file on the requester's behalf.

**`403`** when the caller is not a member, or the file is not in that team's folders. One message for both: distinguishing them would confirm the existence of a file id the caller cannot reach.

### `GET /api/v1/teams/{teamId}/file-reviews/{reviewId}`

**Response 200**: `{ "review": { … } }`

The review must belong to the team in the path; a mismatch answers `404`, not `403`, so a review in a team the caller cannot see does not become discoverable by the difference between the two answers.

### `POST /api/v1/teams/{teamId}/file-reviews/{reviewId}/complete`

A reviewer finishes their part. **Body**: `{ remark?: string }` (optional, ≤ 2000 characters). The remark is stored on the review **and posted into the file's Talk conversation** as the reviewer, who is joined to it first if they have never opened it.

**Response 200**: `{ "status": "completed", "review", "message" }`

**`409`** with `status: "already"` when the caller has already completed it, or the review has since been closed. Neither is a fault — it is a queue that moved on while a tab did not — so it is not a `500` and not a `200` with an error body.

**`403`** when the caller was not asked to review this file.

### `POST /api/v1/teams/{teamId}/file-reviews/{reviewId}/close`

The requester ends the request. **Requester only** — not team admins.

**Response 200**: `{ "status": "closed", "review", "message" }`; `409` when it is already closed.

One side effect the caller must be told about before they call it: every reviewer who had **not** completed loses the request from their My Work without having answered. Their row survives in the database — the requester must still be able to see who never responded — but the provider stops returning it.

Since 4.8.20 closing **destroys nothing**. The discussion lives in the file's own Talk conversation, which the file owns and TeamHub never deletes.

Allowed at any time, however many reviewers are still owed: closing with nobody finished **is** the cancel, which is why there is no separate withdraw verb.

### `GET` / `PUT /api/v1/teams/{teamId}/file-reviews/config`

The per-team switch. **Read**: any member. **Write**: team admin — this decides whether a whole surface exists for everybody else in the team.

**PUT body**: `{ fileReviewsEnabled: bool }`

**Response 200**: `{ "file_reviews_enabled": bool, "module_enabled": bool }`

Both switches store only the *off* state, so an unset key means enabled — which is what makes the feature true for every existing team with no migration and no write. The global switch also rides `PUT /api/v1/admin/settings` as `fileReviewsModuleEnabled`.

---

*Update this file in place at the end of any session that adds, removes, or changes an endpoint.*

---

## OpenProject endpoints (added 4.9.3)

Phase 1 of the OpenProject integration. Design and security notes: `OPENPROJECT.md`. Every route is `#[NoAdminRequired]` with the CSRF check intact; every route that reaches OpenProject carries a `#[UserRateLimit]`. Every OpenProject failure is answered with a stable `code`:

| Status | `code` |
|---|---|
| 403 | `module_unlicensed` · `module_disabled` (4.9.16 — the OpenProject module gate, see below) |
| 422 | `integration_not_installed` · `integration_disabled` · `integration_incompatible` · `host_not_configured` |
| 412 | `user_not_connected` · `auth_failed` |
| 403 | `permission_denied` (and the membership gate's own 403) |
| 404 | `project_not_found` |
| 409 | `link_stale` · `project_already_linked` |
| 429 | `rate_limited` |
| 502 | `api_unavailable` · `temporary_failure` · `unsupported_response` |

Body: `{ "error": "<sentence for the user>", "code", "administratorMessage" }`. Never 200 with an error body.

**The module gate (4.9.16).** OpenProject is a licensed module with an administrator switch (`openproject_module_enabled`, default off; `OpenProjectModuleService`). Every member-facing OpenProject and provisioning route — everything in this section and § Phase 2 except `GET /api/v1/openproject/capabilities` and the `/api/v1/admin/…` routes — answers **403** with `code: module_unlicensed` (plus `licenseGate: true`, the marker every licence-gated endpoint carries) or `code: module_disabled` before membership or OpenProject is consulted. `POST /api/v1/teams` with the `openproject` template refuses the same way. The layout bundle's `openProjectConfig` then reports `moduleAvailable: false, eligible: false, linked: false` for every team, which is what hides the widgets. Administration: `GET/POST /api/v1/admin/settings` carry `openProjectModuleEnabled` (bool; the switch as stored, whatever the licence says).

### `GET /api/v1/openproject/capabilities`

Per **user**, not per team. **Query**: `probe` (bool, default 0 — ask OpenProject `users/me`), `force` (bool — bypass the 60 s probe cache). Rate limit 30/min.

**Response 200**: the capability array — `moduleLicensed`, `moduleEnabled`, `moduleAvailable` (4.9.16 — TeamHub's own module; the wizard hides its *OpenProject project* card on `moduleAvailable: false`), `integrationAppInstalled`, `integrationAppEnabled` (the official app's own state, not the module's), `hostConfigured`, `host`, `authMethod`, `userConnected`, `apiReachable` (`null` = not probed), `projectReadAvailable`, `workPackageReadAvailable`, `workPackageCreateAvailable`, `provisioningAvailable`, `openProjectUser`, `errorCode` (`module_unlicensed` / `module_disabled` first, then the app's codes in fixing order), `userMessage`, `administratorMessage`, `probed`, `checkedAt`. No secrets: the host is the URL users are sent to; the OpenProject user is the name they see in OpenProject's header. **Not module-gated** — it is how a client learns the module is off.

### `GET /api/v1/openproject/projects`

Projects the **current user may link** — administered by them in OpenProject (the resource carries an `update` link) or public — for the team-creation wizard's picker. Per user, not per team: the team does not exist yet. Gated on the right to create a team. **Query**: `q` (string, ≤ 200 chars, matched on name or identifier), `limit` (int, capped at 25). Rate limit 30/min.

**Response 200**: `{ "projects": [ { "id", "identifier", "name", "active", "public", "canEditProject", "linkable", "linkedTeam" } ], "limit": 25 }` — every element has `linkable: true`; projects the user can merely see are not returned. `linkedTeam` (4.9.4) is `null`, or `{ "teamId", "name"|null }` for the team that already links the project — `name` only when the caller is a member of it. The picker lists such a project as taken and will not select it; `POST /api/v1/teams` refuses it again with 409.

### `GET /api/v1/teams/{teamId}/openproject/link`

Member-gated. **Response 200**: `{ "link": { … } | null, "capabilities": { … } }` — capabilities not probed. A link is `{ projectId, projectIdentifier, projectName, host, stale, createdBy, createdAt, updatedAt, lastValidatedAt, urls: { project, workPackages } }`.

**`PUT /api/v1/teams/{teamId}/openproject/link` was removed in 4.9.4.** The link is made by `POST /api/v1/teams` (`openProjectId`, § Team creation) as part of creating the team, and removed only by an NC admin through `DELETE /api/v1/admin/maintenance/teams/{teamId}/openproject-link`. There is no change and no re-link endpoint. The team-delete cascade removes the row.

### `POST /api/v1/teams/{teamId}/openproject/test`

Team-admin gated, rate limit 10/min. A forced capability probe plus, when linked, a read of the linked project. **Response 200**: `{ "capabilities", "link", "project" | null, "projectError": { error, code, administratorMessage } | null, "testedAt" }`.

### `GET /api/v1/teams/{teamId}/openproject/overview`

Member-gated, rate limit 60/min. **Query**: `refresh` (bool — bypass the 5-minute per-user cache, subject to a 10 s cooldown). Shape in `OPENPROJECT.md` §3.5: `project`, `counts` (each count may be `null` = could not be read), `dueSoonDays`, `nextMilestone`, `recentlyCompleted`, `files`, `warnings`, `retrievedAt`, `fromCache`.

### `GET /api/v1/teams/{teamId}/openproject/work`

Member-gated, rate limit 60/min. **Query**: `section` — `upcoming` (the only section since 4.9.5: the project's open work packages of **every assignee** that carry a due date, soonest first; it feeds the team home's Upcoming tasks widget — the 4.9.3 sections `assigned` / `overdue` / `dueThisWeek` / `recentlyUpdated` are gone with the widget they served, and the viewer's own rows are in My Work through `OpenProjectWorkProvider`); `page` (int ≥ 1, OpenProject page number); `pageSize` (int, 1–25, default 10); `refresh` (bool).

**Response 200**: `{ "section", "items": [ { id, subject, type, status, priority, assignee, responsible, project, startDate, dueDate, date, percentageDone, createdAt, updatedAt, url } ], "total", "page", "pageSize", "hasMore", "retrievedAt", "fromCache", "canCreateWorkPackage" }`. Everything is fetched as the caller, so the list can never contain a work package they could not open in OpenProject. `canCreateWorkPackage` (4.9.15) is whether OpenProject grants the caller *add work packages* on the project (the `createWorkPackage` link on the project resource, read with the page and cached with it; `false` when that read fails) — the Upcoming tasks widget shows its *Create OpenProject work package* action exactly when it is true.

### `GET /api/v1/teams/{teamId}/openproject/work-packages/form`  *(added 4.9.15)*

OpenProject's create form for the linked project, as the caller: what the Upcoming tasks widget's create modal may offer. Member-gated, rate limit 30/min, not cached. `POST work_packages/form` with the project in `_links`; the types are the schema's inline allowed values, the assignees are read from the collection the schema names (followed only when it is an `/api/v3/` path; users only, ≤ 200, by name) — a failed assignee read costs the list, not the form.

**Response 200**: `{ "types": [ { id, name } ], "defaultTypeId": int | null, "assignees": [ { id, name } ], "assigneesUnavailable": bool }`. A caller OpenProject does not let add work packages gets its 403 → `permission_denied`.

### `POST /api/v1/teams/{teamId}/openproject/work-packages`  *(added 4.9.15)*

Create one work package in the linked project, **as the caller** — the one OpenProject write a team member makes from the team home. Member-gated, rate limit 30/min, CSRF-checked. **Body**: `subject` (string, 1–255, required), `typeId` (int, required), `assigneeId` (int | null), `dueDate` (`YYYY-MM-DD` | null), `description` (markdown, ≤ 5000). TeamHub checks shape and bounds (400 with `error` on a miss, before OpenProject is asked); OpenProject checks its rules and answers 422, returned as **400** with `code: validation_failed` and OpenProject's own sentence in `error`. One `POST work_packages` (the generic route; OpenProject 17 deprecates `projects/{id}/work_packages`), no notification switch — OpenProject notifies as it would for a work package made in its own UI. Afterwards the team's OpenProject cache generation is bumped, so every member's next `work` and `overview` read is fresh.

**Response 201**: the created work package in the `work` item shape (with `url`).

### `GET /api/v1/teams/{teamId}/openproject/meetings`  *(added 4.9.7)*

The linked project's upcoming OpenProject meetings, as the caller — the Upcoming events widget shows them beside the team calendar's events. Member-gated, rate limit 60/min. **Query**: `limit` (1–25, default 10), `refresh` (bool — bypass the 2-minute per-user cache, subject to the 10 s cooldown). OpenProject 17's Meetings API, `project = [id]`, `datesInterval <>d [today, today+30]` in the caller's zone; templates are dropped; cancelled meetings are dropped from `items` and listed in `cancelledIds` (4.9.10).

**Response 200**: `{ "items": [ { id, title, location, start, end, state, author, url } ], "cancelledIds": [ int ], "total", "retrievedAt", "fromCache", "sync"? }` — soonest first; `start`/`end` are ISO 8601 instants; `end` is `startTime + duration` when OpenProject sends no end; `url` is `/meetings/{id}` on the configured host.

**Side effect (4.9.10) — the one-way copy into the team calendar.** A **fresh** read (`fromCache: false`) also brings the team calendar in line with `items` and `cancelledIds` (`OpenProjectMeetingMirrorService::syncForTeam()`, ledger `teamhub_op_meeting_sync`; `OPENPROJECT.md` §6.4a): new open meetings are written as VEVENTs (URI `openproject-meeting-{12 hex}-{id}.ics`, no organiser, no attendees), changed ones rewritten, cancelled or vanished ones removed, at most ten writes per read, only into a calendar shared with the team **read-write**. The response then carries `sync: { state: "ok"|"no_calendar"|"read_only"|"unavailable"|"error", created, updated, removed, skipped }`; a cached read carries no `sync`. A GET that writes, for the same reason as the news mirror on `overview`: the copy can only be made at the moment TeamHub reads as the viewer. Nothing in OpenProject changes.

### `GET /api/v1/teams/{teamId}/openproject/attention`  *(added 4.9.7)*

The Project info widget's *Your attention* block: the My Work provider's read over this one team, summarised, plus the last seven days of activity. Member-gated, rate limit 60/min. Every count is the **caller's** (assigned to them); the milestone is the project's.

**Response 200**: `{ "overdue", "dueToday", "dueThisWeek", "updatedRecently", "nextMilestone": { id, subject, date, url, daysUntil } | null, "upcomingDays", "warnings": [ … WARN_* codes … ], "urls": { "myWorkPackages", "project" }, "recentActivity": { "count", "state" }, "projectId", "retrievedAt" }`. A failing activity read leaves `recentActivity.state: "skipped"` and the rest intact. Reading this does **not** move the caller's My Work "last visit" checkpoint.

**Side effect of `GET …/openproject/overview` (4.9.7).** A live (non-cached) overview read also mirrors the project's news written since the team was linked into the team's message stream, once per item (`OpenProjectNewsService::mirrorForTeam()`, ledger `teamhub_op_news_mirror`) — the same copy the feed read makes. Bookkeeping on a read, like the provider sync timestamps; nothing in OpenProject changes.

## OpenProject Phase 2 — provisioning endpoints (added 4.9.6)

Blueprint-driven provisioning of an OpenProject workspace. Design, state model, retry and rollback rules, security decisions: `OPENPROJECT.md` §5. Every OpenProject failure is answered with the same `code` table as the Phase 1 section (plus `validation_failed` → **400** since 4.9.15 (502 before) with OpenProject's own sentence in `error`, and `job_failed` → 502). Rate limits on every route that reaches OpenProject; the CSRF check is intact on every POST/PUT/DELETE.

**Who may call what.** *options*, *parents*, *identifier*, *preview* and *start*: anybody who may create a team (`createTeamGroup`), 403 otherwise. *status*: the operation's creator, a Nextcloud administrator, or an admin (level ≥ 8) of the team it produced. *run*, *retry*, *rollback*: the creator or a Nextcloud administrator — the steps run **as the creator** either way. *forTeam*: members. *membership-drift* / *membership-sync*: team admins. Everything under `/admin/`: Nextcloud administrators.

### `GET /api/v1/provisioning/options`

**Query**: `templateKey` (default `openproject`). Rate limit 30/min. Probes the integration. **Response 200**: `{ templateKey, blueprint, components: [ { id, kind: "app"|"module", required, installed, missing, preselected, action: "create"|"link"|"enable"|"none" } ], missingRequired: [ids], capabilities (probed), templates: [ { id, identifier, name, …, approved: true } ] (the OpenProject templates the caller may copy, narrowed to the blueprint's approved list), templatesTotal, approvedOnly, roles: [ { id, name } ], roleMapping: { owner|admin|moderator|member|guest: { name, id, missing } }, canCreate, canLink, modes, allowParent, warnings: [ "templates:<code>" | "roles:<code>" ] }`. The components are the template row's apps and modules — every one required; the wizard has no apps step.

### `GET /api/v1/provisioning/parents`

**Query**: `q`. Rate limit 30/min. **Response 200**: `{ projects: [ project summaries ] }` — the OpenProject projects the caller may create a new project under.

### `GET /api/v1/provisioning/identifier`

**Query**: `identifier`, `name`. Rate limit 60/min. **Response 200**: `{ identifier, valid, available, suggested }` — `available` is OpenProject's answer (404 on `projects/{identifier}` = free; a project the caller cannot see counts as taken).

### `POST /api/v1/provisioning/preview`

**Body**: `{ templateKey, mode: "create"|"link", members: [ { id, type, level, displayName, decision? } ], openProject: { projectId? } }`. Rate limit 20/min. Creates nothing. **Response 200**: `{ entries: [ { id, type, displayName, level, teamRole, openProjectRole: {id,name}|null, principal: {id,name,type}|null, matchedBy: "connected"|"login"|"email"|"group"|null, status: "add"|"exists"|"no_access"|"unmatched", decision, roleDrift?, membershipId? } ], unmatched, needsDecision, usedRoleKeys, roleMapping, roles }`. The caller is added as owner. A member in `needsDecision` blocks `start` until they carry `decision: "teamhub_only" | "omit"`.

### `POST /api/v1/provisioning`

**Body**: `{ idempotencyKey ([A-Za-z0-9_-]{8,64}, required), templateKey, mode, name, description?, visibility: "private"|"public", startDate?, endDate?, category?, ownerUid?, profileKey?, preselectConfig?, openProject: { projectId? (link), templateId?, parentId?, identifier? (create; suggested from the name when blank) }, members: [ … ] }`. Rate limit 10/min. Records the operation and its steps; nothing is made yet. **Response 201** with the operation (`status`, `steps`, …); **200** with the existing operation when the same creator sends the same key again (the replay guard). `components` in the request are ignored — the template decides. 400 for invalid input, 403 when the caller may not create teams, 404 for an unknown template.

### `GET /api/v1/provisioning`

The caller's own unfinished operations: `{ operations: [ summaries ] }`.

### `GET /api/v1/provisioning/{id}`

**Response 200**: `{ id, teamId, templateKey, mode, status: "pending"|"running"|"completed"|"attention"|"failed"|"rolled_back", currentStep, name, createdBy, createdAt, updatedAt, startedAt, completedAt, heartbeatAt, stalled, errorCode, errorMessage, request, steps: [ { key, status: "pending"|"running"|"completed"|"skipped"|"attention"|"failed"|"rolled_back", resourceType, externalId, attempts, startedAt, completedAt, errorCode, errorMessage, retrySafe, rollbackPossible, detail } ], result: { teamId, name, project, resources: [ { app, type, id, mode, url, health } ], membership, handover, dashboard } | null }`. What a page reload reads; nothing in it is a secret.

### `POST /api/v1/provisioning/{id}/run`

**Body**: `budget` (seconds, ≤ 60, default 20). Rate limit 60/min. Executes pending steps for up to the budget under a lease and answers the same shape as the status read, plus `pollAfter` (seconds) when a step is waiting on OpenProject, or `busy: true` when another runner holds the lease. The client calls again while `status` is `pending`/`running`.

### `POST /api/v1/provisioning/{id}/retry`

**Body**: `step` (optional key; without it the failed step, or every step that needs attention). 400 when the step is not `failed`/`attention` or said a retry is not safe. Runs on after the reset.

### `POST /api/v1/provisioning/{id}/rollback`

**Body**: `confirm` (bool). Without `confirm`, a dry run when any created resource may hold activity: `{ status: "confirm_required", needsConfirm: [step keys], verdicts }`. Otherwise the team is removed through the normal delete cascade and the answer is `{ status: "rolled_back", verdicts, … operation }`. Never a linked resource, never the OpenProject project (reported `manual_review`), never a completed operation (400 — delete the team), never after a handover (400 — Maintenance).

### `GET /api/v1/teams/{teamId}/provisioning`

Members. **Response 200**: `{ provisioning: { id, status, currentStep, complete, openSteps: [ { key, status, errorCode } ], createdBy, updatedAt } | null }` — the same summary the layout bundle carries as `provisioning`.

### `GET /api/v1/teams/{teamId}/openproject/membership-drift`

Team admins, rate limit 10/min, read as the caller. **Response 200**: `{ missingInOpenProject: [ { id, displayName, teamRole, openProjectRole, principal } ], extraInOpenProject: [ { membershipId, principal, roles } ], roleDrift: [ { id, displayName, teamRole, expectedRole, currentRoles } ], unmatched: [ { id, displayName, teamRole } ], inSync, checkedAt, roleMapping, projectId }`.

### `POST /api/v1/teams/{teamId}/openproject/membership-sync`

Team admins, rate limit 5/min. The one explicit, one-way action: adds the `missingInOpenProject` members to the project with their mapped role, as the caller. Nothing is removed, no role is changed. **Response 200**: `{ added: [uids], refused: { uid: code }, drift }`. Audited on the team as `openproject.membership_synced`.

### `GET /api/v1/admin/provisioning`

**Query**: `limit` (≤ 100). **Response 200**: `{ operations: [ summaries ] }`, newest first.

### `GET /api/v1/admin/policy/openproject-roles`

Rate limit 10/min. The OpenProject roles and templates as the administrator's own connected account sees them: `{ roles, templates }`. For the template editor's OpenProject section.

### `GET | PUT | DELETE /api/v1/admin/policy/templates/{templateKey}/blueprint`

GET: `{ templateKey, stored, blueprint, vocabulary }`. PUT **body** `{ blueprint }` — validated by name (unknown application, module, widget, role key, copy key or behaviour → 400 naming it; `openproject.required` on any template but `openproject` → 400); `apps`, `modules` and the per-application behaviours are re-derived from the template row on every read, so the section stores only what the row cannot say. DELETE resets to the shipped blueprint (`openproject`) or removes it (the others). Audited as `policy.blueprint_updated` / `policy.blueprint_reset`.

**Audit events added**: `provisioning.started`, `provisioning.step_failed`, `provisioning.completed`, `provisioning.needs_attention`, `provisioning.retried`, `provisioning.rolled_back`, `provisioning.run_as_creator`, `openproject.membership_synced`, `policy.blueprint_updated`, `policy.blueprint_reset`.
