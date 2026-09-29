<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One row of `teamhub_wf_instance` (WorkflowHub phase 1, v4.10.13).
 *
 * A running (or finished) workflow, created from one version of a
 * definition. `status` is one of `OCA\TeamHub\Workflow\WorkflowStatus`
 * and is written only by `WorkflowEngine`. `currentStep` is the key of
 * the active step while the workflow is open, null afterwards. `endedAt`
 * / `endedBy` are set for every terminal status — completed, rejected or
 * cancelled — with `outcome` saying which way it ended in the definition's
 * own words. `retentionUntil` is 0 (keep) in this phase; the maintenance
 * pass of the next phase sets it per tier.
 *
 * @method string  getDefinitionKey()
 * @method void    setDefinitionKey(string $v)
 * @method int     getDefinitionVersion()
 * @method void    setDefinitionVersion(int $v)
 * @method string  getTeamId()
 * @method void    setTeamId(string $v)
 * @method string  getSubjectType()
 * @method void    setSubjectType(string $v)
 * @method string  getSubjectId()
 * @method void    setSubjectId(string $v)
 * @method string  getStatus()
 * @method void    setStatus(string $v)
 * @method ?string getOutcome()
 * @method void    setOutcome(?string $v)
 * @method ?string getCurrentStep()
 * @method void    setCurrentStep(?string $v)
 * @method string  getStartedBy()
 * @method void    setStartedBy(string $v)
 * @method int     getStartedAt()
 * @method void    setStartedAt(int $v)
 * @method int     getUpdatedAt()
 * @method void    setUpdatedAt(int $v)
 * @method ?string getEndedBy()
 * @method void    setEndedBy(?string $v)
 * @method ?int    getEndedAt()
 * @method void    setEndedAt(?int $v)
 * @method int     getRetentionUntil()
 * @method void    setRetentionUntil(int $v)
 * @method ?string getDataJson()
 * @method void    setDataJson(?string $v)
 */
class WorkflowInstance extends Entity {

    protected string  $definitionKey     = '';
    protected int     $definitionVersion = 1;
    protected string  $teamId            = '';
    protected string  $subjectType       = '';
    protected string  $subjectId         = '';
    protected string  $status            = '';
    protected ?string $outcome           = null;
    protected ?string $currentStep       = null;
    protected string  $startedBy         = '';
    protected int     $startedAt         = 0;
    protected int     $updatedAt         = 0;
    protected ?string $endedBy           = null;
    protected ?int    $endedAt           = null;
    protected int     $retentionUntil    = 0;
    protected ?string $dataJson          = null;

    public function __construct() {
        $this->addType('definitionVersion', 'integer');
        $this->addType('startedAt',         'integer');
        $this->addType('updatedAt',         'integer');
        $this->addType('endedAt',           'integer');
        $this->addType('retentionUntil',    'integer');
    }

    /** @return array<string, mixed> */
    public function getData(): array {
        $raw = $this->getDataJson();
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $data */
    public function setData(array $data): void {
        $this->setDataJson($data === [] ? null : (json_encode($data, JSON_UNESCAPED_UNICODE) ?: null));
    }
}
