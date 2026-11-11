<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\Compliment;
use OCA\TeamHub\Db\ComplimentMapper;
use OCA\TeamHub\Db\TeamRegistryMapper;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Compliments — one person telling another they did well, with a note
 * (v4.13.2, DESIGN §2.186).
 *
 * ── What this is not ─────────────────────────────────────────────────────
 *
 * It is deliberately **not** a My Work workflow. `/mywork-workflows` is the
 * layer for anything one person asks, hands to, approves for or reports back
 * to another — every one of which leaves the other party owing an action. A
 * compliment asks for nothing: there is no reply, no approval, no finish and
 * nothing to track. Giving it a workflow would put a row in somebody's queue
 * that they can only dismiss, which is the opposite of the intent.
 *
 * It also raises **no Nextcloud notification** (Justin, 2026-10-16, with the
 * alternative on the table). The whole point is that it arrives as a moment
 * when the recipient next opens TeamHub, and a bell that names the sender
 * first spoils it. The accepted cost: somebody who does not open TeamHub for
 * a month does not learn about it for a month. Nothing is lost — `seen_at`
 * is NULL until they are actually shown it.
 *
 * ── Who may compliment whom ──────────────────────────────────────────────
 *
 * Only somebody you share a team with (Justin, 2026-10-16). The action is
 * offered on avatars inside TeamHub, so this matches what is reachable, and
 * it keeps a free-text note from being a message any account on the instance
 * can send any other. The rule is evaluated **when the compliment is sent**
 * and is not re-checked on read: a compliment does not become unsayable
 * because somebody later left the team.
 */
class ComplimentService {

    /** Longest note we store. Matches the column. */
    public const MAX_NOTE_LENGTH = 500;

    /**
     * How many compliments one person may pay the same person within
     * {@see RATE_WINDOW_SECONDS}. Not a security boundary — it is the
     * difference between a kind gesture and a way to fill somebody's screen
     * with fifty party popups.
     */
    public const RATE_LIMIT_PER_PAIR    = 5;
    public const RATE_WINDOW_SECONDS    = 86400;

    public function __construct(
        private ComplimentMapper   $mapper,
        private TeamRegistryMapper $registry,
        private IDBConnection      $db,
        private IUserManager       $userManager,
        private PersonSublineService $sublines,
        private LoggerInterface    $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Sending
    // -------------------------------------------------------------------------

    /**
     * Pay a compliment.
     *
     * @param string $senderUid    the caller; never taken from the request
     * @param string $recipientUid who it is for
     * @param string $note         what they wrote; may be empty
     * @param string $teamId       the team page it was sent from; '' when none
     *
     * @throws \InvalidArgumentException when the recipient is not somebody
     *                                  this sender may compliment, or the
     *                                  pair's daily limit is reached
     */
    public function send(string $senderUid, string $recipientUid, string $note, string $teamId = ''): void {

        $recipientUid = trim($recipientUid);

        if ($recipientUid === '' || $senderUid === '') {
            throw new \InvalidArgumentException('A compliment needs a sender and a recipient.');
        }

        if ($recipientUid === $senderUid) {
            // Not an error worth a stack trace, but it must not be stored:
            // the popup would tell somebody they had complimented themselves.
            throw new \InvalidArgumentException('You cannot compliment yourself.');
        }

        if (!$this->userManager->userExists($recipientUid)) {
            throw new \InvalidArgumentException('That account does not exist.');
        }

        if (!$this->shareATeam($senderUid, $recipientUid)) {
            throw new \InvalidArgumentException('You can only compliment people you share a team with.');
        }

        $since = $this->now() - self::RATE_WINDOW_SECONDS;
        if ($this->mapper->countFromSenderSince($senderUid, $recipientUid, $since) >= self::RATE_LIMIT_PER_PAIR) {
            throw new \InvalidArgumentException('You have sent this person several compliments today already.');
        }

        $compliment = new Compliment();
        $compliment->setRecipientUid($recipientUid);
        $compliment->setSenderUid($senderUid);
        $compliment->setTeamId($this->normaliseTeamId($teamId));
        $compliment->setNote($this->sanitiseNote($note));
        $compliment->setCreatedAt($this->now());
        $compliment->setSeenAt(null);

        $this->mapper->insert($compliment);

        // No user content in the log line, per CLAUDE.md § Debug logging —
        // the note is the whole point and it is nobody else's business.
        $this->logger->debug('[TeamHub][ComplimentService] compliment stored', [
            'teamId' => $compliment->getTeamId(),
            'app'    => Application::APP_ID,
        ]);
    }

    // -------------------------------------------------------------------------
    // Reading
    // -------------------------------------------------------------------------

    /**
     * Everything this person has not been shown yet, grouped by sender in the
     * order the first compliment from each arrived.
     *
     * Grouping happens here rather than in the browser because the shape is
     * what the popup renders: one avatar per person with their notes under
     * it, which is exactly Justin's "if multiple people did this since the
     * last visit it adds the user icons" plus "if multiple notes are sent
     * they are appended under the first one".
     *
     * @return array{total: int, ids: int[], senders: list<array{
     *             uid: string, displayName: string, subline: string,
     *             count: int, notes: list<array{note: string, createdAt: int}>
     *         }>}
     */
    public function unseenFor(string $recipientUid): array {
        $rows = $this->mapper->findUnseenFor($recipientUid);

        // adminContext: false - a field a sender set to private stays private,
        // the same rule every other person row in this app follows. Resolved
        // in one batch rather than per sender.
        $sublines = $this->sublinesFor(array_map(
            static fn (Compliment $r): string => $r->getSenderUid(),
            $rows,
        ));

        $ids     = [];
        $senders = [];

        foreach ($rows as $row) {
            $ids[]  = $row->getId();
            $sender = $row->getSenderUid();

            if (!isset($senders[$sender])) {
                $senders[$sender] = [
                    'uid'         => $sender,
                    'displayName' => $this->displayName($sender),
                    'subline'     => $sublines[$sender] ?? '',
                    'count'       => 0,
                    'notes'       => [],
                ];
            }

            $senders[$sender]['count']++;

            if ($row->getNote() !== '') {
                $senders[$sender]['notes'][] = [
                    'note'      => $row->getNote(),
                    'createdAt' => $row->getCreatedAt(),
                ];
            }
        }

        return [
            'total'   => count($rows),
            'ids'     => $ids,
            'senders' => array_values($senders),
        ];
    }

    /**
     * Mark compliments as shown. Scoped to the recipient in the UPDATE, so a
     * caller cannot clear somebody else's by guessing ids.
     *
     * @param int[] $ids
     *
     * @return int rows marked
     */
    public function markSeen(string $recipientUid, array $ids): int {
        return $this->mapper->markSeen($recipientUid, $ids, $this->now());
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * Do these two people share at least one TeamHub team?
     *
     * One query, and three things in it are deliberate:
     *
     *  - `circles_membership` rather than `circles_member`, so somebody who
     *    reaches a team through a group or a parent circle counts. Direct-only
     *    would refuse half the members of a group-provisioned team.
     *  - the join to `teamhub_team_registry`, so only a *team* counts.
     *    Without it, two people in the same Nextcloud group would pass on the
     *    hidden group circle Circles creates, and every account on the
     *    instance would pass on anything else they happen to share.
     *  - the two single_ids are resolved first and separately. Both users'
     *    own personal circles are in that table, but they are different
     *    circles and neither is registered, so neither can match.
     *
     * Fails closed: anything unexpected means "no".
     *
     * `protected` as a test seam, the same shape {@see now()} uses: the rule
     * this enforces is unit-tested by overriding it, while the query itself
     * is proven against a real Circles schema on the instance. There is no
     * mock of `circles_membership` worth trusting.
     */
    protected function shareATeam(string $senderUid, string $recipientUid): bool {
        try {
            $senderSingle    = $this->resolveSingleId($senderUid);
            $recipientSingle = $this->resolveSingleId($recipientUid);

            if ($senderSingle === null || $recipientSingle === null) {
                return false;
            }

            $qb = $this->db->getQueryBuilder();
            $qb->select('a.circle_id')
                ->from('circles_membership', 'a')
                ->innerJoin('a', 'circles_membership', 'b', $qb->expr()->eq('b.circle_id', 'a.circle_id'))
                ->innerJoin('a', TeamRegistryMapper::TABLE, 'r', $qb->expr()->eq('r.team_id', 'a.circle_id'))
                ->where($qb->expr()->eq('a.single_id', $qb->createNamedParameter($senderSingle)))
                ->andWhere($qb->expr()->eq('b.single_id', $qb->createNamedParameter($recipientSingle)))
                ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            return $row !== false;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ComplimentService] shared-team lookup failed', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
            return false;
        }
    }

    /**
     * A user's Circles single_id, read from the preferences row Circles
     * writes. The table is `oc_preferences` on every supported Nextcloud
     * version — never probe for `user_preferences`, which does not exist and
     * whose failed query aborts a Postgres transaction (HANDOFF, 2026-09-28).
     */
    private function resolveSingleId(string $uid): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('configvalue')
            ->from('preferences')
            ->where($qb->expr()->eq('userid', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter('circles')))
            ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('userSingleId')))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if ($row !== false && !empty($row['configvalue'])) {
            return (string)$row['configvalue'];
        }

        // Fallback: the user's own single circle, found through their direct
        // member row. Same join MemberService::resolveUserSingleId falls back
        // to, for an account that predates the preference being written.
        $qb = $this->db->getQueryBuilder();
        $qb->select('ms.single_id')
            ->from('circles_membership', 'ms')
            ->innerJoin('ms', 'circles_member', 'm', $qb->expr()->andX(
                $qb->expr()->eq('m.circle_id', 'ms.single_id'),
                $qb->expr()->eq('m.user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)),
            ))
            ->where($qb->expr()->eq('m.user_id', $qb->createNamedParameter($uid)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return ($row !== false && !empty($row['single_id'])) ? (string)$row['single_id'] : null;
    }

    /**
     * Collapse whitespace, strip control characters and cut to the column's
     * length. The note is rendered as text — never `v-html` — so this is
     * hygiene rather than the XSS boundary.
     */
    private function sanitiseNote(string $note): string {
        $note = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $note) ?? '';
        // Keep single newlines: somebody writing two sentences on two lines
        // means it. Collapse runs of three or more to two.
        $note = preg_replace("/\n{3,}/", "\n\n", str_replace("\r\n", "\n", $note)) ?? '';
        $note = trim($note);

        if (mb_strlen($note) > self::MAX_NOTE_LENGTH) {
            $note = mb_substr($note, 0, self::MAX_NOTE_LENGTH);
        }

        return $note;
    }

    /**
     * A team id is provenance only, so an unregistered or malformed one is
     * dropped rather than refused — the compliment itself is still valid.
     */
    private function normaliseTeamId(string $teamId): string {
        $teamId = trim($teamId);
        if ($teamId === '' || strlen($teamId) > 64) {
            return '';
        }
        try {
            return $this->registry->exists($teamId) ? $teamId : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function displayName(string $uid): string {
        $user = $this->userManager->get($uid);
        return $user !== null ? $user->getDisplayName() : $uid;
    }

    /**
     * @param string[] $uids
     *
     * @return array<string,string>
     */
    private function sublinesFor(array $uids): array {
        try {
            return $this->sublines->sublinesFor($uids, false);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Seam for tests. Everything stored here is epoch seconds in UTC.
     */
    protected function now(): int {
        return time();
    }
}
