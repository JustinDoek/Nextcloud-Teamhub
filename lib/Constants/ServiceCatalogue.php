<?php
declare(strict_types=1);

namespace OCA\TeamHub\Constants;

use OCP\IL10N;

/**
 * The services a Service Team can offer (WorkflowHub phase 5, v4.10.20).
 *
 * A *service* is what a requester picks — "request a shared folder". A
 * *workflow definition* is what running it looks like. This table is the
 * join: one service key, one built-in definition key, and the words the
 * requester reads. A catalogue row stores both keys, so a service team's
 * catalogue survives this table changing shape.
 *
 * **Why the keys are code and not admin data.** A service is only useful if
 * a definition exists behind it, and definitions are PHP classes registered
 * at boot. An administrator choosing a service from this list is choosing
 * something the app can actually run; an administrator typing a service name
 * would be creating a row nothing executes. Which of these a service team
 * offers *is* administrator data and lives in `teamhub_service_catalog`.
 *
 * The six below were the product's initial catalogue; v4.10.29 added the
 * seventh, the quota request (`TEAM_QUOTA`), which had run on the ledger. Five of them run the
 * generic service request (`ServiceRequestDefinition`, one registered
 * instance per service); *request a new team* runs the reference workflow
 * `team_request` that has existed since v4.10.14 — a service team taking it
 * over is exactly what a service desk is for, and duplicating it as a
 * seventh definition would have given an instance two ways to ask the same
 * question.
 */
final class ServiceCatalogue {

    public const NEW_TEAM        = 'new_team';
    /**
     * Retired in v4.10.45 (Justin, 2026-09-25: a team admin changes their
     * own team). Kept so the requests made on it keep their title; see
     * `RETIRED`. Its place in the bundle is `TEAM_EXPIRY`.
     */
    public const TEAM_CHANGE     = 'team_change';
    /** v4.10.45 — more time before a team's expiration date. */
    public const TEAM_EXPIRY     = 'team_expiry';
    public const EXTERNAL_ACCESS = 'external_access';
    public const SHARED_FOLDER   = 'shared_folder';
    public const TEAM_ARCHIVE    = 'team_archive';
    public const GENERAL         = 'general';
    /** v4.10.29 — the quota request, moved off the ledger into the bundle. */
    public const TEAM_QUOTA      = 'team_quota';
    /**
     * v4.10.50 — accept or decline a team made outside TeamHub. The one
     * service nobody asks for: TeamHub opens it when its sweep finds such a
     * team (`TeamAdoptionService`), so it has no card on the Services page
     * (`NO_CARD`).
     */
    public const TEAM_ADOPTION   = 'team_adoption';

    /** Display order: the specific services first, the catch-all last. */
    public const SERVICES = [
        self::NEW_TEAM,
        self::TEAM_EXPIRY,
        self::EXTERNAL_ACCESS,
        self::SHARED_FOLDER,
        self::TEAM_QUOTA,
        self::TEAM_ARCHIVE,
        self::TEAM_ADOPTION,
        self::GENERAL,
    ];

    /**
     * v4.10.50 — services of the bundle that never show as a card: TeamHub
     * starts them itself (`IWorkflowSystemStarted`), so there is nothing for
     * a person to ask.
     */
    public const NO_CARD = [
        self::TEAM_ADOPTION,
    ];

    /** Whether a service is left off the Services page (v4.10.50). */
    public static function hasCard(string $serviceKey): bool {
        return !in_array($serviceKey, self::NO_CARD, true);
    }

    /**
     * Services that run a definition of their own rather than a
     * `ServiceRequestDefinition` instance (v4.10.29). `Application.php`
     * registers a generic definition for every service *not* in this list;
     * these two are registered as classes, because they ask for something
     * a summary cannot carry (a team's purpose and members; a size) and
     * their answer is more than words.
     */
    public const OWN_DEFINITIONS = [
        self::NEW_TEAM,
        self::TEAM_QUOTA,
        // v4.10.45 — its answer sets a date.
        self::TEAM_EXPIRY,
        // v4.10.50 — its answer registers a team.
        self::TEAM_ADOPTION,
    ];

    /**
     * Services the bundle no longer offers (v4.10.45). Their definition is
     * still registered — dark, never startable — so a request made on one
     * keeps its title and steps and can be finished; the holder's catalogue
     * row is removed on upgrade (`CompleteNextcloudServicesBundle`).
     */
    public const RETIRED = [
        self::TEAM_CHANGE,
    ];

    /**
     * v4.10.44 — the services a request needs no team for (Justin,
     * 2026-09-25: the requesting team is optional, as on a service a team
     * built). Asked without one, the request is personal and is recorded
     * against the service team that answers it. The other three are about
     * one team — changing it, archiving it, its space's quota — and still
     * ask which.
     */
    public const TEAM_OPTIONAL = [
        self::NEW_TEAM,
        self::EXTERNAL_ACCESS,
        self::SHARED_FOLDER,
        self::GENERAL,
    ];

    /** Whether a request for this service may be made without a team (v4.10.44). */
    public static function isTeamOptional(string $serviceKey): bool {
        return in_array($serviceKey, self::TEAM_OPTIONAL, true);
    }

    // ── Categories (phase 7A, v4.10.25) ──────────────────────────────────
    //
    // A category is an **app-level vocabulary**, not a per-team one: a team
    // that defines its own service (phase C) picks from this list, it does
    // not invent a heading. A team naming its own category gives a readable
    // page in month one and twelve headings in year one.
    //
    // They are code for exactly as long as the services are code. The
    // catalogue page filters on the key and prints the label, so a category
    // only becomes a database column when a team can choose one.

    public const CATEGORY_TEAMS   = 'teams_spaces';
    public const CATEGORY_ACCESS  = 'access_accounts';
    public const CATEGORY_FILES   = 'files_storage';
    public const CATEGORY_SUPPORT = 'support_requests';
    public const CATEGORY_APPS    = 'apps_tools';

    /** Display order of the category filter. */
    public const CATEGORIES = [
        self::CATEGORY_TEAMS,
        self::CATEGORY_ACCESS,
        self::CATEGORY_FILES,
        self::CATEGORY_SUPPORT,
        self::CATEGORY_APPS,
    ];

    /**
     * Service key to its category.
     *
     * `apps_tools` is deliberately empty: nothing built-in belongs in it,
     * and the page hides a category nothing is in rather than advertising
     * something this server does not have. It is here because phase C's
     * team-defined services are what will fill it.
     */
    public const CATEGORY_OF = [
        self::NEW_TEAM        => self::CATEGORY_TEAMS,
        self::TEAM_CHANGE     => self::CATEGORY_TEAMS,
        self::TEAM_EXPIRY     => self::CATEGORY_TEAMS,
        self::TEAM_ARCHIVE    => self::CATEGORY_TEAMS,
        self::EXTERNAL_ACCESS => self::CATEGORY_ACCESS,
        self::SHARED_FOLDER   => self::CATEGORY_FILES,
        self::TEAM_QUOTA      => self::CATEGORY_FILES,
        self::GENERAL         => self::CATEGORY_SUPPORT,
        self::TEAM_ADOPTION   => self::CATEGORY_TEAMS,
    ];

    /**
     * Service key to the definition key a request for it opens.
     *
     * The five `service_*` keys are `ServiceRequestDefinition` instances,
     * registered one per service in `Application.php`; `team_request` is the
     * reference workflow and `teamspace_quota` the quota request, each a
     * class of its own (`OWN_DEFINITIONS`).
     */
    public const DEFINITIONS = [
        self::NEW_TEAM        => 'team_request',
        self::TEAM_CHANGE     => 'service_team_change',
        self::EXTERNAL_ACCESS => 'service_external_access',
        self::SHARED_FOLDER   => 'service_shared_folder',
        self::TEAM_ARCHIVE    => 'service_team_archive',
        self::GENERAL         => 'service_general',
        self::TEAM_QUOTA      => 'teamspace_quota',
        self::TEAM_EXPIRY     => 'team_expiry',
        self::TEAM_ADOPTION   => 'team_adoption',
    ];

    public static function isValid(string $serviceKey): bool {
        return in_array($serviceKey, self::SERVICES, true);
    }

    /** Whether a service runs a definition class of its own (`OWN_DEFINITIONS`). */
    public static function hasOwnDefinition(string $serviceKey): bool {
        return in_array($serviceKey, self::OWN_DEFINITIONS, true);
    }

    public static function definitionFor(string $serviceKey): ?string {
        return self::DEFINITIONS[$serviceKey] ?? null;
    }

    /** The service a definition key belongs to, or null for a definition no service offers. */
    public static function serviceForDefinition(string $definitionKey): ?string {
        $flipped = array_flip(self::DEFINITIONS);
        return $flipped[$definitionKey] ?? null;
    }

    /**
     * What the requester reads on the service card, in their language.
     *
     * @return array{label: string, description: string}
     */
    public static function describe(IL10N $l, string $serviceKey): array {
        switch ($serviceKey) {
            case self::NEW_TEAM:
                return [
                    // TRANSLATORS: service catalogue entry - ask the service desk to create a new team
                    'label'       => $l->t('Request a new team'),
                    'description' => $l->t('Ask for a new team to be created, saying what it is for and who should be in it.'),
                ];
            case self::TEAM_EXPIRY:
                return [
                    // TRANSLATORS: service catalogue entry - ask for a later expiration date for a team you administer
                    'label'       => $l->t('Request more time for a team'),
                    'description' => $l->t('Ask for a later expiration date for a team you administer, saying why it is still needed.'),
                ];
            case self::TEAM_CHANGE:
                // Retired (v4.10.45): only the requests made on it read this.
                return [
                    // TRANSLATORS: service catalogue entry - ask for a change to an existing team
                    'label'       => $l->t('Request a team modification'),
                    'description' => $l->t('Ask for a change to a team you are in: its name, its apps, its members or its settings.'),
                ];
            case self::EXTERNAL_ACCESS:
                return [
                    // TRANSLATORS: service catalogue entry - ask for somebody outside the organisation to get access
                    'label'       => $l->t('Request external access'),
                    'description' => $l->t('Ask for somebody outside the organisation to be given access to a team or a file.'),
                ];
            case self::SHARED_FOLDER:
                return [
                    // TRANSLATORS: service catalogue entry - ask for a team folder (the Nextcloud Team folders app) to be set up
                    'label'       => $l->t('Request a team folder'),
                    'description' => $l->t('Ask for a team folder to be set up, saying who needs it and how much space it needs.'),
                ];
            case self::TEAM_QUOTA:
                return [
                    // TRANSLATORS: service catalogue entry - ask for more storage in the team space of a team you administer
                    'label'       => $l->t('Request a quota increase'),
                    'description' => $l->t('Ask for more storage in the team space of a team you administer.'),
                ];
            case self::TEAM_ADOPTION:
                return [
                    // TRANSLATORS: Nextcloud service - decide whether a team somebody made outside TeamHub (in Contacts, on the Teams page, by a provisioning tool) is taken into TeamHub
                    'label'       => $l->t('Accept teams made outside TeamHub'),
                    'description' => $l->t('Decide whether a team made in Contacts, on the Teams page or by a provisioning tool joins TeamHub, and with which template and policy.'),
                ];
            case self::TEAM_ARCHIVE:
                return [
                    // TRANSLATORS: service catalogue entry - ask for a team to be archived
                    'label'       => $l->t('Request team archiving'),
                    'description' => $l->t('Ask for a team that is finished to be archived.'),
                ];
            case self::GENERAL:
                return [
                    // TRANSLATORS: service catalogue entry - a question or request about Nextcloud the other entries do not cover
                    'label'       => $l->t('Submit a Nextcloud request'),
                    'description' => $l->t('A question or request about Nextcloud that the other services do not cover. Describe what you need.'),
                ];
        }
        return ['label' => $serviceKey, 'description' => ''];
    }

    /**
     * The category a service is filed under, or the catch-all for a key
     * this table has never heard of.
     */
    public static function categoryFor(string $serviceKey): string {
        return self::CATEGORY_OF[$serviceKey] ?? self::CATEGORY_SUPPORT;
    }

    /** The heading a category reads as, in the viewer's language. */
    public static function categoryLabel(IL10N $l, string $category): string {
        switch ($category) {
            case self::CATEGORY_TEAMS:
                // TRANSLATORS: service catalogue category - requests about teams and their spaces
                return $l->t('Teams and spaces');
            case self::CATEGORY_ACCESS:
                // TRANSLATORS: service catalogue category - requests about accounts and who may get in
                return $l->t('Access and accounts');
            case self::CATEGORY_FILES:
                // TRANSLATORS: service catalogue category - requests about files, folders and storage
                return $l->t('Files and storage');
            case self::CATEGORY_SUPPORT:
                // TRANSLATORS: service catalogue category - anything the other categories do not cover
                return $l->t('Support and requests');
            case self::CATEGORY_APPS:
                // TRANSLATORS: service catalogue category - requests about apps and tools
                return $l->t('Apps and tools');
        }
        return $category;
    }

    /**
     * What the card promises about how long the desk takes.
     *
     * An indication, not a commitment: the app has no service-level
     * agreement to read and inventing a per-instance one would be a
     * promise the desk never made. The words therefore say *usually*, and
     * phase C hands the sentence to the team that answers the request —
     * which is the only party that can state it truthfully.
     */
    public static function leadTime(IL10N $l, string $serviceKey): string {
        switch ($serviceKey) {
            case self::NEW_TEAM:
            case self::EXTERNAL_ACCESS:
            case self::TEAM_EXPIRY:
                // TRANSLATORS: indication of how long a service request usually takes to answer
                return $l->t('Usually within 2 working days');
            case self::TEAM_CHANGE:
            case self::SHARED_FOLDER:
            case self::TEAM_QUOTA:
                // TRANSLATORS: indication of how long a service request usually takes to answer
                return $l->t('Usually within 1 working day');
            case self::TEAM_ARCHIVE:
            case self::GENERAL:
                // TRANSLATORS: indication of how long a service request usually takes to answer
                return $l->t('Usually within 3 working days');
        }
        return '';
    }
}
