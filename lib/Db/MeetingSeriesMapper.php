<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * teamhub_mtg_series (v4.20.10, DESIGN §2.264): recurring team meetings that
 * get a notes file and a chat reminder before each date. Plain arrays, no
 * entity: the job reads a handful of columns and writes one.
 */
class MeetingSeriesMapper {

    private const TABLE = 'teamhub_mtg_series';

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    /**
     * @param list<string> $attendees
     */
    public function register(string $teamId, string $eventUid, string $organiser, int $leadDays, array $attendees, int $preparedUntil): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)
            ->values([
                'team_id'        => $qb->createNamedParameter($teamId),
                'event_uid'      => $qb->createNamedParameter(mb_substr($eventUid, 0, 255)),
                'organiser'      => $qb->createNamedParameter($organiser),
                'lead_days'      => $qb->createNamedParameter(max(1, min(7, $leadDays)), IQueryBuilder::PARAM_INT),
                'attendees'      => $qb->createNamedParameter(implode(',', $attendees)),
                'prepared_until' => $qb->createNamedParameter($preparedUntil, IQueryBuilder::PARAM_INT),
                'created_at'     => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            ])
            ->executeStatement();
    }

    /**
     * @return list<array{id: int, team_id: string, event_uid: string, organiser: string, lead_days: int, attendees: list<string>, prepared_until: int, created_at: int}>
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'team_id', 'event_uid', 'organiser', 'lead_days', 'attendees', 'prepared_until', 'created_at')
            ->from(self::TABLE)
            ->orderBy('id', 'ASC');
        $result = $qb->executeQuery();
        $out = [];
        while ($row = $result->fetch()) {
            $out[] = [
                'id'             => (int)$row['id'],
                'team_id'        => (string)$row['team_id'],
                'event_uid'      => (string)$row['event_uid'],
                'organiser'      => (string)$row['organiser'],
                'lead_days'      => (int)$row['lead_days'],
                'attendees'      => array_values(array_filter(explode(',', (string)($row['attendees'] ?? '')))),
                'prepared_until' => (int)$row['prepared_until'],
                'created_at'     => (int)$row['created_at'],
            ];
        }
        $result->closeCursor();
        return $out;
    }

    public function setPreparedUntil(int $id, int $preparedUntil): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('prepared_until', $qb->createNamedParameter($preparedUntil, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    public function delete(int $id): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    /** Every series of a deleted team. */
    public function deleteByTeam(string $teamId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->executeStatement();
    }
}
