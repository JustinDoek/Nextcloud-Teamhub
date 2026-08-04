<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork;

/**
 * The normalized My Work item (v4.5.21).
 *
 * One shape for every provider. The frontend renders this and only this — it
 * has no branch per provider, which is the whole point: a future provider is
 * a backend class and a translated name, not a UI change.
 *
 * Construction rules worth knowing:
 *
 *  - `$id` is always `{providerId}:{providerItemId}`. It is the dedup key and
 *    the handle every action endpoint takes, so it must be stable across
 *    fetches. Providers do not set it; `make()` composes it.
 *  - Timestamps are Unix seconds, `null` when the source has none. Zero is
 *    never a valid timestamp here — Deck stores 0 as "no due date" and letting
 *    that through would put every undated card in 1970.
 *  - `$reason` is a *translated sentence* ("Assigned to you"), not a code.
 *    Providers translate because only they know why the item is present, and
 *    the requirement is that every row explains itself.
 *  - `$openTarget` (v4.5.24) is how the row opens: the provider names one of
 *    OpenTarget's four mechanisms instead of leaving the frontend to guess from
 *    `$resourceType`. Omit it and the item opens `$resourceUrl` in a new
 *    browser tab.
 *  - `$permissions` is what the CURRENT user may do, resolved server-side. The
 *    frontend uses it to render, never to decide — every action re-checks on
 *    execution.
 */
final class WorkItem implements \JsonSerializable {

    /**
     * @param string               $id               "{providerId}:{providerItemId}"
     * @param string               $providerId       owning provider
     * @param string               $providerItemId   provider's own id for the item
     * @param string               $teamId           circle unique_id this item is shown under
     * @param string               $teamName         resolved team display name
     * @param string               $category         one of Category::ORDERED
     * @param string               $title            primary line ("Review Q3 Project Plan")
     * @param string               $subtitle         secondary line — resource/document name
     * @param string               $resourceType     'deck_card' | 'file' | …
     * @param string               $resourceId       id within the source app
     * @param string               $resourceUrl      relative NC URL for the resource
     * @param array|null           $openTarget       OpenTarget descriptor; null = open resourceUrl
     * @param string               $priority         one of Priority::ORDERED
     * @param string               $status           provider's own status string, for display + filtering
     * @param string               $reason           translated "why am I seeing this"
     * @param int|null             $createdAt
     * @param int|null             $updatedAt
     * @param int|null             $dueAt
     * @param int|null             $completedAt
     * @param array|null           $assignee         ['uid' => string, 'displayName' => string]
     * @param array|null           $waitingFor       ['type' => 'user'|'group'|'circle', 'id' => string, 'displayName' => string]
     * @param string[]             $availableActions subset of ActionType::ALL
     * @param array<string,mixed>  $metadata         provider extras (board, stack, workflow state…)
     * @param array<string,bool>   $permissions      ['canComplete' => true, …]
     */
    private function __construct(
        public readonly string $id,
        public readonly string $providerId,
        public readonly string $providerItemId,
        public readonly string $teamId,
        public readonly string $teamName,
        public readonly string $category,
        public readonly string $title,
        public readonly string $subtitle,
        public readonly string $resourceType,
        public readonly string $resourceId,
        public readonly string $resourceUrl,
        public readonly ?array $openTarget,
        public readonly string $priority,
        public readonly string $status,
        public readonly string $reason,
        public readonly ?int $createdAt,
        public readonly ?int $updatedAt,
        public readonly ?int $dueAt,
        public readonly ?int $completedAt,
        public readonly ?array $assignee,
        public readonly ?array $waitingFor,
        public readonly array $availableActions,
        public readonly array $metadata,
        public readonly array $permissions,
    ) {
    }

    /**
     * Build an item from a provider's associative array.
     *
     * Every field is defensively normalized here rather than trusted, so a
     * provider bug produces a slightly wrong row instead of a broken response
     * or a 1970 due date. Unknown categories fall back to UPCOMING and unknown
     * priorities to NORMAL — visible-but-wrong beats invisible.
     *
     * @param array<string,mixed> $data
     */
    public static function make(array $data): self {
        $providerId     = (string)($data['providerId'] ?? '');
        $providerItemId = (string)($data['providerItemId'] ?? '');

        $category = (string)($data['category'] ?? Category::UPCOMING);
        if (!Category::isValid($category)) {
            $category = Category::UPCOMING;
        }

        $priority = (string)($data['priority'] ?? Priority::NORMAL);
        if (!Priority::isValid($priority)) {
            $priority = Priority::NORMAL;
        }

        $actions = [];
        foreach ((array)($data['availableActions'] ?? []) as $a) {
            $a = (string)$a;
            if (ActionType::isValid($a) && !in_array($a, $actions, true)) {
                $actions[] = $a;
            }
        }

        return new self(
            id:               $providerId . ':' . $providerItemId,
            providerId:       $providerId,
            providerItemId:   $providerItemId,
            teamId:           (string)($data['teamId'] ?? ''),
            teamName:         (string)($data['teamName'] ?? ''),
            category:         $category,
            title:            (string)($data['title'] ?? ''),
            subtitle:         (string)($data['subtitle'] ?? ''),
            resourceType:     (string)($data['resourceType'] ?? ''),
            resourceId:       (string)($data['resourceId'] ?? ''),
            resourceUrl:      (string)($data['resourceUrl'] ?? ''),
            openTarget:       OpenTarget::normalize($data['openTarget'] ?? null),
            priority:         $priority,
            status:           (string)($data['status'] ?? ''),
            reason:           (string)($data['reason'] ?? ''),
            createdAt:        self::ts($data['createdAt']   ?? null),
            updatedAt:        self::ts($data['updatedAt']   ?? null),
            dueAt:            self::ts($data['dueAt']       ?? null),
            completedAt:      self::ts($data['completedAt'] ?? null),
            assignee:         self::person($data['assignee']   ?? null),
            waitingFor:       self::party($data['waitingFor'] ?? null),
            availableActions: $actions,
            metadata:         is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            permissions:      self::bools($data['permissions'] ?? []),
        );
    }

    /**
     * Copy with a different category / priority / action set.
     *
     * TeamHub re-categorises after fetch (the TODAY derivation, the snooze
     * override, the overdue promotion), and value objects are immutable, so
     * this is the one mutation path.
     *
     * @param string[]|null $availableActions
     */
    public function with(
        ?string $category = null,
        ?string $priority = null,
        ?array $availableActions = null,
        ?array $metadata = null,
        ?string $teamName = null,
    ): self {
        return new self(
            id:               $this->id,
            providerId:       $this->providerId,
            providerItemId:   $this->providerItemId,
            teamId:           $this->teamId,
            teamName:         $teamName ?? $this->teamName,
            category:         $category ?? $this->category,
            title:            $this->title,
            subtitle:         $this->subtitle,
            resourceType:     $this->resourceType,
            resourceId:       $this->resourceId,
            resourceUrl:      $this->resourceUrl,
            openTarget:       $this->openTarget,
            priority:         $priority ?? $this->priority,
            status:           $this->status,
            reason:           $this->reason,
            createdAt:        $this->createdAt,
            updatedAt:        $this->updatedAt,
            dueAt:            $this->dueAt,
            completedAt:      $this->completedAt,
            assignee:         $this->assignee,
            waitingFor:       $this->waitingFor,
            availableActions: $availableActions ?? $this->availableActions,
            metadata:         $metadata !== null ? array_merge($this->metadata, $metadata) : $this->metadata,
            permissions:      $this->permissions,
        );
    }

    /**
     * Wire shape. Keys are camelCase to match the normalized model in the
     * specification; the frontend consumes these names verbatim.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array {
        return [
            'id'               => $this->id,
            'providerId'       => $this->providerId,
            'providerItemId'   => $this->providerItemId,
            'teamId'           => $this->teamId,
            'teamName'         => $this->teamName,
            'category'         => $this->category,
            'title'            => $this->title,
            'subtitle'         => $this->subtitle,
            'resourceType'     => $this->resourceType,
            'resourceId'       => $this->resourceId,
            'resourceUrl'      => $this->resourceUrl,
            'openTarget'       => $this->openTarget,
            'priority'         => $this->priority,
            'status'           => $this->status,
            'reason'           => $this->reason,
            'createdAt'        => $this->createdAt,
            'updatedAt'        => $this->updatedAt,
            'dueAt'            => $this->dueAt,
            'completedAt'      => $this->completedAt,
            'assignee'         => $this->assignee,
            'waitingFor'       => $this->waitingFor,
            'availableActions' => $this->availableActions,
            'metadata'         => $this->metadata,
            'permissions'      => $this->permissions,
        ];
    }

    /** @return array<string,mixed> */
    public function toArray(): array {
        return $this->jsonSerialize();
    }

    // ---------------------------------------------------------------------
    // Normalizers
    // ---------------------------------------------------------------------

    /**
     * Unix seconds or null. Rejects 0 and negatives: Deck writes 0 for "no
     * due date" and a 1970 badge on every undated card is the single most
     * visible way this feature could look broken.
     */
    private static function ts(mixed $v): ?int {
        if ($v === null || $v === '') {
            return null;
        }
        $i = (int)$v;
        return $i > 0 ? $i : null;
    }

    /** @return array{uid:string,displayName:string}|null */
    private static function person(mixed $v): ?array {
        if (!is_array($v) || ($v['uid'] ?? '') === '') {
            return null;
        }
        return [
            'uid'         => (string)$v['uid'],
            'displayName' => (string)($v['displayName'] ?? $v['uid']),
        ];
    }

    /** @return array{type:string,id:string,displayName:string}|null */
    private static function party(mixed $v): ?array {
        if (!is_array($v) || ($v['id'] ?? '') === '') {
            return null;
        }
        $type = (string)($v['type'] ?? 'user');
        if (!in_array($type, ['user', 'group', 'circle'], true)) {
            $type = 'user';
        }
        return [
            'type'        => $type,
            'id'          => (string)$v['id'],
            'displayName' => (string)($v['displayName'] ?? $v['id']),
        ];
    }

    /** @return array<string,bool> */
    private static function bools(mixed $v): array {
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $k => $val) {
            $out[(string)$k] = (bool)$val;
        }
        return $out;
    }
}
