<?php
declare(strict_types=1);

namespace OCA\TeamHub\Integration;

/**
 * Implemented by an app that gives teams access to Team folders (group
 * folders) of its own, case files for example (v4.20.48).
 *
 * TeamHub discovers every Team folder a team can open and asks a team admin to
 * accept or ignore the ones it did not make. For a folder your app manages
 * there is nothing to decide: your app grants and withdraws the access.
 * Claiming it makes TeamHub show the folder as managed by your app, read-only,
 * and stop asking.
 *
 * Register the class with {@see ITeamHubManagedFolderRegistry} from your own
 * `Application::boot()`. TeamHub resolves it from the DI container when it
 * discovers a folder, so registering costs nothing on other requests.
 */
interface ITeamHubManagedFolderProvider {

    /**
     * Which of these Team folders does your app manage?
     *
     * Called while a team page loads, with every folder of that team TeamHub
     * has not accepted, and from the hourly job. Answer from your own tables
     * with one query and keep it well under 100 ms.
     *
     * The answer is about the folder, not about who is asking: in the hourly
     * job there is no current user. Do not filter for one.
     *
     * Throwing means "I cannot answer now": folders already shown as managed
     * by your app stay that way and nothing new is claimed. Returning a
     * shorter list means "no longer mine": those go back to the team admin's
     * review.
     *
     * Ids that were not asked about are ignored. So is a claim on a folder
     * TeamHub made, on the team's own team space, or on a folder a team admin
     * has already accepted.
     *
     * @param list<int> $folderIds ids as in the Team folders app
     * @return list<int> the ones your app manages
     */
    public function filterManagedFolders(array $folderIds): array;
}
