<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject;

/**
 * Raised by `TeamOpenProjectLinkService::link()` when another team already
 * links the requested project (v4.9.3). One OpenProject project belongs to
 * one team; the controller answers 409 with the team, named when the caller
 * is a member of it. It lives beside the service rather than in
 * `lib/Exception/` because nothing outside the OpenProject integration ever
 * raises or catches it.
 */
class ProjectAlreadyLinkedException extends \RuntimeException {

    /**
     * @param list<array{teamId: string, name: ?string}> $teams
     */
    public function __construct(private array $teams, ?\Throwable $previous = null) {
        parent::__construct('This OpenProject project is already linked to another team', 0, $previous);
    }

    /** @return list<array{teamId: string, name: ?string}> */
    public function getTeams(): array {
        return $this->teams;
    }
}
