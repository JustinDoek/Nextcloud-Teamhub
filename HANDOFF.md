# TeamHub — Developer Handoff v2.31.0

## Quick-start for a new session

1. Read this file completely before writing any code
2. State the single feature or bug fix for this session
3. Identify which files that feature touches — read only those
4. Implement, test, bump the version, update this file, package the zip

**One feature = one session = one version bump.** Never carry a half-finished feature across sessions.

---

## Project overview

TeamHub is a Nextcloud 32+ app giving every Nextcloud Team (Circle) a unified workspace. It wraps Circles infrastructure and provisions Talk chat, Files folder, Calendar, and Deck board — accessible from a single tab bar with a message stream, widgets, and team management.

**GitHub:** https://github.com/justindoek/teamhub
**License:** AGPL-3.0
**Current version:** 2.31.0
**Status:** Production on NC32 PostgreSQL + NC33 PostgreSQL

---

## Environment

| Item | Value |
|---|---|
| Nextcloud | 32 (min), 34 (max), tested on 32 + 33 |
| PHP | 8.1–8.4 |
| Database | PostgreSQL (primary) |
| Vue | 2.7 (NC32 constraint) |
| Vuex | 3 |
| @nextcloud/vue | 8.x |
| Build | npm run build → js/teamhub-main.js + js/admin.js |

**Build location (developer):** C:\Temp\teamhub

---

## File structure

```
teamhub/
├── appinfo/
│   ├── info.xml
│   └── routes.php
├── lib/
│   ├── AppInfo/Application.php
│   ├── Controller/
│   │   ├── PageController.php
│   │   ├── TeamController.php      # updateTeamApps + deleteTeamResource (v2.31)
│   │   ├── MessageController.php
│   │   ├── CommentController.php
│   │   ├── WebLinkController.php
│   │   └── IntegrationController.php
│   ├── Service/
│   │   ├── TeamService.php
│   │   ├── MemberService.php       # requireAdminLevel() used by app toggle guard
│   │   ├── ResourceService.php     # deleteTeamResource() + 5 deletion helpers (v2.31)
│   │   ├── ActivityService.php
│   │   ├── MessageService.php
│   │   ├── WebLinkService.php
│   │   └── IntegrationService.php
│   ├── Db/
│   │   ├── MessageMapper.php
│   │   ├── CommentMapper.php
│   │   ├── WebLinkMapper.php
│   │   ├── TeamAppMapper.php
│   │   ├── IntegrationRegistryMapper.php
│   │   └── TeamIntegrationMapper.php
│   ├── Migration/
│   │   ├── Version000200000Date20240101000000.php
│   │   ├── Version000206001Date20260223000000.php
│   │   ├── Version000207000Date20260224000000.php
│   │   ├── Version000208000Date20260330000000.php
│   │   └── Version000209000Date20260402000000.php
│   ├── Notification/Notifier.php
│   └── Settings/
│       ├── AdminSection.php
│       └── AdminSettings.php
├── src/
│   ├── main.js
│   ├── admin.js
│   ├── App.vue                     # Sidebar layout fix — feedback in #list slot (v2.31)
│   ├── store/index.js
│   └── components/
│       ├── AdminSettings.vue
│       ├── ManageTeamView.vue      # Team Apps: detection fix, Intravox, create/delete (v2.31)
│       ├── CreateTeamView.vue      # console.log cleanup (v2.31)
│       └── [all other components]
├── js/
│   ├── teamhub-main.js             # COMPILED — do not edit
│   ├── admin.js                    # COMPILED — do not edit
│   └── markdown.js
├── templates/main.php
├── templates/admin.php
├── css/main.css
└── HANDOFF.md
```

---

## v2.31.0 — what changed this session

### 1. Sidebar layout fix (v2.30.2) — `src/App.vue`
Root cause: `:deep()` CSS from v2.28 targeted `.app-navigation__body/list/footer` — class names that do not exist in `@nextcloud/vue 8.x`. Rules were dead code. NC's own stylesheet caused overflow.

Fix: removed all three `:deep()` rules. Moved Feedback button out of `#footer` slot into the bottom of `#list` slot as a `NcAppNavigationItem` with a 1px separator. Removed unused `NcButton` import and `feedbackUrl` data property.

### 2. Talk room moderator permissions (v2.30.3) — `lib/Service/ResourceService.php`
`addCircle()` in Strategies 1 & 2 assigned `participant_type=3` (PARTICIPANT) — members could join but had no moderation rights. Strategy 3 used `participant_type=1` (OWNER, wrong semantic).

Fix: new private `promoteTalkCircleToModerator()` — DB UPDATE to `participant_type=2` (MODERATOR) scoped to the circle attendee row. Called after `addCircle()` in Strategies 1 & 2. Strategy 3 changed directly to insert with `participant_type=2`.

### 3. Team Apps: 404, detection, Intravox, create/delete on toggle (v2.30.4 → v2.31.0)

**404 fix:** `getTeamApps` (GET) and `updateTeamApps` (PUT) routes were missing from `routes.php`. Added both, plus new `DELETE /api/v1/teams/{teamId}/resources/{app}`.

**Detection fix:** `installedApps.talk` was always the correct key but the 404 caused the catch block to set `installedApps={}`, making everything show "not installed". Fixed by restoring the routes. Added comment clarifying that app_id `'spreed'` maps to installed key `'talk'`.

**Intravox:** Added Pages/Intravox entry to `teamAppsList`. Uses `intravoxAvailable` from Vuex store via `mapState` (same source as `TeamView`). Added `FileDocumentOutlineIcon` import and registration.

**Filter:** `teamAppsList` now filters out uninstalled apps entirely instead of showing them greyed.

**Create/delete on toggle:** `updateTeamApps` in `TeamController` now:
- On enable: calls `ResourceService::createTeamResources()` then saves flag
- On disable: calls `ResourceService::deleteTeamResource()` (hard delete, all data gone) then saves flag
- Intravox: flag-only both ways (no resource to provision)

`ResourceService::deleteTeamResource()` with 5 private helpers:
- `deleteTalkRoom` — talk_attendees (all) → talk_rooms row
- `deleteSharedFolder` — IShare::deleteShare() then folder node delete; QB fallback
- `deleteCalendar` — CalDavBackend::deleteCalendar() (cascades events); QB fallback
- `deleteDeckBoard` — cards → stacks → ACL → board in dependency order
- `deleteIntravoxAccess` — removes circle share rows (pages themselves not deleted)

**Security fixes this session:**
- `updateTeamApps` and `deleteTeamResource` now call `requireAdminLevel()` — only team admins/owners can toggle apps
- `deleteTeamResource` route allowlists `$app` parameter before it reaches the service
- Exception message detail no longer returned to client — generic string returned, full detail in server log

---

## Security fixes this session

| Issue | Severity | Fix |
|---|---|---|
| `updateTeamApps` had no auth check — any member could hard-delete all team data | **High** | `requireAdminLevel($teamId)` called at top of both `updateTeamApps` and `deleteTeamResource` |
| `$app` route param not validated in `deleteTeamResource` | **Medium** | Allowlist check against `['spreed','files','calendar','deck','intravox']` before service call |
| Exception detail leaked to client in deletion helpers | **Low** | Generic `'Operation failed — see server log for details'` returned; full message still logged |

---

## Admin settings

| Key (oc_appconfig) | Default | Description |
|---|---|---|
| `wizardDescription` | '' | Text at top of Create Team wizard |
| `createTeamGroup` | '' | Comma-separated NC group IDs (empty = everyone) |
| `inviteTypes` | 'user,group' | Comma-separated allowed invite types |
| `pinMinLevel` | 'moderator' | member / moderator / admin |

---

## Circles member levels

| Integer | Role |
|---|---|
| 1 | Member |
| 4 | Moderator |
| 8 | Admin |
| 9 | Owner |

---

## Deploy steps

```bash
npm run build
php occ upgrade
php occ app:disable teamhub && php occ app:enable teamhub
```

---

## Architectural decisions — never revert

1. No probeCircles() for team listing — direct DB queries only.
2. Config bitmask preservation — MANAGED_BITS = 1|2|4|16|512|1024. Never touch bits 8, 256, 32768.
3. PostgreSQL bigint — activity.object_id is bigint. Non-numeric IDs match on object_type only.
4. Circles API session — always startSession/stopSession in try/finally.
5. Admin settings via info.xml only — IRegistrationContext::registerSettings() absent in NC32.
6. Never hand-edit js/admin.js — compiled from src/admin.js.
7. circles_member inserts use ResourceService::getTableColumns() to check column existence.
8. Calendar public token — only dav_shares row with access=4 has the usable token.
9. Never use getContent() in controllers — use $this->request->getParams().
10. Member level checks via direct DB queries (getMemberLevelFromDb) — never Circles API.
11. Notifications: setRichSubject() + setParsedSubject() — $l->t() does NOT interpolate {placeholder}.
12. GET /api/v1/teams/{teamId}/messages returns {pinned: object|null, messages: []}.
13. TeamController injects all original services + IGroupManager — IntegrationController is separate.
14. data_url / action_url must be relative NC path or https:// — no http://, file://, data:, javascript:.
15. iframe_url must be https://.
16. Integration deregistration requires NC admin.
17. Built-in integrations seeded idempotently on every boot.
18. GET /integrations always returns { widgets: [], menu_items: [] } — always both keys.
19. Widget data always fetched server-side — never client-side cross-origin.
20. isBuiltinEnabled() falls back to true when teamMenuItems is empty (covers initial load).
21. findAllWithEnabledStateForTeam() uses single LEFT JOIN — never loop + findById().
22. Feedback URL is a constant in openFeedbackForm() — never constructed from user input.
23. Talk room creation never uses HTTP loopback — always in-process PHP strategies only.
24. Deck ACL: handle both Deck 1.x boolean columns and Deck 2.x `permissions` bitmask column.
25. createTeamGroup stored as comma-separated string; canCurrentUserCreateTeam() checks ANY group (OR).
26. getAdminSettings() requires NC admin — no NoAdminRequired attribute.
27. IGroupManager injected into TeamController via constructor DI — never \OC::$server.
28. Admin settings UI uses custom tab bar (NC CSS vars only) — no NcTabs dependency in admin.js context.
29. Talk circle attendee: participant_type=2 (MODERATOR) — not 1 (OWNER) or 3 (PARTICIPANT).
30. App toggle (enable/disable) requires team admin/owner — enforced via requireAdminLevel().
31. App disable = hard delete (Option B) — all resource data removed. No grace period (future feature).
32. deleteTeamResource $app parameter is allowlisted before reaching ResourceService.
33. Intravox toggle is flag-only — no resource provisioned or deleted server-side.
34. teamAppsList filters out uninstalled apps — never shows "not installed" row.
35. Feedback button is in the #list slot of NcAppNavigation — not #footer (which uses non-existent CSS classes in @nextcloud/vue 8.x).

---

## Known dead code (safe to remove)

- TeamService.php: debugCircleConfig, debugAllCircles, repairCircleMembership, debugActivity, debugResourceTables, debugCirclesMethods — no routes, kept for emergency.
- TeamAppMapper.php + teamhub_team_apps — legacy, still used by getTeamApps/updateTeamApps.
- TeamController.php: testResource() — no route registered, kept for emergency diagnosis.

---

## Files changed in v2.31.0

| File | Change |
|---|---|
| `src/App.vue` | Sidebar layout fix — removed broken :deep() CSS; feedback moved to #list slot |
| `src/components/ManageTeamView.vue` | Team Apps: route fix, installed key fix, Intravox, create/delete toggle, mapState |
| `src/components/CreateTeamView.vue` | Removed 3 console.log debug statements |
| `lib/Controller/TeamController.php` | updateTeamApps triggers resource ops + requireAdminLevel; deleteTeamResource added with allowlist + requireAdminLevel; appIdToResourceKey helper |
| `lib/Service/ResourceService.php` | promoteTalkCircleToModerator(); deleteTeamResource() + 5 deletion helpers; error detail suppressed from client |
| `appinfo/routes.php` | getTeamApps, updateTeamApps, deleteTeamResource routes added |
| `appinfo/info.xml` | Version 2.31.0 |
| `package.json` | Version 2.31.0 |

---

## Suggested next features (v2.32+)

- **Licence / product key system** — offline key validation, new "Licence" tab in admin (tab structure ready). Design fully before coding.
- **Team health summary card** — GET /api/v1/teams/{teamId}/stats + TeamStatsCard.vue
- **@mention notifications** — parse @uid in messages/comments, push NC notification
- **Team search** — GET /api/v1/teams/{teamId}/search?q=
- **Widget refresh interval** — per-widget auto-refresh setting for team admins
- **Migrate teamhub_team_apps** to teamhub_team_integrations
- **Grace period for resource deletion** — background job, pending-deletion state, re-link within window

---

## Starting prompt for next session

> I'm continuing development of TeamHub (NC32 app, v2.31.0). The project zip with full source and this HANDOFF.md is attached. I want to work on [feature]. Please read HANDOFF.md before we begin, then we'll identify which files to read.
