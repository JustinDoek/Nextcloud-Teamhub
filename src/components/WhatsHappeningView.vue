<template>
    <div class="whats-happening">
        <div class="whats-happening__main">
            <!-- Header. Vertical stack (title over subtitle), pushed right so
                 the NC sidebar toggle button in the top-left never overlaps
                 the first line. Refresh sits at the right edge of the header,
                 aligned with the title baseline. -->
            <div class="whats-happening__header">
                <div class="whats-happening__header-text">
                    <h2 class="whats-happening__title">{{ t('teamhub', 'What’s new') }}</h2>
                    <span class="whats-happening__subtitle">
                        {{ t('teamhub', 'Recent messages from your teams and public posts') }}
                    </span>
                </div>
                <NcButton
                    variant="tertiary"
                    class="whats-happening__refresh"
                    :aria-label="t('teamhub', 'Refresh feed')"
                    :title="t('teamhub', 'Refresh feed')"
                    :disabled="loading"
                    @click="refresh">
                    <template #icon>
                        <Refresh :size="16" aria-hidden="true" />
                    </template>
                </NcButton>
            </div>

            <!-- Loading (first page) -->
            <div v-if="loading" class="whats-happening__loading">
                <NcLoadingIcon :size="32" />
            </div>

            <!-- Empty -->
            <NcEmptyContent
                v-else-if="!items.length"
                :name="emptyTitle"
                :description="emptyBody">
                <template #icon><Rss :size="48" /></template>
            </NcEmptyContent>

            <!-- Feed -->
            <ul v-else class="whats-happening__list">
                <li
                    v-for="item in items"
                    :key="itemKey(item)"
                    class="whats-happening__item"
                    :class="itemClass(item)">
                    <div class="whats-happening__item-meta">
                        <NcAvatar
                            :user="item.author_id"
                            :display-name="item.author_display_name || item.author_id"
                            :size="28"
                            :show-user-status="false"
                            :disable-menu="true"
                            class="whats-happening__avatar" />
                        <div class="whats-happening__item-titleline">
                            <button
                                type="button"
                                class="whats-happening__team-btn"
                                :title="t('teamhub', 'Open {team}', { team: item.team_name || item.team_id })"
                                @click="openTeam(item)">
                                {{ item.team_name || item.team_id }}
                            </button>
                            <span
                                v-if="item.isPublic"
                                class="whats-happening__badge whats-happening__badge--public"
                                :title="t('teamhub', 'This message was posted with Public visibility.')">
                                {{ t('teamhub', 'Public') }}
                            </span>
                            <span
                                v-if="item.source === 'talk-poll'"
                                class="whats-happening__badge whats-happening__badge--talk"
                                :title="t('teamhub', 'Poll in Talk chat {room}', { room: item.room_name || '' })">
                                {{ t('teamhub', 'Talk poll') }}
                            </span>
                            <span
                                v-else-if="item.source === 'talk-thread'"
                                class="whats-happening__badge whats-happening__badge--talk"
                                :title="t('teamhub', 'Thread in Talk chat {room}', { room: item.room_name || '' })">
                                {{ t('teamhub', 'Talk thread') }}
                            </span>
                            <span class="whats-happening__timestamp">
                                {{ formatTimestamp(item.created_at) }}
                            </span>
                        </div>
                    </div>

                    <!-- Talk poll — question + options with proportional bars -->
                    <template v-if="item.source === 'talk-poll'">
                        <div class="whats-happening__subject">{{ item.question }}</div>
                        <ul class="whats-happening__poll-options">
                            <li
                                v-for="(opt, i) in item.options || []"
                                :key="i"
                                class="whats-happening__poll-option">
                                <div class="whats-happening__poll-row">
                                    <span class="whats-happening__poll-label">{{ opt }}</span>
                                    <span class="whats-happening__poll-count">
                                        {{ voteCount(item, i) }} · {{ votePercent(item, i) }}%
                                    </span>
                                </div>
                                <div
                                    class="whats-happening__poll-bar"
                                    :aria-label="t('teamhub', '{n} of {total} votes', { n: voteCount(item, i), total: totalVotes(item) })">
                                    <div
                                        class="whats-happening__poll-bar-fill"
                                        :style="{ width: votePercent(item, i) + '%' }" />
                                </div>
                            </li>
                        </ul>
                        <div class="whats-happening__poll-footer">
                            <span class="whats-happening__poll-voters">
                                {{ n('teamhub', '{n} voter', '{n} voters', item.num_voters || 0, { n: item.num_voters || 0 }) }}
                            </span>
                            <button
                                type="button"
                                class="whats-happening__talk-jump"
                                @click="openTalk(item)">
                                {{ t('teamhub', 'Open chat') }}
                            </button>
                        </div>
                    </template>

                    <!-- Talk thread — topic message + reply count.
                         Subject is the truncated one-liner from the
                         thread's cached `name`; preview shows the full
                         topic message when longer. On this Talk schema
                         they carry the same source text, but the preview
                         layout matches other feed cards for consistency
                         and gives more context when the title had to be
                         truncated at 120 chars. -->
                    <template v-else-if="item.source === 'talk-thread'">
                        <div class="whats-happening__subject">{{ item.subject }}</div>
                        <p v-if="preview(item.message)" class="whats-happening__preview">
                            {{ preview(item.message) }}
                        </p>
                        <button
                            type="button"
                            class="whats-happening__talk-jump"
                            @click="openTalk(item)">
                            {{ n('teamhub', '{n} reply — open chat', '{n} replies — open chat', item.num_replies || 0, { n: item.num_replies || 0 }) }}
                        </button>
                    </template>

                    <!-- Team / Public message — original layout -->
                    <template v-else>
                        <div class="whats-happening__subject">{{ item.subject }}</div>
                        <p v-if="preview(item.message)" class="whats-happening__preview">
                            {{ preview(item.message) }}
                        </p>
                    </template>

                    <div
                        v-if="item.author_display_name || item.author_id"
                        class="whats-happening__author">
                        {{ item.author_display_name || item.author_id }}
                    </div>
                </li>
            </ul>

            <!-- Pagination footer. Only render when there is more than one
                 page's worth of results; a single page hides both buttons. -->
            <div v-if="items.length && total > perPage" class="whats-happening__pagination">
                <NcButton
                    variant="secondary"
                    :disabled="page <= 1 || loading"
                    :aria-label="t('teamhub', 'Previous page')"
                    @click="goToPage(page - 1)">
                    <template #icon><ChevronLeft :size="16" /></template>
                    {{ t('teamhub', 'Previous') }}
                </NcButton>
                <span class="whats-happening__pagination-label" aria-live="polite">
                    {{ t('teamhub', 'Page {page} of {total}', { page, total: totalPages }) }}
                </span>
                <NcButton
                    variant="secondary"
                    :disabled="!hasMore || loading"
                    :aria-label="t('teamhub', 'Next page')"
                    @click="goToPage(page + 1)">
                    {{ t('teamhub', 'Next') }}
                    <template #icon><ChevronRight :size="16" /></template>
                </NcButton>
            </div>
        </div>

        <!-- Feed control widget -->
        <aside class="whats-happening__controls" :aria-label="t('teamhub', 'Feed control')">
            <h3 class="whats-happening__controls-title">{{ t('teamhub', 'Feed control') }}</h3>

            <div class="whats-happening__controls-section">
                <div class="whats-happening__controls-label">{{ t('teamhub', 'Show') }}</div>
                <NcCheckboxRadioSwitch
                    v-model="filters.includeTeam"
                    type="switch"
                    :aria-label="t('teamhub', 'Show team messages')"
                    @update:model-value="onFilterChange">
                    {{ t('teamhub', 'Team messages') }}
                </NcCheckboxRadioSwitch>
                <NcCheckboxRadioSwitch
                    v-model="filters.includePublic"
                    type="switch"
                    :aria-label="t('teamhub', 'Show public messages')"
                    @update:model-value="onFilterChange">
                    {{ t('teamhub', 'Public messages') }}
                </NcCheckboxRadioSwitch>
                <NcCheckboxRadioSwitch
                    v-model="filters.includeTalk"
                    type="switch"
                    :aria-label="t('teamhub', 'Show Talk polls and threads')"
                    @update:model-value="onFilterChange">
                    {{ t('teamhub', 'Talk polls & threads') }}
                </NcCheckboxRadioSwitch>
            </div>

            <div class="whats-happening__controls-section">
                <div class="whats-happening__controls-label">{{ t('teamhub', 'Per page') }}</div>
                <!-- Radio-group pattern: v-model on the shared variable
                     (perPage) and :value per item — NcCheckboxRadioSwitch
                     with type=radio then emits the picked value into
                     perPage, which the watcher acts on. The previous
                     per-item boolean binding never fired the click through
                     because NcCheckboxRadioSwitch expects the shared
                     variable, not a boolean. -->
                <NcCheckboxRadioSwitch
                    v-for="opt in perPageOptions"
                    :key="opt"
                    v-model="perPage"
                    :value="opt"
                    type="radio"
                    name="whats-happening-perpage"
                    :aria-label="t('teamhub', '{n} per page', { n: opt })">
                    {{ opt }}
                </NcCheckboxRadioSwitch>
            </div>
        </aside>
    </div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import {
    NcButton,
    NcLoadingIcon,
    NcEmptyContent,
    NcCheckboxRadioSwitch,
    NcAvatar,
} from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Rss from 'vue-material-design-icons/Rss.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'

// localStorage key for the Feed control widget state. Kept in one place
// so the setup + persist paths can't drift. Per-user, per-browser (session B
// scope — server-side sync deferred until we know it's needed).
const STORAGE_KEY = 'teamhub.feedControls'

// Allowed per-page values. Users pick one of these; anything else is
// discarded on load so a hand-edited localStorage value can't ask for
// 10 000 rows. The backend also clamps at 100.
const PER_PAGE_OPTIONS = [20, 50, 100]

export default {
    name: 'WhatsHappeningView',
    components: {
        NcButton, NcLoadingIcon, NcEmptyContent, NcCheckboxRadioSwitch, NcAvatar,
        Refresh, Rss, ChevronLeft, ChevronRight,
    },
    emits: ['open-team', 'open-team-talk'],

    data() {
        return {
            items: [],
            loading: false,
            hasMore: false,
            total: 0,
            page: 1,
            filters: {
                includeTeam: true,
                includePublic: true,
                includeTalk: true,
            },
            perPage: 20,
            perPageOptions: PER_PAGE_OPTIONS,
        }
    },

    computed: {
        emptyTitle() {
            if (!this.filters.includeTeam && !this.filters.includePublic && !this.filters.includeTalk) {
                return t('teamhub', 'Nothing selected')
            }
            return t('teamhub', 'Nothing to show yet')
        },
        emptyBody() {
            if (!this.filters.includeTeam && !this.filters.includePublic && !this.filters.includeTalk) {
                return t('teamhub', 'Enable at least one row in the Feed control widget on the right.')
            }
            return t('teamhub', 'When your teams post messages — or when someone publishes a public message — it will appear here.')
        },
        totalPages() {
            if (this.perPage <= 0) return 1
            return Math.max(1, Math.ceil(this.total / this.perPage))
        },
    },

    watch: {
        // Radio-group change lands on this.perPage directly. Persist +
        // reset to page 1 + refetch (the current page might not exist at
        // the new size, and even if it does the offset math changes).
        perPage(newVal, oldVal) {
            if (newVal === oldVal) return
            this.persist()
            this.page = 1
            this.refresh()
        },
    },

    mounted() {
        this.hydrateFromStorage()
        this.refresh()
    },

    methods: {
        t,
        n,

        // Keys for Talk items must not collide with message ids — an id of
        // 1 could exist in both teamhub_messages and talk_polls.
        itemKey(item) {
            if (item.source === 'talk-poll')   return 'poll-' + item.id
            if (item.source === 'talk-thread') return 'thread-' + item.id
            return 'msg-' + item.id
        },

        itemClass(item) {
            return {
                'whats-happening__item--public': item.isPublic,
                'whats-happening__item--talk':   item.source === 'talk-poll' || item.source === 'talk-thread',
            }
        },

        openTalk(item) {
            // The router in App.vue's onOpenTeamTalk selects the team
            // and then sets currentView='talk'. If the item has no
            // team_id (rare — e.g. a room connected to a circle we
            // couldn't attribute), fall back to just opening the team.
            if (item.team_id) {
                this.$emit('open-team-talk', item.team_id)
            }
        },

        // Talk polls persist their vote tallies as {optionIndex(string) →
        // count(int)}. Coerce keys defensively — some Talk versions ship
        // int keys, others string, and JSON stringifies both to strings.
        voteCount(item, i) {
            const v = item.votes || {}
            return (v[i] ?? v[String(i)] ?? 0) | 0
        },

        // Sum of all option votes. Prefer this to item.num_voters for the
        // per-option percentage because multi-choice polls have
        // sum(votes) > num_voters (each voter picks several options).
        totalVotes(item) {
            const v = item.votes || {}
            let sum = 0
            for (const k of Object.keys(v)) sum += (v[k] | 0)
            return sum
        },

        votePercent(item, i) {
            const total = this.totalVotes(item)
            if (total <= 0) return 0
            return Math.round((this.voteCount(item, i) / total) * 100)
        },

        /**
         * Read the persisted controls from localStorage. Silently drops any
         * value that fails validation so a corrupted store can't lock the
         * user out of the feed.
         */
        hydrateFromStorage() {
            try {
                const raw = window.localStorage.getItem(STORAGE_KEY)
                if (!raw) return
                const parsed = JSON.parse(raw)
                if (typeof parsed?.includeTeam === 'boolean') {
                    this.filters.includeTeam = parsed.includeTeam
                }
                if (typeof parsed?.includePublic === 'boolean') {
                    this.filters.includePublic = parsed.includePublic
                }
                if (typeof parsed?.includeTalk === 'boolean') {
                    this.filters.includeTalk = parsed.includeTalk
                }
                if (PER_PAGE_OPTIONS.includes(parsed?.perPage)) {
                    this.perPage = parsed.perPage
                }
            } catch (e) {
                // localStorage unavailable (private mode, disabled) or JSON
                // corrupt — fall through to defaults.
            }
        },

        persist() {
            try {
                window.localStorage.setItem(STORAGE_KEY, JSON.stringify({
                    includeTeam:   this.filters.includeTeam,
                    includePublic: this.filters.includePublic,
                    includeTalk:   this.filters.includeTalk,
                    perPage:       this.perPage,
                }))
            } catch (e) {
                // Non-fatal — quota exceeded, storage disabled, etc.
            }
        },

        onFilterChange() {
            this.persist()
            // Filter change invalidates the current page number — the
            // shape of the result set is different.
            this.page = 1
            this.refresh()
        },

        goToPage(target) {
            const clamped = Math.max(1, Math.min(target, this.totalPages))
            if (clamped === this.page) return
            this.page = clamped
            this.refresh()
        },

        async refresh() {
            if (this.loading) return
            this.loading = true
            try {
                const offset = (this.page - 1) * this.perPage
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/messages/feed'), {
                    params: {
                        includeTeam:   this.filters.includeTeam   ? 1 : 0,
                        includePublic: this.filters.includePublic ? 1 : 0,
                        includeTalk:   this.filters.includeTalk   ? 1 : 0,
                        limit:  this.perPage,
                        offset,
                    },
                })
                this.items = Array.isArray(data?.items) ? data.items : []
                this.hasMore = !!data?.hasMore
                this.total = Number.isFinite(data?.total) ? data.total : this.items.length
                // Clamp back if server says the page we asked for is now
                // past the end (e.g. content deleted between fetches).
                if (this.page > this.totalPages && this.totalPages >= 1) {
                    this.page = this.totalPages
                    // Re-fetch once with the corrected page number.
                    this.loading = false
                    await this.refresh()
                    return
                }
            } catch (e) {
                // v4.3.0 — the endpoint returns 403 + {licenseGate:true}
                // when the instance loses its license. Surface a
                // license-specific message instead of a generic error so
                // the admin knows where to look.
                if (e?.response?.status === 403 && e?.response?.data?.licenseGate) {
                    showError(t('teamhub', 'What’s new requires an active TeamHub license.'))
                } else {
                    showError(t('teamhub', 'Failed to load feed'))
                }
            } finally {
                this.loading = false
            }
        },

        openTeam(item) {
            if (item.team_id) {
                this.$emit('open-team', item.team_id)
            }
        },

        formatTimestamp(secs) {
            if (!secs) return ''
            return new Date(secs * 1000).toLocaleString()
        },

        /**
         * Cheap body-preview extractor. Strips markdown image / link
         * wrappers, code fences, HTML tags, and collapses whitespace, then
         * truncates. No sanitizer needed — output goes into a text node
         * via `{{ }}`, so Vue auto-escapes.
         */
        preview(body) {
            if (!body) return ''
            let s = String(body)
            // Strip code fences (```…```)
            s = s.replace(/```[\s\S]*?```/g, ' ')
            // Strip inline code (`…`)
            s = s.replace(/`[^`]*`/g, ' ')
            // Strip image markdown ![alt](url) — drop the whole thing
            s = s.replace(/!\[[^\]]*\]\([^)]*\)/g, ' ')
            // Convert link markdown [text](url) to just text
            s = s.replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
            // Strip HTML tags (defensive — Vue will re-escape anyway)
            s = s.replace(/<[^>]*>/g, ' ')
            // Collapse whitespace
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
    padding: 20px 24px;
    align-items: flex-start;
    // Two columns on wide screens; stacked on narrow.
    @media (max-width: 900px) {
        flex-direction: column;
    }
}

.whats-happening__main {
    flex: 1 1 auto;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.whats-happening__header {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    // Left padding clears NC's sidebar toggle button in the top-left of
    // the content area. Roughly the toggle's 44px hit area + a small gap.
    padding-left: 48px;
}

.whats-happening__header-text {
    // Title stacks vertically over the subtitle so a long subtitle
    // doesn't push against the refresh button.
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

.whats-happening__refresh {
    flex: 0 0 auto;
}

.whats-happening__loading {
    display: flex;
    justify-content: center;
    padding: 32px 0;
}

.whats-happening__list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.whats-happening__item {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--th-radius-card, var(--border-radius-large));
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.whats-happening__item--public {
    // Subtle left accent so public posts read differently from team ones.
    border-left: 3px solid var(--color-primary-element);
}

.whats-happening__item-meta {
    display: flex;
    align-items: center;
    gap: 10px;
}

.whats-happening__item-titleline {
    display: flex;
    align-items: baseline;
    gap: 8px;
    flex-wrap: wrap;
    flex: 1 1 auto;
    min-width: 0;
}

// Team-name button. Reset the browser button chrome + inherit type;
// underline on hover to signal it opens the team. Not an NcButton
// because the visual weight here is "in-line link", not a full button.
.whats-happening__team-btn {
    background: none;
    border: none;
    padding: 0;
    cursor: pointer;
    color: var(--color-primary-element);
    font-weight: var(--th-font-weight-semibold, 600);
    font-size: var(--th-font-body, 14px);
    &:hover, &:focus-visible {
        text-decoration: underline;
    }
    &:focus-visible {
        outline: 2px solid var(--color-primary-element);
        outline-offset: 2px;
        border-radius: 2px;
    }
}

.whats-happening__badge {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    border-radius: var(--th-radius-pill, 999px);
    font-size: var(--th-font-micro, 11px);
    font-weight: var(--th-font-weight-semibold, 600);
    line-height: 1.2;
}

// Solid primary background + on-primary text — the previous
// primary-element-light + primary-element-text pair produced primary text
// on a light-primary background, which fails contrast in most themes.
// The new pair (primary-element background + primary-element-text) is
// NC's canonical "filled chip" combination and stays readable on light
// and dark themes.
.whats-happening__badge--public {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

// Talk badge: neutral chip so it doesn't compete with the Public one.
// var(--color-background-dark) reads as "informational neutral" in both
// themes and pairs with --color-main-text for legibility.
.whats-happening__badge--talk {
    background: var(--color-background-dark);
    color: var(--color-main-text);
    border: 1px solid var(--color-border);
}

// Talk card accent — subtle left border in a distinct hue so a Talk row
// reads differently from a Public one without shouting.
.whats-happening__item--talk {
    border-left: 3px solid var(--color-success, var(--color-primary-element));
}

// Talk poll options — each option is a label row + a proportional bar.
// Bar communicates the vote distribution at a glance; count + percent
// keep the numbers close to the option label (no more wide gap).
.whats-happening__poll-options {
    list-style: none;
    margin: 6px 0 4px;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.whats-happening__poll-option {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: var(--th-font-body, 14px);
}

.whats-happening__poll-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 8px;
}

.whats-happening__poll-label {
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-weight: var(--th-font-weight-medium, 500);
}

.whats-happening__poll-count {
    flex: 0 0 auto;
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta, 12px);
    font-variant-numeric: tabular-nums;
}

.whats-happening__poll-bar {
    height: 6px;
    background: var(--color-background-dark);
    border-radius: 3px;
    overflow: hidden;
}

.whats-happening__poll-bar-fill {
    height: 100%;
    background: var(--color-primary-element);
    border-radius: 3px;
    transition: width 200ms ease-out;
    // Zero-width bar still shows the track colour instead of a hairline
    // sliver on the very first render.
    min-width: 0;
}

.whats-happening__poll-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-top: 6px;
}

.whats-happening__poll-voters {
    font-size: var(--th-font-meta, 12px);
    color: var(--color-text-maxcontrast);
}

// Talk-item jump button — plain link visual, no full NcButton chrome so
// it reads as an inline affordance under the card, not a call-to-action.
.whats-happening__talk-jump {
    background: none;
    border: none;
    padding: 4px 0 0;
    cursor: pointer;
    color: var(--color-primary-element);
    font-size: var(--th-font-meta, 12px);
    font-weight: var(--th-font-weight-semibold, 600);
    text-align: left;
    &:hover, &:focus-visible {
        text-decoration: underline;
    }
    &:focus-visible {
        outline: 2px solid var(--color-primary-element);
        outline-offset: 2px;
        border-radius: 2px;
    }
}

.whats-happening__timestamp {
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta, 12px);
    margin-left: auto;
}

.whats-happening__subject {
    font-weight: var(--th-font-weight-semibold, 600);
    font-size: var(--th-font-body, 14px);
    line-height: var(--th-line-height-tight, 1.2);
}

.whats-happening__preview {
    color: var(--color-text-light);
    font-size: var(--th-font-body, 14px);
    line-height: var(--th-line-height-body, 1.4);
    margin: 0;
    // Three-line clamp — reads at a glance without dominating the row.
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.whats-happening__author {
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta, 12px);
}

.whats-happening__pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 12px 0 16px;
}

.whats-happening__pagination-label {
    font-size: var(--th-font-meta, 12px);
    color: var(--color-text-maxcontrast);
    // Prevents jitter when the page number changes width.
    min-width: 8ch;
    text-align: center;
}

/* Right-side Feed control widget */
.whats-happening__controls {
    flex: 0 0 260px;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--th-radius-card, var(--border-radius-large));
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 14px;
    position: sticky;
    top: 20px;

    @media (max-width: 900px) {
        position: static;
        flex: 1 1 auto;
        order: -1; // stack the widget above the feed on narrow screens
    }
}

.whats-happening__controls-title {
    font-size: var(--th-font-heading, 16px);
    font-weight: var(--th-font-weight-bold, 700);
    margin: 0;
}

.whats-happening__controls-section {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.whats-happening__controls-label {
    font-size: var(--th-font-meta, 12px);
    font-weight: var(--th-font-weight-semibold, 600);
    color: var(--color-text-maxcontrast);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 2px;
}
</style>
