<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Workflow;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\WorkflowAttachment;
use OCA\TeamHub\Db\WorkflowAttachmentMapper;
use OCA\TeamHub\Db\WorkflowInstance;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Workflow\WorkflowAttachmentVisibility;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * The paperclip (v4.10.38, WorkflowHub phase 8c; `docs/service-builder.md`
 * § 7).
 *
 * Justin: *"we wouldn't actually upload the attachment to the team but
 * temporary share the file with the service team with the permissions and
 * duration you can set in the service builder."* So a file somebody picks
 * beside what they write on a request is **shared, never copied**, with the
 * other side, until a date:
 *
 * | Who writes                          | Shared with       | Share          |
 * |-------------------------------------|-------------------|----------------|
 * | the requester                       | the service team  | team (circle)  |
 * | a team member, on what the          | the requester     | user           |
 * |   requester reads                   |                   |                |
 * | a team member, on an internal note  | the service team  | team (circle)  |
 *
 * **TeamHub applies the expiry rules itself** (gates G4/G5, closed
 * 2026-09-24): Nextcloud checks the expiry of a *user* share and checks
 * nothing for a *team* share. Every share made here ends at 23:59:59 of its
 * last day in the viewer's time zone — as Nextcloud sets a user share — and
 * never runs past the administrator's enforced maximum for internal shares.
 *
 * **Every share is recorded** on the request as a document
 * (`teamhub_wf_attachment`, phase 6) with its id and date. When the request
 * ends the engine removes them all (`removeShares()`); a share that outlives
 * that — the removal failed, or the request is still open — is removed by
 * the daily `ExpireWorkflowSharesJob` once its date passes (gate G11, closed
 * 2026-09-24: a job, which runs with the apps loaded, finds and deletes a
 * team share by id). Nextcloud itself drops an expired team or user share
 * only when somebody's shares are next read.
 *
 * The file's name stays on the request after the share is gone.
 */
class WorkflowShareService {

    /** Files per message: a request is not a file transfer. */
    public const MAX_FILES = 10;
    public const DEFAULT_DAYS = 14;
    public const MAX_DAYS = 365;

    public const AUDIENCE_TEAM      = 'team';
    public const AUDIENCE_REQUESTER = 'requester';

    public function __construct(
        private WorkflowAttachmentMapper $attachments,
        private IRootFolder              $rootFolder,
        private IShareManager            $shareManager,
        private IDateTimeZone            $dateTimeZone,
        private ITimeFactory             $timeFactory,
        private IL10N                    $l,
        private LoggerInterface          $logger,
    ) {
    }

    /**
     * What a request's service allows (v4.10.38), from the settings copied
     * into the request's data when it started — so a later publish changes
     * no running request. A request without them (a built-in service, a
     * request made before this version) gets the defaults.
     *
     * @param array<string, mixed> $data the instance data
     * @return array{allowed: bool, edit: bool, days: int}
     */
    public static function settingsOf(array $data): array {
        $raw = is_array($data['fileSharing'] ?? null) ? $data['fileSharing'] : [];
        $days = (int)($raw['days'] ?? self::DEFAULT_DAYS);
        return [
            'allowed' => ($raw['allowed'] ?? true) !== false,
            'edit'    => ($raw['edit'] ?? false) === true,
            'days'    => max(1, min(self::MAX_DAYS, $days > 0 ? $days : self::DEFAULT_DAYS)),
        ];
    }

    /**
     * The files a person picked, checked before anything is written: each is
     * a file in *their* Nextcloud that they may share. The name is taken now,
     * so the request can still say what was handed over after the share is
     * gone.
     *
     * @param mixed $fileIds as the client sent them
     * @return array<int, array{fileId: int, name: string}>
     * @throws ValidationException
     */
    public function resolve(string $uid, mixed $fileIds): array {
        if ($fileIds === null || $fileIds === '' || $fileIds === []) {
            return [];
        }
        if (!is_array($fileIds)) {
            throw new ValidationException($this->l->t('The files could not be read.'));
        }
        $ids = [];
        foreach ($fileIds as $id) {
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                throw new ValidationException($this->l->t('The files could not be read.'));
            }
            if ((int)$id > 0) {
                $ids[(int)$id] = true;
            }
        }
        if (count($ids) > self::MAX_FILES) {
            throw new ValidationException($this->l->n(
                'You can attach at most %n file.',
                'You can attach at most %n files.',
                self::MAX_FILES,
            ));
        }
        $out = [];
        $folder = $this->rootFolder->getUserFolder($uid);
        foreach (array_keys($ids) as $id) {
            $node = $folder->getFirstNodeById($id);
            if (!$node instanceof File) {
                throw new ValidationException($this->l->t('A file you picked could not be found.'));
            }
            if (!$node->isShareable()) {
                // TRANSLATORS: %s is the name of a file the person may not share
                throw new ValidationException($this->l->t('You may not share "%s".', [$node->getName()]));
            }
            $out[] = ['fileId' => $id, 'name' => mb_substr($node->getName(), 0, 255)];
        }
        return $out;
    }

    /**
     * When a share made today for `$days` days ends: 23:59:59 of its last
     * day, and never past the administrator's enforced maximum for internal
     * shares — the rules Nextcloud applies to a user share and not to a team
     * share (G4/G5).
     */
    public function expiryFor(int $days): \DateTime {
        $days = max(1, min(self::MAX_DAYS, $days));
        if ($this->shareManager->shareApiInternalDefaultExpireDateEnforced()) {
            $max = $this->shareManager->shareApiInternalDefaultExpireDays();
            if ($max > 0) {
                $days = min($days, $max);
            }
        }
        $date = new \DateTime('@' . $this->timeFactory->getTime());
        $date->setTimezone($this->dateTimeZone->getTimeZone());
        $date->setTime(23, 59, 59);
        $date->add(new \DateInterval('P' . $days . 'D'));
        return $date;
    }

    /**
     * Share each file with the other side and record it on the request.
     * Runs after the write that carried the files has committed: a share is
     * not part of the database transaction, so it is made only once the
     * message it belongs to exists. A file already shared on this request is
     * not shared twice. A share Nextcloud refuses is logged and recorded
     * without an id: the request still says the file was handed over, and the
     * sender sees in Files that it was not shared.
     *
     * @param array<int, array{fileId: int, name: string}> $files from `resolve()`
     * @param string $audience `team` (a circle: `$recipient` is its id) or `requester` (a user)
     * @param array{allowed: bool, edit: bool, days: int} $settings from `settingsOf()`
     */
    public function share(
        WorkflowInstance $instance,
        string           $uid,
        array            $files,
        string           $audience,
        string           $recipient,
        string           $visibility,
        ?string          $stepKey,
        array            $settings,
    ): void {
        if ($files === [] || $recipient === '') {
            return;
        }
        $instanceId = (int)$instance->getId();
        $expiry     = $this->expiryFor($settings['days']);
        $folder     = $this->rootFolder->getUserFolder($uid);
        foreach ($files as $file) {
            $row = $this->attachments->findByFile($instanceId, (int)$file['fileId']);
            if ($row !== null && $row->hasLiveShare()) {
                continue;
            }
            $shareId = '';
            try {
                $node = $folder->getFirstNodeById((int)$file['fileId']);
                if (!$node instanceof File) {
                    throw new \RuntimeException('file not found for the sender');
                }
                $share = $this->shareManager->newShare();
                $share->setNode($node)
                    ->setShareType($audience === self::AUDIENCE_TEAM ? IShare::TYPE_CIRCLE : IShare::TYPE_USER)
                    ->setSharedWith($recipient)
                    ->setSharedBy($uid)
                    ->setShareOwner($node->getOwner()?->getUID() ?? $uid)
                    // View, or view and edit — never reshare or delete.
                    ->setPermissions(Constants::PERMISSION_READ | ($settings['edit'] ? Constants::PERMISSION_UPDATE : 0))
                    ->setExpirationDate(clone $expiry);
                $shareId = $this->shareManager->createShare($share)->getFullId();
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][WorkflowShare] a file could not be shared', [
                    'instance' => $instanceId, 'fileId' => (int)$file['fileId'], 'audience' => $audience,
                    'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
            $this->record($row, $instanceId, $file, $uid, $visibility, $stepKey, $shareId, $shareId !== '' ? $expiry->getTimestamp() : null);
        }
    }

    /**
     * The shares still live on a request, for the engine to remove when the
     * request ends. Read inside the ending transaction, before an unlicensed
     * ending purges the rows; removed after it commits.
     *
     * @return WorkflowAttachment[]
     */
    public function liveShares(int $instanceId): array {
        return array_values(array_filter(
            $this->attachments->findByInstance($instanceId),
            static fn (WorkflowAttachment $row): bool => $row->hasLiveShare(),
        ));
    }

    /**
     * Remove shares TeamHub made and mark their rows (the rows may already be
     * gone, with a purged request). A share Nextcloud already dropped counts
     * as removed.
     *
     * @param WorkflowAttachment[] $rows
     * @return string[] the names of the files whose share could not be removed
     */
    public function removeShares(array $rows): array {
        $failed = [];
        $now    = $this->timeFactory->getTime();
        foreach ($rows as $row) {
            if (!$row->hasLiveShare()) {
                continue;
            }
            try {
                $this->shareManager->deleteShare($this->shareManager->getShareById($row->getShareId()));
            } catch (ShareNotFound) {
                // Already gone: expired and dropped by Nextcloud, or removed by hand.
            } catch (\Throwable $e) {
                $failed[] = $row->getFileName();
                $this->logger->warning('[TeamHub][WorkflowShare] a share could not be removed', [
                    'instance' => $row->getInstanceId(), 'share' => $row->getShareId(),
                    'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
                continue;
            }
            try {
                $row->setUnsharedAt($now);
                $this->attachments->update($row);
            } catch (\Throwable) {
                // The request was purged with its documents; nothing to mark.
            }
        }
        return $failed;
    }

    /** The daily job: every share TeamHub made that is past its date. */
    public function expireDue(): int {
        $due = $this->attachments->findSharesDue($this->timeFactory->getTime());
        $this->removeShares($due);
        return count($due);
    }

    /** @param array{fileId: int, name: string} $file */
    private function record(
        ?WorkflowAttachment $row,
        int                 $instanceId,
        array               $file,
        string              $uid,
        string              $visibility,
        ?string             $stepKey,
        string              $shareId,
        ?int                $until,
    ): void {
        $isNew = $row === null;
        $row ??= new WorkflowAttachment();
        $row->setInstanceId($instanceId);
        $row->setFileId((int)$file['fileId']);
        $row->setFileName((string)$file['name']);
        // A file the requester reads stays readable to them in the record;
        // one an internal note carried stays the team's.
        $row->setVisibility(WorkflowAttachmentVisibility::isValid($visibility) ? $visibility : WorkflowAttachmentVisibility::INTERNAL);
        $row->setStepKey($stepKey);
        $row->setAddedBy($uid);
        $row->setAddedAt($this->timeFactory->getTime());
        $row->setShareId($shareId);
        $row->setShareUntil($until);
        $row->setUnsharedAt(null);
        $isNew ? $this->attachments->insert($row) : $this->attachments->update($row);
    }
}
