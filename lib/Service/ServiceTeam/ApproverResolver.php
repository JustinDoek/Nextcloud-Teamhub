<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\ServiceTeam;

use OCA\TeamHub\Workflow\WorkflowActor;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Who approves a step of a service request (v4.20.58,
 * `docs/service-builder.md` § 14.4).
 *
 * Resolved once, when the request is made: the manager somebody has then,
 * the person they picked on the form then. Whoever it is, it is never the
 * requester: their own name, their own group membership and their own
 * account as a fallback do not count.
 */
class ApproverResolver {

    /** A person the team named in the builder. */
    public const SOURCE_USER    = 'user';
    /** Everybody in a group the team named; the first answer decides. */
    public const SOURCE_GROUP   = 'group';
    /** The requester's manager, as their account says. */
    public const SOURCE_MANAGER = 'manager';
    /** The person the requester picked in a field of the request form. */
    public const SOURCE_FIELD   = 'field';

    public const SOURCES = [self::SOURCE_USER, self::SOURCE_GROUP, self::SOURCE_MANAGER, self::SOURCE_FIELD];

    /** The sources that can come up empty, and so need somebody to fall back on. */
    public const NEEDS_FALLBACK = [self::SOURCE_MANAGER, self::SOURCE_FIELD];

    /** How many members of a group are looked at to find one who may answer. */
    private const GROUP_PROBE = 25;

    public function __construct(
        private IUserManager  $userManager,
        private IGroupManager $groupManager,
    ) {
    }

    public function personExists(string $uid): bool {
        return $uid !== '' && ($this->userManager->get($uid)?->isEnabled() ?? false);
    }

    public function groupExists(string $gid): bool {
        return $gid !== '' && $this->groupManager->groupExists($gid);
    }

    /**
     * @param array<string, mixed> $approver the document's `approver`
     * @param array<int, mixed> $form the request's answers (`data.form`)
     */
    public function resolve(array $approver, string $requesterUid, array $form): ?WorkflowActor {
        $id = (string)($approver['id'] ?? '');
        switch ((string)($approver['source'] ?? '')) {
            case self::SOURCE_USER:
                $candidates = [$id];
                break;
            case self::SOURCE_GROUP:
                if ($this->somebodyElseIn($id, $requesterUid)) {
                    return WorkflowActor::group($id);
                }
                $candidates = [];
                break;
            case self::SOURCE_MANAGER:
                $candidates = $this->userManager->get($requesterUid)?->getManagerUids() ?? [];
                break;
            case self::SOURCE_FIELD:
                $candidates = [];
                foreach ($form as $entry) {
                    if (is_array($entry) && ($entry['key'] ?? null) === $id
                        && ($entry['type'] ?? '') === RequestForm::TYPE_PERSON && is_string($entry['value'] ?? null)) {
                        $candidates[] = $entry['value'];
                    }
                }
                break;
            default:
                return null;
        }
        $candidates[] = (string)($approver['fallback']['id'] ?? '');
        foreach ($candidates as $uid) {
            if ((string)$uid !== $requesterUid && $this->personExists((string)$uid)) {
                return WorkflowActor::user((string)$uid);
            }
        }
        return null;
    }

    /** Whether somebody other than the requester could answer for the group. */
    private function somebodyElseIn(string $gid, string $requesterUid): bool {
        $group = $gid !== '' ? $this->groupManager->get($gid) : null;
        if ($group === null) {
            return false;
        }
        foreach ($group->searchUsers('', self::GROUP_PROBE) as $user) {
            if ($user instanceof IUser && $user->isEnabled() && $user->getUID() !== $requesterUid) {
                return true;
            }
        }
        return false;
    }
}
