<template>
    <div class="th-widget">

        <!-- Unassigned nudge — shown per board whenever unassigned > 0.
             Appears whether or not there are upcoming tasks. -->
        <div v-if="unassignedBoards.length" class="th-unassigned">
            <button
                v-for="b in unassignedBoards"
                :key="b.boardId"
                type="button"
                class="th-unassigned__row"
                :aria-label="n('teamhub', '{n} unassigned card in {board}: open board', '{n} unassigned cards in {board}: open board', b.count, { n: b.count, board: b.boardName || 'board' })"
                @click="openBoard(b)">
                <AlertCircleOutline :size="ICON_INLINE" class="th-unassigned__icon" aria-hidden="true" />
                <span class="th-unassigned__text">
                    <template v-if="b.boardName">
                        <strong>{{ b.boardName }}</strong>{{ t('teamhub', ':') }}
                    </template>
                    {{ n('teamhub', '{n} unassigned card', '{n} unassigned cards', b.count, { n: b.count }) }}
                </span>
                <ChevronRightIcon :size="ICON_INLINE" class="th-unassigned__ext" aria-hidden="true" />
            </button>
        </div>

        <!-- Empty state. While the OpenProject rows are still on their way
             the widget says so rather than "no tasks" for a moment. -->
        <div v-if="mergedTasks.length === 0 && opLoading && !opLoaded" class="th-widget__state">
            <span class="th-widget__state-text">{{ t('teamhub', 'Loading work packages') }}</span>
        </div>
        <div v-else-if="mergedTasks.length === 0" class="th-widget__state th-widget__state--empty">
            <CardTextIcon :size="ICON_BODY" aria-hidden="true" />
            <span class="th-widget__state-text">{{ t('teamhub', 'No upcoming tasks') }}</span>
        </div>

        <ul v-else class="th-widget__rows">
            <li
                v-for="task in mergedTasks"
                :key="task._key"
                class="th-widget__row">

                <!-- Source badge icon -->
                <div
                    class="th-deck__badge"
                    :class="{
                        'th-deck__badge--deck': task.source === 'deck',
                        'th-deck__badge--tasks': task.source === 'tasks',
                        'th-deck__badge--openproject': task.source === 'openproject',
                    }"
                    aria-hidden="true">
                    <CheckboxMarkedOutlineIcon v-if="task.source === 'deck'" :size="ICON_BODY" />
                    <BriefcaseOutlineIcon v-else-if="task.source === 'openproject'" :size="ICON_BODY" />
                    <ClipboardCheckOutlineIcon v-else :size="ICON_BODY" />
                </div>

                <!-- Main content -->
                <div class="th-deck__body">
                    <a
                        :href="task.url"
                        target="_blank"
                        :rel="task.source === 'openproject' ? 'noopener noreferrer' : undefined"
                        class="th-deck__title"
                        :class="{ 'th-deck__title--overdue': task.overdue }"
                        :title="task.source === 'openproject' ? t('teamhub', 'Opens in OpenProject') : undefined"
                        @click="onOpenTask($event, task)">
                        {{ task.title }}
                    </a>
                    <div class="th-deck__meta">
                        <!-- Due date. An OpenProject due date is a calendar
                             date, not an instant, so it is shown without a
                             time. -->
                        <span
                            v-if="task.duedate"
                            :class="{ 'th-deck__meta--overdue': task.overdue }">
                            {{ task.source === 'openproject' ? formatIsoDate(task.duedate) : formatDate(task.duedate) }}
                        </span>

                        <!-- Source pills: board name + app label.
                             Shared outline pill vocabulary. -->
                        <template v-if="task.source === 'deck'">
                            <NcChip
                                v-if="task.boardName && resources.deck && resources.deck.length > 1"
                                no-close
                                variant="tertiary"
                                class="th-deck__boardname"
                                :title="task.boardName">
                                {{ truncate(task.boardName, 20) }}
                            </NcChip>
                            <NcChip no-close variant="secondary">
                                {{ t('teamhub', 'Deck') }}
                            </NcChip>
                        </template>
                        <!-- v4.13.10 — an OpenProject row carried a type chip
                             ("Task") and an "OpenProject" chip; both said what
                             the row's badge icon already says, and together
                             they pushed the assignee onto a line of its own.
                             The meta line is now the due date and who it is
                             for (Justin, 2026-11-01). -->
                        <!--
                            v4.11.16 — every VTODO used to be chipped
                            "Personal task", which stopped being true the
                            moment a task could say who it is for. The chip
                            now says that: your own or the team's.

                            v4.13.10 — a task for a named colleague is that
                            colleague's name, plain, the way an OpenProject
                            assignee reads: no "For" and no pill, since a
                            name is not a category (Justin, 2026-11-01).
                        -->
                        <span
                            v-else-if="task.source === 'tasks' && !task.mine && task.assigneeName"
                            class="th-deck__assignee-name"
                            :title="t('teamhub', 'Assigned to {name}', { name: task.assigneeName })">
                            {{ task.assigneeName }}
                        </span>
                        <NcChip no-close variant="tertiary" v-else-if="task.source === 'tasks'">
                            {{ taskOwnerLabel(task) }}
                        </NcChip>

                        <!-- Assignee avatars (Deck only) -->
                        <span v-if="task.source === 'deck' && assignees(task).length" class="th-deck__assignees">
                            <NcAvatar
                                v-for="u in assignees(task)"
                                :key="u.uid"
                                :user="u.uid"
                                :display-name="u.displayname || u.uid"
                                :hide-status="true"
                                :disable-menu="false"
                                :size="24" />
                        </span>
                        <!-- Assignee name (OpenProject) — a name OpenProject
                             gave us, not a Nextcloud account, so no avatar.
                             A Tasks assignee reads the same way, above. -->
                        <span
                            v-if="task.source === 'openproject' && task.assigneeName"
                            class="th-deck__assignee-name"
                            :title="t('teamhub', 'Assigned to {name}', { name: task.assigneeName })">
                            {{ task.assigneeName }}
                        </span>
                    </div>
                </div>

                <!--
                    v4.13.9 - Change a work package without leaving the team.
                    Shown only when OpenProject grants this viewer the edit
                    on this row AND the OpenProject connection can send
                    PATCH; hidden otherwise rather than disabled, which is
                    CLAUDE.md's rule for anything somebody may not do. On a
                    connection that cannot write, nobody is shown a button
                    that would answer 501.
                -->
                <NcButton
                    v-if="task.source === 'openproject' && task.canEdit && opCanEdit"
                    variant="tertiary"
                    class="th-deck__edit"
                    :aria-label="t('teamhub', 'Change {subject}', { subject: task.title })"
                    :title="t('teamhub', 'Change work package')"
                    @click="$emit('edit-openproject-work-package', { workPackageId: task.id })">
                    <template #icon>
                        <PencilOutlineIcon :size="ICON_BODY" />
                    </template>
                </NcButton>

            </li>
        </ul>

        <!-- v4.9.5 — the OpenProject part of the list: its paging. The Deck
             and Tasks rows are complete lists from the store; the work
             packages are OpenProject's pages. A failed read shows no line
             here: the Project info widget carries the OpenProject state for
             the whole team home (Justin, 2026-09-14), and a failure costs
             the work packages, never the Deck and Tasks rows. -->
        <div v-if="!opError && opHasMore" class="th-deck__more">
            <NcButton variant="tertiary" :disabled="opLoading" @click="fetchOpenProject(opPage + 1)">
                {{ showMoreLabel }}
            </NcButton>
        </div>
    </div>
</template>

<script>
import { mapState, mapMutations } from 'vuex'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { formatDateTime as fmtDateTime, formatIsoDate, zonedIsoDate, todayIso } from '../lib/localDate.js'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcAvatar, NcButton, NcChip } from '@nextcloud/vue'
import { isPlainClick } from '../lib/internalLinks.js'
import { classifyError, mergeItems } from '../lib/openProject.js'
import CardTextIcon from 'vue-material-design-icons/CardText.vue'
import CheckboxMarkedOutlineIcon from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import ClipboardCheckOutlineIcon from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import BriefcaseOutlineIcon from 'vue-material-design-icons/BriefcaseOutline.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import PencilOutlineIcon from 'vue-material-design-icons/PencilOutline.vue'
import { ICON_BODY, ICON_INLINE } from '../constants/uiTokens.js'

/**
 * DeckWidget — the Upcoming tasks widget: Deck cards, NC Tasks VTODOs and,
 * since v4.9.5, the linked OpenProject project's work packages, merged into
 * one list by due date.
 *
 * The OpenProject rows are the whole project's — every assignee — because
 * this is the team's list of what is due; the viewer's own share is in My
 * Work. Unlike the two store-fed sources they are OpenProject's pages,
 * fetched here as the viewer (GET …/openproject/work?section=upcoming) and
 * extended with "Show more"; a failure costs the work packages and never
 * the Deck and Tasks rows (and shows nothing here — the Project info widget
 * carries the OpenProject state for the team home).
 *
 * v4.9.15 — the grid owns this widget's header menu, so the widget emits
 * `openproject-actions` (`{ canCreate }`, from the `work` payload's
 * `canCreateWorkPackage`) and the grid shows or hides "Create OpenProject
 * work package" — the same arrangement as OpenProjectOverviewWidget's
 * `actions`. `refreshOpenProject()` is the public re-read TeamView calls
 * after a work package was created.
 *
 * v4.13.9 — a row OpenProject lets this viewer change carries a pencil,
 * and the widget emits `edit-openproject-work-package` for the grid to
 * open the modal with. Two conditions, both required and both the server's
 * answer: the row's own `canEdit` (OpenProject's `update` link) and the
 * connection's ability to send PATCH (`workPackageEditAvailable` from the
 * capability envelope). Hidden when either is false — never disabled,
 * because a greyed pencil invites somebody to hunt for the permission that
 * would ungrey it, and on the integration-app connection there is none.
 */
export default {
    name: 'DeckWidget',

    components: {
         NcChip, NcAvatar,
        NcButton,
        CardTextIcon,
        CheckboxMarkedOutlineIcon,
        ClipboardCheckOutlineIcon,
        BriefcaseOutlineIcon,
        AlertCircleOutline,
        ChevronRightIcon,
        PencilOutlineIcon,
    },

    /** `openproject-actions` — `{ canCreate: boolean }`, whenever the work payload (re)loads. */
    emits: ['openproject-actions', 'edit-openproject-work-package'],

    data() {
        return {
            ICON_BODY,
            ICON_INLINE,
            // v4.9.5 — the OpenProject part of the list.
            opItems: [],
            // v4.13.9 - can the OpenProject connection send PATCH. From
            // the `work` payload, like `canCreate`; false until a page has
            // been read, so no pencil flashes before the answer arrives.
            opCanEdit: false,
            opTotal: null,
            opPage: 1,
            opHasMore: false,
            opLoading: false,
            opLoaded: false,
            // The last read's failure, if any. Not rendered — the Project
            // info widget carries the OpenProject state for the team home
            // — it only keeps "Show more" off a list that could not be read.
            opError: null,
        }
    },

    computed: {
        ...mapState(['deckTasks', 'teamTasks', 'resources', 'deckUnassignedCounts',
            'currentTeamId', 'widgetRefreshNonce', 'openProjectConfig']),

        /**
         * v4.5.9 — reload trigger; see CalendarWidget for the same pattern.
         * Keyed on team + home-view nonce together so a team switch fires once.
         */
        reloadKey() {
            return `${this.currentTeamId}|${this.widgetRefreshNonce}`
        },

        /** v4.9.5 — the team is linked to an OpenProject project. */
        openProjectActive() {
            return !!(this.openProjectConfig?.eligible && this.openProjectConfig?.linked)
        },

        /** Re-fetch the work packages when the linked project changes. */
        linkedProjectId() {
            return this.openProjectActive ? (this.openProjectConfig?.project?.id ?? null) : null
        },

        opRemaining() {
            return this.opTotal !== null ? Math.max(0, this.opTotal - this.opItems.length) : 0
        },

        showMoreLabel() {
            if (this.opLoading) return t('teamhub', 'Loading work packages')
            if (this.opRemaining > 0) {
                return n('teamhub', 'Show {n} more work package', 'Show {n} more work packages', this.opRemaining, { n: this.opRemaining })
            }
            // TRANSLATORS: button that loads the next page of a list
            return t('teamhub', 'Show more')
        },

        /**
         * Boards with at least one unassigned non-overdue card, sorted by
         * count descending so the board needing most attention is first.
         */
        unassignedBoards() {
            return Object.entries(this.deckUnassignedCounts || {})
                .filter(([, b]) => b.count > 0)
                .map(([boardId, b]) => ({ boardId, count: b.count, boardName: b.boardName }))
                .sort((a, b) => b.count - a.count)
        },

        /**
         * Merge Deck cards and NC Tasks VTODOs into a single list sorted by
         * due date. Tasks without a due date sort last.
         * Each entry gets a `source` field ('deck' | 'tasks') and a unique `_key`.
         */
        mergedTasks() {
            const now = new Date()

            // Normalise Deck cards
            const deckItems = (this.deckTasks || []).map(card => ({
                _key:          'deck-' + card.id,
                source:        'deck',
                id:            card.id,
                title:         card.title,
                duedate:       card.duedate || null,
                overdue:       card.duedate ? new Date(card.duedate) < now : false,
                url:           generateUrl(`/apps/deck/board/${card.boardId}/card/${card.id}`),
                boardId:       card.boardId,
                assignedUsers: card.assignedUsers || [],
                boardName:     card.boardName || '',
            }))

            // NC Tasks VTODOs — only shown when tasks app AND calendar are active.
            const showTasks = this.resources && this.resources.tasks && this.resources.calendar && this.resources.calendar.length > 0
            const taskItems = showTasks
                ? (this.teamTasks || []).map(task => ({
                    _key:    'task-' + task.id,
                    source:  'tasks',
                    id:      task.id,
                    title:   task.title,
                    duedate: task.duedate || null,
                    overdue: task.duedate ? new Date(task.duedate) < now : false,
                    url:     task.url || '/apps/tasks',
                    // v4.11.16 — who it is for, for the chip.
                    mine:         !!task.mine,
                    assigneeName: task.assigneeName || '',
                }))
                : []

            // v4.9.5 — OpenProject work packages. The due date is a calendar
            // date; it is overdue once the viewer's today is past it, not at
            // midnight UTC.
            const today = todayIso()
            const opItems = this.openProjectActive
                ? this.opItems.map(wp => ({
                    _key:         'op-' + wp.id,
                    source:       'openproject',
                    id:           wp.id,
                    title:        wp.subject,
                    duedate:      wp.dueDate || null,
                    overdue:      !!wp.dueDate && wp.dueDate < today,
                    url:          wp.url || '#',
                    type:         wp.type || '',
                    assigneeName: wp.assignee || '',
                    // v4.13.9 - OpenProject's own answer for this row and
                    // this viewer. The action also needs the connection to
                    // be able to send PATCH, which `opCanEdit` folds in.
                    canEdit:      !!wp.canEdit,
                }))
                : []

            const merged = [...deckItems, ...taskItems, ...opItems]

            // Sort by due date ascending; null due dates go last.
            merged.sort((a, b) => {
                if (!a.duedate && !b.duedate) return 0
                if (!a.duedate) return 1
                if (!b.duedate) return -1
                return new Date(a.duedate) - new Date(b.duedate)
            })


            return merged
        },
    },

    watch: {
        /**
         * Refetch on returning to the home view. Unlike CalendarWidget this
         * widget owns no requests of its own for Deck and Tasks — that data
         * arrives via fetchResources → fetchDeckTasks / fetchTeamTasks — so
         * mount and team switch are already covered for them. Firing here as
         * well would double-fetch, so a team change is skipped and left to
         * fetchResources. The OpenProject rows (v4.9.5) are this widget's own
         * request and follow the team through `linkedProjectId` below.
         */
        reloadKey(next, prev) {
            const team = String(next).split('|')[0]
            const prevTeam = String(prev).split('|')[0]
            if (team === prevTeam) {
                this.reload()
            }
        },

        linkedProjectId: {
            handler(next, prev) {
                if (next === prev) return
                this.resetOpenProject()
                if (next) this.fetchOpenProject(1)
            },
            immediate: true,
        },
    },

    methods: {
        t,
        n,
        formatIsoDate,
        ...mapMutations(['SET_VIEW', 'SET_SELECTED_DECK_BOARD']),

        /**
         * Who a VTODO is for, for its chip (v4.11.16).
         *
         * Only the viewer's own and the team's cases reach this: a task for a
         * named colleague shows that name plainly instead (v4.13.10).
         *
         * A task written before the *Who the task is for* field names nobody,
         * which is the team case — so an old task reads "Team task" rather
         * than claiming to belong to whoever happens to be looking.
         *
         * @param {object} task the mapped row
         * @return {string}
         */
        taskOwnerLabel(task) {
            if (task.mine) {
                // TRANSLATORS: chip on a task in the Upcoming tasks widget — the task is the viewer's own
                return t('teamhub', 'My task')
            }
            // TRANSLATORS: chip on a task in the Upcoming tasks widget — the task is for the whole team, nobody in particular
            return t('teamhub', 'Team task')
        },

        // ── OpenProject (v4.9.5) ──────────────────────────────────────────

        resetOpenProject() {
            this.opItems = []
            this.opCanEdit = false
            this.opTotal = null
            this.opPage = 1
            this.opHasMore = false
            this.opError = null
            this.opLoaded = false
            this.$emit('openproject-actions', { canCreate: false })
        },

        /**
         * v4.9.15 — the public re-read after a work package was created
         * (TeamView → grid → here). The plain path is fresh: the create
         * bumped the team's cache generation, so no `refresh=1` is needed
         * and its cooldown cannot get in the way.
         */
        refreshOpenProject() {
            return this.fetchOpenProject(1)
        },

        /**
         * One page of the project's dated open work packages, every
         * assignee, soonest due first. Page 1 replaces the list; a later
         * page appends. `refresh` asks the backend to bypass its per-user
         * cache (subject to its own cooldown).
         */
        async fetchOpenProject(page = 1, refresh = false) {
            if (!this.openProjectActive || !this.currentTeamId || this.opLoading) return
            const teamId = this.currentTeamId
            this.opLoading = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/openproject/work`),
                    { params: { section: 'upcoming', page, ...(refresh ? { refresh: 1 } : {}) } },
                )
                if (teamId !== this.currentTeamId) return
                // mergeItems: a work package that moved between pages while
                // the viewer paged must not appear twice.
                this.opItems = page === 1 ? (data.items || []) : mergeItems(this.opItems, data.items || [])
                this.opTotal = data.total ?? null
                this.opPage = data.page || page
                this.opHasMore = !!data.hasMore
                this.opError = null
                if (page === 1) {
                    this.opCanEdit = !!data.canEditWorkPackages
                    this.$emit('openproject-actions', { canCreate: !!data.canCreateWorkPackage })
                }
            } catch (e) {
                if (teamId !== this.currentTeamId) return
                this.opError = classifyError(e)
                if (page === 1) {
                    this.opItems = []
                    this.opHasMore = false
                    this.opCanEdit = false
                    this.$emit('openproject-actions', { canCreate: false })
                }
            } finally {
                if (teamId === this.currentTeamId) {
                    this.opLoading = false
                    this.opLoaded = true
                }
            }
        },

        /**
         * Open the board in the TeamHub iframe (same as clicking the Deck tab).
         * With multiple boards, SET_SELECTED_DECK_BOARD pre-selects this board
         * so deckUrl in TeamView resolves to the right one.
         */
        openBoard(b) {
            this.SET_SELECTED_DECK_BOARD({ board_id: b.boardId, name: b.boardName })
            // v4.5.9 — clear any pinned card so this lands on the board itself,
            // not on a card left over from a previous click.
            this.$store.commit('SET_DECK_EMBED_CARD_URL', null)
            this.SET_VIEW('deck')
        },

        /**
         * Open a Deck card inside TeamHub's deck iframe rather than navigating
         * away to the Deck app (v4.5.9). Modified clicks (ctrl/cmd/shift/middle)
         * fall through to the native new tab.
         *
         * NC Tasks items (source 'tasks') are deliberately left alone: there is
         * no Tasks tab to embed them in, so their link stays external.
         */
        onOpenTask(domEvent, task) {
            // OpenProject rows (v4.9.5) always leave for their own app — the
            // link's target="_blank" does that; nothing to intercept.
            if (task.source !== 'deck' || !task.boardId || !isPlainClick(domEvent)) {
                return
            }
            domEvent.preventDefault()
            this.$store.dispatch('openDeckCardInEmbed', {
                boardId:   task.boardId,
                cardId:    task.id,
                boardName: task.boardName,
            })
        },

        /**
         * Refetch both sources. The widget itself owns no requests — the data
         * lives in the store — so a reload means re-dispatching the two actions
         * that fetchResources normally fires.
         */
        reload() {
            if (this.resources?.deck?.length) {
                this.$store.dispatch('fetchDeckTasks', this.resources.deck)
            }
            if (this.currentTeamId) {
                this.$store.dispatch('fetchTeamTasks', this.currentTeamId)
            }
            // v4.9.5 — and the first page of work packages again.
            if (this.openProjectActive) {
                this.fetchOpenProject(1)
            }
        },

        truncate(str, max) {
            if (!str) return ''
            return str.length > max ? str.slice(0, max) + '…' : str
        },

        formatDate(duedate) {
            if (!duedate) return ''
            // Same-year test in the reader's zone, so a due date late on 31
            // December does not grow a year label an hour before they do.
            const sameYear = zonedIsoDate(duedate).slice(0, 4) === todayIso().slice(0, 4)
            return fmtDateTime(duedate, {
                month:  'short',
                day:    'numeric',
                hour:   '2-digit',
                minute: '2-digit',
                year:   sameYear ? undefined : 'numeric',
            })
        },

        /**
         * Extract flat { uid, displayname } from Deck's assignedUsers.
         * Handles both nested (participant.uid) and flat (uid) shapes.
         */
        assignees(task) {
            if (!task.assignedUsers || !task.assignedUsers.length) return []
            return task.assignedUsers
                .map(u => u.participant || u)
                .filter(p => p && p.uid)
        },
    },
}
</script>

<style scoped>
/* Widget-specific only — shared classes from widget-tokens.css */

/* Source badge — distinct to Deck widget */
.th-deck__badge {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 38px;
    height: 38px;
    border-radius: var(--border-radius-element);
    border: 1px solid var(--color-border);
}
/* v3.100.14: neutral decorative tile per SKILLS.md — the primary-
   coloured icon carries the "deck" accent. Was
   --color-primary-element-light with a background-dark fallback that
   already conceded this should be neutral. */
.th-deck__badge--deck {
    background: var(--color-background-dark);
    color: var(--color-primary-element);
}
.th-deck__badge--tasks {
    background: var(--color-background-dark);
    color: var(--color-main-text);
}
/* v4.9.5 — OpenProject work package: the same neutral tile, the icon in
   the primary colour like Deck (both are the team's planning tool). */
.th-deck__badge--openproject {
    background: var(--color-background-dark);
    color: var(--color-primary-element);
}

.th-deck__body {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.th-deck__title {
    font-size: var(--th-widget-row-primary-size);
    font-weight: var(--th-widget-row-primary-weight);
    color: var(--color-main-text);
    text-decoration: none;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: block;
}
.th-deck__title:hover { color: var(--color-primary-element); }
/* v3.100.16: NC theme token (was --th-color-error hex). */
.th-deck__title--overdue { color: var(--color-text-error); }

.th-deck__meta {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: var(--th-widget-row-meta-size);
    font-weight: var(--th-widget-row-meta-weight);
    color: var(--th-widget-meta-color);
    flex-wrap: wrap;
}
.th-deck__meta--overdue { color: var(--color-text-error); }

.th-deck__boardname {
    max-width: 120px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.th-deck__assignees {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-inline-start: auto;
}

/* v4.9.5 — an assignee from OpenProject or NC Tasks is a name, not an
   account, so it reads as text where Deck puts its avatars. v4.13.10: it sits
   inline on the meta line (it used to be pushed to the far end, which wrapped
   it onto a line of its own as soon as the widget was narrow). */
.th-deck__assignee-name {
    max-width: 140px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* v4.13.9 — the row's Change action. Pushed to the end of the row and
   kept from shrinking, so a long subject never squeezes it out. It stays
   in the tab order and keeps NcButton's own focus ring; only its opacity
   is quiet until the row is hovered or something inside it has focus. */
.th-deck__edit {
    flex: 0 0 auto;
    align-self: center;
    margin-inline-start: var(--th-space-sm);
    opacity: 0.6;
}

.th-widget__row:hover .th-deck__edit,
.th-widget__row:focus-within .th-deck__edit {
    opacity: 1;
}

.th-deck__more {
    display: flex;
    justify-content: center;
    padding: var(--th-space-xs) 0;
}

/* ─── Unassigned nudge — amber row, top of widget body ─── */

.th-unassigned {
    display: flex;
    flex-direction: column;
    border-bottom: 1px solid var(--color-border);
}

/* v3.100.14: switched from the deprecated --th-color-warning-soft
   token to the SKILLS.md-standard --color-warning + --color-warning-text
   pair, so the unassigned-card row reads as an actual warning state at
   full saturation. The row separator becomes a translucent overlay of
   the text colour instead of a second warning tone. */
.th-unassigned__row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    width: 100%;
    text-align: start;
    text-decoration: none;
    cursor: pointer;
    background: var(--color-warning);
    border: none;
    border-bottom: 1px solid var(--color-warning-text);
    color: var(--color-warning-text);
    font-size: var(--th-widget-row-meta-size);
    font-family: inherit;
    line-height: 1.3;
    transition: background var(--animation-quick);
}

.th-unassigned__row:last-child {
    border-bottom: none;
}

.th-unassigned__row:hover {
    background: var(--color-warning-hover);
}

.th-unassigned__row:focus-visible {
    outline: 2px solid var(--color-warning-text);
    outline-offset: -2px;
}

.th-unassigned__icon {
    flex-shrink: 0;
    opacity: 0.8;
}

.th-unassigned__text {
    flex: 1;
    min-width: 0;
}

.th-unassigned__ext {
    flex-shrink: 0;
    opacity: 0.5;
}
</style>
