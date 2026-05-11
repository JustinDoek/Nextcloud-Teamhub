# Changelog

All notable changes to TeamHub are documented in this file.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [3.36.0] — 2026-05-11 — Session F

### Added
- **Strict 1:1 enforcement for files resources.** `ResourceDiscoveryService::reconcileApp` now snapshots the team's active files state and routes newly discovered rows accordingly: active shared + incoming GF → pending (with `isDualFolderPending` flag); active GF + anything → ignored (GF precedence); active shared + another shared → ignored. `acceptResource` and `unignoreResource` apply the same guard. All refusals write `resource.suppressed_duplicate` audit entries with reason codes.
- **Group folder takes precedence in `getTeamResources`.** When both shared and GF rows are active (dual state during manual migration), the loop explicitly picks the `gf:` row so widgets and the team home always read from the group folder.
- **Dual-folder informational notice** in Manage Team → Settings → Team Apps. When a GF is discovered alongside an active shared folder, a blue panel explains the situation and directs the admin to connect the group folder via the existing buttons and migrate files manually.
- **Resource-type badge** ("Group folder" / "Shared folder") on each active files row in the settings panel.
- **Picker filtering by active files type.** `GET /api/v1/pickers/files` now accepts `activeFilesType=shared|gf|none`. Shared folders are suppressed when one is already active; both types hidden when a GF is active.
- **`isDualFolderPending` flag** on panel data rows.
- **`normalPendingResources`, `dualFolderPendingRow`, `dualFolderSharedRow`, `activeFilesRow`, `activeFilesIsShared`, `activeFilesIsGf`** computed properties in `ManageTeamView.vue`.
- **Create New button for Talk** — shown in empty state alongside Connect existing.
- **Create New group folder button for Files** — shown only when Group Folders is installed; switches label to "+ Create new group folder" when a shared folder is active (signals the workflow).
- **Both 1:1 buttons hidden** once a resource is connected, except when a shared folder is active and Group Folders is available — then the GF buttons remain so admin can attach a GF for manual migration.

### Changed
- `ResourceService::upsertResourceRow()` — now promotes `pending`/`ignored` rows to `active` on explicit connect instead of skipping. Fixes silent failure when the discovery reconciler had already inserted the resource as pending.
- `ResourceService::getTeamResources()` — files block prefers `gf:` row when multiple active rows exist.
- `ResourceDiscoveryService::getSettingsPanelData()` — adds dual-folder detection and tagging.
- `ResourceDiscoveryService::resolveFileName()` — falls back to `basename(path)` when `filecache.name` is empty (some storage backends).
- `FilesService::listConnectableFileFolders()` — accepts `activeFilesType` parameter, filters output accordingly. Also falls back to `basename(path)` for shared folder names.
- `ResourceStateController::getPanelData()` — now triggers `reconcileTeam` before returning panel data, so externally added GF resources appear immediately.
- `ManageTeamView::connectExisting()` — empty catch block replaced with `showError()`.

### Fixed
- **Critical pre-existing bug** in `ArchiveService.php`: stray extra `}` (line ~2770) caused `ParseError: unexpected token "try"` on every admin archive request. Removed. Archive settings save works again.
- **`AdminSettings.vue` archive form**: `archiveBeforeDelete` was missing from the `data()` default and from `loadArchiveSettings` — the toggle had no reactive backing. Both fixed.

### Removed
- The auto-migration system (`FolderMigrationService`, `FolderMigrationController`, `FolderMigrationModal.vue`, two endpoints) was scoped, built, and removed per user direction. NcDialog wiring proved unreliable; manual file migration is the supported path. The dual-folder notice remains as an informational signal only.

### Security
- All new endpoints check team admin level (≥8) before any action.
- No raw SQL anywhere; no `\OC::$server`; constructor DI throughout.

## [3.33.8] — 2026-05-10

### Added
- **Group Folders integration (Session E, v21.0.7).** New `GroupFolderService` wraps `FolderManager` via lazy DI container resolution. When Group Folders is installed, new teams automatically get a server-owned Group Folder instead of a personal shared folder.
- **`GET /api/v1/pickers/files?teamId={id}`** — new endpoint listing connectable file folders: group folders where the team's circle is a member (type `group_folder`, shown first) and shared folders owned by the current user not yet connected to any team (type `shared_folder`). Requires team membership.
- **`gf:` resource ID convention.** Group Folder-backed files resources stored in `teamhub_team_app_resources` with `resource_id = 'gf:{folderId}'` — distinct from legacy share-based integer IDs.
- **`folder_type` field** in `getTeamResources` files response: `'group'` or `'shared'`.
- **`groupfolders` key** in `GET /api/v1/teams/apps/check` response.
- **`groupFoldersDelegation` key** in `GET /api/v1/admin/settings` response.
- **Admin Settings — Group Folders status section.** Read-only ✓/⚠ indicators for app installed and team-creator group configured.
- **`docs/design/team-folder-via-groupfolders.md`** — full design document.
- **Soft-delete suspend/resume for group folders.** `suspendConnectedAppResources` detects `gf:` resources and calls `removeCircleFromFolder`; `restoreConnectedAppResources` calls `assignCircleToFolder`. Folder ID stored in `suspended_resources` JSON.
- **Archive extraction for group folders.** `extractFilesData` routes `gf:` resources to new `extractGroupFolderData` helper that resolves the folder via a team member's file tree.
- **Type badges in Connect picker.** Group folder items show a blue `[Group Folder]` badge; shared folder items show a grey `[Shared]` badge.
- **Pending resources section moved to top of Team-apps panel**, above app rows.

### Changed
- `ResourceService::createTeamResources` — files branch prefers Group Folders when available; falls back to shared folder silently.
- `ResourceService::connectExistingResource` — files case handles `gf:` prefix; already-assigned circle treated as success (not 400).
- `ResourceDiscoveryService::getRealFileIds` — includes `gf:` IDs from `group_folders_groups`.
- `ResourceDiscoveryService::getFilesOwner` — returns null for `gf:` resources (always pending review on discovery).
- `ResourceDiscoveryService::resolveFileName` — handles `gf:` prefix via `group_folders.mount_point`.
- `FilesService::getTeamResources` — resolves filecache ID for group folders so favourites/recent widgets work.
- `FilesService` — added `getGroupFolderFilecacheId`, `listConnectableFileFolders`.
- `ResourcePicker.vue` — all apps now use server-driven list; NC file picker removed.
- `ManageTeamView.vue` — files connect picker uses `/pickers/files` endpoint; type badges on picker items; pending section repositioned.
- `AdminSettings.vue` — team-creator group auto-saves on add/remove (no separate Save click required).
- `TeamService::getAdminSettings` — includes `groupFoldersDelegation`; wrapped in `safeGetDelegationStatus` so failures cannot break the settings load.

### Fixed
- `group_folders` table primary key is `folder_id`, not `id` — fixed all QB queries in `GroupFolderService`.
- `FolderManager::addFolder()` → `createFolder()` — correct method name for GroupFolders v21.
- `getDelegationStatus` — removed bogus `group_folders_applicable` query (table does not exist in v21); simplified to two-field response.
- Security: `GET /api/v1/pickers/files` now validates team membership before exposing group folder names.



### Added
- **Multi-resource support for Calendar and Deck.** Teams can now connect multiple calendars and multiple Deck boards. `getTeamResources()` returns arrays for both apps.
- **Tab bar picker.** When a team has 2+ active calendars or boards, clicking the tab opens a picker. Count badge shown on the tab.
- **Per-app resource list in Manage Team → Settings.** Replaces app toggles for Talk/Files/Calendar/Deck with a live resource list showing connected resources with Disconnect and Delete actions, plus Connect existing and Create new buttons.
- **Create new resource name dialog.** Admin enters a name before creating a new calendar or Deck board.
- **Connect existing uses NC file picker for Files.** Other apps use TeamHub's own picker endpoint.
- **At-risk block at top of Team Apps section.** Resources with `risk_status != none` shown prominently before the per-app lists.
- **Disconnect action** — strips team's circle ACL, resource survives. Replaces "Remove".
- **Delete action with confirmation dialog** — destroys the NC resource permanently (hard delete).
- **Two-pill source labels on widget items.** DeckWidget shows board name (truncated 20 chars, full name on hover) + "Deck". CalendarWidget shows calendar name + "Calendar". Only shown when team has 2+ resources of that type.
- **Multi-resource widget aggregation.** DeckWidget and CalendarWidget aggregate across all connected resources. `fetchDeckTasks` loops all boards. `getTeamCalendarEvents` loops all calendars. `getTeamTasks` loops all connected calendars.
- **`calendarId` and `boardId` in create-event/create-task requests.** When team has 2+ resources, inline picker in modal lets admin choose target. `calendarId`/`boardId` sent in POST body and used by backend.
- **`calendarName` in calendar events response** and **`boardName` in deck task data** for pill display.
- Per-app remove methods: `removeRoomAccess`, `removeCalendarAccess`, `removeFilesAccess`, `removeBoardAccess`.
- Per-resource delete methods: `deleteRoomById`, `deleteCalendarById`, `deleteBoardById`, `deleteFolderById`.
- `deleteResource` endpoint: `DELETE /api/v1/teams/{teamId}/resources/{app}/{resourceId}/delete`.

### Changed
- Calendar and Deck connect guard now blocks only duplicate of the **same** resource, not any resource of that app type (multi-resource fix).
- `TaskService::resolveCalendarId()` updated for array shape. New `resolveAllCalendarIds()` helper.
- `TeamView` passes `calendars` and `boards` arrays as props to `AddEventModal` and `AddTaskModal`.
- `AddTaskModal` — `boardId` prop replaced with `boards` array; stack re-fetch on board switch.
- `AddEventModal` — `calendars` prop added.

### Fixed
- `ArchiveService::readIntegrations()` — `ir.integration_id` corrected to `ir.id AS ir_id` (column does not exist).
- `FilesService::suspendFilesAccess` and `removeFilesAccess` — removed `IManager::getShareById` for circle shares (wrong prefix). Now uses QB delete directly, eliminating spurious "Share not found" warnings on team deletion.
- Multiple PHP brace-balance issues in `ActivityService` and `ResourceService` caused by partial str_replace replacements.
- At-risk resource block text readability — header text now uses `var(--color-main-text)` instead of `var(--color-error)` on dark background.
- Standalone duplicate at-risk section removed from bottom of settings tab.
- Scroll target for warning block link updated to `.manage-section--atrisk-inline`.
- `DeckWidget` calendar check updated for array shape (`resources.calendar.length > 0`).
- `DeckService::deleteBoardByIdInternal` — each QB operation now uses its own instance (fixes MySQL syntax error on board delete).

## [3.31.0] — 2026-05-09

### Added
- **`owner_uid` column** on `teamhub_team_app_resources` (migration `Version000330000Date20260509000000`). Tracks the NC uid of the resource owner for risk monitoring.
- **`UserStatusListener`** — flags all resources owned by a disabled user as `risk_status=owner_disabled`; clears on re-enable. Writes `resource.risk_flagged` / `resource.risk_cleared` audit events.
- **`UserDeletedListener`** — attempts ownership transfer to the most recently active team admin before a user account is deleted. Sets `risk_status=transfer_failed` when no eligible admin exists. Writes `resource.owner_transferred` / `resource.transfer_failed` audit events.
- **"Resources at risk" section** in Manage Team → Settings. Red-bordered, read-only list of active resources with `risk_status != none`. Shows app, resource name, risk reason, and owner uid.
- **Deep-link scroll** — "Open settings →" in the Teaminfo warning block now auto-switches to the Settings tab and smooth-scrolls to the at-risk section with a highlight pulse.
- **`resourceWarningFocus`** state in Vuex store — coordinates the warning block button with ManageTeamView scroll behaviour.

### Changed
- `ResourceDiscoveryService::refreshRiskStatus()` — completed from stub. Always re-resolves live resource owner from NC tables on every reconcile, catching external ownership changes (e.g. Deck board transferred directly in Deck). Backfills or corrects `owner_uid` when it differs from the stored value.
- `ResourceDiscoveryService::insertDiscoveredRow()` — now populates `owner_uid` on insert.
- `TeamAppResourceMapper::insertResource()` — gains `ownerUid` parameter.

## [3.30.0] — 2026-05-08

### Added
- **Resource name resolution in settings panel.** The panel endpoint now returns a `displayName` field for each resource row, resolved from NC native tables: Files (`filecache.name`), Talk (`talk_rooms.name`, falls back to token), Calendar (`calendars.displayname` then `uri`), Deck (`deck_boards.title`). Falls back to raw resource ID on any lookup failure.
- `ManageTeamView` pending and ignored resource rows now show the resolved name with the raw ID as a tooltip.

### Changed
- CSS class `.pending-resource-id` renamed to `.pending-resource-name` in `ManageTeamView.vue`.

## [3.29.0] — 2026-05-08

### Added
- **Resource discovery reconciliation.** `ResourceDiscoveryService` compares live NC ACL/share tables against `teamhub_team_app_resources` on every team page load (render-time) and hourly via cron (`ResourceDiscoveryJob`). Externally added resources are auto-accepted if the owner is a team admin, otherwise inserted as pending.
- **Pending/ignored resource management.** New endpoints `GET /panel`, `POST /accept`, `POST /ignore`, `POST /unignore` under `/api/v1/teams/{teamId}/resources/`. All require team admin level ≥ 8.
- **Teaminfo widget warning block.** Admin-only banner shows combined count of pending + at-risk resources with a link to Manage Team → Settings.
- **Settings panel — pending resources section.** Lists externally discovered resources awaiting review with Accept/Ignore actions. Ignored resources shown in a collapsible section with Un-ignore action.
- **`ResourceDiscoveryJob`** registered as hourly background job in `appinfo/info.xml`.
- **Audit log events** for all resource lifecycle transitions: `resource.discovered`, `resource.auto_accepted`, `resource.external_withdrawn`, `resource.accepted`, `resource.ignored`, `resource.unignored`.
- **`_warnings`** key added to `GET /api/v1/teams/{teamId}/resources` response: `{ pending: int, atRisk: int }`.

### Fixed
- `OCP\IAppManager` corrected to `OCP\App\IAppManager` in `ResourceDiscoveryService` — wrong namespace caused DI resolution failure and broke team listing on page load.

## [3.28.1] — 2026-05-08

### Added
- **Design doc: `docs/design/connected-resources.md`.** Locked design for the upcoming "Connected Resources" overhaul, which moves TeamHub from a one-resource-per-app-per-team model to a hybrid discovery model with explicit team-admin acceptance, multi-resource support for Deck and Calendar, three-action off-semantics (ignore / remove access / delete), and automatic ownership transfer on `BeforeUserDeletedEvent` to preserve team continuity. Implementation split across Sessions A → D. No code changes this session.
- **Project-level design reference: `DESIGN.md`.** First creation. Captures durable architectural choices and principles for future-session reference, seeded with both pre-existing decisions inferred from the codebase and the choices made this session. Per SKILLS.md step 10, this document is appended to whenever a non-trivial design choice is made.
- **Group Folders integration principles** (DESIGN.md §2.18 and §2.19). Locked the principle that TeamHub aligns permissions with other NC apps at configuration time rather than bypassing their security models, and the dual-folder migration semantics for the future Session E that will integrate Group Folders as a preferred team-folder backend. Implementation deferred; pre-Session-E spike documented in HANDOFF.

## [3.28.0] — 2026-05-07

### Added
- **Connect existing app resources to a team.** Team owners can now choose, per app, to connect a Calendar / Files folder / Deck board / Talk room they already own instead of creating a new one. Available in the Create-team wizard step 4 and in Manage Team → Settings → Apps.
- **Resource pickers** (`GET /api/v1/pickers/{calendar|deck|talk}`) listing the current user's owned resources, scoped to the caller's UID.
- **Connect endpoint** `POST /api/v1/teams/{teamId}/resources/{app}/connect` (team-admin required) that inserts the share/ACL row granting the team's circle access to the selected resource.
- **`ResourcePicker.vue`** — unified picker component used by both the wizard and the manage-team dialog. Files mode opens NC's standard `getFilePickerBuilder` dialog; the other three apps render a populated `<select>` populated from the picker endpoint.
- **Connected-resource warning** under "Delete team" in Manage Team → Maintenance, explaining that connected resources are deleted with the team and how to preserve them.
- **Archive-before-delete admin toggle.** New checkbox in Archive Policy controls whether team deletion produces an archive ZIP first or skips archiving entirely. Default OFF for new and existing installs. Same three deletion modes (`hard` / `soft30` / `soft60`) apply to both archive-on and archive-off paths.
- **`POST /api/v1/teams/{teamId}/soft-delete`** endpoint for soft-delete without archive — creates a pending-deletion row and suspends connected app resources but skips archive production.
- **Owner-side delete dialog** when archive-before-delete is OFF, with description and confirmation text adapted to the chosen deletion mode (immediate hard delete vs 30/60 day grace period without archive).

### Changed
- The Delete-team button in Manage Team → Maintenance now branches based on admin policy: archive ON opens the existing archive modal; archive OFF opens a plain `NcDialog` confirmation.
- Description text for the Delete-team row dynamically reflects the active archive policy and deletion mode.

### Fixed
- **PostgreSQL `SQLSTATE[22P02]: invalid input syntax for type smallint: "f"` on team creation.** The `enabled` (in `teamhub_team_apps`) and `is_builtin` (in `teamhub_integ_registry`) columns are SMALLINT (per the v3.9.0 cross-database fix), but their bind parameters were still using `IQueryBuilder::PARAM_BOOL`. PostgreSQL refuses the boolean-to-smallint coercion at the wire-protocol level; MySQL accepted it silently. Fixed by casting to int and binding as `PARAM_INT` in `TeamAppMapper::upsert()` and `IntegrationRegistryMapper::register()`.

### Security
- Every connect endpoint re-verifies that the user owns the specified resource (`WHERE owner = currentUid` or `IRootFolder::getById()` for Files), preventing forged-`resourceId` attacks across the four supported apps.
- Each app refuses to connect a second resource if one is already linked to the team (one-resource-per-team invariant).
- Picker endpoints scope listing to the caller's UID — never accept a UID from request parameters.

## [3.27.0] — 2026-05-07

### Added
- **Calendar extractor (`apps/calendar/`).** Archive includes `calendar.json` (metadata: name, colour, timezone, event count), `events.json` (structured VEVENT/VTODO/VJOURNAL array with organizer, attendees, recurrence rules), and `events.ics` (merged ICS file openable in any calendar client). Looked up via `dav_shares` → `CalDavBackend::getCalendarObjects()`. Pseudonymizes organizer/attendee mailto: addresses when policy is on.
- **Files extractor (`apps/files/`).** Archive includes a full recursive copy of the team's shared folder. Uses `getLocalFile()` + `copy()` for local storage (no memory overhead); falls back to `getContent()` for external storage with a 100 MB per-file skip guard. Files folder and share left completely intact during grace period — only destroyed at hard-delete. `apps/files/index.json` lists all files with sizes and any skip reasons.
- **Talk extractor (`apps/talk/`).** Archive includes `messages.json` (all chat messages from `oc_comments` where `object_type='chat'` and `object_id={room_id}`) and `transcript.html` (self-contained offline viewer with date separators, system message rendering, and rich-object placeholder highlighting). Fixed: `object_id` stores the integer room ID, not the room token.
- **Deck extractor (`apps/deck/`).** Archive includes `board.json` (full board in Deck's import-compatible format: stacks, cards with labels/assignees/comments nested inline) and `board.html` (self-contained offline kanban view with label colour chips, due date highlighting, assignee badges). Card comments sourced from `oc_comments` where `object_type='deckCard'`.
- **Resource suspension on soft-delete.** When a team is archived in soft-delete mode, the team circle is removed from each connected NC app resource (Talk room attendee, Files circle share, Calendar dav_shares row, Deck ACL row) so members lose access immediately. Content stays intact for restore.
- **Resource resume on admin restore.** `restorePendingDeletion` re-adds the circle to each suspended resource using IDs stored in `suspended_resources` on the pending_dels row. Idempotent — skips re-insert if row already exists.
- **`suspended_resources` column** added to `teamhub_pending_dels` (migration `Version000326000`). JSON blob storing the IDs needed to resume each app resource.
- **Pre-flight size check includes destination free space.** Archive is refused if the estimated size exceeds either the admin cap or 90% of the free space at the archive destination, whichever is more restrictive. Error message specifies which constraint was hit.
- **`oc_filecache` folder size in pre-flight.** Real folder size read from NC's file cache (accurate recursive total) rather than estimated from row counts.
- **Audit team list filters deleted teams.** `GET /admin/audit/teams` no longer returns hard-deleted teams (circle gone) or soft-deleted teams (pending grace period). Both are excluded from the dropdown.
- **`PendingDeletionJob` destroys app resources at grace period expiry.** Calls `ResourceService::deleteTeamResource()` for each enabled app before `deleteTeam()`, ensuring the Files folder and other content is fully removed at the scheduled time.

### Fixed
- **Deck `resumeDeckAccess` permissions.** Re-inserted circle ACL row now uses `dbIntrospection->getTableColumns()` for column detection, matching the creation pattern. Handles `permission_edit`, `permission_share`, `permission_manage` (Deck 1.x) and `permissions` bitmask (Deck 2.x). `enforceAclEditPermissions()` called after insert as a belt-and-braces check.
- **Talk message extraction.** `oc_comments.object_id` stores the integer room ID as a string, not the room token. Query now uses `(string)$roomId` which correctly returns messages.
- **`fclose()` warning on ZIP write.** NC's `putContent()` closes stream handles internally; switched to passing file content as string to avoid double-close.
- **`OC_Util::getVersion()` and `OC::$server` removed.** Replaced with `IConfig::getSystemValue('version')` and injected `IAppManager::getAppVersion()`.

### Security
- Resource suspension removes circle access immediately on archive initiation — members cannot use Talk, Files, Calendar, or Deck during the grace period.
- `suspended_resources` JSON stored on the DB row; access is admin-only via `AuthorizedAdminSetting`.

## [3.25.0] — 2026-05-06

### Added
- **Team archiving — Session A (foundation).** Two-action danger zone: "Archive team" (amber) produces a ZIP archive of all team data before deleting; "Delete team" (red, unchanged) deletes immediately without an archive.
- **`teamhub_pending_dels` table.** Shadow table tracking teams in each phase of archiving. Status='pending' hides the team from all member-facing list endpoints. Grace period is immutable once set.
- **Archive bundle format v1.0.** ZIP containing `manifest.json`, `index.html` (self-contained client-side viewer), `teamhub/` (messages, comments, poll votes, web links, widget layouts, integrations, audit log), and `circles/` (team metadata, members, effective users). `apps/` folder is reserved for Sessions B–E.
- **`ArchivePseudonymizer`.** Admin-policy-driven per-archive UID → alias replacement. Alias map is never written to the archive. Message and comment body text is not processed.
- **Admin "Archive" settings tab.** Deletion mode (soft30 / soft60 / hard), archive storage location (owner + folder path with fallback to team owner's Files), max archive size cap (default 5 GB), pseudonymize toggle.
- **Admin archived-teams table.** Lists all pending-deletion rows with Restore (within grace period) and Force-delete actions.
- **`PendingDeletionJob`.** Daily background job finalizing teams whose soft-delete grace period has expired.
- **7 new API endpoints.** `POST /teams/{teamId}/archive`, `GET /teams/{teamId}/archive/status`, `GET|PUT /admin/archive/settings`, `GET /admin/archive/pending`, `POST /admin/archive/pending/{id}/restore`, `POST /admin/archive/pending/{id}/purge`.
- **Write guard on all MemberService mutation methods.** Attempting to invite, remove, level-change, or approve members on a pending-deletion team returns 409 Conflict.

### Security
- All admin archive endpoints carry `#[AuthorizedAdminSetting]` — NC framework enforces admin-only.
- Archive ZIP written atomically (`.zip.tmp` → rename); on failure the partial directory is deleted and the team is not deleted.
- Archive storage writes through `IRootFolder` exclusively — no raw filesystem access outside NC's abstraction layer.

## [3.24.0] — 2026-05-05

### Added
- **Mobile single-canvas layout** for viewports ≤ 768px and tablet portrait (≤ 1024px portrait). New `MobileWidgetView.vue`: scrollable canvas, collapsible icon bar at bottom with one icon per accessible widget, FAB action button.
- **FAB widget actions** — in-canvas action button rows removed; actions surfaced via FAB: single action fires directly, multiple actions open a slide-up sheet.
- **Tablet landscape layout** for viewports ≤ 1200px landscape: 60/40 split with message stream left and collapsible widget column right. Widget cards have spacing and rounded borders.
- **NC sidebar auto-close on mobile/tablet-portrait** — uses `NcAppNavigation :open.sync` prop to close reactively after selecting a team or action, instead of fragile DOM manipulation.

### Changed
- Embedded app iframe content height set to 100% (previously 90%).
- Edit layout button hidden on both mobile and tablet layouts (editing not available in these modes).
- Seven modals (`ManageLinksModal`, `AddEventModal`, `AddTaskModal`, `AddPersonalTaskModal`, `InviteMemberModal`, `ScheduleMeetingModal`, `TeamMeetingModal`) now set `min-width: 0` on viewports ≤ 768px to prevent horizontal overflow on phones.
- `MessageStream` accepts `hide-header` prop and exposes `openPostForm()` method for FAB integration.

## [3.23.0] — 2026-05-04

### Added
- **DELETE `/api/v1/comments/{commentId}`** — hard-delete a comment. Author may always delete their own; team admins (Circles level ≥ 8) may delete any comment. Audit event `comment.deleted` written with metadata `{ message_id, author_id, deleted_by_admin, cleared_solved }`.
- **Solved-question revert on answer deletion.** If the deleted comment is the marked answer to a question, the parent message is automatically reverted to unsolved (`question_solved=0, solved_comment_id=NULL`). The confirmation dialog warns the user before proceeding.
- **Delete button on comments.** Visible to the comment author and team admins. Confirmation dialog; disabled/spinner during async delete. Error messages are HTTP-status-aware (403, 404, generic).
- **`currentUserIsTeamAdmin` Vuex getter** (level ≥ 8) — derived from `current_user_level` now returned by `GET /api/v1/teams/{teamId}/members`.
- **Markdown formatting toolbar** in `PostMessageForm.vue` (new messages) and `CommentsSection.vue` (comments): Bold, Italic, Inline code, Code block, Heading (H2), Bullet list, Link. `@mousedown.prevent` preserves contenteditable selection; `execCommand('insertText')` fires at cursor.
- **Markdown toolbar on edit message.** Same seven buttons in `MessageCard.vue` edit mode. Uses native `selectionStart/End` + `setSelectionRange` (plain textarea — no `execCommand` needed).

### Fixed
- **XSS via `v-html` in message and comment bodies.** Both `renderMarkdown` functions now pass output through `DOMPurify.sanitize()` with an explicit `ALLOWED_TAGS`/`ALLOWED_ATTR` allowlist before binding to `v-html`.
- **Headings (`## text`) and bullet lists (`- item`) rendered as literal text.** `renderMarkdown` was a flat `.replace()` chain ending with `\n → <br>`, so heading and list regexes (which need multiline anchors) never matched. Rewrote using a null-byte placeholder pattern: code blocks and inline code are stashed before block-level rules run; restored after `<br>` conversion. Applied to `MessageCard.vue` and `CommentsSection.vue`.
- **Deck boards created with `permission_edit = 0`.** Deck's `AclMapper` does not mark entity fields dirty when set via `__call` magic, so `setPermissionEdit(true)` was a no-op. Added `enforceAclEditPermissions()`: one independent QB `UPDATE` per column (`permission_edit`, `permission_share`, `permission_manage`), each try/caught so a missing column throws silently without blocking the others. Schema confirmed from live DB.
- **All Deck boards and Calendars provisioned in the same blue colour.** `createTeamResources()` now picks one random colour per team (`$teamColour = self::randomTeamColour()`) and passes the same value to both `createCalendar()` and `createDeckBoard()`.
- **Provisioned resources (Talk, Files, Calendar, Deck) not deleted when a team is deleted.** `deleteTeam()` now fetches the team's app list from `teamhub_team_apps` before destroying the circle, then calls `deleteTeamResource()` for each app. Resources are deleted before `circleService->destroy()` so CalDAV/Talk can still resolve the circle principal. All apps are cleaned regardless of their `enabled` flag.

### Changed
- `GET /api/v1/teams/{teamId}/members` response now includes `current_user_level` (integer) alongside `is_direct_member`.
- `DELETE /teams/{teamId}` now deletes all provisioned Nextcloud app resources before destroying the circle.
- `ResourceService::TEAM_COLOUR_PALETTE` — 12-colour curated palette for NC-friendly team colours.

## [3.22.0] — 2026-05-01

### Fixed
- **Indirect members (added via NC group/sub-team) could not see built-in app tabs (Talk, Files, Calendar, Deck).** `ResourceService::getTeamResources()` checked only for a direct `circles_member` row; indirect members have none, so the method threw and the controller returned all-null resources. Added `isEffectiveTeamMember()` helper in `ResourceService` that mirrors the two-step indirect-membership check (circles_member → circles_membership) used elsewhere, without introducing a circular dependency on `MemberService`.
- **Member count in members widget was inflated when groups or sub-teams were present.** `getEffectiveMemberCount()` used `COUNT(*) FROM circles_membership`, which includes group-proxy and sub-team-proxy circles as rows alongside individual users. Replaced with a query that inner-joins `circles_member` on `user_type=1, level=9` to isolate personal user circles, and uses `COUNT(DISTINCT user_id)` to deduplicate users who appear via multiple membership paths.
- **Pages widget hidden after team creation even when Intravox page was successfully created.** `create-resources` did not write to `teamhub_team_apps`, so `getTeamResources` found no `intravox` row and returned `resources.intravox = false`.
- **Manage team → Settings → Team apps showed all apps enabled after creation, regardless of wizard selections.** Same missing write: `ManageTeamView` fell back to `defaultEnabled = true` for every app when no rows existed. The wizard now sends a complete `appStates` payload (all apps, enabled and disabled) with `create-resources`; the backend validates and persists these via `updateTeamApps()`.

## [3.21.0] — 2026-05-01

### Added
- **WCAG 2.2 accessibility audit and remediation (Sessions 1–3).** Full codebase reviewed against all A and AA criteria. The following fixes were applied:

#### 1.1.1 Non-text content
- `AppEmbed.vue`: `<iframe>` now carries `:title="label"` so screen readers identify embedded apps (Chat, Files, Calendar, Deck).
- `MessageCard.vue`: poll options now carry `role="button"`, `aria-pressed`, `aria-label`, `tabindex`, and `@keydown.enter/space` handlers — keyboard and AT users can vote in polls.

#### 1.3.1 Info and relationships
- `TeamWidgetGrid.vue`: all 11 widget title `<span>` elements replaced with `<h2>` (margin/padding reset added to prevent browser defaults from breaking layout). Screen reader users can now navigate widgets by heading.
- `MessageCard.vue` edit mode: bare `<input>` and `<textarea>` now have associated `<label>` elements linked by unique per-message `id`.

#### 1.4.1 Use of color
- `MessageCard.vue`: voted poll option now shows a `CheckCircleOutline` icon alongside the background highlight — vote state is no longer conveyed by colour alone.

#### 1.4.3 Contrast — hardcoded colours
- `DeckWidget.vue`: `#0e7490` teal replaced with `var(--color-info-text, var(--color-main-text))`.
- `FilesFavoritesWidget.vue`: `#f6c342` gold replaced with `var(--color-warning, #f6c342)`.
- `TeamWidgetGrid.vue`: `#1a1a1a` on success/warning badges replaced with `var(--color-success-text, #1a1a1a)` and `var(--color-warning-text, #1a1a1a)`.

#### 2.1.1 / 2.4.7 Keyboard access and focus visible
- All 10 components with `outline: none` on `:focus` migrated to `:focus-visible` with `2px solid var(--color-primary-element)` ring. Mouse/touch users are unaffected; keyboard users now see focus indicators.
- `App.vue`: duplicate `:focus-visible` blocks consolidated; `outline: none` removed.
- `TeamTabBar.vue`: `role="tablist"`, `role="tab"`, and `aria-selected` added to all tab buttons. Tab/Shift+Tab moves focus; Left/Right arrow reorders the focused tab and restores focus after re-render via `$nextTick`.
- `TeamWidgetGrid.vue` (edit mode): all 11 drag handles gain `tabindex="0"` and `@keydown` handlers for ↑ ↓ ← → to move widgets on the grid. `moveWidget()` swaps positions with the neighbour in sorted order (fixes vue-grid-layout vertical compaction cancelling `y ± 1` nudges).

#### 2.4.6 Headings and labels
- Same as 1.3.1 widget `<h2>` and edit input `<label>` changes above.

#### 2.5.7 Dragging movements
- **Tab bar**: Left/Right arrow keys on focused tab provide a keyboard alternative to drag-to-reorder (WCAG requires a pointer/keyboard alternative).
- **Widget grid**: ↑ ↓ ← → on focused drag handle provide a keyboard alternative to grid drag-and-drop.

#### 4.1.2 Name, role, value
- `TeamTabBar.vue`: `role="tablist"` + `aria-label="Team navigation"` on wrapper; `role="tab"` + `aria-selected` on each button tab; web link tabs correctly excluded from tab role.
- `TeamWidgetGrid.vue`: all 11 collapse/expand buttons now include the widget name in their `aria-label` (e.g. "Collapse Team Messages" instead of "Collapse").
- `AppEmbed.vue`: `<iframe title>` fix (see 1.1.1).

#### 4.1.3 Status messages
- `PostMessageForm.vue`: attachment list wrapped in `aria-live="polite" aria-atomic="false"` — upload status changes (Uploading…, ✓, error) are now announced to screen readers. Checkmark symbol `✓` given `:aria-label="Upload complete"`.

### Security
- `renderMarkdown` (pre-existing): `v-html` binding in `MessageCard.vue` and `CommentsSection.vue` renders user content without HTML sanitization. Logged as open issue for a dedicated security session — fix requires `DOMPurify.sanitize()` before return.

### Removed
- Debug `console.log` calls in `TeamWidgetGrid.vue` (`moveWidget`) and `TeamTabBar.vue` (`moveTabLeft`, `moveTabRight`).



### Fixed
- **Double margin-top gap below NC top bar.** NC page frame and `NcContent` both applied `margin-top: var(--header-height)` to the same element. Added `#content-vue.app-teamhub { margin-top: 0 }` to zero the page-frame copy only.
- **`TypeError: e.n is not a function` on team pages.** `translatePlural` imported at module scope is invisible to Vue 2 templates — added `n` to `methods: { t, n }` in all five affected components; `AdminSettings` gets an inline `n()` method matching its existing `t()` pattern.

### Changed
- **All error messages use `{error}` named placeholder** instead of string concatenation. Allows translators to reposition the error detail within the sentence (22 call sites across 10 components).
- **All count-bearing strings converted to `n()` plural forms** (14 strings across 5 components). Translators can now supply correct plural rules per language.

### Added
- **Transifex plumbing.** `.tx/config` and `.l10nignore` added. Stale `l10n/en.js` / `l10n/en.json` removed. Ready for NC community bot once `@nextcloud-bot` is invited to the repo.
- **`TRANSLATORS:` hints** on ambiguous strings: `Comment`, `Leave`, `Join` (team vs. meeting), poll vote labels.
- **Translation standards** added to `SKILLS.md` — every string written in future sessions must be translation-ready immediately.

### Removed
- **Debug logging purged.** 23 JS (`console.log` / `console.error`) and 15 PHP (`error_log`) calls removed across `App.vue`, `FeedbackModal.vue`, `FilesSharedWidget.vue`, `TeamView.vue`, `FeedbackController.php`, `FeedbackService.php`, `TeamService.php`, `TelemetryService.php`. The `console.warn` in `TeamView.menuItemUrl()` is intentionally kept as a security signal.

## [3.19.0] — 2026-04-30

### Added
- **Transifex plumbing.** Added `.tx/config` pointing at `o:nextcloud:p:nextcloud:r:teamhub` and `.l10nignore` excluding non-translatable paths. Enables NC community bot to open translation PRs.
- **Plural forms for all count strings.** Converted all 14 count-bearing `t()` calls to `n()` (`translatePlural`): comment count, vote count, user count on group/sub-team pills, team count, member invited confirmation, "Show all members" button. Translators can now supply correct plural rules per language.
- **`TRANSLATORS:` hints** on ambiguous strings: `Comment` (verb), `Leave` (depart team), `Join` (team vs. meeting context distinguished), poll vote labels.

### Fixed
- **String concatenation in error messages.** All 22 instances of `t('teamhub', 'Msg') + (msg ? ': ' + msg : '')` replaced with `msg ? t('teamhub', 'Msg: {error}', { error: msg }) : t('teamhub', 'Msg')` — server error detail is now a named placeholder translators can reposition.
- **`margin-top` on main content area.** NC 32 applies a default `margin-top` to `.content` — overridden to `0` on `.app-teamhub` so the full viewport height is used.

### Removed
- Stale `l10n/en.js` and `l10n/en.json` (48 keys, 8% coverage) — these collide with the NC translation bot output and were not being maintained.

## [3.18.0] — 2026-04-29

### Added
- **Audit tab info banner.** A permanent informational banner sits at the top of the Audit tab explaining that external activity is mirrored hourly, new events may take up to an hour to surface, and TeamHub-internal actions are recorded immediately. Always visible, no dismiss.
- **Iframe loading skeleton.** `AppEmbed` shows an `NcLoadingIcon` overlay until the embedded app fires its `load` event.
- **Iframe reload button.** New refresh button in the embed toolbar fully tears down and recreates the iframe element (`:key` bump), equivalent to a hard reload.
- **Iframe error state.** When `iframe_url` fails frontend validation, `AppEmbed` shows an explicit `AlertCircleOutline` error message instead of an indefinite spinner.

### Fixed
- **NC 32 chrome stripping.** NC 32 renamed `#app-navigation` → `#app-navigation-vue`, `#content` → `#content-vue`, `#app-sidebar` → `#app-sidebar-vue`, `.app-menu-main` → `#app-menu-container`. Our injected CSS was targeting the old names. All old + new selectors now covered.
- **NC 32 layout offset.** NC 32 drives the body container position from `--body-container-margin` / `--body-container-radius` CSS variables rather than fixed pixels. Now zeroed on `:root`.
- **Custom Menu app chrome visible.** The "Custom Menu" NC app (`side_menu`) injects `#side-menu-container` and `.cm--topwidemenu` that no prior rule targeted. Now explicitly hidden.
- **Files share dialog and details panel blocked.** `#app-sidebar-vue` was hidden globally — NC apps use it for share panels, file details, Calendar event editors, Deck card details. Removed from hide rules; the sidebar starts closed on page load so no chrome shows.
- **App-internal modals trapped.** `position: fixed; inset: 0` on `#content-vue` flattened the stacking context, preventing app-internal modals from rendering above the content. Replaced with `width/height: 100%` only.
- **Talk chat textarea cut off.** `.app-embed__viewport` used `height: 85%` which truncated the bottom of the Chat view. Replaced with `flex: 1 1 auto; min-height: 0` to fill available column height correctly.
- **`onLoad` never fired / injection never ran.** `loading="lazy"` on the iframe caused the browser to defer `load` events (confirmed in console: *"Load events are deferred"*). Removed.
- **`TypeError: Cannot read properties of undefined (reading 'forEach')`** in `clearRetryTimers()`. Vue 2 doesn't reliably maintain underscore-prefixed `data()` properties. Added `Array.isArray()` guard.
- **MutationObserver infinite loop.** `injectCss()` removed and re-appended the `<style>` tag on every call — each DOM write re-triggered the observer. Now bails early if tag already present.

### Security
- **Origin-aware iframe sandbox.** Cross-origin `menu_item` integrations get `sandbox="allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox"` — without `allow-same-origin` (blocks cookie/localStorage abuse) and without `allow-top-navigation` (blocks parent-window redirect). Same-origin built-ins unsandboxed (DOM access required for CSS injection).
- **`referrerpolicy="strict-origin-when-cross-origin"`** on every iframe — prevents team-scoped URL leaking via `Referer` header.
- **`allow=""`** (empty Permissions Policy) on every iframe — denies camera, mic, geolocation, payment, USB by default.
- **`rel="noopener noreferrer"`** added to "Open in new tab" link (was `target="_blank"` only — leaked `window.opener`).
- **Frontend URL re-validation** in `TeamView.menuItemUrl()`: rejects anything outside `https://`, `/apps/`, `/index.php/`. Defence-in-depth alongside backend validation in `IntegrationService`.



### Added
- **Audit tab info banner.** A permanent informational banner now sits at the top of the Audit tab (above the activity-disabled warning) explaining that external activity is mirrored hourly and that new events may take up to an hour to surface. Internal events (team creation, join requests) continue to be recorded immediately.
- **Iframe loading state.** `AppEmbed` now shows an `NcLoadingIcon` skeleton until the iframe fires its `load` event — replaces the previous blank pane during reloads and tab switches.
- **Iframe reload button.** New refresh control in the embed toolbar bumps a Vue `:key` to fully tear down and recreate the iframe element, equivalent to a hard reload (no cached app state).
- **Iframe error state.** When the URL fails frontend validation (only `https://`, `/apps/`, `/index.php/` are accepted), `AppEmbed` shows an explicit error message with `AlertCircleOutline` icon instead of spinning indefinitely.

### Security
- **Origin-aware iframe sandbox.** Cross-origin iframes (external `menu_item` integrations) now ship with `sandbox="allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox"` — deliberately *without* `allow-same-origin` (blocks cookie/localStorage abuse) and *without* `allow-top-navigation` (blocks parent-window phishing). Same-origin built-ins (Talk/Files/Calendar/Deck) remain unsandboxed because the chrome-stripping CSS injection requires DOM access.
- **Iframe `referrerpolicy="strict-origin-when-cross-origin"`** on every embed — prevents the team-scoped TeamHub URL (which contains `?team={teamId}`) from leaking to third-party origins via the `Referer` header.
- **Iframe `allow=""`** (empty Permissions Policy) on every embed — denies camera, microphone, geolocation, payment, USB, MIDI, and other powerful features by default.
- **Frontend URL re-validation** in `TeamView.menuItemUrl()`: rejects anything not `https://`, `/apps/`, or `/index.php/`. Defence-in-depth alongside the existing backend validation in `IntegrationService`. Rejected URLs are logged with their `registry_id` so admins can locate poisoned rows.
- **`rel="noopener noreferrer"`** added to the "Open in new tab" anchor in `AppEmbed`. The previous `target="_blank"` alone leaked `window.opener`.

### Changed
- Cross-origin iframes now skip the CSS-injection retry loop entirely (was running 4 doomed `setTimeout`s on every cross-origin load — silent throws but wasteful).
- "Open in new tab" button is hidden when the URL is empty (rejected by validation); Reload button is disabled in the same case.

## [3.16.0] — 2026-04-28

### Added
- **Admin governance — audit log.** New "Audit" tab in Admin Settings provides per-team activity logs with team picker, event-type filter, date range, paginated table (50/page, max 200), and ZIP export. NC admin only.
- New table `oc_teamhub_audit_log` (immutable from the application layer — only insert, read, and bulk-purge are exposed) created by migration `Version000316000`.
- `AuditService` — single write entry point with non-fatal failure handling and a 500-char-per-field metadata cap.
- `AuditIngestionService` — hourly mirror from `oc_activity` (Circles + files, 14 mapped subjects across both apps) plus snapshot-diff against `oc_share` for `share.created` / `share.permissions_changed` / `share.deleted`.
- `AuditMirrorJob` — hourly `TimedJob` orchestrating the mirror and the retention purge.
- Direct audit logging in `TeamService` (`team.created`, `team.deleted`, `team.config_changed`, `team.app_enabled`, `team.app_disabled`), `MemberService` (`join.requested`, `join.approved`, `join.rejected`, `member.joined` for open-circle self-join), and `MaintenanceService` (`team.owner_transferred`).
- 5 new admin endpoints under `/api/v1/admin/audit/...` for teams summary, paginated events, ZIP export, retention GET/PUT.
- Configurable retention (7–3650 days, default 90) stored in `IAppConfig`. Mirror job clamps and enforces on every cycle.
- Activity-app-disabled detection: when the NC Activity app is unavailable, the Audit tab shows a warning banner and only direct-logged events continue to be captured.

### Changed
- `AuditLogMapper` exposes a separate `insertWithTimestamp()` so the mirror job can preserve the original `oc_activity` timestamp on backdated rows without a follow-up UPDATE — keeps the immutability contract clean.

## [3.15.0] — 2026-04-28

### Fixed
- Calendar widget now reloads automatically after adding an event, scheduling a meeting, or creating a team meeting — all three modal close handlers now call `refreshCalendar()` via the widget grid ref.
- Meeting notes public share link now grants read+write access (was read-only), so attendees can edit the notes file directly from the shared link.
- `@nextcloud/vue` no longer logs "missing appName / appVersion" console errors — `webpack.DefinePlugin` now injects `appName` and `appVersion` as compile-time bare globals, which is what the library reads at module evaluation time.
- Members widget: removed redundant `border-top` from `.teamhub-memberships-list`; `Show all` button width set to 90%; left-side padding unified to 12px across avatar stack, membership rows, and show-all button.
- Removed redundant "Team Messages" heading from the message stream body (the accordion header already shows this label).
- Removed duplicate "Post First Message" button from the empty-state — the header-level "+ Post Message" button already handles this.
- All semantic color text uses (`--color-error`, `--color-success`, `--color-warning`) replaced with their high-contrast `-text` variants across 21 components, improving readability. Backgrounds and borders retain the base variables.

## [3.14.0] — 2026-04-28

### Added
- **Team meeting action** — new "Team meeting" button in the Calendar widget header (distinct from "Add event" and "Schedule meeting"). Creates a `Meetings/` folder in the team files folder, writes a `template.md` if not present, generates a named meeting-notes `.md` file, creates a public share link, schedules the event in the team calendar with Talk URL in the `LOCATION` field (so it appears in Talk's scheduled meetings panel), and adds all team members as ATTENDEE lines.
- **Schedule in Talk checkbox** — opt-in (default on) in the Team meeting modal; uses the team's existing Talk room token or falls back to creating a new room.
- **Ask for agenda items checkbox** — opt-in (default off); posts a message to the Talk room linking to the meeting notes and asking members to add agenda items. Uses `TalkService::postChatMessage`.
- **Meeting permissions setting** in Manage Team → Settings tab (above Team Apps): dropdown to restrict who can trigger the Team meeting action — Any member / Moderator or above / Admin or above. Stored in `teamhub_team_apps` with `app_id = 'meeting'`.
- **Schedule meeting now links to Talk room** — `ActivityService::createCalendarEvent` automatically resolves the team's Talk room and writes the URL to the `LOCATION` and `URL` iCal fields, making the meeting appear in Talk's scheduled meetings panel.
- **Clickable event titles** in the Calendar widget — each event title is now a link that opens the NC Calendar app directly to the event's edit sidebar using the confirmed direct-edit URL format.

### Fixed
- Calendar widget no longer shows soft-deleted events — NC CalDAV renames deleted events to `*-deleted.ics` without removing the DB row; the query now excludes these with a `NOT LIKE '%-deleted.ics'` filter.
- `resolveAttendees` was joining against `oc_accounts` (wrong table); corrected to join `oc_users` matching the proven `MemberService` pattern.
- `resolveUserEmail` was querying a non-existent `email` column on `oc_accounts`; corrected to use `IConfig::getUserValue('settings', 'email')`.
- `TalkService::postChatMessage` — `getParticipant()` was incorrectly passed a `User` object; corrected to pass the UID string as required by Talk's API.



### Added
- **Group and team members are now fully recognised.** When a Nextcloud group or another team is added to a team, its users count towards the team's member total and gain access to the team. The members widget shows direct users as avatars (up to 16, sorted by role then last activity), followed by a flat list of added groups and teams with a `GROUP` or `TEAM` pill and their user count. A "Show all N members" link opens a searchable modal listing every effective user, deduplicated.
- **Manage Team → Members tab** displays three buckets: Direct Members, Groups & Teams (with name and effective user count), and Pending Join Requests. Admins can remove whole groups or teams, which also clears their users' indirect access.
- **Invite modal** can now search for and add other user-created teams (circles) in addition to users, groups, email invites, and federated contacts.
- New `GET /api/v1/teams/{teamId}/members/all` endpoint — returns the flat deduplicated list of all effective users (direct plus expanded from groups and sub-teams) for the Show All modal. Requires member-level access.
- New `GET /api/v1/teams/{teamId}/members/manage` endpoint — structured response (direct, groups, circles, effective_count) for the Manage Team members tab. Requires admin-level access.
- `BrowseTeamsView` teams now return an `isDirectMember` flag so indirect members see a disabled Leave button with an explanatory tooltip rather than being allowed to "leave" a team they were never directly added to.
- `leaveTeam` now detects indirect membership and returns a 403 with an `indirect_member` sentinel so the UI can show the tooltip explanation.

### Changed
- The `GET /api/v1/teams/{teamId}/members` response shape changed from a flat array to `{members, memberships, effective_count, has_more, is_direct_member}`. `members` is limited to the top 16 direct users (sorted by role then last login), `memberships` is the flat list of added groups and teams for the widget.
- Admin Settings → Maintenance team member count column now reflects effective membership (direct users plus users from added groups and sub-teams) instead of only the three top-level rows in `circles_member`.
- `removeMember()` now correctly handles groups (`user_type=2`) and teams (`user_type=16`) by using `single_id` as the delete key. It also calls `MembershipService::onUpdate()` after deletion so removed indirect users actually disappear from share pickers.
- Pending Join Requests in Manage Team has extra top padding to separate it from the membership summary.
- Group and Team icons/pills use the primary-element (blue) and warning (amber) tones respectively — the previous success-green was too low-contrast.

### Fixed
- Integrity check in Admin Settings → Maintenance no longer flags teams as mismatched just because they have a group or sub-team as a member. It now flags only teams whose `circles_membership` cache is genuinely empty while direct members exist.
- `getTeamMembers` no longer fails on the `u.last_login` column (which does not exist on `oc_users`); last-login sorting now reads from `oc_user_preferences` / `oc_preferences`.
- `browseAllTeams` correctly detects membership via groups or sub-teams in addition to direct rows.

### Security
- `getTeamMembers` now enforces `requireMemberLevel` — previously any authenticated user could enumerate any team's member list by guessing a circle ID.
- `lastLogin` timestamps (used internally for sort order) are stripped from the `members` response so they are never exposed to the client.

## [3.11.0] — 2026-04-22

### Added
- **Upcoming Tasks widget now shows personal tasks alongside Deck tasks.** When the NC Tasks app is installed and the team has a calendar, VTODO tasks from the team calendar are fetched server-side (Sabre/VObject, direct DB query on `calendarobjects`) and merged with Deck cards into a single sorted list. Each task row shows a source pill — blue "Deck" or teal "Personal task" — so users can distinguish at a glance. The two task types also use different badge icons.
- New `GET /api/v1/teams/{teamId}/tasks` endpoint — returns upcoming (≤14 days, non-completed) VTODO tasks from the team calendar.
- New `POST /api/v1/teams/{teamId}/tasks` endpoint — creates a VTODO in the team calendar via `CalDavBackend` (QB fallback if unavailable).
- New **Create personal task** action in the Upcoming Tasks widget header, which opens a modal (title, optional description, optional due date/time). Shown only when Tasks app is installed and team has a calendar.
- The existing **Add task** action renamed to **Create Deck task** to distinguish it from personal tasks. Shown only when team has a Deck board.
- `resources` payload from `GET /teams/{teamId}/resources` now includes a `tasks: bool` flag indicating whether the NC Tasks app is installed.
- New `AddPersonalTaskModal.vue` component.
- New `lib/Service/TaskService.php` service.
- New migration `Version000310001` — ensures `teamhub_integ_registry` exists and drops the legacy `teamhub_integration_registry` table if it survived an NC uninstall. Fixes a scenario where NC's "delete all data" uninstall keeps migration history, causing the new-name table to never be created on reinstall.

### Fixed
- Fixed `oc_teamhub_integ_registry does not exist` error on installs where NC's uninstall-with-delete-data flow preserved migration history, causing migration 000209000 to be skipped on reinstall while the old `teamhub_integration_registry` table survived.

## [3.10.0] — 2026-04-21

### Fixed
- Renamed `teamhub_integration_registry` (28 chars) to `teamhub_integ_registry` (22 chars) across all migrations, mappers, and services to comply with NC's 27-character table name limit.
- Added explicit primary key constraint names to `teamhub_integ_registry`, `teamhub_team_integrations`, and `teamhub_widget_layouts` — auto-generated PostgreSQL names (`oc_{table}_pkey`) exceeded 27 chars and failed NC schema validation.
- Added migration `Version000300901` to rename auto-generated PK constraints on existing PostgreSQL installs.
- Retired `Version000300900` rename logic after discovering `IDBConnection::getPrefix()` does not exist on NC 33's `ConnectionAdapter`; now a safe no-op.

## [3.9.0] — 2026-04-21

### Fixed
- Fixed fresh-install failure: `teamhub_team_apps.enabled` was declared `BOOLEAN NOT NULL` which Doctrine rejects when storing `false` on MySQL/MariaDB; changed to `SMALLINT NOT NULL DEFAULT 1`.
- Fixed same BOOLEAN/NOT NULL issue on `teamhub_integration_registry.is_builtin`; changed to `SMALLINT NOT NULL DEFAULT 0`.
- Added migration `Version000300801` to apply both column type fixes to existing installations.

## [3.8.0] — 2026-04-20

### Added
- Telemetry payload expanded with six new anonymous metrics: `nc_version`, `user_count`, `member_total`, `message_count`, `builtin_integrations` (per-builtin-app team counts), and `link_domains` (custom-link hostname frequency map).
- `link_domains` aggregates custom web-link URLs down to their bare lowercase hostname before sending — no paths, query strings, ports, fragments, localhost entries, or numeric IPs leave the instance.

### Changed
- `GET /api/v1/admin/telemetry` preview object now includes all new fields; admin UI automatically renders them via the existing JSON preview.
- `TelemetryService` now depends on `IUserManager` for user counting.

### Security
- All new collection paths are read-only DB queries using `QueryBuilder` with named parameters — no new user-input surface.
- No new endpoints; existing telemetry endpoint remains `#[AuthorizedAdminSetting]`-guarded.

---

# TeamHub v3.5 — Changes


## Admin Maintenance tab — full teams grid

Replaced the old "Orphaned teams" section with a full teams management grid covering every user-created team on the NC instance.
**What it does:** Paginated table with search by name, "orphans only" toggle, and per-page selector (10/20/50/100). Each row shows team name, description, member count, owner (display name + uid), and creation date. Two icon-only action buttons per row: set owner and delete.

---

## Set owner

Admin can assign any NC user as owner of any team — whether or not that user is currently a member.

## Delete team (admin)

Admin can delete any team regardless of ownership. Cleans up all associated data before destroying the circle.


TeamHub v3.6 — Changes
## Activity widget

Deck activity now scoped to the team's board only — card events (deck_card) and board events (deck_board) handled separately
Talk activity scoped to the team's room via numeric room ID — eliminates cross-team bleed
Calendar/DAV activity subject strings corrected to match real oc_activity values
Friendly human-readable labels for all Deck, Calendar, and Circles activity subjects

## Manage Team — Maintenance tab

"Danger Zone" tab renamed to "Maintenance"
Transfer ownership added — team owner can promote any current team member to owner
Ownership transfer requires two-step confirmation and demotes the current owner to admin
Leave team now shows the real server error message (e.g. "Transfer ownership before leaving")

## Admin Settings — Membership cache integrity

New section in the Maintenance tab: scan all teams for stale membership cache
Compares circles_member (source of truth) against circles_membership (share picker cache)
Per-team Repair button rebuilds the cache — fixes teams invisible to Files, Calendar and Deck share pickers

## Files 

Re-enabling the Files app for a team now works correctly
Favourite Files and Recently Modified widgets no longer appear on teams without a connected Files resource