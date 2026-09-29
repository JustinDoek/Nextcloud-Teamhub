<template>
	<div class="svc-built-w" :class="{ 'svc-built-w--page': page }">
		<!-- v4.10.41 — on the Services tab the builder takes the whole tab;
		     the list comes back when it closes. -->
		<ServiceBuilderDialog
			v-if="page && editing"
			:key="editing.id || 'new'"
			inline
			:service="editing.service"
			:categories="categories"
			:limits="limits"
			:busy="saving"
			:error="dialogError"
			@close="closeEditor"
			@save="save" />

		<template v-else>
		<!-- v4.10.43 — New service sits with the heading, not under the
		     list (Justin, 2026-09-25). -->
		<header v-if="page" class="svc-built-w__head">
			<div class="svc-built-w__head-text">
				<h2 class="svc-built-w__heading">{{ t('teamhub', 'Services') }}</h2>
				<p class="svc-built-w__hint">
					{{ canEdit
						? t('teamhub', 'The services this team offers in the service catalog. Build, publish and change them here.')
						: t('teamhub', 'The services this team offers in the service catalog.') }}
				</p>
			</div>
			<NcButton
				v-if="canEdit && loaded"
				variant="primary"
				:disabled="atMaxServices"
				:title="atMaxServices ? n('teamhub', 'A team can offer at most %n service.', 'A team can offer at most %n services.', limits.services) : ''"
				@click="openNew">
				<template #icon><Plus :size="ICON_BODY" /></template>
				{{ t('teamhub', 'New service') }}
			</NcButton>
		</header>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
			<template #action>
				<NcButton variant="secondary" @click="load">{{ t('teamhub', 'Try again') }}</NcButton>
			</template>
		</NcNoteCard>

		<div v-else-if="loading && !loaded" class="svc-built-w__loading">
			<NcLoadingIcon :size="ICON_LARGE" />
		</div>

		<template v-else-if="loaded">
			<NcEmptyContent
				v-if="!rows.length"
				:name="t('teamhub', 'No services yet')"
				:description="canEdit
					? t('teamhub', 'Build a service your team answers. Once published, anybody can request it in the service catalog.')
					: t('teamhub', 'The services this team publishes are listed here.')">
				<template #icon><ViewGridOutline :size="ICON_LARGE" /></template>
				<template v-if="canEdit" #action>
					<NcButton variant="primary" @click="openNew">
						<template #icon><Plus :size="ICON_BODY" /></template>
						{{ t('teamhub', 'New service') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<template v-else>
				<ul class="svc-built-w__list">
					<li v-for="service in rows" :key="service.id" class="svc-built-w__row">
						<div class="svc-built-w__main">
							<!-- For an admin the name opens the builder; for anybody
							     else it is a name and nothing to press. -->
							<NcButton
								v-if="canEdit"
								variant="tertiary"
								class="svc-built-w__title"
								:aria-label="t('teamhub', 'Edit {service}', { service: titleOf(service) })"
								@click="openEdit(service)">
								<!-- v4.10.45 — the icon the card has. -->
								<template #icon><ServiceIcon :name="iconOf(service)" :size="ICON_BODY" /></template>
								{{ titleOf(service) }}
							</NcButton>
							<span v-else class="svc-built-w__title svc-built-w__title--plain">
								<ServiceIcon :name="iconOf(service)" :size="ICON_BODY" />
								{{ titleOf(service) }}
							</span>

							<div class="svc-built-w__meta">
								<span>{{ categoryLabel(service) }}</span>
								<span>{{ stepCountLabel(service) }}</span>
								<span v-if="service.version > 0">{{ t('teamhub', 'Version {n}', { n: service.version }) }}</span>
							</div>

							<div v-if="canEdit" class="svc-built-w__chips">
								<NcChip
									no-close
									:variant="stateVariant(service)"
									:text="stateLabel(service)" />
							</div>
						</div>

						<NcActions
							v-if="canEdit"
							:aria-label="t('teamhub', 'Actions for {service}', { service: titleOf(service) })"
							:disabled="busyId === service.id">
							<NcActionButton :close-after-click="true" @click="openEdit(service)">
								<template #icon><PencilOutline :size="ICON_BODY" /></template>
								{{ t('teamhub', 'Edit') }}
							</NcActionButton>
							<NcActionButton
								v-if="canPublish(service)"
								:close-after-click="true"
								@click="publish(service)">
								<template #icon><Publish :size="ICON_BODY" /></template>
								{{ service.version > 0 ? t('teamhub', 'Publish changes') : t('teamhub', 'Publish') }}
							</NcActionButton>
							<NcActionButton
								v-if="service.listed"
								:close-after-click="true"
								@click="unpublish(service)">
								<template #icon><PublishOff :size="ICON_BODY" /></template>
								{{ t('teamhub', 'Unpublish') }}
							</NcActionButton>
							<!-- Only a draft that was never published: requests may
							     reference a published one, so it is unpublished instead. -->
							<NcActionButton
								v-if="!(service.version > 0)"
								:close-after-click="true"
								@click="confirmDelete = service">
								<template #icon><TrashCanOutline :size="ICON_BODY" /></template>
								{{ t('teamhub', 'Delete') }}
							</NcActionButton>
						</NcActions>
					</li>
				</ul>

				<div v-if="canEdit && !page" class="svc-built-w__footer">
					<NcButton
						variant="secondary"
						:disabled="atMaxServices"
						:title="atMaxServices ? n('teamhub', 'A team can offer at most %n service.', 'A team can offer at most %n services.', limits.services) : ''"
						@click="openNew">
						<template #icon><Plus :size="ICON_BODY" /></template>
						{{ t('teamhub', 'New service') }}
					</NcButton>
				</div>
			</template>
		</template>

		</template>

		<NcDialog
			v-if="confirmDelete"
			:name="t('teamhub', 'Delete service?')"
			:open="!!confirmDelete"
			@update:open="open => { if (!open) confirmDelete = null }">
			<div class="svc-built-w__confirm">
				<p>{{ t('teamhub', '"{service}" has never been published. Deleting it cannot be undone.', { service: titleOf(confirmDelete) }) }}</p>
				<div class="svc-built-w__confirm-actions">
					<NcButton variant="tertiary" @click="confirmDelete = null">{{ t('teamhub', 'Cancel') }}</NcButton>
					<NcButton variant="error" :disabled="busyId === confirmDelete.id" @click="remove(confirmDelete)">
						{{ t('teamhub', 'Delete service') }}
					</NcButton>
				</div>
			</div>
		</NcDialog>
	</div>
</template>

<script>
import { mapState } from 'vuex'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import {
	NcActionButton, NcActions, NcButton, NcChip, NcDialog, NcEmptyContent, NcLoadingIcon, NcNoteCard,
} from '@nextcloud/vue'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import PublishOff from 'vue-material-design-icons/PublishOff.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import ViewGridOutline from 'vue-material-design-icons/ViewGridOutline.vue'
import ServiceIcon from './services/ServiceIcon.vue'
import ServiceBuilderDialog from './services/ServiceBuilderDialog.vue'
import {
	SERVICE_STATE,
	serviceState,
	serviceStateLabel,
	serviceStateVariant,
	serviceTitle,
	stepCountLabel,
} from '../constants/serviceBuilder.js'
import { ICON_BODY, ICON_LARGE } from '../constants/uiTokens.js'

/**
 * The *Services* widget on a service team's home (v4.10.34, WorkflowHub
 * phase 8a; `docs/service-builder.md`, `/service-teams`): the services the
 * team built itself.
 *
 * - **A team admin** sees every service with its state — draft, published,
 *   unpublished changes, unpublished — and builds, publishes, unpublishes
 *   and deletes (a never-published draft only) from here. The builder is
 *   `ServiceBuilderDialog`.
 * - **v4.10.41 — the team's *Services* tab** (`page`), where the builder
 *   takes the whole tab (`inline`) and the list returns when it closes
 *   (Justin, 2026-09-25: "use the whole iframe for the builder"). v4.10.44
 *   retired the widget on the home: this renders on the tab only.
 * - **Any other member** sees what the team publishes, and nothing to
 *   press (CLAUDE.md § Permissions: hidden, not disabled).
 *
 * Shown on every Service-template team, desk or not yet
 * (`serviceDeskConfig.isServiceTeam`): publishing the first service is what
 * makes a team a desk. The server re-checks both roles on every call.
 */
export default {
	name: 'ServicesWidget',

	components: {
		NcActionButton, NcActions, NcButton, NcChip, NcDialog, NcEmptyContent, NcLoadingIcon, NcNoteCard,
		PencilOutline, Plus, Publish, PublishOff, TrashCanOutline, ViewGridOutline,
		ServiceBuilderDialog, ServiceIcon,
	},

	props: {
		/**
		 * v4.10.41 — the Services tab: the list and the builder on the whole
		 * tab. Off (the widget on the home), *New service* and *Edit* open the
		 * tab with the builder.
		 */
		page: { type: Boolean, default: false },
	},

	data() {
		return {
			ICON_BODY,
			ICON_LARGE,
			services: [],
			categories: [],
			limits: {},
			canEdit: false,
			loaded: false,
			loading: false,
			error: '',
			/** `{ id, service }` while the builder is open; `service` null for a new one. */
			editing: null,
			saving: false,
			dialogError: '',
			/** The one service a row action is in flight for. */
			busyId: null,
			/** The service the delete confirmation asks about. */
			confirmDelete: null,
		}
	},

	computed: {
		...mapState(['currentTeamId']),

		/** An admin sees every service; anybody else what the Services page lists. */
		rows() {
			return this.canEdit ? this.services : this.services.filter(s => s.listed)
		},

		atMaxServices() {
			return !!this.limits.services && this.services.length >= this.limits.services
		},
	},

	watch: {
		currentTeamId: {
			immediate: true,
			handler(teamId) {
				if (teamId) {
					this.reset()
					this.load()
				}
			},
		},
	},

	methods: {
		t,
		n,
		stepCountLabel,

		reset() {
			this.services = []
			this.loaded = false
			this.editing = null
			this.confirmDelete = null
		},

		async load() {
			const teamId = this.currentTeamId
			if (!teamId) {
				return
			}
			this.loading = true
			this.error = ''
			try {
				const data = await this.$store.dispatch('serviceTeams/builtServices', teamId)
				// A team switched while the request was out must not get the
				// previous team's services.
				if (teamId === this.currentTeamId) {
					this.services = data.services
					this.categories = data.categories
					this.limits = data.limits
					this.canEdit = data.canEdit
					this.loaded = true
				}
			} catch (e) {
				this.error = e?.message || t('teamhub', 'Could not load the services.')
			} finally {
				this.loading = false
			}
		},

		titleOf(service) {
			// An admin works on the draft; everybody else reads what is published.
			return serviceTitle(service, this.canEdit) || t('teamhub', 'Untitled service')
		},

		/** v4.10.45 — the icon the team picked, draft or published as for the title. */
		iconOf(service) {
			return (this.canEdit ? service.draft : service.published || service.draft)?.icon || ''
		},

		categoryLabel(service) {
			const key = (this.canEdit ? service.draft : service.published || service.draft)?.category
			return this.categories.find(c => c.key === key)?.label || ''
		},

		stateLabel(service) {
			return serviceStateLabel(serviceState(service))
		},

		stateVariant(service) {
			return serviceStateVariant(serviceState(service))
		},

		/** A draft with no problems, or a published service whose draft moved on, or an unpublished one. */
		canPublish(service) {
			const state = serviceState(service)
			return state !== SERVICE_STATE.PUBLISHED && !(service.publishProblems || []).length
		},

		/** Put a service the server returned back in the list, in place. */
		upsert(service) {
			if (!service) {
				return
			}
			const index = this.services.findIndex(s => s.id === service.id)
			if (index === -1) {
				this.services.push(service)
			} else {
				this.services.splice(index, 1, service)
			}
		},

		openNew() {
			this.dialogError = ''
			this.editing = { id: null, service: null }
		},

		openEdit(service) {
			this.dialogError = ''
			this.editing = { id: service.id, service }
		},

		closeEditor() {
			if (!this.saving) {
				this.editing = null
			}
		},

		/**
		 * Save the draft, and publish it when asked. The two are separate
		 * calls: a publish the server refuses leaves the draft saved, and the
		 * dialog stays open on the reason.
		 */
		async save({ document, publish }) {
			const teamId = this.currentTeamId
			this.saving = true
			this.dialogError = ''
			try {
				let saved = this.editing?.id
					? await this.$store.dispatch('serviceTeams/saveBuiltService', { teamId, id: this.editing.id, service: document })
					: await this.$store.dispatch('serviceTeams/createBuiltService', { teamId, service: document })
				this.upsert(saved)
				this.editing = { id: saved.id, service: saved }
				if (publish) {
					saved = await this.$store.dispatch('serviceTeams/publishBuiltService', { teamId, id: saved.id })
					this.upsert(saved)
				}
				showSuccess(publish
					? t('teamhub', 'Published: {service}', { service: serviceTitle(saved) })
					: t('teamhub', 'Draft saved: {service}', { service: serviceTitle(saved) }))
				this.editing = null
			} catch (e) {
				this.dialogError = e?.message || t('teamhub', 'The service could not be saved.')
			} finally {
				this.saving = false
			}
		},

		async publish(service) {
			await this.rowAction(service, 'serviceTeams/publishBuiltService',
				t('teamhub', 'Published: {service}', { service: serviceTitle(service) }),
				t('teamhub', 'The service could not be published.'))
		},

		async unpublish(service) {
			await this.rowAction(service, 'serviceTeams/unpublishBuiltService',
				t('teamhub', 'Unpublished: {service}', { service: serviceTitle(service, false) }),
				t('teamhub', 'The service could not be unpublished.'))
		},

		async rowAction(service, action, done, failed) {
			if (this.busyId) {
				return
			}
			this.busyId = service.id
			try {
				this.upsert(await this.$store.dispatch(action, { teamId: this.currentTeamId, id: service.id }))
				showSuccess(done)
			} catch (e) {
				showError(e?.message || failed)
			} finally {
				this.busyId = null
			}
		},

		async remove(service) {
			if (this.busyId) {
				return
			}
			this.busyId = service.id
			try {
				await this.$store.dispatch('serviceTeams/deleteBuiltService', { teamId: this.currentTeamId, id: service.id })
				this.services = this.services.filter(s => s.id !== service.id)
				showSuccess(t('teamhub', 'Deleted: {service}', { service: serviceTitle(service) }))
				this.confirmDelete = null
			} catch (e) {
				showError(e?.message || t('teamhub', 'The service could not be deleted.'))
			} finally {
				this.busyId = null
			}
		},
	},
}
</script>

<style scoped lang="scss">
.svc-built-w {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm, 8px);
	/* v4.10.43 — the other widgets keep their content 16px from the card's
	   edge; this one had none (found in the 4.10.x padding check). */
	padding-inline: var(--th-space-lg, 16px);
}

/* On the Services tab the tab has its own padding. */
.svc-built-w--page {
	padding-inline: 0;
}

.svc-built-w__head {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	justify-content: space-between;
	gap: var(--th-space-md, 12px);
}

.svc-built-w__head-text {
	display: flex;
	flex: 1 1 auto;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
	min-width: 0;
}

.svc-built-w__heading {
	margin: 0;
	font-size: var(--th-font-heading-lg, 20px);
	font-weight: var(--th-font-weight-semibold, 600);
}

.svc-built-w__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.svc-built-w--page {
	gap: var(--th-space-md, 12px);
}

.svc-built-w__loading {
	display: flex;
	justify-content: center;
	padding: var(--th-space-lg, 16px);
}

.svc-built-w__list {
	display: flex;
	flex-direction: column;
	margin: 0;
	padding: 0;
	list-style: none;
}

.svc-built-w__row {
	display: flex;
	align-items: flex-start;
	gap: var(--th-space-sm, 8px);
	padding: var(--th-space-sm, 8px) 0;
	border-block-start: 1px solid var(--color-border);

	&:first-child {
		border-block-start: none;
	}
}

.svc-built-w__main {
	display: flex;
	flex: 1 1 auto;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
	min-width: 0;
}

/* Layout only: NcButton owns everything else. */
.svc-built-w__title {
	max-width: 100%;
}

.svc-built-w__title--plain {
	display: inline-flex;
	align-items: center;
	gap: var(--th-space-sm, 8px);
	padding-inline-start: var(--th-space-sm, 8px);
	font-weight: var(--th-font-weight-semibold, 600);
	overflow-wrap: anywhere;
}

.svc-built-w__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 0 var(--th-space-sm, 8px);
	padding-inline-start: var(--th-space-sm, 8px);
	color: var(--th-widget-meta-color, var(--color-text-maxcontrast));
	font-size: var(--th-font-meta, 13px);
}

.svc-built-w__chips {
	display: flex;
	flex-wrap: wrap;
	gap: var(--th-space-xs, 4px);
	padding-inline-start: var(--th-space-sm, 8px);
}

.svc-built-w__footer {
	display: flex;
	justify-content: flex-start;
}

.svc-built-w__confirm {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-md, 12px);
	padding-block-end: var(--th-space-sm, 8px);

	p {
		margin: 0;
	}
}

.svc-built-w__confirm-actions {
	display: flex;
	justify-content: flex-end;
	gap: var(--th-space-sm, 8px);
}
</style>
