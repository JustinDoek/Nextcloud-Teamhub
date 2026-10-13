<?php

declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Exception\LicenseGateException;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\SubTeamModuleService;
use OCA\TeamHub\Service\SubTeamService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Sub-teams of a team (v4.12.2, DESIGN §2.175).
 *
 * ── Why every route is nested under the parent ──────────────────────────
 *
 * **The parent id is the authority.** A sub-team has no administration of its
 * own: its members, name, visibility and deletion are managed from the main
 * team's Manage team, and the main team's admins are the only administrators
 * it has. Putting `{teamId}` in the path means the gate is the first thing
 * every method does and the first thing a reader sees, rather than a fact
 * recovered from a body parameter.
 *
 * ── The creation gate is "admin of the parent", deliberately ────────────
 *
 * `TeamController::createTeam()` gates on
 * `MemberService::canCurrentUserCreateTeam()` — membership of the configured
 * `createTeamGroup`. That is **not** the gate here, and the difference is on
 * purpose: making a sub-team of a team you already administer is an act of
 * organising your own team, not of adding a team to the instance. A main team
 * admin who is outside `createTeamGroup` may still create sub-teams of their
 * own team; nobody else may create one at all.
 *
 * Stated rather than silently skipped, because an unexplained missing gate is
 * exactly the shape of the v4.10.26 bug — where a member outside
 * `createTeamGroup` could bypass "Request a new team" with one POST.
 *
 * Every method opens with its own `requireAdminLevel($teamId)`, and the
 * services behind them check again: a service is reachable from a job, a
 * command or a future controller.
 *
 * ── The module gate comes before the membership gate ────────────────────
 *
 * v4.12.3 — sub-teams are a licensed module with an administrator's switch
 * ({@see SubTeamModuleService}). `requireAvailable()` runs first in all three
 * methods, before the team is even looked at: whether the module exists is an
 * instance-wide fact that discloses nothing about this team, it is the cheaper
 * read of the two, and answering "sub-teams are switched off" to a team admin
 * is a better answer than "you are not an admin of a team you administer".
 * `LicenseGateException` carries `licenseGate: true` so the client offers
 * renewal rather than a validation error; the switched-off half is a plain
 * 403, since there is nothing for the caller to renew.
 *
 * Every mutation is CSRF-protected (no `#[NoCSRFRequired]`), no GET mutates,
 * and status codes come from `ExceptionResponseTrait` — so an
 * `AccessDeniedException` is a 403 and an `\InvalidArgumentException` a 400,
 * never a 200 with an error body.
 */
class SubTeamController extends Controller {

    use ExceptionResponseTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private SubTeamService $subTeamService,
        // v4.12.3 — the licence and the administrator's switch.
        private SubTeamModuleService $subTeamModule,
        private MemberService $memberService,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/v1/teams/{teamId}/sub-teams
     *
     * The parent's administrative view: every sub-team including the private
     * ones, with its visibility, member count and who created it.
     *
     * Admin-gated rather than member-gated, because a private sub-team's
     * existence is not something an ordinary member of the parent is told —
     * the sidebar is where a member learns about the sub-teams they are in or
     * that are listed to them.
     */
    #[NoAdminRequired]
    public function index(string $teamId): JSONResponse {
        try {
            $this->subTeamModule->requireAvailable();
            // The gate here as well as in the service. CLAUDE.md asks every
            // controller method to carry its own check: a service is reachable
            // from a job, a command or a future controller, and "the frontend
            // will not call this" is not a boundary.
            $this->memberService->requireAdminLevel($teamId);
            return new JSONResponse([
                'subTeams' => $this->subTeamService->listForParent($teamId),
            ]);
        } catch (\Throwable $e) {
            return $this->subTeamError($e, 'Failed to load sub-teams', ['teamId' => $teamId]);
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/sub-teams/candidates
     *
     * The parent's roster, for the member picker. Effective membership, so
     * somebody who reaches the main team through a group is offered: being in
     * the main team through an LDAP group is being in the main team.
     */
    #[NoAdminRequired]
    public function candidates(string $teamId): JSONResponse {
        try {
            $this->subTeamModule->requireAvailable();
            $this->memberService->requireAdminLevel($teamId);
            return new JSONResponse([
                'candidates' => $this->subTeamService->candidates($teamId),
            ]);
        } catch (\Throwable $e) {
            return $this->subTeamError($e, 'Failed to load candidates', ['teamId' => $teamId]);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/sub-teams
     * Body: {
     *   name, description?, templateKey?, profileKey?,
     *   visibility?: 'private' | 'listed',
     *   members?: ["uid", …],                       — picked from the parent
     *   outsiders?: [{ id, type? }, …]              — people who are not
     * }
     *
     * The two member lists are validated against the parent's roster in the
     * service, not here: a uid in the wrong list is a 400, because a main team
     * member arriving as a guest — or a stranger arriving as a colleague — is
     * not something to quietly correct.
     */
    #[NoAdminRequired]
    public function create(string $teamId): JSONResponse {
        $body = $this->request->getParams();
        try {
            $this->subTeamModule->requireAvailable();
            // The coarse gate. createSubTeam() then applies the strict one -
            // a **direct** admin row on the parent - because requireAdminLevel()
            // accepts an admin of a parent, which would let a sub-team's
            // administrator create a grandchild.
            $this->memberService->requireAdminLevel($teamId);
            $team = $this->subTeamService->createSubTeam($teamId, [
                'name'        => (string)($body['name'] ?? ''),
                'description' => (string)($body['description'] ?? ''),
                'templateKey' => (string)($body['templateKey'] ?? ''),
                'profileKey'  => (string)($body['profileKey'] ?? ''),
                'visibility'  => (string)($body['visibility'] ?? SubTeamService::DEFAULT_VISIBILITY),
                'members'     => isset($body['members']) && is_array($body['members'])
                    ? $body['members']
                    : [],
                'outsiders'   => isset($body['outsiders']) && is_array($body['outsiders'])
                    ? $body['outsiders']
                    : [],
            ]);
            return new JSONResponse($team, Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            return $this->subTeamError($e, 'Failed to create the sub-team', ['teamId' => $teamId]);
        }
    }

    /**
     * PUT /api/v1/teams/{teamId}/sub-teams/{subTeamId}
     * Body: {
     *   name?: string, description?: string,              — description v4.12.8
     *   visibility?: 'private' | 'listed',
     *   templateKey?: string, profileKey?: string          — v4.12.6
     * }
     *
     * The template and the policy are the parent administrator's to change,
     * although changing a *main* team's policy is a Nextcloud administrator's
     * act — a sub-team is the one exception, and DESIGN §2.180 is where that
     * decision and its cost are written down. The gate is the same
     * `requireAdminLevel()` every method here opens with; the service checks
     * it again and also that the sub-team belongs to this parent.
     *
     * PUT rather than PATCH although the body is partial: every other update
     * in this app is a PUT, and introducing one PATCH would be the only one.
     *
     * Both fields are optional and independent, so a dialog that changes one
     * does not have to resend the other — and sending neither is a no-op
     * rather than an error, which is what a form with no edits submits.
     *
     * `isset()` and not a truthiness test: `''` is a name the server must
     * refuse rather than silently skip.
     */
    #[NoAdminRequired]
    public function update(string $teamId, string $subTeamId): JSONResponse {
        $body = $this->request->getParams();
        try {
            $this->subTeamModule->requireAvailable();
            $this->memberService->requireAdminLevel($teamId);

            if (isset($body['name'])) {
                $this->subTeamService->rename($teamId, $subTeamId, (string)$body['name']);
            }
            if (isset($body['visibility'])) {
                $this->subTeamService->setVisibility($teamId, $subTeamId, (string)$body['visibility']);
            }
            // v4.12.8 — `''` is a description somebody may legitimately want
            // back, so `isset()` again rather than a truthiness test: clearing
            // it is a change, not a field left out of the request.
            if (isset($body['description'])) {
                $this->subTeamService->setDescription($teamId, $subTeamId, (string)$body['description']);
            }
            // v4.12.9 — `array_key_exists()` and not `isset()`, because `null`
            // is the value that **clears** the date and `isset(null)` is false.
            // The one field on this route where the two differ.
            if (array_key_exists('expiresOn', $body)) {
                $raw = $body['expiresOn'];
                $this->subTeamService->setExpiry(
                    $teamId,
                    $subTeamId,
                    $raw === null || $raw === '' ? null : (string)$raw,
                );
            }

            // v4.12.6 - the template and the policy, together, because they
            // are applied together and the service takes them in one call:
            // the template provisions and the profile governs, and a request
            // that changed one before validating the other would be able to
            // leave a sub-team carrying a template whose policy was refused.
            $classification = null;
            if (isset($body['templateKey']) || isset($body['profileKey'])) {
                $classification = $this->subTeamService->setClassification(
                    $teamId,
                    $subTeamId,
                    isset($body['templateKey']) ? (string)$body['templateKey'] : null,
                    isset($body['profileKey'])  ? (string)$body['profileKey']  : null,
                );
            }

            // The payload carries what the apps work did - added, removed, and
            // what has to be done by hand - so the dialog can report it. Absent
            // when neither key was sent, rather than an empty object: nothing
            // was asked and nothing is being reported.
            return new JSONResponse(array_filter([
                'success'        => true,
                'id'             => $subTeamId,
                'classification' => $classification,
            ], static fn ($v) => $v !== null));
        } catch (\Throwable $e) {
            return $this->subTeamError($e, 'Failed to update the sub-team', [
                'teamId' => $teamId, 'subTeamId' => $subTeamId,
            ]);
        }
    }

    /**
     * DELETE /api/v1/teams/{teamId}/sub-teams/{subTeamId}
     *
     * Deletes the sub-team and everything it holds — its own storage, modules
     * and messages. Irreversible, and the dialog in front of it says so.
     */
    #[NoAdminRequired]
    public function destroy(string $teamId, string $subTeamId): JSONResponse {
        try {
            $this->subTeamModule->requireAvailable();
            $this->memberService->requireAdminLevel($teamId);
            $this->subTeamService->destroy($teamId, $subTeamId);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return $this->subTeamError($e, 'Failed to delete the sub-team', [
                'teamId' => $teamId, 'subTeamId' => $subTeamId,
            ]);
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/sub-teams/{subTeamId}/members
     *
     * The sub-team's roster as its parent's administrators see it, each person
     * marked as picked from the main team or invited from outside.
     */
    #[NoAdminRequired]
    public function members(string $teamId, string $subTeamId): JSONResponse {
        try {
            $this->subTeamModule->requireAvailable();
            $this->memberService->requireAdminLevel($teamId);
            return new JSONResponse($this->subTeamService->members($teamId, $subTeamId));
        } catch (\Throwable $e) {
            return $this->subTeamError($e, 'Failed to load the sub-team members', [
                'teamId' => $teamId, 'subTeamId' => $subTeamId,
            ]);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/sub-teams/{subTeamId}/members
     * Body: { members?: ["uid", …], outsiders?: [{ id, type? }, …] }
     *
     * The same two lists creation takes, validated against the parent's roster
     * in the service for the same reason: a uid in the wrong list is a 400,
     * because a main team member arriving as a guest — or a stranger arriving
     * as a colleague — is not something to quietly correct.
     */
    #[NoAdminRequired]
    public function addMembers(string $teamId, string $subTeamId): JSONResponse {
        $body = $this->request->getParams();
        try {
            $this->subTeamModule->requireAvailable();
            $this->memberService->requireAdminLevel($teamId);
            $results = $this->subTeamService->addMembers(
                $teamId,
                $subTeamId,
                isset($body['members']) && is_array($body['members']) ? $body['members'] : [],
                isset($body['outsiders']) && is_array($body['outsiders']) ? $body['outsiders'] : [],
            );
            return new JSONResponse(['success' => true, 'results' => $results]);
        } catch (\Throwable $e) {
            return $this->subTeamError($e, 'Failed to add members to the sub-team', [
                'teamId' => $teamId, 'subTeamId' => $subTeamId,
            ]);
        }
    }

    /**
     * DELETE /api/v1/teams/{teamId}/sub-teams/{subTeamId}/members/{userId}
     *
     * The uid in the path, as `team#removeMember` already does for a team's
     * own members — one shape for "remove this person from this team", not two.
     */
    #[NoAdminRequired]
    public function removeMember(string $teamId, string $subTeamId, string $userId): JSONResponse {
        try {
            $this->subTeamModule->requireAvailable();
            $this->memberService->requireAdminLevel($teamId);
            $this->subTeamService->removeMember($teamId, $subTeamId, $userId);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return $this->subTeamError($e, 'Failed to remove the member', [
                'teamId' => $teamId, 'subTeamId' => $subTeamId,
            ]);
        }
    }

    /**
     * `ExceptionResponseTrait` with the licence gate in front of it (v4.12.3).
     *
     * The trait knows nothing about `LicenseGateException`, so without this it
     * would fall through to the generic 500 — an unlicensed instance would
     * report a server error instead of a licence. The shape is the one
     * `ServiceTeamController::serviceError()` and `WorkflowController` already
     * answer with, so a client handles one error for every licensed surface.
     *
     * @param array<string, mixed> $context
     */
    private function subTeamError(\Throwable $e, string $fallback, array $context = []): JSONResponse {
        if ($e instanceof LicenseGateException) {
            return new JSONResponse([
                'error'            => $e->getMessage(),
                'licenseGate'      => true,
                'enforcementLevel' => $e->getEnforcementLevel(),
            ], Http::STATUS_FORBIDDEN);
        }
        return $this->exceptionResponse($e, $fallback, $context);
    }
}
