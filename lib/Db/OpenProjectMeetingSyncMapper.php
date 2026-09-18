<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The meeting-sync ledger, `teamhub_op_meeting_sync` (v4.9.10, Phase 3).
 *
 * One row per (team, OpenProject connection, meeting) that has been copied
 * into the team calendar, with where the copy lives and a fingerprint of
 * what was written. Plain arrays rather than an entity, like the news
 * ledger: a few reads and writes, none of which needs a model.
 *
 * Also the one place that answers "which calendar is the team's, and may
 * the team write to it" — a read of Nextcloud's own `dav_shares` and
 * `calendars` tables, the same lookup `ActivityService` and
 * `ArchiveService` make, kept here so the sync service owns no query.
 *
 * Every read is narrowed by a team id the caller has already been
 * authorised for; there is no "all rows" query.
 */
class OpenProjectMeetingSyncMapper {

    public const TABLE = 'teamhub_op_meeting_sync';

    /** `dav_shares.access` for a share the sharee may write to (`OCA\DAV\DAV\Sharing\Backend::ACCESS_READ_WRITE`). */
    private const ACCESS_READ_WRITE = 2;

    public function __construct(private IDBConnection $db) {
    }

    /**
     * The team calendar the sync may write to: the first calendar shared
     * with the team's circle **read-write**. Null when the team has no
     * calendar, or only a read-only one — a calendar shared to read is
     * not the team's to write in, whoever asks.
     *
     * @return array{calendarId: int, principalUri: string}|null
     */
    public function writableTeamCalendar(string $teamId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('s.resourceid', 'c.principaluri')
            ->from('dav_shares', 's')
            ->innerJoin('s', 'calendars', 'c', $qb->expr()->eq('s.resourceid', 'c.id'))
            ->where($qb->expr()->eq('s.type', $qb->createNamedParameter('calendar')))
            ->andWhere($qb->expr()->eq('s.principaluri', $qb->createNamedParameter('principals/circles/' . $teamId)))
            ->andWhere($qb->expr()->eq('s.access', $qb->createNamedParameter(self::ACCESS_READ_WRITE, IQueryBuilder::PARAM_INT)))
            ->orderBy('s.resourceid', 'ASC')
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();
        if (!$row) {
            return null;
        }
        return ['calendarId' => (int)$row['resourceid'], 'principalUri' => (string)$row['principaluri']];
    }

    /** Does the team have any calendar at all, writable or not. */
    public function anyTeamCalendar(string $teamId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('resourceid')
            ->from('dav_shares')
            ->where($qb->expr()->eq('type', $qb->createNamedParameter('calendar')))
            ->andWhere($qb->expr()->eq('principaluri', $qb->createNamedParameter('principals/circles/' . $teamId)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();
        return (bool)$row;
    }

    /**
     * Every ledger row of one team and connection, keyed by meeting id.
     *
     * @return array<int, array{id: int, meetingId: int, calendarId: int, objectUri: string, fingerprint: string, startsAt: int, removedAt: ?int}>
     */
    public function findByTeam(string $teamId, string $connection): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'meeting_id', 'calendar_id', 'object_uri', 'fingerprint', 'starts_at', 'removed_at')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('connection', $qb->createNamedParameter($connection)));
        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[(int)$row['meeting_id']] = [
                'id'          => (int)$row['id'],
                'meetingId'   => (int)$row['meeting_id'],
                'calendarId'  => (int)$row['calendar_id'],
                'objectUri'   => (string)$row['object_uri'],
                'fingerprint' => (string)$row['fingerprint'],
                'startsAt'    => (int)$row['starts_at'],
                'removedAt'   => $row['removed_at'] !== null ? (int)$row['removed_at'] : null,
            ];
        }
        $result->closeCursor();
        return $out;
    }

    /**
     * Claim the slot for one meeting before the calendar object is written.
     * Returns the ledger row id, or null — without throwing — when the
     * unique index says somebody claimed it first. `calendar_id` is 0 and
     * `object_uri` empty until `attach()`.
     */
    public function claim(string $teamId, string $connection, int $meetingId, string $syncedBy, int $startsAt): ?int {
        $now = time();
        $qb  = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)
            ->values([
                'team_id'     => $qb->createNamedParameter($teamId),
                'connection'  => $qb->createNamedParameter($connection),
                'meeting_id'  => $qb->createNamedParameter($meetingId, IQueryBuilder::PARAM_INT),
                'calendar_id' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                'object_uri'  => $qb->createNamedParameter(''),
                'fingerprint' => $qb->createNamedParameter(''),
                'synced_by'   => $qb->createNamedParameter($syncedBy),
                'starts_at'   => $qb->createNamedParameter($startsAt, IQueryBuilder::PARAM_INT),
                'created_at'  => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                'updated_at'  => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ]);
        try {
            $qb->executeStatement();
        } catch (DBException $e) {
            if ($e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                return null;
            }
            throw $e;
        }
        return (int)$qb->getLastInsertId();
    }

    /** The calendar object is written (or rewritten): remember where and what. */
    public function attach(int $ledgerId, int $calendarId, string $objectUri, string $fingerprint, int $startsAt): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('calendar_id', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT))
            ->set('object_uri', $qb->createNamedParameter($objectUri))
            ->set('fingerprint', $qb->createNamedParameter($fingerprint))
            ->set('starts_at', $qb->createNamedParameter($startsAt, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->set('removed_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($ledgerId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /** The sync took the copy away (cancelled or deleted in OpenProject). */
    public function markRemoved(int $ledgerId): void {
        $now = time();
        $qb  = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('removed_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($ledgerId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /** The calendar object could not be written: give the slot back. */
    public function release(int $ledgerId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($ledgerId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /** Part of the team-delete and unlink cascade. */
    public function deleteByTeam(string $teamId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
        return $qb->executeStatement();
    }
}
