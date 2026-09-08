<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\TeamImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Bulk team creation from the GUI table (v4.8.9).
 *
 * **A second front end onto `TeamImportService`, not a second importer.** The
 * rows a user types into the bulk table go through the same `normaliseRow()`
 * validation, the same durable run, the same chunked provisioning and the same
 * per-row results as a CSV upload. Writing a separate provisioning loop would
 * have made a third creation path — beside the wizard and the importer — that
 * has to stay in step with both, which is the failure mode this codebase has
 * already paid for more than once (`TeamTemplates` ↔ `CreateTeamView`, the My
 * Work vocabulary, `uiTokens.js` ↔ `widget-tokens.css`).
 *
 * **Why a separate controller from `TeamImportController`.** That one is
 * `#[AuthorizedAdminSetting]` on every method, and bulk create is not
 * administrator-only: it is licensed, and open to the team-creator group. Two
 * different gates on the same service belong on two controllers rather than in
 * a branch inside one.
 *
 * Every method here resolves to `TeamImportService`'s `$enforceNcAdmin = false`
 * path, which pairs the licence check with `canCurrentUserBulkCreateTeams()`
 * **and** requires the run to belong to the caller — "you may use this feature"
 * and "this run is yours" are different questions, and without the second a
 * permitted user could drive somebody else's run by guessing an id.
 *
 * The browser drives provisioning by calling `process` in a loop, exactly as
 * the CSV panel does: that runs inside a real session, which is what Circles
 * and the resource services read.
 */
class BulkTeamController extends Controller {

    use ExceptionResponseTrait;

    public function __construct(
        string                    $appName,
        IRequest                  $request,
        private TeamImportService $importService,
        private LoggerInterface   $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * POST /api/v1/teams/bulk/validate
     * Body: { rows: [ { name, description?, template, policy?, admin, members?, expires?, project_mode? } ] }
     *
     * v4.8.25 — `apps` and `modules` are gone from the row model. The table
     * never sent them; they were reachable only because `validateRows()`
     * flattens against `TeamImportService::COLUMNS`, and they left with that
     * constant. Apps and modules come from the template, filtered by the policy.
     *
     * Dry run. Creates the durable run and returns the preview — nothing is
     * provisioned until `start`.
     *
     * @param list<array<string,mixed>> $rows
     */
    #[NoAdminRequired]
    public function validateRows(array $rows = []): JSONResponse {
        try {
            return new JSONResponse($this->importService->validateRows($rows));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not check these rows.');
        }
    }

    /** GET /api/v1/teams/bulk/{importId} */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(int $importId): JSONResponse {
        try {
            return new JSONResponse($this->importService->getImport($importId, false));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not load that run.', ['importId' => $importId]);
        }
    }

    /** POST /api/v1/teams/bulk/{importId}/start */
    #[NoAdminRequired]
    public function start(int $importId): JSONResponse {
        try {
            return new JSONResponse($this->importService->start($importId, false));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not start creating these teams.', ['importId' => $importId]);
        }
    }

    /**
     * POST /api/v1/teams/bulk/{importId}/process
     *
     * One chunk. The browser calls this in a loop until the run reports
     * `completed`; closing the tab is survivable because the background job
     * adopts a run whose heartbeat goes quiet.
     */
    #[NoAdminRequired]
    public function process(int $importId, int $limit = TeamImportService::DEFAULT_CHUNK): JSONResponse {
        try {
            return new JSONResponse($this->importService->processNextChunk($importId, $limit, false));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not create the next teams.', ['importId' => $importId]);
        }
    }

    /** DELETE /api/v1/teams/bulk/{importId} */
    #[NoAdminRequired]
    public function destroy(int $importId): JSONResponse {
        try {
            return new JSONResponse($this->importService->discard($importId, false));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not discard that run.', ['importId' => $importId]);
        }
    }
}
