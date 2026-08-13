<?php
declare(strict_types=1);

namespace OCA\TeamHub\Util;

use OCA\TeamHub\AppInfo\Application;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Central gate for verbose debug logging.
 *
 * Rationale (see code.md C-4). We accumulated ~270 `$logger->debug(...)`
 * calls during development. Every one of them serialises its context
 * array on every request, even when the log level is above DEBUG — the
 * NC logger discards the message after formatting, but the array
 * flattening still happens up front.
 *
 * This helper checks a single system-config bool (`teamhub.debug`,
 * default `false`) and short-circuits when disabled. Call it exactly
 * where you would have called `$this->logger->debug(...)`:
 *
 *     $this->debug->log($this->logger, '[TeamHub][FooService] doing X', [
 *         'teamId' => $teamId,
 *     ]);
 *
 * Enable at runtime with:
 *     occ config:system:set teamhub.debug --value true --type bool
 *
 * Callers that were already gated by `if ($this->config->…)` can drop
 * the guard — this helper does it internally.
 */
class DebugLog {

    private const CONFIG_KEY = 'teamhub.debug';

    public function __construct(
        private IConfig $config,
    ) {}

    /**
     * Emit a debug-level log line iff `teamhub.debug` is true.
     *
     * Context is only assembled and passed to the logger when the gate
     * is open — otherwise this method is a cheap boolean check plus a
     * fast return.
     */
    public function log(
        LoggerInterface $logger,
        string $message,
        array $context = [],
    ): void {
        if (!$this->isEnabled()) {
            return;
        }
        $context['app'] = $context['app'] ?? Application::APP_ID;
        $logger->debug($message, $context);
    }

    /**
     * True when the operator has explicitly opted in to verbose
     * TeamHub logging. Safe to call in hot paths — reads from NC's
     * in-memory config cache.
     */
    public function isEnabled(): bool {
        return $this->config->getSystemValueBool(self::CONFIG_KEY, false);
    }
}
