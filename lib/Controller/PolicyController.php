<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\PolicyApplyService;
use OCA\TeamHub\Service\PolicyPropagationService;
use OCA\TeamHub\Service\PolicyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Templates and policy profiles — admin endpoints (v4.8.2, Track F2a).
 *
 * Design: `TRACK-F2-DESIGN.md`. Reasoning: `DESIGN.md` §2.104–§2.108.
 *
 * **No longer definitions only.** That was true through v4.8.15 and the note
 * here still said so two versions after it stopped being: v4.8.16 added
 * `applyProfile` / `clearProfile`, which write a team's settings, and v4.8.27
 * added the two `propagate` routes, which write *many* teams' settings from one
 * request. Reclassification (F2d) is still unbuilt.
 *
 * So the routes fall into two kinds, and the distinction is worth keeping in
 * mind when adding a third: the CRUD routes edit definitions and change no
 * team, while `applyProfile`, `clearProfile` and the two `propagate` routes
 * reach team state. Everything in the second group is Nextcloud-admin gated and
 * audited.
 *
 * Gating follows `TeamImportController` verbatim: `#[AuthorizedAdminSetting]`
 * on **every** method, plus `PolicyService::requireNcAdmin()` in the service as
 * the real boundary — never rely on "the frontend won't call this". Note the
 * consequence that combination carries here as it does there: a *delegated*
 * TeamHub admin passes the attribute and is then refused by the service, which
 * requires full `IGroupManager::isAdmin`. That is deliberate for F2a — a policy
 * profile is an instance governance control and the design pins it to a
 * Nextcloud administrator. Widening it to delegated admins is a
 * permission-model decision, not a default.
 *
 * `#[NoCSRFRequired]` appears only on the read-only GETs.
 */
class PolicyController extends Controller {

    use ExceptionResponseTrait;

    public function __construct(
        string                  $appName,
        IRequest                $request,
        private PolicyService   $policyService,
        // v4.8.16 — assignment to an existing team. Its own service because the
        // write goes through TeamService, which already injects PolicyService;
        // see that class's docblock for the cycle this avoids.
        private PolicyApplyService $policyApplyService,
        // v4.8.27 — rolling a saved profile or template out to its teams.
        private PolicyPropagationService $policyPropagationService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // -------------------------------------------------------------------------
    // Field catalogue
    // -------------------------------------------------------------------------

    /**
     * GET /api/v1/admin/policy/fields
     *
     * What a profile may govern, with each field's enforced/asserted tag.
     * No labels — those are translated client-side from
     * `src/constants/policy.js`, because `check:l10n` scans `src/` only.
     *
     * v4.8.24 — the payload gained `confidentialFiles`, carrying whether the
     * Confidential files app is available, whether any classification label is
     * configured, and the tags a profile may choose from. Additive: `fields` is
     * unchanged and still the only key an older bundle reads.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function fields(): JSONResponse {
        try {
            return new JSONResponse($this->policyService->fieldCatalogue());
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not load the policy field catalogue.');
        }
    }

    /**
     * GET /api/v1/admin/policy/summary
     *
     * Counts only, for the setup checklist on the Team creation tab (v4.8.15).
     * Separate from `listProfiles` / `listTemplates` because the checklist is
     * rendered on a different tab from the editor and loads on mount: sending
     * it the full payloads would fetch every profile's value set on every
     * admin-settings page load, for two numbers.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function summary(): JSONResponse {
        try {
            return new JSONResponse($this->policyService->adminSummary());
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not load the policy summary.');
        }
    }

    // -------------------------------------------------------------------------
    // Profiles
    // -------------------------------------------------------------------------

    /** GET /api/v1/admin/policy/profiles */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function listProfiles(): JSONResponse {
        try {
            return new JSONResponse($this->policyService->listProfiles());
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not load policy profiles.');
        }
    }

    /** GET /api/v1/admin/policy/profiles/{profileKey} */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function showProfile(string $profileKey): JSONResponse {
        try {
            return new JSONResponse($this->policyService->getProfile($profileKey));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not load that policy profile.', [
                'profileKey' => $profileKey,
            ]);
        }
    }

    /**
     * POST /api/v1/admin/policy/profiles
     * Body: { profileKey, label, description?, sortIndex?, values? }
     *
     * `values` is a map of field key => { value, mode }. A field left out is
     * *ungoverned*, which is a third state distinct from false.
     *
     * @param array<string,mixed> $values
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function createProfile(
        string  $profileKey = '',
        string  $label = '',
        ?string $description = null,
        int     $sortIndex = 0,
        array   $values = [],
    ): JSONResponse {
        try {
            return new JSONResponse(
                $this->policyService->createProfile($profileKey, $label, $description, $sortIndex, $values),
            );
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not create the policy profile.', [
                'profileKey' => $profileKey,
            ]);
        }
    }

    /**
     * PUT /api/v1/admin/policy/profiles/{profileKey}
     * Body: { label, description?, sortIndex?, values? }
     *
     * Omitting `values` leaves the profile's value set untouched, so a rename
     * does not have to resubmit what the profile governs. Sending `values: {}`
     * clears it — an empty profile is legal and governs nothing.
     *
     * @param array<string,mixed>|null $values
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function updateProfile(
        string  $profileKey,
        string  $label = '',
        ?string $description = null,
        int     $sortIndex = 0,
        ?array  $values = null,
    ): JSONResponse {
        try {
            return new JSONResponse(
                $this->policyService->updateProfile($profileKey, $label, $description, $sortIndex, $values),
            );
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not update the policy profile.', [
                'profileKey' => $profileKey,
            ]);
        }
    }

    /** DELETE /api/v1/admin/policy/profiles/{profileKey} */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function destroyProfile(string $profileKey): JSONResponse {
        try {
            $this->policyService->deleteProfile($profileKey);
            return new JSONResponse(['status' => 'deleted']);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not delete the policy profile.', [
                'profileKey' => $profileKey,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Assignment to an existing team (v4.8.16, Track F2b — TRACK-F2-DESIGN §4.3)
    //
    // The first routes in this controller that write a *team's* settings rather
    // than a definition. The header note above no longer covers them, which is
    // the point: assignment is a write, and these three are where it happens.
    // -------------------------------------------------------------------------

    /**
     * GET /api/v1/admin/policy/teams/{teamId}/preview?profileKey=…
     *
     * What applying that profile would change. Writes nothing — §4.3 step 1
     * makes the preview mandatory, not optional.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function previewAssignment(string $teamId, string $profileKey = ''): JSONResponse {
        try {
            return new JSONResponse($this->policyApplyService->preview($teamId, $profileKey));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not preview that policy profile.', [
                'teamId'     => $teamId,
                'profileKey' => $profileKey,
            ]);
        }
    }

    /**
     * POST /api/v1/admin/policy/teams/{teamId}
     * Body: { profileKey, reapply? }
     *
     * Assigns the profile **and writes its values into the team**. CSRF-protected
     * and deliberately not `#[NoCSRFRequired]`: it is the state-changing half of
     * this controller.
     *
     * `reapply` only changes which audit event is written —
     * `team.policy_reapplied` rather than `team.policy_applied`, so a report can
     * tell a first rollout from a remediation (§8.1). It is not a different
     * operation and does not relax anything.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function applyProfile(string $teamId, string $profileKey = '', bool $reapply = false): JSONResponse {
        try {
            return new JSONResponse($this->policyApplyService->apply($teamId, $profileKey, $reapply));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not apply that policy profile.', [
                'teamId'     => $teamId,
                'profileKey' => $profileKey,
            ]);
        }
    }

    /**
     * DELETE /api/v1/admin/policy/teams/{teamId}
     *
     * Back to unclassified. **Writes no team setting** — the team keeps whatever
     * values it has and stops being compared (§4.3, last paragraph).
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function clearProfile(string $teamId): JSONResponse {
        try {
            $this->policyApplyService->clear($teamId);
            return new JSONResponse(['status' => 'cleared']);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not clear the policy profile.', [
                'teamId' => $teamId,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Templates
    // -------------------------------------------------------------------------

    /**
     * GET /api/v1/admin/policy/templates
     *
     * v4.8.3 — carries `liveAtCreation: true`. The wizard and the CSV importer
     * both read the table now, so an edit here reaches team creation.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function listTemplates(): JSONResponse {
        try {
            return new JSONResponse($this->policyService->listTemplates());
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not load team templates.');
        }
    }

    /**
     * GET /api/v1/policy/creation
     *
     * What the create-team wizard needs to render itself: whether the caller
     * may pick a classification, which one applies by default, and what each
     * one governs so the wizard can render those controls read-only.
     *
     * **Not admin-gated, but asymmetric.** A Nextcloud administrator gets every
     * profile; everybody else gets only the one that will actually apply to
     * them and `canChoose: false`. See `PolicyService::creationContext()`.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function creationContext(): JSONResponse {
        try {
            return new JSONResponse($this->policyService->creationContext());
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not load classification settings.');
        }
    }

    // `PUT /api/v1/admin/policy/default` lived here in v4.8.4 and is gone in
    // v4.8.5: the default policy is a per-template setting now, edited through
    // `updateTemplate` below. See Version000408005.

    /**
     * GET /api/v1/templates
     *
     * The template set for the create-team wizard. **Not admin-gated** — every
     * user who may create a team needs it, and it carries no more than the
     * wizard already renders. No profile, no assignment, no instance config.
     *
     * Fetched once when the wizard mounts, so switching template stays a local
     * lookup and the round trip is not in the critical path.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function templatesForCreation(): JSONResponse {
        try {
            return new JSONResponse($this->policyService->templatesForCreation());
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not load team templates.');
        }
    }

    /**
     * PUT /api/v1/admin/policy/templates/{templateKey}
     * Body: { label, description?, apps?, modules?, expiryEnabled?,
     *         expiryDefaultDays?, preselectConfig?, sortIndex? }
     *
     * `preselectConfig` is masked to `CirclesConfig::MANAGED_BITS` in the
     * service — it is admin-supplied, and a system bit on a user team corrupts
     * the circle. `expiryDefaultDays` is bounded there too, and forced to 0
     * when `expiryEnabled` is false.
     *
     * @param list<string> $apps
     * @param list<string> $modules
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function updateTemplate(
        string  $templateKey,
        string  $label = '',
        ?string $description = null,
        array   $apps = [],
        array   $modules = [],
        bool    $expiryEnabled = false,
        int     $expiryDefaultDays = 0,
        int     $preselectConfig = 0,
        int     $sortIndex = 0,
        ?string $defaultProfileKey = null,
    ): JSONResponse {
        try {
            return new JSONResponse($this->policyService->updateTemplate(
                $templateKey, $label, $description, $apps, $modules,
                $expiryEnabled, $expiryDefaultDays, $preselectConfig, $sortIndex,
                $defaultProfileKey,
            ));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not update the team template.', [
                'templateKey' => $templateKey,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Conflict matrix
    // -------------------------------------------------------------------------

    /**
     * GET /api/v1/admin/policy/conflicts
     *
     * Template × profile pairings where the profile would strip something the
     * template provides. A warning surface, not a save-blocker.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function conflicts(): JSONResponse {
        try {
            return new JSONResponse(['conflicts' => $this->policyService->conflictMatrix()]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not build the conflict matrix.');
        }
    }

    // -------------------------------------------------------------------------
    // Propagation (v4.8.27)
    // -------------------------------------------------------------------------

    /**
     * POST /api/v1/admin/policy/profiles/{profileKey}/propagate
     * Body: { teamIds: ["…"] }
     *
     * Apply the profile's current values to the named teams, and report per
     * team. The teams come from the plan the PUT returned — the administrator
     * either accepted the compliant subset or chose to force all of them, and
     * that decision is not something the server can recompute, because
     * compliance was measured against values the save has already overwritten.
     *
     * Every id is still checked to carry the profile before anything is
     * written; what the list carries is the choice, not the authorisation.
     *
     * @param list<string> $teamIds
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function propagateProfile(string $profileKey, array $teamIds = []): JSONResponse {
        try {
            return new JSONResponse(
                $this->policyPropagationService->propagateProfile($profileKey, $teamIds),
            );
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not roll the policy profile out.', [
                'profileKey' => $profileKey,
            ]);
        }
    }

    /**
     * POST /api/v1/admin/policy/templates/{templateKey}/propagate
     * Body: { teamIds: ["…"] }
     *
     * Bring the named teams' apps in line with the template. Adds what the
     * template gained; switches off what it lost **only where TeamHub created
     * it**, so a discovered Deck board is never taken away by a template edit.
     *
     * @param list<string> $teamIds
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function propagateTemplate(string $templateKey, array $teamIds = []): JSONResponse {
        try {
            return new JSONResponse(
                $this->policyPropagationService->propagateTemplate($templateKey, $teamIds),
            );
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not roll the template out.', [
                'templateKey' => $templateKey,
            ]);
        }
    }
}
