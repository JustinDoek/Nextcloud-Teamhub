<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCP\IUser;
use OCP\IUserManager;

/**
 * Every search for a person in TeamHub goes through here (v4.10.7).
 *
 * Before this class there were three ways to look somebody up — the
 * collaborator search behind the invite picker, `IUserManager::searchDisplayName()`
 * behind the admin pickers, Nextcloud's OCS autocomplete behind @mentions — and
 * four shapes of result row, every one of them a bare display name. This
 * class keeps the two sources that are legitimately different and makes the
 * *row* the same:
 *
 *     { id, type, displayName, subline }
 *
 * - `searchForTeam()` — what a member may see. Runs through
 *   `MemberService::searchUsers()`, i.e. Nextcloud's collaborator search with
 *   every enumeration and sharing restriction the administrator set, plus
 *   TeamHub's invite-type policy; returns users, groups, teams, email and
 *   federated candidates.
 * - `searchDirectory()` — what a Nextcloud administrator may see: the whole
 *   account directory, by display name **and** by uid, for the owner picker
 *   and the audit filter. Users only.
 *
 * The `subline` is {@see PersonSublineService}'s — job title · organisation
 * by default, the administrator's fields otherwise — so *which* Jari Feet is
 * answered the same way in every picker. Rows for a group, a team, an email
 * or a federated user carry no subline; the frontend labels those by type.
 */
class PersonSearchService {

    public function __construct(
        private MemberService        $memberService,
        private PersonSublineService $sublines,
        private IUserManager         $userManager,
    ) {}

    /**
     * People, groups, teams, email and federated candidates a member may
     * invite. Same filters as before; each user row gains its subline.
     *
     * @return array<int, array{id:string, type:string, displayName:string, subline:string, icon:string}>
     */
    public function searchForTeam(string $query, int $limit = 10, string $teamId = ''): array {
        $rows = $this->memberService->searchUsers($query, $limit, $teamId);

        $uids = [];
        foreach ($rows as $row) {
            if (($row['type'] ?? '') === 'user') {
                $uids[] = (string)$row['id'];
            }
        }
        $sublines = $this->sublines->sublinesFor($uids, false);

        foreach ($rows as &$row) {
            $row['subline'] = ($row['type'] ?? '') === 'user'
                ? ($sublines[(string)$row['id']] ?? '')
                : '';
        }
        unset($row);

        return $rows;
    }

    /**
     * Local accounts for a Nextcloud administrator's picker, matched on
     * display name or uid, private profile fields included.
     *
     * @return array<int, array{id:string, type:string, displayName:string, subline:string, icon:string}>
     */
    public function searchDirectory(string $query, int $limit = 10): array {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        /** @var array<string,IUser> $byUid */
        $byUid = [];
        foreach ($this->userManager->searchDisplayName($query, $limit) as $user) {
            $byUid[$user->getUID()] = $user;
        }
        if (count($byUid) < $limit) {
            foreach ($this->userManager->search($query, $limit) as $user) {
                $byUid[$user->getUID()] = $user;
                if (count($byUid) >= $limit) {
                    break;
                }
            }
        }

        $rows = [];
        foreach ($byUid as $uid => $user) {
            $rows[] = [
                'id'          => $uid,
                'type'        => 'user',
                'displayName' => $user->getDisplayName() ?: $uid,
                'subline'     => $this->sublines->sublineFor($user, true),
                'icon'        => 'user',
            ];
        }
        return $rows;
    }
}
