<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * teamhub_mtg_wrapups (v4.20.19, DESIGN §2.271): which meeting occurrences
 * their organiser wrapped up, or marked as needing none. Plain arrays, no
 * entity, like `MeetingSeriesMapper`.
 */
class MeetingWrapUpMapper {

    private const TABLE = 'teamhub_mtg_wrapups';

    public const STATUS_DONE    = 'done';
    public const STATUS_SKIPPED = 'skipped';

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    /**
     * @return array{team_id: string, event_uid: string, occurrence: int, organiser: string, status: string, task_count: int, linked_count: int, created_at: int}|null
     */
    public function find(string $teamId, string $eventUid, int $occurrence): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('team_id', 'event_uid', 'occurrence', 'organiser', 'status', 'task_count', 'linked_count', 'created_at')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('event_uid', $qb->createNamedParameter(mb_substr($eventUid, 0, 255))))
            ->andWhere($qb->expr()->eq('occurrence', $qb->createNamedParameter($occurrence, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row ? self::shape($row) : null;
    }

    /**
     * The wrap-ups one organiser recorded since a moment, newest first.
     *
     * @return list<array{team_id: string, event_uid: string, occurrence: int, organiser: string, status: string, task_count: int, linked_count: int, created_at: int}>
     */
    public function listForOrganiser(string $organiser, int $since): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('team_id', 'event_uid', 'occurrence', 'organiser', 'status', 'task_count', 'linked_count', 'created_at')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('organiser', $qb->createNamedParameter($organiser)))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults(200);
        $result = $qb->executeQuery();
        $out = [];
        while ($row = $result->fetch()) {
            $out[] = self::shape($row);
        }
        $result->closeCursor();
        return $out;
    }

    /**
     * Record a wrap-up. A second one for the same occurrence is refused by
     * the unique index; the service checks `find()` first and says so.
     */
    public function insert(string $teamId, string $eventUid, int $occurrence, string $organiser, string $status, int $taskCount, int $linkedCount): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)
            ->values([
                'team_id'      => $qb->createNamedParameter($teamId),
                'event_uid'    => $qb->createNamedParameter(mb_substr($eventUid, 0, 255)),
                'occurrence'   => $qb->createNamedParameter($occurrence, IQueryBuilder::PARAM_INT),
                'organiser'    => $qb->createNamedParameter($organiser),
                'status'       => $qb->createNamedParameter($status),
                'task_count'   => $qb->createNamedParameter($taskCount, IQueryBuilder::PARAM_INT),
                'linked_count' => $qb->createNamedParameter($linkedCount, IQueryBuilder::PARAM_INT),
                'created_at'   => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            ])
            ->executeStatement();
    }

    /** Every wrap-up of a deleted team. */
    public function deleteByTeam(string $teamId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->executeStatement();
    }

    /**
     * @param array<string,mixed> $row
     * @return array{team_id: string, event_uid: string, occurrence: int, organiser: string, status: string, task_count: int, linked_count: int, created_at: int}
     */
    private static function shape(array $row): array {
        return [
            'team_id'      => (string)$row['team_id'],
            'event_uid'    => (string)$row['event_uid'],
            'occurrence'   => (int)$row['occurrence'],
            'organiser'    => (string)$row['organiser'],
            'status'       => (string)$row['status'],
            'task_count'   => (int)$row['task_count'],
            'linked_count' => (int)$row['linked_count'],
            'created_at'   => (int)$row['created_at'],
        ];
    }
}
