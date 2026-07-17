<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\TeamTypeMapper;
use OCA\TeamHub\Exception\ValidationException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Per-team template label — 'collaboration' | 'project' | 'department'.
 *
 * Written once by CreateTeamView after team creation. Read by the Team info
 * widget (via the layout bundle) and by BrowseTeamsView (via the browse
 * endpoint). Absence of a row => null => no template label shown (legacy
 * teams predating this feature).
 */
class TeamTypeService {

    public const ALLOWED = ['collaboration', 'project', 'department'];

    public function __construct(
        private TeamTypeMapper   $mapper,
        private MemberService    $memberService,
        private IUserSession     $userSession,
        private LoggerInterface  $logger,
    ) {}

    /**
     * @return string|null Membership check is delegated to the caller — this
     *                     is invoked from LayoutController (already gated) and
     *                     from the type controller (gates explicitly).
     */
    public function getType(string $teamId): ?string {
        return $this->mapper->findTypeByTeam($teamId);
    }

    /** @param string[] $teamIds */
    public function getTypesForTeams(array $teamIds): array {
        return $this->mapper->findTypesByTeams($teamIds);
    }

    /**
     * Admin-gated writer. Validates the type against ALLOWED so a bad value
     * from a rogue caller can't land in the DB.
     */
    public function setType(string $teamId, string $type): string {
        $this->memberService->requireAdminLevel($teamId);
        if (!in_array($type, self::ALLOWED, true)) {
            throw new ValidationException('Invalid team type. Allowed: ' . implode(', ', self::ALLOWED));
        }
        $user = $this->userSession->getUser();
        $uid  = $user ? $user->getUID() : '';
        $this->mapper->upsert($teamId, $type, $uid);
        return $type;
    }
}
