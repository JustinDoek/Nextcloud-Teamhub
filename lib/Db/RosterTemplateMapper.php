<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_roster_template — a handful of rows per team, read by the
 * editor in Module settings and by the Roster tab's picker.
 */
class RosterTemplateMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_roster_template', RosterTemplate::class);
    }

    /**
     * Every template this team owns, active or not.
     *
     * Inactive ones come back too: a week may have been laid out from one and
     * the editor has to be able to switch it back on. The *picker* filters
     * them out, which is a different question asked in a different place.
     *
     * @return RosterTemplate[]
     */
    public function findByTeam(string $teamId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('name', 'ASC');

        /** @var RosterTemplate[] */
        return $this->findEntities($qb);
    }

    /**
     * One template, and only if it belongs to this team.
     *
     * The team id is part of the lookup rather than checked afterwards, so a
     * caller cannot accidentally reach another team's template by id.
     */
    public function findByIdForTeam(int $id, string $teamId): ?RosterTemplate {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1);

        try {
            /** @var RosterTemplate */
            return $this->findEntity($qb);
        } catch (\Throwable) {
            return null;
        }
    }

    /** The template of this name in this team, for the clash check. */
    public function findByName(string $teamId, string $name): ?RosterTemplate {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name)))
            ->setMaxResults(1);

        try {
            /** @var RosterTemplate */
            return $this->findEntity($qb);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Forget every template a deleted team owned.
     *
     * The rows it points at are the caller's to delete first; this is only the
     * header table.
     *
     * @return list<int> the ids that were removed, so their rows can go too
     */
    public function deleteByTeam(string $teamId): array {
        $ids = array_map(
            static fn (RosterTemplate $t): int => $t->getId(),
            $this->findByTeam($teamId),
        );
        if ($ids === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
        $qb->executeStatement();

        return $ids;
    }
}
