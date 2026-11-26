<template>
	<NcSettingsSection
		v-if="apps.length > 0 || unreadable"
		:name="t('teamhub', 'Folders managed by other apps')"
		:description="t('teamhub', 'These apps give teams access to Team folders of their own. Team admins see such a folder as managed by the app and are not asked to review it. Switch an app off to send its folders back to the team admins\' review.')">
		<div class="th-managed-admin__status" role="status" aria-live="polite">
			<p v-if="notice" class="th-managed-admin__notice">
				{{ notice }}
			</p>
		</div>

		<NcNoteCard v-if="unreadable" type="error">
			{{ t('teamhub', 'The saved list of switched off apps cannot be read, so every app is switched off. Changing any switch saves a new list, with every other app switched on.') }}
		</NcNoteCard>

		<ul class="th-managed-admin__list">
			<li
				v-for="app in apps"
				:key="app.id"
				class="th-managed-admin__row">
				<div class="th-managed-admin__row-head">
					<span class="th-managed-admin__row-title">{{ app.name }}</span>
					<NcChip noClose variant="tertiary" :text="app.id" />
					<NcChip
						v-if="!app.registered"
						noClose
						variant="warning"
						:text="t('teamhub', 'Not registered')" />
				</div>
				<NcCheckboxRadioSwitch
					type="switch"
					:modelValue="app.enabled"
					:disabled="busyId === app.id"
					:aria-label="app.enabled
						? t('teamhub', 'Let team admins review the folders of {name}', { name: app.name })
						: t('teamhub', 'Let {name} manage its own folders', { name: app.name })"
					@update:modelValue="toggle(app, $event)">
					{{ app.enabled ? t('teamhub', 'Managed by the app') : t('teamhub', 'Reviewed by team admins') }}
				</NcCheckboxRadioSwitch>
			</li>
		</ul>
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'

/**
 * Administration → TeamHub → Integrations → Folders managed by other apps
 * (v4.20.48, DESIGN §2.283).
 *
 * One row per app that claims Team folders through
 * `ITeamHubManagedFolderRegistry`, each with a switch. Drawn only when there
 * is such an app, so most instances never see the section; that is also why a
 * failed load shows nothing here instead of an error about a feature nobody
 * uses. The server is the boundary (`ManagedFolderAdminService`, full
 * Nextcloud administrator).
 */
export default {
	name: 'ManagedFolderAppsPanel',

	components: {
		NcCheckboxRadioSwitch,
		NcChip,
		NcNoteCard,
		NcSettingsSection,
	},

	data() {
		return {
			notice: null,
			apps: [],
			unreadable: false,
			busyId: null,
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		async load() {
			try {
				const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/admin/managed-folders/apps'))
				this.apply(data)
			} catch {
				this.apps = []
			}
		},

		apply(data) {
			this.apps = Array.isArray(data?.apps) ? data.apps : []
			this.unreadable = data?.unreadable === true
		},

		async toggle(app, enabled) {
			this.busyId = app.id
			this.notice = null
			try {
				const { data } = await axios.put(
					generateUrl('/apps/teamhub/api/v1/admin/managed-folders/apps/{claimingApp}', { claimingApp: app.id }),
					{ enabled },
				)
				this.apply(data)
				this.notice = enabled
					? t('teamhub', '{name} manages its own folders again.', { name: app.name })
					: t('teamhub', 'Team admins review the folders of {name}. The folders appear in the review within an hour.', { name: app.name })
			} catch (e) {
				showError(e?.response?.data?.error || t('teamhub', 'Could not save the setting.'))
			} finally {
				this.busyId = null
			}
		},
	},
}
</script>

<style scoped>
.th-managed-admin__status {
	min-height: var(--th-space-xl);
}

.th-managed-admin__notice {
	margin: 0 0 var(--th-space-sm);
	color: var(--color-text-success);
}

.th-managed-admin__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm);
}

.th-managed-admin__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: var(--th-space-lg);
	padding: var(--th-space-md);
	border: 1px solid var(--color-border);
	border-radius: var(--th-radius-card);
	background: var(--color-main-background);
}

.th-managed-admin__row-head {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: var(--th-space-sm);
	min-width: 0;
}

.th-managed-admin__row-title {
	font-size: var(--th-font-heading);
	font-weight: var(--th-font-weight-semibold);
	overflow-wrap: anywhere;
}
</style>
