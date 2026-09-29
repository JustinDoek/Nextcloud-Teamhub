<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow;

/**
 * How far a document attached to a workflow travels (WorkflowHub phase 6,
 * v4.10.21).
 *
 * **Every attachment carries one of these, and there is no default.** A
 * document with no classification would be a document whose audience is
 * decided by whoever renders it next, which is how an internal appraisal
 * ends up in the requester's archive. `WorkflowAttachmentService::attach()`
 * requires the value from the caller and refuses anything else; the column
 * is `notnull` with no database default for the same reason.
 *
 * The two values answer the only question an archive has to ask about a
 * file:
 *
 *   - **`requester`** — part of the answer the requesting team is owed: the
 *     signed form, the approved drawing, the report. Rendered in both
 *     projections.
 *   - **`internal`** — the desk's own working material: a supplier quote, a
 *     screenshot of a failing system, a colleague's assessment. Rendered in
 *     the `service_team` projection only, and dropped from the
 *     `requesting_team` projection by the audience filter rather than by a
 *     check on the viewer.
 *
 * Classification is decided when the document is attached and never
 * changes: an archived workflow's documents are immutable along with the
 * rest of its record. Attaching an `internal` document requires being an
 * eligible agent of the handling desk — a requester cannot classify their
 * own upload as internal, and could gain nothing by it if they did.
 *
 * Deliberately a separate vocabulary from `WorkflowEventType::VISIBILITY_*`
 * although both have two values today. An event's visibility is about the
 * history of the workflow; a document's is about a file in somebody's
 * Nextcloud. Folding them into one constant would make the next value
 * added for one of them silently legal for the other.
 */
final class WorkflowAttachmentVisibility {

    /** Shown to the requesting team as well as to the desk. */
    public const REQUESTER = 'requester';

    /** The service team's own material. Never leaves the desk's projection. */
    public const INTERNAL  = 'internal';

    public const ALL = [self::REQUESTER, self::INTERNAL];

    public static function isValid(string $visibility): bool {
        return in_array($visibility, self::ALL, true);
    }
}
