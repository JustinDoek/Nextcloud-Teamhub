<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Removes a synced copy of a federated team once its owner instance has
 * deleted the original (v4.14.9, DESIGN §2.253).
 *
 * ## The problem this exists for
 *
 * A circle owned by another instance arrives here through Circles'
 * `RemoteService::syncRemoteCircle()` when one of our users is invited into
 * it. When the owner deletes it, Circles broadcasts `CircleDestroy` and we
 * drop our copy. When that broadcast never happens — the owner was offline
 * past the retry window, or the owner deleted the circle with a raw query
 * rather than through Circles — the copy stays **forever**. Nothing on this
 * side ever re-checks it: `MaintenanceService::removeCirclesWithNoOwner()`
 * only drops a circle whose *owner row* is missing locally, which a properly
 * synced copy still has.
 *
 * A stranded copy is not cosmetic. Its members still see the team, and every
 * action on it answers 409 *"Could not verify Team"*, because the owner no
 * longer has the circle and its `verifyCircle()` ends up asking itself for it.
 *
 * ## The rule, and why it is in this order (Justin, 2026-11-10)
 *
 *   1. Is the owner instance reachable? If not, **do nothing** — not for that
 *      team and not for any other team of that instance.
 *   2. Reachable: does the circle still exist there? Gone → drop our copy.
 *   3. Anything else → keep.
 *
 * The order is the whole safety property. A deleted circle and an unreachable
 * server look almost identical from here, and the consequence of confusing
 * them is deleting a live team on nothing more than a certificate renewal or
 * a five-minute outage. So absence is only ever believed when the owner
 * *answered*.
 *
 * In Circles' client that distinction is exactly the difference between
 * `CircleNotFoundException` and everything else.
 * `RemoteStreamService::resultRequestRemoteInstance()` throws for any non-200,
 * and `RemoteService::getCircleFromInstance()` raises
 * `CircleNotFoundException` only after a **200 with an empty body** — a
 * well-formed "no such circle" from a server that was there to say it. Every
 * transport, trust and signature failure surfaces as some other class, and
 * this service treats all of them as "unknown, keep".
 *
 * Because that negative answer is itself proof of reachability, the two gates
 * collapse into one probe per team rather than a separate liveness check.
 * They are still kept apart per *instance*: the first unknown answer from an
 * instance abandons the rest of its teams in the same pass, so a server that
 * goes down midway cannot have half its teams judged.
 *
 * ## Scope
 *
 * Only `source = 16` circles owned by another instance, and only while the
 * administrator has switched federated teams on. A circle TeamHub did not
 * create is not TeamHub's to delete, and an instance that does not use the
 * feature should not have a job quietly removing rows on its behalf.
 *
 * **This deletes rows Circles owns.** It is the one thing in TeamHub that
 * does so without Circles' API, and deliberately: there is no API for it.
 * Circles ships no command to purge a remote-owned copy, and its own destroy
 * is the owner's prerogative — calling it here would try to destroy the
 * original on the owner, which is the opposite of what is wanted.
 */
class FederatedTeamCleanupService {

    /**
     * Admin setting gating the whole federated-teams feature.
     *
     * v4.14.15 - the canonical definition moved to {@see FederatedTeamService},
     * which is where the other half of the switch now lives (what TeamHub
     * shows for a synced copy). Kept here as an alias so every existing
     * reference, this app's own and any test's, keeps reading one value.
     */
    public const CONFIG_KEY = FederatedTeamService::CONFIG_KEY;

    public function __construct(
        private IDBConnection      $db,
        private IConfig            $config,
        private ContainerInterface $container,
        private LoggerInterface    $logger,
    ) {
    }

    /** Has the administrator switched federated teams on? */
    public function isEnabled(): bool {
        return $this->config->getAppValue(Application::APP_ID, self::CONFIG_KEY, '0') === '1';
    }

    /**
     * One pass over every synced copy on this instance.
     *
     * @return array{checked:int, removed:int, kept:int, unreachable:string[], skipped:bool}
     */
    public function run(): array {
        $summary = [
            'checked'     => 0,
            'removed'     => 0,
            'kept'        => 0,
            'unreachable' => [],
            'skipped'     => false,
        ];

        if (!$this->isEnabled()) {
            $summary['skipped'] = true;
            return $summary;
        }

        $byInstance = $this->syncedCopiesByInstance();
        if ($byInstance === []) {
            return $summary;
        }

        foreach ($byInstance as $instance => $teamIds) {
            foreach ($teamIds as $teamId) {
                $summary['checked']++;

                $verdict = $this->ownerStillHasCircle($teamId, $instance);

                if ($verdict === null) {
                    // Rule 3: the owner did not answer. Nothing is decided
                    // about this team, and nothing is decided about the rest
                    // of this instance's teams either — abandon the instance
                    // for this pass rather than probing a server we have just
                    // established is not answering.
                    $summary['unreachable'][] = (string)$instance;
                    $summary['checked']--;
                    continue 2;
                }

                if ($verdict === true) {
                    $summary['kept']++;
                    continue;
                }

                $this->removeLocalCopy($teamId);
                $summary['removed']++;
                $this->logger->info('[TeamHub][FederatedTeamCleanupService] removed a synced copy of a team its owner has deleted', [
                    'teamId'   => $teamId,
                    'instance' => $instance,
                    'app'      => Application::APP_ID,
                ]);
            }
        }

        $summary['unreachable'] = array_values(array_unique($summary['unreachable']));
        return $summary;
    }

    /**
     * Every `source = 16` circle on this instance whose owner lives elsewhere,
     * grouped by that owner's instance.
     *
     * The owner row is what carries the instance — the circle row does not —
     * so this reads level 9 rather than the circle. A circle with no owner row
     * is not ours to judge: `removeCirclesWithNoOwner()` is Circles' own
     * answer to that one.
     *
     * @return array<string, string[]> instance => team ids
     */
    private function syncedCopiesByInstance(): array {
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('cm.circle_id', 'cm.instance')
            ->from('circles_member', 'cm')
            ->innerJoin('cm', 'circles_circle', 'c', $qb->expr()->eq('c.unique_id', 'cm.circle_id'))
            ->where($qb->expr()->eq('c.source', $qb->createNamedParameter(16, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('cm.level', $qb->createNamedParameter(9, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->neq('cm.instance', $qb->createNamedParameter('')))
            ->executeQuery();

        $byInstance = [];
        while ($row = $res->fetch()) {
            $instance = (string)($row['instance'] ?? '');
            $teamId   = (string)($row['circle_id'] ?? '');
            if ($instance === '' || $teamId === '') {
                continue;
            }
            $byInstance[$instance][$teamId] = true;
        }
        $res->closeCursor();

        $out = [];
        foreach ($byInstance as $instance => $ids) {
            $out[$instance] = array_keys($ids);
        }

        return $out;
    }

    /**
     * Ask the owner instance whether it still has the circle.
     *
     * @return bool|null true = still there, false = answered and gone,
     *                   null = no usable answer, decide nothing
     */
    private function ownerStillHasCircle(string $teamId, string $instance): ?bool {
        try {
            $remoteService = $this->container->get(\OCA\Circles\Service\RemoteService::class);
        } catch (\Throwable $e) {
            // Circles missing or not loadable: not an answer about any team.
            return null;
        }

        try {
            $remoteService->getCircleFromInstance($teamId, $instance);
            return true;
        } catch (\OCA\Circles\Exceptions\CircleNotFoundException $e) {
            // A 200 with an empty body: the owner was there and says no.
            return false;
        } catch (\Throwable $e) {
            // Everything else — network, signature, trust, an unexpected
            // status — is "we do not know", never "it is gone".
            $this->logger->debug('[TeamHub][FederatedTeamCleanupService] owner instance gave no usable answer', [
                'teamId'    => $teamId,
                'instance'  => $instance,
                'exception' => get_class($e),
                'app'       => Application::APP_ID,
            ]);
            return null;
        }
    }

    /**
     * Drop this instance's copy: the membership cache, the member rows, then
     * the circle. In that order, so a failure part way never leaves a circle
     * that members still resolve against.
     */
    private function removeLocalCopy(string $teamId): void {
        $msQb = $this->db->getQueryBuilder();
        $msQb->delete('circles_membership')
            ->where($msQb->expr()->eq('circle_id', $msQb->createNamedParameter($teamId)))
            ->executeStatement();

        $mQb = $this->db->getQueryBuilder();
        $mQb->delete('circles_member')
            ->where($mQb->expr()->eq('circle_id', $mQb->createNamedParameter($teamId)))
            ->executeStatement();

        $cQb = $this->db->getQueryBuilder();
        $cQb->delete('circles_circle')
            ->where($cQb->expr()->eq('unique_id', $cQb->createNamedParameter($teamId)))
            ->executeStatement();
    }
}
