<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_dec_links — bidirectional decision ↔ decision link.
 *
 * Canonical invariant: decisionIdA < decisionIdB always.
 * The service enforces this before insert; the unique index on the pair
 * then covers both orderings.
 *
 * @method int    getId()
 * @method void   setId(int $id)
 * @method string getTeamId()
 * @method void   setTeamId(string $teamId)
 * @method int    getDecisionIdA()
 * @method void   setDecisionIdA(int $decisionIdA)
 * @method int    getDecisionIdB()
 * @method void   setDecisionIdB(int $decisionIdB)
 * @method string getCreatedBy()
 * @method void   setCreatedBy(string $createdBy)
 * @method int    getCreatedAt()
 * @method void   setCreatedAt(int $createdAt)
 */
class DecisionLink extends Entity {

    protected string $teamId      = '';
    protected int    $decisionIdA = 0;
    protected int    $decisionIdB = 0;
    protected string $createdBy   = '';
    protected int    $createdAt   = 0;

    public function __construct() {
        $this->addType('decisionIdA', 'integer');
        $this->addType('decisionIdB', 'integer');
        $this->addType('createdAt',   'integer');
    }
}
