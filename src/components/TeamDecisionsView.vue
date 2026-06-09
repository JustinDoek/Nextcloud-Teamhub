<template>
    <div class="th-dv">

        <!-- ── Toolbar ── -->
        <div class="th-dv__toolbar" role="toolbar" :aria-label="t('teamhub', 'Decisions controls')">
            <div class="th-dv__filter-row">

                <!-- Landing: just title -->
                <template v-if="view === 'landing'">
                    <span class="th-dv__breadcrumb-title">{{ t('teamhub', 'Decision categories') }}</span>
                </template>

                <!-- List view: back + filters -->
                <template v-else-if="view === 'list'">
                    <button class="th-dv__back-chip" :aria-label="t('teamhub', 'Back to categories')" @click="goToLanding">
                        <ChevronLeftIcon :size="15" aria-hidden="true" />
                        {{ t('teamhub', 'Categories') }}
                    </button>
                    <span v-if="activeCategory" class="th-dv__breadcrumb-sep" aria-hidden="true">/</span>
                    <span class="th-dv__breadcrumb-cat">
                        <template v-if="activeCategory">
                            <component v-if="activeCategory.icon" :is="categoryIconComponent(activeCategory.icon)" :size="16" class="th-dv__breadcrumb-icon" aria-hidden="true" />
                            {{ activeCategory.name }}
                        </template>
                        <template v-else>{{ t('teamhub', 'All decisions') }}</template>
                    </span>

                    <!-- Status chips -->
                    <div class="th-dv__chips" role="group" :aria-label="t('teamhub', 'Filter by status')">
                        <button
                            v-for="chip in statusChips"
                            :key="chip.value || 'all'"
                            class="th-dv__chip"
                            :class="{ 'th-dv__chip--active': filterStatus === chip.value }"
                            :aria-pressed="filterStatus === chip.value"
                            @click="setStatusFilter(chip.value)">
                            {{ chip.label }}
                        </button>
                    </div>

                    <!-- Impact chips -->
                    <div class="th-dv__chips" role="group" :aria-label="t('teamhub', 'Filter by impact')">
                        <button
                            v-for="chip in impactChips"
                            :key="chip.value || 'all-impact'"
                            class="th-dv__chip"
                            :class="{ 'th-dv__chip--active': filterImpact === chip.value }"
                            :aria-pressed="filterImpact === chip.value"
                            @click="setImpactFilter(chip.value)">
                            {{ chip.label }}
                        </button>
                    </div>

                    <!-- Level chips (only when level feature is on) -->
                    <div v-if="decisionsLevelEnabled" class="th-dv__chips" role="group" :aria-label="t('teamhub', 'Filter by level')">
                        <button
                            v-for="chip in levelChips"
                            :key="chip.value || 'all-level'"
                            class="th-dv__chip"
                            :class="{ 'th-dv__chip--active': filterLevel === chip.value }"
                            :aria-pressed="filterLevel === chip.value"
                            @click="setLevelFilter(chip.value)">
                            {{ chip.label }}
                        </button>
                    </div>

                    <!-- Sort -->
                    <button class="th-dv__sort-btn" :aria-label="sortLabel" @click="toggleSort">
                        <SortAscendingIcon v-if="sort === 'created'" :size="15" aria-hidden="true" />
                        <SortDescendingIcon v-else :size="15" aria-hidden="true" />
                        {{ sortLabel }}
                    </button>
                </template>

                <div class="th-dv__toolbar-spacer" />

                <NcButton variant="primary" class="th-dv__propose-btn" @click="$emit('propose-decision')">
                    <template #icon><PlusIcon :size="18" /></template>
                    {{ t('teamhub', 'Propose decision') }}
                </NcButton>
            </div>
        </div>

        <!-- ── Main content ── -->
        <div class="th-dv__body">

            <!-- ════ LANDING VIEW ════ -->
            <template v-if="view === 'landing'">
                <div class="th-dv__landing">

                    <!-- Search bar — searches DECISIONS across all categories.
                         Empty: show category grid. Typed: show matching decision cards inline. -->
                    <div class="th-dv__landing-search-wrap">
                        <MagnifyIcon :size="16" class="th-dv__landing-search-icon" aria-hidden="true" />
                        <input
                            v-model="searchQuery"
                            type="search"
                            class="th-dv__landing-search"
                            :placeholder="t('teamhub', 'Search decisions…')"
                            :aria-label="t('teamhub', 'Search decisions')"
                            @input="onSearchInput">
                    </div>

                    <!-- SEARCH MODE — when there's a query -->
                    <template v-if="searchQuery.trim()">
                        <div v-if="searchLoading" class="th-dv__state">
                            <NcLoadingIcon :size="32" />
                        </div>
                        <p v-else-if="!searchResults.length" class="th-dv__landing-no-results">
                            {{ t('teamhub', 'No decisions match "{q}"', { q: searchQuery }) }}
                        </p>
                        <ul v-else class="th-dv__cards" role="list">
                            <li
                                v-for="d in searchResults"
                                :key="d.id"
                                class="th-dv__card"
                                :class="[`th-dv__card--${d.status}`]"
                                role="listitem">
                                <button
                                    class="th-dv__card-btn"
                                    :aria-label="t('teamhub', 'View decision: {q}', { q: d.question || t('teamhub', 'Untitled') })"
                                    @click="selectFromSearch(d)">
                                    <span class="th-dv__card-accent" aria-hidden="true" />
                                    <span class="th-dv__card-body">
                                        <span class="th-dv__card-subject">{{ d.question || t('teamhub', 'Untitled decision') }}</span>
                                        <span class="th-dv__card-meta">
                                            <span v-if="d.category" class="th-dv__card-cat-tag">{{ d.category }}</span>
                                            <span class="th-dv__impact" :class="`th-dv__impact--${d.impact}`">{{ impactLabel(d.impact) }}</span>
                                            <span v-if="decisionsLevelEnabled && d.level" class="th-dv__level-badge">{{ levelLabel(d.level) }}</span>
                                            <span class="th-dv__card-date" :title="fullDate(d.createdAt)">{{ relativeDate(d.createdAt) }}</span>
                                        </span>
                                    </span>
                                    <span class="th-dv__status-pill" :class="`th-dv__status-pill--${d.status}`">{{ statusLabel(d.status) }}</span>
                                    <ChevronRightIcon :size="16" class="th-dv__card-chevron" aria-hidden="true" />
                                </button>
                            </li>
                        </ul>
                    </template>

                    <!-- CATEGORY MODE — default when no search -->
                    <template v-else>
                        <!-- All decisions shortcut -->
                        <button class="th-dv__landing-showall" :aria-label="t('teamhub', 'Show all decisions')" @click="openAllDecisions">
                            <span class="th-dv__landing-showall-icon" aria-hidden="true">
                                <FormatListBulletedIcon :size="18" />
                            </span>
                            <span class="th-dv__landing-showall-label">{{ t('teamhub', 'All decisions') }}</span>
                            <span class="th-dv__landing-showall-sub">{{ t('teamhub', 'Browse across all categories') }}</span>
                            <ChevronRightIcon :size="16" class="th-dv__landing-showall-chevron" aria-hidden="true" />
                        </button>

                        <!-- Loading -->
                        <div v-if="categoriesLoading" class="th-dv__state">
                            <NcLoadingIcon :size="32" />
                        </div>

                        <!-- No categories -->
                        <NcEmptyContent
                            v-else-if="!categories.length"
                            :name="t('teamhub', 'No categories yet')"
                            :description="t('teamhub', 'A team admin can add categories under Manage team → Decisions.')"
                            class="th-dv__state">
                            <template #icon><FolderOutlineIcon :size="48" /></template>
                        </NcEmptyContent>

                        <!-- 2-column category grid -->
                        <ul v-else class="th-dv__landing-grid" role="list">
                            <li
                                v-for="cat in categories"
                                :key="cat.id"
                                role="listitem">
                                <button
                                    class="th-dv__cat-card"
                                    :aria-label="t('teamhub', 'Open {name} decisions', { name: cat.name })"
                                    @click="openCategory(cat)">
                                    <span class="th-dv__cat-card-icon" aria-hidden="true">
                                        <component :is="categoryIconComponent(cat.icon)" :size="22" />
                                    </span>
                                    <span class="th-dv__cat-card-body">
                                        <span class="th-dv__cat-card-name">{{ cat.name }}</span>
                                        <span v-if="cat.description" class="th-dv__cat-card-desc">{{ cat.description }}</span>
                                    </span>
                                    <ChevronRightIcon :size="15" class="th-dv__cat-card-chevron" aria-hidden="true" />
                                </button>
                            </li>
                        </ul>
                    </template>
                </div>
            </template>

            <!-- ════ LIST VIEW ════ -->
            <template v-else-if="view === 'list'">
                <div v-if="loading && !grouped.length" class="th-dv__state">
                    <NcLoadingIcon :size="36" />
                </div>
                <div v-else-if="error" class="th-dv__state th-dv__state--error" role="alert">
                    <AlertCircleOutlineIcon :size="28" aria-hidden="true" />
                    <p>{{ t('teamhub', 'Could not load decisions. Please try again.') }}</p>
                    <NcButton variant="secondary" @click="loadPage(null)">{{ t('teamhub', 'Retry') }}</NcButton>
                </div>
                <NcEmptyContent
                    v-else-if="!loading && !grouped.length"
                    :name="emptyTitle"
                    :description="emptyDescription"
                    class="th-dv__state">
                    <template #icon><GavelIcon :size="56" /></template>
                </NcEmptyContent>
                <div v-else class="th-dv__sections">
                    <section
                        v-for="group in grouped"
                        :key="group.category"
                        class="th-dv__section"
                        :aria-label="group.category">
                        <div v-if="!activeCategory" class="th-dv__section-header">
                            <TagIcon :size="14" aria-hidden="true" />
                            <span class="th-dv__section-title">{{ group.category }}</span>
                            <span class="th-dv__section-count" :aria-label="n('teamhub', '{n} decision', '{n} decisions', group.items.length, { n: group.items.length })">
                                {{ group.items.length }}
                            </span>
                        </div>
                        <ul class="th-dv__cards" role="list">
                            <li
                                v-for="d in group.items"
                                :key="d.id"
                                class="th-dv__card"
                                :class="[`th-dv__card--${d.status}`, { 'th-dv__card--target': decisionsTargetMessageId === d.messageId }]"
                                :ref="`row-${d.messageId}`"
                                role="listitem">
                                <button
                                    class="th-dv__card-btn"
                                    :aria-expanded="selected && selected.id === d.id"
                                    :aria-label="t('teamhub', 'View decision: {q}', { q: d.question || t('teamhub', 'Untitled') })"
                                    @click="selectDecision(d)">
                                    <span class="th-dv__card-accent" aria-hidden="true" />
                                    <span class="th-dv__card-body">
                                        <span class="th-dv__card-subject">{{ d.question || t('teamhub', 'Untitled decision') }}</span>
                                        <span class="th-dv__card-meta">
                                            <span class="th-dv__impact" :class="`th-dv__impact--${d.impact}`">{{ impactLabel(d.impact) }}</span>
                                            <span v-if="decisionsLevelEnabled && d.level" class="th-dv__level-badge">{{ levelLabel(d.level) }}</span>
                                            <span class="th-dv__card-date" :title="fullDate(d.createdAt)">{{ relativeDate(d.createdAt) }}</span>
                                        </span>
                                    </span>
                                    <span class="th-dv__status-pill" :class="`th-dv__status-pill--${d.status}`">{{ statusLabel(d.status) }}</span>
                                    <ChevronRightIcon :size="16" class="th-dv__card-chevron" aria-hidden="true" />
                                </button>
                            </li>
                        </ul>
                    </section>
                    <div v-if="nextBefore" class="th-dv__load-more">
                        <NcButton variant="secondary" :disabled="loading" @click="loadMore">
                            {{ loading ? t('teamhub', 'Loading…') : t('teamhub', 'Load more') }}
                        </NcButton>
                    </div>
                </div>
            </template>

                        <!-- ── Detail panel (slide in from right) ── -->
            <transition name="th-dv-detail">
                <div v-if="selected" class="th-dv__detail" role="complementary" :aria-label="t('teamhub', 'Decision detail')">

                    <!-- Detail header -->
                    <div class="th-dv__detail-header">
                        <button
                            class="th-dv__detail-back"
                            :aria-label="t('teamhub', 'Back to decisions list')"
                            @click="selected = null">
                            <ChevronLeftIcon :size="18" aria-hidden="true" />
                            {{ t('teamhub', 'Back') }}
                        </button>
                        <span class="th-dv__status-pill th-dv__detail-status" :class="`th-dv__status-pill--${selected.status}`">
                            {{ statusLabel(selected.status) }}
                        </span>
                        <button
                            class="th-dv__detail-close"
                            :aria-label="t('teamhub', 'Close detail')"
                            :title="t('teamhub', 'Close')"
                            @click="selected = null">
                            <CloseIcon :size="18" />
                        </button>
                    </div>

                    <!-- Two-column body: left = content, right = approval + audit -->
                    <div class="th-dv__detail-body">

                    <!-- LEFT COLUMN — content -->
                    <div class="th-dv__detail-col th-dv__detail-col--left">

                    <!-- Meta grid -->
                    <dl class="th-dv__detail-meta">
                        <div class="th-dv__detail-meta-row">
                            <dt>{{ t('teamhub', 'Impact') }}</dt>
                            <dd><span class="th-dv__impact" :class="`th-dv__impact--${selected.impact}`">{{ impactLabel(selected.impact) }}</span></dd>
                        </div>
                        <div v-if="decisionsLevelEnabled" class="th-dv__detail-meta-row">
                            <dt>{{ t('teamhub', 'Level') }}</dt>
                            <dd><span class="th-dv__level-chip" :class="`th-dv__level-chip--${selected.level || 'operational'}`">{{ levelLabel(selected.level) }}</span></dd>
                        </div>
                        <div v-if="selected.category" class="th-dv__detail-meta-row">
                            <dt>{{ t('teamhub', 'Category') }}</dt>
                            <dd><span class="th-dv__detail-tag">{{ selected.category }}</span></dd>
                        </div>
                        <div class="th-dv__detail-meta-row">
                            <dt>{{ t('teamhub', 'Proposed by') }}</dt>
                            <dd>{{ selected.proposedBy }}</dd>
                        </div>
                        <div class="th-dv__detail-meta-row">
                            <dt>{{ t('teamhub', 'Date') }}</dt>
                            <dd :title="fullDate(selected.createdAt)">{{ relativeDate(selected.createdAt) }}</dd>
                        </div>
                        <div v-if="(selected.status === 'approved' || selected.status === 'decided') && selected.answeredBy" class="th-dv__detail-meta-row">
                            <dt>{{ t('teamhub', 'Decided by') }}</dt>
                            <dd>{{ selected.answeredBy }} <span v-if="selected.decidedAt" class="th-dv__detail-meta-sub">· {{ relativeDate(selected.decidedAt) }}</span></dd>
                        </div>
                    </dl>

                    <!-- Subject — v3.71.3 moved from above the metadata grid
                         to sit directly above the Final proposal block, so
                         the reader sees the question right next to its
                         resolved answer. -->
                    <h2 class="th-dv__detail-question">{{ selected.question || t('teamhub', 'Untitled decision') }}</h2>

                    <!-- Final proposal block -->
                    <div v-if="(selected.status === 'approved' || selected.status === 'finalized' || selected.status === 'decided') && selected.selectedAnswer" class="th-dv__detail-answer">
                        <span class="th-dv__detail-answer-label">
                            <CheckCircleIcon :size="15" aria-hidden="true" />
                            {{ t('teamhub', 'Final proposal') }}
                        </span>
                        <p class="th-dv__detail-answer-text">{{ selected.selectedAnswer }}</p>
                    </div>

                    <!-- Withdrawal / denial reason block -->
                    <div v-if="(selected.status === 'withdrawn' || selected.status === 'denied') && selected.withdrawnReason" class="th-dv__detail-withdrawn">
                        <span class="th-dv__detail-section-label">
                            {{ selected.status === 'denied' ? t('teamhub', 'Reason for denial') : t('teamhub', 'Reason for withdrawal') }}
                        </span>
                        <p class="th-dv__detail-withdrawn-text">{{ selected.withdrawnReason }}</p>
                    </div>

                    <!-- Source — combines proposer's source-of-context (URL/text)
                         with the canonical proposal document and any attachments
                         copied into .proposals/{decisionId}/ on finalize (v3.71.2).
                         v3.71.10 — sourceType==='document' rows (auto-set by
                         pre-3.71.10 finalize) are excluded from the textual
                         line: the .md path is already presented in the file
                         list below. -->
                    <div
                        v-if="(selected.sourceType && selected.sourceType !== 'none' && selected.sourceType !== 'document' && selected.sourceRef) || sourceFiles.length || sourcesLoading"
                        class="th-dv__detail-source">
                        <span class="th-dv__detail-section-label">{{ t('teamhub', 'Source') }}</span>

                        <!-- Proposer's original source URL — opens in the
                             read-only iframe viewer. -->
                        <button
                            v-if="selected.sourceType === 'url' && selected.sourceRef"
                            type="button"
                            class="th-dv__detail-source-link th-dv__detail-source-link--button"
                            :title="t('teamhub', 'Open {url} in read-only viewer', { url: selected.sourceRef })"
                            @click="openSourceUrl(selected.sourceRef)">
                            <OpenInNewIcon :size="14" aria-hidden="true" />
                            <span class="th-dv__detail-source-link-text">{{ selected.sourceRef }}</span>
                        </button>
                        <span
                            v-else-if="selected.sourceRef && selected.sourceType !== 'document'"
                            class="th-dv__detail-source-text">{{ selected.sourceRef }}</span>

                        <!-- Source files list — proposal .md + attachments copied at finalize. -->
                        <div v-if="sourcesLoading" class="th-dv__detail-source-loading">
                            <NcLoadingIcon :size="16" />
                            <span>{{ t('teamhub', 'Loading source files…') }}</span>
                        </div>
                        <ul
                            v-else-if="sourceFiles.length"
                            class="th-dv__link-list"
                            :aria-label="t('teamhub', 'Source files for this decision')">
                            <li
                                v-for="f in sourceFiles"
                                :key="f.file_id"
                                class="th-dv__link-item">
                                <button
                                    type="button"
                                    class="th-dv__link-row"
                                    :title="t('teamhub', 'Open {name} in read-only viewer', { name: f.name })"
                                    @click="openSourceFile(f)">
                                    <CheckCircleIcon v-if="f.is_proposal" :size="16" class="th-dv__link-icon th-dv__link-icon--proposal" aria-hidden="true" />
                                    <FileDocumentOutlineIcon v-else :size="16" class="th-dv__link-icon" aria-hidden="true" />
                                    <span class="th-dv__link-label">{{ f.name }}</span>
                                    <span v-if="f.is_proposal" class="th-dv__link-pill th-dv__link-pill--proposal">
                                        {{ t('teamhub', 'Final proposal') }}
                                    </span>
                                    <span v-if="f.size > 0" class="th-dv__link-meta">
                                        {{ formatFileSize(f.size) }}
                                    </span>
                                </button>
                            </li>
                        </ul>
                    </div>

                    <!-- Actions -->
                    <div class="th-dv__detail-actions">
                        <NcButton
                            v-if="(selected.status === 'open' || selected.status === 'proposed') && canActOn(selected)"
                            variant="tertiary"
                            @click="openSupersede(selected)">
                            <template #icon><SwapHorizontalIcon :size="16" /></template>
                            {{ t('teamhub', 'Supersede') }}
                        </NcButton>
                    </div>

                    </div><!-- /th-dv__detail-col--left -->

                    <!-- RIGHT COLUMN — approval (top) + audit trail (below) -->
                    <div class="th-dv__detail-col th-dv__detail-col--right">

                    <!-- ── Approval block (v3.71.3) ──
                         Replaces the previous inline Approve/Deny buttons +
                         deny-modal pattern. A single textarea captures the
                         approver's rationale, which is mandatory for both
                         Approve (green) and Deny (red). The reason becomes
                         part of the audit trail. Approver-gated; the server
                         re-checks the m:n category-approver list. -->
                    <div
                        v-if="selected.status === 'finalized' && canApprove(selected)"
                        class="th-dv__detail-approval">
                        <span class="th-dv__detail-section-label">{{ t('teamhub', 'Approval') }}</span>

                        <label class="th-dv__approval-label" :for="`th-dv-approval-reason-${selected.id}`">
                            {{ t('teamhub', 'Reason') }}
                            <span class="th-dv__approval-required" aria-hidden="true">*</span>
                        </label>
                        <textarea
                            :id="`th-dv-approval-reason-${selected.id}`"
                            v-model="approvalReason"
                            class="th-dv__approval-textarea"
                            rows="3"
                            :placeholder="t('teamhub', 'Briefly explain your decision — this becomes part of the audit trail.')"
                            :disabled="approving || denying"
                            :maxlength="approvalReasonMax"
                            :aria-describedby="`th-dv-approval-counter-${selected.id}`" />
                        <p
                            :id="`th-dv-approval-counter-${selected.id}`"
                            class="th-dv__approval-counter"
                            :class="{ 'th-dv__approval-counter--warn': approvalReason.length > approvalReasonMax - 50 }">
                            {{ approvalReason.length }} / {{ approvalReasonMax }}
                        </p>

                        <div class="th-dv__approval-actions">
                            <NcButton
                                variant="success"
                                :disabled="!approvalReasonValid || approving || denying"
                                @click="onApproveWithReason(selected)">
                                <template #icon><CheckCircleIcon :size="16" /></template>
                                {{ approving ? t('teamhub', 'Approving…') : t('teamhub', 'Approve') }}
                            </NcButton>
                            <NcButton
                                variant="error"
                                :disabled="!approvalReasonValid || approving || denying"
                                @click="onDenyWithReason(selected)">
                                <template #icon><CloseIcon :size="16" /></template>
                                {{ denying ? t('teamhub', 'Denying…') : t('teamhub', 'Deny') }}
                            </NcButton>
                        </div>
                    </div>

                    <!-- ── Linked tasks (Session B) ── -->
                    <div class="th-dv__detail-tasks">
                        <span class="th-dv__detail-section-label">{{ t('teamhub', 'Linked tasks') }}</span>

                        <div v-if="tasksLoading" class="th-dv__tasks-loading">
                            <NcLoadingIcon :size="16" />
                        </div>

                        <!-- Task list -->
                        <ul v-if="linkedTasks.length" class="th-dv__link-list">
                            <li v-for="task in linkedTasks" :key="task.id" class="th-dv__link-item">
                                <a
                                    :href="generateUrl('/' + task.task_path)"
                                    target="_blank"
                                    rel="noopener"
                                    class="th-dv__link-row"
                                    :title="task.task_path">
                                    <OpenInNewIcon :size="16" class="th-dv__link-icon" aria-hidden="true" />
                                    <span class="th-dv__link-label">{{ task.label || task.task_path }}</span>
                                </a>
                                <button
                                    v-if="canPerformDecisionActions"
                                    class="th-dv__link-remove"
                                    :aria-label="t('teamhub', 'Remove link')"
                                    :title="t('teamhub', 'Remove link')"
                                    @click="deleteTaskLink(task.id)">
                                    <CloseIcon :size="14" />
                                </button>
                            </li>
                        </ul>

                        <!-- Action buttons — gated on canPerformDecisionActions -->
                        <div v-if="canPerformDecisionActions" class="th-dv__tasks-actions">
                            <!-- Link task inline form -->
                            <div v-if="showLinkTaskForm" class="th-dv__tasks-link-form">
                                <input
                                    v-model="linkTaskPath"
                                    type="text"
                                    class="th-dv__tasks-link-input"
                                    :placeholder="t('teamhub', 'Paste task URL or path…')"
                                    :aria-label="t('teamhub', 'Task URL or path')"
                                    @keydown.enter="submitLinkTask">
                                <input
                                    v-model="linkTaskLabel"
                                    type="text"
                                    class="th-dv__tasks-link-input th-dv__tasks-link-input--label"
                                    :placeholder="t('teamhub', 'Label (optional)')"
                                    :aria-label="t('teamhub', 'Task label')">
                                <div class="th-dv__tasks-link-btns">
                                    <NcButton variant="primary" :disabled="!linkTaskPath.trim() || linkingTask" @click="submitLinkTask">
                                        {{ linkingTask ? t('teamhub', 'Linking…') : t('teamhub', 'Link') }}
                                    </NcButton>
                                    <NcButton variant="tertiary" @click="showLinkTaskForm = false">
                                        {{ t('teamhub', 'Cancel') }}
                                    </NcButton>
                                </div>
                            </div>

                            <!-- Buttons row -->
                            <div v-else class="th-dv__link-actions">
                                <NcButton variant="secondary" @click="showLinkTaskForm = true">
                                    <template #icon><LinkVariantIcon :size="16" /></template>
                                    <!-- TRANSLATORS: button to paste a URL linking an external task to this decision -->
                                    {{ t('teamhub', 'Link task') }}
                                </NcButton>
                                <NcButton variant="secondary" @click="showCreateTaskModal = true">
                                    <template #icon><PlusIcon :size="16" /></template>
                                    <!-- TRANSLATORS: button to open the Deck card creation modal, auto-linked to this decision -->
                                    {{ t('teamhub', 'Create task') }}
                                </NcButton>
                            </div>
                        </div>
                    </div>

                    <!-- ── Linked decisions (Session C) ── -->
                    <div class="th-dv__detail-dec-links">
                        <span class="th-dv__detail-section-label">{{ t('teamhub', 'Linked decisions') }}</span>

                        <div v-if="decLinksLoading" class="th-dv__dec-links-loading">
                            <NcLoadingIcon :size="16" />
                        </div>

                        <!-- Linked decision list -->
                        <ul v-if="linkedDecisions.length"
                            class="th-dv__link-list"
                            aria-live="polite"
                            :aria-label="t('teamhub', 'Linked decisions')">
                            <li
                                v-for="link in linkedDecisions"
                                :key="link.id"
                                class="th-dv__link-item">
                                <button
                                    class="th-dv__link-row"
                                    :title="link.peer_title"
                                    @click="selectDecisionById(link.peer_id)">
                                    <LinkVariantIcon :size="16" class="th-dv__link-icon" aria-hidden="true" />
                                    <span class="th-dv__link-label">{{ link.peer_title }}</span>
                                    <span
                                        v-if="decisionsLevelEnabled && link.peer_level"
                                        class="th-dv__link-pill th-dv__link-pill--level"
                                        :class="'th-dv__link-pill--level-' + link.peer_level">
                                        {{ levelLabel(link.peer_level) }}
                                    </span>
                                    <span
                                        class="th-dv__link-pill th-dv__link-pill--status"
                                        :class="'th-dv__link-pill--status-' + link.peer_status">
                                        {{ statusLabel(link.peer_status) }}
                                    </span>
                                </button>
                                <button
                                    v-if="canPerformDecisionActions"
                                    class="th-dv__link-remove"
                                    :aria-label="t('teamhub', 'Remove decision link')"
                                    :title="t('teamhub', 'Remove decision link')"
                                    @click="removeDecisionLink(link.id)">
                                    <CloseIcon :size="14" />
                                </button>
                            </li>
                        </ul>

                        <!-- Empty state: no links yet -->
                        <p v-else-if="!decLinksLoading" class="th-dv__link-empty">
                            {{ t('teamhub', 'No linked decisions') }}
                        </p>

                        <!-- Link button (gated) -->
                        <div v-if="canPerformDecisionActions" class="th-dv__link-actions">
                            <NcButton
                                variant="secondary"
                                @click="openDecisionPicker">
                                <template #icon><LinkVariantIcon :size="16" /></template>
                                <!-- TRANSLATORS: button to open a picker that links another decision to the current one -->
                                {{ t('teamhub', 'Link decision') }}
                            </NcButton>
                        </div>
                    </div>

                    <!-- ── Decision picker modal (Session C) ── -->
                    <NcModal
                        v-if="showDecisionPicker"
                        :name="t('teamhub', 'Link a decision')"
                        @close="closeDecisionPicker">
                        <div class="th-dv__dec-picker">
                            <p class="th-dv__dec-picker-hint">
                                {{ t('teamhub', 'Search for a decision to link') }}
                            </p>
                            <div class="th-dv__dec-picker-search">
                                <input
                                    ref="decPickerInput"
                                    v-model="decPickerQuery"
                                    type="search"
                                    class="th-dv__dec-picker-input"
                                    :placeholder="t('teamhub', 'Search decisions…')"
                                    :aria-label="t('teamhub', 'Search decisions')"
                                    @input="onDecPickerInput">
                            </div>
                            <div v-if="decPickerLoading" class="th-dv__dec-picker-loading">
                                <NcLoadingIcon :size="20" />
                            </div>
                            <ul v-else-if="decPickerResults.length"
                                class="th-dv__dec-picker-list"
                                aria-live="polite"
                                :aria-label="t('teamhub', 'Search results')">
                                <li
                                    v-for="d in decPickerResults"
                                    :key="d.id"
                                    class="th-dv__dec-picker-item">
                                    <button
                                        class="th-dv__dec-picker-item-btn"
                                        :disabled="decPickerLinking"
                                        @click="linkPickedDecision(d.id)">
                                        <span class="th-dv__dec-picker-item-title">{{ d.question }}</span>
                                        <span
                                            class="th-dv__dec-picker-item-status"
                                            :class="'th-dv__dec-picker-item-status--' + d.status">
                                            {{ d.status }}
                                        </span>
                                    </button>
                                </li>
                            </ul>
                            <p v-else-if="decPickerQuery.trim() && !decPickerLoading" class="th-dv__dec-picker-empty">
                                {{ t('teamhub', 'No decisions found') }}
                            </p>
                            <p v-else-if="!decPickerQuery.trim() && !decPickerLoading" class="th-dv__dec-picker-empty">
                                {{ t('teamhub', 'Type to search decisions') }}
                            </p>
                        </div>
                    </NcModal>

                    <!-- ── Audit trail (Session J) ── -->
                    <div class="th-dv__detail-audit">
                        <span class="th-dv__detail-section-label">{{ t('teamhub', 'Audit trail') }}</span>

                        <div v-if="auditLoading" class="th-dv__audit-loading">
                            <NcLoadingIcon :size="16" />
                            <span>{{ t('teamhub', 'Loading…') }}</span>
                        </div>

                        <div v-else-if="auditError" class="th-dv__audit-error" role="alert">
                            {{ t('teamhub', 'Could not load audit trail') }}
                        </div>

                        <ol v-else-if="auditItems.length" class="th-dv__audit-list" aria-live="polite">
                            <li
                                v-for="ev in auditItems"
                                :key="ev.id"
                                class="th-dv__audit-item"
                                :class="`th-dv__audit-item--${ev.transition}`">
                                <span class="th-dv__audit-dot" aria-hidden="true" />
                                <div class="th-dv__audit-body">
                                    <div class="th-dv__audit-header">
                                        <span class="th-dv__audit-action">{{ auditActionLabel(ev) }}</span>
                                        <span class="th-dv__audit-actor">{{ ev.actorDisplayName }}</span>
                                        <span class="th-dv__audit-time" :title="fullDate(ev.createdAt)">{{ relativeDate(ev.createdAt) }}</span>
                                    </div>
                                    <div v-if="auditPayloadText(ev)" class="th-dv__audit-payload">
                                        {{ auditPayloadText(ev) }}
                                    </div>
                                </div>
                            </li>
                        </ol>

                        <div v-else class="th-dv__audit-empty">
                            {{ t('teamhub', 'No events recorded yet') }}
                        </div>
                    </div>

                    </div><!-- /th-dv__detail-col--right -->
                    </div><!-- /th-dv__detail-body -->
                </div>
            </transition>
        </div>

        <!-- ── Supersede modal ── -->
        <NcModal
            v-if="supersedeTarget"
            :name="t('teamhub', 'Supersede decision')"
            @close="supersedeTarget = null">
            <div class="th-dv-modal">
                <p class="th-dv-modal__intro">
                    {{ t('teamhub', 'You are about to withdraw this decision and open a new proposal in its place.') }}
                </p>
                <blockquote class="th-dv-modal__quote">{{ supersedeTarget.question }}</blockquote>
                <div class="th-dv-modal__actions">
                    <NcButton variant="secondary" @click="supersedeTarget = null">{{ t('teamhub', 'Cancel') }}</NcButton>
                    <NcButton variant="primary" @click="confirmSupersede">{{ t('teamhub', 'Go to stream to propose') }}</NcButton>
                </div>
            </div>
        </NcModal>

        <!-- ── Read-only file viewer — v3.71.2 ──
             Opens any source file (proposal .md or copied attachment) in an
             iframe pointing at NC's native viewer. No edit affordances are
             rendered in our chrome; the iframe itself loads NC's Files UI
             which is read-only for users without update permission on the
             file. Owners/admins technically retain whatever permissions NC
             grants them; this modal is presentation-layer enforcement of
             the read-only contract (DESIGN.md option B). -->
        <NcModal
            v-if="viewerFile"
            size="large"
            :name="viewerFile.name"
            @close="closeSourceViewer">
            <div class="th-dv-viewer">
                <div class="th-dv-viewer__header">
                    <span class="th-dv-viewer__name">
                        <FileDocumentOutlineIcon :size="16" aria-hidden="true" />
                        {{ viewerFile.name }}
                        <span v-if="viewerFile.is_proposal" class="th-dv-viewer__badge">
                            {{ t('teamhub', 'Final proposal') }}
                        </span>
                    </span>

                    <!-- Download — for any local file -->
                    <a
                        v-if="viewerFile.file_id && viewerDownloadUrl"
                        :href="viewerDownloadUrl"
                        :download="viewerFile.name"
                        class="th-dv-viewer__newtab"
                        :title="t('teamhub', 'Download {name}', { name: viewerFile.name })">
                        <DownloadIcon :size="14" aria-hidden="true" />
                        {{ t('teamhub', 'Download') }}
                    </a>

                    <!-- External URL tab-out -->
                    <a
                        v-if="viewerFile.external_url"
                        :href="viewerFile.external_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="th-dv-viewer__newtab"
                        :title="t('teamhub', 'Open in new tab')">
                        <OpenInNewIcon :size="14" aria-hidden="true" />
                        {{ t('teamhub', 'New tab') }}
                    </a>

                    <span class="th-dv-viewer__readonly">{{ t('teamhub', 'Read-only') }}</span>
                </div>

                <!-- Body — rendered per mime category. v3.71.7: replaces the
                     iframe-based NC Files viewer (which loaded the full
                     Files app shell and didn't reliably open the right
                     file). Now we render directly: text/markdown as
                     sanitized HTML, images as <img>, PDFs via native
                     browser <embed>, everything else as a download
                     fallback. External URLs remain iframe-based since we
                     can't fetch arbitrary remote content. -->
                <div class="th-dv-viewer__body">
                    <!-- External URL — still iframe; if remote denies embedding,
                         the "New tab" affordance above is the escape hatch. -->
                    <iframe
                        v-if="viewerFile.external_url"
                        :src="viewerFile.external_url"
                        class="th-dv-viewer__frame"
                        :title="t('teamhub', 'Read-only preview of {name}', { name: viewerFile.name })"
                        sandbox="allow-same-origin allow-scripts allow-forms allow-popups allow-downloads" />

                    <!-- Loading state while we fetch text content -->
                    <div v-else-if="viewerLoading" class="th-dv-viewer__loading">
                        <NcLoadingIcon :size="32" />
                        <p>{{ t('teamhub', 'Loading…') }}</p>
                    </div>

                    <!-- Error state -->
                    <div v-else-if="viewerError" class="th-dv-viewer__error" role="alert">
                        <p>{{ t('teamhub', 'Could not load this file.') }}</p>
                        <p v-if="viewerDownloadUrl">
                            <a :href="viewerDownloadUrl" :download="viewerFile.name">
                                {{ t('teamhub', 'Download {name} instead', { name: viewerFile.name }) }}
                            </a>
                        </p>
                    </div>

                    <!-- Markdown / plain text — rendered as sanitized HTML.
                         v-html safe: the source went through DOMPurify in
                         renderViewerMarkdown(). -->
                    <article
                        v-else-if="viewerKind === 'markdown'"
                        class="th-dv-viewer__markdown"
                        v-html="viewerRenderedHtml" />

                    <pre
                        v-else-if="viewerKind === 'text'"
                        class="th-dv-viewer__text">{{ viewerTextContent }}</pre>

                    <!-- Image: NC's preview endpoint serves a JPEG/PNG.
                         Object-contained inside the viewer area, no shell. -->
                    <div v-else-if="viewerKind === 'image'" class="th-dv-viewer__image-wrap">
                        <img
                            :src="generateUrl('/core/preview') + '?fileId=' + viewerFile.file_id + '&x=1600&y=1200&a=true'"
                            :alt="viewerFile.name"
                            class="th-dv-viewer__image">
                    </div>

                    <!-- PDF: native browser viewer. <embed> is more reliable
                         than <iframe> for application/pdf and includes the
                         browser's own zoom/print controls. -->
                    <embed
                        v-else-if="viewerKind === 'pdf'"
                        :src="viewerDownloadUrl"
                        type="application/pdf"
                        class="th-dv-viewer__frame">

                    <!-- Office docs, archives, anything we can't safely
                         render in-browser. Honest fallback over a broken
                         iframe. -->
                    <div v-else class="th-dv-viewer__nopreview">
                        <FileDocumentOutlineIcon :size="48" aria-hidden="true" />
                        <p class="th-dv-viewer__nopreview-title">
                            {{ t('teamhub', 'Preview not available') }}
                        </p>
                        <p class="th-dv-viewer__nopreview-sub">
                            {{ t('teamhub', 'This file type cannot be previewed read-only in the browser. Download it to view its contents.') }}
                        </p>
                        <div class="th-dv-viewer__nopreview-actions">
                            <a
                                v-if="viewerDownloadUrl"
                                :href="viewerDownloadUrl"
                                :download="viewerFile.name"
                                class="th-dv-viewer__nopreview-btn th-dv-viewer__nopreview-btn--primary">
                                <DownloadIcon :size="16" aria-hidden="true" />
                                {{ t('teamhub', 'Download') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </NcModal>

        <!-- Session B: Create task modal — reuses AddTaskModal from the
             upcoming-tasks widget. On creation, the card path is auto-linked
             to the currently selected decision. -->
        <AddTaskModal
            v-if="showCreateTaskModal"
            :boards="resources.deck || []"
            @close="showCreateTaskModal = false"
            @created="onTaskCreated" />

    </div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { mapState, mapMutations }                from 'vuex'
import { generateUrl }                           from '@nextcloud/router'
import { CATEGORY_ICONS, CATEGORY_ICON_MAP } from '../lib/decisionCategoryIcons.js'
import { showError, showSuccess }                from '@nextcloud/dialogs'
import { NcButton, NcModal, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import DOMPurify from 'dompurify'

// MDI icons used directly in the template (separate from the category-icon set
// loaded from ../lib/decisionCategoryIcons.js, which is for picker rendering only)
import GavelIcon              from 'vue-material-design-icons/Gavel.vue'
import PlusIcon               from 'vue-material-design-icons/Plus.vue'
import TagIcon                from 'vue-material-design-icons/Tag.vue'
import ChevronRightIcon       from 'vue-material-design-icons/ChevronRight.vue'
import ChevronLeftIcon        from 'vue-material-design-icons/ChevronLeft.vue'
import CloseIcon              from 'vue-material-design-icons/Close.vue'
import CheckCircleIcon        from 'vue-material-design-icons/CheckCircle.vue'
import SortAscendingIcon      from 'vue-material-design-icons/SortAscending.vue'
import SortDescendingIcon     from 'vue-material-design-icons/SortDescending.vue'
import MessageOutlineIcon     from 'vue-material-design-icons/MessageOutline.vue'
import SwapHorizontalIcon     from 'vue-material-design-icons/SwapHorizontal.vue'
import AlertCircleOutlineIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import OpenInNewIcon          from 'vue-material-design-icons/OpenInNew.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import DownloadIcon            from 'vue-material-design-icons/Download.vue'
import FolderOutlineIcon       from 'vue-material-design-icons/FolderOutline.vue'
import MagnifyIcon             from 'vue-material-design-icons/Magnify.vue'
import LinkVariantIcon          from 'vue-material-design-icons/LinkVariant.vue'
import FormatListBulletedIcon  from 'vue-material-design-icons/FormatListBulleted.vue'
import AddTaskModal             from './AddTaskModal.vue'

const PAGE_SIZE = 50

export default {
    name: 'TeamDecisionsView',

    components: {
        NcButton, NcModal, NcEmptyContent, NcLoadingIcon,
        GavelIcon, PlusIcon, TagIcon, ChevronRightIcon, ChevronLeftIcon,
        CloseIcon, CheckCircleIcon,
        SortAscendingIcon, SortDescendingIcon,
        MessageOutlineIcon, SwapHorizontalIcon,
        AlertCircleOutlineIcon, OpenInNewIcon, FileDocumentOutlineIcon, DownloadIcon,
        FolderOutlineIcon, MagnifyIcon, FormatListBulletedIcon,
        LinkVariantIcon,
        AddTaskModal,
    },

    emits: ['propose-decision', 'propose-decision-superseding'],

    data() {
        return {
            // ── View state machine ──────────────────────────────────────────
            // 'landing' = category grid, 'list' = decisions list for a category
            view:           'landing',
            activeCategory: null,  // { id, name, icon, description } | null (null = all)

            // ── Landing grid ────────────────────────────────────────────────
            landingCategories:  [],
            categoriesLoading:  false,

            // ── Decision search (landing) ───────────────────────────────────
            // Empty searchQuery → show categories. Typed → fetch matching decisions.
            searchQuery:     '',
            searchResults:   [],
            searchLoading:   false,
            _searchDebounce: null,  // internal: setTimeout id for debouncing

            decisions:    [],
            loading:      false,
            error:        false,
            nextBefore:   null,

            filterStatus: null,
            filterImpact: null,
            filterLevel:  null,
            sort:         'recent',

            selected:        null,  // decision object currently shown in detail panel
            supersedeTarget: null,

            // Session H — approve/deny flow
            approverCategories: [],  // cache of {id, name, approvers: [uid]} for auth
            approverCategoriesLoaded: false,
            approving:     false,
            denying:       false,

            // v3.71.3 — inline approval block
            approvalReason:    '',
            approvalReasonMax: 500,
            approvalForDecisionId: null,

            // Session J — audit trail for the currently-selected decision
            auditItems:    [],
            auditLoading:  false,
            auditError:    false,
            auditDecisionId: null,

            // v3.71.2 — Source files for currently-selected decision
            sourceFiles:     [],        // [{ file_id, name, mime, size, is_proposal }]
            sourcesLoading:  false,
            sourcesDecisionId: null,    // tracks which decision the list belongs to

            // ── Session B: Linked tasks ─────────────────────────────────
            linkedTasks:         [],      // [{ id, decision_id, task_path, label, created_by, created_at }]
            tasksLoading:        false,
            tasksDecisionId:     null,    // tracks which decision the tasks belong to
            showLinkTaskForm:    false,   // toggle inline "Link task" URL form
            linkTaskPath:        '',      // v-model for the URL text input
            linkTaskLabel:       '',      // v-model for the label text input
            linkingTask:         false,   // saving indicator
            showCreateTaskModal: false,   // toggle AddTaskModal

            // ── Session C: Linked decisions ─────────────────────────────
            linkedDecisions:        [],     // [{ id, peer_id, peer_title, peer_status, peer_impact, created_by, created_at }]
            decLinksLoading:        false,
            decLinksDecisionId:     null,   // tracks which decision the links belong to
            showDecisionPicker:     false,  // modal toggle
            decPickerQuery:         '',     // search input v-model
            decPickerResults:       [],     // search results (filtered list of decisions)
            decPickerLoading:       false,  // search in flight
            decPickerLinking:       false,  // create-link in flight
            decPickerDebounceId:    null,   // debounce timer handle
            viewerFile:      null,      // file currently open in read-only viewer modal

            // v3.71.7 — type-aware viewer state. Replaces the iframe-loads-
            // the-NC-Files-app approach with direct rendering by mime.
            viewerLoading:      false,
            viewerError:        false,
            viewerTextContent:  '',     // raw text for .md / .txt rendering
            viewerRenderedHtml: '',     // sanitized HTML for .md
        }
    },

    computed: {
        ...mapState(['currentTeamId', 'members', 'decisionsTargetMessageId', 'decisionsConfig', 'resources']),

        decisionsLevelEnabled() {
            return !!(this.decisionsConfig && this.decisionsConfig.decisions_level_enabled)
        },

        // Expose landingCategories as `categories` for the grid template.
        categories() {
            return this.landingCategories
        },

        // Expose the module-level map as a computed so template can reach it.
        categoryIconMap() { return CATEGORY_ICON_MAP },

        // Session B: true if the current user's team level meets or exceeds
        // the configured decisions_action_min_level. Controls visibility of
        // "Link task", "Create task", and (future) "Link decision" buttons.
        canPerformDecisionActions() {
            const uid = this.$store.state.currentUser?.uid
            if (!uid) return false
            const member = this.$store.state.members.find(m => m.userId === uid)
            const userLevel = member ? (member.level || 1) : 1
            const minLevel = this.$store.state.decisionsConfig?.decisions_action_min_level ?? 1
            return userLevel >= minLevel
        },

        statusChips() {
            return [
                { value: null,        label: t('teamhub', 'All') },
                { value: 'open',      label: t('teamhub', 'Open') },
                { value: 'finalized', label: t('teamhub', 'Awaits approval') },
                { value: 'approved',  label: t('teamhub', 'Approved') },
                { value: 'denied',    label: t('teamhub', 'Denied') },
                { value: 'withdrawn', label: t('teamhub', 'Withdrawn') },
            ]
        },

        impactChips() {
            return [
                { value: null,     label: t('teamhub', 'Any impact') },
                { value: 'low',    label: t('teamhub', 'Low') },
                { value: 'medium', label: t('teamhub', 'Medium') },
                { value: 'high',   label: t('teamhub', 'High') },
            ]
        },

        levelChips() {
            return [
                { value: null,          label: t('teamhub', 'Any level') },
                { value: 'operational', label: t('teamhub', 'Operational') },
                { value: 'tactical',    label: t('teamhub', 'Tactical') },
                { value: 'strategic',   label: t('teamhub', 'Strategic') },
            ]
        },

        sortLabel() {
            return this.sort === 'created'
                ? t('teamhub', 'Oldest first')
                : t('teamhub', 'Newest first')
        },

        grouped() {
            const NO_CAT = t('teamhub', 'General')
            const map = new Map()
            for (const d of this.decisions) {
                const key = d.category && d.category.trim() ? d.category.trim() : NO_CAT
                if (!map.has(key)) map.set(key, [])
                map.get(key).push(d)
            }
            const entries = [...map.entries()]
            const generalIdx = entries.findIndex(([k]) => k === NO_CAT)
            if (generalIdx > 0) {
                const [gen] = entries.splice(generalIdx, 1)
                entries.push(gen)
            }
            return entries.map(([category, items]) => ({ category, items }))
        },

        emptyTitle() {
            if (this.filterStatus === 'open')       return t('teamhub', 'No open decisions')
            if (this.filterStatus === 'finalized')  return t('teamhub', 'No decisions awaiting approval')
            if (this.filterStatus === 'approved')   return t('teamhub', 'No approved decisions')
            if (this.filterStatus === 'denied')     return t('teamhub', 'No denied decisions')
            if (this.filterStatus === 'withdrawn')  return t('teamhub', 'No withdrawn decisions')
            return t('teamhub', 'No decisions yet')
        },

        emptyDescription() {
            const hasFilter = this.filterStatus || this.filterImpact || this.filterLevel
            if (hasFilter) return t('teamhub', 'Try removing filters')
            return t('teamhub', 'Propose the first decision using the button above or from the message stream')
        },

        currentUserId() {
            return window.OC?.currentUser || ''
        },

        /**
         * v3.71.3 — true when the inline approval block's textarea contains
         * a non-empty reason within the length cap. Both Approve and Deny
         * require this; the buttons are disabled until it passes.
         */
        approvalReasonValid() {
            const r = (this.approvalReason || '').trim()
            return r.length > 0 && r.length <= this.approvalReasonMax
        },

        /**
         * v3.71.7 — kind of content to render in the viewer modal, derived
         * from mime + filename. Returns one of: 'markdown' | 'text' |
         * 'image' | 'pdf' | 'other'. Drives the v-else-if cascade in the
         * viewer body template.
         */
        viewerKind() {
            if (!this.viewerFile) return null
            const mime = (this.viewerFile.mime || '').toLowerCase()
            const name = (this.viewerFile.name || '').toLowerCase()
            // v3.71.9 — filename FIRST. NC's mime detection often reports .md
            // as text/plain, which would otherwise route the file into the
            // pre-formatted text renderer instead of markdown.
            if (name.endsWith('.md') || name.endsWith('.markdown') || mime === 'text/markdown') {
                return 'markdown'
            }
            if (name.endsWith('.pdf') || mime === 'application/pdf') {
                return 'pdf'
            }
            if (mime.startsWith('image/')) {
                return 'image'
            }
            if (mime.startsWith('text/') || ['application/json', 'application/xml'].includes(mime)) {
                return 'text'
            }
            return 'other'
        },

        /**
         * v3.71.7 — DAV download URL for the file. Used by:
         *   - Download header button
         *   - <embed> src for PDFs
         *   - Fallback link on the "no preview" panel
         * For external URLs we return null so the download affordance hides.
         */
        viewerDownloadUrl() {
            if (!this.viewerFile || !this.viewerFile.file_id) return null
            // v3.71.10 — our own download endpoint serves the raw bytes with
            // the right Content-Type. ?download=1 toggles
            // Content-Disposition: attachment for the Download button (the
            // browser will save instead of preview).
            return generateUrl(`/apps/teamhub/api/v1/files/${this.viewerFile.file_id}/content?download=1`)
        },
    },

    watch: {
        currentTeamId(newId) {
            this.selected = null
            // Drop the cached categories — they're team-scoped.
            this.approverCategories = []
            this.approverCategoriesLoaded = false
            this.reload()
            this.ensureCategoriesLoaded()
        },

        async decisionsTargetMessageId(messageId) {
            if (!messageId) return
            // If we're still on the landing grid, switch to "show all" so the
            // target decision is visible. openAllDecisions() is async (it
            // triggers loadPage); we must await for the list to populate
            // before scrolling/selecting, otherwise the decisions array is
            // still empty and scrollAndSelectTarget finds nothing.
            if (this.view === 'landing') {
                this.openAllDecisions()
                await this.$nextTick()
                // Wait for loadPage to finish populating decisions.
                let guard = 30  // ~3s max
                while (this.loading && guard-- > 0) {
                    await new Promise(r => setTimeout(r, 100))
                }
            }
            this.scrollAndSelectTarget(messageId)
        },

        // Session J — load audit timeline when the user opens a decision.
        // v3.71.2 — also load source files (proposal .md + attachments).
        // Uses id watch so we don't refetch on every property mutation of
        // the same selected object.
        selected(newVal) {
            const id = newVal?.id || null
            if (id !== this.auditDecisionId) {
                this.auditDecisionId = id
                if (id) {
                    this.loadAudit(id)
                } else {
                    this.auditItems   = []
                    this.auditError   = false
                    this.auditLoading = false
                }
            }
            if (id !== this.sourcesDecisionId) {
                this.sourcesDecisionId = id
                if (id) {
                    this.loadSources(id)
                } else {
                    this.sourceFiles    = []
                    this.sourcesLoading = false
                }
            }
            // Session B: load task links when a decision is selected.
            if (id !== this.tasksDecisionId) {
                this.tasksDecisionId = id
                this.showLinkTaskForm = false
                this.linkTaskPath     = ''
                this.linkTaskLabel    = ''
                if (id) {
                    this.loadTasks(id)
                } else {
                    this.linkedTasks  = []
                    this.tasksLoading = false
                }
            }
            // Session C: load decision-decision links when a decision is selected.
            if (id !== this.decLinksDecisionId) {
                this.decLinksDecisionId = id
                if (id) {
                    this.loadDecisionLinks(id)
                } else {
                    this.linkedDecisions = []
                    this.decLinksLoading = false
                }
            }
            // v3.71.3 — reset the inline approval textarea whenever the
            // selected decision changes, so a reason typed for one proposal
            // can't accidentally apply to another.
            if (id !== this.approvalForDecisionId) {
                this.approvalForDecisionId = id
                this.approvalReason = ''
            }
        },

        /**
         * v3.71.7 — when the viewer opens with a text-based file (.md, .txt),
         * fetch its bytes so we can render them directly. For images, PDFs
         * and other types, the template renders without needing a fetch.
         */
        viewerFile: {
            handler(file) {
                if (!file || !file.file_id) return
                if (this.viewerKind === 'markdown' || this.viewerKind === 'text') {
                    this.fetchViewerTextContent()
                }
            },
            immediate: false,
        },
    },

    async mounted() {
        // If a navigation target was set BEFORE this component mounted
        // (typical case: user clicked a decision card in DecisionsWidget,
        // which dispatched SET_DECISIONS_TARGET then SET_VIEW('decisions')),
        // the watcher on decisionsTargetMessageId never fires because the
        // value didn't change after we existed. Honor it here.
        if (this.decisionsTargetMessageId) {
            // Skip the landing entirely — jump straight to the "all" list
            // so scrollAndSelectTarget has something to scroll into.
            this.openAllDecisions()
            // Wait for loadPage to populate decisions.
            let guard = 30
            while (this.loading && guard-- > 0) {
                await new Promise(r => setTimeout(r, 100))
            }
            this.scrollAndSelectTarget(this.decisionsTargetMessageId)
            this.ensureCategoriesLoaded()
            // Still fetch categories for when the user clicks Back.
            this.fetchLandingCategories()
            return
        }
        // Default flow: start on landing.
        this.fetchLandingCategories()
        this.ensureCategoriesLoaded()
    },

    methods: {
        t, n, generateUrl,
        ...mapMutations(['SET_VIEW', 'SET_DECISIONS_TARGET']),

        // ── Landing view ────────────────────────────────────────────

        // Returns the MDI component for a category icon name,
        // falling back to FolderOutlineIcon for unknown/null names.
        categoryIconComponent(name) {
            return (name && CATEGORY_ICON_MAP[name]) || FolderOutlineIcon
        },

        async fetchLandingCategories() {
            this.categoriesLoading = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/manage/categories`)
                )
                this.landingCategories = Array.isArray(data?.items) ? data.items : []
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] fetchLandingCategories error:', err)
                // Non-fatal — show "Show all" tile only
                this.landingCategories = []
            } finally {
                this.categoriesLoading = false
            }
        },

        goToLanding() {
            this.view           = 'landing'
            this.activeCategory = null
            this.decisions      = []
            this.nextBefore     = null
            this.selected       = null
            this.filterStatus   = null
            this.filterImpact   = null
            this.filterLevel    = null
            this.searchQuery    = ''
            this.searchResults  = []
        },

        // ── Decision search (landing) ───────────────────────────────────

        // Debounced — fires 250ms after the user stops typing.
        onSearchInput() {
            if (this._searchDebounce) clearTimeout(this._searchDebounce)
            const q = this.searchQuery.trim()
            if (!q) {
                this.searchResults = []
                this.searchLoading = false
                return
            }
            this._searchDebounce = setTimeout(() => this.performSearch(q), 250)
        },

        async performSearch(q) {
            this.searchLoading = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions`),
                    { params: { limit: 50, sort: 'recent', q } }
                )
                this.searchResults = data.items || []
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] search error:', err)
                this.searchResults = []
            } finally {
                this.searchLoading = false
            }
        },

        // Clicking a search result opens its category list + selects the decision.
        async selectFromSearch(d) {
            // Find the matching landing category by name (or null = "all")
            const cat = this.landingCategories.find(c => c.name === d.category) || null
            if (cat) {
                this.openCategory(cat)
            } else {
                this.openAllDecisions()
            }
            // Wait for the list to load, then select
            this.$nextTick(() => {
                // Add the decision to the local list if not present, then select
                if (!this.decisions.find(x => x.id === d.id)) {
                    this.decisions = [d, ...this.decisions]
                }
                this.selected = d
            })
        },

        openCategory(cat) {
            this.activeCategory = cat
            this.view           = 'list'
            this.decisions      = []
            this.nextBefore     = null
            this.selected       = null
            this.filterStatus   = null
            this.filterImpact   = null
            this.filterLevel    = null
            this.loadPage(null)
        },

        openAllDecisions() {
            this.activeCategory = null
            this.view           = 'list'
            this.decisions      = []
            this.nextBefore     = null
            this.selected       = null
            this.filterStatus   = null
            this.filterImpact   = null
            this.filterLevel    = null
            this.loadPage(null)
        },

        // ── Data ────────────────────────────────────────────────────

        async reload() {
            this.decisions  = []
            this.nextBefore = null
            this.selected   = null
            this.error      = false
            await this.loadPage(null)
            if (this.decisionsTargetMessageId) {
                this.scrollAndSelectTarget(this.decisionsTargetMessageId)
            }
        },

        async loadPage(before) {
            this.loading = true
            try {
                const { default: axios }  = await import(/* webpackChunkName: "axios" */ '@nextcloud/axios')
                const { generateUrl }     = await import(/* webpackChunkName: "router" */ '@nextcloud/router')
                const params = { limit: PAGE_SIZE, sort: this.sort }
                if (this.filterStatus) params.status = this.filterStatus
                if (this.filterImpact) params.impact = this.filterImpact
                if (this.filterLevel)  params.level  = this.filterLevel
                if (this.activeCategory) params.category = this.activeCategory.name
                if (before) params.before = before
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions`),
                    { params }
                )
                this.decisions  = before ? [...this.decisions, ...(data.items || [])] : (data.items || [])
                this.nextBefore = data.nextBefore || null
                this.error      = false
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] loadPage error:', err)
                this.error = true
            } finally {
                this.loading = false
            }
        },

        loadMore() {
            if (this.nextBefore && !this.loading) this.loadPage(this.nextBefore)
        },

        // ── Filters / sort ──────────────────────────────────────────

        setStatusFilter(value) {
            this.filterStatus = value
            this.decisions = []
            this.loadPage(null)
        },

        setImpactFilter(value) {
            this.filterImpact = value
            this.decisions = []
            this.loadPage(null)
        },

        setLevelFilter(value) {
            this.filterLevel = value
            this.decisions = []
            this.loadPage(null)
        },

        toggleSort() {
            this.sort = this.sort === 'recent' ? 'created' : 'recent'
            this.decisions = []
            this.loadPage(null)
        },

        // ── Selection ───────────────────────────────────────────────

        selectDecision(d) {
            this.selected = this.selected?.id === d.id ? null : d
        },

        // ── Navigation ──────────────────────────────────────────────

        navigateToStream(decision) {
            this.SET_DECISIONS_TARGET(decision.messageId)
            this.SET_VIEW('msgstream')
        },

        // ── Target scroll ───────────────────────────────────────────

        scrollAndSelectTarget(messageId) {
            const match = this.decisions.find(d => d.messageId === messageId)
            if (match) {
                this.selected = match
                this.$nextTick(() => {
                    const ref = this.$refs[`row-${messageId}`]
                    const el = Array.isArray(ref) ? ref[0] : ref
                    if (el?.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'center' })
                    this.SET_DECISIONS_TARGET(null)
                })
            }
        },

        // ── Supersede ───────────────────────────────────────────────

        openSupersede(decision) {
            this.supersedeTarget = decision
        },

        confirmSupersede() {
            this.$emit('propose-decision-superseding', this.supersedeTarget.id)
            this.supersedeTarget = null
        },

        // ── Approve / Deny — Session H ──────────────────────────────

        /**
         * Lazy-load the team's categories on first need. We use it locally
         * to decide whether to show the Approve / Deny buttons.
         */
        async ensureCategoriesLoaded() {
            if (this.approverCategoriesLoaded) return
            try {
                const { default: axios }  = await import(/* webpackChunkName: "axios" */ '@nextcloud/axios')
                const { generateUrl }     = await import(/* webpackChunkName: "router" */ '@nextcloud/router')
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/manage/categories`)
                )
                this.approverCategories = Array.isArray(data?.items) ? data.items : []
                this.approverCategoriesLoaded = true
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] failed to load categories (non-fatal):', err)
                this.approverCategoriesLoaded = true
            }
        },

        /**
         * UI gate for the Approve / Deny buttons.
         * Returns true when the current user is in the category's approver
         * list. If the decision has no category match (legacy free-text),
         * fall back to admin-level — same rule the backend applies.
         *
         * If categories haven't loaded yet, returns false; the UI will
         * re-render once ensureCategoriesLoaded() finishes.
         */
        canApprove(decision) {
            if (!this.approverCategoriesLoaded) return false
            if (decision.category) {
                const cat = this.approverCategories.find(c => c.name === decision.category)
                if (cat) {
                    return Array.isArray(cat.approvers) && cat.approvers.includes(this.currentUserId)
                }
            }
            // No category or unmatched — admin only.
            const m = this.members?.find(mem => mem.userId === this.currentUserId)
            return m && m.level >= 8
        },

        // v3.71.3 — inline-approval handlers. Both share the textarea state
        // (`approvalReason`) and require a non-empty trimmed reason. The old
        // confirmApprove (reason-less) + openDeny/closeDeny/confirmDeny modal
        // pattern is gone — the modal-based reason capture for deny moved
        // inline alongside approve.

        async onApproveWithReason(decision) {
            const reason = this.approvalReason.trim()
            if (!reason || !decision) return
            this.approving = true
            try {
                const updated = await this.$store.dispatch('approveDecision', {
                    decisionId: decision.id,
                    messageId:  decision.messageId,
                    reason,
                })
                this.applyDecisionUpdate(updated)
                this.approvalReason = ''
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] approve error:', err)
                const msg = err?.response?.data?.error || err.message
                // No browser popups — surface as an NC toast.
                showError(t('teamhub', 'Approval failed: {error}', { error: msg }))
            } finally {
                this.approving = false
            }
        },

        async onDenyWithReason(decision) {
            const reason = this.approvalReason.trim()
            if (!reason || !decision) return
            this.denying = true
            try {
                const updated = await this.$store.dispatch('denyDecision', {
                    decisionId: decision.id,
                    reason,
                    messageId:  decision.messageId,
                })
                this.applyDecisionUpdate(updated)
                this.approvalReason = ''
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] deny error:', err)
                const msg = err?.response?.data?.error || err.message
                showError(t('teamhub', 'Denial failed: {error}', { error: msg }))
            } finally {
                this.denying = false
            }
        },

        /**
         * Patch an updated decision back into the in-memory list and the
         * detail-panel selection, so the UI reflects the new status without
         * a full reload.
         */
        applyDecisionUpdate(updated) {
            const idx = this.decisions.findIndex(d => d.id === updated.id)
            if (idx >= 0) {
                // Vue 3-safe: $set was removed, plain assignment is reactive.
                // splice preserves array reactivity equally and reads cleaner
                // than index assignment for a same-length replacement.
                this.decisions.splice(idx, 1, updated)
            }
            if (this.selected && this.selected.id === updated.id) {
                this.selected = updated
                // Force an audit reload — the watcher won't fire because
                // the id didn't change, but a new transition just happened.
                this.loadAudit(updated.id)
            }
        },

        // ── Permissions ─────────────────────────────────────────────

        canActOn(decision) {
            if (decision.proposedBy === this.currentUserId) return true
            const m = this.members?.find(mem => mem.userId === this.currentUserId)
            return m && m.level >= 8
        },

        // ── Audit timeline — Session J ──────────────────────────────

        async loadAudit(decisionId) {
            if (!decisionId) return
            this.auditLoading = true
            this.auditError   = false
            try {
                const { default: axios }  = await import(/* webpackChunkName: "axios" */ '@nextcloud/axios')
                const { generateUrl }     = await import(/* webpackChunkName: "router" */ '@nextcloud/router')
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${decisionId}/audit`),
                )
                // Guard against stale responses if the user has switched
                // decisions while a previous request is still in-flight.
                if (this.auditDecisionId !== decisionId) return
                this.auditItems = Array.isArray(data?.items) ? data.items : []
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] loadAudit error:', err)
                if (this.auditDecisionId === decisionId) {
                    this.auditError = true
                }
            } finally {
                if (this.auditDecisionId === decisionId) {
                    this.auditLoading = false
                }
            }
        },

        // ── Source files — v3.71.2 ──────────────────────────────────
        //
        // Loads the contents of {team-folder}/.proposals/{decisionId}/ for
        // the currently-selected decision. Pre-v3.71.2 (flat layout) decisions
        // return a single .md file. Both shapes are normalised server-side.

        async loadSources(decisionId) {
            if (!decisionId) return
            this.sourcesLoading = true
            try {
                const { default: axios }  = await import(/* webpackChunkName: "axios" */ '@nextcloud/axios')
                const { generateUrl }     = await import(/* webpackChunkName: "router" */ '@nextcloud/router')
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${decisionId}/sources`),
                )
                // Guard against stale responses if the user has switched
                // decisions while a previous request is still in-flight.
                if (this.sourcesDecisionId !== decisionId) return
                const items = Array.isArray(data?.items) ? data.items : []
                // Sort: proposal .md first, then alphabetical by name. Keeps
                // the canonical document at the top of the list.
                items.sort((a, b) => {
                    if (a.is_proposal && !b.is_proposal) return -1
                    if (!a.is_proposal && b.is_proposal) return 1
                    return (a.name || '').localeCompare(b.name || '')
                })
                this.sourceFiles = items
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] loadSources error:', err)
                if (this.sourcesDecisionId === decisionId) {
                    this.sourceFiles = []
                }
            } finally {
                if (this.sourcesDecisionId === decisionId) {
                    this.sourcesLoading = false
                }
            }
        },

        // ── Session B: Linked tasks ─────────────────────────────────

        async loadTasks(decisionId) {
            this.tasksLoading = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${decisionId}/tasks`)
                )
                if (this.tasksDecisionId !== decisionId) return
                this.linkedTasks = data.items || []
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] loadTasks error:', err)
                if (this.tasksDecisionId === decisionId) {
                    this.linkedTasks = []
                }
            } finally {
                if (this.tasksDecisionId === decisionId) {
                    this.tasksLoading = false
                }
            }
        },

        async submitLinkTask() {
            if (!this.linkTaskPath.trim() || !this.selected) return
            this.linkingTask = true
            try {
                const { data } = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${this.selected.id}/tasks`),
                    {
                        task_path: this.linkTaskPath.trim(),
                        label: this.linkTaskLabel.trim() || null,
                    }
                )
                this.linkedTasks.push(data)
                this.linkTaskPath     = ''
                this.linkTaskLabel    = ''
                this.showLinkTaskForm = false
                showSuccess(t('teamhub', 'Task linked'))
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] submitLinkTask error:', err)
                showError(t('teamhub', 'Failed to link task: {error}', {
                    error: err?.response?.data?.error || err.message,
                }))
            } finally {
                this.linkingTask = false
            }
        },

        async deleteTaskLink(linkId) {
            if (!this.selected) return
            try {
                await axios.delete(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${this.selected.id}/tasks/${linkId}`)
                )
                this.linkedTasks = this.linkedTasks.filter(t => t.id !== linkId)
                showSuccess(t('teamhub', 'Task link removed'))
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] deleteTaskLink error:', err)
                showError(t('teamhub', 'Failed to remove task link: {error}', {
                    error: err?.response?.data?.error || err.message,
                }))
            }
        },

        // Called by AddTaskModal @created — auto-links the new card to
        // the currently selected decision.
        async onTaskCreated(cardInfo) {
            this.showCreateTaskModal = false
            if (!cardInfo?.path || !this.selected) return
            // Auto-link the new card to the current decision.
            try {
                const { data } = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${this.selected.id}/tasks`),
                    {
                        task_path: cardInfo.path,
                        label: cardInfo.title || null,
                    }
                )
                this.linkedTasks.push(data)
                showSuccess(t('teamhub', 'Task created and linked'))
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] onTaskCreated link error:', err)
                showError(t('teamhub', 'Task created but linking failed: {error}', {
                    error: err?.response?.data?.error || err.message,
                }))
            }
        },

        // ── Session C: Linked decisions ─────────────────────────────────

        /**
         * Load all decision ↔ decision links for the given decision.
         */
        async loadDecisionLinks(decisionId) {
            this.decLinksLoading = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${decisionId}/links`)
                )
                // Race-guard: ignore the response if the user has navigated to
                // a different decision while this request was in flight.
                if (this.decLinksDecisionId !== decisionId) return
                this.linkedDecisions = data.items || []
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] loadDecisionLinks error:', err)
                if (this.decLinksDecisionId === decisionId) {
                    this.linkedDecisions = []
                }
            } finally {
                if (this.decLinksDecisionId === decisionId) {
                    this.decLinksLoading = false
                }
            }
        },

        /**
         * Jump to a peer decision when its row is clicked in the linked-list.
         * Selects the peer if it's currently loaded in the list; otherwise
         * shows a brief warning so the user knows to filter/search for it.
         */
        selectDecisionById(peerId) {
            const match = this.decisions.find(d => d.id === peerId)
            if (match) {
                this.selected = match
                this.$nextTick(() => {
                    const ref = this.$refs[`row-${match.messageId}`]
                    const el = Array.isArray(ref) ? ref[0] : ref
                    if (el?.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'center' })
                })
            } else {
                showError(t('teamhub', 'That decision is not in the current list — clear filters to view it'))
            }
        },

        /**
         * Open the decision picker modal. Prefills search results with an
         * empty query — user types to filter.
         */
        openDecisionPicker() {
            this.showDecisionPicker = true
            this.decPickerQuery     = ''
            this.decPickerResults   = []
            this.decPickerLoading   = false
            this.$nextTick(() => {
                const input = this.$refs.decPickerInput
                if (input && typeof input.focus === 'function') input.focus()
            })
        },

        closeDecisionPicker() {
            this.showDecisionPicker = false
            this.decPickerQuery     = ''
            this.decPickerResults   = []
            if (this.decPickerDebounceId) {
                clearTimeout(this.decPickerDebounceId)
                this.decPickerDebounceId = null
            }
        },

        /**
         * Debounced search handler for the picker input.
         * Reuses the regular decisions list endpoint with the `q` filter the
         * backend already supports — keeps the picker results consistent
         * with the main list semantics (same auth, same gate, same sort).
         */
        onDecPickerInput() {
            if (this.decPickerDebounceId) clearTimeout(this.decPickerDebounceId)
            this.decPickerDebounceId = setTimeout(() => this.runDecPickerSearch(), 250)
        },

        async runDecPickerSearch() {
            const q = this.decPickerQuery.trim()
            if (!q) {
                this.decPickerResults = []
                this.decPickerLoading = false
                return
            }
            this.decPickerLoading = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions`),
                    { params: { q, limit: 20 } }
                )
                // Race-guard: only apply if the input still matches.
                if (this.decPickerQuery.trim() !== q) return
                // Exclude the current decision and any already-linked peers
                // from the picker — linking to self is rejected by the
                // backend, and re-linking an existing pair is a duplicate.
                const linkedIds = new Set(this.linkedDecisions.map(l => l.peer_id))
                const selfId    = this.selected ? this.selected.id : null
                this.decPickerResults = (data.items || [])
                    .filter(d => d.id !== selfId && !linkedIds.has(d.id))
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] runDecPickerSearch error:', err)
                this.decPickerResults = []
            } finally {
                this.decPickerLoading = false
            }
        },

        /**
         * POST a new link between selected.id and the picked peer.
         */
        async linkPickedDecision(targetId) {
            if (!this.selected || this.decPickerLinking) return
            this.decPickerLinking = true
            try {
                const { data } = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${this.selected.id}/links`),
                    { target_decision_id: targetId }
                )
                this.linkedDecisions.push(data)
                showSuccess(t('teamhub', 'Decision linked'))
                this.closeDecisionPicker()
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] linkPickedDecision error:', err)
                showError(t('teamhub', 'Failed to link decision: {error}', {
                    error: err?.response?.data?.error || err.message,
                }))
            } finally {
                this.decPickerLinking = false
            }
        },

        /**
         * DELETE an existing decision link by row id.
         */
        async removeDecisionLink(linkId) {
            if (!this.selected) return
            try {
                await axios.delete(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${this.selected.id}/links/${linkId}`)
                )
                this.linkedDecisions = this.linkedDecisions.filter(l => l.id !== linkId)
                showSuccess(t('teamhub', 'Decision link removed'))
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] removeDecisionLink error:', err)
                showError(t('teamhub', 'Failed to remove decision link: {error}', {
                    error: err?.response?.data?.error || err.message,
                }))
            }
        },

        /**
         * Open a source file in the in-app read-only viewer.
         * The viewer is an iframe that loads NC's own preview/viewer URL —
         * for .md, /apps/files/?fileid=…&openfile=true; for images, the
         * /core/preview endpoint; everything else falls back to NC's default
         * Files-app viewer for the file. The viewer modal has no edit
         * affordances, satisfying the "read-only environment" requirement.
         */
        openSourceFile(file) {
            this.viewerFile = file
        },

        /**
         * v3.71.3 — open the proposer's source URL in the same read-only
         * iframe viewer used for files. We wrap the URL in a virtual file
         * object so sourceViewerUrl() can detect it and skip the fileId
         * resolution path. If the target site denies iframe embedding via
         * X-Frame-Options, the iframe will render blank — we surface a
         * "Open in new tab" affordance in the viewer header to recover.
         */
        openSourceUrl(url) {
            if (!url) return
            // Best-effort label: hostname for http(s) URLs, raw string otherwise.
            let label = url
            try {
                const u = new URL(url)
                label = u.hostname + (u.pathname && u.pathname !== '/' ? u.pathname : '')
            } catch {
                // Not a parseable absolute URL — fall back to raw.
            }
            this.viewerFile = {
                file_id:      null,
                name:         label,
                mime:         'text/html',
                size:         0,
                is_proposal:  false,
                external_url: url,
            }
        },

        closeSourceViewer() {
            this.viewerFile = null
            this.viewerLoading      = false
            this.viewerError        = false
            this.viewerTextContent  = ''
            this.viewerRenderedHtml = ''
        },

        /**
         * v3.71.7 — generateUrl is imported as a top-level function; expose
         * it as an instance method so the template can call generateUrl(…)
         * directly (e.g. for the image preview src expression).
         */
        generateUrl,

        /**
         * v3.71.10 — fetch raw file content via our own download endpoint.
         * Previously used /f/{fileId}, which is NC's web redirect — it
         * returns an HTML "Files app shell" page, not the file's actual
         * bytes, so .md files rendered as "<!DOCTYPE html>". Now we hit a
         * TeamHub endpoint that resolves the fileId server-side (where we
         * can use IRootFolder::getById) and streams the raw content.
         */
        async fetchViewerTextContent() {
            if (!this.viewerFile || !this.viewerFile.file_id) return
            this.viewerLoading      = true
            this.viewerError        = false
            this.viewerTextContent  = ''
            this.viewerRenderedHtml = ''
            const targetFileId = this.viewerFile.file_id
            try {
                const url = generateUrl(`/apps/teamhub/api/v1/files/${targetFileId}/content`)
                const resp = await axios.get(url, {
                    responseType: 'text',
                    transformResponse: [data => data], // disable JSON-parse
                })
                if (!this.viewerFile || this.viewerFile.file_id !== targetFileId) return
                const text = typeof resp.data === 'string' ? resp.data : String(resp.data)
                this.viewerTextContent = text
                if (this.viewerKind === 'markdown') {
                    this.viewerRenderedHtml = this.renderViewerMarkdown(text)
                }
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] viewer fetch error:', err)
                if (this.viewerFile && this.viewerFile.file_id === targetFileId) {
                    this.viewerError = true
                }
            } finally {
                if (this.viewerFile && this.viewerFile.file_id === targetFileId) {
                    this.viewerLoading = false
                }
            }
        },

        /**
         * v3.71.7 — minimal markdown → HTML for the viewer, sanitized via
         * DOMPurify. Intentionally small: headings, bold/italic, lists,
         * inline code, code fences, links, paragraphs, hr, blockquotes.
         * The proposal documents TeamHub writes are predictable so we
         * don't need a full CommonMark engine. If we later need richer
         * markdown (tables, footnotes), swap this for the marked/markdown-it
         * dependency the project doesn't currently have.
         *
         * DOMPurify is the security boundary — even if the regex pipeline
         * here produced bad HTML, the sanitizer drops anything not on the
         * allowlist.
         */
        renderViewerMarkdown(text) {
            if (!text) return ''
            // 1. Escape HTML special chars so user text can't inject tags.
            const escape = s => s
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')

            let html = escape(text)

            // 2. Code fences (```…```), stashed behind placeholders so
            //    later rules don't process their contents.
            const fences = []
            html = html.replace(/```([\s\S]*?)```/g, (_, body) => {
                fences.push(`<pre><code>${body.replace(/^\n/, '')}</code></pre>`)
                return `\u0000${fences.length - 1}\u0000`
            })

            // 3. Inline code (`x`), same placeholder dance.
            const inlines = []
            html = html.replace(/`([^`\n]+)`/g, (_, code) => {
                inlines.push(`<code>${code}</code>`)
                return `\u0001${inlines.length - 1}\u0001`
            })

            // 4. Headings (process longest prefix first to avoid eating
            //    deeper levels with shallower regexes).
            html = html
                .replace(/^###### (.+)$/gm, '<h6>$1</h6>')
                .replace(/^##### (.+)$/gm, '<h5>$1</h5>')
                .replace(/^#### (.+)$/gm, '<h4>$1</h4>')
                .replace(/^### (.+)$/gm,  '<h3>$1</h3>')
                .replace(/^## (.+)$/gm,   '<h2>$1</h2>')
                .replace(/^# (.+)$/gm,    '<h1>$1</h1>')

            // 5. Horizontal rule (--- on its own line).
            html = html.replace(/^---$/gm, '<hr>')

            // 6. Bold + italic (** and __, * and _).
            html = html
                .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
                .replace(/__([^_\n]+)__/g,     '<strong>$1</strong>')
                .replace(/\*([^*\n]+)\*/g,     '<em>$1</em>')
                .replace(/_([^_\n]+)_/g,       '<em>$1</em>')

            // 7. Links: [text](url). target=_blank so links don't navigate
            //    away from the modal-hosting page. rel locks the opener.
            html = html.replace(
                /\[([^\]]+)\]\(([^)\s]+)\)/g,
                '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>',
            )

            // 8. Bullet lists (- item).
            html = html.replace(/((?:^- .+(?:\n|$))+)/gm, block => {
                const items = block.trimEnd().split('\n')
                    .map(line => `<li>${line.replace(/^- /, '')}</li>`)
                    .join('')
                return `<ul>${items}</ul>\n`
            })

            // 9. Blockquotes (> line).
            html = html.replace(/((?:^&gt; .+(?:\n|$))+)/gm, block => {
                const lines = block.trimEnd().split('\n')
                    .map(line => line.replace(/^&gt; /, ''))
                    .join(' ')
                return `<blockquote>${lines}</blockquote>\n`
            })

            // 10. Paragraphs: split on blank lines, wrap lines not already in
            //     a block element.
            html = html.split(/\n{2,}/).map(chunk => {
                const trimmed = chunk.trim()
                if (!trimmed) return ''
                if (/^<(h\d|ul|ol|li|pre|blockquote|hr|p)/i.test(trimmed)) return trimmed
                return `<p>${trimmed.replace(/\n/g, '<br>')}</p>`
            }).join('\n')

            // 11. Restore placeholders.
            html = html
                .replace(/\u0000(\d+)\u0000/g, (_, i) => fences[+i])
                .replace(/\u0001(\d+)\u0001/g, (_, i) => inlines[+i])

            // 12. Sanitize. Allowlist mirrors what we emit above.
            return DOMPurify.sanitize(html, {
                ALLOWED_TAGS: [
                    'a', 'p', 'br', 'strong', 'em', 'code', 'pre',
                    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                    'ul', 'ol', 'li', 'blockquote', 'hr',
                ],
                ALLOWED_ATTR: ['href', 'target', 'rel'],
            })
        },

        /** Format file size for the source list, e.g. 12.4 KB, 3.1 MB. */
        formatFileSize(bytes) {
            if (!Number.isFinite(bytes) || bytes < 0) return ''
            if (bytes < 1024) return `${bytes} B`
            const kb = bytes / 1024
            if (kb < 1024) return `${kb.toFixed(1)} KB`
            const mb = kb / 1024
            return `${mb.toFixed(1)} MB`
        },

        /**
         * Human label for the verb on an audit event line. Distinct from
         * statusLabel — these are past-tense events ("Proposed", "Commented"),
         * not states.
         */
        auditActionLabel(ev) {
            const map = {
                // TRANSLATORS: audit event verb — the decision was first proposed
                proposed:  t('teamhub', 'Proposed'),
                // TRANSLATORS: audit event verb — a comment was added
                commented: t('teamhub', 'Commented'),
                // TRANSLATORS: audit event verb — proposer finalized the proposal; now awaits approval
                finalized: t('teamhub', 'Finalized proposal'),
                // TRANSLATORS: audit event verb — proposal was withdrawn before approval
                withdrawn: t('teamhub', 'Withdrawn'),
                // TRANSLATORS: audit event verb — finalized proposal was approved
                approved:  t('teamhub', 'Approved'),
                // TRANSLATORS: audit event verb — finalized proposal was denied
                denied:    t('teamhub', 'Denied'),
                // TRANSLATORS: audit event verb — a task was linked to this decision
                task_linked:       t('teamhub', 'Linked task'),
                // TRANSLATORS: audit event verb — a task link was removed
                task_unlinked:     t('teamhub', 'Unlinked task'),
                // TRANSLATORS: audit event verb — another decision was linked to this one
                decision_linked:   t('teamhub', 'Linked decision'),
                // TRANSLATORS: audit event verb — a decision link was removed
                decision_unlinked: t('teamhub', 'Unlinked decision'),
            }
            return map[ev.transition] || ev.transition
        },

        /**
         * Returns the secondary line of text under each audit row, or ''
         * if there's nothing to show. Handles transition-specific payloads.
         */
        auditPayloadText(ev) {
            const p = ev.payload
            if (!p) return ''
            if (ev.transition === 'commented' && p.excerpt) {
                return `"${p.excerpt}"`
            }
            if (ev.transition === 'finalized' && p.excerpt) {
                return p.excerpt
            }
            if ((ev.transition === 'withdrawn' || ev.transition === 'denied' || ev.transition === 'approved') && p.reason) {
                // TRANSLATORS: prefix on the reason text in an audit event
                return t('teamhub', 'Reason: {reason}', { reason: p.reason })
            }
            if ((ev.transition === 'task_linked' || ev.transition === 'task_unlinked')) {
                return p.label || p.task_path || ''
            }
            if ((ev.transition === 'decision_linked' || ev.transition === 'decision_unlinked') && p.peer_title) {
                return p.peer_title
            }
            return ''
        },

        // ── Labels ──────────────────────────────────────────────────

        impactLabel(impact) {
            return { low: t('teamhub', 'Low'), medium: t('teamhub', 'Medium'), high: t('teamhub', 'High') }[impact] || impact
        },

        levelLabel(level) {
            return {
                // TRANSLATORS: Decision level — day-to-day operational decisions
                operational: t('teamhub', 'Operational'),
                // TRANSLATORS: Decision level — medium-term tactical decisions
                tactical:    t('teamhub', 'Tactical'),
                // TRANSLATORS: Decision level — long-term strategic decisions
                strategic:   t('teamhub', 'Strategic'),
            }[level] || t('teamhub', 'Operational')
        },

        statusLabel(status) {
            return {
                open:       t('teamhub', 'Open'),
                // TRANSLATORS: status pill — proposal has been finalized by the proposer and is awaiting an approver's decision
                finalized:  t('teamhub', 'Awaits approval'),
                approved:   t('teamhub', 'Approved'),
                denied:     t('teamhub', 'Denied'),
                withdrawn:  t('teamhub', 'Withdrawn'),
                // Legacy values that may still appear in DB for old rows
                proposed:   t('teamhub', 'Open'),
                decided:    t('teamhub', 'Approved'),
            }[status] || status
        },

        relativeDate(ts) {
            if (!ts) return ''
            const ms = typeof ts === 'number' ? ts * 1000 : Date.parse(ts)
            if (isNaN(ms)) return ''
            const diff = Date.now() - ms
            const days = Math.floor(diff / 86400000)
            if (days === 0) return t('teamhub', 'Today')
            if (days === 1) return t('teamhub', 'Yesterday')
            if (days < 7)  return n('teamhub', '{n} day ago', '{n} days ago', days, { n: days })
            return new Date(ms).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
        },

        fullDate(ts) {
            if (!ts) return ''
            const ms = typeof ts === 'number' ? ts * 1000 : Date.parse(ts)
            return isNaN(ms) ? '' : new Date(ms).toLocaleString()
        },
    },
}
</script>

<style scoped>
/* ── Shell ── */
.th-dv {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
    background: var(--color-main-background);
}

/* ── Toolbar ── */
.th-dv__toolbar {
    flex-shrink: 0;
    padding: 12px 20px 10px;
    border-bottom: 1px solid var(--color-border);
    background: var(--color-main-background);
}

.th-dv__filter-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

/* Breadcrumb elements */
.th-dv__breadcrumb-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--color-main-text);
}

.th-dv__breadcrumb-sep {
    color: var(--color-text-maxcontrast);
    font-size: 14px;
}

.th-dv__breadcrumb-cat {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 14px;
    font-weight: 600;
    color: var(--color-main-text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 180px;
}

/* Back chip — looks like a pill button */
.th-dv__back-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px 4px 6px;
    border: 1px solid var(--color-border);
    border-radius: 20px;
    background: transparent;
    font-size: 12px;
    font-weight: 500;
    color: var(--color-text-maxcontrast);
    cursor: pointer;
    transition: background 0.1s, color 0.1s;
    flex-shrink: 0;
}
.th-dv__back-chip:hover       { background: var(--color-background-hover); color: var(--color-main-text); }
.th-dv__back-chip:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 2px; }

.th-dv__chips {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}

.th-dv__chip {
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    border: 1px solid var(--color-border-dark);
    background: transparent;
    color: var(--color-text-maxcontrast);
    cursor: pointer;
    transition: all 0.12s;
    line-height: 1.4;
}

.th-dv__chip:hover       { background: var(--color-background-hover); color: var(--color-main-text); }
.th-dv__chip:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 2px; }
.th-dv__chip--active     { background: var(--color-primary-element); color: var(--color-primary-element-text); border-color: var(--color-primary-element); }

.th-dv__sort-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    border: 1px solid var(--color-border-dark);
    background: transparent;
    color: var(--color-text-maxcontrast);
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.12s;
}

.th-dv__sort-btn:hover        { background: var(--color-background-hover); color: var(--color-main-text); }
.th-dv__sort-btn:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 2px; }

.th-dv__toolbar-spacer { flex: 1 }

/* ── Landing grid ── */
/* ── Landing view ── */
.th-dv__landing {
    display: flex;
    flex-direction: column;
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    gap: 12px;
}

/* Search bar */
.th-dv__landing-search-wrap {
    position: relative;
    display: flex;
    align-items: center;
}
.th-dv__landing-search-icon {
    position: absolute;
    left: 10px;
    color: var(--color-text-maxcontrast);
    pointer-events: none;
}
.th-dv__landing-search {
    width: 100%;
    max-width: 360px;
    padding: 8px 12px 8px 34px;
    border: 1px solid var(--color-border-dark);
    border-radius: 20px;
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 13px;
    outline: none;
    transition: border-color 0.15s;
}
.th-dv__landing-search:focus {
    border-color: var(--color-primary-element);
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--color-primary-element) 20%, transparent);
}

/* "All decisions" shortcut row */
.th-dv__landing-showall {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    cursor: pointer;
    text-align: left;
    width: 100%;
    transition: border-color 0.15s, background 0.15s;
}
.th-dv__landing-showall:hover {
    border-color: var(--color-primary-element);
    background: var(--color-background-hover);
}
.th-dv__landing-showall:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}
.th-dv__landing-showall-icon {
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: color-mix(in srgb, var(--color-primary-element) 12%, transparent);
    color: var(--color-primary-element);
    display: flex;
    align-items: center;
    justify-content: center;
}
.th-dv__landing-showall-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--color-main-text);
    flex: 1;
}
.th-dv__landing-showall-sub {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
}
.th-dv__landing-showall-chevron {
    color: var(--color-text-maxcontrast);
    flex-shrink: 0;
}

.th-dv__landing-no-results {
    text-align: center;
    color: var(--color-text-maxcontrast);
    font-size: 13px;
    padding: 24px 0;
}

/* Category grid — 2 columns on desktop, 1 column on narrow screens */
.th-dv__landing-grid {
    list-style: none;
    padding: 0;
    margin: 0;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
}
@media (max-width: 720px) {
    .th-dv__landing-grid { grid-template-columns: 1fr; }
}

/* Category card — horizontal list item */
.th-dv__cat-card {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 12px 14px;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    cursor: pointer;
    text-align: left;
    transition: border-color 0.12s, background 0.12s;
}
.th-dv__cat-card:hover {
    border-color: var(--color-primary-element);
    background: var(--color-background-hover);
}
.th-dv__cat-card:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

/* Icon bubble */
.th-dv__cat-card-icon {
    flex-shrink: 0;
    width: 42px;
    height: 42px;
    border-radius: 10px;
    background: color-mix(in srgb, var(--color-primary-element) 10%, transparent);
    color: var(--color-primary-element);
    display: flex;
    align-items: center;
    justify-content: center;
}

.th-dv__cat-card-body {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.th-dv__cat-card-name {
    font-size: 15px;
    font-weight: 600;
    color: var(--color-main-text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    line-height: 1.3;
}
.th-dv__cat-card-desc {
    font-size: 13px;
    font-weight: 400;
    color: var(--color-text-maxcontrast);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    line-height: 1.3;
}
.th-dv__cat-card-chevron {
    color: var(--color-text-maxcontrast);
    flex-shrink: 0;
    opacity: 0;
    transition: opacity 0.12s;
}
.th-dv__cat-card:hover .th-dv__cat-card-chevron { opacity: 1; }

/* Decision search-result category tag (inline on card meta) */
.th-dv__card-cat-tag {
    display: inline-block;
    padding: 1px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
    background: color-mix(in srgb, var(--color-primary-element) 10%, transparent);
    color: var(--color-primary-element);
}

/* ── Level badges (in cards) and chips (in detail meta) ── */
.th-dv__level-badge {
    display: inline-block;
    padding: 1px 6px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 600;
    background: color-mix(in srgb, var(--color-primary-element) 12%, transparent);
    color: var(--color-primary-element);
    border: 1px solid color-mix(in srgb, var(--color-primary-element) 30%, transparent);

}

.th-dv__level-chip {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 700;
}
.th-dv__level-chip--operational {
    background: var(--color-background-dark);
    color: var(--color-text-maxcontrast);
}
.th-dv__level-chip--tactical {
    background: color-mix(in srgb, var(--color-primary-element) 14%, transparent);
    color: var(--color-primary-element);
}
.th-dv__level-chip--strategic {
    background: color-mix(in srgb, var(--color-warning) 14%, transparent);
    color: var(--color-warning-text, #a05a00);
}

/* ── Body: holds either the 2-column category grid OR the full-overlay detail.
   Sections and detail are mutually visible at narrow widths now: when a card
   is selected, the detail covers the entire body area (100%). At very wide
   widths we still allow the body to scroll inside its container, but the
   detail no longer steals horizontal space from the cards. */
.th-dv__body {
    flex: 1;
    display: flex;
    overflow: hidden;
    position: relative;
}

/* ── State views ── */
.th-dv__state {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 48px 24px;
    color: var(--color-text-maxcontrast);
    text-align: center;
}

.th-dv__state--error { color: var(--color-error-text); }

/* ── Sections: 2-column grid, with category sections flowing across columns
   so we don't end up with one tall column and one short one. CSS columns
   gives us automatic balancing without JS, but we use grid to keep the
   per-section card lists intact (columns would break list items across
   column boundaries). Below ~640px (tight iframes, mobile), collapse to 1. */
.th-dv__sections {
    flex: 1;
    overflow-y: auto;
    padding: 20px 24px 32px;
    min-width: 0;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    column-gap: 24px;
    row-gap: 8px;
    align-content: start;
}

@media (max-width: 640px) {
    .th-dv__sections { grid-template-columns: 1fr; }
}

.th-dv__section {
    margin-bottom: 20px;
    min-width: 0; /* allow children to truncate inside grid track */
}

/* ── Section header ── */
.th-dv__section-header {
    display: flex;
    align-items: center;
    gap: 7px;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--color-border-dark);
    margin-bottom: 8px;
    color: var(--color-text-maxcontrast);
}

.th-dv__section-title {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--color-text-maxcontrast);
    flex: 1;
}

.th-dv__section-count {
    font-size: 11px;
    font-weight: 600;
    background: var(--color-background-dark);
    border-radius: 10px;
    padding: 1px 7px;
    color: var(--color-text-maxcontrast);
}

/* ── Cards list ── */
.th-dv__cards {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 1px;
}

/* ── Card ── */
.th-dv__card {
    border-radius: var(--border-radius-large);
    overflow: hidden;
    transition: background 0.1s;
}

.th-dv__card--target {
    box-shadow: 0 0 0 2px var(--color-primary-element);
    border-radius: var(--border-radius-large);
}

.th-dv__card-btn {
    display: flex;
    align-items: center;
    width: 100%;
    background: none;
    border: none;
    cursor: pointer;
    text-align: left;
    padding: 0;
    border-radius: var(--border-radius-large);
    transition: background 0.1s;
    gap: 0;
}

.th-dv__card-btn:hover        { background: var(--color-background-hover); }
.th-dv__card-btn:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 1px; }

/* Left accent stripe */
.th-dv__card-accent {
    width: 4px;
    align-self: stretch;
    flex-shrink: 0;
    border-radius: var(--border-radius-large) 0 0 var(--border-radius-large);
    background: var(--color-border);
    min-height: 44px;
}

.th-dv__card--open       .th-dv__card-accent { background: var(--color-primary-element); }
.th-dv__card--finalized  .th-dv__card-accent { background: var(--color-warning, #c9a227); }
.th-dv__card--approved   .th-dv__card-accent { background: var(--color-success-text); }
.th-dv__card--denied     .th-dv__card-accent { background: var(--color-error-text); }
.th-dv__card--withdrawn  .th-dv__card-accent { background: var(--color-text-maxcontrast); opacity: 0.4; }
/* Legacy fallbacks */
.th-dv__card--proposed   .th-dv__card-accent { background: var(--color-primary-element); }
.th-dv__card--decided    .th-dv__card-accent { background: var(--color-success-text); }

/* Card body */
.th-dv__card-body {
    flex: 1;
    min-width: 0;
    padding: 10px 12px;
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.th-dv__card-subject {
    font-size: 13px;
    font-weight: 500;
    color: var(--color-main-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.4;
}

.th-dv__card--withdrawn .th-dv__card-subject,
.th-dv__card--denied .th-dv__card-subject {
    color: var(--color-text-maxcontrast);
    text-decoration: line-through;
    text-decoration-color: var(--color-text-maxcontrast);
}

.th-dv__card-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    color: var(--color-text-maxcontrast);
}

.th-dv__card-date { margin-left: auto; white-space: nowrap; }

/* Impact label — inline coloured text */
.th-dv__impact           { font-size: 11px; font-weight: 700; }
.th-dv__impact--low      { color: var(--color-text-maxcontrast); }
.th-dv__impact--medium   { color: var(--color-warning-text, #a05a00); }
.th-dv__impact--high     { color: var(--color-error-text); }

/* Status pill */
.th-dv__status-pill {
    font-size: 10px;
    font-weight: 700;
    padding: 2px 9px;
    border-radius: 20px;
    flex-shrink: 0;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border: 1.5px solid transparent;
    white-space: nowrap;
}

.th-dv__status-pill--open       { color: var(--color-primary-element);          border-color: var(--color-primary-element); }
.th-dv__status-pill--finalized  { color: var(--color-warning-text, #a05a00);    border-color: var(--color-warning-text, #a05a00); }
.th-dv__status-pill--approved   { color: var(--color-success-text);             border-color: var(--color-success-text); }
.th-dv__status-pill--denied     { color: var(--color-error-text);               border-color: var(--color-error-text); }
.th-dv__status-pill--withdrawn  { color: var(--color-text-maxcontrast);         border-color: var(--color-border-dark); }
/* Legacy fallbacks */
.th-dv__status-pill--proposed   { color: var(--color-primary-element);          border-color: var(--color-primary-element); }
.th-dv__status-pill--decided    { color: var(--color-success-text);             border-color: var(--color-success-text); }

.th-dv__card-chevron {
    color: var(--color-text-maxcontrast);
    margin-right: 10px;
    flex-shrink: 0;
    transition: transform 0.15s;
}

/* ── Load more ── */
.th-dv__load-more {
    display: flex;
    justify-content: center;
    padding-top: 16px;
}

/* ── Detail panel: v3.71.9 — full overlay covering the body area when active
   (previously a 360px sidebar to the right of the sections). At iframe
   widths the side-by-side layout left no room for either; the overlay gives
   the detail view its own focused space and the Back/close button in the
   header brings the user straight back to the category grid. */
.th-dv__detail {
    position: absolute;
    inset: 0;
    background: var(--color-main-background);
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    padding: 0 0 24px;
    z-index: 5; /* above .th-dv__sections, below NcModal (which uses very high z) */
}

/* Two-column body wrapper inside the detail panel.
   On wide screens (≥ 920px) the content sits left, approval+audit on the right.
   On narrow screens we stack them — right column flows under left. */
.th-dv__detail-body {
    display: grid;
    grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
    gap: 24px;
    padding: 0 20px;
    align-items: start;
}
.th-dv__detail-col {
    display: flex;
    flex-direction: column;
    gap: 16px;
    min-width: 0;
}
.th-dv__detail-col--right {
    position: sticky;
    top: 12px;
    /* Right column gets a subtle separator so it visually pairs */
}
@media (max-width: 920px) {
    .th-dv__detail-body {
        grid-template-columns: 1fr;
        padding: 0 16px;
    }
    .th-dv__detail-col--right {
        position: static;
    }
}

/* Slide-in transition: from the right, fast enough that the user doesn't
   feel a pause but slow enough that the spatial relationship reads. */
.th-dv-detail-enter-active,
.th-dv-detail-leave-active {
    transition: transform 0.2s ease, opacity 0.2s ease;
}
.th-dv-detail-enter-from,
.th-dv-detail-leave-to {
    transform: translateX(24px);
    opacity: 0;
}

/* Detail header */
.th-dv__detail-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px 10px;
    border-bottom: 1px solid var(--color-border);
    flex-shrink: 0;
    gap: 8px;
}

.th-dv__detail-close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border: none;
    background: none;
    border-radius: var(--border-radius);
    cursor: pointer;
    color: var(--color-text-maxcontrast);
    flex-shrink: 0;
    transition: background 0.12s;
}

.th-dv__detail-close:hover        { background: var(--color-background-hover); color: var(--color-main-text); }
.th-dv__detail-close:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 1px; }

/* v3.71.9 — Back chip; primary way to leave the detail overlay */
.th-dv__detail-back {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 10px 6px 6px;
    border: 1px solid var(--color-border);
    background: var(--color-main-background);
    border-radius: var(--border-radius);
    cursor: pointer;
    color: var(--color-main-text);
    font-size: 13px;
    font-weight: 500;
    flex-shrink: 0;
    transition: background 0.12s, border-color 0.12s;
}

.th-dv__detail-back:hover         { background: var(--color-background-hover); border-color: var(--color-primary-element); }
.th-dv__detail-back:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 1px; }

.th-dv__detail-status { margin-left: auto; }

/* Question heading */
.th-dv__detail-question {
    font-size: 15px;
    font-weight: 600;
    color: var(--color-main-text);
    line-height: 1.4;
    padding: 16px 0 12px;
    margin: 0;
}

/* Meta grid */
.th-dv__detail-meta {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 1px;
    margin: 0 0 16px;
    background: var(--color-border);
    border-radius: var(--border-radius-large);
    overflow: hidden;
    border: 1px solid var(--color-border);
}

.th-dv__detail-meta-row {
    display: contents;
}

.th-dv__detail-meta-row dt,
.th-dv__detail-meta-row dd {
    padding: 8px 12px;
    margin: 0;
    font-size: 12px;
    background: var(--color-main-background);
    line-height: 1.4;
}

.th-dv__detail-meta-row dt {
    font-weight: 600;
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
}

.th-dv__detail-meta-row dd {
    color: var(--color-main-text);
}

.th-dv__detail-meta-sub { color: var(--color-text-maxcontrast); font-size: 11px; }

.th-dv__detail-tag {
    display: inline-block;
    background: var(--color-background-dark);
    border-radius: 4px;
    padding: 1px 7px;
    font-size: 11px;
}

/* Answer block */
.th-dv__detail-answer {
    margin: 0 0 14px;
    padding: 12px 14px;
    background: color-mix(in srgb, var(--color-success-text) 7%, transparent);
    border-left: 3px solid var(--color-success-text);
    border-radius: 0 var(--border-radius-large) var(--border-radius-large) 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.th-dv__detail-answer-label {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-success-text);
}

.th-dv__detail-answer-text {
    font-size: 13px;
    color: var(--color-main-text);
    margin: 0;
    line-height: 1.5;
}

/* Withdrawn block */
.th-dv__detail-withdrawn {
    margin: 0 0 14px;
    padding: 10px 14px;
    background: var(--color-background-dark);
    border-radius: var(--border-radius-large);
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.th-dv__detail-section-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-maxcontrast);
}

.th-dv__detail-withdrawn-text {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    font-style: italic;
    margin: 0;
    line-height: 1.5;
}

/* Source */
.th-dv__detail-source {
    margin: 0 0 14px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.th-dv__detail-source-link {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: var(--color-primary-element);
    text-decoration: none;
    word-break: break-all;
}

.th-dv__detail-source-link:hover { text-decoration: underline; }

.th-dv__detail-source-text {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
}

/* Source files list — v3.71.2 */
.th-dv__detail-source-loading {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
}

/* ──────────────────────────────────────────────────────────────────────
   Unified row pattern for the right-column "links" sections — used by
   Source files, Linked tasks, and Linked decisions. Same layout, same
   hover, same pill style across all three so the detail panel reads as
   one professional surface rather than three differently-styled lists.
   Pills are solid-filled (no transparency / soft tints) per design.
   ────────────────────────────────────────────────────────────────────── */

/* Container blocks reuse one shared geometry. */
.th-dv__detail-tasks,
.th-dv__detail-dec-links {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

/* Shared list + row + remove-button. */
.th-dv__link-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.th-dv__link-item {
    display: flex;
    align-items: center;
    gap: 2px;
}

.th-dv__link-row {
    flex: 1 1 auto;
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
    padding: 6px 10px;
    background: transparent;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    color: var(--color-main-text);
    font-size: 13px;
    line-height: 1.3;
    text-align: left;
    text-decoration: none;
    cursor: pointer;
    transition: background 0.1s ease, border-color 0.1s ease;
}

.th-dv__link-row:hover {
    background: var(--color-background-hover);
    border-color: var(--color-border-dark);
}

.th-dv__link-row:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}

.th-dv__link-icon {
    flex: 0 0 auto;
    color: var(--color-text-maxcontrast);
}

.th-dv__link-icon--proposal {
    color: var(--color-success);
}

.th-dv__link-label {
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Trailing metadata (file size, etc.) — neutral, no pill */
.th-dv__link-meta {
    flex: 0 0 auto;
    font-size: 11px;
    color: var(--color-text-maxcontrast);
}

/* Pills — solid filled, white text. No soft tints. */
.th-dv__link-pill {
    flex: 0 0 auto;
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 700;
    line-height: 1.4;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    white-space: nowrap;
    color: #fff;
    background: var(--color-text-maxcontrast); /* neutral default */
}

/* Status pills — match the existing list-view colour vocabulary */
.th-dv__link-pill--status-open,
.th-dv__link-pill--status-proposed {
    background: var(--color-primary-element);
}
/* Status pills — hard-contrast colours from widget-tokens.css */
.th-dv__link-pill--status-open,
.th-dv__link-pill--status-proposed {
    background: var(--color-primary-element);
}
.th-dv__link-pill--status-approved,
.th-dv__link-pill--status-decided {
    background: var(--th-color-success);
}
.th-dv__link-pill--status-denied {
    background: var(--th-color-error);
}
.th-dv__link-pill--status-withdrawn {
    background: var(--th-color-neutral);
}

/* Level pills — operational neutral, tactical primary, strategic warning */
.th-dv__link-pill--level-operational {
    background: var(--th-color-neutral);
}
.th-dv__link-pill--level-tactical {
    background: var(--color-primary-element);
}
.th-dv__link-pill--level-strategic {
    background: var(--th-color-warning);
}

/* "Final proposal" badge — matches status-approved */
.th-dv__link-pill--proposal {
    background: var(--th-color-success);
}

/* Remove button — ghost; only visible on hover/focus of its parent row */
.th-dv__link-remove {
    flex: 0 0 auto;
    width: 28px;
    height: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: 1px solid transparent;
    border-radius: var(--border-radius);
    color: var(--color-text-maxcontrast);
    cursor: pointer;
    opacity: 0;
    transition: opacity 0.1s ease, background 0.1s ease, color 0.1s ease;
}

.th-dv__link-item:hover .th-dv__link-remove,
.th-dv__link-item:focus-within .th-dv__link-remove,
.th-dv__link-remove:focus-visible {
    opacity: 1;
}

.th-dv__link-remove:hover {
    background: var(--color-error);
    color: #fff;
}

.th-dv__link-remove:focus-visible {
    background: var(--color-error);
    color: #fff;
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}

/* Empty state + actions row — shared */
.th-dv__link-empty {
    margin: 0;
    padding: 2px 0;
    color: var(--color-text-maxcontrast);
    font-size: 12px;
    font-style: italic;
}

.th-dv__link-actions {
    margin-top: 2px;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

/* Tasks-specific loading + inline form (Session B) — kept */
.th-dv__tasks-loading,
.th-dv__dec-links-loading {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--color-text-maxcontrast);
    font-size: 12px;
}

.th-dv__tasks-link-form {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.th-dv__tasks-link-input {
    width: 100%;
    padding: 6px 10px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 13px;
}
.th-dv__tasks-link-input:focus { border-color: var(--color-primary-element); }
.th-dv__tasks-link-input--label { font-size: 12px; }
.th-dv__tasks-link-btns { display: flex; gap: 6px; }

/* ── Session C: decision picker modal ── */
.th-dv__dec-picker {
    padding: 18px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    min-width: 360px;
    max-width: 520px;
}

.th-dv__dec-picker-hint {
    margin: 0;
    color: var(--color-text-maxcontrast);
    font-size: 0.9em;
}

.th-dv__dec-picker-search {
    display: flex;
}

.th-dv__dec-picker-input {
    flex: 1 1 auto;
    padding: 8px 12px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 0.95em;
}

.th-dv__dec-picker-input:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}

.th-dv__dec-picker-loading {
    display: flex;
    justify-content: center;
    padding: 16px 0;
}

.th-dv__dec-picker-list {
    list-style: none;
    padding: 0;
    margin: 0;
    max-height: 320px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.th-dv__dec-picker-item-btn {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    background: transparent;
    border: 1px solid transparent;
    border-radius: var(--border-radius);
    text-align: left;
    cursor: pointer;
    color: var(--color-main-text);
}

.th-dv__dec-picker-item-btn:hover {
    background: var(--color-background-hover);
    border-color: var(--color-border);
}

.th-dv__dec-picker-item-btn:focus-visible {
    background: var(--color-background-hover);
    border-color: var(--color-border);
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}

.th-dv__dec-picker-item-btn[disabled] {
    opacity: 0.6;
    cursor: progress;
}

.th-dv__dec-picker-item-title {
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.th-dv__dec-picker-item-status {
    flex: 0 0 auto;
    font-size: 0.75em;
    padding: 1px 6px;
    border-radius: 8px;
    background: var(--color-background-darker);
    color: var(--color-text-maxcontrast);
    text-transform: capitalize;
}

.th-dv__dec-picker-item-status--approved,
.th-dv__dec-picker-item-status--decided {
    background: var(--color-success);
    color: #fff;
}

.th-dv__dec-picker-item-status--denied,
.th-dv__dec-picker-item-status--withdrawn {
    background: var(--color-error);
    color: #fff;
}

.th-dv__dec-picker-empty {
    margin: 0;
    padding: 16px 0;
    text-align: center;
    color: var(--color-text-maxcontrast);
    font-size: 0.9em;
    font-style: italic;
}

/* ── Audit timeline (Session J) ── */
.th-dv__detail-audit {
    margin: 14px 18px 0;
    padding-top: 14px;
    border-top: 1px solid var(--color-border);
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.th-dv__audit-loading {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 0;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
}

.th-dv__audit-error {
    padding: 6px 0;
    font-size: 12px;
    color: var(--color-error-text);
}

.th-dv__audit-empty {
    padding: 6px 0;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    font-style: italic;
}

.th-dv__audit-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0;
    position: relative;
}

/* Vertical timeline line — runs through the centre of the dots */
.th-dv__audit-list::before {
    content: '';
    position: absolute;
    left: 5px;
    top: 8px;
    bottom: 8px;
    width: 1px;
    background: var(--color-border);
}

.th-dv__audit-item {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 6px 0;
}

.th-dv__audit-dot {
    width: 11px;
    height: 11px;
    border-radius: 50%;
    flex-shrink: 0;
    margin-top: 4px;
    background: var(--color-text-maxcontrast);
    border: 2px solid var(--color-main-background);
    box-shadow: 0 0 0 1px var(--color-border);
    position: relative;
    z-index: 1;
}

.th-dv__audit-item--proposed  .th-dv__audit-dot { background: var(--color-primary-element); }
.th-dv__audit-item--commented .th-dv__audit-dot { background: var(--color-text-maxcontrast); }
.th-dv__audit-item--finalized .th-dv__audit-dot { background: var(--color-warning, #c9a227); }
.th-dv__audit-item--approved  .th-dv__audit-dot { background: var(--color-success-text); }
.th-dv__audit-item--denied    .th-dv__audit-dot { background: var(--color-error-text); }
.th-dv__audit-item--withdrawn .th-dv__audit-dot { background: var(--color-text-maxcontrast); opacity: 0.6; }

.th-dv__audit-body {
    flex: 1;
    min-width: 0;
}

.th-dv__audit-header {
    display: flex;
    align-items: baseline;
    gap: 6px;
    flex-wrap: wrap;
    font-size: 12px;
    line-height: 1.3;
}

.th-dv__audit-action {
    font-weight: 600;
    color: var(--color-main-text);
}

.th-dv__audit-actor {
    color: var(--color-text-maxcontrast);
}

.th-dv__audit-time {
    margin-left: auto;
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
    font-size: 11px;
}

.th-dv__audit-payload {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    margin-top: 2px;
    overflow-wrap: anywhere;
    line-height: 1.4;
}

/* ── Read-only file viewer modal — v3.71.2 ── */
.th-dv-viewer {
    display: flex;
    flex-direction: column;
    height: min(80vh, 720px);
    width: 100%;
}

.th-dv-viewer__header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--color-border);
    background: var(--color-background-hover);
}

.th-dv-viewer__name {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 600;
    font-size: 14px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex: 1 1 auto;
}

.th-dv-viewer__badge {
    flex: 0 0 auto;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: var(--color-success-text);
    background: var(--color-success-default, var(--color-background-darker));
    padding: 2px 6px;
    border-radius: var(--border-radius);
}

.th-dv-viewer__readonly {
    flex: 0 0 auto;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: var(--color-text-maxcontrast);
    border: 1px solid var(--color-border);
    padding: 2px 8px;
    border-radius: var(--border-radius);
}

/* v3.71.3 — tab-out affordance shown only for external-URL sources */
.th-dv-viewer__newtab {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    text-decoration: none;
    color: var(--color-primary-element);
    border: 1px solid var(--color-border);
    padding: 2px 8px;
    border-radius: var(--border-radius);
}

.th-dv-viewer__newtab:hover,
.th-dv-viewer__newtab:focus-visible {
    background: var(--color-background-hover);
    outline: none;
}

/* v3.71.3 — button-styled source link (same look as <a> it replaced) */
.th-dv__detail-source-link--button {
    background: transparent;
    border: none;
    padding: 0;
    cursor: pointer;
    font: inherit;
    text-align: left;
}

.th-dv__detail-source-link-text {
    word-break: break-all;
}

.th-dv-viewer__frame {
    flex: 1 1 auto;
    width: 100%;
    border: none;
    background: var(--color-main-background);
}

/* v3.71.7 — body container holds whichever kind-specific renderer is shown */
.th-dv-viewer__body {
    flex: 1 1 auto;
    overflow: auto;
    background: var(--color-main-background);
    display: flex;
    flex-direction: column;
}

/* Markdown — proposal-style document, comfortable reading column */
.th-dv-viewer__markdown {
    padding: 24px 32px;
    max-width: 800px;
    width: 100%;
    margin: 0 auto;
    line-height: 1.6;
    color: var(--color-main-text);
}
.th-dv-viewer__markdown h1,
.th-dv-viewer__markdown h2,
.th-dv-viewer__markdown h3,
.th-dv-viewer__markdown h4 {
    margin-top: 1.4em;
    margin-bottom: 0.4em;
    font-weight: 600;
}
.th-dv-viewer__markdown h1 { font-size: 1.6em; }
.th-dv-viewer__markdown h2 { font-size: 1.35em; }
.th-dv-viewer__markdown h3 { font-size: 1.15em; }
.th-dv-viewer__markdown p { margin: 0.6em 0; }
.th-dv-viewer__markdown ul,
.th-dv-viewer__markdown ol { padding-left: 1.5em; margin: 0.6em 0; }
.th-dv-viewer__markdown li { margin: 0.2em 0; }
.th-dv-viewer__markdown hr {
    border: none;
    border-top: 1px solid var(--color-border);
    margin: 1.5em 0;
}
.th-dv-viewer__markdown blockquote {
    border-left: 3px solid var(--color-border);
    margin: 0.8em 0;
    padding: 0.2em 0 0.2em 1em;
    color: var(--color-text-maxcontrast);
}
.th-dv-viewer__markdown code {
    background: var(--color-background-hover);
    padding: 1px 4px;
    border-radius: 3px;
    font-size: 0.92em;
}
.th-dv-viewer__markdown pre {
    background: var(--color-background-hover);
    padding: 12px 14px;
    border-radius: var(--border-radius);
    overflow-x: auto;
    font-size: 0.92em;
}
.th-dv-viewer__markdown pre code {
    background: transparent;
    padding: 0;
}
.th-dv-viewer__markdown a {
    color: var(--color-primary-element);
    text-decoration: underline;
    overflow-wrap: anywhere;
}

/* Plain text — monospace, preserved formatting */
.th-dv-viewer__text {
    padding: 20px 28px;
    margin: 0;
    white-space: pre-wrap;
    word-break: break-word;
    font-family: ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 13px;
    line-height: 1.5;
    color: var(--color-main-text);
}

/* Image — centered, fits modal */
.th-dv-viewer__image-wrap {
    flex: 1 1 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    background: var(--color-background-dark);
}
.th-dv-viewer__image {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

/* Loading + error states */
.th-dv-viewer__loading,
.th-dv-viewer__error {
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 32px;
    text-align: center;
}
.th-dv-viewer__error a {
    color: var(--color-primary-element);
    text-decoration: underline;
}

/* No-preview fallback for office docs, archives, etc. */
.th-dv-viewer__nopreview {
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 40px 24px;
    text-align: center;
    color: var(--color-text-maxcontrast);
}
.th-dv-viewer__nopreview-title {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: var(--color-main-text);
}
.th-dv-viewer__nopreview-sub {
    margin: 0;
    max-width: 480px;
    font-size: 13px;
    line-height: 1.5;
}
.th-dv-viewer__nopreview-actions {
    display: flex;
    gap: 8px;
    margin-top: 8px;
    flex-wrap: wrap;
    justify-content: center;
}

.th-dv-viewer__nopreview-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: var(--color-background-hover);
    color: var(--color-main-text);
    text-decoration: none;
    border-radius: var(--border-radius);
    border: 1px solid var(--color-border);
    font-size: 13px;
    font-weight: 500;
}

.th-dv-viewer__nopreview-btn--primary {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    border-color: var(--color-primary-element);
}

.th-dv-viewer__nopreview-btn:hover,
.th-dv-viewer__nopreview-btn:focus-visible {
    background: var(--color-background-dark);
    outline: none;
}

.th-dv-viewer__nopreview-btn--primary:hover,
.th-dv-viewer__nopreview-btn--primary:focus-visible {
    background: var(--color-primary-element-hover);
    color: var(--color-primary-element-text);
}

</style>
