<?php
declare(strict_types=1);

namespace OCA\TeamHub\Integration;

/**
 * No-op base for {@see ILifecycleProvider} (v4.13.23).
 *
 * Extend this and override only the hooks the package cares about. The point
 * is forward compatibility: when a later TeamHub adds an eighth hook to the
 * interface, an app extending this class keeps loading, and an app
 * implementing the interface directly does not. The interface is the
 * contract; this is the one every real implementation should start from.
 */
abstract class LifecycleProvider implements ILifecycleProvider {

    /** @return list<string> */
    public function validateTeamConfiguration(TeamHubContext $context): array {
        return [];
    }

    public function onTeamCreated(TeamHubContext $context): void {
    }

    public function onTeamUpdated(TeamHubContext $context): void {
    }

    /**
     * @param list<string> $added
     * @param list<string> $removed
     */
    public function onMembersChanged(TeamHubContext $context, array $added, array $removed): void {
    }

    public function onTemplateIntegrationAdded(TeamHubContext $context): void {
    }

    public function onTemplateIntegrationRemoved(TeamHubContext $context): void {
    }

    public function beforeTeamDeleted(TeamHubContext $context): void {
    }

    public function onTeamDeleted(TeamHubContext $context): void {
    }
}
