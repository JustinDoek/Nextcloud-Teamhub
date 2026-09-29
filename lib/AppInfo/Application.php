<?php
declare(strict_types=1);

namespace OCA\TeamHub\AppInfo;

use OCA\TeamHub\Listener\AppDisabledListener;
use OCA\TeamHub\Listener\CalendarObjectDeletedListener;
use OCA\TeamHub\Listener\CircleMembershipChangedListener;
use OCA\TeamHub\Listener\FilesScriptsListener;
use OCA\TeamHub\Listener\GroupMembershipChangedListener;
use OCA\TeamHub\Listener\UnifiedSearchStyleListener;
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
use OCA\TeamHub\MyWork\Provider\FileReviewWorkProvider;
use OCA\TeamHub\MyWork\Provider\MeetingWorkProvider;
use OCA\TeamHub\MyWork\Provider\OpenProjectWorkProvider;
use OCA\TeamHub\MyWork\Provider\TeamAdminWorkProvider;
use OCA\TeamHub\MyWork\Provider\TeamExpiryAdminWorkProvider;
use OCA\TeamHub\MyWork\Provider\TeamExpiryTeamWorkProvider;
use OCA\TeamHub\MyWork\Provider\TeamSpaceAdminWorkProvider;
use OCA\TeamHub\MyWork\ProviderRegistry;
use OCA\TeamHub\Workflow\Definition\QuotaRequestDefinition;
use OCA\TeamHub\Constants\ServiceCatalogue;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Workflow\Definition\ServiceRequestDefinition;
use OCA\TeamHub\Workflow\Definition\TeamAdoptionDefinition;
use OCA\TeamHub\Workflow\Definition\TeamExpiryRequestDefinition;
use OCA\TeamHub\Workflow\Definition\TeamRequestDefinition;
use OCA\TeamHub\Workflow\Definition\TeamServiceDefinition;
use OCA\TeamHub\Db\TeamServiceMapper;
use OCA\TeamHub\Workflow\WorkflowDefinitionRegistry;
use OCA\TeamHub\Search\DecisionSearchProvider;
use OCA\TeamHub\Search\MessageSearchProvider;
use OCA\TeamHub\Search\TeamSearchProvider;
use OCA\TeamHub\Teams\TeamHubResourceProvider;
use OCA\TeamHub\Service\MyWorkConfigService;
use OCA\TeamHub\Service\Suggestion\MeetingSuggestionService;
use OCA\TeamHub\Service\Suggestion\PersonalAndTeamBusyProvider;
use OCA\TeamHub\Service\Suggestion\TeamCalendarBusyProvider;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IContainer;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
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

        // v4.10.47 — a user joining or leaving a Nextcloud group attached to a
        // team: queue a cron-side sync of Circles' copy of the group, which
        // also moves a team off a stale copy. See GroupMirrorSyncService.
        $context->registerEventListener(UserAddedEvent::class, GroupMembershipChangedListener::class);
        $context->registerEventListener(UserRemovedEvent::class, GroupMembershipChangedListener::class);

        // v4.8.18 — the "Request review" entry in a file's ⋯ menu. String
        // class name behind a class_exists guard, matching the DAV and Circles
        // wiring above: the Files app is a hard dependency in practice but not
        // a declared one, and Application is loaded early enough that a missing
        // class here would brick the app rather than degrade it.
        //
        // NO leading backslash — see the Circles comment above for the deploy
        // this cost.
        if (class_exists('OCA\Files\Event\LoadAdditionalScriptsEvent')) {
            $context->registerEventListener(
                'OCA\Files\Event\LoadAdditionalScriptsEvent',
                FilesScriptsListener::class,
            );
        }

        // Register TeamHub teams, messages, and decisions with NC unified search.
        $context->registerSearchProvider(TeamSearchProvider::class);
        $context->registerSearchProvider(MessageSearchProvider::class);
        $context->registerSearchProvider(DecisionSearchProvider::class);
        // v4.10.4 — the results' icon class lives in css/search.css, which has
        // to be on every page the search can open on (Talk's pattern).
        $context->registerEventListener(
            \OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent::class,
            UnifiedSearchStyleListener::class,
        );

        // v4.10.1 — Tell Nextcloud what TeamHub has for a team: its home.
        // Nextcloud's own Teams page, the team popover in the contacts menu
        // and the Teams dashboard widget list a team's resources by asking
        // every registered provider (`GET /teams/{id}/resources`); Talk, Deck,
        // Calendar, Collectives and Team folders answer, and now TeamHub
        // does too. The API is `@since 29`, so this is the same on 33–35.
        $context->registerTeamResourceProvider(TeamHubResourceProvider::class);

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
                // their team's. Its rows are Action required like any other
                // task since v4.10.20, when Category::TEAM_ADMIN was retired.
                TeamAdminWorkProvider::class,
                // v4.6.13 — team expiration dates, in two halves. The team one
                // is an ordinary team-scoped provider. The admin one is the
                // first to declare `isInstanceScoped()`, so its rows reach a
                // Nextcloud administrator about teams they are not in; see
                // WorkQuery's docblock for the three conditions on that, and
                // the provider's own for the authorisation it owes in return.
                TeamExpiryTeamWorkProvider::class,
                TeamExpiryAdminWorkProvider::class,
                // v4.8.18 — file review requests. TeamHub's own module again,
                // and the first provider whose items can *leave* a queue
                // without the viewer having done anything: closing a review
                // withdraws it from everybody who had not answered. That rule
                // lives entirely in the provider — see its docblock — which is
                // the extension point working as advertised.
                FileReviewWorkProvider::class,
                // v4.9.5 — the viewer's OpenProject work packages, one row per
                // dated, assigned, open work package in every project their
                // teams are linked to. Read as the viewer through the official
                // integration app; OPEN is a hand-off to OpenProject.
                OpenProjectWorkProvider::class,
                // v4.10.1 — Nextcloud 35 team spaces, for Nextcloud
                // administrators: the teams still on a shared folder (with
                // the procedure on the row), the reports of what the daily
                // reconcile pass removed, and the folder conflicts a person
                // has to settle. Instance-scoped like the expiry admin
                // provider; unavailable — no tab — on 33/34.
                TeamSpaceAdminWorkProvider::class,
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

        // ── WorkflowHub definition registry (v4.10.13) ───────────────────
        //
        // The built-in workflow definitions, the same shape as the provider
        // registry above: one line per definition class, each constructed
        // in its own try/catch so a definition that cannot be built does
        // not take the engine down. A definition is validated on
        // registration (steps, keys, version), so a malformed one fails
        // here, at boot, rather than at the first create().
        $context->registerService(WorkflowDefinitionRegistry::class, function (IContainer $c): WorkflowDefinitionRegistry {
            $registry = new WorkflowDefinitionRegistry();

            $builtIn = [
                // v4.10.13 — the quota request's shape on the engine; since
                // v4.10.29 the seventh Nextcloud service, answered by the desk
                // that holds the bundle, and the only quota request there is
                // (the ledger version is imported and gone).
                QuotaRequestDefinition::class,
                // v4.10.14 — the reference workflow: a member asks for a new
                // team; owner/moderator approves; the service team that
                // holds the Nextcloud services creates it; the requester
                // confirms. v4.10.23 made it the sixth service of the
                // bundle, so it is licensed and dark until a desk holds it.
                TeamRequestDefinition::class,
                // v4.10.45 — more time before a team's expiration date: the
                // bundle's service that replaced *Request a team
                // modification*; granting sets the date.
                TeamExpiryRequestDefinition::class,
                // v4.10.50 — accept or decline a team made outside TeamHub:
                // the eighth service of the bundle, started by TeamHub's own
                // sweep rather than by a person (IWorkflowSystemStarted).
                TeamAdoptionDefinition::class,
            ];

            foreach ($builtIn as $definitionClass) {
                try {
                    $registry->register($c->get($definitionClass));
                } catch (\Throwable $e) {
                    $c->get(\Psr\Log\LoggerInterface::class)->error(
                        '[TeamHub][Workflow] Definition could not be registered',
                        ['definition' => $definitionClass, 'exception' => $e, 'app' => self::APP_ID],
                    );
                }
            }

            // v4.10.20 — the service catalogue: one ServiceRequestDefinition
            // per service, constructed by hand because they differ only in
            // the service key and the container cannot tell them apart. The
            // services with a definition class of their own (request a new
            // team, the quota request) are registered above, so they are
            // skipped here rather than given a second definition that asks
            // the same question.
            // v4.10.45 — a retired service stays registered, dark, so the
            // requests made on it keep their title and can be finished.
            foreach (array_merge(ServiceCatalogue::SERVICES, ServiceCatalogue::RETIRED) as $serviceKey) {
                if (ServiceCatalogue::hasOwnDefinition($serviceKey)) {
                    continue;
                }
                try {
                    $registry->register(new ServiceRequestDefinition(
                        $c->get(ServiceTeamService::class),
                        $serviceKey,
                    ));
                } catch (\Throwable $e) {
                    $c->get(\Psr\Log\LoggerInterface::class)->error(
                        '[TeamHub][Workflow] Service definition could not be registered',
                        ['service' => $serviceKey, 'exception' => $e, 'app' => self::APP_ID],
                    );
                }
            }

            // v4.10.31 — the services service teams built themselves
            // (`docs/service-builder.md`): one TeamServiceDefinition per row
            // that was ever published, built from its published document.
            // An unpublished one stays registered but dark, so the requests
            // made on it keep their title and steps. One indexed query, run
            // only when something asks for the registry. Guarded as a whole
            // too: during an upgrade the table may not exist yet.
            try {
                $serviceTeams = $c->get(ServiceTeamService::class);
                foreach ($c->get(TeamServiceMapper::class)->findEverPublished() as $row) {
                    try {
                        $registry->register(new TeamServiceDefinition(
                            $serviceTeams,
                            (int)$row->getId(),
                            $row->getTeamId(),
                            $row->getPubVersion(),
                            $row->isListed(),
                            $row->publishedDocument(),
                        ));
                    } catch (\Throwable $e) {
                        $c->get(\Psr\Log\LoggerInterface::class)->error(
                            '[TeamHub][Workflow] Team service could not be registered',
                            ['service' => (int)$row->getId(), 'exception' => $e, 'app' => self::APP_ID],
                        );
                    }
                }
            } catch (\Throwable $e) {
                $c->get(\Psr\Log\LoggerInterface::class)->warning(
                    '[TeamHub][Workflow] Team services could not be read',
                    ['exception' => $e, 'app' => self::APP_ID],
                );
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
