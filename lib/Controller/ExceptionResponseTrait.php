<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\AppNotAvailableException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Shared exception-to-JSONResponse mapping for controllers.
 *
 * Replaces the substring-matched status-code detection
 * (`str_contains($e->getMessage(), 'member') ? 403 : 500`) that used to
 * live in every catch block. That pattern silently reclassified errors
 * whenever a message got rephrased or localised — see security.md H-2.
 *
 * Callers pass their logger + optional context so unexpected exceptions
 * still land in the NC log with structured metadata.
 *
 * Usage:
 *
 *     try {
 *         return new JSONResponse($this->service->doStuff($teamId));
 *     } catch (\Throwable $e) {
 *         return $this->exceptionResponse($e, 'Failed to do stuff', ['teamId' => $teamId]);
 *     }
 */
trait ExceptionResponseTrait {

    /**
     * Map a caught exception to a JSONResponse.
     *
     * Rules:
     *   AccessDeniedException → 403, message passed through (user-facing).
     *   NotFoundException      → 404, message passed through.
     *   ValidationException    → 400, message passed through.
     *   Anything else          → 500, generic $fallbackMessage, exception
     *                             logged at error level with $context.
     */
    protected function exceptionResponse(
        \Throwable $e,
        string $fallbackMessage = 'Internal server error',
        array $context = [],
    ): JSONResponse {
        if ($e instanceof AccessDeniedException) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }
        if ($e instanceof NotFoundException) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
        if ($e instanceof AppNotAvailableException) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        }
        if ($e instanceof ValidationException) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
        // Native \InvalidArgumentException is the PHP-idiomatic
        // validation signal. Treat it like ValidationException so
        // services that raise SPL exceptions get the right status
        // without having to migrate every throw site.
        if ($e instanceof \InvalidArgumentException) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        if (isset($this->logger)) {
            $this->logger->error(
                '[TeamHub] Unexpected exception in ' . static::class,
                array_merge($context, [
                    'exception' => $e,
                    'app'       => \OCA\TeamHub\AppInfo\Application::APP_ID,
                ]),
            );
        }
        return new JSONResponse(['error' => $fallbackMessage], Http::STATUS_INTERNAL_SERVER_ERROR);
    }
}
