<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Service\OpenProject\OpenProjectMessages;
use OCA\TeamHub\Service\OpenProject\ProjectAlreadyLinkedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * OpenProject failures as classified responses (v4.9.4) — shared by
 * `OpenProjectController` and, since the link is made inside `POST /teams`,
 * by `TeamController::createTeam`. One mapping, so the wizard branches on
 * the same `code` whichever route refused the link.
 *
 * Status mapping for `OpenProjectException`:
 *
 *   module unlicensed / switched off (v4.9.16)                      403
 *   integration not installed / disabled / incompatible, no host   422
 *   user not connected, token rejected                              412
 *   permission denied                                               403
 *   project not found                                               404
 *   link made against another instance                              409
 *   OpenProject rate-limiting us                                    429
 *   OpenProject refused the input of a write (422 upstream)         400
 *   unreachable, server error, unrecognised response                502
 *
 * A refused write carries OpenProject's own sentence as `error` when it
 * gave one (v4.9.15) — "Subject can't be blank" is what the person has to
 * read to fix the form; the generic sentence is the fallback.
 *
 * `ProjectAlreadyLinkedException` is 409 with `code: project_already_linked`
 * and the team, named when the caller may know it. Everything else goes
 * through `ExceptionResponseTrait`, which the using class must also use.
 */
trait OpenProjectResponseTrait {

    abstract protected function exceptionResponse(\Throwable $e, string $fallbackMessage = 'Internal server error', array $context = []): JSONResponse;

    /** The sentences for each code — the using controller injects it. */
    abstract protected function openProjectMessages(): OpenProjectMessages;

    /**
     * An OpenProject failure becomes a classified response; everything else
     * goes through the shared trait. Nothing here ever answers 200 with an
     * error body.
     */
    protected function openProjectFailure(\Throwable $e, string $fallback, array $context = []): JSONResponse {
        if ($e instanceof ProjectAlreadyLinkedException) {
            return new JSONResponse([
                'error' => $e->getMessage(),
                'code'  => 'project_already_linked',
                'teams' => $e->getTeams(),
            ], Http::STATUS_CONFLICT);
        }
        if (!$e instanceof OpenProjectException) {
            return $this->exceptionResponse($e, $fallback, $context);
        }
        $code   = $e->getErrorCode();
        $status = match ($code) {
            // v4.9.16 — the module gate answers as the other licence gates in
            // the app do (My Work, File reviews): 403, `licenseGate: true`.
            OpenProjectException::MODULE_UNLICENSED,
            OpenProjectException::MODULE_DISABLED     => Http::STATUS_FORBIDDEN,
            OpenProjectException::INTEGRATION_NOT_INSTALLED,
            OpenProjectException::INTEGRATION_DISABLED,
            OpenProjectException::INTEGRATION_INCOMPATIBLE,
            OpenProjectException::HOST_NOT_CONFIGURED => Http::STATUS_UNPROCESSABLE_ENTITY,
            OpenProjectException::USER_NOT_CONNECTED,
            OpenProjectException::AUTH_FAILED         => Http::STATUS_PRECONDITION_FAILED,
            OpenProjectException::PERMISSION_DENIED   => Http::STATUS_FORBIDDEN,
            OpenProjectException::PROJECT_NOT_FOUND   => Http::STATUS_NOT_FOUND,
            OpenProjectException::LINK_STALE          => Http::STATUS_CONFLICT,
            OpenProjectException::RATE_LIMITED        => Http::STATUS_TOO_MANY_REQUESTS,
            OpenProjectException::VALIDATION_FAILED   => Http::STATUS_BAD_REQUEST,
            default                                   => Http::STATUS_BAD_GATEWAY,
        };
        return new JSONResponse($this->describeOpenProjectCode($code, $e->getUpstreamMessage()), $status);
    }

    /**
     * @param ?string $upstreamMessage OpenProject's own sentence, when the
     *                                 failure carried one — shown instead of
     *                                 the generic one, never beside it.
     * @return array{error: string, code: string, administratorMessage: string, licenseGate?: bool}
     */
    protected function describeOpenProjectCode(string $code, ?string $upstreamMessage = null): array {
        $messages = $this->openProjectMessages();
        $body = [
            'error'                => $upstreamMessage !== null && trim($upstreamMessage) !== ''
                ? trim($upstreamMessage)
                : $messages->userMessage($code),
            'code'                 => $code,
            'administratorMessage' => $messages->administratorMessage($code),
        ];
        if ($code === OpenProjectException::MODULE_UNLICENSED) {
            // The marker every other licence-gated endpoint in the app carries.
            $body['licenseGate'] = true;
        }
        return $body;
    }

    /**
     * The module gate for a controller method (v4.9.16): null when the
     * OpenProject module is licensed and switched on, otherwise the 403 the
     * method returns as-is. The services refuse too — the client throws the
     * same code on the first request — so this is the explicit line at the
     * edge, not the only one; it exists so a route that never reaches
     * OpenProject (a link read, a provisioning status) refuses the same way.
     */
    protected function openProjectModuleGate(): ?JSONResponse {
        $code = $this->openProjectModuleCode();
        if ($code === null) {
            return null;
        }
        return new JSONResponse($this->describeOpenProjectCode($code), Http::STATUS_FORBIDDEN);
    }

    /** `OpenProjectModuleService::unavailableCode()` — the using controller injects the service. */
    abstract protected function openProjectModuleCode(): ?string;
}
