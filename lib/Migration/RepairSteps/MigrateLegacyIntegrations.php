<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration\RepairSteps;

use OCA\TeamHub\Db\IntegrationCapabilityMapper;
use OCA\TeamHub\Db\IntegrationEnablementMapper;
use OCA\TeamHub\Db\IntegrationPackageMapper;
use OCA\TeamHub\Db\IntegrationRegistryMapper;
use OCA\TeamHub\Db\IntegrationTemplateMapper;
use OCA\TeamHub\Db\IntegrationVisibilityMapper;
use OCA\TeamHub\Db\TeamIntegrationMapper;
use OCA\TeamHub\Db\TeamTypeMapper;
use OCA\TeamHub\Service\LegacyIntegrationAdapter;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Carry every existing integration registration into the package model
 * (v4.13.23).
 *
 * A repair step rather than a `postSchemaChange()`, because a fresh install
 * never runs a post step and this one has to be a no-op there rather than
 * absent — see `/migrations` rule 2a. It is application code and uses live
 * constants deliberately: it runs with the code it ships with, and
 * `check:migrations` scans `Version*.php` only.
 *
 * ## The rule, stated once
 *
 * **The upgrade preserves exactly what is on screen today, and grants nothing
 * that was not.** Everything below follows from that sentence, and every
 * choice it forced is recorded here because the alternatives all look
 * reasonable until you work out which teams they change.
 *
 * ### One package per app, one capability per legacy row
 *
 * A legacy row is keyed `(app_id, integration_type)`, so an app had at most a
 * widget row and a menu-item row. Both become capabilities of **one** package
 * `(app_id, 'legacy')` with capability keys `'widget'` and `'menu_item'` —
 * the same identity {@see \OCA\TeamHub\Service\LegacyIntegrationAdapter}
 * produces at boot, so the step and the adapter converge on the same rows
 * instead of racing to make two.
 *
 * Title, description, icon, provider class and iframe URL are copied across
 * unchanged. The package is written with `registered = 0`: registration is
 * something an app does in its own `boot()`, and claiming it here would mark a
 * package registered that nothing has actually registered this process. The
 * owning app's next boot sets it, which on a live instance is the next
 * request.
 *
 * ### Built-in rows are skipped
 *
 * `spreed`, `files`, `calendar` and `deck` are seeded into the legacy registry
 * by `IntegrationService::seedBuiltins()` and filtered out of both the Manage
 * Team list and the tab bar (`!i.is_builtin` in each). They are not
 * registrations and no app made them. Importing them would invent four
 * packages nobody registered, each needing an administrator's decision, for
 * apps TeamHub already handles natively.
 *
 * ### Administrator enablement: enabled if it was in use
 *
 * The conservative inference the objective asks for, and the one place a
 * judgement had to be made.
 *
 *   **enabled** when the app has at least one `teamhub_team_integrations` row
 *   anywhere on the instance — a team somewhere is using it, so the upgrade
 *   must not switch it off.
 *
 *   **no row (disabled)** otherwise — registered but never adopted by any
 *   team. A new package is disabled by default and this one has never been
 *   anybody's working integration, so enabling it would be the upgrade making
 *   a decision on the administrator's behalf.
 *
 * ### Template inclusion: only templates that actually had it
 *
 * For each legacy row, the teams that had it enabled are mapped to their
 * template (`teamhub_team_type.type`), and the package is included in exactly
 * those templates. A template no team used it under gets nothing.
 *
 * ### Visibility: the part that keeps the rule true
 *
 * Inclusion is per *template*, and a template has sibling teams. Including a
 * package because team A used it would hand it to teams B and C of the same
 * template, which breaks the rule in the second paragraph. So for every team
 * of an included template that did **not** have the legacy row, this step
 * writes an explicit `hidden = 1` for each of the package's visual
 * capabilities. Teams that did have it get `hidden = 0` and keep their
 * `sort_order` from `teamhub_team_integrations`.
 *
 * The visible set after the upgrade is therefore identical to the visible set
 * before it, and a team administrator can reveal what their siblings have at
 * any time — which they could not do before, because adding an integration
 * was all-or-nothing.
 *
 * ### Teams with no template
 *
 * A team created before templates existed has no `teamhub_team_type` row, so
 * there is no template through which it could inherit an inclusion. Its
 * configuration is **not** deleted and the package is still created and
 * enabled; the teams are counted and named in this step's output so an
 * administrator can adopt them. Guessing a template for them would be the one
 * thing worse than reporting it.
 *
 * ### An unavailable app changes nothing
 *
 * A legacy row whose owning app is uninstalled or disabled migrates exactly
 * like any other. Availability is read live and is not this step's business;
 * discarding a row because the app happens to be down during the upgrade is
 * how an estate loses configuration it will want back on Monday.
 *
 * ## Idempotency
 *
 * A once-per-instance marker, not insert-when-absent. The rows this step
 * writes — enablement, inclusions, preferences — are all rows an administrator
 * may legitimately delete, and a second run would resurrect an inclusion they
 * removed on purpose. The marker records the version that ran so a later,
 * different backfill can still be added.
 */
class MigrateLegacyIntegrations implements IRepairStep {

    private const APP_ID     = 'teamhub';
    private const MARKER_KEY = 'legacy_integrations_migrated';

    /** How many team ids the output names before it summarises the rest. */
    private const NAME_LIMIT = 20;

    public function __construct(
        private IntegrationRegistryMapper   $legacyRegistry,
        private TeamIntegrationMapper        $legacyTeams,
        private IntegrationPackageMapper     $packages,
        private IntegrationCapabilityMapper  $capabilities,
        private IntegrationEnablementMapper  $enablement,
        private IntegrationTemplateMapper    $inclusions,
        private IntegrationVisibilityMapper  $visibility,
        private TeamTypeMapper               $teamTypes,
        private IConfig                      $config,
        private LoggerInterface              $logger,
    ) {}

    public function getName(): string {
        return 'Carry existing TeamHub integrations into the package model';
    }

    public function run(IOutput $output): void {
        try {
            if ($this->config->getAppValue(self::APP_ID, self::MARKER_KEY, '') !== '') {
                return;
            }

            $legacyRows = array_values(array_filter(
                $this->legacyRegistry->findAll(),
                // Built-ins are seeded fiction, not registrations. See the
                // class docblock.
                static fn (array $row): bool => !$row['is_builtin'],
            ));

            if ($legacyRows === []) {
                $this->config->setAppValue(self::APP_ID, self::MARKER_KEY, (string)time());
                return;
            }

            $assignments = $this->legacyTeams->findAllAssignments();
            $now         = time();

            // registry_id => [teamId => sortOrder]
            $teamsByRegistry = [];
            foreach ($assignments as $assignment) {
                $teamsByRegistry[$assignment['registry_id']][$assignment['team_id']] = $assignment['sort_order'];
            }

            // app_id => list of legacy rows
            $rowsByApp = [];
            foreach ($legacyRows as $row) {
                $rowsByApp[(string)$row['app_id']][] = $row;
            }

            $packagesMade  = 0;
            $capsMade      = 0;
            $enabledCount  = 0;
            $inclusions    = 0;
            $hiddenWritten = 0;
            $shownWritten  = 0;
            $orphanTeams   = [];

            foreach ($rowsByApp as $appId => $rows) {
                $pkgId = $this->packages->upsert(
                    appId:          $appId,
                    integrationKey: LegacyIntegrationAdapter::LEGACY_KEY,
                    // The app's own title. Both legacy rows of one app
                    // normally carry the same one; the first is as good an
                    // answer as exists and the app replaces it at next boot.
                    title:          (string)$rows[0]['title'],
                    description:    $rows[0]['description'] !== null ? (string)$rows[0]['description'] : null,
                    icon:           $rows[0]['icon'] !== null ? (string)$rows[0]['icon'] : null,
                    version:        '',
                    minThVersion:   '',
                    maxThVersion:   '',
                    isLegacy:       true,
                    // Not registered: see the class docblock. The owning app's
                    // next boot registers it.
                    registered:     false,
                    metadata:       ['adapted_from' => 'registerIntegration', 'migrated_at' => $now],
                    now:            $now,
                );
                $packagesMade++;

                // capability_key => [teamId => sortOrder] for this package
                $teamsByCapability = [];
                $capIdsByKey       = [];

                foreach ($rows as $row) {
                    $type = (string)$row['integration_type'];
                    if ($type !== IntegrationRegistryMapper::TYPE_WIDGET
                        && $type !== IntegrationRegistryMapper::TYPE_MENU_ITEM) {
                        // An integration_type this version does not know. Left
                        // alone rather than guessed at — the legacy row stays
                        // and keeps working through the old endpoints.
                        continue;
                    }

                    $capId = $this->capabilities->upsert(
                        pkgId:         $pkgId,
                        // The old integration_type is the only stable key the
                        // legacy model had, and it is the one the adapter uses.
                        capabilityKey: $type,
                        capType:       $type,
                        title:         (string)$row['title'],
                        description:   $row['description'] !== null ? (string)$row['description'] : null,
                        icon:          $row['icon'] !== null ? (string)$row['icon'] : null,
                        providerClass: $row['php_class'] !== null ? (string)$row['php_class'] : null,
                        iframeUrl:     $row['iframe_url'] !== null ? (string)$row['iframe_url'] : null,
                        defaultOrder:  0,
                        surfaces:      [],
                        presentation:  [],
                        now:           $now,
                    );
                    $capsMade++;

                    $capIdsByKey[$type]       = $capId;
                    $teamsByCapability[$type] = $teamsByRegistry[(int)$row['id']] ?? [];
                }

                if ($capIdsByKey === []) {
                    continue;
                }

                // Match the package's own `registered = 0`. `upsert()` sets a
                // capability registered because its only other caller *is* a
                // registration; this step is a reconstruction, and a
                // capability claiming to be registered while its package is
                // not is a state no real registration can produce. The owning
                // app's next boot sets both through the compatibility adapter.
                $this->capabilities->markUnregisteredExcept($pkgId, [], $now);

                // ── Administrator enablement ──────────────────────────────
                $teamsUsingThisApp = [];
                foreach ($teamsByCapability as $teams) {
                    foreach (array_keys($teams) as $teamId) {
                        $teamsUsingThisApp[$teamId] = true;
                    }
                }

                if ($teamsUsingThisApp !== []) {
                    $this->enablement->setEnabled($pkgId, true, 'migration', $now);
                    $enabledCount++;
                }

                // ── Template inclusion ────────────────────────────────────
                $templatesFor = $this->teamTypes->findTypesByTeams(array_keys($teamsUsingThisApp));

                $templateKeys = [];
                foreach (array_keys($teamsUsingThisApp) as $teamId) {
                    $templateKey = (string)($templatesFor[$teamId] ?? '');
                    if ($templateKey === '') {
                        $orphanTeams[$teamId] = true;
                        continue;
                    }
                    $templateKeys[$templateKey] = true;
                }

                foreach (array_keys($templateKeys) as $templateKey) {
                    $this->inclusions->includePackage($templateKey, $pkgId, 'migration', $now);
                    $inclusions++;
                }

                // ── Visibility, per capability, per team of each template ──
                foreach ($capIdsByKey as $capabilityKey => $capId) {
                    $teamsWithIt = $teamsByCapability[$capabilityKey] ?? [];

                    foreach (array_keys($templateKeys) as $templateKey) {
                        foreach ($this->teamTypes->findTeamsByType($templateKey) as $teamId) {
                            if (array_key_exists($teamId, $teamsWithIt)) {
                                // Had it: shown, in the position it was in.
                                $this->visibility->setBoth(
                                    $teamId, $capId, false, (int)$teamsWithIt[$teamId], 'migration', $now,
                                );
                                $shownWritten++;
                                continue;
                            }

                            // A sibling team of an included template that never
                            // had this capability. Explicitly hidden, so the
                            // upgrade grants nothing — see the class docblock.
                            $this->visibility->setBoth(
                                $teamId, $capId, true, IntegrationVisibilityMapper::NO_ORDER, 'migration', $now,
                            );
                            $hiddenWritten++;
                        }
                    }
                }
            }

            $this->config->setAppValue(self::APP_ID, self::MARKER_KEY, (string)$now);

            $output->info(sprintf(
                'TeamHub integrations: %d package(s) from %d legacy registration(s), %d capability/ies, '
                . '%d enabled, %d template inclusion(s), %d capability/ies shown and %d hidden across teams.',
                $packagesMade, count($legacyRows), $capsMade, $enabledCount, $inclusions,
                $shownWritten, $hiddenWritten,
            ));

            if ($orphanTeams !== []) {
                $ids   = array_keys($orphanTeams);
                $named = array_slice($ids, 0, self::NAME_LIMIT);
                $output->warning(sprintf(
                    'TeamHub integrations: %d team(s) use an integration but have no team template, so no '
                    . 'template inclusion could be recorded for them and their integrations are not '
                    . 'effective until the team is adopted into a template. Nothing was deleted. Team ids: %s%s',
                    count($ids),
                    implode(', ', $named),
                    count($ids) > count($named) ? ', …' : '',
                ));
            }
        } catch (\Throwable $e) {
            // Never fail an upgrade here. The legacy tables are untouched and
            // every old endpoint still works, so an instance that trips over
            // this keeps exactly the integration behaviour it had — and the
            // marker is unset, so the next upgrade retries.
            $this->logger->error('[TeamHub][MigrateLegacyIntegrations] Backfill failed; legacy behaviour is unchanged', [
                'error_class' => get_class($e), 'exception' => $e, 'app' => self::APP_ID,
            ]);
            $output->warning(
                'TeamHub integrations: the package backfill did not complete. Existing integrations keep '
                . 'working through the previous mechanism and the backfill will be retried on the next upgrade.',
            );
        }
    }
}
