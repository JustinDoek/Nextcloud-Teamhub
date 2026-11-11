<template>
    <!--
        v4.5.10 — hover/focus on a tab starts its iframe loading before the
        click lands, which is what makes an app tab feel instant. Delegated on
        the container rather than bound per tab: every tab already carries
        id="tab-{view}", so this covers the built-ins, the project tabs and any
        integration tab without touching them individually.
    -->
    <div
        class="teamhub-tab-bar"
        role="tablist"
        :aria-label="t('teamhub', 'Team navigation')"
        @mouseover="onTabIntent"
        @focusin="onTabIntent">
        <!--
            Home tab — always first, not reorderable.
        -->
        <button
            id="tab-msgstream"
            role="tab"
            class="teamhub-tab"
            :class="{ active: currentView === 'msgstream' }"
            :aria-selected="currentView === 'msgstream' ? 'true' : 'false'"
            @click="setView('msgstream')">
            <MessageOutline :size="ICON_BODY" />
            {{ t('teamhub', 'Home') }}
        </button>

        <!--
            Draggable tabs.
            Mouse: drag using the grip handle at the left of the tab.
            Keyboard: Tab/Shift+Tab to focus a tab, then Left/Right arrow to reorder.
        -->
        <draggable
            v-model="visibleTabs"
            :animation="150"
            ghost-class="teamhub-tab-ghost"
            drag-class="teamhub-tab-dragging"
            handle=".teamhub-tab-drag-handle"
            class="teamhub-tab-draggable"
            item-key="key"
            @end="$emit('tab-reorder', orderedTabs)">
            <template #item="{ element: tab }">
                <!--
                    One node per descriptor — v4.13.24.

                    What stood here was a sixteen-arm v-if/v-else-if chain, one
                    arm per tab key, each a copy of the one above it differing
                    in an icon, a label and sometimes a click handler. A
                    descriptor that matched no arm rendered nothing at all, and
                    silently: a v-else-if chain has no else. That is what hid
                    the Wiki tab in v4.3.9, the Services tab in v4.10.45 and
                    every integration-package tab in v4.13.23.

                    The label and the icon name were already on the descriptor
                    (`buildAllTabDescriptors` translates and names them); the
                    chain held a second copy of both. Now the descriptor is the
                    only copy, `tabIcon()` resolves the icon name through one
                    map with a Puzzle fallback — which matters because a
                    third-party app chooses that string — and TAB_KIND decides
                    the element. An absolute web link is the one descriptor
                    that is not a tab: it leaves the page, so it renders as an
                    anchor without tab semantics.
                -->
                <component
                    :is="isExternalLinkTab(tab) ? 'a' : 'button'"
                    :id="'tab-' + tab.key"
                    :key="'tab-' + tab.key"
                    class="teamhub-tab"
                    :class="{
                        'teamhub-tab--link': isExternalLinkTab(tab),
                        active: !isExternalLinkTab(tab) && currentView === tab.key,
                    }"
                    :role="isExternalLinkTab(tab) ? null : 'tab'"
                    :aria-selected="isExternalLinkTab(tab) ? null : String(currentView === tab.key)"
                    :href="isExternalLinkTab(tab) ? tab.url : null"
                    :target="isExternalLinkTab(tab) ? '_blank' : null"
                    :rel="isExternalLinkTab(tab) ? 'noopener noreferrer' : null"
                    :title="t('teamhub', 'Press left/right arrow to reorder')"
                    @click="onTabClick(tab)"
                    @keydown.left.prevent="moveTab(tab, -1)"
                    @keydown.right.prevent="moveTab(tab, 1)">
                    <DragVariant class="teamhub-tab-drag-handle" :size="ICON_INLINE" aria-hidden="true" />
                    <!-- An app's own icon when it has one, else the named
                         MDI icon. Decorative either way: the label follows. -->
                    <img
                        v-if="tabAppIconUrl(tab)"
                        :src="tabAppIconUrl(tab)"
                        alt=""
                        class="teamhub-tab-app-icon"
                        aria-hidden="true"
                        @error="onTabIconError($event, tab)" />
                    <component v-else :is="tabIcon(tab)" :size="ICON_BODY" />
                    {{ tab.label }}
                    <span
                        v-if="tabCount(tab) > 1"
                        class="teamhub-tab-count"
                        :aria-label="tabCountLabel(tab)">
                        {{ tabCount(tab) }}
                    </span>
                </component>
            </template>
        </draggable>

        <!-- Overflow "More" menu — shown when tabs exceed available width -->
        <div v-if="hasOverflow"
             ref="moreContainer"
             class="teamhub-tab-more"
             @mouseenter="openMoreMenu"
             @mouseleave="scheduleMoreMenuClose">
            <button
                class="teamhub-tab teamhub-tab-more-trigger"
                :class="{ active: activeInOverflow }"
                :aria-expanded="String(moreMenuOpen)"
                aria-haspopup="true"
                @click="toggleMoreMenu"
                @keydown.escape="moreMenuOpen = false">
                <ChevronDown :size="ICON_BODY" />
                {{ t('teamhub', 'More…') }}
            </button>
            <div v-show="moreMenuOpen"
                 ref="moreMenuEl"
                 class="teamhub-tab-more-menu"
                 role="menu"
                 :aria-label="t('teamhub', 'More tabs')"
                 :style="moreMenuStyle"
                 @mouseenter="openMoreMenu"
                 @mouseleave="scheduleMoreMenuClose">
                <!-- v4.13.24 — the same one-node-per-descriptor shape as the
                     strip above, sharing its icon and element resolvers. The
                     overflow menu had its own `ext-`-only app-icon test, so an
                     integration whose tab was pushed into the overflow lost
                     its branding even once the tab rendered. -->
                <component
                    v-for="tab in overflowTabs"
                    :is="isExternalLinkTab(tab) ? 'a' : 'button'"
                    :key="'more-' + tab.key"
                    class="teamhub-tab-more-item"
                    :class="{ active: !isExternalLinkTab(tab) && currentView === tab.key }"
                    role="menuitem"
                    :href="isExternalLinkTab(tab) ? tab.url : null"
                    :target="isExternalLinkTab(tab) ? '_blank' : null"
                    :rel="isExternalLinkTab(tab) ? 'noopener noreferrer' : null"
                    @click="onOverflowTabClick(tab)">
                    <img
                        v-if="tabAppIconUrl(tab)"
                        :src="tabAppIconUrl(tab)"
                        alt=""
                        class="teamhub-tab-app-icon"
                        aria-hidden="true"
                        @error="onTabIconError($event, tab)" />
                    <component v-else :is="tabIcon(tab)" :size="ICON_BODY" />
                    {{ tab.label }}
                </component>
            </div>
        </div>

        <NcButton
            v-if="canManageLinks"
            class="teamhub-tab-add"
            variant="tertiary"
            :aria-label="t('teamhub', 'Manage links')"
            @click="$emit('manage-links')">
            <template #icon><Plus :size="ICON_BODY" /></template>
        </NcButton>

        <!-- Edit layout toggle — shown only on Home view, and only on
             non-mobile viewports. The mobile layout is fixed (one canvas
             per widget) and not user-arrangeable, so the button is hidden
             rather than disabled to keep the bar clean. -->
        <!-- v4.13.34 — when a team admin has *enforced* a layout for this
             team, the control stays where it is, greyed, and says why.
             Justin's call, 2026-10-27: "the edit config button is greyed out
             with text team preset".

             This is the one documented departure from the hidden-not-disabled
             rule in CLAUDE.md, and the reason it is right here is that the
             rule is about **roles**: an action somebody's role will never
             allow should not be on their screen at all, because its absence is
             the honest answer. This is a **state** — the member could arrange
             their home yesterday and will again if the team lifts
             enforcement — and a control that silently vanished would read as a
             bug in the app rather than as a decision somebody made. A disabled
             control with the reason on it answers the question the
             disappearance would raise. -->
        <NcButton
            v-if="currentView === 'msgstream' && !isMobile && !isTablet"
            class="teamhub-edit-layout-btn"
            :variant="editMode ? 'primary' : 'tertiary'"
            :disabled="(layoutEnforced && !canEditEnforced) || layoutActionBusy"
            :title="layoutEnforced && !canEditEnforced ? t('teamhub', 'A team admin set the layout for this team.') : null"
            :aria-label="layoutEnforced && !canEditEnforced
                ? t('teamhub', 'Team preset') + '. ' + t('teamhub', 'A team admin set the layout for this team.')
                : (layoutEnforced && canEditEnforced
                    ? (editMode ? t('teamhub', 'Publish team layout') : t('teamhub', 'Edit team layout'))
                    : (editMode ? t('teamhub', 'Done editing layout') : t('teamhub', 'Edit layout')))"
            @click="$emit('toggle-edit-mode')">
            <template #icon><ViewDashboardEdit :size="ICON_BODY" /></template>
            <template v-if="layoutEnforced && !canEditEnforced">{{ t('teamhub', 'Team preset') }}</template>
            <template v-else-if="layoutEnforced && canEditEnforced">{{ editMode ? t('teamhub', 'Done') : t('teamhub', 'Edit team layout') }}</template>
            <template v-else>{{ editMode ? t('teamhub', 'Done') : t('teamhub', 'Edit layout') }}</template>
        </NcButton>

        <!-- Keep only Reset to template in the team-admin overflow menu.
             Unlock remains in the team-layout draft banner beside Publish
             and Cancel; publishing a team layout is the only lock action. -->
        <NcActions
            v-if="currentView === 'msgstream' && !isMobile && !isTablet && canEditEnforced && hasTeamLayout && !editMode"
            class="teamhub-layout-control-actions"
            :aria-label="t('teamhub', 'Layout controls')"
            :title="t('teamhub', 'Layout controls')"
            :disabled="layoutActionBusy">
            <template #icon><DotsVertical :size="ICON_BODY" /></template>
            <NcActionButton :disabled="layoutActionBusy" @click="$emit('reset-team-layout')">
                <template #icon><Restore :size="ICON_BODY" /></template>
                {{ t('teamhub', 'Reset to template') }}
            </NcActionButton>
        </NcActions>
    </div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { mapState } from 'vuex'
import { NcButton, NcActions, NcActionButton } from '@nextcloud/vue'
import draggable from 'vuedraggable'
import { ICON_BODY, ICON_INLINE } from '../constants/uiTokens.js'
// v4.13.24 — the renderability gate and the tab kinds come from the same
// module that builds the descriptors. This component used to keep its own
// copy of the gate, and the copy is what hid the package tabs.
import { TAB_KIND, isBuiltinTabRenderable } from '../lib/teamTabs.js'

import MessageOutline from 'vue-material-design-icons/MessageOutline.vue'
import Chat from 'vue-material-design-icons/Chat.vue'
import Folder from 'vue-material-design-icons/Folder.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import CardText from 'vue-material-design-icons/CardText.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Puzzle from 'vue-material-design-icons/Puzzle.vue'
import ViewDashboardEdit from 'vue-material-design-icons/ViewDashboardEdit.vue'
import Web from 'vue-material-design-icons/Web.vue'
import OfficeBuildingIcon from 'vue-material-design-icons/OfficeBuilding.vue'
import GavelIcon          from 'vue-material-design-icons/Gavel.vue'
import BookOpenOutline    from 'vue-material-design-icons/BookOpenOutline.vue'
import TimelineIcon       from 'vue-material-design-icons/TimelineCheckOutline.vue'
import WalletOutlineIcon  from 'vue-material-design-icons/WalletOutline.vue'
import ClockOutlineIcon   from 'vue-material-design-icons/ClockOutline.vue'
import ViewGridOutline    from 'vue-material-design-icons/ViewGridOutline.vue'
import ChevronDown        from 'vue-material-design-icons/ChevronDown.vue'
// v3.100.14: replaces the ⠿ Braille character previously used as a
// drag handle. See gui.md § 13 — the Braille glyph renders
// inconsistently across fonts and is not localisable.
import DragVariant        from 'vue-material-design-icons/DragVariant.vue'
import DotsVertical        from 'vue-material-design-icons/DotsVertical.vue'
import Restore             from 'vue-material-design-icons/Restore.vue'
import CalendarClock       from 'vue-material-design-icons/CalendarClock.vue'

/**
 * Icon name → component, for the one `tabIcon()` lookup — v4.13.24.
 *
 * Keyed by the MDI name a descriptor carries, which is also the name an
 * integrating app writes in its registration and the name `developers.md`
 * documents. One table, so adding an icon is adding a line here and the name
 * to that document, rather than an arm in a template chain plus a case in a
 * switch plus an entry in `components`.
 *
 * A name that is not here resolves to `Puzzle`. That is not a fallback for
 * our own tabs — it is the contract for somebody else's: a third-party app
 * picks this string, and a typo in it must cost a generic icon, never a tab
 * that fails to render.
 */
const TAB_ICONS = {
    Chat,
    Folder,
    Calendar,
    CardText,
    OfficeBuilding: OfficeBuildingIcon,
    CalendarClock,
    Gavel: GavelIcon,
    BookOpenOutline,
    TimelineCheckOutline: TimelineIcon,
    WalletOutline: WalletOutlineIcon,
    ClockOutline: ClockOutlineIcon,
    ViewGridOutline,
    Web,
    OpenInNew,
    Puzzle,
}

export default {
    name: 'TeamTabBar',

    // v4.13.24 — only what the template names itself. Every tab icon is
    // resolved through TAB_ICONS and bound as a component object, so the
    // fourteen icons that used to be registered here only to be reached by
    // name from a template arm are no longer part of this list.
    components: {
        NcButton, NcActions, NcActionButton,
        draggable,
        MessageOutline, Plus, ViewDashboardEdit, ChevronDown, DragVariant,
        DotsVertical, Restore,
    },

    props: {
        modelValue: { type: Array, required: true },
        editMode: { type: Boolean, default: false },
        // True when the viewport ≤ 768px or tablet portrait — hides Edit layout button.
        isMobile: { type: Boolean, default: false },
        // True when tablet landscape (≤1200px landscape) — also hides Edit layout button.
        isTablet: { type: Boolean, default: false },
        // Whether the current user can manage (add/edit/delete) links.
        // Controlled by the per-team linkMinLevel setting.
        canManageLinks: { type: Boolean, default: false },
        // v4.13.34 — a team admin has enforced this team's home layout, so
        // there is nothing for a member to arrange. See the Edit layout button.
        layoutEnforced: { type: Boolean, default: false },
        canEditEnforced: { type: Boolean, default: false },
        hasTeamLayout: { type: Boolean, default: false },
        layoutActionBusy: { type: Boolean, default: false },
    },

    emits: ['update:modelValue', 'tab-reorder', 'manage-links', 'toggle-edit-mode', 'show-picker', 'preload', 'reset-team-layout'],

    data() {
        return {
            ICON_BODY,
            ICON_INLINE,
            overflowStartIndex: -1,
            moreMenuOpen: false,
            moreMenuTop: 0,
            moreMenuLeft: 0,
            moreMenuMaxHeight: 600,
        }
    },

    created() {
        this._tabWidthCache = {}
        this._resizeObserver = null
        this._onMoreReposition = () => this.repositionMoreMenu()
    },

    mounted() {
        this._resizeObserver = new ResizeObserver(() => this.calcOverflow())
        this._resizeObserver.observe(this.$el)
        this.$nextTick(() => {
            this.measureTabWidths()
            this.calcOverflow()
        })
        document.addEventListener('click', this._onDocumentClick)
    },

    // v4.20.0: was beforeDestroy(), which Vue 3 never calls, so this cleanup
    // never ran and every listener here outlived the component.
    beforeUnmount() {
        if (this._resizeObserver) {
            this._resizeObserver.disconnect()
            this._resizeObserver = null
        }
        if (this._moreMenuCloseTimer) {
            clearTimeout(this._moreMenuCloseTimer)
            this._moreMenuCloseTimer = null
        }
        document.removeEventListener('click', this._onDocumentClick)
        window.removeEventListener('resize', this._onMoreReposition)
        window.removeEventListener('scroll', this._onMoreReposition, true)
    },

    watch: {
        renderableTabKeys() {
            this.overflowStartIndex = -1
            this.$nextTick(() => {
                this.measureTabWidths()
                this.calcOverflow()
            })
        },
        // The available space changes when an admin preset is loaded,
        // locked/unlocked or while the team-layout editor is open. The bar's
        // outer width does not change, so ResizeObserver alone misses this.
        hasTeamLayout() { this.$nextTick(() => this.calcOverflow()) },
        layoutEnforced() { this.$nextTick(() => this.calcOverflow()) },
        canEditEnforced() { this.$nextTick(() => this.calcOverflow()) },
        editMode() { this.$nextTick(() => this.calcOverflow()) },
        moreMenuOpen(open) {
            if (open) {
                window.addEventListener('resize', this._onMoreReposition)
                window.addEventListener('scroll', this._onMoreReposition, true)
            } else {
                window.removeEventListener('resize', this._onMoreReposition)
                window.removeEventListener('scroll', this._onMoreReposition, true)
            }
        },
    },

    computed: {
        ...mapState(['currentView', 'resources']),

        orderedTabs: {
            get() { return this.modelValue },
            set(val) { this.$emit('update:modelValue', val) },
        },

        /**
         * The subset of orderedTabs that can actually render, in order.
         * vuedraggable v4's #item slot must produce exactly one node per
         * item, so resource-gated tabs (Talk without a token, Files without
         * a path, empty Calendar/Deck) are filtered out here rather than via
         * v-if inside the slot. The getter applies the same conditions the
         * template chain used in Vue 2; the setter maps a drag-reordered
         * renderable list back onto the full orderedTabs (preserving the
         * relative position of any hidden tabs).
         */
        renderableTabs: {
            get() {
                return this.modelValue.filter(tab => this.isTabRenderable(tab))
            },
            set(reordered) {
                // Rebuild the full list: keep non-renderable tabs in place,
                // apply the new order to the renderable ones.
                const renderableKeys = new Set(reordered.map(t => t.key))
                const result = []
                let ri = 0
                for (const tab of this.modelValue) {
                    if (renderableKeys.has(tab.key)) {
                        result.push(reordered[ri++])
                    } else {
                        result.push(tab)
                    }
                }
                this.$emit('update:modelValue', result)
            },
        },

        renderableTabKeys() {
            return this.renderableTabs.map(t => t.key).join(',')
        },

        visibleTabs: {
            get() {
                const renderable = this.renderableTabs
                if (this.overflowStartIndex === -1 || this.overflowStartIndex >= renderable.length) {
                    return renderable
                }
                return renderable.slice(0, this.overflowStartIndex)
            },
            set(reordered) {
                const renderable = this.renderableTabs
                const overflow = this.overflowStartIndex === -1 || this.overflowStartIndex >= renderable.length
                    ? []
                    : renderable.slice(this.overflowStartIndex)
                this.renderableTabs = [...reordered, ...overflow]
            },
        },

        overflowTabs() {
            if (this.overflowStartIndex === -1) return []
            return this.renderableTabs.slice(this.overflowStartIndex)
        },

        hasOverflow() {
            return this.overflowStartIndex > -1 && this.overflowTabs.length > 0
        },

        activeInOverflow() {
            return this.overflowTabs.some(t => this.currentView === t.key)
        },

        moreMenuStyle() {
            return {
                top: this.moreMenuTop + 'px',
                left: this.moreMenuLeft + 'px',
                maxHeight: this.moreMenuMaxHeight + 'px',
            }
        },
    },

    methods: {
        t,

        /**
         * Whether a tab can render. v4.13.24 — one call into the shared gate
         * in `teamTabs.js` instead of a second copy of the rule.
         *
         * The copy that stood here ended in an allowlist: `ext-` or `link-`,
         * everything else false. So every tab kind added after web links was
         * filtered out of the strip and the overflow menu both, and a
         * registered integration package's team tab could not appear however
         * correctly it was registered, enabled, included and shown. The
         * shared gate defaults to true — a descriptor exists because its own
         * gate said yes, and only the four Nextcloud-app embeds need a second
         * look at whether the resource is actually there.
         *
         * @param {object} tab a tab descriptor from orderedTabs
         * @return {boolean}
         */
        isTabRenderable(tab) {
            return isBuiltinTabRenderable(tab.key, this.resources)
        },

        setView(view) {
            // Clicking a tab always returns to that tab's own default, even if a
            // widget had pinned one specific thing into it. SET_VIEW only clears
            // an override when switching to a *different* view, so clicking the
            // already-active tab would otherwise keep showing the pinned item.
            if (view === 'files') {
                this.$store.commit('SET_FILES_EMBED_FILE_URL', null)
            } else if (view === 'calendar') {
                this.$store.commit('SET_CALENDAR_EMBED_EVENT', null)
            } else if (view === 'deck') {
                this.$store.commit('SET_DECK_EMBED_CARD_URL', null)
            }
            this.$store.commit('SET_VIEW', view)
        },

        /**
         * Hovering or focusing a tab is a strong signal it is about to be
         * clicked — start its iframe now. Tabs are identified by their existing
         * `id="tab-{view}"`; anything else on the bar (drag handles, the
         * overflow menu) resolves to no tab and is ignored.
         */
        onTabIntent(event) {
            const tab = event.target?.closest?.('.teamhub-tab')
            const id = tab?.id || ''
            if (id.startsWith('tab-')) {
                this.$emit('preload', id.slice(4))
            }
        },

        onCalendarTabClick() {
            if (this.resources.calendar && this.resources.calendar.length > 1) {
                this.$emit('show-picker', 'calendar')
            } else {
                this.setView('calendar')
            }
        },

        onDeckTabClick() {
            if (this.resources.deck && this.resources.deck.length > 1) {
                this.$emit('show-picker', 'deck')
            } else {
                this.setView('deck')
            }
        },

        /**
         * Move focused tab one position left.
         * Triggered by Left arrow keydown (WCAG 2.5.7).
         */
        /**
         * Move a tab one position left (dir=-1) or right (dir=+1) within the
         * visible (renderable) tab order. WCAG 2.5.7 keyboard alternative to
         * drag reordering. Operates by key on renderableTabs so the index
         * always matches what the user sees, then writes back via the
         * renderableTabs setter (which maps onto the full orderedTabs).
         * @param {object} tab - the focused tab descriptor
         * @param {number} dir - -1 for left, +1 for right
         */
        moveTab(tab, dir) {
            const list = [...this.renderableTabs]
            const index = list.findIndex(t => t.key === tab.key)
            const target = index + dir
            if (index === -1 || target < 0 || target >= list.length) return
            ;[list[index], list[target]] = [list[target], list[index]]
            this.renderableTabs = list
            this.$emit('tab-reorder', this.orderedTabs)
            // Restore focus to the moved tab after Vue re-renders the list
            this.$nextTick(() => {
                const el = document.getElementById('tab-' + tab.key)
                if (el) el.focus()
            })
        },

        appIconUrl(appId) {
            return generateUrl(`/apps/${appId}/img/app.svg`)
        },

        onTabIconError(event, tab) {
            const img = event.target
            if (img.src.endsWith('.svg')) {
                img.src = img.src.replace('.svg', '.png')
            } else {
                img.style.display = 'none'
                tab.appId = null
            }
        },

        measureTabWidths() {
            for (const tab of this.renderableTabs) {
                const el = document.getElementById('tab-' + tab.key)
                if (el) {
                    this._tabWidthCache[tab.key] = el.offsetWidth
                }
            }
        },

        calcOverflow() {
            const renderable = this.renderableTabs
            if (!renderable.length) {
                this.overflowStartIndex = -1
                return
            }
            const bar = this.$el
            if (!bar) return

            const barWidth = bar.clientWidth
            const gap = 4
            const paddingLeft = 44
            const paddingRight = 16

            const homeTab = document.getElementById('tab-msgstream')
            const homeWidth = homeTab ? homeTab.offsetWidth : 0

            let rightWidth = 0
            const addBtn = bar.querySelector('.teamhub-tab-add')
            if (addBtn) rightWidth += addBtn.offsetWidth + gap
            const editBtn = bar.querySelector('.teamhub-edit-layout-btn')
            if (editBtn) rightWidth += editBtn.offsetWidth + gap
            const layoutActions = bar.querySelector('.teamhub-layout-control-actions')
            if (layoutActions) rightWidth += layoutActions.offsetWidth + gap

            const available = barWidth - paddingLeft - paddingRight - homeWidth - gap - rightWidth

            let totalWidth = 0
            for (let i = 0; i < renderable.length; i++) {
                totalWidth += (this._tabWidthCache[renderable[i].key] || 90) + gap
            }
            if (totalWidth <= available) {
                this.overflowStartIndex = -1
                return
            }

            const moreWidth = 100
            const availableWithMore = available - moreWidth - gap

            let used = 0
            for (let i = 0; i < renderable.length; i++) {
                const w = (this._tabWidthCache[renderable[i].key] || 90) + gap
                if (used + w > availableWithMore) {
                    this.overflowStartIndex = Math.max(0, i)
                    return
                }
                used += w
            }
            this.overflowStartIndex = -1
        },

        onOverflowTabClick(tab) {
            // v4.13.24 — closes the menu, then defers to the one click rule
            // the strip uses rather than keeping a second copy of the
            // Calendar/Deck picker branch.
            this.moreMenuOpen = false
            this.onTabClick(tab)
        },

        /**
         * Compute the dropdown's screen coordinates from the trigger's
         * bounding rect. Needed because the menu is position:fixed to escape
         * the tab bar's `overflow: hidden` clip.
         *
         * Horizontal: prefer left-align to the trigger; if the menu would
         * spill past the viewport's right edge, slide left so it sits flush
         * against the edge with an 8px margin.
         *
         * Vertical: cap max-height to the space remaining below the trigger,
         * so the menu never extends past the viewport bottom and so the
         * inline `overflow-y: auto` only engages when content genuinely
         * exceeds that available space.
         */
        repositionMoreMenu() {
            const container = this.$refs.moreContainer
            if (!container) return
            const rect = container.getBoundingClientRect()
            const margin = 8

            this.moreMenuTop = rect.bottom + 4
            this.moreMenuMaxHeight = Math.max(120, window.innerHeight - this.moreMenuTop - margin)

            const menuEl = this.$refs.moreMenuEl
            // offsetWidth is 0 before first display; fall back to min-width.
            const menuWidth = (menuEl && menuEl.offsetWidth) ? menuEl.offsetWidth : 180
            const maxLeft = window.innerWidth - menuWidth - margin
            this.moreMenuLeft = Math.max(margin, Math.min(rect.left, maxLeft))
        },

        openMoreMenu() {
            // Cancel any pending close — the cursor came back over the
            // trigger or onto the menu after starting to leave.
            if (this._moreMenuCloseTimer) {
                clearTimeout(this._moreMenuCloseTimer)
                this._moreMenuCloseTimer = null
            }
            // Show first so the menu has a measurable offsetWidth, then
            // reposition on the next tick now that layout has run.
            this.moreMenuOpen = true
            this.repositionMoreMenu()
            this.$nextTick(() => this.repositionMoreMenu())
        },

        scheduleMoreMenuClose() {
            // 4px gap between the trigger and the position:fixed menu means
            // the cursor briefly leaves both elements while moving from one
            // to the other. A short delay lets the menu's own @mouseenter
            // cancel this close. 180ms is enough for normal cursor speeds
            // without feeling laggy on intentional dismiss.
            if (this._moreMenuCloseTimer) {
                clearTimeout(this._moreMenuCloseTimer)
            }
            this._moreMenuCloseTimer = setTimeout(() => {
                this.moreMenuOpen = false
                this._moreMenuCloseTimer = null
            }, 180)
        },

        toggleMoreMenu() {
            if (this.moreMenuOpen) {
                this.moreMenuOpen = false
            } else {
                this.openMoreMenu()
            }
        },

        /**
         * The icon component for a descriptor — v4.13.24.
         *
         * `getTabIconName()` stood here: a third copy of the key → icon fact,
         * after the descriptor's own `icon` and the icon hard-coded into each
         * arm of the template chain. The three drifted, and a tab kind the
         * switch had never heard of fell through to Puzzle even when its
         * registration named an icon.
         *
         * The descriptor's `icon` is the only source now. It can come from a
         * third-party registration, so an unknown name resolves to Puzzle
         * rather than rendering nothing.
         *
         * @param {object} tab a tab descriptor
         * @return {object} a Vue component
         */
        tabIcon(tab) {
            return TAB_ICONS[tab?.icon] || Puzzle
        },

        /**
         * The owning app's own icon, for a tab an app registered. Null for
         * TeamHub's own tabs and for web links, and null once `onTabIconError`
         * has given up on both the .svg and the .png.
         *
         * @param {object} tab a tab descriptor
         * @return {?string}
         */
        tabAppIconUrl(tab) {
            const ownedByAnApp = tab?.kind === TAB_KIND.EXT || tab?.kind === TAB_KIND.PKG
            return ownedByAnApp && tab.appId ? this.appIconUrl(tab.appId) : null
        },

        /** An absolute web link leaves the page, so it is an anchor, not a tab. */
        isExternalLinkTab(tab) {
            return tab?.kind === TAB_KIND.LINK && !tab.isNcRelative
        },

        /**
         * How many backing resources a tab has, for the number badge. Only
         * Calendar and Deck declare a `countResource`; everything else
         * answers 0 and renders no badge.
         *
         * @param {object} tab a tab descriptor
         * @return {number}
         */
        tabCount(tab) {
            if (!tab?.countResource) return 0
            const bundle = this.resources?.[tab.countResource]
            return Array.isArray(bundle) ? bundle.length : 0
        },

        /**
         * The badge's accessible name. Two tabs have one and the noun differs,
         * so both strings stay spelled out rather than built by concatenation.
         *
         * @param {object} tab a tab descriptor
         * @return {string}
         */
        tabCountLabel(tab) {
            const n = this.tabCount(tab)
            if (tab?.countResource === 'calendar') {
                return t('teamhub', '{n} calendars', { n })
            }
            if (tab?.countResource === 'deck') {
                return t('teamhub', '{n} boards', { n })
            }
            return String(n)
        },

        /**
         * Clicking a tab. Calendar and Deck open a picker when the team has
         * more than one of them; an absolute web link is an anchor and is left
         * to navigate on its own.
         *
         * @param {object} tab a tab descriptor
         */
        onTabClick(tab) {
            if (this.isExternalLinkTab(tab)) return
            if (tab.key === 'calendar') {
                this.onCalendarTabClick()
            } else if (tab.key === 'deck') {
                this.onDeckTabClick()
            } else {
                this.setView(tab.key)
            }
        },

        _onDocumentClick(e) {
            if (this.moreMenuOpen && this.$refs.moreContainer && !this.$refs.moreContainer.contains(e.target)) {
                this.moreMenuOpen = false
            }
        },
    },
}
</script>

<style scoped>
.teamhub-tab-bar {
    display: flex;
    gap: 4px;
    padding: 8px 16px 8px 44px;
    border-bottom: 1px solid var(--color-border);
    background: var(--color-main-background);
    flex-shrink: 0;
    align-items: center;
    flex-wrap: nowrap;
    overflow: hidden;
}

/* Draggable wrapper is invisible to the tab bar's flex layout */
.teamhub-tab-draggable {
    display: contents;
}

/* v3.104.4: retired --border-radius-pill in favour of NcButton's
   --border-radius-element (with --border-radius-large fallback for
   older themes) so the tab shape lines up with the toolbar buttons
   ("Time settings", "Add expense", etc.) sitting immediately below
   the tab bar. Pill tabs next to rounded-rect buttons read as two
   inconsistent shape languages. */
.teamhub-tab {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 8px 16px;
    border-radius: var(--border-radius-element, var(--border-radius-large, 8px));
    border: none;
    background: transparent;
    color: var(--color-text-maxcontrast);
    cursor: pointer;
    font-size: var(--th-font-body);
    font-weight: 500;
    transition: background var(--animation-quick), color var(--animation-quick);
    text-decoration: none;
    white-space: nowrap;
    flex-shrink: 0;
}

.teamhub-tab:hover {
    background: var(--color-background-hover);
    color: var(--color-main-text);
}

.teamhub-tab.active {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

/* Keyboard focus ring (WCAG 2.4.7) */
.teamhub-tab:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

.teamhub-tab--link {
    opacity: 0.85;
    border: 1px solid var(--color-border);
}

/* v3.100.14: was a <span> wrapping the ⠿ Braille character; now an
   MDI DragVariant icon component (size handled via the :size prop, so
   the font-size rule that sized the glyph is gone). */
.teamhub-tab-drag-handle {
    cursor: grab;
    opacity: 0;
    transition: opacity var(--animation-quick);
    line-height: 1;
    color: var(--color-text-maxcontrast);
    user-select: none;
    display: inline-flex;
    align-items: center;
}

.teamhub-tab:hover .teamhub-tab-drag-handle {
    opacity: 0.55;
}

.teamhub-tab-ghost {
    opacity: 0.35;
    background: var(--color-background-hover);
}

.teamhub-tab-dragging {
    cursor: grabbing;
    box-shadow: 0 2px 8px var(--color-box-shadow);
    border-radius: var(--border-radius-element, var(--border-radius-large, 8px));
}

.teamhub-tab-add {
    flex-shrink: 0;
}

.teamhub-tab-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 16px;
    height: 16px;
    padding: 0 4px;
    border-radius: var(--border-radius-element);
    background: var(--color-primary);
    color: var(--color-primary-text);
    font-size: var(--th-font-meta);
    font-weight: 600;
    line-height: 1;
    flex-shrink: 0;
}

.teamhub-tab-app-icon {
    width: 16px;
    height: 16px;
    object-fit: contain;
    flex-shrink: 0;
}

.teamhub-edit-layout-btn {
    flex-shrink: 0;
    margin-inline-start: auto;
    white-space: nowrap;
}
.teamhub-layout-control-actions {
    flex-shrink: 0;
}

/* Overflow "More…" dropdown */
.teamhub-tab-more {
    position: relative;
    flex-shrink: 0;
}

.teamhub-tab-more-trigger .material-design-icon {
    transition: transform var(--animation-quick);
}

.teamhub-tab-more-trigger[aria-expanded="true"] .material-design-icon {
    transform: rotate(180deg);
}

.teamhub-tab-more-menu {
    /* max-height is set inline by repositionMoreMenu() based on viewport
       space below the trigger, so the menu never spills off-screen and
       overflow-y only engages when content genuinely exceeds that space.

       overflow-x is set explicitly: per CSS spec, setting overflow-y to
       a non-visible value while leaving overflow-x at the default
       `visible` promotes overflow-x to `auto` — which produced a
       spurious horizontal scrollbar at the bottom of the menu even
       when content fit. `hidden` keeps the horizontal axis truly
       non-scrolling. */
    position: fixed;
    z-index: 1000;
    min-width: 180px;
    overflow-x: hidden;
    overflow-y: auto;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-element);
    box-shadow: 0 2px 8px var(--color-box-shadow);
    padding: 4px 0;
}

.teamhub-tab-more-item {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 8px 12px;
    border: none;
    background: transparent;
    color: var(--color-main-text);
    cursor: pointer;
    font-size: var(--th-font-body);
    text-decoration: none;
    white-space: nowrap;
}

.teamhub-tab-more-item:hover {
    background: var(--color-background-hover);
}

/* v3.100.14: full-saturation active state per SKILLS.md, matching the
   main tab-bar .teamhub-tab.active treatment (was
   --color-primary-element-light). */
.teamhub-tab-more-item.active {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    font-weight: 600;
}

.teamhub-tab-more-item:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: -2px;
}
</style>
