# TeamHub — Handoff Document

> Read this file at the start of every session, alongside SKILLS.md.
> It describes current state, open issues, and what to work on next.

---

## Current version

**3.10.0** — released 2026-04-21

---

## Recent session history

### 2026-04-21 — Table name & primary key constraint audit
**Goal:** Rename `teamhub_integration_registry` (28 chars, over NC's 27-char limit) to `teamhub_integ_registry`, and fix all primary key constraint names that auto-generated to over 27 chars.

**What was done:**
- Renamed `teamhub_integration_registry` → `teamhub_integ_registry` across all migration files, mapper files, and service files.
- Added `Version000300900` (no-op placeholder) for the table rename — rename logic was retired after discovering `IDBConnection::getPrefix()` does not exist on NC 33's `ConnectionAdapter`; the 1–2 live installs that had the old name were remediated separately.
- Added explicit PK names to three `setPrimaryKey()` calls in base migrations where auto-generated PostgreSQL constraint names exceeded 27 chars:
  - `teamhub_integ_registry` → `th_integ_reg_pk`
  - `teamhub_team_integrations` → `th_team_integ_pk`
  - `teamhub_widget_layouts` → `th_widget_lyt_pk`
- Added `Version000300901` to rename auto-generated PK constraints on existing PostgreSQL installs (no-ops on MySQL/MariaDB; uses `IConfig::getSystemValue('dbtableprefix')` to avoid the `getPrefix()` API gap).

**Key lesson learned:** `IDBConnection::getPrefix()` does not exist on `OC\DB\ConnectionAdapter` in NC 33. Use `IConfig::getSystemValue('dbtableprefix', 'oc_')` in migrations that need the table prefix.

---

### 2026-04-21 — BOOLEAN/NOT NULL column fix (v3.9.0)
Fixed `enabled` and `is_builtin` columns that were declared `BOOLEAN NOT NULL`, which Doctrine rejects when storing `false` on MySQL/MariaDB. Changed both to `SMALLINT`. Added `Version000300801` to apply the fix to existing installs.

### 2026-04-20 — Telemetry expansion (v3.8.0)
Expanded telemetry payload with six new anonymous metrics. No new endpoints.

---

## Open issues

None carried forward from this session.

---

## Known constraints & learnings

- **`IDBConnection::getPrefix()` does not exist in NC 33.** Use `IConfig::getSystemValue('dbtableprefix', 'oc_')` instead in any migration that needs the raw table prefix.
- **NC table/index name limit is 27 characters.** This applies to table names, index names, and PK constraint names. On PostgreSQL, auto-generated PK names follow `{prefix}{tablename}_pkey` — always pass an explicit name to `setPrimaryKey()` for any table whose prefixed name + `_pkey` would exceed 27 chars.
- **Circles API is unreliable for circles with non-zero config bitmasks.** Use direct DB operations or the correct service layer (`CircleService`, `FederatedUserService::setLocalCurrentUser`) as the appropriate fallback.
- **NC 32 changed personal circle naming** to `user:{uid}:{randomId}` — do not assume `user:{uid}`.
- **`npm run build` is run by Justin**, not Claude. Deliver source files only.

---

## Immediate next priority

No active ticket. Confirm with Justin at the start of the next session.

---

## Database tables (current)

| Table | Short name | Purpose |
|---|---|---|
| `teamhub_messages` | — | Stream messages |
| `teamhub_poll_votes` | — | Poll votes on messages |
| `teamhub_comments` | — | Comments on messages |
| `teamhub_web_links` | — | Custom team links |
| `teamhub_team_apps` | — | Per-team app toggles |
| `teamhub_last_seen` | — | User last-seen per team |
| `teamhub_integ_registry` | (was `teamhub_integration_registry`) | Global integration registry |
| `teamhub_team_integrations` | — | Per-team integration opt-ins |
| `teamhub_widget_layouts` | — | Per-user widget layout config |

---

*Last updated: 2026-04-21*
