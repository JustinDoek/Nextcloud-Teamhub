<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\OpenProjectException;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Administrator diagnostics for the OpenProject aggregation (v4.9.7,
 * Phase 3).
 *
 * TeamHub reads OpenProject **live, as each viewer** — there is no
 * background synchronisation, no index and no stored activity (see
 * OPENPROJECT.md §6.3 for why). So "synchronisation health" here is the
 * health of those live reads, aggregated across every viewer: when one last
 * succeeded, when one last failed and with what code, how many projects the
 * last runs covered or skipped, and how often each class of failure has
 * been seen. **Nothing personal is recorded** — no user id, no project name,
 * no work-package subject, no count of anybody's work. An administrator
 * reads this beside the Nextcloud log, which carries the same anonymity.
 *
 * Two channels, `mywork` (the My Work provider) and `activity` (the What's
 * New source), because their failure modes differ and an administrator
 * wants to know which one is broken.
 *
 * ## Where it lives
 *
 * Counters accumulate in the distributed cache (they are cheap to bump on
 * every request and cost nothing to lose on a cache flush — they are
 * diagnostics, not records) and the four "last …" timestamps go to
 * appconfig, throttled the way `MyWorkConfigService::recordProviderSync()`
 * throttles its own: a success is written at most once a minute, a failure
 * always, so "when did this last break" is exact and "when did this last
 * work" is close enough.
 */
class OpenProjectSyncHealth {

    public const CHANNEL_MY_WORK  = 'mywork';
    public const CHANNEL_ACTIVITY = 'activity';

    public const CHANNELS = [self::CHANNEL_MY_WORK, self::CHANNEL_ACTIVITY];

    /** How often a successful attempt's timestamp is actually written. */
    private const SUCCESS_WRITE_INTERVAL = 60;
    /** Counters survive this long without a bump. */
    private const COUNTER_TTL = 7 * 24 * 3600;

    private const CFG_PREFIX = 'openproject_health_';

    private const COUNTERS = [
        'attempts', 'successes', 'partial', 'projectsCovered', 'projectsSkipped',
        'authProblems', 'permissionProblems', 'notFound', 'timeouts',
        'unsupportedResponses', 'rateLimited', 'unavailable', 'budgetExhausted',
    ];

    private ICache $cache;

    public function __construct(
        ICacheFactory           $cacheFactory,
        private IConfig         $config,
        private LoggerInterface $logger,
    ) {
        $this->cache = $cacheFactory->createDistributed('teamhub_openproject_health');
    }

    /**
     * One run of one channel is over: record what happened. `$skipped`
     * counts projects that answered with an error and were left out;
     * `$errorCodes` are the classified codes of those errors (one per
     * skipped project, so a run over three projects of which one was
     * forbidden records `covered 2, skipped 1, permissionProblems 1`).
     *
     * @param list<string> $errorCodes
     */
    public function recordRun(string $channel, int $covered, int $skipped, array $errorCodes, bool $budgetExhausted = false): void {
        if (!in_array($channel, self::CHANNELS, true)) {
            return;
        }
        try {
            $this->bump($channel, 'attempts');
            $this->bump($channel, 'projectsCovered', $covered);
            $this->bump($channel, 'projectsSkipped', $skipped);
            foreach ($errorCodes as $code) {
                $counter = $this->counterFor($code);
                if ($counter !== null) {
                    $this->bump($channel, $counter);
                }
            }
            if ($budgetExhausted) {
                $this->bump($channel, 'budgetExhausted');
            }

            $now = time();
            if ($errorCodes === [] && !$budgetExhausted) {
                $this->bump($channel, 'successes');
                $last = (int)$this->getAppValue($channel . '_success_at', '0');
                if ($now - $last >= self::SUCCESS_WRITE_INTERVAL) {
                    $this->setAppValue($channel . '_success_at', (string)$now);
                    $this->setAppValue($channel . '_attempt_at', (string)$now);
                }
                return;
            }

            $this->bump($channel, 'partial');
            $this->setAppValue($channel . '_attempt_at', (string)$now);
            if ($errorCodes !== []) {
                $this->setAppValue($channel . '_error_at', (string)$now);
                // The most recent code, bounded to the known vocabulary.
                $code = (string)end($errorCodes);
                $this->setAppValue($channel . '_error_code', preg_match('/^[a-z_]{1,40}$/', $code) === 1 ? $code : 'unknown');
            }
        } catch (\Throwable $e) {
            // Bookkeeping must never break a fetch.
            $this->logger->debug('[TeamHub][OpenProjectSyncHealth] Could not record a run', [
                'channel' => $channel, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * A run that never got to a project: the integration was unusable or
     * the viewer's connection was refused. Recorded as an attempt with one
     * error so the administrator sees the code, and nothing else.
     */
    public function recordFailure(string $channel, string $errorCode): void {
        $this->recordRun($channel, 0, 0, [$errorCode]);
    }

    /**
     * The diagnostics block for one channel, as the admin page renders it.
     *
     * @return array<string, mixed>
     */
    public function describe(string $channel): array {
        $counters = [];
        foreach (self::COUNTERS as $name) {
            $counters[$name] = $this->read($channel, $name);
        }
        $since = $this->cache->get($this->key($channel, 'since'));
        return [
            'channel'       => $channel,
            'lastAttemptAt' => $this->timestamp($channel . '_attempt_at'),
            'lastSuccessAt' => $this->timestamp($channel . '_success_at'),
            'lastErrorAt'   => $this->timestamp($channel . '_error_at'),
            'lastErrorCode' => $this->getAppValue($channel . '_error_code', '') ?: null,
            'countersSince' => is_numeric($since) ? (int)$since : null,
            'counters'      => $counters,
        ];
    }

    /**
     * The same, flattened into the `[{label, value}]` rows the My Work admin
     * page's "Integration details" list already renders for other
     * providers. English on purpose — administrator diagnostics are read
     * beside the Nextcloud log (see `OpenProjectMessages`).
     *
     * @return list<array{label: string, value: string}>
     */
    public function diagnosticRows(): array {
        $rows = [];
        foreach (self::CHANNELS as $channel) {
            $d     = $this->describe($channel);
            $label = $channel === self::CHANNEL_MY_WORK ? 'My Work' : 'What\'s new';
            $fmt   = static fn (?int $ts): string => $ts === null ? 'never' : gmdate('Y-m-d H:i:s', $ts) . ' UTC';
            $c     = $d['counters'];
            $rows[] = ['label' => $label . ' — last attempt',  'value' => $fmt($d['lastAttemptAt'])];
            $rows[] = ['label' => $label . ' — last success',  'value' => $fmt($d['lastSuccessAt'])];
            $rows[] = ['label' => $label . ' — last error',    'value' => $d['lastErrorAt'] === null
                ? 'none'
                : $fmt($d['lastErrorAt']) . ' (' . ($d['lastErrorCode'] ?? 'unknown') . ')'];
            $rows[] = ['label' => $label . ' — runs',          'value' => sprintf('%d, %d complete, %d partial', $c['attempts'], $c['successes'], $c['partial'])];
            $rows[] = ['label' => $label . ' — projects',      'value' => sprintf('%d covered, %d skipped', $c['projectsCovered'], $c['projectsSkipped'])];
            $rows[] = ['label' => $label . ' — problems',      'value' => sprintf(
                'auth %d, permission %d, not found %d, timeout %d, unsupported %d, rate-limited %d, unreachable %d, budget %d',
                $c['authProblems'], $c['permissionProblems'], $c['notFound'], $c['timeouts'],
                $c['unsupportedResponses'], $c['rateLimited'], $c['unavailable'], $c['budgetExhausted'],
            )];
        }
        $rows[] = [
            'label' => 'Strategy',
            'value' => 'live per-viewer reads; no background job, no index, no webhook. Checkpoint: per-user "last visit" for the "updated since your last visit" reason; the What\'s new window is the viewer\'s period.',
        ];
        return $rows;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    private function counterFor(string $code): ?string {
        return match ($code) {
            OpenProjectException::AUTH_FAILED,
            OpenProjectException::USER_NOT_CONNECTED   => 'authProblems',
            OpenProjectException::PERMISSION_DENIED    => 'permissionProblems',
            OpenProjectException::PROJECT_NOT_FOUND    => 'notFound',
            OpenProjectException::TEMPORARY_FAILURE    => 'timeouts',
            OpenProjectException::UNSUPPORTED_RESPONSE => 'unsupportedResponses',
            OpenProjectException::RATE_LIMITED         => 'rateLimited',
            OpenProjectException::API_UNAVAILABLE      => 'unavailable',
            default                                    => null,
        };
    }

    private function bump(string $channel, string $counter, int $by = 1): void {
        if ($by === 0) {
            return;
        }
        $key = $this->key($channel, $counter);
        $cur = $this->cache->get($key);
        if (!is_numeric($cur)) {
            $cur = 0;
            if ($this->cache->get($this->key($channel, 'since')) === null) {
                $this->cache->set($this->key($channel, 'since'), time(), self::COUNTER_TTL);
            }
        }
        $this->cache->set($key, (int)$cur + $by, self::COUNTER_TTL);
    }

    private function read(string $channel, string $counter): int {
        $v = $this->cache->get($this->key($channel, $counter));
        return is_numeric($v) ? (int)$v : 0;
    }

    private function key(string $channel, string $name): string {
        return 'v1|' . $channel . '|' . $name;
    }

    private function timestamp(string $key): ?int {
        $v = (int)$this->getAppValue($key, '0');
        return $v > 0 ? $v : null;
    }

    private function getAppValue(string $key, string $default): string {
        return $this->config->getAppValue(Application::APP_ID, self::CFG_PREFIX . $key, $default);
    }

    private function setAppValue(string $key, string $value): void {
        $this->config->setAppValue(Application::APP_ID, self::CFG_PREFIX . $key, $value);
    }
}
