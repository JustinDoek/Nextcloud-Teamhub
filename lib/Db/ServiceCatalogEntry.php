<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One row of `teamhub_service_catalog` (WorkflowHub phase 5, v4.10.20).
 *
 * One service a service team offers, and the built-in workflow definition a
 * request for it opens. The service key is the application's vocabulary
 * (`OCA\TeamHub\Constants\ServiceCatalogue`); the definition key is what the
 * engine will run. Both are stored so a catalogue read needs neither the
 * registry nor the constants to answer.
 *
 * @method string getTeamId()
 * @method void   setTeamId(string $v)
 * @method string getServiceKey()
 * @method void   setServiceKey(string $v)
 * @method string getDefinitionKey()
 * @method void   setDefinitionKey(string $v)
 * @method int    getEnabled()
 * @method void   setEnabled(int $v)
 * @method int    getSortOrder()
 * @method void   setSortOrder(int $v)
 * @method int    getUpdatedAt()
 * @method void   setUpdatedAt(int $v)
 */
class ServiceCatalogEntry extends Entity {

    protected string $teamId        = '';
    protected string $serviceKey    = '';
    protected string $definitionKey = '';
    protected int    $enabled       = 1;
    protected int    $sortOrder     = 1;
    protected int    $updatedAt     = 0;

    public function __construct() {
        $this->addType('enabled',   'integer');
        $this->addType('sortOrder', 'integer');
        $this->addType('updatedAt', 'integer');
    }

    public function isEnabled(): bool {
        return $this->getEnabled() === 1;
    }
}
