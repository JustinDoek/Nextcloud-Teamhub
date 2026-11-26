<template>
	<div class="th-shared-admin">

		<div v-if="loading" class="th-shared-admin__loading">
			<NcLoadingIcon :size="ICON_BODY" />
			<span>{{ t('teamhub', 'Loading') }}</span>
		</div>

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
			<template #action>
				<NcButton variant="secondary" @click="load">
					{{ t('teamhub', 'Try again') }}
				</NcButton>
			</template>
		</NcNoteCard>

		<template v-else>
			<div class="th-shared-admin__status" role="status" aria-live="polite">
				<p v-if="notice" class="th-shared-admin__notice">{{ notice }}</p>
			</div>

			<NcNoteCard v-if="unreadable" type="error">
				{{ t('teamhub', 'The saved list of switched off apps cannot be read, so every app is switched off. Changing any switch saves a new list, with every other app switched on.') }}
			</NcNoteCard>

			<NcEmptyContent
				v-if="providers.length === 0"
				:name="t('teamhub', 'No app shares team resources')"
				:description="t('teamhub', 'An installed app appears here when it lists resources for teams through Nextcloud\'s Teams API.')">
				<template #icon>
					<ShareVariantOutline :size="ICON_HERO" />
				</template>
			</NcEmptyContent>

			<ul v-else class="th-shared-admin__list">
				<li
					v-for="provider in providers"
					:key="provider.id"
					class="th-shared-admin__row">
					<div class="th-shared-admin__row-head">
						<span class="th-shared-admin__row-title">{{ provider.name }}</span>
						<NcChip no-close variant="tertiary" :text="provider.id" />
						<NcChip
							v-if="!provider.registered"
							no-close
							variant="warning"
							:text="t('teamhub', 'Not registered')" />
					</div>
					<NcCheckboxRadioSwitch
						type="switch"
						:model-value="provider.enabled"
						:disabled="busyId === provider.id"
						:aria-label="provider.enabled
							? t('teamhub', 'Stop showing {name} in TeamHub', { name: provider.name })
							: t('teamhub', 'Show {name} in TeamHub', { name: provider.name })"
						@update:model-value="toggle(provider, $event)">
						{{ provider.enabled ? t('teamhub', 'Shown in TeamHub') : t('teamhub', 'Not shown in TeamHub') }}
					</NcCheckboxRadioSwitch>
				</li>
			</ul>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

import ShareVariantOutline from 'vue-material-design-icons/ShareVariantOutline.vue'

import { ICON_BODY, ICON_HERO } from '../../constants/uiTokens.js'

/**
 * Administration → TeamHub → Integrations → Shared with team (v4.20.41).
 *
 * The per-provider switch from DESIGN §2.281, on screen: every app that lists
 * team resources through Nextcloud's Teams API, each with a switch that stops
 * its resources appearing in TeamHub's Shared with team card. Default on.
 *
 * The server is the boundary (`SharedResourceProviderAdminService`, full
 * Nextcloud administrator); this page only renders what it answers and sends
 * one PUT per switch. Rows the server marks `registered: false` are switched
 * off ids whose app no longer registers a provider: they stay listed so a
 * switch can be undone after the app comes back. `unreadable` is the
 * fail-closed state, said in words because every switch then reads off.
 */
export default {
	name: 'SharedResourceProvidersPanel',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcChip,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		ShareVariantOutline,
	},

	data() {
		return {
			ICON_BODY,
			ICON_HERO,
			loading: true,
			error: null,
			notice: null,
			providers: [],
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
			this.loading = true
			this.error = null
			try {
				const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/admin/shared-resources/providers'))
				this.apply(data)
			} catch (e) {
				this.error = this.errorText(e, t('teamhub', 'Could not load the apps that share team resources.'))
			} finally {
				this.loading = false
			}
		},

		apply(data) {
			this.providers = Array.isArray(data?.providers) ? data.providers : []
			this.unreadable = data?.unreadable === true
		},

		async toggle(provider, enabled) {
			this.busyId = provider.id
			this.notice = null
			try {
				const { data } = await axios.put(
					generateUrl('/apps/teamhub/api/v1/admin/shared-resources/providers/{providerId}', { providerId: provider.id }),
					{ enabled },
				)
				this.apply(data)
				this.notice = enabled
					? t('teamhub', '{name} is shown in Shared with team again.', { name: provider.name })
					: t('teamhub', '{name} no longer appears in Shared with team.', { name: provider.name })
			} catch (e) {
				showError(this.errorText(e, t('teamhub', 'Could not save the setting.')))
			} finally {
				this.busyId = null
			}
		},

		errorText(e, fallback) {
			return e?.response?.data?.error || fallback
		},
	},
}
</script>

<style scoped>
.th-shared-admin__loading {
	display: flex;
	align-items: center;
	gap: var(--th-space-sm);
	padding: var(--th-space-md) 0;
	color: var(--color-text-maxcontrast);
}

.th-shared-admin__status {
	min-height: var(--th-space-xl);
}

.th-shared-admin__notice {
	margin: 0 0 var(--th-space-sm);
	color: var(--color-text-success);
}

.th-shared-admin__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm);
}

.th-shared-admin__row {
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

.th-shared-admin__row-head {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: var(--th-space-sm);
	min-width: 0;
}

.th-shared-admin__row-title {
	font-size: var(--th-font-heading);
	font-weight: var(--th-font-weight-semibold);
	overflow-wrap: anywhere;
}
</style>
