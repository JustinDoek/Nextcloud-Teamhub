<template>
	<section class="th-tfs">
		<!-- Status region. aria-live so a save is announced to a screen reader,
		     not only shown. Matches the Policy tab this row came from. -->
		<div class="th-tfs__status" role="status" aria-live="polite">
			<p v-if="notice" class="th-tfs__notice">{{ notice }}</p>
			<p v-if="error" class="th-tfs__error">
				<AlertCircleOutlineIcon :size="iconInline" aria-hidden="true" />
				{{ error }}
			</p>
		</div>

		<div class="th-tfs__rows">
			<div class="th-tfs__row">
				<div class="th-tfs__row-text">
					<span class="th-tfs__row-name">{{ t('teamhub', 'Switch the teams app frontend on/off') }}</span>
					<InfoHint
						controls="th-tfs-hint"
						:open="hintOpen"
						:label="t('teamhub', 'About the teams app frontend')"
						@toggle="hintOpen = !hintOpen" />
				</div>
				<NcCheckboxRadioSwitch
					class="th-tfs__row-control"
					type="switch"
					:model-value="enabled"
					:disabled="saving || loading || !available"
					:aria-label="t('teamhub', 'Switch the teams app frontend on/off')"
					@update:model-value="save">
					{{ enabled ? t('teamhub', 'Enabled') : t('teamhub', 'Disabled') }}
				</NcCheckboxRadioSwitch>
			</div>

			<!-- TRANSLATORS: the explanation behind the (i) beside the switch that
			     turns Nextcloud's own Teams app frontend off. "Collectives" is an
			     app name. -->
			<p v-if="hintOpen" id="th-tfs-hint" class="th-tfs__hint-panel">
				{{ t('teamhub', 'Off disables Nextcloud’s own Teams app: its navigation entry, its pages and its dashboard widget stop answering, so a team is managed in TeamHub only. Leave it on if you use the Collectives app, which manages a collective’s members through that same frontend.') }}
			</p>
		</div>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import AlertCircleOutlineIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import InfoHint from './InfoHint.vue'
import { ICON_INLINE } from '../../constants/uiTokens.js'

/**
 * Nextcloud's own Teams app frontend, on or off (v4.13.16, DESIGN §2.190).
 *
 * ## Why it is a component of its own as of v4.13.75
 *
 * It was the first section of the **Policy** tab, on the reasoning that it
 * decides whether there is a second, unaudited way into the same teams at all.
 * Justin moved it to **Getting started** on 2026-11-02, which is the better
 * home for the same reason the tab was renamed: deciding that TeamHub is where
 * teams are managed is something an administrator does once, when setting the
 * instance up, not a standing policy they revisit.
 *
 * Extracting it rather than copying the markup is what keeps the switch and
 * its `PUT` in one place. It owns its own fetch, its own save and its own
 * status line, so neither tab has to carry state for it. `AdminSettings.vue`
 * separately *reads* the same endpoint for its setup checklist, which is a
 * different question (is this decided?) asked in a different place.
 *
 * `available: false` means Circles is not installed, so there is no frontend
 * to switch and the control is disabled rather than hidden: it is an
 * administrator's setting whose subject is missing, not one they may not use.
 */
export default {
	name: 'TeamsFrontendSwitch',

	components: { NcCheckboxRadioSwitch, AlertCircleOutlineIcon, InfoHint },

	data() {
		return {
			iconInline: ICON_INLINE,
			hintOpen: false,
			loading: true,
			saving: false,
			enabled: false,
			available: false,
			notice: '',
			error: '',
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,

		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(
					generateUrl('/apps/teamhub/api/v1/admin/policy/teams-frontend'),
				)
				this.enabled = !!data?.enabled
				this.available = data?.available !== false
			} catch (e) {
				this.error = e?.response?.data?.error
					|| t('teamhub', 'Could not read the Teams app frontend setting.')
			} finally {
				this.loading = false
			}
		},

		async save(enabled) {
			this.saving = true
			this.notice = ''
			this.error = ''
			try {
				const { data } = await axios.put(
					generateUrl('/apps/teamhub/api/v1/admin/policy/teams-frontend'),
					{ enabled: !!enabled },
				)
				// Trust the answer rather than the click: the server decides,
				// and an estate where it refuses must not be left showing the
				// switch in a position it never reached.
				this.enabled = data?.enabled !== undefined ? !!data.enabled : !!enabled
				this.notice = t('teamhub', 'Saved')
			} catch (e) {
				this.error = e?.response?.data?.error
					|| t('teamhub', 'Could not change the Teams app frontend.')
				// Put the control back where the server still has it.
				await this.load()
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.th-tfs__status {
	min-height: var(--th-space-lg);
}

.th-tfs__notice {
	margin: 0;
	font-size: var(--th-font-meta);
	color: var(--color-text-maxcontrast);
}

.th-tfs__error {
	display: flex;
	align-items: center;
	gap: var(--th-space-xs);
	margin: 0;
	font-size: var(--th-font-meta);
	color: var(--color-text-error);
}

/* The admin page's compact row: the setting on the start side, the control on
   the end side, the control's own label carrying the state in words.
   `AdminSettings.vue` has the same shape for every module switch, but its
   classes live in that file's *scoped* block and cannot be reached from a
   child component, so the geometry is repeated here in tokens. */
.th-tfs__rows {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm);
}

/* Centred, not top-aligned: the text column is a single label line since the
   explanation moved behind the (i), so the switch lines up with it. */
.th-tfs__row {
	display: flex;
	align-items: center;
	gap: var(--th-space-md);
	flex-wrap: wrap;
}

/* Label and its (i) on one line. The explanation is no longer a paragraph
   under the label (Justin, 2026-11-06): it opens from the (i), the same
   affordance the Team creation and Team import tabs use, so the Getting
   started tab stays a list of decisions rather than a wall of prose. */
.th-tfs__row-text {
	display: flex;
	align-items: center;
	gap: var(--th-space-xs);
	flex: 1 1 320px;
	min-width: 0;
}

.th-tfs__row-name {
	font-size: var(--th-font-body);
	font-weight: var(--th-font-weight-bold);
}

/* What the (i) opens. Same tinted block as `.admin-grid__hint` and
   `.team-import__hint-panel`, so an explanation looks the same wherever it is
   opened. It sits after the row, not inside the label column, so it spans the
   full width under both the label and the switch. */
.th-tfs__hint-panel {
	margin: 0;
	padding: var(--th-space-md);
	border-radius: var(--th-radius-card);
	background: var(--color-background-hover);
	font-size: var(--th-font-meta);
	color: var(--color-text-maxcontrast);
	line-height: var(--th-line-height-body);
}

.th-tfs__row-control {
	flex: 0 0 auto;
}
</style>
