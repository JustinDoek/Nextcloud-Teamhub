<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\LicenseService;
use OCA\TeamHub\Service\TeamExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Bulk team export (v4.6.14) — the read side of the CSV contract
 * {@see TeamImportController} writes.
 *
 * The same rule that file states applies here verbatim:
 * `#[AuthorizedAdminSetting]` on **every** method, with
 * `TeamExportService::requireNcAdmin()` re-checking inside the service. There
 * is no `#[NoAdminRequired]` in this file and there must never be one — every
 * response is a list of who is in which team, across teams the caller may not
 * be a member of.
 *
 * `#[NoCSRFRequired]` appears only on the two read-only GETs, matching the
 * importer's treatment of its own reads. The download is a GET as well, because
 * it is triggered by a plain navigation rather than by XHR: the browser has to
 * own the response for the file to land in the downloads folder.
 *
 * **Licensed feature (v4.6.28).** Every method here is behind `licenceGate()`,
 * the same ladder `MessageController::getPersonalFeed` and `FeedTalkController`
 * use: `none` and `grace` pass, `unlicensed` and `soft-lock` refuse with 403 +
 * `licenseGate`. The admin panel hides the section as well, but that is a
 * courtesy — this file is the boundary (SKILLS.md § Security standards). Bulk
 * *import* is deliberately not gated: an instance that has lapsed must still be
 * able to get its data in, and the asymmetry is the point.
 */
class TeamExportController extends Controller {

    use ExceptionResponseTrait;

    public function __construct(
        string                    $appName,
        IRequest                  $request,
        private TeamExportService $exportService,
        private LicenseService    $licenseService,
        private LoggerInterface   $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/v1/admin/export/teams/selectable
     *
     * Every exportable team, for the panel's multiselect. Not paginated — a
     * picker that only knows about the first page cannot select from the rest.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function selectable(): JSONResponse {
        if (($gate = $this->licenceGate()) !== null) {
            return $gate;
        }
        try {
            return new JSONResponse(['teams' => $this->exportService->listSelectableTeams()]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to load the team list');
        }
    }

    /**
     * POST /api/v1/admin/export/teams/preview
     * Body: { "teamIds": ["…"] } — omit or send [] for every team.
     *
     * What the download would contain, and which rows could not be re-imported,
     * without producing the file or writing an audit event.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function preview(array $teamIds = []): JSONResponse {
        if (($gate = $this->licenceGate()) !== null) {
            return $gate;
        }
        try {
            return new JSONResponse($this->exportService->preview($this->cleanIds($teamIds)));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to build the export preview');
        }
    }

    /**
     * GET /api/v1/admin/export/teams/download?teamIds[]=…
     *
     * The CSV. A GET so `window.location` can trigger it and the browser owns
     * the download; the selection therefore rides the query string, which is
     * why it carries team ids and nothing about people. Sending an empty
     * selection exports every team.
     *
     * Writes one `team.exported` audit event per team — this is the moment
     * member lists leave the instance as a file.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function download(array $teamIds = []): DataDownloadResponse|JSONResponse {
        // Before the service runs, so a locked instance writes no
        // `team.exported` audit events for a file it never produced.
        if (($gate = $this->licenceGate()) !== null) {
            return $gate;
        }
        try {
            $csv = $this->exportService->exportCsv($this->cleanIds($teamIds));

            return new DataDownloadResponse(
                $csv,
                $this->exportService->exportFilename(),
                'text/csv; charset=utf-8',
            );
        } catch (\Throwable $e) {
            // A failed download still answers JSON: the browser shows it rather
            // than saving a file full of an error message, which is the failure
            // mode of returning a 200 with error text as the body.
            return $this->exceptionResponse($e, 'Failed to build the export');
        }
    }

    /**
     * Bulk export is a licensed feature (v4.6.28). Same ladder as "What's new"
     * and the personal feed: `none` (active or trial) and `grace` are honoured,
     * `unlicensed` and `soft-lock` refuse.
     *
     * `licenseGate` in the body is what lets the client tell "your license does
     * not cover this" apart from a generic failure — the former needs a renew
     * call-to-action, not an error toast.
     *
     * @return JSONResponse|null the 403 to return, or null to proceed
     */
    private function licenceGate(): ?JSONResponse {
        $level = $this->licenseService->getEnforcementLevel();
        if ($level === 'none' || $level === 'grace') {
            return null;
        }
        return new JSONResponse([
            'error'            => 'Bulk team export requires an active TeamHub license.',
            'licenseGate'      => true,
            'enforcementLevel' => $level,
        ], Http::STATUS_FORBIDDEN);
    }

    /**
     * Query strings and JSON bodies both arrive loosely typed. Anything that is
     * not a non-empty string is dropped rather than coerced — a stray `0` from
     * a malformed array would otherwise become the team id `"0"` and widen the
     * selection by one nonexistent row.
     *
     * @param mixed $teamIds
     * @return list<string>
     */
    private function cleanIds(mixed $teamIds): array {
        if (!is_array($teamIds)) {
            return [];
        }
        $out = [];
        foreach ($teamIds as $id) {
            if (is_string($id) && trim($id) !== '') {
                $out[] = trim($id);
            }
        }
        return array_values(array_unique($out));
    }
}
