<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCA\TeamHub\Constants\TeamApps;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The one answer to "which apps does this team actually have" (v4.8.27).
 *
 * ── The question had three answers before this class ─────────────────────
 *
 * Manage Team read `teamhub_team_apps`, found nothing, and fell back to
 * `enabled = true` for every installed app — so it showed a default and called
 * it the team's state. The policy integration allow-list read the same empty
 * table and therefore never found a single finding. Only
 * `teamhub_team_app_resources` knew the truth, and nothing that needed to
 * compare a team against a template or a profile was reading it.
 *
 * That is not a table that happens to be empty on one install. Resource-backed
 * apps stopped being written to `teamhub_team_apps` when resources became
 * registry-driven — `ResourceConnectController`'s docblock records it — and the
 * two readers that predate that change were never repointed.
 *
 * ── The rule ─────────────────────────────────────────────────────────────
 *
 * An app is present when the registry holds an **active** row for it, or when
 * it is toggle-only and `teamhub_team_apps` says it is on. An explicit
 * `enabled = 0` **overrides a live resource row**: a team admin who switched
 * Deck off has said what they mean, and a registry row that outlived the toggle
 * is the stale one. That precedence is the only judgement call in this class
 * and it is the reason both queries are read rather than just the registry.
 *
 * **Set queries, never per team** — `TRACK-F2-DESIGN.md` §5.2's rule, because
 * the compliance sweep and template propagation both walk the whole estate.
 */
class TeamAppPresenceMapper {

    /** Matches `PolicyObservationMapper::CHUNK`; same reasoning. */
    private const CHUNK = 500;

    public function __construct(private IDBConnection $db) {}

    /**
     * Canonical app ids each team has, keyed by team id.
     *
     * A team with none is absent from the result rather than present with an
     * empty list, matching every other set reader in the policy layer.
     *
     * @param list<string> $teamIds
     * @return array<string, list<string>>
     */
    public function presenceForTeams(array $teamIds): array {
        if ($teamIds === []) {
            return [];
        }

        $fromRegistry = $this->activeRegistryApps($teamIds);
        $toggles      = $this->toggleStates($teamIds);

        $out = [];
        foreach ($teamIds as $teamId) {
            $present = $fromRegistry[$teamId] ?? [];

            foreach ($toggles[$teamId] ?? [] as $appId => $enabled) {
                if ($enabled) {
                    if (!in_array($appId, $present, true)) {
                        $present[] = $appId;
                    }
                    continue;
                }
                // Switched off explicitly. Wins over a registry row — see the
                // class docblock.
                $present = array_values(array_diff($present, [$appId]));
            }

            if ($present !== []) {
                $out[$teamId] = $present;
            }
        }

        return $out;
    }

    /**
     * Canonical app ids each team has **that TeamHub provisioned itself**.
     *
     * Bounds what template propagation is allowed to switch off. A resource
     * that arrived through discovery belongs to whoever made it in Deck or
     * Talk; a template edit must not be able to take it away.
     *
     * @param list<string> $teamIds
     * @return array<string, list<string>>
     */
    public function teamHubCreatedForTeams(array $teamIds): array {
        return $this->activeRegistryApps($teamIds, TeamApps::ORIGIN_CREATED);
    }

    /**
     * Active registry rows, optionally narrowed to one origin.
     *
     * @param list<string> $teamIds
     * @return array<string, list<string>>
     */
    private function activeRegistryApps(array $teamIds, ?string $origin = null): array {
        $out = [];

        foreach (array_chunk($teamIds, self::CHUNK) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->selectDistinct('team_id')
                ->addSelect('app_id')
                ->from('teamhub_team_app_resources')
                ->where($qb->expr()->in(
                    'team_id',
                    $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY),
                ))
                ->andWhere($qb->expr()->eq(
                    'status',
                    $qb->createNamedParameter('active'),
                ));

            if ($origin !== null) {
                $qb->andWhere($qb->expr()->eq('origin', $qb->createNamedParameter($origin)));
            }

            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $teamId = (string)$row['team_id'];
                $appId  = TeamApps::canonical((string)$row['app_id']);
                if (!in_array($appId, $out[$teamId] ?? [], true)) {
                    $out[$teamId][] = $appId;
                }
            }
            $result->closeCursor();
        }

        return $out;
    }

    /**
     * Stored on/off toggles per team.
     *
     * Both states are returned, because an explicit off is information: it is
     * what distinguishes "never had it" from "turned it off".
     *
     * @param list<string> $teamIds
     * @return array<string, array<string, bool>>
     */
    private function toggleStates(array $teamIds): array {
        $out = [];

        foreach (array_chunk($teamIds, self::CHUNK) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('team_id', 'app_id', 'enabled')
                ->from('teamhub_team_apps')
                ->where($qb->expr()->in(
                    'team_id',
                    $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY),
                ));

            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $teamId  = (string)$row['team_id'];
                $appId   = TeamApps::canonical((string)$row['app_id']);
                $enabled = (bool)$row['enabled'];

                // Two spellings can collapse onto one canonical id — an install
                // that ran an older version may hold a `spreed` row beside a
                // `talk` one. **An explicit off wins**, deterministically:
                // switching an app off is a decision somebody made, and the
                // alternative is a result that depends on row order.
                if (array_key_exists($appId, $out[$teamId] ?? [])) {
                    $enabled = $enabled && $out[$teamId][$appId];
                }

                $out[$teamId][$appId] = $enabled;
            }
            $result->closeCursor();
        }

        return $out;
    }
}
