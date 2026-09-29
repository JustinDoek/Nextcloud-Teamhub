<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow;

/**
 * Who is responsible for a step, or who takes part in a workflow
 * (WorkflowHub phase 1, v4.10.13).
 *
 * An actor is a *selector*, not a list of people: `group:admin` is "whoever
 * is in the admin group when it matters", `team_owner` is "whoever owns the
 * instance's team now". `WorkflowActorResolver` answers whether a given user
 * holds an actor at the moment of the question — so a role that changes
 * hands mid-workflow follows the role, and the workflow never strands on a
 * person who left.
 *
 * The team-relative actors carry no id: the team is the instance's.
 */
final class WorkflowActor {
    /** One Nextcloud user. `$id` = uid. */
    public const TYPE_USER           = 'user';
    /** Every member of one Nextcloud group. `$id` = group id (`admin` = the Nextcloud administrators). */
    public const TYPE_GROUP          = 'group';
    /** The owner (Circles level 9) of the instance's team. */
    public const TYPE_TEAM_OWNER     = 'team_owner';
    /** Every moderator, admin or owner (Circles level ≥ 4) of the instance's team. */
    public const TYPE_TEAM_MODERATOR = 'team_moderator';
    /** Every effective member (direct or via a group) of the instance's team. */
    public const TYPE_TEAM           = 'team';
    /**
     * v4.10.20 — every eligible agent of one service team. `$id` = the
     * service team's id, which is **not** the instance's team: a request
     * belongs to the team that asked, and is handled by the team that
     * serves. Carrying the id on the actor is what keeps the engine free of
     * service-team knowledge — `holds()` resolves it like any other
     * selector.
     *
     * A definition lists it with an empty id (`serviceAgentPlaceholder()`);
     * `ServiceRequestDefinition::resolveActor()` fills the id in before the
     * step row is written, so no stored row ever carries an empty one.
     */
    public const TYPE_SERVICE_AGENT  = 'service_agent';
    /**
     * v4.10.14 — the person who started the workflow. A *definition-only*
     * placeholder: `WorkflowEngine::create()` turns it into `user:{uid}` when
     * it materialises the steps, so no stored row ever carries it and
     * `holds()` never sees it.
     */
    public const TYPE_INITIATOR      = 'initiator';

    public const TYPES = [
        self::TYPE_USER, self::TYPE_GROUP, self::TYPE_TEAM_OWNER, self::TYPE_TEAM_MODERATOR, self::TYPE_TEAM,
        self::TYPE_SERVICE_AGENT, self::TYPE_INITIATOR,
    ];

    /** Types a stored step or participant row may carry. */
    public const STORABLE = [
        self::TYPE_USER, self::TYPE_GROUP, self::TYPE_TEAM_OWNER, self::TYPE_TEAM_MODERATOR, self::TYPE_TEAM,
        self::TYPE_SERVICE_AGENT,
    ];

    /** Actor types that are resolved against the instance's team rather than an id. */
    public const TEAM_RELATIVE = [self::TYPE_TEAM_OWNER, self::TYPE_TEAM_MODERATOR, self::TYPE_TEAM];

    private function __construct(
        public readonly string $type,
        public readonly string $id,
    ) {
    }

    public static function user(string $uid): self {
        return new self(self::TYPE_USER, $uid);
    }

    public static function group(string $gid): self {
        return new self(self::TYPE_GROUP, $gid);
    }

    public static function teamOwner(): self {
        return new self(self::TYPE_TEAM_OWNER, '');
    }

    public static function teamModerator(): self {
        return new self(self::TYPE_TEAM_MODERATOR, '');
    }

    public static function team(): self {
        return new self(self::TYPE_TEAM, '');
    }

    /** Every eligible agent of one service team (v4.10.20). */
    public static function serviceAgent(string $serviceTeamId): self {
        return new self(self::TYPE_SERVICE_AGENT, $serviceTeamId);
    }

    /**
     * The service-agent actor a *definition* lists, before the service team
     * handling this instance is known (v4.10.20). Never stored: `create()`
     * asks the definition to resolve it, and refuses the step if it cannot.
     */
    public static function serviceAgentPlaceholder(): self {
        return new self(self::TYPE_SERVICE_AGENT, '');
    }

    /** Definition-only; see TYPE_INITIATOR. */
    public static function initiator(): self {
        return new self(self::TYPE_INITIATOR, '');
    }

    /** A placeholder a definition must resolve before the step row is written. */
    public function isUnresolved(): bool {
        return $this->type === self::TYPE_INITIATOR
            || ($this->type === self::TYPE_SERVICE_AGENT && $this->id === '');
    }

    /**
     * Rebuild from stored columns. Rejects an unknown type and an empty id
     * where one is required, so a corrupt row surfaces as an exception in
     * the engine rather than as an actor nobody holds.
     */
    public static function of(string $type, string $id): self {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown workflow actor type: ' . $type);
        }
        if (in_array($type, self::TEAM_RELATIVE, true) || $type === self::TYPE_INITIATOR) {
            return new self($type, '');
        }
        if ($id === '') {
            throw new \InvalidArgumentException('A ' . $type . ' actor needs an id.');
        }
        return new self($type, $id);
    }

    public function isInitiator(): bool {
        return $this->type === self::TYPE_INITIATOR;
    }

    public function isTeamRelative(): bool {
        return in_array($this->type, self::TEAM_RELATIVE, true);
    }

    /** `type:id` — the key participant lookups match on. */
    public function key(): string {
        return $this->type . ':' . $this->id;
    }

    public function equals(self $other): bool {
        return $this->type === $other->type && $this->id === $other->id;
    }
}
