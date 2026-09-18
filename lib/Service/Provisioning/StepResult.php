<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Provisioning;

/**
 * What one provisioning step reports back (v4.9.6, Phase 2).
 *
 * `status` is one of:
 *   completed  — done; `externalId` names what it made or found
 *   skipped    — nothing to do for this workspace (the blueprint said so, or
 *                the application is optional and absent); recorded, not hidden
 *   running    — waiting on something asynchronous (an OpenProject copy job);
 *                `externalRef` is what to poll; the runner comes back later
 *   attention  — done as far as it could, with something a person must look
 *                at (a member OpenProject refused, a folder that is not there)
 *   failed     — did not complete; `retrySafe` says whether trying again is
 *                sound
 *
 * `detail` is a small, sanitised array for the status UI — names and ids,
 * never a token, never a body. `errorMessage` is a sentence for a person.
 */
final class StepResult {

    public const COMPLETED = 'completed';
    public const SKIPPED   = 'skipped';
    public const RUNNING   = 'running';
    public const ATTENTION = 'attention';
    public const FAILED    = 'failed';

    /**
     * @param array<string,mixed> $detail
     */
    public function __construct(
        public readonly string  $status,
        public readonly ?string $externalId = null,
        public readonly ?string $externalRef = null,
        public readonly array   $detail = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly bool    $retrySafe = true,
        public readonly ?bool   $rollbackPossible = null,
        /** Seconds the runner should wait before polling again (RUNNING only). */
        public readonly int     $pollAfter = 0,
    ) {
    }

    /** @param array<string,mixed> $detail */
    public static function completed(?string $externalId = null, array $detail = []): self {
        return new self(self::COMPLETED, $externalId, null, $detail);
    }

    /** @param array<string,mixed> $detail */
    public static function skipped(string $reason, array $detail = []): self {
        return new self(self::SKIPPED, null, null, ['reason' => $reason] + $detail);
    }

    /** @param array<string,mixed> $detail */
    public static function running(string $externalRef, int $pollAfter = 3, array $detail = []): self {
        return new self(self::RUNNING, null, $externalRef, $detail, null, null, true, null, max(1, $pollAfter));
    }

    /** @param array<string,mixed> $detail */
    public static function attention(string $code, string $message, array $detail = [], ?string $externalId = null): self {
        return new self(self::ATTENTION, $externalId, null, $detail, $code, $message, true);
    }

    /** @param array<string,mixed> $detail */
    public static function failed(string $code, string $message, bool $retrySafe = true, array $detail = []): self {
        return new self(self::FAILED, null, null, $detail, $code, $message, $retrySafe);
    }

    public function isTerminalSuccess(): bool {
        return $this->status === self::COMPLETED || $this->status === self::SKIPPED;
    }
}
