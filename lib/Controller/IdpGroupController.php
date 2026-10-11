<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\IdpGroupRequestService;
use OCA\TeamHub\Service\IdpTeamProvisionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Directory groups waiting for a team (v4.11.32, DESIGN §2.166).
 *
 * The surface for `idpTeamsMode = review`: the administrator sees which
 * groups qualify and decides one at a time. In `auto` mode nothing waits here
 * and the list is empty; in `off` mode the feature does not run at all.
 *
 * **Admin-only, both ways.** Unlike the adoption grid — which also lives on a
 * service team and is therefore `#[NoAdminRequired]` with the decision in the
 * service — this is instance provisioning configuration and has exactly one
 * home, Admin → TeamHub. So every method carries
 * `#[AuthorizedAdminSetting]` **and** {@see requireNcAdmin()} re-checks in
 * this class: the attribute admits a *delegated* TeamHub admin, and creating
 * teams unattended from a directory is a full-administrator decision. Never
 * rely on "the frontend won't call this".
 *
 * Thin by design: input in, the service's answer out.
 */
class IdpGroupController extends Controller {

    use ExceptionResponseTrait;

    public function __construct(
        string                          $appName,
        IRequest                        $request,
        private IdpTeamProvisionService $provisioning,
        private IdpGroupRequestService  $requests,
        private IGroupManager           $groupManager,
        private IUserSession            $userSession,
        private LoggerInterface         $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/v1/admin/idp-groups — the groups waiting for a decision.
     *
     * v4.11.39 — no longer a history: what has been decided is in the
     * Compliance tab's audit log (DESIGN §2.172).
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function index(): JSONResponse {
        return $this->run(
            fn (string $uid): array => $this->provisioning->reviewQueue(),
            'Failed to load directory groups',
        );
    }

    /**
     * POST /api/v1/admin/idp-groups/{id}/create — make the team for one
     * waiting group, now.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function create(int $id, string $templateKey = '', string $profileKey = ''): JSONResponse {
        return $this->run(
            // v4.11.39 — through the request service, so the administrators'
            // My Work task for this group is withdrawn once it is decided.
            fn (string $uid): array => $this->requests->accept($id, $uid, $templateKey, $profileKey),
            'Failed to create the team',
        );
    }

    /**
     * POST /api/v1/admin/idp-groups/{id}/decline — never offer this group
     * again.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function decline(int $id, string $reason = ''): JSONResponse {
        return $this->run(
            function (string $uid): array {
                $this->requests->decline($id, $uid, $reason);
                return ['declined' => true];
            },
            'Failed to decline the group',
        );
    }

    /**
     * The real boundary. `#[AuthorizedAdminSetting]` admits a delegated
     * TeamHub admin; this does not.
     *
     * @return string the acting uid
     * @throws \RuntimeException when the caller is not a Nextcloud admin
     */
    private function requireNcAdmin(): string {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new \RuntimeException('Not authenticated');
        }
        if (!$this->groupManager->isAdmin($user->getUID())) {
            throw new \RuntimeException('Nextcloud admin privilege required');
        }
        return $user->getUID();
    }

    /**
     * @param callable(string): array<string,mixed> $work
     */
    private function run(callable $work, string $failure): JSONResponse {
        try {
            return new JSONResponse($work($this->requireNcAdmin()));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, $failure, ['app' => 'teamhub']);
        }
    }
}
