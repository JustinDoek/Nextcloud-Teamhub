<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for `teamhub_wf_event` (WorkflowHub phase 1, v4.10.13). Append
 * only: there is no update path and the one delete goes with the instance.
 *
 * @extends QBMapper<WorkflowEvent>
 */
class WorkflowEventMapper extends QBMapper {

    public const TABLE = 'teamhub_wf_event';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE, WorkflowEvent::class);
    }

    /** @return WorkflowEvent[] oldest first */
    public function findByInstance(int $instanceId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('instance_id', $qb->createNamedParameter($instanceId, IQueryBuilder::PARAM_INT)))
            ->orderBy('occurred_at', 'ASC')
            ->addOrderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * One person's own events of one type on many instances, since a point
     * in time — one query for a whole list (v4.10.17).
     *
     * Added for the status-request cooldown: the view has to say whether
     * the viewer may ask for an update *now*, and asking per row would be
     * a query per row. Returns the latest `occurred_at` per instance and
     * step, which is all a cooldown needs.
     *
     * Served by `th_wfe_type_idx` (`instance_id`, `event_type`).
     *
     * @param int[] $instanceIds
     * @return array<int, array<string, int>> instanceId → stepKey → occurredAt
     */
    public function findOwnEventsSince(array $instanceIds, string $actorUid, string $eventType, int $since): array {
        $instanceIds = array_values(array_unique(array_map('intval', $instanceIds)));
        if ($instanceIds === [] || $actorUid === '') {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('instance_id', 'step_key', 'occurred_at')
            ->from($this->getTableName())
            ->where($qb->expr()->in('instance_id', $qb->createNamedParameter($instanceIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('actor_uid', $qb->createNamedParameter($actorUid)))
            ->andWhere($qb->expr()->eq('event_type', $qb->createNamedParameter($eventType)))
            ->andWhere($qb->expr()->gt('occurred_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)));

        $out    = [];
        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $id   = (int)$row['instance_id'];
            // A null step_key (an instance-level event) keys as the empty
            // string, the same way the engine compares it.
            $key  = (string)($row['step_key'] ?? '');
            $at   = (int)$row['occurred_at'];
            if (!isset($out[$id][$key]) || $out[$id][$key] < $at) {
                $out[$id][$key] = $at;
            }
        }
        $result->closeCursor();
        return $out;
    }

    /**
     * The earliest moment each step saw one of the given event types
     * (v4.10.27) — "when was this request first claimed", for the service
     * team's statistics. The step row cannot answer it: a release clears
     * `started_at`, so a request claimed, released and claimed again would
     * otherwise report its second claim.
     *
     * Served by `th_wfe_type_idx` (`instance_id`, `event_type`).
     *
     * @param int[]    $instanceIds
     * @param string[] $eventTypes
     * @return array<int, array<string, int>> instanceId → stepKey → earliest occurredAt
     */
    public function findFirstEventsOfTypes(array $instanceIds, array $eventTypes): array {
        $instanceIds = array_values(array_unique(array_map('intval', $instanceIds)));
        if ($instanceIds === [] || $eventTypes === []) {
            return [];
        }
        $out = [];
        // Chunked: an IN list is capped at 1000 entries on Oracle and a busy
        // desk's 90-day window can exceed that.
        foreach (array_chunk($instanceIds, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('instance_id', 'step_key', 'occurred_at')
                ->from($this->getTableName())
                ->where($qb->expr()->in('instance_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($qb->expr()->in('event_type', $qb->createNamedParameter(array_values($eventTypes), IQueryBuilder::PARAM_STR_ARRAY)));
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $id  = (int)$row['instance_id'];
                $key = (string)($row['step_key'] ?? '');
                $at  = (int)$row['occurred_at'];
                if (!isset($out[$id][$key]) || $out[$id][$key] > $at) {
                    $out[$id][$key] = $at;
                }
            }
            $result->closeCursor();
        }
        return $out;
    }

    public function deleteByInstance(int $instanceId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('instance_id', $qb->createNamedParameter($instanceId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }
}
