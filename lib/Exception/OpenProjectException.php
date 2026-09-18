<?php
declare(strict_types=1);

namespace OCA\TeamHub\Exception;

/**
 * A failure talking to OpenProject, classified (v4.9.3).
 *
 * Every failure the integration can hit is one of the codes below, and the
 * code is the contract: the controller maps it to a status, the frontend maps
 * it to a sentence, and neither ever looks at the message. The message is for
 * the Nextcloud log and the administrator diagnostic, and it never contains a
 * response body, a token or a header — `OpenProjectClient` is the only place
 * that builds one, and it is written to keep those out.
 */
class OpenProjectException extends \RuntimeException {

    /**
     * v4.9.16 — TeamHub's OpenProject module needs a licence and has none
     * (or a lapsed one past its grace window). Checked before anything about
     * the official app: see `OpenProjectModuleService`.
     */
    public const MODULE_UNLICENSED = 'module_unlicensed';
    /** v4.9.16 — the module is licensed but switched off in TeamHub administration. */
    public const MODULE_DISABLED = 'module_disabled';
    /** The official integration app is not installed. */
    public const INTEGRATION_NOT_INSTALLED = 'integration_not_installed';
    /** Installed, but disabled by an administrator. */
    public const INTEGRATION_DISABLED = 'integration_disabled';
    /** No OpenProject instance URL is configured in the integration app. */
    public const HOST_NOT_CONFIGURED = 'host_not_configured';
    /** The installed integration app has no usable request interface. */
    public const INTEGRATION_INCOMPATIBLE = 'integration_incompatible';
    /** The current user has not connected their OpenProject account. */
    public const USER_NOT_CONNECTED = 'user_not_connected';
    /** OpenProject rejected the user's credentials (401). */
    public const AUTH_FAILED = 'auth_failed';
    /** OpenProject refused the action for this user (403). */
    public const PERMISSION_DENIED = 'permission_denied';
    /** The linked project no longer exists, or is not visible to this user. */
    public const PROJECT_NOT_FOUND = 'project_not_found';
    /** OpenProject answered, but not in a shape this integration understands. */
    public const UNSUPPORTED_RESPONSE = 'unsupported_response';
    /** OpenProject could not be reached at all. */
    public const API_UNAVAILABLE = 'api_unavailable';
    /** A 5xx or a timeout — worth retrying, nothing to configure. */
    public const TEMPORARY_FAILURE = 'temporary_failure';
    /** OpenProject is rate-limiting this user (429). */
    public const RATE_LIMITED = 'rate_limited';
    /** The link was made against another OpenProject instance. */
    public const LINK_STALE = 'link_stale';
    /**
     * v4.9.6 — OpenProject refused a write on its own rules (422): an
     * identifier already taken, a name too long, a template that cannot be
     * copied. The one code that carries OpenProject's own sentence
     * ({@see getUpstreamMessage()}), because the user has to read it to fix
     * the input.
     */
    public const VALIDATION_FAILED = 'validation_failed';
    /**
     * v4.9.6 — an OpenProject background job (a project copy) ended in
     * error or failure.
     */
    public const JOB_FAILED = 'job_failed';

    public function __construct(
        private string $errorCode,
        string $message = '',
        private ?int $upstreamStatus = null,
        ?\Throwable $previous = null,
        private ?string $upstreamMessage = null,
    ) {
        parent::__construct($message !== '' ? $message : $errorCode, 0, $previous);
    }

    public function getErrorCode(): string {
        return $this->errorCode;
    }

    /** The HTTP status OpenProject answered with, when there was one. */
    public function getUpstreamStatus(): ?int {
        return $this->upstreamStatus;
    }

    /**
     * OpenProject's own plain-text explanation, only for `validation_failed`
     * and `job_failed` — already reduced to text and bounded by the client.
     */
    public function getUpstreamMessage(): ?string {
        return $this->upstreamMessage;
    }

    /**
     * Whether the failure is about the environment (nothing the user can fix
     * from a team) rather than about this user or this project.
     */
    public function isConfigurationProblem(): bool {
        return in_array($this->errorCode, [
            self::MODULE_UNLICENSED,
            self::MODULE_DISABLED,
            self::INTEGRATION_NOT_INSTALLED,
            self::INTEGRATION_DISABLED,
            self::HOST_NOT_CONFIGURED,
            self::INTEGRATION_INCOMPATIBLE,
        ], true);
    }
}
