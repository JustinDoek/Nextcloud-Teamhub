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
			<!-- v4.13.58 — this tab autosaves, so it gets the same status
			     region as the Team creation and Integrations tabs: transient
			     only, aria-live so a screen reader hears the outcome of a
			     change nobody pressed a button for, and the container holds its
			     height when empty so nothing shifts as saving/saved appears. -->
			<div class="th-svc-admin__autosave" role="status" aria-live="polite">
				<template v-if="saving">
					<NcLoadingIcon :size="ICON_BODY" />
					<span>{{ t('teamhub', 'Saving…') }}</span>
				</template>
				<span v-else-if="saveError" class="th-svc-admin__autosave-err">{{ saveError }}</span>
				<span v-else-if="saved" class="th-svc-admin__autosave-ok">{{ t('teamhub', 'Settings saved') }}</span>
			</div>

			<!-- ── Links ──────────────────────────────────────────────── -->
			<section class="th-svc-admin__section" aria-labelledby="th-svc-admin-links">
				<h2 id="th-svc-admin-links" class="th-svc-admin__title">{{ t('teamhub', 'Links under the service catalog') }}</h2>
				<p class="th-svc-admin__hint">
					{{ t('teamhub', 'Where people go when the catalog does not have what they need. Each link is shown as a button under the catalog; leave a field empty to show nothing.') }}
				</p>

				<!-- v4.13.58 — no Save links button (Justin, 2026-10-31). A
				     changed address writes itself, debounced, and only once it
				     is a valid https:// address or empty: saving per keystroke
				     would store "h", "ht", "htt" and have the server reject
				     every one of them on the way to a good value. -->
				<div class="th-svc-admin__fields">
					<NcTextField
						v-model="links.serviceDesk"
						type="url"
						:label="t('teamhub', 'Service desk')"
						placeholder="https://"
						:error="!linkOk(links.serviceDesk)"
						:helper-text="linkOk(links.serviceDesk) ? '' : t('teamhub', 'Enter a web address that starts with https://')"
						@update:model-value="onLinksInput" />
					<NcTextField
						v-model="links.knowledgePortal"
						type="url"
						:label="t('teamhub', 'Knowledge portal')"
						placeholder="https://"
						:error="!linkOk(links.knowledgePortal)"
						:helper-text="linkOk(links.knowledgePortal) ? '' : t('teamhub', 'Enter a web address that starts with https://')"
						@update:model-value="onLinksInput" />
				</div>
			</section>

			<!-- ── Categories ─────────────────────────────────────────── -->
			<section class="th-svc-admin__section" aria-labelledby="th-svc-admin-categories">
				<h2 id="th-svc-admin-categories" class="th-svc-admin__title">{{ t('teamhub', 'Categories') }}</h2>
				<p class="th-svc-admin__hint">
					{{ t('teamhub', 'The categories people filter the service catalog on, in this order. A service team files each service it builds under one of them. A built-in category keeps its translations until you rename it. A category with services in it cannot be removed: move the services to another category first.') }}
				</p>

				<!-- v4.13.58 — two cards abreast on a desktop pane (Justin,
				     2026-10-31). A category card is a name, a count and an icon
				     picker; at one per row a seeded instance was five screens
				     of mostly empty width. The grid falls back to one column on
				     a narrow pane, where the icon picker needs the room. -->
				<ol class="th-svc-admin__list">
					<li v-for="(row, index) in rows" :key="row.uid" class="th-svc-admin__row">
						<div class="th-svc-admin__row-head">
							<NcTextField
								v-model="row.label"
								class="th-svc-admin__name"
								:label="t('teamhub', 'Name')"
								:maxlength="64"
								@update:model-value="onCategoryInput" />

							<div class="th-svc-admin__row-actions">
								<NcButton
									variant="tertiary"
									:aria-label="t('teamhub', 'Move {category} up', { category: nameOf(row) })"
									:title="t('teamhub', 'Move {category} up', { category: nameOf(row) })"
									:disabled="index === 0"
									@click="move(index, -1)">
									<template #icon><ArrowUp :size="ICON_BODY" /></template>
								</NcButton>
								<NcButton
									variant="tertiary"
									:aria-label="t('teamhub', 'Move {category} down', { category: nameOf(row) })"
									:title="t('teamhub', 'Move {category} down', { category: nameOf(row) })"
									:disabled="index === rows.length - 1"
									@click="move(index, 1)">
									<template #icon><ArrowDown :size="ICON_BODY" /></template>
								</NcButton>
								<NcButton
									variant="tertiary"
									:aria-label="removeLabel(row)"
									:title="removeLabel(row)"
									:disabled="row.usage > 0 || rows.length === 1"
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
							@update:value="onCategoryChange" />
					</li>
				</ol>

				<!-- No Save categories button either: a rename debounces, and a
				     move, a removal or a new name writes straight away. A row
				     with no name, or a name another row already has, is not a
				     saveable list and is held back until it is one. -->
				<div class="th-svc-admin__actions">
					<NcButton
						variant="secondary"
						:disabled="rows.length >= MAX_CATEGORIES"
						@click="add">
						<template #icon><Plus :size="ICON_BODY" /></template>
						{{ t('teamhub', 'Add category') }}
					</NcButton>
					<span v-if="!categoriesOk" class="th-svc-admin__held">
						{{ t('teamhub', 'Give every category a name of its own to save the list.') }}
					</span>
				</div>
			</section>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
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
			// v4.13.58 — one autosave state for the whole tab, like the Team
			// creation and Integrations tabs. Two independent "saving" flags
			// would need two status regions, and a tab that saves itself has
			// one place where it says so.
			saving: false,
			saved: false,
			saveError: '',
			_linksTimer: null,
			_categoriesTimer: null,
			_savedTimer: null,
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

	beforeUnmount() {
		// A pending debounce must not fire into a dead component: the tab is
		// mounted with `v-if` and an administrator who types and immediately
		// switches tabs would otherwise hit `this.saving` on an unmounted one.
		clearTimeout(this._linksTimer)
		clearTimeout(this._categoriesTimer)
		clearTimeout(this._savedTimer)
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
			this.onCategoryChange()
		},

		/**
		 * A new, nameless row. Nothing is written yet: `categoriesOk` is false
		 * while it has no name, so the list saves itself the moment it is named.
		 */
		add() {
			this.rows.push({ uid: nextUid++, key: '', label: '', icon: DEFAULT_SERVICE_ICON, usage: 0 })
		},

		/** A category in use cannot go. Removal writes straight away. */
		remove(index) {
			if (this.rows[index]?.usage > 0) {
				return
			}
			this.rows.splice(index, 1)
			this.onCategoryChange()
		},

		// ── Autosave (v4.13.58) ──────────────────────────────────────
		//
		// Both sections write themselves. The pattern is AdminSettings' own:
		// a debounce on typing, an immediate write on a discrete action, and
		// one transient status region for the outcome. No toasts — a toast per
		// keystroke-settled save is noise on a page that saves continuously,
		// and the region already announces it to a screen reader.

		/** Flash "Settings saved" and clear it again. */
		markSaved() {
			this.saved = true
			clearTimeout(this._savedTimer)
			this._savedTimer = setTimeout(() => { this.saved = false }, 2000)
		},

		onLinksInput() {
			clearTimeout(this._linksTimer)
			this._linksTimer = setTimeout(() => this.saveLinks(), 800)
		},

		async saveLinks() {
			// Held back rather than rejected: the field is already showing its
			// own error, and a 400 toast on top of it says the same thing twice.
			if (!this.linksOk) {
				return
			}
			this.saving = true
			this.saveError = ''
			try {
				const { data } = await axios.put(this.url('/links'), {
					serviceDesk: this.links.serviceDesk.trim(),
					knowledgePortal: this.links.knowledgePortal.trim(),
				})
				// The server normalises, so take its answer — but only for a
				// field the administrator is not still in the middle of. Both
				// are settled by the time the debounce fires, so assigning the
				// pair is safe here and keeps the two in step.
				this.links = {
					serviceDesk: String(data?.links?.serviceDesk || ''),
					knowledgePortal: String(data?.links?.knowledgePortal || ''),
				}
				this.markSaved()
			} catch (e) {
				this.saveError = e?.response?.data?.error || t('teamhub', 'The links could not be saved.')
			} finally {
				this.saving = false
			}
		},

		/** A rename: debounced, because every keystroke lands here. */
		onCategoryInput() {
			clearTimeout(this._categoriesTimer)
			this._categoriesTimer = setTimeout(() => this.saveCategories(), 800)
		},

		/** A move, a removal or an icon: one event per decision, so write now. */
		onCategoryChange() {
			clearTimeout(this._categoriesTimer)
			this.saveCategories()
		},

		async saveCategories() {
			if (!this.categoriesOk) {
				return
			}
			this.saving = true
			this.saveError = ''
			try {
				const { data } = await axios.put(this.url('/categories'), {
					categories: this.rows.map(row => ({ key: row.key || undefined, label: row.label.trim(), icon: row.icon })),
				})
				this.reconcile(data)
				this.markSaved()
			} catch (e) {
				this.saveError = e?.response?.data?.error || t('teamhub', 'The categories could not be saved.')
			} finally {
				this.saving = false
			}
		},

		/**
		 * Take back from the server only what it alone knows (v4.13.58).
		 *
		 * `apply()` cannot be used after an autosave: it rebuilds every row
		 * with a fresh `uid`, the `:key` changes, and Vue remounts the name
		 * field the administrator is typing in — losing the caret mid-word. So
		 * the rows stay as they are and pick up the two fields the client
		 * cannot compute: the `key` a new category was given, and the `usage`
		 * count. Order and length are the ones that went up, so index is a
		 * sound pairing; if the server disagrees about either, fall back to a
		 * full rebuild rather than pair the wrong rows together.
		 *
		 * @param {object} data The PUT response.
		 */
		reconcile(data) {
			const categories = data?.categories || []
			if (categories.length !== this.rows.length) {
				this.apply(data)
				return
			}
			categories.forEach((category, index) => {
				this.rows[index].key = category.key
				this.rows[index].usage = Number(category.usage) || 0
			})
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

/* v4.13.58 — a section heading on this tab now renders at the height of a
   section heading on every other tab (Justin, 2026-10-31: "the section headers
   seem smaller as on the other tabs"). The tabs built from `NcSettingsSection`
   get its `h2` at 20px bold for free; this tab builds its sections by hand and
   was two steps down the scale. `--th-font-heading-lg` *is* 20px in
   widget-tokens ("view / section heading (H2)"), so this is the token scale
   rather than a number chosen to match. The element went h3 → h2 with it:
   these are the tab's top-level sections, nothing sits above them. */
.th-svc-admin__title {
	margin: 0;
	font-size: var(--th-font-heading-lg, 20px);
	font-weight: var(--th-font-weight-bold, 700);
}

/* The tab's autosave status region. Holds its height when empty so the
   sections below do not shift as saving/saved appears and clears. */
.th-svc-admin__autosave {
	display: flex;
	align-items: center;
	gap: var(--th-space-sm, 8px);
	min-height: var(--default-clickable-area);
	font-size: var(--th-font-meta);
	color: var(--color-text-maxcontrast);
}

.th-svc-admin__autosave-ok {
	color: var(--color-text-success);
}

.th-svc-admin__autosave-err {
	color: var(--color-text-error);
}

/* Why the list is held back, beside the button that unblocks it. */
.th-svc-admin__held {
	align-self: center;
	font-size: var(--th-font-meta);
	color: var(--color-text-maxcontrast);
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

/* v4.13.58 — two cards abreast on a desktop pane. `auto-fill` with a minimum
   rather than a hard `repeat(2, …)`: the pane is narrower than the viewport
   whenever the settings navigation is open, and a fixed two-column grid would
   squeeze the icon picker instead of dropping to one column. `minmax(0, 1fr)`
   on the track so a long category name cannot push the grid wider than it. */
.th-svc-admin__list {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(min(320px, 100%), 1fr));
	gap: var(--th-space-md, 12px);
	margin: 0;
	padding: 0;
	list-style: none;
}

.th-svc-admin__row {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm, 8px);
	/* A grid item's automatic minimum size is its content, so without this a
	   long name or a wide icon row widens the whole column (v4.13.58). */
	min-width: 0;
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
	min-width: 0;
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
