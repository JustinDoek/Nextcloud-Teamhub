<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\RosterTemplate;
use OCA\TeamHub\Db\RosterTemplateMapper;
use OCA\TeamHub\Db\RosterTemplateRow;
use OCA\TeamHub\Db\RosterTemplateRowMapper;
use OCA\TeamHub\Db\ShiftType;
use OCA\TeamHub\Db\ShiftTypeMapper;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * A team's roster week templates (v4.13.74, DESIGN §2.240).
 *
 * ## The division this class exists to hold
 *
 * **A Nextcloud administrator defines the shifts** — `teamhub_shift_type`,
 * *Early is 07:00 to 15:00* — in Admin, TeamHub. **A team admin defines that
 * team's templates** in Manage team, Module settings. Justin's call,
 * 2026-11-02, and it is the reason this was never the instance-wide schedule
 * of §2.234 with a team id bolted on: the vocabulary is the instance's and the
 * week is the team's, so they have different owners, different screens and
 * different lifetimes. v4.14.4 finished the thought and removed that
 * schedule, which leaves this the only week in the app (§2.247).
 *
 * A template line is *a weekday, a shift type, the role somebody is needed
 * for, and how many people*. The role is what makes the template worth having:
 * a week laid out from one already says **Receptionist 08:00 to 12:00** on
 * every card, so staffing it is picking people and nothing else. That is the
 * whole of what Justin asked for — *the team admin only needs to assign
 * members to a role and a shift*.
 *
 * ## The week is replaced wholesale on save, in one transaction
 *
 * Diffing lines would mean deciding what an edited line means for a week
 * already laid out from the template, and the answer is **nothing**: applying
 * a template copies values into shifts, so a later edit never reaches back
 * into a week somebody has already been told about. That is what lets a team
 * admin fix a template without silently rewriting next week.
 *
 * Gating belongs to the caller: `RosterController` asks for team admin and for
 * Shifts to be available before any of this runs.
 */
class RosterTemplateService {

    /** 0 = Monday, matching PresenceTemplate and the roster grid. */
    private const DAYS = [0, 1, 2, 3, 4, 5, 6];

    /** A role is a job title. The column is 128; so is the limit. */
    private const ROLE_MAX = 128;

    public function __construct(
        private RosterTemplateMapper    $templateMapper,
        private RosterTemplateRowMapper $rowMapper,
        private ShiftTypeMapper         $typeMapper,
        private IDBConnection           $db,
        private IL10N                   $l,
    ) {}

    /**
     * Every template this team owns, with its week.
     *
     * @return list<array<string, mixed>>
     */
    public function listForTeam(string $teamId): array {
        $templates = $this->templateMapper->findByTeam($teamId);
        $rows = $this->rowMapper->findByTemplateIds(
            array_map(static fn (RosterTemplate $t): int => $t->getId(), $templates),
        );
        $types = $this->typesById();

        return array_map(
            fn (RosterTemplate $template): array => $this->serialize(
                $template, $rows[$template->getId()] ?? [], $types,
            ),
            $templates,
        );
    }

    /** @return array<string, mixed>|null */
    public function get(int $id, string $teamId): ?array {
        $template = $this->templateMapper->findByIdForTeam($id, $teamId);
        if ($template === null) {
            return null;
        }
        return $this->serialize(
            $template,
            $this->rowMapper->findByTemplate($id),
            $this->typesById(),
        );
    }

    /**
     * Create or replace a template and its whole week.
     *
     * @param list<array{dayOfWeek: int, shiftTypeId: int, role?: string, requiredCount?: int}> $lines
     * @throws \InvalidArgumentException
     * @return array<string, mixed>
     */
    public function save(
        ?int $id,
        string $teamId,
        string $name,
        string $description,
        bool $isActive,
        array $lines,
        string $actorUid,
    ): array {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 128) {
            throw new \InvalidArgumentException(
                $this->l->t('A template needs a name of up to 128 characters.'),
            );
        }

        // Scoped to the team: two teams may both have a "Reception week", and
        // neither ever sees the other's.
        $clash = $this->templateMapper->findByName($teamId, $name);
        if ($clash !== null && $clash->getId() !== $id) {
            throw new \InvalidArgumentException(
                $this->l->t('This team already has a template called %s.', [$name]),
            );
        }

        $clean = $this->validateLines($lines);

        $now = time();
        $this->db->beginTransaction();
        try {
            if ($id === null) {
                $template = new RosterTemplate();
                $template->setTeamId($teamId);
                $template->setCreatedBy($actorUid);
                $template->setCreatedAt($now);
            } else {
                $template = $this->templateMapper->findByIdForTeam($id, $teamId);
                if ($template === null) {
                    throw new \InvalidArgumentException(
                        $this->l->t('That template does not exist.'),
                    );
                }
            }
            $template->setName($name);
            $template->setDescription(mb_substr(trim($description), 0, 255));
            $template->setIsActive($isActive ? 1 : 0);
            $template->setUpdatedAt($now);

            /** @var RosterTemplate $saved */
            $saved = $id === null
                ? $this->templateMapper->insert($template)
                : $this->templateMapper->update($template);

            $this->rowMapper->deleteByTemplate($saved->getId());
            foreach ($clean as $line) {
                $row = new RosterTemplateRow();
                $row->setTemplateId($saved->getId());
                $row->setDayOfWeek($line['dayOfWeek']);
                $row->setShiftTypeId($line['shiftTypeId']);
                $row->setRole($line['role']);
                $row->setRequiredCount($line['requiredCount']);
                $this->rowMapper->insert($row);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->serialize(
            $saved,
            $this->rowMapper->findByTemplate($saved->getId()),
            $this->typesById(),
        );
    }

    /**
     * Delete a template and its week.
     *
     * Weeks already laid out from it are untouched: applying a template copies
     * values into shifts, so nothing downstream depends on this row continuing
     * to exist.
     */
    public function delete(int $id, string $teamId): void {
        $template = $this->templateMapper->findByIdForTeam($id, $teamId);
        if ($template === null) {
            throw new \InvalidArgumentException($this->l->t('That template does not exist.'));
        }

        $this->db->beginTransaction();
        try {
            $this->rowMapper->deleteByTemplate($id);
            $this->templateMapper->delete($template);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Forget everything a deleted team's templates held.
     *
     * Called from TeamService when a team is deleted, beside the rest of that
     * team's rows.
     */
    public function forgetTeam(string $teamId): void {
        $this->db->beginTransaction();
        try {
            $ids = $this->templateMapper->deleteByTeam($teamId);
            $this->rowMapper->deleteByTemplates($ids);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * Validate every line before the transaction opens: a week either saves
     * whole or not at all, and a half-written template is worse than a refused
     * one.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array{dayOfWeek: int, shiftTypeId: int, role: string, requiredCount: int}>
     * @throws \InvalidArgumentException
     */
    private function validateLines(array $lines): array {
        $validTypes = $this->typesById();

        $clean = [];
        $seen  = [];
        foreach ($lines as $line) {
            $day   = (int)($line['dayOfWeek'] ?? -1);
            $type  = (int)($line['shiftTypeId'] ?? 0);
            $role  = mb_substr(trim((string)($line['role'] ?? '')), 0, self::ROLE_MAX);
            $count = (int)($line['requiredCount'] ?? 1);

            if (!in_array($day, self::DAYS, true)) {
                throw new \InvalidArgumentException(
                    $this->l->t('A weekday must be 0 (Monday) to 6 (Sunday).'),
                );
            }
            if (!isset($validTypes[$type])) {
                throw new \InvalidArgumentException(
                    $this->l->t('Unknown shift type in the template.'),
                );
            }
            if ($role === '') {
                // The role is the point of a template, so an empty one is a
                // refusal rather than a blank card: a drafted week whose
                // places do not say what they are for is the state 4.13.70
                // was reported for.
                throw new \InvalidArgumentException(
                    $this->l->t('Every line needs the role you need somebody for.'),
                );
            }
            if ($count < 1 || $count > 99) {
                throw new \InvalidArgumentException(
                    $this->l->t('A shift needs between 1 and 99 people.'),
                );
            }

            // Same weekday, same shift type, same role twice would silently
            // double the coverage target. Two *different* roles on one type
            // and day are the ordinary case and are left alone.
            $key = $day . ':' . $type . ':' . mb_strtolower($role);
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException($this->l->t(
                    'That role appears twice on the same shift and day. Change how many people it needs instead.',
                ));
            }
            $seen[$key] = true;

            $clean[] = [
                'dayOfWeek'     => $day,
                'shiftTypeId'   => $type,
                'role'          => $role,
                'requiredCount' => $count,
            ];
        }

        return $clean;
    }

    /**
     * The shift types a template may be built from: the active ones, in the
     * administrator's own order.
     *
     * It travels with the template list so the editor needs one request. The
     * admin catalogue (`/api/v1/admin/shift-types`) is a Nextcloud
     * administrator's endpoint and a team admin is not necessarily one — which
     * is exactly how the Roster tab's picker came to be permanently empty for
     * the person it was built for.
     *
     * @return list<array<string, mixed>>
     */
    public function availableTypes(): array {
        return array_values(array_map(
            static fn (ShiftType $type): array => [
                'id'        => $type->getId(),
                'label'     => $type->getLabel(),
                'startTime' => $type->getStartTime(),
                'endTime'   => $type->getEndTime(),
                'color'     => $type->getColor(),
            ],
            $this->typeMapper->findActive(),
        ));
    }

    /** @return array<int, ShiftType> */
    private function typesById(): array {
        $types = [];
        foreach ($this->typeMapper->findAll() as $type) {
            $types[$type->getId()] = $type;
        }
        return $types;
    }

    /**
     * @param RosterTemplateRow[] $rows
     * @param array<int, ShiftType> $types
     * @return array<string, mixed>
     */
    private function serialize(RosterTemplate $template, array $rows, array $types): array {
        $lines = [];
        $total = 0;
        foreach ($rows as $row) {
            $type = $types[$row->getShiftTypeId()] ?? null;
            $total += $row->getRequiredCount();
            $lines[] = [
                'dayOfWeek'     => $row->getDayOfWeek(),
                'shiftTypeId'   => $row->getShiftTypeId(),
                'shiftTypeName' => $type?->getLabel() ?? $this->l->t('Removed shift type'),
                'color'         => $type?->getColor() ?? '',
                'startTime'     => $type?->getStartTime() ?? '',
                'endTime'       => $type?->getEndTime() ?? '',
                'role'          => $row->getRole(),
                'requiredCount' => $row->getRequiredCount(),
            ];
        }

        return [
            'id'          => $template->getId(),
            'name'        => $template->getName(),
            'description' => $template->getDescription(),
            'isActive'    => $template->getIsActive() === 1,
            'lines'       => $lines,
            // What a week of this template costs in people-shifts. The number
            // a team admin wants before committing to it.
            'weeklyTotal' => $total,
        ];
    }
}
