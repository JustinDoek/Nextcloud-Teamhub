<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\IntegrationCapabilityMapper;
use OCA\TeamHub\Integration\ILifecycleProvider;
use OCA\TeamHub\Integration\TeamHubContextFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fires integration lifecycle hooks, in isolation (v4.13.23).
 *
 * ## Where a lifecycle provider comes from, and why not a capability type
 *
 * `lifecycle_provider` is listed in `CapabilityType::FUTURE` and is not an
 * accepted capability type. A package does not register a lifecycle provider
 * separately — instead, **any provider class the package already registered
 * may additionally implement {@see ILifecycleProvider}**, and this service
 * finds it with `instanceof`.
 *
 * That is deliberate, and it is the conservative choice rather than the clever
 * one. A separate capability type would mean a class TeamHub instantiates for
 * no purpose but to be notified, registered through a code path with no other
 * use, before there is a single surface that fires every hook in the
 * interface. Hanging the hooks off a class the package already has means an
 * app opts in by implementing an interface on its widget or its work
 * provider — one line, no new registration, nothing to migrate when the
 * capability type does open.
 *
 * ## Isolation is the whole job
 *
 * Every invocation is wrapped. A hook that throws is logged with the package
 * identity, the team and the error class — never the provider's data — and the
 * caller carries on. A hook cannot veto a team's creation or deletion, cannot
 * fail a reconciliation and cannot corrupt TeamHub state, because nothing it
 * returns is written anywhere except {@see validateTeamConfiguration()}, whose
 * problems are shown to the person making the change.
 *
 * ## Transaction discipline
 *
 * This service never opens a transaction and must never be called from inside
 * one for the after-commit hooks. The ordering guarantees are stated per hook
 * on {@see ILifecycleProvider}; keeping them is the caller's job, and the
 * callers that matter — provisioning and reconciliation — are both documented
 * as running these after their own commit.
 */
class IntegrationLifecycleService {

    public function __construct(
        private IntegrationCapabilityMapper $capabilities,
        private TeamHubContextFactory       $contextFactory,
        private ContainerInterface          $container,
        private LoggerInterface             $logger,
    ) {}

    /**
     * Fire one hook for one package and one team.
     *
     * @param array<string,mixed> $pkgRow
     * @param string              $hook   a method name on {@see ILifecycleProvider}
     * @param list<mixed>         $extra  further arguments after the context
     *
     * @return array{fired: int, failed: int, errors: list<string>}
     */
    public function fire(array $pkgRow, string $teamId, string $actorUid, string $hook, array $extra = []): array {
        $fired  = 0;
        $failed = 0;
        $errors = [];

        foreach ($this->providersFor($pkgRow) as $capabilityKey => $provider) {
            if (!method_exists($provider, $hook)) {
                continue;
            }

            try {
                $context = $this->contextFactory->forActor(
                    teamId:         $teamId,
                    actorUid:       $actorUid,
                    appId:          (string)$pkgRow['app_id'],
                    integrationKey: (string)$pkgRow['integration_key'],
                    capabilityKey:  $capabilityKey,
                );

                $provider->{$hook}($context, ...$extra);
                $fired++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = get_class($e);
                // Identity, team and error class. Never the provider's data.
                $this->logger->error('[TeamHub][Integration] Lifecycle hook failed', [
                    'app_id'          => (string)$pkgRow['app_id'],
                    'integration_key' => (string)$pkgRow['integration_key'],
                    'capability_key'  => $capabilityKey,
                    'team_id'         => $teamId,
                    'hook'            => $hook,
                    'error_class'     => get_class($e),
                    'exception'       => $e,
                    'app'             => Application::APP_ID,
                ]);
            }
        }

        return ['fired' => $fired, 'failed' => $failed, 'errors' => array_values(array_unique($errors))];
    }

    /**
     * Collect objections to a team's configuration.
     *
     * The one hook whose return value TeamHub acts on. A provider that throws
     * here is treated as a fault and the change proceeds — objecting is done
     * by returning sentences, not by failing.
     *
     * @param array<string,mixed> $pkgRow
     * @return list<string>
     */
    public function validate(array $pkgRow, string $teamId, string $actorUid): array {
        $problems = [];

        foreach ($this->providersFor($pkgRow) as $capabilityKey => $provider) {
            try {
                $context = $this->contextFactory->forActor(
                    teamId:         $teamId,
                    actorUid:       $actorUid,
                    appId:          (string)$pkgRow['app_id'],
                    integrationKey: (string)$pkgRow['integration_key'],
                    capabilityKey:  $capabilityKey,
                );

                foreach ($provider->validateTeamConfiguration($context) as $problem) {
                    $text = trim((string)$problem);
                    if ($text !== '') {
                        $problems[] = $text;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('[TeamHub][Integration] Configuration validation hook failed', [
                    'app_id'          => (string)$pkgRow['app_id'],
                    'integration_key' => (string)$pkgRow['integration_key'],
                    'capability_key'  => $capabilityKey,
                    'team_id'         => $teamId,
                    'error_class'     => get_class($e),
                    'exception'       => $e,
                    'app'             => Application::APP_ID,
                ]);
            }
        }

        return array_values(array_unique($problems));
    }

    /** Does this package have anything that wants lifecycle hooks? */
    public function hasLifecycleProviders(array $pkgRow): bool {
        return $this->providersFor($pkgRow) !== [];
    }

    /**
     * The package's registered provider classes that implement
     * {@see ILifecycleProvider}, keyed by capability key.
     *
     * Resolved fresh from the container so each gets full constructor
     * injection, exactly as a widget provider does. A class that cannot be
     * constructed is skipped and logged rather than taking the caller down —
     * the same fault-isolation contract applied one stage earlier, which is
     * the pattern `Application::register()` already uses for the built-in My
     * Work providers.
     *
     * @param array<string,mixed> $pkgRow
     * @return array<string,ILifecycleProvider>
     */
    private function providersFor(array $pkgRow): array {
        $out  = [];
        $seen = [];

        try {
            $rows = $this->capabilities->findByPackage((int)$pkgRow['id']);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][Integration] Could not read capabilities for lifecycle hooks', [
                'app_id' => (string)$pkgRow['app_id'], 'error_class' => get_class($e),
                'exception' => $e, 'app' => Application::APP_ID,
            ]);

            return [];
        }

        foreach ($rows as $row) {
            if (!$row['registered']) {
                continue;
            }
            $class = $row['provider_class'];
            if ($class === null || $class === '') {
                continue;
            }
            // One instance per class, whichever capability named it first: a
            // package whose widget and work provider are the same class must
            // not be notified twice for one event.
            if (isset($seen[$class])) {
                continue;
            }
            $seen[$class] = true;

            if (!is_a($class, ILifecycleProvider::class, true)) {
                continue;
            }

            try {
                $provider = $this->container->get($class);
            } catch (\Throwable $e) {
                $this->logger->error('[TeamHub][Integration] Lifecycle provider could not be constructed', [
                    'app_id'          => (string)$pkgRow['app_id'],
                    'integration_key' => (string)$pkgRow['integration_key'],
                    'capability_key'  => (string)$row['capability_key'],
                    'error_class'     => get_class($e),
                    'exception'       => $e,
                    'app'             => Application::APP_ID,
                ]);
                continue;
            }

            if ($provider instanceof ILifecycleProvider) {
                $out[(string)$row['capability_key']] = $provider;
            }
        }

        return $out;
    }
}
