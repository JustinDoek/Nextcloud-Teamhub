<template>
	<li class="mywork-row" :class="rowClasses">
		<!-- ── Column 1: type glyph + title + resource name ─────────────── -->
		<div class="mywork-row__main">
			<!-- The same glyph the team tab bar uses for this resource's tab,
			     so the row says "this lives in Deck" before a word is read.
			     v4.5.28 — tinted with its team's own colour, the one the team
			     pill in the team column already carries, and with the grey
			     chip behind it removed. Two things on a row that belong to the
			     same team now say so in the same colour. -->
			<span
				class="mywork-row__glyph"
				:class="'mywork-row__glyph--' + teamTone"
				aria-hidden="true">
				<component :is="resourceIcon" :size="iconToolbar" />
			</span>

			<span class="mywork-row__text">
				<button
					type="button"
					class="mywork-row__title"
					:title="t('teamhub', 'Open {title}', { title: item.title })"
					@click="$emit('open', item)">
					{{ item.title }}
				</button>
				<span class="mywork-row__sub">
					<!-- Which source this came from, as a real chip rather
					     than trailing grey text. With three providers this is
					     the fastest way to tell a Deck card from a decision,
					     and it carries the same glyph as the tab it opens. -->
					<span class="mywork-row__source">
						<component :is="providerIcon" :size="iconInline" aria-hidden="true" />
						{{ providerName }}
					</span>
					<span v-if="item.subtitle" class="mywork-row__resource">{{ item.subtitle }}</span>
					<!-- Only when the deadline column is not already saying it.
					     On an overdue row "URGENT" beside "Overdue by 16 days"
					     is the same fact twice, and it was the loudest thing
					     on every row in Justin's screenshot. -->
					<span
						v-if="item.priority === 'urgent' && due.tone !== 'overdue'"
						class="mywork-row__flag mywork-row__flag--urgent">
						{{ priorityLabel(item.priority) }}
					</span>
					<span v-if="isSnoozed" class="mywork-row__flag" :title="snoozedLabel">
						<AlarmSnooze :size="iconInline" aria-hidden="true" />
						{{ t('teamhub', 'Snoozed') }}
					</span>
					<!-- v4.9.7 — a row that leaves TeamHub says so before it is
					     opened. Generic on `metadata.opensIn` (the app's name,
					     set by the provider): OpenProject is the first source
					     whose rows open in another application entirely. -->
					<span
						v-if="opensIn"
						class="mywork-row__flag mywork-row__flag--external"
						:title="t('teamhub', 'Opens in {app}, in a new tab', { app: opensIn })">
						<OpenInNew :size="iconInline" aria-hidden="true" />
						{{ opensIn }}
					</span>
					<!-- v4.8.19 — who has answered, on any row whose source
					     sends a roster. The provider has always put it in
					     metadata; until now nothing rendered it, so "waiting
					     for others" could not say which others. Generic on
					     `metadata.reviewers`, not a file-review branch: the
					     next source with a per-person roster gets it free. -->
					<button
						v-if="roster.length"
						type="button"
						class="mywork-row__flag mywork-row__roster-toggle"
						:aria-expanded="rosterOpen ? 'true' : 'false'"
						:title="rosterSummary"
						@click="rosterOpen = !rosterOpen">
						<ChevronDown v-if="!rosterOpen" :size="iconInline" aria-hidden="true" />
						<ChevronUp v-else :size="iconInline" aria-hidden="true" />
						{{ rosterSummary }}
					</button>
				</span>
			</span>
		</div>

		<!-- ── Column 2: team badge ─────────────────────────────────────── -->
		<div class="mywork-row__cell mywork-row__cell--team">
			<button
				type="button"
				class="mywork-row__team"
				:class="'mywork-row__team--' + teamTone"
				:title="t('teamhub', 'Open team {team}', { team: item.teamName })"
				@click="$emit('open-team', item)">
				{{ item.teamName }}
			</button>
			<span
				v-if="extraTeamCount > 0"
				class="mywork-row__team-more"
				:title="t('teamhub', 'This resource is also linked to other teams you are in.')">
				{{ n('teamhub', '+{n} other team', '+{n} other teams', extraTeamCount, { n: extraTeamCount }) }}
			</span>
		</div>

		<!-- ── Column 3: why is this here, and who is it on ─────────────── -->
		<div class="mywork-row__cell mywork-row__cell--reason">
			<NcAvatar
				v-if="reasonAvatarUid"
				:user="reasonAvatarUid"
				:display-name="reasonAvatarName"
				:size="20"
				:show-user-status="false"
				:disable-menu="true"
				class="mywork-row__avatar" />
			<span v-else class="mywork-row__reason-glyph" aria-hidden="true">
				<InformationOutline :size="iconInline" />
			</span>
			<span class="mywork-row__reason-text" :title="fullReason">{{ shortReason }}</span>
		</div>

		<!-- ── Column 4: deadline ───────────────────────────────────────── -->
		<div class="mywork-row__cell mywork-row__cell--due">
			<span
				v-if="due.text"
				class="mywork-row__due"
				:class="'mywork-row__due--' + due.tone"
				:title="dueTitle">
				<AlertCircleOutline v-if="due.tone === 'overdue'" :size="iconInline" aria-hidden="true" />
				{{ due.text }}
			</span>
			<span v-else class="mywork-row__due mywork-row__due--none">—</span>
		</div>

		<!-- ── Column 5: actions ────────────────────────────────────────────
		     v4.5.25 — one button and a menu, per Justin's mockup. Up to three
		     labelled action buttons used to sit here; with four sources and
		     twenty rows that was the loudest part of the page, and every row
		     shouting equally is the same as no row shouting. Open is the only
		     thing promoted because it is the only action every row has, and it
		     is the one that leads to all the others. Nothing was removed —
		     Approve, Reject and Complete are one click further away, in the
		     menu, where the deliberate actions belong. -->
		<div class="mywork-row__actions">
			<NcButton
				variant="secondary"
				:disabled="busy"
				:aria-label="t('teamhub', 'Open {title}', { title: item.title })"
				:title="t('teamhub', 'Open {title}', { title: item.title })"
				@click="$emit('open', item)">
				<template #icon><OpenInNew :size="iconBody" /></template>
			</NcButton>

			<NcActions
				v-if="menuActions.length || canSnooze"
				:aria-label="t('teamhub', 'More actions for {title}', { title: item.title })"
				:disabled="busy">
				<template v-for="action in menuActions" :key="action">
					<NcActionButton
						v-if="action !== 'snooze'"
						close-after-click
						@click="$emit('action', { item, action })">
						<template #icon>
							<component :is="actionIcon(action)" :size="iconNav" />
						</template>
						{{ actionLabel(action) }}
					</NcActionButton>
				</template>

				<template v-if="canSnooze">
					<NcActionSeparator v-if="menuActions.length" />
					<NcActionCaption :name="t('teamhub', 'Snooze until')" />
					<NcActionButton
						v-for="preset in snoozeOptions"
						:key="preset.key"
						close-after-click
						@click="$emit('snooze', { item, preset: preset.key })">
						<template #icon><AlarmSnooze :size="iconNav" /></template>
						{{ preset.label }}
					</NcActionButton>
				</template>
			</NcActions>
		</div>

		<!-- ── Full-width roster panel (v4.8.19) ────────────────────────────
		     Spans every column so the names line up under the title rather
		     than inside the 200px reason cell. Only rendered when open, so a
		     list of twenty rows costs nothing until somebody asks. -->
		<ul v-if="rosterOpen && roster.length" class="mywork-row__roster">
			<li
				v-for="person in roster"
				:key="person.uid"
				class="mywork-row__roster-item">
				<NcAvatar
					:user="person.uid"
					:display-name="person.displayName"
					:size="20"
					:show-user-status="false"
					:disable-menu="true"
					class="mywork-row__avatar" />
				<span class="mywork-row__roster-name">{{ person.displayName }}</span>
				<span
					v-if="person.completedAt"
					class="mywork-row__roster-state mywork-row__roster-state--done">
					<Check :size="iconInline" aria-hidden="true" />
					{{ t('teamhub', 'Completed {date}', { date: formatAbsolute(person.completedAt) }) }}
				</span>
				<span v-else class="mywork-row__roster-state">
					{{ t('teamhub', 'Not yet') }}
				</span>
				<!-- The remark is the reviewer's own words and the only part
				     of their reasoning that outlives the review's chat room,
				     so it is shown in full rather than truncated. -->
				<span v-if="person.remark" class="mywork-row__roster-remark">
					“{{ person.remark }}”
				</span>
			</li>
		</ul>
	</li>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcButton, NcActions, NcActionButton, NcActionSeparator, NcActionCaption, NcAvatar } from '@nextcloud/vue'

import AlarmSnooze from 'vue-material-design-icons/AlarmSnooze.vue'
import AlarmOff from 'vue-material-design-icons/AlarmOff.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import Check from 'vue-material-design-icons/Check.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import Close from 'vue-material-design-icons/Close.vue'
import CommentEditOutline from 'vue-material-design-icons/CommentEditOutline.vue'
import CommentOutline from 'vue-material-design-icons/CommentOutline.vue'
import AccountArrowRight from 'vue-material-design-icons/AccountArrowRight.vue'
// v4.5.45 — Team admin rows (resource review); also the category's glyph.
import ShieldAccountOutline from 'vue-material-design-icons/ShieldAccountOutline.vue'
// v4.6.13 — team expiration rows, and requests to extend one.
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
// v4.6.17 — somebody asking to join a team.
import AccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'
// v4.8.18 — file reviews: the provider's own glyph, and the requester's
// Close review action.
import FileEyeOutline from 'vue-material-design-icons/FileEyeOutline.vue'
import BriefcaseOutline from 'vue-material-design-icons/BriefcaseOutline.vue'
// v4.9.7 — an OpenProject milestone's glyph.
import FlagOutline from 'vue-material-design-icons/FlagOutline.vue'
import ArchiveCheckOutline from 'vue-material-design-icons/ArchiveCheckOutline.vue'
// v4.8.19 — the roster panel's disclosure control.
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import VideoOutline from 'vue-material-design-icons/VideoOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
// v4.6.16 — the Email owner action on an expiring-team row.
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
// Resource-type + provider glyphs — the SAME components the team tab bar
// uses, so an item's icon in My Work is the icon of the tab it opens.
import CardText from 'vue-material-design-icons/CardText.vue'
import Folder from 'vue-material-design-icons/Folder.vue'
import Gavel from 'vue-material-design-icons/Gavel.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import Puzzle from 'vue-material-design-icons/Puzzle.vue'

import {
	ACTION,
	ACTION_ICONS,
	CATEGORY,
	FALLBACK_ICON,
	PRIMARY_ACTIONS,
	PROVIDER_ICONS,
	RESOURCE_TYPE_ICONS,
	actionLabel,
	formatAbsolute,
	formatDue,
	priorityLabel,
	snoozePresets,
	teamBadgeTone,
} from '../../constants/myWork.js'
import { ICON_INLINE, ICON_BODY, ICON_TOOLBAR, ICON_NAV } from '../../constants/uiTokens.js'

/**
 * One row in the My Work queue.
 *
 * Laid out as four aligned columns — item, team, reason, deadline — plus
 * actions, so a queue of twenty rows scans vertically instead of forcing the
 * eye to re-parse each card. The columns collapse to a stacked block below
 * the tablet breakpoint, where alignment buys nothing and vertical space is
 * the scarce resource.
 *
 * Renders from the normalized work-item model only: there is no provider
 * branch anywhere in this component, which is what makes a new backend
 * provider free at the UI layer.
 */
export default {
	name: 'MyWorkItemRow',
	components: {
		NcButton, NcActions, NcActionButton, NcActionSeparator, NcActionCaption, NcAvatar,
		AlarmSnooze, AlarmOff, AlertCircleOutline,
		Check, CheckCircleOutline,
		Close, CommentEditOutline, CommentOutline, AccountArrowRight,
		InformationOutline, OpenInNew, VideoOutline, FileDocumentOutline, EmailOutline,
		CardText, Folder, Gavel, Calendar, Puzzle, ShieldAccountOutline, CalendarClock,
		AccountPlusOutline, FileEyeOutline, ArchiveCheckOutline, BriefcaseOutline, FlagOutline,
		ChevronDown, ChevronUp,
	},
	props: {
		item: { type: Object, required: true },
		/** id → translated provider name, so the row can label its source. */
		providerNames: { type: Object, default: () => ({}) },
		/** True while an action on this row is in flight. */
		busy: { type: Boolean, default: false },
		/** Compact density — action buttons collapse to icons only. */
		compact: { type: Boolean, default: false },
	},
	emits: ['open', 'open-team', 'action', 'snooze'],

	data() {
		return {
			/** v4.8.19 — the roster panel starts collapsed. */
			rosterOpen: false,
		}
	},

	computed: {
		iconInline() { return ICON_INLINE },
		iconBody() { return ICON_BODY },
		/** v4.5.30 — the row's type glyph stepped up one notch on the scale. */
		iconToolbar() { return ICON_TOOLBAR },
		iconNav() { return ICON_NAV },

		rowClasses() {
			return {
				'mywork-row--snoozed': this.isSnoozed,
				'mywork-row--completed': this.item.category === CATEGORY.COMPLETED,
				'mywork-row--compact': this.compact,
			}
		},

		due() {
			return formatDue(this.item.dueAt)
		},

		/**
		 * When the date is TeamHub's own derived expiry rather than a real
		 * deadline in the source app, say so — presenting an invented
		 * deadline as the source's would be misleading.
		 */
		dueTitle() {
			if (!this.item.dueAt) {
				return ''
			}
			const absolute = formatAbsolute(this.item.dueAt)
			if (this.item.metadata?.dueAtIsDerived) {
				return t('teamhub', 'Expected by {date}. This is a TeamHub reminder, not a deadline set in the source app.', { date: absolute })
			}
			return t('teamhub', 'Due {date}', { date: absolute })
		},

		resourceIcon() {
			return RESOURCE_TYPE_ICONS[this.item.resourceType] || FALLBACK_ICON
		},

		providerIcon() {
			return PROVIDER_ICONS[this.item.providerId] || FALLBACK_ICON
		},

		teamTone() {
			return teamBadgeTone(this.item.teamId)
		},

		providerName() {
			return this.providerNames[this.item.providerId] || this.item.providerId
		},

		isSnoozed() { return !!this.item.metadata?.snoozed },

		/** v4.9.7 — the name of the app a row opens in, when it is not TeamHub. */
		opensIn() {
			const app = this.item.metadata?.opensIn
			return typeof app === 'string' && app !== '' ? app : ''
		},

		/**
		 * v4.8.19 — the per-person roster a source may attach to an item.
		 *
		 * Shape: `[{ uid, displayName, completedAt, remark }]`. File reviews
		 * are the first source to send one; nothing here knows that, which is
		 * the point — a provider that attaches the same key gets the panel
		 * without a change in this file.
		 */
		roster() {
			const list = this.item.metadata?.reviewers
			return Array.isArray(list) ? list : []
		},

		rosterDone() {
			return this.roster.filter(r => !!r.completedAt).length
		},

		/**
		 * "2 of 4 reviewed". Not `n()`: both numbers vary independently, so
		 * there is no single count to pluralise on, and every language in the
		 * project renders this as one phrase with two placeholders.
		 */
		rosterSummary() {
			return t('teamhub', '{done} of {total} reviewed', {
				done: this.rosterDone,
				total: this.roster.length,
			})
		},

		snoozedLabel() {
			const until = this.item.metadata?.snoozedUntil
			return until
				? t('teamhub', 'Snoozed until {date}', { date: formatAbsolute(until) })
				: t('teamhub', 'Snoozed')
		},

		extraTeamCount() {
			return (this.item.metadata?.additionalTeamIds || []).length
		},

		/**
		 * The reason column shows a person where there is one — a requester,
		 * an approver, the assignee of a blocking card — because "who" is the
		 * fastest way to recognise an item you already know about.
		 */
		reasonAvatarUid() {
			return this.item.waitingFor?.type === 'user'
				? this.item.waitingFor.id
				: (this.item.metadata?.requester?.uid
					|| this.item.metadata?.blockedBy?.[0]?.assignee?.uid
					|| null)
		},

		reasonAvatarName() {
			return this.item.waitingFor?.type === 'user'
				? this.item.waitingFor.displayName
				: (this.item.metadata?.requester?.displayName
					|| this.item.metadata?.blockedBy?.[0]?.assignee?.displayName
					|| '')
		},

		/**
		 * A short label for the column, with the provider's full sentence kept
		 * on the tooltip. The full reason is often a whole clause ("You have
		 * been designated as an approver") which would wrap to three lines in
		 * a table cell and destroy the row rhythm.
		 */
		shortReason() {
			const party = this.item.waitingFor
			if (party?.displayName) {
				if (party.type === 'group') {
					return t('teamhub', 'Waiting for the group {name}', { name: party.displayName })
				}
				if (party.type === 'circle') {
					return t('teamhub', 'Waiting for the team {name}', { name: party.displayName })
				}
				return t('teamhub', 'Waiting for {name}', { name: party.displayName })
			}
			const blocker = this.item.metadata?.blockedBy?.[0]
			if (blocker) {
				return t('teamhub', 'Blocked by “{title}”', { title: blocker.title })
			}
			return this.item.reason
		},

		fullReason() {
			return this.item.reason
		},

		/**
		 * Everything the row can do, minus the two that are not menu items:
		 * Open has its own button, and Snooze has its own preset submenu below.
		 *
		 * Ordered by PRIMARY_ACTIONS first so the menu opens with the most
		 * consequential verb at the top — the ordering that used to decide
		 * which buttons were promoted now decides which entry the eye lands on.
		 */
		menuActions() {
			const available = this.item.availableActions || []
			const ranked = [
				...PRIMARY_ACTIONS.filter(a => available.includes(a)),
				...available.filter(a => !PRIMARY_ACTIONS.includes(a)),
			]
			return ranked.filter(a => a !== ACTION.SNOOZE && a !== ACTION.OPEN)
		},

		canSnooze() {
			return (this.item.availableActions || []).includes(ACTION.SNOOZE)
		},

		/**
		 * Evaluated in a computed rather than at module scope, so `t()` runs
		 * after Nextcloud's l10n bundle has loaded.
		 */
		snoozeOptions() {
			return snoozePresets()
		},
	},

	methods: {
		t,
		n,
		actionLabel,
		priorityLabel,
		// v4.8.19 — the roster panel formats each completion time in the
		// template. Options API: a helper is only callable from the template
		// if it is exposed here (SKILLS.md § Exposing `t` and `n`).
		formatAbsolute,

		actionIcon(action) {
			return ACTION_ICONS[action] || FALLBACK_ICON
		},

	},
}
</script>

<style scoped lang="scss">
/* Four content columns + actions. The template is shared with the section
   header in MyWorkView so the column captions line up with the cells. */
/* Column widths come from the parent (.mywork__groups) through the cascade,
   so the section header's captions and these cells cannot drift apart. The
   fallbacks keep the row usable if it is ever mounted outside that wrapper. */
.mywork-row {
	display: grid;
	grid-template-columns:
		minmax(0, 1fr)
		var(--th-mywork-col-team, 128px)
		var(--th-mywork-col-reason, minmax(0, 200px))
		var(--th-mywork-col-due, 148px)
		var(--th-mywork-col-actions, 236px);
	align-items: center;
	gap: 12px;
	padding: 9px 16px;
	border-top: 1px solid var(--color-border);
	background: var(--color-main-background);

	&:hover {
		background: var(--color-background-hover);
	}
}

/* v4.5.25 — compact actually does something now.
   It used to remove the action buttons' labels, and when the row dropped to a
   single icon button there was nothing left for it to change: the class was
   applied and styled nothing, so the toggle did nothing. Density is now what
   the name always implied — vertical space. Roughly 40% shorter rows, so a
   long Action-required list fits on one screen. */
.mywork-row--compact {
	padding-top: 4px;
	padding-bottom: 4px;
	row-gap: 0;

	.mywork-row__sub {
		/* The second line is the first thing to go: it is context, and in
		   compact mode the user has chosen density over context. */
		display: none;
	}

	/* v4.8.20 — except the roster disclosure. Hiding the whole second line
	   also hid "2 of 4 reviewed", which is not context but the answer to the
	   question the row exists to raise — and the only way to reach the names.
	   Compact should cost density, not a control. */
	.mywork-row__sub:has(.mywork-row__roster-toggle) {
		display: block;

		> *:not(.mywork-row__roster-toggle) {
			display: none;
		}
	}

	.mywork-row__title { font-size: var(--th-font-meta, 12px); }

	.mywork-row__team,
	.mywork-row__reason-text,
	.mywork-row__due {
		font-size: var(--th-font-micro, 11px);
	}
}

/* ── Roster panel (v4.8.19) ──────────────────────────────────────────────
   `grid-column: 1 / -1` is what puts it under the whole row rather than in
   one cell: the row is a five-column grid, and a sixth child would otherwise
   start a second implicit row of columns. */
.mywork-row__roster-toggle {
	/* A raw <button> rather than NcButton: this sits inside the row's second
	   line among other flag chips, and NcButton's 44px touch target would
	   double the row height for a disclosure control. SKILLS.md § "NcButton is
	   the default" — the carve-out is the same one the chip-remove buttons use. */
	display: inline-flex;
	align-items: center;
	gap: 2px;
	border: none;
	background: none;
	padding: 0;
	margin: 0;
	min-height: 0;
	font: inherit;
	color: var(--color-text-maxcontrast);
	cursor: pointer;

	&:hover { color: var(--color-main-text); }

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 1px;
		border-radius: var(--border-radius);
	}
}

.mywork-row__roster {
	grid-column: 1 / -1;
	list-style: none;
	margin: 4px 0 2px;
	padding: 8px 12px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.mywork-row__roster-item {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px;
	font-size: var(--th-font-meta, 12px);
}

.mywork-row__roster-name { font-weight: 600; }

.mywork-row__roster-state {
	display: inline-flex;
	align-items: center;
	gap: 2px;
	color: var(--color-text-maxcontrast);
}

/* Not colour alone: the state also carries a check glyph and its own words,
   so it survives a monochrome or colour-blind reading (WCAG 1.4.1). */
.mywork-row__roster-state--done { color: var(--color-success-text, var(--color-success)); }

.mywork-row__roster-remark {
	flex-basis: 100%;
	margin-inline-start: 28px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.mywork-row--snoozed { opacity: 0.62; }

.mywork-row--completed .mywork-row__title {
	color: var(--color-text-maxcontrast);
}

/* ── Column 1 ──────────────────────────────────────────────────────── */

/* v4.5.27 — top-aligned, not centred. Against a two-line row the centred
   glyph floated between the title and the sub-line, belonging to neither;
   Justin asked for it level with the subject. */
.mywork-row__main {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	min-width: 0;
}

/* v4.5.30 — the glyph wears its team's pill: the same soft fill and the same
   ink the team chip in the team column uses, from the same deterministic tone.
   Two things on a row that belong to the same team now say so the same way.
   This supersedes 4.5.22's "deliberately uncoloured", 4.5.27's flat primary,
   and 4.5.28's fill-less version.

   Six locks on the box because NC's global button rule sets both min-width and
   min-height to 44px, and per spec min-* beats an unqualified width/height
   (SKILLS.md § UI shapes) — this is a span today, but the pattern is the one
   that survives someone turning it into a button. */
.mywork-row__glyph {
	flex: 0 0 auto;
	box-sizing: border-box;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	min-width: 32px;
	min-height: 32px;
	max-width: 32px;
	max-height: 32px;
	padding: 0;
	border-radius: var(--th-radius-chip, 10px);
	/* Optical alignment against the title *button*, not against its line box.
	   The chip is a filled shape, so its visible top is its box top; the
	   title's visible top is its cap height, which sits ~4px below the top of a
	   14px/1.2 line box (half-leading plus the ascender gap). At the old 1px
	   the chip started above the text it is meant to line up with, which is
	   what Justin's screenshot showed. Compact rows are 12px/1.2, where the
	   same offset is ~3px — under a pixel of difference, not worth a second
	   rule. */
	margin-top: 4px;
	/* Fallbacks for a row with no team — the tone class is data-driven, and a
	   gap would otherwise render as transparent-on-black. */
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.mywork-row__glyph--1 { background: var(--th-mywork-team-1-bg); color: var(--th-mywork-team-1-ink); }
.mywork-row__glyph--2 { background: var(--th-mywork-team-2-bg); color: var(--th-mywork-team-2-ink); }
.mywork-row__glyph--3 { background: var(--th-mywork-team-3-bg); color: var(--th-mywork-team-3-ink); }
.mywork-row__glyph--4 { background: var(--th-mywork-team-4-bg); color: var(--th-mywork-team-4-ink); }
.mywork-row__glyph--5 { background: var(--th-mywork-team-5-bg); color: var(--th-mywork-team-5-ink); }
.mywork-row__glyph--6 { background: var(--th-mywork-team-6-bg); color: var(--th-mywork-team-6-ink); }

.mywork-row__text {
	display: flex;
	flex-direction: column;
	gap: 1px;
	min-width: 0;
}

/* Raw <button>: a full-width text affordance inside a card row — the
   documented carve-out in SKILLS.md § "NcButton is the default". */
.mywork-row__title {
	background: none;
	border: none;
	padding: 0;
	margin: 0;
	text-align: left;
	cursor: pointer;
	color: var(--color-main-text);
	font-size: var(--th-font-body, 14px);
	font-weight: var(--th-font-weight-semibold, 600);
	line-height: var(--th-line-height-tight, 1.2);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;

	&:hover { text-decoration: underline; }
	/* Split from :hover on purpose — grouping them is what silently kills
	   the keyboard focus ring (SKILLS.md § Focus visibility standard). */
	&:focus-visible {
		text-decoration: underline;
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
		border-radius: 2px;
	}
}

.mywork-row__sub {
	display: flex;
	align-items: center;
	gap: 6px;
	min-width: 0;
	font-size: var(--th-font-micro, 11px);
	color: var(--color-text-maxcontrast);
}

.mywork-row__resource {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* Source chip — bordered so it reads as a label rather than as more meta
   text. Sits first in the sub-line because "which app is this from" is the
   question the eye asks before the document name. */
.mywork-row__source {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	flex: 0 0 auto;
	padding: 1px 7px;
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--th-radius-pill, 999px);
	color: var(--color-text-maxcontrast);
	font-weight: var(--th-font-weight-semibold, 600);
	line-height: 1.5;
}

.mywork-row__flag {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	flex: 0 0 auto;
}

.mywork-row__flag--urgent {
	padding: 0 6px;
	border-radius: var(--th-radius-pill, 999px);
	background: var(--color-error);
	color: var(--color-error-text, var(--color-primary-element-text));
	font-weight: var(--th-font-weight-semibold, 600);
	text-transform: uppercase;
	letter-spacing: 0.03em;
}

/* v4.9.7 — "opens in OpenProject": quiet, bordered, the same weight as the
   source chip beside it — a fact about the row, not an alarm. */
.mywork-row__flag--external {
	padding: 0 6px;
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--th-radius-pill, 999px);
	color: var(--color-text-maxcontrast);
}

/* ── Shared cell ───────────────────────────────────────────────────── */

.mywork-row__cell {
	display: flex;
	align-items: center;
	gap: 6px;
	min-width: 0;
	font-size: var(--th-font-micro, 11px);
	color: var(--color-text-maxcontrast);
}

/* ── Column 2: team badge ──────────────────────────────────────────── */

/* Deterministic tint per team (teamBadgeTone) so the same team is always
   the same colour — that is what makes it recognisable rather than
   decorative. The team name is always present in words. */
.mywork-row__team {
	max-width: 100%;
	padding: 2px 9px;
	border: none;
	border-radius: var(--th-radius-pill, 999px);
	cursor: pointer;
	font-size: var(--th-font-micro, 11px);
	font-weight: var(--th-font-weight-semibold, 600);
	line-height: 1.5;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;

	&:hover { filter: brightness(0.96); }
	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 1px;
	}
}

.mywork-row__team--1 { background: var(--th-mywork-team-1-bg); color: var(--th-mywork-team-1-ink); }
.mywork-row__team--2 { background: var(--th-mywork-team-2-bg); color: var(--th-mywork-team-2-ink); }
.mywork-row__team--3 { background: var(--th-mywork-team-3-bg); color: var(--th-mywork-team-3-ink); }
.mywork-row__team--4 { background: var(--th-mywork-team-4-bg); color: var(--th-mywork-team-4-ink); }
.mywork-row__team--5 { background: var(--th-mywork-team-5-bg); color: var(--th-mywork-team-5-ink); }
.mywork-row__team--6 { background: var(--th-mywork-team-6-bg); color: var(--th-mywork-team-6-ink); }

.mywork-row__team-more {
	flex: 0 0 auto;
	white-space: nowrap;
}

/* ── Column 3: reason ──────────────────────────────────────────────── */

.mywork-row__avatar { flex: 0 0 auto; }

.mywork-row__reason-glyph {
	flex: 0 0 auto;
	display: inline-flex;
	opacity: 0.7;
}

.mywork-row__reason-text {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* ── Column 4: deadline ────────────────────────────────────────────── */

.mywork-row__due {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	white-space: nowrap;
	font-weight: var(--th-font-weight-medium, 500);
}

/* Overdue and today also carry an icon and their own words, so colour is
   never the only carrier (WCAG 1.4.1). */
.mywork-row__due--overdue {
	color: var(--color-error-text, var(--color-error));
	font-weight: var(--th-font-weight-semibold, 600);
}

.mywork-row__due--today {
	color: var(--th-mywork-today-accent);
	font-weight: var(--th-font-weight-semibold, 600);
}

.mywork-row__due--none { opacity: 0.5; }

/* ── Column 5: actions ─────────────────────────────────────────────── */

.mywork-row__actions {
	display: flex;
	align-items: center;
	justify-content: flex-end;
	gap: 4px;
}

/* ── Narrow: two lines, not five ───────────────────────────────────── */

/* v4.5.39 — the four desktop columns used to stack into four rows, which gave
   the team pill a line of its own with nothing else on it. Justin asked for it
   to join the information already below the title.

   Flex, not grid, because the three meta cells want to flow and wrap against
   each other rather than sit in fixed tracks: on a phone "You are an approver
   for this decision" and "Overdue by 32 days" are very different widths and
   any track wide enough for one wastes the row for the other. Nothing is
   dropped — every cell that exists on desktop is still here. */
@media (max-width: 900px) {
	.mywork-row {
		display: flex;
		flex-wrap: wrap;
		align-items: flex-start;
		gap: 6px 10px;
		padding: 12px 14px;
	}

	.mywork-row__main    { order: 0; flex: 1 1 auto; min-width: 0; }
	.mywork-row__actions { order: 1; flex: 0 0 auto; margin-left: auto; }

	/* A zero-height full-width flex item: the standard way to force a line
	   break in a wrapping flex container, so the meta cells always start a
	   fresh line instead of squeezing in beside the actions. */
	.mywork-row::after {
		content: '';
		order: 2;
		flex-basis: 100%;
		height: 0;
	}

	.mywork-row__cell         { order: 3; flex: 0 1 auto; max-width: none; }
	.mywork-row__cell--team   { order: 3; }
	/* Takes the slack, gives it up first — it is the longest and the most
	   compressible of the three. */
	.mywork-row__cell--reason { order: 4; flex: 1 1 auto; min-width: 0; }
	.mywork-row__cell--due    { order: 5; }
}
</style>
