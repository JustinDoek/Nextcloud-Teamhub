<?php
declare(strict_types=1);

namespace OCA\TeamHub\Constants;

/**
 * The icons a service or a service category may be given (v4.10.45).
 *
 * Justin, 2026-09-25: every service a team built showed the question mark,
 * because there was nothing to choose. A team now picks one in the builder,
 * and a Nextcloud administrator picks one per category on Settings →
 * TeamHub → Services.
 *
 * **A closed list, by name.** The names are Material Design icon component
 * names (`vue-material-design-icons/<Name>.vue`); the client imports exactly
 * these, so a stored name is always one it can render. The client's list is
 * `SERVICE_ICON_CHOICES` in `src/constants/serviceTeams.js`, and
 * `tests/js/serviceIcons.test.mjs` fails when the two drift apart.
 */
final class ServiceIcons {

    /** What a service or category without a choice shows. */
    public const DEFAULT = 'HelpCircleOutline';

    public const ALLOWED = [
        'HelpCircleOutline',
        'Lifebuoy',
        'AccountGroupOutline',
        'AccountPlusOutline',
        'AccountKeyOutline',
        'AccountSchoolOutline',
        'FolderAccountOutline',
        'FolderOutline',
        'FileDocumentOutline',
        'DatabaseArrowUpOutline',
        'ArchiveOutline',
        'CalendarClock',
        'CalendarOutline',
        'ClockOutline',
        'EmailOutline',
        'ChatOutline',
        'PhoneOutline',
        'Cellphone',
        'Laptop',
        'Printer',
        'Wifi',
        'Web',
        'CloudOutline',
        'ShieldLockOutline',
        'KeyOutline',
        'CogOutline',
        'WrenchOutline',
        'ToolboxOutline',
        'BugOutline',
        'SchoolOutline',
        'BookOpenOutline',
        'LightbulbOutline',
        'BullhornOutline',
        'CartOutline',
        'CashMultiple',
        'TruckOutline',
        'OfficeBuildingOutline',
        'MapMarkerOutline',
        'BriefcaseOutline',
        'ClipboardCheckOutline',
        'HandshakeOutline',
        'HeartOutline',
        'Gavel',
        'VideoOutline',
        'ImageOutline',
        'ViewGridOutline',
    ];

    public static function isAllowed(string $name): bool {
        return in_array($name, self::ALLOWED, true);
    }
}
