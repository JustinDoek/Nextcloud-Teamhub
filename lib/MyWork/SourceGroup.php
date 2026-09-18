<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork;

/**
 * The My Work source groups (v4.9.17).
 *
 * The source tabs used to be one per provider — nine of them by 4.9.15, and
 * three pairs said the same thing twice: *File approval* and *File reviews*
 * are both files; *Team admin* and *Team expiration* are both the housekeeping
 * a team's own admin owes it; *Team lifecycle* is what a Nextcloud
 * administrator owes the instance. Justin, 2026-09-15: cluster them — all
 * file work under **Files**, all team management under **Teams**, and
 * anything aimed at a Nextcloud administrator doing something in the
 * administration area (granting a longer expiration, enlarging a team
 * folder) under **Administration**. Deck, Decisions, Meetings and
 * OpenProject stay single tabs for now.
 *
 * Like `Category`, this is TeamHub's vocabulary, not any provider's: a
 * provider does not declare its group, the group names its members. A
 * provider not named here is its own tab. A group key is accepted wherever a
 * provider id is (`providerIds=files`) and expanded server-side, so a stored
 * preference, an API caller and the tab bar all mean the same thing.
 *
 * **Administration is for Nextcloud administrators** and its members are the
 * instance-scoped providers — the ones `MyWorkService` lets skip the
 * membership filter for an admin. They are not listed to anybody else at all
 * (`MyWorkService::describeProvidersForViewer()`), so the tab is hidden, not
 * shown at zero: a role-restricted surface is hidden, never disabled.
 *
 * Mirrored in `src/constants/myWork.js` (`SOURCE_GROUP*`). Keep in sync.
 */
final class SourceGroup {
    public const FILES          = 'files';
    public const TEAMS          = 'teams';
    public const ADMINISTRATION = 'administration';

    /** Group → the provider ids it clusters. Order within a group is display order. */
    public const MEMBERS = [
        self::FILES          => ['approval', 'file_review'],
        self::TEAMS          => ['teamadmin', 'teamexpiry_team'],
        self::ADMINISTRATION => ['teamexpiry_admin'],
    ];

    public static function isGroup(string $key): bool {
        return isset(self::MEMBERS[$key]);
    }

    /** The group a provider belongs to, or null when it stands alone. */
    public static function of(string $providerId): ?string {
        foreach (self::MEMBERS as $group => $members) {
            if (in_array($providerId, $members, true)) {
                return $group;
            }
        }
        return null;
    }

    /**
     * Provider ids for a list that may mix group keys and provider ids: each
     * group key becomes its members, each provider id stays, duplicates
     * collapse, order is kept.
     *
     * @param string[] $keys
     * @return string[]
     */
    public static function expand(array $keys): array {
        $out = [];
        foreach ($keys as $key) {
            foreach (self::MEMBERS[$key] ?? [$key] as $id) {
                if (!in_array($id, $out, true)) {
                    $out[] = $id;
                }
            }
        }
        return $out;
    }
}
