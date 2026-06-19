<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\Milestone;
use OCA\TeamHub\Db\MilestoneMapper;
use Psr\Log\LoggerInterface;

/**
 * Service for Timeline Milestones (v3.78.2 — v1).
 *
 * A milestone is a team-admin-authored label with an optional date,
 * rendered on the Timeline tab as a full-height red marker line. All
 * CRUD operations are admin-gated — milestones are managed exclusively
 * from Manage Team → Integration settings, which is itself only reachable
 * by team admins/owners. Read access for the *Timeline display* (regular
 * members viewing the rendered line) goes through TimelineService /
 * TeamController::getTimeline instead, not through this service.
 *
 * Dates are stored as Unix timestamps at UTC midnight of the chosen day.
 * Undated milestones (milestoneDate === null) are valid and listed in the
 * management UI, but excluded by TimelineService's date-range query since
 * there is no x-position to plot them at.
 */
class MilestoneService {

    public function __construct(
        private MilestoneMapper $mapper,
        private MemberService   $memberService,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array<int, array{id:int, label:string, date:?string, createdBy:string, createdAt:int}>
     */
    public function listForTeam(string $teamId): array {
        $this->memberService->requireAdminLevel($teamId);

        $rows = $this->mapper->findByTeam($teamId);

        // Display order: dated milestones ascending by date, undated ones
        // last (in creation order). Done in PHP — see MilestoneMapper note
        // on cross-database NULL ordering.
        usort($rows, function (Milestone $a, Milestone $b) {
            $da = $a->getMilestoneDate();
            $db = $b->getMilestoneDate();
            if ($da === null && $db === null) return $a->getId() <=> $b->getId();
            if ($da === null) return 1;
            if ($db === null) return -1;
            return $da <=> $db;
        });

        return array_map(fn(Milestone $r) => $this->serialize($r), $rows);
    }

    /**
     * Create a milestone. Admin-gated.
     */
    public function create(string $teamId, string $label, ?string $date, string $actingUserId): array {
        $this->memberService->requireAdminLevel($teamId);

        $label = $this->validateLabel($label);
        $ts    = $this->parseDate($date);

        $row = $this->mapper->insertMilestone($teamId, $label, $ts, $actingUserId);

        $this->logger->info('[TeamHub][MilestoneService] create', [
            'teamId' => $teamId, 'milestoneId' => $row->getId(), 'by' => $actingUserId,
            'app' => Application::APP_ID,
        ]);

        return $this->serialize($row);
    }

    /**
     * Update a milestone's label and/or date. Admin-gated.
     */
    public function update(string $teamId, int $id, string $label, ?string $date): array {
        $this->memberService->requireAdminLevel($teamId);

        $row = $this->mapper->findById($id);
        if (!$row || $row->getTeamId() !== $teamId) {
            throw new \InvalidArgumentException('Milestone not found');
        }

        $row->setLabel($this->validateLabel($label));
        $row->setMilestoneDate($this->parseDate($date));
        /** @var Milestone $row */
        $row = $this->mapper->update($row);

        $this->logger->info('[TeamHub][MilestoneService] update', [
            'teamId' => $teamId, 'milestoneId' => $id, 'app' => Application::APP_ID,
        ]);

        return $this->serialize($row);
    }

    /**
     * Delete a milestone. Admin-gated.
     */
    public function delete(string $teamId, int $id): void {
        $this->memberService->requireAdminLevel($teamId);

        $row = $this->mapper->findById($id);
        if (!$row || $row->getTeamId() !== $teamId) {
            throw new \InvalidArgumentException('Milestone not found');
        }

        $this->mapper->delete($row);

        $this->logger->info('[TeamHub][MilestoneService] delete', [
            'teamId' => $teamId, 'milestoneId' => $id, 'app' => Application::APP_ID,
        ]);
    }

    // =========================================================================
    // Validation
    // =========================================================================

    private function validateLabel(string $label): string {
        $label = trim($label);
        if ($label === '') {
            throw new \InvalidArgumentException('Label is required');
        }
        if (mb_strlen($label) > 255) {
            $label = mb_substr($label, 0, 255);
        }
        return $label;
    }

    /**
     * Parse a 'YYYY-MM-DD' date string into a Unix timestamp at UTC
     * midnight. Null or blank input means "no date set" — a valid state,
     * not an error.
     */
    private function parseDate(?string $date): ?int {
        if ($date === null || trim($date) === '') {
            return null;
        }
        $date = trim($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException('Date must be in YYYY-MM-DD format');
        }
        try {
            $dt = new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Invalid date');
        }
        return $dt->getTimestamp();
    }

    private function serialize(Milestone $row): array {
        $ts = $row->getMilestoneDate();
        return [
            'id'        => $row->getId(),
            'label'     => $row->getLabel(),
            'date'      => $ts !== null ? (new \DateTimeImmutable('@' . $ts))->format('Y-m-d') : null,
            'createdBy' => $row->getCreatedBy(),
            'createdAt' => $row->getCreatedAt(),
        ];
    }
}
