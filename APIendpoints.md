# TeamHub — API Endpoints

> Updated per `/session-end` step 6 whenever a session adds, removes, or changes an endpoint.
> Endpoints below cover the changes made this session (3.78.0 through 3.79.0). For a complete catalog of all TeamHub endpoints see `appinfo/routes.php`.

---

## Refusals answer 403 and 404, not 400 (changed 4.20.0)

Fifteen `TeamController` routes answered **every** exception with 400 and its message, so a refused membership or admin check came back as 400 and a missing team as 400. They now answer through `clientErrorResponse()`:

| Exception | Status | Body |
|---|---|---|
| `AccessDeniedException` | 403 | `{ error }`, the message |
| `NotFoundException` | 404 | `{ error }` |
| `AppNotAvailableException` | 422 | `{ error }` |
| `ValidationException`, `\InvalidArgumentException` | 400 | `{ error }` |
| any other `\Exception` | 400 | `{ error }`, the message, **unchanged** |

Routes: `team#getIntravoxSubPages`, `getIntravoxTeamPage`, `invalidateIntravoxCache`, `getCollectivesConfig`, `getCollectivesTeamRow`, `getCollectivesSubPages`, `invalidateCollectivesCache`, `createCollectivesPage`, `markTeamSeen`, `createCalendarEvent`, `updateTeamDescription`, `removeMember`, `updateMemberLevel`, `approveRequest`, `rejectRequest`.

`team#getCollectivesPageTree` (`GET /api/v1/teams/{teamId}/collectives/page-tree`) caught a class that did not exist, so every failure escaped as an unlogged 500. It now uses `exceptionResponse()`: 403 for a non-member, and a logged 500 with a `ref` for anything unexpected.

`roster#calendarWeek` (`GET …/roster/calendar`) is **CSRF-protected** since 4.20.0. It carried `#[NoCSRFRequired]` with no documented reason; its only caller is `@nextcloud/axios`, which sends the request token.

---

## A members' endpoint that was admitting only administrators (changed 4.13.51)

`GET /api/v1/teams/{teamId}/calendar/events/week` (`team#getCalendarEventsForWeek`) carried **no controller attributes at all**. Its `#[NoAdminRequired]` and `#[NoCSRFRequired]` were stranded above the *next* method's docblock and therefore applied to `getCalendarEventsInRange`, which declares its own. Without `#[NoAdminRequired]` Nextcloud admits only a Nextcloud administrator, so the week view refused every ordinary member. The attributes now sit on the method they were written for; the endpoint's own `requireMemberLevel()` check is unchanged, and was always the real boundary.

`getCalendarEventsInRange` keeps `#[NoAdminRequired]` and no longer inherits a stray `#[NoCSRFRequired]`. It is called from the frontend through `@nextcloud/axios`, which sends the request token, so the CSRF check it now faces is one it already satisfies.

Found by the first PHPStan run that could resolve the framework. DESIGN §2.218.

---

## People: the collaboration profile, birthdays, anniversaries and time off (added 4.13.41 — 4.13.50)

The Presence module is called **People** everywhere a person reads it. Nothing underneath was renamed: the routes are still `/api/v1/presence/*`, the config keys still `presence_*`, the tables still `teamhub_presence_*`. See DESIGN §2.210 for why.

### The location tree (changed in 4.13.54)

`GET /api/v1/admin/presence/buildings` returns the buildings → floors → rooms tree. Each building and each floor now carries a **`source`**: `null` for one an administrator created here, or the identifier of the room backend that publishes it (`calendar_resource_management`, `roomvox`, …).

A row with a non-null `source` is **read only**. `PUT` and `DELETE` on it answer **403** from `PresenceLocationService`, not merely hidden controls in the admin page; adding a floor under a managed building, or a room under a managed floor, is unaffected and is the point of the feature. Rooms never carry a `source` and are never synced.

The tree read also triggers the sync, so the buildings and floors a backend publishes are never stale when somebody looks at them. See DESIGN §2.221.

### The collaboration profile

| Endpoint | Gate | Answers |
|---|---|---|
| `GET /api/v1/people/profile` | `#[NoAdminRequired]`, the caller only | the caller's profile, the Department-template teams they may pick from, and the room tree |
| `PUT /api/v1/people/profile` | `#[NoAdminRequired]`, CSRF-protected | body `{ "profile": { … } }` — **the caller's own profile, always**; no `userId` is accepted |

The write verifies every reference server-side: a department id against `TeamService::getUserTeams()` + `TeamTypeService`, a room id against `PresenceLocationService`. A department the caller does not belong to, or a room that does not exist, is **400** — not a silent drop.

**Storage is a preference, not a table**: `people_collaboration_profile_v1`, one JSON value per user under the `teamhub` app id (DESIGN §2.211). No migration, and an app update keeps it.

**Two fields are read-only and come from Nextcloud, not from the form** (DESIGN §2.212): the account date from a `UserCreatedEvent` TeamHub recorded, falling back to `IUser::getFirstLogin()`, with `0` and `-1` reading as *Not available*; the department date from the direct Circles membership's `joined` column, absent for a member who holds the team only through a group. **Neither is an employment date** and neither may be presented as one.

### The read-only profile of a colleague (gate changed 4.13.52)

| Endpoint | Gate | Answers |
|---|---|---|
| `GET /api/v1/people/directory/{userId}` | `#[NoAdminRequired]`, People module on, **signed-in viewer** | the fields that user shares |
| `GET /people/{userId}` | the same check, repeated server-side | the `TemplateResponse` standalone page |

**The shared-Department requirement is gone (Justin, 2026-10-30).** A collaboration profile is a property of a person, not of a team. Requiring a shared People-enabled Department team made the feature invisible on every upgraded instance, because the 4.13.41 migration writes `presence_enabled = 0` for every pre-existing team — so the gate was shut everywhere and nothing on the page could say why. What remains is the instance-wide People switch, a **signed-in** viewer (a Nextcloud profile page can be public; these details are for colleagues), and each field's own sharing switch. Both endpoints still answer **403** through one gate in `PeopleDirectoryService`, so the page and the API cannot drift apart.

`fields` changed shape with the gate: it is now an **ordered list of `{label, value}` with the labels already translated** server-side, instead of an object keyed by English label. Both readers render what they are given rather than each keeping a copy of the label map.

**4.13.63 — there is now a third reader, and it is unified search.** `PeopleSearchProvider` (`teamhub-people`, order 54) matches the term against published profile fields and links each hit to `/people/{userId}`. It has **no endpoint of its own**: Nextcloud's search drawer calls it, and it reads through the same gate. Two things an integration should know about what is searchable:

- **The SQL is a prefilter, not the authorisation.** One `iLike` over the stored `people_collaboration_profile_v1` preferences narrows the pool; every candidate then goes through `PeopleDirectoryService::profiles()` and only the fields *that* returns are matched. A field its owner un-shared is in the stored JSON and can never be a search key.
- **Department and usual workplace are not search keys**, though both still appear in a result's subline. They are stored as ids rather than names; a department is a team, and `teamhub-teams` already answers for teams. Display names are left to Nextcloud core.

`PeopleDirectoryService::profiles(array $userIds)` is the batched form of `profile()` added for this, and is the one to call for more than one person: `getBuildings()` triggers the room-backend sync (§2.221), so a loop over the single-person call would sync once per person.

The entry point is no longer a profile **action**. The fields are rendered into the Nextcloud profile page itself, by a script attached through the documented `OCP\Profile\BeforeTemplateRenderedEvent`; `PeopleProfileLinkAction` is removed (DESIGN §2.219, which reverses §2.214).

### Birthdays and anniversaries

| Endpoint | Answers |
|---|---|
| `GET /api/v1/teams/{teamId}/people/birthdays` | the **three** next birthdays in a Department team, from today; display name and localized month/day **only** |
| `GET /api/v1/people/birthday/celebration` | whether the caller has a greeting waiting |
| `POST /api/v1/people/birthday/acknowledge` | records it, **on dismissal only** |
| `GET /api/v1/people/anniversaries` | the caller's 1/5/10/25-year department milestones falling today |
| `POST /api/v1/people/anniversaries/acknowledge` | body `{ teamId, years }`, the caller only |

The birthday source of truth is Nextcloud's own `IAccountManager::PROPERTY_BIRTHDATE`. `SCOPE_PRIVATE` and the profile's `shareBirthday` both veto it, **no birth year is ever returned**, and a 29 February birthday is greeted on 1 March in a common year. The members endpoint requires team membership before it enumerates anybody, and returns an empty list for a non-Department team or one whose admin hid the widget.

A `GET` never marks a greeting seen — only the dismissal `POST` does, so a page reload does not consume somebody's birthday (CLAUDE.md: a GET never mutates).

### Shifts: my week in my calendar, double bookings, and notices (4.14.8)

DESIGN §2.250. Two endpoints, both behind team membership and `requireAvailable()` like every roster route.

| Endpoint | Gate | Answers |
|---|---|---|
| `GET …/roster/calendar?week=YYYY-MM-DD` | team member | `{ from, to, shifts, calendars, calendarUri, transp }`. The **caller's own published** shifts in the week holding `week` (Monday to Sunday), each with `inCalendar`; the calendars the caller **owns** that can hold an event (tasks-only calendars are left out); and what they picked last time, else Nextcloud's default calendar, else busy |
| `POST …/roster/calendar` | team member | `{ week, calendarUri, transp }` → `{ added, updated, failed, from, to }`. `transp` is `OPAQUE` (busy) or `TRANSPARENT` (free). Writes every one of those shifts into that calendar in one call; **running it again rewrites the same events**. 400 for a calendar the caller does not own, a bad `transp`, or a week with nothing of theirs in it; 422 when the CalDAV backend is not there |

**No user id is accepted by either**, so neither can read or write a colleague's week. The calendar is matched against the caller's own calendar home on the server, not trusted from the body.

**Each event** carries `UID:teamhub-shift-{shiftId}-{userId}@teamhub-roster` (user id sanitised to `[a-zA-Z0-9._-]`), object URI the UID lowercased plus `.ics`, `PRODID:-//TeamHub//TeamHub Roster//EN` and the marker **`X-TEAMHUB-ROSTER:1`**. Times are UTC, converted from the member's own timezone, and a shift that ends the next day ends at the wall-clock time even on the night the clocks change. `teamhub_roster_cal` holds one row per (shift, person) with the calendar and URI.

**Coming off a shift takes the event out**: `unassign`, a hand-over claimed from you, `unpublish`, `DELETE …/shifts/{id}`, and deleting the team (shifts from today on only). Every removal checks the marker first and leaves anything without it. Gaining a shift never writes by itself.

**Double bookings are refused.** `assign`, `claim` and a hand-over `claim` answer **409** when the person is already on another shift whose hours overlap, **in any team, draft or published**. The message names neither the other shift nor its team. Back-to-back shifts touch and are allowed. `staffing` reports it as a fourth `state`, **`overlap`**, beside `timeoff`; both refuse.

**`claim` now refuses a draft shift** (400). The docblock always said it needed a published shift; nothing enforced it until 4.14.8.

**Notifications.** Seven new `teamhub` subjects, all about **published** shifts, never sent to the person who acted, each linking to `?team={teamId}&roster={date}` (opens the Roster tab):

| Subject | Sent to | When |
|---|---|---|
| `roster_published` | everybody on a newly published shift | `publish`. One per person for the week, listing up to seven of their shifts. Object `roster_week` / `{teamId}:{from}`, which replaces the previous week notice |
| `roster_withdrawn` | everybody who was on the week | `unpublish`. Same object, so it replaces the publish notice |
| `roster_assigned` | the person | `assign` onto a published shift |
| `roster_unassigned` | the person | `unassign` of somebody else from a published shift |
| `roster_shift_removed` | everybody on it | `DELETE` of a published shift |
| `roster_role_changed` | everybody on it | `PUT` that changes a published shift's role |
| `roster_handover_taken` | the requester | a hand-over claimed |

`PUT …/shifts/{id}`, `DELETE …/shifts/{id}`, `unassign` and `unpublish` now pass the caller to the service as the actor. Their request and response shapes are unchanged.

### Shifts: schedules, concept/published, and hand-overs (added 4.13.68)

**The gate changed.** Every roster endpoint now goes through `ShiftsModuleService::requireAvailable()`, which is **three** gates, refused in this order and named in the message:

1. `presence_module_enabled` — the People module, instance-wide. Shifts has **no instance switch of its own**.
2. **A licence.** `LicenseService::hasLicenseKey()` plus an enforcement level of `none` or `grace`.
3. `teamhub_presence_team.shifts_enabled` — the team's own switch, **default 0**.

`GET /api/v1/teams/{teamId}/presence/config` now also answers `shifts_enabled`, `shifts_offerable` (People on **and** licensed) and `shifts_reason` (`unlicensed` | `disabled` | null), so a client can grey the control with the reason instead of hiding it. `PUT` accepts `shifts_enabled`; setting it to 1 without a licence is **403**, setting it to 0 is always allowed.

#### Shift types — Nextcloud administrator only

No `#[NoAdminRequired]` on either of these, which is the access rule. Served by `ShiftTypeController`, which was `ShiftScheduleController` until 4.14.4.

| Endpoint | Answers |
|---|---|
| `GET/POST /api/v1/admin/shift-types` | the vocabulary every team rosters from |
| `PUT/DELETE /api/v1/admin/shift-types/{id}` | a built-in refuses deletion (switch it off); so does one a **team roster template** still uses |

**Removed in 4.14.4: the four `/api/v1/admin/shift-schedules` endpoints.** They served the instance-wide weekly pattern of DESIGN §2.234, which the team roster template below replaced one release later (§2.247). The two tables behind them are dropped by `Version000414004Date20261103120000`.

The *in use* check moved with them. Until 4.14.4 deleting a type was refused when a **schedule** used it and never looked at a team's template, so a type a team was rostering from could be deleted without a word; it now asks `RosterTemplateRowMapper::usesShiftType()`.

#### The team's own week templates — team admin (added 4.13.74)

**The division, and it is the point of these five endpoints.** A Nextcloud administrator defines the shift **types** above; a **team admin** defines that team's **templates**, from Manage team → Module settings. Before 4.13.74 the only weekly pattern was the instance-wide schedule, whose endpoint admits Nextcloud administrators only — so the Roster tab's picker was permanently empty for the one person it was built for (DESIGN §2.240). 4.14.4 removed that schedule, which leaves these the only week in the app (§2.247).

Every one of these is `#[NoAdminRequired]` plus `requireAdminLevel($teamId)` plus `requireAvailable($teamId)`. `{templateId}` is looked up **by id and team together**, so an id belonging to another team is a 400 "that template does not exist" rather than a 403 — it is not found, not refused.

| Endpoint | Answers |
|---|---|
| `GET …/roster/templates` | `{ templates, types }`. The team's templates with their weeks, **and the active shift types**, which travel with them because a team admin cannot read the administrator's own type endpoint |
| `POST …/roster/templates` | `{ name, description?, isActive?, lines }` → the saved template. **201** |
| `PUT …/roster/templates/{templateId}` | the same body. The week is **replaced wholesale**, in one transaction |
| `DELETE …/roster/templates/{templateId}` | `{ deleted: true }`. Weeks already drafted from it are untouched |
| `POST …/roster/apply-template` | `{ weekStart, templateId }` → `{ created, skipped, from, to }`. Drafts the week with every place named by its role |

A template line is `{ dayOfWeek, shiftTypeId, role, requiredCount }`, `dayOfWeek` 0 = Monday. **The `role` is required** and is what the template is for: it is copied into the shift's `notes`, which is the column the roster card has shown as the role since 4.13.70, so a drafted week reads *Receptionist 08:00 to 12:00* and the only thing left is assigning people.

**Two roles may share one weekday and one shift type**; the same role twice on one may not. That is the one rule that could not have been expressed by adding a `team_id` to the instance-wide schedule tables, whose unique index was (schedule, weekday, type) — and the reason the **duplicate guard on a shift now counts the role**. A line whose shift type has since been switched off is skipped rather than refused: the administrator who retired *Night* did not invalidate every template that used it.

Deleting a team forgets its templates.

#### Planning, publishing and announcing

`POST …/roster/apply`, which laid the instance-wide pattern over a week, **was removed in 4.14.4** with the pattern itself. `…/roster/apply-template` above is now the only way a week is drafted.

| Endpoint | Gate | Answers |
|---|---|---|
| `POST …/roster/unpublish` | **team admin** | `{ from, to }`. Back to concept: clears `published_at`, removes the presence rows the week wrote, withdraws open hand-overs. **`published_by` is kept** |
| `POST …/roster/notify` | **team admin** | `{ from, to }` → `{ posted, error }`. Announces the published week in the **message stream**, not Talk. Never fails a publish |

#### Hand-overs

| Endpoint | Gate | Answers |
|---|---|---|
| `POST …/roster/shifts/{shiftId}/handover` | member, **on that shift**, shift published | `{ reason? }` → `{ request, announced }`. The row is written first; the Talk post is best effort, so a team without Talk still gets a working request and `announced: false` |
| `POST …/roster/handovers/{requestId}/claim` | team member | The requester comes off, the claimer goes on, **one transaction**. Availability still blocks (**409**) |
| `POST …/roster/handovers/{requestId}/withdraw` | the requester only | |

The grid read now carries `handover` on each shift: `null`, or `{ id, requesterUid, requesterName, reason }` for an open request.

The Talk message links to `?team={teamId}&shiftRequest={id}`, which opens the team's Roster tab.

### Statistics, availability and staffing (added 4.13.71)

| Endpoint | Gate | Answers |
|---|---|---|
| `GET …/roster/stats/me?year=YYYY` | team member | **the caller's own** year. No uid is accepted, so this cannot read a colleague's. **Since 4.14.11 it spans every team** the caller has published shifts or hand-over requests in, not only `{teamId}`, and adds `teams: [{ teamId, teamName, total }]` (DESIGN §2.253) |
| `GET …/roster/stats/team?year=YYYY` | **team admin** | the team's year plus a line per member. A comparison between colleagues is not a member's business |
| `GET …/roster/shifts/{shiftId}/staffing` | **team admin** | who may be put on this shift, and what stands in each person's way |
| `GET /api/v1/people/shift-availability` | signed in | **the caller's own** week, all fourteen cells |
| `PUT /api/v1/people/shift-availability` | signed in | `{ cells: [{ dayOfWeek, halfDay, available }] }`, the caller's own |

A statistics bucket is `{ plannedShifts, plannedMinutes, workedShifts, workedMinutes, upcomingShifts, upcomingMinutes, handoverRequests }`, with twelve `months` (January is 0) and a `total`. **Minutes, not hours** — rounding happens at the point of display, or a column of rounded figures stops adding up to its own total.

**`workedBasis` is `past_shifts`, and a client should say so.** Nothing records that a shift was staffed, so "worked" means its date has passed. Only **published** shifts are counted: a draft is a plan nobody was told about. Hours come from the shift type, so retiming a type changes historical figures.

Hand-over requests are counted by **when they were asked**, whatever became of them — a withdrawn request counts, because the asking is the fact.

**Staffing options** are `{ userId, displayName, state, reason, onShift }` with `state` one of:

- `ok` — nothing known against it.
- `unavailable` — they said they cannot work this half-day. **Advice, not a lock**: the assign endpoint still accepts them.
- `timeoff` — booked time off, which **does** refuse with a 409.
- `overlap` (4.14.8) — already on another shift at overlapping hours, in any team. Refuses with a 409.

**4.14.9 — the staffing response is an object, and it is ordered fairly** (DESIGN §2.251). `GET …/staffing` answers `{ options, sortedBy, weekFrom, weekTo, weekendFrom }` (it was `{ options }` with the same `options` inside, so a client reading `options` is unaffected). Each option adds **`weekMinutes`** (minutes this person has on **this team's** shifts in the shift's Monday to Sunday week) and **`weekendShifts`** (this team's Saturday and Sunday shifts over the four weeks ending with that week). Drafts count; other teams do not. `sortedBy` is `weekend` when the shift being staffed is on a Saturday or Sunday, else `week`. Within each `state` the options are ordered lightest first: by `weekendShifts` then `weekMinutes` for a weekend shift, the other way round otherwise, then by name. It orders and never filters.

**Availability is instance-wide**, not per team, and an **absent row means available** — anything else would empty every roster on the day the feature is switched on. Only the exceptions are stored. `day_of_week` is 0 = Monday; `half_day` is 0 morning, 1 afternoon.

**A public holiday no longer blocks an assignment** (4.13.71). The grid read carries `holidays` as `{ "YYYY-MM-DD": name }` so a client can mark the day, and a published shift now **overwrites** the holiday overlay in `teamhub_presence_slots`: somebody rostered on a holiday is working it. A deliberate absence still wins.

---

**4.13.70 — `notes` is `role`.** The shift's free-text field is **Role** everywhere a person or an integration sees it, and the grid serialises it as `role`. **The column is still `notes`** — renaming it would be a migration on every supported database to change text no user ever sees (§2.210's rule). A shift with a role is labelled by it; without one, by its shift type. The hand-over message in Talk uses the same resolution, and its date is now formatted with `IL10N::l('date', …)` in the poster's language — a Talk message is one string for a room, so it cannot follow each reader's locale.

**4.13.69 — two corrections.** The planning controls (apply a schedule, publish, back to concept, notify) moved from Manage team onto the **Roster tab**, gated on `can_manage` from the grid payload; the server gates are unchanged, so an integration sees no difference. And every message these endpoints send had `{name}` placeholders that `OCP\IL10N::t()` never substitutes — it takes **sprintf** parameters, unlike the JavaScript `t()`. The strings are numbered (`%1$s`) now. No PHP string in this app is covered by `check:l10n`, which scans `src/` only.

---

### The roster: who works when (added 4.13.67)

The People module's second surface, behind the **same double gate** as the planning grid: the instance switch `presence_module_enabled` **and** the team's `presence_enabled`. Either off is a **403** on every endpoint below, not an empty list.

| Endpoint | Gate | Answers |
|---|---|---|
| `GET /api/v1/teams/{teamId}/roster?from=&to=` | team member | the week. A member is served **published shifts only**; a team admin also gets the draft and `can_manage: true` |
| `POST /api/v1/teams/{teamId}/roster/shifts` | **team admin** | `{ date, shiftTypeId, roomId?, requiredCount?, role? }` |
| `PUT /api/v1/teams/{teamId}/roster/shifts/{shiftId}` | **team admin** | `{ requiredCount?, role?, roomId? }`. The date and the type are **not** editable: a shift that changes either is a different shift, and editing in place would silently move people already on it |
| `DELETE /api/v1/teams/{teamId}/roster/shifts/{shiftId}` | **team admin** | removes the shift, everyone on it, and the presence rows it wrote |
| `POST …/shifts/{shiftId}/assign` | **team admin** | `{ userId }` |
| `POST …/shifts/{shiftId}/unassign` | team admin, **or** the member dropping a shift they claimed | `{ userId }` |
| `POST …/shifts/{shiftId}/claim` | team member | the caller takes an open place on a published shift |
| `POST /api/v1/teams/{teamId}/roster/publish` | **team admin** | `{ from, to }` → `{ published, from, to }` |

Range reads are capped at **31 days**; a longer one is a 400.

**A shift id is checked against its team**, not trusted from the route — the id is guessable and "the frontend only sends its own" is not a boundary.

**409, not 400, when the person is not available.** Assigning onto a public holiday or a member's booked time off is refused with the reason. Availability is a hard constraint: an unassigned shift is a better answer than one assigned to somebody on holiday. A *weekly workplace* does not block.

**What publishing does.** It stamps `published_at` on every **draft** shift in the range, leaves an already-published one's timestamp alone (that date is the record that the notice period was met, per Arbeidstijdenwet art. 4:2), and writes every assignee's presence.

**The presence write-through.** A published shift lands in `teamhub_presence_slots` with **`source = 'roster'`**, on the half-days it occupies — the noon split, and a shift running past midnight also takes the next morning. It replaces a `template` slot, never a `holiday` or a deliberate absence, and the clean-up only ever deletes rows carrying its own source. A client reading the presence grid can therefore tell an assigned shift from a declared workplace by `source`.

**Shift types are instance-wide and administrator-managed**, the same contract as presence types: `teamhub_shift_type`, keyed by `slug`, with `HH:MM` wall-clock times. `end <= start` means the shift ends the next day; nothing stores that separately, so the two cannot disagree. Three are seeded (`early`, `late`, `night`) by `RepairSteps\SeedShiftTypes`.

---

### Time off: a range of half-days

`PUT /api/v1/presence/slots/override-range` — `#[NoAdminRequired]`, the caller only.

```
{ "from_date": "2026-12-21", "to_date": "2027-01-02",
  "part": "all" | "morning" | "afternoon",
  "presence_type_id": 7, "restore": false }
```

| Rule | Answer |
|---|---|
| range length | 1 to 120 days inclusive, else **400** |
| `presence_type_id` | only the `vacation`, `sick` and `non_working_day` slugs, else **400**; required unless `restore` |
| an administrator's public holiday | **skipped, never overwritten**, and counted in `skipped` |
| every date locked by a holiday | **409**, carrying `affectedCount` |
| `restore: true` | returns each selected half-slot to its own weekly workplace **including its original room**, or clears it where there is no weekly preference |

The write is one transaction: a range either lands or it does not. `part` addresses **half-days**, and materialisation now skips only the half-days that are already specified, so an afternoon of sick leave no longer suppresses that morning's normal workplace.

**What this is not:** a TeamHub absence is not a Nextcloud Absence. It is read by TeamHub's meeting suggestions and planning grid and by nothing else — it writes no calendar event, does not set Nextcloud's own absence status, and does not block third-party CalDAV scheduling (DESIGN §2.216). A member who needs those must set Nextcloud Absence as well.

**What a colleague sees is `Unavailable`** — never *Sick*, never the reason, and with the team's existing `hide_reasons` setting the label goes too. The reason stays in TeamHub's own rows, which is to say an operator with database access can still read it.

### Availability comes from the calendar, and only the meeting wizard asks

**Changed in 4.13.64: `GET /api/v1/teams/{teamId}/presence` no longer returns `availability` or `availability_samples`.** A client reading either gets nothing; both keys are gone from both branches of the response, including the zero-member one. The grid's sampled dots are removed with them (DESIGN §2.230) — two samples per member per day were fourteen `checkMany()` calls per grid load for a signal that needed a caveat sentence above the table to avoid being misread.

**Meeting suggestions are unchanged** and are the only caller of `NextcloudAvailabilityService` now. Three things still hold there (DESIGN §2.215):

- An unresolvable answer is **`unknown`**, never `free`. It depends on a valid email address, a configured calendar and organizer permission.
- The wizard checks **exact candidate times**, not samples.
- An explicit TeamHub non-working override beats a `free` from FreeBusy (`MeetingSuggestionService::scoreCandidate`). The reverse — TeamHub writing presence into a calendar — is switched off; **events written by the old sync still exist and were deliberately not deleted**, so they can still influence a FreeBusy answer.

---

## Default home layouts: the cascade stopped being a race (changed 4.13.36 — 4.13.40)

Issue #109, after Justin's walk. **This section reverses the contract below it**, which 4.13.35 had only just established. The reasoning is DESIGN §2.209; what an integration has to know is here.

### The newest decision no longer wins

4.13.35 served the strongest applicable default unless the member had arranged that home *since* it was set. `LayoutCascadePolicy` replaces that comparison with a fixed order, and says so in as many words: *once a member arranges their own grid, later template edits must never reset that arrangement.*

| Order | Applies |
|---|---|
| 1 | the team's default in mode **`enforce`** — absolute, no comparison, no member write permitted |
| 2 | the member's **personal default for this template**, when they have set one |
| 3 | the team's default in mode **`apply_once`** — *still compared against the member's `updated_at`* |
| 4 | the default for this kind of team (its template) |
| 5 | the shipped layout |

The timestamp comparison survives in `LayoutController::getLayout()`, but `LayoutCascadePolicy::resolve()` now returns `updatedAt: 0` for everything except an `apply_once` team default. So an **administrator's template or instance default no longer reaches a member who has arranged that team** — re-saving it changes nothing for them. Only a team `apply_once` default still rolls out that way.

A personal default reaches the member's other teams by a different route: saving one **releases that user's own grid rows** for unlocked teams of the same template (`origin` back to `auto`), so the rollout is a write, not a comparison.

### A personal default is scoped to a template, and `teamId` is now required

| Endpoint | Change |
|---|---|
| `GET /api/v1/layout/default` | **requires `?teamId=`** |
| `PUT /api/v1/layout/default` | **requires `teamId` in the payload**; **403** when that team's layout is enforced |

Both verify membership, and the template is derived from the team **server-side** — a client-supplied `templateKey` cannot choose the scope of a preference write. Storage moved from the global `default_layout_json` / `default_tab_order_json` to `default_layout_template_<type>` / `default_tab_order_template_<type>` (`UserTemplateLayoutKeys`). **The old global keys are left in place, unread and unmigrated**: they could spread a Project team's widgets into a Collaboration team, which is the bug that ended them.

### `DELETE .../teams/{teamId}/layout` means something else now

It used to delete the member's row so the team rejoined the default cascade. It now performs an **explicit reset to the current template recipe**, persisting the row with `origin = 'tmpl'` (which fits the existing `VARCHAR(8)` column, so no migration). **An integration that relied on the old meaning has to be reviewed.** It is refused while the team layout is enforced.

### Tab order has its own endpoint

`PUT /api/v1/teams/{teamId}/layout/tabs` — body `{ "tabOrder": [ … ] }`, any member. It saves navigation order **without touching grid coordinates, `origin` or `updated_at`**, and it stays available under enforcement: a tab bar is navigation, not layout. `PUT` and `DELETE` of the grid itself both return **403** while a team layout is enforced, for team administrators too — they publish through the admin-gated `PUT /api/v1/teams/{teamId}/dashboard/config`, which also accepts `reset_team_layout: true` to put the team back on its template while keeping its mode.

`TeamLayoutPolicy::locksPersonalGrid()` is the single predicate behind every one of those refusals.

### A newly published team layout is enforced by default

Publishing a team layout creates it in **`enforce`**. Replacing an existing one keeps whatever mode it already had. **No existing team was migrated.** `suggest` and `apply_once` remain available in Manage team → Settings → Dashboard.

Deleting a team now also clears its `teamhub_layout_default` row and its members' `teamhub_widget_layouts` rows, cascading to sub-teams.

### Still open in this contract

- **One-second timestamp resolution.** Two decisions in the same second compare equal, both in default precedence and in the key a dismissed suggestion is stored under. A real fix needs a generation counter or a higher-resolution column, and a migration.
- **No optimistic concurrency.** Two administrators publishing a team layout at once: last write wins, silently.
- **No push.** A mode changed in another session is enforced by the server on the next write and shown after the next navigation; an already-open tab is not told.

---

## Default home layouts, corrected after the first walk (changed 4.13.35)

Six fixes from Justin's walk of 4.13.33/34. Three of them change this contract.

### The cascade: the newest decision wins

v4.13.34 put every administrator-set default **behind** the member's own arrangement, so setting one changed nothing for anybody who had ever dragged a card. It is now **one comparison**: an applicable default is served unless the member arranged that home *after* the default was set (`teamhub_widget_layouts.updated_at` against the default's `updated_at`).

| Order | Applies |
|---|---|
| 1 | the team's default in mode **`enforce`** — always, no comparison |
| 2 | the member's own arrangement (`origin = 'user'`) — **if they made it after the strongest applicable default was set** |
| 3 | the team's default in mode **`apply_once`** |
| 4 | the member's personal default |
| 5 | the default for this kind of team (its template) |
| 6 | the instance default |
| 7 | the system default |

Only the **strongest applicable** default's timestamp is compared, so touching the instance default cannot override an arrangement on a team whose template has one of its own. A member's **personal** default is never in the comparison: it is their own decision, so it cannot be what their own arrangement loses to.

A consequence worth stating: **saving a default is the rollout.** Re-saving an unchanged layout is how an administrator makes it reach members who have arranged since, and there is no separate rollout route.

### `canSetDefault` is now `canSetTeamDefault`

The layout bundle sent three booleans; it now sends one.

```
- "canSetDefault": { "team": true, "template": true, "instance": true }
+ "canSetTeamDefault": true
```

The instance and per-template defaults are no longer offered from a team's home at all — they are set in Admin → TeamHub → Policy (Justin, 2026-10-28: Nextcloud administrator tasks belong there, and nothing guarantees an administrator is a member of a team). `canSetTeamDefault` is Circles level ≥ 8, as before.

### The two admin GETs carry what an editor needs to open

| Endpoint | Added |
|---|---|
| `GET /api/v1/admin/layout/default` | **`shipped`** — the layout TeamHub ships. The editor has no team's home to seed from, and this is what its *Reset* restores |
| `GET /api/v1/admin/policy/templates/{templateKey}/layout` | **`shipped`**, and **`instance`** — a template with no default of its own inherits the instance one, so that is what its editor opens on and what *Reset* restores |

`PUT` is unchanged, and an **empty `tabOrder` is meaningful**: the admin editor arranges the grid only, sends `[]`, and the cascade then keeps whatever tab order the member already has.

### `dashboardConfig` is built in one place

`team_layout` and `team_layout_mode` were added to `TeamController::readDashboardConfig()` in 4.13.34 and **not** to the layout bundle's copy, which is the one the Manage team screen reads — so a saved team layout reported as absent. Both now call `DashboardWidgets::configFor()`. No field changed shape; the bundle simply carries the two it was missing.

---

## Default home layouts: the instance, a kind of team, one team (added 4.13.34)

Issue #109 phases 2 and 3. A default is what a member's home looks like **before and unless they arrange it themselves**; nothing is copied into anybody's layout row when one is set, so a changed default reaches everybody who has not arranged their own home, at once. Storage is one table, `teamhub_layout_default`, keyed by `(scope, scope_id)`.

### The cascade `GET /api/v1/teams/{teamId}/layout` now walks

1. the team's own default, if its mode is **`enforce`** — above the member's own arrangement
2. the member's own arrangement (`teamhub_widget_layouts.origin = 'user'`)
3. the team's own default, if its mode is **`apply_once`**
4. the member's personal default (`oc_preferences`)
5. the default for this **kind** of team (its template)
6. the **instance** default
7. the system default

A team default in mode **`suggest`** is in none of those: it reaches the member as an offer on the bundle and changes nothing until they take it.

### Two new fields on the layout bundle

| Field | Meaning |
|---|---|
| `teamPreset` | `{ mode: "suggest"\|"apply_once"\|"enforce"\|null, updatedAt, layout }`. `mode` is null when the team has no default of its own. `layout` is sent **only** for `suggest`, the one mode that asks the member to choose; the other two are already in `layout` |
| `canSetDefault` | `{ team, template, instance }` booleans. Which "save this arrangement as a default" actions to offer. `team` is Circles level ≥ 8; the other two are a Nextcloud administrator. Answered by the server so a control nobody may use is never drawn |

### Instance and per-template defaults

All six are gated by `#[AuthorizedAdminSetting]` **alone**, with no `requireNcAdmin()` behind them, so a **delegated TeamHub admin may write them** (Justin's decision, 2026-10-27). That is a departure from every other instance-governance route in this app, which still refuses one; see `LayoutDefaultController`'s docblock and HANDOFF.

| Endpoint | Purpose |
|---|---|
| `GET /api/v1/admin/layout/default` | `{ default: { layout, tabOrder, mode, updatedBy, updatedAt } \| null }` |
| `PUT /api/v1/admin/layout/default` | Body `{ layout, tabOrder }`. **400** on an empty layout, or one too large (64 KB cleaned) |
| `DELETE /api/v1/admin/layout/default` | Teams fall back to the system default |
| `GET /api/v1/admin/policy/templates/{templateKey}/layout` | The same shape, for one kind of team |
| `PUT /api/v1/admin/policy/templates/{templateKey}/layout` | Body `{ layout, tabOrder }`. **400** on a malformed template key |
| `DELETE /api/v1/admin/policy/templates/{templateKey}/layout` | Teams of the template fall back to the instance default |

Deleting a template (`DELETE /api/v1/admin/policy/templates/{templateKey}`) takes its default layout with it, for the reason it already took the integration inclusions: a template later created with the same key must not inherit decisions made about a different one.

### A team's own default rides the dashboard config

No new route: it is a Manage team setting written by a **team admin** (level ≥ 8), beside the hidden-widgets list it belongs with.

`PUT /api/v1/teams/{teamId}/dashboard/config` takes three more shapes, and `false` (absent) is distinguished from `null` because null is itself one of the meanings:

| Body | Effect |
|---|---|
| `{ team_layout: [...], team_tab_order: [...], team_layout_mode: "apply_once" }` | Set or replace it. Mode defaults to `apply_once` |
| `{ team_layout: null }` | Remove it. Members keep any arrangement of their own |
| `{ team_layout_mode: "enforce" }` | Change what it does, without touching the layout. **400** when the team has no layout to apply a mode to |

`GET` and the `PUT` response add `team_layout` (`{ widgets, updatedAt }` or null) and `team_layout_mode`. The layout itself is deliberately **not** returned here: the Manage team screen has no grid to draw it in, and it reaches the people it is for through the layout bundle.

An unrecognised stored mode reads as `suggest`, never `enforce` — a value nothing recognises must not be the one that takes a member's home away from them.

---

## The home layout: a packed default, and who arranged one (changed 4.13.33)

Three changes to the layout endpoints, all driven by issue #109.

| Endpoint | Change |
|---|---|
| `DELETE /api/v1/teams/{teamId}/layout` | **New.** Drops the caller's own arrangement of this team so the default cascade applies to it again. `{ "status": "ok" }`; **400** on a malformed team id, **403** for a non-member, **500** if the delete fails. What *Reset to the default layout* calls, in place of the `PUT` of a copy of the default it used to send. Scoped to the caller and the team: no body, no bulk form, nothing that could reach another member's row |
| `PUT /api/v1/teams/{teamId}/layout` | Accepts **`origin`** in the body: `"user"` when the save is the member arranging their home (a drag, a resize, leaving edit mode), anything else — including absent — when TeamHub is saving on their behalf. Stored in `teamhub_widget_layouts.origin` and only ever **promoted**, so a tab reorder cannot take a home off the cascade and cannot put an arranged one back on it |
| `GET /api/v1/teams/{teamId}/layout` | **`isDefault`** is unchanged in shape and now load-bearing: `true` means the layout came from the cascade and the client packs it to the widgets the team actually shows (`src/lib/packLayout.js`) without saving the result. A saved team row is used **only when `origin` is `user`** — a row TeamHub wrote for itself is walked past. `userDefault` is now the **merged** default on both paths; it used to be merged on one and raw on the other |

**`autoFit` is retired.** It is no longer emitted on `DEFAULT_LAYOUT` items and is dropped from a `PUT` body that still carries it. The measurement pass behind it could never grow a widget — it measured `.teamhub-widget-card`, which has `overflow: hidden`, so `scrollHeight` always equalled `clientHeight` — and what it did instead was write a layout row on first team open, which took every member off the cascade permanently. Widget heights are stated by the default now: one height for every widget whose body is a list, enough for five rows. DESIGN §2.206.

**No migration is needed for the `origin` value itself**, but the column is new (`Version000413033`) and is NULL on every row that predates it. `GET` answers for a NULL row as it reads it, by comparing where its widgets stand against the system default, and does not write the answer back — a GET here does not mutate. The column fills when the member next arranges something.

---

## Sub-teams (added 4.12.2)

A sub-team is an ordinary TeamHub team with a parent: its own circle, registry row, members, storage, modules, policy profile and archive, plus one row in `teamhub_sub_team`. **There is no `circles_member` row between a parent and a child** — Circles propagates membership from the contained circle up to the container, so such a row would make a sub-team's invited outsiders effective members of the main team. DESIGN §2.175.

**The parent id is the authority.** A sub-team has no administration of its own, so every route below is nested under `{teamId}` (the *main* team) and every one of them requires **admin of that team**. All are `#[NoAdminRequired]`, CSRF-protected, and no GET mutates.

**Every route below is gated on the module first (4.12.3).** Sub-teams are a licensed module with a Nextcloud
administrator's switch (Settings → TeamHub → Modules → *Sub-teams module*, appconfig
`teamhub/sub_teams_module_enabled`, **default on**). `SubTeamModuleService::requireAvailable()` runs before the
membership check — whether the module exists is an instance-wide fact that discloses nothing about the team, and it
is the cheaper read. Two refusals, both **403**:

| Missing | Body |
|---|---|
| a licence (no key, or `soft-lock` / `unlicensed`) | `{ "error": "Sub-teams require an active TeamHub license.", "licenseGate": true, "enforcementLevel": "…" }` |
| the administrator's switch | `{ "error": "Sub-teams are switched off on this instance." }` |

`licenseGate` is the same envelope the workflow and service-team routes answer with, so a client handles one error
shape for every licensed surface. The switched-off half carries no `licenseGate`, because there is nothing to renew.
DESIGN §2.177.

| Route | Verb | Auth |
|---|---|---|
| `/api/v1/teams/{teamId}/sub-teams` | GET | admin of `{teamId}` |
| `/api/v1/teams/{teamId}/sub-teams/candidates` | GET | admin of `{teamId}` |
| `/api/v1/teams/{teamId}/sub-teams` | POST | **direct** admin of `{teamId}` |
| `/api/v1/teams/{teamId}/sub-teams/{subTeamId}` | PUT | admin of `{teamId}` (4.12.5; template and policy 4.12.6) |
| `/api/v1/teams/{teamId}/sub-teams/{subTeamId}` | DELETE | admin of `{teamId}` (4.12.5) |
| `/api/v1/teams/{teamId}/sub-teams/{subTeamId}/members` | GET | admin of `{teamId}` (4.12.5) |
| `/api/v1/teams/{teamId}/sub-teams/{subTeamId}/members` | POST | admin of `{teamId}` (4.12.5) |
| `/api/v1/teams/{teamId}/sub-teams/{subTeamId}/members/{userId}` | DELETE | admin of `{teamId}` (4.12.5; `?type=group` 4.13.3) |

**Every `{subTeamId}` route proves two things, not one.** Admin of `{teamId}` says only *you administer some main
team*, and the sub-team id arrives from the caller — so each of these also checks that `{subTeamId}` is a child of
`{teamId}`, and answers **400** *That sub-team does not belong to this team.* when it is not. Without it the
authority on these routes would reach every sub-team on the instance. Pinned by
`tests/Unit/SubTeams/SubTeamAuthorityTest.php`.

`candidates` is declared before the `{subTeamId}` routes in `routes.php`, because the router matches in order and
the reverse would read the literal word "candidates" as a sub-team id.

### `GET /api/v1/teams/{teamId}/sub-teams`

The parent's administrative view — **every** sub-team, including the private ones, which is why it is admin-gated rather than member-gated.

```json
{ "subTeams": [ { "id": "…", "name": "Tender NL-2027", "visibility": "private",
                  "members": 8, "description": "Bid team for the 2027 tender",
                  "expiresOn": "2027-03-01", "expiryEligible": true,
                  "templateKey": "project", "templateLabel": "Project", "templateSeeded": true,
                  "profileKey": "confidential", "profileLabel": "Confidential", "profileSeeded": true,
                  "createdBy": "inge", "createdAt": 1760000000 } ] }
```

**4.12.6 adds `templateLabel` / `profileLabel` and their `*Seeded` flags.** The labels are resolved server-side in one pass for the whole list (`PolicyService::labelledClassificationFor()`, three queries whatever the number of children); the seeded flags travel because the *label rule* is the client's — `profileDisplayName()` in `src/constants/policy.js` translates a seeded row by key and takes an administrator's own wording verbatim. `profileKey` is `null` for an unclassified sub-team, and the row renders *No policy* rather than a blank: which policy got applied is the question, and "none" is an answer. **No conformance is computed or returned** — see `PUT` below.

`visibility` is `private` (the default — invisible to members of the parent who are not in it) or `listed` (name visible under the parent, still not openable). `createdBy` is the admin who created it and therefore its owner; they are filtered out of the roster the sub-team's own members see, and shown here.

### `GET /api/v1/teams/{teamId}/sub-teams/candidates`

The parent's roster, for the member picker. **Effective** membership, so somebody who reaches the main team through a group is offered. Same row shape as `GET /api/v1/teams/{teamId}/members/all`, under `{ "candidates": […] }`.

### `POST /api/v1/teams/{teamId}/sub-teams`

```json
{ "name": "Tender NL-2027", "description": "", "templateKey": "project",
  "profileKey": "standard", "visibility": "private",
  "members": ["jaap", "sanne"],
  "outsiders": [ { "id": "consultant@firm.example", "type": "email" } ] }
```

`name` is the only required field. Answers **201** with the team payload plus `is_sub_team`, `parent_team_id`, `sub_team_visibility` and `can_have_sub_teams`.

Four refusals worth knowing, all **400** except the first:

- **403** when the caller is not a *direct* admin of `{teamId}`. Deliberately stricter than the other two routes: `requireAdminLevel()` accepts an admin of the parent, which would let a sub-team's administrator create a grandchild.
- **400** *A sub-team cannot have sub-teams of its own.* Depth is 1.
- **400** *Members of a sub-team are picked from the main team. Invite anyone else as a guest instead.* — a uid in `members` that is not on the parent's roster.
- **400** *This person is already in the main team — add them as a member rather than a guest.* — a **local user** in `outsiders` who *is* on the roster. An `email` or `federated` invitee is never tested against a roster of uids.
- **400** *This team already has a sub-team with that name.* Sub-team names are unique **per parent**, not instance-wide — two teams may each have a "Design". The storage folder is named `{parent} – {child}`, which is what keeps the two folders apart.

The sub-team's modules and apps come from its template and from nothing else; there is no per-sub-team module endpoint, by design. **4.12.6:** `templateKey: "service"` is refused — see the `PUT` below.

### `PUT /api/v1/teams/{teamId}/sub-teams/{subTeamId}` (4.12.5, extended 4.12.6)

```json
{ "name": "Tender NL-2027", "description": "Bid team for the 2027 tender",
  "visibility": "listed", "expiresOn": "2027-03-01",
  "templateKey": "project", "profileKey": "confidential" }
```

All six fields optional and independent — a dialog that changes one does not resend the other, and a body with neither is a no-op rather than an error. `PUT` and not `PATCH` although the body is partial: every other update in this app is a `PUT`, and one `PATCH` would be the only one.

Refusals: **400** for a name that fails the shape rules, **400** *This team already has a sub-team with that name.* (uniqueness is among **siblings**, and the sub-team itself is excluded so re-saving its own name is not a duplicate), **400** *That is not a visibility a sub-team can have.*, **400** *That is not a template on this instance.*, **400** *That is not a policy profile on this instance.* and **400** *A sub-team cannot be a service team. Create a service team of its own instead.* — the Service template is what makes a team a service desk, which §2.175 refuses for a sub-team; the same refusal now also guards `POST` (it did not before 4.12.6).

**The Files folder keeps the name it was created with.** It is `{parent} – {child}` from creation, and TeamHub cannot rename a team folder, a Talk room, a Deck board or a collective — `TeamService::renameTeam()` exists for this route alone and carries a standing note not to give it a route for an ordinary team until the resources are solved. The dialog says so beside the field.

#### `description` (4.12.8)

Set in the create dialog and then unreachable until now: there was no field in the manage dialog, and `TeamService::updateTeamDescription()` gated on a **raw** `level >= 4` row on the sub-team itself, which an admin of the main team who did not create it does not have. That gate is `requireModeratorLevel()` now, which is identical for an ordinary team and accepts an admin of the **governing** team for a sub-team (DESIGN §2.182). `''` clears it, and is a change rather than an omission — the controller tests with `isset()`.

#### `expiresOn` (4.12.9)

`"YYYY-MM-DD"`, or **`null` to clear** — read with `array_key_exists()` rather than `isset()`, since `null` is the value that clears a date and `isset(null)` is false. The one field on this route where the two differ.

A sub-team's date is the parent administrator's to set (DESIGN §2.183): a sub-team has no Manage team of its own, so Maintenance — Nextcloud-admin only — was the only screen that could put one on it, while its own administrators could already *request* more time. `TeamExpiryService::setExpiry()` stays NC-admin gated; this path uses `setExpiryForSubTeam()`, which trusts its caller as `extendByServiceTeam()` already does. **The cost, as for the policy profile:** a date a Nextcloud administrator set on a sub-team can be changed or cleared by the parent's admin.

Eligibility is the **template's** and is unchanged — Collaboration and Project only. A date on any other template is **400** *Only Collaboration and Project teams can have an expiration date.*; `GET /api/v1/teams/{teamId}/sub-teams` carries `expiryEligible` so a client can show the reason instead of a field the server would refuse.

#### `templateKey` and `profileKey` (4.12.6)

**A team administrator changing a policy profile is this app's one exception**, and it is deliberate (Justin, 2026-10-12; DESIGN §2.180). Everywhere else reclassification is a Nextcloud administrator's act — `POST /api/v1/admin/policy/teams/{teamId}` — because a team admin reclassifying their own team is the bypass DESIGN §2.103 records. A sub-team is different in two ways that make the exception hold: its parent's administrator **already picks both at creation**, from the full set, so the only thing refusing this would buy is forcing them to delete the sub-team and make it again; and a sub-team has **no module switch anywhere**, so its template is the only lever it has. What it costs is stated rather than hidden: a Nextcloud administrator who reclassifies a sub-team can be overruled by its parent's admin, and the assignment row therefore records `sub_team` as its `source`, distinct from both `creation` and `assignment`.

Both keys are validated **before either is written**, so a body naming a real template and a profile that does not exist changes nothing at all. A key equal to what the sub-team already carries is not re-applied: `apply` writes an assignment row and an audit event, so re-applying on every submit would fill the trail with changes nobody made.

The response carries what the template change actually did, and is absent when neither key was sent:

```json
{ "success": true, "id": "…",
  "classification": { "templateKey": "project", "profileKey": "confidential",
                      "added": ["deck", "decisions"], "removed": ["talk"],
                      "manual": ["files"], "errors": [] } }
```

- `added` / `removed` — apps and feature modules brought in line with the new template, through `PolicyPropagationService::applyTemplateToTeam()`, the same writer an administrator's rollout uses. **A removal destroys the resource**: the Talk room, calendar, Deck board, Intravox space or Collective TeamHub created for the old template is gone, which is why the dialog states it before the button is pressed.
- `manual` — what was **not** removed although the new template no longer asks for it: the team folder above all, which is reported for somebody to remove by hand and never deleted (`PolicyPropagationService::DESTRUCTIVE_ON_REMOVE` omits `files`, and that omission is the safety property). Also anything TeamHub did not create.
- `errors` — including the template key itself when the apps work threw: the label and the assignment are already written and correct, and losing them because a Deck board would not create is the worse outcome, so this reports rather than throws. Same stance `createSubTeam()` takes past its step 6.

**No compliance surface, by decision.** Nothing here reports conformance and the admin Compliance tab gains no sub-team row: a sub-team has no settings page of its own, so there is nothing for its administrator to bring back into line and nothing to report at them (Justin, 2026-10-12). `PolicyService::labelledClassificationFor()` is the ungated, drift-free twin of `classificationForTeams()` for exactly that reason.

Pinned by `tests/Unit/SubTeams/SubTeamClassificationTest.php` and `tests/js/subTeamClassification.test.mjs`.

### `DELETE /api/v1/teams/{teamId}/sub-teams/{subTeamId}` (4.12.5)

Deletes the sub-team and everything keyed on it, through `TeamService::deleteTeam()` — the one team-destroy path, so a sub-team is never destroyed differently from a team. Answers `{ "success": true }`.

### `GET /api/v1/teams/{teamId}/sub-teams/{subTeamId}/members` (4.12.5)

The shape `GET /api/v1/teams/{teamId}/members/manage` returns — `direct`, `groups`, `circles`, `inherited`, `effective_count` — with one field added to each person in `direct` and `inherited`:

```json
{ "direct": [ { "userId": "jaap", "displayName": "Jaap Tel", "role": "Member",
                "level": 1, "status": "Member", "fromParent": true } ], "…": "…" }
```

`fromParent` is whether this person is on the main team's effective roster: `true` means they were picked out of it, `false` means they are a guest invited from outside. It is computed from the parent's roster read **once**, not per person.

Each person also carries `subline` — the administrator's *Telling people apart* fields (`PersonSublineService`), resolved in one call for the whole roster. `GET …/sub-teams/candidates` carries it too. Neither did before 4.12.5: `getAllEffectiveMembers()` returns the members-widget shape (email, phone, live status) and adds no subline, so the two sub-team pickers were the only person pickers in the app showing a bare display name. Resolved with `adminContext: false`, so a profile field its owner marked private stays private — a team admin is not a Nextcloud administrator.

Admin-gated through `getMembersForManage()` deliberately, rather than `getAllEffectiveMembers()`: the latter gates on `requireMemberLevel()`, which has no parent-admin fall-back and would refuse the very administrator this panel is built for.

### `POST /api/v1/teams/{teamId}/sub-teams/{subTeamId}/members` (4.12.5)

```json
{ "members": ["jaap"], "outsiders": [ { "id": "consultant", "type": "user" } ] }
```

The same two lists `POST …/sub-teams` takes, validated by the same rule and answering the same two **400**s. Returns `{ "success": true, "results": […] }` — the per-entry results `inviteMembers()` produces, passed through rather than swallowed: Circles authorises its own `addMember()` against the initiator's rights on the circle, so a parent admin with no row on the sub-team may see entries fail and the panel has to be able to say so.

**4.13.3 — an `outsiders` entry may be a Nextcloud group:** `{ "id": "finance", "type": "group" }`. Nothing in the rule changed to allow it. `splitMembership()` has only ever tested the **local-user** type against the parent's roster, because a group is never on one — so a group whose gid happens to match a main team member's uid is still accepted, and the two refusals above are unchanged for people. The group becomes one `circles_member` row of `user_type = 2`; the people inside it reach the sub-team through it and come back from the GET above under `inherited`, not as rows of their own.

Whether groups are offered at all is the instance's decision, not this endpoint's: `GET /api/v1/users/search` already filters by the administrator's invite-type policy and by Nextcloud's own `shareapi_allow_group_sharing`, so on an instance where either is off no group is ever found to send.

### `DELETE /api/v1/teams/{teamId}/sub-teams/{subTeamId}/members/{userId}` (4.12.5)

The uid in the path, as `team#removeMember` already does for a team's own members. Straight through `MemberService::removeMember()`, which is the point: leaving a team has a tick list — the resource-membership reconcile, the message subscriptions, the outstanding file reviews — and a second removal path would be a second place for it to fall out of date. That method also refuses to remove an **owner**, which is what protects the admin who created the sub-team; the UI hides the button rather than offering one that fails.

**`?type=group` (4.13.3)** removes an invited group instead of a person, and then **the id in the path is the group's `singleId`** — the `groupId` the GET above returns — not its gid. `circles_member` keys a row on `(circle_id, single_id, user_type)`, so the type is as load-bearing as the id; before 4.13.3 the type was hardcoded to a person and a group could be added here and never removed. The same query parameter and the same mapping `team#removeMember` has always taken. `circle` is deliberately not accepted: a team cannot be invited into a sub-team in the first place, and any other value **400**s.

## Changed by sub-teams (4.12.2)

- **`GET /api/v1/teams`** now returns the **nested** shape: each team may carry `sub_teams: […]`. Every row also gains `is_sub_team`, `parent_team_id`, `sub_team_visibility` and `can_have_sub_teams`.

  `parent_team_id` is sent **only when the caller also has the parent in the same response**. A person invited into a sub-team from outside the main team gets the sub-team as an ordinary **top-level** row with `parent_team_id: null` — they are told nothing about the parent, not even that it exists.

  A `listed` sub-team the caller is *not* a member of appears inside its parent as `{ id, name, listed_only: true, level: 0, … }`. Those rows exist in this response and nowhere else: they carry no My Work scope, no Files-action scope and no file-review scope, and every team-scoped route still refuses them with the usual *You are not a member of this team*.

  `GET /api/v1/teams/{teamId}` gains the same four fields (flat — no `sub_teams`), under the same disclosure rule for `parent_team_id`.

  **With the module unavailable (4.12.3):** `GET /api/v1/teams` carries **no sub-team rows at all** — neither
  nested nor top-level, so an invited outsider who is in no other team gets an empty list — and
  `can_have_sub_teams` is `false` on every row of both responses. The three *structural* fields stay truthful on
  the rows that remain: a sub-team whose module is off is still a sub-team, and its parent's admins are still its
  only administrators.

  **`sub_teams_unavailable_reason` (4.12.5)** — `null`, `"unlicensed"` or `"disabled"`, on every row of both
  responses. It is what the Manage team → Sub-teams tab is **greyed** on rather than hidden (DESIGN §2.179):
  `can_have_sub_teams` is one boolean over two different situations, and a client deriving which would be a second
  copy of a precedence rule `SubTeamModuleService::unavailableReason()` owns. **`null` on a sub-team's own row**
  as well as on a healthy instance — the depth rule is not advertised, and `is_sub_team` beside it is how a client
  tells those two apart: `is_sub_team` true means hide the tab, a non-null reason means grey it and say which.

  **Deliberately unchanged by the switch:** My Work, the Files-app actions and the file-review scopes all read the
  flat, unfiltered list behind these payloads, so a member of a sub-team keeps every open work item and review while
  the module is off. Nothing is deleted, and switching back on restores every surface.

- **`GET /api/v1/teams/browse`** never lists a sub-team. Its membership is hand-picked by the parent's admin, so it must not appear anywhere anyone can join from.

- **`PUT /api/v1/teams/{teamId}/group`** answers **400** *A sub-team follows its main team in the sidebar and cannot be moved on its own.* A sub-team renders inside its parent's row and already follows it into whatever personal group the parent is filed under.

- **`PUT /api/v1/team-groups/teams/{teamId}/expanded`** (new) — body `{ "expanded": true|false }`. Remembers whether a team's sub-teams are showing, per reader, in the same `oc_preferences` blob as the sidebar groups. Member-gated, for the same reason `…/group` is. Returns the whole grouping state, which now also carries `expandedTeams` (teamId → `true`; a team with no entry is collapsed).

- **Admin reach over a sub-team.** `MemberService::requireAdminLevel()` and `requireModeratorLevel()` now pass for an **admin of the parent**, so every team-scoped route a sub-team has reaches its parent's admins.

  **`DELETE /api/v1/teams/{teamId}` was widened in 4.12.5, not 4.12.2.** The 4.12.2 entry here claimed it and the archive routes had been, and neither had: `SubTeamService::isParentAdmin()` ended up with no caller outside its own class, so until 4.12.5 only the admin who created a sub-team could delete it. The delete now accepts an admin of the governing team. **This is a widened permission boundary: a parent admin can delete a sub-team they do not own.**

  **Still not widened: the archive routes.** `ArchiveService`'s two level-9 reads are unchanged, so archiving a sub-team is the creating admin's alone. That is Ship 3's business, which is where the archive cascade lives.

- **What a sub-team is refused, now enforced (4.12.5).** §2.175 recorded four refusals and only one of them — being filed into a personal sidebar group — had code behind it. The other three are now written:

  | Route | Answer |
  |---|---|
  | `POST /api/v1/teams/{teamId}/transfer-owner` | **403** *A sub-team is governed by its main team, so its ownership cannot be transferred.* Inside `requireOwnerLevel()`, whose only caller this is; the sub-team's creator **is** its owner and would otherwise pass. |
  | `POST /api/v1/teams/{teamId}/invite-members` with a `circle` entry | the per-entry result reads `failed: a sub-team cannot be added as a member of another team`. Circles propagates membership **upward**, so such a row hands `{teamId}`'s files, Talk room and calendar to the sub-team's invited outsiders. |
  | `GET /api/v1/users/search` | never returns a sub-team. That query has no membership or visibility filter, so it had been disclosing every private sub-team's name to an admin of any team. |
  | the CSV importer's `team:<name>` token | dropped in the preview with *"…" is a sub-team and cannot be added to another team* — the same refusal, named in a row rather than failing silently after the run. |
  | publishing a service / claiming the Nextcloud services | **400** *A sub-team cannot answer service requests. Use its main team instead.* Both entry points, because either makes the team a desk. |

  `inviteMembers()` is the **boundary** for the member-circle rule; the search exclusion and the importer's warning are affordances, since search results go stale and the invite route is reachable directly. Pinned by `tests/Unit/SubTeams/SubTeamExclusionsTest.php`, which asserts the call sites exist — written against the source on purpose, because a decision with no caller is what went wrong twice.

---

## Creating a team (changed 4.10.26)

`POST /api/v1/teams` now answers **403** `{ "error": "You are not allowed to create teams" }` when the caller is outside the *who may create teams* groups (`createTeamGroup`; empty = everyone), checked before anything is read or written. Before 4.10.26 only the sidebar button, the OpenProject search and the provisioning routes asked, so a member the policy excluded could create a team with one POST instead of using *Request a new team*. The same rule `GET /api/v1/user/can-create-team` reports, so the UI and the route cannot disagree.

## Which circles are teams (changed 4.10.6)

No endpoint was added or removed. Every team-scoped endpoint now answers for **teams TeamHub created** only (a row in `teamhub_team_registry`, written by `TeamService::createTeam()`): `GET /api/v1/teams` and `GET /api/v1/teams/browse` list registered teams, `GET /api/v1/teams/{teamId}` and `…/preview` answer **404** for a circle made in Contacts, Collectives, `occ` or another app, and every gate in `MemberService::require*Level()` — the membership check behind the team-scoped routes — throws *Team not found* for one, so the usual 403/404 of the calling controller applies. Teams that existed at the upgrade were registered by the migration. DESIGN §2.136.

## Person search (changed 4.10.7)

One row shape for every person search, from `PersonSearchService`:

```json
{ "id": "jfeet2", "type": "user", "displayName": "Jari Feet", "subline": "Controller · Finance", "icon": "user" }
```

- `GET /api/v1/users/search?q=&teamId=` — unchanged filters (Nextcloud's collaborator search + TeamHub's invite types); every `user` row now carries `subline`. Rows of type `group` / `circle` / `email` / `federated` carry `subline: ""`.
- `GET /api/v1/admin/users/search?q=` (admin) — **breaking for its two callers, both updated:** rows are `{id, type, displayName, subline, icon}` instead of `{uid, displayName}`; matches on display name **and** uid; the subline includes fields the person set to private.
- `GET /api/v1/teams/{teamId}/members/all` — each member row gains `subline`.
- `GET|POST /api/v1/admin/settings` — new `personSublineFields` (comma list, canonical order; `none` stored for an empty choice) and read-only `personSublineFieldsAvailable` (`role, organisation, headline, manager, groups, email, uid`).

The line is `PersonSublineService`'s: the administrator's fields in order, the first two filled in, groups as one value of up to three names. DESIGN §2.137.

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

**`teamexpiry_team` declares two categories from 4.6.17** — `action_required` while the team can still act (`team_admin` until 4.10.20, when that category was folded into this one), and `waiting_for_others` from the moment an extension request is in flight. The second is not cosmetic: `MyWorkService::applyUrgency()` promotes any dated item into `action_required` as its deadline nears, and `waiting_for_others` is the one category it skips, so a team awaiting a decision is no longer escalated daily for something it cannot do. Those rows carry `waitingFor` naming the Nextcloud administrators group.

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

**`teamadmin` gains a second resource type in 4.6.17** — `team_join_request`, status `join_requested`, alongside the existing `team_resource` / `resource_pending_review`. It carries somebody's pending request to join a team into that team's admins' queue, under `action_required` (`team_admin` until 4.10.20), with `approve` and `reject`. Item ids are `joinreq:{teamId}:{uid}`, told apart from a resource's `{teamId}:{appId}:{resourceId}` by the leading marker. Both verbs route through `MemberService::approveRequest` / `rejectRequest`, which re-check the caller's team level independently — a request already decided returns `409 conflict`, not an error.

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
| `GET /api/v1/admin/mywork/config` | Horizons (including `actionRequiredDays`), cache TTL, request budget, approval expiry thresholds, and the bounds the UI constrains its inputs to. **`categoryMap` and `defaultCategoryMap` were removed in 4.10.20** — a category is decided by the provider that emits the item, not by an instance-wide status→category table (DESIGN §2.143). `PUT` ignores a `categoryMap` in the body |
| `PUT /api/v1/admin/mywork/config` | Any subset of the above. Out-of-range numbers are **clamped, not rejected** |
| `GET /api/v1/admin/mywork/status` | Every registered provider with availability, unavailability reason, capabilities, allowed actions, last successful sync, last error, and `diagnostics`. **`talkThreading` was removed in 4.10.20** — it was a Talk fact on a My Work endpoint, rendered as raw reflection output on a tab it did not belong to. `TalkService::getThreadingDiagnostics()` remains for a debug session |
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

### Team spaces (added 4.10.1, Nextcloud 35 only)

On a Nextcloud that offers a team-folder provider (35 with Team folders), every `files` row whose `resourceId` starts with `gf:` carries two more fields; on 33/34 neither is present.

| Field | Meaning |
|---|---|
| `isTeamSpace` | `true` when the folder is this team's own Nextcloud team space (`group_folders.team_circle_id` = the team), `false` for a plain team folder |
| `quota` | The space's quota in bytes, `0` for unlimited, `null` when the folder is not the team's space |

`GET /api/v1/apps/check` carries **`teamSpaces`** (`bool`): whether the running Nextcloud offers team spaces. Manage team labels the create button and the folder badge by it.

`POST /api/v1/teams/{teamId}/resources/files/connect` answers **400** with `error` when the folder is another team's space — Team folders refuses to share a space, and a row the team cannot open is never written. The `success` payload carries `team_space` (`bool`): whether the folder became the team's space on connect (it does when it is applicable to this circle alone and the team has no space yet).

No endpoint of its own for the conversion: it runs in `TeamSpaceReconcileJob` (daily cron), the administrator items are My Work rows of the `teamspace_admin` provider, and the notifications use the existing notifier. The hand-over rides `POST /api/v1/mywork/action`: `delegate` on a `teamspace_admin` shared-folder row (optional `params.reason` = the note to the owner) hands it to the team owner, whose own row (`teamadmin`, item `teamspace:{teamId}:task`) takes `complete`; `complete` on the administrator's row then closes it. Rows may carry `metadata.actionLabels` (a row's own label for a shared action), `metadata.confirm[action]` (`{title, body, reasonLabel?}` — the row scripts its confirmation dialog) and `metadata.steps` (a numbered procedure); all three are optional and generic. TeamHub also registers `OCP\Teams\ITeamResourceProvider`, so core's `GET /ocs/v2.php/teams/{teamId}/resources` lists the team's TeamHub home.

### Team-space quota request (added 4.10.2; a Nextcloud service since 4.10.29)

**Removed 4.10.29:** `POST /api/v1/teams/{teamId}/team-space/quota-request`, the `teamspace_admin` / `quota:{teamId}` and `teamadmin` / `quota:{teamId}:request` My Work rows, and `quotaRequests` in `GET /api/v1/apps/check`. The quota request is a Nextcloud service on the workflow engine — see *Service Teams → the quota request* below.

Every `gf:` resource row that is the team's space still carries **`quotaRequest`** — now `{ workflowId, status: pending|answered, requestedBytes, requestedAt }` for the team's open engine request (`answered` = waiting for the requester to close it), or `null`. Manage team shows its state instead of the ⋯ menu by it.

**Workflow rows** (the ledger-era workflows, every party) carry `metadata.workflow = { steps: [{ label, state: done|current|pending, actor, at }], current }` — the whole workflow with the row's position in it, computed by the owning service; the row's disclosure renders it as a tracker. Optional and generic like `actionLabels`, `confirm` and `steps`.

`POST /api/v1/teams/{teamId}/resources/{app}/{resourceId}/dismiss` deletes a pending row whose resource is gone or detached. Team admin required. The verdict is **recomputed server-side**, not taken from the request: a row that has become available again is refused with `400` and `{"error": "resource_available_again"}` so the client can refetch and offer Accept / Ignore instead. Dismissing drops the row from the `pending` count that feeds the Team info "N resources need review" strip.

---

## WorkflowHub endpoints (added 4.10.14)

The built-in workflows over the workflow engine (`docs/workflowhub-architecture.md`). Every route is `#[NoAdminRequired]` and **CSRF-protected, reads included** — no `#[NoCSRFRequired]` anywhere on the controller; `@nextcloud/axios` sends the request token. **No licence gate on the routes**: usable on licensed and unlicensed instances alike, and **no route ever refuses an action on a workflow that exists because a licence expired** — see § The licence below. The My Work endpoints above are unchanged.

**The caller is the session user and nothing else.** No route and no body carries an actor, a participant, a step or a status. The engine acts on *the caller's own active step*, decides what the caller may see or do from the live roles (Circles level, group membership — resolved server-side at the moment of the call) and the participant rows, and ignores anything else a client sends (`data` is passed through the definition's `validateStart`, which keeps only the fields it knows).

**Who sees what.** A workflow is visible to its participants — the initiator, the holders of every step's actor (by uid, group membership, or team role on the instance's team), everybody who acted — and to Nextcloud administrators. Nobody else: `403`.

**Errors.** `401` no session · `403` not yours · `404` no such workflow or definition · `400` bad input · `409 {error, conflict: true}` the step or workflow is no longer in a state that allows this (the caller's view is stale — reload) · `429 {error, retryAfter}` + `Retry-After` header for a repeated status request · `500 {error, ref}` unexpected, with a correlation id and no detail.

### Reading

| Method & path | Answer |
|---|---|
| `GET /api/v1/workflows?status=` | `{ workflows: [ …view ], tier, capabilities }` — every workflow the caller takes part in, most recently updated first. `status` narrows to one of `submitted`, `in_progress`, `waiting`, `blocked`, `cancelled`, `rejected`, `completed`; on an unlicensed instance a terminal status answers `[]`. |
| `GET /api/v1/workflows/definitions` | `{ definitions: [ { key, version, name, concurrency, unlicensed, steps: [ { key, label, actor } ] } ], tier, capabilities }` — the built-in workflows a client may offer, step labels in the caller's language. An unlicensed instance lists only those with `unlicensed: true`. Whether *this* caller may start one on *a* team is decided at start. |
| `GET /api/v1/workflows/{id}` | `{ workflow: …view + history, tier, capabilities }`. `history` is the event log, oldest first: `[ { type, stepKey, actorUid, occurredAt, payload } ]`; on an unlicensed instance it is the **basic timeline** (fewer event types, `stepId` and `stepKey` null). **404 for an ended workflow.** |

### Starting

`POST /api/v1/teams/{teamId}/workflows/{definitionKey}` — body `{ "data": { … } }`, the definition's opening payload. `201 { workflow }`. The definition decides who may start (team request: any effective member of `{teamId}`; team-space quota: level ≥ 8) and validates `data`. `409` when a workflow of this kind is already open on the team and the definition allows only one (`concurrency: one_open_per_team`).

**`404` when the definition is unknown *or not startable on this instance*** (v4.10.17). A definition may be registered but **dark** — its shape ships before the service behind it is wired — and a dark definition is left out of `GET …/workflows/definitions` and refused here with the same answer as an unknown key. `teamspace_quota` is dark until `workflow_engine_quota` is set to `1`, because the live quota request still runs on the ledger: before the gate existed this route answered `201` and created a parallel request the ledger could not see, whose `decide` step would have reported a quota grant that was never written.

**Rate-limited: ten a hour per user** (`#[UserRateLimit(limit: 10, period: 3600)]`, v4.10.17). `team_request`'s concurrency is `unbounded` — asking for several teams is legitimate — and every start notifies the requesting team's owner and moderators, so without a cap one member could ring their bell arbitrarily often. Exceeding it is Nextcloud's own `429`, not a workflow error body.

### Acting on the caller's active step

All `POST /api/v1/workflows/{id}/…`, all answer `200 { workflow }` with the updated view.

| Path | Body | Who | Effect |
|---|---|---|---|
| `complete` | `{ note? }` | a holder of the active step's actor | Completes the step (an *available* step is started and completed in one go). The next step becomes available and its holders are notified; completing the last step completes the workflow and every named participant is notified. |
| `reject` | `{ reason }` required | a holder of the active step's actor | Rejects the step: workflow `rejected`, the steps never reached `skipped`. |
| `request-information` | `{ note }` required | a holder of the active step's actor | Step → `waiting_for_information`, workflow → `waiting`; the initiator is notified. |
| `provide-information` | `{ note }` required | any participant | Only on a waiting step (`409` otherwise): step → `in_progress`; the step's holders are notified. |
| `cancel` | `{ reason? }` | the initiator, or a Nextcloud administrator | Workflow `cancelled`; the active and pending steps `cancelled`; named participants notified. |
| `status-request` | `{ note? }` | a participant who is **not** a holder of the active step (the holder gets `400`) | **Changes no status.** Records a `status_requested` event and notifies every holder of the active step. Rate-limited twice: once per participant per step per 24 h in the engine (`429`, `retryAfter` seconds), and at most 10 calls per user per hour at the edge (`#[UserRateLimit]`). |
| `message` (v4.11.0) | `{ note, fileIds? }` — note required | on a **service request** only: the requester, or on the desk's side whoever claimed it (or an admin of the service team); everybody else `403`, and so is a desk member on an unclaimed request | **Changes no status.** Records a `message` event (`visibility = all`) while the request is open (`409` once it has ended). Notifies the other side: the requester's message → whoever claimed it, **nobody** while it is unclaimed; the desk's → the requester (and the claimant, when a team admin wrote it). Each recipient's earlier `workflow_message` notification about the request is withdrawn first, so the bell holds one per request. Licensed with the service-teams module (`403` otherwise). At most 60 calls per user per hour (`#[UserRateLimit]`). |

### The workflow view

```json
{
  "id": 12,
  "definitionKey": "team_request",
  "definitionVersion": 1,
  "title": "Team request: Marketing",
  "teamId": "aBcDeF…",
  "subject": { "type": "team_request", "id": "Marketing" },
  "status": "in_progress",
  "outcome": null,
  "currentStep": "approve",
  "startedBy": "jaap",
  "startedAt": 1790000000,
  "updatedAt": 1790000001,
  "endedBy": null,
  "endedAt": null,
  "teamName": "Sales",
  "description": "Campaign work needs its own space.",
  "data": { "teamName": "Marketing", "reason": "Campaign work needs its own space." },
  "people": { "jaap": "Jaap Tel" },
  "steps": [
    { "key": "submit",  "order": 1, "status": "completed", "label": "Request submitted",                        "actor": { "type": "user", "id": "jaap" },         "enteredAt": 1790000000, "startedAt": 1790000000, "completedAt": 1790000000, "completedBy": "jaap", "actionTaken": "complete", "reason": null },
    { "key": "approve", "order": 2, "status": "available", "label": "Team owner or moderator approves",         "actor": { "type": "team_moderator", "id": "" },   "enteredAt": 1790000001, "startedAt": null, "completedAt": null, "completedBy": null, "actionTaken": null, "reason": null },
    { "key": "process", "order": 3, "status": "pending",   "label": "Nextcloud administrator creates the team", "actor": { "type": "group", "id": "admin" },       "enteredAt": null, "startedAt": null, "completedAt": null, "completedBy": null, "actionTaken": null, "reason": null },
    { "key": "confirm", "order": 4, "status": "pending",   "label": "Requester confirms",                       "actor": { "type": "user", "id": "jaap" },         "enteredAt": null, "startedAt": null, "completedAt": null, "completedBy": null, "actionTaken": null, "reason": null }
  ],
  "participants": [
    { "actor": { "type": "user", "id": "jaap" },          "role": "initiator",   "addedAt": 1790000000 },
    { "actor": { "type": "team_moderator", "id": "" },    "role": "responsible", "addedAt": 1790000000 },
    { "actor": { "type": "group", "id": "admin" },        "role": "responsible", "addedAt": 1790000000 }
  ],
  "responsible": { "type": "team_moderator", "id": "" },
  "purged": false,
  "viewer": { "isParticipant": true, "isResponsible": false, "canAct": false, "canCancel": true, "canRequestStatus": true }
}
```

Actor types: `user` (uid), `group` (Nextcloud group id; `admin` = the administrators), `team_owner` (level 9 of the instance's team), `team_moderator` (level ≥ 4), `team` (any effective member). `viewer` is computed for the caller and is a rendering hint only — every action re-checks. Row ids of steps and participants, retention and removed participants are not exposed.

**Added 4.11.0** (the conversation on a service request): `viewer.canMessage` — the caller may `POST …/message` now; `viewer.messageSide` — `'requester'`, `'desk'` or `null`; `viewer.canAskRequester` — the caller's message may instead be sent as `request-information` (their own claimed desk task, not already waiting); `viewer.answersQuestion` — the caller is the requester and a desk task is waiting for their answer, so their next message goes as `provide-information`. All four are `false`/`null` on a workflow no service team handles.

**Changed 4.10.17:** `viewer.canRequestStatus` now also honours its own cooldown — one ask per person per step per 24 hours — so it is `false` while the caller is inside that window and the client stops offering a button whose press would answer `429` (CLAUDE.md § Permissions: hidden, not left to fail). One batched query serves a whole list, so this costs no extra request per row.

**Added 4.10.16** (the licence tier): `responsible` — the active step's actor, `null` once the workflow has ended — which is the field a client reads to say who is up, **on both tiers**; and `purged: true` on the single view returned by the call that ended a workflow on an unlicensed instance (the workflow is deleted by then; every later read answers 404).

**Added 4.10.15** (for the My Work workflow sections): `teamName` (the requesting team's display name, `""` when unknown), `description` (the definition's sentence for the instance — the team request's reason), and `people` — `{ uid: displayName }` for every uid the view mentions (initiator, step actors and completers, user participants; on `GET /workflows/{id}` also the history's actors). Only uids the caller was already given are resolved; the client shows names, never looks them up.

### The reference workflow — request a new team (`team_request`)

`POST /api/v1/teams/{teamId}/workflows/team_request` with `{ "data": { "teamName": "Marketing", "reason": "…", "templateKey": "project", "profileKey": "internal" } }`. Steps: `submit` (done at creation, in the requester's name) → `approve` (owner or moderator of `{teamId}`; `complete` or `reject`) → `process` (**the service team that holds the Nextcloud services**, resolved when the request is created and frozen onto the step row — v4.10.23, no longer a configured group) → `confirm` (the requester) → completed. Several may be open per team. The team itself is created by the service team with the tools they have; the workflow carries the request and the answers, no side effects.

**v4.12.4 — `templateKey` and `profileKey`.** Both optional, both validated against `teamhub_template` / `teamhub_policy_profile` (`400` for a key that does not exist), and both stored with the label they carried when the request was made (`templateLabel`, `profileLabel`) so the record keeps the words the requester read; the two labels are appended to the workflow's `description`. Omitted, the request is a request without a shape asked for — which is every request made before this version, and what either form sends when its defaults fetch failed. Both start points ask for them: the sidebar's *Request team* dialog and the `new_team` card on the Services page.

**v4.12.4 — `{teamId}` for a new team is normally the desk.** The request forms no longer ask which team the request comes from (a new team is asked for by a person), so they send the service team's own id, the personal-request form of `teamOptional` below; `canStart()` admits any signed-in account on that id, and a member of `{teamId}` on any other.

No admin setting. `workflowTeamRequestGroup` was removed in v4.10.23 with the configured processing group it named (`TeamService` line ~2250 records it); who answers a team request is decided by which service team holds the Nextcloud services, in Admin → TeamHub → Service desk.

### The licence (added 4.10.16)

The instance is licensed or it is not — never per user, team or workflow. Every read carries `tier` (`"full"` | `"basic"`) and `capabilities`, a flat `{ capability: bool }` map, so a client **hides** what this instance does not have instead of offering it and failing. The capabilities and the full data lifecycle are in `docs/unlicensed-workflow-data-lifecycle.md`.

What a `basic` (unlicensed) answer differs in:

| | `basic` | `full` |
|---|---|---|
| `steps` | `[]` | the step rows |
| `currentStep` | `null` | the active step's key |
| `responsible` | the active step's actor | the same |
| `viewer.canRequestStatus` | always `false` | as computed |
| `history` | the basic timeline: `created`, `step_completed`, `step_rejected`, `information_requested`, `information_provided`, `status_requested`, `blocked`, `unblocked`, `completed`, `rejected`, `cancelled`, each with `stepId`/`stepKey` null | every event |
| `GET /workflows/{id}` on an **ended** workflow | `404` | the view |
| `GET /workflows?status=completed` | `[]` | the ended workflows |
| `GET /workflows/definitions` | only `unlicensed: true` | all |

Two calls, and only these two, can answer **`403 { error, licenseGate: true, enforcementLevel }`** — the shape `MyWorkController` and the Budget/Time routes already use:

- `POST /api/v1/teams/{teamId}/workflows/{definitionKey}` when the definition is not allowed for unlicensed use;
- `POST /api/v1/workflows/{id}/status-request`, which is licensed.

`complete`, `reject`, `request-information`, `provide-information` and `cancel` **never** carry a licence check, at the controller or in the engine: a workflow that exists can always be finished, whatever the licence did in the meantime.

**Completion on an unlicensed instance deletes the workflow** in the same transaction that ends it. The ending call returns the last view once (`purged: true`); after it the workflow is 404 for everybody including administrators, is in no list, and has no archive or result record anywhere.

Notifications (`Notifier.php`, per recipient language, object `workflow/{id}`): `workflow_step_available` to the holders of a step that just became available (minus whoever handed it over), `workflow_status_requested`, `workflow_information_requested` (initiator), `workflow_information_provided` (holders), `workflow_ended` (every named participant), and since 4.10.20 `workflow_step_assigned` to the one agent a request was handed to. A step moving on withdraws the instance's earlier notifications. Recipients of a team-role actor are the team's *direct* members at that level.


### Service Teams (v4.10.20, WorkflowHub phase 5)

**v4.10.27 — the desk works from its own team's home** (`/service-teams`). The global *Service desk* page (removed 4.10.26) is replaced by two widgets on the service team; what changed on the wire:

| Endpoint | Change |
|---|---|
| `GET /api/v1/service-teams` | Each desk adds `unclaimedCount` — the navigation badge behind the team's name |
| `GET /api/v1/service-teams/{teamId}/queue` | Adds `closed`: requests whose service step finished in the last 30 days, newest first, each with `desk: { status, closedAt, closedBy }` (`completed` = answered, `rejected`, `cancelled` = withdrawn by the requester) |
| `GET /api/v1/service-teams/{teamId}/statistics?days=30` | **New.** `{ statistics: { days, since, totals, services } }`. `days` is 7, 30 or 90 (else **400**). `totals` and every `services[]` entry carry `received`, `claimed`, `closed`, `withdrawn`, `open`, `unclaimed`, `medianFirstClaim`, `medianClose` (seconds, `null` when nothing was measured); services add `serviceKey`, `label`. Members of the service team only (**403** otherwise); licensed |
| `POST /api/v1/workflows/{id}/assign` | **Team admins only** — a member gets **403**. `internal.canAssign` on every row says so in advance |
| `GET /api/v1/teams/{teamId}/layout` (bundle) | Adds `serviceDeskConfig: { isDesk, isAdmin }` — gates the two widgets; `widget-service-queue` and `widget-service-stats` are accepted layout ids |

The team's activity feed (`GET /api/v1/teams/{teamId}/activity`) now includes the desk's state changes for members of a service team: rows with `app: "teamhub"`, `subject` one of `service.request_received|claimed|assigned|released|answered|rejected|cancelled`, and `subjectparams: { title, to_user, to_name }`.

A **service team** is a team created from the **Service** template that answers requests, with a catalogue of services each wired to one built-in workflow definition. Everything here is **licensed** — every route below answers `403` with `{"licenseGate": true, "enforcementLevel": "…"}` on an unlicensed instance, and there is no reduced version. A workflow that is already running is unaffected: no transition verb reads the licence.

**v4.10.23 — the desk's people are the team's people.** The roster is gone. **Who is eligible** to work a desk's queue: any member of the service team, directly or through a group that is a member of it. **Who owns the service** (may reconfigure it, and may take a request off another agent): a **team admin**, Circles level ≥ 8, resolved live — `isServiceOwner` still carries it under that name. A Nextcloud administrator is **not** eligible by being one. The rule lives in `ServiceTeamService::isEligibleAgent()` and nothing else decides it.

| Endpoint | Purpose |
|---|---|
| `GET /api/v1/service-teams` | The desks the caller may work, each with `teamId`, `teamName`, `isServiceOwner` and its `catalogue`. Empty for everybody who is not an agent — not an error |
| `GET /api/v1/service-teams/catalogue` | Every service any **active** desk offers: `serviceKey`, `definitionKey`, `label`, `description`, `category`, `categoryLabel`, `leadTime`, `serviceTeamId`, `serviceTeamName`. One payload for the whole Services page — A–Z, the grouping by team and the category tiles are all derived from it client-side (v4.10.25, DESIGN §2.147) |
| `GET /api/v1/service-teams/claim-status` | `{ available, claimed, holderName, holderTeamId, availableServices }` — whether this instance has the Nextcloud services and whether a team already holds them. Any signed-in account: it is what the creation wizard's checkbox reads before the team it would belong to exists, and what the sidebar's *Request team* opens its request against. It discloses the holding team's **name and id** (v4.12.4) and nothing else |
| `GET /api/v1/service-teams/{teamId}/queue` | `{ unclaimed, mine, others, withOthers, closed }` — `unclaimed` / `mine` / `others`: the open workflows whose active step is this desk's; **`withOthers`** (v4.10.31): this desk's open workflows whose active step is somebody else's — back with the requester for a planned action or the confirmation, or before the desk's step; `closed`: the desk's part ended in the last 30 days. Eligible agents only (`403` otherwise) |
| `GET /api/v1/service-teams/{teamId}/requests` *(4.11.7)* | The desk's whole history, filtered: `{ requests, total, truncated, agents, requesters }`. Query: `state` (`open` \| `closed` \| `all`, default `all` — **the desk's** reading, as the queue's Closed tab is: a request whose desk step is done is closed here while the workflow still waits for the requester to confirm), `assignee` (a uid, or `unassigned`), `requester` (a uid), `q` (≤ 200 chars, over the title and the requester's own words), `offset`, `limit` (≤ 100, default 25). Each row is a workflow view plus `desk: { open, assignee, status, closedAt, closedBy }`. No time window — this is what a member reads to find a request they never worked. `agents` and `requesters` are the two pickers' values, computed over everything the grid can see so narrowing one never empties the other. Bounded by `WorkflowEngine::DESK_GRID_MAX` (500, newest first); `truncated` says the oldest were dropped. Eligible agents only (`403` otherwise) |

Four verbs act on one workflow's active step and live under `/workflows/{id}` because that is what they act on. Each returns `{"workflow": …}`, the same view `GET /workflows/{id}` gives.

| Endpoint | Body | Notes |
|---|---|---|
| `POST /api/v1/workflows/{id}/claim` | — | Takes an unclaimed request out of the queue and starts the step. `409` when somebody else already has it |
| `POST /api/v1/workflows/{id}/assign` | `{ uid }` | Hands it to an eligible agent; naming yourself is how you take it over. `400` when the target is not eligible |
| `POST /api/v1/workflows/{id}/release` | `{ reason? }` | Back to the queue. The assignee or the service owner only (`403` otherwise) |
| `POST /api/v1/workflows/{id}/internal-note` | `{ note }` | A note only the desk reads. Changes no status and moves no step |
| `POST /api/v1/workflows/{id}/decisions` | `{ decisionId }` | **v4.11.12.** Post a decision of the **handling service team** into the request's conversation, from its Messages tab. Four rules, all server-side: the decision belongs to that desk (`400` otherwise), is **approved** (`400`; `decided` counts), is **public** (`400` — the requester reads the conversation), and the caller can open it (`403`). The gate is the **message** gate, not the internal-note one: `messageSide() === 'desk'` — the claimant or an admin of the desk, on an open request (`403` for a colleague who did not claim it). Returns `{"decisions": […]}`, not a workflow view. Event `decision_linked` with **`visibility: all`**, carrying `decisionId`, `teamId` and the decision's `question` and `status` **as they were** (a snapshot, like an attachment's `fileName`); audit `decision_linked` with the id and no question. Idempotent: linking the same decision twice leaves one link and writes one event. **The server takes an id, never a URL** — a pasted link is resolved to an id in the client |
| `POST /api/v1/workflows/{id}/close-request` | `{ note? }` | **v4.10.31.** An **admin of the handling service team** closes the request, at any step (`403` for anybody else, and for a definition that is not closable — the quota request and the team request, whose desk step does something). At the requester's confirmation it completes that step (`outcome: "completed"`); before it, the workflow ends `status: "completed"`, **`outcome: "closed"`**, the active and later steps `skipped`, event `closed` with the note. Written to the desk's activity stream as `service.request_closed` |

**v4.11.12 — the decisions behind a request** (Justin, 2026-09-30). Three more routes, and the flag they rest on:

| Endpoint | Notes |
|---|---|
| `GET /api/v1/workflows/{id}/decisions` | `{ decisions: [{ id, teamId, status, isPublic, canOpen, question, addedBy, addedAt }] }`. Every eligible agent of the handling desk, **including on a closed request** — the grid is the desk's whole history. `canOpen` is the answer for *this* caller and **`question` is `''` when it is false**: that a request was decided is the desk's record, the decision's words are the Decisions module's to give out. A link whose decision has been deleted is left out rather than returned as a broken row |
| `DELETE /api/v1/workflows/{id}/decisions/{decisionId}` | Takes the decision off the request; the decision itself is untouched. Same gate as linking. Event `decision_unlinked`, also `visibility: all` — the conversation said the decision was linked, so it has to be able to say it was taken off; **the entry that announced it stays**, because a conversation is a record and not a current state. Removing a link that is not there is not an error and writes nothing |
| `GET /api/v1/service-teams/{teamId}/public-decisions?q=` | The desk's own **approved, public** decisions whose question or answer contains `q` (≤ 200 chars), newest first, **at most four** (Justin: a picker is for recognising the decision you already have in mind), in the same row shape. Eligible agents of that desk only (`403`). Both narrowings match what `POST …/decisions` accepts, so the picker cannot offer what the link would refuse |
| `PUT /api/v1/teams/{teamId}/decisions/{decisionId}/public` | `{ public: bool }` → the serialised decision. Publishes it, or takes it back into the team. **v4.11.31 — gated on being an approver of the decision's category** (`assertApproverFor()`, `403`), in **both** directions. Justin, 2026-10-06: *only a category approver can set a decision in the category as public.* This replaces the team's `decisions_action_min_level`, which gated it from v4.11.12 and put publishing beside *Link decision* and *Link task* — the wrong comparison: attaching a link adds to the team's own record, while publishing moves the record **out of** the team, to every logged-in account on the instance, and that is a statement about what the decision *is*. Default 1 on the old gate meant any member could make it. Unpublish is gated the same way on purpose: publish-restricted and unpublish-open would let an ordinary member withdraw what an approver published. **`400` for any decision in the archive category**, which is team-only and has no publishing at all — its own refusal rather than the approver gate's, because the archive has no approvers and "you are not an approver" would send the reader looking for one who could. Unpublishing *inside* the archive falls back to `decisions_action_min_level` for the same no-approvers reason, so a row left public by an earlier version is never stranded; the `400` above means the fallback cannot put the flag back up. Audited as `published` / `unpublished` — **and until v4.11.31 it was not**: `setPublic()` has written those two since v4.11.12 into a vocabulary (`DecisionAuditService::TRANSITIONS`) that did not list them, and `log()` silently drops an unknown transition, so every one of those rows went into a warning and nowhere else. Any live status may be published |

**The conversation carries them.** `decision_linked` and `decision_unlinked` are part of `CONVERSATION_EVENT_TYPES`, so they arrive in the Messages tab in order with the messages rather than on the timeline; a client renders the linked decision from the event's own payload, never by fetching it again. `GET …/decisions` remains the **live** view (current status, current `canOpen`) and is what the desk's grid column reads.

Every decision payload gains **`isPublic`**. **Public means readable by every logged-in account on this instance** — instance-wide, never anonymous: no route serving a decision is a `#[PublicPage]`, and there is no share token. It widens who may **read**; who may act, approve, withdraw or comment is unchanged and stays the team's. A public decision is also *findable*, which is what the picker above needs and what a link-capability could never give.

**Claiming narrows, it does not replace.** A desk's step is held by every eligible agent; once one has claimed it, only they — or the service owner — may complete or reject it, and any other agent must take it over explicitly. That is `WorkflowEngine::assertHolder()`, so the API and the buttons cannot disagree.

**The internal half.** `GET /workflows/{id}` gains **`internal`**, present only for an eligible agent of the handling desk and `null` for everybody else: `{ serviceTeamId, serviceTeamName, assignee, assigneeName, claimable, canAssign, canRelease, isServiceOwner, agents, involved }` (`involved`, v4.10.31: this member claimed or completed one of its desk steps). `viewer.isServiceAgent` says whether it is there. The history gains **`visibility`** per event (`all` / `internal`), and `internal` events are **removed from the response** for the requester, for every other participant and for a Nextcloud administrator — the filter runs before the licence tier's, so a licence can never widen what a requester reads. `responsible` is unchanged and still names the desk, so a requester always knows who has it.

**v4.10.31 — whose action is required, and who lists what.** The view's `viewer` block gains **`actionRequired`** (this viewer's action is required: they may act on the active step *and*, for a desk step, have claimed it — an unclaimed desk step is the desk's, not any one member's; My Work labels it *Action required*) and **`canClose`** (see `close-request`). Each step in `steps` gains **`roleLabel`**, the role a built service's desk step needs (`''` otherwise). `GET /api/v1/workflows` (My Work) lists a desk's request for a desk member **only when they worked on it** (claimed, were assigned or completed one of its desk steps); `GET /workflows/{id}` still lets any member of the desk open any of its requests.

**Starting a service request** uses the existing route: `POST /api/v1/teams/{teamId}/workflows/{definitionKey}` with `{ "data": { "summary", "details" } }`, where `definitionKey` comes from a catalogue entry. A definition no active desk offers is dark — `isStartable()` is false, so it is not listed and `create()` answers `404`.

**v4.10.45 — icons, the administrator's categories, links, and more time for a team.**

- `GET /api/v1/service-teams/catalogue` answers `{ catalogue, categories, links, tier }`. `categories` is `[{ key, label, icon }]` in the administrator's order (the tiles follow it); `links` is `{ serviceDesk, knowledgePortal }`, each `''` or an `https://` URL. Every entry gains **`categoryIcon`**, and a built service **`icon`** (one of `ServiceIcons::ALLOWED`, default `HelpCircleOutline`). The entry `team_expiry` (*Request more time for a team*) is listed only for a caller who administers a team with an expiration date; `team_archive` is not listed while archiving before deletion is on, and cannot be started then. `team_change` is retired: no longer listed or startable, its running requests finish.
- `GET /api/v1/service-teams/expiry-teams` — `{ teams: [{ teamId, teamName, expiresOn, openRequest: { workflowId, proposedOn } | null }] }`: the teams the caller administers (level ≥ 8) with an expiration date. `workflowId` 0 is a ledger request still waiting for an administrator. Licensed.
- **Starting it:** `POST /api/v1/teams/{teamId}/workflows/team_expiry` with `{ data: { proposedOn: "YYYY-MM-DD", reason } }` — a team admin, while a desk offers it; the date must be later than the current one, the server records `currentOn`. The desk's step is *Grant* / *Decline*; granting sets the date (`TeamExpiryService::extendByServiceTeam()`).
- `GET /api/v1/teams/{teamId}/expiry` gains **`viaServiceTeam`** (a desk answers requests for more time) and **`serviceRequest`** (`{ workflowId, proposedOn }` of the open one, or `null`). `POST /api/v1/teams/{teamId}/expiry/request` answers `400` while `viaServiceTeam` is true.
- Built-service documents (`/built-services`) carry **`icon`** (`''` = default); `options.icons` lists the names the builder offers, and `options.categories` is the administrator's list.
- **Settings → TeamHub → Services** (`#[AuthorizedAdminSetting]`, not licensed): `GET /api/v1/admin/services/settings` → `{ categories: [{ key, label, icon, builtIn, customLabel, usage }], links, icons }`; `PUT /api/v1/admin/services/settings/categories` with `{ categories: [{ key?, label, icon }] }` in display order (a row without `key` is new; `400` when a removed category still has services, when two share a name, or when the list is empty); `PUT /api/v1/admin/services/settings/links` with `{ serviceDesk?, knowledgePortal? }` (`''` removes one; `400` for anything but `https://`).

**v4.10.44 — a request without a team.** Every catalogue entry carries **`teamOptional`**: `true` for a service a team built and for `new_team`, `external_access`, `shared_folder` and `general`; `false` for `team_modify`, `team_archive` and `team_quota`. A service with `teamOptional` may be started with `{teamId}` = the entry's `serviceTeamId` by anybody signed in — a personal request; any other `{teamId}` still needs the caller to be a member of that team (`403` otherwise). `team_request` (the new team) also accepts the generic form: `summary` is read as `teamName` and `details` as `reason` when those are absent.

**The quota request (v4.10.29)** is the seventh Nextcloud service: catalogue entry `serviceKey: "team_quota"`, `definitionKey: "teamspace_quota"`, category `files_storage`, offered by the desk that holds the Nextcloud services.

| Endpoint | Notes |
|---|---|
| `GET /api/v1/service-teams/catalogue` | Lists the quota entry **only for a caller who administers a team with a team space** (`QuotaTeamsService`); every other entry is unchanged |
| `GET /api/v1/service-teams/quota-teams` | **New.** `{ teams: [{ teamId, teamName, quota, openRequest: { workflowId, requestedBytes } \| null }] }` — the teams the caller administers (direct Circles level ≥ 8) that have a team space, by name. Licensed (`403` + `licenseGate` otherwise); empty is an answer |
| `POST /api/v1/teams/{teamId}/workflows/teamspace_quota` | Body `{ "data": { "requestedBytes", "reason" } }`. Team admin or owner of `{teamId}` while a desk offers it (`403` otherwise; `404` when no desk does or the instance has no team spaces); `400` for a size ≤ 0, > 10 TiB, not an increase, or no reason; `409` when the team has no space or already has a request open. The server records `currentBytes` itself — a client-sent one is dropped |

Steps: `submit` (done at creation) → `handle` (the desk: `complete` **grants** — the quota is written inside the same transaction, and a provider refusal leaves the step unanswered; `reject` **declines**, reason required) → `confirm` (the requester closes). A grant refuses (`409`) when the space has meanwhile reached the requested size or has gone.

**Added 4.10.29 to the workflow view:** `actionLabels` — `{ complete?, reject? }`, the active step's own words for the engine's verbs in the viewer's language (`{ complete: "Grant", reject: "Decline" }` on the quota request's desk step, `{ complete: "Close" }` on its last step), `[]` when the engine's own words apply. Words only: the verbs and routes are the same.

### Manage team → Services (v4.10.23, team admins)

`TeamServiceController`. All `#[NoAdminRequired]` — a Nextcloud administrator has no part in this. **Two gates, both re-checked on every call:** the caller is a **team admin** (Circles level ≥ 8) *and* the team was created from the **Service** template. Either failing answers the same way a missing team does, so a caller learns nothing about a team they may not administer. Licensed like every other half.

| Endpoint | Purpose |
|---|---|
| `GET /api/v1/teams/{teamId}/services` | `{ services: { holdsServices, claimedByOther, holderTeamId, holderName, agentCount, updatedAt }, availableServices }` — what the Services tab renders, including the six services behind its **?** panel |
| `POST /api/v1/teams/{teamId}/services` | Claim the **Nextcloud services** for this team — all six at once. `400` when another team holds them; idempotent for the team that already does |
| `DELETE /api/v1/teams/{teamId}/services` | Give them back. The team stays a service team and may claim again; open requests keep their step actor. **v4.10.33:** a team that has ever published a service of its own stays an active desk |

**The creation wizard posts to the same claim route**, after writing the team type, so ticking the box during creation and ticking it a week later are one code path.

### The service builder (v4.10.33, WorkflowHub phase 8a)

`ServiceBuilderController` + `TeamServiceBuilder`; design in `docs/service-builder.md`. The services a service team builds and publishes **itself**, beside the Nextcloud services. All `#[NoAdminRequired]`. **Gates, re-checked on every call:** the team was created from the **Service** template (else `403` *Team not found*); **reading** — any member, directly or through a group; **writing** — a team admin (Circles level ≥ 8). Licensed (`403` + `licenseGate`).

| Endpoint | Body | Notes |
|---|---|---|
| `GET /api/v1/teams/{teamId}/built-services` | — | `{ services: [service], canEdit, categories: [{ key, label }], limits: { services, steps, title, description, stepLabel, role, leadDays, links, linkLabel, url, tasks, fileDays } }` (`links`, `linkLabel`, `url` v4.10.36; `tasks` v4.10.37; `fileDays` v4.10.38) — drafts included, in display order |
| `POST /api/v1/teams/{teamId}/built-services` | `{ service: document }` | A new **draft**: nothing listed, nothing requestable. `201` + `{ service }`. `400` for an invalid document or past `limits.services` (50) |
| `PUT /api/v1/teams/{teamId}/built-services/{serviceId}` | `{ service: document }` | Save the draft. Requests keep starting from the published version until the draft is published |
| `DELETE /api/v1/teams/{teamId}/built-services/{serviceId}` | — | `{ deleted: true }`. **A never-published draft only** — `400` otherwise: requests may reference a published one, so it is unpublished instead |
| `POST /api/v1/teams/{teamId}/built-services/{serviceId}/publish` | — | Copies the draft to *published*, raises `version`, lists it on the Services page and makes the team an **active desk** if it was not one. `400` with the reasons joined when `publishProblems` is not empty |
| `POST /api/v1/teams/{teamId}/built-services/{serviceId}/unpublish` | — | Off the Services page at once; `version` and `published` stay, so running requests finish on the version they started on |

A service of another team answers `404`, whatever the path's team.

**The layout bundle (v4.10.34).** `GET /api/v1/teams/{teamId}/layout` adds two flags to `serviceDeskConfig`: **`isServiceTeam`** (licensed and a team created from the Service template, desk or not yet; the bundle is members-only, so this is the builder's read gate) and **`canBuild`** (a team admin of it, the write gate). The first gates the *Services* widget, layout id **`widget-services`**, which the layout save accepts and the default layout places under the statistics.

**The document** (`TeamServiceBuilder::normalise()` is its only writer; unknown keys are dropped):

```json
{
  "title": "Application intake",
  "description": "Ask for a new application.",
  "category": "apps_tools",
  "leadDays": 3,
  "askTeam": false,
  "steps": [
    { "kind": "desk", "label": "Assess the request", "tasks": [
      { "label": "Privacy check", "role": "Privacy officer", "nonBlocking": false,
        "links": [{ "label": "Intake checklist", "url": "https://wiki.example.org/intake", "kind": "link" }] },
      { "label": "Security check", "role": "CISO", "nonBlocking": true, "links": [] }
    ] },
    { "kind": "requester", "label": "Sign the agreement", "tasks": [
      { "label": "", "role": "", "nonBlocking": false,
        "links": [{ "label": "Agreement form", "url": "https://forms.example.org/agreement", "kind": "form" }] }
    ] },
    { "kind": "desk", "label": "Install the app", "tasks": [{ "label": "", "role": "", "nonBlocking": false, "links": [] }] }
  ],
  "files": { "allowed": true, "edit": false, "days": 14 }
}
```

`title` required, ≤ 100, one line. `description` ≤ 1000, line breaks allowed. `category` one of `ServiceCatalogue::CATEGORIES` (default `support_requests`). `leadDays` 0–60 whole working days, `0` / `""` / `null` = none said. `askTeam` stored for personal requests (a later step; today every request carries the team it is asked from). `steps` a list of at most 10; `kind` `desk` (the service team) or `requester` (a planned action); `label` ≤ 100, one line. The engine adds *Request submitted* before and *Requester confirms* after.

**v4.10.37 — tasks (`docs/service-builder.md` § 4).** Every step holds `tasks`, 1–10, done at the same time: `{ label ≤ 100, role ≤ 64, nonBlocking, links }`. `role` and `nonBlocking` mean something on a `desk` step only (`role` is a label, not a permission; `nonBlocking` lets the request move on while the task is open — it must still be done before the request ends); on a `requester` step they are dropped. One task may leave `label` empty and borrows the step's. **A step saved before v4.10.37** — `role` and `links` on the step itself — is read as one task that has them; the next save writes the new shape. **`start` / `startUrl` (v4.10.36, the link service) are withdrawn**: dropped on save; a service still *published* with `start: "link"` is left off the catalogue and cannot be started until it is published again.

**Links (v4.10.36, on tasks since v4.10.37).** Every task carries `links`, at most 5, each `{ label ≤ 100 one line, url, kind: link | form }` (`kind` defaults to `link`; it sets the button's words and icon). **Every `url` is `''` or an absolute `https://` address with a host, no spaces or control characters, ≤ 2000** — anything else (`http:`, `javascript:`, a relative path) is `400` on any save, draft included. A draft may hold a link without a name or an address; publishing may not. On a requester task, pressing a link in the client completes the task (`POST /workflows/{id}/complete` with its `step`).

**`files` (v4.10.38, the paperclip, § 7):** `{ allowed (default true), edit (default false: view only), days 1–365 (default 14) }`. Copied into each request's data as `fileSharing` when it starts.

**A service** in every response: `{ id, teamId, definitionKey: "team_service_{id}", draft, published | null, version, listed, hasChanges, publishProblems: [string], createdBy, createdAt, updatedAt, publishedBy, publishedAt }`. `publishProblems` is what stands between the draft and publishing, in the viewer's language: no step for the team, a step without a name, a requester step first (v4.10.35: every request reaches the team first) or last, a link without a name or an address (v4.10.36), a task without a name in a step of several, a team step whose every task is non-blocking (v4.10.37).

**On the Services page** a published, listed service is one more `GET /api/v1/service-teams/catalogue` entry of its team: `serviceKey` = `definitionKey` = `team_service_{id}`, `label` / `description` in the team's own words (not translated), `leadTime` *Usually within {n} working days* or `""`, **`builtService: true`**, and (v4.10.38) **`fileSharing`** `{ allowed, edit, days }` for the request form's paperclip. It is requested with the generic form, `POST /api/v1/teams/{teamId}/workflows/team_service_{id}` + `{ data: { summary, details, fileIds? } }`.

**Step links on a request (v4.10.36).** A request copies each task's `links` onto its row at start (`teamhub_wf_step.links`), like the label and the role, and every workflow view's `steps[]` carries **`links: [{ label, url, kind }]`** — `[]` for a step without any, which is every step of a built-in workflow. **A desk task's links are the team's own material: they are sent only to an agent of the desk handling the request**; everybody else on the request receives `[]` for that task. A requester task's links go to everybody on the request. The client renders only `https://` addresses.

**Personal requests (v4.10.40).** A service a team built may be started on the **service team's own id** — `POST /api/v1/teams/{serviceTeamId}/workflows/team_service_{id}` — by anybody signed in: a request asked from no team. Every workflow view carries **`personal`**: true when the request is recorded against the service team handling it. Started on any other team, the caller must still be a member of it; the built-in Nextcloud services still need a team.

**A team task is claimed first (v4.10.39).** `complete`, `reject`, `request-information`, `block` and `unblock` on a service-team task nobody has claimed answer `403` *Claim this task before you act on it.* — for every member, the team's admins included; `claim` (or an admin's `assign`) comes first. The view says so: `viewer.canAct` and each task's `canAct` are false and `internal.claimable` is true until then. **A service may start with the requester** again (the v4.10.35 publish rule is lifted): the new request's view answers for the requester's first task (`viewer.stepKey`, `canAct: true`), and the team lists it under `withOthers` until it is done.

**Tasks on a request (v4.10.37).** A task is a row of `steps[]`; the tasks of one step share its `order`. Each row adds **`stageLabel`** (the step's name when it holds several tasks, else `''`), **`nonBlocking`**, **`canAct`** (this viewer may act on this task now — what a requester's link completes) and **`assignee`** (who has a team task — sent to the desk's agents only, `''` otherwise). **`viewer.stepKey`** names the task the view answers for: the queue row's, the one just acted on, or the viewer's own; `viewer.*`, `responsible`, `actionLabels` and `internal` all answer for that task. `GET /workflows/{id}?step=` opens a request on one task. **Every verb takes an optional `step`** in its body — `complete`, `reject`, `request-information`, `provide-information`, `status-request`, `claim`, `assign`, `release`, `internal-note` — and acts on that task; `409` when it is no longer open; without it the server picks the caller's own task (claimed by or assigned to them, theirs by name, one they may take up), which is what every client before v4.10.37 gets. **The queue lists one row per open team task** (`GET /service-teams/{teamId}/queue`), and `unclaimedCount` counts tasks. **`GET /workflows`** lists a request once per open task that is the caller's own when they have two or more (two requester tasks of one step, two team tasks they claimed), each answering for its task; otherwise once. Completing the task that would end the request while another task is still open (a non-blocking one) is `409` *This request cannot end while "…" is still open*. Rejecting a task rejects the request and skips every other open task; withdrawing cancels them all.

**The paperclip on a request (v4.10.38, § 7).** `complete`, `reject`, `request-information`, `provide-information`, `internal-note` and `close-request` take **`fileIds: [int]`**, and the request form takes `data.fileIds` — files **of the caller's own** (`oc_filecache` ids they can read and may share), at most 10. `400` when a file cannot be found or shared, when the request is not handled by a service team, or when its service takes no files (`data.fileSharing.allowed: false`). The event of that write carries **`payload.files: [{ fileId, name }]`**. After the write commits, each file is shared — the requester's with the service team (a team share), a team member's with the requester (a user share), an internal note's with the team — for `fileSharing.days`, ending at 23:59:59 and never past the administrator's enforced maximum for internal shares; permission read, or read + update when `fileSharing.edit`. Each share is recorded as a workflow document (`teamhub_wf_attachment.share_id`, `share_until`, `unshared_at`); the documents endpoint adds `shared`, `sharedUntil`, `unsharedAt`. **When the request ends every share is removed**, and files attached to the very write that ended it are not shared. `ExpireWorkflowSharesJob` (daily) removes any share past its date.

### Service Teams — what an administrator keeps (Nextcloud administrators only)

`ServiceTeamAdminController`; **every method lacks `#[NoAdminRequired]`**, and that absence is the gate. Licensed like the agent half.

| Endpoint | Purpose |
|---|---|
| `GET /api/v1/admin/service-teams` | `{ holderTeamId, holderName, agentCount, availableServices, tier }` — who holds the Nextcloud services, for the Setup checklist row. No holder answers `holderTeamId: ""`, which is that row's "nothing set up yet" state |
| `DELETE /api/v1/admin/service-teams/{teamId}` | Release the claim. The team is untouched and so is its queue |

**The setup flow is gone (v4.10.23).** A service team is created from the Service template and claims the services itself; an administrator neither declares one nor names its people. The release survives because the claim is instance-wide: without it, a bundle taken by the wrong team could only ever be given back by that team's own admins.

### The workflow archive (v4.10.21, WorkflowHub phase 6)

All `#[NoAdminRequired]`, none CSRF-exempt, none public. **Licensed** (`archive_results`): every route answers `403 { licenseGate: true }` on an unlicensed instance, where a completed workflow is deleted rather than recorded (`docs/unlicensed-workflow-data-lifecycle.md`). Full design: `docs/workflow-archiving.md`.

**`audience` is a request, not a grant.** `requesting_team` (the default) or `service_team`. The service view is `403` for anybody who is not an eligible agent of the handling desk — a Nextcloud administrator included — and the requesting-team view **never carries an internal note or an internal document to anybody at all**, because that filter belongs to the audience rather than to the viewer.

| Endpoint | Purpose |
|---|---|
| `GET /api/v1/archive/workflows` | Search. **Query**: `audience`, `q` (free text over the request's own words, or an exact reference), `outcome`, `definitionKey`, `serviceKey`, `from`, `to` (Unix seconds, on the completion date), `limit` (≤ 100, default 25), `offset`. With no `audience` both are searched. **Response 200**: `{ results: [ { archiveId, reference, audience, viewerRole, title, teamId, teamName, outcome, decision, completedAt, serviceKey, link } ], scope: [ { audience, teamId, teamName } ], audiences, tier, capabilities }`. The scope is resolved from the caller's **live** roles, never from the query; rows the per-archive rule refuses are dropped silently. No total is returned (deferred) |
| `GET /api/v1/archive/workflows/{id}` | One projection. **Response 200**: `{ archive, tier, capabilities }` — see the shape below |
| `GET /api/v1/archive/workflows/{id}/record` | **The link from a projection to the authoritative record**: `{ record: { archiveId, reference, audience, viewerRole, authoritative: { instanceId, definitionKey, definitionVersion, teamId, serviceTeamId, subject, status, outcome, startedAt, completedAt, archivedAt }, integrity: { eventCount, sealed, verified }, history, retention } }`. `definitionVersion` is the version the workflow was **created** on. `history` is filtered for the audience — a link to the record is not a way around the filter |
| `GET /api/v1/archive/workflows/by-instance/{instanceId}` | The archive of one workflow, by the workflow's own id — what a finished My Work row links to. Same response as `{id}` |

**The projection** (`archive`): `{ archiveId, reference, audience, viewerRole, request: { title, summary, teamId, teamName, subject, submittedBy, submittedAt, serviceKey }, outcome: { status, outcome, decision, completedAt, completedBy, closingNote }, documents: [ { id, fileId, fileName, visibility, stepKey, addedBy, addedAt } ], followUp: [ { kind, stepKey, text, at, by } ], history, retention: { archivedAt, retentionUntil, policy, legalHold, enforced }, link, people, internal }`.

- `decision` is `approved` / `rejected` / `withdrawn` — the approval-or-rejection in one word.
- `followUp.kind` is `closing_note`, `rejection_reason`, `cancellation_reason` or `unanswered_question`; the list is **derived** from the record, and a workflow that ended cleanly has an empty one.
- `retention.enforced` is always `false` in this version: the metadata is written and **nothing deletes on it**.
- `viewerRole` is `requester`, `participant`, `team_admin`, `nc_admin`, `service_agent` or `service_owner` — why this caller may read this archive.
- **`internal` is `null`** on the requesting-team projection and absent-not-empty for a workflow no desk handled. On the service projection it is `{ serviceTeamId, serviceTeamName, isServiceOwner, assignedAgents, processingSteps, internalNotes, escalations, technicalActions, operationalOutcome: { handledBy, handlingStepKey, secondsInQueue, secondsInProgress, totalSeconds, claims, reassignments, releases }, documents }`.

**Who may read what.** `service_team`: the desk's service owner or an eligible agent, and nobody else. `requesting_team`: a Nextcloud administrator, or a **current** effective member of the requesting team who is a team administrator (Circles level ≥ 8), the requester, or a participant who still holds their actor. A plain member of the team reads nothing. Every check is at read time against live roles, so a membership or an agent seat that ends closes the archive with it.

### Workflow documents (v4.10.21)

A document is a **reference to a file already in Nextcloud**, never a copy, and every one carries a visibility that is always stated. Licensed like the archive it feeds.

| Endpoint | Purpose |
|---|---|
| `GET /api/v1/workflows/{id}/attachments` | `{ attachments: [ { id, instanceId, fileId, fileName, visibility, stepKey, addedBy, addedByName, addedAt } ] }`, as this caller may see them — a participant or a Nextcloud administrator gets the `requester` ones, an eligible agent of the handling desk gets all |
| `POST /api/v1/workflows/{id}/attachments` | `{ fileId, visibility, stepKey? }` → **201** `{ attachment }`. **`visibility` is required and has no default** (`requester` \| `internal`); anything else is `400`. `internal` needs an eligible agent of the handling desk (`403` otherwise). The file is resolved in the **caller's own** Nextcloud, so `404` for a file they cannot read and for a folder. `400` on a duplicate or at 50 documents. `409` once the workflow has ended |
| `DELETE /api/v1/workflows/{id}/attachments/{attachmentId}` | The person who attached it, or the service owner for an internal one (`403` otherwise). `409` once the workflow has ended — an archived record's evidence is immutable too |

Every read resolves `fileId` against the **reader's** Nextcloud, so attaching grants nobody access to anything; `fileName` is the name at the moment of attaching, a label for the record rather than an access path.

**Audit events added**: `workflow.<definitionKey>.archived`, `workflow.<definitionKey>.attachment_added`, `workflow.<definitionKey>.attachment_removed` — the last two without the file name.

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

## Reviewing, superseding and archiving a decision (added 4.11.18)

An approved decision is terminal, and until 4.11.18 that meant nothing could follow it. Three things can now: a review a member asks for, a review the category's clock asks for, and a supersede. All three end in the same two places — the decision stands, or it is retired into the team's archive.

**The rule the API is built on:** a review never rewrites the decision. The row keeps `approved`, keeps its `resolvedBy` and `resolvedAt`, and keeps governing while the review runs. Nothing here can turn an approved decision back into a proposal; a team that wants different wording proposes a new decision and supersedes this one.

### Two new statuses

`status` gains `superseded` and `archived`. Both are **terminal**, so read-only comes from the rule that was already there, and both move the row into the team's `Archive` category while keeping the name it was decided under:

| Field | Meaning |
|---|---|
| `isRetired` | `superseded` or `archived`. Sent rather than derived client-side, because three components ask and one of them getting the list wrong is a read-only record rendering its Edit button |
| `archivedAt` / `archivedBy` | When and by whom |
| `archivedFromCategory` | The category it was decided under, so the record still answers "what did Marketing decide" |
| `reviewDueAt` | When it next needs a review; `null` means never — either the category has no review period or the decision never reached `approved` |

`GET /decisions/{id}` additionally returns **`supersededBy`** — `{ id, question, status }`, or `null`. Resolved by looking up `supersedes_id` on the *newer* decision, which is the only link there is: adding a `superseded_by` column would be a second answer to one question, free to disagree with the first. Not on `serialize()`, for the same reason `proposalBody` is not — one extra query, read only by the detail panel.

**v4.11.31 — and the same relationship from the other end: `supersedes`**, `{ id, question, status }` or `null`, the decision **this** one replaced. Justin, 2026-10-06: *when we supersede a decision this is visible on the superseded decision, but not on the decision that supersedes it.* The retired row had `supersededBy` and a banner; the replacement had nothing, so the panel's Links section could not draw the link and its audit trail said the supersede had never happened.

Both fields read the **same** `supersedes_id`, from the two sides — `supersededBy` by looking it up, `supersedes` straight off the row — so there is still one place a supersede is stored and no way for the two directions to disagree. `supersedes` costs the lookup only when the column is set; `supersedesId` itself has been on `serialize()` since v3.64, which is how the client knows synchronously that there is a row to wait for.

### Endpoints

| Endpoint | Notes |
|---|---|
| `GET /api/v1/teams/{teamId}/decisions/{decisionId}/reviews` | `{ items: [{ id, decisionId, teamId, kind, requestedBy, requestedByName, motivation, proposedQuestion, proposedAnswer, hasProposedWording, attachmentFileId, attachmentName, status, outcome, completedBy, completedByName, completedAt, createdAt }] }`, newest first. Any team member. `kind` is `requested` (a member asked; `motivation` and `requestedBy` are theirs) or `periodic` (the clock asked; `requestedBy` is `''` and `motivation` `null` — naming the system as a person would be a lie the audit trail then repeats). **v4.11.30** adds the five proposal fields: `proposedQuestion` / `proposedAnswer` are the wording the requester asks for and are `null` on a review that proposed nothing (every row written before 4.11.30, and every periodic review); `hasProposedWording` is sent rather than left to the client to derive from those two, for the reason `isRetired` is sent on a decision — three surfaces ask the question and one of them getting null-versus-empty wrong is an *Approve* button over no words at all; `attachmentFileId` is the id of the **copy** inside `.proposals/{decisionId}/`, never of the requester's original, and `attachmentName` is the name they know it by (the copy's own filename carries a `review-{timestamp}-` prefix so a regeneration of the proposal folder cannot overwrite it) |
| `POST /api/v1/teams/{teamId}/decisions/{decisionId}/reviews` | `{ motivation, proposed_question?, proposed_answer?, attachment_file_id?, attachment_name? }` → the serialised review. **Any team member**: asking is not deciding, so this is not gated on the category's approver list, and deliberately not on `decisions_action_min_level` either — that gate is for writing *on* a decision, and a review request writes a question *about* one. Motivation required, ≤ 1000 chars (`400`). `approved` only (`404` otherwise). **`409` when a review is already open** — two would be two approvers answering different questions. **v4.11.30 — the request may carry what it is asking for** (DESIGN §2.162): the two wording fields are ≤ 4000 chars each (`400` beyond), trimmed, and stored as `null` when blank, because *nothing was proposed* is the fact the approver's buttons are chosen from. `attachment_file_id` is a file in the **requester's** own Nextcloud; the server copies it into the decision's proposal folder **before the review row is written**, so that a file which cannot be staged leaves no half-promised open review behind — which means a `400` here can mean *the attached file could not be read* and a `404` can mean *this team has no team folder, so a file cannot be attached to a review*. All four are optional and a request with none of them is the 4.11.18 shape: *look at this again*, with nothing proposed |
| `POST /api/v1/teams/{teamId}/decisions/{decisionId}/reviews/complete` | `{ outcome, reason, question?, answer? }` → the serialised **decision**, because that is what the client renders afterwards. Approver-gated (`403`). `outcome` is one of **`denied` · `accepted` · `kept` · `archived`**. A *requested* review is answered `denied` or `accepted`; a *periodic* one `kept` or `archived`. The service takes any of the four for either kind — which two are offered is the surface's business. **Three of them leave the decision `approved`** and restart the review clock from today; only `archived` retires it. `accepted` (v4.11.26) **rewrites the decision's wording in place** from `question` and `answer`: same status, same `resolvedBy`, same `resolvedAt`. At least one of the two must be present *and* differ from what is stored, or `400` — an accept that alters nothing is a `denied` wearing the wrong label. They are ignored for the other three outcomes. **v4.11.30 — omitting both is how an approver says "as proposed"**: each field falls back to the review row's `proposed_question` / `proposed_answer`, field by field, so an approver may retype the wording and leave the question to the requester. It is still a `400` on a review that proposed nothing, which is the case the message was written for. The `revised` audit payload gains `as_proposed` (whether the approver took the words as given or wrote their own) and `attachment_name` (the file the new wording came from). **The approver never touches the attachment** — it is the requester's; it stays where it was staged on an `accepted` and is deleted on `denied`, `kept` and `archived` alike, because the decision stands unchanged and its source files must not grow a document nobody adopted. ⚠️ **v4.11.24 had `accepted` return the decision to `open` for redrafting; it never does that again** — the proposal lifecycle has terminal `denied` and `withdrawn` in it, and a review must not be able to destroy the decision it was asked about (DESIGN §2.161). **`reason` is required** (v4.11.23; `400` without it, ≤ 1000 chars) — the same rule approve and deny have carried since v3.71.3, because somebody asked a question and *kept* with no words answers nobody. It joins the motivation on the row, so it reads as the exchange it was. `404` when no review is open. The review id is not in the URL: the server resolves the open one rather than the client fetching an id first |
| `POST /api/v1/teams/{teamId}/decisions/{decisionId}/archive` | **v4.11.24.** `{ reason }` → the serialised decision. Retires an approved decision outright, with no review behind it — the Actions-menu half of *archive*, where the periodic review is the other half. Approver-gated (`403`), reason required (`400`), `404` while a review is open (that review is where it gets decided). Audited as `archived`, carrying the reason and the category it came from |
| `POST /api/v1/teams/{teamId}/decisions/{decisionId}/category` | **v4.11.30.** `{ category }` → the serialised decision. Moves a decision into the category it should have been proposed in (DESIGN §2.163). **Gated on being an approver of the category it is in now**, not of the one it is going to (`403`): the people who find a decision in their queue that does not belong to them are the current approvers, and they are the ones who need to push it out. `400` for a target that is not one of the team's categories, is the archive (archiving has its own verb and its own mandatory reason), or is the category the decision is already in. `404` for a retired decision and while a review is open — that review is with the current approvers, so answer it first. No reason field: where a decision is filed is a clerical fact, not a judgement, and the `category_moved` audit line carries `from`, `to` and the actor. An **approved** decision recalculates `review_due_at` from the new category's period, because the period belongs to the category; anything not yet approved keeps a null clock, which `approve()` starts |
| `POST /api/v1/teams/{teamId}/decisions/{decisionId}/supersede` | `{ supersededById }` → the serialised (now superseded) decision. Approver-gated (`403`). Writes `supersedes_id` on the **newer** decision and retires this one. Refusals, each with its own sentence: superseding itself, a decision that is not `approved`, a replacement that is not `approved`, a replacement that already supersedes another. **v4.11.31 — it now audits both sides**: `superseded` + `archived` against the retired decision as before, and a new **`supersedes`** transition against the replacement, payload `{ supersedes, supersedes_title }`. An audit trail is per decision, so writing only against the retired row left the replacement's trail empty — and the replacement is the one still governing. Three rows for one act, two decisions |
| `GET /api/v1/teams/{teamId}/decisions/{decisionId}/supersede-options?q=` | `{ items: [{ id, question, category, resolvedAt }] }` — the team's approved decisions whose question matches `q` (≤ 200 chars, `400` beyond), minus this one, newest first, **at most four** (v4.11.20; 4.11.18 returned 50 unsearched). The same number and the same reasoning as `public-decisions` above: a picker is for recognising the decision you already have in mind. An empty `q` gives the four most recent. Readable by any member; the act is approver-gated. Deliberately **not** filtered to decisions that supersede nothing: `supersede` refuses those with a sentence, and a picker that silently omits what somebody is looking for sends them to read the code. The server fetches five and drops this decision afterwards, or a decision sorting into the first four would leave three |

`GET …/{decisionId}/audit` returns the trail **newest first** since v4.11.23 — the detail panel's order. The proposal `.md` document renders the same events oldest first, which is the mapper's order; the endpoint reverses rather than the client, so there is one defined order per reader.

**v4.11.31 — a retired decision is taken back out of public.** `retire()` clears `is_public` on the way into the archive and writes an `unpublished` audit row with `reason: "archived"` beside the `archived` one. The archive is team-only, and the flag says the decision *is* a public record — searchable, listable, the raw material a knowledge item is built on — which a decision that has stopped governing is not. A decision that was already team-only writes no such row: an audit trail that reports non-events is one people stop reading.

**Who may do what, in one line:** any member asks for a review — and, since **v4.11.30**, says in that request what the decision should say instead and attaches the file it should say it from; an approver of the decision's category answers it (approving the proposed wording, editing it first, or denying), archives, supersedes, and moves it to another category. The attachment is the requester's throughout: the approver reads it and never edits it. **v4.11.24** also widened `PUT …/proposal` and `POST …/finalize-proposal` from proposer-only to **proposer or team admin**, mirroring `withdraw()`; that stays in v4.11.26 although accepting no longer reopens anything, because the decisions 4.11.24 already reopened still need somebody able to move them and an ordinary proposal whose proposer has left the team must not be strandable either. That is `DecisionService::assertApproverFor()` — the same authority that gates approve and deny, made public for this, because retiring a decision is the mirror of approving it. Either-one, like approval: one approver's answer completes the review.

### The category's review period

`teamhub_dec_categories` gains `review_period_months` (0–120, 0 = never) and `is_archive`. Both are on every category payload as `review_period_months` and `is_archive`, and `POST`/`PUT /decisions/manage/categories[/{id}]` accept `review_period_months`. `is_archive` is **not settable over the API**: there is exactly one archive per team and it is created with the module.

Changing a category's period recomputes `review_due_at` on every approved decision in it, from each decision's own anchor — its last completed review, or its approval. So shortening a period does not punish a decision reviewed last month, and lengthening one does not hide a decision that is already overdue.

**The archive category is locked.** `PUT` refuses a rename, an approver list and any non-zero review period (`400` each, with the reason); `DELETE` refuses outright. Its icon and description stay editable — nothing stores those. It is also the one category allowed **zero approvers**: nothing in it is ever approved, so substituting one would name somebody responsible for a queue that cannot exist.

**And nothing can be proposed into it** (v4.11.30, DESIGN §2.163). `POST /decisions` refuses a `category` that is the team's archive with a `400` — *the archive category holds retired decisions, a proposal cannot be made in it* — and the composer stops listing it. Both, because a hidden option has never been a boundary: a proposal born in the archive sits in a category with no approvers and no review period, so nothing could ever approve it. The guard compares **case-insensitively**, because category names are unique case-insensitively and a guard that `archive` defeated would be a guard in name only. The archive is also not a target for `POST /decisions/{id}/category`: putting a decision there is *archiving* it, which has its own endpoint and its own mandatory reason.

### Two seeded categories

Switching the Decisions module on for a team (`PUT /decisions/config` with `decisions_enabled`) now also gives it **General** (the team's admins and owner as approvers, review period 0 — an ordinary category otherwise) and **Archive**. The `SeedDecisionCategories` repair step does the same for teams whose module predates 4.11.18: Archive for every one of them, General only for a team with no categories at all, so a team that already organises its decisions does not find a stray tile on its landing grid.

General's approver list is a **snapshot of user ids**, not the "team admin" role — somebody promoted next month is not automatically an approver, and the list is editable like any other. That is how every category already works.

### My Work and the bell

Three provider statuses (`decision_review_requested`, `decision_review_due`, `decision_review_waiting`) and no new `ActionType`: the approver's row offers `COMPLETE` (*Keep decision*) and `REJECT` (*Archive decision*) with the row's own words in `metadata.actionLabels`, the requester's row offers `OPEN`, and both carry the whole review in `metadata.workflow`. A review row's `providerItemId` is prefixed `review:` — a decision row and a review row can exist for one decision at once, and two items sharing an id would share their snooze state and route each other's actions.

Three notification subjects, the module's first: `decision_review_requested` and `decision_review_due` to the approvers, `decision_review_completed` to the member who asked. A completed review withdraws the pending bells about that decision for everyone, so nobody clicks through to a row a colleague already answered. A periodic review has no requester, so completing one sends nothing.

`DecisionReviewJob` runs daily and opens the periodic review two weeks before it falls due.

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

### `POST /api/v1/announcements/seen` (body: `{ filename, event }`) — added 4.13.56

Record one popup event for the current user. Feeds the four counters the telemetry payload reports as `announcement_popup`, so Justin can tell "nobody acted on it" from "nobody saw it".

**Auth**: any authenticated user (`#[NoAdminRequired]`). The popup is only drawn for Nextcloud instance admins, but the gate here is the service's "may this user see this announcement" rule — the audience rule lives in the frontend and is deliberately not duplicated server-side.

**Body**:
```json
{ "filename": "welcome-4.13.56.md", "event": "read" }
```

- `event` — one of `shown` (the popup was displayed), `read` (scrolled to the end, which is what unlocks its controls), `ack` (OK pressed, so it returns at the next sign-in), `optout` (OK with "do not show again" ticked).

**Response 200**: `{ "recorded": bool }`. `false` for an unknown filename, an unknown event, an announcement this user may not see, or an event this user has **already** been counted for — each user contributes at most one of each event per announcement, which is what stops one administrator reloading from reading as twenty instances paying attention. None of those is an error the reader should be shown, hence 200 rather than 400/404.

**Response 400**: `filename` or `event` missing.

**Storage**: counters in app config `announce_popup_stats` (`{ "<filename>": { shown, read, ack, optout } }`); the per-user ledger in `oc_preferences` under `announce_popup_seen`. Counts only — no user id and no content leaves the instance.

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

`sourceCounts` and `facets` are computed on the merged result **before** the source tab narrows anything, so selecting one tab leaves every other tab's number intact. `team` / `public` / `talk` partition the feed; `mentions` and `decisions` are lenses over those same rows, so the numbers are **not** expected to sum to `all`. **There is no `openproject` key since 4.13.31** — an OpenProject news row counts under `team`.

**OpenProject news rows (v4.9.7).** `includeOpenProject` (bool, default `1`) adds one row per OpenProject **news** item written inside the period, from every project the caller's teams are linked to, read live as the caller (OPENPROJECT.md §6.3) — and, as a side effect of the read, mirrors each item once into its team's message stream (§6.1). `projectIds` (list, max 50, digits only) narrows them; `teamIds` and `from`/`to` apply too ("all time" = the last 90 days for OpenProject); `types` excludes them wholesale like Talk rows. Shape: `{ "id": "op:<connection>:<projectId>:news:<newsId>", "source": "openproject", "activityType": "news", "subject" (the title), "message" (the summary, or the first 400 characters of the body, plain text), "created_at", "team_id", "team_name", "author_id": "" (not a Nextcloud account), "actor_name" (the author as OpenProject names them), "project": { id, name, url }, "news": { id, url }, "opens_externally": true, "can_open_team", "can_view_comments": false, "can_comment": false, "comment_count": 0 }`. **Counted under `sourceCounts.team` since 4.13.31** (`openproject` only, before that): a news item *is* a team message — the mirror writes it into the team's stream as one — so counting it separately counted one item under two headings. `source` stays `openproject`, as the row's provenance: the card reads it to draw the project pill and the *Open in OpenProject* action. A news row only ever resolves through a link on a team the caller is in, so `team` is right by construction; it is counted under neither `mentions` nor `decisions`. `facets.projects` lists `{ id, name, teamId, count }` for the projects present before the `projectIds` narrowing. The payload also carries `sources.openproject` — `{ state: ok | partial | error | auth_required | not_connected | unavailable | skipped, code, message, covered, skipped }` — so the client can render one notice while every other source stands; a failing OpenProject never fails the feed. Work-package edits are **not** feed rows (Justin, 2026-09-14: the feed is for news and messages).

`includeOpenProject` is **API-only since 4.13.31**, and ANDed with `includeTeam`: news is a team message, so `includeTeam=0` drops it whether or not this is set, and the frontend no longer sends it (its rail switch is gone, and a stored `false` is ignored on read and dropped on the next save, the way `includeSystem`'s was in 4.5.31). The parameter stays because `includeOpenProject=0` is the one way to ask for the team's messages **without** this source's live HTTP read.

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

Server-side validated against `TeamTypeService::ALLOWED = ['collaboration','project','department','openproject','service']` — anything else returns 400.

**Side effects of the value written** (both in `TeamController::saveTeamType`, not in `TeamTypeService`):

- `expiresOn` in the body sets the team's expiry at creation, for the templates that allow one.
- **`service` seeds the default issue process** *(4.11.6)* — `TeamServiceBuilder::seedDefault()` writes *"{Team} support request"* as an unpublished **draft** on the team. Skipped silently when the team already has a service of its own, or when the licence or the administrator's module switch is missing. A failure is logged and does not fail the request.

**Response 200**:
```json
{ "type": "project", "expiry": null }
```

**Failures**:
- `400` — value not in the allowed enum
- `403` — not a team admin
- `500` — internal error (logged)

---

## Compliments (added 4.13.2)

One person telling another they did well, with a note. No `{teamId}` in any path on purpose: a compliment is between two people, and the gate that matters — the two must share a team — is about the *pair*. The team is recorded as provenance and an unresolvable one is dropped rather than refused. Sender and recipient are always the session's user, never the body's. DESIGN §2.186.

Deliberately **not** a My Work workflow and **not** a Nextcloud notification — see §2.186 for both decisions and the cost accepted for the second.

### `POST /api/v1/compliments`  *(added 4.13.2)*

Pay a compliment.

**Auth**: any signed-in user. The service refuses unless the two share at least one registered team (read through `circles_membership`, so membership via a group or a parent circle counts).

**Body**:
```json
{ "to": "jaap", "note": "Thanks for covering the handover.", "teamId": "TEAM1" }
```

- `to` — the recipient's uid. Required.
- `note` — up to 500 characters; control characters stripped, runs of blank lines collapsed, trimmed, then cut to length. **Optional at the API and required in the composer** (§2.186), so an older client is never refused.
- `teamId` — the team page it was sent from; omitted or unregistered is stored as `''`.

**Response 200**: `{ "status": "sent" }`

**Failures**:
- `400` with a sentence the sender can act on — complimenting themselves, an account that does not exist, somebody they share no team with, or the sixth compliment to the same person within 24 hours (`ComplimentService::RATE_LIMIT_PER_PAIR`, per *pair* rather than per sender)
- `401` — not signed in
- `500` — internal error (logged)

---

### `GET /api/v1/compliments/unseen`  *(added 4.13.2)*

What the caller has not been shown yet, grouped by sender in the order each person's **first** compliment arrived, each sender's notes in the order they were written. That is the shape the party popup renders.

**Auth**: any signed-in user; answers for the caller only.

**Response 200**:
```json
{
  "total": 3,
  "ids": [1, 2, 3],
  "senders": [
    { "uid": "inge", "displayName": "Inge NC", "subline": "Controller · Finance", "count": 2,
      "notes": [ { "note": "You unblocked me on Monday.", "createdAt": 1760600000 },
                 { "note": "And again on Thursday.", "createdAt": 1760686400 } ] }
  ]
}
```

- `total` — compliments, not senders. At most 50 per read (`ComplimentMapper::MAX_PER_READ`).
- `ids` — exactly the rows this payload describes; the client posts these back to `/seen`.
- `notes` — a compliment sent with an empty note contributes to `count` and adds no entry here.
- `subline` is resolved with `adminContext: false`, so a field the sender set to private stays private.

**Reading marks nothing seen.** A tab closed before the popup paints does not swallow a compliment.

---

### `POST /api/v1/compliments/seen`  *(added 4.13.2)*

Mark the compliments the popup just showed as seen.

**Auth**: any signed-in user.

**Body**: `{ "ids": [1, 2, 3] }` — at most 100 per call; a JSON-encoded string is also accepted.

**Response 200**: `{ "marked": 3 }`

The UPDATE is scoped to the caller's own still-unseen rows, so an id that is not theirs matches nothing rather than being refused — which is also why there is no separate ownership check. Nothing is deleted: `seen_at` is set, and "since your last visit" needs no stored visit.

---

### `POST /api/v1/teams/{teamId}/banner`  *(added 4.13.1)*

Upload the team banner — the wide picture shown between the tab bar and the team's content, on every tab.

**Auth**: team **moderator** or above (level ≥ 4), the same gate the team picture uses.

**Body**: `multipart/form-data` with a file field named `banner`. Accepted types `image/jpeg|png|gif|webp`; at most 8 MB and 50 megapixels. The image is scaled to fit 1920×1080 with its aspect ratio preserved and never upscaled or cropped, then re-encoded as JPEG at quality 82. The crop to the strip happens in the browser (`object-fit: cover`).

**Response 200**:
```json
{ "banner_url": "/index.php/apps/teamhub/api/v1/teams/.../banner?v=1760630400" }
```

The `v` parameter is the stored file's modification time. It is what makes a replaced banner reach members who already have the old one cached, since the response carries a day of `Cache-Control`.

**Failures**:
- `400` — no file, upload error, over 8 MB, over 50 MP, unsupported type, undecodable
- `403` — not a moderator
- `500` — storage or encoding failure (logged)

**Storage**: NC app data — `appdata_<instanceid>/teamhub/team-banners/{teamId}.jpg`. No table, no migration.

---

### `DELETE /api/v1/teams/{teamId}/banner`  *(added 4.13.1)*

Remove the team banner. Silent when the team has none.

**Auth**: team moderator or above.

**Response 200**: `{ "banner_url": null }`

---

### `GET /api/v1/teams/{teamId}/banner`  *(added 4.13.1)*

Serve the banner bytes. `#[NoCSRFRequired]` so an ordinary `<img src>` can load it.

**Auth**: any team member, direct or inherited — the boundary the team picture's own serve route applies. Without it any authenticated user could read any team's banner by iterating `teamId`.

**Response 200**: `image/jpeg`, with `Cache-Control: public, max-age=86400` and an `ETag`.

**Failures**:
- `403` — not a member
- `404` — the team has no banner

---

### `GET /api/v1/teams/{teamId}/dashboard/config`  *(added 4.1.2)*

Fetch the team-wide dashboard customization: which widgets the owner/admin has hidden from every member's Home dashboard, and which tab opens when a member enters the team.

**Auth**: team member required.

**Response 200**:
```json
{ "hidden_widgets": ["widget-activity", "widget-files-center"], "default_tab": "decisions" }
```

- `hidden_widgets` — array of widget ids removed from every member's grid (desktop, tablet, mobile). Their grid positions are preserved, so un-hiding restores placement.
- **Default when nothing is stored (4.13.1)**: `["widget-teaminfo"]`. The Team info widget is hidden on a team no administrator has configured, because the team banner now carries the team's name and description above every tab. It is a default, not a removal — the switch is still in Manage team → Settings → Widgets. **A stored empty list is not the same thing:** `[]` means an administrator chose to show every widget and is honoured as such, so the two readers take an absent key (`''`) rather than `'[]'` as their fallback. `OCA\TeamHub\Constants\DashboardWidgets`, DESIGN §2.185.
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

### `GET /api/v1/welcome-message` — added 4.13.56

The welcome page's subline as a Nextcloud administrator wrote it, or `''` when none is set (the page then shows the line TeamHub ships).

**Auth**: any logged-in user (`#[NoAdminRequired]`). The value is shown to every user by design.

**Response 200**:
```json
{ "welcomeMessage": "Everything team related lives here. See the [intranet](https://example.com) for the handbook." }
```

Rendered as markdown through the team-message renderer (`src/lib/messageMarkdown.js`), so it is DOMPurify-sanitised on the way to the page.

**Written** on `PUT /api/v1/admin/settings` as `welcomeMessage`, which is NC-admin gated. It is **not** a field on `GET /api/v1/preferences` above, whose contract is that every value it returns is the caller's own, and **not** read from `/api/v1/admin/settings`, which would 403 for every non-admin and silently fall back to the shipped line — the bug 4.11.33 fixed for the create-team dialog's own admin-written sentence. Stored in app config, capped at `TeamService::WELCOME_MESSAGE_MAX` (4000 characters).

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
- `membership` — `member` (direct or via a group/sub-team), `invited` (the team has invited this person and is waiting on them), `requesting` (this person has asked and a moderator has not answered), `none`. **`invited` is new in 4.13.18**; until then it was folded into `requesting`, which told an invitee that *their* request was awaiting approval and offered them nothing to press. DESIGN §2.195.
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

## Invitation endpoints (added 4.13.18)

### `POST /api/v1/teams/{teamId}/invitation/accept`
### `POST /api/v1/teams/{teamId}/invitation/decline`

The two decisions an invited user can take. `{"success": true}` on either.

**No role gate, and none is possible** — the caller is not a member of the team yet. Holding a `circles_member` row with `status = 'Invited'` *is* the authorisation, checked in `TeamInvitationService`; the team's own configuration is what decided to write that row. Everything else answers **403 `{"error": "not_invited"}`**, including an existing member, a pending join request and a blocked user. A 403 rather than a 404 deliberately: a 404 here would confirm which team ids exist.

**Separate from `POST /join` on purpose.** That endpoint answers 403 `invite_only` for a team without `CFG_OPEN`, which is the kind of team an invitation usually comes from — reusing it would refuse nearly every invitation. Circles agrees: `CircleJoin::manageMemberStatus()` takes an `Invited` row at `LEVEL_NONE` to `LEVEL_MEMBER` without consulting the config or an invitation code.

**Both reach Circles through `CircleService`, never `LocalController`.** The frontend switch of §2.190 gates that controller and nothing below it; routing either decision through it is what this release fixes. Accept is `circleJoin()`, decline is `circleLeave()`.

These are also the targets of the `team_invitation` notification's **Accept** / **Decline** buttons, so their route names are load-bearing — `Notifier.php` builds the action links from `teamhub.team.acceptInvitation` / `teamhub.team.declineInvitation`. The notifications web UI posts them with `@nextcloud/axios`, so the CSRF token travels and neither needs `#[NoCSRFRequired]`. A desktop or mobile client sends no such token; for those the notification's body link opens `?team=<id>`, which lands on `TeamJoinView` and offers the same two buttons. DESIGN §2.195.

---

## Layout endpoint (updated 4.1.0)

### `GET /api/v1/teams/{teamId}/layout`

Response additions in both team-row and cascade-to-default branches (in addition to earlier ones):

- **`messagesConfig`** (4.0.0) — `{ messages_enabled: bool }`, so the frontend can gate the message stream widget without a second fetch.
- **`teamType`** (4.1.0) — `"collaboration" | "project" | "department" | null`. Populated from `teamhub_team_type` via `TeamTypeService::getType`. `null` for legacy teams so the badge renders nothing.
- **`autoFit` on DEFAULT_LAYOUT items** (4.1.0, **retired 4.13.33**) — new grid items carried `autoFit: true` and the frontend was to measure rendered content on first mount and grow `h` to fit. It never could: see the 4.13.33 section at the top of this file. No longer emitted, and dropped from a save that still sends it.
- **`teamBanner`** (4.13.1) — `{ url: string|null }`. The current team's banner, `null` when it has none; the URL carries the stored file's version as `v`. Always emitted, so a team without a banner clears the strip rather than leaving the previous team's picture on screen. Deliberately **not** on `GET /api/v1/teams` — that would cost an app-data stat per sidebar row for a picture only the open team draws. DESIGN §2.185.
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

## Team tasks *(documented 4.11.15; endpoints older)*

VTODO objects on the team's calendars — what *Create personal task* writes and the Tasks app shows. Both endpoints need the Tasks app; without it they answer `422`.

### `GET /api/v1/teams/{teamId}/tasks`

Up to 10 open tasks across every calendar connected to the team, undated ones included, soonest due first. Feeds the Upcoming tasks widget. **Auth**: `requireMemberLevel($teamId)`.

**Response**: `[{ id, title, duedate, priority, status, url }]`

### `POST /api/v1/teams/{teamId}/tasks`

Creates a VTODO. **Auth**: `requireMemberLevel($teamId)`.

| Param | Type | Notes |
|---|---|---|
| `title` | string | Required. `400` when empty. |
| `description` | string | Optional. |
| `duedate` | string | Optional, ISO 8601. An unparseable value is dropped rather than refused. |
| `calendarId` | int | Optional. Defaults to the team's first connected calendar. |
| `assignee` | string | *(4.11.15)* A team member's uid, `self`, or `team` — omitted means `team`. |

**`assignee` is who the task is for**, and it is the whole of what tells a to-do somebody wrote for themselves from one the team owes (`TaskService`'s docblock; DESIGN §2.153):

- a uid or `self` → `ORGANIZER` = caller, one `ATTENDEE` = that person. It reaches **their** My Work and nobody else's; a colleague is notified (`task_assigned`) and the caller gets a *Waiting for others* row.
- `team` or omitted → `ORGANIZER` = caller, **no `ATTENDEE`**. The team's own task list, on every member's My Work. Every task written before 4.11.15 is this case.

A uid that is not an effective member of the team is `400`. The person is identified by their `mailto:` address plus an `X-NC-USER-ID` parameter, so the match survives an email-address change.

**Response 201**: `{ uri, title, assignee }` — `assignee` is the resolved uid, or `null` for a team task.

**Status codes**: `400` title missing or assignee not in the team · `403` not a member, no signed-in user, or the calendar is read-only for this account · `404` the team has no calendar, or it is not in the caller's calendar home · `422` Tasks app missing · `500` with a `ref` for anything else.

Completing a task is **not** an endpoint of its own: it is the `complete` action on the `tasks` My Work provider (`POST /api/v1/mywork/action`, item id `tasks:{calendarId}:{objectUri}`). Offered on a task that is yours or the team's, never on a *Waiting for others* row; an already-finished task answers `conflict`, and the organiser is notified (`task_completed`) when somebody else finishes what they asked for.

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

## Member-id repair (added 4.14.7)

### `POST /api/v1/admin/maintenance/repair-member-ids/{teamId}`

**Auth**: NC admin (`MaintenanceService::requireNcAdmin()`).

Rewrites every `circles_member` row of the team whose `member_id` is shorter than 14 characters, empty or absent, giving each a freshly generated 31-character id. Matching `circles_token` rows are updated in the same pass.

**Response 200**
```json
{ "success": true, "repaired": 1 }
```

`repaired` is the number of member rows rewritten; `0` is a normal answer for a healthy team.

**Failures**: `403` not an NC admin, `500` other.

**Why it exists.** `circles_member.member_id` is the member row's own identity, and Circles rejects anything under 14 characters on every write (`CoreRequestBuilder::confirmValidId()`). Four TeamHub paths used to insert the bare uid by raw SQL, bypassing that check. The row works locally and fails only across federation: the receiving instance runs the **owner** row through the one `insertOrUpdate()` in `RemoteService::syncRemoteCircle()` that has no `catch (InvalidIdException)`, and answers the event with a 409 *"Could not verify Team"* while the inviting side reports success. See DESIGN §2.249.

`GET /api/v1/admin/maintenance/membership-check` reports these as an `invalid_member_id` issue, carrying `invalid_count` and `owner_affected` — the owner row is the one that actually blocks federation, since every other member is skipped individually by `syncRemoteMembers()`.

`Version000414007Date20261109180000` performs the same repair across every `source = 16` team on upgrade, so this endpoint is for teams damaged after that point rather than the main remediation path.

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

TeamHub licensing is manual: trials and paid licenses are issued by hand from the portal in response to email, and the customer pastes the returned JWT into the License tab. **Validation is entirely offline for every licence kind** — the signature is checked in-process against a public key shipped with the app, and nothing is ever fetched to decide whether a key is good. No activation call, no daily check-in, no install/uninstall ping, no renewed-JWT download.

**One exception, added in 4.13.30 and scoped to one licence kind** (`docs/metered-licensing-architecture.md`): a licence whose `kind` claim is `metered` has no seat cap and instead reports its effective member count to the portal twice a month, which is what the customer is billed for. That report is the app's only licensing-related outbound call. It is **a property of the licence, not of the app** — a banded (`airgapped`) licence makes no outbound call on any app version, which is what an air-gapped instance depends on.

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
  "graceDays":            14,
  "enforcementLevel":     "none",
  "graceRemaining":       null,
  "invalidReason":        null,
  "instanceUuid":         "abcd1234efgh",
  "seatsUsed":            42,
  "seatsOverBy":          0,
  "seatEnforcement":      "none",
  "seatCap":              100,
  "seatLockAt":           120,
  "reportState":          "not-required",
  "reportOverdueDays":    0,
  "reportLastOkAt":       null,
  "reportLastPeriod":     null,
  "reportNextDueAt":      null,
  "reportLockAt":         null,
  "reportLastPayload":    null,
  "reportLastError":      null
}
```

**Field notes**:
- **`enforcementLevel`** (`none` / `grace` / `soft-lock` / `unlicensed`) — temporal state, driven by JWT `exp` vs now. `grace` runs for `LicenseService::PAID_GRACE_DAYS = 14` past `exp` for a paid key (the Commercial Licence and Support Agreement's art. 13.4; **was 30 until 4.10.12**) and not at all for a trial (`is_trial`, art. 14.3): a trial is `soft-lock` from the moment it expires. The boundary is inclusive — on the fourteenth day a paid key is soft-locked.
- **`seatEnforcement`** (added 4.4.0; `none` / `over-warn` / `over-lock`) — seat-count state. `count > seats` → `over-warn` (banner only); `count > ceil(1.2 × seats)` → `over-lock` (blocks new-Advanced creation + writes on existing Advanced surfaces). Unlimited tier (999999), missing `seats` claim on legacy JWTs, and unlicensed instances all resolve to `none`.
- **`paidUntil`, `paidDaysRemaining`** (added 4.3.21) — new-model JWTs carry a `paid_until` claim. Legacy JWTs without it fall back to `paid_until = exp` so the API shape stays valid. **`graceDays`** (changed 4.10.12) is the window the key is *entitled to* — `14` for a paid key, `0` for a trial — computed by `LicenseService::graceDaysFor()`; the JWT's `grace_days` claim is no longer echoed or consulted.
- **`lastTelemetryAt`, `lastTelemetryPayload`** — **removed in 4.13.30.** They were `null` on every instance from 4.4.0 onward (the daily-report writer was deleted and nothing replaced it), and no caller in `src/` ever read them. A client still reading them gets `undefined` rather than `null`; nothing else changes.
- **`reportState`** (added 4.13.30; `not-required` / `ok` / `due` / `overdue` / `locked`) — where a **metered** licence sits on the member-reporting ladder, measured from the last accepted report (or from when the key was first seen). `not-required` for every other licence kind, which is the invariant an air-gapped instance relies on. The rungs are `LicenseService::REPORT_WARN_DAYS` (21), `REPORT_ESCALATE_DAYS` (32) and `REPORT_LOCK_DAYS` (45); the boundary is inclusive, so on the forty-fifth day the instance locks. **A trial never reaches `locked`** — it reports and is warned, but is never locked for silence.
- **`locked` feeds `enforcementLevel`.** It is the only place reporting state changes what the app allows, and it fires on a key that is otherwise perfectly valid: `enforcementLevel` becomes `soft-lock` and `invalidReason` says in words that no report has arrived, so an administrator is never left guessing why a current licence stopped working.
- **`reportLockAt`** — the timestamp the instance will lock, so a client can name the date rather than do the arithmetic. `null` for a trial and before anything has been measured.
- **`reportLastPayload`** — the last report **verbatim**, exactly as posted. It is echoed back so an administrator (and their DPO) can see what left the box rather than read a promise about it.

### `PUT /api/v1/admin/license`

Save a new license JWT (verified before persist).

**Auth**: NC admin required (`#[AuthorizedAdminSetting]`).

**Request body**:
```json
{ "jwt": "<full JWT string>" }
```

**Response 200**: same envelope as `GET /api/v1/admin/license` (the frontend refreshes without a second roundtrip).

**Failures**: `400` invalid JWT (bad signature, expired, UUID mismatch, missing claims). The specific reason surfaces in `invalidReason` on the status envelope so the admin sees exactly what to fix.

### `POST /api/v1/admin/license/quote` *(4.14.12)*

Send the License tab's quote request to the licensing service, which files it with the website's `quote.php` requests (`enquiries` table) and mails sales. `LicenseController::requestQuote` → `QuoteRequestService`.

**Auth**: `#[AuthorizedAdminSetting(AdminSettings)]`, CSRF-protected. `#[UserRateLimit(limit: 5, period: 3600)]`.

**Request body**:
```json
{ "kind": "licence", "email": "it@example.org", "country": "NL", "seats": 120, "supportPlan": "none", "message": "" }
```

- `kind` — `licence` or `support`.
- `country` — ISO 3166 alpha-2. No VAT number: VAT is checked by hand after the quotation is sent.
- `seats` — required for `licence` (1 to 100000), ignored for `support`.
- `supportPlan` — `none` (default, the Included level), `supported` or `priority`; `licence` only, ignored for `support`. `400` field `support_plan` for anything else.
- `message` — up to 2000 characters.

The server adds `uuid` (Nextcloud `instanceid`), `members` (unique team members, the Statistics figure) and the TeamHub and Nextcloud versions before posting. The browser never sends them. The outbound body and the receiver's answers are in `docs/licensing-requests-contract.md`.

**Response 201**: `{ "reference": "TH-2611-K7QM" }`.

**Failures** (the error is a code; the License tab writes the sentence):
- `400` `{ "error": "invalid", "field": "kind"|"email"|"country"|"seats"|"message" }`
- `429` `{ "error": "rate_limited" }`, from the receiver's throttle (or Nextcloud's own limiter)
- `502` `{ "error": "unreachable"|"unavailable"|"rejected" }`. The dialog then offers the e-mail fallback.

### `POST /api/v1/admin/license/trial` *(4.14.12)*

*Request a trial key*. Without `extension`, fetches this instance's one trial key from the licensing service (one per instance UUID, ever) and installs it through `LicenseService::saveKey()`. With `extension`, files a request for an extended trial with sales instead; the License tab offers that after a `trial_used`. `LicenseController::requestTrial` → `TrialRequestService`.

**Auth**: `#[AuthorizedAdminSetting(AdminSettings)]`, CSRF-protected. `#[UserRateLimit(limit: 5, period: 3600)]`.

**Request body**:
```json
{ "email": "it@example.org", "extension": false, "message": "" }
```

`message` (up to 2000 characters) is kept only with `extension`. The server adds `uuid`, `members` and the two versions, as for the quote.

**Response 201**:
- `{ "status": "issued", "expiresAt": 1795000000, "license": { …the GET /admin/license envelope… } }`
- `{ "status": "extension_requested", "reference": "TH-2611-K7QM" }`

**Failures**:
- `400` `{ "error": "invalid", "field": "email"|"message" }`
- `409` `{ "error": "trial_used" }`: this UUID has had its trial
- `409` `{ "error": "licensed" }`: a paid key is valid or in grace; a trial key would replace it, so nothing was sent
- `429` `{ "error": "rate_limited" }`
- `502` `{ "error": "unreachable"|"unavailable"|"rejected"|"invalid_key" }`. `invalid_key`: the service issued a key this instance does not accept (logged; the trial is spent).

### No endpoint for sending a seat report *(and why)*

v4.13.30 briefly had `POST /api/v1/admin/license/report`, to back a **Send report now** button
on the License tab. Justin removed the button the same day: the member count is a billing matter
between the instance's operator and us, and a button there makes it look like something a team
administrator is meant to manage. The endpoint went with it rather than staying on as an
admin-gated route with no caller.

Sending on demand is an occ command, which is where operator actions belong:

```
occ teamhub:seat-report            # send now
occ teamhub:seat-report --dry-run  # show what would be sent, send nothing
```

It is safe to run repeatedly: the receiver is idempotent per (licence, period) and answers
`already_recorded` rather than revising a figure, so "the month's latest snapshot" means the
latest *period*, not the latest POST. It does not bypass the licence conditions either -- a
banded licence, an unlicensed instance or one expired past its grace window is refused with the
reason, and a banded licence is told plainly that it does not report at all rather than being
treated as an error.

The report itself still goes out on `SeatReportJob`'s twelve-hourly tick without anybody asking.
What is sent, and to where:

**POST `https://portal.doekworks.eu/api/seat-report`**, with the licence JWT as a bearer token:

> **The extensionless path depends on a rewrite on the portal host.** The receiver is
> `public_html/portal/api/seat-report.php`, MultiViews is off there, and
> `public_html/portal/api/.htaccess` maps the clean URL onto it. Both halves have to stay in
> step: the app can only change `SeatReportService::REPORT_URL` by shipping a release, so the
> host is the side that has to answer what is already in the field. Confirmed working with a
> real POST on 2026-11-06, which is also the day it was found broken.

```json
{
  "lic":               "lic_2026_abc123",
  "uuid":              "abcd1234efgh",
  "period":            "2026-10-H2",
  "counted_at":        1761500000,
  "effective_members": 437,
  "app_version":       "4.13.30",
  "nc_version":        "33.0.1.2",
  "is_trial":          false
}
```

Aggregate only -- no hostname, no instance URL, no user ids, no team names, no content.
`effective_members` is `TelemetryService::countUniqueTeamMembers()` (DESIGN §2.34), the same
number the Reporting tab shows the customer, so the figure they read is the figure they are
invoiced. The License tab prints the stored payload back verbatim, which is the assurance an
administrator and their DPO need; it just has no button beside it.

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
- **`POST /api/v1/admin/license/trial`** — removed 4.4.0. Server-to-server trial-request endpoint. Superseded by the "Request trial by email" mailto in the License tab, which opens the admin's mail client and sends the instance UUID to `sales@doekworks.eu` (was `teamhub@tldr.host` until 4.11.0); Justin issues the JWT by hand from the licensing dashboard and replies. **The path is back in 4.14.12 with a different contract** (see `POST /api/v1/admin/license/trial` above): the portal issues the key from the partner portal's trial registry, and the app installs it.

---

## Unified-search providers (added 3.84.3 / pre-existing)

NC's unified search calls `IProvider::search` on every registered provider. TeamHub registers five:

| Provider | ID | `getOrder` | Surfaces |
|---|---|---|---|
| `TeamSearchProvider` (new in 3.84.3) | `teamhub-teams` | 49 | Teams the searcher is a member of (direct or via group / sub-team), filtered to user-created teams (`circles_circle.source=16`), pending-deletion excluded. Result entry deep-links to `/apps/teamhub/#/team/{teamId}`. |
| `MessageSearchProvider` (pre-existing) | `teamhub-messages` | 50 | Messages in teams the searcher belongs to. |
| `DecisionSearchProvider` (pre-existing) | `teamhub-decisions` | 51 | Decisions in teams the searcher belongs to. |
| `ServiceSearchProvider` *(4.11.11)* | `teamhub-services` | 52 | The service catalog as **this viewer** sees it — `ServiceCatalogueReader::forUser()`, the same list the Services page renders, so the per-viewer cards (quota, more time) are filtered before matching. Matches label, description, offering team and category. Links to `?services=1`. |
| `ServiceRequestSearchProvider` *(4.11.11)* | `teamhub-service-requests` | 53 | The searcher's **own** service requests — `listForParticipant()` narrowed to those they started, so a desk member's claimed work is not listed here. Open before finished, then most recently touched. Links to `?mywork=1`. |

The two service providers answer with **no section at all** when the licence or the administrator's *Service teams module* switch is missing, rather than an error in the search drawer.

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

### `POST /api/v1/teams/{teamId}/intravox/subpage` (added 4.13.62)

Creates a page **under** the team's own IntraVox page. This is what the *Create Intranet page* action calls. Until 4.13.61 the browser POSTed straight to `/apps/intravox/api/pages`, deriving the parent path and a slug of its own; that was the only place in the app that named an IntraVox route from the client, and the reason two slug rules governed one page tree.

**Auth**: team **admin** required (`MemberService::requireAdminLevel`) — the same gate as creating and deleting the team's own page. It was a *moderator* action in the interface until 4.13.62, which meant moderators were offered buttons that answered 403; the buttons moved to the admin gate in the same change.

**Body**: `{ "title": "Onboarding" }`. The page's id is derived server-side by `IntravoxService::slugify()`, the same rule the team page's own slug comes from — the client sends no id.

**Response 200**: `{ "success": true, "result": { "page_created": true, "page_id": "onboarding", "team_page_created": false } }`.

`team_page_created` is `true` when this call **also created the team's own page**, which it does when that page is missing. That state is why the endpoint exists: every sub-page is created at `{intravoxParentPath}/{teamSlug}`, and when that page is absent IntraVox refuses the child with *"Permission denied: cannot create pages in this location"* — nothing is denied, the location is not there. The interface had no way out of it, because *Create Intranet page* makes a child and *Delete Intranet page* disables itself once there is nothing left to delete. The repair goes through `IntravoxService::createPage()`, so the page is identical to a provisioned one. The client says so in its toast when the flag comes back true.

**Failures**: `400` — IntraVox absent or unreachable; an empty title; a title or team name with no usable slug (rejected rather than silently renamed, because IntraVox keys a page by its id and a second fallback id under one parent is a collision the user cannot see coming); or IntraVox threw. `403` — not a team admin.

**When the team page itself cannot be created**, the remaining explanation is the configured parent path, so the error names it: *"This team has no intranet page yet and one could not be created under "en/teamhub/acme". Check that this path exists in IntraVox; it is set as the intranet parent path in Admin → TeamHub → Integrations."* It does not claim the path is missing — only that it is the thing to check — because nothing in the bound IntraVox surface resolves a path to a page and can prove it.

**Cache**: invalidates the sub-pages cache on success.

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

### `DELETE /api/v1/admin/import/teams` (added 4.13.58)

Clears the *Recent imports* list: deletes every run that is over, and its rows.

**Response 200**: `{ "removed": 3 }` — how many runs went.

A run whose status is `running` is **left alone** — it is in flight, and dropping it would take work off somebody's screen and orphan the button that reopens it. Every other status goes, `validated` included: an unconfirmed preview cannot be reopened from the panel, so it is history like any other row. So `removed` can be lower than the number of rows the list showed, and `0` is a normal answer. If a `validated` run is cleared while another administrator has that preview open, their confirm fails with `404` rather than importing part of a file that no longer exists. The teams a cleared run created are untouched — the run row is bookkeeping about an operation, not the operation's result.

**Failures**: `403` not an admin.

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

**Response 200**: `{ "templates": [ … ], "liveAtCreation": true, "apps": [ … ], "modules": [ … ], "managedBits": 8568, "teamsByTemplate": { "<templateKey>": 3 } }`.

Each template carries `expiryEnabled` and `expiryDefaultDays` (4.8.3, replacing `offerExpiry`). `liveAtCreation` became `true` in 4.8.3: the wizard and the importer read this table, so an edit here reaches team creation.

`teamsByTemplate` (4.13.24) is one grouped query over `teamhub_team_type`, giving the number of teams created from each key. A key with no teams is absent, so a reader takes it as 0 rather than having to tell "none" from "unknown". It is context for the delete confirmation — never the authority for it; `destroyTemplate` counts again and reports the real figure back.

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

### `POST /api/v1/admin/policy/templates` (4.13.24)

**Body**: `{ templateKey, label, description?, apps?, modules?, expiryEnabled?, expiryDefaultDays?, preselectConfig?, sortIndex?, defaultProfileKey? }`.

**Response 200**: the stored template, in the same shape `listTemplates` returns.

Create a team template. Every field is validated by the same service helpers the `PUT` uses, so the two cannot drift.

There was **no create route until 4.13.24**: `teamhub_template` only ever gained rows from `SeedTemplatesAndProfiles`, so the five templates TeamHub ships were the only kinds of team an estate could have, and an admin screen with an Edit button on every row could rename them and nothing more.

**No migration behind it.** `template_key` is the table's primary key, and the create-team wizard, the CSV importer and the exporter all read the table live (`liveAtCreation`), so a template created here is offered the moment it is saved.

`templateKey` is lower-cased and must match `/^[a-z][a-z0-9_]{1,31}$/` — the same rule a profile key follows. **400** when it is taken, and **400** when it matches one of the keys TeamHub seeds: that one would be re-inserted by the repair step at the next `occ upgrade` and silently merge with what the administrator built.

### `DELETE /api/v1/admin/policy/templates/{templateKey}` (4.13.24)

**Response 200**: `{ template_key, label, teams_affected, inclusions_removed }`.

Three refusals, each **400**:

- a **seeded** template — the repair step re-inserts the shipped set on every upgrade, so a delete would undo itself at the next `occ upgrade` with the administrator's edits gone;
- the **last** template — the create-team wizard requires one, so an instance with none could not create a team at all. The same promise `destroyProfile` keeps;
- nothing else. In particular, **teams created from the template do not block it.**

`teamhub_team_type.type` keeps the key, so those teams keep every app, module and setting they were created with — a template describes what a team is *made* with, not what it is, which is the same reason the key is never renamed. What they lose is the *future* relationship to the definition: a policy propagation has no template to push, and the integration packages the template included stop being effective for them, because inclusion is read live.

`inclusions_removed` counts the `teamhub_integ_tmpl` rows dropped. This is the one place the "nothing is deleted when an integration is switched off" promise does not hold, and the template key is the distinction: removing a package *from* a template keeps every per-team preference because the template is still there to re-include it into, whereas a deleted template's key names nothing. A row left behind would be unreachable until somebody created a template with the same key, and would then switch integrations on for teams nobody asked. Per-team shown/hidden preferences and ordering are still untouched — those hang off the capability and the team, not the template.

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

### `GET /api/v1/admin/audit/integrity` (4.13.17)

**Response 200**: `{ verdict, anchors, verifiedAnchors, verifiedRows, unanchoredRows, rowsWithoutHash, firstBreak, lastAnchoredAt, keyFingerprint }`.

Recomputes the audit trail's tamper-evidence chain and reports (DESIGN §2.191). `verdict` is one of `ok` | `broken` | `unkeyed` | `not_anchored` | `key_rotated` | `truncated` — **not a boolean**, because the last four are reasons the question cannot be answered rather than answers, and reporting them as "not ok" would accuse an administrator of tampering that did not happen. `firstBreak` is `{ anchorId, fromId, toId, reason }` or null; `reason` is `row_content` | `row_count` | `range_digest` | `chain_link` | `key_fingerprint`.

Real work — it re-hashes up to `AuditIntegrityService::VERIFY_ROW_BUDGET` (50,000) rows — so it is a button on the Compliance tab rather than part of that tab's summary fetch. The cheap half (`anchors`, `lastAnchoredAt`, `unanchoredRows`, `rowsWithoutHash`, `keyed`) rides `GET /api/v1/admin/compliance/summary` as `audit_integrity`. NC-admin gated in the service, `#[NoCSRFRequired]` because it is a read.

Writing the chain is **not** an endpoint: `AuditMirrorJob` anchors hourly and records the retention purge. There is no way to make the server anchor on request, deliberately — a chain an administrator can advance by hand is a chain whose timing proves less.

## Integration packages (4.13.23, amended 4.13.24)

The package/capability model. Addressed by stable identity (`app_id` /
`integration_key` / `capability_key`), never by a database id, so a per-team
preference survives the owning app being disabled, upgraded and reinstalled.
`developers.md` is the contract an integrating app reads; this section is the
wire shape.

These went undocumented here when they shipped in 4.13.23 and are written up
now, with the 4.13.24 amendments marked.

### `GET /api/v1/admin/integrations` · `…/{pkgId}/enable` · `…/{pkgId}/disable` · `…/{pkgId}/disable-preview`

NC-admin. The second governance level: which integrations this server has, and
which an administrator has switched on. `disable-preview` is a mandatory
impact preview — a disable is one click that can empty a widget grid and a tab
bar across every team of several templates.

`capability_summary` counts `widgets`, `menu_items`, `my_work_providers` and
`other`. **4.13.24**: a `widget_iframe` capability counts under `widgets`. An
administrator reading "2 widgets" before a disable is counting cards that will
vanish from a home view, and which side of the frame TeamHub draws is not
their question.

### `GET /api/v1/admin/integrations/templates` · `…/templates/{templateKey}/include/{pkgId}` (`POST` │ `DELETE`) · `…/preview/{pkgId}` · `…/reconcile/{pkgId}`

NC-admin. The third governance level: which templates include which packages.
`GET` returns `{ templates: [{ template_key, label, team_count, included: […] }], eligible: […] }`.
The selector may offer only `eligible` — a package that is available,
registered, compatible and enabled — so an administrator cannot pick something
the server would refuse. A template referencing a package that has *since*
become unavailable keeps the reference and reports `included_but_unavailable`
with the reason, because silently dropping a saved reference hides exactly the
problem the administrator needs to see.

**Unchanged in 4.13.24.** What moved is only where the UI lives: the inclusion
matrix was a section of Administration → TeamHub → Integrations and is now
inside each template's own editor on the Policy tab. Same routes, same bodies,
same `templateKey`.

`include` and `remove` take effect for every team of the template immediately —
inclusion is read live, so a team cannot drift from its template.
`reconcile` therefore repairs nothing: it fires the lifecycle hooks and records
the outcome per team, and a run that reports every team `ok` having changed
nothing is the normal result for a package with no lifecycle provider. It is
offered only when the change reports `reconcile_recommended`.

### `GET | PUT | POST /api/v1/teams/{teamId}/integrations/presentation[/…]`

**Team-admin.** The fourth level, and the only one a team administrator
touches: `presentation/{capId}/show`, `presentation/{capId}/hide` and
`presentation/reorder`.

There is deliberately **no route here that can enable, disable, include or
remove a package, or touch My Work.** Hiding is refused for a non-visual
capability rather than quietly stored — a hide that reached a My Work provider
would be a team administrator switching off something they do not control.

`reorder` takes a `capType` and the capability ids in order. **4.13.24**: it
matches on the *surface*, not the raw type, so naming either widget type
reorders both — `widget` and `widget_iframe` are two types on one grid and the
manager lists them together, so matching on the type would silently skip every
canvas card in the list the administrator just dragged and write a partial
order while reporting success.

### `GET /api/v1/teams/{teamId}/integrations/packages`

**Any team member.** What this team actually renders:
`{ widgets: […], menu_items: […] }`. Hidden capabilities are **absent**, so
nothing downstream constructs their provider. No `php_class` in the payload:
an ordinary member has no use for one and publishing it describes the owning
app's internals.

Each `menu_items` entry carries `url` — the stored `iframe_url` re-validated
by `IframeUrl` and carrying TeamHub's own verified team id as `?teamId=`. The
team id comes from the membership check that produced the list, never from the
stored URL or from request input. A stored URL that no longer passes the rule
is **dropped and logged**, not rendered with an empty `src`.

**4.13.24**: `widgets` now holds both kinds of card, interleaved by the team's
own arrangement rather than grouped by kind, and every entry carries
`cap_type`. A `widget` carries no `url` and its body is fetched from the
widget-data endpoints below; a `widget_iframe` carries `url` on the same terms
as a tab and its body is a frame the browser fetches. `cap_type` is what the
frontend reads to tell them apart — the order cannot, which is why it is in
the payload.

**4.13.29**: a `widget_iframe` entry also carries `actions` — the header links
the capability declared at registration, each `{ label, icon, url }`, at most
ten, in declaration order. Absent capabilities aside, it is `[]` when none were
declared. Every `url` is treated exactly like the card's own: re-validated by
`IframeUrl` and carrying the verified `?teamId=`, with an action whose stored
URL no longer passes **dropped and logged** while the rest of the menu renders.
A `widget` entry has no `actions` key — a row widget's actions come back from
its provider at render time through the widget-data endpoints instead, because
they can depend on what the provider found.

### `GET /api/v1/teams/{teamId}/integrations/packages/all-widget-data`

**Any team member.** Every shown row widget's data in one request: one
membership check, one effective-state pass, and a per-widget error instead of a
failed page. Items are capped at 20 and actions at 10 per widget; a provider
that throws yields an error card and leaves every other widget alone.

**4.13.24**: a `widget_iframe` is skipped here rather than reported as an
error. It has no provider and nothing to batch.

### `GET /api/v1/teams/{teamId}/integrations/packages/{appId}/{integrationKey}/{capabilityKey}/data` · `…/form` · `…/submit`

**Any team member.** One widget's data, one action's form, one action's
submission. `mayRender()` rather than `isEffective()`: a hidden widget's
provider must not be called even by a direct request — both the performance
property and the "frontend filtering is not a boundary" rule.

**404** for a capability that is not a widget, and **4.13.24** adds a distinct
404 for a `widget_iframe`: *"That capability draws its own card in an iframe.
It has no widget data to fetch."* A mistake worth naming rather than an empty
200.

### `POST /api/v1/ext/integrations/register`

The legacy single-registration route. **`php_class` is not accepted over HTTP**
since 4.13.23 — a widget names a class TeamHub executes, so it must be
registered in-process. `menu_item` over HTTP is unaffected. A `widget_iframe`
is likewise registerable over HTTP, since it names a URL and no class.

### `GET /api/v1/admin/integrations/register` (4.13.17)

**Response 200**: `{ generatedAt, rows: [{ appId, offServer, teamsEnabled, teamsWithData, resources, firstLinkedAt, lastLinkedAt, unhealthy }], hosts: [{ appId, host, teams }] }`.

Where this instance's team data goes (DESIGN §2.194) — the artefact an ISO 27001 A.5.19 supplier review opens with. Derived from `teamhub_team_apps` (intent), `teamhub_team_app_resources` (reality), `teamhub_resource_link` (history) and `teamhub_openproject_link.host`; **no storage of its own**. Off-server integrations sort first. TeamHub's own modules are not listed — see `TeamApps::INTEGRATIONS`.

`teamsEnabled` and `teamsWithData` deliberately disagree: a team can have an integration switched on with nothing provisioned, and can hold a resource that was discovered rather than created here.

### `GET /api/v1/admin/integrations/register/{appId}` (4.13.17)

**Response 200**: `{ appId, teams: [{ teamId, name, enabled, resources, firstLinkedAt, lastLinkedAt, host, project }], truncated }`. **400** for an app id that is not an integration this instance registers. Capped at 500 teams.

### `GET | POST /api/v1/teams/{teamId}/members/review` (4.13.17)

**GET 200**: `{ status: { required, periodMonths, dueAt, overdue, withinNotice, lastReviewedAt, lastReviewedBy, memberCount }, history: […] }` (the ten most recent attestations).
**POST** takes `{ note?: string }` (≤ 500 characters) and answers `{ status }`.

Membership recertification (DESIGN §2.193). `required` is false — and every other field quiet — unless the team's policy profile governs `member_review_months` with a value above 0; an unclassified team, an ungoverned field and a period of 0 are the same answer. `withinNotice` opens two weeks before `dueAt`, which is when the My Work row appears.

**Team-admin gated**, not NC-admin: the asset owner performs the review, and a server administrator attesting a list they do not know is a control that exists only on paper. `requireAdminLevel()` carries the parent-admin fallback, so an admin of a main team can attest one of its sub-teams. The POST is CSRF-protected and writes both an append-only `teamhub_member_review` row (the evidence, which outlives the audit retention horizon) and a `member.review_completed` audit event (the actor trail).

The same attestation is reachable as the **Confirm membership** action on the My Work row, which is the one place `/mywork-workflows` allows a workflow button outside the row: the opening action belongs where its subject lives, and the subject is the member list.

**Also in 4.13.17, not an endpoint change but a payload one:** every member in `GET /api/v1/teams/{teamId}/members` and `…/members/manage` now carries `joinedAt` — epoch seconds from Circles' own `circles_member.joined`, `0` when that column holds nothing (it is nullable and older rows have none). The frontend renders `0` as *Joined date unknown*, never as 1970.

### `GET | PUT /api/v1/admin/policy/teams-frontend` (4.13.16)

**Response 200**: `{ "available": bool, "enabled": bool, "decided": bool, "systemValue": "" }`. The PUT takes `{ "enabled": bool }` and answers with the same shape.

**The one endpoint pair in this app that writes another app's config value** — `circles`' `frontend_enabled`, which gates Nextcloud's own Teams app: its entry in the app menu, `/apps/circles/teams` (404 when off), all 26 `LocalController` OCS endpoints (`frontend disabled`, which is also what Contacts' own team view calls), the Teams dashboard widget and its controller. Circles' PHP API, the share provider, the share picker, DAV principals and the `circles_*` tables are **not** behind that gate, so TeamHub keeps working with the frontend off. `TeamsFrontendService` has the full list and the reasoning; DESIGN §2.190 has the decision.

`available` is false when the Circles app itself is not enabled, and the PUT then answers **422**. `systemValue` is Circles' `config.php` override (`circles.frontend_enabled`), reported because the app value this endpoint writes takes precedence over it — reported, not obeyed.

`decided` says whether this instance has an explicit answer at all (an app value, or a system value in `config.php`) rather than running on Circles' default of on. It is what the admin-settings **setup checklist** row grades on — either answer counts — and it is why the PUT writes `'0'` instead of deleting the key. Both the Policy tab and the first admin tab read this GET; only the Policy tab writes.

Both are `#[AuthorizedAdminSetting]` with a **full Nextcloud administrator** re-check in the service, exactly like the policy routes: a delegated TeamHub admin passes the attribute and is refused by the service. The GET is `#[NoCSRFRequired]`; the PUT is not. A state change is audited under `AuditService::INSTANCE_SCOPE` as `instance.teams_frontend_changed`; a write that changes nothing is not.

---

## Files ⋯ menu endpoints (added 4.11.42)

What TeamHub's two entries in a file's ⋯ menu ask the server. Both are called **from the Nextcloud Files app**, a page TeamHub does not render, and both are gated exactly like everything else: the caller comes from the session, the teams from `TeamService`, and every file is resolved in that user's own folder tree, so a file they cannot see drops out without a special case. `#[NoAdminRequired]`, no `#[NoCSRFRequired]`, both reads.

**`fileAction#scopes` replaced `fileReview#getScopes` in 4.11.42.** The old endpoint was licence-gated, because reviews are; *Propose a decision* is not licensed, and riding on that endpoint would have hidden it on every unlicensed instance. So the gate moved off the endpoint and onto each action — and one call still answers for both, because the entry runs on every Files page load and a second round trip is a second cost on a page TeamHub only visits. `FileReviewService::listScopes()` is gone with it.

### `GET /api/v1/file-actions/scopes`

The caller's team-folder path prefixes and what each team allows, so the Files app's **synchronous** `enabled()` callback can decide whether to offer an entry without a round trip per file.

**Response 200**: `{ "scopes": [ { "teamId", "teamName", "paths": ["/Team Alpha"], "actions": ["review", "propose"] } ] }`

`actions` holds `review` (file reviews on globally — which needs a licence — and on for the team), `propose` (the Decisions module on globally and for the team), or both. A team that allows neither, and a team with no folder, are **omitted** rather than returned with a flag: the caller's only question is "may I offer this here", and per `CLAUDE.md` § Permissions an entry that does not apply is hidden rather than disabled.

`paths` are **root-relative** — `/Team Alpha`, not `/alice/files/Team Alpha` — because that is what the Files app's client-side `Node.path` is. A user in no team gets `[]`, and no entry ever appears for them.

### `GET /api/v1/teams/{teamId}/file-actions/propose-context`

**Query**: `fileId` — required.

What the deep link into the proposal composer needs about one file. The entry does not open a composer of its own: it opens TeamHub at `?team=…&propose=1&file=…`, and `App.vue` calls this to find out whether the file may be quoted and what it is called.

**Response 200**: `{ "eligible", "teamId", "teamName", "fileName" }`

`eligible: false` (with an empty name) is returned — **not** an error — when the Decisions module is off for the team, the file is not in that team's folders, or the caller cannot read it. The client's prefix test can legitimately disagree with the server if a folder was detached or the module switched off between page load and click, and that is a "no" to render rather than a fault. **`403`** when the caller is not a member of the team.

The file **name comes from here rather than from the deep link** on purpose: it ends up inside the proposal's Context field as markdown, and a name carried in a URL is a name anybody can choose.

---

## File review endpoints (added 4.8.18)

Asking teammates to review a file. Design: `FILE-REVIEW-PLAN.md`; the module's own reasoning lives in `FileReviewService`.

**One of these is called from the Nextcloud Files app** — `review-context`, by the review dialog, on click. It is gated exactly like the rest: every method re-establishes the caller and their membership, and none trusts a team or file id because it arrived in a URL. All are `#[NoAdminRequired]` and none carries `#[NoCSRFRequired]`. The folder-prefix call that used to sit beside it is now `fileAction#scopes`, above.

Every endpoint requires the module to be on **globally and for the team**; a team with it off answers `403`.

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

### `GET /api/v1/openproject/connection`  *(added 4.13.5; `transport` added 4.13.21)*

Where the **current user** stands with TeamHub's own OpenProject connection, for the personal settings panel. Module-gated; 401 without a session. `POST` on the same path begins the authorization and answers the OpenProject URL to send the browser to (a JSON answer rather than a redirect, so the panel can show a reason instead of navigating into a 422); `DELETE` disconnects.

**Response 200**: `{ "configured", "methodSupported", "transport", "authMethod", "host", "connected", "openProjectUser" }`.

- **`configured` answers a narrower question than it looks** — it is about **TeamHub's own** OAuth client, so it is `false` on a working instance whose OpenProject runs through the official `integration_openproject` app. Reading it as "OpenProject is set up here" is what caused the 4.13.21 bug; use `transport` for that.
- **`transport`** (4.13.21) — `direct` or `integration_app`, from `OpenProjectTransportSelector::forUser()`. The panel needs it because the two cases want opposite things: on `direct` it offers its own Connect button, on `integration_app` it points at `/settings/user/openproject`, because the official app owns the tokens and a credential written here would be read by nothing. DESIGN §2.198.
- No secrets: `host` is the URL users are sent to, `openProjectUser` the name they see in OpenProject's own header. The OAuth client secret is never returned by any route.

**Where a user connects is `/settings/user/teamhub#openproject`**, TeamHub's own section — not the official app's page, which on a `direct` instance may not exist at all. `personalSettingsUrl()` in `src/lib/openProject.js` is the single source of that link.

### `GET /api/v1/openproject/projects`

Projects the **current user may link** — administered by them in OpenProject (the resource carries an `update` link) or public — for the team-creation wizard's picker. Per user, not per team: the team does not exist yet. Gated on the right to create a team. **Query**: `q` (string, ≤ 200 chars, matched on name or identifier), `limit` (int, capped at 25). Rate limit 30/min.

**Response 200**: `{ "projects": [ { "id", "identifier", "name", "active", "public", "canEditProject", "linkable", "linkedTeam" } ], "limit": 25 }` — every element has `linkable: true`; projects the user can merely see are not returned. `linkedTeam` (4.9.4) is `null`, or `{ "teamId", "name"|null }` for the team that already links the project — `name` only when the caller is a member of it. The picker lists such a project as taken and will not select it; `POST /api/v1/teams` refuses it again with 409.

### `GET /api/v1/teams/{teamId}/openproject/link`

Member-gated. **Response 200**: `{ "link": { … } | null, "capabilities": { … } }` — capabilities not probed. A link is `{ projectId, projectIdentifier, projectName, host, stale, createdBy, createdAt, updatedAt, lastValidatedAt, urls: { project, workPackages } }`.

**Layout bundle, `openProjectConfig.project` gains `wikiUrl` in 4.13.13.** `{ id, identifier, name, url, wikiUrl }` for a linked team. `wikiUrl` is the project's wiki table of contents in OpenProject (`/projects/{ref}/wiki/index` — OpenProject routes the index there and leaves the bare `/wiki` without a route). It is a **deep link only**: API v3 mounts wiki pages as `GET /api/v3/wiki_pages/{id}` with no index and no project-scoped collection, so a project's wiki pages cannot be listed, and the Pages widget offers one row out to OpenProject rather than a page list.

**`PUT /api/v1/teams/{teamId}/openproject/link` was removed in 4.9.4.** The link is made by `POST /api/v1/teams` (`openProjectId`, § Team creation) as part of creating the team, and removed only by an NC admin through `DELETE /api/v1/admin/maintenance/teams/{teamId}/openproject-link`. There is no change and no re-link endpoint. The team-delete cascade removes the row.

### `POST /api/v1/teams/{teamId}/openproject/test`

Team-admin gated, rate limit 10/min. A forced capability probe plus, when linked, a read of the linked project. **Response 200**: `{ "capabilities", "link", "project" | null, "projectError": { error, code, administratorMessage } | null, "testedAt" }`.

### `GET /api/v1/teams/{teamId}/openproject/overview`

Member-gated, rate limit 60/min. **Query**: `refresh` (bool — bypass the 5-minute per-user cache, subject to a 10 s cooldown). Shape in `OPENPROJECT.md` §3.5: `project`, `counts` (each count may be `null` = could not be read), `dueSoonDays`, `nextMilestone`, `version`, `recentlyCompleted`, `files`, `warnings`, `retrievedAt`, `fromCache`.

`version` *(4.13.13)* is the OpenProject version the project is in now — its current sprint or release — or the next one due to start: `{ id, name, startDate, endDate, status, upcoming, url }`, or `null`. Read from `GET /api/v3/projects/{id}/versions`, which answers the project's `shared_versions` (a parent project's included) as an **unpaginated** collection, and needs `view_work_packages` **or** `manage_versions` in the project — a member without either gets `null` and a `version:<code>` warning, never a failed overview. Only `open` versions are considered; among those running (a missing bound is open-ended) the soonest to end wins, and `upcoming` is `true` when nothing is running and the next one to start is shown instead.

### `GET /api/v1/teams/{teamId}/openproject/work`

Member-gated, rate limit 60/min. **Query**: `section` — `upcoming` (the only section since 4.9.5: the project's open work packages of **every assignee** that carry a due date, soonest first; it feeds the team home's Upcoming tasks widget — the 4.9.3 sections `assigned` / `overdue` / `dueThisWeek` / `recentlyUpdated` are gone with the widget they served, and the viewer's own rows are in My Work through `OpenProjectWorkProvider`); `page` (int ≥ 1, OpenProject page number); `pageSize` (int, 1–25, default 10); `refresh` (bool).

**Response 200**: `{ "section", "items": [ { id, subject, type, status, priority, assignee, responsible, project, startDate, dueDate, date, percentageDone, createdAt, updatedAt, url } ], "total", "page", "pageSize", "hasMore", "retrievedAt", "fromCache", "canCreateWorkPackage" }`. Everything is fetched as the caller, so the list can never contain a work package they could not open in OpenProject. `canCreateWorkPackage` (4.9.15) is whether OpenProject grants the caller *add work packages* on the project (the `createWorkPackage` link on the project resource, read with the page and cached with it; `false` when that read fails) — the Upcoming tasks widget shows its *Create OpenProject work package* action exactly when it is true.

`canEditWorkPackages` (4.13.9) is whether the **OpenProject connection** can send HTTP PATCH at all; each item additionally carries `canEdit`, which is OpenProject's answer for that one work package (its own `update` / `updateImmediately` link). The widget shows a row's *Change* action only when **both** are true, and hides it otherwise rather than disabling it — on a connection that cannot write there is no permission anybody could grant to ungrey it. Items also carry `lockVersion`, which a PATCH must quote.

### `GET /api/v1/teams/{teamId}/openproject/work-packages/form`  *(added 4.9.15)*

OpenProject's create form for the linked project, as the caller: what the Upcoming tasks widget's create modal may offer. Member-gated, rate limit 30/min, not cached. `POST work_packages/form` with the project in `_links`; the types are the schema's inline allowed values, the assignees are read from the collection the schema names (followed only when it is an `/api/v3/` path; users only, ≤ 200, by name) — a failed assignee read costs the list, not the form.

**Response 200**: `{ "types": [ { id, name } ], "defaultTypeId": int | null, "assignees": [ { id, name } ], "assigneesUnavailable": bool }`. A caller OpenProject does not let add work packages gets its 403 → `permission_denied`.

### `POST /api/v1/teams/{teamId}/openproject/work-packages`  *(added 4.9.15)*

Create one work package in the linked project, **as the caller** — the one OpenProject write a team member makes from the team home. Member-gated, rate limit 30/min, CSRF-checked. **Body**: `subject` (string, 1–255, required), `typeId` (int, required), `assigneeId` (int | null), `dueDate` (`YYYY-MM-DD` | null), `description` (markdown, ≤ 5000). TeamHub checks shape and bounds (400 with `error` on a miss, before OpenProject is asked); OpenProject checks its rules and answers 422, returned as **400** with `code: validation_failed` and OpenProject's own sentence in `error`. One `POST work_packages` (the generic route; OpenProject 17 deprecates `projects/{id}/work_packages`), no notification switch — OpenProject notifies as it would for a work package made in its own UI. Afterwards the team's OpenProject cache generation is bumped, so every member's next `work` and `overview` read is fresh.

**Response 201**: the created work package in the `work` item shape (with `url`).

### `GET /api/v1/teams/{teamId}/openproject/work-packages/{workPackageId}/form`  *(added 4.13.9)*

OpenProject's **edit** form for one work package, as the caller. Member-gated, rate limit 30/min, not cached. One `POST work_packages/{id}/form` with an empty body — the form is the only way to learn two things that must not be guessed: **which status transitions are legal** (a workflow restricts them per type and per role, so the full status list would offer choices OpenProject refuses) and the current **`lockVersion`**, which the PATCH has to carry. Both are read from the same response, so the version belongs to the same moment as the allowed values.

**Response 200**: `{ "workPackage": <work item shape, plus lockVersion and canEdit, with url>, "types": [ { id, name } ], "statuses": [ { id, name } ], "assignees": [ { id, name } ], "assigneesUnavailable": bool }`. A caller OpenProject does not let edit gets its 403 → `permission_denied`. A form that carries no `lockVersion` is refused as `unsupported_response` rather than handed back, because a PATCH cannot be sent safely without one.

### `PATCH /api/v1/teams/{teamId}/openproject/work-packages/{workPackageId}`  *(added 4.13.9)*

Change one work package, **as the caller** — the write DESIGN §2.124 and §2.126 recorded as impossible, because it needs HTTP PATCH and the official integration app's request layer sends GET, POST, PUT and DELETE only. On a connection that cannot send it, this answers **501** with `code: method_unsupported` and **no request is made**; `OPENPROJECT.md` §3.1a has which connection can.

Member-gated, rate limit 30/min, CSRF-checked. **Body**: `lockVersion` (int, **required** — the one from the edit form), plus any of `subject` (1–255), `typeId`, `statusId`, `assigneeId`, `dueDate` (`YYYY-MM-DD`), `percentageDone` (0–100), `description` (markdown, ≤ 5000). **Only the fields present in the body are changed**, and `assigneeId` or `dueDate` sent as `null` **clears** them — absent and null are deliberately different. A body carrying `lockVersion` alone is refused locally (400): OpenProject would accept it and bump the version, which reads as an edit in its activity log.

TeamHub checks shape and bounds before asking. OpenProject then answers:

| Upstream | Returned | Meaning |
|---|---|---|
| 409 | **409** `edit_conflict` | the `lockVersion` is behind — somebody changed it first. Optimistic locking working: the edit is refused rather than overwriting theirs. Carries OpenProject's own sentence in `error`. |
| 422 | 400 `validation_failed` | a rule on OpenProject's side refused the input, with its sentence |
| 403 | 403 `permission_denied` | OpenProject does not let this caller edit it |

**Response 200**: the updated work package in the `work` item shape (with `url`, `lockVersion` and `canEdit`). Afterwards the team's OpenProject cache generation is bumped, so every member's next `work`, `overview` and My Work read is fresh.

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

### `POST /api/v1/provisioning/{id}/acknowledge` *(added 4.13.30)*

**Body**: none. Accepts an operation whose status is `attention`, setting it to `completed` so the team page stops reporting it. Answers the operation as `GET /api/v1/provisioning/{id}` does.

**400** when the status is anything but `attention`. A `failed` operation is deliberately refused: an attention note means the workspace is usable and something is worth a look, while a failed required step means it is not, and accepting that away would turn a broken workspace into a silent one. Use retry or rollback there.

**The step rows keep their notes**, so the details pane still shows what did not happen; only the banner stops. Who accepted it and when is in the audit log (`provisioning.acknowledged`, with the open step keys) rather than on the operation row, because `ProvisioningMapper::updateOperation()` writes a fixed set of columns and a new one would have needed a migration for a fact the audit trail already carries.

**Why it exists.** `attention` previously had one exit, retry, and that is not always an exit: a step re-runs against the request frozen when the operation started, so a note about an integration the template no longer carries comes back every time. Reported by Justin on 2026-10-26, on a team that kept reporting Intravox after Pages, Collectives and Intravox had all been removed from its template.

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

**No UI caller since 4.13.17.** Its only reader was the *Workspace provisioning* panel on the Maintenance tab, removed at Justin's request because none of what it offered — retry, continue, rollback — is a Nextcloud administrator's decision; provisioning is driven by the team's creator from the wizard, and a failed run is picked up from the banner on the team page. The route is kept because it is the only instance-wide view of provisioning that exists and costs nothing to leave in place; its per-operation siblings (`/provisioning/{id}`, `…/retry`, `…/rollback`) are still the team-side recovery path and are very much in use.

### `GET /api/v1/admin/policy/openproject-roles`

Rate limit 10/min. The OpenProject roles and templates as the administrator's own connected account sees them: `{ roles, templates }`. For the template editor's OpenProject section.

### `GET | PUT | DELETE /api/v1/admin/policy/templates/{templateKey}/blueprint`

GET: `{ templateKey, stored, blueprint, vocabulary }`. PUT **body** `{ blueprint }` — validated by name (unknown application, module, widget, role key, copy key or behaviour → 400 naming it; `openproject.required` on any template but `openproject` → 400); `apps`, `modules` and the per-application behaviours are re-derived from the template row on every read, so the section stores only what the row cannot say. DELETE resets to the shipped blueprint (`openproject`) or removes it (the others). Audited as `policy.blueprint_updated` / `policy.blueprint_reset`.

**Audit events added**: `provisioning.started`, `provisioning.step_failed`, `provisioning.completed`, `provisioning.needs_attention`, `provisioning.retried`, `provisioning.rolled_back`, `provisioning.run_as_creator`, `openproject.membership_synced`, `policy.blueprint_updated`, `policy.blueprint_reset`.

## Members on another server (payload and behaviour change, 4.14.16)

Design: DESIGN §2.257. A member whose account is on another Nextcloud is `circles_member.user_type = TYPE_USER` with a non-empty `instance`; **there is no federated member type** (4 is `TYPE_MAIL`). They were always in the member lists. What they were missing from is the **count**, and what could not be done to them is the two writes.

**`effective_count` now includes them.** It is computed from `circles_membership` joined to each principal circle, and a federated member has no principal circle on this server, so they were silently absent: a team of two read `1`. Affects `GET …/members`, `…/members/manage` and `…/members/mail`.

**New fields.** Each person in `GET …/members`, `…/members/all` and the `direct` bucket of `…/members/manage` carries `instance` (the owning server, `''` for a local account) and `isRemote`. `…/members/manage` also carries `userType`, `singleId` and `memberId`; `memberId` is Circles' own handle for the row and is what the two writes below need. A remote row in `…/members/all` has no `email`, `phone`, `ncStatus` or `subline`: there is no local account to read them from. `MemberService::getAllEffectiveMembers()` keeps a **local-only default** for its service callers (decision approvers, shift staffing, presence, file reviews, birthdays) and only the controller behind the members widget and `@mention` passes `includeRemote: true`. A test pins that default.

**`DELETE /api/v1/teams/{teamId}/members/{userId}` takes a fourth `type`: `federated`.** It is the frontend saying *this row showed a globe*; the service re-establishes the fact from the row's `instance` before acting, so a wrong `type` can only make a federated removal fail, never make a local one federate. A federated removal is performed by **Circles**, so the member's own server receives `MemberRemove`; a refusal comes back as **400** with Circles' message and nothing is removed locally. There is no raw fallback.

**`PUT /api/v1/teams/{teamId}/members/{userId}/level`** detects the same case from the row itself and routes the change through Circles' `MemberLevel` event. **A member on another server is capped at Moderator (4)**: level 8 answers **400**, and the frontend does not offer it. **400** also when Circles refuses, or when the row carries no valid `member_id` (the §2.249 repair fixes that, and the message says so). Local members are unchanged on both endpoints.

Both URLs now carry a URL-encoded member id: a federated member's id is a cloud id (`someone@their.server`), not a bare uid.

## A team another server owns (added 4.14.15)

One read-only route for a team this instance holds only as Nextcloud Teams' synced copy. Design: DESIGN §2.256. Gated on the administrator's *Teams from other servers* switch (`federatedTeamsEnabled`, off by default) **and** on the circle being a copy owned elsewhere **and** on the caller being in it; anything else answers **404**, which is also the answer for a team that does not exist, so the route discloses nothing about a team the caller is not in.

Deliberately its own controller: every `/teams/{teamId}` route answers for a team this server owns and runs through the registry gate.

### `GET /api/v1/federated-teams/{teamId}`

`#[NoAdminRequired]`. Reads rows Nextcloud Teams has already synced onto this instance; it never calls the owning server and never writes.

**Response 200**: `{ team: { id, name, description, remote_instance, is_remote: true, level, members: [member] } }`

`member`: `{ userId, displayName, level, role (owner|admin|moderator|member), instance, is_remote }`. The roster is read with `user_type IN (1, 4)`, so accounts on the owning server are in it; `instance` is empty for a local account and the owning host for everybody else. `level` on the team is the caller's own, 0 when their access comes through a group or another team.

**404**: not a synced copy, the switch is off, or the caller is not a member. **401**: no session.

**Also on the team list.** `GET /api/v1/teams` (`TeamService::getSidebarTeams()`) now carries a row per synced copy, after the local ones, each with `is_remote: true` and `remote_instance`. These rows are added to the **sidebar** list only: `getUserTeams()`, the authorisation boundary behind My Work, the Files-app actions and file reviews, is unchanged and still returns local teams only.

## Teams made outside TeamHub — the adoption grid (added 4.10.50)

The grid of teams made in Contacts, on the Teams page, by occ or by a provisioning tool, where each is accepted into TeamHub with a template and a policy, or declined. Design: DESIGN §2.149. Every route is `#[NoAdminRequired]` and the service checks `TeamAdoptionDecisionService::mayDecide()`: a **Nextcloud administrator**, or a **member of the team that holds the Nextcloud services** while it holds `team_adoption`. Anybody else gets **403**. CSRF is intact on every route.

### `GET /api/v1/team-adoptions`

**Response 200**: `{ mode, pending: [row], templates: [ { templateKey, label, defaultProfileKey } ], profiles: [ { profileKey, label } ], defaultTemplate }`. **v4.11.39 — `decided` is gone**: the thirty-day history moved to the Compliance tab's audit log (DESIGN §2.172), and the grid was its only reader. `templates` excludes `openproject` and `service`. `mode` (added 4.11.34) is the instance's rule `accept` | `review` | `decline`; the grid reads it for its empty state, which in the two automatic modes must not promise that teams turn up there.

`row`: `{ id, teamId, teamName, ownerUid, ownerName, status (pending|accepted|declined|withdrawn), route (auto|desk|admin|grid), workflowId, templateKey, profileKey, decidedBy, decidedByName, decidedAt, reason, provisionStatus (none|queued|done|failed), provisionNote, detectedAt, claimedBy, claimedByName, claimedByOther }`. On a pending row `templateKey` / `profileKey` come back **resolved** (the defaults filled in), so the pickers show what *Accept* will do. `claimedByOther` is true when another desk member has claimed the request and the viewer is not an administrator; the grid hides the buttons for that row.

### `PUT /api/v1/team-adoptions/{id}`

**Body**: `{ templateKey, profileKey }`. Stores the choice on a pending row, so the request's own *Accept* in a queue or My Work uses it. **200** `{ adoption: row }`; **400** for a template the grid does not offer or a policy that does not exist; **409** when the row is no longer pending.

### `POST /api/v1/team-adoptions/{id}/accept`

**Body**: `{ templateKey, profileKey }` (both optional; defaults apply). Accepts at once: registry row (origin `adopted`), lock, team type, policy; the template's apps follow in `TeamAdoptionProvisionJob`, run as the team owner. When the row has an open request the decider holds, the answer goes through it (a desk member claims then completes; an administrator on the administrators' route completes); an administrator deciding a request in a desk's queue decides directly and the request is cancelled with the reason. **200** `{ adoption: row }`; **409** when already decided, the circle is gone, or another desk member has the request.

### `POST /api/v1/team-adoptions/{id}/decline`

**Body**: `{ reason }` — required (**400** without). Final: the team is never offered again and is left as it is in Nextcloud. The owner is told (through the request's own rejection notice, or `team_adoption_declined` when there was no request). **200** `{ adoption: row }`; **409** when already decided.

**Workflow**: `team_adoption` (`TeamAdoptionDefinition`), the eighth Nextcloud service, system-started — `POST /api/v1/workflows` with it answers 404. Its view carries `reference: { label, url }` for a desk member or administrator (the grid); `reference` is a new optional field on every workflow view, null unless the definition implements `IWorkflowDefinitionReference`.

**Layout**: `serviceDeskConfig.handlesAdoption` (new) — the viewer is a desk member of the team that holds `team_adoption`; the widget `widget-team-adoption` shows on exactly that.

**Notifications added**: `team_adoption_pending` (administrators, unlicensed route, links to `#team-adoption`), `team_adoption_accepted` and `team_adoption_declined` (the owner, when no request carried the answer).

**4.11.32 — the sweep gained a second candidate class.** A circle Circles' SCIM integration provisioned from a directory group (`source = 10001`, `config & 262144`) is now offered too, as `kind: 'scim'`, and such a row is always `route: 'grid'`: it has no human owner, so the three routes that open a request for one cannot apply. See DESIGN §2.166.

**4.11.34 — the customer sets what happens, and the creator group no longer decides.** `teamAdoptionMode` (app config, on `GET`/`POST /api/v1/admin/settings` as `teamAdoptionMode`) takes `accept`, `review` or `decline`, `review` being the default and what an unrecognised value clamps to. `accept` and `decline` decide in the sweep itself, with `route: 'auto'`, `decidedBy: ''` and no request opened; `decline` stores its own reason and notifies the owner with `team_adoption_declined`. Both apply to a `scim` candidate too. Under `review` the three remaining routes are unchanged (desk queue, administrators' My Work, the grid alone). This retires the rule that an instance with no team creator group adopted every such team by itself — that estate now chooses `accept` explicitly. See DESIGN §2.168.

**4.11.35 — what `accept` applies.** `teamAdoptionTemplate` and `teamAdoptionProfile` ride `GET`/`POST /api/v1/admin/settings` beside the mode (length-capped on write; resolved at the point of use, so a deleted template or profile falls back to `collaboration` / `internal`). `decideByItself()` writes the pair onto the adoption row before deciding, exactly as the grid writes a pair a person chose, so the row reports what was applied. A team with **no owner and no decider** — every automatic acceptance of a `scim` candidate — is now classified under `PolicyService::ACTOR_SYSTEM` (`app:teamhub`); before this it reached `assignAtCreation()` with an empty actor, which returns at its no-session guard, and the team was left unclassified. See DESIGN §2.169.

## Directory groups waiting for a team (added 4.11.32)

Groups from LDAP, Active Directory or an identity provider that qualify for a team but do not have one. The surface for `idpTeamsMode = review`; in `auto` mode nothing waits here and in `off` mode the feature does not run. Design: DESIGN §2.166.

**Gating, and how it differs from the adoption grid above.** Every route carries `#[AuthorizedAdminSetting]` **and** re-checks `IGroupManager::isAdmin()` in the controller. This has one home — Admin → TeamHub — and provisioning teams unattended from a directory is pinned to a full **Nextcloud administrator**: a *delegated* TeamHub admin passes the attribute and is then refused. Anybody else gets **403**.

### `GET /api/v1/admin/idp-groups`

**Response 200**: `{ mode (off|review|auto), pending: [row], serviceAccountProblem }`. **v4.11.39 — `decided` is gone**, for the same reason as the adoption grid above: what has been decided is in the audit log (DESIGN §2.172).

`row`: `{ id, groupId, displayName, backend, groupDn, memberCount, detectedAt }`. `backend` is what `IGroup::getBackendNames()` reported when the group was found (`LDAP`, `Database`, …) and `groupDn` is the LDAP DN when there is one — both recorded at detection, because neither can be read again once the group is gone. Pending rows are **re-checked against the current rule** before they are returned: a row recorded under different settings is not offered.

`serviceAccountProblem`: `''` when provisioning can run, otherwise `no_account`, `no_such_account`, `account_disabled`, `account_may_not_create_teams` or `unavailable`. A reason rather than a bool so the panel can say what to fix.

### `POST /api/v1/admin/idp-groups/{id}/create`

**Body**: `{ templateKey, profileKey }` (both optional; the configured defaults apply). Makes the team now, as the configured service account: `createTeam` → policy → the template's privacy bits → team type → the template's apps → the group as a member. **200** `{ ok, teamId, note }` — `ok: false` with a reason when it could not start, and `ok: true` with a non-empty `note` when the team exists but a step did not finish. **400** when the group has already been decided (two administrators in one grid: the row leaves `pending` atomically, so the loser is told rather than creating a second team). **404** for no such row.

### `POST /api/v1/admin/idp-groups/{id}/decline`

**Body**: `{ reason }` (optional). Final, like a declined adoption: the row stays and the sweep skips every group that has one. **200** `{ declined: true }`; **400** when already decided; **404** for no such row. **v4.11.39** — both this and `/create` go through `IdpGroupRequestService`, which writes the decision on the row **first** and then cancels the administrators' request for that group; the order is what carries the template and policy chosen per row, since a directory group stores no pair.

**Admin settings added** (on `GET`/`POST /api/v1/admin/settings`, under `idpTeams`): `mode`, `backends`, `ldapOu`, `ldapDnAvailable` (read-only — false when user_ldap is absent, and the panel then does not offer the sub-OU field), `pattern`, `template`, `profile`, `serviceAccount`, `onGroupGone`, `serviceAccountProblem` (read-only). Written as the flat keys `idpTeamsMode`, `idpTeamsBackends`, `idpTeamsLdapOu`, `idpTeamsPattern`, `idpTeamsTemplate`, `idpTeamsProfile`, `idpTeamsServiceAccount`, `idpTeamsOnGroupGone`.

**4.11.33 — `onGroupGone` takes `archive_then_delete` | `delete`.** Both delete; the first produces an archive through `ArchiveService::produceTeamArchive()` first, regardless of the Archive tab's own `archiveBeforeDelete`, because choosing it here *is* the instruction to archive. `keep_and_report` is retired and reads as the default, `archive_then_delete`.

**4.11.33 — `GET /api/v1/templates` carries two more fields.** `{ templates, wizardDescription, expiryDefaultDate }`. The route is **deliberately not admin-gated** (`PolicyService::templatesForCreation()`), which is the point: the create-team dialog used to read both values from `GET /api/v1/admin/settings` and therefore showed them to administrators only.

**Background jobs added**: `IdpGroupSweepJob` (hourly — new qualifying groups, then the orphan pass), `IdpGroupConsiderJob` (queued per group by `GroupMembershipChangedListener`).

**Audit events added**: `team.adopted`, `team.adoption_declined`, `team.adoption_withdrawn`.

## The audit log across every team (added 4.11.39)

Until 4.11.39 the only paginated read was per team, so the Compliance tab could only be entered by picking one — and four services had been writing events under `AuditService::INSTANCE_SCOPE` (`'_instance'`) that nothing could display. Design: DESIGN §2.172. Admin-gated like every other audit route (`#[AuthorizedAdminSetting]`, `#[NoCSRFRequired]` on the GET).

### `GET /api/v1/admin/audit/events`

**Query**: `teamId` (`''` = every team; `_instance` = only what is not about one team), `page` (1-based), `perPage` (default **15**, capped at 200), `eventTypes` (comma-separated), `from`, `to` (unix seconds, inclusive, `0` = no bound).

**Response 200**: `{ rows, total, page, per_page, total_pages }`. Each row is an audit row plus **`team_name`** — resolved in one query for the page, `''` for the instance sentinel and for a team whose circle is gone, which is why the client renders its own label for both. **400** for a `teamId` over 64 characters.

Narrowing by `teamId` runs the same query as `GET /api/v1/admin/audit/teams/{teamId}/events`, which stays (its default page size is still 50). The ZIP export is still one team's whole log and has no all-teams form.

### `GET /api/v1/admin/audit/teams` — one new field

**`instance_scope`**: `{ team_id, event_count, last_event_at }`, reported beside `teams` because the sentinel is not a circle, has no name to read out of the database, and was therefore dropped by the display-name filter that excludes hard-deleted teams. `event_count: 0` means the dropdown offers no *Not about one team* option.

**Audit events added**: `directory.group_found`, `directory.group_accepted`, `directory.group_declined`, `directory.group_failed`, `directory.group_orphaned`, `directory.group_forgotten` — all under `INSTANCE_SCOPE`, `target_type: group`, `target_id` the group id, actor `app:teamhub` when TeamHub decided. `group_found` is written in `review` mode only; in `auto` mode the group is found and answered in one breath and only `group_accepted` (carrying `mode: auto`) is written. `group_forgotten` matters most: v4.11.37 deletes the row when its group goes, so this event is afterwards the only trace the group was ever considered.

## A directory group waiting for a team is an administrator's task (added 4.11.39)

**Workflow**: `idp_group_adoption` (`IdpGroupAdoptionDefinition`), a sibling of `team_adoption` and system-started the same way — `POST /api/v1/workflows` with it answers 404. Two steps: `submit` (auto-completed at creation, in TeamHub's own name, `app:teamhub`) and `decide`, whose actor is the **`admin` group**, always — never a service desk. Its view carries `reference: { label, url }` pointing at Admin → TeamHub `#team-adoption` for an administrator and `null` for anybody else.

**`teamhub_wf_instance.team_id` holds the Nextcloud group id** for this definition and this definition only, which is what makes the engine's `one_open_per_team` rule mean one open request per group. `teamName` on the view is therefore `''` — `WorkflowActorResolver::teamName()` finds no circle — and the group's name is in the title. `subject` is `{ type: 'group', id: groupId }`.

**v4.11.41 — every workflow view carries `sourceGroup`.** A `SourceGroup` key (`teams`, `administration`) or `''`, from the new optional `IWorkflowDefinitionSource`; it says which My Work source chip the request appears under. `''` means *All and the Services chip only*, which is where every request was until this version (v4.10.18 gave a workflow no source, so any chip hid it). The directory-group and team-adoption definitions are `administration`; the team, quota and expiry requests are `teams`; the two generic service definitions are `''`. A chip for a single provider matches no workflow, whatever it says.

**v4.11.41 — two verbs this definition refuses.** `POST /api/v1/workflows/{id}/request-information` answers **409** (*There is nobody to ask for information on this request.*) because the initiator is `app:teamhub`; `POST /api/v1/workflows/{id}/cancel` answers **409** (*This request cannot be withdrawn. Decline it instead…*) for any caller other than the instance's initiator, so TeamHub can still retire its own task. The workflow view's `viewer` block gained **`canRequestInformation`** and its `canCancel` now respects the same policy, both via the new optional `IWorkflowDefinitionActionPolicy`; a definition that does not implement it is unaffected, which is every other one.

Licensed, like every workflow: on the basic tier no request is opened and the grid is the only place. No notification type was added — the engine's own `workflow_step_available` carries it.

**Background jobs changed**: `IdpGroupSweepJob` gained a third pass, `IdpGroupRequestService::reconcile()`, which opens a request for every `pending` row that has none and cancels every open request whose row is no longer pending or no longer there; `IdpGroupConsiderJob` calls `openForGroup()` when it has just made a group pending, so a newly found group does not wait an hour for its task.
