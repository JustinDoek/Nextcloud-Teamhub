<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\DecisionTeamService;
use OCA\TeamHub\Service\FileReviewService;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\TeamFileScopeService;
use OCA\TeamHub\Service\TeamService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * What TeamHub's entries in the Nextcloud Files ⋯ menu ask the server (v4.11.42).
 *
 * ## Why this is not on `FileReviewController`
 *
 * `scopes` here replaces `fileReview#getScopes`, which was the Files entry's
 * only call while *Request review* was its only action. *Propose a decision*
 * (v4.11.42) made that endpoint the wrong shape in two ways:
 *
 *  - **The gating differs per action.** File reviews need an active licence and
 *    a per-team switch; Decisions needs neither a licence nor anything beyond
 *    its own per-team switch. The old endpoint answered 403 on an unlicensed
 *    instance, which would have hidden a proposal entry that has no licence
 *    requirement. So the gate moved off the endpoint and onto each action: a
 *    team appears here with the actions it actually allows, and a team that
 *    allows neither is absent.
 *  - **One page load, one request.** The alternative was a second endpoint and
 *    a second GET on every Files page load for every user — on a page where
 *    TeamHub is a guest and the whole three-stage entry (`src/filesactions.js`)
 *    exists to keep that cost down. One call answers for both actions.
 *
 * `fileReview#reviewContext` stays where it is: it is about a review, it is
 * licensed like the rest of them, and the dialog that calls it is a review
 * dialog.
 *
 * ## Both methods are called from a page TeamHub does not render
 *
 * Which changes nothing about how they are gated. Each re-establishes the
 * caller from the session, asks `TeamService` which teams are theirs, and
 * resolves every file in that user's **own** folder tree — a file that does
 * not resolve there is a file they cannot see, and it drops out without a
 * special case. Neither method trusts a team id or a file id because it
 * arrived in a URL. Both are reads, and neither drops CSRF protection.
 */
class FileActionController extends Controller {

    use ExceptionResponseTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private TeamFileScopeService $fileScope,
        private FileReviewService $reviewService,
        private DecisionTeamService $decisionTeamService,
        private TeamService $teamService,
        private MemberService $memberService,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Team-folder path prefixes for the current user, with the actions each
     * team allows.
     *
     * One call per Files page load, and the only thing that makes a
     * synchronous `enabled()` callback possible in a file action: the entry
     * cannot ask the server whether the file under the cursor is in a team
     * folder, so the answer has to already be in memory when the menu opens.
     *
     * Shape per entry: `{ teamId, teamName, paths: string[], actions: string[] }`
     * where `actions` holds `review`, `propose`, or both. A team with no
     * action and a team with no folder are both omitted — the entry has
     * nothing to offer for either, and per CLAUDE.md § Permissions an action
     * that does not apply is hidden rather than shown and disabled. A team
     * appears with the actions it allows rather than with a flag per action:
     * the caller's only question is "may I offer this entry here".
     *
     * **The paths are root-relative** — `/Team Alpha`, not
     * `/alice/files/Team Alpha`. Internally TeamHub compares absolute paths,
     * because that is what `Node::getPath()` gives; the Files app's client-side
     * `Node.path` is relative to the user's files root. Converting here rather
     * than in the browser keeps the two representations from being confused in
     * the place where a wrong answer is silent: a prefix test that never
     * matches simply means the entry never appears.
     */
    #[NoAdminRequired]
    public function scopes(): JSONResponse {
        $uid = $this->currentUid();
        if ($uid === null) {
            return $this->unauthenticated();
        }

        try {
            // teamId => actions, for the teams that allow at least one.
            $allowed = [];
            $names   = [];
            foreach ($this->teamService->getUserTeams() as $team) {
                $teamId = (string)($team['id'] ?? '');
                if ($teamId === '') {
                    continue;
                }

                $actions = [];
                if ($this->reviewService->isEnabledForTeam($teamId)) {
                    $actions[] = 'review';
                }
                if ($this->decisionTeamService->isEnabledForTeam($teamId)) {
                    $actions[] = 'propose';
                }
                if ($actions === []) {
                    continue;
                }

                $allowed[$teamId] = $actions;
                $names[$teamId]   = (string)($team['name'] ?? '');
            }

            if ($allowed === []) {
                return new JSONResponse(['scopes' => []]);
            }

            $userFolder = $this->fileScope->userFolder($uid);
            if ($userFolder === null) {
                return new JSONResponse(['scopes' => []]);
            }

            $scopes = [];
            foreach ($this->fileScope->pathsByTeam($uid, array_keys($allowed), $userFolder) as $teamId => $paths) {
                $relative = [];
                foreach ($paths as $path) {
                    $rel = $userFolder->getRelativePath($path);
                    if ($rel !== null && $rel !== '') {
                        $relative[] = '/' . ltrim($rel, '/');
                    }
                }
                if ($relative === []) {
                    continue;
                }

                $scopes[] = [
                    'teamId'   => (string)$teamId,
                    'teamName' => $names[(string)$teamId] ?? '',
                    'paths'    => $relative,
                    'actions'  => $allowed[(string)$teamId],
                ];
            }

            return new JSONResponse(['scopes' => $scopes]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to load file action scopes', []);
        }
    }

    /**
     * What the deep link into the proposal composer needs about one file.
     *
     * The Files entry does not open a composer of its own — it opens TeamHub's,
     * which is the only place a decision is proposed (see
     * `ComposeDecisionModal`). So this answers the two questions the receiving
     * page cannot answer for itself: may this file be quoted in a proposal for
     * this team, and what is it called.
     *
     * `eligible: false` rather than an error when the answer is simply no: the
     * entry's prefix test can disagree with the server (a folder detached
     * between page load and click, the module switched off meanwhile), and that
     * is a sentence to render, not a fault to log. A caller who is not a member
     * of the team still gets the service's refusal — membership is checked
     * before the file is resolved.
     *
     * The file name is read from the server rather than carried in the deep
     * link on purpose. It ends up inside the proposal's Context field as
     * markdown, and a name that arrives in a URL is a name anybody can choose.
     */
    #[NoAdminRequired]
    public function proposeContext(string $teamId, ?int $fileId = null): JSONResponse {
        $uid = $this->currentUid();
        if ($uid === null) {
            return $this->unauthenticated();
        }

        $miss = ['eligible' => false, 'teamId' => $teamId, 'teamName' => '', 'fileName' => ''];

        try {
            $this->memberService->requireMemberLevel($teamId);

            // Optional in the signature so a call without it is the same "no"
            // as a file that does not resolve, rather than a dispatcher error.
            if ($fileId === null || $fileId <= 0) {
                return new JSONResponse($miss);
            }

            if (!$this->decisionTeamService->isEnabledForTeam($teamId)) {
                return new JSONResponse($miss);
            }

            // Resolved against the named team only, not against "whichever team
            // sorts first": a file can sit under two teams' folders at once, and
            // a proposal in team B must not be refused because team A happened
            // to match. `resolveFile()` also fails closed for a file this user
            // cannot see.
            $resolved = $this->fileScope->resolveFile($uid, [$teamId], $fileId);
            if ($resolved === null || $resolved['teamId'] !== $teamId) {
                return new JSONResponse($miss);
            }

            // One document, checked here and not only in the browser. Both
            // actions are about a single file — a folder has no answer to
            // "what are we deciding" -- and `singleFile()` in
            // `src/filesactionsBoot.js` already refuses one. That check lives
            // on a page TeamHub does not render, so it is a courtesy, not a
            // boundary: a hand-made `?propose=1&file=<folder id>` link reached
            // this far and seeded the composer with a link to a folder,
            // TeamHub's own `.system` among them (walked on the test instance,
            // 2026-10-10). Nothing was exposed — `resolveFile()` had already
            // confined the node to this user's own tree -- so this is the
            // one-document rule being enforced where it is load-bearing
            // rather than a leak being closed.
            if (!$resolved['node'] instanceof File) {
                return new JSONResponse($miss);
            }

            $teamName = '';
            foreach ($this->teamService->getUserTeams() as $team) {
                if ((string)($team['id'] ?? '') === $teamId) {
                    $teamName = (string)($team['name'] ?? '');
                    break;
                }
            }

            return new JSONResponse([
                'eligible' => true,
                'teamId'   => $teamId,
                'teamName' => $teamName,
                'fileName' => $resolved['node']->getName(),
            ]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to load the proposal context', [
                'teamId' => $teamId, 'fileId' => $fileId,
            ]);
        }
    }

    // ---------------------------------------------------------------------

    private function currentUid(): ?string {
        $user = $this->userSession->getUser();

        return $user === null ? null : $user->getUID();
    }

    private function unauthenticated(): JSONResponse {
        return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
    }
}
