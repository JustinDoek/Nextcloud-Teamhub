<template>
	<div ref="scroller" class="mywork" @scroll.passive="rememberScroll">
		<!-- ── Header ───────────────────────────────────────────────────── -->
		<header class="mywork__header">
			<div class="mywork__header-text">
				<h2 class="mywork__title">{{ t('teamhub', 'My Work') }}</h2>
				<span class="mywork__subtitle">
					{{ t('teamhub', 'Everything that needs your attention, across all your teams') }}
				</span>
			</div>

			<div class="mywork__header-actions">
				<NcButton
					:variant="filtersOpen || hasActiveFilters ? 'secondary' : 'tertiary'"
					:aria-expanded="filtersOpen"
					aria-controls="mywork-filters"
					@click="filtersOpen = !filtersOpen">
					<template #icon><FilterVariant :size="iconBody" /></template>
					{{ filterButtonLabel }}
				</NcButton>

				<!-- Density toggle. Segmented pair with aria-pressed — the
				     SKILLS.md carve-out for two touching pills. -->
				<div class="mywork__density" role="group" :aria-label="t('teamhub', 'Row density')">
					<button
						type="button"
						class="mywork__density-btn"
						:class="{ 'mywork__density-btn--on': !compact }"
						:aria-pressed="!compact"
						:title="t('teamhub', 'Comfortable rows')"
						@click="setCompact(false)">
						<FormatListBulletedSquare :size="iconBody" aria-hidden="true" />
						<span class="mywork__sr">{{ t('teamhub', 'Comfortable rows') }}</span>
					</button>
					<button
						type="button"
						class="mywork__density-btn"
						:class="{ 'mywork__density-btn--on': compact }"
						:aria-pressed="compact"
						:title="t('teamhub', 'Compact rows')"
						@click="setCompact(true)">
						<ViewSequentialOutline :size="iconBody" aria-hidden="true" />
						<span class="mywork__sr">{{ t('teamhub', 'Compact rows') }}</span>
					</button>
				</div>

				<NcButton
					variant="tertiary"
					:aria-label="t('teamhub', 'Refresh My Work')"
					:title="t('teamhub', 'Refresh My Work')"
					:disabled="loading"
					@click="refresh(true)">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="iconBody" />
						<Refresh v-else :size="iconBody" aria-hidden="true" />
					</template>
				</NcButton>
			</div>
		</header>

		<!-- ── Provider failures — non-blocking ─────────────────────────── -->
		<div v-if="failedProviders.length" class="mywork__notice" role="status" aria-live="polite">
			<AlertCircleOutline :size="iconBody" aria-hidden="true" />
			<span>{{ providerFailureMessage }}</span>
			<NcButton variant="tertiary" @click="refresh(true)">{{ t('teamhub', 'Try again') }}</NcButton>
		</div>

		<!-- v4.9.7 — a source that answered, but not cleanly: the viewer's
		     account needs reconnecting, some projects could not be read, or
		     the source was slow and the tail was skipped. One line per
		     source and code; the rows that did arrive are real. -->
		<div
			v-for="warning in providerWarnings"
			:key="warning.key"
			class="mywork__notice"
			:class="{ 'mywork__notice--info': warning.tone === 'info' }"
			role="status"
			aria-live="polite">
			<AlertCircleOutline v-if="warning.tone !== 'info'" :size="iconBody" aria-hidden="true" />
			<InformationOutline v-else :size="iconBody" aria-hidden="true" />
			<span>{{ warning.text }}</span>
			<NcButton v-if="warning.action === 'reconnect'" variant="tertiary" @click="openPersonalSettings(warning.providerId)">
				{{ t('teamhub', 'Open personal settings') }}
			</NcButton>
			<NcButton v-else variant="tertiary" @click="refresh(true)">{{ t('teamhub', 'Refresh') }}</NcButton>
		</div>

		<div v-if="truncatedProviders.length" class="mywork__notice mywork__notice--info" role="status" aria-live="polite">
			<InformationOutline :size="iconBody" aria-hidden="true" />
			<span>{{ t('teamhub', 'Some sources returned more work than can be shown at once. The most urgent items are listed first.') }}</span>
		</div>

		<!-- ── Summary cards ────────────────────────────────────────────── -->
		<div class="mywork__summary" role="group" :aria-label="t('teamhub', 'Work summary')">
			<button
				v-for="card in summaryCards"
				:key="card.key"
				type="button"
				class="mywork__card"
				:class="[
					'mywork__card--' + card.tone,
					{ 'mywork__card--active': card.active },
				]"
				:aria-pressed="card.active"
				@click="toggleSummary(card)">
				<!-- v4.5.39 — the card is a two-column grid: name over sources
				     on the left, the number on its own on the right, centred
				     against both lines. 4.5.27 put the count at the end of the
				     label's row, which capped how large it could be — the label
				     is a heading now and the number is the size it deserves. -->
				<span class="mywork__card-head">
					<span class="mywork__card-chip" aria-hidden="true">
						<component :is="card.icon" :size="iconBody" />
					</span>
					<span class="mywork__card-label">{{ card.label }}</span>
				</span>
				<!-- v4.5.25 — one chip per source: its glyph and its number.
				     The name lives in the title/aria-label rather than on the
				     card, so three sources cost one line instead of three.
				     v4.5.39 — a step larger and a step lighter: it is detail
				     you read after the number, not with it. -->
				<span class="mywork__card-lines">
					<span
						v-for="row in card.breakdown"
						:key="row.providerId"
						class="mywork__card-source"
						:title="breakdownLabel(row, providerNames)"
						:aria-label="breakdownLabel(row, providerNames)">
						<component
							:is="providerIcon(row.providerId)"
							:size="iconBody"
							aria-hidden="true" />
						{{ row.count }}
					</span>
					<span v-if="!card.breakdown.length" class="mywork__card-line mywork__card-line--empty">
						{{ card.emptyHint }}
					</span>
				</span>
				<span class="mywork__card-count">{{ card.count }}</span>
			</button>
		</div>

		<!-- ── Source tabs + sort ───────────────────────────────────────────
		     The tab bar answers "where is my work coming from" in one glance,
		     which the summary cards cannot: they split by urgency, and a user
		     who wants only their Deck cards was previously three clicks into
		     the filter panel. Counts come from the server with every filter
		     applied EXCEPT this one, on the same principle as the cards. -->
		<!-- ── Predefined views (v4.9.7) ───────────────────────────────────
		     Shortcuts over the same filter state the bar below sets — a chip
		     lights up when the state matches it, whichever control got it
		     there, and Clear filters undoes it like anything else. -->
		<div class="mywork__views" role="group" :aria-label="t('teamhub', 'Views')">
			<span class="mywork__views-label">{{ t('teamhub', 'Views') }}</span>
			<button
				v-for="view in views"
				:key="view.key"
				type="button"
				class="mywork__view"
				:class="{ 'mywork__view--on': view.active }"
				:aria-pressed="view.active"
				@click="applyView(view)">
				{{ view.label }}
			</button>
			<span v-if="lastUpdatedLabel" class="mywork__updated" :title="lastUpdatedTitle">
				{{ lastUpdatedLabel }}
			</span>
		</div>

		<div class="mywork__toolbar">
			<div class="mywork__sources" role="group" :aria-label="t('teamhub', 'Filter by source')">
				<button
					v-for="tab in sourceTabs"
					:key="tab.key"
					type="button"
					class="mywork__source"
					:class="{ 'mywork__source--on': tab.active }"
					:aria-pressed="tab.active"
					@click="selectSource(tab.key)">
					<component :is="tab.icon" :size="iconInline" class="mywork__source-icon" aria-hidden="true" />
					<span>{{ tab.label }}</span>
					<span v-if="tab.count !== null" class="mywork__source-count">{{ tab.count }}</span>
				</button>
			</div>

			<label class="mywork__sort">
				<span class="mywork__sort-label">{{ t('teamhub', 'Sort by') }}</span>
				<select class="mywork__sort-select" :value="sortBy" @change="onSortBy($event.target.value)">
					<option v-for="option in sortChoices" :key="option.key" :value="option.key">
						{{ option.label }}
					</option>
				</select>
			</label>
		</div>

		<!-- ── Filters, collapsed by default ────────────────────────────── -->
		<MyWorkFilters
			v-show="filtersOpen"
			id="mywork-filters"
			:search="filters.search"
			:group-by="groupBy"
			:team-id="filters.teamId"
			:provider-id="filters.providerId"
			:priority="filters.priority"
			:status="filters.status"
			:resource-type="filters.resourceType"
			:due-window="filters.dueWindow"
			:show-snoozed="filters.showSnoozed"
			:project-id="filters.projectId || ''"
			:work-type="filters.workType || ''"
			:projects="facetProjects"
			:work-types="facetWorkTypes"
			:teams="payloadTeams"
			:providers="providers"
			@update:projectId="onFilter('projectId', $event)"
			@update:workType="onFilter('workType', $event)"
			@update:search="onFilter('search', $event)"
			@update:groupBy="onGroupBy"
			@update:teamId="onFilter('teamId', $event)"
			@update:providerId="onFilter('providerId', $event)"
			@update:priority="onFilter('priority', $event)"
			@update:status="onFilter('status', $event)"
			@update:resourceType="onFilter('resourceType', $event)"
			@update:dueWindow="onFilter('dueWindow', $event)"
			@update:showSnoozed="onFilter('showSnoozed', $event)"
			@reset="resetFilters" />

		<!-- ── First load ───────────────────────────────────────────────── -->
		<div v-if="loading && !payload" class="mywork__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<!-- ── Nothing at all ───────────────────────────────────────────── -->
		<NcEmptyContent
			v-else-if="!items.length"
			:name="emptyTitle"
			:description="emptyBody">
			<template #icon><ClipboardCheckOutline :size="iconHero" /></template>
			<template v-if="hasActiveFilters" #action>
				<NcButton variant="secondary" @click="resetFilters">
					{{ t('teamhub', 'Clear filters') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<!-- ── The queue ────────────────────────────────────────────────────
		     Two columns when the page is grouped by category: what you have to
		     do on the left, what is merely happening on the right. Waiting for
		     others and Completed are real information but they are not a
		     to-do list, and giving them the same weight as Action required is
		     what made a queue of twenty rows feel like forty.

		     The rail is category-derived, so it only appears when the page is
		     actually grouped by category. Under any other grouping the same
		     items would appear twice, and a duplicate row is worse than a
		     missing panel. -->
		<div v-else class="mywork__layout" :class="{ 'mywork__layout--railless': !showRail }">
		<div class="mywork__groups" :class="{ 'mywork__groups--compact': compact }">
			<section
				v-for="group in mainGroups"
				:key="group.key"
				class="mywork__group"
				:class="[
					'mywork__group--' + groupTone(group.key),
					{ 'mywork__group--collapsed': !isExpanded(group.key) },
				]">
				<!-- Section header: icon + name on the left, the column
				     captions in the middle, the collapse control on the right.
				     v4.5.39 — every section collapses to this row alone, and
				     the chevron sits at the far right the way every team-home
				     widget's does. It is its own button rather than the whole
				     title, again matching the widgets: the heading is a
				     heading, and the one thing that toggles looks like it.
				     Column captions go with the rows they label — they say
				     nothing about a section with no rows on screen. -->
				<div class="mywork__group-head">
					<component
						:is="groupIcon(group.key)"
						:size="iconToolbar"
						class="mywork__group-icon"
						aria-hidden="true" />

					<h3 :id="'mywork-group-title-' + group.key" class="mywork__group-title">
						<span>{{ group.label }}</span>
						<span class="mywork__group-count">{{ group.itemIds.length }}</span>
					</h3>

					<div v-if="isExpanded(group.key)" class="mywork__columns" aria-hidden="true">
						<span>{{ t('teamhub', 'Team') }}</span>
						<span>{{ t('teamhub', 'Reason') }}</span>
						<span>{{ t('teamhub', 'Deadline') }}</span>
					</div>

					<!-- `aria-labelledby` at the heading rather than an
					     aria-label of its own: the button's name is the section
					     it opens, the heading already says it, and a second
					     copy is a string that can drift out of step with the
					     first. Announces as "Action required 7, collapsed,
					     button". -->
					<button
						type="button"
						class="mywork__group-toggle"
						:aria-expanded="isExpanded(group.key)"
						:aria-labelledby="'mywork-group-title-' + group.key"
						@click="toggleGroup(group.key)">
						<ChevronDown
							:size="iconBody"
							class="mywork__chevron"
							:class="{ 'mywork__chevron--open': isExpanded(group.key) }"
							aria-hidden="true" />
					</button>
				</div>

				<!-- `v-if`, not `v-show`, and therefore no `aria-controls` on
				     the toggle: a collapsed section that stayed in the DOM
				     would still mount its rows and fetch their avatars, which
				     is the opposite of what collapsing it asked for.
				     `aria-expanded` is the part the disclosure pattern
				     requires; `aria-controls` is optional and would dangle at
				     an id that does not exist while collapsed. -->
				<ul v-if="isExpanded(group.key)" class="mywork__list">
					<MyWorkItemRow
						v-for="item in visibleItemsForGroup(group)"
						:key="item.id"
						:item="item"
						:provider-names="providerNames"
						:busy="busyItemId === item.id"
						:compact="compact"
						@open="openItem"
						@open-team="openTeam"
						@action="onAction"
						@snooze="onSnooze" />
				</ul>

				<!-- Per-section "show the rest" — a long Action-required
				     section should not push every other section off screen. -->
				<button
					v-if="isExpanded(group.key) && hiddenCount(group) > 0"
					type="button"
					class="mywork__group-more"
					@click="expandGroup(group.key)">
					{{ t('teamhub', 'Show all {n}', { n: group.itemIds.length }) }}
				</button>
			</section>

			<!-- ── Pagination ───────────────────────────────────────────── -->
			<div v-if="total > pageSize" class="mywork__pagination">
				<NcButton variant="secondary" :disabled="page <= 1 || loading" @click="goToPage(page - 1)">
					<template #icon><ChevronLeft :size="iconBody" /></template>
					{{ t('teamhub', 'Previous') }}
				</NcButton>
				<span class="mywork__pagination-label" aria-live="polite">
					{{ t('teamhub', 'Page {page} of {total}', { page, total: totalPages }) }}
				</span>
				<NcButton variant="secondary" :disabled="!hasMore || loading" @click="goToPage(page + 1)">
					{{ t('teamhub', 'Next') }}
					<template #icon><ChevronRight :size="iconBody" /></template>
				</NcButton>
			</div>
		</div>

		<!-- ── The rail ─────────────────────────────────────────────────── -->
		<aside v-if="showRail" class="mywork__rail" :aria-label="t('teamhub', 'At a glance')">
			<section
				v-for="panel in railPanels"
				:key="panel.key"
				class="mywork__panel">
				<h3 class="mywork__panel-head">
					<span class="mywork__panel-icon" aria-hidden="true">
						<component :is="panel.icon" :size="iconBody" />
					</span>
					<span class="mywork__panel-title">{{ panel.label }}</span>
					<span class="mywork__panel-count">{{ panel.count }}</span>
				</h3>

				<ul v-if="panel.items.length" class="mywork__panel-list">
					<li v-for="item in panel.items" :key="item.id" class="mywork__panel-row">
						<span class="mywork__panel-glyph" aria-hidden="true">
							<component :is="resourceIcon(item)" :size="iconInline" />
						</span>
						<button
							type="button"
							class="mywork__panel-text"
							:title="t('teamhub', 'Open {title}', { title: item.title })"
							@click="openItem(item)">
							<span class="mywork__panel-item-title">{{ item.title }}</span>
							<span class="mywork__panel-item-sub">{{ panelSubtitle(item) }}</span>
						</button>
						<span v-if="panelMeta(panel, item)" class="mywork__panel-meta">
							{{ panelMeta(panel, item) }}
						</span>
					</li>
				</ul>

				<p v-else class="mywork__panel-empty">{{ panel.empty }}</p>

				<button
					v-if="panel.count > panel.items.length"
					type="button"
					class="mywork__panel-more"
					@click="panel.viewAll()">
					{{ t('teamhub', 'View all') }} →
				</button>
			</section>
		</aside>
		</div>

		<!-- ── Custom snooze ────────────────────────────────────────────── -->
		<NcModal v-if="snoozeTarget" :name="t('teamhub', 'Snooze until')" @close="snoozeTarget = null">
			<div class="mywork__modal">
				<h3 class="mywork__modal-title">{{ t('teamhub', 'Snooze until') }}</h3>
				<p class="mywork__modal-body">
					{{ t('teamhub', 'This hides the item from My Work until the moment you choose. It does not change the due date in the source application.') }}
				</p>
				<label class="mywork__modal-field">
					<span>{{ t('teamhub', 'Date and time') }}</span>
					<input v-model="snoozeCustomValue" type="datetime-local" class="mywork__modal-input">
				</label>
				<div class="mywork__modal-actions">
					<NcButton variant="tertiary" @click="snoozeTarget = null">{{ t('teamhub', 'Cancel') }}</NcButton>
					<NcButton variant="primary" :disabled="!snoozeCustomValue" @click="confirmCustomSnooze">
						{{ t('teamhub', 'Snooze') }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<!-- ── Request an extension (v4.6.17) ───────────────────────────────
		     The row used to send the reader to Manage team → Maintenance to
		     fill this in. Two fields do not justify leaving the queue. -->
		<NcModal
			v-if="extensionTarget"
			:name="t('teamhub', 'Request an extension')"
			@close="extensionTarget = null">
			<div class="mywork__modal">
				<h3 class="mywork__modal-title">{{ t('teamhub', 'Request an extension') }}</h3>
				<p class="mywork__modal-body">
					{{ t('teamhub', '{team} expires on {date}. A Nextcloud administrator decides on the request.', {
						team: extensionTarget.teamName,
						date: extensionTarget.metadata.expiresOn,
					}) }}
				</p>
				<label class="mywork__modal-field">
					<span>{{ t('teamhub', 'Ask to extend until') }}</span>
					<!-- min is the day after the current date: the server refuses
					     anything at or before it, so refusing here saves a
					     round trip to be told so. -->
					<input
						v-model="extensionDate"
						type="date"
						class="mywork__modal-input"
						:min="extensionMinDate">
				</label>
				<label class="mywork__modal-field">
					<span>{{ t('teamhub', 'Why does this team need more time?') }}</span>
					<textarea
						v-model="extensionReason"
						class="mywork__modal-input mywork__modal-textarea"
						rows="3"
						maxlength="1000"
						:placeholder="t('teamhub', 'e.g. The project runs until the end of Q3 and the handover is in September.')" />
				</label>
				<div class="mywork__modal-actions">
					<NcButton variant="tertiary" @click="extensionTarget = null">
						{{ t('teamhub', 'Cancel') }}
					</NcButton>
					<NcButton variant="primary" :disabled="!extensionDate" @click="confirmExtension">
						{{ t('teamhub', 'Request extension') }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<!-- ── Comment ──────────────────────────────────────────────────── -->
		<NcModal v-if="commentTarget" :name="t('teamhub', 'Add a comment')" @close="commentTarget = null">
			<div class="mywork__modal">
				<h3 class="mywork__modal-title">{{ t('teamhub', 'Add a comment') }}</h3>
				<p class="mywork__modal-body">{{ commentTarget.subtitle || commentTarget.title }}</p>
				<label class="mywork__modal-field">
					<span>{{ t('teamhub', 'Comment') }}</span>
					<textarea
						v-model="commentText"
						class="mywork__modal-input mywork__modal-textarea"
						rows="4"
						maxlength="1000" />
				</label>
				<div class="mywork__modal-actions">
					<NcButton variant="tertiary" @click="commentTarget = null">{{ t('teamhub', 'Cancel') }}</NcButton>
					<NcButton variant="primary" :disabled="!commentText.trim()" @click="confirmComment">
						{{ t('teamhub', 'Post comment') }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<!-- ── Approve / reject, with a rationale where the source needs one.
		     One modal for both verbs and both cases: the item declares
		     `metadata.requiresReason` and the text field appears. Generic, so
		     a future provider whose actions need free text gets it free. -->
		<NcModal v-if="confirmTarget" :name="confirmTitle" @close="confirmTarget = null">
			<div class="mywork__modal">
				<h3 class="mywork__modal-title">{{ confirmTitle }}</h3>
				<p class="mywork__modal-body">
					{{ confirmBody }}
				</p>
				<label v-if="confirmNeedsReason || confirmAllowsReason" class="mywork__modal-field">
					<span>{{ confirmReasonLabel }}</span>
					<textarea
						v-model="confirmReason"
						class="mywork__modal-input mywork__modal-textarea"
						rows="3"
						maxlength="1000" />
				</label>
				<div class="mywork__modal-actions">
					<NcButton variant="tertiary" @click="confirmTarget = null">{{ t('teamhub', 'Cancel') }}</NcButton>
					<NcButton
						:variant="confirmIsDestructive ? 'error' : 'primary'"
						:disabled="confirmNeedsReason && !confirmReason.trim()"
						@click="confirmDestructive">
						{{ actionLabel(confirmTarget.action) }}
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import { mapState } from 'vuex'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { shiftIsoDate, formatTime } from '../lib/localDate.js'
import { personalSettingsUrl } from '../lib/openProject.js'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { NcButton, NcLoadingIcon, NcEmptyContent, NcModal } from '@nextcloud/vue'

import Refresh from 'vue-material-design-icons/Refresh.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import FilterVariant from 'vue-material-design-icons/FilterVariant.vue'
import FormatListBulletedSquare from 'vue-material-design-icons/FormatListBulletedSquare.vue'
import ViewSequentialOutline from 'vue-material-design-icons/ViewSequentialOutline.vue'
import CalendarToday from 'vue-material-design-icons/CalendarToday.vue'
// v4.5.45 — the Team admin category's glyph.
import ShieldAccountOutline from 'vue-material-design-icons/ShieldAccountOutline.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import AccountClock from 'vue-material-design-icons/AccountClock.vue'
// v4.6.17 — a person asking to be let into a team. AccountPlusOutline is the
// verb the admin is being asked for; AccountClock is taken by the Waiting for
// others category and the two would be a coin-flip at 16px.
import AccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
// Source-tab and rail-row glyphs — the same components the team tab bar uses,
// so a source chip here is the icon of the tab its items open in.
import ViewGrid from 'vue-material-design-icons/ViewGrid.vue'
import CardText from 'vue-material-design-icons/CardText.vue'
import Folder from 'vue-material-design-icons/Folder.vue'
import Gavel from 'vue-material-design-icons/Gavel.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import Puzzle from 'vue-material-design-icons/Puzzle.vue'
// v4.8.18 — the file review provider's glyph, used in the source filter and
// the group headers the same way every other provider icon is.
import FileEyeOutline from 'vue-material-design-icons/FileEyeOutline.vue'
import BriefcaseOutline from 'vue-material-design-icons/BriefcaseOutline.vue'
// v4.9.7 — an OpenProject milestone's glyph (rail rows, group headers).
import FlagOutline from 'vue-material-design-icons/FlagOutline.vue'
// v4.9.17 — the source groups' glyphs: Teams (a group of people) and
// Administration (the instance's authority — a shield with a crown, so it
// cannot be mistaken for Team admin's shield-with-person inside Teams).
// Files reuses Folder above.
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import ShieldCrownOutline from 'vue-material-design-icons/ShieldCrownOutline.vue'

import MyWorkItemRow from './mywork/MyWorkItemRow.vue'
import MyWorkFilters from './mywork/MyWorkFilters.vue'
import {
	ACTION,
	CATEGORY,
	CATEGORY_ICONS,
	CATEGORY_ORDER,
	CATEGORY_TONES,
	DESTRUCTIVE_ACTIONS,
	NAVIGATION_ACTIONS,
	FORM_ACTIONS,
	actionLabel,
	breakdownLabel,
	categoryEmptyState,
	categoryLabel,
	resolveSnoozePreset,
	FALLBACK_ICON,
	PROVIDER_ICONS,
	RESOURCE_TYPE_ICONS,
	SORT,
	sortOptions,
	predefinedViews,
	isViewActive,
	viewFilterPatch,
	providerWarning,
	formatAbsolute,
	buildSourceTabs,
	sourceGroupOf,
} from '../constants/myWork.js'
import { ICON_INLINE, ICON_BODY, ICON_TOOLBAR, ICON_HERO } from '../constants/uiTokens.js'

/** Debounce for the search field — one request per pause, not per keystroke. */
const SEARCH_DEBOUNCE_MS = 300

/** How stale a cached payload may be before mount forces a blocking refetch. */
const STALE_AFTER_MS = 30000

/** Rows shown per section before "Show all". Keeps every section on screen. */
const SECTION_PREVIEW = 5

/**
 * Categories the rail owns, and the main column therefore does not render.
 *
 * Both are real information and neither is a to-do: one is work you have
 * handed on, the other is work already done. Giving them the same weight as
 * Action required is what made a queue of twenty rows feel like forty.
 */
const RAIL_CATEGORIES = [CATEGORY.WAITING_FOR_OTHERS, CATEGORY.COMPLETED]

/**
 * Categories that get a summary card (v4.5.39).
 *
 * Waiting for others has a rail panel of its own — with its count, its rows
 * and a View all — so a card carrying the same number was the same fact in two
 * places, and the rail is the better one: it lists *who* you are waiting on.
 * Completed keeps its card because the number there is a week's worth of
 * closure, which is a summary; the panel beside it only shows the last few.
 */
const SUMMARY_CATEGORIES = CATEGORY_ORDER.filter(c => c !== CATEGORY.WAITING_FOR_OTHERS)

export default {
	name: 'MyWorkView',
	components: {
		NcButton, NcLoadingIcon, NcEmptyContent, NcModal,
		Refresh, ChevronLeft, ChevronRight, ChevronDown, ClipboardCheckOutline,
		AlertCircleOutline, InformationOutline, FilterVariant,
		FormatListBulletedSquare, ViewSequentialOutline,
		CalendarToday, CalendarClock, AccountClock, CheckCircleOutline,
		ShieldAccountOutline, AccountPlusOutline,
		ViewGrid, CardText, Folder, Gavel, Calendar, Puzzle, FileEyeOutline, BriefcaseOutline, FlagOutline,
		AccountGroupOutline, ShieldCrownOutline,
		MyWorkItemRow, MyWorkFilters,
	},
	emits: ['open-team', 'open-item', 'counts-changed'],

	data() {
		return {
			loading: false,
			busyItemId: null,
			filtersOpen: false,
			snoozeTarget: null,
			snoozeCustomValue: '',
			commentTarget: null,
			commentText: '',
			confirmTarget: null,
			confirmReason: '',
			// v4.6.17 — the expiring-team item whose extension form is open.
			extensionTarget: null,
			extensionDate: '',
			extensionReason: '',
			/** Group keys the user has expanded past SECTION_PREVIEW. */
			expandedGroups: [],
			_searchTimer: null,
		}
	},

	computed: {
		...mapState({ myWork: state => state.myWork }),

		iconInline() { return ICON_INLINE },
		iconBody() { return ICON_BODY },
		iconToolbar() { return ICON_TOOLBAR },
		iconHero() { return ICON_HERO },

		/**
		 * v4.6.17 — earliest date the extension picker accepts: the day after
		 * the team's current expiration. `TeamExpiryService::requestExtension`
		 * refuses anything at or before it, so the browser refusing first saves
		 * a round trip to be told so — the same rule `expiryMinProposal` applies
		 * to the form in Manage team.
		 *
		 * Parsed from the item's `expiresOn` (a plain `YYYY-MM-DD` string) via
		 * Date arithmetic rather than string surgery, so a month or year
		 * boundary is the platform's problem.
		 */
		extensionMinDate() {
			const on = this.extensionTarget?.metadata?.expiresOn
			if (!on) {
				return ''
			}
			return shiftIsoDate(on, { days: 1 })
		},

		filters() { return this.myWork.filters },
		groupBy() { return this.myWork.groupBy },
		sortBy() { return this.myWork.sortBy },
		page() { return this.myWork.page },
		compact() { return this.myWork.compact },
		collapsedGroups() { return this.myWork.collapsedGroups || [] },
		payload() { return this.myWork.payload },
		providers() { return this.myWork.providers },

		items() { return this.payload?.items || [] },
		counts() { return this.payload?.counts || {} },
		total() { return this.payload?.total || 0 },
		hasMore() { return !!this.payload?.hasMore },
		pageSize() { return this.payload?.limit || 50 },
		totalPages() { return Math.max(1, Math.ceil(this.total / this.pageSize)) },
		payloadTeams() { return this.payload?.teams || [] },

		/** id → translated provider name, for the source label on each row. */
		providerNames() {
			const map = {}
			this.providers.forEach(p => { map[p.id] = p.name })
			return map
		},

		/**
		 * The cards — see SUMMARY_CATEGORIES for which. Today is a **lens, not
		 * a bucket**: with the action-required lead time on, an item due today
		 * is correctly in Action required, so filtering the Today card by
		 * category would show an empty list next to a non-zero count. It
		 * filters by due date instead, which is what a user clicking "Today"
		 * actually means.
		 */
		summaryCards() {
			const breakdown = this.payload?.breakdown || {}
			// v4.5.31 — under the Today lens the Completed card counts today's
			// completions, matching the side panel beside it. Both read the
			// same server-narrowed number, so the card and the panel cannot
			// disagree about what "completed" currently means.
			const completedToday = this.payload?.highlights?.completedScope === 'today'

			return SUMMARY_CATEGORIES.map(category => ({
				key: category,
				tone: CATEGORY_TONES[category],
				icon: CATEGORY_ICONS[category],
				label: (completedToday && category === CATEGORY.COMPLETED)
					? t('teamhub', 'Completed today')
					: categoryLabel(category),
				count: (completedToday && category === CATEGORY.COMPLETED)
					? (this.payload?.highlights?.completedCount ?? 0)
					: (this.counts[category] || 0),
				breakdown: breakdown[category] || [],
				// A card at zero still needs a second line or the row of
				// cards loses its shared height and the grid jitters as
				// counts change.
				emptyHint: category === CATEGORY.COMPLETED
					? t('teamhub', 'Recently')
					: t('teamhub', 'Nothing'),
				active: category === CATEGORY.TODAY
					? this.filters.dueWindow === 'today'
					: this.filters.category === category,
			}))
		},

		sortChoices() { return sortOptions() },

		/**
		 * All + one tab per available source — a provider on its own, or a
		 * group (Files, Teams, Administration) standing in for its members.
		 *
		 * v4.9.17 — the clustering, and the rule that an *unavailable* source
		 * (app not installed, module off) gets no tab, live in
		 * `buildSourceTabs()` so they can be tested. A source that is merely
		 * empty still gets a tab, showing zero: "no file work waiting on me" is
		 * information, and a tab that vanishes when it empties makes the bar
		 * reflow every refresh. The Administration group is absent for a
		 * non-admin because the server does not list its providers to them.
		 */
		sourceTabs() {
			return buildSourceTabs(this.providers, this.payload?.sourceCounts || {}, this.filters.providerId)
		},

		/**
		 * The rail is derived from item categories, so it only makes sense
		 * while the page is grouped by category — see the template comment.
		 */
		showRail() {
			return this.groupBy === 'category'
		},

		/** Groups the main column renders: the ones that are a to-do list. */
		mainGroups() {
			if (!this.showRail) {
				return this.visibleGroups
			}
			return this.visibleGroups.filter(g => !RAIL_CATEGORIES.includes(g.key))
		},

		/**
		 * The rail's rows come from the server's `highlights`, not from the
		 * current page.
		 *
		 * They used to be filtered out of `items`, which meant selecting a
		 * summary card emptied every panel while the counts beside them kept
		 * reading the real number — "Nothing finished yet this week" printed
		 * directly under a 5. `highlights` is computed before the category
		 * filter is applied, exactly like the counts, so the two always agree.
		 */
		railPanels() {
			const h = this.payload?.highlights || {}
			const today = h.today || []
			const waiting = h[CATEGORY.WAITING_FOR_OTHERS] || []
			const done = h[CATEGORY.COMPLETED] || []
			// The server says which scope it narrowed Completed to, rather than
			// the view re-deriving it from the filter — they would then be two
			// answers to one question, and could disagree mid-refresh.
			const completedToday = h.completedScope === 'today'

			return [
				{
					key: 'today',
					label: t('teamhub', 'Upcoming today'),
					icon: CATEGORY_ICONS[CATEGORY.TODAY],
					count: this.counts[CATEGORY.TODAY] || today.length,
					items: today,
					empty: t('teamhub', 'Nothing due today.'),
					viewAll: () => this.applyFilterPatch({ dueWindow: 'today', category: '' }),
				},
				{
					key: CATEGORY.WAITING_FOR_OTHERS,
					label: categoryLabel(CATEGORY.WAITING_FOR_OTHERS),
					icon: CATEGORY_ICONS[CATEGORY.WAITING_FOR_OTHERS],
					count: this.counts[CATEGORY.WAITING_FOR_OTHERS] || waiting.length,
					items: waiting,
					empty: t('teamhub', 'You are not waiting on anyone.'),
					viewAll: () => this.applyFilterPatch({
						category: CATEGORY.WAITING_FOR_OTHERS, dueWindow: '',
					}),
				},
				// v4.5.28 — the Completed panel follows the Today lens. Its
				// label, its rows and its count all come from the same
				// narrowed set, so the heading can never disagree with the
				// number underneath it (the §2.74 rule, applied the other way
				// round this time).
				{
					key: CATEGORY.COMPLETED,
					label: completedToday
						? t('teamhub', 'Completed today')
						: t('teamhub', 'Completed this week'),
					icon: CATEGORY_ICONS[CATEGORY.COMPLETED],
					count: completedToday
						? (h.completedCount ?? done.length)
						: (this.counts[CATEGORY.COMPLETED] || done.length),
					items: done,
					empty: completedToday
						? t('teamhub', 'Nothing finished today.')
						: t('teamhub', 'Nothing finished yet this week.'),
					viewAll: () => this.applyFilterPatch({
						category: CATEGORY.COMPLETED,
						// Keep the lens when the panel is showing today's work
						// — otherwise "View all" widens to the week, which is
						// not what the panel just offered.
						dueWindow: completedToday ? 'today' : '',
					}),
				},
			]
		},

		filterButtonLabel() {
			const active = this.activeFilterCount
			return active
				? t('teamhub', 'Filters ({n})', { n: active })
				: t('teamhub', 'Filters')
		},

		activeFilterCount() {
			const f = this.filters
			return ['search', 'teamId', 'providerId', 'priority', 'status', 'resourceType', 'dueWindow', 'projectId', 'workType']
				.filter(k => !!f[k]).length + (f.showSnoozed ? 1 : 0)
		},

		/** v4.9.7 — the projects and work types the queue currently holds. */
		facetProjects() { return this.payload?.facets?.projects || [] },
		facetWorkTypes() { return this.payload?.facets?.workTypes || [] },

		/**
		 * v4.9.7 — the predefined views, with their on/off state and with the
		 * ones that need a provider this instance does not have dropped.
		 */
		views() {
			const registered = new Set(this.providers.filter(p => p.enabled !== false).map(p => p.id))
			return predefinedViews()
				.filter(v => !v.requiresProvider || registered.has(v.requiresProvider))
				.map(v => ({ ...v, active: isViewActive(v, this.filters, this.groupBy) }))
		},

		/**
		 * v4.9.7 — one notice per (source, warning code) from the providers
		 * that answered with a caveat: reconnect, partial, slow.
		 */
		providerWarnings() {
			const out = []
			for (const status of this.payload?.providerStatus || []) {
				for (const code of status.warnings || []) {
					const notice = providerWarning(code, status.name || status.id)
					if (notice) {
						out.push({ key: status.id + ':' + code, providerId: status.id, ...notice })
					}
				}
			}
			return out
		},

		/**
		 * v4.9.7 — when the queue was last computed. `generatedAt` is the
		 * server's clock at fetch time, and `cached` says whether this
		 * payload came from the server-side cache — a reader who sees an
		 * old number knows why before pressing Refresh.
		 */
		lastUpdatedLabel() {
			const at = this.payload?.generatedAt
			if (!at) {
				return ''
			}
			const time = formatTime(at * 1000, { hour: '2-digit', minute: '2-digit' })
			return this.payload?.cached
				? t('teamhub', 'Updated {time} (cached)', { time })
				: t('teamhub', 'Updated {time}', { time })
		},

		lastUpdatedTitle() {
			const at = this.payload?.generatedAt
			return at ? formatAbsolute(at) : ''
		},

		visibleGroups() {
			return (this.payload?.groups || []).filter(g => this.itemsForGroup(g).length > 0)
		},

		failedProviders() {
			return (this.payload?.providerStatus || [])
				.filter(p => p.state === 'error' || p.state === 'timeout')
		},

		truncatedProviders() { return this.payload?.truncated || [] },

		/**
		 * Not `n()`: the count that varies is the number of failed *sources*,
		 * and every language wants the source names inline rather than a
		 * pluralised noun.
		 */
		providerFailureMessage() {
			const sources = this.failedProviders.map(p => p.name).join(', ')
			return t('teamhub', '{sources} items could not be refreshed. All other results are up to date.', { sources })
		},

		hasActiveFilters() {
			return this.activeFilterCount > 0 || !!this.filters.category
		},

		/** The source told us its action needs free text before it will run. */
		confirmNeedsReason() {
			return !!this.confirmTarget?.item?.metadata?.requiresReason
		},

		/**
		 * v4.8.18 — the source offers free text but does not require it.
		 *
		 * The sibling of `requiresReason` rather than a second mechanism: a
		 * file reviewer may add a remark when they complete, and that remark is
		 * the only part of their reasoning that survives the review's Talk room
		 * being deleted at close. Optional, so the button stays enabled.
		 */
		confirmAllowsReason() {
			return !!this.confirmTarget?.item?.metadata?.allowsReason
		},

		confirmReasonLabel() {
			return this.confirmNeedsReason
				? t('teamhub', 'Reason')
				// TRANSLATORS: label of an optional free-text field shown when finishing a file review
				: t('teamhub', 'Remark (optional)')
		},

		/** Red button: the action destroys something or cannot be undone. */
		confirmIsDestructive() {
			return this.confirmTarget?.action === ACTION.REJECT
				|| this.confirmTarget?.action === ACTION.CLOSE
		},

		confirmTitle() {
			if (!this.confirmTarget) {
				return ''
			}
			const label = this.confirmTarget.item.subtitle || this.confirmTarget.item.title
			switch (this.confirmTarget.action) {
			case ACTION.REJECT:
				return t('teamhub', 'Reject “{title}”?', { title: label })
			case ACTION.CLOSE:
				return t('teamhub', 'Close the review of “{title}”?', { title: label })
			case ACTION.COMPLETE:
				return t('teamhub', 'Complete your review of “{title}”?', { title: label })
			default:
				return t('teamhub', 'Approve “{title}”?', { title: label })
			}
		},

		/**
		 * v4.8.18 — closing a file review says all three of its consequences
		 * out loud, because two of them are invisible and neither is
		 * reversible: the conversation is deleted, and anybody who had not
		 * answered loses the request from their own queue without having
		 * answered it.
		 */
		confirmBody() {
			if (!this.confirmTarget) {
				return ''
			}
			if (this.confirmTarget.action === ACTION.CLOSE) {
				const pending = (this.confirmTarget.item.metadata?.reviewers || [])
					.filter(r => !r.completedAt)
					.map(r => r.displayName)
				if (pending.length === 0) {
					return t('teamhub', 'Everyone has completed this review. The file’s chat is not affected.')
				}
				return t('teamhub', 'These people have not completed it yet: {names}. Closing removes the request from their My Work without them answering. The file’s chat is not affected.', { names: pending.join(', ') })
			}
			if (this.confirmTarget.action === ACTION.COMPLETE) {
				return t('teamhub', 'This tells the person who asked that you are done. Anything you add here is kept with the review and posted in the file’s chat.')
			}
			if (this.confirmNeedsReason) {
				return t('teamhub', 'This is recorded in the source application together with your reason, and cannot be undone from My Work.')
			}
			return t('teamhub', 'This is recorded in the source application and cannot be undone from My Work.')
		},

		emptyTitle() {
			return this.hasActiveFilters
				? t('teamhub', 'Nothing matches these filters')
				: t('teamhub', 'You’re all caught up')
		},

		emptyBody() {
			if (this.hasActiveFilters) {
				return t('teamhub', 'Try widening the filters, or clear them to see everything again.')
			}
			if (this.filters.category) {
				return categoryEmptyState(this.filters.category)
			}
			return t('teamhub', 'New tasks and approvals from your teams will appear here automatically.')
		},
	},

	async mounted() {
		// Render immediately from the cached payload when there is one — the
		// user is usually coming back from an item they just opened — then
		// always refresh behind it. v4.5.22: the refresh is unconditional.
		// Skipping it while the payload was "fresh" stacked a second cache on
		// top of the server's own, and a due-date change could take minutes to
		// surface.
		await Promise.all([this.loadProviders(), this.loadPreferences()])
		const stale = !this.payload || (Date.now() - this.myWork.loadedAt) > STALE_AFTER_MS
		if (stale) {
			await this.refresh(false)
		} else {
			this.refresh(false)
		}
		this.restoreScroll()
	},

	beforeUnmount() {
		if (this._searchTimer) {
			clearTimeout(this._searchTimer)
			this._searchTimer = null
		}
	},

	methods: {
		t,
		n,
		categoryLabel,
		breakdownLabel,
		actionLabel,

		groupIcon(key) {
			return CATEGORY_ICONS[key] || 'CalendarClock'
		},

		/** The glyph for a source, for the summary cards' breakdown chips. */
		providerIcon(providerId) {
			return PROVIDER_ICONS[providerId] || FALLBACK_ICON
		},

		/** The glyph of the tab this item opens in — same map the rows use. */
		resourceIcon(item) {
			return RESOURCE_TYPE_ICONS[item.resourceType]
				|| PROVIDER_ICONS[item.providerId]
				|| FALLBACK_ICON
		},

		/**
		 * A rail row is half the width of a main row, so its second line gets
		 * one fact rather than three: which team, because that is what
		 * distinguishes two similarly-named items across teams.
		 */
		panelSubtitle(item) {
			return item.teamName || item.subtitle || ''
		},

		/**
		 * The right-hand meta on a rail row. Only the Today panel has one — a
		 * time is what you want to know about something happening today, and
		 * the other two panels have nothing equally useful to put there.
		 */
		panelMeta(panel, item) {
			if (panel.key !== 'today' || !item.dueAt) {
				return ''
			}
			return formatTime(item.dueAt * 1000, {
				hour: '2-digit',
				minute: '2-digit',
			})
		},

		/**
		 * Open a navigation action's URL in a new tab.
		 *
		 * The URL comes from the item's own metadata, which the server built —
		 * but it is still checked against an allow-list of schemes before being
		 * handed to the browser, because "a URL we put there ourselves" is
		 * exactly the assumption that makes a `javascript:` link work one day.
		 *
		 * v4.6.16 — `mailto:` joins http(s) for the Email owner action, and it
		 * cannot go through `window.open`: a handler-less browser leaves the
		 * blank tab it just opened sitting there. Assigning `location.href`
		 * hands the URL to the OS (or to whichever web client is registered)
		 * and leaves the page alone if nothing takes it. Every other scheme is
		 * still refused.
		 */
		openNavigationAction(item, action) {
			const url = item.metadata?.[NAVIGATION_ACTIONS[action]]
			if (!url) {
				return
			}
			let parsed
			try {
				parsed = new URL(url, window.location.origin)
			} catch (e) {
				return
			}
			if (parsed.protocol === 'mailto:') {
				window.location.href = parsed.href
				return
			}
			if (parsed.protocol !== 'https:' && parsed.protocol !== 'http:') {
				return
			}
			window.open(parsed.href, '_blank', 'noopener,noreferrer')
		},

		selectSource(providerId) {
			if (this.filters.providerId === providerId) {
				return
			}
			this.applyFilterPatch({ providerId })
		},

		onSortBy(value) {
			if (value === this.sortBy) {
				return
			}
			this.$store.commit('SET_MYWORK_SORT_BY', value)
			this.$store.commit('SET_MYWORK_PAGE', 1)
			this.expandedGroups = []
			this.persistPreferences()
			this.refresh(false)
		},

		groupTone(key) {
			return CATEGORY_TONES[key] || 'upcoming'
		},

		itemsForGroup(group) {
			const ids = new Set(group.itemIds || [])
			return this.items.filter(i => ids.has(i.id))
		},

		/** The preview slice, unless the user expanded this section. */
		visibleItemsForGroup(group) {
			const all = this.itemsForGroup(group)
			return this.expandedGroups.includes(group.key) ? all : all.slice(0, SECTION_PREVIEW)
		},

		hiddenCount(group) {
			return this.itemsForGroup(group).length - this.visibleItemsForGroup(group).length
		},

		expandGroup(key) {
			if (!this.expandedGroups.includes(key)) {
				this.expandedGroups = [...this.expandedGroups, key]
			}
		},

		/**
		 * v4.5.39 — every section in the main column collapses to its header.
		 *
		 * Replaces the single `completedExpanded` boolean, which could only
		 * ever speak for one category and in practice spoke for none: under
		 * category grouping Completed lives in the rail, so the one collapsible
		 * section was not on screen. State is a list of *collapsed* keys so the
		 * default — an empty list — is everything open; a list of expanded keys
		 * would have made a fresh user's queue arrive folded shut.
		 *
		 * The keys are whatever `groupBy` is currently producing (categories,
		 * team ids, priorities). Collapsing a team and then switching to
		 * category grouping leaves a key nothing matches, which costs nothing
		 * and means going back finds it as you left it.
		 */
		isExpanded(key) {
			return !this.collapsedGroups.includes(key)
		},

		toggleGroup(key) {
			const next = this.isExpanded(key)
				? [...this.collapsedGroups, key]
				: this.collapsedGroups.filter(k => k !== key)
			this.$store.commit('SET_MYWORK_COLLAPSED_GROUPS', next)
			this.persistPreferences()
		},

		setCompact(value) {
			this.$store.commit('SET_MYWORK_COMPACT', value)
			this.persistPreferences()
		},

		// ── Loading ──────────────────────────────────────────────────────

		async loadProviders() {
			try {
				const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/mywork/providers'))
				this.$store.commit('SET_MYWORK_PROVIDERS', data?.providers || [])
			} catch (e) {
				// The filter bar degrades to team/priority/date only. Not worth
				// a toast — the queue itself still loads.
				this.$store.commit('SET_MYWORK_PROVIDERS', [])
			}
		},

		/**
		 * Restore the view state the server has been holding (v4.5.25).
		 *
		 * `persistPreferences()` has written this since 4.5.21 and nothing ever
		 * read it back, so "your choices follow you to another browser" was
		 * only ever half true — the write half. Loaded once on mount, before
		 * the first fetch, so the queue is requested with the user's own
		 * grouping and sort rather than fetched twice.
		 *
		 * Only applied when the store is still at its defaults: coming back
		 * from an item the user just opened, the in-memory state is what they
		 * were last looking at and the server's copy may be a step behind.
		 */
		async loadPreferences() {
			if (this.payload) {
				return
			}
			try {
				const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/mywork/preferences'))
				if (!data) {
					return
				}
				if (data.groupBy) this.$store.commit('SET_MYWORK_GROUP_BY', data.groupBy)
				if (data.sortBy) this.$store.commit('SET_MYWORK_SORT_BY', data.sortBy)
				this.$store.commit('SET_MYWORK_COMPACT', !!data.compact)
				// A pre-4.5.39 server sends `completedExpanded` and no
				// `collapsedGroups`. Nothing is migrated: the old key was one
				// boolean about one section, the new one a set of section keys,
				// so carrying a stored `false` across would arrive meaning
				// "everything collapsed" — the 4.5.29 `mentionsOnly` lesson.
				// Absent means the default, which is everything open.
				if (Array.isArray(data.collapsedGroups)) {
					this.$store.commit('SET_MYWORK_COLLAPSED_GROUPS', data.collapsedGroups)
				}
				if (data.filters && typeof data.filters === 'object') {
					const filters = { ...data.filters }
					// v4.9.17 — a preference saved before the source groups
					// existed may hold a member (`approval`) where the bar and
					// the dropdown now speak in groups (`files`). The server
					// would honour either; the controls only agree with the
					// rows if they are told the group.
					const group = sourceGroupOf(filters.providerId || '')
					if (group) {
						filters.providerId = group
					}
					this.$store.commit('SET_MYWORK_FILTERS', filters)
				}
			} catch (e) {
				// Defaults are a perfectly good starting point; a failed
				// preference read must never keep the queue off the screen.
			}
		},

		/**
		 * @param {boolean} userInitiated true for the Refresh button — bypasses
		 *   the server-side cache and surfaces failures as a toast.
		 */
		async refresh(userInitiated = true) {
			// v4.5.27 — a request arriving while one is in flight is **queued,
			// not dropped**.
			//
			// This used to `return` outright, so clicking a summary card while
			// the previous fetch was still running committed the new filter and
			// then never fetched for it: the state said Completed and the rows
			// said something else, and it took a second click to catch up.
			// Justin hit it on Completed, which is the slowest category to
			// fetch and therefore the easiest to click through.
			if (this.loading) {
				this._refreshQueued = true
				this._refreshQueuedUserInitiated = this._refreshQueuedUserInitiated || userInitiated
				return
			}
			this.loading = true
			try {
				const params = this.queryParams()
				if (userInitiated) {
					params.nocache = 1
				}
				const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/mywork'), { params })
				this.$store.commit('SET_MYWORK_PAYLOAD', data)

				// v4.5.26 — keep the sidebar badge honest.
				//
				// It used to refresh only when the user *left* My Work, so
				// completing something while staying on the page left a stale
				// number until a reload. Every payload already carries the
				// counts, so this costs nothing — no second request.
				this.$emit('counts-changed', Number(data?.counts?.action_required) || 0)

				// The requested page can fall off the end when work is
				// completed between fetches — clamp and refetch once.
				if (this.page > this.totalPages) {
					this.$store.commit('SET_MYWORK_PAGE', this.totalPages)
					this.loading = false
					await this.refresh(false)
					return
				}
			} catch (e) {
				if (e?.response?.status === 403 && e?.response?.data?.licenseGate) {
					showError(t('teamhub', 'My Work requires an active TeamHub license.'))
				} else if (userInitiated) {
					showError(t('teamhub', 'Failed to load My Work'))
				}
			} finally {
				this.loading = false
			}

			// Drain the queue: something changed the filters while that request
			// was in the air, so fetch once more for whatever the state is now.
			// One replay, not a loop — the queue is a flag, so any number of
			// clicks during the fetch collapse into a single follow-up.
			if (this._refreshQueued) {
				this._refreshQueued = false
				const wasUserInitiated = this._refreshQueuedUserInitiated
				this._refreshQueuedUserInitiated = false
				await this.refresh(wasUserInitiated)
			}
		},

		queryParams() {
			const f = this.filters
			const params = {
				groupBy: this.groupBy,
				sortBy: this.sortBy,
				limit: this.pageSize,
				offset: (this.page - 1) * this.pageSize,
				includeSnoozed: f.showSnoozed ? 1 : 0,
			}
			if (f.search) params.search = f.search
			if (f.teamId) params.teamIds = f.teamId
			if (f.providerId) params.providerIds = f.providerId
			if (f.priority) params.priorities = f.priority
			if (f.status) params.statuses = f.status
			if (f.resourceType) params.resourceTypes = f.resourceType
			if (f.category) params.categories = f.category
			// v4.9.7 — source-specific narrowing.
			if (f.projectId) params.projectIds = f.projectId
			if (f.workType) params.workTypes = f.workType

			// The due-window presets become explicit bounds server-side, so the
			// backend never has to know what "this week" means.
			const now = Math.floor(Date.now() / 1000)
			const startOfToday = Math.floor(new Date().setHours(0, 0, 0, 0) / 1000)
			const endOfToday = startOfToday + 86400
			switch (f.dueWindow) {
			case 'overdue':
				params.dueTo = startOfToday - 1
				break
			case 'today':
				// v4.5.31 — **no lower bound.** Today is "what do I have to
				// deal with today", and something that was due last Tuesday is
				// squarely that; a window that started at midnight hid every
				// overdue item behind a filter called Today. The upper bound
				// still ends the day, so nothing from tomorrow leaks in.
				params.dueTo = endOfToday - 1
				// v4.5.30 — the *name* of the preset, and the day it means.
				// The side rail and the Completed card narrow to today when
				// this lens is on, and they cannot infer the day from
				// dueFrom/dueTo: 'overdue' and 'week' set those too, and the
				// server's own midnight is not the user's.
				params.todayLens = 1
				params.todayFrom = startOfToday
				params.todayTo = endOfToday - 1
				break
			case 'week':
				params.dueFrom = startOfToday
				params.dueTo = now + (7 * 86400)
				break
			case 'month':
				params.dueFrom = startOfToday
				params.dueTo = now + (30 * 86400)
				break
			}

			return params
		},

		// ── Filters ──────────────────────────────────────────────────────

		onFilter(key, value) {
			this.$store.commit('SET_MYWORK_FILTERS', { [key]: value })
			this.$store.commit('SET_MYWORK_PAGE', 1)
			this.expandedGroups = []
			this.persistPreferences()

			if (key === 'search') {
				if (this._searchTimer) clearTimeout(this._searchTimer)
				this._searchTimer = setTimeout(() => this.refresh(false), SEARCH_DEBOUNCE_MS)
				return
			}
			this.refresh(false)
		},

		onGroupBy(value) {
			this.$store.commit('SET_MYWORK_GROUP_BY', value)
			this.$store.commit('SET_MYWORK_PAGE', 1)
			this.expandedGroups = []
			this.persistPreferences()
			this.refresh(false)
		},

		/**
		 * Today filters by due date; every other card filters by category.
		 *
		 * The two live on different filter keys, so selecting one card has to
		 * explicitly clear the other dimension — otherwise picking Upcoming
		 * after Today left both highlighted and intersected, which is what
		 * Justin hit ("Today stays active, I need to click it again").
		 * Both keys move in a single commit so only one refetch fires.
		 */
		toggleSummary(card) {
			const turningOff = card.active

			if (card.key === CATEGORY.TODAY) {
				this.applyFilterPatch({
					dueWindow: turningOff ? '' : 'today',
					category: '',
				})
				return
			}

			this.applyFilterPatch({
				category: turningOff ? '' : card.key,
				dueWindow: '',
			})
		},

		/**
		 * v4.9.7 — a predefined view. A filter view replaces the filters any
		 * other filter view set (`viewFilterPatch` — Justin's review,
		 * 2026-09-14: *Needs attention* left on under *OpenProject* hid the
		 * work packages); a grouping view only changes the grouping. Clicking
		 * an active view turns its own filters off and leaves the grouping,
		 * which is a preference, alone.
		 */
		applyView(view) {
			if (view.groupBy && !view.active && view.groupBy !== this.groupBy) {
				this.$store.commit('SET_MYWORK_GROUP_BY', view.groupBy)
			}
			const patch = viewFilterPatch(view, view.active)
			if (Object.keys(patch).length || (view.groupBy && !view.active)) {
				this.applyFilterPatch(patch)
			}
		},


		/**
		 * v4.9.7 — where the source's account is (re)connected. OpenProject
		 * has its own personal settings section; any other source lands on
		 * the personal settings index.
		 */
		openPersonalSettings(providerId) {
			window.location.href = providerId === 'openproject'
				? personalSettingsUrl()
				: generateUrl('/settings/user')
		},

		/** Commit several filter keys at once, then refetch once. */
		applyFilterPatch(patch) {
			this.$store.commit('SET_MYWORK_FILTERS', patch)
			this.$store.commit('SET_MYWORK_PAGE', 1)
			this.expandedGroups = []
			this.persistPreferences()
			this.refresh(false)
		},

		resetFilters() {
			this.$store.commit('RESET_MYWORK_FILTERS')
			this.$store.commit('SET_MYWORK_PAGE', 1)
			this.expandedGroups = []
			this.persistPreferences()
			this.refresh(false)
		},

		goToPage(target) {
			const clamped = Math.max(1, Math.min(target, this.totalPages))
			if (clamped === this.page) {
				return
			}
			this.$store.commit('SET_MYWORK_PAGE', clamped)
			this.refresh(false)
			this.scrollToTop()
		},

		/**
		 * Mirror the view state to the server so it follows the user to
		 * another browser. Fire-and-forget: a failed preference write must
		 * never interrupt filtering.
		 */
		persistPreferences() {
			axios.put(generateUrl('/apps/teamhub/api/v1/mywork/preferences'), {
				groupBy: this.groupBy,
				sortBy: this.sortBy,
				showSnoozed: this.filters.showSnoozed,
				collapsedGroups: this.collapsedGroups,
				compact: this.compact,
				filters: this.filters,
			}).catch(() => {})
		},

		// ── Scroll position ──────────────────────────────────────────────

		rememberScroll(event) {
			this.$store.commit('SET_MYWORK_SCROLL', event.target.scrollTop || 0)
		},

		restoreScroll() {
			this.$nextTick(() => {
				const el = this.$refs.scroller
				if (el && this.myWork.scrollTop) {
					el.scrollTop = this.myWork.scrollTop
				}
			})
		},

		scrollToTop() {
			const el = this.$refs.scroller
			if (el) el.scrollTop = 0
			this.$store.commit('SET_MYWORK_SCROLL', 0)
		},

		// ── Opening ──────────────────────────────────────────────────────

		openItem(item) { this.$emit('open-item', item) },
		openTeam(item) { this.$emit('open-team', item.teamId) },

		// ── Actions ──────────────────────────────────────────────────────

		onAction({ item, action }) {
			if (action === ACTION.OPEN) {
				this.openItem(item)
				return
			}
			// v4.6.17 — needs a form first, and the form opens here. Until this
			// version the same action followed the item's openTarget and landed
			// the reader in Manage team → Maintenance, which answered "ask for
			// more time" by throwing them out of the queue that asked.
			if (FORM_ACTIONS.includes(action)) {
				this.extensionTarget = item
				// Prefill nothing: any default we picked would be a length of
				// extension we invented on the requester's behalf, and the
				// administrator reads this date as what they actually need.
				this.extensionDate = ''
				this.extensionReason = ''
				return
			}
			// v4.5.25 — navigation actions never reach the server: the item
			// carries the URL and this opens it. Justin's finding was that a
			// meeting sat in Action required with nothing to click; Join call
			// and Open agenda are the two things you actually do with one
			// before it starts.
			if (NAVIGATION_ACTIONS[action]) {
				this.openNavigationAction(item, action)
				return
			}
			if (action === ACTION.COMMENT) {
				this.commentTarget = item
				this.commentText = ''
				return
			}
			// Confirm when the action is destructive, and also whenever the
			// source needs a rationale — approving a decision without one is
			// rejected server-side, so prompting is the only way it can work.
			//
			// v4.8.18 — `allowsReason` joins them. It is not a confirmation so
			// much as somewhere to type: a file reviewer's optional remark is
			// the only part of their reasoning that outlives the review's Talk
			// room, and the row has nowhere to put a text field.
			if (DESTRUCTIVE_ACTIONS.includes(action)
				|| item.metadata?.requiresReason
				|| item.metadata?.allowsReason) {
				this.confirmTarget = { item, action }
				this.confirmReason = ''
				return
			}
			this.execute(item, action, {})
		},

		confirmDestructive() {
			const { item, action } = this.confirmTarget
			const reason = this.confirmReason.trim()
			this.confirmTarget = null
			this.confirmReason = ''
			this.execute(item, action, reason ? { reason } : {})
		},

		/**
		 * v4.6.17 — submit the extension request and stay put. `execute` reports
		 * the server's own message and refreshes, so the row comes back as
		 * "Extension requested" without a navigation.
		 */
		confirmExtension() {
			const item = this.extensionTarget
			const params = {
				proposedOn: this.extensionDate,
				reason: this.extensionReason.trim(),
			}
			this.extensionTarget = null
			this.extensionDate = ''
			this.extensionReason = ''
			this.execute(item, ACTION.REQUEST_EXTENSION, params)
		},

		onSnooze({ item, preset }) {
			if (preset === 'custom') {
				this.snoozeTarget = item
				this.snoozeCustomValue = ''
				return
			}
			// v4.5.24 — resolved here rather than server-side: "Tomorrow 09:00"
			// means the user's tomorrow morning, and the browser is the only
			// party that knows which one that is. Sent as a custom snooze,
			// which the stored absolute timestamp already supported — hence no
			// migration. An unknown key still goes to the server's own preset
			// handling rather than failing.
			const until = resolveSnoozePreset(preset)
			this.execute(item, ACTION.SNOOZE, until === null ? { preset } : { preset: 'custom', until })
		},

		confirmCustomSnooze() {
			const item = this.snoozeTarget
			// `datetime-local` gives a local wall-clock string with no zone;
			// Date parses it in the browser's zone, which is what the user meant.
			const until = Math.floor(new Date(this.snoozeCustomValue).getTime() / 1000)
			this.snoozeTarget = null
			if (!until || Number.isNaN(until)) {
				showError(t('teamhub', 'That is not a valid date and time.'))
				return
			}
			this.execute(item, ACTION.SNOOZE, { preset: 'custom', until })
		},

		confirmComment() {
			const item = this.commentTarget
			const message = this.commentText.trim()
			this.commentTarget = null
			this.execute(item, ACTION.COMMENT, { message })
		},

		async execute(item, action, params) {
			if (this.busyItemId) {
				return
			}
			this.busyItemId = item.id
			try {
				const { data } = await axios.post(generateUrl('/apps/teamhub/api/v1/mywork/action'), {
					providerId: item.providerId,
					itemId: item.providerItemId,
					action,
					params,
				})
				if (data?.message) {
					showSuccess(data.message)
				}
				// Acting invalidates this user's cache server-side, but ask for
				// a fresh read anyway — the row that just changed is the one
				// the user is looking at.
				await this.refresh(true)
			} catch (e) {
				// The server sends a translated, specific message for every
				// refusal — surface it rather than replacing it with a generic
				// failure, because "someone else already completed this card"
				// is the useful sentence.
				const msg = e?.response?.data?.message || e?.response?.data?.error
				showError(msg || t('teamhub', 'That action could not be completed.'))
				if ([404, 409].includes(e?.response?.status)) {
					await this.refresh(false)
				}
			} finally {
				this.busyItemId = null
			}
		},
	},
}
</script>

<style scoped lang="scss">
.mywork {
	display: flex;
	flex-direction: column;
	gap: 14px;
	/* Left padding clears NC's sidebar-toggle button AND gives the canvas
	   room to breathe against the sidebar — everything below the header
	   shares this edge, so the whole view lines up on one axis. */
	padding: 18px 28px 28px 48px;
	height: 100%;
	overflow-y: auto;
	/* v4.5.27 — the same canvas a team page uses (.teamhub-home-view in
	   TeamWidgetGrid). The cards and section panels were already
	   --color-main-background with a border, so they had been sitting on a
	   background of exactly their own colour and the borders were doing all
	   the work. On grey they read as cards, and the three pages in the
	   sidebar look like one product. */
	background: var(--color-background-dark);
}

/* ── Header ─────────────────────────────────────────────────────────── */

.mywork__header {
	display: flex;
	align-items: flex-start;
	gap: 16px;
	flex-wrap: wrap;
}

.mywork__header-text {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1 1 auto;
	min-width: 0;
}

.mywork__title {
	margin: 0;
	font-size: var(--th-font-heading-lg, 20px);
	font-weight: var(--th-font-weight-bold, 700);
	line-height: var(--th-line-height-tight, 1.2);
}

.mywork__subtitle {
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 12px);
	line-height: var(--th-line-height-body, 1.4);
}

.mywork__header-actions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex: 0 0 auto;
}

/* Segmented density pair — raw buttons with aria-pressed, the SKILLS.md
   carve-out for a toggle group. */
.mywork__density {
	display: inline-flex;
	border: 1px solid var(--color-border);
	border-radius: var(--th-radius-control, var(--border-radius));
	overflow: hidden;
}

.mywork__density-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 34px;
	height: 34px;
	min-width: 34px;
	min-height: 34px;
	padding: 0;
	border: none;
	background: var(--color-main-background);
	color: var(--color-text-maxcontrast);
	cursor: pointer;

	&:hover { background: var(--color-background-hover); }
	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: -2px;
	}
}

.mywork__density-btn--on {
	background: var(--color-primary-element-light);
	color: var(--color-main-text);
}

/* Visually hidden but read by screen readers — the icons alone are not
   a name, and the title attribute is not reliably announced. */
.mywork__sr {
	position: absolute;
	width: 1px;
	height: 1px;
	padding: 0;
	margin: -1px;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	white-space: nowrap;
	border: 0;
}

/* ── Notices ────────────────────────────────────────────────────────── */

.mywork__notice {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	border-radius: var(--th-radius-control, var(--border-radius));
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	font-size: var(--th-font-meta, 12px);
	color: var(--color-main-text);
}

.mywork__notice--info { color: var(--color-text-maxcontrast); }

/* ── Summary cards ──────────────────────────────────────────────────── */

/* Four cards since v4.5.39 — Waiting for others moved to the rail alone. */
.mywork__summary {
	display: grid;
	grid-template-columns: repeat(4, minmax(0, 1fr));
	gap: 10px;

	@media (max-width: 1100px) { grid-template-columns: repeat(2, minmax(0, 1fr)); }
	@media (max-width: 700px)  { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

/* Raw <button>: a custom card with bespoke chrome — the documented
   carve-out in SKILLS.md § "NcButton is the default".

   v4.5.39 — a two-column grid rather than a column stack. Left column holds
   the name over its source chips; right column holds the count alone, centred
   against both rows. The hairline spans the full width underneath. */
.mywork__card {
	display: grid;
	grid-template-columns: minmax(0, 1fr) auto;
	grid-template-rows: auto auto auto;
	align-items: center;
	column-gap: 10px;
	/* v4.5.39 — ~30% taller again on top of the first pass, per Justin: 14 →
	   28px vertical padding takes the card from ~91px to ~119px. The height is
	   all padding and row gap, never type size, so the count and the heading
	   keep the sizes already agreed and simply get room to breathe. */
	row-gap: 6px;
	padding: 28px 16px;
	cursor: pointer;
	text-align: left;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--th-radius-card, var(--border-radius-large));
	color: var(--color-main-text);

	&:hover { background: var(--color-background-hover); }
	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
	}
}

/* Selected state is a light tint plus a stronger border, not a saturated
   fill: these are multi-select filter tiles, which is the case where the
   light tint is the house rule. */
.mywork__card--active {
	background: var(--color-primary-element-light);
	border-color: var(--color-primary-element);
}

.mywork__card-head {
	grid-column: 1;
	grid-row: 1;
	display: flex;
	align-items: center;
	gap: 7px;
	min-width: 0;
	width: 100%;
}

/* v4.5.39 — 24 → 28px, so the chip still balances a heading-sized label on a
   card that grew by a fifth. */
.mywork__card-chip {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: 0 0 auto;
	width: 28px;
	height: 28px;
	border-radius: 50%;
	/* Six locks so NC's global 44px button min-* can't stretch this into
	   an oval (SKILLS.md § UI shapes). */
	min-width: 28px;
	min-height: 28px;
	max-width: 28px;
	max-height: 28px;
	padding: 0;
	box-sizing: border-box;
}

/* v4.5.39 — a heading, at heading size and in the app's heading colour, like
   .teamhub-widget-title and .mywork__group-title. It was 12px semibold black,
   which read as a caption on a card whose whole job is to be a heading with a
   number beside it. */
.mywork__card-label {
	flex: 1 1 auto;
	min-width: 0;
	font-size: var(--th-font-heading, 16px);
	font-weight: var(--th-font-weight-semibold, 600);
	line-height: var(--th-line-height-tight, 1.2);
	color: var(--color-primary-element);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* The number, alone in the right-hand column and spanning both text rows so it
   is centred against them rather than pinned to the first line. */
.mywork__card-count {
	grid-column: 2;
	grid-row: 1 / span 2;
	align-self: center;
	justify-self: end;
	padding-left: 6px;
	font-size: var(--th-font-display, 30px);
	font-weight: var(--th-font-weight-bold, 700);
	line-height: var(--th-line-height-tight, 1.2);
	font-variant-numeric: tabular-nums;
}

/* One line per source. The cards live in a grid row, so they already share
   a height — but give the block a floor so a card with one source and a card
   with three still look like siblings. */
/* v4.5.25 — a row of source chips, not a stack of source sentences. The
   min-height keeps every card the same height whether it has three sources or
   none, so the grid does not jitter as counts change. */
/* v4.5.39 — a step larger (11 → 12px, ICON_INLINE → ICON_BODY glyphs) and a
   step lighter. Justin's brief: it should be readable without asking for
   attention, so size buys legibility and the opacity gives the attention back
   to the count. --color-text-maxcontrast is already NC's muted text token and
   there is no lighter one, so the last step is opacity rather than a colour
   that would stop tracking the theme. */
.mywork__card-lines {
	grid-column: 1;
	grid-row: 2;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px 10px;
	width: 100%;
	min-height: 1.6em;
	font-size: var(--th-font-meta, 12px);
	color: var(--color-text-maxcontrast);
	opacity: 0.8;
}

.mywork__card-source {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

.mywork__card-line {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.mywork__card-line--empty { opacity: 0.6; }

/* v4.5.25 — the glyphs are neutral, at Justin's request. They used to carry a
   per-category tint; a row of five differently-coloured chips reads as five
   competing signals before the eye reaches a single number, and the numbers
   are the point of the cards.

   The categories are still distinguishable: each card keeps its icon and its
   name in words, and the section it opens keeps a coloured accent bar. Colour
   is never the only signal here, and now it is not the loudest one either. */
.mywork__card-chip {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

/* A hairline in the category's colour along the bottom of the card. Enough to
   tie a card to its section, too little to compete with the count. */
.mywork__card::after {
	content: '';
	/* Row 3, spanning both columns, so it stays a full-width rule under the
	   text *and* the count now that the card is a grid (v4.5.39). */
	grid-column: 1 / -1;
	grid-row: 3;
	display: block;
	width: 100%;
	height: 2px;
	margin-top: 8px;
	border-radius: 2px;
	background: var(--th-mywork-accent, var(--color-border));
}

/* `--waiting` has had no card to colour since v4.5.39. Kept so the set stays
   one-to-one with CATEGORY_TONES — a gap here would read as an omission, and
   it is what the rule needs back if the card ever returns. */
.mywork__card--action   { --th-mywork-accent: var(--th-mywork-action-accent); }
.mywork__card--today    { --th-mywork-accent: var(--th-mywork-today-accent); }
.mywork__card--upcoming { --th-mywork-accent: var(--th-mywork-upcoming-accent); }
.mywork__card--waiting  { --th-mywork-accent: var(--th-mywork-waiting-accent); }
.mywork__card--admin    { --th-mywork-accent: var(--th-mywork-admin-accent); }
.mywork__card--done     { --th-mywork-accent: var(--th-mywork-done-accent); }

/* ── Source tabs + sort ─────────────────────────────────────────────── */

/* v4.9.7 — the predefined views: the same pill as a source tab, one step
   quieter (no icon), with the last-updated stamp pushed to the far end. */
.mywork__views {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-wrap: wrap;
	min-width: 0;
}

.mywork__views-label {
	font-size: var(--th-font-micro, 11px);
	font-weight: var(--th-font-weight-semibold, 600);
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
	margin-inline-end: 4px;
}

/* Raw <button>: aria-pressed toggle group, the SKILLS.md carve-out the
   source tabs below already use. */
.mywork__view {
	display: inline-flex;
	align-items: center;
	padding: 5px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--th-radius-pill, 999px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: var(--th-font-meta, 12px);
	cursor: pointer;
	white-space: nowrap;

	&:hover { background: var(--color-background-hover); }

	&:focus-visible {
		background: var(--color-background-hover);
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
	}
}

.mywork__view--on {
	background: var(--color-primary-element-light);
	border-color: var(--color-primary-element);
	font-weight: var(--th-font-weight-semibold, 600);
}

.mywork__updated {
	margin-inline-start: auto;
	font-size: var(--th-font-micro, 11px);
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.mywork__toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
}

.mywork__sources {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-wrap: wrap;
	min-width: 0;
}

/* Raw <button>: a segmented toggle group using aria-pressed — the documented
   carve-out in SKILLS.md § "NcButton is the default". NcButton's 44px minimum
   would make a six-source bar taller than the summary cards above it. */
.mywork__source {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 6px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--th-radius-pill, 999px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: var(--th-font-meta, 12px);
	cursor: pointer;
	white-space: nowrap;

	&:hover { background: var(--color-background-hover); }

	/* Split from :hover on purpose — grouping them silences the keyboard
	   focus ring, which is the trap SKILLS.md § Focus visibility names. */
	&:focus-visible {
		background: var(--color-background-hover);
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
	}
}

.mywork__source--on {
	background: var(--color-primary-element-light);
	border-color: var(--color-primary-element);
	font-weight: var(--th-font-weight-semibold, 600);
}

/* Neutral, like every other glyph on this page. */
.mywork__source-icon { color: var(--color-text-maxcontrast); }
.mywork__source--on .mywork__source-icon { color: inherit; }

.mywork__source-count {
	font-variant-numeric: tabular-nums;
	color: var(--color-text-maxcontrast);
}

.mywork__source--on .mywork__source-count { color: inherit; }

.mywork__sort {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	font-size: var(--th-font-meta, 12px);
	white-space: nowrap;
}

.mywork__sort-label { color: var(--color-text-maxcontrast); }

.mywork__sort-select {
	min-height: 34px;
	padding: 4px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--th-radius-control, var(--border-radius));
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: var(--th-font-meta, 12px);

	/* The NC form-field pattern: no outline, a primary border on focus.
	   Documented as acceptable in SKILLS.md § Focus visibility. */
	&:focus {
		outline: none;
		border-color: var(--color-primary-element);
	}
	&:focus-visible {
		box-shadow: 0 0 0 2px var(--color-primary-element);
	}
}

/* ── Two-column layout ──────────────────────────────────────────────── */

.mywork__layout {
	display: grid;
	grid-template-columns: minmax(0, 1fr) 300px;
	align-items: start;
	gap: 16px;

	/* Below this the rail would squeeze the five-column rows into
	   unreadability, and the rail's own rows are the less important of the
	   two — so it goes under the queue rather than beside it. */
	@media (max-width: 1280px) {
		grid-template-columns: minmax(0, 1fr);
	}
}

.mywork__layout--railless { grid-template-columns: minmax(0, 1fr); }

.mywork__rail {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-width: 0;
}

.mywork__panel {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--th-radius-card, var(--border-radius-large));
	padding: 12px 14px;
}

/* v4.5.27 — a rail panel's header is a header, so it reads like every other
   one on the page and on a team page: primary colour, same weight. */
.mywork__panel-head {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0 0 8px;
	font-size: var(--th-font-heading, 16px);
	font-weight: var(--th-font-weight-semibold, 600);
	color: var(--color-primary-element);
}

/* No chip behind it. The circle was doing the work of separating a
   maxcontrast glyph from the text; a primary-coloured glyph next to a
   primary-coloured title needs no help, and the chip only added weight. The
   six size locks stay — without them NC's global 44px button min-* would
   still stretch the box (SKILLS.md § UI shapes). */
.mywork__panel-icon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: 0 0 auto;
	box-sizing: border-box;
	width: 24px;
	height: 24px;
	min-width: 24px;
	min-height: 24px;
	max-width: 24px;
	max-height: 24px;
	padding: 0;
	background: none;
	color: var(--color-primary-element);
}

.mywork__panel-title {
	flex: 1 1 auto;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.mywork__panel-count {
	flex: 0 0 auto;
	font-size: var(--th-font-micro, 11px);
	font-variant-numeric: tabular-nums;
	color: var(--color-text-maxcontrast);
}

.mywork__panel-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.mywork__panel-row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 5px 4px;
	border-radius: var(--th-radius-control, var(--border-radius));
	min-width: 0;

	&:hover { background: var(--color-background-hover); }
}

.mywork__panel-glyph {
	flex: 0 0 auto;
	display: inline-flex;
	color: var(--color-text-maxcontrast);
}

/* Raw <button>: a full-width card-row list item — the documented carve-out in
   SKILLS.md § "NcButton is the default". */
.mywork__panel-text {
	flex: 1 1 auto;
	min-width: 0;
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 1px;
	background: none;
	border: none;
	padding: 0;
	cursor: pointer;
	text-align: left;
	color: inherit;
	font: inherit;

	&:hover .mywork__panel-item-title { text-decoration: underline; }
	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
		border-radius: 2px;
	}
}

.mywork__panel-item-title,
.mywork__panel-item-sub {
	max-width: 100%;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.mywork__panel-item-title {
	font-size: var(--th-font-meta, 12px);
	font-weight: var(--th-font-weight-medium, 500);
}

.mywork__panel-item-sub {
	font-size: var(--th-font-micro, 11px);
	color: var(--color-text-maxcontrast);
}

.mywork__panel-meta {
	flex: 0 0 auto;
	font-size: var(--th-font-micro, 11px);
	font-variant-numeric: tabular-nums;
	color: var(--color-text-maxcontrast);
}

.mywork__panel-empty {
	margin: 0;
	font-size: var(--th-font-micro, 11px);
	color: var(--color-text-maxcontrast);
}

/* Raw <button>: an inline text link-button, not a control needing a 44px
   target. Same pattern as .mywork__group-more below. */
.mywork__panel-more {
	margin-top: 8px;
	background: none;
	border: none;
	padding: 0;
	cursor: pointer;
	font-size: var(--th-font-micro, 11px);
	color: var(--color-primary-element);

	&:hover { text-decoration: underline; }
	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
		border-radius: 2px;
	}
}

/* ── Sections ───────────────────────────────────────────────────────── */

/* ONE grid template, declared once, consumed by both the section header and
   every row (MyWorkItemRow reads these variables through the cascade — that
   is exactly what scoped styles cannot break).
   v4.5.23: the header previously ended in a 40px spacer while the row ended
   in `auto`, so the actions column resolved to two different widths and the
   TEAM / REASON / DEADLINE captions sat well right of their cells. Both now
   read the same variables, so they cannot drift again. */
.mywork__groups {
	display: flex;
	flex-direction: column;
	gap: 14px;

	--th-mywork-col-team: 128px;
	--th-mywork-col-reason: minmax(0, 200px);
	/* Wide enough for "Overdue by 30 days" and its icon in every shipped
	   locale; German is the long one. */
	--th-mywork-col-due: 148px;
	/* v4.5.25 — one 44px Open button and a 44px overflow menu. It was 236px
	   when up to three labelled buttons lived here; the width the buttons
	   vacated goes back to the title, which is what people actually read. */
	--th-mywork-col-actions: 100px;
}

/* Density now only changes row padding — the actions column is the same two
   controls either way. */
.mywork__groups--compact {
	--th-mywork-col-actions: 100px;
}

.mywork__group {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--th-radius-card, var(--border-radius-large));
	overflow: hidden;
}

.mywork__group-head {
	display: grid;
	/* Identical to MyWorkItemRow's grid, from the same variables. */
	grid-template-columns:
		minmax(0, 1fr)
		var(--th-mywork-col-team)
		var(--th-mywork-col-reason)
		var(--th-mywork-col-due)
		var(--th-mywork-col-actions);
	align-items: center;
	gap: 12px;
	padding: 10px 16px;
	/* v4.5.39 — the same header treatment every team widget uses
	   (.teamhub-widget-header): the card's own background, separated from its
	   body by a hairline rather than by a grey fill. It was
	   --color-background-hover, which made the section headers on this page the
	   only grey headers in the product. */
	background: var(--color-main-background);
	border-bottom: 1px solid var(--color-border);
}

/* Collapsed to the header alone — nothing below it for the rule to separate. */
.mywork__group--collapsed .mywork__group-head {
	border-bottom: none;
}

.mywork__group-icon { flex: 0 0 auto; }

/* The icon sits outside the grid flow, so pull the title back over it. */
.mywork__group-head > .mywork__group-icon {
	grid-column: 1;
	grid-row: 1;
	justify-self: start;
}

/* v4.5.27 — matches .teamhub-widget-title on the team page: 18px, 600,
   primary. The section header of a My Work group and the header of a team
   widget do the same job, and they now look like it. */
.mywork__group-title {
	grid-column: 1;
	grid-row: 1;
	margin: 0 0 0 28px;
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: var(--th-font-heading, 16px);
	font-weight: var(--th-font-weight-semibold, 600);
	color: var(--color-primary-element);
	min-width: 0;
}

/* v4.5.25 — every glyph on this page is neutral.
   The coloured left edge this briefly had is gone at Justin's request: the
   section already announces itself with a heading and a count, and a bar down
   the side of the most important card on the page was reading as an alert.
   The summary card above still carries the category's hairline, which is
   where a colour cue costs nothing. */
/* v4.5.27 — matches the team page's widget-header icons
   (.teamhub-widget-header in TeamWidgetGrid), per Justin. Was maxcontrast
   grey, which read as chrome rather than as part of the same product. */
.mywork__group-icon {
	color: var(--color-primary-element);
	/* The header is a single line, so centring is right here — this only
	   matters if the title ever wraps. */
	align-self: center;
}

/* v4.5.39 — the collapse control, in the header's last column so it lines up
   with the Open / overflow buttons on the rows beneath and sits where every
   team-home widget keeps its chevron.

   Raw <button>: an icon-only chevron at 28px, below NcButton's 44px touch
   target — the same carve-out .teamhub-widget-collapse-btn takes. Six locks on
   the box because NC's global button rule sets both min-width and min-height
   to 44px and per spec min-* beats an unqualified width/height
   (SKILLS.md § UI shapes). */
.mywork__group-toggle {
	grid-column: 5;
	grid-row: 1;
	justify-self: end;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: 0 0 auto;
	box-sizing: border-box;
	width: 28px;
	height: 28px;
	min-width: 28px;
	min-height: 28px;
	max-width: 28px;
	max-height: 28px;
	padding: 0;
	background: none;
	border: none;
	border-radius: var(--th-radius-control, var(--border-radius));
	cursor: pointer;
	color: var(--color-primary-element);

	&:hover { background: var(--color-background-hover); }
	/* Split from :hover — grouping them is what silently kills the keyboard
	   focus ring (SKILLS.md § Focus visibility standard). */
	&:focus-visible {
		background: var(--color-background-hover);
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
	}
}

/* Down = collapsed, up = expanded, the same direction the widget headers use.
   One imported icon rotated rather than a ChevronUp/ChevronDown pair. */
.mywork__chevron { transition: transform 150ms ease-out; }
.mywork__chevron--open { transform: rotate(180deg); }

/* v4.5.39 — --color-background-dark, not --color-main-background: the header
   behind it is the card's own background now, so the pill was white on white
   and the count read as loose text. */
.mywork__group-count {
	font-size: var(--th-font-micro, 11px);
	font-weight: var(--th-font-weight-semibold, 600);
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
	background: var(--color-background-dark);
	border-radius: var(--th-radius-pill, 999px);
	padding: 0 7px;
}

/* Column captions. Aria-hidden in the template — they label cells that
   each already carry their own accessible text, and announcing four
   extra words per row would be noise, not help. */
.mywork__columns {
	display: contents;

	> span {
		font-size: var(--th-font-micro, 11px);
		font-weight: var(--th-font-weight-semibold, 600);
		color: var(--color-text-maxcontrast);
		text-transform: uppercase;
		letter-spacing: 0.03em;
	}
}

/* The spacer that used to hold the header's last column is gone (v4.5.39) —
   the collapse button occupies it now, and two things claiming column 5 would
   push one of them onto a second row. */

.mywork__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

/* Footer link, matching the mockup's "Bekijk alle (n)". */
.mywork__group-more {
	display: block;
	width: 100%;
	padding: 9px 16px;
	border: none;
	border-top: 1px solid var(--color-border);
	background: none;
	cursor: pointer;
	color: var(--color-primary-element);
	font-size: var(--th-font-meta, 12px);
	font-weight: var(--th-font-weight-semibold, 600);

	&:hover { background: var(--color-background-hover); }
	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: -2px;
	}
}

.mywork__loading {
	display: flex;
	justify-content: center;
	padding: 32px 0;
}

.mywork__pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	padding: 4px 0 16px;
}

.mywork__pagination-label {
	font-size: var(--th-font-meta, 12px);
	color: var(--color-text-maxcontrast);
	min-width: 8ch;
	text-align: center;
}

/* ── Modals ─────────────────────────────────────────────────────────── */

.mywork__modal {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px 24px;
	min-width: 0;
}

.mywork__modal-title {
	margin: 0;
	font-size: var(--th-font-heading, 16px);
	font-weight: var(--th-font-weight-bold, 700);
}

.mywork__modal-body {
	margin: 0;
	font-size: var(--th-font-body, 14px);
	color: var(--color-text-light);
	line-height: var(--th-line-height-body, 1.4);
}

.mywork__modal-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: var(--th-font-meta, 12px);
	color: var(--color-text-maxcontrast);
}

.mywork__modal-input {
	width: 100%;
	padding: 6px 8px;
	font-size: var(--th-font-body, 14px);
	color: var(--color-main-text);
	background: var(--color-main-background);
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--th-radius-control, var(--border-radius));
	outline: none;

	&:focus { border-color: var(--color-primary-element); }
	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 1px;
	}
}

.mywork__modal-textarea {
	resize: vertical;
	font-family: inherit;
}

.mywork__modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}

/* ── Narrow ─────────────────────────────────────────────────────────── */

@media (max-width: 900px) {
	.mywork { padding: 16px 14px 24px 14px; }
	.mywork__header { padding-left: 44px; }

	/* v4.5.39 — the summary cards, rearranged rather than shrunk.
	   Two cards to a phone row leaves ~55px for a heading that needs ~110px,
	   so "Action required" was clipped to "Action r…". Three changes, in the
	   order they buy the most room:

	   1. The chip goes. It is `aria-hidden` and says exactly what the label
	      beside it says, so it is the one element on the card carrying no
	      information — 35px back for nothing lost.
	   2. The heading wraps to two lines instead of ellipsing. It is a heading;
	      headings are allowed to be two lines.
	   3. Vertical padding drops to 16px, because a two-line heading has
	      already added the height the 28px was buying. */
	.mywork__card { padding: 16px 14px; }
	.mywork__card-chip { display: none; }

	.mywork__card-label {
		white-space: normal;
		overflow: visible;
		/* Two lines, then ellipsis — a locale with a very long category name
		   must not push the cards to different heights. */
		display: -webkit-box;
		-webkit-line-clamp: 2;
		-webkit-box-orient: vertical;
		overflow: hidden;
	}

	/* The section header stops being a column ruler and becomes a title
	   bar; the rows below stack, so captions would label nothing. */
	.mywork__group-head { display: flex; align-items: center; gap: 10px; }
	.mywork__columns { display: none; }

	/* No grid to place it in, so the flex row pushes it right instead
	   (v4.5.39). The title takes the slack. */
	.mywork__group-title { flex: 1 1 auto; }
	.mywork__group-toggle { margin-left: auto; }

	/* The 28px indent existed to clear the icon inside a shared grid cell.
	   In flex the icon is a real sibling, so the indent would double it. */
	.mywork__group-title { margin-left: 0; }
}
</style>
