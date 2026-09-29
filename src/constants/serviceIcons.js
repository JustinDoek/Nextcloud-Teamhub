/**
 * The icons a service or a service category may be given (v4.10.45).
 *
 * Mirrors `lib/Constants/ServiceIcons.php` — the server refuses a name that
 * is not in its list, and `tests/js/serviceIcons.test.mjs` fails when the
 * two drift apart. The components themselves are imported once, in
 * `components/services/ServiceIcon.vue`; this file stays pure so the test
 * can run it without a browser.
 */
import { translate as t } from '@nextcloud/l10n'

/** What a service or category without a choice shows. */
export const DEFAULT_SERVICE_ICON = 'HelpCircleOutline'

/** The names, in the order the picker shows them. */
export const SERVICE_ICON_NAMES = [
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
]

export function isServiceIcon(name) {
	return SERVICE_ICON_NAMES.includes(name)
}

/** The name to render: the one given when it is offered, else the default. */
export function serviceIconName(name) {
	return isServiceIcon(name) ? name : DEFAULT_SERVICE_ICON
}

/** What an icon is called, for its button's accessible name and tooltip. */
export function serviceIconLabel(name) {
	switch (name) {
	// TRANSLATORS: name of an icon a service can be given - a question mark in a circle
	case 'HelpCircleOutline': return t('teamhub', 'Question')
	// TRANSLATORS: name of an icon a service can be given - a lifebuoy (help)
	case 'Lifebuoy': return t('teamhub', 'Lifebuoy')
	// TRANSLATORS: name of an icon a service can be given
	case 'AccountGroupOutline': return t('teamhub', 'Group of people')
	// TRANSLATORS: name of an icon a service can be given
	case 'AccountPlusOutline': return t('teamhub', 'Add a person')
	// TRANSLATORS: name of an icon a service can be given
	case 'AccountKeyOutline': return t('teamhub', 'Person with a key')
	// TRANSLATORS: name of an icon a service can be given
	case 'AccountSchoolOutline': return t('teamhub', 'Student')
	// TRANSLATORS: name of an icon a service can be given
	case 'FolderAccountOutline': return t('teamhub', 'Shared folder')
	// TRANSLATORS: name of an icon a service can be given
	case 'FolderOutline': return t('teamhub', 'Folder')
	// TRANSLATORS: name of an icon a service can be given
	case 'FileDocumentOutline': return t('teamhub', 'Document')
	// TRANSLATORS: name of an icon a service can be given
	case 'DatabaseArrowUpOutline': return t('teamhub', 'More storage')
	// TRANSLATORS: name of an icon a service can be given
	case 'ArchiveOutline': return t('teamhub', 'Archive box')
	// TRANSLATORS: name of an icon a service can be given
	case 'CalendarClock': return t('teamhub', 'Calendar with a clock')
	// TRANSLATORS: name of an icon a service can be given
	case 'CalendarOutline': return t('teamhub', 'Calendar')
	// TRANSLATORS: name of an icon a service can be given
	case 'ClockOutline': return t('teamhub', 'Clock')
	// TRANSLATORS: name of an icon a service can be given
	case 'EmailOutline': return t('teamhub', 'Email')
	// TRANSLATORS: name of an icon a service can be given
	case 'ChatOutline': return t('teamhub', 'Chat')
	// TRANSLATORS: name of an icon a service can be given
	case 'PhoneOutline': return t('teamhub', 'Phone')
	// TRANSLATORS: name of an icon a service can be given
	case 'Cellphone': return t('teamhub', 'Mobile phone')
	// TRANSLATORS: name of an icon a service can be given
	case 'Laptop': return t('teamhub', 'Laptop')
	// TRANSLATORS: name of an icon a service can be given
	case 'Printer': return t('teamhub', 'Printer')
	// TRANSLATORS: name of an icon a service can be given
	case 'Wifi': return t('teamhub', 'Wi-Fi')
	// TRANSLATORS: name of an icon a service can be given - a globe
	case 'Web': return t('teamhub', 'Web')
	// TRANSLATORS: name of an icon a service can be given
	case 'CloudOutline': return t('teamhub', 'Cloud')
	// TRANSLATORS: name of an icon a service can be given
	case 'ShieldLockOutline': return t('teamhub', 'Security')
	// TRANSLATORS: name of an icon a service can be given
	case 'KeyOutline': return t('teamhub', 'Key')
	// TRANSLATORS: name of an icon a service can be given
	case 'CogOutline': return t('teamhub', 'Settings')
	// TRANSLATORS: name of an icon a service can be given
	case 'WrenchOutline': return t('teamhub', 'Wrench')
	// TRANSLATORS: name of an icon a service can be given
	case 'ToolboxOutline': return t('teamhub', 'Toolbox')
	// TRANSLATORS: name of an icon a service can be given - a bug, as in a software fault
	case 'BugOutline': return t('teamhub', 'Bug')
	// TRANSLATORS: name of an icon a service can be given
	case 'SchoolOutline': return t('teamhub', 'Training')
	// TRANSLATORS: name of an icon a service can be given
	case 'BookOpenOutline': return t('teamhub', 'Open book')
	// TRANSLATORS: name of an icon a service can be given
	case 'LightbulbOutline': return t('teamhub', 'Idea')
	// TRANSLATORS: name of an icon a service can be given
	case 'BullhornOutline': return t('teamhub', 'Announcement')
	// TRANSLATORS: name of an icon a service can be given
	case 'CartOutline': return t('teamhub', 'Shopping cart')
	// TRANSLATORS: name of an icon a service can be given
	case 'CashMultiple': return t('teamhub', 'Money')
	// TRANSLATORS: name of an icon a service can be given
	case 'TruckOutline': return t('teamhub', 'Delivery')
	// TRANSLATORS: name of an icon a service can be given
	case 'OfficeBuildingOutline': return t('teamhub', 'Office building')
	// TRANSLATORS: name of an icon a service can be given
	case 'MapMarkerOutline': return t('teamhub', 'Location')
	// TRANSLATORS: name of an icon a service can be given
	case 'BriefcaseOutline': return t('teamhub', 'Briefcase')
	// TRANSLATORS: name of an icon a service can be given
	case 'ClipboardCheckOutline': return t('teamhub', 'Checklist')
	// TRANSLATORS: name of an icon a service can be given
	case 'HandshakeOutline': return t('teamhub', 'Handshake')
	// TRANSLATORS: name of an icon a service can be given
	case 'HeartOutline': return t('teamhub', 'Heart')
	// TRANSLATORS: name of an icon a service can be given - a judge's hammer (legal)
	case 'Gavel': return t('teamhub', 'Legal')
	// TRANSLATORS: name of an icon a service can be given
	case 'VideoOutline': return t('teamhub', 'Video')
	// TRANSLATORS: name of an icon a service can be given
	case 'ImageOutline': return t('teamhub', 'Image')
	// TRANSLATORS: name of an icon a service can be given
	case 'ViewGridOutline': return t('teamhub', 'Apps')
	}
	return name
}
