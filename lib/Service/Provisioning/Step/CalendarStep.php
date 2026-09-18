<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Provisioning\Step;

use OCA\TeamHub\Service\Provisioning\ProvisioningContext;

/**
 * Step — the team's calendar, when the blueprint creates one and the
 * workspace has Calendar. See {@see AbstractResourceStep}.
 */
class CalendarStep extends AbstractResourceStep {

    public const KEY = 'calendar';

    public function key(): string { return self::KEY; }
    public function resourceType(): ?string { return 'calendar'; }
    protected function appId(): string { return 'calendar'; }
    protected function ledgerType(): string { return 'calendar'; }

    public function applies(ProvisioningContext $ctx): bool {
        return $ctx->hasApp('calendar') && $ctx->blueprint->calendarBehavior() === 'create';
    }
}
