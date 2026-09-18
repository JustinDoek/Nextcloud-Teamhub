<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Provisioning\Step;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\Provisioning\Blueprint;
use OCA\TeamHub\Service\Provisioning\ProvisioningContext;
use OCA\TeamHub\Service\Provisioning\StepResult;
use OCP\IConfig;

/**
 * Step — the team's dashboard, as the blueprint declares it.
 *
 * TeamHub's dashboard is per user (each member arranges their own grid) with
 * one team-wide control: the widgets an admin hides for everyone
 * (`dashboard_hidden_<teamId>`) and the tab the team opens on
 * (`dashboard_tab_<teamId>`). This step sets those two — the same keys
 * Manage team → Settings → Dashboard writes, so the result stays editable
 * there afterwards by the existing rules.
 *
 * What is hidden: the blueprint's `dashboard.hidden`, plus every widget for
 * an application the workspace does not have. What is shown is whatever the
 * blueprint's `dashboard.widgets` names and the team has; a blueprint with
 * an empty list hides nothing beyond the unavailable ones.
 */
class DashboardStep implements StepInterface {

    public const KEY = 'dashboard';

    /** Widget → the app it needs; a widget without an entry needs none. */
    private const WIDGET_NEEDS = [
        'widget-calendar'    => 'calendar',
        'widget-deck'        => null, // Upcoming tasks also lists OpenProject work
        'widget-pages'       => 'intravox',
        'widget-files-center' => 'files',
        'widget-openproject' => null,
    ];

    public function __construct(private IConfig $config) {}

    public function key(): string { return self::KEY; }
    public function resourceType(): ?string { return null; }
    public function applies(ProvisioningContext $ctx): bool { return true; }
    public function rollbackPossible(): bool { return false; }

    public function run(ProvisioningContext $ctx): StepResult {
        if ($ctx->teamId === null) {
            return StepResult::failed('no_team', 'The team does not exist yet.', true);
        }
        $bp     = $ctx->blueprint;
        $hidden = $bp->dashboardHidden();
        $shown  = $bp->dashboardWidgets();

        foreach (Blueprint::WIDGET_IDS as $widget) {
            if (in_array($widget, $hidden, true)) {
                continue;
            }
            if (!$this->isAvailable($ctx, $widget) || ($shown !== [] && !in_array($widget, $shown, true))) {
                $hidden[] = $widget;
            }
        }
        $hidden = array_values(array_unique($hidden));

        $this->config->setAppValue(Application::APP_ID, 'dashboard_hidden_' . $ctx->teamId, json_encode($hidden));
        $this->config->setAppValue(Application::APP_ID, 'dashboard_tab_' . $ctx->teamId, 'msgstream');

        $ctx->remember(self::KEY, 'hidden', $hidden);
        return StepResult::completed(null, ['hidden' => $hidden, 'widgets' => array_values(array_diff(Blueprint::WIDGET_IDS, $hidden))]);
    }

    public function rollback(ProvisioningContext $ctx, array $stepRow, bool $confirm): StepResult {
        return StepResult::skipped('removed_with_team');
    }

    /** Can this workspace show this widget at all. */
    private function isAvailable(ProvisioningContext $ctx, string $widget): bool {
        if ($widget === 'widget-pages') {
            return $ctx->hasApp('intravox') || $ctx->hasApp('collectives');
        }
        if ($widget === 'widget-decisions') {
            return $ctx->hasModule('decisions');
        }
        if ($widget === 'msgstream') {
            return $ctx->hasModule('messages');
        }
        $needs = self::WIDGET_NEEDS[$widget] ?? null;
        return $needs === null || $ctx->hasApp($needs);
    }
}
