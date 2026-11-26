<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Per-user TeamHub preferences (v4.4.12).
 *
 * User-scoped UI preferences that are not tied to a team and not part of
 * the layout payload. Stored in `oc_preferences` under the `teamhub` app
 * id via IConfig::get/setUserValue — the same mechanism
 * LayoutController uses for the personal default layout.
 *
 * Kept separate from LayoutController because these are app-chrome
 * preferences rather than per-team view state, and separate from
 * LicenseController because a UI preference has no business riding on a
 * licensing endpoint even though the first consumer only renders on
 * unlicensed instances.
 *
 * No membership check is possible or needed: every value here is scoped
 * to the calling user's own account and nothing else is readable.
 */
class PreferencesController extends Controller {

    /**
     * Show the "Need help getting started?" callout above the sidebar help
     * button. '1' = show (default for every user, including existing ones),
     * '0' = hidden. Only ever rendered on unlicensed instances, because the
     * help button it points at is itself licence-gated — see App.vue.
     */
    public const PREF_GETTING_STARTED = 'getting_started_hint';

    /**
     * v4.20.15 — let the people on my teams see my busy times on the team
     * calendar. '1' = shown (the default), '0' = left out. Read by
     * `TeamBusyService`, which leaves a member who switched it off out of the
     * list the team calendar asks Nextcloud's free/busy about. It is not a
     * security boundary and is not described as one: Nextcloud answers the
     * same free/busy question to any user who adds this person to an event in
     * Calendar. What it controls is whether TeamHub puts the blocks on a
     * shared screen without being asked. DESIGN §2.267.
     */
    public const PREF_SHOW_BUSY = 'busy_on_team_calendars';

    public function __construct(
        string $appName,
        IRequest $request,
        private IConfig $config,
        private IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/v1/preferences
     *
     * @return JSONResponse{gettingStartedHint: bool, showBusyOnTeamCalendars: bool}
     */
    #[NoAdminRequired]
    public function getPreferences(): JSONResponse {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse($this->currentPreferences($uid));
    }

    /**
     * GET /api/v1/welcome-message
     *
     * The welcome page's subline, as a Nextcloud administrator wrote it, or
     * '' when none is set — in which case the page uses the line TeamHub
     * ships (v4.13.56).
     *
     * **Its own endpoint, and not a field on `getPreferences()` above.** That
     * method's contract is in this class's docblock: every value it returns is
     * scoped to the calling user's own account. This one is an instance-wide
     * setting and belongs to nobody, so putting it there would make that
     * sentence false for the next person who reads it.
     *
     * **And not on `/api/v1/admin/settings`**, which is where the value is
     * written. That endpoint is NC-admin gated, so every ordinary user would
     * get a 403 and silently fall back to the shipped line — exactly the bug
     * v4.11.33 fixed for the create-team dialog's own admin-written sentence
     * (`PolicyService::templatesForWizard()` carries that note).
     *
     * #[NoAdminRequired]: the value is shown to every user by design, so
     * every authenticated user may read it. Nothing about it is secret and
     * nothing here reveals who wrote it.
     *
     * @return JSONResponse{welcomeMessage: string}
     */
    #[NoAdminRequired]
    public function getWelcomeMessage(): JSONResponse {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse([
            'welcomeMessage' => $this->config->getAppValue(Application::APP_ID, 'welcomeMessage', ''),
        ]);
    }

    /**
     * PUT /api/v1/preferences
     * Body: { gettingStartedHint?: bool, showBusyOnTeamCalendars?: bool }
     *
     * Only keys present in the body are written, so a future preference can
     * be added without every caller having to send the whole set.
     *
     * CSRF-protected (no #[NoCSRFRequired]) — this is a state-changing
     * request and @nextcloud/axios sends the request token automatically.
     *
     * @return JSONResponse{gettingStartedHint: bool, showBusyOnTeamCalendars: bool}
     */
    #[NoAdminRequired]
    public function savePreferences(): JSONResponse {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $body = $this->request->getParams();

        if (array_key_exists('gettingStartedHint', $body)) {
            $raw = $body['gettingStartedHint'];
            // Accept bool, int, and the '0'/'1'/'true'/'false' strings a
            // form post can produce. Anything else is a bad request rather
            // than a silent coercion to false.
            $val = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($val === null) {
                return new JSONResponse(
                    ['error' => 'gettingStartedHint must be a boolean'],
                    Http::STATUS_BAD_REQUEST,
                );
            }
            $this->config->setUserValue(
                $uid,
                Application::APP_ID,
                self::PREF_GETTING_STARTED,
                $val ? '1' : '0',
            );
        }

        if (array_key_exists('showBusyOnTeamCalendars', $body)) {
            $val = filter_var($body['showBusyOnTeamCalendars'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($val === null) {
                return new JSONResponse(
                    ['error' => 'showBusyOnTeamCalendars must be a boolean'],
                    Http::STATUS_BAD_REQUEST,
                );
            }
            $this->config->setUserValue(
                $uid,
                Application::APP_ID,
                self::PREF_SHOW_BUSY,
                $val ? '1' : '0',
            );
        }

        return new JSONResponse($this->currentPreferences($uid));
    }

    /**
     * Every preference this controller owns, read for one user. One place, so
     * GET and PUT cannot answer different shapes.
     *
     * @return array{gettingStartedHint: bool, showBusyOnTeamCalendars: bool}
     */
    private function currentPreferences(string $uid): array {
        return [
            'gettingStartedHint' => $this->config->getUserValue(
                $uid,
                Application::APP_ID,
                self::PREF_GETTING_STARTED,
                '1',
            ) === '1',
            'showBusyOnTeamCalendars' => $this->config->getUserValue(
                $uid,
                Application::APP_ID,
                self::PREF_SHOW_BUSY,
                '1',
            ) !== '0',
        ];
    }
}
