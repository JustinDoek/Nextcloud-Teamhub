<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Controller\PreferencesController;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\PropertyDoesNotExistException;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Who the team calendar may ask Nextcloud's free/busy about (v4.20.15,
 * DESIGN §2.267).
 *
 * The team calendar can lay each member's busy times over the team's own
 * events. TeamHub does not read anybody's personal calendar for that. The
 * browser sends one free/busy request to the viewer's own CalDAV outbox, the
 * request Nextcloud Calendar sends when somebody is added to an event, and
 * Nextcloud answers it: times only, and only what the member's own settings
 * let count as busy (events marked Free, cancelled events and calendars kept
 * out of availability do not; working hours come back as unavailable). This
 * service only decides **whom** that request names.
 *
 * Nextcloud's free/busy looks a person up by email address and nothing else
 * (sabre's `Schedule\Plugin::getFreeBusyForEmail()`), so the browser needs an
 * address per person. That is why this endpoint exists rather than the grid
 * reusing the members list:
 *
 *  - **A member who switched it off** (`PreferencesController::PREF_SHOW_BUSY`)
 *    is left out.
 *  - **A member whose email is Private** in their Nextcloud profile is left
 *    out, the rule the members widget applies to the same field. Their
 *    address would otherwise reach every teammate's browser as a lookup key.
 *  - **A member with no email** cannot be looked up and is left out.
 *
 * The three are counted together as `notShown` and not told apart, so the
 * answer never says which teammate chose to hide. The viewer is always
 * included when they have an address, whatever their own scope: it is their
 * own address going to their own browser.
 *
 * Membership is checked by `MemberService::getEffectiveMemberIds()`. Members
 * on another server have no local account and never resolve.
 */
class TeamBusyService {

    /**
     * People one request may name. The grid asks for the people the viewer
     * picked, never the whole list, and Nextcloud computes free/busy per
     * person over every calendar they own, so a 300-member team must not turn
     * into one 300-person query. The browser holds the same number in
     * `src/lib/teamBusy.js` (`MAX_BUSY_PEOPLE`); each names the other.
     */
    public const MAX_PEOPLE_PER_REQUEST = 12;

    public function __construct(
        private MemberService $memberService,
        private IUserManager $userManager,
        private IUserSession $userSession,
        private IConfig $config,
        private IAccountManager $accountManager,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{
     *     organizer: string|null,
     *     maxPerRequest: int,
     *     people: list<array{userId: string, displayName: string, email: string, self: bool}>,
     *     notShown: int,
     * }
     * @throws \Exception when the caller is not a member (403 via the controller)
     */
    public function peopleFor(string $teamId): array {
        $memberIds = $this->memberService->getEffectiveMemberIds($teamId);

        $viewer = $this->userSession->getUser();
        $viewerUid = $viewer?->getUID() ?? '';

        $people = [];
        $notShown = 0;
        foreach ($memberIds as $uid) {
            $user = $this->userManager->get($uid);
            if ($user === null || !$user->isEnabled()) {
                continue;
            }
            $isSelf = $uid === $viewerUid;

            if (!$isSelf && $this->config->getUserValue(
                $uid,
                Application::APP_ID,
                PreferencesController::PREF_SHOW_BUSY,
                '1',
            ) === '0') {
                $notShown++;
                continue;
            }

            $email = $isSelf ? $this->plainEmail($user) : $this->shareableEmail($user);
            if ($email === null) {
                $notShown++;
                continue;
            }

            $people[] = [
                'userId' => $uid,
                'displayName' => $user->getDisplayName(),
                'email' => $email,
                'self' => $isSelf,
            ];
        }

        // The viewer first, then by name: the picker's order.
        usort($people, static function (array $a, array $b): int {
            if ($a['self'] !== $b['self']) {
                return $a['self'] ? -1 : 1;
            }
            return strcasecmp($a['displayName'], $b['displayName']);
        });

        return [
            // The ORGANIZER of the free/busy request must be one of the
            // viewer's own calendar addresses or Nextcloud refuses it. Null
            // when the viewer has no email; the browser then uses the
            // viewer's principal URL, which is in the same address set.
            'organizer' => $viewer !== null ? $this->plainEmail($viewer) : null,
            'maxPerRequest' => self::MAX_PEOPLE_PER_REQUEST,
            'people' => $people,
            'notShown' => $notShown,
        ];
    }

    private function plainEmail(IUser $user): ?string {
        try {
            $email = trim((string)$user->getEMailAddress());
        } catch (\Throwable $e) {
            return null;
        }
        return $email !== '' ? $email : null;
    }

    /**
     * The member's email address unless they made it Private, the same rule
     * `MemberService::enrichMembers()` applies for the members widget. When
     * the account manager cannot answer for this one account, the person is
     * left out rather than their address handed out unchecked.
     */
    private function shareableEmail(IUser $user): ?string {
        try {
            $property = $this->accountManager->getAccount($user)
                ->getProperty(IAccountManager::PROPERTY_EMAIL);
            if ($property->getScope() === IAccountManager::SCOPE_PRIVATE) {
                return null;
            }
            $value = trim((string)$property->getValue());
            return $value !== '' ? $value : null;
        } catch (PropertyDoesNotExistException $e) {
            return null;
        } catch (\Throwable $e) {
            $this->logger->info('[TeamHub][TeamBusyService] email scope could not be read', [
                'exception' => $e,
            ]);
            return null;
        }
    }
}
