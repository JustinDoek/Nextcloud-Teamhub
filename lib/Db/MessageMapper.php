<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class MessageMapper {
    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    /**
     * Count non-pinned messages for a team (for pagination total).
     */
    public function countByTeamId(string $teamId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) AS cnt'))
            ->from('teamhub_messages')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('pinned', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Count question-type messages that have not been marked solved.
     * Used by the project-health widget's Quality pillar.
     */
    public function countUnsolvedQuestions(string $teamId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) AS cnt'))
            ->from('teamhub_messages')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('message_type', $qb->createNamedParameter('question')))
            ->andWhere($qb->expr()->eq('question_solved', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Find non-pinned messages by team ID (pinned message is returned separately).
     */
    public function findByTeamId(string $teamId, int $limit = 50, int $offset = 0): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('m.*', $qb->createFunction('COALESCE(c.comment_count, 0) AS comment_count'))
            ->from('teamhub_messages', 'm')
            ->leftJoin('m', $qb->createFunction(
                '(SELECT message_id, COUNT(*) as comment_count FROM oc_teamhub_comments GROUP BY message_id)'
            ), 'c', 'm.id = c.message_id')
            ->where($qb->expr()->eq('m.team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('m.pinned', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->orderBy('m.created_at', 'DESC')
            // v4.5.26 — tie-break. Two messages written in the same second had
            // no defined order, so paging could repeat or skip one; and
            // findStreamPosition() (which powers the deep link from "What's
            // new") can only compute a page if this order is total.
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $result = $qb->executeQuery();
        $messages = [];

        while ($row = $result->fetch()) {
            $messages[] = $this->rowToArray($row);
        }

        $result->closeCursor();
        return $messages;
    }

    /**
     * v4.5.26 — 0-based position of a message within its team's stream.
     *
     * The stream is `pinned = 0` ordered `created_at DESC, id DESC` (the same
     * order `findByTeamId` produces, tie-break included), so the position is
     * simply how many messages sort ahead of it. Used to answer "which page is
     * this message on" for a deep link from "What's new" — without it the
     * frontend would have to walk pages until it found the row.
     *
     * Returns -1 when the message is not in the team's stream at all: it was
     * deleted, it belongs to another team, or it is the **pinned** message
     * (which is rendered above the stream and has no page of its own).
     */
    public function findStreamPosition(string $teamId, int $messageId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('created_at', 'pinned')
            ->from('teamhub_messages')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1);
        $res = $qb->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();

        if (!$row || (int)($row['pinned'] ?? 0) === 1) {
            return -1;
        }
        $createdAt = (int)$row['created_at'];

        // Count what sorts strictly ahead of it under `created_at DESC, id DESC`:
        // anything newer, plus anything from the same second with a higher id.
        $cQb = $this->db->getQueryBuilder();
        $cQb->select($cQb->createFunction('COUNT(*) AS cnt'))
            ->from('teamhub_messages')
            ->where($cQb->expr()->eq('team_id', $cQb->createNamedParameter($teamId)))
            ->andWhere($cQb->expr()->eq('pinned', $cQb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($cQb->expr()->orX(
                $cQb->expr()->gt('created_at', $cQb->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT)),
                $cQb->expr()->andX(
                    $cQb->expr()->eq('created_at', $cQb->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT)),
                    $cQb->expr()->gt('id', $cQb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)),
                ),
            ));
        $cRes = $cQb->executeQuery();
        $cRow = $cRes->fetch();
        $cRes->closeCursor();

        return (int)($cRow['cnt'] ?? 0);
    }

    /**
     * Find the single pinned message for a team, or null if none.
     */
    public function findPinnedByTeamId(string $teamId): ?array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('m.*', $qb->createFunction('COALESCE(c.comment_count, 0) AS comment_count'))
            ->from('teamhub_messages', 'm')
            ->leftJoin('m', $qb->createFunction(
                '(SELECT message_id, COUNT(*) as comment_count FROM oc_teamhub_comments GROUP BY message_id)'
            ), 'c', 'm.id = c.message_id')
            ->where($qb->expr()->eq('m.team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('m.pinned', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return $row ? $this->rowToArray($row) : null;
    }

    /**
     * Find a single message by ID.
     */
    public function find(int $id): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('teamhub_messages')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if (!$row) {
            throw new \Exception('Message not found');
        }

        return $this->rowToArray($row);
    }

    /**
     * Create a new message.
     */
    public function create(string $teamId, string $authorId, string $subject, string $message, string $priority = 'normal', string $messageType = 'normal', ?array $pollOptions = null, bool $isPublic = false, bool $isSystem = false): array {
        $qb = $this->db->getQueryBuilder();
        $now = time();

        $values = [
            'team_id'      => $qb->createNamedParameter($teamId),
            'author_id'    => $qb->createNamedParameter($authorId),
            'subject'      => $qb->createNamedParameter($subject),
            'message'      => $qb->createNamedParameter($message),
            'priority'     => $qb->createNamedParameter($priority),
            'message_type' => $qb->createNamedParameter($messageType),
            'pinned'       => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
            // PARAM_INT (not PARAM_BOOL) — Postgres refuses 't'/'f' coercion
            // on SMALLINT via PARAM_BOOL (DESIGN §2.4).
            'is_public'    => $qb->createNamedParameter($isPublic ? 1 : 0, IQueryBuilder::PARAM_INT),
            // v4.5.26 — set only by the headless auto-post services. A system
            // post still carries a real author uid (DESIGN §2.47 Decision 5);
            // this flag is what the feed's "System messages" toggle reads.
            'is_system'    => $qb->createNamedParameter($isSystem ? 1 : 0, IQueryBuilder::PARAM_INT),
            'created_at'   => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'updated_at'   => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
        ];

        if ($messageType === 'poll' && $pollOptions) {
            $values['poll_options'] = $qb->createNamedParameter(json_encode($pollOptions));
        }

        $qb->insert('teamhub_messages')->values($values);
        $qb->executeStatement();
        $id = $this->db->lastInsertId('*PREFIX*teamhub_messages');

        return $this->find($id);
    }

    /**
     * Update a message.
     */
    public function update(int $id, string $subject, string $message): array {
        $qb = $this->db->getQueryBuilder();

        $qb->update('teamhub_messages')
            ->set('subject', $qb->createNamedParameter($subject))
            ->set('message', $qb->createNamedParameter($message))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();

        return $this->find($id);
    }

    /**
     * Delete a message.
     */
    public function delete(int $id): void {
        $qb = $this->db->getQueryBuilder();

        $qb->delete('teamhub_messages')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    /**
     * Unpin all messages for a team (call before setting a new pin).
     */
    public function unpinAllForTeam(string $teamId): void {
        $qb = $this->db->getQueryBuilder();

        $qb->update('teamhub_messages')
            ->set('pinned', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('pinned', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    /**
     * Pin a message. Caller must call unpinAllForTeam() first.
     */
    public function pin(int $id): array {
        $qb = $this->db->getQueryBuilder();

        $qb->update('teamhub_messages')
            ->set('pinned', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();

        return $this->find($id);
    }

    /**
     * Unpin a specific message.
     */
    public function unpin(int $id): array {
        $qb = $this->db->getQueryBuilder();

        $qb->update('teamhub_messages')
            ->set('pinned', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();

        return $this->find($id);
    }

    /**
     * Close a poll to prevent further voting.
     */
    public function closePoll(int $id): array {
        $qb = $this->db->getQueryBuilder();

        $qb->update('teamhub_messages')
            ->set('poll_closed', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();

        return $this->find($id);
    }

    /**
     * Mark a question as solved with a specific comment.
     */
    public function markQuestionSolved(int $id, int $commentId): array {
        $qb = $this->db->getQueryBuilder();

        $qb->update('teamhub_messages')
            ->set('question_solved', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
            ->set('solved_comment_id', $qb->createNamedParameter($commentId, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();

        return $this->find($id);
    }

    /**
     * Unmark a question as solved.
     */
    public function unmarkQuestionSolved(int $id): array {
        $qb = $this->db->getQueryBuilder();

        $qb->update('teamhub_messages')
            ->set('question_solved', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->set('solved_comment_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();

        return $this->find($id);
    }

    /**
     * Find aggregated messages from multiple teams.
     */
    public function findAggregated(array $teamIds, int $limit = 10): array {
        if (empty($teamIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('teamhub_messages')
            ->where($qb->expr()->in('team_id', $qb->createNamedParameter($teamIds, IQueryBuilder::PARAM_STR_ARRAY)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);

        $result = $qb->executeQuery();
        $messages = [];

        while ($row = $result->fetch()) {
            $messages[] = $this->rowToArray($row);
        }

        $result->closeCursor();
        return $messages;
    }

    /**
     * v4.2.13 — Fetch the "What’s new" personal feed page.
     *
     * Semantics:
     *   - Team messages: any message whose team_id is in $userTeamIds.
     *     Includes both non-public and public posts in those teams.
     *   - Public messages: any message with is_public=1, ANY team.
     *     Includes public posts from the user's own teams too, so
     *     toggling Team off + Public on still shows own-team public posts.
     *
     * When both toggles are on the WHERE becomes `team_id IN userTeams OR
     * is_public = 1`. Rows are unique so a message that satisfies both
     * clauses appears once. Frontend classifies each row's `source`
     * ('team' if team_id ∈ userTeams else 'public') for optional grouping,
     * but the Public badge is driven by isPublic — a public post in the
     * user's own team is badged public and still shown once.
     *
     * When both toggles are false the query returns [] without running.
     *
     * Callers are trusted to pass $userTeamIds already validated as the
     * viewer's own memberships — this mapper does not re-check auth.
     *
     * v4.5.26 — the Feed control rail's PERIOD / TEAMS / TYPES sections and
     * its System-messages and Mentions-only switches are all applied here, in
     * SQL, via the $filters bag. Filtering in PHP after the fact would have
     * been simpler to write and wrong: the merge stage slices to a page window,
     * so a filter applied afterwards would silently return short pages.
     *
     * @param array<string,mixed> $filters See buildFeedWhere() for the shape.
     */
    public function findFeed(array $userTeamIds, bool $includeTeam, bool $includePublic, int $limit, int $offset, array $filters = []): array {
        // Build WHERE on the same qb we'll execute — createNamedParameter
        // binds names per-qb, so a throwaway builder would leave stale
        // placeholders unbound.
        $qb = $this->db->getQueryBuilder();
        $where = $this->buildFeedWhere($userTeamIds, $includeTeam, $includePublic, $qb, $filters);
        if ($where === null) {
            return [];
        }
        $qb->select('*')
            ->from('teamhub_messages')
            ->where($where['expr'])
            ->orderBy('created_at', 'DESC')
            // Tie-break so two messages written in the same second keep a
            // stable order across pages — without it the OFFSET window can
            // repeat or skip a row between fetches.
            ->addOrderBy('id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $result = $qb->executeQuery();
        $messages = [];
        while ($row = $result->fetch()) {
            $messages[] = $this->rowToArray($row);
        }
        $result->closeCursor();
        return $messages;
    }

    /**
     * v4.2.13 — total row count matching the feed's WHERE clause. Used
     * so the frontend can render "Page N of M" and disable Next reliably.
     *
     * @param array<string,mixed> $filters See buildFeedWhere() for the shape.
     */
    public function countFeed(array $userTeamIds, bool $includeTeam, bool $includePublic, array $filters = []): int {
        $qb = $this->db->getQueryBuilder();
        $where = $this->buildFeedWhere($userTeamIds, $includeTeam, $includePublic, $qb, $filters);
        if ($where === null) {
            return 0;
        }
        $qb->select($qb->createFunction('COUNT(*) AS cnt'))
            ->from('teamhub_messages')
            ->where($where['expr']);
        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * v4.5.26 — comment counts for a set of message ids.
     *
     * `findByTeamId` gets its `comment_count` from a LEFT JOIN onto a
     * `createFunction('(SELECT … FROM oc_teamhub_comments …)')` subquery, which
     * hardcodes the `oc_` table prefix. That works on a default install and
     * silently returns nothing on one configured with another prefix. Rather
     * than copy the idiom into the feed, the counts come from one grouped query
     * over the page's ids — prefix-safe through the QueryBuilder, and bounded
     * because the page is at most 100 rows.
     *
     * @param int[] $messageIds
     * @return array<int,int> message id → comment count (ids with none are absent)
     */
    public function countCommentsForMessages(array $messageIds): array {
        if (empty($messageIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('message_id')
            ->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
            ->from('teamhub_comments')
            ->where($qb->expr()->in(
                'message_id',
                $qb->createNamedParameter($messageIds, IQueryBuilder::PARAM_INT_ARRAY),
            ))
            ->groupBy('message_id');

        $result = $qb->executeQuery();
        $out = [];
        while ($row = $result->fetch()) {
            $out[(int)$row['message_id']] = (int)$row['cnt'];
        }
        $result->closeCursor();
        return $out;
    }

    /**
     * Shared WHERE-clause builder for findFeed / countFeed.
     * Returns null when the WHERE would be trivially empty (both toggles
     * off, or Team-only with no team memberships) so the caller can skip
     * the query entirely.
     *
     * Pass a $qb to bind named parameters onto it. If omitted, a fresh
     * throwaway QB is used — safe for the null-check case where you only
     * want to know whether a query is worth running.
     *
     * $filters (v4.5.26, all optional):
     *   from          int    unix seconds, inclusive lower bound on created_at
     *   to            int    unix seconds, inclusive upper bound on created_at
     *   teamIds       string[] restrict to these teams. Intersected with the
     *                        visible set by the caller — see the note below.
     *   excludeTeamIds string[] drop these teams entirely (archived / deleted)
     *   messageTypes  string[] restrict to these `message_type` values
     *   includeSystem bool   false drops is_system = 1 rows (default true)
     *   mentionsUid   string  when non-empty, only messages whose body mentions
     *                        this uid. **Pre-filter only** — see below. Unused
     *                        since v4.5.28 (the Mentions switch became an
     *                        exclusion, which a LIKE cannot express safely);
     *                        kept because it is the correct shape for any
     *                        future caller that wants to *narrow* to mentions.
     *
     * **The team filter narrows, it can never widen.** It is ANDed onto the
     * membership/public OR-branch rather than replacing it, so asking for a
     * team you are not in returns that team's *public* messages and nothing
     * else — exactly what you could already see. The controller does not have
     * to sanitise the list for this to hold.
     *
     * @param array<string,mixed> $filters
     * @return array{expr: mixed}|null
     */
    private function buildFeedWhere(array $userTeamIds, bool $includeTeam, bool $includePublic, ?\OCP\DB\QueryBuilder\IQueryBuilder $qb = null, array $filters = []): ?array {
        // v4.5.31 — decisions are a third visibility branch, not a subset of
        // the team one.
        $includeDecisions = ($filters['includeDecisions'] ?? true) && !empty($userTeamIds);

        if (!$includeTeam && !$includePublic && !$includeDecisions) {
            return null;
        }
        $qb = $qb ?? $this->db->getQueryBuilder();

        $or = $qb->expr()->orX();
        if ($includeTeam && !empty($userTeamIds)) {
            $or->add($qb->expr()->in(
                'team_id',
                $qb->createNamedParameter($userTeamIds, IQueryBuilder::PARAM_STR_ARRAY),
            ));
        }
        if ($includePublic) {
            $or->add($qb->expr()->eq(
                'is_public',
                $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
            ));
        }
        if ($includeDecisions) {
            // **Its own branch, so the Open decisions switch is the only thing
            // that governs them.** Until v4.5.31 a decision reached the feed
            // only through the team branch, so turning Team messages off hid
            // every decision as well — two switches for one row, and the wrong
            // one winning.
            //
            // Still scoped to the caller's own teams: a decision is never
            // public (`createMessage` strips `is_public` for anything that is
            // not a plain message), so there is no second branch to widen to,
            // and this must not become a way to read another team's proposals.
            // Which decisions *survive* — only ones still open — is
            // MessageService::applyDecisionFilter's job; status lives in
            // another table.
            $or->add($qb->expr()->andX(
                $qb->expr()->eq('message_type', $qb->createNamedParameter('decision')),
                $qb->expr()->in(
                    'team_id',
                    $qb->createNamedParameter($userTeamIds, IQueryBuilder::PARAM_STR_ARRAY),
                ),
            ));
        }
        if ($or->count() === 0) {
            return null;
        }

        // Everything below is a narrowing AND on top of the visibility OR.
        $and = $qb->expr()->andX($or);

        $from = (int)($filters['from'] ?? 0);
        if ($from > 0) {
            $and->add($qb->expr()->gte('created_at', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)));
        }
        $to = (int)($filters['to'] ?? 0);
        if ($to > 0) {
            $and->add($qb->expr()->lte('created_at', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT)));
        }

        $teamIds = $filters['teamIds'] ?? [];
        if (is_array($teamIds) && !empty($teamIds)) {
            $and->add($qb->expr()->in(
                'team_id',
                $qb->createNamedParameter(array_values($teamIds), IQueryBuilder::PARAM_STR_ARRAY),
            ));
        }

        // v4.5.26 — teams that were archived or deleted. Applied to the whole
        // WHERE, not just the public branch, so it holds even if a membership
        // row somehow outlives its team. See
        // MessageService::resolveHiddenPublicTeamIds() for how the list is
        // built and why it is an exclusion rather than an allow-list.
        $excludeTeamIds = $filters['excludeTeamIds'] ?? [];
        if (is_array($excludeTeamIds) && !empty($excludeTeamIds)) {
            $and->add($qb->expr()->notIn(
                'team_id',
                $qb->createNamedParameter(array_values($excludeTeamIds), IQueryBuilder::PARAM_STR_ARRAY),
            ));
        }

        $types = $filters['messageTypes'] ?? [];
        if (is_array($types) && !empty($types)) {
            $and->add($qb->expr()->in(
                'message_type',
                $qb->createNamedParameter(array_values($types), IQueryBuilder::PARAM_STR_ARRAY),
            ));
        }

        if (($filters['includeSystem'] ?? true) === false) {
            $and->add($qb->expr()->eq('is_system', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        }

        $mentionsUid = (string)($filters['mentionsUid'] ?? '');
        if ($mentionsUid !== '') {
            // There is no mentions table to join — a mention exists only as
            // text in the body. NcRichContenteditable writes it two ways, and
            // both have to be looked for: bare `@<id>` when the id has no
            // space, colon or slash, and `@"<id>"` otherwise.
            //
            // This is a **narrowing pre-filter only**. SQL has no portable word
            // boundary, so `%@jaap%` also matches `@jaapt`;
            // MessageService::mentionsUser() re-checks every candidate by
            // tokenising the body properly. Do not treat this clause as the
            // boundary.
            //
            // escapeLikeParameter() neutralises % and _ in the uid — without it
            // a uid containing an underscore (common) would match any character
            // in that position, and one containing % would match far wider.
            // LOWER() on both sides: MySQL's default collation makes LIKE
            // case-insensitive and Postgres's does not, so without this the
            // Mentions-only switch behaved differently per database — and the
            // PHP check that follows is case-insensitive, so a case-sensitive
            // pre-filter would drop rows PHP would have accepted. No index is
            // lost; there is none on `message`.
            $escaped = mb_strtolower($this->db->escapeLikeParameter($mentionsUid));
            // Placeholders from createNamedParameter interpolated into a raw
            // fragment — the standard NC idiom when an expression needs a SQL
            // function on the column side. The *values* stay bound; only the
            // literal column name and LOWER() are inlined.
            $bare   = $qb->createNamedParameter('%@' . $escaped . '%');
            $quoted = $qb->createNamedParameter('%@"' . $escaped . '"%');
            $and->add('(LOWER(message) LIKE ' . $bare . ' OR LOWER(message) LIKE ' . $quoted . ')');
        }

        return ['expr' => $and];
    }

    /**
     * Find messages marked is_public across all teams. Used by the personal
     * aggregated feed (GET /api/v1/messages/public) so a member can see
     * public posts from teams they don't belong to.
     *
     * Callers must still exclude any team ids the user is already a member
     * of if they want to avoid a double-listing in the aggregated feed —
     * that scoping decision is caller-side, not baked in here.
     */
    public function findPublic(int $limit = 20, int $offset = 0, array $excludeTeamIds = []): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('teamhub_messages')
            ->where($qb->expr()->eq('is_public', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if (!empty($excludeTeamIds)) {
            $qb->andWhere($qb->expr()->notIn(
                'team_id',
                $qb->createNamedParameter($excludeTeamIds, IQueryBuilder::PARAM_STR_ARRAY)
            ));
        }

        $result = $qb->executeQuery();
        $messages = [];

        while ($row = $result->fetch()) {
            $messages[] = $this->rowToArray($row);
        }

        $result->closeCursor();
        return $messages;
    }

    /**
     * Get the Unix timestamp of the most recent message for a team (0 if none).
     */
    public function getLatestMessageTimestamp(string $teamId): int {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('MAX(created_at) as latest'))
            ->from('teamhub_messages')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return (int)($row['latest'] ?? 0);
    }

    /**
     * Full-text search across message subject and body.
     *
     * Also JOINs circles_circle to include the team display_name in each row,
     * so the search provider can show "Team Name" in the result subline without
     * a second query per result.
     *
     * Returns raw rows (not run through rowToArray) because the search provider
     * only needs a subset of fields and also needs team_name from the JOIN.
     *
     * @param string $term  The search term (LIKE %term%)
     * @param int    $limit Maximum rows to return from the DB
     * @param int    $offset Pagination offset (for cursor-based paging)
     * @return array<int, array{id:int, team_id:string, author_id:string, subject:string, message:string, team_name:string}>
     */
    public function search(string $term, int $limit = 30, int $offset = 0): array {

        $qb = $this->db->getQueryBuilder();

        $qb->select('m.id', 'm.team_id', 'm.author_id', 'm.subject', 'm.message', 'm.created_at')
            ->addSelect($qb->createFunction("COALESCE(cc.display_name, '') AS team_name"))
            ->from('teamhub_messages', 'm')
            ->leftJoin(
                'm',
                'circles_circle',
                'cc',
                $qb->expr()->eq('m.team_id', 'cc.unique_id')
            )
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->like(
                        $qb->createFunction('LOWER(m.subject)'),
                        $qb->createNamedParameter('%' . strtolower($term) . '%')
                    ),
                    $qb->expr()->like(
                        $qb->createFunction('LOWER(m.message)'),
                        $qb->createNamedParameter('%' . strtolower($term) . '%')
                    )
                )
            )
            ->orderBy('m.created_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $result = $qb->executeQuery();
        $rows   = [];

        while ($row = $result->fetch()) {
            $rows[] = [
                'id'        => (int)$row['id'],
                'team_id'   => (string)$row['team_id'],
                'author_id' => (string)$row['author_id'],
                'subject'   => (string)$row['subject'],
                'message'   => (string)$row['message'],
                'team_name' => (string)$row['team_name'],
                'created_at'=> (int)$row['created_at'],
            ];
        }

        $result->closeCursor();

        return $rows;
    }

    private function rowToArray(array $row): array {
        $pollOptions = null;
        if (!empty($row['poll_options'])) {
            $pollOptions = json_decode($row['poll_options'], true);
        }

        return [
            'id'              => (int)$row['id'],
            'team_id'         => $row['team_id'],
            'author_id'       => $row['author_id'],
            'subject'         => $row['subject'],
            'message'         => $row['message'],
            'priority'        => $row['priority'] ?? 'normal',
            'messageType'     => $row['message_type'] ?? 'normal',
            'pinned'          => (bool)($row['pinned'] ?? false),
            'isPublic'        => (bool)($row['is_public'] ?? false),
            'pollOptions'     => $pollOptions,
            'pollClosed'      => (bool)($row['poll_closed'] ?? false),
            'questionSolved'  => (bool)($row['question_solved'] ?? false),
            'solvedCommentId' => isset($row['solved_comment_id']) ? (int)$row['solved_comment_id'] : null,
            'isSystem'        => (bool)($row['is_system'] ?? false),
            'comment_count'   => (int)($row['comment_count'] ?? 0),
            'created_at'      => (int)$row['created_at'],
            'updated_at'      => (int)$row['updated_at'],
        ];
    }
}
