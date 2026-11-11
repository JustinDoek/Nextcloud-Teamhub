<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Mapper for teamhub_roster_tpl_row — the lines of a team's week template. */
class RosterTemplateRowMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_roster_tpl_row', RosterTemplateRow::class);
    }

    /**
     * The whole week, Monday first, roles in alphabetical order inside a day.
     *
     * @return RosterTemplateRow[]
     */
    public function findByTemplate(int $templateId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('template_id', $qb->createNamedParameter($templateId, IQueryBuilder::PARAM_INT)))
            ->orderBy('day_of_week', 'ASC')
            ->addOrderBy('role', 'ASC')
            ->addOrderBy('shift_type_id', 'ASC');

        /** @var RosterTemplateRow[] */
        return $this->findEntities($qb);
    }

    /**
     * Lines for several templates at once, keyed by template id.
     *
     * The editor lists every template with its week; one query rather than one
     * per template.
     *
     * @param list<int> $templateIds
     * @return array<int, RosterTemplateRow[]>
     */
    public function findByTemplateIds(array $templateIds): array {
        if ($templateIds === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in(
                'template_id',
                $qb->createNamedParameter($templateIds, IQueryBuilder::PARAM_INT_ARRAY),
            ))
            ->orderBy('day_of_week', 'ASC')
            ->addOrderBy('role', 'ASC')
            ->addOrderBy('shift_type_id', 'ASC');

        $out = [];
        /** @var RosterTemplateRow $row */
        foreach ($this->findEntities($qb) as $row) {
            $out[$row->getTemplateId()][] = $row;
        }
        return $out;
    }

    /**
     * Whether any team's template still has a line on this shift type.
     *
     * The guard behind *a shift type in use cannot be deleted* (v4.14.4).
     * Until that release the question was asked of the instance-wide
     * schedules, which no longer exist; a team's template is what actually
     * depends on the vocabulary now, and a type deleted out from under one
     * would cost that template a line with nothing on screen to say why.
     *
     * Counted in the database rather than by reading every template: this runs
     * on one administrator click and the answer is a yes or a no.
     */
    public function usesShiftType(int $shiftTypeId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq(
                'shift_type_id',
                $qb->createNamedParameter($shiftTypeId, IQueryBuilder::PARAM_INT),
            ));

        $result = $qb->executeQuery();
        $count  = (int)$result->fetchOne();
        $result->closeCursor();

        return $count > 0;
    }

    /** Replace a week wholesale. The caller owns the transaction. */
    public function deleteByTemplate(int $templateId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('template_id', $qb->createNamedParameter($templateId, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement();
    }

    /**
     * Drop the lines of several templates at once, for a deleted team.
     *
     * @param list<int> $templateIds
     */
    public function deleteByTemplates(array $templateIds): int {
        if ($templateIds === []) {
            return 0;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->in(
                'template_id',
                $qb->createNamedParameter($templateIds, IQueryBuilder::PARAM_INT_ARRAY),
            ));

        return $qb->executeStatement();
    }
}
