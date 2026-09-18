<template>
    <div class="whats-happening">
        <div class="whats-happening__main">
            <!-- Header. Vertical stack (title over subtitle), pushed right so
                 the NC sidebar toggle button in the top-left never overlaps
                 the first line. -->
            <!-- v4.5.39 — the Refresh and Filters buttons are gone. Refresh
                 duplicated the browser's own reload for a view that already
                 refetches on mount and on every control change, and Filters
                 only scrolled the rail into view, which is what scrolling is
                 for. `refresh()` itself stays — it is the fetch every control
                 change calls. -->
            <div class="whats-happening__header">
                <div class="whats-happening__header-text">
                    <h2 class="whats-happening__title">{{ t('teamhub', 'What’s new') }}</h2>
                    <span class="whats-happening__subtitle">
                        {{ t('teamhub', 'Stay up to date across all your teams') }}
                    </span>
                </div>
                <!-- v4.5.39 — opens the control drawer. Narrow screens only:
                     above 900px the rail is a permanent sidebar and there is
                     nothing to open. -->
                <NcButton
                    variant="secondary"
                    class="whats-happening__controls-btn"
                    :aria-expanded="railOpen"
                    :aria-label="t('teamhub', 'Feed control')"
                    :title="t('teamhub', 'Feed control')"
                    @click="railOpen = true">
                    <template #icon>
                        <FilterVariant :size="ICON_BODY" />
                    </template>
                </NcButton>
            </div>

            <!-- Source tabs. Counts come from the server and are computed
                 before the active tab narrows anything, so switching tabs
                 never changes the other tabs' numbers. -->
            <!-- A segmented toggle group, not a tablist: there is one region
                 below and these narrow it, so `role="tab"` would promise
                 tabpanels that do not exist. `aria-pressed` is the honest
                 pattern and the one SKILLS.md § "NcButton is the default"
                 names as a raw-<button> carve-out. -->
            <div class="whats-happening__tabs" role="group" :aria-label="t('teamhub', 'Filter by source')">
                <button
                    v-for="tab in visibleTabs"
                    :key="tab"
                    type="button"
                    class="whats-happening__tab"
                    :class="{ 'whats-happening__tab--active': activeTab === tab }"
                    :aria-pressed="activeTab === tab ? 'true' : 'false'"
                    @click="setTab(tab)">
                    {{ feedTabLabel(tab) }}
                    <span class="whats-happening__tab-count">{{ sourceCounts[tab] || 0 }}</span>
                </button>
            </div>

            <!-- v4.9.7 — the OpenProject news source did not answer cleanly:
                 the viewer is not connected, the token was refused, or some
                 projects could not be read. One line; the rest of the feed
                 stands. Shown only while the source is switched on and the
                 tab could show its rows. -->
            <div
                v-if="openProjectNotice && (activeTab === 'all' || activeTab === 'openproject')"
                class="whats-happening__notice"
                role="status"
                aria-live="polite">
                <BriefcaseOutline :size="ICON_BODY" aria-hidden="true" />
                <span>{{ openProjectNotice.text }}</span>
                <a
                    v-if="openProjectNotice.action === 'connect'"
                    class="whats-happening__notice-link"
                    :href="personalSettingsUrl()">
                    {{ t('teamhub', 'Open personal settings') }}
                </a>
            </div>

            <div v-if="loading && !items.length" class="whats-happening__loading">
                <NcLoadingIcon :size="32" />
            </div>

            <NcEmptyContent
                v-else-if="!visibleItems.length"
                :name="emptyTitle"
                :description="emptyBody">
                <template #icon><Rss :size="ICON_HERO" /></template>
            </NcEmptyContent>

            <template v-else>
                <section
                    v-for="group in groupedItems"
                    :key="group.bucket"
                    class="whats-happening__group">
                    <h3 class="whats-happening__group-heading">{{ group.label }}</h3>
                    <ul class="whats-happening__list">
                        <FeedItemCard
                            v-for="item in group.items"
                            :key="itemKey(item)"
                            :item="item"
                            :preview="preview(item.message)"
                            @open-item="openItem"
                            @open-team="openTeam"
                            @open-talk="openTalk"
                            @voted="onVoted"
                            @count-changed="onCountChanged" />
                    </ul>
                </section>

                <div class="whats-happening__footer">
                    <span class="whats-happening__footer-text" aria-live="polite">
                        {{ hasMore
                            ? n('teamhub', '{n} item shown', '{n} items shown', items.length, { n: items.length })
                            : t('teamhub', 'You’ve reached the end') }}
                    </span>
                    <NcButton
                        v-if="hasMore"
                        variant="secondary"
                        :disabled="loadingMore"
                        @click="loadMore">
                        <template v-if="loadingMore" #icon>
                            <NcLoadingIcon :size="ICON_BODY" />
                        </template>
                        {{ t('teamhub', 'Load more') }}
                    </NcButton>
                </div>
            </template>
        </div>

        <!-- Scrim. Only ever visible while the drawer is (it is display:none
             above 900px), so the desktop layout has nothing extra in it. A
             plain div, not a button: it is a click target for dismissal, and
             Escape plus the drawer's own Close button are the accessible ways
             out. -->
        <div
            v-if="railOpen"
            class="whats-happening__scrim"
            @click="railOpen = false" />

        <FeedControlRail
            :value="controls"
            :teams="facets.teams"
            :projects="facets.projects"
            :open-project-available="openProjectAvailable"
            :per-page-options="perPageOptions"
            :saving="savingDefaults"
            :saved-at="savedDefaultsAt"
            :open="railOpen"
            @update="onControlsUpdate"
            @save-default="saveAsDefault"
            @close="railOpen = false" />
    </div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import { showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import {
    NcButton,
    NcLoadingIcon,
    NcEmptyContent,
} from '@nextcloud/vue'
import FilterVariant from 'vue-material-design-icons/FilterVariant.vue'
import Rss from 'vue-material-design-icons/Rss.vue'
import BriefcaseOutline from 'vue-material-design-icons/BriefcaseOutline.vue'
import FeedItemCard from './feed/FeedItemCard.vue'
import FeedControlRail from './feed/FeedControlRail.vue'
import { ICON_BODY, ICON_HERO } from '../constants/uiTokens.js'
import { mentionsUser } from '../lib/mentions.js'
import { personalSettingsUrl } from '../lib/openProject.js'
import {
    FEED_TABS,
    FEED_TAB_ALL,
    FEED_TAB_DECISIONS,
    FEED_TAB_MENTIONS,
    FEED_TAB_OPENPROJECT,
    FEED_TAB_PUBLIC,
    FEED_TAB_TALK,
    FEED_TAB_TEAM,
    feedTabLabel,
    FEED_DATE_BUCKETS,
    feedDateBucket,
    feedBucketLabel,
    feedItemKind,
    dedupeMirroredNews,
    openProjectSourceNotice,
    resolvePeriodRange,
} from '../constants/feed.js'

/**
 * localStorage key for the Feed control state. Kept as the *session* store —
 * the per-user server-side copy behind "Save as default" (v4.5.26) is what
 * follows the user to another browser. Two layers on purpose: fiddling with a
 * filter should not silently rewrite the default you chose deliberately.
 */
const STORAGE_KEY = 'teamhub.feedControls'

const PER_PAGE_OPTIONS = [20, 50, 100]

/** The shape every control-state path starts from. */
function defaultControls() {
    return {
        includeTeam: true,
        includePublic: true,
        includeTalk: true,
        // v4.5.31 — `includeSystem` is gone with its switch. The server still
        // accepts the parameter for API callers; the frontend never sends it,
        // so system posts always show. `teamhub_messages.is_system` stays —
        // it is the only thing that can tell an auto-post apart, and a future
        // caller may want it.
        // v4.5.28 — an inclusion switch like its neighbours. Was `mentionsOnly`
        // (a lens that hid everything else), which the Mentions tab already
        // does and does better.
        includeMentions: true,
        // v4.5.29 — its own Show row. Only open decisions are listed; the
        // server enforces that, this is just the switch.
        includeDecisions: true,
        // v4.9.7 — OpenProject news: its Show switch and the projects to
        // keep ([] = all). The server validates the list to a bounded shape.
        includeOpenProject: true,
        projectIds: [],
        period: 'all',
        customFrom: 0,
        customTo: 0,
        teamIds: [],
        perPage: 20,
    }
}

export default {
    name: 'WhatsHappeningView',
    components: {
        NcButton,
        NcLoadingIcon,
        NcEmptyContent,
        FeedItemCard,
        FeedControlRail,
        FilterVariant,
        Rss,
        BriefcaseOutline,
    },
    emits: ['open-team', 'open-team-talk', 'open-item'],

    data() {
        return {
            items: [],
            loading: false,
            loadingMore: false,
            hasMore: false,
            total: 0,
            sourceCounts: {},
            facets: { teams: [], projects: [] },
            /** v4.9.7 — the server's per-source health block. */
            sources: {},
            activeTab: FEED_TAB_ALL,
            controls: defaultControls(),
            savingDefaults: false,
            savedDefaultsAt: 0,
            // v4.5.39 — the control drawer, on narrow screens only. Above
            // 900px the rail is a permanent sidebar and this does nothing, so
            // there is no viewport listener to keep the two in step.
            railOpen: false,
            tabs: FEED_TABS,
            perPageOptions: PER_PAGE_OPTIONS,
            ICON_BODY,
            ICON_HERO,
        }
    },

    computed: {
        /**
         * The source tab is applied client-side, on the already-fetched page.
         *
         * It has to be: the tab counts are computed server-side across every
         * source *before* any tab narrows them, so pushing the tab into the
         * query would make each tab's count depend on which tab is open — the
         * exact bug My Work's "Completed this week showed 5 but said nothing
         * finished" turned out to be (§4.5.25). The SHOW switches in the rail
         * do go to the server, because they change what the counts are counting.
         */
        visibleItems() {
            if (this.activeTab === FEED_TAB_ALL) {
                // v4.9.9 — the one tab that lists both the live news card
                // and the message mirrored from it shows the item once.
                return dedupeMirroredNews(this.items)
            }
            return this.items.filter((item) => {
                const kind = feedItemKind(item)
                switch (this.activeTab) {
                case FEED_TAB_TALK:
                    return kind === 'talk-poll' || kind === 'talk-thread'
                case FEED_TAB_PUBLIC:
                    return item.source === 'public'
                case FEED_TAB_TEAM:
                    return item.source === 'team'
                case FEED_TAB_DECISIONS:
                    // The server has already dropped every decision that is no
                    // longer open, so being a decision at all is enough here.
                    return kind === 'decision'
                case FEED_TAB_MENTIONS:
                    return this.mentionsMe(item)
                case FEED_TAB_OPENPROJECT:
                    return kind === 'openproject'
                default:
                    return true
                }
            })
        },

        /**
         * v4.9.7 — whether the OpenProject source exists on this instance:
         * the server reports it under `sources.openproject` on every load,
         * with `unavailable` when the integration app is absent. Until the
         * first load has answered, assume it does not, so the rail never
         * flashes a switch that then disappears.
         */
        openProjectAvailable() {
            const state = this.sources?.openproject?.state
            return !!state && state !== 'unavailable'
        },

        /** v4.9.7 — the notice above the list, or null. */
        openProjectNotice() {
            if (!this.controls.includeOpenProject) {
                return null
            }
            return openProjectSourceNotice(this.sources?.openproject)
        },

        /**
         * The tabs on screen: OpenProject only when the source exists here.
         * A tab that always reads 0 because the integration is not installed
         * is a question nobody asked.
         */
        visibleTabs() {
            return this.tabs.filter((tab) => tab !== FEED_TAB_OPENPROJECT || this.openProjectAvailable)
        },

        groupedItems() {
            const buckets = {}
            for (const b of FEED_DATE_BUCKETS) buckets[b] = []
            for (const item of this.visibleItems) {
                buckets[feedDateBucket(item)].push(item)
            }
            return FEED_DATE_BUCKETS
                .filter((b) => buckets[b].length)
                .map((b) => ({ bucket: b, label: feedBucketLabel(b), items: buckets[b] }))
        },

        /**
         * Anything moved off its default. Read by the empty state, so "nothing
         * here" can say whether a filter is the reason (v4.5.39 — it used to
         * label the Filters button too, which is gone).
         */
        activeFilterCount() {
            const d = defaultControls()
            let count = 0
            for (const key of ['includeTeam', 'includePublic', 'includeTalk', 'includeMentions', 'includeDecisions', 'includeOpenProject']) {
                if (this.controls[key] !== d[key]) count++
            }
            if (this.controls.period !== 'all') count++
            if (this.controls.teamIds.length) count++
            if (this.controls.projectIds.length) count++
            return count
        },

        allSourcesOff() {
            return !this.controls.includeTeam
                && !this.controls.includePublic
                && !this.controls.includeTalk
        },

        /** The page has rows, but the chosen source tab hides all of them. */
        emptiedByTab() {
            return this.items.length > 0 && this.visibleItems.length === 0
        },

        emptyTitle() {
            if (this.allSourcesOff) return t('teamhub', 'Nothing selected')
            if (this.emptiedByTab) {
                return t('teamhub', 'Nothing under {tab}', { tab: feedTabLabel(this.activeTab) })
            }
            if (this.activeFilterCount) return t('teamhub', 'Nothing matches these filters')
            return t('teamhub', 'Nothing to show yet')
        },

        emptyBody() {
            if (this.allSourcesOff) {
                return t('teamhub', 'Turn on at least one row under Show in the Feed control panel.')
            }
            if (this.emptiedByTab) {
                return t('teamhub', 'Pick All to see everything that is here.')
            }
            if (this.activeFilterCount) {
                return t('teamhub', 'Try widening the period, or clearing a team or type selection.')
            }
            return t('teamhub', 'When your teams post messages — or when someone publishes a public message — it will appear here.')
        },
    },

    mounted() {
        this.hydrate()
        // Escape closes the control drawer. On the document rather than on the
        // drawer, because the trigger keeps focus after opening and a keydown
        // there would never reach a listener bound inside the panel.
        document.addEventListener('keydown', this.onKeydown)
    },

    // `beforeUnmount`, not `beforeDestroy` — Vue 3 never calls the Vue 2 name,
    // and the listener would outlive the view (the 4.5.27 App.vue bug).
    beforeUnmount() {
        document.removeEventListener('keydown', this.onKeydown)
    },

    methods: {
        t,
        n,
        feedTabLabel,
        personalSettingsUrl,

        onKeydown(event) {
            if (event.key === 'Escape' && this.railOpen) {
                this.railOpen = false
            }
        },

        // Keys for Talk items must not collide with message ids — an id of 1
        // could exist in both teamhub_messages and talk_polls.
        itemKey(item) {
            if (item.source === 'talk-poll') return 'poll-' + item.id
            if (item.source === 'talk-thread') return 'thread-' + item.id
            // v4.9.7 — an OpenProject id is already a string keyed on the
            // connection, project and work package.
            if (item.source === 'openproject') return 'op-' + item.id
            return 'msg-' + item.id
        },

        /**
         * Load the saved defaults, then let this browser's session state win
         * where it exists, then fetch once.
         *
         * Order matters: the server copy is the user's deliberate default and
         * the localStorage copy is where they were last looking, so the second
         * is the more specific answer to "what did I want to see".
         */
        async hydrate() {
            await this.loadSavedDefaults()
            this.hydrateFromStorage()
            await this.refresh()
        },

        async loadSavedDefaults() {
            try {
                const { data } = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/messages/feed/preferences'),
                )
                if (data && typeof data === 'object') {
                    this.controls = { ...defaultControls(), ...this.pickControlKeys(data) }
                }
            } catch (e) {
                // Defaults are a perfectly good starting point; a failed
                // preference read must never keep the feed off the screen.
            }
        },

        /**
         * Take only the keys the control state owns. A response with an extra
         * field (a newer server, a hand-edited preference) must not be able to
         * inject something the rail never renders and the query then sends on.
         */
        pickControlKeys(source) {
            const out = {}
            for (const key of Object.keys(defaultControls())) {
                if (source[key] !== undefined) out[key] = source[key]
            }
            if (!Array.isArray(out.teamIds)) delete out.teamIds
            if (!PER_PAGE_OPTIONS.includes(out.perPage)) delete out.perPage
            // v4.9.7 — the OpenProject project list: digits only.
            if (!Array.isArray(out.projectIds)) {
                delete out.projectIds
            } else {
                out.projectIds = out.projectIds.map(String).filter((id) => /^[0-9]+$/.test(id))
            }
            return out
        },

        hydrateFromStorage() {
            try {
                const raw = window.localStorage.getItem(STORAGE_KEY)
                if (!raw) return
                const parsed = JSON.parse(raw)
                if (parsed && typeof parsed === 'object') {
                    this.controls = { ...this.controls, ...this.pickControlKeys(parsed) }
                    if (FEED_TABS.includes(parsed.activeTab)) {
                        this.activeTab = parsed.activeTab
                    }
                }
            } catch (e) {
                // localStorage unavailable (private mode, disabled) or JSON
                // corrupt — fall through to whatever we already have.
            }
        },

        persist() {
            try {
                window.localStorage.setItem(STORAGE_KEY, JSON.stringify({
                    ...this.controls,
                    activeTab: this.activeTab,
                }))
            } catch (e) {
                // Non-fatal — quota exceeded, storage disabled, etc.
            }
        },

        setTab(tab) {
            if (this.activeTab === tab) return
            this.activeTab = tab
            // Client-side filter over the fetched page — no refetch, so the
            // counts stay put and the switch is instant.
            this.persist()
        },

        onControlsUpdate(patch) {
            this.controls = { ...this.controls, ...patch }
            this.persist()

            // A custom range with only one end filled in is a half-typed date,
            // not a query — wait for the other end rather than flashing an
            // empty feed between the two keystrokes.
            if (this.controls.period === 'custom'
                && !this.controls.customFrom
                && !this.controls.customTo) {
                return
            }
            this.refresh()
        },

        /**
         * Query parameters for the current control state.
         *
         * The period is resolved to a from/to pair **here**, in the browser:
         * "today" means the viewer's today, and the server's timezone is not
         * theirs. Same move the My Work snooze presets made in 4.5.24.
         */
        buildParams(offset) {
            const { from, to } = resolvePeriodRange(
                this.controls.period,
                this.controls.customFrom,
                this.controls.customTo,
            )
            const params = {
                includeTeam: this.controls.includeTeam ? 1 : 0,
                includePublic: this.controls.includePublic ? 1 : 0,
                includeTalk: this.controls.includeTalk ? 1 : 0,
                includeMentions: this.controls.includeMentions ? 1 : 0,
                includeDecisions: this.controls.includeDecisions ? 1 : 0,
                includeOpenProject: this.controls.includeOpenProject ? 1 : 0,
                limit: this.controls.perPage,
                offset,
            }
            if (from) params.from = from
            if (to) params.to = to
            if (this.controls.teamIds.length) params.teamIds = this.controls.teamIds.join(',')
            if (this.controls.projectIds.length) params.projectIds = this.controls.projectIds.join(',')
            return params
        },

        async refresh() {
            if (this.loading) return
            this.loading = true
            try {
                const { data } = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/messages/feed'),
                    { params: this.buildParams(0) },
                )
                this.applyPage(data, false)
            } catch (e) {
                this.reportError(e)
            } finally {
                this.loading = false
            }
        },

        async loadMore() {
            if (this.loadingMore || !this.hasMore) return
            this.loadingMore = true
            try {
                const { data } = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/messages/feed'),
                    { params: this.buildParams(this.items.length) },
                )
                this.applyPage(data, true)
            } catch (e) {
                this.reportError(e)
            } finally {
                this.loadingMore = false
            }
        },

        applyPage(data, append) {
            const incoming = Array.isArray(data?.items) ? data.items : []
            if (append) {
                // De-duplicate on the way in: a message posted between the two
                // requests shifts the offset window by one and would otherwise
                // arrive twice with the same key.
                const seen = new Set(this.items.map((i) => this.itemKey(i)))
                this.items = this.items.concat(
                    incoming.filter((i) => !seen.has(this.itemKey(i))),
                )
            } else {
                this.items = incoming
            }
            this.hasMore = !!data?.hasMore
            this.total = Number.isFinite(data?.total) ? data.total : this.items.length
            this.sourceCounts = data?.sourceCounts || {}
            // v4.5.29 — teams only. The rail's TYPES section is gone, so its
            // facet has no consumer; the server still computes it for API
            // callers.
            this.facets = { teams: data?.facets?.teams || [], projects: data?.facets?.projects || [] }
            // v4.9.7 — per-source health, for the notice and the rail switch.
            this.sources = data?.sources && typeof data.sources === 'object' ? data.sources : {}
            // A remembered OpenProject tab on an instance without the source
            // would show an empty list under a tab that is no longer drawn.
            if (this.activeTab === FEED_TAB_OPENPROJECT && !this.openProjectAvailable) {
                this.activeTab = FEED_TAB_ALL
            }
        },

        reportError(e) {
            // v4.3.0 — the endpoint returns 403 + {licenseGate:true} when the
            // instance loses its license. Surface a license-specific message
            // instead of a generic error so the admin knows where to look.
            if (e?.response?.status === 403 && e?.response?.data?.licenseGate) {
                showError(t('teamhub', 'What’s new requires an active TeamHub license.'))
            } else {
                showError(t('teamhub', 'Failed to load feed'))
            }
        },

        async saveAsDefault() {
            if (this.savingDefaults) return
            this.savingDefaults = true
            try {
                const { data } = await axios.put(
                    generateUrl('/apps/teamhub/api/v1/messages/feed/preferences'),
                    { ...this.controls },
                )
                // Adopt what the server stored rather than what we sent — it
                // validates every field, and showing the user something it
                // rejected would be a lie about their own default.
                if (data && typeof data === 'object') {
                    this.controls = { ...defaultControls(), ...this.pickControlKeys(data) }
                    this.persist()
                }
                this.savedDefaultsAt = Date.now()
            } catch (e) {
                showError(t('teamhub', 'Could not save your feed defaults.'))
            } finally {
                this.savingDefaults = false
            }
        },

        /**
         * Shares `src/lib/mentions.js` with the message renderer, and mirrors
         * `MessageService::parseMentionCandidates()` — the three have to agree
         * or the Mentions tab, the Mentions-only switch and the highlighted
         * text in the message body all disagree about who was mentioned.
         */
        mentionsMe(item) {
            return mentionsUser(item.message, this.currentUid())
        },

        currentUid() {
            return getCurrentUser()?.uid
                || this.$store?.state?.currentUser?.uid
                || ''
        },

        /**
         * v4.5.26 — "Open" lands on the item, not just its team. App.vue picks
         * the destination from what the row is: a decision goes to the
         * Decisions tab, a Talk row to the Talk tab, anything else to the page
         * of the stream that holds it.
         */
        openItem(item) {
            if (item.team_id) this.$emit('open-item', item)
        },

        openTeam(item) {
            if (item.team_id) this.$emit('open-team', item.team_id)
        },

        openTalk(item) {
            // The router in App.vue's onOpenTeamTalk selects the team and then
            // sets currentView='talk'. A Talk item with no team_id (a room
            // connected to a circle we couldn't attribute) has nowhere to go.
            if (item.team_id) this.$emit('open-team-talk', item.team_id)
        },

        onVoted({ item, myVotes, votes, numVoters, status }) {
            const target = this.items.find((i) => this.itemKey(i) === this.itemKey(item))
            if (!target) return
            target.my_votes = myVotes
            // Applied in place from the vote response. A full refresh here
            // would collapse every expanded thread on the page to move one
            // percentage. When Talk's schema didn't answer, the tallies stay
            // as they were rather than being replaced with a guess.
            if (votes && typeof votes === 'object') target.votes = votes
            if (Number.isFinite(numVoters)) target.num_voters = numVoters
            if (Number.isFinite(status)) target.status = status
        },

        onCountChanged({ item, count }) {
            const target = this.items.find((i) => this.itemKey(i) === this.itemKey(item))
            if (!target) return
            if (item.source === 'talk-thread') {
                target.num_replies = count
            } else {
                target.comment_count = count
            }
        },

        /**
         * Cheap body-preview extractor. Strips markdown image / link wrappers,
         * code fences, HTML tags, and collapses whitespace, then truncates. No
         * sanitizer needed — output goes into a text node via `{{ }}`, so Vue
         * auto-escapes.
         */
        preview(body) {
            if (!body) return ''
            let s = String(body)
            s = s.replace(/```[\s\S]*?```/g, ' ')
            s = s.replace(/`[^`]*`/g, ' ')
            s = s.replace(/!\[[^\]]*\]\([^)]*\)/g, ' ')
            s = s.replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
            s = s.replace(/<[^>]*>/g, ' ')
            s = s.replace(/\s+/g, ' ').trim()
            if (s.length > 240) s = s.slice(0, 240).trimEnd() + '…'
            return s
        },
    },
}
</script>

<style scoped lang="scss">
.whats-happening {
    display: flex;
    gap: 20px;
    padding: 20px 24px 28px 48px;
    align-items: flex-start;
    min-height: 100%;
    box-sizing: border-box;
    /* v4.5.27 — same canvas as a team page (.teamhub-home-view) and My Work.
       The cards and the control rail are --color-main-background with a
       border, so on a same-coloured background the borders were carrying the
       whole layout; on grey they read as cards. */
    background: var(--color-background-dark);

    /* v4.5.39 — the 48px left padding is clearance for NC's sidebar-toggle
       button, which only overlaps the *first* row. Applying it to the whole
       view cost a phone a seventh of its width down the entire page. The
       clearance moves onto the header alone, exactly as My Work does it, and
       the content gets ~93% of a 390px screen. */
    @media (max-width: 900px) {
        flex-direction: column;
        padding: 16px 14px 24px 14px;
    }
}

.whats-happening__main {
    flex: 1 1 auto;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.whats-happening__header {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    // The 48px clearance for NC's sidebar-toggle button moved to the view's
    // own padding in v4.5.27, so every row shares one left edge — the same
    // arrangement My Work uses.
}

.whats-happening__header-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex: 1 1 auto;
    min-width: 0;
}

.whats-happening__title {
    font-size: var(--th-font-heading-lg, 20px);
    font-weight: var(--th-font-weight-bold, 700);
    margin: 0;
    line-height: var(--th-line-height-tight, 1.2);
}

.whats-happening__subtitle {
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta, 12px);
    line-height: var(--th-line-height-body, 1.4);
}

// The rail is a permanent sidebar above 900px, so there is no drawer to open
// and no scrim to dim. Both are mobile-only chrome.
.whats-happening__controls-btn,
.whats-happening__scrim {
    display: none;
}

@media (max-width: 900px) {
    .whats-happening__controls-btn {
        display: inline-flex;
        flex: 0 0 auto;
    }

    .whats-happening__scrim {
        display: block;
        position: fixed;
        // Matches the drawer: both start below NC's own header rather than
        // under it.
        inset: var(--header-height, 50px) 0 0 0;
        z-index: 1000; // under the drawer, over everything else
        background: rgba(0, 0, 0, 0.32);
    }

    // Clearance for NC's sidebar-toggle button, which sits over this row only.
    .whats-happening__header {
        padding-left: 44px;
    }
}

// ── Tabs ─────────────────────────────────────────────────────────────────
.whats-happening__tabs {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

// Raw <button> with role="tab": a segmented tab bar, which SKILLS.md
// § "NcButton is the default" lists as a carve-out.
.whats-happening__tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border: 1px solid var(--color-border);
    border-radius: var(--th-radius-pill, 999px);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: var(--th-font-meta, 12px);
    font-weight: var(--th-font-weight-medium, 500);
    cursor: pointer;

    &:hover {
        background: var(--color-background-hover);
    }

    // Split from :hover — grouping them silences the keyboard focus ring,
    // which is the trap SKILLS.md § Focus visibility documents.
    &:focus-visible {
        background: var(--color-background-hover);
        outline: 2px solid var(--color-primary-element);
        outline-offset: 2px;
    }
}

// Selection is carried by the fill AND the border AND the bolder count chip,
// so it does not rest on colour alone (WCAG 1.4.1).
.whats-happening__tab--active {
    background: var(--color-primary-element);
    border-color: var(--color-primary-element);
    color: var(--color-primary-element-text);
    font-weight: var(--th-font-weight-semibold, 600);

    &:hover,
    &:focus-visible {
        background: var(--color-primary-element-hover, var(--color-primary-element));
    }
}

.whats-happening__tab-count {
    font-variant-numeric: tabular-nums;
    opacity: 0.85;
    font-weight: var(--th-font-weight-semibold, 600);
}

// ── Groups ───────────────────────────────────────────────────────────────
.whats-happening__group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

// v4.5.27 — same weight and colour as a team widget header
// (.teamhub-widget-title) and My Work's section titles, so a heading means
// the same thing on all three pages. Kept one step down in size because these
// separate days rather than titling a panel.
.whats-happening__group-heading {
    margin: 4px 0 0;
    font-size: var(--th-font-heading, 16px);
    font-weight: var(--th-font-weight-semibold, 600);
    color: var(--color-primary-element);
}

.whats-happening__list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.whats-happening__loading {
    display: flex;
    justify-content: center;
    padding: 32px 0;
}

/* v4.9.7 — the OpenProject source notice: informational, one line, the
   same shape as My Work's provider notices. */
.whats-happening__notice {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 12px;
    padding: 8px 12px;
    border: 1px solid var(--color-border);
    border-radius: var(--th-radius-card, var(--border-radius-large));
    background: var(--color-background-hover);
    color: var(--color-main-text);
    font-size: var(--th-font-meta, 12px);
}

.whats-happening__notice-link {
    color: var(--color-primary-element);
    font-weight: var(--th-font-weight-semibold, 600);
    text-decoration: underline;

    &:focus-visible {
        outline: 2px solid var(--color-primary-element);
        outline-offset: 2px;
    }
}

.whats-happening__footer {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 8px 0 16px;
    flex-wrap: wrap;
}

.whats-happening__footer-text {
    font-size: var(--th-font-meta, 12px);
    color: var(--color-text-maxcontrast);
}
</style>
