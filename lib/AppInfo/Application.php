<?php
declare(strict_types=1);

namespace OCA\TeamHub\AppInfo;

use OCA\TeamHub\Listener\AppDisabledListener;
use OCA\TeamHub\Listener\CalendarObjectDeletedListener;
use OCA\TeamHub\Listener\CircleMembershipChangedListener;
use OCA\TeamHub\Listener\UserStatusListener;
use OCA\TeamHub\Listener\UserDeletedListener;
use OCA\TeamHub\Notification\Notifier;
use OCA\TeamHub\Service\IntegrationService;
use OCP\App\Events\AppDisabledEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCA\TeamHub\MyWork\Provider\ApprovalWorkProvider;
use OCA\TeamHub\MyWork\Provider\DeckWorkProvider;
use OCA\TeamHub\MyWork\Provider\DecisionWorkProvider;
use OCA\TeamHub\MyWork\Provider\MeetingWorkProvider;
use OCA\TeamHub\MyWork\Provider\TeamAdminWorkProvider;
use OCA\TeamHub\MyWork\Provider\TeamExpiryAdminWorkProvider;
use OCA\TeamHub\MyWork\Provider\TeamExpiryTeamWorkProvider;
use OCA\TeamHub\MyWork\ProviderRegistry;
use OCA\TeamHub\Search\DecisionSearchProvider;
use OCA\TeamHub\Search\MessageSearchProvider;
use OCA\TeamHub\Search\TeamSearchProvider;
use OCA\TeamHub\Service\MyWorkConfigService;
use OCA\TeamHub\Service\Suggestion\MeetingSuggestionService;
use OCA\TeamHub\Service\Suggestion\PersonalAndTeamBusyProvider;
use OCA\TeamHub\Service\Suggestion\TeamCalendarBusyProvider;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IContainer;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\BeforeUserDeletedEvent;

class Application extends App implements IBootstrap {
    public const APP_ID = 'teamhub';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Register notification notifier.
        $context->registerNotifierService(Notifier::class);

        // Auto-deregister any integration whose app is disabled or removed.
        $context->registerEventListener(AppDisabledEvent::class, AppDisabledListener::class);

        // Flag / clear risk_status when a resource owner is disabled or re-enabled.
        $context->registerEventListener(UserChangedEvent::class, UserStatusListener::class);

        // Attempt ownership transfer before a resource owner's account is deleted.
        $context->registerEventListener(BeforeUserDeletedEvent::class, UserDeletedListener::class);

        // Cancel RoomVox bookings when their calendar event is deleted.
        // Wire to both "deleted" and "moved-to-trash" events because NC's
        // calendar app trashes first, then deletes after retention period.
        // Class names are kept as strings to tolerate older NC versions
        // without the trash event without a fatal failure (Application is
        // loaded very early and missing classes here would brick the app).
        if (class_exists('\OCA\DAV\Events\CalendarObjectDeletedEvent')) {
            $context->registerEventListener(
                \OCA\DAV\Events\CalendarObjectDeletedEvent::class,
                CalendarObjectDeletedListener::class,
            );
        }
        if (class_exists('\OCA\DAV\Events\CalendarObjectMovedToTrashEvent')) {
            $context->registerEventListener(
                \OCA\DAV\Events\CalendarObjectMovedToTrashEvent::class,
                CalendarObjectDeletedListener::class,
            );
        }

        // v4.7.10 (GitHub #87) — reconcile a team's resources when Circles
        // finishes changing its effective membership. This is the only hook
        // that fires late enough for a direct member add: the join is completed
        // in a separate async request, long after the one TeamHub handled. See
        // CircleMembershipChangedListener for the full sequence.
        //
        // String class names and a class_exists guard, matching the DAV events
        // below: Circles is a hard dependency in practice but not a declared
        // one, and Application is loaded early enough that a missing class here
        // would brick the app rather than degrade it.
        // NO leading backslash in these strings. dispatchTyped() dispatches
        // under get_class($event), which never has one, and Symfony matches
        // listener keys by exact string — so '\OCA\…' registers the listener
        // under a name nothing is ever dispatched to. class_exists() accepts
        // either spelling, so the guard below passes and the wiring looks
        // correct while silently doing nothing. Cost a deploy on 2026-08-29.
        // The ::class form used for the DAV events above cannot have this bug.
        foreach ([
            'OCA\Circles\Events\MembershipsCreatedEvent',
            'OCA\Circles\Events\MembershipsRemovedEvent',
        ] as $circlesEvent) {
            if (class_exists($circlesEvent)) {
                $context->registerEventListener($circlesEvent, CircleMembershipChangedListener::class);
            }
        }

        // Register TeamHub teams, messages, and decisions with NC unified search.
        $context->registerSearchProvider(TeamSearchProvider::class);
        $context->registerSearchProvider(MessageSearchProvider::class);
        $context->registerSearchProvider(DecisionSearchProvider::class);

        // MeetingSuggestionService is the STAGE-1 scorer. It picks half-days
        // based on PRESENCE only (which team members are at-the-office /
        // home-available / not-busy on that morning or afternoon). Calendar
        // events — team or personal — are NOT consulted here, because
        // existing events shouldn't kill a whole half-day for a meeting
        // that could be scheduled around them. That fine-grained check is
        // stage 2's job (TimeslotSuggestionService, which reads each
        // attendee's personal + team-membership calendars to find a free
        // window inside the half-day).
        //
        // Earlier iterations of this code wired TeamCalendarBusyProvider
        // (and later PersonalAndTeamBusyProvider) into stage 1. Both were
        // wrong for the same reason: a 30-minute conflict at 09:00 was
        // eliminating the entire morning instead of just narrowing where
        // stage 2 could place the meeting. Empty array = no calendar
        // consultation at stage 1.
        //
        // The constructor still requires a BusyProviderInterface[] for
        // backward shape; passing [] is a supported "presence-only" mode.
        // PersonalAndTeamBusyProvider and TeamCalendarBusyProvider both
        // remain registered (auto-wired) so stage 2 / future callers can
        // still resolve them.
        $context->registerService(MeetingSuggestionService::class, function (IContainer $c): MeetingSuggestionService {
            return new MeetingSuggestionService(
                $c->get(\OCA\TeamHub\Service\MemberService::class),
                $c->get(\OCA\TeamHub\Db\PresenceSlotMapper::class),
                $c->get(\OCA\TeamHub\Db\PresenceTypeMapper::class),
                $c->get(\OCA\TeamHub\Db\RoomMapper::class),
                $c->get(\OCA\TeamHub\Db\FloorMapper::class),
                $c->get(\OCA\TeamHub\Db\BuildingMapper::class),
                $c->get(\OCP\IConfig::class),
                $c->get(\OCA\TeamHub\Service\TimezoneService::class),
                $c->get(\Psr\Log\LoggerInterface::class),
                [],
            );
        });

        // ── My Work provider registry (v4.5.21) ──────────────────────────
        //
        // This is the extension point. Adding a source to My Work is one line
        // in the loop below plus a class implementing IWorkProvider — nothing
        // in the service, the controllers or the frontend changes.
        //
        // Each provider is constructed inside its own try/catch: a provider
        // whose constructor throws (a missing optional dependency on some
        // install, say) must not take the whole registry — and therefore the
        // whole My Work view — down with it. That is the same fault-isolation
        // contract ProviderRegistry::fetchAll() applies at fetch time, applied
        // one stage earlier.
        $context->registerService(ProviderRegistry::class, function (IContainer $c): ProviderRegistry {
            $registry = new ProviderRegistry(
                $c->get(MyWorkConfigService::class),
                $c->get(\Psr\Log\LoggerInterface::class),
            );

            $builtIn = [
                DeckWorkProvider::class,
                ApprovalWorkProvider::class,
                // v4.5.23 — TeamHub's own Decisions module. Added as a third
                // provider with no change to the service, the controllers or
                // the frontend, which is the extensibility claim in DESIGN.md
                // §2.69 exercised rather than asserted.
                DecisionWorkProvider::class,
                // v4.5.25 — team calendar events you are invited to or
                // organised, Talk meetings included: TeamHub writes the call
                // link into the event's LOCATION, so a Talk meeting is a
                // calendar event that carries one rather than a source of
                // its own.
                MeetingWorkProvider::class,
                // v4.5.45 — team-administration housekeeping, currently
                // resources awaiting an admin's accept/ignore. The first
                // provider whose items are not the viewer's own work but
                // their team's; it owns Category::TEAM_ADMIN.
                TeamAdminWorkProvider::class,
                // v4.6.13 — team expiration dates, in two halves. The team one
                // is an ordinary team-scoped provider. The admin one is the
                // first to declare `isInstanceScoped()`, so its rows reach a
                // Nextcloud administrator about teams they are not in; see
                // WorkQuery's docblock for the three conditions on that, and
                // the provider's own for the authorisation it owes in return.
                TeamExpiryTeamWorkProvider::class,
                TeamExpiryAdminWorkProvider::class,
            ];

            foreach ($builtIn as $providerClass) {
                try {
                    $registry->register($c->get($providerClass));
                } catch (\Throwable $e) {
                    $c->get(\Psr\Log\LoggerInterface::class)->error(
                        '[TeamHub][MyWork] Provider could not be constructed',
                        ['provider' => $providerClass, 'exception' => $e, 'app' => self::APP_ID],
                    );
                }
            }

            return $registry;
        });

        // Note: Background jobs are registered via appinfo/info.xml <background-jobs> block.
        // registerBackgroundJob() was removed from IRegistrationContext in NC 33.

        // Note: Admin settings panel is registered via appinfo/info.xml <settings> block.
        // Do NOT call $context->registerSettings() here — that method does not exist
        // on NC32's IRegistrationContext and causes a fatal on every page load.
    }

    public function boot(IBootContext $context): void {
        // Seed built-in integrations (Talk, Files, Calendar, Deck) into the
        // integration registry. Idempotent — safe to call on every boot.
        try {
            $container = $context->getAppContainer();
            /** @var IntegrationService $integrationService */
            $integrationService = $container->get(IntegrationService::class);
            $integrationService->seedBuiltins();
        } catch (\Throwable $e) {
            // Never let a seeding failure crash the entire app boot.
        }

        // Air-gapped-only licensing model — no install/uninstall telemetry
        // pings. The only outbound call the app ever makes to the licensing
        // back-end is the one-shot Start-trial POST from the License tab.
    }
}
