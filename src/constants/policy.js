/**
 * TeamHub — templates and policy profiles, shared vocabulary (v4.8.2, F2a).
 *
 * Mirrors `lib/Constants/PolicyField.php`. KEEP IN SYNC — the field keys, tags
 * and modes travel over the wire in both directions.
 *
 * **Labels live here, not in PHP, and that is deliberate.** `npm run
 * check:l10n` scans `src/` only, so a user-facing string defined in PHP is
 * outside its reach — the pipeline gap that left every `lib/MyWork/` provider
 * string untranslated for months. The backend sends keys; this file names them.
 *
 * Labels are functions rather than constants because `t()` must run after
 * Nextcloud's l10n bundle has loaded. Evaluating at module scope gives English
 * on a slow bundle load — the same reason `myWork.js` does it this way.
 */

import { translate as t } from '@nextcloud/l10n'

// ── Tags: what a profile can actually do about a field ──────────────────

export const TAG = {
	ENFORCED: 'enforced',
	ASSERTED: 'asserted',
}

/**
 * The single most important string in this feature.
 *
 * DESIGN §2.104: calling both halves "locked" is the failure the 2026-07-27
 * docs audit found, with higher stakes — an administrator who believes a
 * Confidential team cannot be made visible has been sold a control that does
 * not exist. Every surface that shows a field shows its tag.
 */
export function tagLabel(tag) {
	return tag === TAG.ENFORCED
		// TRANSLATORS: a team setting only TeamHub can change, so a locked value is genuinely blocked
		? t('teamhub', 'Enforced')
		// TRANSLATORS: a team setting other apps can also change, so TeamHub can only detect and report a change
		: t('teamhub', 'Reported')
}

export function tagExplanation(tag) {
	return tag === TAG.ENFORCED
		? t('teamhub', 'Only TeamHub can change this. A locked value is refused.')
		: t('teamhub', 'Contacts, the Teams app or the app itself can also change this. TeamHub sets it, keeps its own screens read-only, and reports any later change — it cannot prevent one.')
}

// ── Fields ──────────────────────────────────────────────────────────────
//
// v4.8.3 — there is no longer a per-field mode. A setting is either governed by
// the profile or it is not; a governed setting is locked. The `MODE` constants
// and the "starting value vs locked" picker are gone.

export const FIELD = {
	CFG_VISIBLE: 'cfg_visible',
	CFG_OPEN: 'cfg_open',
	CFG_INVITE: 'cfg_invite',
	CFG_REQUEST: 'cfg_request',
	CFG_PROTECTED: 'cfg_protected',
	CFG_ROOT: 'cfg_root',
	CFG_FEDERATED: 'cfg_federated',
	EXTERNAL_MEMBERS: 'external_members',
	INTEGRATIONS_ALLOWED: 'integrations_allowed',
	PUBLIC_MESSAGES: 'public_messages',
	CONFIDENTIAL_TAG: 'confidential_tag',
	// Expiry left this list in v4.8.3 — whether a kind of team can expire, and
	// for how long by default, is a property of the template rather than of how
	// sensitive the team is.
}

/**
 * Modules that the wizard has always rendered as *apps*, and which the Policy
 * template editor groups the same way.
 *
 * They are stored in the `modules` list because that is where their on/off
 * boolean lives, but each one **provisions a resource** — an Intravox page, a
 * Collectives collective — rather than toggling a feature, so they belong next
 * to Calendar and Deck rather than next to Decisions and Presence.
 * `CreateTeamView.vue`'s `toggleOnlyAppOptions()` made that call in v4.4.10;
 * this constant is what stops the admin screen from disagreeing with it.
 */
export const RESOURCE_MODULES = ['pages', 'wiki']

export function fieldLabel(fieldKey) {
	switch (fieldKey) {
	case FIELD.CFG_VISIBLE:
		return t('teamhub', 'Visible to everyone')
	case FIELD.CFG_OPEN:
		return t('teamhub', 'Anyone can join')
	case FIELD.CFG_INVITE:
		return t('teamhub', 'Invited members must confirm')
	case FIELD.CFG_REQUEST:
		return t('teamhub', 'Join requests need approval')
	case FIELD.CFG_PROTECTED:
		return t('teamhub', 'Password-protected shares')
	case FIELD.CFG_ROOT:
		return t('teamhub', 'Cannot be a member of another team')
	case FIELD.CFG_FEDERATED:
		// TRANSLATORS: policy setting — may the team include accounts from other Nextcloud servers
		return t('teamhub', 'Federated members allowed')
	case FIELD.EXTERNAL_MEMBERS:
		return t('teamhub', 'External members allowed')
	case FIELD.INTEGRATIONS_ALLOWED:
		return t('teamhub', 'Integrations allowed')
	case FIELD.PUBLIC_MESSAGES:
		return t('teamhub', 'Public messages allowed')
	case FIELD.CONFIDENTIAL_TAG:
		// TRANSLATORS: policy setting — the classification tag put on the team's shared folder
		return t('teamhub', 'Team folder classification')
	default:
		return fieldKey
	}
}

export function fieldHint(fieldKey) {
	switch (fieldKey) {
	case FIELD.CFG_REQUEST:
		// The one field whose behaviour is genuinely surprising, read off
		// Circles' own CircleJoin::manageMemberStatus() — see
		// CirclesConfig::joinPolicy(). It is also why this field carries a
		// `dependsOn` and is greyed out until "Anyone can join" is governed
		// and on: without that, an admin sets it alone and believes the team
		// is "closed but askable", which is not a state Circles has.
		return t('teamhub', 'Only has an effect when "Anyone can join" is on. On its own the team is simply closed.')
	case FIELD.INTEGRATIONS_ALLOWED:
		// v4.8.17 — reworded for the three-state control. "Restrict to" with
		// nothing ticked still allows everything, which is the field's inert
		// setting and the reason the wording has to name it rather than leave an
		// administrator to discover that an empty list is not an empty team.
		return t('teamhub', 'Restricting to nothing allows every integration — the same as leaving it to the team.')
	case FIELD.CONFIDENTIAL_TAG:
		// The sentence an administrator most needs and is most likely to get
		// wrong. Nextcloud has no tag inheritance, so the files themselves are
		// never tagged and show nothing in Files — but the workflow engine reads
		// a file's parent folders when it evaluates a tag condition, so rules
		// keyed on the tag do reach them. Saying only the first half would read
		// as "this does nothing"; saying only the second would promise
		// protection TeamHub does not itself provide.
		return t('teamhub', 'The tag goes on the team folder, not on the files in it. Access-control rules that match this tag still apply to those files.')
	default:
		return ''
	}
}

/** Shown on a greyed-out field, so the reason is on screen and not only implied. */
export function dependencyNote(dependsOnKey) {
	return t('teamhub', 'Available once "{setting}" is switched on above.', { setting: fieldLabel(dependsOnKey) })
}

/**
 * Why a field that needs another app is unavailable (v4.8.24).
 *
 * Three states, not two. "Installed" and "usable" are different questions, and
 * the gap between them is where the v4.6.16 Mail bug lived — an app that was
 * enabled, reported healthy, and could not do the thing being asked of it. An
 * administrator looking at an empty picker deserves to know which of the two
 * they are in, because the fix is different: install an app, or go and define
 * some labels.
 *
 * @param {object} availability `confidentialFiles` from the field catalogue
 * @return {string} empty when the field is usable
 */
export function confidentialAppNote(availability) {
	if (!availability || !availability.appAvailable) {
		// TRANSLATORS: shown on a greyed-out policy setting. "Confidential files" is the name of a Nextcloud app and is not translated.
		return t('teamhub', 'Needs the Confidential files app, which is not installed on this instance.')
	}
	if (!availability.labelsConfigured) {
		// TRANSLATORS: shown on a greyed-out policy setting. "Confidential files" is the name of a Nextcloud app and is not translated.
		return t('teamhub', 'Confidential files is installed but has no classification labels yet. Add one in the Confidential files administration settings, then this setting becomes available.')
	}
	if (!(availability.tags || []).length) {
		// Labels exist but every tag they name has since been deleted. Rare, and
		// distinct from both states above: nothing is missing except the tags.
		return t('teamhub', 'Every tag used by a classification label has been deleted. Repair the labels in the Confidential files administration settings.')
	}
	return ''
}

/**
 * How firmly a chosen tag will sit on the folder.
 *
 * A user-assignable tag can be taken off by anyone who can edit tags; an
 * unassignable one only by an administrator. The field is reported either way —
 * TeamHub never refuses the removal — but the two are not equally fragile, and
 * an administrator choosing between two tags should be able to see which is
 * which without leaving the screen.
 */
export function tagRemovabilityNote(userAssignable) {
	return userAssignable
		? t('teamhub', 'Anyone who can edit tags may remove this one from the folder.')
		: t('teamhub', 'Only an administrator can remove this tag from the folder.')
}

// ── Templates ───────────────────────────────────────────────────────────

/**
 * Seeded template and profile labels are translated by key; an admin who edits
 * a label is taken at their word and the stored text is shown instead. That is
 * why the panel calls `seededLabel(key, storedLabel)` rather than translating
 * unconditionally.
 */
export function seededTemplateLabel(templateKey) {
	switch (templateKey) {
	case 'collaboration':
		return t('teamhub', 'Collaboration')
	case 'project':
		return t('teamhub', 'Project')
	case 'department':
		return t('teamhub', 'Department')
	case 'openproject':
		return t('teamhub', 'OpenProject project')
	default:
		return ''
	}
}

export function seededProfileLabel(profileKey) {
	switch (profileKey) {
	case 'public':
		return t('teamhub', 'Public')
	case 'internal':
		return t('teamhub', 'Internal')
	case 'confidential':
		return t('teamhub', 'Confidential')
	case 'restricted':
		return t('teamhub', 'Restricted')
	default:
		return ''
	}
}

/**
 * The naming rule above, applied (v4.8.15).
 *
 * A seeded row keeps its translated name; one an admin has renamed is shown as
 * they typed it, in every language. An unknown key degrades to the key rather
 * than to a blank chip — the key is still what the team carries.
 *
 * These two exist because the rule now has three call sites (the Policy panel's
 * tables, the Maintenance grid's chips) and three copies of a four-line switch
 * is how the `myWork.js` / `MyWork/*.php` drift started.
 *
 * @param {string}  key         profile key as stored on the team
 * @param {?string} storedLabel the label in teamhub_policy_profile
 * @param {boolean} isSeeded    whether the row shipped with TeamHub
 * @return {string}
 */
export function profileDisplayName(key, storedLabel, isSeeded) {
	if (!key) return ''
	return (isSeeded ? seededProfileLabel(key) : '') || storedLabel || key
}

/** @see profileDisplayName — same rule, template side. */
export function templateDisplayName(key, storedLabel, isSeeded) {
	if (!key) return ''
	return (isSeeded ? seededTemplateLabel(key) : '') || storedLabel || key
}

/**
 * `pages` and `wiki` appear here as well as in `moduleLabel()`: they are stored
 * as modules but shown as apps (see `RESOURCE_MODULES`), and under Apps they
 * carry the product's name rather than the module key's.
 *
 * Those two are **deliberately not `t()`-wrapped** — Intravox and Collectives
 * are product names, and `CreateTeamView.vue` already renders them unwrapped in
 * exactly this position. The Nextcloud apps above are wrapped, because
 * Nextcloud translates its own app names (Files is "Bestanden" in Dutch).
 */
export function appLabel(appId) {
	switch (appId) {
	case 'talk':
		return t('teamhub', 'Talk')
	case 'files':
		return t('teamhub', 'Files')
	case 'calendar':
		return t('teamhub', 'Calendar')
	case 'deck':
		return t('teamhub', 'Deck')
	case 'pages':
	// v4.8.32 — the registry's own spellings, which is what the rollout
	// report and the presence reader answer in. `pages`/`wiki` are the
	// template's words for the same two things; both map here so a label
	// never depends on which layer the key came from.
	case 'intravox':
		return 'Intravox'
	case 'wiki':
	case 'collectives':
		return 'Collectives'
	default:
		return appId
	}
}

/**
 * Label for anything a template can switch on — app or feature module.
 *
 * The rollout report lists both in one column, and `appLabel()` alone would
 * render `presence` as the bare key. Tries the app vocabulary first because
 * `pages` and `wiki` are in both and read better as product names there.
 */
export function integrationLabel(key) {
	const asApp = appLabel(key)
	return asApp === key ? moduleLabel(key) : asApp
}

export function moduleLabel(moduleId) {
	switch (moduleId) {
	case 'decisions':
		return t('teamhub', 'Decisions')
	case 'presence':
		return t('teamhub', 'Presence')
	case 'timeline':
		return t('teamhub', 'Timeline')
	case 'messages':
		return t('teamhub', 'Messages')
	case 'pages':
		return t('teamhub', 'Pages')
	case 'wiki':
		return t('teamhub', 'Collectives')
	default:
		return moduleId
	}
}

// ── Conflicts ───────────────────────────────────────────────────────────

/**
 * A conflicting template × profile pairing does not create a non-conformant
 * team — the profile wins at creation, so the team is conformant by
 * construction. What it does is silently strip something the admin thought the
 * template provided, which is why this is a warning and not a save-blocker.
 */
export function conflictLabel(conflict) {
	if (conflict.kind === 'app_not_allowed') {
		const apps = (conflict.detail || []).map(appLabel).join(', ')
		return t('teamhub', 'The template creates {apps}, which this profile does not allow.', { apps })
	}
	return ''
}
