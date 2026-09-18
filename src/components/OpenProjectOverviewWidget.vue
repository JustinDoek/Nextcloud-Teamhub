<template>
    <div class="th-op" :aria-busy="loading">
        <!-- First fetch — nothing to show yet -->
        <div v-if="state === 'loading'" class="th-op__loading">
            {{ t('teamhub', 'Loading OpenProject project') }}
        </div>

        <!-- Defensive: the grid hides the widget for unlinked teams -->
        <div v-else-if="state === 'unlinked'" class="th-op__empty">
            {{ t('teamhub', 'This team is not linked to an OpenProject project.') }}
        </div>

        <!-- The connection between Nextcloud and OpenProject is broken
             (integration unconfigured or incompatible, OpenProject
             unreachable, the link made against another host): one picture,
             one sentence, and this widget only — the Upcoming tasks and
             Upcoming events widgets say nothing (Justin, 2026-09-14). The
             exact reason rides in the title for whoever hovers; the header
             menu's Refresh is the retry. -->
        <NcEmptyContent
            v-else-if="state === 'error' && isBrokenConnectionCode(error.code)"
            class="th-op__broken"
            role="status"
            :title="error.message"
            :name="t('teamhub', 'OpenProject connection lost')"
            :description="t('teamhub', 'Contact your administrator.')">
            <template #icon>
                <LanDisconnect :size="ICON_HERO" />
            </template>
        </NcEmptyContent>

        <!-- A failure the viewer can act on (connect or reconnect their
             account) or one about the project itself (no access, gone) -->
        <div v-else-if="state === 'error'" class="th-op__error" role="alert">
            <p class="th-op__error-text">{{ error.message }}</p>
            <div v-if="isConnectionCode(error.code)" class="th-op__error-actions">
                <NcButton
                    variant="primary"
                    :href="personalSettingsUrl()">
                    {{ t('teamhub', 'Connect OpenProject account') }}
                </NcButton>
            </div>
        </div>

        <template v-else>
            <!-- A refresh failed; the previous payload stays on screen -->
            <div v-if="state === 'stale-error'" class="th-op__banner" role="alert">
                {{ t('teamhub', 'Could not refresh: {error}', { error: error.message }) }}
            </div>

            <!-- Project head -->
            <div class="th-op__head">
                <a
                    v-if="project.url"
                    class="th-op__name"
                    :href="project.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    :title="opensInOpenProject"
                    :aria-label="t('teamhub', 'Open {name} in OpenProject', { name: project.name })">
                    <span class="th-op__name-text">{{ project.name }}</span>
                    <OpenInNew :size="ICON_INLINE" aria-hidden="true" class="th-op__ext" />
                </a>
                <span v-else class="th-op__name th-op__name-text">{{ project.name }}</span>
                <span
                    v-if="project.status"
                    class="th-op__chip"
                    :class="`th-op__chip--${projectStatusTone(project.status.code)}`">
                    {{ project.status.label }}
                </span>
            </div>
            <div v-if="project.identifier" class="th-op__meta">{{ project.identifier }}</div>
            <p v-if="project.description" class="th-op__desc">{{ project.description }}</p>
            <p v-if="project.statusExplanation" class="th-op__desc th-op__desc--status">{{ project.statusExplanation }}</p>

            <!-- Counts. A null count renders as an em dash with a title,
                 never as 0 — "we could not read it" is not "there are none". -->
            <ul class="th-op__tiles" role="list">
                <li class="th-op-tile" :title="countTitle(counts.open)">
                    <span class="th-op-tile__value">{{ countText(counts.open) }}</span>
                    <!-- TRANSLATORS: count tile label — open work packages in the OpenProject project -->
                    <span class="th-op-tile__label">{{ t('teamhub', 'Open') }}</span>
                </li>
                <li class="th-op-tile" :class="{ 'th-op-tile--alert': counts.overdue > 0 }" :title="countTitle(counts.overdue)">
                    <span class="th-op-tile__value">{{ countText(counts.overdue) }}</span>
                    <!-- TRANSLATORS: count tile label — work packages past their due date -->
                    <span class="th-op-tile__label">{{ t('teamhub', 'Overdue') }}</span>
                </li>
                <li class="th-op-tile" :class="{ 'th-op-tile--warn': counts.dueSoon > 0 }" :title="countTitle(counts.dueSoon)">
                    <span class="th-op-tile__value">{{ countText(counts.dueSoon) }}</span>
                    <span class="th-op-tile__label">{{ n('teamhub', 'Due in {n} day', 'Due in {n} days', payload.dueSoonDays, { n: payload.dueSoonDays }) }}</span>
                </li>
            </ul>

            <!-- v4.9.7 — your attention: the viewer's own work in this
                 project, from the My Work provider's read over this one team
                 (no second data path), plus how much changed this week. Only
                 drawn when there is something to say; a viewer with nothing
                 due and no changes sees the project block as before. -->
            <div v-if="attentionRows.length" class="th-op__attention" :aria-label="t('teamhub', 'Your attention')">
                <span class="th-op__attention-title">{{ t('teamhub', 'Your attention') }}</span>
                <ul class="th-op__attention-list" role="list">
                    <li
                        v-for="row in attentionRows"
                        :key="row.key"
                        class="th-op__attention-row"
                        :class="row.tone ? `th-op__attention-row--${row.tone}` : null">
                        <component :is="row.icon" :size="ICON_INLINE" aria-hidden="true" />
                        <a
                            v-if="row.url"
                            class="th-op__link"
                            :href="row.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            :title="opensInOpenProject">{{ row.text }}</a>
                        <span v-else>{{ row.text }}</span>
                    </li>
                </ul>
            </div>

            <!-- Next milestone -->
            <div v-if="payload.nextMilestone" class="th-op__milestone" :class="{ 'th-op__milestone--overdue': payload.nextMilestone.overdue }">
                <FlagOutline :size="ICON_BODY" aria-hidden="true" class="th-op__milestone-icon" />
                <div class="th-op__milestone-body">
                    <span class="th-op__milestone-label">
                        {{ payload.nextMilestone.overdue ? t('teamhub', 'Overdue milestone') : t('teamhub', 'Next milestone') }}
                    </span>
                    <a
                        v-if="payload.nextMilestone.url"
                        class="th-op__link"
                        :href="payload.nextMilestone.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        :title="opensInOpenProject">{{ payload.nextMilestone.subject }}</a>
                    <span v-else>{{ payload.nextMilestone.subject }}</span>
                    <span v-if="payload.nextMilestone.date" class="th-op__milestone-date">{{ formatIsoDate(payload.nextMilestone.date) }}</span>
                </div>
            </div>

            <!-- Recently completed -->
            <div v-if="payload.recentlyCompleted && payload.recentlyCompleted.length > 0" class="th-op__section">
                <h3 class="th-op__section-title">{{ t('teamhub', 'Recently completed') }}</h3>
                <ul class="th-op__list" role="list">
                    <li v-for="wp in payload.recentlyCompleted" :key="wp.id" class="th-op__item">
                        <a
                            v-if="wp.url"
                            class="th-op__link th-op__item-subject"
                            :href="wp.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            :title="opensInOpenProject">{{ wp.subject }}</a>
                        <span v-else class="th-op__item-subject">{{ wp.subject }}</span>
                        <span class="th-op__item-meta">
                            <span v-if="wp.type">{{ wp.type }}</span>
                            <span v-if="wp.updatedAt">{{ formatDateTime(wp.updatedAt) }}</span>
                        </span>
                    </li>
                </ul>
            </div>

            <!-- v4.9.5 — the quick actions (Open project, Work packages, New
                 work package, Project files, Refresh) moved to the widget
                 header's action menu, which the grid owns; this component
                 reports the links it has through the `actions` event. -->
            <div class="th-op__footer">
                <span class="th-op__retrieved">
                    {{ t('teamhub', 'Retrieved {time}', { time: formatDateTime(payload.retrievedAt * 1000) }) }}
                </span>
            </div>
        </template>
    </div>
</template>

<script>
import { mapState } from 'vuex'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import FlagOutline from 'vue-material-design-icons/FlagOutline.vue'
// The broken-connection picture.
import LanDisconnect from 'vue-material-design-icons/LanDisconnect.vue'
// v4.9.7 — the attention rows' glyphs.
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import History from 'vue-material-design-icons/History.vue'
import { ICON_INLINE, ICON_BODY, ICON_HERO } from '../constants/uiTokens.js'
import { formatDateTime, formatIsoDate } from '../lib/localDate.js'
import {
    classifyError, widgetState, projectStatusTone, personalSettingsUrl,
    isConnectionCode, isBrokenConnectionCode,
} from '../lib/openProject.js'

/**
 * OpenProjectOverviewWidget (v4.9.3, OpenProject Phase 1).
 *
 * The project cockpit ("Project info" since v4.9.5): name, status,
 * description excerpt, three counts, the next milestone and recently
 * completed work. The quick actions into OpenProject (Open project, Work
 * packages, New work package, Project files, Refresh) live in the widget
 * header's action menu since v4.9.5 — the grid owns that header, so this
 * component emits `actions` with the links it currently has, and the grid
 * calls `refresh()`. Rendered by TeamWidgetGrid / MobileWidgetView only when the
 * team is of the OpenProject template and linked (gate in
 * src/lib/activeWidgets.js). The link itself is made once, in the creation
 * wizard, so there is no "change connection" action here.
 *
 * Fetches GET /api/v1/teams/{teamId}/openproject/overview as the viewer on
 * mount, on team change, when the link changes, and when the tab regains
 * focus (same no-polling pattern as ProjectHealthWidget). The backend caches
 * per user for five minutes; "Refresh" asks it to bypass that, subject to
 * its own cooldown, so the footer time is when OpenProject was really asked.
 *
 * Every OpenProject failure arrives as a `code`; what is shown is chosen
 * from the code in src/lib/openProject.js, never from the message text: a
 * broken connection (the administrator's to fix) is one picture and one
 * sentence, a member's own missing connection is the sentence with the
 * connect button, and a project problem is its sentence.
 */
export default {
    name: 'OpenProjectOverviewWidget',

    components: {
        NcButton, NcEmptyContent, OpenInNew, FlagOutline, LanDisconnect, AlertCircleOutline, CalendarClock, History,
    },

    /**
     * `actions` — `{ projectUrl, workPackagesUrl, newWorkPackageUrl, filesUrl }`,
     * each a string or null, emitted whenever the payload changes so the
     * grid's header menu shows exactly the links OpenProject granted.
     */
    emits: ['actions'],

    data() {
        return {
            payload: null,
            loading: false,
            error: null,
            /**
             * v4.9.7 — the viewer's attention summary. Its own fetch, its own
             * failure: an unreadable summary costs the strip, never the
             * project block.
             */
            attention: null,
            ICON_INLINE,
            ICON_BODY,
            ICON_HERO,
        }
    },

    computed: {
        ...mapState(['currentTeamId', 'openProjectConfig']),

        state() {
            return widgetState(this.openProjectConfig, this.payload, this.error, this.loading)
        },

        project() {
            return this.payload?.project || {}
        },

        counts() {
            return this.payload?.counts || { open: null, overdue: null, dueSoon: null }
        },

        opensInOpenProject() {
            return t('teamhub', 'Opens in OpenProject')
        },

        /** Re-fetch when the linked project changes, not on every bundle load. */
        linkedProjectId() {
            return this.openProjectConfig?.project?.id ?? null
        },

        /**
         * v4.9.7 — the attention strip's rows, only the ones with something
         * in them. Each links to the exact place in OpenProject: the viewer's
         * own open work packages for the counts, the milestone itself.
         */
        attentionRows() {
            const a = this.attention
            if (!a) {
                return []
            }
            const rows = []
            const mine = a.urls?.myWorkPackages || null
            if (a.overdue > 0) {
                rows.push({
                    key: 'overdue',
                    icon: 'AlertCircleOutline',
                    tone: 'alert',
                    text: n('teamhub', '{n} of your work packages is overdue', '{n} of your work packages are overdue', a.overdue, { n: a.overdue }),
                    url: mine,
                })
            }
            if (a.dueThisWeek > 0) {
                rows.push({
                    key: 'week',
                    icon: 'CalendarClock',
                    tone: 'warn',
                    text: a.dueToday > 0
                        // TRANSLATORS: Project info widget — the viewer's work packages due soon; {today} of them are due today
                        ? t('teamhub', '{n} of your work packages due within {days} days, {today} today', { n: a.dueThisWeek, days: a.upcomingDays, today: a.dueToday })
                        : n('teamhub', '{n} of your work packages is due within {days} days', '{n} of your work packages are due within {days} days', a.dueThisWeek, { n: a.dueThisWeek, days: a.upcomingDays }),
                    url: mine,
                })
            }
            if (a.nextMilestone) {
                const m = a.nextMilestone
                rows.push({
                    key: 'milestone',
                    icon: 'FlagOutline',
                    tone: null,
                    text: m.daysUntil === 0
                        ? t('teamhub', 'Milestone today: {subject}', { subject: m.subject })
                        : n('teamhub', 'Milestone in {n} day: {subject}', 'Milestone in {n} days: {subject}', m.daysUntil || 0, { n: m.daysUntil || 0, subject: m.subject }),
                    url: m.url || null,
                })
            }
            const recent = a.recentActivity?.count || 0
            if (recent > 0) {
                rows.push({
                    key: 'activity',
                    icon: 'History',
                    tone: null,
                    text: n('teamhub', '{n} work package changed in the last 7 days', '{n} work packages changed in the last 7 days', recent, { n: recent }),
                    url: this.project.workPackagesUrl || null,
                })
            }
            return rows
        },

        /** What the header menu may offer right now (v4.9.5). */
        actions() {
            return {
                projectUrl:        this.project.url || null,
                workPackagesUrl:   this.project.workPackagesUrl || null,
                newWorkPackageUrl: this.project.newWorkPackageUrl || null,
                filesUrl:          this.payload?.files?.url || null,
            }
        },
    },

    watch: {
        actions: {
            handler(next) { this.$emit('actions', next) },
            immediate: true,
        },
        currentTeamId(newId) {
            this.payload = null
            this.attention = null
            this.error = null
            if (newId) this.fetchOverview()
        },
        linkedProjectId(newId, oldId) {
            if (newId !== oldId) {
                this.payload = null
                this.error = null
                if (newId) this.fetchOverview()
            }
        },
    },

    mounted() {
        this.fetchOverview()
        this._onVisibility = () => {
            if (document.visibilityState === 'visible') this.fetchOverview()
        }
        this._onFocus = () => this.fetchOverview()
        document.addEventListener('visibilitychange', this._onVisibility)
        window.addEventListener('focus', this._onFocus)
    },

    // Vue 3 — `beforeDestroy` is never called (HANDOFF).
    beforeUnmount() {
        if (this._onVisibility) {
            document.removeEventListener('visibilitychange', this._onVisibility)
            this._onVisibility = null
        }
        if (this._onFocus) {
            window.removeEventListener('focus', this._onFocus)
            this._onFocus = null
        }
    },

    methods: {
        t, n,
        formatDateTime, formatIsoDate, projectStatusTone, personalSettingsUrl,
        isConnectionCode, isBrokenConnectionCode,

        /** The header menu's Refresh (v4.9.5): bypass the cache. */
        refresh() {
            this.fetchOverview(true)
        },

        async fetchOverview(refresh = false) {
            if (!this.currentTeamId || !this.openProjectConfig?.linked) return
            if (this.loading) return
            this.loading = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/openproject/overview`),
                    { params: refresh ? { refresh: 1 } : {} },
                )
                this.payload = data
                this.error = null
                // v4.9.7 — the attention strip, behind the project block, never
                // ahead of it: the widget renders as soon as the overview is
                // in, and the strip arrives when it arrives.
                this.fetchAttention()
            } catch (e) {
                this.error = classifyError(e)
            } finally {
                this.loading = false
            }
        },

        /**
         * v4.9.7 — the viewer's own summary for this team. A failure is
         * silent by design: an unconnected member already sees the connect
         * hint on the block above, and a transient error costs one strip
         * until the next focus.
         */
        async fetchAttention() {
            const teamId = this.currentTeamId
            if (!teamId) {
                return
            }
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/openproject/attention`),
                )
                if (this.currentTeamId === teamId) {
                    this.attention = data
                }
            } catch (e) {
                this.attention = null
            }
        },

        countText(value) {
            return value === null || value === undefined ? '—' : String(value)
        },

        countTitle(value) {
            return value === null || value === undefined
                ? t('teamhub', 'Not available — OpenProject did not answer this count')
                : ''
        },
    },
}
</script>

<style scoped>
.th-op {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-sm);
    padding: var(--th-space-sm) var(--th-space-md) var(--th-space-md);
    font-size: var(--th-font-body);
}

.th-op__loading,
.th-op__empty {
    padding: var(--th-space-sm) var(--th-space-xs);
    color: var(--color-text-maxcontrast);
}

.th-op__error {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-sm);
    padding: var(--th-space-sm) var(--th-space-md);
    border-radius: var(--th-radius-control);
    background: var(--color-error);
    color: var(--color-error-text);
}

.th-op__error-text {
    margin: 0;
}

.th-op__error-actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--th-space-sm);
}

/* The broken-connection state: NcEmptyContent's own layout (centred icon,
   name, description) inside the widget body, its heading at widget scale
   rather than the page-level 20px it ships with. */
.th-op__broken {
    padding: var(--th-space-md) var(--th-space-sm);
}

.th-op__broken :deep(.empty-content__name) {
    font-size: var(--th-font-heading);
    line-height: var(--th-line-height-body);
}

.th-op__banner {
    padding: var(--th-space-xs) var(--th-space-md);
    border-radius: var(--th-radius-control);
    background: var(--color-warning);
    color: var(--color-warning-text);
    font-size: var(--th-font-meta);
}

.th-op__head {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--th-space-sm);
}

.th-op__name {
    display: inline-flex;
    align-items: center;
    gap: var(--th-space-xs);
    min-width: 0;
    font-weight: var(--th-font-weight-semibold);
    font-size: var(--th-font-heading);
    color: var(--color-main-text);
    text-decoration: none;
}

a.th-op__name:hover .th-op__name-text,
a.th-op__name:focus-visible .th-op__name-text {
    text-decoration: underline;
}

a.th-op__name:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
    border-radius: var(--th-radius-control);
}

.th-op__name-text {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.th-op__ext {
    color: var(--color-text-maxcontrast);
    flex-shrink: 0;
}

.th-op__chip {
    display: inline-flex;
    align-items: center;
    padding: 0 var(--th-space-sm);
    border-radius: var(--th-radius-chip);
    font-size: var(--th-font-meta);
    font-weight: var(--th-font-weight-semibold);
    line-height: var(--th-line-height-relaxed);
    background: var(--color-background-dark);
    color: var(--color-main-text);
}

.th-op__chip--success { background: var(--color-success); color: var(--color-success-text); }
.th-op__chip--warning { background: var(--color-warning); color: var(--color-warning-text); }
.th-op__chip--error   { background: var(--color-error);   color: var(--color-error-text); }

.th-op__meta {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.th-op__desc {
    margin: 0;
    font-size: var(--th-font-meta);
    color: var(--color-main-text);
    line-height: var(--th-line-height-body);
}

.th-op__desc--status {
    color: var(--color-text-maxcontrast);
}

.th-op__tiles {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: var(--th-space-sm);
    list-style: none;
    margin: 0;
    padding: 0;
}

.th-op-tile {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: var(--th-space-sm) var(--th-space-xs);
    border-radius: var(--th-radius-control);
    background: var(--color-background-hover);
    border-left: var(--th-accent-border) solid transparent;
}

.th-op-tile--warn  { border-left-color: var(--color-warning); }
.th-op-tile--alert { border-left-color: var(--color-error); }

.th-op-tile__value {
    font-size: var(--th-font-heading-lg);
    font-weight: var(--th-font-weight-bold);
    line-height: var(--th-line-height-tight);
}

.th-op-tile__label {
    font-size: var(--th-font-micro);
    color: var(--color-text-maxcontrast);
    text-align: center;
}

.th-op__milestone {
    display: flex;
    align-items: flex-start;
    gap: var(--th-space-sm);
    padding: var(--th-space-sm);
    border-radius: var(--th-radius-control);
    background: var(--color-background-hover);
    border-left: var(--th-accent-border) solid var(--color-success);
}

.th-op__milestone--overdue {
    border-left-color: var(--color-error);
}

/* v4.9.7 — the attention strip: the same block shape as the milestone,
   keyed on the primary colour because it is about the viewer, not the
   project; each row tones its own glyph and never relies on colour alone. */
.th-op__attention {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    padding: var(--th-space-sm);
    border-radius: var(--th-radius-control);
    background: var(--color-background-hover);
    border-left: var(--th-accent-border) solid var(--color-primary-element);
}

.th-op__attention-title {
    font-size: var(--th-font-micro);
    font-weight: var(--th-font-weight-semibold);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--color-text-maxcontrast);
}

.th-op__attention-list {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    margin: 0;
    padding: 0;
    list-style: none;
}

.th-op__attention-row {
    display: flex;
    align-items: center;
    gap: var(--th-space-xs);
    font-size: var(--th-font-meta);
}

.th-op__attention-row--alert {
    color: var(--color-error-text);
}

.th-op__attention-row--warn {
    color: var(--color-warning-text);
}

.th-op__milestone-icon {
    color: var(--color-text-maxcontrast);
    flex-shrink: 0;
}

.th-op__milestone-body {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xxs);
    min-width: 0;
}

.th-op__milestone-label {
    font-size: var(--th-font-micro);
    font-weight: var(--th-font-weight-semibold);
    text-transform: uppercase;
    color: var(--color-text-maxcontrast);
}

.th-op__milestone-date {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.th-op__section-title {
    margin: 0 0 var(--th-space-xs);
    font-size: var(--th-font-meta);
    font-weight: var(--th-font-weight-semibold);
    color: var(--color-text-maxcontrast);
}

.th-op__list {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    list-style: none;
    margin: 0;
    padding: 0;
}

.th-op__item {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xxs);
    min-width: 0;
}

.th-op__item-subject {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.th-op__item-meta {
    display: flex;
    gap: var(--th-space-sm);
    font-size: var(--th-font-micro);
    color: var(--color-text-maxcontrast);
}

.th-op__link {
    color: var(--color-main-text);
    text-decoration: none;
}

.th-op__link:hover {
    text-decoration: underline;
}

.th-op__link:focus-visible {
    text-decoration: underline;
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
    border-radius: var(--th-radius-control);
}

.th-op__footer {
    display: flex;
    align-items: center;
    margin-top: var(--th-space-xs);
}

.th-op__retrieved {
    font-size: var(--th-font-micro);
    color: var(--color-text-maxcontrast);
}
</style>
