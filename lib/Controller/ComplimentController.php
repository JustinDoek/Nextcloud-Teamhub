<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\ComplimentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Compliments (v4.13.2, DESIGN §2.186).
 *
 * Routes:
 *   POST /api/v1/compliments        — pay one
 *   GET  /api/v1/compliments/unseen — what the caller has not been shown yet
 *   POST /api/v1/compliments/seen   — mark the ones just shown as shown
 *
 * There is no team id in any path, on purpose: a compliment is between two
 * people, and the team is provenance rather than scope. The authorisation
 * that matters — the two must share a team — is the service's, and it is
 * about the *pair*, which no `{teamId}` in a URL could express.
 *
 * Every method is the caller's own: the sender and the recipient are taken
 * from the session, never from the request body. `GET` reads and nothing
 * else; marking as seen is a POST because it writes.
 */
class ComplimentController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private ComplimentService $compliments,
        private IUserSession      $userSession,
        private LoggerInterface   $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * POST /api/v1/compliments
     *
     * Body: { to: string, note?: string, teamId?: string }
     */
    #[NoAdminRequired]
    public function send(): JSONResponse {
        $uid = $this->currentUid();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not signed in'], Http::STATUS_UNAUTHORIZED);
        }

        $to     = (string)($this->request->getParam('to', '') ?? '');
        $note   = (string)($this->request->getParam('note', '') ?? '');
        $teamId = (string)($this->request->getParam('teamId', '') ?? '');

        try {
            $this->compliments->send($uid, $to, $note, $teamId);
            return new JSONResponse(['status' => 'sent']);
        } catch (\InvalidArgumentException $e) {
            // Every refusal in the service is one the sender can act on —
            // wrong person, themselves, too many today — so the message is
            // passed through rather than replaced by a generic 400.
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub] ComplimentController send failed', ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not send the compliment'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET /api/v1/compliments/unseen
     *
     * Returns { total, ids, senders: [{ uid, displayName, subline, count,
     * notes: [{ note, createdAt }] }] } — grouped by sender, oldest first.
     *
     * Reading does not mark anything seen. The browser does that in a
     * separate POST once the popup has actually been shown, so a request
     * that is cancelled, or a tab that is closed before it paints, does not
     * swallow somebody's compliment.
     */
    #[NoAdminRequired]
    public function unseen(): JSONResponse {
        $uid = $this->currentUid();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not signed in'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            return new JSONResponse($this->compliments->unseenFor($uid));
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub] ComplimentController unseen failed', ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not load compliments'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /api/v1/compliments/seen
     *
     * Body: { ids: int[] }
     */
    #[NoAdminRequired]
    public function seen(): JSONResponse {
        $uid = $this->currentUid();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not signed in'], Http::STATUS_UNAUTHORIZED);
        }

        $raw = $this->request->getParam('ids', []);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            $raw = [];
        }

        try {
            // The UPDATE is scoped to the caller's own rows, so an id that is
            // not theirs matches nothing rather than being refused — which is
            // also why this needs no ownership check here.
            $marked = $this->compliments->markSeen($uid, array_slice($raw, 0, 100));
            return new JSONResponse(['marked' => $marked]);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub] ComplimentController seen failed', ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not update compliments'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    private function currentUid(): ?string {
        $user = $this->userSession->getUser();
        return $user !== null ? $user->getUID() : null;
    }
}
