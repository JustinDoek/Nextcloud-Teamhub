<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_compliment (v4.13.2).
 *
 * Three queries, and that is the whole table's job: what has this person not
 * been shown, mark those as shown, and how many has this sender already paid
 * this person today (the anti-spam cap).
 */
class ComplimentMapper extends QBMapper {

    /**
     * Hard ceiling on one read. A popup cannot usefully show more, and it
     * bounds the query whatever the instance does.
     */
    public const MAX_PER_READ = 50;

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_compliment', Compliment::class);
    }

    /**
     * Compliments this person has never been shown, oldest first — the order
     * the popup lists them in, so the first note read is the first one sent.
     *
     * @return Compliment[]
     */
    public function findUnseenFor(string $recipientUid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('recipient_uid', $qb->createNamedParameter($recipientUid)))
            ->andWhere($qb->expr()->isNull('seen_at'))
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC')   // tiebreaker for same-second rows
            ->setMaxResults(self::MAX_PER_READ);

        return $this->findEntities($qb);
    }

    /**
     * Mark the given rows as shown. Scoped to the recipient as well as the
     * ids, so a caller cannot mark somebody else's compliments seen by
     * guessing ids.
     *
     * @param int[] $ids
     *
     * @return int rows affected
     */
    public function markSeen(string $recipientUid, array $ids, int $seenAt): int {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));
        if ($ids === []) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('seen_at', $qb->createNamedParameter($seenAt, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('recipient_uid', $qb->createNamedParameter($recipientUid)))
            ->andWhere($qb->expr()->isNull('seen_at'))
            ->andWhere($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

        return $qb->executeStatement();
    }

    /**
     * How many compliments this sender has paid this recipient since the
     * given moment. Feeds the per-pair daily cap.
     */
    public function countFromSenderSince(string $senderUid, string $recipientUid, int $since): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('sender_uid', $qb->createNamedParameter($senderUid)))
            ->andWhere($qb->expr()->eq('recipient_uid', $qb->createNamedParameter($recipientUid)))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return (int)($row['cnt'] ?? 0);
    }
}
