<template>
    <!-- v4.5.39 — on a narrow screen this is a drawer that slides in from the
         right, not a block stacked above the feed. It used to take the top of
         every phone-sized page before a single message was visible. Above
         900px `open` means nothing: the rail is a permanent sidebar there and
         the CSS ignores the class. -->
    <aside
        class="feed-rail"
        :class="{ 'feed-rail--open': open }"
        :aria-label="t('teamhub', 'Feed control')">
        <div class="feed-rail__head">
            <h3 class="feed-rail__title">{{ t('teamhub', 'Feed control') }}</h3>
            <!-- Drawer chrome: present only while the rail is a drawer, which
                 is the mobile-drawer carve-out in SKILLS.md § "NcButton is the
                 default". -->
            <button
                type="button"
                class="feed-rail__close"
                :aria-label="t('teamhub', 'Close')"
                :title="t('teamhub', 'Close')"
                @click="$emit('close')">
                <Close :size="ICON_TOOLBAR" aria-hidden="true" />
            </button>
        </div>

        <!-- ── SHOW ─────────────────────────────────────────────────────── -->
        <section class="feed-rail__section">
            <h4 class="feed-rail__label">{{ t('teamhub', 'Show') }}</h4>
            <NcCheckboxRadioSwitch
                :model-value="value.includeTeam"
                type="switch"
                @update:model-value="patch('includeTeam', $event)">
                {{ t('teamhub', 'Team messages') }}
            </NcCheckboxRadioSwitch>
            <NcCheckboxRadioSwitch
                :model-value="value.includePublic"
                type="switch"
                @update:model-value="patch('includePublic', $event)">
                {{ t('teamhub', 'Public messages') }}
            </NcCheckboxRadioSwitch>
            <NcCheckboxRadioSwitch
                :model-value="value.includeTalk"
                type="switch"
                @update:model-value="patch('includeTalk', $event)">
                {{ t('teamhub', 'Talk polls & threads') }}
            </NcCheckboxRadioSwitch>
            <!-- v4.5.29 — decisions are their own source here, not a message
                 type buried in a second filter. Only open ones are listed; a
                 resolved decision is a record, and the Decisions tab is where
                 records live.
                 v4.5.45 — relabelled "Open proposals". The preference key
                 stays `includeDecisions`: it is stored per user and sent as a
                 query param, so renaming it would silently reset the switch
                 for everyone in exchange for nothing. -->
            <NcCheckboxRadioSwitch
                :model-value="value.includeDecisions"
                type="switch"
                @update:model-value="patch('includeDecisions', $event)">
                {{ t('teamhub', 'Open proposals') }}
            </NcCheckboxRadioSwitch>
            <!-- v4.5.31 — the System messages switch is gone. It filtered
                 exactly one thing (milestone auto-posts), which nobody was
                 looking to hide, and it cost a row in a list where every other
                 row answers a question people actually ask. -->

            <!-- v4.5.28 — an include/exclude switch like every other row here,
                 not a lens. It used to mean "mentions only", which hid
                 everything else and made it the one switch in the group that
                 behaved differently. Showing *only* mentions is what the
                 Mentions tab above the feed is for. -->
            <NcCheckboxRadioSwitch
                :model-value="value.includeMentions"
                type="switch"
                @update:model-value="patch('includeMentions', $event)">
                {{ t('teamhub', 'Mentions') }}
            </NcCheckboxRadioSwitch>
            <!-- v4.9.7 — OpenProject news, an include switch like its
                 neighbours. Shown whenever the source can be asked at all
                 (the rail does not know whether the viewer is connected; the
                 feed says so in a notice when it matters). -->
            <NcCheckboxRadioSwitch
                v-if="openProjectAvailable"
                :model-value="value.includeOpenProject"
                type="switch"
                @update:model-value="patch('includeOpenProject', $event)">
                {{ t('teamhub', 'OpenProject news') }}
            </NcCheckboxRadioSwitch>
        </section>

        <!-- ── OPENPROJECT PROJECTS (v4.9.7) ─────────────────────────────
             Only while the switch above is on and the results hold news
             from more than nothing: which projects. Nothing ticked means
             everything, the same rule the Teams section follows. -->
        <section v-if="openProjectAvailable && value.includeOpenProject && projects.length" class="feed-rail__section">
            <h4 class="feed-rail__label">{{ t('teamhub', 'OpenProject projects') }}</h4>
            <ul class="feed-rail__picklist">
                <li v-for="project in projects" :key="project.id">
                    <NcCheckboxRadioSwitch
                        :model-value="value.projectIds.includes(project.id)"
                        @update:model-value="toggleInList('projectIds', project.id, $event)">
                        {{ project.name }} ({{ project.count }})
                    </NcCheckboxRadioSwitch>
                </li>
            </ul>
        </section>

        <!-- ── PERIOD ───────────────────────────────────────────────────── -->
        <section class="feed-rail__section">
            <h4 class="feed-rail__label">{{ t('teamhub', 'Period') }}</h4>
            <NcCheckboxRadioSwitch
                v-for="opt in periodOptions"
                :key="opt.id"
                :model-value="value.period"
                :value="opt.id"
                type="radio"
                name="feed-period"
                @update:model-value="patch('period', $event)">
                {{ opt.label }}
            </NcCheckboxRadioSwitch>

            <div v-if="value.period === 'custom'" class="feed-rail__dates">
                <label class="feed-rail__date">
                    <span>{{ t('teamhub', 'From') }}</span>
                    <input
                        type="date"
                        :value="toDateInput(value.customFrom)"
                        :max="todayInput"
                        @change="patch('customFrom', fromDateInput($event.target.value))">
                </label>
                <label class="feed-rail__date">
                    <span>{{ t('teamhub', 'To') }}</span>
                    <input
                        type="date"
                        :value="toDateInput(value.customTo)"
                        :max="todayInput"
                        @change="patch('customTo', fromDateInput($event.target.value))">
                </label>
            </div>
        </section>

        <!-- ── TEAMS ────────────────────────────────────────────────────── -->
        <section class="feed-rail__section">
            <h4 class="feed-rail__label">{{ t('teamhub', 'Teams') }}</h4>
            <NcCheckboxRadioSwitch
                :model-value="teamMode"
                value="all"
                type="radio"
                name="feed-teams"
                @update:model-value="setTeamMode">
                {{ t('teamhub', 'All teams') }}
            </NcCheckboxRadioSwitch>
            <NcCheckboxRadioSwitch
                :model-value="teamMode"
                value="some"
                type="radio"
                name="feed-teams"
                :disabled="!teams.length"
                @update:model-value="setTeamMode">
                {{ t('teamhub', 'Select teams') }}
            </NcCheckboxRadioSwitch>

            <ul v-if="teamMode === 'some' && teams.length" class="feed-rail__picklist">
                <li v-for="team in teams" :key="team.id">
                    <NcCheckboxRadioSwitch
                        :model-value="value.teamIds.includes(team.id)"
                        @update:model-value="toggleInList('teamIds', team.id, $event)">
                        {{ team.name }} ({{ team.count }})
                    </NcCheckboxRadioSwitch>
                </li>
            </ul>
            <p v-else-if="teamMode === 'some'" class="feed-rail__hint">
                {{ t('teamhub', 'No teams in the current results.') }}
            </p>
        </section>

        <!-- v4.5.29 — the TYPES section is gone. Message type and the Show
             switches were two controls answering one question, and Show is the
             one that reads as a control; keeping both meant a feed could be
             narrowed twice with no indication which filter had emptied it. -->

        <!-- ── PER PAGE ─────────────────────────────────────────────────── -->
        <section class="feed-rail__section">
            <h4 class="feed-rail__label">{{ t('teamhub', 'Per page') }}</h4>
            <div class="feed-rail__perpage">
                <NcCheckboxRadioSwitch
                    v-for="opt in perPageOptions"
                    :key="opt"
                    :model-value="value.perPage"
                    :value="opt"
                    type="radio"
                    name="feed-perpage"
                    :aria-label="t('teamhub', '{n} per page', { n: opt })"
                    @update:model-value="patch('perPage', Number($event))">
                    {{ opt }}
                </NcCheckboxRadioSwitch>
            </div>
        </section>

        <NcButton
            variant="secondary"
            class="feed-rail__save"
            :disabled="saving"
            @click="$emit('save-default')">
            <template v-if="saving" #icon>
                <NcLoadingIcon :size="ICON_BODY" />
            </template>
            {{ t('teamhub', 'Save as default') }}
        </NcButton>

        <!-- Confirmation is a live region: the button label doesn't change, so
             a screen-reader user would otherwise get no feedback at all. -->
        <p class="feed-rail__saved" aria-live="polite">
            <span v-if="savedAt">{{ t('teamhub', 'Saved as your default.') }}</span>
        </p>
    </aside>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { toDateInput, fromDateInput } from '../../lib/localDate.js'
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import { ICON_BODY, ICON_TOOLBAR } from '../../constants/uiTokens.js'

export default {
    name: 'FeedControlRail',
    components: { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, Close },

    props: {
        /**
         * The live control state. Treated as read-only — every change leaves
         * through the `update` event so the view stays the single owner and a
         * refetch can never race a half-applied local mutation.
         */
        value: {
            type: Object,
            required: true,
        },
        /** v4.9.7 — OpenProject projects present in the current results (facet). */
        projects: {
            type: Array,
            default: () => [],
        },
        /** v4.9.7 — whether the OpenProject source exists on this instance at all. */
        openProjectAvailable: {
            type: Boolean,
            default: false,
        },
        /** Facet list from the server: teams present in the current results. */
        teams: {
            type: Array,
            default: () => [],
        },
        perPageOptions: {
            type: Array,
            default: () => [20, 50, 100],
        },
        saving: {
            type: Boolean,
            default: false,
        },
        /** Timestamp of the last successful save; drives the confirmation. */
        savedAt: {
            type: Number,
            default: 0,
        },
        /**
         * Whether the drawer is showing (v4.5.39). Narrow screens only — above
         * 900px the rail is a permanent sidebar and this is ignored, so the
         * parent never has to know which layout is on screen.
         */
        open: {
            type: Boolean,
            default: false,
        },
    },

    emits: ['update', 'save-default', 'close'],

    data() {
        return {
            ICON_BODY,
            ICON_TOOLBAR,
            // "Select teams" has to be able to be *chosen* before anything is
            // ticked, otherwise the radio snaps straight back to "All teams"
            // (an empty list means all) and the picker never opens. These
            // track the disclosure; the selection itself stays in `value`.
            teamPickerOpen: false,
        }
    },

    computed: {
        periodOptions() {
            return [
                { id: 'all', label: t('teamhub', 'All time') },
                { id: 'today', label: t('teamhub', 'Today') },
                { id: 'week', label: t('teamhub', 'This week') },
                { id: 'month', label: t('teamhub', 'This month') },
                { id: 'custom', label: t('teamhub', 'Custom range') },
            ]
        },

        // "All teams" IS "no team selected" — the selection stays the single
        // source of truth for what gets queried. The open flag only survives
        // the moment between choosing "Select teams" and ticking the first one.
        teamMode() {
            return (this.value.teamIds.length || this.teamPickerOpen) ? 'some' : 'all'
        },

        todayInput() {
            return this.toDateInput(Math.floor(Date.now() / 1000))
        },
    },

    methods: {
        t,

        patch(key, val) {
            this.$emit('update', { [key]: val })
        },

        setTeamMode(mode) {
            this.teamPickerOpen = mode === 'some'
            // Clearing on the way back to "all" is what makes the radio mean
            // what it says — leaving the ticks behind would show every team
            // while the picker still claimed a subset.
            if (mode === 'all' && this.value.teamIds.length) {
                this.$emit('update', { teamIds: [] })
            }
        },

        toggleInList(key, id, checked) {
            const next = new Set(this.value[key])
            if (checked) {
                next.add(id)
            } else {
                next.delete(id)
            }
            this.$emit('update', { [key]: [...next] })
        },

        // Both now live in src/lib/localDate.js — this component was the only
        // place in the app that handled the local/UTC distinction correctly,
        // so the module was built from these two and the rest converted to it.
        toDateInput,
        fromDateInput,
    },
}
</script>

<style scoped lang="scss">
.feed-rail {
    flex: 0 0 260px;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--th-radius-card, var(--border-radius-large));
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    position: sticky;
    top: 20px;
    max-height: calc(100vh - 40px);
    overflow-y: auto;

    // v4.5.39 — a right-hand drawer, off-canvas until asked for. It used to be
    // `order: -1`, which put the whole control panel above the feed: every
    // phone-sized visit opened on the controls instead of on the messages.
    //
    // `position: fixed` rather than absolute — it has to escape the view's own
    // scroll container to cover the screen. Width is capped so the page behind
    // stays partly visible, which is what makes it read as a drawer over the
    // feed rather than as a new page.
    @media (max-width: 900px) {
        position: fixed;
        // Below Nextcloud's own header, which sits at z-index 2000 and would
        // otherwise crop the top of the drawer.
        top: var(--header-height, 50px);
        right: 0;
        bottom: 0;
        z-index: 1001; // above the scrim
        flex: 0 0 auto;
        width: min(340px, 86vw);
        max-height: none;
        border-radius: 0;
        border-width: 0 0 0 1px;
        box-shadow: -4px 0 16px rgba(0, 0, 0, 0.18);
        transform: translateX(100%);
        transition: transform 200ms ease-out;
        // Off-canvas is not just invisible: without this the switches stay in
        // the tab order and keyboard focus walks into a closed drawer.
        visibility: hidden;

        &.feed-rail--open {
            transform: translateX(0);
            visibility: visible;
        }

        // Respect a user who has asked for less motion — the drawer still
        // opens, it just arrives rather than slides.
        @media (prefers-reduced-motion: reduce) {
            transition: none;
        }
    }
}

.feed-rail__head {
    display: flex;
    align-items: center;
    gap: 8px;
}

// Drawer chrome only — above 900px there is nothing to close.
.feed-rail__close {
    display: none;
}

@media (max-width: 900px) {
    .feed-rail__close {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
        box-sizing: border-box;
        margin-left: auto;
        width: 32px;
        height: 32px;
        // Six locks: NC's global button rule sets both min-width and
        // min-height to 44px, and per spec min-* beats an unqualified
        // width/height (SKILLS.md § UI shapes).
        min-width: 32px;
        min-height: 32px;
        max-width: 32px;
        max-height: 32px;
        padding: 0;
        border: none;
        border-radius: var(--th-radius-control, var(--border-radius));
        background: none;
        color: var(--color-main-text);
        cursor: pointer;

        &:hover { background: var(--color-background-hover); }
        // Split from :hover — grouping them silences the keyboard focus ring
        // (SKILLS.md § Focus visibility standard).
        &:focus-visible {
            background: var(--color-background-hover);
            outline: 2px solid var(--color-primary-element);
            outline-offset: 2px;
        }
    }
}

/* v4.5.27 — primary, like every other panel header in the app
   (.teamhub-widget-title, .mywork__panel-head). It was plain body black,
   which made the one heading on the rail read as body text. */
.feed-rail__title {
    font-size: var(--th-font-heading, 16px);
    font-weight: var(--th-font-weight-semibold, 600);
    color: var(--color-primary-element);
    margin: 0;
}

.feed-rail__section {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.feed-rail__label {
    font-size: var(--th-font-micro, 11px);
    font-weight: var(--th-font-weight-semibold, 600);
    color: var(--color-text-maxcontrast);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin: 0 0 2px;
}

.feed-rail__hint {
    margin: 2px 0 0;
    font-size: var(--th-font-micro, 11px);
    line-height: var(--th-line-height-body, 1.4);
    color: var(--color-text-maxcontrast);
}


.feed-rail__picklist {
    list-style: none;
    margin: 2px 0 0 12px;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
    // A user in thirty teams must not push Save off the bottom of the rail.
    max-height: 220px;
    overflow-y: auto;
}

.feed-rail__dates {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin: 6px 0 0 12px;
}

.feed-rail__date {
    display: flex;
    flex-direction: column;
    gap: 2px;
    font-size: var(--th-font-micro, 11px);
    color: var(--color-text-maxcontrast);

    input {
        font-size: var(--th-font-meta, 12px);
        padding: 4px 6px;
        border: 1px solid var(--color-border-dark);
        border-radius: var(--th-radius-control, var(--border-radius));
        background: var(--color-main-background);
        color: var(--color-main-text);

        &:focus {
            outline: none;
            border-color: var(--color-primary-element);
        }

        &:focus-visible {
            box-shadow: 0 0 0 2px var(--color-primary-element);
        }
    }
}

.feed-rail__perpage {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.feed-rail__save {
    width: 100%;
}

.feed-rail__saved {
    margin: 0;
    min-height: 1em; // reserves the line so the rail doesn't jump on save
    font-size: var(--th-font-micro, 11px);
    color: var(--color-success-text);
    text-align: center;
}
</style>
