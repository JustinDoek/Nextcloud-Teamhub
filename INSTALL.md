# Installation

## Requirements

- Nextcloud 32 or later
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

## After upgrading

```bash
sudo -u www-data php occ upgrade
```

## Enabling optional integrations

TeamHub auto-detects the following apps. Enable them in Nextcloud for the corresponding features to appear:

| App | Feature |
|-----|---------|
| Talk (`spreed`) | Chat tab, Talk token in activity feed |
| Calendar | Calendar tab, upcoming events widget |
| Deck | Deck tab, open tasks widget |
| Files | Files tab, favorite files and recently modified files widgets |
| IntraVox | Pages widget |
