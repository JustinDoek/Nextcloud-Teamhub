<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Provisioning;

/**
 * Everything a step needs to know about the operation it is part of
 * (v4.9.6, Phase 2). Built by the runner from the operation row, its step
 * rows and the template's blueprint; steps read it, and write back the two
 * things later steps depend on — the team id once it exists, and per-step
 * facts (`remember()`) such as the OpenProject project id.
 *
 * Nothing in here is a secret: the request is the sanitised wizard payload
 * and the facts are ids and names.
 */
final class ProvisioningContext {

    /** @var array<string, array<string,mixed>> facts by step key, read back from step detail */
    private array $facts = [];

    /**
     * @param array<string,mixed> $operation
     * @param array<string, array<string,mixed>> $steps by key
     */
    public function __construct(
        public readonly array     $operation,
        public array              $steps,
        public readonly Blueprint $blueprint,
        /** The Nextcloud uid the operation runs as — always its creator. */
        public readonly string    $userId,
        public ?string            $teamId,
    ) {
        foreach ($steps as $key => $row) {
            $this->facts[$key] = is_array($row['detail'] ?? null) ? $row['detail'] : [];
        }
    }

    /**
     * Set by the runner: persists the team id on the operation row the
     * moment the team exists, before anything else happens to it — so a
     * request that dies right after `createTeam()` still leaves a record
     * that the retry can adopt.
     *
     * @var null|\Closure(string): void
     */
    public ?\Closure $onTeamCreated = null;

    public function id(): int {
        return (int)$this->operation['id'];
    }

    /** The team exists now — record it here and on the operation row. */
    public function setTeamId(string $teamId): void {
        $this->teamId = $teamId;
        if ($this->onTeamCreated !== null) {
            ($this->onTeamCreated)($teamId);
        }
    }

    /** @return array<string,mixed> */
    public function request(): array {
        return $this->operation['request'];
    }

    public function mode(): string {
        return (string)$this->operation['mode'];
    }

    public function isCreateMode(): bool {
        return $this->mode() === 'create';
    }

    /** A request field, with a default. */
    public function field(string $key, mixed $default = null): mixed {
        return $this->operation['request'][$key] ?? $default;
    }

    /** The name the team and every resource named after it get. */
    public function teamName(): string {
        return trim((string)$this->field('name', ''));
    }

    /**
     * The applications this workspace gets: the blueprint's required ones
     * plus the optional ones the creator kept.
     *
     * @return list<string>
     */
    public function apps(): array {
        return $this->operation['request']['components']['apps'] ?? [];
    }

    /** @return list<string> */
    public function modules(): array {
        return $this->operation['request']['components']['modules'] ?? [];
    }

    public function hasApp(string $appId): bool {
        return in_array($appId, $this->apps(), true);
    }

    public function hasModule(string $module): bool {
        return in_array($module, $this->modules(), true);
    }

    /** A fact an earlier step recorded (its detail), or a default. */
    public function fact(string $stepKey, string $name, mixed $default = null): mixed {
        return $this->facts[$stepKey][$name] ?? $default;
    }

    /** Keep a fact for later steps in this run (persisted with the step's detail). */
    public function remember(string $stepKey, string $name, mixed $value): void {
        $this->facts[$stepKey][$name] = $value;
    }

    /** @return array<string,mixed> */
    public function factsOf(string $stepKey): array {
        return $this->facts[$stepKey] ?? [];
    }

    /** The OpenProject project id this workspace is for, once known. */
    public function projectId(): ?int {
        $id = $this->fact('openproject_project', 'projectId');
        if ($id === null) {
            $id = $this->isCreateMode() ? null : ($this->operation['request']['openProject']['projectId'] ?? null);
        }
        return is_numeric($id) && (int)$id > 0 ? (int)$id : null;
    }

    /** The step row by key, if the operation has it. */
    public function step(string $key): ?array {
        return $this->steps[$key] ?? null;
    }
}
