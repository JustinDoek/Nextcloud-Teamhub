<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\MyWorkConfigService;
use Psr\Log\LoggerInterface;

/**
 * Holds the registered providers and runs them safely (v4.5.21).
 *
 * This is the fault-isolation boundary the specification asks for: a provider
 * that throws, or that eats the whole request budget, must not make My Work
 * unusable. Every fetch goes through `fetchAll()`, which returns partial
 * results plus a per-provider status list the UI renders as a non-blocking
 * notice ("Deck items could not be refreshed. All other results are up to
 * date.").
 *
 * ## About "timeouts"
 *
 * PHP has no way to interrupt a running DB query from userland, so a real
 * per-provider timeout is not achievable without pcntl/threads. What is
 * achievable — and what this does — is a **wall-clock budget**: providers run
 * in a deterministic order, elapsed time is measured after each, and once the
 * budget is spent the remaining providers are skipped and reported as
 * `timeout` rather than being started. That bounds the total request, which is
 * the property that actually matters for the page. A single provider that
 * hangs forever will still hang the request; that is a limitation, it is
 * recorded here deliberately, and the mitigation is the per-provider row cap
 * on WorkQuery plus bounded SQL in each provider.
 */
class ProviderRegistry {

    /** @var array<string, IWorkProvider> */
    private array $providers = [];

    public function __construct(
        private MyWorkConfigService $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Register a provider. Called from Application::register via the DI
     * container. Later registrations of the same id win, which lets an
     * integration override a built-in without a core change.
     */
    public function register(IWorkProvider $provider): void {
        $this->providers[$provider->getId()] = $provider;
    }

    /** @return array<string, IWorkProvider> all registered, ignoring admin state */
    public function all(): array {
        return $this->providers;
    }

    public function get(string $providerId): ?IWorkProvider {
        return $this->providers[$providerId] ?? null;
    }

    /**
     * Providers an administrator has enabled AND that report themselves usable.
     *
     * @return array<string, IWorkProvider>
     */
    public function enabled(): array {
        $out = [];
        foreach ($this->providers as $id => $provider) {
            if (!$this->config->isProviderEnabled($id)) {
                continue;
            }
            try {
                if (!$provider->isAvailable()) {
                    continue;
                }
            } catch (\Throwable $e) {
                // A provider that cannot even answer "are you there" is not
                // one we are going to ask for data.
                $this->logger->warning('[TeamHub][MyWork] Provider availability check threw', [
                    'provider' => $id, 'exception' => $e, 'app' => Application::APP_ID,
                ]);
                continue;
            }
            $out[$id] = $provider;
        }
        return $out;
    }

    /**
     * Run every eligible provider and merge the results.
     *
     * Never throws. The returned `status` array always has one entry per
     * registered provider so the UI can distinguish "returned nothing" from
     * "was not asked" from "blew up".
     *
     * @return array{
     *     items: WorkItem[],
     *     status: array<int, array<string,mixed>>,
     *     truncated: string[]
     * }
     */
    public function fetchAll(WorkQuery $query): array {
        $budgetMs  = $this->config->getProviderBudgetMs();
        $startedAt = microtime(true);

        $items     = [];
        $status    = [];
        $truncated = [];

        // Deterministic order so the budget is spent predictably rather than
        // starving whichever provider happens to be last in a hash map.
        $providerIds = array_keys($this->providers);
        sort($providerIds);

        foreach ($providerIds as $id) {
            $provider = $this->providers[$id];

            // Caller asked for a subset (a provider filter in the UI).
            if ($query->providerIds !== [] && !in_array($id, $query->providerIds, true)) {
                $status[] = $this->statusRow($id, $provider, 'skipped', null, 0);
                continue;
            }

            if (!$this->config->isProviderEnabled($id)) {
                $status[] = $this->statusRow($id, $provider, 'disabled', null, 0);
                continue;
            }

            $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
            if ($elapsedMs >= $budgetMs) {
                $status[] = $this->statusRow(
                    $id, $provider, 'timeout',
                    'Skipped: the request budget was already spent by earlier providers.',
                    0,
                );
                continue;
            }

            $providerStart = microtime(true);
            try {
                if (!$provider->isAvailable()) {
                    $status[] = $this->statusRow(
                        $id, $provider, 'unavailable',
                        $provider->getUnavailableReason(),
                        (int)round((microtime(true) - $providerStart) * 1000),
                    );
                    continue;
                }

                $page = $provider->fetchItems($query);
                foreach ($page->items as $item) {
                    $items[] = $item;
                }
                if ($page->truncated) {
                    $truncated[] = $id;
                }

                $row = $this->statusRow(
                    $id, $provider, 'ok', null,
                    (int)round((microtime(true) - $providerStart) * 1000),
                );
                $row['count'] = count($page->items);
                $row['total'] = $page->total;
                $status[]     = $row;

                $this->config->recordProviderSync($id, true, null);
            } catch (\Throwable $e) {
                // Fault isolation: log it, mark it, keep going.
                $this->logger->error('[TeamHub][MyWork] Provider fetch failed', [
                    'provider' => $id, 'exception' => $e, 'app' => Application::APP_ID,
                ]);
                $status[] = $this->statusRow(
                    $id, $provider, 'error',
                    $e->getMessage(),
                    (int)round((microtime(true) - $providerStart) * 1000),
                );
                $this->config->recordProviderSync($id, false, $e->getMessage());
            }
        }

        return ['items' => $items, 'status' => $status, 'truncated' => $truncated];
    }

    /**
     * Provider descriptors for the filter UI and the admin status page.
     *
     * @return array<int, array<string,mixed>>
     */
    public function describeAll(): array {
        $out = [];
        foreach ($this->providers as $id => $provider) {
            $available = false;
            $reason    = null;
            try {
                $available = $provider->isAvailable();
                $reason    = $available ? null : $provider->getUnavailableReason();
            } catch (\Throwable $e) {
                $reason = $e->getMessage();
            }

            $sync = $this->config->getProviderSync($id);

            // Optional, picked up by method_exists rather than added to
            // IWorkProvider: a provider integrating against a schema we cannot
            // verify locally can explain what it found, without every future
            // provider having to implement a method it has no use for.
            $diagnostics = [];
            if (method_exists($provider, 'getDiagnostics')) {
                try {
                    $diagnostics = (array)$provider->getDiagnostics();
                } catch (\Throwable $e) {
                    $diagnostics = [[
                        'label' => 'diagnostics',
                        'value' => $e->getMessage(),
                    ]];
                }
            }

            $out[] = [
                'id'               => $id,
                'name'             => $provider->getName(),
                'icon'             => $provider->getIcon(),
                'enabled'          => $this->config->isProviderEnabled($id),
                'available'        => $available,
                'unavailableReason' => $reason,
                'capabilities'     => $provider->getCapabilities(),
                'allowedActions'   => $this->config->getAllowedActions($id, $provider),
                'supportedFilters' => $provider->getSupportedFilters(),
                'configSchema'     => $provider->getConfigSchema(),
                'lastSyncAt'       => $sync['lastSyncAt'],
                'lastErrorAt'      => $sync['lastErrorAt'],
                'lastError'        => $sync['lastError'],
                'diagnostics'      => $diagnostics,
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcmp((string)$a['id'], (string)$b['id']));
        return $out;
    }

    /** @return array<string,mixed> */
    private function statusRow(
        string $id,
        IWorkProvider $provider,
        string $state,
        ?string $message,
        int $durationMs,
    ): array {
        // Provider names are translated strings; the id is what code branches on.
        $name = $id;
        try {
            $name = $provider->getName();
        } catch (\Throwable) {
            // A provider whose own name throws is broken enough that the id
            // is the more useful label anyway.
        }

        return [
            'id'         => $id,
            'name'       => $name,
            'state'      => $state,
            'message'    => $message,
            'durationMs' => $durationMs,
            'count'      => 0,
            'total'      => 0,
        ];
    }
}
