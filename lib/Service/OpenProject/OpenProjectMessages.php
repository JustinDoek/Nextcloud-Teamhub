<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject;

use OCA\TeamHub\Exception\OpenProjectException;
use OCP\IL10N;

/**
 * One sentence per error code, in two registers (v4.9.3).
 *
 * The user sentence says what the person in front of the widget can do. The
 * administrator sentence says what is wrong with the environment and where
 * to fix it. Neither ever includes a response body, a host, a token or a
 * status line — those stay in the log.
 *
 * The user sentences are deliberately the **same source strings** as
 * `src/lib/openProject.js` uses: Nextcloud serves `l10n/<lang>.json` to PHP's
 * `IL10N` and to the frontend's `t()` alike, so one translation covers both
 * and `npm run check:l10n` (which scans `src/` only) keeps the set complete.
 * Change one side, change the other.
 */
final class OpenProjectMessages {

    public function __construct(private IL10N $l) {
    }

    public function userMessage(string $code): string {
        return match ($code) {
            // v4.9.16 — the module itself, before anything about the official
            // app. One sentence for both: a member cannot tell a licence from
            // a switch, and should not have to.
            OpenProjectException::MODULE_UNLICENSED,
            OpenProjectException::MODULE_DISABLED =>
                $this->l->t('OpenProject is not enabled in TeamHub. Ask your Nextcloud administrator.'),
            OpenProjectException::INTEGRATION_NOT_INSTALLED,
            OpenProjectException::INTEGRATION_DISABLED,
            OpenProjectException::INTEGRATION_INCOMPATIBLE,
            OpenProjectException::HOST_NOT_CONFIGURED =>
                $this->l->t('OpenProject is not available on this Nextcloud. Ask your administrator to set up the OpenProject integration.'),
            OpenProjectException::USER_NOT_CONNECTED =>
                $this->l->t('Connect your OpenProject account in your personal settings to see this project.'),
            OpenProjectException::AUTH_FAILED =>
                $this->l->t('OpenProject no longer accepts your connection. Reconnect your OpenProject account in your personal settings.'),
            OpenProjectException::PERMISSION_DENIED =>
                $this->l->t('You do not have access to this in OpenProject.'),
            OpenProjectException::PROJECT_NOT_FOUND =>
                $this->l->t('The linked OpenProject project no longer exists or is not visible to you.'),
            OpenProjectException::UNSUPPORTED_RESPONSE =>
                $this->l->t('OpenProject answered in a way TeamHub does not understand. This OpenProject version may not be supported.'),
            OpenProjectException::API_UNAVAILABLE =>
                $this->l->t('OpenProject cannot be reached right now.'),
            OpenProjectException::TEMPORARY_FAILURE =>
                $this->l->t('OpenProject had a temporary problem. Try again in a moment.'),
            OpenProjectException::RATE_LIMITED =>
                $this->l->t('OpenProject is receiving too many requests. Try again in a moment.'),
            OpenProjectException::LINK_STALE =>
                $this->l->t('This team was linked to a different OpenProject instance. Ask your Nextcloud administrator.'),
            // v4.9.6 — writes. OpenProject's own sentence travels beside this
            // one (`upstreamMessage`), because the input has to be fixed.
            OpenProjectException::VALIDATION_FAILED =>
                $this->l->t('OpenProject did not accept this.'),
            OpenProjectException::JOB_FAILED =>
                $this->l->t('OpenProject could not finish copying the template.'),
            default =>
                $this->l->t('OpenProject data could not be loaded.'),
        };
    }

    /**
     * Administrator diagnostics are technical and deliberately English: they
     * are read next to the Nextcloud log, which is English too, and they name
     * settings by their English labels.
     */
    public function administratorMessage(string $code): string {
        return match ($code) {
            OpenProjectException::MODULE_UNLICENSED =>
                'The OpenProject module requires an active TeamHub license. Add or renew one under Administration settings → TeamHub → License.',
            OpenProjectException::MODULE_DISABLED =>
                'The OpenProject module is switched off. Enable it under Administration settings → TeamHub → Integrations → Modules.',
            OpenProjectException::INTEGRATION_NOT_INSTALLED =>
                'The "OpenProject Integration" app (integration_openproject) is not installed. Install it from the app store.',
            OpenProjectException::INTEGRATION_DISABLED =>
                'The "OpenProject Integration" app is installed but disabled, or not enabled for this user\'s groups. Enable it under Apps.',
            OpenProjectException::INTEGRATION_INCOMPATIBLE =>
                'The installed "OpenProject Integration" app does not expose the request interface TeamHub relies on. Update the integration app; TeamHub supports its 2.x and 3.x lines.',
            OpenProjectException::HOST_NOT_CONFIGURED =>
                'No OpenProject host is configured. Set the OpenProject instance URL under Administration settings → OpenProject.',
            OpenProjectException::USER_NOT_CONNECTED =>
                'This user has not connected their OpenProject account (Personal settings → OpenProject). With OIDC authorization, check that the user logged in through the configured provider.',
            OpenProjectException::AUTH_FAILED =>
                'OpenProject rejected this user\'s token (401). The user should reconnect; if every user is affected, check the OAuth client or OIDC audience in the integration app.',
            OpenProjectException::PERMISSION_DENIED =>
                'OpenProject refused the request (403). The user lacks a permission in OpenProject, or the API is restricted for this client.',
            OpenProjectException::PROJECT_NOT_FOUND =>
                'OpenProject returned 404 for the linked project. It was deleted, archived, or this user was removed from it.',
            OpenProjectException::UNSUPPORTED_RESPONSE =>
                'OpenProject answered with a body TeamHub could not interpret. Check the OpenProject version (API v3 required) and any proxy in between.',
            OpenProjectException::API_UNAVAILABLE =>
                'The OpenProject host could not be reached from this Nextcloud server. Check the URL, DNS, TLS and any outbound proxy.',
            OpenProjectException::TEMPORARY_FAILURE =>
                'OpenProject answered with a server error or timed out. Check the OpenProject instance; nothing needs to change on the Nextcloud side.',
            OpenProjectException::RATE_LIMITED =>
                'OpenProject is rate-limiting requests from this Nextcloud (429). TeamHub caches aggressively; if this persists, raise the limit on the OpenProject side.',
            OpenProjectException::LINK_STALE =>
                'The integration app now points at a different OpenProject instance than the one this team was linked against. Restore the previous instance URL, or unlink the team under Administration settings → TeamHub → Maintenance and create it again from the OpenProject project template.',
            OpenProjectException::VALIDATION_FAILED =>
                'OpenProject answered 422 to a write: a validation rule on its side refused the input (an identifier already taken, a name too long, a template that cannot be copied). The response carries the sentence OpenProject gave.',
            OpenProjectException::JOB_FAILED =>
                'An OpenProject background job (a project copy) ended in error or failure. Check the job log in OpenProject; the template may reference something the copying user cannot see.',
            default => 'OpenProject request failed. See the Nextcloud log for the endpoint and status.',
        };
    }
}
