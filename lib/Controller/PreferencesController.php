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
     * @return JSONResponse{gettingStartedHint: bool}
     */
    #[NoAdminRequired]
    public function getPreferences(): JSONResponse {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse([
            'gettingStartedHint' => $this->config->getUserValue(
                $uid,
                Application::APP_ID,
                self::PREF_GETTING_STARTED,
                '1',
            ) === '1',
        ]);
    }

    /**
     * PUT /api/v1/preferences
     * Body: { gettingStartedHint: bool }
     *
     * Only keys present in the body are written, so a future preference can
     * be added without every caller having to send the whole set.
     *
     * CSRF-protected (no #[NoCSRFRequired]) — this is a state-changing
     * request and @nextcloud/axios sends the request token automatically.
     *
     * @return JSONResponse{gettingStartedHint: bool}
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

        return new JSONResponse([
            'gettingStartedHint' => $this->config->getUserValue(
                $uid,
                Application::APP_ID,
                self::PREF_GETTING_STARTED,
                '1',
            ) === '1',
        ]);
    }
}
