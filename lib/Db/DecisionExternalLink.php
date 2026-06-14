<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_dec_ext_links — an outbound URL attached to a decision.
 *
 * One-way only: the other end is by definition outside TeamHub. Use cases:
 *   - This decision needs to be tracked in organisation-management tooling
 *   - An earlier decision in another system led to this decision
 *
 * @method int    getId()
 * @method void   setId(int $id)
 * @method string getTeamId()
 * @method void   setTeamId(string $teamId)
 * @method int    getDecisionId()
 * @method void   setDecisionId(int $decisionId)
 * @method string getUrl()
 * @method void   setUrl(string $url)
 * @method string getLabel()
 * @method void   setLabel(string $label)
 * @method string getCreatedBy()
 * @method void   setCreatedBy(string $createdBy)
 * @method int    getCreatedAt()
 * @method void   setCreatedAt(int $createdAt)
 */
class DecisionExternalLink extends Entity {

    protected string $teamId     = '';
    protected int    $decisionId = 0;
    protected string $url        = '';
    protected string $label      = '';
    protected string $createdBy  = '';
    protected int    $createdAt  = 0;

    public function __construct() {
        $this->addType('decisionId', 'integer');
        $this->addType('createdAt',  'integer');
    }
}
