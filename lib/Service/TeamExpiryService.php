<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\TeamExpiryMapper;
use OCA\TeamHub\Db\TeamExpiryRequest;
use OCA\TeamHub\Db\TeamExpiryRequestMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Optional team expiration dates (v4.6.13).
 *
 * ## What an expiry does — and does not do
 *
 * **Nothing happens on the date itself.** No deletion, no archive, no lock.
 * The expiry is a governance flag: a week before it falls due (configurable),
 * the team surfaces in every Nextcloud admin's My Work and in its own admins'
 * My Work, and it keeps surfacing until somebody extends it or removes the
 * team by hand. That was a deliberate choice — an automatic destruction path
 * tied to a date somebody set months ago in a wizard is the kind of feature
 * that eventually deletes the wrong team, and the archive/delete flow that
 * already exists is the considered way to remove one.
 *
 * A consequence worth stating: an expired team keeps working exactly as it did
 * the day before. `isExpired()` is a label, not a gate, and nothing in this
 * app should start treating it as one without a separate decision.
 *
 * ## Which teams may have one
 *
 * Collaboration and Project teams only — both project modes, since basic and
 * advanced are the same `teamhub_team_type.type`. **Departments never expire**:
 * a department is a standing part of the organisation chart, not a piece of
 * work with an end, and putting a countdown on one would produce a yearly
 * ritual of extending something that was never going to end.
 *
 * Teams with no type row at all — everything created before v4.0.2 — are also
 * excluded. They are not knowably eligible, and the requirement for existing
 * teams is that they start with no expiry.
 *
 * ## Date convention
 *
 * A date arrives and leaves as `YYYY-MM-DD` and is stored as the Unix timestamp
 * of **23:59:59 UTC on that day**, so a team whose expiry reads 2026-12-31 is
 * valid through the whole of 31 December and expired on 1 January.
 *
 * This differs from `MilestoneService::parseDate()`, which stores UTC midnight,
 * and the divergence is intentional: a milestone marks a moment, and midnight
 * is the natural instant for it; an expiry is a deadline, and a deadline that
 * fires at the first second of the day it names would cut a team off a day
 * early from every user's point of view. `formatDate()` round-trips either
 * convention back to the same `Y-m-d` string, so the two never disagree in the
 * UI.
 *
 * ## Authorisation
 *
 * Three levels, and each public method states which it enforces:
 *   - **Nextcloud admin** — set, extend, clear, approve, deny.
 *   - **Team admin** — request an extension, read own team's state.
 *   - **Creation path** — `setAtCreation()`, which is called while the team is
 *     being created and so cannot demand a level the caller does not have yet.
 *     It is the one method that trusts its caller, and it is private-by-contract:
 *     only TeamService's creation flow and the bulk importer may call it.
 */
class TeamExpiryService {

    /** Templates that may carry an expiry. Departments are deliberately absent. */
    public const ELIGIBLE_TYPES = ['collaboration', 'project'];

    /** appconfig key for the warning window, in days. */
    public const CONFIG_WARNING_DAYS = 'expiry_warning_days';

    /** Days before the expiry that the warning surfaces, when unconfigured. */
    public const DEFAULT_WARNING_DAYS = 7;

    /** Bounds on the configurable warning window. */
    public const MIN_WARNING_DAYS = 1;
    public const MAX_WARNING_DAYS = 365;

    /**
     * How far ahead the wizard's date picker opens when a user first clicks it.
     * Six months — long enough that a normal piece of work is not immediately
     * nagging its admins, short enough that the date is a real decision rather
     * than a formality.
     */
    public const DEFAULT_PICKER_MONTHS = 6;

    /**
     * Furthest future date an expiry may be set to.
     *
     * Ten years is not a policy about how long teams should live; it is a
     * sanity bound so a mistyped year (2262 instead of 2026) is rejected at the
     * boundary rather than stored and quietly never surfacing again.
     */
    public const MAX_YEARS_AHEAD = 10;

    /** Cap on the free-text reason, before AuditService's own metadata cap. */
    public const REASON_MAX_LENGTH = 1000;

    public function __construct(
        private TeamExpiryMapper        $expiryMapper,
        private TeamExpiryRequestMapper $requestMapper,
        private TeamTypeService         $teamTypeService,
        private MemberService           $memberService,
        private AuditService            $auditService,
        private IUserSession            $userSession,
        private IGroupManager           $groupManager,
        private IUserManager            $userManager,
        private IConfig                 $config,
        private IDBConnection           $db,
        // v4.6.26 — owns the "Mail or mailto:" question; was a private copy of
        // the same oc_mail_accounts lookup here until this version. Took the
        // IAppManager dependency with it — that was its only user.
        private MailClientService       $mailClientService,
        private INotificationManager    $notificationManager,
        private IURLGenerator           $urlGenerator,
        private LoggerInterface         $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    /** Days before expiry that the warning appears. Clamped to the legal range. */
    public function getWarningDays(): int {
        $raw = (int)$this->config->getAppValue(
            Application::APP_ID,
            self::CONFIG_WARNING_DAYS,
            (string)self::DEFAULT_WARNING_DAYS,
        );
        if ($raw < self::MIN_WARNING_DAYS || $raw > self::MAX_WARNING_DAYS) {
            return self::DEFAULT_WARNING_DAYS;
        }
        return $raw;
    }

    /**
     * Clamp a warning-window value to the legal range.
     *
     * Coerces rather than throws, matching every other setting on the admin
     * Team creation tab: that tab autosaves as the admin types, so a value
     * that is briefly out of range on the way to a good one is a keystroke,
     * not an error worth surfacing. The caller stores the returned value and
     * the field re-renders with it, so the admin sees what was actually kept.
     */
    public static function clampWarningDays(int $days): int {
        if ($days < self::MIN_WARNING_DAYS) {
            return self::MIN_WARNING_DAYS;
        }
        if ($days > self::MAX_WARNING_DAYS) {
            return self::MAX_WARNING_DAYS;
        }
        return $days;
    }

    // -------------------------------------------------------------------------
    // Eligibility
    // -------------------------------------------------------------------------

    /** True when this team's template may carry an expiry. */
    public function isEligible(string $teamId): bool {
        return in_array($this->teamTypeService->getType($teamId) ?? '', self::ELIGIBLE_TYPES, true);
    }

    /** True when this template name may carry an expiry. */
    public static function isEligibleType(?string $type): bool {
        return in_array($type ?? '', self::ELIGIBLE_TYPES, true);
    }

    /**
     * Batch eligibility for a page of teams.
     *
     * @param string[] $teamIds
     * @return array<string,bool> [teamId => eligible]
     */
    public function eligibilityForTeams(array $teamIds): array {
        if ($teamIds === []) {
            return [];
        }
        $types = $this->teamTypeService->getTypesForTeams($teamIds);
        $out   = [];
        foreach ($teamIds as $teamId) {
            $out[$teamId] = self::isEligibleType($types[$teamId] ?? null);
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Reads
    // -------------------------------------------------------------------------

    /**
     * The team's expiry state, or null when it has none.
     *
     * **Ungated on purpose.** Every caller already stands behind its own gate —
     * the layout bundle is membership-checked, the admin grid is NC-admin
     * gated, the providers filter by role — and adding a second membership
     * query here would put one on the critical path of every team load. This
     * method never returns anything that is not already visible to a member.
     *
     * @return array{expiresAt:int, expiresOn:string, daysRemaining:int,
     *               expired:bool, warning:bool, setBy:string, setAt:int,
     *               lastExtendedBy:?string, lastExtendedAt:?int}|null
     */
    public function getExpiry(string $teamId, ?int $now = null): ?array {
        $row = $this->expiryMapper->findByTeam($teamId);
        return $row === null ? null : $this->decorate($row, $now ?? time());
    }

    /**
     * Expiry state for a page of teams, keyed by team id. Teams without an
     * expiry are simply absent from the result.
     *
     * @param string[] $teamIds
     * @return array<string, array<string,mixed>>
     */
    public function getExpiryForTeams(array $teamIds, ?int $now = null): array {
        $now  = $now ?? time();
        $rows = $this->expiryMapper->findByTeams($teamIds);
        $out  = [];
        foreach ($rows as $teamId => $row) {
            $out[$teamId] = $this->decorate($row, $now);
        }
        return $out;
    }

    /**
     * The whole picture for one team, as the Manage team → Maintenance tab and
     * the Team info banner need it: the expiry, whether the viewer may act, and
     * the most recent extension request with its outcome.
     *
     * Team-admin gated — the request form and the decision note behind it are
     * administrative, and SKILLS.md § Permissions means a member who cannot act
     * should not be shown the affordance at all.
     *
     * @return array{eligible:bool, expiry:?array, request:?array, warningDays:int}
     */
    public function getTeamStatus(string $teamId, ?int $now = null): array {
        $this->memberService->requireAdminLevel($teamId);
        $now = $now ?? time();

        $latest = $this->requestMapper->findLatestByTeam($teamId);

        return [
            'eligible'    => $this->isEligible($teamId),
            'expiry'      => $this->getExpiry($teamId, $now),
            'request'     => $latest !== null ? $this->serializeRequest($latest) : null,
            'warningDays' => $this->getWarningDays(),
        ];
    }

    /**
     * Teams whose expiry falls inside the warning window, plus those already
     * past it, newest deadline first.
     *
     * The lower bound is deliberately generous — `$graceDays` back from now —
     * so a team whose date slipped past while nobody was looking keeps showing
     * up rather than dropping silently out of the queue the day after it
     * expired. Since nothing happens on expiry, an item nobody acted on is
     * exactly the item that most needs to stay visible.
     *
     * Eligibility is re-checked here rather than trusted from the write path: a
     * team whose template changed after an expiry was set must stop warning.
     *
     * @return list<array<string,mixed>> each with the decorated expiry plus teamId
     */
    public function findExpiringSoon(?int $now = null, int $graceDays = 365, int $limit = 500): array {
        $now  = $now ?? time();
        $from = $now - ($graceDays * 86400);
        $to   = $now + ($this->getWarningDays() * 86400);

        $rows = $this->expiryMapper->findExpiringBetween($from, $to, $limit);
        if ($rows === []) {
            return [];
        }

        $eligible = $this->eligibilityForTeams(array_column($rows, 'teamId'));

        $out = [];
        foreach ($rows as $row) {
            if (($eligible[$row['teamId']] ?? false) !== true) {
                continue;
            }
            $out[] = ['teamId' => $row['teamId']] + $this->decorate($row, $now);
        }
        return $out;
    }

    /**
     * Every open extension request on the instance. NC-admin gated.
     *
     * @return list<array<string,mixed>>
     */
    public function listPendingRequests(int $limit = 200): array {
        $this->requireNcAdmin();
        $out = [];
        foreach ($this->requestMapper->findAllPending($limit) as $row) {
            $out[] = $this->serializeRequest($row) + [
                'teamName' => $this->resolveTeamName($row->getTeamId()),
                'expiry'   => $this->getExpiry($row->getTeamId()),
            ];
        }
        return $out;
    }

    /**
     * Requests decided since a cut-off, instance-wide. NC-admin gated.
     *
     * Feeds My Work's Completed category on the administrator's side. Same
     * shape as `listPendingRequests()` so a provider can build a row from
     * either without branching on the source.
     *
     * @return list<array<string,mixed>>
     */
    public function listRecentlyDecided(int $since, int $limit = 200): array {
        $this->requireNcAdmin();
        $out = [];
        foreach ($this->requestMapper->findDecidedSince($since, $limit) as $row) {
            $out[] = $this->serializeRequest($row) + [
                'teamName' => $this->resolveTeamName($row->getTeamId()),
                'expiry'   => $this->getExpiry($row->getTeamId()),
            ];
        }
        return $out;
    }

    /**
     * The same, for a set of teams the caller already administers.
     *
     * **Deliberately not NC-admin gated, and it does not check membership
     * either** — it cannot, because it takes a list rather than a single team.
     * The caller must pass only teams it has already established the viewer
     * administers. `TeamExpiryTeamWorkProvider` does that with the same
     * `isTeamAdmin()` filter it applies to the expiry sweep, and My Work
     * re-filters team-scoped providers on the way out.
     *
     * @param string[] $teamIds
     * @return list<array<string,mixed>>
     */
    public function listRecentlyDecidedForTeams(array $teamIds, int $since, int $limit = 200): array {
        $out = [];
        foreach ($this->requestMapper->findDecidedSinceForTeams($teamIds, $since, $limit) as $row) {
            $out[] = $this->serializeRequest($row) + [
                'teamName' => $this->resolveTeamName($row->getTeamId()),
            ];
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Writes — Nextcloud admin
    // -------------------------------------------------------------------------

    /**
     * Set or replace a team's expiry. NC-admin gated.
     *
     * Passing null clears the expiry. Either way, any open extension request is
     * closed as superseded — the admin has just answered the question the
     * request was asking, by a different route.
     *
     * @param string|null $expiresOn 'YYYY-MM-DD', or null to clear.
     */
    public function setExpiry(string $teamId, ?string $expiresOn): ?array {
        $this->requireNcAdmin();
        $actor = $this->requireUid();
        $now   = time();

        if (!$this->isEligible($teamId)) {
            throw new ValidationException(
                'Only Collaboration and Project teams can have an expiration date.',
            );
        }

        if ($expiresOn === null || trim($expiresOn) === '') {
            return $this->clearExpiry($teamId, $actor, $now);
        }

        $expiresAt = $this->parseDate($expiresOn, $now);
        $existing  = $this->expiryMapper->findByTeam($teamId);

        // A first date and a later one are different events even though they go
        // through the same write. The audit log distinguishes them, and so does
        // the row: set_by/set_at keep naming whoever originally decided this
        // team should expire.
        if ($existing === null) {
            $this->expiryMapper->upsert($teamId, $expiresAt, $actor, $now);
            $this->audit($teamId, 'team.expiry_set', $actor, [
                'expiresOn' => $this->formatDate($expiresAt),
            ]);
        } else {
            $this->expiryMapper->upsert(
                $teamId,
                $expiresAt,
                $existing['setBy'],
                $existing['setAt'],
                $actor,
                $now,
            );
            $this->audit($teamId, 'team.expiry_extended', $actor, [
                'from' => $this->formatDate($existing['expiresAt']),
                'to'   => $this->formatDate($expiresAt),
            ]);
        }

        $this->supersedeOpenRequests($teamId, $actor, $now);

        return $this->getExpiry($teamId, $now);
    }

    /**
     * Approve an open extension request. NC-admin gated.
     *
     * `$grantedOn` lets the admin grant a different date than the one asked
     * for — approving for three months when six were requested is a real
     * outcome and should not force a denial. Null means "grant what was asked".
     */
    public function approveRequest(int $requestId, ?string $grantedOn = null, string $note = ''): array {
        $this->requireNcAdmin();
        $actor = $this->requireUid();
        $now   = time();

        $request = $this->loadPendingRequest($requestId);
        $teamId  = $request->getTeamId();

        $grantedAt = ($grantedOn === null || trim($grantedOn) === '')
            ? $request->getProposedUntil()
            : $this->parseDate($grantedOn, $now);

        // A request whose proposed date has itself gone stale while it sat in
        // the queue would otherwise be approved into the past.
        if ($grantedAt <= $now) {
            throw new ValidationException(
                'The granted date is in the past. Choose a new date when approving.',
            );
        }

        $existing = $this->expiryMapper->findByTeam($teamId);
        $this->expiryMapper->upsert(
            $teamId,
            $grantedAt,
            $existing['setBy'] ?? $actor,
            $existing['setAt'] ?? $now,
            $actor,
            $now,
        );

        $request->setStatus(TeamExpiryRequest::STATUS_APPROVED);
        $request->setDecidedBy($actor);
        $request->setDecidedAt($now);
        $request->setGrantedUntil($grantedAt);
        $request->setDecisionNote($this->capReason($note) ?: null);
        $this->requestMapper->update($request);

        $this->audit($teamId, 'team.expiry_extension_approved', $actor, [
            'requestId'   => $requestId,
            'requestedBy' => $request->getRequestedBy(),
            'proposedOn'  => $this->formatDate($request->getProposedUntil()),
            'grantedOn'   => $this->formatDate($grantedAt),
            'from'        => $existing !== null ? $this->formatDate($existing['expiresAt']) : null,
        ]);

        // Any other request that was open for this team is now answered too.
        $this->supersedeOpenRequests($teamId, $actor, $now);

        $this->notifyRequester($request, 'expiry_request_approved', $grantedAt, $note);

        return $this->serializeRequest($request);
    }

    /** Deny an open extension request. NC-admin gated. The expiry is untouched. */
    public function denyRequest(int $requestId, string $note = ''): array {
        $this->requireNcAdmin();
        $actor = $this->requireUid();
        $now   = time();

        $request = $this->loadPendingRequest($requestId);

        $request->setStatus(TeamExpiryRequest::STATUS_DENIED);
        $request->setDecidedBy($actor);
        $request->setDecidedAt($now);
        $request->setGrantedUntil(null);
        $request->setDecisionNote($this->capReason($note) ?: null);
        $this->requestMapper->update($request);

        $this->audit($request->getTeamId(), 'team.expiry_extension_denied', $actor, [
            'requestId'   => $requestId,
            'requestedBy' => $request->getRequestedBy(),
            'proposedOn'  => $this->formatDate($request->getProposedUntil()),
            'note'        => $this->capReason($note),
        ]);

        $this->notifyRequester($request, 'expiry_request_denied', null, $note);

        return $this->serializeRequest($request);
    }

    // -------------------------------------------------------------------------
    // Writes — team admin
    // -------------------------------------------------------------------------

    /**
     * Ask for the expiry to be pushed back. Team-admin gated.
     *
     * One open request per team: a queue that lets six admins of the same team
     * each file a request is a queue nobody works. A team that wants to change
     * what it asked for withdraws and re-files.
     */
    public function requestExtension(string $teamId, string $proposedOn, string $reason = ''): array {
        $this->memberService->requireAdminLevel($teamId);
        $actor = $this->requireUid();
        $now   = time();

        if (!$this->isEligible($teamId)) {
            throw new ValidationException(
                'Only Collaboration and Project teams can have an expiration date.',
            );
        }

        $expiry = $this->expiryMapper->findByTeam($teamId);
        if ($expiry === null) {
            throw new ValidationException(
                'This team has no expiration date, so there is nothing to extend.',
            );
        }

        if ($this->requestMapper->findPendingByTeam($teamId) !== null) {
            throw new ValidationException(
                'An extension request for this team is already waiting for a decision.',
            );
        }

        $proposedAt = $this->parseDate($proposedOn, $now);
        if ($proposedAt <= $expiry['expiresAt']) {
            throw new ValidationException(
                'The requested date must be later than the current expiration date.',
            );
        }

        $request = new TeamExpiryRequest();
        $request->setTeamId($teamId);
        $request->setRequestedBy($actor);
        $request->setRequestedAt($now);
        $request->setProposedUntil($proposedAt);
        $request->setReason($this->capReason($reason) ?: null);
        $request->setStatus(TeamExpiryRequest::STATUS_PENDING);

        /** @var TeamExpiryRequest $request */
        $request = $this->requestMapper->insert($request);

        $this->audit($teamId, 'team.expiry_extension_requested', $actor, [
            'requestId'  => $request->getId(),
            'currentOn'  => $this->formatDate($expiry['expiresAt']),
            'proposedOn' => $this->formatDate($proposedAt),
            'reason'     => $this->capReason($reason),
        ]);

        return $this->serializeRequest($request);
    }

    /**
     * Withdraw the team's own open request. Team-admin gated.
     *
     * Recorded as superseded by the withdrawing user rather than deleted, so
     * the admin queue's "this disappeared" has a reason attached to it.
     */
    public function withdrawRequest(string $teamId): void {
        $this->memberService->requireAdminLevel($teamId);
        $actor = $this->requireUid();
        $now   = time();

        $request = $this->requestMapper->findPendingByTeam($teamId);
        if ($request === null) {
            throw new NotFoundException('There is no open extension request for this team.');
        }

        $this->requestMapper->supersedePendingForTeam($teamId, $actor, $now);
        $this->audit($teamId, 'team.expiry_extension_withdrawn', $actor, [
            'requestId' => $request->getId(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Creation path
    // -------------------------------------------------------------------------

    /**
     * Write the expiry chosen while a team was being created.
     *
     * **The one method that does not check a level**, because at the moment it
     * runs the caller is mid-creation: the wizard's user is about to become the
     * team's owner but the Circles rows that would prove it may not be settled
     * yet, and the bulk importer runs as the importing admin rather than as any
     * member of the team it just made. Callers are TeamService's creation flow
     * and TeamImportService, both of which have already established that the
     * user is allowed to create this team.
     *
     * Silently does nothing for an ineligible template, so the creation flow
     * does not have to branch: hand it whatever the wizard collected and a
     * Department team simply comes out without an expiry.
     *
     * @param string|null $expiresOn 'YYYY-MM-DD', or null for no expiry.
     */
    public function setAtCreation(string $teamId, ?string $type, ?string $expiresOn, string $actorUid): void {
        if ($expiresOn === null || trim($expiresOn) === '') {
            return;
        }
        if (!self::isEligibleType($type)) {
            return;
        }

        $now = time();
        try {
            $expiresAt = $this->parseDate($expiresOn, $now);
        } catch (ValidationException $e) {
            // A bad date must not cost the caller its team. The team is already
            // created by this point; refusing here would leave it half-made.
            $this->logger->warning('[TeamHub][TeamExpiry] Rejected expiry at creation', [
                'teamId' => $teamId, 'reason' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return;
        }

        $this->expiryMapper->upsert($teamId, $expiresAt, $actorUid, $now);
        $this->audit($teamId, 'team.expiry_set', $actorUid, [
            'expiresOn' => $this->formatDate($expiresAt),
            'origin'    => 'creation',
        ]);
    }

    /**
     * Drop everything this feature stores for a team.
     *
     * Called from the team-deletion path. Unlike most `teamhub_*` tables (see
     * HANDOFF.md — nothing is purged on team deletion today), these two are
     * cheap to clean and leaving them would let a recreated team with the same
     * id inherit a stranger's expiry.
     */
    public function purgeTeam(string $teamId): void {
        try {
            $this->expiryMapper->deleteByTeam($teamId);
            $this->requestMapper->deleteByTeam($teamId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamExpiry] Purge failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Dates
    // -------------------------------------------------------------------------

    /**
     * 'YYYY-MM-DD' → Unix timestamp at 23:59:59 UTC on that day.
     *
     * Rejects anything that is not exactly that shape, anything in the past,
     * and anything beyond MAX_YEARS_AHEAD. `checkdate()` is what catches
     * 2026-02-30, which `DateTimeImmutable` would happily roll into March.
     */
    public function parseDate(string $date, ?int $now = null): int {
        $now  = $now ?? time();
        $date = trim($date);

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            throw new ValidationException('The expiration date must be in YYYY-MM-DD format.');
        }
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            throw new ValidationException('That is not a real date.');
        }

        try {
            $dt = new \DateTimeImmutable($date . ' 23:59:59', new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            throw new ValidationException('That is not a real date.');
        }

        $ts = $dt->getTimestamp();
        if ($ts <= $now) {
            throw new ValidationException('The expiration date must be in the future.');
        }

        $max = (new \DateTimeImmutable('@' . $now))->modify('+' . self::MAX_YEARS_AHEAD . ' years');
        if ($ts > $max->getTimestamp()) {
            throw new ValidationException(sprintf(
                'The expiration date cannot be more than %d years from now.',
                self::MAX_YEARS_AHEAD,
            ));
        }

        return $ts;
    }

    /** Unix timestamp → 'YYYY-MM-DD' in UTC. Null in, null out. */
    public function formatDate(?int $ts): ?string {
        if ($ts === null) {
            return null;
        }
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
    }

    /**
     * The date the wizard's picker should open on: today + DEFAULT_PICKER_MONTHS.
     *
     * Computed server-side so the wizard, the importer's documentation and the
     * admin UI cannot drift apart on what "the default" is.
     */
    public function defaultPickerDate(?int $now = null): string {
        $base = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new \DateTimeZone('UTC'));
        return $base->modify('+' . self::DEFAULT_PICKER_MONTHS . ' months')->format('Y-m-d');
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Add the derived fields every consumer wants, so the countdown is computed
     * in one place rather than three times in Vue.
     *
     * `daysRemaining` is negative once the date has passed, which is what lets
     * the UI say "expired 3 days ago" without a second field.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decorate(array $row, int $now): array {
        $expiresAt = (int)$row['expiresAt'];
        $days      = (int)floor(($expiresAt - $now) / 86400);

        return [
            'expiresAt'      => $expiresAt,
            'expiresOn'      => $this->formatDate($expiresAt),
            'daysRemaining'  => $days,
            'expired'        => $expiresAt <= $now,
            'warning'        => $expiresAt > $now && $days < $this->getWarningDays(),
            'setBy'          => $row['setBy'],
            'setAt'          => $row['setAt'],
            'lastExtendedBy' => $row['lastExtendedBy'],
            'lastExtendedAt' => $row['lastExtendedAt'],
        ];
    }

    /** @return array<string,mixed> */
    private function serializeRequest(TeamExpiryRequest $row): array {
        return [
            'id'            => $row->getId(),
            'teamId'        => $row->getTeamId(),
            'requestedBy'   => $row->getRequestedBy(),
            'requestedName' => $this->displayName($row->getRequestedBy()),
            'requestedAt'   => $row->getRequestedAt(),
            'proposedUntil' => $row->getProposedUntil(),
            'proposedOn'    => $this->formatDate($row->getProposedUntil()),
            'reason'        => $row->getReason(),
            'status'        => $row->getStatus(),
            'decidedBy'     => $row->getDecidedBy(),
            'decidedName'   => $row->getDecidedBy() !== null ? $this->displayName($row->getDecidedBy()) : null,
            'decidedAt'     => $row->getDecidedAt(),
            'grantedUntil'  => $row->getGrantedUntil(),
            'grantedOn'     => $this->formatDate($row->getGrantedUntil()),
            'decisionNote'  => $row->getDecisionNote(),
        ];
    }

    private function clearExpiry(string $teamId, string $actor, int $now): ?array {
        $existing = $this->expiryMapper->findByTeam($teamId);
        if ($existing === null) {
            // Already clear. Not an error — the caller asked for a state that
            // already holds, and reporting that as a failure would make the
            // admin grid's clear button fail on a double click.
            return null;
        }

        $this->expiryMapper->deleteByTeam($teamId);
        $this->audit($teamId, 'team.expiry_cleared', $actor, [
            'was' => $this->formatDate($existing['expiresAt']),
        ]);
        $this->supersedeOpenRequests($teamId, $actor, $now);

        return null;
    }

    private function supersedeOpenRequests(string $teamId, string $actor, int $now): void {
        try {
            $this->requestMapper->supersedePendingForTeam($teamId, $actor, $now);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamExpiry] Could not supersede open requests', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    private function loadPendingRequest(int $requestId): TeamExpiryRequest {
        $request = $this->requestMapper->findById($requestId);
        if ($request === null) {
            throw new NotFoundException('That extension request no longer exists.');
        }
        if ($request->getStatus() !== TeamExpiryRequest::STATUS_PENDING) {
            // Somebody else decided it while this row was on screen.
            throw new ValidationException('That extension request has already been decided.');
        }
        return $request;
    }

    private function capReason(string $reason): string {
        $reason = trim($reason);
        if ($reason === '') {
            return '';
        }
        return mb_strlen($reason) > self::REASON_MAX_LENGTH
            ? mb_substr($reason, 0, self::REASON_MAX_LENGTH)
            : $reason;
    }

    private function audit(string $teamId, string $eventType, string $actor, array $metadata): void {
        $this->auditService->log($teamId, $eventType, $actor, 'team', $teamId, $metadata);
    }

    private function requireUid(): string {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new AccessDeniedException('Not authenticated');
        }
        return $user->getUID();
    }

    /**
     * Defence in depth. Every route into these methods already carries
     * `#[AuthorizedAdminSetting]`, and this check is here for the reason
     * SKILLS.md § Security standards gives: "the frontend won't call this" is
     * not a security boundary, and neither is "the router won't route this".
     */
    private function requireNcAdmin(): void {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new AccessDeniedException('Not authenticated');
        }
        if (!$this->groupManager->isAdmin($user->getUID())) {
            throw new AccessDeniedException('Nextcloud administrator privileges are required.');
        }
    }

    /** True when the given uid is a Nextcloud administrator. */
    public function isNcAdmin(string $uid): bool {
        return $this->groupManager->isAdmin($uid);
    }

    private function displayName(string $uid): string {
        $user = $this->userManager->get($uid);
        return $user !== null ? ($user->getDisplayName() ?: $uid) : $uid;
    }

    /**
     * Team display name from circles_circle, falling back to the id.
     *
     * Same three-way preference the admin grid uses (display_name →
     * sanitized_name → name with the legacy `app:circles:` prefix stripped),
     * because the two lists sit next to each other in the same tab and must
     * not disagree about what a team is called.
     */
    public function resolveTeamName(string $teamId): string {
        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('name', 'display_name', 'sanitized_name')
                ->from('circles_circle')
                ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();

            if ($row === false) {
                return $teamId;
            }
            if (!empty($row['display_name'])) {
                return (string)$row['display_name'];
            }
            if (!empty($row['sanitized_name'])) {
                return (string)$row['sanitized_name'];
            }
            $raw = (string)($row['name'] ?? '');
            if (str_starts_with($raw, 'app:circles:')) {
                return substr($raw, strlen('app:circles:'));
            }
            return $raw !== '' ? $raw : $teamId;
        } catch (\Throwable) {
            return $teamId;
        }
    }

    /**
     * The team's owner as uid, display name and email address (v4.6.16).
     *
     * Same join `MaintenanceService::listTeams()` uses for its Owner column —
     * level 9, status Member, user_type 1 — so the two agree about who owns a
     * team. `user_id` is the uid for `user_type = 1` rows; it is only a
     * human-readable label on group and circle rows, which this filter excludes.
     *
     * Returns null when the team has no owner (an orphan the admin panel already
     * reports) or when the account behind the row no longer exists. The email is
     * null when the account has no address configured — a real and common case,
     * and the caller must not offer to write to somebody who cannot be reached.
     *
     * @return array{uid: string, displayName: string, email: ?string}|null
     */
    public function resolveTeamOwner(string $teamId): ?array {
        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('user_id')
                ->from('circles_member')
                ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->eq('level', $qb->createNamedParameter(9, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('Member')))
                ->andWhere($qb->expr()->eq('user_type', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();

            if ($row === false || empty($row['user_id'])) {
                return null;
            }

            $uid  = (string)$row['user_id'];
            $user = $this->userManager->get($uid);
            if ($user === null) {
                return null;
            }

            $email = $user->getEMailAddress();
            return [
                'uid'         => $uid,
                'displayName' => $user->getDisplayName() ?: $uid,
                'email'       => ($email !== null && $email !== '') ? $email : null,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamExpiry] owner lookup failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }
    }

    /**
     * Where a "write to this person" control should send the current user
     * (v4.6.17).
     *
     * **Nextcloud Mail only when Mail is actually usable.** v4.6.16 asked
     * `isEnabledForUser('mail')`, which answers "is the app installed and
     * switched on" — not "can this person send a message with it". On an
     * install where Mail ships enabled and nobody has added an account, that
     * sent the reader to `/apps/mail/compose`, which answers a request to write
     * one email by asking them to configure an IMAP account. Justin hit exactly
     * that on 2026-08-09; `oc_mail_accounts` on that instance holds zero rows.
     *
     * So the test is now whether **the viewer** has at least one account in
     * Mail. It is per-user by necessity: Mail accounts are personal, and an
     * administrator with none needs the system handler even on an instance
     * where their colleagues use Mail every day.
     *
     * Fails to `mailto:`, always. Every uncertainty here — no Mail, no table,
     * an unreadable table, a route that has moved — resolves to the handler the
     * operating system already has, because that one cannot be misconfigured
     * by us.
     *
     * Subject and body are the caller's: this owns the choice of client, not
     * what gets written. Either way the message is composed, read and sent by
     * the user. Nothing leaves the server on their behalf.
     *
     * v4.6.26 — the client choice and the URL shape moved to
     * `MailClientService`, which was a third caller away from being copied
     * again. Behaviour is unchanged bar two corrections that came with the
     * move: the address is validated before it reaches the URL, and the
     * request goes to Mail's `/mailto` route directly rather than to
     * `/compose?uri=`, which only parses the URI in PHP and 302s to the same
     * place.
     *
     * Still returns a string: every caller here has exactly one address, and
     * `composeUrl()` only answers `null` when it is given nothing usable —
     * which for this method means an address that failed validation. A plain
     * `mailto:` with the raw address is the honest fallback there, since the
     * caller has already decided there is somebody to write to.
     */
    public function mailComposeUrl(string $email, string $subject, string $body): string {
        return $this->mailClientService->composeUrl([$email], $subject, $body)
            ?? 'mailto:' . rawurlencode($email);
    }

    /**
     * Tell the requester what was decided.
     *
     * Non-fatal by construction: a notification that cannot be delivered must
     * not roll back a decision the admin has already made and that is already
     * in the audit log.
     */
    private function notifyRequester(
        TeamExpiryRequest $request,
        string            $subject,
        ?int              $grantedAt,
        string            $note,
    ): void {
        try {
            $actor    = $this->userSession->getUser();
            $teamId   = $request->getTeamId();
            $teamName = $this->resolveTeamName($teamId);
            $link     = $this->urlGenerator->linkToRouteAbsolute('teamhub.page.index')
                . '?team=' . urlencode($teamId);

            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($request->getRequestedBy())
                ->setDateTime(new \DateTime())
                ->setObject($subject, (string)$request->getId())
                ->setSubject($subject, [
                    'adminUid'  => $actor?->getUID() ?? '',
                    'adminName' => $actor !== null ? ($actor->getDisplayName() ?: $actor->getUID()) : '',
                    'teamId'    => $teamId,
                    'teamName'  => $teamName,
                    'grantedOn' => $this->formatDate($grantedAt) ?? '',
                    'note'      => $this->capReason($note),
                ])
                ->setLink($link);
            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamExpiry] Could not notify requester', [
                'requestId' => $request->getId(), 'error' => $e->getMessage(),
                'app' => Application::APP_ID,
            ]);
        }
    }
}
