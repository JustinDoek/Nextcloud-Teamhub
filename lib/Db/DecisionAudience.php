<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_decision_audience — who may see a `selected` proposal
 * while it is open (v4.5.42).
 *
 * One row per person per decision. Rows exist only for decisions whose
 * `share_mode` is `selected`; an `immediate` or `team` proposal has no
 * audience because neither restricts visibility.
 *
 * **The rows are not deleted when the proposal is finalized.** Once finalized,
 * a decision is a team record and every member can see it — the audience stops
 * being consulted rather than being cleared. Keeping it preserves who was
 * actually invited to the discussion, which is the only record of that.
 *
 * @method int    getId()
 * @method void   setId(int $id)
 * @method int    getDecisionId()
 * @method void   setDecisionId(int $decisionId)
 * @method string getUserId()
 * @method void   setUserId(string $userId)
 * @method int    getCreatedAt()
 * @method void   setCreatedAt(int $createdAt)
 */
class DecisionAudience extends Entity {

    protected int    $decisionId = 0;
    protected string $userId     = '';
    protected int    $createdAt  = 0;

    public function __construct() {
        $this->addType('decisionId', 'integer');
        $this->addType('createdAt',  'integer');
    }
}
