<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject;

/**
 * Raw OpenProject HAL+JSON → the shapes TeamHub's frontend is built against
 * (v4.9.3).
 *
 * This is the DTO layer. Nothing past this class sees an OpenProject
 * response: a Vue component receives `subject`, `type`, `status`, `dueDate`,
 * and never `_links.status.title`. When OpenProject renames a field, this file
 * changes and nothing else does.
 *
 * Two rules every method follows:
 *
 *   - **A field we cannot read reliably is null, never a guess.** A work
 *     package without a due date has `dueDate: null`, not an empty string or
 *     today; a project whose status link is absent has `status: null`. The
 *     frontend hides what is null rather than rendering a zero.
 *   - **Everything is plain text.** OpenProject descriptions are Markdown and
 *     carry an `html` rendering; neither reaches the frontend. `excerpt()`
 *     reduces them to a short plain string, and the components interpolate it
 *     as text, so no OpenProject content can ever become markup in TeamHub.
 *
 * All methods are static and pure so they can be unit-tested against
 * recorded responses without a container.
 */
final class OpenProjectNormalizer {

    /** Longest description excerpt the overview shows. */
    public const EXCERPT_LENGTH = 240;

    /**
     * A project resource → the overview's project block.
     *
     * @param array<string, mixed> $raw
     * @return array{
     *   id: int, identifier: string, name: string, active: bool, public: bool,
     *   status: ?array{code: string, label: string}, statusExplanation: ?string,
     *   description: ?string, createdAt: ?string, updatedAt: ?string,
     *   canCreateWorkPackage: bool, canEditProject: bool, linkable: bool
     * }
     */
    public static function project(array $raw): array {
        $statusHref  = self::linkHref($raw, 'status');
        $statusLabel = self::linkTitle($raw, 'status');
        $status      = null;
        if ($statusHref !== null && $statusLabel !== null) {
            $status = ['code' => self::trailingSegment($statusHref), 'label' => $statusLabel];
        }

        return [
            'id'                   => (int)($raw['id'] ?? 0),
            'identifier'           => self::identifier((string)($raw['identifier'] ?? '')),
            'name'                 => self::text((string)($raw['name'] ?? '')),
            'active'               => (bool)($raw['active'] ?? true),
            'public'               => (bool)($raw['public'] ?? false),
            'status'               => $status,
            'statusExplanation'    => self::excerpt(self::formattable($raw['statusExplanation'] ?? null)),
            'description'          => self::excerpt(self::formattable($raw['description'] ?? null)),
            'createdAt'            => self::isoOrNull($raw['createdAt'] ?? null),
            'updatedAt'            => self::isoOrNull($raw['updatedAt'] ?? null),
            // OpenProject includes this link only for users with "add work
            // packages" on the project. Its presence is the permission check.
            'canCreateWorkPackage' => self::linkHref($raw, 'createWorkPackage') !== null
                || self::linkHref($raw, 'createWorkPackageImmediately') !== null,
            'canEditProject'       => self::canEditProject($raw),
            'linkable'             => self::isLinkable($raw),
        ];
    }

    /**
     * Does OpenProject let this user administer the project. The project
     * resource carries `update` / `updateImmediately` links only for users
     * with the "edit project" permission — the permission a project
     * administrator has and an ordinary member does not. That link is the
     * API's own answer; role names are configurable per instance and are not
     * consulted.
     *
     * @param array<string, mixed> $raw
     */
    public static function canEditProject(array $raw): bool {
        return self::linkHref($raw, 'update') !== null
            || self::linkHref($raw, 'updateImmediately') !== null;
    }

    /**
     * May this user link the project to a team: they administer it in
     * OpenProject, or it is public (Justin, 2026-09-12).
     *
     * @param array<string, mixed> $raw
     */
    public static function isLinkable(array $raw): bool {
        return self::canEditProject($raw) || (bool)($raw['public'] ?? false);
    }

    /**
     * A project resource → the compact row the project picker shows.
     *
     * @param array<string, mixed> $raw
     * @return array{id: int, identifier: string, name: string, active: bool, public: bool, canEditProject: bool, linkable: bool}
     */
    public static function projectSummary(array $raw): array {
        return [
            'id'             => (int)($raw['id'] ?? 0),
            'identifier'     => self::identifier((string)($raw['identifier'] ?? '')),
            'name'           => self::text((string)($raw['name'] ?? '')),
            'active'         => (bool)($raw['active'] ?? true),
            'public'         => (bool)($raw['public'] ?? false),
            'canEditProject' => self::canEditProject($raw),
            'linkable'       => self::isLinkable($raw),
        ];
    }

    /**
     * A work package resource → one row of a work list.
     *
     * `date` is a milestone's single date; ordinary work packages carry
     * `startDate`/`dueDate` instead. Both are passed through as-is so the
     * caller can pick.
     *
     * v4.9.7 — the ids behind the four links (`typeId`, `statusId`,
     * `priorityId`, `assigneeId`) and the author's name joined the shape, so
     * the aggregation can ask OpenProject's own lists "is this status
     * closed", "how does this priority rank", "is this type a milestone"
     * without guessing from a label. Additive; nothing that read the 4.9.3
     * shape changes.
     *
     * @param array<string, mixed> $raw
     * @return array{
     *   id: int, subject: string, type: ?string, typeId: ?int, status: ?string, statusId: ?int,
     *   priority: ?string, priorityId: ?int, assignee: ?string, assigneeId: ?int,
     *   responsible: ?string, author: ?string, project: ?array{id: ?int, name: string},
     *   startDate: ?string, dueDate: ?string, date: ?string, percentageDone: ?int,
     *   createdAt: ?string, updatedAt: ?string
     * }
     */
    public static function workPackage(array $raw): array {
        $projectName = self::linkTitle($raw, 'project');
        $projectId   = self::linkId($raw, 'project');

        return [
            'id'             => (int)($raw['id'] ?? 0),
            'subject'        => self::text((string)($raw['subject'] ?? '')),
            'type'           => self::linkTitle($raw, 'type'),
            'typeId'         => self::linkId($raw, 'type'),
            'status'         => self::linkTitle($raw, 'status'),
            'statusId'       => self::linkId($raw, 'status'),
            'priority'       => self::linkTitle($raw, 'priority'),
            'priorityId'     => self::linkId($raw, 'priority'),
            'assignee'       => self::linkTitle($raw, 'assignee'),
            'assigneeId'     => self::linkId($raw, 'assignee'),
            'responsible'    => self::linkTitle($raw, 'responsible'),
            'author'         => self::linkTitle($raw, 'author'),
            'project'        => $projectName !== null ? ['id' => $projectId, 'name' => $projectName] : null,
            'startDate'      => self::dateOrNull($raw['startDate'] ?? null),
            'dueDate'        => self::dateOrNull($raw['dueDate'] ?? null),
            'date'           => self::dateOrNull($raw['date'] ?? null),
            'percentageDone' => isset($raw['percentageDone']) && is_numeric($raw['percentageDone'])
                ? (int)$raw['percentageDone']
                : null,
            'createdAt'      => self::isoOrNull($raw['createdAt'] ?? null),
            'updatedAt'      => self::isoOrNull($raw['updatedAt'] ?? null),
        ];
    }

    /**
     * A news item → what the What's new card and the mirrored team message
     * carry (v4.9.7, Phase 3 — Justin's review, 2026-09-14: the feed is for
     * news and messages, not work-package edits).
     *
     * `summary` is OpenProject's own short line; `excerpt` is the first
     * NEWS_EXCERPT_LENGTH characters of the body as plain text, for a news
     * item without a summary. News is written to be read by the whole
     * project, so unlike a work-package comment its text may travel — but
     * only as plain text, never as markup.
     *
     * @param array<string, mixed> $raw
     * @return array{
     *   id: int, title: string, summary: ?string, excerpt: ?string,
     *   author: ?string, authorId: ?int, project: ?array{id: ?int, name: string},
     *   createdAt: ?string
     * }
     */
    public static function news(array $raw): array {
        $projectName = self::linkTitle($raw, 'project');
        $summary     = self::excerpt(is_string($raw['summary'] ?? null) ? $raw['summary'] : null, self::NEWS_EXCERPT_LENGTH);
        return [
            'id'        => (int)($raw['id'] ?? 0),
            'title'     => self::text((string)($raw['title'] ?? '')),
            'summary'   => $summary,
            'excerpt'   => self::excerpt(self::formattable($raw['description'] ?? null), self::NEWS_EXCERPT_LENGTH),
            'author'    => self::linkTitle($raw, 'author'),
            'authorId'  => self::linkId($raw, 'author'),
            'project'   => $projectName !== null ? ['id' => self::linkId($raw, 'project'), 'name' => $projectName] : null,
            'createdAt' => self::isoOrNull($raw['createdAt'] ?? null),
        ];
    }

    /**
     * A meeting → one row of the Upcoming events widget (v4.9.7). Times are
     * instants (ISO 8601 with zone) as OpenProject sends them; the widget
     * renders them in the reader's zone like every timed calendar event.
     * `durationHours` comes from the ISO 8601 duration OpenProject uses
     * (`PT1H30M`); `endTime` is preferred when present.
     *
     * @param array<string, mixed> $raw
     * @return array{
     *   id: int, title: string, location: ?string, startTime: ?string, endTime: ?string,
     *   durationHours: ?float, state: ?string, author: ?string,
     *   project: ?array{id: ?int, name: string}, updatedAt: ?string
     * }
     */
    public static function meeting(array $raw): array {
        $projectName = self::linkTitle($raw, 'project');
        $location    = is_string($raw['location'] ?? null) ? self::text($raw['location']) : '';
        $state       = is_string($raw['state'] ?? null) ? $raw['state'] : null;
        return [
            'id'            => (int)($raw['id'] ?? 0),
            'title'         => self::text((string)($raw['title'] ?? '')),
            'location'      => $location !== '' ? $location : null,
            'startTime'     => self::isoOrNull($raw['startTime'] ?? null),
            'endTime'       => self::isoOrNull($raw['endTime'] ?? null),
            'durationHours' => self::durationHours($raw['duration'] ?? null),
            'state'         => $state !== null && preg_match('/^[a-z_]{1,20}$/', $state) === 1 ? $state : null,
            'author'        => self::linkTitle($raw, 'author'),
            'project'       => $projectName !== null ? ['id' => self::linkId($raw, 'project'), 'name' => $projectName] : null,
            'updatedAt'     => self::isoOrNull($raw['updatedAt'] ?? null),
        ];
    }

    /** Longest news summary / body excerpt the feed shows. */
    public const NEWS_EXCERPT_LENGTH = 400;

    /** An ISO 8601 duration (`PT1H30M`, `PT45M`, `P1D`) → hours, or null. */
    private static function durationHours(mixed $value): ?float {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            $i = new \DateInterval($value);
        } catch (\Throwable) {
            return null;
        }
        $hours = $i->d * 24 + $i->h + $i->i / 60 + $i->s / 3600;
        return $hours > 0 ? round($hours, 2) : null;
    }

    /**
     * The elements of a HAL collection, or an exception-worthy null when the
     * body is not a collection.
     *
     * @param array<string, mixed> $raw
     * @return ?array{total: ?int, elements: list<array<string, mixed>>}
     */
    public static function collection(array $raw): ?array {
        $elements = $raw['_embedded']['elements'] ?? null;
        if (!is_array($elements)) {
            return null;
        }
        return [
            'total'    => isset($raw['total']) && is_numeric($raw['total']) ? (int)$raw['total'] : null,
            'elements' => array_values(array_filter($elements, 'is_array')),
        ];
    }

    /**
     * Reduce a Markdown/HTML formattable to a short plain-text excerpt.
     * Null in → null out; empty after stripping → null.
     */
    public static function excerpt(?string $text, int $max = self::EXCERPT_LENGTH): ?string {
        if ($text === null) {
            return null;
        }
        $plain = self::text($text);
        if ($plain === '') {
            return null;
        }
        if (mb_strlen($plain) <= $max) {
            return $plain;
        }
        $cut   = mb_substr($plain, 0, $max);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > (int)($max * 0.6)) {
            $cut = mb_substr($cut, 0, $space);
        }
        return rtrim($cut, " \t\n\r.,;:") . '…';
    }

    /**
     * Plain text from anything OpenProject may hand us: tags gone, Markdown
     * decoration gone, whitespace collapsed. Never returns markup.
     */
    public static function text(string $value): string {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Markdown links: keep the text, drop the target — including a target
        // that itself contains one level of parentheses (`javascript:alert(1)`).
        $value = (string)preg_replace('/\[([^\]]*)\]\((?:[^()]*|\([^()]*\))*\)/u', '$1', $value);
        // Heading, emphasis, code and quote markers.
        $value = (string)preg_replace('/^[ \t]*(#{1,6}|>)+[ \t]*/mu', '', $value);
        $value = str_replace(['**', '__', '`'], '', $value);
        $value = (string)preg_replace('/\s+/u', ' ', $value);
        return trim($value);
    }

    /**
     * An OpenProject project identifier is `[a-z0-9_-]`, lower-case, and is
     * the one value that lands in a URL path. Anything else becomes '' so a
     * deep link is never built from it.
     */
    public static function identifier(string $value): string {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,254}$/', $value) === 1 ? $value : '';
    }

    // ─────────────────────────────────────────────────────────────────────
    // HAL helpers
    // ─────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $raw */
    public static function linkHref(array $raw, string $rel): ?string {
        $href = $raw['_links'][$rel]['href'] ?? null;
        return is_string($href) && $href !== '' ? $href : null;
    }

    /** @param array<string, mixed> $raw */
    public static function linkTitle(array $raw, string $rel): ?string {
        $link = $raw['_links'][$rel] ?? null;
        if (!is_array($link) || !isset($link['href']) || $link['href'] === null) {
            return null;
        }
        $title = $link['title'] ?? null;
        return is_string($title) && trim($title) !== '' ? self::text($title) : null;
    }

    /** The trailing integer of a link's href, e.g. `/api/v3/projects/12` → 12. */
    public static function linkId(array $raw, string $rel): ?int {
        $href = self::linkHref($raw, $rel);
        if ($href === null) {
            return null;
        }
        $segment = self::trailingSegment($href);
        return ctype_digit($segment) ? (int)$segment : null;
    }

    public static function trailingSegment(string $href): string {
        $trimmed = rtrim($href, '/');
        $pos     = strrpos($trimmed, '/');
        return $pos === false ? $trimmed : substr($trimmed, $pos + 1);
    }

    /** A formattable (`{format, raw, html}`) or plain string → its raw text. */
    private static function formattable(mixed $value): ?string {
        if (is_array($value)) {
            $raw = $value['raw'] ?? null;
            return is_string($raw) ? $raw : null;
        }
        return is_string($value) ? $value : null;
    }

    private static function isoOrNull(mixed $value): ?string {
        if (!is_string($value) || $value === '') {
            return null;
        }
        return strtotime($value) === false ? null : $value;
    }

    private static function dateOrNull(mixed $value): ?string {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        return $value;
    }
}
