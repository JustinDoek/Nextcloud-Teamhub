<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Provisioning\Step;

use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Service\OpenProject\OpenProjectClient;
use OCA\TeamHub\Service\OpenProject\OpenProjectProjectService;
use OCA\TeamHub\Service\OpenProject\OpenProjectProvisioningService;
use OCA\TeamHub\Service\Provisioning\ProvisioningContext;
use OCA\TeamHub\Service\Provisioning\StepResult;

/**
 * Step 2 — the OpenProject project: created from a template (mode A) or
 * read and checked (mode B). **Before the team exists**, on purpose: Phase
 * 1's rule is that an OpenProject team is created with its project or not
 * at all, so the project has to be there first.
 *
 * ## Idempotency in mode A
 *
 * Three things can have happened before a retry, and each is looked for:
 *   1. a copy job was submitted — its id is in `externalRef`; poll it;
 *   2. the project exists under the reserved identifier — the previous
 *      attempt made it and died before recording; adopt it (the validate
 *      step proved the identifier free when the operation started, so a
 *      project with it now is ours);
 *   3. nothing happened — create or copy.
 * Nothing is ever submitted twice.
 *
 * ## Rollback
 *
 * An OpenProject project is **never deleted by TeamHub**, created or not: a
 * copy of a template already holds work packages, and the point of the
 * integration is that OpenProject owns them. Rollback reports the project
 * for a person to review and removes nothing.
 */
class OpenProjectProjectStep implements StepInterface {

    public const KEY = 'openproject_project';

    public function __construct(
        private OpenProjectProvisioningService $op,
        private OpenProjectProjectService      $projects,
        private OpenProjectClient              $client,
    ) {
    }

    public function key(): string { return self::KEY; }
    public function resourceType(): ?string { return 'openproject_project'; }
    public function applies(ProvisioningContext $ctx): bool { return $ctx->blueprint->requiresOpenProject(); }
    public function rollbackPossible(): bool { return false; }

    public function run(ProvisioningContext $ctx): StepResult {
        $uid   = $ctx->userId;
        $opReq = (array)$ctx->field('openProject', []);

        try {
            if (!$ctx->isCreateMode()) {
                $projectId = (int)($opReq['projectId'] ?? 0);
                $project   = $this->projects->getProject($uid, $projectId);
                if (!$project['linkable']) {
                    return StepResult::failed(OpenProjectException::PERMISSION_DENIED, 'You must administer this project in OpenProject, or it must be public.', false);
                }
                return $this->done($ctx, $project, 'linked');
            }

            $identifier = (string)($opReq['identifier'] ?? '');
            $templateId = (int)($opReq['templateId'] ?? 0);
            $parentId   = (int)($opReq['parentId'] ?? 0) ?: null;
            $public     = ($ctx->field('visibility', 'private')) === 'public';
            $name       = $ctx->teamName();
            $desc       = (string)$ctx->field('description', '');

            // 1. A job in flight from an earlier attempt.
            $step = $ctx->step(self::KEY);
            $ref  = $step['externalRef'] ?? null;
            if (is_string($ref) && $ref !== '') {
                return $this->poll($ctx, $ref);
            }

            // 2. The project already exists under our identifier.
            $existing = $this->op->findProjectByIdentifier($uid, $identifier);
            if ($existing !== null) {
                return $this->done($ctx, $existing, 'created', ['adopted' => true]);
            }

            // 3. Make it.
            if ($templateId > 0) {
                $job = $this->op->copyProject($uid, $templateId, $name, $identifier, $desc, $public, $parentId, $ctx->blueprint->copyOptions());
                $ctx->remember(self::KEY, 'templateId', $templateId);
                $ctx->remember(self::KEY, 'jobId', $job['jobId']);
                if ($job['status'] === 'success' && $job['projectId'] !== null) {
                    return $this->done($ctx, $this->projects->getProject($uid, $job['projectId']), 'created');
                }
                if ($job['status'] === 'error' || $job['status'] === 'failure') {
                    return StepResult::failed(OpenProjectException::JOB_FAILED, $job['message'] ?? 'OpenProject could not copy the template.', true);
                }
                return StepResult::running($job['jobId'], 3, $ctx->factsOf(self::KEY));
            }

            $project = $this->op->createProject($uid, $name, $identifier, $desc, $public, $parentId);
            return $this->done($ctx, $project, 'created');
        } catch (OpenProjectException $e) {
            $retry = !in_array($e->getErrorCode(), [
                OpenProjectException::PERMISSION_DENIED,
                OpenProjectException::VALIDATION_FAILED,
                OpenProjectException::PROJECT_NOT_FOUND,
            ], true);
            return StepResult::failed($e->getErrorCode(), $e->getUpstreamMessage() ?? $e->getMessage(), $retry);
        }
    }

    public function rollback(ProvisioningContext $ctx, array $stepRow, bool $confirm): StepResult {
        $projectId = $ctx->fact(self::KEY, 'projectId');
        if ($projectId === null) {
            return StepResult::skipped('nothing_to_undo');
        }
        if (($stepRow['detail']['mode'] ?? 'linked') === 'linked') {
            return StepResult::skipped('linked_resource_kept', ['projectId' => $projectId]);
        }
        // Created by this operation, and still left alone: see the class docblock.
        return StepResult::attention(
            'manual_review',
            'The OpenProject project created for this workspace was left in place for you to review or delete in OpenProject.',
            ['projectId' => $projectId, 'identifier' => $ctx->fact(self::KEY, 'identifier'), 'url' => $ctx->fact(self::KEY, 'url')],
            (string)$projectId,
        );
    }

    private function poll(ProvisioningContext $ctx, string $jobId): StepResult {
        $job = $this->op->jobStatus($ctx->userId, $jobId);
        if ($job['status'] === 'success') {
            $projectId = $job['projectId'];
            if ($projectId === null) {
                // The job says done but names no project: the identifier is
                // the way back to it.
                $existing = $this->op->findProjectByIdentifier($ctx->userId, (string)($ctx->field('openProject', [])['identifier'] ?? ''));
                if ($existing === null) {
                    return StepResult::failed(OpenProjectException::UNSUPPORTED_RESPONSE, 'The copy finished but OpenProject did not say which project it made.', true);
                }
                return $this->done($ctx, $existing, 'created');
            }
            return $this->done($ctx, $this->projects->getProject($ctx->userId, $projectId), 'created');
        }
        if ($job['status'] === 'error' || $job['status'] === 'failure') {
            // The job is spent; a retry submits a new one (the identifier
            // check in run() decides whether a project exists anyway).
            $ctx->remember(self::KEY, 'jobId', null);
            return new StepResult(StepResult::FAILED, null, null, $ctx->factsOf(self::KEY), OpenProjectException::JOB_FAILED, $job['message'] ?? 'OpenProject could not copy the template.', true);
        }
        return StepResult::running($jobId, 3, $ctx->factsOf(self::KEY));
    }

    /**
     * @param array<string,mixed> $project a normalised project
     * @param array<string,mixed> $extra
     */
    private function done(ProvisioningContext $ctx, array $project, string $mode, array $extra = []): StepResult {
        $ctx->remember(self::KEY, 'projectId', $project['id']);
        $ctx->remember(self::KEY, 'identifier', $project['identifier']);
        $ctx->remember(self::KEY, 'name', $project['name']);
        $ctx->remember(self::KEY, 'mode', $mode);
        $ref = $project['identifier'] !== '' ? $project['identifier'] : (string)$project['id'];
        $ctx->remember(self::KEY, 'url', $this->client->projectUrl($ref));
        $ctx->remember(self::KEY, 'jobId', null);
        return StepResult::completed((string)$project['id'], $ctx->factsOf(self::KEY) + $extra);
    }
}
