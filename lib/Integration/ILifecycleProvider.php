<?php
declare(strict_types=1);

namespace OCA\TeamHub\Integration;

/**
 * Opt-in lifecycle hooks for an integration package (v4.13.23).
 *
 * ## Deliberately conservative
 *
 * These are the points at which TeamHub tells a package that something
 * happened to a team it participates in. They are **not** a general event
 * bus, they are **not** transactional, and a package may implement any subset
 * — every method has a no-op default via {@see LifecycleProvider}, so adding
 * a hook later cannot break an existing implementation.
 *
 * `lifecycle_provider` is listed in {@see CapabilityType::FUTURE} and is not
 * yet an accepted capability type: the hooks below are the shape, documented
 * and testable, and the registry will accept them when there is a surface
 * that fires every one of them. What ships now is the contract, so that an
 * app written against it today does not have to change when it opens.
 *
 * ## Rules, all of them load-bearing
 *
 * 1. **Idempotent.** Every hook may be called more than once for the same
 *    event — a retried reconciliation, a replayed provisioning step. A hook
 *    that cannot be called twice safely is a hook that will corrupt something
 *    the first time a request times out halfway.
 * 2. **A failure is isolated, never fatal.** TeamHub catches every
 *    `\Throwable`, logs the identity and the error class, records the failure
 *    for retry where the caller has a retry record, and carries on. A hook
 *    cannot veto a team's creation or deletion; {@see validateTeamConfiguration()}
 *    is the one place a package may object, and it does so by returning
 *    problems rather than by throwing.
 * 3. **Irreversible external work never runs inside a TeamHub database
 *    transaction.** TeamHub calls `onTeamCreated()` and `onTeamDeleted()`
 *    *after* its own commit, and `beforeTeamDeleted()` *before* it begins —
 *    so a rolled-back TeamHub transaction can never leave an external system
 *    holding work that TeamHub then denies. This is stated per method below.
 * 4. **External data is never deleted automatically.** `beforeTeamDeleted()`
 *    and `onTeamDeleted()` are notifications, not instructions to delete. A
 *    package that chooses to delete on them is choosing; TeamHub never asks.
 * 5. **Showing or hiding a visual capability is not a lifecycle event.** It
 *    is a layout preference and there is deliberately no hook for it — see
 *    the class docblock of {@see \OCA\TeamHub\Service\IntegrationPresentationService}.
 */
interface ILifecycleProvider {

    /**
     * May this team be configured for this package?
     *
     * Called before a team is created or its configuration saved. Return an
     * empty list to accept. Return problems to object: each is a sentence
     * shown to the person making the change, who then fixes it or chooses a
     * different template. Do not throw — a throw is treated as a fault and
     * logged, and the change proceeds.
     *
     * No writes. This runs inside the caller's validation phase, which may be
     * inside a transaction.
     *
     * @return list<string> human-readable problems; [] means "fine"
     */
    public function validateTeamConfiguration(TeamHubContext $context): array;

    /**
     * A team this package participates in has been created.
     *
     * Runs **after** TeamHub has committed the team. Safe for irreversible
     * external work: the team exists and will not be rolled back underneath
     * it.
     */
    public function onTeamCreated(TeamHubContext $context): void;

    /**
     * A team's configuration or presentation-relevant settings changed.
     *
     * Runs after commit. Not fired for a shown/hidden preference — see rule 5.
     */
    public function onTeamUpdated(TeamHubContext $context): void;

    /**
     * The team's membership changed.
     *
     * Runs after commit. `$added` and `$removed` are Nextcloud user ids; both
     * may be empty when the change was a level change rather than a join or
     * leave.
     *
     * @param list<string> $added
     * @param list<string> $removed
     */
    public function onMembersChanged(TeamHubContext $context, array $added, array $removed): void;

    /**
     * This package was added to a template, and this team is governed by it.
     *
     * Runs after commit, once per affected team, from the reconciliation pass
     * — which is retryable, so this hook must be idempotent (rule 1).
     */
    public function onTemplateIntegrationAdded(TeamHubContext $context): void;

    /**
     * This package was removed from a template, and this team was governed by
     * it.
     *
     * The package's capabilities are already ineffective for the team by the
     * time this runs. TeamHub has deleted nothing of the package's and will
     * not: the preferences, the mappings and the external data all stay, so
     * re-adding the package restores the team exactly.
     */
    public function onTemplateIntegrationRemoved(TeamHubContext $context): void;

    /**
     * The team is about to be deleted.
     *
     * Runs **before** TeamHub begins its deletion and outside any TeamHub
     * transaction, so a package that wants to export or detach something
     * still can. TeamHub proceeds whatever this returns or throws — a package
     * cannot veto a deletion an administrator has decided on.
     */
    public function beforeTeamDeleted(TeamHubContext $context): void;

    /**
     * The team has been deleted.
     *
     * Runs after commit. A notification, not an instruction: see rule 4.
     */
    public function onTeamDeleted(TeamHubContext $context): void;
}
