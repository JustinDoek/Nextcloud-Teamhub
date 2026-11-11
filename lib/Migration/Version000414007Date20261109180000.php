<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Log\LoggerInterface;

/**
 * v4.14.7 — member ids Nextcloud Teams refuses (DESIGN §2.249).
 *
 * `circles_member.member_id` is the member row's own identity, and Circles
 * validates every write of it in `CoreRequestBuilder::confirmValidId()`:
 * anything under 14 characters throws `InvalidIdException`, so Circles itself
 * can never store a short one.
 *
 * Four TeamHub paths could, because they insert the row by raw SQL and set
 * `member_id` to the bare uid — `MemberService::requestJoinTeam()`, and
 * `MaintenanceService::assignOwner()`, `::deleteOrphanedTeam()` and
 * `::repairMissingOwner()`, the last of which set no value at all. All four
 * are fixed from this version; this step repairs the rows they already wrote.
 *
 * ## Why it is worth a migration rather than only the Repair button
 *
 * The damage is invisible until the team is shared with another Nextcloud
 * server, and then it is invisible in the wrong direction: the remote runs
 * the owner row through `MemberRequest::insertOrUpdate()` inside
 * `RemoteService::syncRemoteCircle()`, where the throw is **not** caught,
 * answers the federated event with a 409 *"Could not verify Team"*, and
 * discards the exception. The inviting side reports success and nothing
 * arrives. An administrator has no way to connect the two, so leaving this to
 * a button they have to know to press is leaving it broken.
 *
 * ## `circles_token` goes with it
 *
 * That table keeps a copy of `member_id` and includes it in its unique index
 * `sicisimit` (`share_id, circle_id, single_id, member_id, token`). No foreign
 * key enforces the link, so rewriting one side alone would be accepted by the
 * database and quietly orphan the tokens. Each rewrite is matched on circle,
 * single id **and** the old value, so one member's tokens cannot pick up
 * another's. A row whose old value is the empty string gets no token update:
 * empty is `circles_token`'s own default, not a reference to this row.
 *
 * ## Scope
 *
 * `source = 16` only — user-created teams. A circle TeamHub did not create is
 * not TeamHub's to rewrite, however malformed it looks, and other apps'
 * circles reach their remotes through code that never had this bug.
 *
 * Rule compliance (`/migrations`): no schema change, so rule 1 (explicit
 * identifier names ≤30 chars) and rule 1b do not apply. **No `OCA\TeamHub\`
 * constant or class is referenced** (rule 2) — this is why the 14, the 31 and
 * the alphabet are literals here rather than `CirclesMemberId::MIN_LENGTH`
 * and friends, and why the generator is duplicated: a migration that imports
 * an application class stops being runnable the moment that class moves, and
 * `npm run check:migrations` gates every deploy on it. No `Doctrine\DBAL`
 * type is named (rule 2b) and there is no seed (rule 2a).
 *
 * The length test runs in PHP, not as a SQL `LENGTH()`. Both MySQL and
 * Postgres have the function, but a migration is the last place to spend a
 * portability question for the sake of reading a few more rows.
 */
class Version000414007Date20261109180000 extends SimpleMigrationStep {

    /** Circles' own threshold, from `CoreRequestBuilder::confirmValidId()`. */
    private const MIN_LENGTH = 14;

    /** What Circles generates, and the width of both `member_id` columns. */
    private const ID_LENGTH = 31;

    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    public function __construct(
        private IDBConnection  $db,
        private LoggerInterface $logger,
    ) {
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Circles may not be installed, and on a fresh install this step can
        // run before it is. Nothing to repair either way.
        if (!$schema->hasTable('circles_member')) {
            $output->info('[TeamHub 4.14.7] circles_member not present, nothing to repair');
            return;
        }
        $hasTokens = $schema->hasTable('circles_token');

        $output->info('[TeamHub 4.14.7] Scanning team member rows for member ids Nextcloud Teams would refuse…');

        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('cm.id', 'cm.circle_id', 'cm.single_id', 'cm.member_id', 'cm.level')
            ->from('circles_member', 'cm')
            ->innerJoin('cm', 'circles_circle', 'c', $qb->expr()->eq('c.unique_id', 'cm.circle_id'))
            ->where($qb->expr()->eq('c.source', $qb->createNamedParameter(16, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->executeQuery();

        $bad = [];
        while ($row = $res->fetch()) {
            $memberId = $row['member_id'] === null ? '' : (string)$row['member_id'];
            if (strlen($memberId) >= self::MIN_LENGTH) {
                continue;
            }
            $bad[] = [
                'id'       => (int)$row['id'],
                'circleId' => (string)$row['circle_id'],
                'singleId' => (string)($row['single_id'] ?? ''),
                'old'      => $memberId,
                'owner'    => ((int)$row['level'] === 9),
            ];
        }
        $res->closeCursor();

        if ($bad === []) {
            $output->info('[TeamHub 4.14.7] No member rows need repair');
            return;
        }

        $owners = 0;
        foreach ($bad as $row) {
            $new = $this->generateId();

            $updQb = $this->db->getQueryBuilder();
            $updQb->update('circles_member')
                ->set('member_id', $updQb->createNamedParameter($new))
                ->where($updQb->expr()->eq('id', $updQb->createNamedParameter($row['id'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            if ($hasTokens && $row['old'] !== '' && $row['singleId'] !== '') {
                $tokQb = $this->db->getQueryBuilder();
                $tokQb->update('circles_token')
                    ->set('member_id', $tokQb->createNamedParameter($new))
                    ->where($tokQb->expr()->eq('circle_id', $tokQb->createNamedParameter($row['circleId'])))
                    ->andWhere($tokQb->expr()->eq('single_id', $tokQb->createNamedParameter($row['singleId'])))
                    ->andWhere($tokQb->expr()->eq('member_id', $tokQb->createNamedParameter($row['old'])))
                    ->executeStatement();
            }

            if ($row['owner']) {
                $owners++;
            }
        }

        $output->info(sprintf(
            '[TeamHub 4.14.7] Repaired %d member row(s), %d of them a team owner',
            count($bad),
            $owners
        ));
        $this->logger->info('[Migration 4.14.7] Repaired invalid circles_member.member_id values', [
            'rows'   => count($bad),
            'owners' => $owners,
            'app'    => 'teamhub',
        ]);
    }

    /** A Circles-shaped member id. Duplicated from the app on purpose — see the class note. */
    private function generateId(): string {
        $alphabet = self::ALPHABET;
        $max      = strlen($alphabet) - 1;
        $id       = '';
        for ($i = 0; $i < self::ID_LENGTH; $i++) {
            $id .= $alphabet[random_int(0, $max)];
        }

        return $id;
    }
}
