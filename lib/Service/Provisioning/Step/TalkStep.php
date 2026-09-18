<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Provisioning\Step;

use OCA\TeamHub\Service\Provisioning\ProvisioningContext;

/**
 * Step — the team's Talk conversation, when the blueprint creates one and
 * the workspace has Talk. See {@see AbstractResourceStep}.
 */
class TalkStep extends AbstractResourceStep {

    public const KEY = 'talk';

    public function key(): string { return self::KEY; }
    public function resourceType(): ?string { return 'conversation'; }
    protected function appId(): string { return 'talk'; }
    protected function ledgerType(): string { return 'conversation'; }

    public function applies(ProvisioningContext $ctx): bool {
        return $ctx->hasApp('talk') && $ctx->blueprint->talkBehavior() === 'create';
    }
}
