import { translate as t } from '@nextcloud/l10n'

/**
 * A room feature code from Nextcloud's room metadata, in words (v4.20.19,
 * moved here from CalendarWidget so the room finder's equipment filter says
 * the same). An unknown code is shown as its own words.
 *
 * @param {string} code e.g. 'VIDEO-CONFERENCING'
 * @return {string}
 */
export function featureLabel(code) {
	switch (String(code || '').toUpperCase()) {
	case 'PHONE': return t('teamhub', 'Phone')
	case 'VIDEO-CONFERENCING': return t('teamhub', 'Video conferencing')
	case 'TV': return t('teamhub', 'Screen')
	case 'PROJECTOR': return t('teamhub', 'Projector')
	case 'WHITEBOARD': return t('teamhub', 'Whiteboard')
	case 'WHEELCHAIR-ACCESSIBLE': return t('teamhub', 'Wheelchair accessible')
	default: {
		const words = String(code || '').toLowerCase().replace(/[-_]+/g, ' ')
		return words.charAt(0).toUpperCase() + words.slice(1)
	}
	}
}
