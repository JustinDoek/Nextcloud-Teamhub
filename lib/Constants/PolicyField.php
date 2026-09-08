<?php
declare(strict_types=1);

namespace OCA\TeamHub\Constants;

/**
 * The fields a policy profile may govern (v4.8.2, Track F2a).
 *
 * Full design: `TRACK-F2-DESIGN.md` §3. Reasoning: `DESIGN.md` §2.104.
 *
 * **The tag is the point of this file.** Every field is either:
 *
 *   ENFORCED — TeamHub is the only writer. A `locked` value refuses the write
 *              with a 403 and the attempt is audited.
 *   ASSERTED — something outside TeamHub can also write it. We set the value
 *              when the profile is applied and keep our own screens read-only,
 *              but we cannot refuse a change made elsewhere. Divergence is
 *              detected by scan and reported.
 *
 * **The tag is declared per field, not derived from which table holds the
 * value.** That distinction is not academic — `integrations_allowed` reads
 * `teamhub_team_apps`, which is our own table, and it is still ASSERTED,
 * because `ResourceDiscoveryService` surfaces Deck boards and Talk rooms
 * created directly in their own apps. A registry that computed the tag from
 * the owning table would have got that field wrong and made the product claim
 * a control that a two-click detour around TeamHub defeats.
 *
 * The test is: **can anything outside TeamHub write this?** It is answered by
 * a person reading the code, once, per field — not by a rule.
 *
 * The enforced set is deliberately small. A short list we genuinely control is
 * worth more than a long one we half-control, because every entry is a claim
 * an administrator will rely on.
 *
 * **No labels here.** User-facing names live in `src/constants/policy.js` and
 * go through `t('teamhub', …)`. Putting them in PHP would put them outside
 * `npm run check:l10n`'s reach, which scans `src/` only — the same pipeline gap
 * that left the `lib/MyWork/` provider strings untranslated.
 */
final class PolicyField {

    // -------------------------------------------------------------------------
    // Tags
    // -------------------------------------------------------------------------

    public const TAG_ENFORCED = 'enforced';
    public const TAG_ASSERTED = 'asserted';

    // -------------------------------------------------------------------------
    // Value types. Everything is stored as TEXT in teamhub_policy_value; the
    // type says how to cast it back. See cast() below.
    // -------------------------------------------------------------------------

    public const TYPE_BOOL = 'bool';
    public const TYPE_INT  = 'int';
    public const TYPE_LIST = 'list';
    // v4.8.24 — an opaque identifier chosen from a vocabulary the platform owns
    // rather than TeamHub. `confidential_tag` holds a Nextcloud system tag id;
    // the registry deliberately does not know which ids are valid, because the
    // answer changes whenever an administrator edits a Confidential files label.
    // Validation of *membership* belongs to the service that can read that list.
    public const TYPE_STRING = 'string';
    // There was a TYPE_ENUM in v4.8.2, used only by `expiry_policy`. That field
    // moved to the template, so the type had no members left and went with it
    // rather than sitting in the registry advertising a capability nothing
    // uses. Re-adding it is a case in serialize() and one in cast().

    // -------------------------------------------------------------------------
    // Field keys
    //
    // v4.8.3 — there is no longer a per-field mode. A field is either governed
    // by a profile or it is not, and a governed field is locked. The
    // default-vs-locked distinction and its `mode` column are gone; see
    // Version000408003 for what that costs.
    // -------------------------------------------------------------------------

    public const CFG_VISIBLE   = 'cfg_visible';
    public const CFG_OPEN      = 'cfg_open';
    public const CFG_INVITE    = 'cfg_invite';
    public const CFG_REQUEST   = 'cfg_request';
    public const CFG_PROTECTED = 'cfg_protected';
    public const CFG_ROOT      = 'cfg_root';

    public const EXTERNAL_MEMBERS     = 'external_members';
    public const INTEGRATIONS_ALLOWED = 'integrations_allowed';
    public const PUBLIC_MESSAGES      = 'public_messages';
    public const CONFIDENTIAL_TAG     = 'confidential_tag';

    /**
     * The app `confidential_tag` needs before it means anything.
     *
     * Named as a constant rather than typed inline because three layers test it
     * — the registry entry below, the availability probe in
     * `ConfidentialFilesService`, and the panel's greyed-out state.
     */
    public const APP_FILES_CONFIDENTIAL = 'files_confidential';

    /**
     * The registry.
     *
     * `source` documents where the drift scan reads the observed value from.
     * It is documentation in F2a — nothing reads it until the scan lands in
     * F2c — and it names a table and column rather than a method, so it cannot
     * go stale against code that does not exist yet.
     *
     * `dependsOn`, where present, names a field that must itself be governed
     * and true for this one to mean anything. The admin UI greys the dependent
     * field out until the condition holds, rather than letting somebody set a
     * value the platform will ignore.
     *
     * **Expiry is not here.** v4.8.3 moved it to the template
     * (`teamhub_template.offer_expiry` + `expiry_default_days`): whether a kind
     * of team can expire, and for how long by default, is a property of what
     * that kind of team *is*, not of how sensitive it is. A six-month project
     * and a six-month confidential project want the same expiry.
     *
     * `requiresApp`, where present, names a Nextcloud app that must be installed
     * and enabled for the field to mean anything. Unlike `dependsOn` it can
     * never be satisfied by editing the profile, so the panel greys the field
     * out with a different reason and a pointer to the app.
     *
     * @var array<string, array{tag: string, type: string, source: string, dependsOn?: string, requiresApp?: string}>
     */
    public const FIELDS = [
        // ── Circles-owned config bits. CirclesConfig::MANAGED_BITS is the
        //    authoritative bit set; these six are exactly it.
        self::CFG_VISIBLE => [
            'tag'    => self::TAG_ASSERTED,
            'type'   => self::TYPE_BOOL,
            'source' => 'circles_circle.config & 8',
        ],
        self::CFG_OPEN => [
            'tag'    => self::TAG_ASSERTED,
            'type'   => self::TYPE_BOOL,
            'source' => 'circles_circle.config & 16',
        ],
        self::CFG_INVITE => [
            'tag'    => self::TAG_ASSERTED,
            'type'   => self::TYPE_BOOL,
            'source' => 'circles_circle.config & 32',
        ],
        self::CFG_REQUEST => [
            'tag'    => self::TAG_ASSERTED,
            'type'   => self::TYPE_BOOL,
            'source' => 'circles_circle.config & 64',
            // Read off Circles' own CircleJoin::manageMemberStatus(): CFG_OPEN
            // is the gate and CFG_REQUEST only modulates what happens once you
            // are through it. CFG_REQUEST without CFG_OPEN is not "closed but
            // askable" — it is simply closed. See CirclesConfig::joinPolicy().
            'dependsOn' => self::CFG_OPEN,
        ],
        self::CFG_PROTECTED => [
            'tag'    => self::TAG_ASSERTED,
            'type'   => self::TYPE_BOOL,
            'source' => 'circles_circle.config & 256',
        ],
        self::CFG_ROOT => [
            'tag'    => self::TAG_ASSERTED,
            'type'   => self::TYPE_BOOL,
            'source' => 'circles_circle.config & 8192',
        ],

        // ── Asserted despite being partly or wholly ours. See the class
        //    docblock — these two are why the tag is declared, not derived.
        self::EXTERNAL_MEMBERS => [
            // Enforced on TeamHub's invite path; asserted overall, because
            // Contacts can add a mail member without passing through us.
            'tag'    => self::TAG_ASSERTED,
            'type'   => self::TYPE_BOOL,
            'source' => 'circles_member.user_type in (4 mail, 8 contact)',
        ],
        self::INTEGRATIONS_ALLOWED => [
            // Allow-list. EMPTY MEANS ALL ALLOWED, so the field ships inert by
            // construction. Asserted: ResourceDiscoveryService surfaces
            // resources created directly in Deck or Talk.
            'tag'    => self::TAG_ASSERTED,
            'type'   => self::TYPE_LIST,
            'source' => 'teamhub_team_apps.app_id',
        ],

        // ── Enforced. No route in but ours.
        self::PUBLIC_MESSAGES => [
            'tag'    => self::TAG_ENFORCED,
            'type'   => self::TYPE_BOOL,
            // v4.8.15 — corrected. This read `teamhub_team_config.public_messages`
            // from v4.8.2, and that table has never existed: the setting is
            // per-team app-config, written by `MessageService::saveMessageSettings`
            // and read back by `getAllowPublicMessages()`. The key prefix is
            // `MessageService::CONFIG_ALLOW_PUBLIC_PREFIX`. Caught when the
            // compliance sweep went looking for the column.
            'source' => 'appconfig teamhub/allowPublicMessages_{teamId}',
        ],

        // ── The team folder's classification (v4.8.24) ───────────────────
        //
        // Holds a Nextcloud **system tag id**, stored as a string. Not a tag
        // name: NC identifies a tag by id, names are not unique across the
        // (name, userVisible, userAssignable) triple, and Confidential files
        // stores the id too — its admin screen saves `String(tag.id)` and its
        // `HookListener` passes that straight into
        // `ISystemTagObjectMapper::assignTags()`, whose argument is ids. Storing
        // the name would have produced a second tag with the same label the
        // first time anything created one.
        //
        // **What applying it actually does.** The tag lands on the team's group
        // folder root — `oc_group_folders.root_id`, which is the fileid of the
        // folder's `files` node. Nextcloud has no tag inheritance, so the files
        // inside stay untagged and the tag is invisible on them in the Files UI.
        // What makes this worth doing is the workflow engine:
        // `OCA\WorkflowEngine\Check\FileSystemTags::getFileIds()` recurses up the
        // path and collects every ancestor's tags before evaluating, so a Files
        // Access Control or retention rule keyed on the tag matches every file
        // in the folder. TeamHub sets the classification; the admin's own rules
        // decide what it costs.
        //
        // ASSERTED, and it could not honestly be anything else. Anyone who can
        // assign system tags can take it off in the Files UI, and Confidential
        // files assigns and unassigns label tags on files by content. We set it
        // and report a change; we cannot refuse one.
        self::CONFIDENTIAL_TAG => [
            'tag'    => self::TAG_ASSERTED,
            'type'   => self::TYPE_STRING,
            'source' => 'systemtag_object_mapping(objecttype=files) on group_folders.root_id',
            // Not `dependsOn`: that names another *field* that must be governed
            // and true. This dependency is on an app being installed, which no
            // profile can satisfy, so it is a separate key and the panel greys
            // the field out with a different reason.
            'requiresApp' => self::APP_FILES_CONFIDENTIAL,
        ],

        // Deliberately absent, each for a stated reason — see
        // TRACK-F2-DESIGN.md §3.2: retention (no mechanism behind it),
        // mandatory team folder (per-team scan cost — note that
        // `confidential_tag` above reads the same folder and is still fine,
        // because it observes through one set query over
        // `group_folders_groups` rather than a lookup per team), membership caps
        // (MemberLevel dispatches real events, so polling is the wrong
        // mechanism — that is F3), name/description patterns (cosmetic).
        // And expiry, which moved to the template in v4.8.3.
    ];

    public static function isValid(string $fieldKey): bool {
        return isset(self::FIELDS[$fieldKey]);
    }

    /**
     * The field this one needs, or null when it stands alone.
     *
     * A dependency is satisfied when the named field is itself governed by the
     * profile AND set to true.
     */
    public static function dependsOn(string $fieldKey): ?string {
        return self::FIELDS[$fieldKey]['dependsOn'] ?? null;
    }

    /**
     * The app this field needs installed, or null when it stands alone.
     *
     * A field whose app is missing is not an error and not a finding — it is
     * simply not offered. See `PolicyService::fieldCatalogue()`.
     */
    public static function requiresApp(string $fieldKey): ?string {
        return self::FIELDS[$fieldKey]['requiresApp'] ?? null;
    }

    /** @return list<string> */
    public static function keys(): array {
        return array_keys(self::FIELDS);
    }

    /**
     * ENFORCED or ASSERTED. Callers that render this to a user must not
     * translate it here — see the class docblock.
     */
    public static function tag(string $fieldKey): string {
        return self::FIELDS[$fieldKey]['tag'] ?? self::TAG_ASSERTED;
    }

    public static function isEnforced(string $fieldKey): bool {
        return self::tag($fieldKey) === self::TAG_ENFORCED;
    }

    public static function type(string $fieldKey): string {
        return self::FIELDS[$fieldKey]['type'] ?? self::TYPE_BOOL;
    }

    /**
     * The six Circles config bits, in MANAGED_BITS order.
     *
     * @return array<string,int> field key => bit value
     */
    public static function configBits(): array {
        return [
            self::CFG_VISIBLE   => CirclesConfig::CFG_VISIBLE,
            self::CFG_OPEN      => CirclesConfig::CFG_OPEN,
            self::CFG_INVITE    => CirclesConfig::CFG_INVITE,
            self::CFG_REQUEST   => CirclesConfig::CFG_REQUEST,
            self::CFG_PROTECTED => CirclesConfig::CFG_PROTECTED,
            self::CFG_ROOT      => CirclesConfig::CFG_ROOT,
        ];
    }

    // -------------------------------------------------------------------------
    // Serialisation. Everything round-trips through TEXT.
    // -------------------------------------------------------------------------

    /**
     * Validate and normalise an incoming value for storage.
     *
     * Returns the string to store. Throws on anything the field cannot hold —
     * the caller is an admin endpoint, so a bad value is a 400, not a silent
     * coercion.
     *
     * @param bool|int|string|list<string> $value
     * @throws \InvalidArgumentException
     */
    public static function serialize(string $fieldKey, mixed $value): string {
        if (!self::isValid($fieldKey)) {
            throw new \InvalidArgumentException('Unknown policy field: ' . $fieldKey);
        }

        switch (self::type($fieldKey)) {
            case self::TYPE_BOOL:
                if (is_string($value)) {
                    $value = in_array(strtolower($value), ['1', 'true', 'yes'], true);
                }
                return $value ? '1' : '0';

            case self::TYPE_STRING:
                if (is_array($value)) {
                    throw new \InvalidArgumentException($fieldKey . ' must be a single value.');
                }
                $text = trim((string)$value);
                // Governing the field with no value is the one thing this type
                // must refuse. A profile that says "tag the folder" without
                // saying which tag is not a weaker rule, it is an unrunnable
                // one — and the apply step would have to guess or skip, which is
                // how a control that never fires ends up looking like it works.
                if ($text === '') {
                    throw new \InvalidArgumentException($fieldKey . ' cannot be empty.');
                }
                return $text;

            case self::TYPE_INT:
                if (!is_numeric($value)) {
                    throw new \InvalidArgumentException($fieldKey . ' must be a number.');
                }
                $int = (int)$value;
                if ($int < 0) {
                    throw new \InvalidArgumentException($fieldKey . ' cannot be negative.');
                }
                return (string)$int;

            case self::TYPE_LIST:
            default:
                $items = is_array($value)
                    ? $value
                    : array_filter(array_map('trim', explode(';', (string)$value)));
                $clean = [];
                foreach ($items as $item) {
                    $item = trim((string)$item);
                    // Semicolon is the separator, so it cannot appear in a
                    // value. Rejecting rather than stripping: a key containing
                    // one is a caller bug, and stripping would store something
                    // the admin did not type.
                    if ($item === '' || str_contains($item, ';')) {
                        continue;
                    }
                    $clean[$item] = true;
                }
                return implode(';', array_keys($clean));
        }
    }

    /**
     * Cast a stored string back to its PHP value.
     *
     * @return bool|int|string|list<string>
     */
    public static function cast(string $fieldKey, string $stored): mixed {
        switch (self::type($fieldKey)) {
            case self::TYPE_INT:
                return (int)$stored;
            case self::TYPE_STRING:
                return $stored;
            case self::TYPE_LIST:
                return $stored === '' ? [] : explode(';', $stored);
            case self::TYPE_BOOL:
            default:
                return $stored === '1';
        }
    }
}
