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
                    <NcButton
                        class="th-dv__back-chip"
                        :aria-label="t('teamhub', 'Back to categories')"
                        @click="goToLanding"
                        variant="tertiary">
                        <template #icon>
                            <ChevronLeftIcon :size="ICON_INLINE" aria-hidden="true" />
                        </template>
                        {{ t('teamhub', 'Categories') }}
                    </NcButton>
                    <span v-if="activeCategory" class="th-dv__breadcrumb-sep" aria-hidden="true">/</span>
                    <span class="th-dv__breadcrumb-cat">
                        <template v-if="activeCategory">
                            <component v-if="activeCategory.icon" :is="categoryIconComponent(activeCategory.icon)" :size="ICON_BODY" class="th-dv__breadcrumb-icon" aria-hidden="true" />
                            {{ activeCategory.name }}
                        </template>
                        <template v-else>{{ t('teamhub', 'All decisions') }}</template>
                    </span>

                    <!-- Filters and sort — hidden when the detail panel is open
                         so only the breadcrumb + propose button remain visible. -->
                    <template v-if="!selected">
                        <!-- Mobile-only toggle: button rendered always, hidden
                             on desktop by CSS. Tapping it reveals the chip
                             groups (which are display:none on mobile by default). -->
                        <button
                            class="th-dv__filters-toggle"
                            :class="{ 'th-dv__filters-toggle--open': mobileFiltersOpen }"
                            :aria-expanded="mobileFiltersOpen"
                            :aria-controls="'th-dv-filters-panel'"
                            :aria-label="activeFilterCount > 0
                                ? t('teamhub', 'Filters ({n} active)', { n: activeFilterCount })
                                : t('teamhub', 'Filters')"
                            @click="mobileFiltersOpen = !mobileFiltersOpen">
                            <FilterVariantIcon :size="ICON_INLINE" aria-hidden="true" />
                            <span class="th-dv__filters-toggle-label">{{ t('teamhub', 'Filters') }}</span>
                            <span
                                v-if="activeFilterCount > 0"
                                class="th-dv__filters-toggle-badge"
                                aria-hidden="true">{{ activeFilterCount }}</span>
                        </button>

                        <!-- Filter+sort panel. On desktop this always renders inline
                             in the toolbar. On mobile (<720px) it collapses to a wrapped
                             block below the toolbar, gated by mobileFiltersOpen. -->
                        <div
                            id="th-dv-filters-panel"
                            class="th-dv__filters-panel"
                            :class="{ 'th-dv__filters-panel--open': mobileFiltersOpen }">
                            <!-- Status chips -->
                            <div class="th-dv__chips" role="group" :aria-label="t('teamhub', 'Filter by status')">
                                <NcButton
                                    v-for="chip in statusChips"
                                    :key="chip.value || 'all'"
                                    class="th-dv__chip"
                                    :class="{ 'th-dv__chip--active': filterStatus === chip.value }"
                                    @click="setStatusFilter(chip.value)"
                                    :pressed="filterStatus === chip.value"
                                    variant="tertiary">
                                    {{ chip.label }}
                                </NcButton>
                            </div>

                            <!-- Impact chips -->
                            <div class="th-dv__chips" role="group" :aria-label="t('teamhub', 'Filter by impact')">
                                <NcButton
                                    v-for="chip in impactChips"
                                    :key="chip.value || 'all-impact'"
                                    class="th-dv__chip"
                                    :class="{ 'th-dv__chip--active': filterImpact === chip.value }"
                                    @click="setImpactFilter(chip.value)"
                                    :pressed="filterImpact === chip.value"
                                    variant="tertiary">
                                    {{ chip.label }}
                                </NcButton>
                            </div>

                            <!-- Level chips (only when level feature is on) -->
                            <div v-if="decisionsLevelEnabled" class="th-dv__chips" role="group" :aria-label="t('teamhub', 'Filter by level')">
                                <NcButton
                                    v-for="chip in levelChips"
                                    :key="chip.value || 'all-level'"
                                    class="th-dv__chip"
                                    :class="{ 'th-dv__chip--active': filterLevel === chip.value }"
                                    @click="setLevelFilter(chip.value)"
                                    :pressed="filterLevel === chip.value"
                                    variant="tertiary">
                                    {{ chip.label }}
                                </NcButton>
                            </div>

                            <!-- Sort -->
                            <NcButton
                                class="th-dv__sort-btn"
                                :aria-label="sortLabel"
                                @click="toggleSort"
                                variant="tertiary">
                                <template #icon>
                                    <SortAscendingIcon v-if="sort === 'created'" :size="ICON_INLINE" aria-hidden="true" />
                                    <SortDescendingIcon v-else :size="ICON_INLINE" aria-hidden="true" />
                                </template>
                                {{ sortLabel }}
                            </NcButton>
                        </div>
                    </template>
                </template>

                <div class="th-dv__toolbar-spacer" />

                <NcButton variant="primary" class="th-dv__propose-btn" @click="$emit('propose-decision')">
                    <template #icon><PlusIcon :size="ICON_BODY" /></template>
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
                    <div class="th-dv__landing-top">
                        <div class="th-dv__landing-search-wrap">
                            <MagnifyIcon :size="ICON_BODY" class="th-dv__landing-search-icon" aria-hidden="true" />
                            <NcTextField
                                v-model="searchQuery"
                                type="search"
                                class="th-dv__landing-search"
                                :placeholder="t('teamhub', 'Search decisions…')"
                                :aria-label="t('teamhub', 'Search decisions')"
                                @input="onSearchInput"
                                label-outside />
                        </div>

                        <!-- v4.11.24 — beside the search rather than on its
                             own line above the grid: it is the second thing
                             you can do on this page, not a section header. -->
                        <NcButton
                            v-if="canManageCategories"
                            class="th-dv__landing-add"
                            variant="secondary"
                            @click="openCategoryDialog(null)">
                            <template #icon><PlusIcon :size="ICON_BODY" /></template>
                            {{ t('teamhub', 'Add category') }}
                        </NcButton>
                    </div>

                    <!-- SEARCH MODE — when there's a query -->
                    <template v-if="searchQuery.trim()">
                        <div v-if="searchLoading" class="th-dv__state">
                            <NcLoadingIcon :size="ICON_LARGE" />
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
                                    <ChevronRightIcon :size="ICON_BODY" class="th-dv__card-chevron" aria-hidden="true" />
                                </button>
                            </li>
                        </ul>
                    </template>

                    <!-- CATEGORY MODE — default when no search -->
                    <template v-else>
                        <!-- All decisions shortcut -->
                        <button class="th-dv__landing-showall" :aria-label="t('teamhub', 'Show all decisions')" @click="openAllDecisions">
                            <span class="th-dv__landing-showall-icon" aria-hidden="true">
                                <FormatListBulletedIcon :size="ICON_BODY" />
                            </span>
                            <span class="th-dv__landing-showall-label">{{ t('teamhub', 'All decisions') }}</span>
                            <span class="th-dv__landing-showall-sub">{{ t('teamhub', 'Browse across all categories') }}</span>
                            <ChevronRightIcon :size="ICON_BODY" class="th-dv__landing-showall-chevron" aria-hidden="true" />
                        </button>

                        <!-- Loading -->
                        <div v-if="categoriesLoading" class="th-dv__state">
                            <NcLoadingIcon :size="ICON_LARGE" />
                        </div>

                        <!-- No categories -->
                        <NcEmptyContent
                            v-else-if="!categories.length"
                            :name="t('teamhub', 'No categories yet')"
                            :description="t('teamhub', 'A team admin can add categories under Manage team → Decisions.')"
                            class="th-dv__state">
                            <template #icon><FolderOutlineIcon :size="ICON_XL" /></template>
                        </NcEmptyContent>

                        <!-- 2-column category grid -->
                        <ul v-else class="th-dv__landing-grid" role="list">
                            <!-- v4.11.19 — the card is no longer one big
                                 <button>. Its approvers' avatars open
                                 Nextcloud's contact menu, and an interactive
                                 element inside a button is both invalid HTML
                                 and unreachable — the button swallows the
                                 click. So the card is the <li>, the open
                                 action is a button that stretches over it
                                 (`::after`, inset 0), and the avatars sit
                                 above that overlay. One tab stop for the
                                 card plus one per avatar, which is also the
                                 reading order. -->
                            <li
                                v-for="cat in categories"
                                :key="cat.id"
                                class="th-dv__cat-card"
                                role="listitem">
                                <button
                                    class="th-dv__cat-card-main"
                                    :aria-label="t('teamhub', 'Open {name} decisions', { name: cat.name })"
                                    @click="openCategory(cat)">
                                    <span class="th-dv__cat-card-icon" aria-hidden="true">
                                        <component :is="categoryIconComponent(cat.icon)" :size="ICON_BODY" />
                                    </span>
                                    <span class="th-dv__cat-card-body">
                                        <span class="th-dv__cat-card-name">{{ cat.name }}</span>
                                        <span v-if="cat.description" class="th-dv__cat-card-desc">{{ cat.description }}</span>
                                    </span>
                                </button>

                                <!-- Who decides here. The archive has no
                                     approvers by design, so it gets no
                                     avatars rather than an empty slot. -->
                                <span
                                    v-if="categoryApprovers(cat).length"
                                    class="th-dv__cat-card-approvers"
                                    :aria-label="approverListLabel(cat)">
                                    <NcAvatar
                                        v-for="uid in categoryApprovers(cat)"
                                        :key="uid"
                                        class="th-dv__cat-card-avatar"
                                        :user="uid"
                                        :display-name="memberDisplayName(uid)"
                                        :size="AVATAR_SM"
                                        :hide-status="true"
                                        :disable-menu="false" />
                                    <!-- Not an avatar: a count is not a
                                         person, and a generic bubble that
                                         opens nobody's contact card would
                                         be a broken avatar. -->
                                    <span
                                        v-if="approverOverflow(cat) > 0"
                                        class="th-dv__cat-card-more"
                                        :title="approverListLabel(cat)">
                                        +{{ approverOverflow(cat) }}
                                    </span>
                                </span>

                                <!-- v4.11.23 — a team admin manages the
                                     category from the grid it appears on,
                                     rather than going to Manage team for a
                                     form that is now the same component. -->
                                <NcActions
                                    v-if="canManageCategories"
                                    class="th-dv__cat-card-menu"
                                    :aria-label="t('teamhub', 'Actions for {name}', { name: cat.name })">
                                    <NcActionButton close-after-click @click="openCategoryDialog(cat)">
                                        <template #icon><PencilIcon :size="ICON_BODY" /></template>
                                        {{ t('teamhub', 'Edit category') }}
                                    </NcActionButton>
                                </NcActions>

                                <ChevronRightIcon :size="ICON_INLINE" class="th-dv__cat-card-chevron" aria-hidden="true" />
                            </li>
                        </ul>
                    </template>
                </div>
            </template>

            <!-- ════ LIST VIEW ════ -->
            <template v-else-if="view === 'list'">
                <div v-if="loading && !grouped.length" class="th-dv__state">
                    <NcLoadingIcon :size="ICON_LARGE" />
                </div>
                <div v-else-if="error" class="th-dv__state th-dv__state--error" role="alert">
                    <AlertCircleOutlineIcon :size="ICON_LARGE" aria-hidden="true" />
                    <p>{{ t('teamhub', 'Could not load decisions. Try again.') }}</p>
                    <NcButton variant="secondary" @click="loadPage(null)">{{ t('teamhub', 'Retry') }}</NcButton>
                </div>
                <NcEmptyContent
                    v-else-if="!loading && !grouped.length"
                    :name="emptyTitle"
                    :description="emptyDescription"
                    class="th-dv__state">
                    <template #icon><GavelIcon :size="ICON_XL" /></template>
                </NcEmptyContent>
                <div v-else class="th-dv__sections">
                    <section
                        v-for="group in grouped"
                        :key="group.category"
                        class="th-dv__section"
                        :aria-label="group.category">
                        <div v-if="!activeCategory" class="th-dv__section-header">
                            <TagIcon :size="ICON_INLINE" aria-hidden="true" />
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
                                    <ChevronRightIcon :size="ICON_BODY" class="th-dv__card-chevron" aria-hidden="true" />
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
                        <NcButton
                            class="th-dv__detail-back"
                            :aria-label="t('teamhub', 'Back to decisions list')"
                            @click="selected = null"
                            variant="tertiary">
                            <template #icon>
                                <ChevronLeftIcon :size="ICON_BODY" aria-hidden="true" />
                            </template>
                            {{ t('teamhub', 'Back') }}
                        </NcButton>

                        <!-- Right-side controls: status + actions + close
                             v4.11.19 — one Actions menu, beside the status
                             pill. Everything you can *do* to a decision used
                             to be a button in whichever block happened to own
                             it: Edit and Finalize in the drafting block,
                             Request review and Supersede below it, Keep and
                             Archive inside the review note, Link task and
                             Create task in one section, Link decision and Link
                             external in another, Copy link up here. Eleven
                             buttons in six places, and which of them you saw
                             depended on the status *and* your role, so the
                             panel's shape changed under you. They are one list
                             now, in one place, in a fixed order.

                             What stays outside: Approve and Deny, because they
                             submit the approval form and its mandatory reason —
                             a submit button belongs to its form, and a menu
                             item that only scrolls you to a field is worse than
                             the field. Same reasoning keeps Save/Cancel in the
                             proposal editor and Link/Cancel in the inline link
                             forms. Back and Close are chrome, not actions. -->
                        <div class="th-dv__detail-header-right">
                            <span
                                class="th-dv__status-pill th-dv__detail-status"
                                :class="`th-dv__status-pill--${selected.status}`">
                                {{ statusLabel(selected.status) }}
                            </span>

                            <NcActions
                                v-if="panelActions.length"
                                class="th-dv__detail-menu"
                                :aria-label="t('teamhub', 'Actions for this decision')"
                                :disabled="panelActionsBusy">
                                <template v-for="action in panelActions" :key="action.key">
                                    <!-- A separator carries no label; it is
                                         the gap between groups of verbs. -->
                                    <NcActionSeparator v-if="action.key.startsWith('sep')" />

                                    <!-- The only one that leaves the app, so
                                         the only one that is a link and not a
                                         button. -->
                                    <NcActionLink
                                        v-else-if="action.href"
                                        :href="action.href"
                                        target="_blank"
                                        rel="noreferrer noopener">
                                        <template #icon>
                                            <component :is="action.icon" :size="ICON_BODY" />
                                        </template>
                                        {{ action.label }}
                                    </NcActionLink>

                                    <!-- Publishing is a state, not a verb, so
                                         it reads as a checkbox and shows what
                                         it currently is. -->
                                    <NcActionCheckbox
                                        v-else-if="action.key === 'public'"
                                        :model-value="!!selected.isPublic"
                                        :disabled="settingPublic"
                                        @update:model-value="setPublic">
                                        {{ action.label }}
                                    </NcActionCheckbox>

                                    <NcActionButton
                                        v-else
                                        close-after-click
                                        @click="runPanelAction(action.key)">
                                        <template #icon>
                                            <component :is="action.icon" :size="ICON_BODY" />
                                        </template>
                                        {{ action.label }}
                                    </NcActionButton>
                                </template>
                            </NcActions>

                            <NcButton
                                class="th-dv__detail-close"
                                :aria-label="t('teamhub', 'Close detail')"
                                :title="t('teamhub', 'Close')"
                                @click="selected = null"
                                variant="tertiary">
                                <template #icon>
                                    <CloseIcon :size="ICON_BODY" />
                                </template>
                            </NcButton>
                        </div>
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
                            <dd>{{ levelLabel(selected.level) }}</dd>
                        </div>
                        <div v-if="selected.category" class="th-dv__detail-meta-row">
                            <dt>{{ t('teamhub', 'Category') }}</dt>
                            <dd>{{ selected.category }}</dd>
                        </div>
                        <!-- v3.97.5 — milestone linkage. Only rendered when
                             the decision was linked to a milestone at
                             propose time AND that milestone still exists. -->
                        <div v-if="selected.milestoneLabel" class="th-dv__detail-meta-row">
                            <dt>{{ t('teamhub', 'Milestone') }}</dt>
                            <dd>
                                <span class="th-dv__milestone-chip">
                                    <FlagOutlineIcon :size="ICON_INLINE" aria-hidden="true" />
                                    <span>{{ selected.milestoneLabel }}</span>
                                    <span v-if="selected.milestoneDate" class="th-dv__milestone-date">— {{ selected.milestoneDate }}</span>
                                </span>
                            </dd>
                        </div>
                        <div class="th-dv__detail-meta-row">
                            <dt>{{ t('teamhub', 'Proposed by') }}</dt>
                            <dd>
                                <span class="th-dv__user-cell">
                                    <NcAvatar
                                        :user="selected.proposedBy"
                                        :display-name="memberDisplayName(selected.proposedBy)"
                                        :hide-status="true"
                                        :size="24" />
                                    <span class="th-dv__user-name">{{ memberDisplayName(selected.proposedBy) }}</span>
                                </span>
                            </dd>
                        </div>
                        <div class="th-dv__detail-meta-row">
                            <dt>{{ t('teamhub', 'Date') }}</dt>
                            <dd :title="fullDate(selected.createdAt)">{{ relativeDate(selected.createdAt) }}</dd>
                        </div>
                        <!-- Decided by — the person who approved or denied.
                             resolvedBy is set by approve() and deny(); it is also set
                             by finalize() but we gate on status so finalized-only
                             decisions don't surface the proposer here. -->
                        <div v-if="selected.resolvedBy && (selected.status === 'approved' || selected.status === 'denied' || selected.status === 'decided')" class="th-dv__detail-meta-row">
                            <dt>{{ t('teamhub', 'Decided by') }}</dt>
                            <dd>
                                <span class="th-dv__user-cell">
                                    <NcAvatar
                                        :user="selected.resolvedBy"
                                        :display-name="memberDisplayName(selected.resolvedBy)"
                                        :hide-status="true"
                                        :size="24" />
                                    <span class="th-dv__user-name">{{ memberDisplayName(selected.resolvedBy) }}</span>
                                </span>
                            </dd>
                        </div>
                    </dl>

                    <!-- ── Public record (v4.11.12, Justin 2026-09-30) ──
                         Whether this decision is a team-internal record or a
                         public one. Public means readable by **every
                         logged-in account on this instance** — instance-wide,
                         never anonymous, and never a link that grants access
                         on its own. It widens who may read; it changes
                         nothing about who may act on it.

                         Gated on the team's own action level, the same gate
                         as *Link decision* and *Link task*, so a team that
                         wants publishing to be an admin's call raises that
                         one setting. Everybody else sees the state as text:
                         whether a decision is public is not a secret from
                         the team whose decision it is. -->
                    <!-- v4.11.19 — the switch moved into the Actions menu as a
                         checkbox; what stays here is the state and what it
                         means, which is a thing you read rather than a thing
                         you press. Still shown to everybody: whether a
                         decision is public is not a secret from the team whose
                         decision it is, and somebody below the action level
                         sees the sentence without the checkbox. -->
                    <div class="th-dv__detail-public">
                        <p class="th-dv__detail-public-state">
                            {{ selected.isPublic
                                ? t('teamhub', 'Public decision')
                                : t('teamhub', 'Team decision') }}
                        </p>
                        <!-- v4.11.19 — the "make it public" half of the
                             sentence names the Actions menu only for somebody
                             who will find the checkbox there. Telling a member
                             below the action level where a control they do not
                             have would be is worse than not mentioning it. -->
                        <p class="th-dv__detail-public-hint">
                            {{ publicHint }}
                        </p>
                    </div>

                    <!-- Subject — v3.71.3 moved from above the metadata grid
                         to sit directly above the Final proposal block, so
                         the reader sees the question right next to its
                         resolved answer.

                         Suppressed on the proposer's own open proposal: there
                         the subject belongs to the drafting block, above the
                         context and the buttons that act on both. Rendering
                         it here as well would print the question twice. -->
                    <h2 v-if="!isMyOpenProposal" class="th-dv__detail-question">{{ selected.question || t('teamhub', 'Untitled decision') }}</h2>

                    <!-- Final proposal block -->
                    <div v-if="(selected.status === 'approved' || selected.status === 'finalized' || selected.status === 'decided') && selected.selectedAnswer" class="th-dv__detail-answer">
                        <span class="th-dv__detail-answer-label">
                            <CheckCircleIcon :size="ICON_INLINE" aria-hidden="true" />
                            {{ t('teamhub', 'Final proposal') }}
                        </span>
                        <!-- v3.75.5 — render the proposal body as markdown
                             rather than raw text. selectedAnswer is the same
                             markdown that the proposer wrote (and that the
                             saved .md viewer renders identically). Sanitized
                             via DOMPurify inside renderViewerMarkdown. -->
                        <!-- eslint-disable-next-line vue/no-v-html -->
                        <div class="th-dv__detail-answer-text th-dv__detail-answer-text--md"
                             v-html="renderedSelectedAnswer" />
                    </div>

                    <!-- Withdrawal / denial reason block -->
                    <div v-if="(selected.status === 'withdrawn' || selected.status === 'denied') && selected.withdrawnReason" class="th-dv__detail-withdrawn">
                        <span class="th-dv__detail-section-label">
                            {{ selected.status === 'denied' ? t('teamhub', 'Reason for denial') : t('teamhub', 'Reason for withdrawal') }}
                        </span>
                        <p class="th-dv__detail-withdrawn-text">{{ selected.withdrawnReason }}</p>
                    </div>

                    <!-- v4.11.18 — a retired decision says so before it says
                         anything else, and says what replaced it. Above the
                         Source block on purpose: "this no longer applies" is
                         the first thing a reader of an archived record needs,
                         and finding it under the attachments would be finding
                         it too late. `NcNoteCard` rather than a hand-rolled
                         banner (/ui-standards). -->
                    <NcNoteCard
                        v-if="selected.isRetired"
                        type="warning"
                        class="th-dv__detail-retired">
                        <p class="th-dv__detail-retired-line">
                            <template v-if="selected.status === 'superseded'">
                                {{ t('teamhub', 'This decision has been superseded and is read-only.') }}
                            </template>
                            <template v-else>
                                {{ t('teamhub', 'This decision has been archived and is read-only.') }}
                            </template>
                        </p>

                        <!-- The decision that replaced it. A link and not a
                             sentence: the reader's next move is to go there. -->
                        <p v-if="supersededByRow" class="th-dv__detail-retired-line">
                            <button
                                class="th-dv__detail-retired-link"
                                @click="selectDecisionById(supersededByRow.id)">
                                {{ t('teamhub', 'Superseded by: {question}', { question: supersededByRow.question }) }}
                            </button>
                        </p>

                        <p v-if="selected.archivedFromCategory" class="th-dv__detail-retired-line">
                            <!-- TRANSLATORS: %{category} is the category the decision was decided under before it was archived -->
                            {{ t('teamhub', 'Archived from {category}', { category: selected.archivedFromCategory }) }}
                        </p>
                        <p v-if="selected.archivedAt" class="th-dv__detail-retired-line">
                            {{ t('teamhub', 'Archived {date}', { date: fullDate(selected.archivedAt) }) }}
                        </p>
                    </NcNoteCard>

                    <!-- v4.11.18 — a review somebody has asked for, while it
                         is running. Every member sees it: a decision under
                         review is still in force, and knowing that it is
                         being looked at is the point of asking in the open. -->
                    <NcNoteCard
                        v-if="openReview"
                        type="info"
                        class="th-dv__detail-review">
                        <p class="th-dv__detail-review-line">
                            <template v-if="openReview.kind === 'requested'">
                                {{ t('teamhub', '{name} asked for a review of this decision. It stays in force until the approvers answer.', { name: openReview.requestedByName }) }}
                            </template>
                            <template v-else>
                                {{ t('teamhub', 'This decision has reached its review date. It stays in force until the approvers answer.') }}
                            </template>
                        </p>
                        <blockquote v-if="openReview.motivation" class="th-dv__detail-review-quote">
                            {{ openReview.motivation }}
                        </blockquote>

                        <!-- v4.11.30 — **what the requester is asking the
                             decision to say.** In the banner and not in the
                             approver's block, because it is not only the
                             approvers' business: a decision under review is
                             still in force, and the whole team is entitled to
                             read what somebody is asking to change it to.

                             It is also the thing that was missing. The
                             complaint this version answers was *if I create a
                             review I cannot review the attachment* — so the
                             file is here too, as a button into the same
                             read-only viewer the Source list opens, and the
                             approver does not have to go hunting through the
                             team folder for a document they are being asked
                             to agree with. -->
                        <div v-if="openReviewHasProposal || openReview.attachmentFileId" class="th-dv__review-proposal">
                            <span class="th-dv__review-proposal-label">
                                {{ t('teamhub', 'Proposed wording') }}
                            </span>
                            <p v-if="openReview.proposedQuestion" class="th-dv__review-proposal-question">
                                {{ openReview.proposedQuestion }}
                            </p>
                            <blockquote v-if="openReview.proposedAnswer" class="th-dv__detail-review-quote">
                                {{ openReview.proposedAnswer }}
                            </blockquote>
                            <NcButton
                                v-if="openReview.attachmentFileId"
                                variant="tertiary"
                                :title="t('teamhub', 'Open {name} in read-only viewer', { name: openReview.attachmentName })"
                                @click="openReviewAttachment(openReview)">
                                <template #icon>
                                    <FileDocumentOutlineIcon :size="ICON_INLINE" aria-hidden="true" />
                                </template>
                                {{ openReview.attachmentName }}
                            </NcButton>
                        </div>

                        <!-- v4.11.23 — the answer is a block in the right
                             column, not a menu item. See the Review block
                             beside the Approval one. -->
                        <p v-if="canApprove(selected) && openReview.kind === 'requested'" class="th-dv__detail-review-line">
                            <template v-if="openReviewHasProposal">
                                {{ t('teamhub', 'Answer it under Review, on the right. Approving writes the proposed wording into the decision; it keeps its approval either way.') }}
                            </template>
                            <template v-else>
                                {{ t('teamhub', 'Answer it under Review, on the right. Accepting rewrites the wording; the decision keeps its approval either way.') }}
                            </template>
                        </p>
                        <p v-else-if="canApprove(selected)" class="th-dv__detail-review-line">
                            {{ t('teamhub', 'Answer it under Review, on the right.') }}
                        </p>
                    </NcNoteCard>

                    <!-- The next review date, when the category asks for one
                         and no review is running. A date and nothing else —
                         there is nothing to do about it yet. -->
                    <p
                        v-else-if="selected.reviewDueAt && selected.status === 'approved'"
                        class="th-dv__detail-review-due">
                        {{ t('teamhub', 'Next review due {date}', { date: fullDate(selected.reviewDueAt) }) }}
                    </p>

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
                        <NcButton
                            v-if="selected.sourceType === 'url' && selected.sourceRef"
                            class="th-dv__detail-source-link th-dv__detail-source-link--button"
                            :title="t('teamhub', 'Open {url} in read-only viewer', { url: selected.sourceRef })"
                            @click="openSourceUrl(selected.sourceRef)"
                            variant="tertiary">
                            <template #icon>
                                <OpenInNewIcon :size="ICON_INLINE" aria-hidden="true" />
                            </template>
                            <span class="th-dv__detail-source-link-text">{{ selected.sourceRef }}</span>
                        </NcButton>
                        <span
                            v-else-if="selected.sourceRef && selected.sourceType !== 'document'"
                            class="th-dv__detail-source-text">{{ selected.sourceRef }}</span>

                        <!-- Source files list — proposal .md + attachments copied at finalize. -->
                        <div v-if="sourcesLoading" class="th-dv__detail-source-loading">
                            <NcLoadingIcon :size="ICON_BODY" />
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
                                    <CheckCircleIcon v-if="f.is_proposal" :size="ICON_BODY" class="th-dv__link-icon th-dv__link-icon--proposal" aria-hidden="true" />
                                    <FileDocumentOutlineIcon v-else :size="ICON_BODY" class="th-dv__link-icon" aria-hidden="true" />
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

                    <!-- ── Drafting block (v4.5.42) ──
                         The proposer's side of an open proposal: where the
                         discussion is happening, the editor for acting on what
                         came back, and the button that ends drafting.

                         Only the proposer sees it — DecisionService refuses
                         both writes from anyone else, so rendering it for
                         others would be a button that 404s (SKILLS.md
                         § Permissions: hide what the role cannot do). -->
                    <div v-if="isMyOpenProposal" class="th-dv__drafting">
                        <span class="th-dv__detail-section-label">{{ t('teamhub', 'Proposal') }}</span>

                    <!-- v4.5.46 — two columns: the proposal's own controls on
                         the left, the lifecycle rail on the right. The rail is
                         a progress indicator, so reading order reaches the
                         controls first and it stacks under them on narrow
                         screens. -->
                    <div class="th-dv__drafting-body">
                        <div class="th-dv__drafting-main">

                        <!-- Subject and context, above the buttons that act on
                             them. Both are hidden while the editor is open —
                             the editor holds the same two fields, and showing
                             the saved copy beside the box you are changing it
                             in invites you to edit the wrong one. -->
                        <template v-if="!editingProposal">
                            <h2 class="th-dv__detail-question th-dv__drafting-question">
                                {{ selected.question || t('teamhub', 'Untitled decision') }}
                            </h2>

                            <!-- Safe: renderViewerMarkdown() runs the text
                                 through DOMPurify with a tight allowlist,
                                 same as the final-proposal block below. -->
                            <!-- eslint-disable-next-line vue/no-v-html -->
                            <div
                                v-if="renderedProposalBody"
                                class="th-dv__drafting-context"
                                v-html="renderedProposalBody" />
                        </template>

                        <!-- Editor — closed by default so the panel still reads
                             as a record rather than a form. -->
                        <template v-if="editingProposal">
                            <label class="th-dv__drafting-label" :for="`th-dv-edit-q-${selected.id}`">
                                {{ t('teamhub', 'Question') }}
                            </label>
                            <NcTextField
                                :id="`th-dv-edit-q-${selected.id}`"
                                v-model="editQuestion"
                                class="th-dv__drafting-input"
                                :disabled="savingProposal"
                                :maxlength="4000"
                                label-outside />

                            <label class="th-dv__drafting-label" :for="`th-dv-edit-b-${selected.id}`">
                                {{ t('teamhub', 'Proposal') }}
                            </label>
                            <NcTextArea
                                :id="`th-dv-edit-b-${selected.id}`"
                                v-model="editBody"
                                class="th-dv__drafting-textarea"
                                rows="8"
                                :disabled="savingProposal"
                                :maxlength="4000"
                                label-outside />

                            <div class="th-dv__drafting-actions">
                                <NcButton
                                    variant="primary"
                                    :disabled="!editQuestion.trim() || savingProposal"
                                    @click="saveProposal(selected)">
                                    {{ savingProposal ? t('teamhub', 'Saving…') : t('teamhub', 'Save changes') }}
                                </NcButton>
                                <NcButton variant="tertiary" :disabled="savingProposal" @click="cancelEditProposal">
                                    {{ t('teamhub', 'Cancel') }}
                                </NcButton>
                            </div>
                        </template>

                        <!-- v4.11.19 — Edit proposal, Finalize and Discussion
                             are in the Actions menu. The hint stays, because
                             what Finalize *does* is not obvious from its name
                             and is the one thing on this block that cannot be
                             undone. -->
                        <p v-if="!editingProposal" class="th-dv__drafting-hint">
                            {{ t('teamhub', 'Finalizing locks the wording and sends the proposal to the category approvers. Edit, finalize and the discussion are in the Actions menu.') }}
                        </p>

                        </div><!-- /th-dv__drafting-main -->

                        <div class="th-dv__drafting-rail">
                            <!-- Discuss and Finalize are deliberately *both*
                                 active: on an open proposal the proposer can
                                 edit again or finalize, and neither is more
                                 current than the other. -->
                            <DecisionProgressRail :active="['discuss', 'finalize']" />
                        </div>
                    </div><!-- /th-dv__drafting-body -->
                    </div>

                    <!-- v4.11.18 put Request review and Supersede here, on the
                         approved decision rather than the open proposal.
                         v4.11.19 moved them into the Actions menu with the
                         rest; the reasoning for *which decisions* they apply
                         to is in `panelActions`. -->

                    </div><!-- /th-dv__detail-col--left -->

                    <!-- RIGHT COLUMN — approval / review (top) + audit trail -->
                    <div class="th-dv__detail-col th-dv__detail-col--right">

                    <!-- ── Review block (v4.11.23) ──
                         The Approval block's twin, and deliberately so.
                         Justin's rule: **permanent actions live in the
                         Actions menu, temporary ones live on the page.**
                         Answering a review is the most temporary thing a
                         decision ever has — somebody is waiting on it — and
                         it needs a mandatory reason, so the buttons belong
                         with the field they submit, in the same place an
                         approver already looks for exactly this.

                         Only one of the two blocks can ever be on screen:
                         approval is `finalized`, a review is `approved`. -->
                    <div
                        v-if="openReview && canApprove(selected)"
                        class="th-dv__detail-approval">
                        <span class="th-dv__detail-section-label">{{ t('teamhub', 'Review') }}</span>

                        <label class="th-dv__approval-label" :for="`th-dv-review-reason-${selected.id}`">
                            {{ t('teamhub', 'Reason') }}
                            <span class="th-dv__approval-required" aria-hidden="true">*</span>
                        </label>
                        <NcTextArea
                            :id="`th-dv-review-reason-${selected.id}`"
                            v-model="reviewReason"
                            class="th-dv__approval-textarea"
                            rows="3"
                            :placeholder="t('teamhub', 'Say why the decision stands, why it is being reworded, or why it is being retired — this becomes part of the audit trail.')"
                            :disabled="completingReview"
                            :maxlength="reviewReasonMax"
                            :aria-describedby="`th-dv-review-counter-${selected.id}`"
                            label-outside />
                        <p
                            :id="`th-dv-review-counter-${selected.id}`"
                            class="th-dv__approval-counter"
                            :class="{ 'th-dv__approval-counter--warn': reviewReason.length > reviewReasonMax - 50 }">
                            {{ reviewReason.length }} / {{ reviewReasonMax }}
                        </p>

                        <!-- v4.11.24 — the two answers depend on what was
                             asked. A **requested** review is a request, so
                             it is accepted or denied; a **periodic** one
                             asks "is this still right", so it is kept or
                             archived. Same block, same field, same service
                             call — four outcomes, two offered at a time. -->
                        <div v-if="openReview.kind === 'requested'" class="th-dv__approval-actions">
                            <!-- Accepting says the decision is altered: its
                                 wording is rewritten in place and it stays
                                 approved throughout (v4.11.26 — it used to
                                 reopen for redrafting, which let a review
                                 end with the decision denied).
                                 `success`, because agreeing to a colleague's
                                 request is the affirmative answer here — it
                                 is Deny that refuses.

                                 **v4.11.30 — two shapes, and which one you
                                 get is decided by the request and not by
                                 the approver.** A request that carries the
                                 wording it wants has something to approve,
                                 so *Approve* posts it in one click and
                                 *Edit wording* is the second-choice road for
                                 an approver who agrees with the ask but not
                                 with the words. A request that proposed
                                 nothing has nothing to approve, so the only
                                 affirmative answer is to write the wording
                                 yourself — which is what *Accept and reword*
                                 has always been, and is the only button
                                 offered there. Justin: *the approver only
                                 approves or denies with a reason, or is able
                                 to edit the final wording.* -->
                            <NcButton
                                v-if="openReviewHasProposal"
                                variant="success"
                                :disabled="!reviewReasonValid || completingReview"
                                @click="onCompleteReview('accepted')">
                                <template #icon><CheckCircleIcon :size="ICON_BODY" /></template>
                                {{ completingReview ? t('teamhub', 'Saving') : t('teamhub', 'Approve as proposed') }}
                            </NcButton>
                            <NcButton
                                :variant="openReviewHasProposal ? 'secondary' : 'success'"
                                :disabled="!reviewReasonValid || completingReview"
                                @click="openReviewAccept(selected)">
                                <template #icon>
                                    <PencilIcon v-if="openReviewHasProposal" :size="ICON_BODY" />
                                    <CheckCircleIcon v-else :size="ICON_BODY" />
                                </template>
                                {{ openReviewHasProposal
                                    ? t('teamhub', 'Edit wording')
                                    : t('teamhub', 'Accept and reword') }}
                            </NcButton>
                            <NcButton
                                variant="error"
                                :disabled="!reviewReasonValid || completingReview"
                                @click="onCompleteReview('denied')">
                                <template #icon><CloseIcon :size="ICON_BODY" /></template>
                                {{ t('teamhub', 'Deny') }}
                            </NcButton>
                        </div>

                        <div v-else class="th-dv__approval-actions">
                            <NcButton
                                variant="success"
                                :disabled="!reviewReasonValid || completingReview"
                                @click="onCompleteReview('kept')">
                                <template #icon><CheckCircleIcon :size="ICON_BODY" /></template>
                                {{ completingReview ? t('teamhub', 'Saving') : t('teamhub', 'Keep decision') }}
                            </NcButton>
                            <!-- `warning`, not `error`. Retiring a decision
                                 that has served its purpose is not a refusal
                                 and should not be painted as one. -->
                            <NcButton
                                variant="warning"
                                :disabled="!reviewReasonValid || completingReview"
                                @click="onCompleteReview('archived')">
                                <template #icon><ArchiveOutlineIcon :size="ICON_BODY" /></template>
                                {{ t('teamhub', 'Archive decision') }}
                            </NcButton>
                        </div>
                    </div>

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
                        <NcTextArea
                            :id="`th-dv-approval-reason-${selected.id}`"
                            v-model="approvalReason"
                            class="th-dv__approval-textarea"
                            rows="3"
                            :placeholder="t('teamhub', 'Briefly explain the decision — this becomes part of the audit trail.')"
                            :disabled="approving || denying"
                            :maxlength="approvalReasonMax"
                            :aria-describedby="`th-dv-approval-counter-${selected.id}`"
                            label-outside />
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
                                <template #icon><CheckCircleIcon :size="ICON_BODY" /></template>
                                {{ approving ? t('teamhub', 'Approving…') : t('teamhub', 'Approve') }}
                            </NcButton>
                            <NcButton
                                variant="error"
                                :disabled="!approvalReasonValid || approving || denying"
                                @click="onDenyWithReason(selected)">
                                <template #icon><CloseIcon :size="ICON_BODY" /></template>
                                {{ denying ? t('teamhub', 'Denying…') : t('teamhub', 'Deny') }}
                            </NcButton>
                            <!-- v4.11.19 — *Schedule meeting* (v3.74.10) is in
                                 the Actions menu. It is the one item in this
                                 block that was not a submit button: it
                                 scheduled a conversation rather than
                                 answering the proposal, and it deliberately
                                 did not disable Approve/Deny. Leaving it
                                 beside them made it look like a third
                                 outcome. -->
                        </div>

                        <!-- Only where the item exists: pointing at a menu
                             entry a team without Calendar will never see
                             would be worse than saying nothing. -->
                        <p v-if="canScheduleApproverMeeting(selected)" class="th-dv__approval-hint">
                            {{ t('teamhub', 'Want to talk it over with the other approvers first? Schedule meeting is in the Actions menu.') }}
                        </p>
                    </div>

                    <!-- ── Scheduled meetings (v3.74.10) ──
                         Shows approver meetings scheduled via "Schedule meeting".
                         Hidden entirely when no meetings have been scheduled yet
                         to keep the panel clean for proposals that don't need one. -->
                    <div v-if="scheduledMeetings.length || scheduledMeetingsLoading" class="th-dv__detail-meetings">
                        <span class="th-dv__detail-section-label">{{ t('teamhub', 'Scheduled meetings') }}</span>

                        <div v-if="scheduledMeetingsLoading" class="th-dv__meetings-loading">
                            <NcLoadingIcon :size="ICON_BODY" />
                        </div>

                        <ul v-else-if="scheduledMeetings.length" class="th-dv__link-list" aria-live="polite">
                            <li
                                v-for="m in scheduledMeetings"
                                :key="m.id"
                                class="th-dv__link-item">
                                <div class="th-dv__link-row" :title="m.meetingTitle">
                                    <CalendarClockIcon :size="ICON_BODY" class="th-dv__link-icon" aria-hidden="true" />
                                    <span class="th-dv__link-label">
                                        {{ m.meetingTitle }}
                                        <span class="th-dv__meeting-when">
                                            · {{ formatMeetingTime(m.meetingStart) }}
                                        </span>
                                    </span>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <!-- ── Links ──
                         v3.75.3 unified decision↔decision links with external
                         URLs here. v4.11.19 folds the linked **tasks** in as
                         well, so everything this decision points at is one
                         list with a kind pill on each row.

                         Why one list: the two sections had the same shape, the
                         same remove button, the same inline paste-a-URL form
                         and the same gate, and differed only in what the row
                         pointed at. A reader asking "what else is attached to
                         this decision" had to look in two places and notice
                         that the answer was split; a decision with one task and
                         one related decision rendered two sections of one row.
                         The pill is what the heading used to be. -->
                    <div class="th-dv__detail-dec-links">
                        <span class="th-dv__detail-section-label">{{ t('teamhub', 'Links') }}</span>

                        <div v-if="linksLoading" class="th-dv__dec-links-loading">
                            <NcLoadingIcon :size="ICON_BODY" />
                        </div>

                        <!-- One list, three kinds. Each row dispatches on kind:
                             decision → button that opens the peer decision
                             task     → anchor to the task, in a new tab
                             external → anchor to an outbound URL, in a new tab -->
                        <ul v-if="unifiedLinks.length"
                            class="th-dv__link-list"
                            aria-live="polite"
                            :aria-label="t('teamhub', 'Links on this decision')">
                            <li
                                v-for="row in unifiedLinks"
                                :key="row.kind + ':' + row.id"
                                class="th-dv__link-item">

                                <!-- v4.11.31 — the decision this one
                                     replaced. Opens in this view like any
                                     other decision row; what differs is the
                                     pill, which names the *relationship*
                                     rather than the kind of thing linked,
                                     and that it has no Remove button below:
                                     a supersede is part of what this
                                     decision is, not something attached to
                                     it. Synthesised from `supersedes_id`, so
                                     there is nothing to remove it from. -->
                                <button
                                    v-if="row.kind === 'supersedes'"
                                    class="th-dv__link-row"
                                    :title="row.peer_title"
                                    @click="selectDecisionById(row.peer_id)">
                                    <SwapHorizontalIcon :size="ICON_BODY" class="th-dv__link-icon" aria-hidden="true" />
                                    <span class="th-dv__link-label">{{ row.peer_title }}</span>
                                    <span class="th-dv__link-pill th-dv__link-pill--kind th-dv__link-pill--kind-supersedes">
                                        <!-- TRANSLATORS: pill on a link row — this decision replaced the decision the row points at -->
                                        {{ t('teamhub', 'Supersedes') }}
                                    </span>
                                    <!-- No status pill, unlike the Decision
                                         row below. The peer of a Supersedes
                                         row is always `superseded` — that is
                                         what being on this row means — so a
                                         status pill would say a second time
                                         what the kind pill just said. In
                                         German it would say it in the same
                                         word: *Superseded* and *Supersedes*
                                         are both "Ersetzt". -->
                                </button>

                                <!-- A decision in this team — opens in this view -->
                                <button
                                    v-else-if="row.kind === 'internal'"
                                    class="th-dv__link-row"
                                    :title="row.peer_title"
                                    @click="selectDecisionById(row.peer_id)">
                                    <GavelIcon :size="ICON_BODY" class="th-dv__link-icon" aria-hidden="true" />
                                    <span class="th-dv__link-label">{{ row.peer_title }}</span>
                                    <span class="th-dv__link-pill th-dv__link-pill--kind th-dv__link-pill--kind-internal">
                                        <!-- TRANSLATORS: pill on a link row — it points at another decision in this TeamHub -->
                                        {{ t('teamhub', 'Decision') }}
                                    </span>
                                    <span
                                        v-if="decisionsLevelEnabled && row.peer_level"
                                        class="th-dv__link-pill th-dv__link-pill--level"
                                        :class="'th-dv__link-pill--level-' + row.peer_level">
                                        {{ levelLabel(row.peer_level) }}
                                    </span>
                                    <span
                                        class="th-dv__link-pill th-dv__link-pill--status"
                                        :class="'th-dv__link-pill--status-' + row.peer_status">
                                        {{ statusLabel(row.peer_status) }}
                                    </span>
                                </button>

                                <!-- A task — a Deck card or a pasted path -->
                                <a
                                    v-else-if="row.kind === 'task'"
                                    :href="generateUrl('/' + row.task_path)"
                                    target="_blank"
                                    rel="noopener"
                                    class="th-dv__link-row"
                                    :title="row.task_path">
                                    <FormatListBulletedIcon :size="ICON_BODY" class="th-dv__link-icon" aria-hidden="true" />
                                    <span class="th-dv__link-label">{{ row.label || row.task_path }}</span>
                                    <span class="th-dv__link-pill th-dv__link-pill--kind th-dv__link-pill--kind-task">
                                        <!-- TRANSLATORS: pill on a link row — it points at a task (a Deck card or a pasted task URL) -->
                                        {{ t('teamhub', 'Task') }}
                                    </span>
                                    <!-- Completion is only known for Deck cards,
                                         where `isDone` is non-null. A pasted path
                                         has no state we can read, so it gets no
                                         pill rather than a guessed one. -->
                                    <span
                                        v-if="row.isDone !== null && row.isDone !== undefined"
                                        class="th-dv__link-pill th-dv__link-pill--status"
                                        :class="row.isDone ? 'th-dv__link-pill--status-approved' : 'th-dv__link-pill--status-open'">
                                        <!-- TRANSLATORS: pill on a linked Deck card that has been completed -->
                                        <template v-if="row.isDone">{{ t('teamhub', 'Complete') }}</template>
                                        <!-- TRANSLATORS: pill on a linked Deck card that is still open -->
                                        <template v-else>{{ t('teamhub', 'Open') }}</template>
                                    </span>
                                </a>

                                <!-- An outbound URL -->
                                <a
                                    v-else
                                    :href="row.url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="th-dv__link-row"
                                    :title="row.url"
                                    @click="onExternalLinkOpen">
                                    <OpenInNewIcon :size="ICON_BODY" class="th-dv__link-icon" aria-hidden="true" />
                                    <span class="th-dv__link-label">{{ externalLinkDisplay(row) }}</span>
                                    <span class="th-dv__link-pill th-dv__link-pill--kind th-dv__link-pill--kind-external">
                                        <!-- TRANSLATORS: pill on a link row — it points at a URL outside TeamHub -->
                                        {{ t('teamhub', 'External') }}
                                    </span>
                                </a>

                                <!-- v4.11.31 — every kind but `supersedes`.
                                     That row is read off `supersedes_id` and
                                     is the lifecycle talking; a Remove button
                                     on it would promise to undo a supersede,
                                     which is not a thing this module can
                                     do. -->
                                <NcButton
                                    v-if="canPerformDecisionActions && row.kind !== 'supersedes'"
                                    class="th-dv__link-remove"
                                    :aria-label="t('teamhub', 'Remove link')"
                                    :title="t('teamhub', 'Remove link')"
                                    @click="removeLink(row)"
                                    variant="tertiary"
                                    size="small">
                                    <template #icon>
                                        <CloseIcon :size="ICON_INLINE" />
                                    </template>
                                </NcButton>
                            </li>
                        </ul>

                        <!-- Empty state: nothing of any kind attached yet -->
                        <p v-else-if="!linksLoading" class="th-dv__link-empty">
                            {{ t('teamhub', 'Nothing linked to this decision') }}
                        </p>

                        <!-- v4.11.19 — the four *Link …* buttons are in the
                             Actions menu. What stays here is the form a menu
                             item opens, because a field belongs where its
                             result will appear. -->
                        <div v-if="canPerformDecisionActions && (showLinkTaskForm || showLinkExternalForm)" class="th-dv__link-actions-row">
                            <div v-if="showLinkTaskForm" class="th-dv__tasks-link-form">
                                <NcTextField
                                    ref="linkTaskInput"
                                    v-model="linkTaskPath"
                                    class="th-dv__tasks-link-input"
                                    :placeholder="t('teamhub', 'Paste task URL or path')"
                                    :aria-label="t('teamhub', 'Task URL or path')"
                                    @keydown.enter="submitLinkTask"
                                    label-outside />
                                <NcTextField
                                    v-model="linkTaskLabel"
                                    class="th-dv__tasks-link-input th-dv__tasks-link-input--label"
                                    :placeholder="t('teamhub', 'Label (optional)')"
                                    :aria-label="t('teamhub', 'Task label')"
                                    label-outside />
                                <div class="th-dv__tasks-link-btns">
                                    <NcButton variant="primary" :disabled="!linkTaskPath.trim() || linkingTask" @click="submitLinkTask">
                                        {{ linkingTask ? t('teamhub', 'Linking…') : t('teamhub', 'Link') }}
                                    </NcButton>
                                    <NcButton variant="tertiary" @click="showLinkTaskForm = false">
                                        {{ t('teamhub', 'Cancel') }}
                                    </NcButton>
                                </div>
                            </div>

                            <div v-else class="th-dv__tasks-link-form th-dv__tasks-link-form--ext">
                                <NcTextField
                                    ref="linkExternalInput"
                                    v-model="linkExternalUrl"
                                    type="url"
                                    class="th-dv__tasks-link-input"
                                    :placeholder="t('teamhub', 'Paste URL (https://…)')"
                                    :aria-label="t('teamhub', 'External decision URL')"
                                    @keydown.enter="submitLinkExternal"
                                    label-outside />
                                <NcTextField
                                    v-model="linkExternalLabel"
                                    class="th-dv__tasks-link-input th-dv__tasks-link-input--label"
                                    :placeholder="t('teamhub', 'Label (optional)')"
                                    :aria-label="t('teamhub', 'External link label')"
                                    label-outside />
                                <div class="th-dv__tasks-link-btns">
                                    <NcButton variant="primary" :disabled="!linkExternalUrl.trim() || linkingExternal" @click="submitLinkExternal">
                                        {{ linkingExternal ? t('teamhub', 'Linking…') : t('teamhub', 'Link') }}
                                    </NcButton>
                                    <NcButton variant="tertiary" @click="showLinkExternalForm = false">
                                        {{ t('teamhub', 'Cancel') }}
                                    </NcButton>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Link a decision ──
                         Session C; an `NcDialog` since v4.11.19. Converted
                         with the supersede picker rather than after it: two
                         pickers doing nearly the same job, side by side in
                         one panel, one in Nextcloud's dialog and one in a
                         bare modal, is the inconsistency the move was for.
                         Its behaviour is unchanged — it still searches every
                         decision, not only the approved ones, because a link
                         is a relation and a relation to an open proposal is
                         a real thing to record. -->
                    <NcDialog
                        v-if="showDecisionPicker"
                        :name="t('teamhub', 'Link a decision')"
                        size="normal"
                        @closing="closeDecisionPicker">
                        <div class="th-dv-dialog">
                            <p class="th-dv-dialog__intro">
                                {{ t('teamhub', 'Search for a decision to link') }}
                            </p>

                            <NcTextField
                                ref="decPickerInput"
                                v-model="decPickerQuery"
                                type="search"
                                class="th-dv-dialog__field"
                                :label="t('teamhub', 'Search decisions')"
                                :placeholder="t('teamhub', 'Type a few words from its question')"
                                @update:model-value="onDecPickerInput" />

                            <div v-if="decPickerLoading" class="th-dv-dialog__loading">
                                <NcLoadingIcon :size="ICON_BODY" />
                                <span>{{ t('teamhub', 'Searching') }}</span>
                            </div>

                            <ul
                                v-else-if="decPickerResults.length"
                                class="th-dv-dialog__results"
                                aria-live="polite"
                                :aria-label="t('teamhub', 'Search results')">
                                <li v-for="d in decPickerResults" :key="d.id">
                                    <button
                                        type="button"
                                        class="th-dv-dialog__result"
                                        :disabled="decPickerLinking"
                                        @click="linkPickedDecision(d.id)">
                                        <GavelIcon :size="ICON_BODY" class="th-dv-dialog__result-icon" aria-hidden="true" />
                                        <span class="th-dv-dialog__result-text">
                                            <span class="th-dv-dialog__result-title">{{ d.question }}</span>
                                            <span class="th-dv-dialog__result-meta">{{ statusLabel(d.status) }}</span>
                                        </span>
                                    </button>
                                </li>
                            </ul>

                            <NcEmptyContent
                                v-else
                                class="th-dv-dialog__empty"
                                :name="decPickerQuery.trim()
                                    ? t('teamhub', 'No decisions found')
                                    : t('teamhub', 'Type to search decisions')"
                                :description="decPickerQuery.trim()
                                    ? t('teamhub', 'Try fewer words.')
                                    : t('teamhub', 'A few words from the decision’s question is enough.')">
                                <template #icon><GavelIcon :size="ICON_XL" /></template>
                            </NcEmptyContent>
                        </div>
                    </NcDialog>

                    <!-- ── Audit trail (Session J) ── -->
                    <div class="th-dv__detail-audit">
                        <span class="th-dv__detail-section-label">{{ t('teamhub', 'Audit trail') }}</span>

                        <div v-if="auditLoading" class="th-dv__audit-loading">
                            <NcLoadingIcon :size="ICON_BODY" />
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

        <!-- ── Add or edit a category (v4.11.23) ──
             The same component Manage team → Decisions uses, so the two
             surfaces cannot offer different options. -->
        <DecisionCategoryDialog
            v-if="categoryDialogOpen"
            :team-id="currentTeamId"
            :category="categoryDialogTarget"
            :members="members || []"
            @saved="onCategorySaved"
            @close="closeCategoryDialog" />

        <!-- ── Archive a decision (v4.11.24) ──
             The Actions-menu half of *archive*. A menu item that needs a
             field opens a dialog for it — the same shape *Request a review*
             uses, and the reason §2.159's rule gives for not putting the
             field itself in the menu. -->
        <NcDialog
            v-if="archiveTarget"
            :name="t('teamhub', 'Archive decision')"
            :buttons="archiveButtons"
            size="normal"
            @closing="closeArchive">
            <div class="th-dv-dialog">
                <p class="th-dv-dialog__intro">
                    {{ t('teamhub', 'The decision moves to the team archive and becomes read-only. The record and its audit trail are kept.') }}
                </p>
                <NcTextArea
                    v-model="archiveReason"
                    class="th-dv-dialog__field"
                    rows="3"
                    :label="t('teamhub', 'Why is it being archived?')"
                    :placeholder="t('teamhub', 'Say why it no longer applies — this becomes part of the audit trail.')"
                    :disabled="archiving"
                    :maxlength="reviewReasonMax"
                    :helper-text="`${archiveReason.length} / ${reviewReasonMax}`"
                    required />
            </div>
        </NcDialog>

        <!-- ── Accept a review request (v4.11.26) ──
             Accepting means the decision is **altered**, so this dialog is
             where the alteration is written. The decision never leaves
             `approved`: there is no redraft, no second finalize and no
             second approval, which is what keeps `denied` out of reach of a
             review.

             Both fields are prefilled with what the decision says now, so
             the approver edits a decision rather than filling in a form.
             The reason comes over from the Review block's field — it was
             already typed and required there, and asking for it twice would
             read as two different questions. -->
        <NcDialog
            v-if="reviewAcceptTarget"
            :name="openReviewHasProposal ? t('teamhub', 'Edit the proposed wording') : t('teamhub', 'Accept and reword')"
            :buttons="reviewAcceptButtons"
            size="large"
            @closing="closeReviewAccept">
            <div class="th-dv-dialog">
                <p class="th-dv-dialog__intro">
                    {{ t('teamhub', 'The decision stays in force and keeps its approval — only its wording changes. The previous wording is kept in the audit trail.') }}
                </p>
                <!-- v4.11.30 — says whose words are in the boxes. An
                     approver who opened this from *Edit wording* is looking
                     at the requester's proposal, not at the decision as it
                     stands, and editing the one while believing it is the
                     other is how a correction becomes a surprise.
                     The attachment is deliberately not here: it is the
                     requester's and the approver does not edit it. -->
                <NcNoteCard v-if="openReviewHasProposal" type="info">
                    {{ t('teamhub', 'These are the words {name} proposed. Edit them and approve, or go back and approve them as they are.', { name: openReview ? openReview.requestedByName : '' }) }}
                </NcNoteCard>
                <NcTextField
                    v-model="reviewAcceptQuestion"
                    class="th-dv-dialog__field"
                    :label="t('teamhub', 'Question')"
                    :disabled="completingReview"
                    :maxlength="4000" />
                <NcTextArea
                    v-model="reviewAcceptAnswer"
                    class="th-dv-dialog__field"
                    rows="8"
                    :label="t('teamhub', 'Decision')"
                    :placeholder="t('teamhub', 'The wording the team is subject to from now on.')"
                    :disabled="completingReview"
                    :maxlength="4000"
                    required />
                <NcNoteCard v-if="!reviewAcceptChanged" type="info">
                    {{ t('teamhub', 'Nothing has been changed yet. Accepting a review means altering the decision — edit the wording above, or deny the request instead.') }}
                </NcNoteCard>
            </div>
        </NcDialog>

        <!-- ── Request a review ──
             v4.11.19 — an `NcDialog`, not a bare `NcModal`. The previous one
             wrapped its content in `.th-dv-modal` / `__intro` / `__quote` /
             `__actions`, and **none of those four classes had a single rule
             anywhere in this file**. They never did: the old supersede
             confirm was their only user and it was two lines of text, so an
             unstyled box looked like a plain box. Putting a labelled form
             with a counter and two buttons inside the same box is what made
             it visible. `NcDialog` brings Nextcloud's own padding, heading,
             scrolling and button row, so there is nothing of ours left to
             forget to style. -->
        <NcDialog
            v-if="reviewRequestTarget"
            :name="t('teamhub', 'Request a review')"
            :buttons="reviewRequestButtons"
            size="normal"
            @closing="closeReviewRequest">
            <div class="th-dv-dialog">
                <p class="th-dv-dialog__intro">
                    {{ t('teamhub', 'The approvers of this category will be asked to look at the decision again. It stays in force until they answer.') }}
                </p>
                <NcTextArea
                    v-model="reviewMotivation"
                    class="th-dv-dialog__field"
                    rows="4"
                    :label="t('teamhub', 'Why does this need reviewing?')"
                    :placeholder="t('teamhub', 'Describe what changed — this becomes part of the audit trail.')"
                    :disabled="requestingReview"
                    :maxlength="reviewMotivationMax"
                    :helper-text="reviewMotivationHelper"
                    required />

                <!-- v4.11.30 — what the requester is asking for.
                     Justin: *the review requester should give the correct
                     wording and if needed attachment.* They are the one who
                     knows what the decision should now say; the approver's
                     job is to agree with it, refuse it, or correct the
                     words. Both fields are prefilled with what the decision
                     says today, so this reads as editing a decision rather
                     than filling in a form — the same choice the *Accept and
                     reword* dialog made for the approver.

                     Optional, and the fieldset says so. *Look at this
                     again*, with nothing proposed, is still a legitimate
                     thing for a member to ask and is the only shape a review
                     had before this version. -->
                <fieldset class="th-dv-dialog__fieldset">
                    <legend class="th-dv-dialog__legend">
                        {{ t('teamhub', 'What should it say instead?') }}
                    </legend>
                    <p class="th-dv-dialog__legend-hint">
                        {{ t('teamhub', 'Optional. Fill this in and the approvers can approve your wording in one step. Leave it empty to ask them to look at the decision without proposing anything.') }}
                    </p>

                    <NcTextField
                        v-model="reviewProposedQuestion"
                        class="th-dv-dialog__field"
                        :label="t('teamhub', 'Proposed question')"
                        :disabled="requestingReview"
                        :maxlength="reviewProposedMax" />
                    <NcTextArea
                        v-model="reviewProposedAnswer"
                        class="th-dv-dialog__field"
                        rows="6"
                        :label="t('teamhub', 'Proposed wording')"
                        :placeholder="t('teamhub', 'The wording you are asking the team to be subject to instead.')"
                        :disabled="requestingReview"
                        :maxlength="reviewProposedMax" />

                    <DecisionAttachField
                        v-model="reviewProposedAttachment"
                        class="th-dv-dialog__field"
                        :label="t('teamhub', 'Supporting file')"
                        :hint="t('teamhub', 'Copied into the decision so the approvers can read it. It is kept if they approve and removed if they deny.')"
                        :disabled="requestingReview" />
                </fieldset>
            </div>
        </NcDialog>

        <!-- ── Move a decision to another category (v4.11.30) ──
             Justin: *an approver for a category can move a proposal that is
             in the wrong category to the proper category from the actions
             menu on the decision page.*

             A menu item that needs a field opens a dialog for it, which is
             the shape *Request a review* and *Archive decision* both use.
             No reason field: where a decision is filed is a clerical fact
             about it, not a judgement on it, and the audit line already
             carries both category names and who moved it. -->
        <NcDialog
            v-if="moveCategoryTarget"
            :name="t('teamhub', 'Move to another category')"
            :buttons="moveCategoryButtons"
            size="normal"
            @closing="closeMoveCategory">
            <div class="th-dv-dialog">
                <p class="th-dv-dialog__intro">
                    {{ t('teamhub', 'The decision moves to the category you pick, and its approvers take it over from there. The archive is not on this list — archiving a decision is a separate action.') }}
                </p>
                <NcNoteCard v-if="!moveCategoryOptions.length" type="info">
                    {{ t('teamhub', 'This team has no other category to move it to. A team admin can add one on the Decisions page.') }}
                </NcNoteCard>
                <template v-else>
                    <label for="th-dv-move-category" class="th-dv-dialog__label">
                        {{ t('teamhub', 'New category') }}
                    </label>
                    <NcSelect
                        v-model="moveCategoryChoice"
                        class="th-dv-dialog__field"
                        input-id="th-dv-move-category"
                        label-outside
                        :aria-label-combobox="t('teamhub', 'New category')"
                        :options="moveCategoryOptions"
                        :reduce="o => o.id"
                        :clearable="false"
                        :disabled="movingCategory"
                        :placeholder="t('teamhub', 'Pick a category')" />
                    <!-- A moved decision that is already approved restarts
                         its clock on the new category's period, which is a
                         consequence worth reading before the click and not
                         after. -->
                    <p v-if="selected && selected.status === 'approved'" class="th-dv-dialog__hint">
                        {{ t('teamhub', 'This decision is approved, so its next review date is recalculated from the new category.') }}
                    </p>
                </template>
            </div>
        </NcDialog>

        <!-- ── Supersede picker ──
             v4.11.19 — an `NcDialog` with a search field and **at most four
             results**, which is what the service desk's *Link a decision*
             picker settled on in v4.11.12 and is the same act: a picker is
             for recognising the decision you already have in mind, not for
             browsing. 4.11.18 listed the fifty most recent and reasoned that
             a team with more than fifty approved decisions should look in
             its archive — which told somebody with sixty exactly nothing.
             The server does the matching, so it is the same LIKE the
             Decisions list already runs.

             No footer buttons: picking a result **is** the confirmation, so
             a *Supersede* button below the list would be a second click for
             a choice already made. Cancel is the dialog's own close. -->
        <NcDialog
            v-if="supersedeTarget"
            :name="t('teamhub', 'Supersede decision')"
            size="normal"
            @closing="closeSupersede">
            <div class="th-dv-dialog">
                <p class="th-dv-dialog__intro">
                    {{ t('teamhub', 'Pick the decision that overrules this one. This decision moves to the archive and becomes read-only.') }}
                </p>
                <NcTextField
                    ref="supersedeSearch"
                    v-model="supersedeQuery"
                    type="search"
                    class="th-dv-dialog__field"
                    :label="t('teamhub', 'Search approved decisions')"
                    :placeholder="t('teamhub', 'Type a few words from its question')"
                    :disabled="superseding"
                    @update:model-value="onSupersedeSearch" />

                <div v-if="supersedeLoading" class="th-dv-dialog__loading">
                    <NcLoadingIcon :size="ICON_BODY" />
                    <span>{{ t('teamhub', 'Searching') }}</span>
                </div>

                <NcNoteCard v-else-if="supersedeError" type="error">
                    {{ supersedeError }}
                </NcNoteCard>

                <ul
                    v-else-if="supersedeOptions.length"
                    class="th-dv-dialog__results"
                    :aria-label="t('teamhub', 'Approved decisions')">
                    <li v-for="d in supersedeOptions" :key="d.id">
                        <button
                            type="button"
                            class="th-dv-dialog__result"
                            :disabled="superseding"
                            @click="confirmSupersede(d.id)">
                            <GavelIcon :size="ICON_BODY" class="th-dv-dialog__result-icon" aria-hidden="true" />
                            <span class="th-dv-dialog__result-text">
                                <span class="th-dv-dialog__result-title">{{ d.question }}</span>
                                <span class="th-dv-dialog__result-meta">
                                    <template v-if="d.category">{{ d.category }}</template>
                                    <template v-if="d.category && d.resolvedAt"> · </template>
                                    <template v-if="d.resolvedAt">{{ fullDate(d.resolvedAt) }}</template>
                                </span>
                            </span>
                        </button>
                    </li>
                </ul>

                <!-- Two empty states, because they mean different things:
                     a search that found nothing is not a team with nothing
                     to find. -->
                <NcEmptyContent
                    v-else
                    class="th-dv-dialog__empty"
                    :name="supersedeQuery.trim()
                        ? t('teamhub', 'No approved decision matches that')
                        : t('teamhub', 'No other approved decision yet')"
                    :description="supersedeQuery.trim()
                        ? t('teamhub', 'Only approved decisions can supersede another one. Try fewer words.')
                        : t('teamhub', 'A decision has to be approved before it can supersede another one.')">
                    <template #icon><GavelIcon :size="ICON_XL" /></template>
                </NcEmptyContent>
            </div>
        </NcDialog>

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
                        <FileDocumentOutlineIcon :size="ICON_BODY" aria-hidden="true" />
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
                        <DownloadIcon :size="ICON_INLINE" aria-hidden="true" />
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
                        <OpenInNewIcon :size="ICON_INLINE" aria-hidden="true" />
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
                        <NcLoadingIcon :size="ICON_LARGE" />
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
                        <FileDocumentOutlineIcon :size="ICON_XL" aria-hidden="true" />
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
                                <DownloadIcon :size="ICON_BODY" aria-hidden="true" />
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

        <!-- v3.74.10 — Schedule approver meeting wizard.
             Mounted on demand from the "Schedule meeting" button in the
             approval block. Pre-filled with the approvers for the proposal's
             category, plus title/description/category. The wizard emits
             `created` with {eventUid, start, title} which is then used to
             record the back-reference on the proposal. -->
        <SuggestMeetingWizard
            v-if="showApproverMeetingWizard"
            :team-id="currentTeamId"
            :calendars="resources.calendar || []"
            :prefilled-attendees="approverPrefill.attendees"
            :prefilled-title="approverPrefill.title"
            :prefilled-description="approverPrefill.description"
            :prefilled-category="approverPrefill.category"
            :prefill-banner="approverPrefill.banner"
            :lock-attendees="true"
            @created="onApproverMeetingCreated"
            @close="showApproverMeetingWizard = false; schedulingMeetingForDecisionId = null" />

    </div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { formatDate, formatDateTime } from '../lib/localDate.js'
import { mapState, mapMutations }                from 'vuex'
import { generateUrl }                           from '@nextcloud/router'
import { decisionUrl, handleInternalLinkClick }  from '../lib/internalLinks.js'
import { CATEGORY_ICONS, CATEGORY_ICON_MAP } from '../lib/decisionCategoryIcons.js'
import { showError, showSuccess }                from '@nextcloud/dialogs'
import {
	NcActionButton, NcActionCheckbox, NcActionLink, NcActions, NcActionSeparator,
	NcAvatar, NcButton, NcDialog, NcModal, NcEmptyContent, NcLoadingIcon, NcNoteCard,
	NcTextField, NcTextArea, NcSelect,
} from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import DOMPurify from 'dompurify'
import { AVATAR_SM, ICON_BODY, ICON_INLINE, ICON_LARGE, ICON_XL } from '../constants/uiTokens.js'
// v4.11.19 — the Actions menu's contents and every gate behind them.
import { decisionPanelActions } from '../constants/decisions.js'

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
import FilterVariantIcon      from 'vue-material-design-icons/FilterVariant.vue'
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
import CalendarPlusIcon        from 'vue-material-design-icons/CalendarPlus.vue'
import CalendarClockIcon       from 'vue-material-design-icons/CalendarClock.vue'
import FlagOutlineIcon         from 'vue-material-design-icons/FlagOutline.vue'
// v4.5.42 — the proposer's drafting block.
import PencilIcon              from 'vue-material-design-icons/Pencil.vue'
import ForumIcon               from 'vue-material-design-icons/Forum.vue'
// v4.11.18 — the review and retirement cycle. ArchiveOutline is also in
// CATEGORY_ICONS now (the seeded Archive category carries it), but the
// picker's map and the template's imports are separate lists by design —
// see ../lib/decisionCategoryIcons.js.
import RefreshIcon             from 'vue-material-design-icons/Refresh.vue'
import ArchiveOutlineIcon      from 'vue-material-design-icons/ArchiveOutline.vue'
import FolderMoveOutlineIcon   from 'vue-material-design-icons/FolderMoveOutline.vue'
import AddTaskModal             from './AddTaskModal.vue'
import SuggestMeetingWizard     from './SuggestMeetingWizard.vue'
import DecisionProgressRail     from './DecisionProgressRail.vue'
// v4.11.23 — the category form, shared with ManageTeamView.
import DecisionCategoryDialog   from './DecisionCategoryDialog.vue'
// v4.11.30 — the optional file on a review request: pick one from your own
// Nextcloud or upload one, and hand the server the id either way.
import DecisionAttachField     from './DecisionAttachField.vue'

const PAGE_SIZE = 50

export default {
    name: 'TeamDecisionsView',

    components: {
          NcTextArea, NcTextField, NcSelect, NcAvatar, NcButton, NcDialog, NcModal, NcEmptyContent, NcLoadingIcon, NcNoteCard,
        // v4.11.19 — the Actions menu. NcCheckboxRadioSwitch left with the
        // inline *Public decision* switch it was the only user of.
        NcActions, NcActionButton, NcActionCheckbox, NcActionLink, NcActionSeparator,
        GavelIcon, PlusIcon, TagIcon, ChevronRightIcon, ChevronLeftIcon,
        CloseIcon, CheckCircleIcon,
        SortAscendingIcon, SortDescendingIcon, FilterVariantIcon,
        MessageOutlineIcon, SwapHorizontalIcon,
        AlertCircleOutlineIcon, OpenInNewIcon, FileDocumentOutlineIcon, DownloadIcon,
        FolderOutlineIcon, MagnifyIcon, FormatListBulletedIcon,
        LinkVariantIcon, CalendarPlusIcon, CalendarClockIcon,
        FlagOutlineIcon,
        PencilIcon, ForumIcon,
        RefreshIcon, ArchiveOutlineIcon, FolderMoveOutlineIcon,
        AddTaskModal, SuggestMeetingWizard, DecisionProgressRail, DecisionCategoryDialog,
        DecisionAttachField,
    },

    // v4.11.18 — `propose-decision-superseding` is gone with the
    // open-proposal Supersede button. Superseding is now a write on the
    // approved decision, not a handshake that opens the composer.
    emits: ['propose-decision'],

    data() {
        return {
            ICON_BODY,
            ICON_INLINE,
            ICON_LARGE,
            ICON_XL,
            // v4.11.19 — the approver avatars on a category card. Three,
            // because a fourth stops reading as "these people" and starts
            // reading as a crowd; the rest become a +N that names them all
            // on hover.
            AVATAR_SM,
            APPROVER_AVATAR_CAP: 3,
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
            // v3.75.4 — mobile-only: collapsed-by-default filter panel toggle.
            // Has no effect on desktop layout (CSS hides the button at ≥720px
            // and always shows the chips). On mobile (<720px), chips are
            // hidden by default and revealed when this flag is true.
            mobileFiltersOpen: false,
            sort:         'recent',

            selected:        null,  // decision object currently shown in detail panel

            // v4.11.23 — the category form, opened from the landing grid.
            // `null` target means create.
            categoryDialogOpen:   false,
            categoryDialogTarget: null,

            // ── v4.11.18 — review and retirement of an approved decision ──
            // The supersede picker. `supersedeTarget` is the decision being
            // retired; the options are the team's other approved decisions.
            supersedeTarget:  null,
            supersedeOptions: [],
            supersedeLoading: false,
            supersedeError:   '',
            superseding:      false,

            // Requesting a review.
            reviewRequestTarget: null,
            reviewMotivation:    '',
            reviewMotivationMax: 1000,   // DecisionReviewService::MAX_MOTIVATION_LEN
            requestingReview:    false,

            // v4.11.30 — what the request is asking for. Prefilled with the
            // decision's current wording when the dialog opens, so an empty
            // proposal is one the requester deliberately cleared rather than
            // one they were handed two blank boxes for. `null` attachment
            // means no file; `{ fileId, name }` means one.
            reviewProposedQuestion:   '',
            reviewProposedAnswer:     '',
            reviewProposedAttachment: null,
            reviewProposedMax:        4000,  // DecisionReviewService::MAX_PROPOSED_LEN

            // v4.11.30 — moving a miscategorised decision, from the menu.
            moveCategoryTarget: null,
            moveCategoryChoice: null,
            movingCategory:     false,

            // v4.11.24 — archiving straight from the Actions menu.
            archiveTarget: null,
            archiveReason: '',
            archiving:     false,

            // v4.11.19 — the supersede picker searches now, so it debounces.
            supersedeQuery:      '',
            supersedeSearchId:   null,

            // The reviews of the selected decision, newest first, and the
            // open one pulled out of them. Loaded per selection beside the
            // audit — the list endpoint deliberately omits them, the same
            // reasoning §2.79 recorded for the proposal body.
            // v4.11.23 — the Review block's mandatory reason, mirroring
            // `approvalReason`. Its own field rather than a shared one: the
            // two blocks are never on screen together, but a reason typed
            // for an approval has no business surviving into a review.
            reviewReason:     '',
            reviewReasonMax:  1000,   // DecisionReviewService::MAX_REASON_LEN
            reviews:          [],
            reviewsLoading:   false,
            reviewsForDecisionId: null,
            completingReview: false,
            // v4.11.26 — the *Accept and reword* dialog. The decision being
            // reworded, and the two fields of it that the approver is
            // rewriting; both are prefilled from what it says now, so an
            // untouched dialog submits nothing and the server would refuse
            // it anyway.
            reviewAcceptTarget:   null,
            reviewAcceptQuestion: '',
            reviewAcceptAnswer:   '',
            // { id, question, status } — the decision that replaced a
            // superseded one. Resolved by GET /decisions/{id}, which is the
            // only response that carries it.
            supersededBy:     null,
            // v4.11.31 — { id, question, status } — the decision **this** one
            // replaced, for the Links section. The mirror of `supersededBy`
            // and resolved from the same `supersedes_id`, read from the other
            // side: that column is the only place a supersede is stored.
            supersedes:       null,
            // Which decision `supersedes` has been **settled** for, loaded
            // or failed. Needed because `linksLoading` below waits for that
            // fetch, and a failed one would otherwise spin for ever.
            supersedesSettledFor: null,

            // Session H — approve/deny flow
            approverCategories: [],  // cache of {id, name, approvers: [uid]} for auth
            approverCategoriesLoaded: false,
            approving:     false,
            denying:       false,

            // v3.71.3 — inline approval block
            approvalReason:    '',
            approvalReasonMax: 500,
            approvalForDecisionId: null,

            // v4.5.42 — the proposer's drafting block on an open proposal.
            editingProposal:    false,
            editQuestion:       '',
            editBody:           '',
            savingProposal:     false,
            finalizingProposal: false,

            // The proposal's context text, fetched per selection. `null` is
            // "not loaded yet" and `''` is "loaded, and there is none" — the
            // two have to be distinguishable or an empty context renders the
            // same as a pending fetch.
            proposalBody:           null,
            proposalBodyDecisionId: null,

            // v3.74.10 — Schedule approver meeting flow.
            // When showApproverMeetingWizard is true, SuggestMeetingWizard
            // is mounted with the prefill data captured in approverPrefill.
            showApproverMeetingWizard: false,
            approverPrefill: {
                attendees:   [],
                title:       '',
                description: '',
                category:    '',
                banner:      '',
            },
            // The decision the wizard is currently being opened for, so the
            // success callback can record the meeting against it.
            schedulingMeetingForDecisionId: null,
            // Scheduled meetings for the currently-selected decision.
            scheduledMeetings: [],
            scheduledMeetingsLoading: false,
            // Lock the schedule button while we fetch approvers + open wizard.
            openingMeetingWizard: false,

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

            // ── v3.75.2: External decision links ───────────────────────
            // v4.11.12 — a publish/unpublish call in flight.
            settingPublic:        false,
            externalLinks:        [],     // [{ id, url, label, createdBy, createdAt }]
            externalLinksLoading: false,
            externalLinksDecisionId: null, // tracks which decision the links belong to
            showLinkExternalForm: false,
            linkExternalUrl:      '',
            linkExternalLabel:    '',
            linkingExternal:      false,

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
        ...mapState(['currentTeamId', 'members', 'decisionsTargetMessageId', 'decisionsPreselectStatus', 'decisionsConfig', 'resources']),

        decisionsLevelEnabled() {
            return !!(this.decisionsConfig && this.decisionsConfig.decisions_level_enabled)
        },

        /**
         * The landing grid's tiles.
         *
         * v4.11.18 — the archive goes last, whatever its name sorts as. It is
         * where decisions end up rather than one of the places a team decides
         * things, and a team whose archive sorts first would open the
         * Decisions tab on its own history. The server orders by name; this
         * is a stable partition on top of that, not a re-sort.
         */
        categories() {
            const live    = []
            const archive = []
            for (const cat of this.landingCategories) {
                (cat.is_archive ? archive : live).push(cat)
            }
            return [...live, ...archive]
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

        /**
         * v3.75.3 — Unified linked-decisions list for the detail panel.
         *
         * Combines internal decision↔decision links and external outbound
         * URL links into one array. Each row carries `kind: 'internal'` or
         * `kind: 'external'` so the template can dispatch on it (button vs
         * anchor, which icon, which pills).
         *
         * Sort: internal first (chronological), external after (chronological).
         * This keeps in-app navigation at the top — the most likely click
         * target — and pushes outbound links below.
         *
         * Both kinds keep their own id space; the `:key` uses `kind:id`.
         */
        unifiedLinks() {
            const out = []
            // v4.11.31 — first: the decision this one replaced. Justin,
            // 2026-10-06: *there should be a link under Links that links to
            // the superseded decision with appropriate pill.*
            //
            // **Not a row in `teamhub_dec_links`.** It is synthesised from
            // `supersedes_id`, which stays the one place a supersede lives,
            // so this link cannot be removed, cannot drift out of step with
            // the lifecycle, and cannot become a second answer free to
            // disagree with the first. That is also why it carries no Remove
            // button below: a supersede is a thing that happened, not an
            // attachment somebody chose.
            //
            // Above the chosen links, because it is the only one of them
            // that is part of what this decision *is*.
            if (this.supersedes) {
                out.push({
                    kind:        'supersedes',
                    id:          this.supersedes.id,
                    peer_id:     this.supersedes.id,
                    peer_title:  this.supersedes.question,
                    peer_status: this.supersedes.status,
                })
            }
            // Internal links — already loaded by loadDecisionLinks.
            for (const l of (this.linkedDecisions || [])) {
                out.push({
                    kind:        'internal',
                    id:          l.id,
                    peer_id:     l.peer_id,
                    peer_title:  l.peer_title,
                    peer_status: l.peer_status,
                    peer_level:  l.peer_level,
                    created_at:  l.created_at,
                })
            }
            // v4.11.19 — tasks, previously their own section. Between
            // decisions and external URLs: a task is in-app like a decision
            // but it is not another decision, and an outbound URL is the one
            // kind that leaves the instance, so it sorts last.
            for (const task of (this.linkedTasks || [])) {
                out.push({
                    kind:       'task',
                    id:         task.id,
                    task_path:  task.task_path,
                    label:      task.label,
                    isDone:     task.isDone,
                    created_at: task.created_at,
                })
            }
            // External links — already loaded by loadExternalLinks.
            for (const e of (this.externalLinks || [])) {
                out.push({
                    kind:       'external',
                    id:         e.id,
                    url:        e.url,
                    label:      e.label,
                    created_at: e.createdAt,
                })
            }
            return out
        },

        /**
         * v4.11.19 — one spinner for the one Links section. Three fetches
         * feed it and they finish independently, so the section says "loading"
         * until the last of them is in rather than flickering an empty state
         * between them.
         */
        linksLoading() {
            if (this.decLinksLoading || this.externalLinksLoading || this.tasksLoading) {
                return true
            }
            // v4.11.31 — and the fourth fetch, the one that resolves the
            // Supersedes row. `supersedesId` is on the list row, so the
            // panel knows *synchronously* that a row is coming and can say
            // "loading" instead of rendering "Nothing linked to this
            // decision" for as long as the request takes — which on a
            // decision whose only link is the one it replaced would be the
            // empty state answering a question it has the answer to.
            const d = this.selected
            return !!(d && d.supersedesId && this.supersedesSettledFor !== d.id)
        },

        /**
         * v4.11.31 — is the selected decision filed in the team's archive?
         *
         * The archive is team-only, so this is what takes *Public decision*
         * out of the Actions menu. Read from `is_archive` on the category
         * rows `ensureCategoriesLoaded()` already has — the flag carries the
         * whole lock on the server too (`DecisionCategoryService`), so there
         * is nothing here to keep in step with a name.
         *
         * **Falls back to `isRetired`** while the categories are still
         * loading, and for a row whose category name no category answers to
         * any more. Every retired decision is in the archive — `retire()` is
         * the only way in, and `moveToCategory()` refuses the archive as a
         * target — so the fallback errs the safe way: it hides a checkbox
         * the server would refuse anyway, rather than offering one.
         */
        selectedInArchiveCategory() {
            const d = this.selected
            if (!d) return false
            if (this.approverCategoriesLoaded && d.category) {
                const cat = this.approverCategories.find(c => c.name === d.category)
                if (cat) return !!cat.is_archive
            }
            return !!d.isRetired
        },

        /**
         * The Actions menu for this decision.
         *
         * The list and every gate behind it live in
         * `decisionPanelActions()` (`src/constants/decisions.js`) — a pure
         * function, so the gates can be read side by side and tested without
         * a browser, the way `serviceTeams.js` does it for the catalogue.
         * What this component contributes is the half only it knows: the
         * viewer's level, the team's Deck boards, and whether a form or a
         * call is already in flight.
         */
        panelActions() {
            return decisionPanelActions(this.selected, {
                canActOn:                   this.selected ? this.canActOn(this.selected) : false,
                canApprove:                 this.selected ? this.canApprove(this.selected) : false,
                canPerformDecisionActions:  this.canPerformDecisionActions,
                // v4.11.31 — the archive is team-only, so it offers no
                // *Public decision* checkbox at all.
                inArchiveCategory:          this.selectedInArchiveCategory,
                canScheduleApproverMeeting: this.selected ? this.canScheduleApproverMeeting(this.selected) : false,
                hasDeckBoard:               !!(this.resources.deck && this.resources.deck.length),
                hasOpenReview:              !!this.openReview,
                editingProposal:            this.editingProposal,
                finalizing:                 this.finalizingProposal,
                talkHref:                   this.selected?.talkToken ? this.talkUrl(this.selected.talkToken) : null,
            })
        },

        /**
         * The *Request a review* dialog's footer, as `NcDialog` wants it.
         *
         * Handing the buttons to the dialog rather than drawing them in the
         * slot is the whole reason the dialog looks like a Nextcloud dialog:
         * it owns the row, the spacing and the order, and none of that is
         * ours to get wrong a second time.
         */
        reviewRequestButtons() {
            return [
                {
                    label: t('teamhub', 'Cancel'),
                    type: 'secondary',
                    disabled: this.requestingReview,
                    callback: () => this.closeReviewRequest(),
                },
                {
                    label: this.requestingReview ? t('teamhub', 'Submitting') : t('teamhub', 'Submit'),
                    type: 'primary',
                    disabled: !this.reviewMotivation.trim() || this.requestingReview,
                    callback: () => this.submitReviewRequest(),
                },
            ]
        },

        /**
         * Does the open review carry wording the approver could approve
         * outright? (v4.11.30)
         *
         * Read from the server's `hasProposedWording` rather than derived
         * from the two strings here, for the reason `isRetired` is sent on a
         * decision: the server already answered the question, and a client
         * that re-derived it would eventually get null-versus-empty wrong
         * and offer an **Approve** button over no words at all.
         */
        openReviewHasProposal() {
            return !!this.openReview?.hasProposedWording
        },

        /**
         * The categories this decision could be moved into (v4.11.30).
         *
         * Everything the team has, minus the archive — putting a decision
         * there is *archiving* it, which has its own verb and its own
         * mandatory reason — and minus the one it is already in, which is
         * not a move.
         */
        moveCategoryOptions() {
            const current = (this.selected?.category || '').toLowerCase()
            return (this.landingCategories || [])
                .filter(c => !c.is_archive && (c.name || '').toLowerCase() !== current)
                .map(c => ({ id: c.name, label: c.name }))
        },

        /** The *Move to another category* dialog's footer. */
        moveCategoryButtons() {
            return [
                {
                    label: t('teamhub', 'Cancel'),
                    type: 'secondary',
                    disabled: this.movingCategory,
                    callback: () => this.closeMoveCategory(),
                },
                {
                    label: this.movingCategory ? t('teamhub', 'Moving') : t('teamhub', 'Move decision'),
                    type: 'primary',
                    disabled: !this.moveCategoryChoice || this.movingCategory,
                    callback: () => this.submitMoveCategory(),
                },
            ]
        },

        /**
         * Has the approver actually altered anything? (v4.11.26)
         *
         * The server refuses an accept that changes nothing — a
         * `review_accepted` line over an unchanged decision tells a later
         * reader something was altered when nothing was. Saying so here as
         * well means the approver reads it before they submit rather than
         * after.
         */
        reviewAcceptChanged() {
            const d = this.reviewAcceptTarget
            if (!d) return false
            // Trimmed on both sides, and the server compares the same way
            // (v4.11.27). A decision proposed from an attachment usually has
            // a stored body ending in a newline, so an untrimmed comparison
            // here would enable the button on a dialog nobody had touched.
            return this.reviewAcceptQuestion.trim() !== (d.question || '').trim()
                || this.reviewAcceptAnswer.trim() !== (d.selectedAnswer || '').trim()
        },

        /** The *Accept and reword* dialog's footer. */
        reviewAcceptButtons() {
            return [
                {
                    label: t('teamhub', 'Cancel'),
                    type: 'secondary',
                    disabled: this.completingReview,
                    callback: () => this.closeReviewAccept(),
                },
                {
                    label: this.completingReview ? t('teamhub', 'Saving') : t('teamhub', 'Accept and reword'),
                    type: 'primary',
                    disabled: !this.reviewAcceptChanged
                        || !this.reviewAcceptQuestion.trim()
                        || !this.reviewAcceptAnswer.trim()
                        || !this.reviewReasonValid
                        || this.completingReview,
                    callback: () => this.submitReviewAccept(),
                },
            ]
        },

        /** The Archive dialog's footer. */
        archiveButtons() {
            return [
                {
                    label: t('teamhub', 'Cancel'),
                    type: 'secondary',
                    disabled: this.archiving,
                    callback: () => this.closeArchive(),
                },
                {
                    label: this.archiving ? t('teamhub', 'Archiving') : t('teamhub', 'Archive decision'),
                    // `warning`, like the Review block's Archive: retiring a
                    // decision that has served its purpose is not a refusal.
                    type: 'warning',
                    disabled: !this.archiveReason.trim() || this.archiving,
                    callback: () => this.submitArchive(),
                },
            ]
        },

        /**
         * The counter under the motivation field, as `NcTextArea`'s own
         * helper text rather than a `<p>` of ours — so it sits where every
         * other Nextcloud field's helper sits and is announced with the
         * field instead of after it.
         */
        reviewMotivationHelper() {
            return `${this.reviewMotivation.length} / ${this.reviewMotivationMax}`
        },

        /**
         * What being public, or not being public, means for this decision.
         *
         * Four sentences rather than two, because the second half of "not
         * public" is an instruction, and an instruction is only worth giving
         * to somebody who can follow it.
         */
        publicHint() {
            if (!this.selected) return ''
            if (this.selected.isPublic) {
                return t('teamhub', 'Everybody signed in on this Nextcloud can read this decision and find it in a search. Only this team can act on it.')
            }
            return this.canPerformDecisionActions
                ? t('teamhub', 'Only this team can read this decision. Make it public from the Actions menu to let everybody signed in on this Nextcloud read it and find it in a search.')
                : t('teamhub', 'Only this team can read this decision.')
        },

        /**
         * True while any menu action is in flight. One flag disables the whole
         * menu rather than each item disabling itself: these actions all write
         * to the same decision, and a second one fired mid-flight would race
         * the first.
         */
        panelActionsBusy() {
            return this.finalizingProposal
                || this.completingReview
                || this.superseding
                || this.settingPublic
        },

        statusChips() {
            return [
                { value: null,        label: t('teamhub', 'All') },
                { value: 'open',      label: t('teamhub', 'Open') },
                { value: 'finalized', label: t('teamhub', 'Awaits approval') },
                { value: 'approved',  label: t('teamhub', 'Approved') },
                { value: 'denied',    label: t('teamhub', 'Denied') },
                { value: 'withdrawn', label: t('teamhub', 'Withdrawn') },
                // v4.11.18 — retired decisions. Two chips rather than one
                // "Retired": a decision a newer one replaced and a decision
                // somebody retired on its own are different answers to "why
                // doesn't this apply any more", and the filter is where that
                // question gets asked.
                { value: 'superseded', label: t('teamhub', 'Superseded') },
                { value: 'archived', label: t('teamhub', 'Archived') },
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

        /**
         * v3.75.4 — Count of active filter facets (status, impact, level)
         * that are not "All". Displayed as a badge on the mobile "Filters"
         * button so the user can see at a glance whether anything is
         * narrowed down without having to expand the panel.
         */
        activeFilterCount() {
            let n = 0
            if (this.filterStatus) n++
            if (this.filterImpact) n++
            if (this.filterLevel)  n++
            return n
        },

        /**
         * v3.75.5 — Final proposal text rendered as sanitized HTML.
         * selected.selectedAnswer holds the same markdown the proposer
         * wrote (compose-modal direct proposals; or, for stream-based
         * proposals, the comment selected as the final answer). Reuses
         * renderViewerMarkdown which already handles headings, bold,
         * italic, lists, links, code, blockquotes and runs the result
         * through DOMPurify with a tight allowlist.
         *
         * Returns '' (empty string) when there's no selection, so the
         * v-html binding renders nothing rather than 'undefined'.
         */
        renderedSelectedAnswer() {
            if (!this.selected || !this.selected.selectedAnswer) return ''
            return this.renderViewerMarkdown(this.selected.selectedAnswer)
        },

        /**
         * Is the open proposal on screen one this user can still act on?
         *
         * Named once because two places have to agree: the drafting block
         * renders on it, and the standalone subject heading is suppressed on
         * it — the subject moves *into* the block in that case, and rendering
         * both would print the question twice.
         */
        isMyOpenProposal() {
            return !!this.selected
                && this.isOpenProposal(this.selected)
                && this.isMyProposal(this.selected)
        },

        /**
         * The proposal's context text as sanitized HTML.
         *
         * Same pipeline as the final proposal below it, because it is the
         * same markdown — what the proposer wrote. Empty while the fetch is
         * in flight, so the block renders nothing rather than 'null'.
         */
        renderedProposalBody() {
            if (!this.proposalBody) return ''
            return this.renderViewerMarkdown(this.proposalBody)
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
            if (this.filterStatus === 'superseded') return t('teamhub', 'No superseded decisions')
            if (this.filterStatus === 'archived')   return t('teamhub', 'No archived decisions')
            return t('teamhub', 'No decisions yet')
        },

        /**
         * v4.11.23 — may this viewer add and edit the team's decision
         * categories?
         *
         * Admin or owner, the same level Manage team → Decisions requires,
         * because this is the same form and the server applies the same
         * gate (`requireAdminLevel`). Read from the member list rather than
         * from a capability flag, as `canActOn()` next door does.
         */
        canManageCategories() {
            const m = this.members?.find(mem => mem.userId === this.currentUserId)
            return !!m && m.level >= 8
        },

        /** The Review block's gate, mirroring `approvalReasonValid`. */
        reviewReasonValid() {
            const n = this.reviewReason.trim().length
            return n > 0 && n <= this.reviewReasonMax
        },

        /**
         * v4.11.18 — the review that is running on the selected decision, or
         * null. One at a time, enforced by the service, so the newest open
         * row is the only one there can be.
         */
        openReview() {
            return this.reviews.find(r => r.status === 'open') || null
        },

        /**
         * The decision that replaced a superseded one. A computed over the
         * data field rather than the field itself, so the template never
         * renders the link for a decision that is not superseded — which is
         * what a stale value from a previous selection would do.
         */
        supersededByRow() {
            if (!this.selected || this.selected.status !== 'superseded') return null
            return this.supersededBy
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
         * v4.5.42 — an open proposal, under either spelling.
         *
         * `proposed` is the legacy synonym for `open` and both are still in
         * the data. HANDOFF's 4.5.30 note applies: if you touch decision
         * status anywhere, grep for both names.
         */
        isOpenProposal() {
            return decision => decision
                && (decision.status === 'open' || decision.status === 'proposed')
        },

        isMyProposal() {
            return decision => !!decision && decision.proposedBy === this.currentUserId
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
                // v4.11.19 — both inline link forms are reset here, not just
                // the task one. They share the Links section, so a form left
                // open on the previous decision would reappear over the next
                // one with the previous decision's half-typed URL in it.
                this.showLinkTaskForm     = false
                this.showLinkExternalForm = false
                this.linkTaskPath     = ''
                this.linkTaskLabel    = ''
                this.linkExternalUrl   = ''
                this.linkExternalLabel = ''
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
            // The proposal's own context text. `GET /decisions` omits it —
            // it lives on the backing message and would cost a query per row
            // — so the panel fetches it for the one proposal on screen. Same
            // reasoning as startEditProposal(), which is where the only
            // previous fetch lived; that is why the context was invisible
            // until you pressed Edit.
            if (id !== this.proposalBodyDecisionId) {
                this.proposalBodyDecisionId = id
                this.proposalBody = null
                if (id) {
                    this.loadProposalBody(newVal)
                }
            }
            // v3.74.10: load scheduled approver meetings whenever the
            // selected decision changes. Clears when no decision is selected.
            if (id) {
                this.loadScheduledMeetings(id)
            } else {
                this.scheduledMeetings = []
            }
            // v3.75.2: load external links on the selected decision.
            if (id) {
                this.loadExternalLinks(id)
            } else {
                this.externalLinks = []
                this.externalLinksDecisionId = null
            }
            // v3.71.3 — reset the inline approval textarea whenever the
            // selected decision changes, so a reason typed for one proposal
            // can't accidentally apply to another.
            if (id !== this.approvalForDecisionId) {
                this.approvalForDecisionId = id
                this.approvalReason = ''
                // v4.11.23 — same rule for the Review block's reason.
                this.reviewReason = ''
            }
            // v4.11.18 — the review history, and the successor of a
            // superseded decision. Both are per-decision fetches for the
            // same reason as the proposal body: the list endpoint omits them
            // because only this panel reads them.
            if (id !== this.reviewsForDecisionId) {
                this.reviewsForDecisionId = id
                this.reviews      = []
                this.supersededBy = null
                this.supersedes   = null
                this.supersedesSettledFor = null
                if (id) {
                    // Only where there can be something to show. An open or
                    // withdrawn proposal has never been reviewed, and asking
                    // would be a request per selection that always answers
                    // the empty list.
                    if (newVal.status === 'approved' || newVal.isRetired) {
                        this.loadReviews(id)
                    }
                    // v4.11.31 — one fetch, both directions. The old
                    // decision needs its successor, the new one needs what
                    // it replaced, and `GET /decisions/{id}` carries both —
                    // so the condition is "is this row either end of a
                    // supersede" rather than two calls to the same URL.
                    // `supersedesId` is on the list row already, which is
                    // why this costs no extra request to find out.
                    if (newVal.status === 'superseded' || newVal.supersedesId) {
                        this.loadSupersedeLinks(id)
                    }
                }
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
        // v3.99.8 — preselect-status hand-off from ProjectHealthWidget's
        // "Open Decisions" links: skip landing, open All + pre-apply the
        // requested status chip so the click lands on the pressing list.
        // Clear the store field after honoring so a Back nav still shows
        // landing.
        if (this.decisionsPreselectStatus) {
            const requestedStatus = this.decisionsPreselectStatus
            this.openAllDecisions()
            this.filterStatus = requestedStatus
            this.SET_DECISIONS_PRESELECT_STATUS(null)
            this.reload()
            this.ensureCategoriesLoaded()
            this.fetchLandingCategories()
            return
        }
        // Default flow: start on landing.
        this.fetchLandingCategories()
        this.ensureCategoriesLoaded()
    },

    methods: {
        t, n, generateUrl,
        ...mapMutations(['SET_VIEW', 'SET_DECISIONS_TARGET', 'SET_DECISIONS_PRESELECT_STATUS']),

        /**
         * A decision's "External" link is an arbitrary URL somebody pasted, so
         * most of them genuinely are external and open in a new tab as before.
         * But people do paste links to their own Nextcloud — a file, a Deck
         * card, a wiki page — and those should stay in the team (v4.5.11).
         */
        onExternalLinkOpen(event) {
            handleInternalLinkClick(event, this.$store)
        },

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

        /**
         * Copy a direct deep-link to this decision to the clipboard.
         * The URL follows the same ?team=…&decision=… pattern used by the
         * meeting-invite link body (buildMeetingDescription) and the
         * Timeline's decision chip deep-link — so any existing handler that
         * auto-opens the right decision on page load will catch it.
         * Shown as an icon-button in the detail-panel header next to the
         * status pill (v3.78.5).
         */
        async copyDecisionLink(d) {
            try {
                // v4.11.12 — built by `decisionUrl()`, the same helper the
                // service desk's *Link a decision* box reads a pasted link
                // back with (`decisionFromUrl()`). One shape, two directions,
                // one place: a link copied here is one the desk can paste.
                const url = window.location.origin + decisionUrl(this.currentTeamId, d.id)
                await navigator.clipboard.writeText(url)
                showSuccess(t('teamhub', 'Decision link copied to clipboard'))
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] copyDecisionLink error:', err)
                showError(t('teamhub', 'Failed to copy link: {error}', {
                    error: err?.message || String(err),
                }))
            }
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

        // ── Categories, from the landing grid — v4.11.23 ────────────

        /** @param cat the category to edit, or null to create one. */
        openCategoryDialog(cat) {
            this.categoryDialogTarget = cat
            this.categoryDialogOpen   = true
        },

        closeCategoryDialog() {
            this.categoryDialogOpen   = false
            this.categoryDialogTarget = null
        },

        /**
         * Re-read rather than splice the server's answer into the grid: a
         * category's approver list comes back resolved (an empty list
         * becomes the team owner), and the landing tiles are sorted with the
         * archive last. Both are the server's and the computed's business,
         * not this handler's.
         */
        async onCategorySaved() {
            await this.fetchLandingCategories()
        },

        // ── Category approvers on the landing grid — v4.11.19 ───────

        /**
         * The approvers to show on a category card, capped.
         *
         * Three is where a row of avatars stops reading as "these people"
         * and starts reading as a crowd; the rest become a `+N`. Capping in
         * one place means the count and the list cannot disagree.
         *
         * The uids come from `GET /decisions/manage/categories`, which the
         * landing grid already loads — so this costs no request.
         */
        categoryApprovers(cat) {
            return (cat.approvers || []).slice(0, this.APPROVER_AVATAR_CAP)
        },

        /** How many approvers the `+N` stands for. */
        approverOverflow(cat) {
            return Math.max(0, (cat.approvers || []).length - this.APPROVER_AVATAR_CAP)
        },

        /**
         * Every approver by name, for the group's accessible label and the
         * `+N`'s tooltip.
         *
         * **All of them, not the three on screen.** A screen reader
         * announcing "Approvers: Inge, Jaap, Lieke" when there are five is
         * worse than no label — the cap is a layout decision and should not
         * reach the reading of it.
         */
        approverListLabel(cat) {
            const names = (cat.approvers || []).map(uid => this.memberDisplayName(uid))
            if (!names.length) {
                return ''
            }
            // TRANSLATORS: accessible label on the row of approver avatars on a decision-category card
            return t('teamhub', 'Approvers: {names}', { names: names.join(', ') })
        },

        // ── The Actions menu — v4.11.19 ─────────────────────────────

        /**
         * Run one menu action.
         *
         * A switch rather than putting the handler on the action object: the
         * objects come from a computed, so a function on each one would be
         * rebuilt on every re-render, and this keeps `panelActions` a plain
         * description of *what is offered* with nothing executable in it.
         *
         * `copy-link`, `public` and `discussion` never arrive here — the first
         * two are their own item types in the template and the third is a
         * link. `copy-link` does, because it is an ordinary button.
         */
        runPanelAction(key) {
            const d = this.selected
            if (!d) return

            switch (key) {
            case 'edit':
                this.startEditProposal(d)
                break
            case 'finalize':
                this.onFinalizeProposal(d)
                break
            case 'schedule-meeting':
                this.openApproverMeetingWizard(d)
                break
            case 'review-request':
                this.openReviewRequest(d)
                break
            // `review-keep` and `review-archive` were handled here until
            // v4.11.26. v4.11.23 moved both answers out of the menu and onto
            // the page (§2.159 — the menu holds what is true of the decision
            // whatever is happening to it), so `decisionPanelActions` has not
            // produced either key since; the arms were dead and the JS suite
            // asserts the menu never offers them.
            case 'supersede':
                this.openSupersede(d)
                break
            case 'archive':
                this.openArchive(d)
                break
            case 'move-category':
                this.openMoveCategory(d)
                break
            case 'link-decision':
                this.openDecisionPicker()
                break
            case 'link-task':
                // One form at a time in the Links section: opening this one
                // closes the other, so the two cannot both claim the slot.
                this.showLinkExternalForm = false
                this.showLinkTaskForm     = true
                this.focusLinkForm('linkTaskInput')
                break
            case 'link-external':
                this.showLinkTaskForm     = false
                this.showLinkExternalForm = true
                this.focusLinkForm('linkExternalInput')
                break
            case 'create-task':
                this.showCreateTaskModal = true
                break
            case 'copy-link':
                this.copyDecisionLink(d)
                break
            default:
                // An action in the list with no handler is a bug, not a no-op.
                console.error('[TeamHub][TeamDecisionsView] unhandled panel action:', key)
            }
        },

        /**
         * Put the cursor in the form the menu just opened.
         *
         * The menu closes on click, so without this the focus ring lands back
         * on the menu trigger and a keyboard user has to tab down to a field
         * that appeared because of something they did. `$nextTick` because the
         * field does not exist yet at the moment the flag flips.
         */
        focusLinkForm(ref) {
            this.$nextTick(() => {
                // NcTextField is a component, so the focusable element is the
                // `input` inside its root — not the ref itself.
                const input = this.$refs[ref]?.$el?.querySelector?.('input')
                input?.focus()
            })
        },

        /**
         * Remove one row from the Links section, whichever kind it is.
         *
         * The three kinds live in three tables with three endpoints, and the
         * row carries its kind — so the dispatch is here rather than in the
         * template, where it had grown into a ternary that would not have
         * survived a third kind.
         */
        removeLink(row) {
            switch (row.kind) {
            case 'internal':
                this.removeDecisionLink(row.id)
                break
            case 'task':
                this.deleteTaskLink(row.id)
                break
            case 'external':
                this.deleteExternalLink(row.id)
                break
            }
        },

        // ── Review and retirement — v4.11.18 ────────────────────────

        /**
         * The reviews of a decision, newest first. Guarded by decision id:
         * the selection can move while the request is in flight, and showing
         * one decision's review under another's subject is worse than
         * showing none (DESIGN §2.79).
         */
        async loadReviews(decisionId) {
            if (!decisionId) return
            this.reviewsLoading = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${decisionId}/reviews`),
                )
                if (this.reviewsForDecisionId !== decisionId) return
                this.reviews = Array.isArray(data?.items) ? data.items : []
            } catch (err) {
                // Silent, like the proposal-body fetch: what is lost is a
                // banner beside a decision that renders correctly without
                // it, so a toast would report something nobody can act on.
                console.error('[TeamHub][TeamDecisionsView] loadReviews error:', err)
                if (this.reviewsForDecisionId === decisionId) this.reviews = []
            } finally {
                if (this.reviewsForDecisionId === decisionId) this.reviewsLoading = false
            }
        },

        /**
         * Both ends of a supersede, in one fetch. Same guard.
         *
         * v4.11.31 — was `loadSupersededBy`, and resolved only the successor
         * of a retired decision. `GET /decisions/{id}` now carries
         * `supersedes` as well, so the decision that *did* the superseding
         * can name what it replaced; one response, two fields, and either
         * can be null.
         */
        async loadSupersedeLinks(decisionId) {
            if (!decisionId) return
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${decisionId}`),
                )
                if (this.reviewsForDecisionId !== decisionId) return
                this.supersededBy = data?.supersededBy || null
                this.supersedes   = data?.supersedes || null
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] loadSupersedeLinks error:', err)
            } finally {
                // Settled either way, so the Links spinner stops. A failed
                // fetch costs the Supersedes row, not the whole section.
                if (this.reviewsForDecisionId === decisionId) {
                    this.supersedesSettledFor = decisionId
                }
            }
        },

        openReviewRequest(decision) {
            this.reviewRequestTarget = decision
            this.reviewMotivation    = ''
            // v4.11.30 — prefilled with what the decision says today, so the
            // requester edits a decision rather than facing two blank boxes
            // and guessing what shape an answer takes. The same choice the
            // approver's dialog made in v4.11.26, for the same reason. An
            // empty proposal is then one they deliberately cleared, which
            // the server reads as "nothing proposed".
            this.reviewProposedQuestion   = decision?.question || ''
            this.reviewProposedAnswer     = decision?.selectedAnswer || ''
            this.reviewProposedAttachment = null
        },

        closeReviewRequest() {
            this.reviewRequestTarget      = null
            this.reviewMotivation         = ''
            this.reviewProposedQuestion   = ''
            this.reviewProposedAnswer     = ''
            this.reviewProposedAttachment = null
        },

        async submitReviewRequest() {
            const decision   = this.reviewRequestTarget
            const motivation = this.reviewMotivation.trim()
            if (!decision || !motivation || this.requestingReview) return

            // v4.11.30 — only sent when they differ from what the decision
            // already says. A prefilled box the requester never touched is
            // not a proposal, and sending it would hand the approver an
            // *Approve as proposed* button that changes nothing — which the
            // server then refuses, after they had clicked it.
            const proposedQuestion = this.reviewProposedQuestion.trim()
            const proposedAnswer   = this.reviewProposedAnswer.trim()
            const payload = { motivation }
            if (proposedQuestion && proposedQuestion !== (decision.question || '').trim()) {
                payload.proposed_question = proposedQuestion
            }
            if (proposedAnswer && proposedAnswer !== (decision.selectedAnswer || '').trim()) {
                payload.proposed_answer = proposedAnswer
            }
            if (this.reviewProposedAttachment?.fileId) {
                payload.attachment_file_id = this.reviewProposedAttachment.fileId
                payload.attachment_name    = this.reviewProposedAttachment.name
            }

            this.requestingReview = true
            try {
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${decision.id}/reviews`),
                    payload,
                )
                showSuccess(t('teamhub', 'Review requested. The approvers have it in My Work.'))
                this.closeReviewRequest()
                // Re-read rather than push a row we built ourselves: the
                // server decides what the review looks like, including the
                // requester's display name.
                //
                // v4.11.21 — **and the audit trail.** The `review_requested`
                // line was written server-side all along; this panel simply
                // never re-read it, so requesting a review looked as though
                // it had not been recorded. Every other write on this panel
                // goes through `applyDecisionUpdate()`, which reloads the
                // audit for you — this one returns a review rather than a
                // decision and so had nothing to route through it.
                await Promise.all([
                    this.loadReviews(decision.id),
                    this.loadAudit(decision.id),
                    // v4.11.30 — an attachment on the request is copied into
                    // the decision's proposal folder, so the Source list has
                    // a file in it that was not there a second ago.
                    payload.attachment_file_id ? this.loadSources(decision.id) : null,
                ])
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] submitReviewRequest error:', err)
                showError(err?.response?.data?.error || t('teamhub', 'Could not request a review'))
                // A 409 means somebody asked first. Re-read so the banner
                // replaces the button instead of offering it again.
                if (err?.response?.status === 409) {
                    this.closeReviewRequest()
                    await this.loadReviews(decision.id)
                }
            } finally {
                this.requestingReview = false
            }
        },

        /**
         * Answer the open review from the decision panel. The same call the
         * My Work row makes — an approver looking at the decision should not
         * have to go to a queue to act on what is in front of them.
         *
         * `archived` retires the decision, so it confirms first: the
         * consequence is a read-only record in another category, and nothing
         * on the button says that.
         *
         * v4.11.26 — `wording` carries the new question and decision text
         * for `accepted`, which the *Accept and reword* dialog collects.
         * Every other outcome passes nothing and the server writes nothing
         * from it.
         *
         * @param {string} outcome  'denied' | 'accepted' | 'kept' | 'archived'
         * @param {?object} wording { question, answer } for `accepted`
         * @return {Promise<boolean>} whether the server accepted it — the
         *         dialog needs to know, because it stays open on a refusal
         *         so the approver can fix the wording rather than retype it.
         */
        async onCompleteReview(outcome, wording = null) {
            const decision = this.selected
            const reason   = this.reviewReason.trim()
            if (!decision || this.completingReview || !reason) return false

            if (outcome === 'archived'
                && !window.confirm(t('teamhub', 'Archive this decision? It moves to the team archive and becomes read-only. The record and its audit trail are kept.'))
            ) {
                return false
            }

            this.completingReview = true
            try {
                const { data } = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${decision.id}/reviews/complete`),
                    wording
                        ? { outcome, reason, question: wording.question, answer: wording.answer }
                        : { outcome, reason },
                )
                showSuccess(this.reviewOutcomeMessage(outcome))
                this.reviewReason = ''
                this.applyDecisionUpdate(data)
                await this.loadReviews(decision.id)
                return true
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] onCompleteReview error:', err)
                showError(err?.response?.data?.error || t('teamhub', 'Could not complete the review'))
                return false
            } finally {
                this.completingReview = false
            }
        },

        /**
         * What to tell the approver afterwards. Four outcomes, and three of
         * them leave the decision in force — but not in the same way, and
         * "the decision stands" over a decision whose wording just changed
         * would be the panel contradicting itself.
         */
        reviewOutcomeMessage(outcome) {
            if (outcome === 'archived') return t('teamhub', 'Decision archived')
            if (outcome === 'accepted') return t('teamhub', 'Decision reworded. It stays in force with its original approval.')
            if (outcome === 'denied')   return t('teamhub', 'Review denied. The decision stands unchanged.')
            return t('teamhub', 'Review completed. The decision stands.')
        },

        // ── Accept a review request, with the rewording — v4.11.26 ───

        /**
         * Open the rewording dialog, prefilled with what the decision says
         * now.
         *
         * `selectedAnswer` is on the decision the panel already has — it is
         * the wording the Final proposal block is rendering a few
         * centimetres away — so there is nothing to fetch and no window in
         * which the dialog could open empty and be saved over the top of.
         */
        openReviewAccept(decision) {
            if (!decision) return
            this.reviewAcceptTarget = decision
            // v4.11.30 — **the requester's proposal is the starting point
            // when there is one.** An approver who opens this dialog on a
            // request that came with wording is editing that wording, not
            // starting from the decision as it stands and retyping what they
            // have already been handed. Field by field, because a request
            // may propose new wording and leave the question alone.
            const review = this.openReview
            this.reviewAcceptQuestion = review?.proposedQuestion || decision.question || ''
            this.reviewAcceptAnswer   = review?.proposedAnswer || decision.selectedAnswer || ''
        },

        /**
         * Open the file a review requester put forward (v4.11.30).
         *
         * The same read-only viewer the Source list uses — it is the same
         * kind of thing, a document belonging to this decision, and the
         * review's copy lives in the same `.proposals/{id}/` folder. The
         * mime type is not on the review row, so the viewer works it out
         * from the name, which is what it already falls back to for a source
         * file with no mime.
         */
        openReviewAttachment(review) {
            if (!review?.attachmentFileId) return
            this.openSourceFile({
                file_id: review.attachmentFileId,
                name:    review.attachmentName || String(review.attachmentFileId),
                mime:    '',
                size:    0,
                is_proposal: false,
            })
        },

        closeReviewAccept() {
            this.reviewAcceptTarget   = null
            this.reviewAcceptQuestion = ''
            this.reviewAcceptAnswer   = ''
        },

        async submitReviewAccept() {
            const question = this.reviewAcceptQuestion.trim()
            const answer   = this.reviewAcceptAnswer.trim()
            if (!this.reviewAcceptTarget || !question || !answer) return

            const ok = await this.onCompleteReview('accepted', { question, answer })
            // Left open on a refusal: the approver's new wording is in these
            // two boxes and nowhere else, and closing the dialog would make
            // them type it again to find out what was wrong with it.
            if (ok) this.closeReviewAccept()
        },

        // ── Move to another category, from the Actions menu — v4.11.30 ──

        /**
         * Open the picker.
         *
         * The category list is fetched when it is not already here: the
         * landing grid loads it, but an approver who followed a deep link
         * straight to a decision never passed through the landing grid, and
         * a dialog whose only control is an empty dropdown is worse than a
         * moment's wait.
         */
        async openMoveCategory(decision) {
            this.moveCategoryTarget = decision
            this.moveCategoryChoice = null
            if (!this.landingCategories.length) {
                await this.fetchLandingCategories()
            }
        },

        closeMoveCategory() {
            this.moveCategoryTarget = null
            this.moveCategoryChoice = null
        },

        async submitMoveCategory() {
            const decision = this.moveCategoryTarget
            // `:reduce` on the select, as the composer's category picker does,
            // so the model is the category's name and not an option object.
            const category = this.moveCategoryChoice
            if (!decision || !category || this.movingCategory) return

            this.movingCategory = true
            try {
                const { data } = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${decision.id}/category`),
                    { category },
                )
                showSuccess(t('teamhub', 'Decision moved to {category}', { category }))
                this.closeMoveCategory()
                // `applyDecisionUpdate` reloads the audit as a side effect,
                // which is where the *Moved to another category* line is —
                // the mistake v4.11.21 made by not routing a write through
                // it, and v4.11.25 made by misspelling it.
                this.applyDecisionUpdate(data)
                // The decision has left the category the list is showing, so
                // its row has to go with it. The detail panel stays open —
                // the approver just moved this decision and the result of
                // that is what they want to look at.
                this.decisions = []
                await this.loadPage(null)
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] submitMoveCategory error:', err)
                showError(err?.response?.data?.error || t('teamhub', 'Could not move this decision'))
            } finally {
                this.movingCategory = false
            }
        },

        // ── Archive, from the Actions menu — v4.11.24 ───────────────

        openArchive(decision) {
            this.archiveTarget = decision
            this.archiveReason = ''
        },

        closeArchive() {
            this.archiveTarget = null
            this.archiveReason = ''
        },

        async submitArchive() {
            const decision = this.archiveTarget
            const reason   = this.archiveReason.trim()
            if (!decision || !reason || this.archiving) return

            this.archiving = true
            try {
                const { data } = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${decision.id}/archive`),
                    { reason },
                )
                showSuccess(t('teamhub', 'Decision archived'))
                this.closeArchive()
                this.applyDecisionUpdate(data)
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] submitArchive error:', err)
                showError(err?.response?.data?.error || t('teamhub', 'Could not archive this decision'))
            } finally {
                this.archiving = false
            }
        },

        // ── Supersede ───────────────────────────────────────────────

        /**
         * Open the picker, with the four most recent approved decisions
         * already in it.
         *
         * Fetched rather than taken from the list on screen, because "the
         * team's approved decisions" is not what the list is showing — that
         * is filtered by whatever the viewer chose.
         */
        openSupersede(decision) {
            this.supersedeTarget  = decision
            this.supersedeQuery   = ''
            this.supersedeOptions = []
            this.supersedeError   = ''
            this.searchSupersedeCandidates()
            this.$nextTick(() => {
                this.$refs.supersedeSearch?.$el?.querySelector?.('input')?.focus()
            })
        },

        /**
         * Debounced, like every other search-as-you-type in this app: one
         * request per pause, not one per keystroke.
         */
        onSupersedeSearch() {
            if (this.supersedeSearchId) clearTimeout(this.supersedeSearchId)
            this.supersedeSearchId = setTimeout(() => {
                this.supersedeSearchId = null
                this.searchSupersedeCandidates()
            }, 300)
        },

        /**
         * Ask the server for at most four matches.
         *
         * Guarded by the dialog's own target: the dialog can be closed while
         * a request is in flight, and writing results into a picker that is
         * gone — or worse, into the next one — is the bug §2.79 recorded for
         * the proposal body.
         */
        async searchSupersedeCandidates() {
            const decision = this.supersedeTarget
            if (!decision) return

            const term = this.supersedeQuery.trim()
            this.supersedeLoading = true
            this.supersedeError   = ''
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${decision.id}/supersede-options`),
                    { params: term ? { q: term } : {} },
                )
                if (this.supersedeTarget?.id !== decision.id || this.supersedeQuery.trim() !== term) return
                this.supersedeOptions = Array.isArray(data?.items) ? data.items : []
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] searchSupersedeCandidates error:', err)
                if (this.supersedeTarget?.id === decision.id) {
                    this.supersedeOptions = []
                    this.supersedeError = err?.response?.data?.error
                        || t('teamhub', 'Could not search the approved decisions')
                }
            } finally {
                if (this.supersedeTarget?.id === decision.id) this.supersedeLoading = false
            }
        },

        closeSupersede() {
            if (this.supersedeSearchId) {
                clearTimeout(this.supersedeSearchId)
                this.supersedeSearchId = null
            }
            this.supersedeTarget  = null
            this.supersedeQuery   = ''
            this.supersedeOptions = []
            this.supersedeError   = ''
        },

        async confirmSupersede(supersededById) {
            const decision = this.supersedeTarget
            if (!decision || !supersededById || this.superseding) return

            this.superseding = true
            try {
                const { data } = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${decision.id}/supersede`),
                    { supersededById },
                )
                showSuccess(t('teamhub', 'Decision superseded and moved to the archive'))
                this.closeSupersede()
                this.applyDecisionUpdate(data)
                this.supersededBy = data?.supersededBy || null
                this.supersedes   = data?.supersedes || null
                // The response is this decision's own, so the Supersedes
                // fetch is settled without making it.
                this.supersedesSettledFor = decision.id
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] confirmSupersede error:', err)
                showError(err?.response?.data?.error || t('teamhub', 'Could not supersede this decision'))
            } finally {
                this.superseding = false
            }
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

        // ── v4.5.42 — the proposer's drafting block ──────────────────────

        /** Deep link to a Talk conversation by token. */
        talkUrl(token) {
            return generateUrl(`/call/${token}`)
        },

        /**
         * Open the editor, fetching the current proposal text first.
         *
         * `selectDecision` puts the **list row** in `selected`, and the list
         * comes from `GET /decisions`, whose serialiser deliberately omits the
         * proposal body — it lives on the backing message and would cost a
         * query per row. Only `GET /decisions/{id}` returns `proposalBody`.
         *
         * Without this fetch the textarea would open empty and Save would
         * blank the proposal. Fetching is also the right thing on its own: it
         * picks up an edit made in another tab rather than overwriting it.
         */
        async startEditProposal(decision) {
            this.editQuestion = decision.question || ''
            this.editBody = ''
            this.editingProposal = true
            this.savingProposal = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${decision.id}`)
                )
                this.editQuestion = data.question || ''
                this.editBody = data.proposalBody ?? ''
                this.applyDecisionUpdate(data)
            } catch (err) {
                const msg = err?.response?.data?.error || err.message
                showError(t('teamhub', 'Could not load the proposal text: {error}', { error: msg }))
                // Closed rather than left open on an empty box the user could
                // save over the top of.
                this.cancelEditProposal()
            } finally {
                this.savingProposal = false
            }
        },

        /**
         * Fetch the context text of the proposal now on screen.
         *
         * Only for an open proposal of the viewer's own: every other status
         * shows its wording in the Final proposal block, and fetching for
         * those would be a request per selection for something already on
         * the page.
         *
         * A failure is silent. The context is supporting text next to a
         * subject and three buttons that all still work without it, so a
         * toast here would report a problem the user cannot act on and
         * cannot dismiss by doing anything differently. The reason goes to
         * the console instead. **Edit proposal deliberately keeps its
         * toast** — there, a failed fetch means the editor would open empty
         * and Save would blank the proposal.
         */
        async loadProposalBody(decision) {
            if (!decision || !this.isOpenProposal(decision) || !this.isMyProposal(decision)) return

            const requestedId = decision.id
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${requestedId}`)
                )
                // The selection can move while this is in flight; a late
                // response must not write another proposal's text under the
                // subject now on screen.
                if (this.selected?.id !== requestedId) return
                this.proposalBody = data.proposalBody ?? ''
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] loadProposalBody error:', err)
                if (this.selected?.id === requestedId) {
                    this.proposalBody = ''
                }
            }
        },

        cancelEditProposal() {
            this.editingProposal = false
            this.editQuestion = ''
            this.editBody = ''
        },

        async saveProposal(decision) {
            const question = this.editQuestion.trim()
            if (!question || !decision) return

            this.savingProposal = true
            try {
                const { data } = await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${decision.id}/proposal`),
                    { question, body: this.editBody }
                )
                this.applyDecisionUpdate(data)
                // Set from what we just saved rather than refetched: the id
                // has not changed, so the selection watcher will not fire and
                // the panel would otherwise keep showing the pre-edit text.
                this.proposalBody = this.editBody
                this.cancelEditProposal()
                showSuccess(t('teamhub', 'Proposal updated.'))
            } catch (err) {
                const msg = err?.response?.data?.error || err.message
                showError(t('teamhub', 'Could not save the proposal: {error}', { error: msg }))
            } finally {
                this.savingProposal = false
            }
        },

        async onFinalizeProposal(decision) {
            if (!decision) return
            this.finalizingProposal = true
            try {
                const { data } = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${decision.id}/finalize-proposal`)
                )
                this.applyDecisionUpdate(data)
                this.cancelEditProposal()
                showSuccess(t('teamhub', 'Proposal finalized. It is now with the approvers.'))
            } catch (err) {
                const msg = err?.response?.data?.error || err.message
                showError(t('teamhub', 'Could not finalize the proposal: {error}', { error: msg }))
            } finally {
                this.finalizingProposal = false
            }
        },

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

        // ── v3.75.2: External decision links ────────────────────────

        async loadExternalLinks(decisionId) {
            this.externalLinksLoading = true
            this.externalLinksDecisionId = decisionId
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${decisionId}/external-links`),
                )
                if (this.externalLinksDecisionId !== decisionId) return
                this.externalLinks = Array.isArray(data?.items) ? data.items : []
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] loadExternalLinks error:', err)
                if (this.externalLinksDecisionId === decisionId) {
                    this.externalLinks = []
                }
            } finally {
                if (this.externalLinksDecisionId === decisionId) {
                    this.externalLinksLoading = false
                }
            }
        },

        async submitLinkExternal() {
            if (!this.linkExternalUrl.trim() || !this.selected) return
            this.linkingExternal = true
            try {
                const { data } = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${this.selected.id}/external-links`),
                    {
                        url:   this.linkExternalUrl.trim(),
                        label: this.linkExternalLabel.trim(),
                    },
                )
                this.externalLinks.push(data)
                this.linkExternalUrl = ''
                this.linkExternalLabel = ''
                this.showLinkExternalForm = false
                showSuccess(t('teamhub', 'External link added'))
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] submitLinkExternal error:', err)
                showError(t('teamhub', 'Failed to add external link: {error}', {
                    error: err?.response?.data?.error || err.message,
                }))
            } finally {
                this.linkingExternal = false
            }
        },

        /**
         * v4.11.12 — publish this decision to everybody signed in on this
         * Nextcloud, or take it back into the team.
         *
         * The server is the authority on whether this account may: the
         * checkbox is only rendered for somebody who holds the team's action
         * level, and a 403 puts the switch back where it was rather than
         * leaving the panel claiming something that did not happen.
         */
        async setPublic(value) {
            if (!this.selected || this.settingPublic) return
            const before = !!this.selected.isPublic
            this.settingPublic = true
            this.selected.isPublic = value
            try {
                const { data } = await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${this.selected.id}/public`),
                    { public: value },
                )
                this.selected.isPublic = !!data?.isPublic
                showSuccess(value
                    ? t('teamhub', 'Decision published')
                    : t('teamhub', 'Decision is team only again'))
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] setPublic error:', err)
                this.selected.isPublic = before
                showError(t('teamhub', 'Failed to change who can read this decision: {error}', {
                    error: err?.response?.data?.error || err.message,
                }))
            } finally {
                this.settingPublic = false
            }
        },

        async deleteExternalLink(linkId) {
            if (!this.selected) return
            try {
                await axios.delete(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${this.selected.id}/external-links/${linkId}`),
                )
                this.externalLinks = this.externalLinks.filter(l => l.id !== linkId)
                showSuccess(t('teamhub', 'External link removed'))
            } catch (err) {
                console.error('[TeamHub][TeamDecisionsView] deleteExternalLink error:', err)
                showError(t('teamhub', 'Failed to remove external link: {error}', {
                    error: err?.response?.data?.error || err.message,
                }))
            }
        },

        /**
         * Display helper — show the URL's host + path, with the scheme
         * stripped, so long URLs don't blow out the row width. The full
         * URL is in the href so the click still works.
         */
        externalLinkDisplay(link) {
            if (link.label && link.label.trim() !== '') return link.label
            try {
                const u = new URL(link.url)
                const path = (u.pathname && u.pathname !== '/') ? u.pathname : ''
                return u.host + path
            } catch (e) {
                return link.url
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
            // v4.7.0 — this value ends up in `:href` and in the viewer's
            // `<iframe :src>`, so the protocol is checked at the sink and not
            // only on the way in. The server refuses a scriptable scheme on
            // write (DecisionService::assertSourceRefSchemeSafe), but that
            // cannot reach a row stored before the check existed, and this is
            // the point where a `javascript:` ref would actually execute.
            // Parsed rather than string-matched: `java\tscript:` and a
            // leading newline both survive a naive startsWith test.
            let parsed = null
            try {
                parsed = new URL(url)
            } catch {
                parsed = null
            }
            if (!parsed || (parsed.protocol !== 'https:' && parsed.protocol !== 'http:')) {
                showError(t('teamhub', 'That source link cannot be opened — only http and https addresses are allowed.'))
                return
            }
            // Best-effort label: hostname + path.
            const label = parsed.hostname + (parsed.pathname && parsed.pathname !== '/' ? parsed.pathname : '')
            this.viewerFile = {
                file_id:      null,
                name:         label,
                mime:         'text/html',
                size:         0,
                is_proposal:  false,
                // The normalised value, not the raw input: `new URL()` is what
                // was validated, so it is what should reach `:href`/`:src`.
                // A tab or newline inside the raw string survives into the
                // attribute otherwise — harmless in practice, since browsers
                // strip them too, but it means the checked value and the used
                // value are not the same string.
                external_url: parsed.href,
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
                // TRANSLATORS: audit event verb — the proposer changed the proposal text while it was open
                proposal_updated:  t('teamhub', 'Edited proposal'),
                // TRANSLATORS: audit event verb — the proposal was opened for discussion in Talk
                shared_for_discussion: t('teamhub', 'Opened for discussion'),
                // v4.11.18 — the review and retirement cycle. Justin: every
                // step of a review is in the audit stream, so every one of
                // them has a label here.
                // TRANSLATORS: audit event verb — a team member asked the approvers to review the decision
                review_requested: t('teamhub', 'Review requested'),
                // TRANSLATORS: audit event verb — the category's review period came round
                review_due:       t('teamhub', 'Review date reached'),
                // TRANSLATORS: audit event verb — a reviewer looked and let the decision stand
                review_kept:      t('teamhub', 'Kept after review'),
                // v4.11.24 — the two answers to a *requested* review.
                // TRANSLATORS: audit event verb — the approvers refused a review request; the decision stands
                review_denied:    t('teamhub', 'Review denied'),
                // TRANSLATORS: audit event verb — the approvers agreed the decision needs altering
                review_accepted:  t('teamhub', 'Review accepted'),
                // TRANSLATORS: audit event verb — the decision's wording was rewritten in place; it stayed approved throughout
                revised:          t('teamhub', 'Wording revised'),
                // v4.11.24 only, and nothing writes it any more (v4.11.26).
                // The label stays so rows already in the trail still read as
                // sentences — the same reason the panel still reads the old
                // `note` key from before the reason was required.
                // TRANSLATORS: audit event verb — the decision went back to open so its wording could be brought up to date
                reopened:         t('teamhub', 'Reopened for redrafting'),
                // TRANSLATORS: audit event verb — a reviewer retired the decision
                review_archived:  t('teamhub', 'Archived after review'),
                // TRANSLATORS: audit event verb — a newer decision took over
                superseded:       t('teamhub', 'Superseded'),
                // v4.11.31 — the same act read from the decision that did
                // it. Justin: a supersede was visible on the superseded
                // decision and nowhere on the one that superseded it.
                // TRANSLATORS: audit event verb — this decision took over from an older one
                supersedes:       t('teamhub', 'Superseded an earlier decision'),
                // v4.11.31 — publishing, which `setPublic` has written since
                // v4.11.12 into a vocabulary that did not list it, so every
                // one of these rows was dropped. Labelled now that they land.
                // TRANSLATORS: audit event verb — the decision was made readable by everyone on this Nextcloud
                published:        t('teamhub', 'Made public'),
                // TRANSLATORS: audit event verb — the decision was taken back out of public and is team-only again
                unpublished:      t('teamhub', 'Made team-only'),
                // TRANSLATORS: audit event verb — the record moved into the team archive
                archived:         t('teamhub', 'Moved to archive'),
                // v4.11.30 — refiled under a different category by an
                // approver of the one it was in.
                // TRANSLATORS: audit event verb — the decision was refiled under a different decision category
                category_moved:   t('teamhub', 'Moved to another category'),
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
            // v4.11.18 — a review request carries the member's reasons, and a
            // completion carries the reviewer's optional note. Both are the
            // words somebody wrote, which is the whole value of the line.
            if (ev.transition === 'review_requested' && (p.motivation || p.attachment_name)) {
                // v4.11.30 — the file joins the motivation on the same line.
                // A request that put a document forward said something the
                // prose alone does not, and the motivation is required, so
                // there is no row where the file would get a line of its own.
                const parts = []
                if (p.motivation) {
                    parts.push(p.motivation)
                }
                if (p.attachment_name) {
                    // TRANSLATORS: audit line — names the file the review requester attached
                    parts.push(t('teamhub', 'Attached: {name}', { name: p.attachment_name }))
                }
                return parts.join(' · ')
            }
            if (['review_kept', 'review_archived', 'review_denied', 'review_accepted'].includes(ev.transition)
                && (p.reason || p.note)) {
                // v4.11.23 — the payload key became `reason` when it became
                // required. `note` is still read so the rows written while
                // it was optional keep rendering their text.
                return t('teamhub', 'Reason: {reason}', { reason: p.reason || p.note })
            }
            if (ev.transition === 'revised') {
                // v4.11.26 — the line that says what accepting did. The
                // previous wording is only here: the decision row now holds
                // the new text, so a trail that said "revised" and no more
                // could not answer the question it invites.
                const parts = []
                if (p.previous_question) {
                    // TRANSLATORS: audit line — what the decision's question said before it was reworded
                    parts.push(t('teamhub', 'Previously: {question}', { question: p.previous_question }))
                }
                if (p.previous_answer) {
                    parts.push(p.previous_answer)
                }
                // v4.11.30 — whose words these are, and the file they came
                // from. *Reworded as proposed* is a different act from an
                // approver writing their own text over a request, and the
                // trail is the only place that distinction survives.
                if (p.as_proposed) {
                    // TRANSLATORS: audit line — the approver accepted the requester's wording without changing it
                    parts.push(t('teamhub', 'As proposed in the review'))
                }
                if (p.attachment_name) {
                    // TRANSLATORS: audit line — names the file the new wording came from
                    parts.push(t('teamhub', 'From file: {name}', { name: p.attachment_name }))
                }
                return parts.join(' · ')
            }
            // v4.11.30 — both category names, because `category` on the row
            // now holds only the second of the two and "moved" without them
            // cannot answer the question it invites.
            if (ev.transition === 'category_moved') {
                if (p.from && p.to) {
                    // TRANSLATORS: audit line — the decision was refiled from one category into another
                    return t('teamhub', 'From {from} to {to}', { from: p.from, to: p.to })
                }
                if (p.to) {
                    // TRANSLATORS: audit line — the decision had no category before it was refiled
                    return t('teamhub', 'Moved into {to}', { to: p.to })
                }
                return ''
            }
            if (ev.transition === 'superseded' && p.superseded_by_title) {
                // TRANSLATORS: audit line — names the decision that took over
                return t('teamhub', 'Superseded by: {question}', { question: p.superseded_by_title })
            }
            // v4.11.31 — and the other end: what this decision replaced.
            if (ev.transition === 'supersedes' && p.supersedes_title) {
                // TRANSLATORS: audit line — names the older decision this one replaced
                return t('teamhub', 'Supersedes: {question}', { question: p.supersedes_title })
            }
            // v4.11.31 — a decision reaching the archive is taken back out
            // of public, and the line says it was the archiving that did it
            // rather than somebody unticking the box.
            if (ev.transition === 'unpublished' && p.reason === 'archived') {
                // TRANSLATORS: audit line — the decision stopped being public because it was moved into the team archive, which is team-only
                return t('teamhub', 'The archive is team-only')
            }
            if (ev.transition === 'archived') {
                // v4.11.24 — an archive straight from the Actions menu
                // carries a reason and no review beside it to explain
                // itself, so both facts go on the line: why, and where
                // from. A review's archive has its reason on its own
                // `review_archived` line above.
                const parts = []
                if (p.reason) {
                    parts.push(t('teamhub', 'Reason: {reason}', { reason: p.reason }))
                }
                if (p.from_category) {
                    // TRANSLATORS: audit line — the category the decision was decided under before it was archived
                    parts.push(t('teamhub', 'Archived from {category}', { category: p.from_category }))
                }
                return parts.join(' · ')
            }
            return ''
        },

        // ── Labels ──────────────────────────────────────────────────

        impactLabel(impact) {
            return { low: t('teamhub', 'Low'), medium: t('teamhub', 'Medium'), high: t('teamhub', 'High') }[impact] || impact
        },

        /**
         * Look up a user's display name from the loaded members list.
         * Falls back to the userId itself when the user is not a current
         * team member (e.g. they proposed a decision then left the team).
         * NcAvatar will still render their avatar correctly via NC's user API;
         * the display name is only used for the label text next to the avatar.
         */
        memberDisplayName(userId) {
            if (!userId) return ''
            const member = (this.members || []).find(m => m.userId === userId)
            return member?.displayName || userId
        },

        // ── v3.74.10 — Schedule approver meeting flow ────────────────

        /**
         * True when the schedule-meeting button should be shown for `d`.
         * Conditions: status is finalized (awaiting approval), the team has
         * a Calendar resource (otherwise the wizard cannot create an event),
         * the current user is an approver for the decision's category, AND
         * the category has more than one approver (no point scheduling a
         * meeting with yourself).
         */
        canScheduleApproverMeeting(d) {
            if (!d || d.status !== 'finalized') return false
            if (!(this.resources && this.resources.calendar && this.resources.calendar.length > 0)) return false
            if (!this.canApprove(d)) return false
            return this.approverCountFor(d) > 1
        },

        /**
         * Look up the approver count for a decision's category from the
         * already-loaded approverCategories cache. Returns 0 when the
         * category is unknown — the canScheduleApproverMeeting gate then
         * suppresses the button.
         */
        approverCountFor(d) {
            if (!d || !d.category || !this.approverCategoriesLoaded) return 0
            const cat = this.approverCategories.find(c => c.name === d.category)
            return Array.isArray(cat?.approvers) ? cat.approvers.length : 0
        },

        /**
         * Build the description block for a proposal meeting:
         *   - Decision title
         *   - First 400 chars of the body (selected.message?.message), trimmed
         *   - A link back to the team home (Decisions tab) where the proposal
         *     can be found.
         * The link goes to the team home rather than directly to the decision
         * — deep-linking to a specific decision is not yet wired through the
         * app shell; tracked as an open item in HANDOFF.
         */
        buildMeetingDescription(d) {
            const lines = []
            if (d.question) lines.push(d.question)

            const body = (d.message && d.message.message) ? String(d.message.message) : ''
            if (body) {
                const truncated = body.length > 400 ? body.slice(0, 400).trimEnd() + '…' : body
                lines.push('')
                lines.push(truncated)
            }

            // Deep link to the specific proposal — App.vue reads ?team=…&decision=…
            // on mount and routes to the team's Decisions tab pre-selecting the
            // referenced decision (uses the existing decisionsTargetMessageId
            // pattern, which scrolls/selects the matching decision card).
            //
            // The placeholder is passed as { value, escape: false } because
            // NC's t() defaults to HTML-escaping named placeholders — which
            // turns `&` into `&amp;`, breaking the URL when calendar clients
            // copy it. The output goes into a plain-text iCal DESCRIPTION
            // field, not HTML, so escaping is wrong here.
            try {
                const deepUrl = window.location.origin
                    + generateUrl('/apps/teamhub')
                    + `?team=${encodeURIComponent(this.currentTeamId)}`
                    + `&decision=${encodeURIComponent(d.id)}`
                lines.push('')
                lines.push(t('teamhub', 'Link: {url}', { url: { value: deepUrl, escape: false } }))
            } catch (e) {
                console.warn('[TeamHub][TeamDecisionsView] buildMeetingDescription link build failed', e?.message)
            }

            return lines.join('\n')
        },

        /**
         * Open the SuggestMeetingWizard pre-filled with the approver list,
         * title, description, and category for this proposal. Called from
         * the "Schedule meeting" button in the approval block.
         */
        async openApproverMeetingWizard(d) {
            if (!d || this.openingMeetingWizard) return
            this.openingMeetingWizard = true
            try {
                // Fetch approver list from the new backend endpoint.
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${d.id}/approvers`),
                )
                const approverIds = Array.isArray(data?.approvers)
                    ? data.approvers.map(a => a.userId).filter(Boolean)
                    : []

                this.approverPrefill = {
                    attendees:   approverIds,
                    // TRANSLATORS: prefilled meeting title for an approver-meeting on a proposal
                    title:       t('teamhub', 'Proposal meeting'),
                    description: this.buildMeetingDescription(d),
                    category:    t('teamhub', 'Proposals'),
                    banner:      t('teamhub', 'This meeting will be scheduled with all approvers of this category. The attendee list is locked.'),
                }
                this.schedulingMeetingForDecisionId = d.id
                this.showApproverMeetingWizard = true
            } catch (e) {
                console.warn('[TeamHub][TeamDecisionsView] openApproverMeetingWizard: approvers fetch failed', e?.message)
                showError(t('teamhub', 'Could not load approvers for this proposal'))
            } finally {
                this.openingMeetingWizard = false
            }
        },

        /**
         * Wizard `created` callback — receives { eventUid, start, title }.
         * Records the back-reference so the proposal's Scheduled meetings
         * section shows the new entry.
         */
        async onApproverMeetingCreated(payload) {
            const decisionId = this.schedulingMeetingForDecisionId
            this.schedulingMeetingForDecisionId = null
            this.showApproverMeetingWizard = false
            if (!decisionId || !payload || !payload.eventUid) {
                console.warn('[TeamHub][TeamDecisionsView] onApproverMeetingCreated: missing decisionId or eventUid')
                return
            }
            try {
                const startMs = payload.start ? new Date(payload.start).getTime() : 0
                const startSec = startMs > 0 ? Math.floor(startMs / 1000) : Math.floor(Date.now() / 1000)
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${decisionId}/meetings`),
                    {
                        eventUid:     payload.eventUid,
                        meetingTitle: payload.title || '',
                        meetingStart: startSec,
                    },
                )
                // Refresh the section if we're still viewing the same decision.
                if (this.selected && this.selected.id === decisionId) {
                    this.loadScheduledMeetings(decisionId)
                }
            } catch (e) {
                console.warn('[TeamHub][TeamDecisionsView] onApproverMeetingCreated: record failed', e?.message)
                showError(t('teamhub', 'Meeting created, but the link to the proposal could not be saved'))
            }
        },

        /**
         * Load scheduled meetings for the selected proposal. Called when the
         * detail panel opens and after a new meeting is recorded.
         */
        async loadScheduledMeetings(decisionId) {
            if (!decisionId) return
            this.scheduledMeetingsLoading = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${decisionId}/meetings`),
                )
                this.scheduledMeetings = Array.isArray(data?.items) ? data.items : []
            } catch (e) {
                console.warn('[TeamHub][TeamDecisionsView] loadScheduledMeetings failed:', e?.message)
                this.scheduledMeetings = []
            } finally {
                this.scheduledMeetingsLoading = false
            }
        },

        /**
         * Format a scheduled meeting's start time for display.
         */
        formatMeetingTime(unixSeconds) {
            if (!unixSeconds) return ''
            return formatDateTime(unixSeconds * 1000, {
                weekday: 'short', year: 'numeric', month: 'short', day: 'numeric',
                hour: '2-digit', minute: '2-digit',
            })
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
                // v4.11.18 — the two retirement states.
                superseded: t('teamhub', 'Superseded'),
                archived:   t('teamhub', 'Archived'),
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
            return formatDate(ms, { month: 'short', day: 'numeric' })
        },

        fullDate(ts) {
            if (!ts) return ''
            const ms = typeof ts === 'number' ? ts * 1000 : Date.parse(ts)
            return isNaN(ms) ? '' : formatDateTime(ms)
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
    padding: 12px 20px 8px;
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
    font-size: var(--th-font-body);
    font-weight: 600;
    color: var(--color-main-text);
}

.th-dv__breadcrumb-sep {
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-body);
}

.th-dv__breadcrumb-cat {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: var(--th-font-body);
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
    flex-shrink: 0;
}

.th-dv__chips {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}

.th-dv__sort-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

/* ── v3.75.4: Mobile filter toggle ─────────────────────────────────
   On desktop the toggle button is hidden and the filters panel renders
   inline as part of the toolbar flex row (contents-only display so its
   children are direct flex items).
   On mobile (<720px) the toggle becomes visible, the panel collapses
   to a wrapped block below the toolbar, and the chips' default visibility
   flips to hidden until the user taps the toggle. */

.th-dv__filters-toggle {
    /* Hidden on desktop; the chips render directly in the toolbar. */
    display: none;
    align-items: center;
    gap: 8px;
    background: transparent;
    border: 1px solid var(--color-border);
    color: var(--color-main-text);
    border-radius: var(--border-radius-pill);
    padding: 4px 8px;
    font-size: var(--th-font-meta);
    font-weight: 500;
    cursor: pointer;
}
.th-dv__filters-toggle--open {
    background: var(--color-background-hover);
}
.th-dv__filters-toggle:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}
.th-dv__filters-toggle-label {
    line-height: 1;
}
.th-dv__filters-toggle-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 4px;
    border-radius: var(--border-radius-element);
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    font-size: var(--th-font-meta);
    font-weight: 700;
    line-height: 1;
}

/* On desktop: panel is invisible to layout (display:contents) so chips
   sit directly in the toolbar's flex row, preserving the pre-3.75.4 look. */
.th-dv__filters-panel {
    display: contents;
}

@media (max-width: 720px) {
    .th-dv__filters-toggle {
        display: inline-flex;
    }
    /* Mobile: panel becomes a real wrapped block below the toolbar,
       hidden by default, revealed when --open is set by the toggle. */
    .th-dv__filters-panel {
        display: none;
        flex-basis: 100%;
        flex-direction: column;
        gap: 8px;
        padding: 8px 0 0;
        order: 999; /* push to the end of the toolbar flex row */
    }
    .th-dv__filters-panel--open {
        display: flex;
    }
    /* On mobile each chip group + sort takes its own row so wrap is clean. */
    .th-dv__filters-panel .th-dv__chips {
        flex-wrap: wrap;
    }
}

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
    inset-inline-start: 10px;
    color: var(--color-text-maxcontrast);
    pointer-events: none;
}
.th-dv__landing-search {
    width: 100%;
    max-width: 360px;
}
/* v3.100.14: color-mix here is a focus-glow ring, not a state
   background — kept per SKILLS.md § "State-coloured backgrounds"
   (the rule targets state signalling, not focus indicators). */

/* "All decisions" shortcut row */
.th-dv__landing-showall {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-element);
    cursor: pointer;
    text-align: start;
    width: 100%;
    transition: border-color var(--animation-quick), background var(--animation-quick);
}
.th-dv__landing-showall:hover {
    border-color: var(--color-primary-element);
    background: var(--color-background-hover);
}
.th-dv__landing-showall:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}
/* v3.100.14: decorative icon circle — neutral surface, primary
   icon inside carries the accent (was a 12% color-mix soft tint). */
.th-dv__landing-showall-icon {
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--color-background-dark);
    color: var(--color-primary-element);
    display: flex;
    align-items: center;
    justify-content: center;
}
.th-dv__landing-showall-label {
    font-size: var(--th-font-meta);
    font-weight: 600;
    color: var(--color-main-text);
    flex: 1;
}
.th-dv__landing-showall-sub {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}
.th-dv__landing-showall-chevron {
    color: var(--color-text-maxcontrast);
    flex-shrink: 0;
}

.th-dv__landing-no-results {
    text-align: center;
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
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
/* v4.11.19 — the card is the <li> now, not the button inside it: its
   approver avatars are their own interactive elements and could not live
   inside one. The button stretches over the whole card through an ::after,
   so the click target is unchanged, and hover and focus moved up here with
   the border they were drawing. */
.th-dv__cat-card {
    position: relative;
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 12px 16px;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-element);
}

/* The open action. Transparent and unpadded — the card draws the surface;
   this only has to be the thing you click and the thing you tab to. */
.th-dv__cat-card-main {
    flex: 1 1 auto;
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 0;
    background: none;
    border: none;
    text-align: start;
    cursor: pointer;
    color: inherit;
    font: inherit;
}

/* Stretched over the card, so the padding and the chevron are clickable
   too. The avatars sit above it — see below. */
.th-dv__cat-card-main::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
}
/* Hover follows the pointer anywhere on the card; the focus ring follows
   the button, because that is what the keyboard actually lands on. */
.th-dv__cat-card:hover {
    border-color: var(--color-primary-element);
    background: var(--color-background-hover);
}
.th-dv__cat-card:focus-within {
    border-color: var(--color-primary-element);
}
.th-dv__cat-card-main:focus-visible::after {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

/* v4.11.24 — the search field and Add category share one line. The field
   takes the space; the button keeps its own width and does not shrink into
   an ellipsis on a narrow panel. */
.th-dv__landing-top {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.th-dv__landing-top .th-dv__landing-search-wrap {
    flex: 1 1 220px;
    min-width: 0;
}

.th-dv__landing-add {
    flex: 0 0 auto;
}

/* ── Approvers on the card, and the card's own menu ──
   Both sit above the stretched overlay, or clicking either would open the
   category instead. */
.th-dv__cat-card-menu {
    position: relative;
    z-index: 1;
    flex: 0 0 auto;
}

.th-dv__cat-card-approvers {
    position: relative;
    z-index: 1;
    flex: 0 0 auto;
    display: flex;
    align-items: center;
}

/* Overlapped, so four avatars cost the width of about two and a half and
   the row still reads as one group. The inline-start margin is logical, so
   the overlap falls the right way in RTL. */
.th-dv__cat-card-avatar + .th-dv__cat-card-avatar,
.th-dv__cat-card-avatar + .th-dv__cat-card-more {
    margin-inline-start: -6px;
}

/* A ring in the card's own background separates overlapping avatars
   without a border that would change their size. */
.th-dv__cat-card-avatar,
.th-dv__cat-card-more {
    box-shadow: 0 0 0 2px var(--color-main-background);
    border-radius: 50%;
}
.th-dv__cat-card:hover .th-dv__cat-card-avatar,
.th-dv__cat-card:hover .th-dv__cat-card-more {
    box-shadow: 0 0 0 2px var(--color-background-hover);
}

.th-dv__cat-card-more {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    background: var(--color-background-dark);
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
    line-height: 1;
}

/* Icon bubble — decorative surface, primary icon inside carries the
   accent. v3.100.14: neutral surface per SKILLS.md (was 10% color-mix). */
.th-dv__cat-card-icon {
    flex-shrink: 0;
    width: 42px;
    height: 42px;
    border-radius: var(--border-radius-container);
    background: var(--color-background-dark);
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
    gap: 4px;
}
.th-dv__cat-card-name {
    font-size: var(--th-font-body);
    font-weight: 600;
    color: var(--color-main-text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    line-height: 1.3;
}
.th-dv__cat-card-desc {
    font-size: var(--th-font-meta);
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
    transition: opacity var(--animation-quick);
}
.th-dv__cat-card:hover .th-dv__cat-card-chevron,
/* v4.11.19 — and on keyboard focus, which it never did. The chevron is the
   only thing saying the card opens, and somebody tabbing through had no
   way to know. */
.th-dv__cat-card:focus-within .th-dv__cat-card-chevron { opacity: 1; }

/* Decision search-result category tag (inline on card meta)
   v3.100.14: full-saturation category tag per SKILLS.md (was 10% mix). */
.th-dv__card-cat-tag {
    display: inline-block;
    padding: 1px 8px;
    border-radius: var(--border-radius-container);
    font-size: var(--th-font-micro);
    font-weight: 600;
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

/* ── Level badges (in cards) and chips (in detail meta) ──
   v3.100.14: full-saturation badge per SKILLS.md (was 12% mix + border
   at 30%; now solid fill with the -text token, no border needed). */
.th-dv__level-badge {
    display: inline-block;
    padding: 1px 8px;
    border-radius: var(--border-radius-container);
    font-size: var(--th-font-meta);
    font-weight: 600;
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    border: 1px solid var(--color-primary-element);
}

.th-dv__level-chip {
    display: inline-block;
    padding: 4px 8px;
    border-radius: var(--border-radius-container);
    font-size: var(--th-font-micro);
    font-weight: 700;
}
.th-dv__level-chip--operational {
    background: var(--color-background-dark);
    color: var(--color-text-maxcontrast);
}
/* v3.100.14: full-saturation tactical chip per SKILLS.md (was 14% mix). */
.th-dv__level-chip--tactical {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}
/* v3.100.14: full-saturation strategic chip per SKILLS.md (was 14% mix). */
.th-dv__level-chip--strategic {
    background: var(--color-warning);
    color: var(--color-warning-text);
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

.th-dv__state--error { color: var(--color-text-error); }

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
    gap: 8px;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--color-border-dark);
    margin-bottom: 8px;
    color: var(--color-text-maxcontrast);
}

.th-dv__section-title {
    font-size: var(--th-font-micro);
    font-weight: 700;
    color: var(--color-text-maxcontrast);
    flex: 1;
}

.th-dv__section-count {
    font-size: var(--th-font-micro);
    font-weight: 600;
    background: var(--color-background-dark);
    border-radius: var(--border-radius-container);
    padding: 1px 8px;
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
    border-radius: var(--border-radius-element);
    overflow: hidden;
    transition: background var(--animation-quick);
}

.th-dv__card--target {
    box-shadow: 0 0 0 2px var(--color-primary-element);
    border-radius: var(--border-radius-element);
}

.th-dv__card-btn {
    display: flex;
    align-items: center;
    width: 100%;
    background: none;
    border: none;
    cursor: pointer;
    text-align: start;
    padding: 0;
    border-radius: var(--border-radius-element);
    transition: background var(--animation-quick);
    gap: 0;
}

.th-dv__card-btn:hover        { background: var(--color-background-hover); }
.th-dv__card-btn:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 1px; }

/* Left accent stripe */
.th-dv__card-accent {
    width: 4px;
    align-self: stretch;
    flex-shrink: 0;
    border-radius: var(--border-radius-element) 0 0 var(--border-radius-element);
    background: var(--color-border);
    min-height: 44px;
}

.th-dv__card--open       .th-dv__card-accent { background: var(--color-primary-element); }
.th-dv__card--finalized  .th-dv__card-accent { background: var(--color-warning); }
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
    padding: 8px 12px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.th-dv__card-subject {
    font-size: var(--th-font-meta);
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
    font-size: var(--th-font-micro);
    color: var(--color-text-maxcontrast);
}

.th-dv__card-date { margin-inline-start: auto; white-space: nowrap; }

/* Impact label — inline coloured text */
.th-dv__impact           { font-size: var(--th-font-micro); font-weight: 700; }
.th-dv__impact--low      { color: var(--color-text-maxcontrast); }
.th-dv__impact--medium   { color: var(--color-warning-text); }
.th-dv__impact--high     { color: var(--color-text-error); }

/* Status pill */
.th-dv__status-pill {
    font-size: var(--th-font-meta);
    font-weight: 700;
    padding: 4px 8px;
    border-radius: var(--border-radius-pill);
    flex-shrink: 0;
    border: 1.5px solid transparent;
    white-space: nowrap;
}

.th-dv__status-pill--open       { color: var(--color-primary-element);          border-color: var(--color-primary-element); }
.th-dv__status-pill--finalized  { color: var(--color-warning-text);    border-color: var(--color-warning-text); }
.th-dv__status-pill--approved   { color: var(--color-text-success);             border-color: var(--color-text-success); }
.th-dv__status-pill--denied     { color: var(--color-text-error);               border-color: var(--color-text-error); }
.th-dv__status-pill--withdrawn  { color: var(--color-text-maxcontrast);         border-color: var(--color-border-dark); }
/* Legacy fallbacks */
.th-dv__status-pill--proposed   { color: var(--color-primary-element);          border-color: var(--color-primary-element); }
.th-dv__status-pill--decided    { color: var(--color-text-success);             border-color: var(--color-text-success); }

.th-dv__card-chevron {
    color: var(--color-text-maxcontrast);
    margin-inline-end: 8px;
    flex-shrink: 0;
    transition: transform var(--animation-quick);
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
    padding: 16px 20px 0;
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
    transition: transform var(--animation-quick) ease, opacity var(--animation-quick) ease;
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
    padding: 16px 16px 8px;
    border-bottom: 1px solid var(--color-border);
    flex-shrink: 0;
    gap: 8px;
}

.th-dv__detail-close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    flex-shrink: 0;
}

/* v3.71.9 — Back chip; primary way to leave the detail overlay */
.th-dv__detail-back {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    flex-shrink: 0;
}

.th-dv__detail-status { margin-inline-start: auto; }

/* Right-side header controls: status pill + share + close, grouped
   together so the status pill's margin-inline-start: auto pushes the whole
   group flush right (v3.78.5). */
.th-dv__detail-header-right {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-inline-start: auto;
    flex-shrink: 0;
}
.th-dv__detail-header-right .th-dv__detail-status {
    margin-inline-start: 0; /* spacing now handled by parent gap */
}

/* v4.11.19 — .th-dv__detail-share is gone with the standalone copy-link
   icon button; Copy link is a menu item. The menu itself needs no geometry —
   NcActions brings its own trigger — only the guarantee that it does not get
   squeezed by a long status pill. */
.th-dv__detail-menu {
    flex-shrink: 0;
}

/* Question heading */
/* v4.11.12 — the public-record row */
.th-dv__detail-public {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs, 4px);
    margin-block: var(--th-space-md, 12px);
    padding: var(--th-space-sm, 8px) var(--th-space-lg, 16px);
    background: var(--color-background-dark);
    border-radius: var(--border-radius-element);
}

.th-dv__detail-public-state {
    margin: 0;
    font-size: var(--th-font-body);
    font-weight: 600;
}

.th-dv__detail-public-hint {
    margin: 0;
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
}

.th-dv__detail-question {
    font-size: var(--th-font-body);
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
    border-radius: var(--border-radius-element);
    /* overflow:hidden removed — it clips NcAvatar's user-card popup */
    border: 1px solid var(--color-border);
}

.th-dv__detail-meta-row {
    display: contents;
}

/* v3.97.5 — milestone chip inside the decision detail panel. Same visual
 * language as the list-view chip in DecisionsList.vue. */
.th-dv__milestone-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px;
    border-radius: var(--border-radius-container);
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    font-weight: 600;
    font-size: var(--th-font-micro);
}
.th-dv__milestone-date {
    opacity: 0.85;
    font-weight: 500;
}

.th-dv__detail-meta-row dt,
.th-dv__detail-meta-row dd {
    padding: 8px 12px;
    margin: 0;
    font-size: var(--th-font-meta);
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

.th-dv__detail-meta-sub { color: var(--color-text-maxcontrast); font-size: var(--th-font-micro); }

/* Avatar + name inline layout used in Proposed by / Decided by rows */
.th-dv__user-cell {
    display: flex;
    align-items: center;
    gap: 8px;
}

.th-dv__user-name {
    font-size: var(--th-font-meta);
    color: var(--color-main-text);
    line-height: 1.4;
}

.th-dv__detail-tag {
    display: inline-block;
    background: var(--color-background-dark);
    border-radius: var(--border-radius-small);
    padding: 1px 8px;
    font-size: var(--th-font-micro);
}

/* Answer block */
/* Answer block — the recorded answer of a decision. State: "this
   decision has an outcome". v3.100.14: full-saturation success per
   SKILLS.md § "State-coloured backgrounds" (was a 7% color-mix soft
   tint of the -text token, which was also an unusual pattern). */
.th-dv__detail-answer {
    margin: 0 0 16px;
    padding: 12px 16px;
    background: var(--color-success);
    color: var(--color-success-text);
    border-inline-start: 3px solid var(--color-success-text);
    border-radius: 0 var(--border-radius-element) var(--border-radius-element) 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.th-dv__detail-answer-label {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: var(--th-font-micro);
    font-weight: 700;
    color: var(--color-text-success);
}

.th-dv__detail-answer-text {
    font-size: var(--th-font-meta);
    color: var(--color-main-text);
    margin: 0;
    line-height: 1.5;
}

/* v3.75.5 — Markdown-rendered variant of the final proposal block.
   Defaults are tightened so the rendered HTML (h*, ul/ol, p, hr, etc.)
   sits well in the compact detail panel rather than expanding it. */
.th-dv__detail-answer-text--md > :first-child { margin-top: 0; }
.th-dv__detail-answer-text--md > :last-child  { margin-bottom: 0; }
.th-dv__detail-answer-text--md p {
    margin: 0 0 8px;
}
.th-dv__detail-answer-text--md h1,
.th-dv__detail-answer-text--md h2,
.th-dv__detail-answer-text--md h3,
.th-dv__detail-answer-text--md h4,
.th-dv__detail-answer-text--md h5,
.th-dv__detail-answer-text--md h6 {
    margin: 8px 0 4px;
    font-weight: 700;
    line-height: 1.3;
}
.th-dv__detail-answer-text--md h1 { font-size: var(--th-font-heading); }
.th-dv__detail-answer-text--md h2 { font-size: var(--th-font-body); }
.th-dv__detail-answer-text--md h3 { font-size: var(--th-font-body); }
.th-dv__detail-answer-text--md h4,
.th-dv__detail-answer-text--md h5,
.th-dv__detail-answer-text--md h6 { font-size: var(--th-font-meta); }
.th-dv__detail-answer-text--md ul,
.th-dv__detail-answer-text--md ol {
    margin: 0 0 8px;
    padding-inline-start: 24px;
}
.th-dv__detail-answer-text--md li {
    margin: 4px 0;
}
.th-dv__detail-answer-text--md a {
    color: var(--color-primary-element);
    text-decoration: underline;
}
.th-dv__detail-answer-text--md code {
    font-family: var(--font-face-mono, monospace);
    background: var(--color-background-dark);
    padding: 1px 4px;
    border-radius: var(--border-radius-small);
    font-size: var(--th-font-meta);
}
.th-dv__detail-answer-text--md pre {
    background: var(--color-background-dark);
    padding: 8px 8px;
    border-radius: var(--border-radius-small);
    overflow-x: auto;
    margin: 0 0 8px;
}
.th-dv__detail-answer-text--md pre code {
    background: transparent;
    padding: 0;
}
.th-dv__detail-answer-text--md blockquote {
    border-inline-start: 3px solid var(--color-border);
    margin: 0 0 8px;
    padding: 4px 8px;
    color: var(--color-text-maxcontrast);
}
.th-dv__detail-answer-text--md hr {
    border: 0;
    border-top: 1px solid var(--color-border);
    margin: 8px 0;
}

/* Withdrawn block */
.th-dv__detail-withdrawn {
    margin: 0 0 16px;
    padding: 8px 16px;
    background: var(--color-background-dark);
    border-radius: var(--border-radius-element);
    display: flex;
    flex-direction: column;
    gap: 4px;
}

/* ── v4.11.18 — retirement and review ──
   Both blocks are NcNoteCards, so the card's own padding, border and colour
   come from Nextcloud. What is left is the spacing between the lines inside
   one, which the component does not and should not decide. */
.th-dv__detail-retired,
.th-dv__detail-review {
    margin: 0 0 16px;
}

.th-dv__detail-retired-line,
.th-dv__detail-review-line {
    margin: 0;
    line-height: 1.4;
}

.th-dv__detail-retired-line + .th-dv__detail-retired-line {
    margin-block-start: 4px;
}

/* A raw <button> that reads as a link: the documented carve-out in
   /ui-standards is for exactly this — an inline affordance inside a sentence,
   where NcButton's chrome would break the line. Focus ring kept. */
.th-dv__detail-retired-link {
    padding: 0;
    border: none;
    background: none;
    color: var(--color-primary-element);
    font: inherit;
    text-align: start;
    text-decoration: underline;
    cursor: pointer;
}

.th-dv__detail-retired-link:hover {
    color: var(--color-primary-element-hover);
}

.th-dv__detail-retired-link:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
    border-radius: var(--border-radius-small);
}

.th-dv__detail-review-quote {
    margin: 8px 0 0;
    padding-inline-start: 12px;
    border-inline-start: 2px solid var(--color-border-dark);
    color: var(--color-text-maxcontrast);
    white-space: pre-wrap;
}

/* v4.11.30 — what the requester is asking the decision to say, inside the
   running-review banner. Set apart from the motivation above it by a label
   and a rule, because the two are different kinds of statement: one is why
   somebody asked, the other is what they want it to say. */
.th-dv__review-proposal {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--th-space-xs);
    margin-block-start: var(--th-space-md);
    padding-block-start: var(--th-space-sm);
    border-block-start: 1px solid var(--color-border);
}

.th-dv__review-proposal-label {
    font-size: var(--th-font-meta);
    font-weight: 600;
    color: var(--color-text-maxcontrast);
}

.th-dv__review-proposal-question {
    margin: 0;
    font-weight: 600;
}

/* v4.11.19 — .th-dv__detail-review-actions is gone: Keep and Archive moved
   into the Actions menu, and the note card is prose again. */

.th-dv__detail-review-line + .th-dv__detail-review-line {
    margin-block-start: 4px;
}

.th-dv__detail-review-due {
    margin: 0 0 16px;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.th-dv__detail-section-label {
    font-size: var(--th-font-micro);
    font-weight: 700;
    color: var(--color-text-maxcontrast);
}

/* ── Drafting block (v4.5.42) ─────────────────────────────────────────
   The proposer's controls on an open proposal. Same card shape as the
   approval block on the other side of the panel, so the two read as the
   two ends of one lifecycle rather than as unrelated widgets. */
.th-dv__drafting {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 12px 16px;
    margin-bottom: 12px;
    border: 1px solid var(--color-border);
    border-radius: var(--th-radius-card);
    background: var(--color-background-hover);
}

/* v4.5.46 — controls left, lifecycle rail right. */
.th-dv__drafting-body {
    display: flex;
    align-items: flex-start;
    gap: 16px;
}

.th-dv__drafting-main {
    display: flex;
    flex-direction: column;
    gap: 8px;
    /* Stops a long proposal body from pushing the rail off the panel. */
    min-width: 0;
    flex: 1 1 auto;
}

.th-dv__drafting-rail {
    flex: 0 0 auto;
    max-width: 130px;
}

/* The detail panel is already a two-column layout on wide screens, so the
   drafting block gets narrow early. Stack the rail under the controls rather
   than squeezing both. */
@media (max-width: 900px) {
    .th-dv__drafting-body {
        flex-direction: column;
    }
    .th-dv__drafting-rail {
        max-width: none;
    }
}

/* Subject and context inside the drafting block.

   The standalone heading carries `padding: 16px 0 12px`, which separates it
   from the meta grid it normally sits under. In here it is the first thing
   in a block that already has its own padding and an 8px flex gap, so that
   padding would read as a stray gap under the "YOUR PROPOSAL" label. */
.th-dv__drafting-question {
    padding: 0;
}

/* Base typography matches the final-proposal block deliberately: it is the
   same text at a different point in its life.

   The child rules need `:deep()`. This is a `v-html` subtree, and scoped CSS
   puts the scope attribute on the *last* compound selector — so a plain
   `.th-dv__drafting-context p` compiles to `p[data-v-…]`, which nothing
   inside rendered HTML ever carries. The element itself is in the template
   and does get the attribute, which is why the base rule works without it.
   (The `.th-dv__detail-answer-text--md` rules above have the same problem
   and are inert for the same reason — pre-existing, logged, not fixed here.) */
.th-dv__drafting-context {
    font-size: var(--th-font-meta);
    line-height: 1.5;
    color: var(--color-main-text);
    margin: 0;
}

.th-dv__drafting-context :deep(> :first-child) { margin-top: 0; }
.th-dv__drafting-context :deep(> :last-child)  { margin-bottom: 0; }
.th-dv__drafting-context :deep(p)  { margin: 0 0 8px; }
.th-dv__drafting-context :deep(ul),
.th-dv__drafting-context :deep(ol) { margin: 0 0 8px; padding-inline-start: 24px; }
.th-dv__drafting-context :deep(li) { margin: 2px 0; }
.th-dv__drafting-context :deep(a) {
    color: var(--color-primary-element);
    text-decoration: underline;
}
.th-dv__drafting-context :deep(code) {
    font-family: var(--font-face-mono, monospace);
    background: var(--color-background-dark);
    padding: 1px 4px;
    border-radius: var(--border-radius-small);
    font-size: var(--th-font-meta);
}

.th-dv__drafting-label {
    font-size: var(--th-font-meta);
    font-weight: var(--th-font-weight-semibold);
    color: var(--color-main-text);
}

.th-dv__drafting-input,
.th-dv__drafting-textarea {
    width: 100%;
    box-sizing: border-box;
    /* NC's form-field pattern: no outline, a primary border on focus.
       Documented as acceptable in SKILLS.md § Focus visibility standard. */
}

.th-dv__drafting-input:focus,

.th-dv__drafting-input:focus-visible,

.th-dv__drafting-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.th-dv__drafting-hint {
    margin: 0;
    font-size: var(--th-font-micro);
    color: var(--color-text-maxcontrast);
    line-height: var(--th-line-height-body);
}

/* ── Approval block ──────────────────────────────────────────────────── */
.th-dv__detail-approval {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 12px;
    background: var(--color-background-soft);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-element);
}

.th-dv__approval-label {
    font-size: var(--th-font-meta);
    font-weight: 600;
    color: var(--color-main-text);
    display: flex;
    align-items: center;
    gap: 4px;
}

.th-dv__approval-required {
    color: var(--color-text-error);
    font-weight: 700;
}

.th-dv__approval-textarea {
    width: 100%;
    box-sizing: border-box;
}

.th-dv__approval-textarea:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.th-dv__approval-counter {
    font-size: var(--th-font-micro);
    color: var(--color-text-maxcontrast);
    text-align: end;
    margin: 0;
}

/* v4.11.19 — the one line that replaced the Schedule meeting button, telling
   an approver where it went. Meta weight, because it is an aside beside two
   buttons that are not. */
.th-dv__approval-hint {
    margin: 8px 0 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    line-height: 1.4;
}

.th-dv__approval-counter--warn {
    color: var(--color-warning-text);
    font-weight: 600;
}

.th-dv__approval-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.th-dv__detail-withdrawn-text {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    margin: 0;
    line-height: 1.5;
}

/* Source */
.th-dv__detail-source {
    margin: 0 0 16px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.th-dv__detail-source-link {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    word-break: break-all;
}

.th-dv__detail-source-text {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

/* Source files list — v3.71.2 */
.th-dv__detail-source-loading {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

/* ──────────────────────────────────────────────────────────────────────
   Unified row pattern for the right-column "links" sections — used by
   Source files, Linked tasks, and Linked decisions. Same layout, same
   hover, same pill style across all three so the detail panel reads as
   one professional surface rather than three differently-styled lists.
   Pills are solid-filled (no transparency / soft tints) per design.
   ────────────────────────────────────────────────────────────────────── */

/* Container blocks reuse one shared geometry. v4.11.19 — .th-dv__detail-tasks
   went with the separate Linked-tasks section; its rows live in the Links
   list now and use .th-dv__link-* like every other kind. */
.th-dv__detail-dec-links,
.th-dv__detail-meetings {
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
    gap: 8px;
}

/* v4.11.19 — .th-dv__task-status-pill* is gone. A linked Deck card's
   Complete / Open state is a status pill now, reusing
   .th-dv__link-pill--status-approved and --status-open: it sits in the same
   row as a linked decision's status and had no business carrying a second
   shape and a second pair of colours for the same idea. */

.th-dv__link-row {
    flex: 1 1 auto;
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
    padding: 8px 8px;
    background: transparent;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-small);
    color: var(--color-main-text);
    font-size: var(--th-font-meta);
    line-height: 1.3;
    text-align: start;
    text-decoration: none;
    cursor: pointer;
    transition: background var(--animation-quick) ease, border-color var(--animation-quick) ease;
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
    font-size: var(--th-font-micro);
    color: var(--color-text-maxcontrast);
}

/* Pills — solid filled, white text. No soft tints. */
.th-dv__link-pill {
    flex: 0 0 auto;
    display: inline-block;
    padding: 4px 8px;
    border-radius: var(--border-radius-container);
    font-size: var(--th-font-meta);
    font-weight: 700;
    line-height: 1.4;
    white-space: nowrap;
    /* v3.100.16: theme-safe inversion — text uses --color-main-background
       so it inverts alongside the --color-text-maxcontrast fill in dark
       mode (was hard-pinned `color: #fff`). */
    color: var(--color-main-background);
    background: var(--color-text-maxcontrast); /* neutral default */
}

/* Status pills — match the existing list-view colour vocabulary */
.th-dv__link-pill--status-open,
.th-dv__link-pill--status-proposed {
    background: var(--color-primary-element);
}
/* Status pills — v3.100.16: NC theme tokens (were --th-color-* hex). */
.th-dv__link-pill--status-open,
.th-dv__link-pill--status-proposed {
    background: var(--color-primary-element);
}
.th-dv__link-pill--status-approved,
.th-dv__link-pill--status-decided {
    background: var(--color-success);
}
.th-dv__link-pill--status-denied {
    background: var(--color-error);
}
.th-dv__link-pill--status-withdrawn {
    background: var(--color-text-maxcontrast);
}

/* Level pills — operational neutral, tactical primary, strategic warning */
.th-dv__link-pill--level-operational {
    background: var(--color-text-maxcontrast);
}
.th-dv__link-pill--level-tactical {
    background: var(--color-primary-element);
}
.th-dv__link-pill--level-strategic {
    background: var(--color-warning);
}

/* "Final proposal" badge — matches status-approved */
.th-dv__link-pill--proposal {
    background: var(--color-success);
}

/* v3.75.3 — Kind pills distinguish internal-decision rows from
   external-URL rows in the unified Linked decisions list. Neutral
   palette so they don't compete with the status/level pills. */
.th-dv__link-pill--kind-internal {
    background: var(--color-primary-element);
}
/* v4.11.19 — a task. `--color-primary-element-hover` rather than a new
   colour: the three kind pills have to be told apart at a glance, and this is
   a shade of the same primary the Decision pill uses, which reads as "also in
   this Nextcloud" next to the neutral External pill. Deliberately not
   `--color-warning` or `--color-success` — a task is neither good news nor
   bad, and a state colour here would compete with the status pill beside it.
   Light pill text (`--color-main-background`, from the base rule) holds on
   it, which is why a `-light` shade was not an option. */
.th-dv__link-pill--kind-task {
    background: var(--color-primary-element-hover);
}
.th-dv__link-pill--kind-external {
    background: var(--color-text-maxcontrast);
}
/* v4.11.31 — the Supersedes pill. `--color-main-text`, the strongest neutral
   NC has, with the base rule's `--color-main-background` ink: it inverts in
   dark mode like the External pill does, and it is the one fill that reads as
   *structural* beside three pills that name a kind of thing linked. This row
   is not a kind of attachment — it is the lifecycle — so it must not look
   like a fourth sibling of Decision / Task / External.
   Deliberately not a state colour: replacing a decision is neither good news
   nor bad, and a state fill here would compete with the status pill next to
   it (the same reasoning as the Task pill above). */
.th-dv__link-pill--kind-supersedes {
    background: var(--color-main-text);
}
/* v4.11.31 — the two retired statuses, written out rather than left to the
   base rule's neutral. They are reachable on a link row now that the
   Supersedes row points at a superseded decision, and `decisionStatusTone()`
   spells them out for the same reason: a retired decision is a record and not
   a problem, so it must not pick up `--color-error` if somebody later
   reorders these rules. */
.th-dv__link-pill--status-superseded,
.th-dv__link-pill--status-archived {
    background: var(--color-text-maxcontrast);
}

/* v4.11.19 — holds only the inline form a menu item opened; the four
   *Link …* buttons are in the Actions menu. */
.th-dv__link-actions-row {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.th-dv__tasks-link-form--ext {
    /* Visually grouped form used by the external-link inline editor.
       Inherits geometry from .th-dv__tasks-link-form; this modifier
       exists for future tweaks without touching the shared rule. */
}

/* Remove button — ghost; only visible on hover/focus of its parent row */
.th-dv__link-remove {
    flex: 0 0 auto;
    width: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
}

.th-dv__link-item:hover .th-dv__link-remove,
.th-dv__link-item:focus-within .th-dv__link-remove,
.th-dv__link-remove:focus-visible {
    opacity: 1;
}

/* Empty state + actions row — shared */
.th-dv__link-empty {
    margin: 0;
    padding: 4px 0;
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
}


/* Tasks-specific loading + inline form (Session B) — kept */
.th-dv__tasks-loading,
.th-dv__dec-links-loading,
.th-dv__meetings-loading {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
}

/* v3.74.10 — Scheduled meetings: time suffix on each meeting row */
.th-dv__meeting-when {
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-micro);
    margin-inline-start: 4px;
}

.th-dv__tasks-link-form {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.th-dv__tasks-link-input {
    width: 100%;
}
.th-dv__tasks-link-btns { display: flex; gap: 6px; }

/* ── Session C: decision picker modal ── */
/* ── Dialogs (v4.11.19) ──
   One set of rules for the three dialogs on this panel: Request a review,
   Supersede, and Link a decision. They replaced `.th-dv__dec-picker*` and a
   `.th-dv-modal*` set that had **no rules at all** — four class names used
   in a template and defined nowhere, which is why 4.11.18's review form
   rendered as a stack of unstyled elements.

   `NcDialog` owns the frame: the heading, the padding around the content,
   the scrolling and the button row. What is left here is the inside of the
   slot, and deliberately nothing that the dialog already decides. */
.th-dv-dialog {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.th-dv-dialog__intro {
    margin: 0;
    line-height: 1.4;
}

/* v4.11.21 — `.th-dv-dialog__quote` is gone with the quoted question. You
   opened the dialog from the decision, so repeating its wording back was a
   paragraph telling you something you had just clicked on. */

.th-dv-dialog__field {
    width: 100%;
}

/* v4.11.30 — the optional proposal inside the review-request dialog. A
   fieldset because the three controls answer one question together, and a
   border because it is the part of the dialog the requester may skip: a
   boundary says "this is a section" where whitespace alone reads as more of
   the same form. */
.th-dv-dialog__fieldset {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-sm);
    margin: 0;
    padding: var(--th-space-md);
    border: 2px solid var(--color-border);
    border-radius: var(--th-radius-card);
}

.th-dv-dialog__legend {
    padding-inline: var(--th-space-xs);
    font-size: var(--th-font-body);
    font-weight: 600;
}

.th-dv-dialog__legend-hint,
.th-dv-dialog__hint {
    margin: 0;
    font-size: var(--th-font-meta);
    line-height: var(--th-line-height-body);
    color: var(--color-text-maxcontrast);
}

.th-dv-dialog__label {
    font-size: var(--th-font-meta);
    font-weight: 500;
    color: var(--color-text-maxcontrast);
}

.th-dv-dialog__loading {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 0;
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
}

/* At most four results, so no scroller and no max-height: a list that
   cannot overflow should not look as though it might. */
.th-dv-dialog__results {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

/* A raw <button> rather than NcButton: this is a list row, which is the
   documented carve-out in /ui-standards — NcButton's chrome would make four
   results look like four primary actions. Focus ring kept. */
.th-dv-dialog__result {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px;
    background: transparent;
    border: 1px solid transparent;
    border-radius: var(--border-radius-element);
    text-align: start;
    cursor: pointer;
    color: var(--color-main-text);
    font: inherit;
}

.th-dv-dialog__result:hover {
    background: var(--color-background-hover);
    border-color: var(--color-border);
}

.th-dv-dialog__result:focus-visible {
    background: var(--color-background-hover);
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}

.th-dv-dialog__result[disabled] {
    opacity: 0.6;
    cursor: progress;
}

.th-dv-dialog__result-icon {
    flex: 0 0 auto;
    color: var(--color-text-maxcontrast);
}

.th-dv-dialog__result-text {
    flex: 1 1 auto;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.th-dv-dialog__result-title {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    line-height: 1.3;
}

.th-dv-dialog__result-meta {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* NcEmptyContent sizes itself for a page; inside a dialog it needs reining
   in or it pushes the buttons off the bottom. */
.th-dv-dialog__empty {
    margin: 0;
    padding: 8px 0;
}

/* v4.11.21 — `.th-dv-dialog__hint` went with the "showing the four best
   matches" line. A list of four with a search box above it does not need a
   sentence explaining that it is a list of four. */

/* ── Audit timeline (Session J) ── */
.th-dv__detail-audit {
    margin: 16px 16px 0;
    padding-top: 16px;
    border-top: 1px solid var(--color-border);
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.th-dv__audit-loading {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.th-dv__audit-error {
    padding: 8px 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-error);
}

.th-dv__audit-empty {
    padding: 8px 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
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
    inset-inline-start: 5px;
    top: 8px;
    bottom: 8px;
    width: 1px;
    background: var(--color-border);
}

.th-dv__audit-item {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 8px 0;
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
.th-dv__audit-item--finalized .th-dv__audit-dot { background: var(--color-warning); }
.th-dv__audit-item--approved  .th-dv__audit-dot { background: var(--color-success-text); }
.th-dv__audit-item--denied    .th-dv__audit-dot { background: var(--color-error-text); }
.th-dv__audit-item--withdrawn .th-dv__audit-dot { background: var(--color-text-maxcontrast); opacity: 0.6; }
/* v4.11.26 — the decision was reworded and stayed in force, so the dot is
   the primary element's: something happened to the record, and it was
   neither an approval nor a refusal. Every transition without a rule of its
   own falls back to the default dot, which is why only the ones that mean
   something different are listed here. */
.th-dv__audit-item--revised   .th-dv__audit-dot { background: var(--color-primary-element); }

.th-dv__audit-body {
    flex: 1;
    min-width: 0;
}

.th-dv__audit-header {
    display: flex;
    align-items: baseline;
    gap: 8px;
    flex-wrap: wrap;
    font-size: var(--th-font-meta);
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
    margin-inline-start: auto;
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
    font-size: var(--th-font-micro);
}

.th-dv__audit-payload {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    margin-top: 4px;
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
    gap: 8px;
    font-weight: 600;
    font-size: var(--th-font-body);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex: 1 1 auto;
}

.th-dv-viewer__badge {
    flex: 0 0 auto;
    font-size: var(--th-font-meta);
    font-weight: 600;
    letter-spacing: 0.4px;
    color: var(--color-text-success);
    background: var(--color-success-default, var(--color-background-darker));
    padding: 4px 8px;
    border-radius: var(--border-radius-small);
}

.th-dv-viewer__readonly {
    flex: 0 0 auto;
    font-size: var(--th-font-micro);
    letter-spacing: 0.4px;
    color: var(--color-text-maxcontrast);
    border: 1px solid var(--color-border);
    padding: 4px 8px;
    border-radius: var(--border-radius-small);
}

/* v3.71.3 — tab-out affordance shown only for external-URL sources */
.th-dv-viewer__newtab {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: var(--th-font-micro);
    text-decoration: none;
    color: var(--color-primary-element);
    border: 1px solid var(--color-border);
    padding: 4px 8px;
    border-radius: var(--border-radius-small);
}

/* v3.100.14: split hover+focus so keyboard focus keeps a visible ring. */
.th-dv-viewer__newtab:hover {
    background: var(--color-background-hover);
}
.th-dv-viewer__newtab:focus-visible {
    background: var(--color-background-hover);
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

/* v3.71.3 — button-styled source link (same look as <a> it replaced) */

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
.th-dv-viewer__markdown ol { padding-inline-start: 1.5em; margin: 0.6em 0; }
.th-dv-viewer__markdown li { margin: 0.2em 0; }
.th-dv-viewer__markdown hr {
    border: none;
    border-top: 1px solid var(--color-border);
    margin: 1.5em 0;
}
.th-dv-viewer__markdown blockquote {
    border-inline-start: 3px solid var(--color-border);
    margin: 0.8em 0;
    padding: 0.2em 0 0.2em 1em;
    color: var(--color-text-maxcontrast);
}
.th-dv-viewer__markdown code {
    background: var(--color-background-hover);
    padding: 1px 4px;
    border-radius: var(--border-radius-small);
    font-size: 0.92em;
}
.th-dv-viewer__markdown pre {
    background: var(--color-background-hover);
    padding: 12px 16px;
    border-radius: var(--border-radius-small);
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
    font-size: var(--th-font-meta);
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
    font-size: var(--th-font-heading);
    font-weight: 600;
    color: var(--color-main-text);
}
.th-dv-viewer__nopreview-sub {
    margin: 0;
    max-width: 480px;
    font-size: var(--th-font-meta);
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
    gap: 8px;
    padding: 8px 16px;
    background: var(--color-background-hover);
    color: var(--color-main-text);
    text-decoration: none;
    border-radius: var(--border-radius-small);
    border: 1px solid var(--color-border);
    font-size: var(--th-font-meta);
    font-weight: 500;
}

.th-dv-viewer__nopreview-btn--primary {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    border-color: var(--color-primary-element);
}

/* v3.100.14: split hover+focus so keyboard focus keeps a visible ring. */
.th-dv-viewer__nopreview-btn:hover {
    background: var(--color-background-dark);
}
.th-dv-viewer__nopreview-btn:focus-visible {
    background: var(--color-background-dark);
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

.th-dv-viewer__nopreview-btn--primary:hover,
.th-dv-viewer__nopreview-btn--primary:focus-visible {
    background: var(--color-primary-element-hover);
    color: var(--color-primary-element-text);
}

</style>
