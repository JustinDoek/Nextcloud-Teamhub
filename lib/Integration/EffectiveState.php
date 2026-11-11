<?php
declare(strict_types=1);

namespace OCA\TeamHub\Integration;

/**
 * The computed state of one integration package, for one team (v4.13.23).
 *
 * Produced only by {@see \OCA\TeamHub\Service\IntegrationStateService} — the
 * single place that answers "may this run". Every server-side entry point
 * takes one of these and reads {@see $effective} rather than re-deriving the
 * answer from the four governance levels, because four booleans combined in
 * six places is four booleans combined five different ways by the second
 * release.
 *
 * ## The four governance levels, and the two conditions around them
 *
 *   appAvailable        the owning Nextcloud app is installed and enabled.
 *                       Read live from `IAppManager`, never stored: a stored
 *                       copy is a fact that goes stale between the moment an
 *                       administrator disables an app and the moment anything
 *                       re-reads it.
 *   integrationEnabled  a Nextcloud administrator enabled the package under
 *                       Administration → TeamHub → Integrations. Absent means
 *                       disabled: a newly discovered package is off.
 *   includedInTemplate  a Nextcloud administrator included the package in
 *                       this team's template.
 *   shownForTeam        a team administrator has not hidden this capability.
 *                       Applies to visual capabilities only, and is the one
 *                       level a team administrator controls.
 *
 * plus two that are not governance but are conditions all the same:
 *
 *   registered          the package registered itself in this process. An app
 *                       can be installed and enabled and still not register —
 *                       a failed boot, a version that dropped the call — and
 *                       that is a different state from being disabled.
 *   compatible          the package declares support for this TeamHub.
 *   userMayAccessTeam   the caller is a member of the team, or may otherwise
 *                       see it.
 *
 * ## effective, and why shownForTeam is not part of it
 *
 * {@see $effective} is all six of the non-visual conditions. It deliberately
 * excludes {@see $shownForTeam}, because that is the whole point of the
 * governance model: a hidden widget means the package is still effective —
 * My Work keeps contributing, lifecycle hooks keep firing, synchronisation
 * keeps running — and only the card is gone. Code that wants to render asks
 * {@see mayRender()}; code that wants to run a non-visual provider asks
 * {@see $effective}. Mixing the two is the bug this split exists to make
 * impossible.
 */
final class EffectiveState implements \JsonSerializable {

    /** Why a package is not effective. One value, the first that applies. */
    public const REASON_APP_NOT_INSTALLED = 'app_not_installed';
    public const REASON_APP_DISABLED      = 'app_disabled';
    public const REASON_NOT_REGISTERED    = 'not_registered';
    public const REASON_INCOMPATIBLE      = 'incompatible';
    public const REASON_NOT_ENABLED       = 'integration_not_enabled';
    public const REASON_NOT_IN_TEMPLATE   = 'not_included_in_template';
    public const REASON_NO_TEMPLATE       = 'team_has_no_template';
    public const REASON_NO_TEAM_ACCESS    = 'no_team_access';
    public const REASON_HIDDEN_FOR_TEAM   = 'hidden_for_team';

    /**
     * @param string      $appId
     * @param string      $integrationKey
     * @param bool        $appAvailable       owning app installed AND enabled
     * @param bool        $appInstalled       installed, whether or not enabled — lets the
     *                                        administrator see "disabled" rather than "gone"
     * @param bool        $registered         registered in this process
     * @param bool        $compatible         within the package's declared TeamHub range
     * @param bool        $integrationEnabled a Nextcloud administrator enabled it
     * @param bool        $includedInTemplate included in this team's template
     * @param bool        $userMayAccessTeam  the caller may see the team
     * @param bool        $shownForTeam       not hidden by a team administrator
     * @param string|null $unavailableReason  one of the REASON_* constants, or null
     * @param string      $templateKey        the team's template, '' when it has none
     */
    public function __construct(
        public readonly string  $appId,
        public readonly string  $integrationKey,
        public readonly bool    $appAvailable,
        public readonly bool    $appInstalled,
        public readonly bool    $registered,
        public readonly bool    $compatible,
        public readonly bool    $integrationEnabled,
        public readonly bool    $includedInTemplate,
        public readonly bool    $userMayAccessTeam,
        public readonly bool    $shownForTeam,
        public readonly ?string $unavailableReason,
        public readonly string  $templateKey = '',
    ) {
    }

    /**
     * Every condition except the visual preference.
     *
     * What a My Work provider, a lifecycle hook and any future non-visual
     * capability are gated on.
     */
    public function isEffective(): bool {
        return $this->appAvailable
            && $this->registered
            && $this->compatible
            && $this->integrationEnabled
            && $this->includedInTemplate
            && $this->userMayAccessTeam;
    }

    /**
     * May a visual capability be rendered?
     *
     * Effective *and* not hidden. The only gate that consults the team
     * administrator's preference.
     */
    public function mayRender(): bool {
        return $this->isEffective() && $this->shownForTeam;
    }

    /**
     * The same state with the visual preference replaced.
     *
     * The package-level state is computed once per team; each capability then
     * carries its own shown/hidden. Recomputing the package level per
     * capability would mean six reads per card.
     */
    public function withShownForTeam(bool $shown): self {
        $reason = $this->unavailableReason;
        if ($reason === null && !$shown) {
            $reason = self::REASON_HIDDEN_FOR_TEAM;
        } elseif ($reason === self::REASON_HIDDEN_FOR_TEAM && $shown) {
            $reason = null;
        }

        return new self(
            $this->appId,
            $this->integrationKey,
            $this->appAvailable,
            $this->appInstalled,
            $this->registered,
            $this->compatible,
            $this->integrationEnabled,
            $this->includedInTemplate,
            $this->userMayAccessTeam,
            $shown,
            $reason,
            $this->templateKey,
        );
    }

    /**
     * The API shape. Field names are the governance vocabulary verbatim —
     * `app_available`, `integration_enabled`, `included_in_template`,
     * `shown_for_team` — and there is deliberately no bare `enabled` key: the
     * old API had one and nobody reading a response could tell whose decision
     * it reported.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array {
        return [
            'app_id'               => $this->appId,
            'integration_key'      => $this->integrationKey,
            'app_available'        => $this->appAvailable,
            'app_installed'        => $this->appInstalled,
            'registered'           => $this->registered,
            'compatible'           => $this->compatible,
            'integration_enabled'  => $this->integrationEnabled,
            'included_in_template' => $this->includedInTemplate,
            'shown_for_team'       => $this->shownForTeam,
            'effective'            => $this->isEffective(),
            'unavailable_reason'   => $this->unavailableReason,
            'template_key'         => $this->templateKey,
        ];
    }
}
