<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Provisioning\Step;

use OCA\TeamHub\Db\ResourceLinkMapper;
use OCA\TeamHub\Db\TeamAppResourceMapper;
use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Service\OpenProject\OpenProjectProvisioningService;
use OCA\TeamHub\Service\Provisioning\ProvisioningContext;
use OCA\TeamHub\Service\Provisioning\StepResult;
use OCA\TeamHub\Service\ResourceService;
use Psr\Log\LoggerInterface;

/**
 * Step — project files, coordinated with the official integration.
 *
 * ## Two folders, two owners (documented decision, DESIGN §2.123)
 *
 * The official `integration_openproject` app can manage a **project folder**
 * per OpenProject project: a subfolder of the "OpenProject" group folder,
 * created by OpenProject itself through its own Nextcloud account, whose
 * permissions OpenProject keeps in step with the project's members. That
 * folder is OpenProject's: TeamHub never creates it, never changes its
 * permissions, never deletes it. It is *linked* — recorded in the ledger
 * with its file id and its "open" link — and the *Project files* action in
 * Project info already points at it (Phase 1).
 *
 * The **team folder** is TeamHub's: the group folder every other template
 * gets, with the team's circle on the ACL. It holds the collaboration
 * material around the project — minutes, drafts, what does not belong in a
 * work package.
 *
 * The blueprint's `folder.behavior` picks: `teamhub` (team folder only),
 * `openproject` (link the managed folder, no team folder), `both` (the
 * shipped default), `none`. Source of truth for permissions: the managed
 * folder's are OpenProject's, the team folder's are the team's.
 *
 * With `openproject`, a project without a managed folder is `attention` —
 * an administrator sets the storage up in OpenProject and retries — rather
 * than a team folder created in its place, which would be exactly the
 * competing structure this step exists to avoid.
 */
class ProjectFolderStep extends AbstractResourceStep {

    public const KEY = 'project_folder';

    public function __construct(
        ResourceService       $resources,
        TeamAppResourceMapper $appResources,
        ResourceLinkMapper    $registry,
        LoggerInterface       $logger,
        private OpenProjectProvisioningService $op,
    ) {
        parent::__construct($resources, $appResources, $registry, $logger);
    }

    public function key(): string { return self::KEY; }
    public function resourceType(): ?string { return 'folder'; }
    protected function appId(): string { return 'files'; }
    protected function ledgerType(): string { return 'folder'; }

    public function applies(ProvisioningContext $ctx): bool {
        return $ctx->blueprint->folderBehavior() !== 'none' && $ctx->hasApp('files');
    }

    public function run(ProvisioningContext $ctx): StepResult {
        if ($ctx->teamId === null) {
            return StepResult::failed('no_team', 'The team does not exist yet.', true);
        }
        $behavior = $ctx->blueprint->folderBehavior();
        $detail   = [];

        // ── The OpenProject-managed folder: linked, never made ───────────
        $managed = null;
        if ($behavior === 'openproject' || $behavior === 'both') {
            $projectId = $ctx->projectId();
            if ($projectId !== null) {
                try {
                    foreach ($this->op->projectStorages($ctx->userId, $projectId) as $storage) {
                        if ($storage['projectFolderFileId'] !== null && $storage['projectFolderMode'] !== 'inactive') {
                            $managed = $storage;
                            break;
                        }
                    }
                } catch (OpenProjectException $e) {
                    if ($e->getErrorCode() !== OpenProjectException::PERMISSION_DENIED) {
                        return StepResult::failed($e->getErrorCode(), $e->getMessage(), true);
                    }
                    $detail['storagesHidden'] = true;
                }
            }
            if ($managed !== null) {
                $this->registry->upsert(
                    $ctx->teamId, 'files', 'openproject_folder', (string)$managed['projectFolderFileId'],
                    'linked', $ctx->id(), $ctx->userId, $managed['openUrl'],
                    ['storageId' => $managed['storageId'], 'storageName' => $managed['storageName'], 'projectFolderMode' => $managed['projectFolderMode']],
                );
                $detail['openProjectFolder'] = [
                    'fileId' => $managed['projectFolderFileId'],
                    'mode'   => $managed['projectFolderMode'],
                    'url'    => $managed['openUrl'],
                ];
                $ctx->remember(self::KEY, 'openProjectFolderFileId', $managed['projectFolderFileId']);
            }
        }

        if ($behavior === 'openproject') {
            if ($managed === null) {
                return StepResult::attention(
                    'no_managed_folder',
                    'OpenProject manages no project folder for this project yet. Set up a storage with a project folder in OpenProject, then retry this step.',
                    $detail,
                );
            }
            return StepResult::completed((string)$managed['projectFolderFileId'], $detail + ['mode' => 'linked']);
        }

        // ── The team folder: TeamHub's own ───────────────────────────────
        $result = parent::run($ctx);
        if ($result->status !== StepResult::COMPLETED) {
            return $result;
        }
        return new StepResult(
            StepResult::COMPLETED,
            $result->externalId,
            null,
            $result->detail + $detail,
        );
    }
}
