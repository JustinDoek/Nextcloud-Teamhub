<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.9.9 — strip the provenance footer from news posts mirrored by 4.9.7.
 *
 * The first mirror (Version000409007's ledger) closed every copied post
 * with two paragraphs: "Posted in OpenProject by … in the project …" and
 * "Read it in OpenProject: <url>". Justin's review of 2026-09-14 replaced
 * them with a *Source: OpenProject* pill the card draws from the ledger,
 * so a post written since carries the summary alone. This step brings the
 * posts written before it into line: for every message the ledger names,
 * the paragraph holding that news item's URL (`/news/{id}`) and the one
 * before it are removed. The paragraphs are located by the URL, not by
 * the footer's wording, which was written in the author's language.
 *
 * Bounded by the ledger — a handful of rows on any instance that ran
 * 4.9.7 for a day, none on a fresh install. No schema change. Self-
 * contained: no `OCA\TeamHub\` constant or class (`/migrations` §2).
 */
class Version000409009Date20260914100000 extends SimpleMigrationStep {

    public function __construct(private IDBConnection $db) {
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('teamhub_op_news_mirror') || !$schema->hasTable('teamhub_messages')) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('message_id', 'news_id')
            ->from('teamhub_op_news_mirror')
            ->where($qb->expr()->gt('message_id', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        $result  = $qb->executeQuery();
        $ledger  = [];
        while ($row = $result->fetch()) {
            $ledger[(int)$row['message_id']] = (int)$row['news_id'];
        }
        $result->closeCursor();
        if ($ledger === []) {
            return;
        }

        $repaired = 0;
        foreach (array_chunk(array_keys($ledger), 200) as $ids) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'message')
                ->from('teamhub_messages')
                ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $id      = (int)$row['id'];
                $body    = (string)($row['message'] ?? '');
                $stripped = self::withoutFooter($body, $ledger[$id] ?? 0);
                if ($stripped === $body) {
                    continue;
                }
                $update = $this->db->getQueryBuilder();
                $update->update('teamhub_messages')
                    ->set('message', $update->createNamedParameter($stripped))
                    ->where($update->expr()->eq('id', $update->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                $update->executeStatement();
                $repaired++;
            }
            $result->closeCursor();
        }

        if ($repaired > 0) {
            $output->info('TeamHub: removed the OpenProject footer from ' . $repaired . ' mirrored news post(s).');
        }
    }

    /**
     * The body without the two footer paragraphs, or unchanged when the
     * URL paragraph is not there (already stripped, or written by 4.9.9).
     */
    private static function withoutFooter(string $body, int $newsId): string {
        if ($newsId <= 0 || $body === '') {
            return $body;
        }
        $paras  = explode("\n\n", $body);
        $needle = '/news/' . $newsId;
        $at     = null;
        foreach ($paras as $i => $para) {
            if (str_contains($para, $needle)) {
                $at = $i;
            }
        }
        if ($at === null) {
            return $body;
        }
        // The URL paragraph and the "Posted in …" paragraph before it.
        $from = $at > 0 ? $at - 1 : $at;
        array_splice($paras, $from, $at - $from + 1);
        return trim(implode("\n\n", $paras));
    }
}
