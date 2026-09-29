<template>
	<div ref="scroller" class="mywork" @scroll.passive="rememberScroll">
		<!-- ── Header ───────────────────────────────────────────────────── -->
		<header class="mywork__header">
			<div class="mywork__header-text">
				<h2 class="mywork__title">{{ t('teamhub', 'My Work') }}</h2>
				<span class="mywork__subtitle">
					{{ t('teamhub', 'Everything that needs attention, across all teams') }}
				</span>
			</div>

			<div class="mywork__header-actions">
				<!-- v4.10.15 — "Request a new team" was here.
				     v4.10.22 — moved to the navigation, where "New team" is:
				     asking for a team is the same intent as creating one, so
				     it belongs beside the action rather than on a page a
				     member may never open. It is shown there only to somebody
				     who may not create a team directly. -->

				<!-- v4.10.20 — "Ask the service desk" was here.
				     v4.10.25 — moved to the Services page, which is where a
				     request starts now. My Work is where a person sees where
				     their request is and takes the step that is theirs; it
				     no longer starts anything (docs/service-catalogue.md
				     § 1). -->

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

		<!-- ── Workflows, standalone (v4.10.15, narrowed in v4.10.17) ──────
		     A workflow is a request travelling between people; an aggregated
		     item is a task from a source. They keep separate row components
		     for that reason — an aggregated item has no step, no position in
		     a whole and nobody to ask — but since v4.10.17 they share **one
		     list**, grouped by the same categories, because two lists with
		     their own "Action required" heading and their own count is the
		     single thing that made this page hard to read (DESIGN §2.142,
		     which reverses §2.141's "beside, never through").

		     These standalone cards are what is left of that: the two cases
		     where there is no list to merge into —
		       * an unlicensed instance, which has no aggregated queue at all;
		       * a page grouped by something other than category, where a
		         category-keyed merge would put every workflow in the wrong
		         group.
		     `mergeWorkflows` is the one condition, so the rows are never
		     rendered twice. -->
		<div v-if="!mergeWorkflows" class="mywork__workflows">
			<WorkflowSection
				:kind="WORKFLOW_SECTION.ACTION_REQUIRED"
				:workflows="workflowSections.actionRequired"
				:loading="workflows.loading"
				:loaded="workflows.loaded"
				:error="workflows.error"
				:busy-id="workflows.busyId"
				@open="openWorkflow"
				@action="onWorkflowAction"
				@retry="loadWorkflows" />
			<WorkflowSection
				:kind="WORKFLOW_SECTION.WAITING"
				:workflows="workflowSections.waiting"
				:loading="workflows.loading"
				:loaded="workflows.loaded"
				:error="workflows.error"
				:busy-id="workflows.busyId"
				@open="openWorkflow"
				@action="onWorkflowAction"
				@retry="loadWorkflows" />
			<WorkflowSection
				v-if="workflowSections.completed.length"
				:kind="WORKFLOW_SECTION.COMPLETED"
				:workflows="workflowSections.completed"
				:loading="workflows.loading"
				:loaded="workflows.loaded"
				:error="workflows.error"
				:busy-id="workflows.busyId"
				@open="openWorkflow"
				@action="onWorkflowAction"
				@retry="loadWorkflows" />
			<WorkflowLicenceNote :licensed="!licenseGated" :workflows-licensed="workflowsLicensed" />
		</div>

		<!-- The aggregated queue is the licensed part of the page. On an
		     unlicensed instance the server answers 403 with licenseGate, the
		     sections above still render, and the licence note says why the
		     rest is missing — once, in one place. -->
		<template v-if="!licenseGated">
		<!-- ── The one list's heading (v4.10.17) ────────────────────────────
		     One question above one list, so the page says what it is for
		     before it says how it can be sliced. -->
		<h3 v-if="unifiedGroups.length || loading" class="mywork__lead">
			{{ t('teamhub', 'What needs your attention') }}
		</h3>

		<!-- ── One filter: the type of work (v4.10.18) ─────────────────────
		     The only control on this page. Everything else that used to sit
		     here — the five urgency tiles, the saved views, the sort order,
		     the row density and the eleven-field filter panel — is gone,
		     because all of it was *stored* state that could leave the page
		     in a shape its reader never chose and could not see how to
		     leave. Inge's saved `groupBy: project` plus a sticky
		     `category: action_required` is what made 4.10.17 look
		     unchanged to her: the work was under Action required all along
		     and the headings said "Marketing".

		     What is left answers the one question people actually asked of
		     the filter panel: show me only my Deck cards / only the
		     decisions. Counts come from the server with this filter *not*
		     applied, so picking one never changes the others. -->
		<div v-if="typeFilters.length > 1" class="mywork__types" role="group" :aria-label="t('teamhub', 'Show only one type of work')">
			<NcButton
				v-for="type in typeFilters"
				:key="type.key"
				class="mywork__type"
				:pressed="type.active"
				variant="tertiary"
				@click="selectType(type.key)">
				<template v-if="type.icon" #icon>
					<component :is="type.icon" :size="iconBody" aria-hidden="true" />
				</template>
				{{ type.label }}
				<span v-if="type.count !== null" class="mywork__type-count">{{ type.count }}</span>
			</NcButton>
		</div>

		<!-- ── First load ───────────────────────────────────────────────── -->
		<div v-if="loading && !payload" class="mywork__loading">
			<NcLoadingIcon :size="ICON_LARGE" />
		</div>

		<!-- ── Nothing at all ───────────────────────────────────────────────
		     v4.10.17 — `listGroups`, not `items`: when the aggregated queue
		     is empty but a workflow is waiting, the page is not empty, and
		     the merged list has a group to render. -->
		<NcEmptyContent
			v-else-if="!listGroups.length"
			:name="emptyTitle"
			:description="emptyBody">
			<template #icon><ClipboardCheckOutline :size="iconHero" /></template>
			<template v-if="hasActiveFilters" #action>
				<NcButton variant="secondary" @click="resetFilters">
					{{ t('teamhub', 'Show all work') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<!-- ── The queue ────────────────────────────────────────────────────
		     Two columns when the page is grouped by category: what you have to
		     do on the left, what is merely happening on the right. Waiting for
		     others and Completed are real information but they are not a
		     to-do list, and giving them the same weight as Action required is
		     what made a queue of twenty rows feel like forty.

		     v4.10.17 — one column. The two-column split (a to-do list on the
		     left, "at a glance" panels on the right for Waiting for others
		     and Completed) is gone: the same six categories are six groups
		     of one list now, in one order, and each group holds both the
		     workflows and the aggregated items that belong to it. The rail
		     was a second place to look, with its own half-width rows and its
		     own View-all buttons, for two of the categories only — and the
		     request that started this was to make one page somebody can read
		     top to bottom. -->
		<div v-else class="mywork__layout">
		<div class="mywork__groups">
			<section
				v-for="group in listGroups"
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
						<!-- v4.10.17 — one count per group, workflows included.
						     Two headings each counting half the group is what
						     this page is being reworked to stop doing. -->
						<span class="mywork__group-count">{{ groupTotal(group) }}</span>
					</h3>

					<!-- v4.10.17 — the Team / Reason / Deadline column captions
					     that used to sit here are gone: they labelled three of
					     an *aggregated* row's fields and nothing at all on a
					     workflow row, so in one merged list they captioned a
					     layout half the rows do not have. -->
					<span class="mywork__group-spacer" />

					<!-- `aria-labelledby` at the heading rather than an
					     aria-label of its own: the button's name is the section
					     it opens, the heading already says it, and a second
					     copy is a string that can drift out of step with the
					     first. Announces as "Action required 7, collapsed,
					     button". -->
					<NcButton
						class="mywork__group-toggle"
						:aria-expanded="isExpanded(group.key)"
						:aria-labelledby="'mywork-group-title-' + group.key"
						@click="toggleGroup(group.key)"
						variant="tertiary">
						<template #icon>
							<ChevronDown
							:size="iconBody"
							class="mywork__chevron"
							:class="{ 'mywork__chevron--open': isExpanded(group.key) }"
							aria-hidden="true" />
						</template>
					</NcButton>
				</div>

				<!-- `v-if`, not `v-show`, and therefore no `aria-controls` on
				     the toggle: a collapsed section that stayed in the DOM
				     would still mount its rows and fetch their avatars, which
				     is the opposite of what collapsing it asked for.
				     `aria-expanded` is the part the disclosure pattern
				     requires; `aria-controls` is optional and would dangle at
				     an id that does not exist while collapsed. -->
				<ul v-if="isExpanded(group.key)" class="mywork__list">
					<!-- v4.10.17 — the workflows of this category, first. A
					     request travelling between people outranks a task
					     from a source: somebody is waiting on the other end
					     of it. Still WorkflowItemRow, never MyWorkItemRow —
					     the row components stay separate because the two
					     kinds of work say different things. Never rendered
					     here unless `mergeWorkflows`, which is also what
					     hides the standalone cards at the top. -->
					<WorkflowItemRow
						v-for="w in (group.workflows || [])"
						:key="'wf-' + rowKey(w)"
						:workflow="w"
						:busy="workflows.busyId === w.id"
						@open="openWorkflow"
						@action="onWorkflowAction" />
					<MyWorkItemRow
						v-for="item in visibleItemsForGroup(group)"
						:key="item.id"
						:item="item"
						:provider-names="providerNames"
						:busy="busyItemId === item.id"
						@open="openItem"
						@open-team="openTeam"
						@action="onAction"
						@snooze="onSnooze" />
				</ul>

				<!-- Per-section "show the rest" — a long Action-required
				     section should not push every other section off screen. -->
				<NcButton
					v-if="isExpanded(group.key) && hiddenCount(group) > 0"
					class="mywork__group-more"
					variant="tertiary"
					@click="expandGroup(group.key)">
					{{ t('teamhub', 'Show all {n}', { n: groupTotal(group) }) }}
				</NcButton>
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

		</div>

		<!-- ── Under the list (v4.10.17) ─────────────────────────────────
		     The licence note used to sit between the workflow cards and the
		     queue, which is where the seam between the two lists was. There
		     is one list now, so it belongs under it — and outside the
		     list's own `v-else`, so an empty page still says it. -->
		<WorkflowLicenceNote v-if="mergeWorkflows" :licensed="!licenseGated" :workflows-licensed="workflowsLicensed" />
		</template>

		<!-- ── Workflow detail and actions (v4.10.15) ───────────────────── -->
		<WorkflowDetailModal
			v-if="workflowDetailOpen"
			:workflow="workflows.detail"
			:loading="workflows.detailLoading"
			:error="workflows.detailError"
			:busy="workflows.busyId !== null && workflows.detail && workflows.busyId === workflows.detail.id"
			:display-names="workflows.detail ? (workflows.detail.people || {}) : {}"
			@close="closeWorkflow"
			@retry="reopenWorkflow"
			@action="onWorkflowAction"
			@changed="emitCounts()" />
		<WorkflowActionDialog
			v-if="workflowAction"
			:action="workflowAction.action"
			:subject="workflowAction.workflow.title"
			:workflow="workflowAction.workflow"
			:busy="workflows.busyId === workflowAction.workflow.id"
			@close="workflowAction = null"
			@submit="submitWorkflowAction" />

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
					<NcTextArea
						v-model="extensionReason"
						class="mywork__modal-input mywork__modal-textarea"
						rows="3"
						maxlength="1000"
						:placeholder="t('teamhub', 'e.g. The project runs until the end of Q3 and the handover is in September.')"
						label-outside
						:aria-label="t('teamhub', 'Why does this team need more time?')" />
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
					<NcTextArea
						v-model="commentText"
						class="mywork__modal-input mywork__modal-textarea"
						rows="4"
						maxlength="1000"
						label-outside
						:aria-label="t('teamhub', 'Comment')" />
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
					<NcTextArea
						v-model="confirmReason"
						class="mywork__modal-input mywork__modal-textarea"
						rows="3"
						maxlength="1000"
						label-outside
						:aria-label="confirmReasonLabel" />
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
import { mapState, mapGetters } from 'vuex'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { shiftIsoDate, formatTime } from '../lib/localDate.js'
import { personalSettingsUrl } from '../lib/openProject.js'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { NcButton, NcLoadingIcon, NcEmptyContent, NcModal, NcTextArea } from '@nextcloud/vue'

import Refresh from 'vue-material-design-icons/Refresh.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import CalendarToday from 'vue-material-design-icons/CalendarToday.vue'
// v4.5.45 — the team-resource glyph (the Team admin *category* it was added
// for was folded into Action required in v4.10.20).
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
// cannot be mistaken for the Team admin source's shield-with-person inside
// Teams).
// Files reuses Folder above.
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import ShieldCrownOutline from 'vue-material-design-icons/ShieldCrownOutline.vue'
// v4.10.1 — the team-space administrator source and its report rows.
import FolderAccountOutline from 'vue-material-design-icons/FolderAccountOutline.vue'
import ArchiveCheckOutline from 'vue-material-design-icons/ArchiveCheckOutline.vue'

import MyWorkItemRow from './mywork/MyWorkItemRow.vue'
// v4.10.15 — WorkflowHub in My Work.
import WorkflowSection from './mywork/WorkflowSection.vue'
// v4.10.17 — the merged list renders workflow rows itself, beside the
// aggregated ones, so it needs the row component and not only the section.
import WorkflowItemRow from './mywork/WorkflowItemRow.vue'
import WorkflowDetailModal from './mywork/WorkflowDetailModal.vue'
import WorkflowActionDialog from './mywork/WorkflowActionDialog.vue'
import WorkflowLicenceNote from './mywork/WorkflowLicenceNote.vue'
import {
	WORKFLOW_SECTION,
	WORKFLOW_ACTION,
	actionText as workflowActionText,
	isOpen as workflowIsOpen,
	rowKey,
} from '../constants/workflows.js'
import {
	ACTION,
	CATEGORY,
	CATEGORY_ICONS,
	CATEGORY_ORDER,
	CATEGORY_TONES,
	RETIRED_CATEGORIES,
	DESTRUCTIVE_ACTIONS,
	NAVIGATION_ACTIONS,
	FORM_ACTIONS,
	actionLabel,
	categoryLabel,
	resolveSnoozePreset,
	SORT,
	providerWarning,
	formatAbsolute,
	buildSourceTabs,
	sourceGroupOf,
} from '../constants/myWork.js'
import { ICON_INLINE, ICON_BODY, ICON_TOOLBAR, ICON_HERO, ICON_LARGE } from '../constants/uiTokens.js'

/** Debounce for the search field — one request per pause, not per keystroke. */

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

/**
 * Categories that get a summary card (v4.5.39).
 *
 * Waiting for others has a rail panel of its own — with its count, its rows
 * and a View all — so a card carrying the same number was the same fact in two
 * places, and the rail is the better one: it lists *who* you are waiting on.
 * Completed keeps its card because the number there is a week's worth of
 * closure, which is a summary; the panel beside it only shows the last few.
 */

export default {
	name: 'MyWorkView',
	components: {
		  NcTextArea, NcButton, NcLoadingIcon, NcEmptyContent, NcModal,
		Refresh, ChevronLeft, ChevronRight, ChevronDown, ClipboardCheckOutline,
		AlertCircleOutline, InformationOutline,
		CalendarToday, CalendarClock, AccountClock, CheckCircleOutline,
		ShieldAccountOutline, AccountPlusOutline,
		ViewGrid, CardText, Folder, Gavel, Calendar, Puzzle, FileEyeOutline, BriefcaseOutline, FlagOutline,
		AccountGroupOutline, ShieldCrownOutline, FolderAccountOutline, ArchiveCheckOutline,
		MyWorkItemRow,
		WorkflowSection, WorkflowItemRow, WorkflowDetailModal, WorkflowActionDialog, WorkflowLicenceNote,
	},
	emits: ['open-team', 'open-item', 'counts-changed'],

	data() {
		return {
			ICON_LARGE,
			WORKFLOW_SECTION,
			loading: false,
			busyItemId: null,
			// v4.10.15 — the aggregated queue answered 403 with licenseGate:
			// the workflow sections stay, the rest of the page is not rendered.
			licenseGated: false,
			// The workflow whose detail view is open, and the action a dialog
			// is collecting a text for: { workflow, action } | null.
			workflowDetailOpen: false,
			workflowAction: null,
			/**
			 * v4.10.18 — the per-type counts from the last answer that had
			 * **no** type filter on, which is the only honest source for the
			 * chips' numbers. `null` until such an answer arrives.
			 *
			 * The server cannot supply these while narrowed: `providerIds`
			 * decides which providers are *queried at all*
			 * (`ProviderRegistry`), so a narrowed answer holds no rows for
			 * the other sources and `MyWorkService`'s `ignoreProvider: true`
			 * has nothing left to un-filter. Picking Decisions made every
			 * other chip read 0 — "you have no Deck work", said to somebody
			 * with three overdue Deck cards.
			 */
			allSourceCounts: null,
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
		...mapState({ myWork: state => state.myWork, workflows: state => state.workflows }),
		...mapGetters('workflows', { workflowSections: 'sections', workflowActionRequiredCount: 'actionRequiredCount' }),

		/**
		 * v4.10.16 — whether this instance is licensed, as the workflow
		 * list reported it. Independent of `licenseGated`, which only says
		 * whether the aggregated queue answered: the two always agree, but
		 * the licence note reads the tier the workflow API stated.
		 */
		workflowsLicensed() {
			return this.workflows.tier !== 'basic'
		},

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
		/**
		 * v4.10.18 — fixed, not stored. My Work is one list grouped by what
		 * the work needs from you, sorted by when it is due, at one density.
		 * These were three saved preferences, and a saved `groupBy: project`
		 * is what made the category headings invisible for a reader who had
		 * once tried that grouping and never found the way back.
		 */
		groupBy() { return 'category' },
		sortBy() { return SORT.DEADLINE },
		compact() { return false },
		page() { return this.myWork.page },
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
		 * v4.10.18 — the page's only control: one chip per kind of work this
		 * person actually has, plus "All work".
		 *
		 * Built from the same `buildSourceTabs` the old source bar used, so
		 * the grouping of related sources (File approval + File reviews →
		 * Files) and the server's counts are unchanged; what changed is that
		 * this is now the whole filter surface rather than one row of six.
		 */
		typeFilters() {
			const known = this.allSourceCounts
			const tabs = buildSourceTabs(
				this.providers,
				known || this.payload?.sourceCounts || {},
				this.filters.providerId,
			)
			if (known) {
				return tabs
			}
			// No unnarrowed answer yet — a filter restored from preferences on
			// a cold start. An inactive chip shows no number rather than a
			// zero it cannot stand behind; the real ones arrive with All.
			return tabs.map(tab => (tab.active ? tab : { ...tab, count: null }))
		},

		/**
		 * Whether the workflows are rendered inside the one list (v4.10.17)
		 * rather than as their own cards above it.
		 *
		 * One case cannot merge and falls back to the standalone cards: an
		 * **unlicensed** instance, which has no aggregated queue to merge
		 * into. (Until v4.10.18 a second case could: a page grouped by
		 * something other than category. Grouping is no longer a choice, so
		 * that case is gone.) One condition drives both the cards and the
		 * in-list rows, so a workflow is never rendered twice.
		 */
		mergeWorkflows() {
			return !this.licenseGated
		},

		/**
		 * The one list: every category that has something in it, in
		 * `CATEGORY_ORDER`, each carrying both its workflows and its
		 * aggregated items.
		 *
		 * This replaces the two-column layout. Waiting for others and
		 * Completed used to be excluded from the main column and rendered as
		 * "at a glance" panels in a rail instead; they are ordinary groups
		 * now. A category the *server* sent no group for can still hold
		 * workflows — a team request waiting on somebody else, on an instance
		 * with no other waiting work at all — so those are appended rather
		 * than lost.
		 */
		unifiedGroups() {
			// v4.10.18 — a workflow belongs to no source: it is a request
			// travelling between people, not a Deck card or a Decision. So a
			// type filter hides it, and the group heading agrees with the chip
			// instead of counting one more than it. With All on, it is back.
			const byCategory = this.filters.providerId ? {} : {
				[CATEGORY.ACTION_REQUIRED]: this.workflowSections.actionRequired,
				[CATEGORY.WAITING_FOR_OTHERS]: this.workflowSections.waiting,
				[CATEGORY.COMPLETED]: this.workflowSections.completed,
			}
			const out  = []
			const seen = new Set()
			for (const group of (this.payload?.groups || [])) {
				seen.add(group.key)
				const workflows = byCategory[group.key] || []
				if (!this.itemsForGroup(group).length && !workflows.length) {
					continue
				}
				out.push({ ...group, workflows })
			}
			for (const key of CATEGORY_ORDER) {
				const workflows = byCategory[key] || []
				if (seen.has(key) || !workflows.length) {
					continue
				}
				out.push({ key, label: categoryLabel(key), itemIds: [], workflows })
			}
			return out.sort((a, b) => CATEGORY_ORDER.indexOf(a.key) - CATEGORY_ORDER.indexOf(b.key))
		},

		/** What the list renders — merged, or the aggregated groups alone. */
		listGroups() {
			return this.mergeWorkflows ? this.unifiedGroups : this.visibleGroups
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

		/** v4.10.18 — there is one filter, so this is one question. */
		hasActiveFilters() {
			return !!this.filters.providerId
		},

		/**
		 * v4.10.1 — a source may script the confirmation of one of its actions
		 * itself: `metadata.confirm[action] = { title, body, reasonLabel? }`,
		 * translated server-side. The team-space hand-over is the first to —
		 * "Hand the move to Inge?" is a question only the source can word, and
		 * the generic copy below is written for approvals. `reasonLabel` adds
		 * an optional note field. Absent → everything works as before.
		 */
		confirmSpec() {
			const spec = this.confirmTarget?.item?.metadata?.confirm?.[this.confirmTarget?.action]
			return spec && typeof spec === 'object' ? spec : null
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
				|| typeof this.confirmSpec?.reasonLabel === 'string'
		},

		confirmReasonLabel() {
			if (typeof this.confirmSpec?.reasonLabel === 'string' && this.confirmSpec.reasonLabel !== '') {
				return this.confirmSpec.reasonLabel
			}
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
			if (typeof this.confirmSpec?.title === 'string' && this.confirmSpec.title !== '') {
				return this.confirmSpec.title
			}
			const label = this.confirmTarget.item.subtitle || this.confirmTarget.item.title
			switch (this.confirmTarget.action) {
			case ACTION.REJECT:
				return t('teamhub', 'Reject “{title}”?', { title: label })
			case ACTION.CLOSE:
				return t('teamhub', 'Close the review of “{title}”?', { title: label })
			case ACTION.COMPLETE:
				return t('teamhub', 'Complete the review of “{title}”?', { title: label })
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
			if (typeof this.confirmSpec?.body === 'string') {
				return this.confirmSpec.body
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
			if (this.hasActiveFilters) {
				return t('teamhub', 'Nothing matches these filters')
			}
			// v4.10.15 — with workflow rows on screen above, "caught up"
			// would contradict them; the queue is empty, the page is not.
			if (this.workflowSections.actionRequired.length || this.workflowSections.waiting.length) {
				return t('teamhub', 'No other work')
			}
			return t('teamhub', 'You’re all caught up')
		},

		emptyBody() {
			if (this.hasActiveFilters) {
				return t('teamhub', 'Try widening the filters, or clear them to see everything again.')
			}
			return t('teamhub', 'New tasks and approvals from the teams appear here automatically.')
		},
	},

	async mounted() {
		// Render immediately from the cached payload when there is one — the
		// user is usually coming back from an item they just opened — then
		// always refresh behind it. v4.5.22: the refresh is unconditional.
		// Skipping it while the payload was "fresh" stacked a second cache on
		// top of the server's own, and a due-date change could take minutes to
		// surface.
		// v4.10.15 — the workflow sections load beside the queue, never
		// behind it: on an unlicensed instance the queue's request is the
		// one that fails, and the sections must not wait for it.
		this.loadWorkflows()
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
		rowKey,
		t,
		n,
		categoryLabel,

		/**
		 * v4.10.1 — the confirm button wears the row's own word for the verb
		 * when the source gave one (`metadata.actionLabels`), so "Hand to team
		 * owner" on the row is "Hand to team owner" in the dialog too.
		 */
		actionLabel(action) {
			const own = this.confirmTarget?.item?.metadata?.actionLabels?.[action]
			return typeof own === 'string' && own !== '' ? own : actionLabel(action)
		},

		groupIcon(key) {
			return CATEGORY_ICONS[key] || 'CalendarClock'
		},

		/**
		 * One count per group, workflows included (v4.10.17). The heading
		 * counts what the group renders — the whole point of merging the two
		 * lists was that "Action required 0" above "Action required 7" is
		 * not a thing a reader can resolve.
		 */
		groupTotal(group) {
			return (group.itemIds || []).length + (group.workflows || []).length
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

		/** v4.10.18 — the one filter. An empty key is "All work". */
		selectType(providerId) {
			if (this.filters.providerId === providerId) {
				return
			}
			this.applyFilterPatch({ providerId })
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
				// v4.10.18 — `groupBy`, `sortBy` and `compact` are deliberately
				// **not** read back any more. They are no longer choices, and a
				// stored value is worse than no value: Inge's saved
				// `groupBy: "project"` survived the 4.10.17 rework and replaced
				// every category heading with a project name, so the page looked
				// untouched to the one person it was reworked for. The server may
				// still hold the old keys; nothing reads them, and the next
				// `persistPreferences()` overwrites them.
				//
				// A pre-4.5.39 server sends `completedExpanded` and no
				// `collapsedGroups`. Nothing is migrated: the old key was one
				// boolean about one section, the new one a set of section keys,
				// so carrying a stored `false` across would arrive meaning
				// "everything collapsed" — the 4.5.29 `mentionsOnly` lesson.
				// Absent means the default, which is everything open.
				// v4.10.20 — a key naming a retired category is dropped, not
				// carried forward. See RETIRED_CATEGORIES in constants/myWork.js.
				if (Array.isArray(data.collapsedGroups)) {
					this.$store.commit(
						'SET_MYWORK_COLLAPSED_GROUPS',
						data.collapsedGroups.filter(k => !(k in RETIRED_CATEGORIES)),
					)
				}
				if (data.filters && typeof data.filters === 'object') {
					// v4.10.18 — the type of work is the only filter that
					// survives a reload. Everything else a stored filter set
					// could carry — a category, a team, a priority, a due
					// window, a search — is dropped on the way in: a sticky
					// filter nobody can see the control for is how a page ends
					// up showing a third of somebody's work with no clue why.
					// Inge's stored `category: "action_required"` was exactly
					// that.
					//
					// v4.9.17 — a preference saved before the source groups
					// existed may hold a member (`approval`) where the control
					// now speaks in groups (`files`). The server would honour
					// either; the control only agrees with the rows if it is
					// told the group.
					const stored = String(data.filters.providerId || '')
					this.$store.commit('SET_MYWORK_FILTERS', {
						providerId: sourceGroupOf(stored) || stored,
					})
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
			if (userInitiated) {
				this.loadWorkflows()
			}
			try {
				const params = this.queryParams()
				if (userInitiated) {
					params.nocache = 1
				}
				const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/mywork'), { params })
				this.$store.commit('SET_MYWORK_PAYLOAD', data)
				this.licenseGated = false
				// Only an unnarrowed answer can be trusted for the chips.
				if (!params.providerIds) {
					this.allSourceCounts = data?.sourceCounts || {}
				}

				// v4.5.26 — keep the sidebar badge honest.
				//
				// It used to refresh only when the user *left* My Work, so
				// completing something while staying on the page left a stale
				// number until a reload. Every payload already carries the
				// counts, so this costs nothing — no second request.
				// v4.10.15 — plus the workflow steps waiting on the viewer.
				this.emitCounts()

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
					// v4.10.15 — not an error any more: the page renders the
					// workflow sections and the licence note; no toast.
					this.licenseGated = true
					this.emitCounts()
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

		/** v4.10.18 — "Show all work": the one filter off. */
		resetFilters() {
			this.applyFilterPatch({ providerId: '' })
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
		/**
		 * v4.10.18 — two things are still the viewer's own: which sections
		 * they collapsed, and which type of work they are looking at. The
		 * grouping, the sort, the density and the other ten filter keys are
		 * written as their fixed values / empty, which also *clears* whatever
		 * an older version stored for this person on their first visit.
		 */
		persistPreferences() {
			axios.put(generateUrl('/apps/teamhub/api/v1/mywork/preferences'), {
				groupBy: 'category',
				sortBy: SORT.DEADLINE,
				showSnoozed: false,
				collapsedGroups: this.collapsedGroups,
				compact: false,
				filters: { providerId: this.filters.providerId || '' },
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

		// ── Workflows (v4.10.15) ──────────────────────────────────────────

		/** The sidebar badge: the queue's Action required plus the workflow steps waiting on the viewer. */
		emitCounts() {
			const queue = this.licenseGated ? 0 : (Number(this.payload?.counts?.action_required) || 0)
			this.$emit('counts-changed', queue + this.workflowActionRequiredCount)
		},

		async loadWorkflows() {
			await this.$store.dispatch('workflows/load')
			this.emitCounts()
		},

		/** v4.10.37 — opened on the row's own task. */
		openWorkflow(workflow) {
			this.workflowDetailOpen = true
			this.$store.dispatch('workflows/open', { id: workflow.id, step: workflow.viewer?.stepKey || '' })
		},

		reopenWorkflow() {
			const detail = this.workflows.detail
			if (detail?.id) {
				this.$store.dispatch('workflows/open', { id: detail.id, step: detail.viewer?.stepKey || '' })
			}
		},

		closeWorkflow() {
			this.workflowDetailOpen = false
			this.$store.dispatch('workflows/close')
		},

		/**
		 * A row or the detail asked for an action. Verbs that take a text
		 * open the dialog first; the rest fire at once. Nothing fires while
		 * another action is in flight — the store refuses, and the buttons
		 * are disabled anyway.
		 */
		onWorkflowAction({ workflow, action, direct = false }) {
			if (this.workflows.busyId !== null) {
				return
			}
			// v4.10.39 — a team task is claimed before anybody acts on it; the
			// detail view offers the claim, the service team's store makes it.
			if (action === 'claim') {
				this.claimWorkflowTask(workflow)
				return
			}
			// v4.10.37 — `direct`: the requester pressed a task's link, and
			// pressing it is doing the task; there is nothing to ask first.
			if (direct || workflowActionText(action) === 'none') {
				this.runWorkflowAction(workflow, action, '')
				return
			}
			this.workflowAction = { workflow, action }
		},

		/** v4.10.38 — `files` from the dialog's paperclip, `[{ fileId, name }]`. */
		async claimWorkflowTask(workflow) {
			const step = workflow.viewer?.stepKey || ''
			const result = await this.$store.dispatch('serviceTeams/act', {
				id: workflow.id, action: 'claim', uid: getCurrentUser()?.uid || '', step,
			})
			if (result?.ok) {
				showSuccess(t('teamhub', 'Claimed: {title}', { title: workflow.title }))
				this.$store.dispatch('workflows/refresh')
				this.$store.dispatch('workflows/open', { id: workflow.id, step })
			} else if (!result?.error?.busy) {
				showError(result?.error?.message || t('teamhub', 'The request could not be claimed.'))
			}
		},

		submitWorkflowAction(text, files = []) {
			if (!this.workflowAction) {
				return
			}
			const { workflow, action } = this.workflowAction
			this.runWorkflowAction(workflow, action, text, files)
		},

		async runWorkflowAction(workflow, action, text, files = []) {
			try {
				const updated = await this.$store.dispatch('workflows/act', {
					id: workflow.id, action, text, step: workflow.viewer?.stepKey || '',
					fileIds: (files || []).map(f => f.fileId),
				})
				this.workflowAction = null
				showSuccess(this.workflowActionToast(action, updated))
				// v4.10.37 — finishing one task can open the next step's tasks
				// or close this request's other rows: re-read the list.
				this.$store.dispatch('workflows/refresh')
				if (this.workflowDetailOpen && updated && this.workflows.detail?.id === updated.id) {
					if (workflowIsOpen(updated)) {
						// The events changed; re-read the detail with its history.
						this.$store.dispatch('workflows/open', { id: updated.id, step: updated.viewer?.stepKey || '' })
					} else {
						// It ended. On an unlicensed instance it no longer
						// exists at all (v4.10.16), so re-reading it would
						// answer 404: close the view, the toast said what
						// happened.
						this.closeWorkflow()
					}
				}
				this.emitCounts()
			} catch (e) {
				if (e?.status === 0) {
					return // busy — the first click is still running
				}
				const msg = e?.message
				if (e?.status === 429) {
					showError(msg || t('teamhub', 'You asked for an update recently. Try again later.'))
				} else if (e?.status === 409) {
					showError(msg || t('teamhub', 'This workflow changed in the meantime. The list was refreshed.'))
					this.workflowAction = null
				} else {
					showError(msg || t('teamhub', 'That action could not be completed.'))
				}
				this.emitCounts()
			}
		},

		/** The toast after an action; a finished workflow says so — there is no Completed section to find it in. */
		workflowActionToast(action, updated) {
			const title = updated?.title || ''
			if (updated && !workflowIsOpen(updated)) {
				switch (updated.status) {
				case 'completed':
					return t('teamhub', 'Workflow completed: {title}', { title })
				case 'rejected':
					return t('teamhub', 'Workflow rejected: {title}', { title })
				default:
					return t('teamhub', 'Workflow withdrawn: {title}', { title })
				}
			}
			switch (action) {
			case WORKFLOW_ACTION.COMPLETE:
				return t('teamhub', 'Step completed. The next step is with {actor}.', { actor: this.nextActorLabel(updated) })
			case WORKFLOW_ACTION.REQUEST_INFORMATION:
				return t('teamhub', 'Asked. The requester has been notified.')
			case WORKFLOW_ACTION.PROVIDE_INFORMATION:
				return t('teamhub', 'Answer sent.')
			case WORKFLOW_ACTION.REQUEST_STATUS:
				return t('teamhub', 'Update requested. The responsible person has been notified.')
			default:
				return t('teamhub', 'Done')
			}
		},

		nextActorLabel(updated) {
			const step = (updated?.steps || []).find(s => ['available', 'in_progress', 'waiting_for_information'].includes(s.status))
			if (!step) {
				return ''
			}
			const people = updated?.people || {}
			switch (step.actor?.type) {
			case 'user': return people[step.actor.id] || step.actor.id
			case 'group': return t('teamhub', 'Group {group}', { group: step.actor.id })
			case 'team_owner': return t('teamhub', 'Team owner')
			case 'team_moderator': return t('teamhub', 'Team owner or moderator')
			case 'team': return t('teamhub', 'Team members')
			default: return ''
			}
		},

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
			//
			// v4.10.1 — and whenever the source scripted this action's
			// confirmation itself (`metadata.confirm[action]`).
			if (DESTRUCTIVE_ACTIONS.includes(action)
				|| item.metadata?.requiresReason
				|| item.metadata?.allowsReason
				|| item.metadata?.confirm?.[action]) {
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
	gap: 16px;
	/* Left padding clears NC's sidebar-toggle button AND gives the canvas
	   room to breathe against the sidebar — everything below the header
	   shares this edge, so the whole view lines up on one axis. */
	padding: 16px 28px 28px 48px;
	/* v4.10.10: no own scroll box — NcAppContent is the scroller (NC's
	   content rule: no overflow on content parents unless it is a split
	   pane). The view just lays out its height. */
	min-height: 100%;
	box-sizing: border-box;
	/* v4.5.27 — the same canvas a team page uses (.teamhub-home-view in
	   TeamWidgetGrid). The cards and section panels were already
	   --color-main-background with a border, so they had been sitting on a
	   background of exactly their own colour and the borders were doing all
	   the work. On grey they read as cards, and the three pages in the
	   sidebar look like one product. */
	background: var(--color-background-dark);
}

/* ── Workflows (v4.10.15) ────────────────────────────────────────────── */

.mywork__workflows {
	display: flex;
	flex-direction: column;
	gap: 16px;
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
	gap: 4px;
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

/* ── The one filter (v4.10.18) ──────────────────────────────────────── */

/* One chip per kind of work, plus All work. This is the whole of what used
   to be here: the segmented density pair, six summary tiles with their
   accent rails, the saved-view chips, the source tab bar and the sort
   select. */
.mywork__types {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--th-space-sm, 8px);
	margin-block: var(--th-space-md, 12px);
}

.mywork__type-count {
	margin-inline-start: var(--th-space-xs, 4px);
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

/* ── Two-column layout ──────────────────────────────────────────────── */

/* v4.10.17 — one column. The 300px rail beside it is gone; Waiting for
   others and Completed are groups of the one list. */
.mywork__layout {
	display: grid;
	grid-template-columns: minmax(0, 1fr);
	align-items: start;
	gap: var(--th-space-lg, 16px);
}

/* The question the page answers, above the list that answers it. */
.mywork__lead {
	margin: var(--th-space-lg, 16px) 0 var(--th-space-sm, 8px);
	font-size: var(--th-font-heading-lg, 20px);
	font-weight: var(--th-font-weight-semibold, 600);
}

/* Holds the space the Team / Reason / Deadline captions used to take, so
   the collapse chevron stays at the far end of the header row. */
.mywork__group-spacer {
	flex: 1 1 auto;
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
	gap: 16px;

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

.mywork__group {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--th-radius-card, var(--border-radius-element));
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
	padding: 8px 16px;
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
	max-width: 28px;

	&:hover { background: var(--color-background-hover); }
	/* Split from :hover — grouping them is what silently kills the keyboard
	   focus ring (SKILLS.md § Focus visibility standard). */
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
	padding: 0 8px;
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

	&:hover { background: var(--color-background-hover); }
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
	padding: 8px 8px;
	font-size: var(--th-font-body, 14px);
	color: var(--color-main-text);
	background: var(--color-main-background);
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--th-radius-control, var(--border-radius-small));
	outline: none;

	&:focus { border-color: var(--color-primary-element); }
	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 1px;
	}
}

.mywork__modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}

/* ── Narrow ─────────────────────────────────────────────────────────── */

@media (max-width: 900px) {
	.mywork { padding: 16px 14px 24px 14px; }
	.mywork__header { padding-inline-start: 44px; }

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
	.mywork__group-toggle { margin-inline-start: auto; }

	/* The 28px indent existed to clear the icon inside a shared grid cell.
	   In flex the icon is a real sibling, so the indent would double it. */
	.mywork__group-title { margin-inline-start: 0; }
}
</style>
