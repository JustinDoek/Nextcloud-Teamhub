<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject\Transport;

/**
 * One way of getting an API v3 request to OpenProject and a decoded body
 * back (v4.13.4).
 *
 * ## Why this exists
 *
 * Until 4.13.3 there was exactly one way: the official
 * `integration_openproject` app's `OpenProjectAPIService::request()`.
 * DESIGN §2.120 chose that deliberately — "a dependency on one checked
 * method of another app is cheaper than a second implementation of its
 * hardest part" — and it was the right call for Phase 1. Two things have
 * since made it a liability rather than a saving:
 *
 *  1. **Release coupling.** The official app's latest stable (3.2.0,
 *     2026-09-10) declares `max-version 34`. Nextcloud 35 shipped on
 *     2026-09-15. Until their 3.3.0 is released, every OpenProject surface
 *     in TeamHub is simply absent on 35 — not degraded, absent — and the
 *     date is not ours to set.
 *  2. **PATCH is refused upstream.** `rawRequest()` whitelists GET, POST,
 *     PUT and DELETE and answers anything else with `Bad HTTP method`.
 *     OpenProject updates a work package with PATCH and only PATCH (the
 *     resource's own `update` link says `"method": "patch"`). So write-back
 *     is not a feature we postponed; it is one the dependency forbids.
 *     DESIGN §2.124 and §2.126 both record the postponement and both name
 *     this as the reason.
 *
 * So the dependency becomes **one of two** transports rather than the only
 * one. Everything above this interface — the 15 services, the normalizer,
 * the cache, the messages, the My Work provider, the controllers — is
 * unchanged and cannot tell which one it is talking through.
 *
 * ## The contract
 *
 * `request()` deliberately returns the official app's shape rather than
 * throwing: the decoded body on success, or
 * `['error' => string, 'statusCode' => ?int, 'message' => ?string]` on
 * failure. `OpenProjectClient::classifyError()` then turns that into an
 * `OpenProjectException` code, in one place, for both transports. A
 * transport that classified its own failures would be a second set of error
 * codes to keep in step with `OpenProjectMessages`, and the two would drift.
 *
 * **A transport never throws for an HTTP-level failure.** It may throw only
 * for a programming error. Anything OpenProject (or the network, or the
 * official app) did wrong comes back as the error array.
 *
 * Three optional keys travel on that array, read by `OpenProjectClient` and
 * by nothing else:
 *
 *  - `threw` — the request raised rather than answered (a timeout, a fault
 *    inside the official app). Becomes `temporary_failure`.
 *  - `unsupported` — something answered, but not in a shape that can be a
 *    body. Becomes `unsupported_response`.
 *  - `cause` — the `\Throwable` behind either, attached to the
 *    `OpenProjectException` as its `previous` so the Nextcloud log keeps
 *    the real stack. It is never read for its message and never shown to
 *    anyone.
 *
 * ## What a transport does not do
 *
 * It does not know about teams, projects, links, caches or the TeamHub
 * module gate. It does not build deep links. It does not decide whether the
 * *user* may see something — that is OpenProject's answer, carried in the
 * body. It is the wire and the credential, and nothing else.
 */
interface IOpenProjectTransport {

    /** Through the official `integration_openproject` app. */
    public const ID_INTEGRATION_APP = 'integration_app';
    /** TeamHub's own HTTP client and its own credentials. */
    public const ID_DIRECT = 'direct';

    /**
     * Which transport this is, for diagnostics and the capability envelope.
     * One of the `ID_*` constants.
     */
    public function id(): string;

    /**
     * Why this transport cannot be used on this instance, as an
     * `OpenProjectException` code — or null when it can.
     *
     * Deliberately *not* including TeamHub's module gate: that is the
     * client's question, asked once, above both transports. This answers
     * only "is this particular way of reaching OpenProject set up".
     *
     * Must be cheap: appconfig and class checks, never an HTTP request. It
     * is called on page loads.
     */
    public function unavailableCode(): ?string;

    /**
     * The OpenProject instance URL this transport talks to, without a
     * trailing slash, or '' when none is configured.
     *
     * **This is the only host that will ever be contacted or linked to.**
     * Where it comes from differs per transport; that it is an
     * administrator's and never a user's input does not.
     */
    public function getHost(): string;

    /** How users authenticate here: 'oauth2' or 'oidc'. */
    public function getAuthMethod(): string;

    /**
     * Has this user a credential this transport can use — as far as can be
     * told without an HTTP request. A `true` is a hint; `users/me` is the
     * proof, and the capability probe is what asks for it.
     */
    public function isUserConnected(string $userId): bool;

    /**
     * Can this transport send that HTTP method at all.
     *
     * The honest answer is `false` for PATCH on the official app, and the
     * whole reason this interface has the question: a caller that wants to
     * update a work package has to be able to find out *before* building a
     * request, so the UI can hide the action rather than offer one that
     * fails. See `OpenProjectClient::patch()`.
     */
    public function supportsMethod(string $method): bool;

    /**
     * Send one request as `$userId` and return the decoded body, or the
     * error array described in the interface docblock.
     *
     * `$endpoint` is the path under `/api/v3/` — `projects/12`,
     * `work_packages`. For GET, `$params` is the query. For everything else
     * only `$params['body']` is read, and it is an already-encoded JSON
     * string — the same convention the official app uses, kept so that
     * `OpenProjectClient` encodes once for both transports.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function request(string $userId, string $endpoint, array $params, string $method): array;
}
