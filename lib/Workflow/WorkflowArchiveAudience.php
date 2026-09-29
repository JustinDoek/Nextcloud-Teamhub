<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow;

/**
 * Who an archive projection is for (WorkflowHub phase 6, v4.10.21;
 * `docs/workflow-archiving.md`).
 *
 * A completed licensed workflow is recorded **once** — one
 * `teamhub_wf_archive` row over the instance, step, participant and event
 * rows that already exist. What differs per audience is not the record but
 * *how much of it is rendered*, and that is what an audience is: a named
 * reading of one record, with its own permission rule and its own filter.
 *
 * There are exactly two, because there are exactly two parties to a
 * request:
 *
 *   - **`requesting_team`** — the team that asked. It reads the request,
 *     the business outcome, the decision, the date, the reference, the
 *     documents marked requester-visible and the follow-up actions. It
 *     never reads an internal note, an internal document or the desk's
 *     technical actions, **whoever is asking** — the filter is a property
 *     of the audience, not of the viewer, so no role and no licence can
 *     widen it.
 *   - **`service_team`** — the desk that handled it. Everything the
 *     requesting team reads, plus the internal half: the agents, the
 *     processing steps, the internal notes, the escalations, the technical
 *     actions and the operational outcome. Only an eligible agent of that
 *     desk may ask for it; a Nextcloud administrator may not, for the same
 *     reason they may not read a live internal note (v4.10.20).
 *
 * A workflow no service team handled has no `service_team` projection at
 * all — absent, not empty.
 */
final class WorkflowArchiveAudience {

    /** The team that made the request. */
    public const REQUESTING_TEAM = 'requesting_team';

    /** The service team that handled it. Only exists when one did. */
    public const SERVICE_TEAM    = 'service_team';

    public const ALL = [self::REQUESTING_TEAM, self::SERVICE_TEAM];

    public static function isValid(string $audience): bool {
        return in_array($audience, self::ALL, true);
    }
}
