<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork;

/**
 * Outcome of executing one action (v4.5.21).
 *
 * Providers return this rather than throwing, so a refused action reads as a
 * normal answer with a reason the user can act on ("This card was completed by
 * someone else") instead of a 500. Genuine faults still throw and are caught
 * one level up.
 *
 * `$errorCode` is a stable machine string the frontend can branch on:
 *   - `forbidden`   the user may not do this
 *   - `gone`        the source resource no longer exists
 *   - `conflict`    the source status no longer allows it
 *   - `unsupported` the provider does not implement this action
 *   - `failed`      the source app refused or errored
 */
final class ActionResult implements \JsonSerializable {

    private function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly ?string $errorCode = null,
        public readonly ?WorkItem $item = null,
        public readonly bool $removed = false,
    ) {
    }

    /**
     * Action succeeded.
     *
     * @param WorkItem|null $item    refreshed item, when it still belongs in
     *                               the queue (e.g. snooze — same item, new state)
     * @param bool          $removed true when the item has left the queue
     *                               entirely, so the row can be dropped
     *                               without a refetch
     */
    public static function success(string $message, ?WorkItem $item = null, bool $removed = false): self {
        return new self(true, $message, null, $item, $removed);
    }

    public static function failure(string $message, string $errorCode = 'failed'): self {
        return new self(false, $message, $errorCode);
    }

    public static function forbidden(string $message): self {
        return new self(false, $message, 'forbidden');
    }

    public static function gone(string $message): self {
        return new self(false, $message, 'gone');
    }

    public static function conflict(string $message): self {
        return new self(false, $message, 'conflict');
    }

    public static function unsupported(string $message): self {
        return new self(false, $message, 'unsupported');
    }

    /**
     * HTTP status this result should be returned with. Kept next to the
     * error codes so the controller cannot drift from them, and so a refused
     * action never comes back as `200` with an error body (SKILLS.md § PHP
     * coding standards).
     */
    public function httpStatus(): int {
        if ($this->ok) {
            return 200;
        }
        return match ($this->errorCode) {
            'forbidden'   => 403,
            'gone'        => 404,
            'conflict'    => 409,
            'unsupported' => 400,
            default       => 502,
        };
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array {
        return [
            'ok'        => $this->ok,
            'message'   => $this->message,
            'errorCode' => $this->errorCode,
            'item'      => $this->item?->toArray(),
            'removed'   => $this->removed,
        ];
    }
}
