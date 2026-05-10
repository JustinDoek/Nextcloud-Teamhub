<?php
declare(strict_types=1);

namespace OCA\TeamHub\Listener;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\TeamAppResourceMapper;
use OCA\TeamHub\Service\AuditService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserChangedEvent;
use Psr\Log\LoggerInterface;

/**
 * Listens for user enabled/disabled changes and updates risk_status on
 * all resource rows owned by that user.
 *
 * Fires on: UserChangedEvent where feature = 'enabled'.
 *
 * - User disabled (value = false): risk_status → 'owner_disabled'
 *   Writes audit event: resource.risk_flagged
 *
 * - User re-enabled (value = true): risk_status → 'none'
 *   Writes audit event: resource.risk_cleared
 *
 * @template-implements IEventListener<UserChangedEvent>
 */
class UserStatusListener implements IEventListener {

    public function __construct(
        private readonly TeamAppResourceMapper $resourceMapper,
        private readonly AuditService          $auditService,
        private readonly LoggerInterface       $logger,
    ) {}

    public function handle(Event $event): void {
        if (!($event instanceof UserChangedEvent)) {
            return;
        }

        // We only care about the 'enabled' feature toggle.
        if ($event->getFeature() !== 'enabled') {
            return;
        }

        $uid     = $event->getUser()->getUID();
        $enabled = (bool) $event->getValue();

        $this->logger->debug('[TeamHub][UserStatusListener] user enabled changed', [
            'uid' => $uid, 'enabled' => $enabled, 'app' => Application::APP_ID,
        ]);

        try {
            $rows = $this->resourceMapper->findByOwnerUid($uid);

            if (empty($rows)) {
                return;
            }

            if ($enabled) {
                // User re-enabled — clear risk flag.
                $this->resourceMapper->updateRiskStatusByOwner($uid, 'none');

                foreach ($rows as $row) {
                    $this->auditService->log(
                        $row->getTeamId(),
                        'resource.risk_cleared',
                        null, // system actor
                        'resource',
                        $row->getAppId() . ':' . $row->getResourceId(),
                        [
                            'app_id'      => $row->getAppId(),
                            'resource_id' => $row->getResourceId(),
                            'owner_uid'   => $uid,
                        ],
                    );
                }

                $this->logger->info('[TeamHub][UserStatusListener] risk cleared for re-enabled user', [
                    'uid' => $uid, 'rows' => count($rows), 'app' => Application::APP_ID,
                ]);
            } else {
                // User disabled — flag all their resources as at-risk.
                $this->resourceMapper->updateRiskStatusByOwner($uid, 'owner_disabled');

                foreach ($rows as $row) {
                    $this->auditService->log(
                        $row->getTeamId(),
                        'resource.risk_flagged',
                        null, // system actor
                        'resource',
                        $row->getAppId() . ':' . $row->getResourceId(),
                        [
                            'app_id'      => $row->getAppId(),
                            'resource_id' => $row->getResourceId(),
                            'owner_uid'   => $uid,
                            'reason'      => 'owner_disabled',
                        ],
                    );
                }

                $this->logger->info('[TeamHub][UserStatusListener] resources flagged for disabled user', [
                    'uid' => $uid, 'rows' => count($rows), 'app' => Application::APP_ID,
                ]);
            }
        } catch (\Throwable $e) {
            // Never let a listener crash the calling action.
            $this->logger->error('[TeamHub][UserStatusListener] handle failed', [
                'uid' => $uid, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }
}
