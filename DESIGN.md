# TeamHub — Design Choices

> **Purpose:** the durable record of *why the codebase is shaped the way it is*.
> Read this when you want to understand a decision, not the process that produced it.
>
> Pair with:
> - `MISSION.MD` — what TeamHub is for.
> - `SKILLS.md` — how we work (process, standards, session rules).
> - `ROADMAP.md` — what's planned and where we are.
> - `HANDOFF.md` — current per-session state.
> - `CHANGELOG.md` — what shipped and when.
> - `docs/design/*.md` — deep design docs for individual features.
>
> **Maintenance:** every session that makes a non-trivial design choice adds an entry here. New choices append to the relevant section; the session log at the bottom records when each entry came in. Existing entries are revised in place when a choice is genuinely revisited (rare).

---

## 1. Principles

These are the durable commitments that shape every decision below. They're not negotiable per session — change one and a lot of code follows.

**Reflect Nextcloud reality, don't fight it.** TeamHub is a presentation and integration layer over Nextcloud's existing primitives (Circles/Teams, Deck, Calendar/CalDAV, Files, Talk). When NC has a way to do something, we use it. When NC's behaviour is inconvenient, we adapt rather than work around. The cost of fighting upstream is paid forever.

**Source of truth is wherever the data already lives.** Where NC has a representation (ACL rows, share rows, member rows), we read from it rather than caching. Cached state drifts; derived state stays honest. We add storage of our own only when we're representing concepts NC doesn't have (messages, widgets, integration registry, per-resource curation metadata).

**Authorisation at every boundary.** Membership and admin-level checks happen at the controller level on every team-scoped endpoint, regardless of what the frontend "should" call. The frontend is not a security boundary.

**Cross-database from day one.** MySQL/MariaDB and PostgreSQL are both supported. Type strictness varies between them; we code to the stricter (PostgreSQL) and accept it works on both.

**Layered architecture, thin controllers, fat services, dumb mappers.** Controllers validate input and authorise. Services own business logic. Mappers do CRUD. Services map raw DB rows to plain arrays before returning to controllers — entity objects don't leak past the service boundary.

**One feature per session.** Scope discipline is non-negotiable. Discovered issues become open items, not in-session detours. Every session ends shippable.

**Surface state, don't invent notifications.** When TeamHub needs to tell someone something, we prefer in-app surfaces (widget warnings, settings panel indicators) over the NC notification API. The user already comes to TeamHub for team work; the surface is already there.

---

## 2. Architectural decisions

Organised by area. Each entry: the choice, why, and where to look for more.

### 2.1 Service layer per integrated app

Each NC app TeamHub integrates with has its own service: `DeckService`, `CalendarService`, `FilesService`, `TalkService`. `ResourceService` is the dispatcher that routes per-app calls and assembles the multi-app responses (`getTeamResources`, `createTeamResources`).

**Why.** Each app has its own schema quirks (Deck's ACL table renamed across versions, Calendar's principal-URI parsing, Files' share-vs-storage distinction, Talk's room+attendee model). Concentrating per-app knowledge in one place keeps `ResourceService` from becoming a swamp of `if app === 'deck'` branches and lets us add per-app helpers without touching unrelated code.

**Where.** `lib/Service/{Deck,Calendar,Files,Talk}Service.php`. `ResourceService` for the dispatcher.

### 2.2 Schema-aware insertion via `DbIntrospectionService`

When TeamHub writes into NC app tables (creating a Deck board, populating stacks, inserting ACL rows), it consults `DbIntrospectionService::getTableColumns()` and only sets columns that actually exist on this NC version.

**Why.** Deck in particular has changed its schema across NC 25 → 26 → 27 → 28+ (the boolean-permission columns vs. the bitmask `permissions` column being the most painful example). A naïve `INSERT … VALUES (…)` breaks on whichever NC version we didn't develop against. Introspection lets one code path serve all supported versions.

**Trade-off.** Introspection adds a round trip per write and a small layer of indirection. Worth it.

**Where.** `lib/Service/DbIntrospectionService.php`. Used most prominently in `DeckService::createDeckBoard` and `DeckService::resumeDeckAccess`.

### 2.3 No raw SQL, no `\OC::$server`

All database access goes through `OCP\DB\QueryBuilder`. All dependencies are constructor-injected. No service locator, no global lookups.

**Why.** Both are mandated by NC's app review guidelines. Beyond compliance, both make tests possible and break-on-update problems traceable.

**Where.** Every mapper and service. `SKILLS.md §PHP coding standards` for the rule.

### 2.4 Cross-database type handling

`Types::BOOLEAN` with `notnull=true` fails on MySQL/MariaDB at insert time and on PostgreSQL at parameter-bind time. We use `Types::SMALLINT` with a default, and bind parameters as `IQueryBuilder::PARAM_INT` (not `PARAM_BOOL`).

**Why.** This was a real production bug — PostgreSQL refused the `'f'`/`'t'` boolean coercion that MySQL accepted silently. Discovered and fixed in v3.9.0 for the schema definition; a follow-up fix in v3.28.0 caught the parameter-bind side that was still using `PARAM_BOOL`.

**Where.** `TeamAppMapper::upsert`, `IntegrationRegistryMapper::register`. CHANGELOG entries for 3.9.0 and 3.28.0.

### 2.5 DBAL table-name budget

Nextcloud's DBAL imposes a ≤27-character limit on the *non-prefix* portion of table names. `teamhub_integration_registry` (28 chars) violated this and was renamed to `teamhub_integ_registry`.

**Why.** The constraint is invisible until the migration fails on a real install. Always count characters before naming a new table; abbreviate the noun, never the prefix.

**Where.** Renamed in v3.18 series. New table from this session (`teamhub_team_app_resources`, 26 chars) is within budget.

### 2.6 Circles/Teams membership lookup gotcha

In `circles_member`, `user_id` is a *human-readable label* for `user_type=2` (group) and `user_type=16` (circle) rows, not a lookup key. The actual lookup key is `single_id`, which joins to `circles_circle.unique_id`. Effective team membership for a user must be checked both directly (their `circles_member` row) and indirectly (group/sub-team membership via `circles_membership`).

**Why.** TeamHub wraps Nextcloud Teams. Getting this wrong means users added via a group are denied access to the resources their team has — silent breakage discovered in the v3.13 membership integrity overhaul.

**Where.** `MemberService`, `ResourceService::isEffectiveTeamMember`, `ResourceService::getMemberLevelFromDb`. Fixed in v3.13.0.

### 2.7 Activity sourcing for team events

Circles 33.0.0 emits exactly seven distinct subjects on `oc_activity` (`app=circles`): `member_added`, `member_circle_added`, `member_invited`, `member_remove`, `member_left`, `member_level`, `circle_delete`. There is no `circle_create` event. Team-creation activity is logged directly from `TeamService::createTeam`.

**Why.** Activity-feed consumers learn this the hard way otherwise. The `subjectparams.circle.singleId` in each row equals the team `unique_id` (== TeamHub `team_id`), so no JOIN is needed.

**Where.** `ActivityService` and `TeamService::createTeam`.

### 2.8 Telemetry is anonymous and aggregated

Telemetry payloads include NC version, total counts (users, messages, members), built-in integration usage counts, and aggregated custom-web-link domains. No content. No personal data. Receiver at `tldr.host`.

**Why.** Privacy and trust. Telemetry that risks leaking private information would lose us the right to send it.

**Where.** `TelemetryService`. Expanded in v3.8.0.

### 2.9 Two warning surfaces, no NC notification API

When TeamHub needs to alert a team admin about a state that needs review (resource at risk, pending acceptance, transfer failed), it uses two surfaces:

1. **Settings panel row indicator** in *Manage team → Settings → Team apps* — visible to anyone who can open that panel.
2. **Teaminfo widget warning block** on the team home — conditionally rendered for team admins and owners only, aggregating across resources.

We do **not** use the NC system notification API for these.

**Why.** TeamHub is the place team admins already come to manage team-scoped state. A NC system notification adds noise outside that surface and creates a second source of truth for team state. Keeping warnings inside TeamHub also means we don't have to worry about NC's notification expiration, dismissal, or per-user preference behaviour drifting on us.

**Decided.** This session, 2026-05-08. Confirmed for the upcoming Connected Resources overhaul.

**Where.** `docs/design/connected-resources.md §7`.

### 2.10 Discovery vs. curation: hybrid model

For each resource-backed app (Deck, Calendar, Files, Talk), TeamHub maintains a per-resource metadata table (`teamhub_team_app_resources`) on top of NC's native ACL/share tables. NC remains the source of truth for *what's accessible*. The TeamHub table is the source of truth for *what shows up in the team home* — and crucially adds an explicit `pending` state for externally-shared resources whose owner is not a team admin, gating their appearance behind admin acceptance.

**Why.** Pure discovery (always show what's there) gives admins no control over what lands on the team home. Pure curation (only what was explicitly added) ignores legitimate external shares. The hybrid lets NC admins flexibly grant access while letting team admins curate their team's view. Auto-acceptance when the resource owner is themselves a team admin avoids notifying admins about their own actions.

**Decided.** This session, 2026-05-08.

**Where.** `docs/design/connected-resources.md §3, §5`.

### 2.11 Source-of-truth derivation for resource-backed apps

For Deck, Calendar, Files, and Talk — apps that have an underlying NC representation — the answer to "is this app on for this team?" is derived live from `count(active resources) > 0`, not from a stored boolean. The `enabled` column on `teamhub_team_apps` is no longer read or written for these four apps. It is retained for `intravox` and `shared_files`, which are pure feature toggles with no NC-side resource.

**Why.** Two sources of truth drift. The `enabled` boolean and the actual ACL row were written together when going through TeamHub but only one changed when an admin yanked access externally — producing the long-standing toggle-shows-on-but-resource-is-gone bug. Deriving from the real ACL state eliminates the drift class entirely.

**Decided.** This session, 2026-05-08. Implemented in Session A.

**Where.** `docs/design/connected-resources.md §4.2`.

### 2.12 Multi-resource scope: Deck and Calendar only

Deck and Calendar support multiple connected resources per team. Files and Talk remain 1:1.

**Why.** Multi-board and multi-calendar are real patterns for small teams (separating workstreams, separating schedule from deadlines). Multiple team folders is unusual — subfolders inside the team folder do the same job. Multiple Talk rooms per team is rare and NC Talk's own UI handles it. Limiting the scope keeps the migration tractable and the UX consistent (multi shows up only where it pays off).

**Decided.** This session, 2026-05-08.

**Where.** `docs/design/connected-resources.md §2 (non-goals), §10`.

### 2.13 Three off-actions, distinct semantics

When an admin wants a resource "off" the team, three actions are available, none aliased to any other:

1. **Ignore** — TeamHub-only soft hide. ACL untouched. Reversible.
2. **Remove team access** — strip the team's circle from the resource's ACL. Resource survives, team can't reach it.
3. **Delete the resource** — destroy the resource entirely. Respects per-app soft/hard admin policy.

**Why.** The three are semantically different and have different blast radii. Conflating them (as the previous "disconnect" did) led to admins doing #2 when they meant #1, and to delete buttons that didn't always actually delete. Distinct UI affordances per action prevent the confusion.

**Decided.** This session, 2026-05-08.

**Where.** `docs/design/connected-resources.md §6`.

### 2.14 Continuity on owner deletion: auto-transfer, not team-as-owner

When an account is deleted (event: `BeforeUserDeletedEvent`) and the deleted user owns resources connected to one or more teams, TeamHub automatically transfers ownership using each app's official transfer mechanism (`occ deck:transfer-ownership`-equivalent, `dav:move-calendar`-equivalent, etc.). The successor is computed at transfer time by a deterministic rule (team owner → longest-tenured team admin), not stored per resource.

We considered making the team itself the owner of the resource. We ruled it out: only Calendar supports a circle-principal owner natively, and not via a supported transfer command. Deck and Files have no concept of team ownership.

**Why.** Personal Deck boards, calendars, and the team folder all live in the resource owner's account — when their account is deleted, NC deletes the data unless transferred first. Team-as-owner would solve this cleanly if it existed; absent that, supported transfer is the next best path.

Computing the successor at transfer time (rather than picking one at accept time) is a real simplification: no per-resource UI affordance, no stored field that could go stale, deterministic rule that always reflects the current team state.

**Decided.** This session, 2026-05-08.

**Where.** `docs/design/connected-resources.md §8`.

### 2.15 Account disable surfaces a warning, account delete triggers transfer

`UserChangedEvent` (account disabled) sets a `risk_status='owner_disabled'` flag on every active resource where the disabled user is the current owner. The flag drives the two warning surfaces (§2.9). No transfer attempted — the resource is still functional and the disable might be reversed.

`BeforeUserDeletedEvent` (account being deleted) triggers the auto-transfer (§2.14).

Demotion from team admin does *not* surface a warning. Demotion is forward-looking; only disable and delete create real risk to existing resources.

**Why.** The two events have different urgency and different correct responses. Treating them the same either over-warns on disable or under-acts on delete.

**Decided.** This session, 2026-05-08.

**Where.** `docs/design/connected-resources.md §3.3, §8`.

### 2.16 Default-resource picker, no memory

When a team has 2+ active resources of the same app type, clicking the tab opens a picker. There is no stored "last opened" or default. Picker shows on every click.

**Why.** Picking once and forever is opinionated against teams whose work moves between resources frequently. Storing a default per user adds UI affordance and storage cost for a marginal benefit. If clicks become a real burden in practice, we'd revisit then.

**Decided.** This session, 2026-05-08.

**Where.** `docs/design/connected-resources.md §10.1`.

### 2.17 Migration discipline

Every fix or feature that changes a table or its semantics ships **two** migrations:

1. The base migration update (so fresh installs have the new shape).
2. A separate migration for existing installations (so live deployments are upgraded in place).

**Why.** TeamHub has live installs. A fresh-install-only migration is a silent breakage on every existing deployment.

**Where.** `lib/Migration/`. SKILLS.md mandates this.

### 2.18 Permission alignment at configuration, not by bypass

When TeamHub integrates with another NC app whose actions require permissions a regular team-creating user does not have, we **align permissions at configuration time** rather than bypass the other app's security model in code.

Concrete example: Group Folders requires admin or sub-admin rights to create a folder, and its create endpoint requires password confirmation. TeamHub does not call the underlying service class with elevated privileges to work around this. Instead, the NC admin configures the *team-creator group* (the group of users TeamHub already restricts team creation to) as a Group Folders sub-admin. Now anyone going through the create-team wizard inherently holds the right permission.

TeamHub's role is to **surface misalignment** (read-only status indicator: "team-creator group has Group Folders delegation rights ✓ / ✗"), not to repair it. Repair stays with the NC admin.

**Why.** Bypassing another app's security model is a debt that compounds: every NC version risks breaking the bypass, every audit raises questions, and the relationship between TeamHub's "you can do X" and the underlying app's "you can do X" becomes ambiguous. Configuration-level alignment is provable, auditable, and stable across upgrades.

**Trade-off.** Misconfigured installations get a fallback path (e.g. shared-folder team folder when Group Folders integration is unavailable) rather than a working group folder. This is correct: the feature is opt-in for the admin, not implicit.

**Counter-rule.** When TeamHub *creates* an NC resource on a user's behalf using the same user's session and permissions (calling a service class directly to avoid an HTTP roundtrip — as `DeckService::createDeckBoard` does with `OCA\Deck\Db\BoardMapper`), that is **not** a bypass. The user has the permission; we just skip the network hop. The line is at *elevating* privileges, not at *transport*.

**Decided.** This session, 2026-05-08. Concretised by the upcoming Group Folders integration.

**Where.** `docs/design/team-folder-via-groupfolders.md` (to be written for Session E).

### 2.19 Group Folders dual-folder migration

When a team already has a shared-folder team folder (the legacy pattern) and Group Folders discovery surfaces a group folder for the same team's circle, both coexist briefly. TeamHub does not block this — it auto-accepts the group folder per §2.10's discovery rule for group folders — and resolves the dual state with a deterministic precedence and a user-driven migration.

**Precedence rule.** When both exist, the group folder wins. Widgets and tab-bar links read and write to the group folder. The shared folder remains technically accessible but TeamHub treats it as deprecated; it is not used as a write target by any TeamHub surface.

**Warning surfaces.** During the dual-folder window, both warning surfaces (settings panel row and Teaminfo widget) flag the dual configuration to admins.

**Migration offer.** TeamHub offers an automated migration: copy contents of the shared folder into the group folder. If the admin/owner declines or ignores the offer, migration becomes a manual user task; TeamHub does not initiate anything without explicit consent.

**Migration mechanics.**

- *Pre-flight space check.* Like the archive flow, TeamHub checks the shared folder's used space against the group folder's available space before any copy is attempted. If the copy will not fit, migration is **skipped** with a clear notification: TeamHub explains the constraint and instructs the user to migrate manually and remove team permissions from the shared folder. Insufficient space is a "we won't try" decision, not a failure state.
- *Pre-existing content preservation.* If the group folder already contains files (for any reason — NC admin pre-seeded, another team-member added through Group Folders' own UI), TeamHub creates `team_files_backup/` inside the group folder, moves the existing content there, and *then* copies from the shared folder. User is notified of the backup location. If `team_files_backup/` already exists, append a counter (`team_files_backup_2/`).
- *No per-file conflict logic.* The pre-existing-content move sidesteps file collisions entirely.

**Cleanup is implicit.** Once the team's permissions on the shared folder are removed (by any path — TeamHub's own action, an external admin, the user manually disconnecting), normal reconciliation drops the shared-folder row and the dual-folder warnings clear. No special "I'm done with that folder" affordance is needed.

**Decided.** This session, 2026-05-08. Implementation deferred to Session E.

**Where.** `docs/design/team-folder-via-groupfolders.md` (to be written for Session E).

---

## 3. Open architectural questions

These are not unresolved decisions — each has a current working answer — but each is a place where we may revisit if reality pushes back.

- **Sub-teams** as a first-class concept. Currently deferred. The Connected Resources schema is structured so a sub-team's resource scope can be added later without a second migration. Pull forward only when the demand is concrete.
- **Per-user resource defaults** for the multi-resource picker. Currently no defaults. Revisit if user feedback says click cost is real.
- **Pending-resources count in the Teaminfo widget warning block.** Currently the block aggregates `risk_status != 'none'` only. Whether to also include `status='pending'` rows is deferred to Session B of the Connected Resources work.
- **Cross-team resource sharing.** A resource owned by one team's circle and shared with another team's circle is currently handled as discovery on the receiving team. Whether this should be a richer concept (with audit links across teams) is open.
- **Group Folders integration as the team folder backend (Session E).** Decided in principle this session: integrate Group Folders as a preferred team-folder backend when the app is installed and the team-creator group has delegation rights. Implementation deferred. Migration UX for the dual-folder window (when both a shared folder and a group folder coexist for one team) is fully specified but unimplemented; covered in §2.19 and the future `docs/design/team-folder-via-groupfolders.md`. A half-session spike against the Group Folders OpenAPI spec and a real NC instance is required before Session E proper to verify password-confirmation behaviour for sub-admins, the assign-circle and ACL endpoints, and object-storage installation behaviour.

---

### 2.32 Decisions: predefined categories, not free-text

Each team maintains a predefined list of decision categories (table `teamhub_dec_categories`). The compose form uses a required `NcSelect` populated from this list — free-text category entry is gone. When a team has no categories yet, the composer shows a warning and submit stays disabled.

**Why.** Free-text categories don't compose with approver assignment (the next design entry). Per-category approver lists are useless if categories drift through typos and casing variations. A constrained vocabulary is the precondition for routed approval.

**Where.** `DecisionCategoryService`, `lib/Db/DecisionCategory*`, ManageTeamView Decisions sub-panel, `PostMessageForm` NcSelect.

### 2.33 Per-category approvers, either-one semantics, team-owner default

Each category has an m:n approver list (table `teamhub_dec_cat_apprs`). Any one approver in the list can act — first-approver-wins, no quorum. The team owner is auto-added to every new category as the default approver, and the service refuses to leave a category with zero approvers.

**Why.** Quorum semantics complicate the UI (pending counts per approver, parallel decisions) without giving small teams meaningful value. Team-owner-as-default-approver matches the natural reading of "every team has someone who can finalize", and the never-empty invariant frees Sessions H/I from defensive checks.

**Where.** `DecisionCategoryService::resolveApprovers`, `DecisionCategoryApproverMapper`.

### 2.34 Decisions lifecycle: open → finalized → approved/denied/withdrawn

Status vocabulary:
- `open` — initial state on `propose()`; discussion via comments
- `finalized` — proposer's gavel-click closes discussion and locks comments
- `approved` — terminal; approver accepted the finalized proposal
- `denied` — terminal; approver rejected with a mandatory reason
- `withdrawn` — terminal; proposer (or admin override) cancelled before approval

`COMMENTS_LOCKED_STATUSES = ['finalized', 'approved', 'denied', 'withdrawn']` — only `open` allows further comments. `TERMINAL_STATUSES = ['approved', 'denied', 'withdrawn']` — `finalized` is *not* terminal because it can still flip to approved/denied.

**Why.** "Decided" conflated outcome-good and outcome-final into one state. Splitting `finalized` from `approved` lets the proposer signal "I'm done drafting" without claiming authority they don't have, and gives the approver a clean handoff.

**Where.** `DecisionService::STATUSES`. Legacy values (`proposed`, `decided`) are no longer written; existing test data should be wiped on upgrade.

### 2.35 Finalize is proposer-only; the gavel is on the proposer's own last comment

Finalize is the proposer's statement that the discussion is closed and the final wording is *this* comment. Only the proposer can finalize — there is no admin override. The gavel button only appears on comments authored by the proposer themselves, only while the decision is `open`, and only to the proposer (not to admins or other members).

**Why.** The final wording is the proposer's responsibility. If they're absent, the right path is for an admin to `withdraw` the proposal (admin override exists for withdraw), not for someone else to finalize for them.

**Where.** `DecisionService::finalize`, `CommentsSection::canMarkBestAnswer`.

### 2.36 Proposal document in `{team-folder}/.proposals/{id}.md`

When a decision is finalized, write a markdown document containing the question, the original proposal message, every comment in chronological order with author display names + ISO timestamps, the final wording, and the audit trail. Store at `{team-folder}/.proposals/{decisionId}.md`. Set `source_type='document'` + `source_ref` to the path on the decision row. Regenerate on `approve`/`deny` so the file reflects the final outcome.

**Why.** The decision is the team's institutional record. Storing it as a markdown file in the team folder makes it portable (downloadable, indexable by Files search, accessible offline), persists past the message-stream UI changing, and is naturally team-shared via the existing circle permissions on the folder. Mirrors the `.teamhub-cache/` hidden-folder pattern.

**Why best-effort.** If the team has no team folder, the decision still finalizes; `source_ref` stays null. We don't block lifecycle transitions on file I/O.

**Where.** `DecisionService::writeProposalDocument` + `renderProposalMarkdown` + `regenerateProposalDocument`.

### 2.37 Approver gate has admin fallback for unmatched categories

`approve()` and `deny()` check membership in the decision's category's approver list. If the decision's stored category string doesn't match any predefined category (legacy free-text from before Session G, or a category deleted after the decision was proposed), fall back to "team admin only".

**Why.** Auth gates can't fail-closed when historical data points at something that no longer exists — that would lock decisions forever. Failing back to admin-only preserves the security property ("only people with team authority can act") without blocking legitimate operations.

**Where.** `DecisionService::assertApproverFor`.

### 2.38 Audit trail as a dedicated append-only table

`teamhub_dec_audit` stores one row per lifecycle transition with closed vocabulary `proposed | commented | finalized | withdrawn | approved | denied`. Append-only — no update method. Payload is JSON, transition-specific.

We considered using `oc_activity` for this. Rejected: oc_activity is broadcast-per-user (feed semantics); reading "all events on this one decision" would require an awkward filter; payload schema varies per transition; we want the audit to survive deletion of the originating message or comment.

**Failure mode**: audit writes are best-effort. A failed audit write never aborts the originating action. The operational record (the actual status flip) is more valuable than the audit guarantee.

**Where.** `DecisionAuditService`, `lib/Db/DecisionAudit*`.

### 2.39 Supersede auto-withdraws the original (only if still `open`)

When a new proposal supersedes an existing decision via `supersedesId`, the original is auto-withdrawn at proposal time IF its current status is `open`. The `supersedes` link is captured on the new decision, the original's withdrawal reason becomes "Superseded by decision #X", and the audit trail logs the transition on both sides (`proposed` on the new one, `withdrawn` with `superseded_by` payload on the original).

If the original is in any other state (`finalized`, `approved`, `denied`, or already `withdrawn`), the new proposal still records the supersedes link but does not touch the original. A `finalized`/`approved`/`denied` decision is a settled record — overriding it via supersede would be misleading.

**Why.** The user gesture "this replaces that" needs to actually replace something, not just leave both visible as separate active proposals. Limiting auto-withdraw to the `open` state prevents accidentally hiding a settled outcome.

**Where.** `DecisionService::propose` (supersede side-effect block).

### 2.40 Decision-decision links: one row, canonical ordering, bidirectional read

Bidirectional decision-decision links are stored as **one row per link** in `teamhub_dec_links` with a **canonical ordering invariant** (`decision_id_a < decision_id_b` always). Both directions are read from that single row via a single OR-query (`WHERE decision_id_a = ? OR decision_id_b = ?`). The peer for the calling side is computed in the service when the row is serialized.

**Why.** Storing both directions would double row count and create a write-time invariant (keep the two rows in sync) on every mutation. Canonical ordering plus a unique index on `(decision_id_a, decision_id_b)` makes duplicates impossible regardless of which side initiated the link, with no synchronisation cost. The OR-query is index-friendly because both columns are indexed individually.

**Trade-off.** The service has to do a per-link `findById($peerId)` to enrich the response with peer title/status/level — that's an N+1. Defensible at typical scale (decisions rarely have more than ~5 links, peer lookups are PK hits). Documented in code; revisit if real installs accumulate >20 links per decision.

**Where.** `lib/Db/DecisionLinkMapper::findByDecisionId`, `lib/Service/DecisionLinkService::listForDecision`. Decided this session (v3.74.0).

### 2.41 Decision-link audit on both sides

`createLink` and `deleteLink` log audit events on **both** decisions involved. Each one's audit trail surfaces the relationship from its own perspective — when you view decision A you see "Linked decision: B"; when you view B you see "Linked decision: A". The payload carries `peer_id` and `peer_title`.

**Why.** A single audit row on the "initiating" side hides the relationship from the other decision's history. Symmetric audit matches the symmetric data model.

**Where.** `DecisionLinkService::createLink` / `deleteLink`. Decided this session (v3.74.0).

### 2.42 Cross-team linking rejected at service boundary

A decision can only be linked to another decision **in the same team**. Cross-team links are rejected by `assertDecisionBelongsToTeam` on the peer.

**Why.** Decisions are team-scoped artefacts. A cross-team link would create read-access ambiguity (does seeing a link reveal a decision's existence to a non-member?) and break the team-membership-as-authorization invariant. If cross-team relationships become a real use case, a separate "cross-team reference" concept with explicit consent on both sides would be the right shape, not an overload of the link table.

**Where.** `DecisionLinkService::createLink`. Decided this session (v3.74.0).

### 2.43 One row per link is enough — no relation types

A decision-link is just "these two decisions are related." No "supersedes", "informs", "blocks", "duplicates" etc.

**Why.** Relation types are tempting but create a vocabulary problem (which types? configurable? per-team? per-tenant?) and a UI problem (selectable on creation, displayed in list, filtered in queries). The data model becomes the spec. Plain links cover the actual user need — "I want to see decisions related to this one" — without that committment. If real usage shows specific relation patterns we'd add them as a discriminator column later (the migration is additive).

**Where.** `teamhub_dec_links` schema. Decided this session (v3.74.0).

### 2.44 Cross-widget design unification via shared tokens, not per-widget styles

All TeamHub widgets consume a single global stylesheet (`src/styles/widget-tokens.css`, loaded once from `main.js`) that defines: hard-contrast brand palette (`--th-color-{success,warning,error,neutral}`), typography scale (title 14/600, row primary 14/500, row meta 12/400, pill 10/700), spacing tokens, and shared utility classes (`.th-widget__row`, `.th-widget__state`, `.th-widget__spinner`, `.th-widget__pill` with `--primary/--success/--warning/--error/--neutral` + `--outline` modifier). Per-widget `<style scoped>` blocks keep only what's genuinely unique to that widget (calendar date badge, decisions accent bar, member presence pills).

**Why.** Drift across widgets is the natural failure mode of scoped styles — every widget redefined "the same" row pattern slightly differently (font-size 9-16px, weight 500/600/700, multiple ad-hoc `--color-warning-soft` invented in one widget). Centralising the design tokens makes a single edit propagate everywhere; the typography scale of "size carries hierarchy, weight is reserved for pills and the widget header" is enforced at the token layer, not by reviewer discipline.

**On NC theme variables.** The shared palette deliberately bypasses NC's `--color-success`/`--color-warning`/`--color-error` because those render as soft tints on many themes (the user's installation showed pale mint as "success"). Against the 10px white pill text those failed WCAG AA contrast. Our `--th-color-*` are hex values chosen for ≥4.5:1 contrast on white. NC's `--color-primary-element` is still used for primary actions (it's brand-aware and reliable across themes).

**On size, not weight, for hierarchy.** Widgets previously distinguished primary vs meta line by font-weight (often 500/600 vs 400/500). The new convention: primary line 14px medium, meta line 12px regular. Weight 700 is reserved for pills (uppercase + bold is what makes a pill a pill); weight 600 is reserved for the widget header title. ActivityWidget's subject is the documented exception — sentences read better at regular weight (400).

**Where.** `src/styles/widget-tokens.css` (the file itself), `src/main.js` (the import). Decided this session (v3.74.0).

### 2.45 Activity subject as the documented weight exception

ActivityWidget's subject line uses `font-weight: 400` (regular), overriding the shared `--th-widget-row-primary-weight: 500`.

**Why.** Activity subjects are sentences with embedded entity names ("Alice approved the budget proposal"), not titles. At medium weight the whole sentence reads as a label rather than as prose. Regular weight makes it scan as the narration it actually is. Size (14px) still distinguishes it from the 12px meta line below — hierarchy preserved.

**Where.** `src/components/ActivityWidget.vue` style block, with an inline comment explaining the divergence. Decided this session (v3.74.0).

---

## 4. Session log

When each design choice landed. Append, don't reorder.

| Session | Date | Choices added or revised |
| --- | --- | --- |
| pre-3.28 | various | §2.1–§2.8 (per-app services, introspection, no raw SQL, cross-DB types, table-name budget, circles lookup gotcha, activity sourcing, telemetry privacy). Inferred from existing code, CHANGELOG, and prior session notes — historical attribution best-effort. |
| 3.28.1 | 2026-05-08 | §2.9–§2.16 (Connected Resources design lock). §2.17 (migration discipline) added explicitly. §2.18 (configuration alignment principle) and §2.19 (Group Folders dual-folder migration) added during continued brainstorm on Group Folders integration. First creation of this document. |
| 3.29.0 | 2026-05-08 | §2.20 (dual reconciliation: render-time + cron). §2.21 (auto-accept threshold: owner is team admin level ≥ 8). §2.22 (warning block combined count: pending + atRisk in one surface). |
| 3.30.0 | 2026-05-08 | §2.23 (name resolution server-side in serializeRow, not a separate service). §2.24 (fallback to raw resourceId — never show empty name). |
| 3.31.0 | 2026-05-09 | §2.25 (refreshRiskStatus always re-resolves live owner — drift detection over null-only backfill). §2.26 (resourceWarningFocus as Vuex boolean flag — avoids prop-drilling through 3-layer event chain). §2.27 (transfer_failed stays until human action — reconciler does not auto-retry after admin sets new owner). |
| 3.32.0 | 2026-05-10 | §2.28 (soft delete not implemented for resources — hard delete only, complexity not justified). §2.29 (multi-resource pills shown only when team has 2+ of that app — single resource teams stay clean). §2.30 (IManager not used for circle share deletion — QB direct delete for share_type=7). §2.31 (Disconnect = strip ACL only, resource survives; Delete = destroy resource permanently). |
| 3.74.0 | 2026-06-09 | §2.40 (decision-link: one row + canonical ordering + bidirectional read). §2.41 (audit on both sides). §2.42 (cross-team rejected at service boundary). §2.43 (no relation types). §2.44 (cross-widget design unification via shared tokens). §2.45 (activity subject as documented weight exception). |
| 3.73.0 | 2026-06-09 | §2.32 (compose modal auto-finalize: proposals via compose skip open/discussion phase, land as 'finalized'). §2.33 (writeProposalDocument outside transactions: refresh-proposal endpoint handles post-commit file writes). §2.34 (task links use relative paths, not structured Deck card IDs — simpler, supports any URL). §2.35 (single approval modal replaces separate Approve/Deny buttons + old deny modal). §2.36 (shared icon library: 320 icons in one module, both consumers import from it). |
| 3.71.0 | 2026-06-03 | §2.32 (predefined categories — free-text picker gone). §2.33 (per-category m:n approvers, either-one semantics, team-owner default, never-empty invariant). §2.34 (5-state lifecycle: open → finalized → approved/denied/withdrawn; comments lock at finalized; only approved/denied/withdrawn are terminal). §2.35 (finalize is proposer-only, gavel only on their own comment). §2.36 (proposal markdown in `{team-folder}/.proposals/`, regenerated on approve/deny). §2.37 (approver gate falls back to admin-only when category isn't predefined). §2.38 (audit trail as dedicated append-only table; not oc_activity; best-effort never aborts the action). §2.39 (supersede auto-withdraws the original only when it's still `open`). |
| 3.60.0 | 2026-05-30 | §2.32 (RoomVox integration via documented public REST API v1, not internal classes). §2.33 (`nextcloud-allow-local-address` per-request flag for same-instance public API calls is NOT a bypass — SSRF protection is for user-controlled URLs). §2.34 (stage 1 of meeting wizard is presence-only; calendar consultation belongs to stage 2). §2.35 (busy reads cover personal-principal + team-membership calendars; external shares out of scope). §2.36 (cross-team busy visibility surfaces conflict counts only, never event details). §2.37 (RRULE expansion via Sabre `EventIterator` with 1000-iteration safety cap). |
| 3.63.0 | 2026-06-02 | §2.38 (members widget = three tabs, one row per member, no avatar stack; Tomorrow tab gated by both global and per-team presence toggles; Talk via `?callUser=`). §2.39 (profile-field exposure respects IAccountManager scope — `SCOPE_PRIVATE` filtered for email and phone alike; legacy `IUser::getEMailAddress()` is fallback only). |
| 3.72.0 | 2026-06-04 | §2.40 (proposal source files served via TeamHub endpoint, not NC's `/f/{id}` redirect — defense-in-depth: NC ACL + `.proposals/` ancestor check). §2.41 (approve reason stored in audit payload only, not a column — `approved_reason` would be semantically wrong; audit timeline is the right home for rationale). §2.42 (in-app file viewer renders by type, no NC Files-app embed — direct markdown via DOMPurify, image via `/core/preview`, PDF via native `<embed>`, honest "Preview not available" fallback for everything else). §2.43 (Group Folder team folders: attachments upload to user's PERSONAL `TeamHub Attachments/` instead of inside the GF, sidestepping ACL-related MKCOL 405; copy-on-finalize still lands them in `.proposals/`). §2.44 (Decisions module enable/disable lives only under Integrations tab; categories management lives only under dedicated Decisions tab — single-source-of-truth per UI concern). |

---

## §2.32 RoomVox integration is via documented public REST API

When TeamHub creates a meeting that books a physical room via RoomVox, it calls RoomVox's documented public REST API v1 (`/index.php/apps/roomvox/api/v1/rooms/{roomId}/bookings`) using a Bearer token configured by an NC admin in TeamHub admin settings. We do **not** resolve RoomVox's internal PHP service classes via the DI container, even though that would avoid the HTTP roundtrip.

**Why.** RoomVox's public REST API is the integration surface RoomVox themselves designate for external consumers. Their PHP class names, method signatures, and dependencies are internal and can change in any version. Using the public API:
- Keeps the contract stable across RoomVox versions (the REST API is the published interface)
- Respects RoomVox's auth model (the admin scopes the token in RoomVox itself with `book` permission, optionally per-room)
- Matches DESIGN §2.18 (configuration alignment over bypass) — the admin's token-configuration step IS the alignment

**Trade-off.** One HTTP roundtrip to localhost per booking. Acceptable at ~50ms in the user-facing create-event flow.

**Decided.** Session 2026-05-30. Started with discovery via NC's `OCP\Calendar\Room\IManager` (which RoomVox does register with), then booking via REST.

**Where.** `lib/Service/RoomVoxClient.php`, `lib/Listener/CalendarObjectDeletedListener.php`, RoomVox admin settings in `lib/Service/TeamService.php` getter/saver.

---

## §2.33 `nextcloud-allow-local-address` per-request flag is correct usage

NC's `IClient` blocks loopback HTTP by default (SSRF protection). The correct option key is `$options['nextcloud']['allow_local_address'] = true` (nested array, not the flat `nextcloud-allow-local-address` form which NC silently ignores). TeamHub uses this flag **per-request** on calls to RoomVox's documented public API.

**Why this is not a security regression.** NC's SSRF guard exists to stop *user-controlled URLs* from reaching localhost — a classic SSRF attack vector. Our URL is generated by NC's own `urlGenerator`, is constant (always RoomVox's API endpoint), and is authenticated by RoomVox's own Bearer token. None of the threat-model assumptions SSRF protection is built around apply. The flag's existence is precisely for legitimate same-instance app-to-app integrations like ours.

**Where.** `lib/Service/RoomVoxClient.php` — both `createBooking()` and `cancelBooking()`.

**Wrong forms to avoid.** `'nextcloud-allow-local-address' => true` (flat key — NC ignores). Any global override of the SSRF guard (would weaken every other IClient call). Bypassing IClient entirely (would lose NC's other safeguards: certificate verification, timeout enforcement, etc.).

---

## §2.34 Meeting wizard stage 1 is presence-only

The half-day scorer (`MeetingSuggestionService`) consults presence data only. Calendar conflicts — team or personal — are NOT considered at this stage. The fine-grained free-window check happens in stage 2 (`TimeslotSuggestionService`), which reads each attendee's full calendar set inside the chosen half-day.

**Why.** A 30-minute calendar conflict shouldn't eliminate an entire half-day from the wizard's recommendations — there might be three free hours around it. The two-stage model lets stage 1 be coarsely "is this person available to attend a meeting in this half-day" (= presence question) and stage 2 be precisely "where in this half-day is there a free N-minute window" (= calendar question).

Earlier iterations during this session wired `TeamCalendarBusyProvider` and then `PersonalAndTeamBusyProvider` into stage 1. Both were wrong: existing events were killing whole half-days instead of just narrowing where stage 2 could place the meeting.

**Where.** `lib/AppInfo/Application.php` — `MeetingSuggestionService` is registered with an empty providers array. The two busy providers stay registered (auto-wired) for stage 2 / future callers.

---

## §2.35 Busy reads cover personal-principal + team-membership calendars

When the meeting wizard asks "is user X busy at time T," the answer comes from:
1. Calendars X owns via their personal principal (`principals/users/{uid}/…`)
2. Calendars of every team X is a member of (`principals/circles/{teamId}/…`, where membership is direct OR via group, resolved via `circles_membership.single_id`)

**Out of scope:** calendars individually shared TO X via `dav_shares` outside any TeamHub-managed team.

**Why.** Personal calendars cover "X's own appointments." Team-membership calendars cover "appointments X has via teams." Together these cover every event X would be expected to attend through TeamHub's own scheduling surface. Individual third-party shares would add complexity (per-call `dav_shares` joins, harder caching) for marginal benefit — they're rare in practice, and "I shared my calendar with X" usually doesn't carry an attendance commitment the way team membership does.

**Where.** `lib/Service/Suggestion/PersonalAndTeamBusyProvider.php` and `lib/Service/Suggestion/TimeslotSuggestionService.php` — both implement the same scope via `resolveAccessibleCalendarIds()`.

---

## §2.36 Cross-team busy visibility surfaces conflict counts only

When admin Z runs the wizard for team Z and selects user X, who is ALSO a member of team Y, X's calendar conflicts from team Y contribute to the "X is busy" signal. Admin Z sees only the conflict *count* — never the event title, attendees, location, or any other detail.

**Why this is correct.** Time commitments are a coordination signal, not a secret. Without this signal, scheduling tools would lie ("X is free at 10:00 Thursday" when X is in a team-Y meeting at 10:00). The privacy-respecting model is: surface the constraint, hide the content.

**Why this is not a privacy regression.** Admin Z can't read team Y's calendar; the wizard only tells them "X is busy at this time." That's the same information X would convey if asked verbally. The wizard simply automates the question.

**Where.** Documented behaviour of `PersonalAndTeamBusyProvider`. No special code path — the provider returns `{start, end}` integer timestamps with no metadata.

---

## §2.37 RRULE expansion via Sabre EventIterator with 1000-iter safety cap

Recurring events whose master DTSTART lies outside the search window must still be considered busy at every occurrence inside the window. TeamHub uses `\Sabre\VObject\Recur\EventIterator` with `fastForward()` to the window start, walking concrete occurrences (applying overrides and exclusions) until the iterator exceeds the window end. A safety cap at 1000 iterations protects against malformed `FREQ=MINUTELY` events without bounds.

**Why this is the correct mechanism.** It's the same iterator NC Calendar itself uses for occurrence expansion. Reimplementing RRULE handling would be wrong; Sabre's implementation is RFC 5545-conformant and battle-tested.

**Where.** `lib/Service/Suggestion/PersonalAndTeamBusyProvider.php` and `lib/Service/Suggestion/TimeslotSuggestionService.php`.

**Trade-off.** Iterator construction + fastForward adds ~1ms per recurring event. Acceptable for our scale (≤50 recurring events across all attendees in a typical window).

---

## §2.38 Members widget — three tabs, one row per member, no avatar stack

The members widget on the team home is a three-tab `MembersWidget.vue` (Members / Tomorrow / Search) with one row per effective team member. The previous avatar-stack + group/team pills + "Show all" modal was replaced.

**Why.** The avatar stack scaled badly past ~16 members and gave each member only a coloured dot. A vertical scrollable list per member lets us surface live status text, an inline message ("In Meeting"), and contact actions without a second click into a modal. The Tomorrow tab makes scheduled presence first-class — the previous widget only showed today's merged dot.

**Tab gates.** Members and Search are always shown. Tomorrow is hidden when either the global `presenceModuleEnabled` toggle is off or the team's per-team `presence_enabled` is off. A team without presence sees a clean two-tab widget.

**Deduplication.** The Members tab lists effective users (direct + indirect via groups/sub-teams, deduplicated by uid). The way each user joined the team is still visible in *Manage team → Members*; the widget is the consumption surface, not the administrative surface.

**Contact actions per row.** Talk, phone, and email icons render per row. Each is shown only when the corresponding data exists for that user and the corresponding capability is available (Talk requires Spreed enabled for the viewer). No greyed-out disabled icons — absence is the affordance.

**Talk 1:1 launch via `?callUser=`.** The Talk URL is `generateUrl('/apps/spreed/') + '?callUser=' + encodeURIComponent(uid)`. Verified on the live install. This is one frontend line and zero backend code; the OCS-create-room alternative was considered and rejected for this volume.

**Where.** `src/components/MembersWidget.vue`, `src/components/members/MemberRow.vue`, `src/components/members/MemberPresenceRow.vue`. Inline replaced in `TeamWidgetGrid.vue` (desktop + tablet) and `MobileWidgetView.vue`.

---

## §2.39 Profile-field exposure respects IAccountManager scope, never legacy IUser getters alone

Email and phone are returned to other team members via `IAccountManager::getAccount($user)->getProperty(...)` and filtered to skip `SCOPE_PRIVATE`. The legacy `IUser::getEMailAddress()` is used only as a fallback when `IAccountManager` is unavailable (very unusual NC installs).

**Why.** Users can mark profile fields as Private in their NC profile settings. A widget that shows them to fellow team members despite that scope choice is a privacy regression — even when the audience is technically authorised to know who's in the team. The team-membership gate (`requireMemberLevel`) controls *who can call the endpoint*; the per-property scope controls *what they see*. Both apply.

**Where.** `MemberService::enrichMembersForWidget`. Applies to email and phone equivalently as of v3.63.0.

---

## §2.40 Decisions module gate: assertModuleEnabledForTeam is the sole security boundary

All Decisions API endpoints call `DecisionService::assertModuleEnabledForTeam(string $teamId)` as the first line in the controller method. This method checks two conditions: (1) global app-config flag `decisions_module_enabled === '1'`, and (2) the team's `teamhub_decision_team` row has `decisions_enabled = 1`. If either fails, it throws `RuntimeException` which the controller maps to 404 — treating the feature as non-existent when off.

**Why 404 and not 403.** 403 tells callers the feature exists but they're forbidden. 404 reveals nothing about whether the feature exists. When the module is globally off, there is no meaningful difference between "you can't use it" and "it doesn't exist" from the caller's perspective — 404 is the safer and cleaner contract.

**Why a single boundary in DecisionService and not in each service method.** The controller is the right boundary for gate checks — it owns the HTTP contract. Duplicating gate checks in service methods creates two places to maintain and two places to forget. The service layer assumes the gate has already passed.

**Mirrors the Presence module pattern exactly.** Same shape, same rationale, same location (controller → service assertion → mapper read).

**Where.** `DecisionService::assertModuleEnabledForTeam()`. Called in `DecisionController::getConfig()` and `DecisionController::saveConfig()`. Will be called in all Session B controller methods.

---

## §2.41 Decisions config co-delivered with layout response

`decisionsConfig` and `decisionsModuleEnabled` are bundled into the layout `GET` response by `LayoutController`, eliminating a separate round-trip on team switch. This mirrors the `presenceConfig`/`presenceModuleEnabled` pattern added in an earlier session.

**Why.** The team home always loads the layout. Piggy-backing config values on a request that already happens avoids a race condition (module state is known before tabs are built) and reduces total request count.

**Where.** `LayoutController::getLayout()` (both the team-specific and cascaded-default code paths). `TeamView.vue` reads both fields from the layout response.

## §2.42 Decision rows are terminal-immutable; supersession is forward-only

Once a decision moves to `decided` or `withdrawn`, the row is closed for further status changes. Editing the answer post-fact, reversing a withdrawal, or "re-opening" are not supported operations. The only forward path is to propose a NEW decision with `supersedes_id` pointing back at the closed one.

**Why.** Decisions are a record. Letting them be reopened erodes the audit value — a row's history would no longer reflect what actually happened. Forward-only supersession keeps every closed row truthful and makes the chain visible.

**Where.** `DecisionService::assertNotTerminal()` enforces this for `markBest` and `withdraw`. The `supersedes_id` column on `teamhub_decisions` and the supersession validation in `propose` form the forward path.

---

## §2.43 selected_answer is denormalised onto the decision row

When a decision is marked, the selected comment's body is copied into `teamhub_decisions.selected_answer`. The decision row also stores `selected_comment_id` as a back-reference, but the answer text lives on the decision row itself.

**Why.** The selected answer must remain readable when the underlying comment is deleted — either by the author or by an admin. Reading the answer from a comment that might be a tombstone breaks the decision record. Snapshotting at mark time keeps the row honest forever, at the cost of duplication.

**Trade-off.** If a privacy-sensitive comment is the selected answer and the user later deletes it, the content persists on the decision row. We accept this because the act of marking is itself a deliberate "this is the record" action — comparable to a meeting minute being preserved even after a Slack channel is purged. If a real privacy issue surfaces, the team admin can withdraw and re-mark (or supersede with a new decision).

**Where.** `DecisionService::markBest()` copies the comment body; `selected_answer` and `selected_comment_id` columns on `teamhub_decisions`.

---

## §2.44 Cross-team scope is enforced by 404, never 403

Any decision, message, or task link lookup that resolves to a row belonging to a different team is treated as 404 — not 403. The frontend cannot tell the difference between "doesn't exist" and "exists but you can't see it".

**Why.** 403 leaks existence. For a feature where decisions can be sensitive (HR, strategy), even existence is information. The 404 approach also unifies frontend handling: every "you can't see this" response routes the user back to the team home without a special case.

**Where.** `DecisionService::loadDecisionInTeam()`, `propose`'s message-team check, `DecisionMapper`'s team-scoped queries.

---

## §2.45 Deck card visibility: link-time ACL only

When a card is linked to a decision via `linkTask`, we verify the card exists (`DeckService::cardExists`) but do not re-check ACL on every later read. Subsequent renders use `getCardsByIds` which returns title + board metadata without consulting Deck's ACL.

**Why.** A user who could link a card had ACL on it at link time — that's verified at the Deck UI layer where they picked it. The link itself is the act of bringing it into the team's record. Later, if ACL changes, two outcomes are acceptable:
- They can still SEE the title (metadata-only display) — fine, it's not sensitive content.
- They can't FOLLOW the link — Deck enforces this on the click-through.

Re-checking ACL on every read would push us into Deck's permission model in a brittle way (Deck's ACL tables renamed across NC versions; we've already documented the deck_board_acl/deck_acl divergence). Avoiding that read-path ACL keeps the integration thin.

**Trade-off.** A team admin who revokes someone's Deck access will see that user's old decision-task links continue to render the card title. The content of the card is not exposed; only its title and board name. This is acceptable as audit metadata.

**Where.** `DeckService::getCardsByIds()`, `DeckService::cardExists()` (link-time only), `DecisionService::linkTask()`.

---

## §2.46 Comment lock at the controller boundary, predicate from the service

The comment lock — refusing comment writes when the parent message has a terminal-state decision — is enforced at the `CommentController` level. `DecisionService` exposes the predicate `isCommentLocked($teamId, $messageId): bool`; the controller decides whether to honour or override (admin moderation).

**Why.** The controller owns the auth model. CommentService doesn't exist; `CommentMapper` is just CRUD. Threading admin-override semantics into the service layer would conflate decision logic with comment-write authorisation. The split keeps each layer's concern narrow.

**Where.** `CommentController::checkDecisionLockForMessage()`, `CommentController::createComment`/`updateComment`/`deleteComment`; predicate from `DecisionService::isCommentLocked`.

---

## §2.47 Cursor pagination uses id alone, even with timestamp sort

`DecisionMapper::list()` supports two sorts: `'recent'` (COALESCE timestamp DESC) and `'created'`. The pagination cursor (`before` parameter) uses only the decision `id`, regardless of sort. With timestamp sort this means we approximate — we return rows with `id < before` rather than rows with a timestamp earlier than the boundary row's timestamp.

**Why.** A full timestamp+id cursor would need to expose the boundary row's timestamp to the frontend, which complicates the API and adds tie-breaking edge cases. For the common case ("show me older decisions"), id-cursor + DESC sort drops you onto older rows ~correctly because creation order ≈ recent order. The pathological case — a freshly decided very-old decision sneaking in — is rare enough to accept in v1.

**If this becomes a real problem,** revisit by adding `before_ts` alongside `before` and using a tuple cursor `(ts, id)`.

**Where.** `DecisionMapper::list()`, `DecisionService::list()` cursor return value.

---

## §2.48 PHP brace-balance check is part of session-end (effective from Session B)

After two parse-error bugs slipped past session-end in the v3.64.x line, the session-end sequence now includes a brace/paren/bracket balance check on every changed PHP file. This is a 5-second sanity check that catches the highest-frequency `str_replace`-induced parse errors.

**Why.** No PHP linter is available in the development environment Claude uses; full `php -l` runs would require shipping a binary into the working environment. Brace balance is the cheap proxy that catches the failure mode we've seen.

**Where.** Run mechanically before packaging. To be added to `SKILLS.md` §session-end at the next project-file edit.

## §2.49 Decision-typed message creation is a server-side transaction

When a message is posted with `messageType='decision'`, the message-row insert and the decision-row insert happen inside a single DB transaction in `MessageService::createMessage`. If `DecisionService::propose` throws (e.g. invalid impact, bad supersedes target, source-type rejected), the transaction rolls back so no orphan decision-typed message survives.

**Why server-side and not two API calls.** The plan considered making the frontend do two sequential POSTs (one to create the message, one to create the decision). That approach was rejected because the partial-state window — message created, decision creation failed — is real and recoverable only with non-trivial frontend logic. A server-side transaction is the simplest correctness story.

**Why in `MessageService` and not a new `/messages/decision` endpoint.** Adding a parallel endpoint would split the message-creation pipeline (notifications, mention parsing, priority email) into two places. Extending the existing flow with an optional `?array $decisionData` parameter keeps one notification path, one mention parser, one email path.

**Trade-off.** The createMessage signature now has one more optional parameter and one more conditional branch. Acceptable: the branch is small and the alternative was a parallel pipeline.

**Where.** `MessageService::createMessage` — the `if ($messageType === 'decision') { beginTransaction → create message → propose → commit }` block.

---

## §2.50 Decision payload is hydrated onto messages at fetch time

`MessageService::getTeamMessages` walks the returned message list, collects the IDs of any `messageType='decision'` rows, and calls `DecisionMapper::findByMessageIds` in a single query to hydrate the `decision` field directly onto each message. No separate frontend fetch.

**Why.** The team home shows the message stream; decision-typed messages need their status, impact, and reason data immediately to render correctly. A separate fetch per decision-typed message would multiply round-trips on the most-viewed surface in the app. One batch query at fetch time is the right shape.

**Auth note.** `hydrateForMessages` carries no gate check because the caller (`getTeamMessages`) has already authorised the user as a team member. Surfacing a decision row keyed to a message the user can already see is not a leak.

**Where.** `MessageService::getTeamMessages`, `DecisionMapper::findByMessageIds`, `DecisionService::hydrateForMessages`.

---

## §2.51 Frontend lock mirrors backend lock — but admin moderation diverges by affordance

`CommentsSection.vue` computes `decisionLocked` and `commentsReadOnly` to disable inputs, edit pencils, and delete buttons when the parent decision is in a terminal state. This mirrors `DecisionService::isCommentLocked` exactly. Admin override is honoured: admins see delete affordances on locked threads (to enable moderation) but NOT edit affordances on other authors' comments.

**Why the asymmetry.** Editing someone else's content is a stronger moderation action than deleting it. Deletion is reversible-ish (the user can re-post; nothing is materially altered). Edit silently rewrites authorship. Even with admin level, we don't expose an "edit this for the author" affordance.

**Same rule applies to the `selected_comment_id`:** once a comment has been marked as the recorded answer, no one (not even admins via UI) can edit it; admins can still delete it. The selected-comment lock is forward-only.

**Where.** `CommentsSection.vue` `canEditComment(c)` and `canDeleteComment(c)`. Backend lock unchanged from Session B.

---

## §2.52 source_type / source_ref are reserved for future entry points, not exposed in v1 compose

The `teamhub_decisions.source_type` and `source_ref` columns exist on the schema from Session A but are **not exposed in the v1 in-stream compose form**. Every decision proposed via the compose form has `source_type='message'` set automatically server-side, with `source_ref` left null.

**Why we removed the picker.** The first iteration of Session C surfaced a "Source reference" picker in the compose form with Document (via Files picker) and External URL options. Two problems made this wrong:

1. A user's Files picker scope is their personal files; storing a path to a personal file as a team decision's source is broken — other team members can't follow the link. The proper way to attach a file to a decision-bearing message is the existing attachment flow in the message body, which handles team-scoped sharing.
2. External URLs are equally well-served by being links inside the message body — the body already supports markdown link rendering.

In both cases the message body is the right home for context, not a separate per-decision field with weaker semantics.

**Why we kept the columns.** The columns are forward-looking. They give future entry points a structural slot to populate:
- A meeting-notes conversion ("turn this paragraph into a decision") would set `source_type='meeting_notes'` and `source_ref=<doc-id>`. (`meeting_notes` is rejected at the API layer in v1 — DESIGN.md §2.43 docblock — but the column is ready.)
- A future "API-created decision" (e.g. from an external workflow tool) would set `source_type='external'` and `source_ref=<URL>`.
- A "convert this message into a decision" action (deferred from the v1 scope) would set `source_type='message'` and `source_ref=<message-id>` if the source message differed from the carrier message.

**Defaulting rule.** `DecisionService::propose` defaults `source_type` to `'message'` when the caller omits it. v1 compose form omits both source fields; the default applies.

**Where.** The PostMessageForm decision block has Impact + Category only; no source picker. MessageCard's decision meta strip shows category only. Backend `propose()` defaulting unchanged.

---

*This file is consulted at the start of every session per SKILLS.md. If a future session's choice contradicts an entry here, revise the entry — don't leave both.*
