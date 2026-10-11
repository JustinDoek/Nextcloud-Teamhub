<template>
	<!-- v4.11.12 — the desk points a request at a decision of its own, from
	     the request's Messages tab (Justin, 2026-09-30). Two ways in, side by
	     side, because the desk arrives from two places: with the decision's
	     link already copied from the decision page, or with only the words it
	     remembers.

	     Both paths take **approved, published** decisions only, and the
	     server checks it again: a proposal still being argued is not an
	     answer to a request, and the requester reads the conversation this is
	     posted into, so only a public decision opens for them. -->
	<!-- TRANSLATORS for the strings below: "published" is the decision's
	     public flag — readable by everybody signed in on this Nextcloud. -->
	<NcModal
		:name="t('teamhub', 'Link a decision')"
		@close="$emit('close')">
		<div class="svc-linkdec">
			<!-- No heading of its own: `NcModal` renders `name` as the dialog's
			     title, and a second copy inside would print it twice. -->
			<p class="svc-linkdec__hint">
				{{ t('teamhub', 'Post a decision of this service team into the conversation. Only approved, published decisions can be linked — the requester reads this too.') }}
			</p>

			<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>

			<!-- ── What is linked now ──
			     Here rather than on the grid row: a cell of pills with a
			     remove button on each is a crowded place to undo something,
			     and this is the one dialog the desk already opens to change
			     what a request points at. -->
			<section v-if="linked.length" class="svc-linkdec__section">
				<span class="svc-linkdec__label">{{ t('teamhub', 'Linked now') }}</span>
				<ul class="svc-linkdec__linked">
					<li v-for="decision in linked" :key="decision.id" class="svc-linkdec__linked-row">
						<GavelIcon :size="ICON_BODY" class="svc-linkdec__result-icon" aria-hidden="true" />
						<span class="svc-linkdec__linked-text">
							{{ decision.question || t('teamhub', 'A decision you cannot open') }}
						</span>
						<NcButton
							variant="tertiary"
							size="small"
							:disabled="busy"
							:aria-label="t('teamhub', 'Remove this decision from the request')"
							:title="t('teamhub', 'Remove this decision from the request')"
							@click="unlink(decision)">
							<template #icon><CloseIcon :size="ICON_INLINE" /></template>
						</NcButton>
					</li>
				</ul>
			</section>

			<!-- ── Search the team's public decisions ── -->
			<section class="svc-linkdec__section">
				<NcTextField
					v-model="query"
					type="search"
					class="svc-linkdec__field"
					label-outside
					:label="t('teamhub', 'Search the published decisions')"
					:aria-label="t('teamhub', 'Search the published decisions')"
					:placeholder="t('teamhub', 'Search by keyword')" />

				<div v-if="searching" class="svc-linkdec__loading">
					<NcLoadingIcon :size="ICON_BODY" />
				</div>

				<ul v-else-if="results.length" class="svc-linkdec__results" aria-live="polite">
					<!-- A multi-line card row: the carve-out in /ui-standards
					     § NcButton, the same one the decision picker in
					     TeamDecisionsView uses. -->
					<li v-for="decision in results" :key="decision.id">
						<button
							type="button"
							class="svc-linkdec__result"
							:class="{ 'svc-linkdec__result--picked': picked === decision.id }"
							:aria-pressed="picked === decision.id"
							@click="picked = decision.id">
							<GavelIcon :size="ICON_BODY" class="svc-linkdec__result-icon" aria-hidden="true" />
							<span class="svc-linkdec__result-text">
								<span class="svc-linkdec__result-title">{{ decision.question }}</span>
								<span class="svc-linkdec__result-meta">{{ decisionStatusLabel(decision.status) }}</span>
							</span>
						</button>
					</li>
				</ul>

				<p v-else-if="searched" class="svc-linkdec__empty">
					{{ t('teamhub', 'No approved, published decision of this team matches those words.') }}
				</p>

				<!-- Four is the whole list, not the first page (Justin): a
				     picker is for recognising the decision you already have in
				     mind. Said only when four came back, so it reads as a hint
				     to narrow rather than as a limit nobody hit. -->
				<p v-if="!searching && results.length >= MAX_RESULTS" class="svc-linkdec__empty">
					{{ t('teamhub', 'Only the four most recent matches are shown. Add a word to narrow them.') }}
				</p>
			</section>

			<!-- ── Or paste the decision's link ── -->
			<section class="svc-linkdec__section">
				<NcTextField
					v-model="url"
					type="url"
					class="svc-linkdec__field"
					label-outside
					:label="t('teamhub', 'Decision link')"
					:aria-label="t('teamhub', 'Decision link')"
					:placeholder="t('teamhub', 'Paste the link of a decision')"
					:error="!!url.trim() && !urlDecisionId"
					:helper-text="url.trim() && !urlDecisionId
						? t('teamhub', 'That is not a link to a decision in TeamHub.')
						: ''"
					@keydown.enter="submit" />
			</section>

			<div class="svc-linkdec__actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('teamhub', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!chosen || busy" @click="submit">
					{{ busy ? t('teamhub', 'Linking') : t('teamhub', 'Link decision') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcModal, NcNoteCard, NcTextField } from '@nextcloud/vue'
import GavelIcon from 'vue-material-design-icons/Gavel.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'

import { linkRequestDecision, loadRequestDecisions, searchPublicDecisions, unlinkRequestDecision } from '../../api/serviceTeams.js'
import { decisionFromUrl } from '../../lib/internalLinks.js'
import { decisionStatusLabel } from '../../constants/decisions.js'
import { ICON_BODY, ICON_INLINE } from '../../constants/uiTokens.js'

/**
 * The whole search result, not a page of it (Justin, 2026-09-30). The server
 * caps it at the same number; this is what the hint under the list counts.
 */
const MAX_RESULTS = 4

export default {
	name: 'LinkDecisionDialog',

	components: {
		NcButton, NcLoadingIcon, NcModal, NcNoteCard, NcTextField, GavelIcon, CloseIcon,
	},

	props: {
		/** The request (workflow instance) the decision is linked to. */
		requestId: {
			type: Number,
			required: true,
		},
		/** The service team handling it — whose public decisions are searched. */
		serviceTeamId: {
			type: String,
			required: true,
		},
	},

	emits: ['close', 'linked'],

	data() {
		return {
			ICON_BODY,
			ICON_INLINE,
			MAX_RESULTS,
			/**
			 * What the request points at now. Read from the server when the
			 * dialog opens rather than taken as a prop: the dialog is opened
			 * from the conversation, which knows the request's history but not
			 * which of its links are still live.
			 */
			linked: [],
			query: '',
			url: '',
			results: [],
			picked: null,
			searching: false,
			searched: false,
			busy: false,
			error: '',
			searchTimer: null,
		}
	},

	computed: {
		/** The decision id in the pasted link, or null. */
		urlDecisionId() {
			const target = decisionFromUrl(this.url.trim())
			return target ? target.decisionId : null
		},

		/**
		 * A pasted link wins over a search hit: somebody who pastes one after
		 * picking a result has changed their mind, and the box they typed in
		 * last is the one they mean.
		 */
		chosen() {
			return this.urlDecisionId || this.picked || null
		},
	},

	watch: {
		query() {
			clearTimeout(this.searchTimer)
			this.searchTimer = setTimeout(this.search, 300)
		},
	},

	mounted() {
		// The team's most recent published decisions, before a word is typed:
		// a desk with a handful of them never has to guess a keyword.
		this.search()
		this.loadLinked()
	},

	beforeUnmount() {
		clearTimeout(this.searchTimer)
	},

	methods: {
		t,
		decisionStatusLabel,

		/** Never fatal: a dialog that cannot list what is linked can still link. */
		async loadLinked() {
			try {
				this.linked = await loadRequestDecisions(this.requestId)
			} catch (e) {
				this.linked = []
			}
		},

		async search() {
			this.searching = true
			this.error = ''
			try {
				this.results = await searchPublicDecisions(this.serviceTeamId, this.query.trim())
				if (!this.results.some(d => d.id === this.picked)) {
					this.picked = null
				}
			} catch (e) {
				this.error = e?.response?.data?.error || t('teamhub', 'The decisions could not be searched.')
				this.results = []
			} finally {
				this.searching = false
				this.searched = true
			}
		},

		async submit() {
			const decisionId = this.chosen
			if (!decisionId || this.busy) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				const decisions = await linkRequestDecision(this.requestId, decisionId)
				this.linked = decisions
				this.$emit('linked', decisions)
				this.$emit('close')
			} catch (e) {
				this.error = e?.response?.data?.error || t('teamhub', 'The decision could not be linked.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Take one decision off the request. The decision itself is untouched
		 * — it stays in the team's Decisions module with everything on it.
		 * The dialog stays open: removing the wrong one and linking the right
		 * one is one visit.
		 */
		async unlink(decision) {
			if (this.busy) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				const decisions = await unlinkRequestDecision(this.requestId, decision.id)
				this.linked = decisions
				this.$emit('linked', decisions)
			} catch (e) {
				this.error = e?.response?.data?.error || t('teamhub', 'The decision could not be removed.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.svc-linkdec {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-md, 12px);
	padding: var(--th-space-xl, 24px);
}

.svc-linkdec__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

.svc-linkdec__section {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm, 8px);
}

.svc-linkdec__label {
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
	font-weight: 600;
}

.svc-linkdec__linked {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
}

.svc-linkdec__linked-row {
	display: flex;
	align-items: center;
	gap: var(--th-space-sm, 8px);
	padding: var(--th-space-xs, 4px) var(--th-space-sm, 8px);
	border-radius: var(--border-radius-element);
	background: var(--color-background-hover);
}

.svc-linkdec__linked-text {
	flex: 1 1 auto;
	min-inline-size: 0;
	overflow: hidden;
	white-space: nowrap;
	text-overflow: ellipsis;
	font-size: var(--th-font-meta, 13px);
}

.svc-linkdec__loading {
	display: flex;
	justify-content: center;
	padding: var(--th-space-md, 12px);
}

.svc-linkdec__results {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
	max-block-size: 240px;
	overflow-y: auto;
}

.svc-linkdec__result {
	display: flex;
	align-items: flex-start;
	gap: var(--th-space-sm, 8px);
	inline-size: 100%;
	padding: var(--th-space-sm, 8px);
	border: 2px solid transparent;
	border-radius: var(--border-radius-element);
	background: var(--color-background-hover);
	color: var(--color-main-text);
	text-align: start;
	cursor: pointer;

	&:hover {
		background: var(--color-background-dark);
	}

	/* Split from :hover on purpose — grouped, the outline would be silenced
	   for the keyboard (/ui-standards § Focus visibility). */
	&:focus-visible {
		background: var(--color-background-dark);
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
	}

	/* Selection rests on the fill *and* the border, never on colour alone. */
	&--picked {
		border-color: var(--color-primary-element);
		background: var(--color-primary-element-light);
	}
}

.svc-linkdec__result-icon {
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
}

.svc-linkdec__result-text {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
	min-inline-size: 0;
}

.svc-linkdec__result-title {
	font-size: var(--th-font-body, 15px);
}

.svc-linkdec__result-meta {
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

.svc-linkdec__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

.svc-linkdec__actions {
	display: flex;
	justify-content: flex-end;
	gap: var(--th-space-sm, 8px);
}
</style>
