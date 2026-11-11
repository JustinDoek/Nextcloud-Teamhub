<template>
	<!-- `no-close` and `close-on-click-outside: false` are the whole point of
	     this component: there is no cross, no backdrop click and no Escape out
	     of it. The only way past it is the button row below, and that row is
	     locked until the body has been scrolled to its end. -->
	<NcDialog
		:name="t('teamhub', 'Message from TeamHub')"
		:open="true"
		size="normal"
		no-close
		:close-on-click-outside="false">
		<template #default>
			<div v-if="loading" class="th-announce-dialog__loading">
				{{ t('teamhub', 'Loading message') }}
			</div>

			<NcNoteCard v-else-if="error" type="warning">
				{{ errorDescription }}
			</NcNoteCard>

			<!-- tabindex 0 so the scroll region is reachable by keyboard: a
			     gate that can only be opened with a mouse wheel is not a gate,
			     it is a trap. With focus here, Page Down and End work and the
			     scroll handler fires exactly as it does for a pointer. -->
			<div
				v-else
				ref="scroller"
				class="th-announce-dialog__scroller"
				tabindex="0"
				role="region"
				:aria-label="t('teamhub', 'Message from TeamHub')"
				@scroll="onScroll">
				<article class="th-announce-dialog__body" v-html="rendered" />
			</div>

			<p v-if="!loading && !error && !atEnd" class="th-announce-dialog__gate" role="status">
				{{ t('teamhub', 'Scroll to the end of the message to continue.') }}
			</p>
		</template>

		<template #actions>
			<!-- The checkbox sits in the action row rather than at the end of
			     the body, because it is a decision about the dialog and not
			     part of what was written. Disabled until the end is reached,
			     which is the one documented departure from the
			     hidden-not-disabled rule that applies here: it is a state of
			     the reading, not a role the administrator lacks, and a control
			     that appears only once you reach the bottom is a control
			     nobody expects to find. -->
			<NcCheckboxRadioSwitch
				v-if="!loading && !error"
				class="th-announce-dialog__optout"
				type="checkbox"
				:model-value="optOut"
				:disabled="!atEnd"
				@update:model-value="optOut = $event">
				<!-- TRANSLATORS: checkbox in a message administrators have to
				     read. Ticked, this exact message is never shown again on
				     this instance for this administrator. -->
				{{ t('teamhub', 'Do not show this message again') }}
			</NcCheckboxRadioSwitch>

			<NcButton
				variant="primary"
				:disabled="loading || busy || (!error && !atEnd)"
				@click="confirm">
				{{ t('teamhub', 'OK') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcNoteCard } from '@nextcloud/vue'
import { renderAnnouncement } from '../lib/announcementMarkdown.js'

/**
 * The forced in-app message to Nextcloud administrators (v4.13.56).
 *
 * ## Why it exists
 *
 * In-app announcements have shipped since v4.5.0 as an envelope in the sidebar
 * footer that opens a canvas page. Justin, 2026-10-31: *"I don't get any
 * feedback from those messages at all which makes me wonder if they are at all
 * effective."* An envelope cannot tell "nobody cared" from "nobody noticed",
 * and nothing in the app counted either.
 *
 * So: administrator-targeted announcements are put in front of the
 * administrator instead of offered, and four counters say what happened.
 * `role: 'everyone'` announcements are untouched and still use the envelope.
 * Making every member of every instance clear a scroll gate to reach their
 * teams is not a thing a team app should do.
 *
 * ## The gate, and what it is actually for
 *
 * The body scrolls inside the dialog. Until it has been scrolled to the end
 * the checkbox and OK are both disabled. That is the point: `read` is the
 * counter worth having, and a dialog that can be cleared without scrolling
 * would only ever have counted `shown`.
 *
 * **A body shorter than the scroller counts as read on arrival.** Without
 * that, a two-line announcement can never be dismissed — there is nothing to
 * scroll — and the feature would lock administrators out of the app. The same
 * check runs on load and on every resize, because a window that starts narrow
 * and is widened turns a scrolling body into a short one.
 *
 * ## Coming back
 *
 * OK alone suppresses it **for this browser session**, so it returns at the
 * next sign-in; `App.vue` holds that flag in `sessionStorage`. OK with the box
 * ticked calls the existing dismiss endpoint, which writes the per-user row
 * that has always made an announcement stay gone — and because the registry
 * pins each announcement to one exact app version, "gone" already means "for
 * this version", which is what was asked for.
 *
 * `sessionStorage` rather than a server round trip: it is per-tab-session
 * state about a dialog, it needs no durability, and a user preference would
 * have to be cleared again at the next version.
 */
export default {
	name: 'AnnouncementDialog',

	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcNoteCard },

	props: {
		/** Basename of the announcement's markdown file, from /announcements. */
		filename: {
			type: String,
			required: true,
		},
	},

	emits: ['closed', 'dismissed'],

	data() {
		return {
			body: null,
			loading: true,
			error: false,
			errorDescription: '',
			atEnd: false,
			optOut: false,
			busy: false,
		}
	},

	computed: {
		rendered() {
			return renderAnnouncement(this.body)
		},
	},

	mounted() {
		this.load()
		window.addEventListener('resize', this.measure)
	},

	beforeUnmount() {
		window.removeEventListener('resize', this.measure)
	},

	methods: {
		t,

		async load() {
			this.loading = true
			this.error = false
			this.body = null
			try {
				const { data } = await axios.get(
					generateUrl('/apps/teamhub/api/v1/announcements/body'),
					{ params: { filename: this.filename } },
				)
				this.body = String(data?.body ?? '')
				this.record('shown')
			} catch (e) {
				this.error = true
				this.errorDescription = e?.response?.status === 404
					? t('teamhub', 'This message is no longer available.')
					: t('teamhub', 'Could not load the message. Try again in a moment.')
			} finally {
				this.loading = false
				// After the body is in the DOM, so a message too short to
				// scroll unlocks the controls instead of sealing the dialog.
				this.$nextTick(this.measure)
			}
		},

		onScroll() {
			this.measure()
		},

		/**
		 * Is the reader at the end of the body?
		 *
		 * A two-pixel tolerance, because `scrollTop` is fractional on a
		 * zoomed page and on a high-DPI display, and an exact comparison
		 * leaves the last pixel unreachable.
		 *
		 * One-way: once at the end, scrolling back up does not re-lock the
		 * controls. Re-locking would punish somebody re-reading the top.
		 */
		measure() {
			if (this.atEnd) return
			const el = this.$refs.scroller
			if (!el) return
			const reached = el.scrollHeight - el.scrollTop - el.clientHeight <= 2
			if (reached) {
				this.atEnd = true
				this.record('read')
			}
		},

		/**
		 * Count one event. Fire and forget: a counter is not worth an error
		 * message on a message the administrator is trying to read, and the
		 * server refuses duplicates anyway.
		 */
		record(event) {
			axios.post(
				generateUrl('/apps/teamhub/api/v1/announcements/seen'),
				{ filename: this.filename, event },
			).catch(() => {})
		},

		async confirm() {
			this.busy = true
			if (this.optOut) {
				try {
					await axios.post(
						generateUrl('/apps/teamhub/api/v1/announcements/dismiss'),
						{ filename: this.filename },
					)
					this.record('optout')
					this.$emit('dismissed')
					return
				} catch (e) {
					// The dismiss did not land, so the box did not take effect.
					// Fall through to the session suppression: the
					// administrator pressed OK and must not be held here, and
					// the message coming back next time is the honest outcome
					// of a write that failed.
					this.busy = false
				}
			}

			this.record('ack')
			// App.vue owns the session suppression: it already has the
			// sessionStorage read/write pair the getting-started callout uses,
			// and it is the component that decides whether to draw this
			// dialog at all. Two places writing the same key is how one of
			// them ends up writing a different one.
			this.$emit('closed')
		},
	},
}
</script>

<style scoped>
/* The scroll region, not the dialog, is what scrolls. A fixed height is what
   makes the gate meaningful: let the dialog grow to its content and a short
   viewport decides whether there was anything to scroll. */
.th-announce-dialog__scroller {
	max-height: 50vh;
	min-height: 120px;
	overflow-y: auto;
	padding-inline-end: var(--th-space-sm);
}

.th-announce-dialog__scroller:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
	border-radius: var(--border-radius-small);
}

.th-announce-dialog__loading {
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-body);
	padding: var(--th-space-xl) 0;
	text-align: center;
}

.th-announce-dialog__gate {
	margin: var(--th-space-sm) 0 0;
	font-size: var(--th-font-meta);
	color: var(--color-text-maxcontrast);
}

/* Pushes the OK button to the end of the action row, so the checkbox reads as
   a qualifier on the button rather than as another button. */
.th-announce-dialog__optout {
	margin-inline-end: auto;
}

.th-announce-dialog__body {
	font-size: var(--th-font-body);
	line-height: var(--th-line-height-relaxed);
	color: var(--color-main-text);
}
.th-announce-dialog__body :deep(h1),
.th-announce-dialog__body :deep(h2),
.th-announce-dialog__body :deep(h3) {
	margin-top: var(--th-space-lg);
	margin-bottom: var(--th-space-sm);
	font-weight: var(--th-font-weight-semibold);
	line-height: var(--th-line-height-tight);
}
.th-announce-dialog__body :deep(h1) { font-size: var(--th-font-heading-lg); }
.th-announce-dialog__body :deep(h2) { font-size: var(--th-font-heading); }
.th-announce-dialog__body :deep(h3) { font-size: var(--th-font-body); }
.th-announce-dialog__body :deep(p) { margin: 0 0 var(--th-space-md); }
.th-announce-dialog__body :deep(ul),
.th-announce-dialog__body :deep(ol) {
	margin: 0 0 var(--th-space-md);
	padding-inline-start: var(--th-space-xl);
}
.th-announce-dialog__body :deep(li) { margin-bottom: var(--th-space-xs); }
.th-announce-dialog__body :deep(a) {
	color: var(--color-primary-element);
	text-decoration: underline;
}
.th-announce-dialog__body :deep(a:focus-visible) {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
	border-radius: var(--border-radius-small);
}
.th-announce-dialog__body :deep(code) {
	background: var(--color-background-hover);
	padding: 1px var(--th-space-xs);
	border-radius: var(--th-radius-control);
	font-size: var(--th-font-meta);
}
.th-announce-dialog__body :deep(pre) {
	background: var(--color-background-hover);
	padding: var(--th-space-md);
	border-radius: var(--th-radius-card);
	overflow-x: auto;
}
.th-announce-dialog__body :deep(blockquote) {
	border-inline-start: 3px solid var(--color-border);
	padding-inline-start: var(--th-space-md);
	margin: 0 0 var(--th-space-md);
	color: var(--color-text-maxcontrast);
}
</style>
