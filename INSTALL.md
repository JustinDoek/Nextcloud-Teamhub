# Installation & Administration Guide

## Requirements

- Nextcloud 33 or later
- PHP 8.1, 8.2, 8.3, or 8.4
- Nextcloud Teams (Circles) app enabled
- PostgreSQL or MySQL/MariaDB

## From a release zip

1. Download the latest zip from the [Releases](../../releases) page
2. Extract into your Nextcloud apps directory:
   ```bash
   unzip teamhub-x.y.z.zip -d /path/to/nextcloud/apps/
   ```
3. Set ownership:
   ```bash
   chown -R www-data:www-data /path/to/nextcloud/apps/teamhub
   ```
4. Enable:
   ```bash
   sudo -u www-data php occ app:enable teamhub
   ```

Database tables are created automatically on first enable.

## From source

```bash
cd /path/to/nextcloud/apps
git clone https://github.com/jdoek/teamhub.git teamhub
cd teamhub
npm install
npm run build
sudo -u www-data php occ app:enable teamhub
```

## After upgrading

```bash
sudo -u www-data php occ upgrade
```

Run this after every TeamHub update. It applies database migrations automatically.

---

## NC Admin Settings — TeamHub

Open **NC Admin Settings → TeamHub** to configure the app.

### Team creation tab
- **Wizard description** — text shown at the top of the Create Team dialog. Leave empty for no intro text.
- **Creation permissions** — restrict who can create teams to specific NC groups. **Leave empty and every user on the instance can create teams** — empty means unrestricted, not admin-only.
- **Group Folders integration** — read-only readiness check: whether the Group Folders app is installed and whether a team-creator group is configured.

### Invitations tab
- **Allowed invite types** — which invitation methods team admins can use: user, group, circle, email, federated.
- Manage pending team invitations across the instance.

> Message permissions (who can post, comment, add links, and the minimum level to pin) are **per-team**, not instance-wide. They live in *Manage team → Integration settings → Messages*.

### Integrations tab

#### Presence module
Toggle the Presence module on or off for the whole instance. **Default: off.**

When **enabled**:
- A *Presence module* tab appears in admin settings with sub-settings for status types, locations, and holidays.
- Team admins can activate a Presence tab for their team (Manage Team → Settings).
- Members can set their weekly presence schedule (NC Settings → Personal → TeamHub).

When **disabled**:
- The Presence tab is hidden in the admin settings.
- No presence UI appears for team admins or members.
- Existing presence data is preserved in the database; re-enabling restores it.

#### Presence module tab (visible when module is enabled)

**Status types** — create and edit presence types (Office, Home, Vacation, etc.). Each type has a colour, icon, and a busy/free flag. The `holiday` type is built-in and cannot be deleted. Built-in types can be reordered and their colours adjusted.

**Locations** — define a building → floor → room hierarchy. Users can select a room when setting an Office or other location-based status.

**Holidays** — define admin-locked dates. On a holiday date, member slots are overridden to the `holiday` status and cannot be changed by users. Preview how many user entries will be affected before confirming.

#### IntraVox integration
Set the parent path inside IntraVox where team pages are created (e.g. `en/teamhub`).

### Statistics, Maintenance, Audit, Archive tabs
Administrative tools for monitoring usage, repairing configuration, reviewing member changes, and archiving teams.

---

## Optional integrations

TeamHub auto-detects the following apps. Enable them in Nextcloud for the corresponding features:

| App | Feature |
|-----|---------|
| Talk (`spreed`) | Chat tab, Talk room per team |
| Calendar | Calendar tab, upcoming events widget |
| Deck | Deck tab, open tasks widget |
| IntraVox | Pages widget |

---

## Background jobs

TeamHub registers the following background jobs (run by the NC cron):

| Job | Frequency | Purpose |
|-----|-----------|---------|
| `PresenceMaterialisationJob` | Nightly | Expand presence week templates into concrete slots for the rolling window (today → end of next year) |
| `ResourceDiscoveryJob` | Periodic | Detect externally shared resources needing team admin attention |
| `AuditMirrorJob` | Periodic | Sync member changes to the audit log |
| `PendingDeletionJob` | Periodic | Clean up soft-deleted resources |
| `TalkMembershipReconcileJob` | Periodic | Reconcile Talk room membership with team membership |
| `MilestoneAutoPostJob` | Periodic | Post milestone updates to the team message stream |
| `LicenseExpiryNotificationJob` | Daily | Notify admins 14 days (paid) / 7 days (trial) before licence expiry, and on seat overage |
| `TelemetryReportJob` | Daily | Send the anonymous usage report — **only on instances with no active licence or trial**. See [Telemetry](#telemetry) below. |

Presence calendar sync (`PresenceCalendarSyncJob`) is a one-shot queued job added on demand — it does not appear in the persistent job list.

Ensure your NC cron is configured: `sudo -u www-data php /var/www/html/nextcloud/cron.php` via system crontab or systemd timer every 5 minutes.

---

## Telemetry

Whether TeamHub reports anonymous usage statistics is **derived from your licence state**, not from a setting. There is no on/off switch — installing a licence is what turns reporting off.

| Instance state | Reports |
|---|---|
| No licence installed (community / free tier) | Yes |
| Malformed or unreadable licence key | Yes — treated as unlicensed |
| Active licence **or trial** | **Never** |
| Licence expired, within the 30-day grace window | **Never** |
| Licence expired beyond the grace window | Yes |

When an instance does report, `TelemetryReportJob` sends one aggregate payload per day to `https://tldr.host/teamhub/report/`:

- TeamHub and Nextcloud versions
- An anonymous instance UUID, generated locally and never linked to an account
- Team, user, member and message counts
- Which built-in integrations are in use, and which third-party integrations are registered
- Whether the Presence and Decisions modules are enabled, plus aggregate Decisions counts by status
- Bare hostnames of custom team links — no paths, query strings, ports or fragments

No user identifiers, no message content, no file names, and no instance URL or hostname are ever sent. You can see the exact field list on **NC Admin Settings → TeamHub → Reporting**.

### Blocking it entirely

The report is a single outbound HTTPS POST once every 24 hours. Blocking `tldr.host` at the firewall, or running the instance air-gapped, causes the request to fail and be logged at warning level — nothing in TeamHub degrades. Licensing itself is fully manual and never contacts a licence server.
