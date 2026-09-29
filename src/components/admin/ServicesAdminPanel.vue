<template>
	<!-- v4.10.45 — Settings → TeamHub → Services (Justin, 2026-09-25): the
	     service catalog's categories, and the two links under it. -->
	<div class="th-svc-admin">
		<div v-if="loading" class="th-svc-admin__loading">
			<NcLoadingIcon :size="ICON_BODY" />
		</div>

		<NcNoteCard v-else-if="loadError" type="error">
			<p>{{ loadError }}</p>
			<NcButton variant="secondary" @click="load">{{ t('teamhub', 'Retry') }}</NcButton>
		</NcNoteCard>

		<template v-else>
			<!-- ── Links ──────────────────────────────────────────────── -->
			<section class="th-svc-admin__section" aria-labelledby="th-svc-admin-links">
				<h3 id="th-svc-admin-links" class="th-svc-admin__h3">{{ t('teamhub', 'Links under the service catalog') }}</h3>
				<p class="th-svc-admin__hint">
					{{ t('teamhub', 'Where people go when the catalog does not have what they need. Each link is shown as a button under the catalog; leave a field empty to show nothing.') }}
				</p>

				<div class="th-svc-admin__fields">
					<NcTextField
						v-model="links.serviceDesk"
						type="url"
						:label="t('teamhub', 'Service desk')"
						placeholder="https://"
						:error="!linkOk(links.serviceDesk)"
						:helper-text="linkOk(links.serviceDesk) ? '' : t('teamhub', 'Enter a web address that starts with https://')"
						:disabled="savingLinks" />
					<NcTextField
						v-model="links.knowledgePortal"
						type="url"
						:label="t('teamhub', 'Knowledge portal')"
						placeholder="https://"
						:error="!linkOk(links.knowledgePortal)"
						:helper-text="linkOk(links.knowledgePortal) ? '' : t('teamhub', 'Enter a web address that starts with https://')"
						:disabled="savingLinks" />
				</div>

				<div class="th-svc-admin__actions">
					<!-- Secondary: the one primary button of the tab is Save categories. -->
					<NcButton
						variant="secondary"
						:disabled="savingLinks || !linksOk"
						@click="saveLinks">
						<template v-if="savingLinks" #icon><NcLoadingIcon :size="ICON_BODY" /></template>
						{{ t('teamhub', 'Save links') }}
					</NcButton>
				</div>
			</section>

			<!-- ── Categories ─────────────────────────────────────────── -->
			<section class="th-svc-admin__section" aria-labelledby="th-svc-admin-categories">
				<h3 id="th-svc-admin-categories" class="th-svc-admin__h3">{{ t('teamhub', 'Categories') }}</h3>
				<p class="th-svc-admin__hint">
					{{ t('teamhub', 'The categories people filter the service catalog on, in this order. A service team files each service it builds under one of them. A built-in category keeps its translations until you rename it. A category with services in it cannot be removed: move the services to another category first.') }}
				</p>

				<ol class="th-svc-admin__list">
					<li v-for="(row, index) in rows" :key="row.uid" class="th-svc-admin__row">
						<div class="th-svc-admin__row-head">
							<NcTextField
								v-model="row.label"
								class="th-svc-admin__name"
								:label="t('teamhub', 'Name')"
								:maxlength="64"
								:disabled="savingCategories" />

							<div class="th-svc-admin__row-actions">
								<NcButton
									variant="tertiary"
									:aria-label="t('teamhub', 'Move {category} up', { category: nameOf(row) })"
									:title="t('teamhub', 'Move {category} up', { category: nameOf(row) })"
									:disabled="savingCategories || index === 0"
									@click="move(index, -1)">
									<template #icon><ArrowUp :size="ICON_BODY" /></template>
								</NcButton>
								<NcButton
									variant="tertiary"
									:aria-label="t('teamhub', 'Move {category} down', { category: nameOf(row) })"
									:title="t('teamhub', 'Move {category} down', { category: nameOf(row) })"
									:disabled="savingCategories || index === rows.length - 1"
									@click="move(index, 1)">
									<template #icon><ArrowDown :size="ICON_BODY" /></template>
								</NcButton>
								<NcButton
									variant="tertiary"
									:aria-label="removeLabel(row)"
									:title="removeLabel(row)"
									:disabled="savingCategories || row.usage > 0 || rows.length === 1"
									@click="remove(index)">
									<template #icon><TrashCanOutline :size="ICON_BODY" /></template>
								</NcButton>
							</div>
						</div>

						<p class="th-svc-admin__usage">
							{{ row.usage > 0
								? n('teamhub', '{n} service is in this category', '{n} services are in this category', row.usage, { n: row.usage })
								: t('teamhub', 'No service is in this category') }}
						</p>

						<ServiceIconPicker
							v-model:value="row.icon"
							:label="t('teamhub', 'Icon of {category}', { category: nameOf(row) })"
							:disabled="savingCategories" />
					</li>
				</ol>

				<div class="th-svc-admin__actions">
					<NcButton
						variant="secondary"
						:disabled="savingCategories || rows.length >= MAX_CATEGORIES"
						@click="add">
						<template #icon><Plus :size="ICON_BODY" /></template>
						{{ t('teamhub', 'Add category') }}
					</NcButton>
					<NcButton
						variant="primary"
						:disabled="savingCategories || !categoriesOk"
						@click="saveCategories">
						<template v-if="savingCategories" #icon><NcLoadingIcon :size="ICON_BODY" /></template>
						{{ t('teamhub', 'Save categories') }}
					</NcButton>
				</div>
			</section>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard, NcTextField } from '@nextcloud/vue'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import ServiceIconPicker from '../services/ServiceIconPicker.vue'
import { ICON_BODY } from '../../constants/uiTokens.js'
import { DEFAULT_SERVICE_ICON } from '../../constants/serviceIcons.js'

const MAX_CATEGORIES = 20

let nextUid = 1

/** `https://` with a host, or empty. The server checks again. */
function linkOk(url) {
	const value = String(url || '').trim()
	if (value === '') {
		return true
	}
	try {
		const parsed = new URL(value)
		return parsed.protocol === 'https:' && parsed.hostname !== '' && !/\s/.test(value)
	} catch (e) {
		return false
	}
}

export default {
	name: 'ServicesAdminPanel',

	components: {
		NcButton, NcLoadingIcon, NcNoteCard, NcTextField,
		ArrowDown, ArrowUp, Plus, TrashCanOutline, ServiceIconPicker,
	},

	data() {
		return {
			ICON_BODY,
			MAX_CATEGORIES,
			loading: true,
			loadError: '',
			links: { serviceDesk: '', knowledgePortal: '' },
			rows: [],
			savingLinks: false,
			savingCategories: false,
		}
	},

	computed: {
		linksOk() {
			return linkOk(this.links.serviceDesk) && linkOk(this.links.knowledgePortal)
		},

		categoriesOk() {
			if (!this.rows.length) {
				return false
			}
			const names = this.rows.map(row => row.label.trim().toLowerCase())
			return names.every(name => name !== '') && new Set(names).size === names.length
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,
		linkOk,

		url(path) {
			return generateUrl('/apps/teamhub/api/v1/admin/services/settings' + path)
		},

		apply(data) {
			this.links = {
				serviceDesk: String(data?.links?.serviceDesk || ''),
				knowledgePortal: String(data?.links?.knowledgePortal || ''),
			}
			this.rows = (data?.categories || []).map(category => ({
				uid: nextUid++,
				key: category.key,
				label: category.label,
				icon: category.icon || DEFAULT_SERVICE_ICON,
				usage: Number(category.usage) || 0,
			}))
		},

		async load() {
			this.loading = true
			this.loadError = ''
			try {
				const { data } = await axios.get(this.url(''))
				this.apply(data)
			} catch (e) {
				this.loadError = t('teamhub', 'Could not load the service catalog settings.')
			} finally {
				this.loading = false
			}
		},

		nameOf(row) {
			return row.label.trim() || t('teamhub', 'New category')
		},

		removeLabel(row) {
			return row.usage > 0
				? n('teamhub', '{category} cannot be removed: {n} service is in it', '{category} cannot be removed: {n} services are in it', row.usage, { category: this.nameOf(row), n: row.usage })
				: t('teamhub', 'Remove {category}', { category: this.nameOf(row) })
		},

		move(index, by) {
			const to = index + by
			if (to < 0 || to >= this.rows.length) {
				return
			}
			const rows = [...this.rows]
			const [row] = rows.splice(index, 1)
			rows.splice(to, 0, row)
			this.rows = rows
		},

		add() {
			this.rows.push({ uid: nextUid++, key: '', label: '', icon: DEFAULT_SERVICE_ICON, usage: 0 })
		},

		/** Off the list until *Save categories*; a category in use cannot go. */
		remove(index) {
			if (this.rows[index]?.usage > 0) {
				return
			}
			this.rows.splice(index, 1)
		},

		async saveLinks() {
			this.savingLinks = true
			try {
				const { data } = await axios.put(this.url('/links'), {
					serviceDesk: this.links.serviceDesk.trim(),
					knowledgePortal: this.links.knowledgePortal.trim(),
				})
				this.links = data.links
				showSuccess(t('teamhub', 'Links saved'))
			} catch (e) {
				showError(e?.response?.data?.error || t('teamhub', 'The links could not be saved.'))
			} finally {
				this.savingLinks = false
			}
		},

		async saveCategories() {
			this.savingCategories = true
			try {
				const { data } = await axios.put(this.url('/categories'), {
					categories: this.rows.map(row => ({ key: row.key || undefined, label: row.label.trim(), icon: row.icon })),
				})
				this.apply(data)
				showSuccess(t('teamhub', 'Categories saved'))
			} catch (e) {
				showError(e?.response?.data?.error || t('teamhub', 'The categories could not be saved.'))
			} finally {
				this.savingCategories = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.th-svc-admin {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xl, 24px);
}

.th-svc-admin__loading {
	display: flex;
	justify-content: center;
	padding: var(--th-space-xl, 24px);
}

.th-svc-admin__section {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-md, 12px);
	max-width: var(--th-page-width, 960px);
}

.th-svc-admin__h3 {
	margin: 0;
	font-size: var(--th-font-heading, 16px);
	font-weight: var(--th-font-weight-semibold, 600);
}

.th-svc-admin__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.th-svc-admin__fields {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-md, 12px);
}

.th-svc-admin__list {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-md, 12px);
	margin: 0;
	padding: 0;
	list-style: none;
}

.th-svc-admin__row {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm, 8px);
	padding: var(--th-space-md, 12px);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-container);
}

.th-svc-admin__row-head {
	display: flex;
	align-items: flex-end;
	gap: var(--th-space-sm, 8px);
}

.th-svc-admin__name {
	flex: 1 1 auto;
}

.th-svc-admin__row-actions {
	display: flex;
	gap: var(--th-space-xs, 4px);
}

.th-svc-admin__usage {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

.th-svc-admin__actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--th-space-sm, 8px);
}
</style>
