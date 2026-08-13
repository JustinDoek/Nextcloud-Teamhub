<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCA\TeamHub\AppInfo\Application;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Log\LoggerInterface;

/**
 * v4.6.20 — revoke the public share on every team calendar TeamHub published.
 *
 * Until this version `CalendarService` gave each team calendar a second
 * `dav_shares` row with `access = 4` — NC's "publish calendar" — and a random
 * 32-hex token. It existed for one reason: the Calendar tab was an iframe, and
 * `/apps/calendar/p/{token}` was the only route in NC Calendar that shows a
 * *single* calendar. Its authenticated routes take a view and a date and offer
 * no way to say which calendar.
 *
 * The cost was that every team's agenda was readable by anyone holding the
 * token, with no login: the page answered unauthenticated and so did
 * `remote.php/dav/public-calendars/{token}/?export`, which returns the whole
 * calendar as ICS. The token was delivered to every member's browser in the
 * layout bundle, never expired, and had no revocation path in the UI.
 *
 * v4.6.20 renders the tab from TeamHub's own membership-gated endpoint, so
 * nothing reads the token any more. Removing the creation only helps new
 * teams — every calendar provisioned before this version is still published
 * until the row is deleted, which is what this migration does.
 *
 * **Scope is the narrow part, and it is deliberately narrow.** A publish row
 * created by TeamHub and one a user created themselves in the Calendar app are
 * identical in `dav_shares`: same `access`, same shape of `publicuri`, same
 * `principaluri`. Deleting by `access = 4` alone would silently unpublish
 * calendars people had chosen to share, on every instance this upgrade
 * touches, with no way to put them back — the token is not recoverable once
 * the row is gone.
 *
 * The discriminator is TeamHub's own resource registry: `origin` is
 * `teamhub_create` exactly for calendars this app provisioned. A calendar the
 * team merely *connected* (`connectExistingCalendar`) belongs to the user who
 * already had it, so its publish state is theirs to decide and it is left
 * alone — even though that path also used to add a token of its own. Leaving a
 * few of those published is the safer error: they can be revoked by hand,
 * whereas a wrongly deleted share cannot be restored.
 */
class Version000406020Date20260810000000 extends SimpleMigrationStep {

    /** NC's `access` value for a public (published) share. */
    private const ACCESS_PUBLIC = 4;

    public function __construct(
        private IDBConnection   $db,
        private LoggerInterface $logger,
    ) {}

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        // Data migration only.
        return null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $output->info('[TeamHub 4.6.20] Revoking public share links on TeamHub-created team calendars…');

        // Resource ids are stored as a varchar in the registry because the
        // column is shared with apps whose ids are not numeric, so they are
        // read out and cast here rather than joined on in SQL — a cross-type
        // join would behave differently on MySQL and Postgres.
        $calendarIds = [];
        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('resource_id')
                ->from('teamhub_team_app_resources')
                ->where($qb->expr()->eq('app_id', $qb->createNamedParameter('calendar')))
                ->andWhere($qb->expr()->eq('origin', $qb->createNamedParameter('teamhub_create')))
                ->executeQuery();
            while ($row = $res->fetch()) {
                $id = (int)$row['resource_id'];
                if ($id > 0) {
                    $calendarIds[] = $id;
                }
            }
            $res->closeCursor();
        } catch (\Throwable $e) {
            // An upgrade must not fail because the cleanup could not run. The
            // app is fully functional with the rows still present — they are
            // simply not revoked yet — so this reports and returns rather than
            // aborting the upgrade half-applied.
            $output->warning('[TeamHub 4.6.20] Could not read the resource registry; public shares were NOT revoked: ' . $e->getMessage());
            $this->logger->error('[TeamHub][Migration 4.6.20] Registry read failed', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return;
        }

        if ($calendarIds === []) {
            $output->info('[TeamHub 4.6.20] No TeamHub-created calendars found — nothing to revoke.');
            return;
        }

        $revoked = 0;
        $failed  = 0;
        foreach ($calendarIds as $calendarId) {
            try {
                $del = $this->db->getQueryBuilder();
                $revoked += $del->delete('dav_shares')
                    ->where($del->expr()->eq('resourceid', $del->createNamedParameter($calendarId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->andWhere($del->expr()->eq('type', $del->createNamedParameter('calendar')))
                    ->andWhere($del->expr()->eq('access', $del->createNamedParameter(self::ACCESS_PUBLIC, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->executeStatement();
            } catch (\Throwable $e) {
                // Per-calendar, so one failure does not strand the rest still
                // published.
                $failed++;
                $this->logger->warning('[TeamHub][Migration 4.6.20] Could not revoke public share', [
                    'calendarId' => $calendarId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }

        $output->info(sprintf(
            '[TeamHub 4.6.20] Revoked %d public calendar share(s) across %d TeamHub calendar(s).',
            $revoked,
            count($calendarIds),
        ));
        if ($failed > 0) {
            $output->warning(sprintf(
                '[TeamHub 4.6.20] %d calendar(s) could not be revoked — see the log. Those calendars are still publicly readable.',
                $failed,
            ));
        }

        $this->logger->info('[TeamHub][Migration 4.6.20] Public calendar shares revoked', [
            'calendars' => count($calendarIds),
            'revoked'   => $revoked,
            'failed'    => $failed,
            'app'       => Application::APP_ID,
        ]);
    }
}
