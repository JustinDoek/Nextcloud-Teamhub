<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject\Transport;

/**
 * Which way TeamHub reaches OpenProject, for one user, right now (v4.13.4).
 *
 * ## The rule (Justin, 2026-10-18)
 *
 * **Prefer our own transport; fall back to the official app.** Spelled out,
 * because "prefer" has to answer three different questions:
 *
 *  1. **Is the direct transport configured at all?** An administrator sets
 *     a host and, for OAuth2, a client. If not, there is nothing to prefer
 *     and the official app answers everything — which is every instance
 *     today, so this whole class is a no-op until somebody configures one.
 *  2. **Has *this user* connected to it?** If yes, direct. A per-user
 *     question, not an instance one, because the realistic rollout is an
 *     administrator configuring the direct transport on a Monday and the
 *     team connecting over the following fortnight.
 *  3. **If not, can the official app still serve them?** If it can and they
 *     are connected there, they keep working through it — nobody loses
 *     their project cockpit on the day the administrator changes a setting.
 *     If it cannot, the answer is the direct transport anyway, so that what
 *     the user is told ("connect your OpenProject account") points at the
 *     flow that will actually work for them.
 *
 * The cost of (3) is honest and worth naming: during a migration two
 * members of one team can be on two different transports, and only the ones
 * on the direct transport can update a work package, because the official
 * app has no PATCH. The UI hides what a transport cannot do rather than
 * offering a button that fails — the same rule CLAUDE.md applies to
 * permissions — so what they see differs, and neither sees something
 * broken.
 *
 * ## Why the cache needs no transport dimension
 *
 * A cached entry is normalised OpenProject data for one user, one team, one
 * project and one host. OpenProject answers per *user*, not per transport,
 * so the same user reading the same host through either route gets the same
 * data, and the host is already part of every key. Two transports pointing
 * at two different hosts are already two different keys. Nothing to add.
 *
 * ## Memoised per request
 *
 * The selection reads appconfig and user config, which do not change
 * mid-request, and it is asked several times per page load (the capability
 * envelope, each widget's service, the My Work provider). The memo is keyed
 * by user id because the answer is per user.
 */
class OpenProjectTransportSelector {

    /** @var array<string, IOpenProjectTransport> memo, by user id */
    private array $chosen = [];

    public function __construct(
        private DirectTransport         $direct,
        private IntegrationAppTransport $integrationApp,
    ) {
    }

    /**
     * The transport to use for `$userId`.
     *
     * Always returns one — a transport that cannot be used still answers
     * `unavailableCode()`, and the client turns that into the error code
     * and the sentence an administrator or a user needs. Returning null
     * here would only move that decision somewhere less able to explain it.
     */
    public function forUser(string $userId): IOpenProjectTransport {
        if (isset($this->chosen[$userId])) {
            return $this->chosen[$userId];
        }
        return $this->chosen[$userId] = $this->choose($userId);
    }

    /**
     * The transport for instance-level questions that have no user: which
     * host is configured, is anything set up at all.
     *
     * Direct when it is configured, the official app otherwise. It
     * deliberately does not consider any user's connection, so that an
     * administrator's diagnostic reads the same whoever is looking at it.
     */
    public function forInstance(): IOpenProjectTransport {
        return $this->direct->isConfigured() ? $this->direct : $this->integrationApp;
    }

    /** Both, for diagnostics that report on each one's state separately. */
    public function direct(): DirectTransport {
        return $this->direct;
    }

    public function integrationApp(): IntegrationAppTransport {
        return $this->integrationApp;
    }

    private function choose(string $userId): IOpenProjectTransport {
        // (1) Nothing set up — the official app owns everything.
        //
        // The test is `isConfigured()`, not `unavailableCode() === null`
        // (v4.13.5). A transport an administrator *has* configured but
        // which cannot run — today, one set to OIDC before that exchange
        // is built — must still be chosen, so the user is told
        // `auth_method_unsupported` rather than something about an
        // integration app the administrator deliberately moved away from.
        // Falling back there would send them to fix the wrong thing.
        if (!$this->direct->isConfigured()) {
            return $this->integrationApp;
        }
        // (2) Configured and this user has connected to it.
        if ($userId !== '' && $this->direct->isUserConnected($userId)) {
            return $this->direct;
        }
        // (3) Configured, but this user has not connected yet. Keep them on
        //     the official app while it can still serve them.
        if ($userId !== ''
            && $this->integrationApp->unavailableCode() === null
            && $this->integrationApp->isUserConnected($userId)) {
            return $this->integrationApp;
        }
        return $this->direct;
    }
}
