<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_dec_audit — one row per lifecycle transition on a
 * decision (Session J). See migration for the transition vocabulary and
 * payload schema by transition.
 *
 * @method int     getId()
 * @method void    setId(int $id)
 * @method string  getTeamId()
 * @method void    setTeamId(string $teamId)
 * @method int     getDecisionId()
 * @method void    setDecisionId(int $decisionId)
 * @method string  getTransition()
 * @method void    setTransition(string $transition)
 * @method string  getActor()
 * @method void    setActor(string $actor)
 * @method ?string getPayloadJson()
 * @method void    setPayloadJson(?string $payloadJson)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 */
class DecisionAudit extends Entity {

    protected string  $teamId       = '';
    protected int     $decisionId   = 0;
    protected string  $transition   = '';
    protected string  $actor        = '';
    protected ?string $payloadJson  = null;
    protected int     $createdAt    = 0;

    public function __construct() {
        $this->addType('decisionId', 'integer');
        $this->addType('createdAt',  'integer');
    }
}
