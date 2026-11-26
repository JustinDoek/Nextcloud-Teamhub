<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\ServiceTeam;

use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;

/**
 * The person a requester picked on a request form (v4.20.54,
 * `docs/service-builder.md` § 14.3): an enabled account they could also share
 * a file with.
 *
 * The picker searches through Nextcloud's own collaborator search, with every
 * restriction the administrator set. This is the same boundary for what is
 * *sent*: where sharing is limited to members of one's own groups, naming
 * somebody outside them is refused, so a form is not a way to look people up.
 *
 * v4.20.58 — the same boundary for who may be named as an approver: by a team
 * admin in the builder, by whoever claimed a task, by an approver handing on.
 */
class RequestPersonResolver {

    public function __construct(
        private IUserManager  $userManager,
        private IUserSession  $userSession,
        private IGroupManager $groupManager,
        private IShareManager $shareManager,
    ) {
    }

    /** The account's display name, or null when the requester may not name it. */
    public function nameFor(string $uid): ?string {
        $person    = $this->userManager->get($uid);
        $requester = $this->userSession->getUser();
        if ($person === null || !$person->isEnabled() || $requester === null) {
            return null;
        }
        if ($this->shareManager->shareWithGroupMembersOnly() && $person->getUID() !== $requester->getUID()) {
            $shared = array_diff(
                array_intersect($this->groupManager->getUserGroupIds($requester), $this->groupManager->getUserGroupIds($person)),
                $this->shareManager->shareWithGroupMembersOnlyExcludeGroupsList(),
            );
            if ($shared === []) {
                return null;
            }
        }
        $name = $person->getDisplayName();
        return $name !== '' ? $name : $person->getUID();
    }

    /** The group's name, or null when the person asking may not share with it. */
    public function groupNameFor(string $gid): ?string {
        $group = $gid !== '' ? $this->groupManager->get($gid) : null;
        $asker = $this->userSession->getUser();
        if ($group === null || $asker === null || !$this->shareManager->allowGroupSharing()) {
            return null;
        }
        if ($this->shareManager->shareWithGroupMembersOnly() && !$group->inGroup($asker)) {
            return null;
        }
        $name = $group->getDisplayName();
        return $name !== '' ? $name : $group->getGID();
    }
}
