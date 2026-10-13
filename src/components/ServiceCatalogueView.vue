<template>
	<div class="svc-cat">
		<!-- v4.11.12 — a request opened from the catalog takes the whole page,
		     with *Back to catalog* (Justin, 2026-09-30). The requester's own
		     requests live in the aside; opening one there is not a glance at a
		     card, it is reading where their request stands and taking the step
		     that is theirs, so the catalogue steps aside for it.
		     `MyRequestsWidget` draws the request itself and says so here. -->
		<!-- ── Header ───────────────────────────────────────────────────── -->
		<header v-if="!requestOpen" class="svc-cat__head">
			<div>
				<!-- v4.10.44 — "Service catalog" (was "Services"; Justin, 2026-09-25):
				     a team's own Services tab is where a team builds them. -->
				<h2 class="svc-cat__title">{{ t('teamhub', 'Service catalog') }}</h2>
				<p class="svc-cat__hint">
					{{ t('teamhub', 'Everything the service teams on this server offer. Pick what you need; the team that offers it answers and you confirm when it is done.') }}
				</p>
			</div>

			<NcButton
				variant="tertiary"
				:aria-label="t('teamhub', 'Refresh the services')"
				:title="t('teamhub', 'Refresh the services')"
				:disabled="loading"
				@click="reload">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="ICON_BODY" />
					<Refresh v-else :size="ICON_BODY" aria-hidden="true" />
				</template>
			</NcButton>
		</header>

		<!-- v4.11.7 — the catalogue on the left, the requester's own requests
		     on the right (Justin, 2026-09-30). The aside renders whatever the
		     catalogue does: somebody with no service left to request still has
		     requests to follow. -->
		<div class="svc-cat__layout" :class="{ 'svc-cat__layout--request': requestOpen }">
		<!-- v4.11.13 — the pills sit in a row of their own above the two
		     columns, so *My requests* starts level with the first service card
		     instead of with the filters (Justin, 2026-10-01). A grid row rather
		     than an offset on the aside: the pills wrap at narrow widths and the
		     row grows with them, which a margin would not. -->
		<template v-if="!requestOpen">
		<div v-if="entries.length" class="svc-cat__pills">
			<!-- ── Categories ───────────────────────────────────────────
			     A tile per category that has something in it, counted for
			     this viewer. A category nothing is in is not shown: a tile
			     reading "Apps and tools - 0" advertises what this server
			     does not have. -->
			<!-- v4.11.8 — the pills of What's new: one shape for a filter that
			     carries an icon, a name and a number (Justin, 2026-09-30). -->
			<div class="svc-cat__filters" role="group" :aria-label="t('teamhub', 'Filter the services by category')">
				<TogglePill
					:label="t('teamhub', 'All services')"
					:count="entries.length"
					:pressed="activeCategory === ALL_CATEGORIES"
					@click="activeCategory = ALL_CATEGORIES" />

				<TogglePill
					v-for="tile in tiles"
					:key="tile.id"
					:label="tile.label"
					:count="tile.count"
					:pressed="activeCategory === tile.id"
					@click="activeCategory = tile.id">
					<template #icon>
						<ServiceIcon :name="tile.icon" :size="ICON_INLINE" />
					</template>
				</TogglePill>
			</div>

			<!-- ── The two readings of one list ─────────────────────────
			     A–Z is the requester who knows what they need; by team is
			     the requester who knows which desk they deal with. One
			     payload, a client-side toggle. -->
			<!-- The same pill: this row sits directly under the categories and
			     is the same kind of control, so it reads as one family. It
			     carries no number — the pill's is optional. -->
			<div class="svc-cat__views" role="group" :aria-label="t('teamhub', 'How the services are ordered')">
				<TogglePill
					:label="t('teamhub', 'A to Z')"
					:pressed="view === CATALOGUE_VIEW.ALPHABETICAL"
					@click="view = CATALOGUE_VIEW.ALPHABETICAL">
					<template #icon><SortAlphabeticalVariant :size="ICON_INLINE" /></template>
				</TogglePill>
				<TogglePill
					:label="t('teamhub', 'By team')"
					:pressed="view === CATALOGUE_VIEW.BY_TEAM"
					@click="view = CATALOGUE_VIEW.BY_TEAM">
					<template #icon><AccountGroupOutline :size="ICON_INLINE" /></template>
				</TogglePill>
			</div>
		</div>

		<div class="svc-cat__main">

		<!-- Nothing to start is not an error: an instance without a service
		     team, or one whose licence has lapsed, has no catalogue and the
		     navigation entry is absent. This page is still reachable by a
		     deep link, so it says what is missing rather than rendering an
		     empty grid. -->
		<NcEmptyContent
			v-if="!entries.length && !loading"
			:name="t('teamhub', 'Nothing can be requested yet')"
			:description="t('teamhub', 'No service team on this server is open for requests.')">
			<template #icon><LifebuoyIcon :size="ICON_HERO" /></template>
		</NcEmptyContent>

		<div v-else-if="loading && !entries.length" class="svc-cat__loading">
			<NcLoadingIcon :size="ICON_LARGE" />
		</div>

		<template v-else>
			<p v-if="!visible.length" class="svc-cat__empty">
				{{ t('teamhub', 'No service in this category.') }}
			</p>

			<!-- ── A to Z ───────────────────────────────────────────────
			     The offering team is on every card here, because two desks
			     may offer services with similar names and in this view the
			     card is the only place that can tell them apart. -->
			<ul v-else-if="view === CATALOGUE_VIEW.ALPHABETICAL" class="svc-cat__grid">
				<li v-for="entry in visible" :key="catalogueKey(entry)" class="svc-card">
					<div class="svc-card__icon">
						<ServiceIcon :name="entryIcon(entry)" :size="ICON_LARGE" />
					</div>
					<h3 class="svc-card__title">{{ entry.label }}</h3>
					<p v-if="entry.serviceTeamName" class="svc-card__team">{{ entry.serviceTeamName }}</p>
					<p class="svc-card__text">{{ entry.description }}</p>
					<p v-if="entry.leadTime" class="svc-card__lead">
						<ClockOutline :size="ICON_INLINE" aria-hidden="true" />
						{{ entry.leadTime }}
					</p>
					<NcButton
						variant="secondary"
						class="svc-card__action"
						:aria-label="t('teamhub', 'Request {service}', { service: entry.label })"
						@click="startRequest(entry)">
						{{ t('teamhub', 'Request this') }}
					</NcButton>
				</li>
			</ul>

			<!-- ── By team ──────────────────────────────────────────────
			     The same cards under a heading per offering desk. The team
			     subline is dropped inside a group: the heading above the
			     grid already says it. -->
			<template v-else>
				<section v-for="group in groups" :key="group.teamId" class="svc-cat__group">
					<h3 class="svc-cat__group-title">
						{{ group.teamName || t('teamhub', 'Service team') }}
						<NcCounterBubble :count="group.services.length" />
					</h3>
					<ul class="svc-cat__grid">
						<li v-for="entry in group.services" :key="catalogueKey(entry)" class="svc-card">
							<div class="svc-card__icon">
								<ServiceIcon :name="entryIcon(entry)" :size="ICON_LARGE" />
							</div>
							<h4 class="svc-card__title">{{ entry.label }}</h4>
							<p class="svc-card__text">{{ entry.description }}</p>
							<p v-if="entry.leadTime" class="svc-card__lead">
								<ClockOutline :size="ICON_INLINE" aria-hidden="true" />
								{{ entry.leadTime }}
							</p>
							<NcButton
								variant="secondary"
								class="svc-card__action"
								:aria-label="t('teamhub', 'Request {service}', { service: entry.label })"
								@click="startRequest(entry)">
								{{ t('teamhub', 'Request this') }}
							</NcButton>
						</li>
					</ul>
				</section>
			</template>
		</template>

		</div>
		</template>

		<aside class="svc-cat__aside" :class="{ 'svc-cat__aside--request': requestOpen }">
			<MyRequestsWidget @detail="requestOpen = $event" />
		</aside>
		</div>

		<!-- v4.10.45 — where to go when the catalog does not have it: the
		     service desk and the knowledge portal an administrator set on
		     Settings → TeamHub → Services. Absent when neither is set. -->
		<footer v-if="!requestOpen && (links.serviceDesk || links.knowledgePortal)" class="svc-cat__links">
			<p class="svc-cat__links-text">{{ t('teamhub', 'Not finding what you need?') }}</p>
			<div class="svc-cat__links-actions">
				<NcButton
					v-if="links.serviceDesk"
					variant="secondary"
					:href="links.serviceDesk"
					target="_blank"
					rel="noopener noreferrer">
					<template #icon><LifebuoyIcon :size="ICON_BODY" /></template>
					{{ t('teamhub', 'Open the service desk') }}
				</NcButton>
				<NcButton
					v-if="links.knowledgePortal"
					variant="secondary"
					:href="links.knowledgePortal"
					target="_blank"
					rel="noopener noreferrer">
					<template #icon><BookOpenOutline :size="ICON_BODY" /></template>
					{{ t('teamhub', 'Open the knowledge portal') }}
				</NcButton>
			</div>
		</footer>

		<!-- The form behind a card. The service is already chosen, so the
		     dialog asks only what the desk cannot know. -->
		<!-- v4.10.29 — the quota request asks for a size, for a team the
		     viewer administers, so it has a form of its own. -->
		<QuotaRequestDialog
			v-if="chosen && chosenIsQuota && quotaTeams"
			:service="chosen"
			:teams="quotaTeams"
			:busy="workflowBusy"
			:error="requestError"
			@close="closeRequest"
			@submit="submitQuotaRequest" />
		<!-- v4.10.45 — more time for a team asks for a date, for a team the
		     viewer administers, so it has a form of its own too. -->
		<ExpiryRequestDialog
			v-else-if="chosen && chosenIsExpiry && expiryTeams"
			:service="chosen"
			:teams="expiryTeams"
			:busy="workflowBusy"
			:error="requestError"
			@close="closeRequest"
			@submit="submitExpiryRequest" />
		<ServiceRequestDialog
			v-else-if="chosen && !chosenIsQuota && !chosenIsExpiry"
			:service="chosen"
			:teams="askableTeams"
			:busy="workflowBusy"
			:error="requestError"
			:created="created"
			@close="closeRequest"
			@submit="submitRequest"
			@complete-task="completeCreatedTask" />
	</div>
</template>

<script>
import { mapState } from 'vuex'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcCounterBubble, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'

import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import BookOpenOutline from 'vue-material-design-icons/BookOpenOutline.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import LifebuoyIcon from 'vue-material-design-icons/Lifebuoy.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import SortAlphabeticalVariant from 'vue-material-design-icons/SortAlphabeticalVariant.vue'

import ExpiryRequestDialog from './services/ExpiryRequestDialog.vue'
import MyRequestsWidget from './services/MyRequestsWidget.vue'
import TogglePill from './TogglePill.vue'
import QuotaRequestDialog from './services/QuotaRequestDialog.vue'
import ServiceIcon from './services/ServiceIcon.vue'
import ServiceRequestDialog from './services/ServiceRequestDialog.vue'
import { loadExpiryTeams, loadQuotaTeams } from '../api/serviceTeams.js'
import {
	ALL_CATEGORIES, CATALOGUE_VIEW,
	categoryTiles, catalogueKey, entryIcon, filterByCategory, formatBytes, groupedByTeam,
	isExpiryService, isQuotaService, sortedCatalogue,
} from '../constants/serviceTeams.js'
import { httpsOnly, ownOpenTasks } from '../constants/workflows.js'
import { ICON_BODY, ICON_HERO, ICON_INLINE, ICON_LARGE } from '../constants/uiTokens.js'

/**
 * The service catalogue (WorkflowHub phase 7A, v4.10.25).
 *
 * Where a request **starts**. My Work is where a person understands where
 * their request is and takes the step that is theirs; the service team is
 * where the work is done. Those are three surfaces with three jobs, and
 * this is the first of them — which is why *Ask the service desk* left My
 * Work's header for a card on this page (`docs/service-catalogue.md` § 1).
 *
 * **One payload, two readings.** `GET /service-teams/catalogue` returns
 * every service every active desk offers, each row carrying its offering
 * team, its category and its lead time. A–Z, by team and the category
 * tiles are all derived from that one list in the browser: there is no
 * second endpoint, no second request and nothing to keep in step.
 *
 * The page is licensed and claim-gated by consequence rather than by a
 * check of its own: an unlicensed instance answers 403, a server with no
 * desk answers an empty list, and both end as "nothing can be requested".
 * The navigation entry is absent in either case, so the empty state here
 * is for a stale deep link.
 */
export default {
	name: 'ServiceCatalogueView',

	components: {
		NcButton, NcCounterBubble, NcEmptyContent, NcLoadingIcon,
		ExpiryRequestDialog, MyRequestsWidget, QuotaRequestDialog, ServiceIcon, ServiceRequestDialog, TogglePill,
		// v4.10.45 — the card and tile glyphs are ServiceIcon's, by name.
		AccountGroupOutline, BookOpenOutline, ClockOutline, LifebuoyIcon,
		Refresh, SortAlphabeticalVariant,
	},

	data() {
		return {
			ALL_CATEGORIES,
			CATALOGUE_VIEW,
			ICON_BODY,
			ICON_HERO,
			ICON_INLINE,
			ICON_LARGE,
			/** '' is every service; a category key filters both views. */
			activeCategory: ALL_CATEGORIES,
			view: CATALOGUE_VIEW.ALPHABETICAL,
			loading: false,
			/** The catalogue entry whose form is open, or null. */
			chosen: null,
			/**
			 * v4.11.12 — one of the viewer's own requests has the page.
			 * `MyRequestsWidget` owns the request and says when it does; this
			 * page's job is to step out of the way while it is open.
			 */
			requestOpen: false,
			requestError: '',
			/** v4.10.29 — the quota card's teams, read when its form opens; null while loading. */
			quotaTeams: null,
			/** v4.10.45 — the same for the card asking for more time. */
			expiryTeams: null,
			/** v4.10.39 — the request just sent, while the requester does its first tasks. */
			created: null,
		}
	},

	computed: {
		...mapState({
			entries: state => state.serviceTeams.catalogue || [],
			// v4.10.45 — the administrator's category order, and the links.
			categoryOrder: state => (state.serviceTeams.catalogueCategories || []).map(c => c.key),
			// v4.11.0 — re-checked here before either reaches an `href`: the
			// server stores `https://` only, and the page does not rely on it.
			links: state => {
				const raw = state.serviceTeams.catalogueLinks || {}
				return { serviceDesk: httpsOnly(raw.serviceDesk), knowledgePortal: httpsOnly(raw.knowledgePortal) }
			},
			allTeams: state => state.teams || [],
			workflowBusy: state => state.workflows.busyId === 0,
		}),

		tiles() {
			return categoryTiles(this.entries, this.categoryOrder)
		},

		/** The selected category, in the reading the toggle asks for. */
		visible() {
			return sortedCatalogue(filterByCategory(this.entries, this.activeCategory))
		},

		groups() {
			return groupedByTeam(this.visible)
		},

		/**
		 * The teams a request may be about: every team the viewer is in.
		 * Not the desks — the desk is decided by the service, and asking a
		 * requester to pick one is the thing a catalogue exists to avoid.
		 */
		askableTeams() {
			return this.allTeams.map(team => ({ id: team.id, name: team.name }))
		},

		chosenIsQuota() {
			return isQuotaService(this.chosen)
		},

		chosenIsExpiry() {
			return isExpiryService(this.chosen)
		},
	},

	mounted() {
		this.reload()
	},

	methods: {
		t,
		catalogueKey,
		entryIcon,

		async reload() {
			this.loading = true
			try {
				await this.$store.dispatch('serviceTeams/loadCatalogue')
				// A category that was filtered on and has since gone (a desk
				// released its claim) would otherwise leave the page empty
				// with no tile pressed to explain why.
				if (this.activeCategory && !this.tiles.some(tile => tile.id === this.activeCategory)) {
					this.activeCategory = ALL_CATEGORIES
				}
			} finally {
				this.loading = false
			}
		},

		async startRequest(entry) {
			this.requestError = ''
			this.quotaTeams = null
			this.expiryTeams = null
			this.chosen = entry
			// v4.10.45 — the card asking for more time: the teams the viewer
			// administers with an expiration date, read fresh.
			if (isExpiryService(entry)) {
				try {
					this.expiryTeams = await loadExpiryTeams()
				} catch (e) {
					this.chosen = null
					showError(t('teamhub', 'Could not load your teams. Try again.'))
				}
				return
			}
			if (!isQuotaService(entry)) {
				return
			}
			// The quota form needs the teams the viewer administers with a
			// team space, read fresh: a request opened a minute ago on Manage
			// team must already count as open here.
			try {
				this.quotaTeams = await loadQuotaTeams()
			} catch (e) {
				this.chosen = null
				showError(t('teamhub', 'Could not load your teams. Try again.'))
			}
		},

		/**
		 * v4.10.39 — the requester did one of their first tasks from the
		 * dialog (pressed its link, or marked it done). When none is left,
		 * the request is with the team and the dialog closes.
		 */
		async completeCreatedTask({ workflow, step }) {
			this.requestError = ''
			try {
				const updated = await this.$store.dispatch('workflows/act', { id: workflow.id, action: 'complete', step })
				if (updated && ownOpenTasks(updated).length) {
					this.created = updated
					return
				}
				const title = updated?.title || workflow.title
				this.closeRequest()
				showSuccess(t('teamhub', 'Request sent: {title}', { title }))
			} catch (e) {
				if (e?.status === 0) {
					return
				}
				this.requestError = e?.message || t('teamhub', 'That could not be marked as done.')
			}
		},

		closeRequest() {
			this.created = null
			this.chosen = null
			this.quotaTeams = null
			this.expiryTeams = null
			this.requestError = ''
		},

		/** v4.10.45 — a request for more time: a date and a reason, for one team. */
		async submitExpiryRequest({ teamId, proposedOn, reason }) {
			if (!this.chosen) {
				return
			}
			this.requestError = ''
			try {
				await this.$store.dispatch('workflows/start', {
					teamId,
					definitionKey: this.chosen.definitionKey,
					data: { proposedOn, reason },
				})
				this.closeRequest()
				showSuccess(t('teamhub', 'More time until {date} requested', { date: proposedOn }))
			} catch (e) {
				if (e?.status === 0) {
					return
				}
				this.requestError = e?.message || t('teamhub', 'The request could not be sent.')
			}
		},

		/** v4.10.29 — a quota request: a size and a reason, for one team. */
		async submitQuotaRequest({ teamId, requestedBytes, reason }) {
			if (!this.chosen) {
				return
			}
			this.requestError = ''
			try {
				await this.$store.dispatch('workflows/start', {
					teamId,
					definitionKey: this.chosen.definitionKey,
					data: { requestedBytes, reason },
				})
				this.closeRequest()
				showSuccess(t('teamhub', 'Quota increase to {size} requested', { size: formatBytes(requestedBytes) }))
			} catch (e) {
				if (e?.status === 0) {
					return
				}
				this.requestError = e?.message || t('teamhub', 'The request could not be sent.')
			}
		},

		/**
		 * One service request. The definition key comes from the catalogue
		 * entry behind the card, so the routing decision is the server's
		 * catalogue and never a string this component built.
		 */
		async submitRequest({ teamId, summary, details, fileIds = [], templateKey = '', profileKey = '' }) {
			if (!this.chosen) {
				return
			}
			this.requestError = ''
			try {
				const data = { summary, details }
				// v4.10.38 — the files ride along; the server shares them
				// with the service team and keeps them out of the data.
				if (fileIds.length) {
					data.fileIds = fileIds
				}
				// v4.12.4 — the new-team card's two extra answers. The dialog
				// sends them for that card only, and the definition keeps them
				// optional, so an empty one is a request without a shape asked
				// for rather than a refusal.
				if (templateKey) {
					data.templateKey = templateKey
				}
				if (profileKey) {
					data.profileKey = profileKey
				}
				const created = await this.$store.dispatch('workflows/start', {
					teamId,
					definitionKey: this.chosen.definitionKey,
					data,
				})
				// v4.10.39 — a service that starts with the requester: keep the
				// dialog open on their first task (a form to fill in).
				if (ownOpenTasks(created).length) {
					this.created = created
					return
				}
				this.chosen = null
				showSuccess(t('teamhub', 'Request sent: {title}', { title: created?.title || summary }))
			} catch (e) {
				// status 0 is the store refusing a second submission while one
				// is in flight; the button is already disabled, so saying so
				// twice would be noise.
				if (e?.status === 0) {
					return
				}
				this.requestError = e?.message || t('teamhub', 'The request could not be sent.')
			}
		},
	},
}
</script>

<style scoped lang="scss">
.svc-cat {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-lg, 16px);
	padding: var(--th-space-lg, 16px);
	/* v4.11.10 — the same canvas as My Work, What's new and a team page
	   (v4.5.27): the service cards and the My requests panel are
	   --color-main-background with a border, so on a white page the borders
	   were carrying the whole layout. On grey they read as cards, and the
	   pages in the sidebar look like one product.
	   No own scroll box — NcAppContent is the scroller. */
	min-height: 100%;
	box-sizing: border-box;
	background: var(--color-background-dark);
}

/* v4.11.7 — the catalogue and the requester's own requests, side by side.
   The aside keeps a readable width and the catalogue takes the rest; below
   the breakpoint they stack, the requests last, because the page's job is
   still to start one.
   v4.11.13 — a grid of two rows: the pills across the catalogue column only,
   then the services beside the requests. The second row is what lines the
   aside up with the first service card. */
.svc-cat__layout {
	--svc-aside-width: 320px;

	display: grid;
	grid-template-columns: minmax(0, 1fr) var(--svc-aside-width);
	grid-template-areas:
		"pills ."
		"main  aside";
	align-items: start;
	gap: var(--th-space-lg, 16px);
}

.svc-cat__pills {
	grid-area: pills;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: var(--th-space-lg, 16px);
}

.svc-cat__main {
	grid-area: main;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: var(--th-space-lg, 16px);
}

.svc-cat__aside {
	grid-area: aside;
	min-width: 0;
	position: sticky;
	inset-block-start: var(--th-space-lg, 16px);
}

/* v4.11.12 — while a request has the page there is one column, and the aside
   is that column: no sticky 320px rail, no gap beside an empty main. */
.svc-cat__layout--request {
	display: block;
}

.svc-cat__aside--request {
	inline-size: 100%;
	position: static;
}

@media (max-width: 1024px) {
	.svc-cat__layout {
		grid-template-columns: minmax(0, 1fr);
		grid-template-areas:
			"pills"
			"main"
			"aside";
	}

	.svc-cat__aside {
		inline-size: 100%;
		position: static;
	}
}

.svc-cat__head {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: var(--th-space-md, 12px);
	/* 2026-09-25 — clearance for NC's navigation toggle. NcAppNavigation
	   renders it unconditionally and parks it over the first 44px of the app
	   content at every width, open or closed, so without this the button sat
	   on top of "Service catalog". */
	padding-inline-start: var(--default-clickable-area, 44px);
}

.svc-cat__title {
	font-size: var(--th-font-title, 24px);
	font-weight: var(--th-font-weight-bold, 700);
	margin: 0;
}

.svc-cat__hint {
	margin: var(--th-space-xs, 4px) 0 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
	max-width: 70ch;
}

/* v4.10.45 — the service desk and knowledge portal links. */
.svc-cat__links {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: var(--th-space-md, 12px);
	padding-block-start: var(--th-space-lg, 16px);
	border-block-start: 1px solid var(--color-border);
}

.svc-cat__links-text {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.svc-cat__links-actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--th-space-sm, 8px);
}

.svc-cat__loading {
	display: flex;
	justify-content: center;
	padding: var(--th-space-xl, 24px);
}

.svc-cat__filters,
.svc-cat__views {
	display: flex;
	flex-wrap: wrap;
	gap: var(--th-space-sm, 8px);
}

.svc-cat__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

.svc-cat__group {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm, 8px);
}

.svc-cat__group-title {
	display: flex;
	align-items: center;
	gap: var(--th-space-sm, 8px);
	font-size: var(--th-font-heading, 16px);
	font-weight: var(--th-font-weight-semibold, 600);
	margin: 0;
}

/* The grid is fluid rather than a fixed column count: the sidebar makes the
   canvas narrow enough that three columns would break, and auto-fill lets
   the same page read on a phone. */
.svc-cat__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
	gap: var(--th-space-md, 12px);
	list-style: none;
	margin: 0;
	padding: 0;
}

.svc-card {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
	padding: var(--th-space-lg, 16px);
	border: 1px solid var(--color-border);
	border-radius: var(--th-radius-card, var(--border-radius-container));
	background: var(--color-main-background);

	&:hover {
		background: var(--color-background-hover);
	}
}

.svc-card__icon {
	display: flex;
	color: var(--color-primary-element);
	margin-block-end: var(--th-space-xs, 4px);
}

.svc-card__title {
	font-size: var(--th-font-heading, 16px);
	font-weight: var(--th-font-weight-semibold, 600);
	margin: 0;
}

.svc-card__team {
	margin: 0;
	color: var(--color-primary-element);
	font-size: var(--th-font-meta, 13px);
}

.svc-card__text {
	margin: 0;
	font-size: var(--th-font-body, 15px);
	color: var(--color-main-text);
}

.svc-card__lead {
	display: flex;
	align-items: center;
	gap: var(--th-space-xs, 4px);
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

/* Pushed to the bottom so every card in a row ends with its button on the
   same line, however long the description is. */
.svc-card__action {
	margin-block-start: auto;
	align-self: flex-start;
}
</style>
