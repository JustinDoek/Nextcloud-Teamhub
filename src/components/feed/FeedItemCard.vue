<template>
    <li class="feed-card" :class="`feed-card--${tone}`">
        <div class="feed-card__main">
            <!-- Glyph chip. Tinted per kind so the list can be scanned by
                 shape; the badge beside the title carries the same fact in
                 words, so colour is never the only signal (WCAG 1.4.1). -->
            <span class="feed-card__glyph" aria-hidden="true">
                <component :is="glyphIcon" :size="ICON_TOOLBAR" />
            </span>

            <div class="feed-card__body">
                <div class="feed-card__titleline">
                    <span class="feed-card__title">{{ title }}</span>
                    <span class="feed-card__badge">{{ kindLabel }}</span>
                </div>

                <!-- v4.6.25 — a TeamHub message renders its markdown, the same
                     sanitised HTML the team stream shows. It used to arrive as
                     a preview string with every newline collapsed to a space,
                     so a bulleted post read as one run-on line and bold was
                     gone. A Talk message keeps the plain preview: its body is
                     not our markdown flavour, and running it through our
                     renderer would italicise the underscores in a filename.
                     Sanitised by renderMarkdown via DOMPurify — see
                     src/lib/messageMarkdown.js. -->
                <!-- eslint-disable-next-line vue/no-v-html -->
                <div
                    v-if="renderedBody"
                    class="feed-card__subtitle feed-card__subtitle--rich"
                    v-html="renderedBody" />
                <p v-else-if="subtitle" class="feed-card__subtitle">{{ subtitle }}</p>

                <div class="feed-card__teamline">
                    <AccountGroup :size="ICON_INLINE" aria-hidden="true" />
                    <!-- Raw <button>: an inline link-weight affordance inside a
                         meta line, not a call to action. Same carve-out the
                         previous version of this view used for the team name.
                         v4.5.39 — plain text for a public post from a team the
                         viewer is not in. The link led to a team page that
                         renders empty for a non-member, which reads as a broken
                         app rather than as a boundary. The name still shows:
                         knowing which team published it is the point of a
                         public message. -->
                    <button
                        v-if="canOpenTeam"
                        type="button"
                        class="feed-card__team-btn"
                        :title="t('teamhub', 'Open {team}', { team: teamName })"
                        @click="$emit('open-team', item)">
                        {{ teamName }}
                    </button>
                    <span v-else class="feed-card__team-name">{{ teamName }}</span>
                    <template v-if="item.room_name">
                        <span class="feed-card__sep" aria-hidden="true">·</span>
                        <span>{{ item.room_name }}</span>
                    </template>
                    <!-- v4.9.7 — the OpenProject project the news is from, as
                         a link that leaves TeamHub (the title says so), and
                         the author as OpenProject names them. -->
                    <template v-if="isOpenProject && openProjectName">
                        <span class="feed-card__sep" aria-hidden="true">·</span>
                        <button
                            v-if="openProjectUrl"
                            type="button"
                            class="feed-card__team-btn"
                            :title="t('teamhub', 'Open project {project} in OpenProject (new tab)', { project: openProjectName })"
                            @click="openExternal(openProjectUrl)">
                            {{ openProjectName }}
                        </button>
                        <span v-else>{{ openProjectName }}</span>
                    </template>
                    <template v-if="isOpenProject && item.actor_name">
                        <span class="feed-card__sep" aria-hidden="true">·</span>
                        <!-- TRANSLATORS: What's new — who wrote an OpenProject news item; {name} is the person's name in OpenProject -->
                        <span>{{ t('teamhub', 'by {name}', { name: item.actor_name }) }}</span>
                    </template>
                    <!-- v4.9.9 — a team message mirrored from OpenProject news
                         says so here too, the same pill the stream shows. -->
                    <template v-if="isMirroredNews">
                        <span class="feed-card__sep" aria-hidden="true">·</span>
                        <span>{{ t('teamhub', 'Source:') }}</span>
                        <a
                            v-if="originUrl"
                            class="th-widget__pill th-widget__pill--outline feed-card__origin-pill"
                            :href="originUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            :title="t('teamhub', 'Open this news item in OpenProject')">
                            {{ t('teamhub', 'OpenProject') }}
                        </a>
                        <span v-else class="th-widget__pill th-widget__pill--outline feed-card__origin-pill">
                            {{ t('teamhub', 'OpenProject') }}
                        </span>
                    </template>
                </div>

                <!-- Talk poll — options with proportional bars. When the viewer
                     may vote, each option is a button; otherwise the same
                     markup renders as static rows, so the layout does not jump
                     between the two states. -->
                <ul v-if="isTalkPoll && pollOptions.length" class="feed-card__poll">
                    <li v-for="(opt, i) in pollOptions" :key="i" class="feed-card__poll-option">
                        <component
                            :is="item.can_vote ? 'button' : 'div'"
                            :type="item.can_vote ? 'button' : null"
                            class="feed-card__poll-row"
                            :class="{
                                'feed-card__poll-row--votable': item.can_vote,
                                'feed-card__poll-row--mine': hasVoted(i),
                            }"
                            :aria-pressed="item.can_vote ? String(hasVoted(i)) : null"
                            :disabled="item.can_vote && voting ? true : null"
                            @click="item.can_vote ? vote(i) : null">
                            <span class="feed-card__poll-label">
                                <Check
                                    v-if="hasVoted(i)"
                                    :size="ICON_INLINE"
                                    class="feed-card__poll-check"
                                    aria-hidden="true" />
                                {{ opt }}
                            </span>
                            <span class="feed-card__poll-count">
                                {{ voteCount(i) }} · {{ votePercent(i) }}%
                            </span>
                        </component>
                        <div
                            class="feed-card__poll-bar"
                            role="img"
                            :aria-label="t('teamhub', '{option}: {n} of {total} votes', {
                                option: opt,
                                n: voteCount(i),
                                total: totalVotes,
                            })">
                            <div
                                class="feed-card__poll-fill"
                                :style="{ width: votePercent(i) + '%' }" />
                        </div>
                    </li>
                </ul>

                <p v-if="voteError" class="feed-card__error" role="alert">{{ voteError }}</p>

                <FeedItemThread
                    v-if="showThread"
                    :item="item"
                    @count-changed="$emit('count-changed', { item, count: $event })" />
            </div>

            <div class="feed-card__aside">
                <span v-if="statusPill" class="feed-card__pill" :class="`feed-card__pill--${statusPill.tone}`">
                    {{ statusPill.text }}
                </span>

                <!-- v4.9.7 — an OpenProject row has no Nextcloud author to
                     draw; the glyph chip on the left already says where it
                     is from, so the rail simply has no avatar. -->
                <NcAvatar
                    v-if="!isOpenProject"
                    :user="item.author_id"
                    :display-name="authorName"
                    :size="AVATAR_SIZE"
                    :show-user-status="false"
                    :disable-menu="true"
                    class="feed-card__avatar" />

                <span class="feed-card__time" :title="absoluteTime">{{ relativeTime }}</span>

                <!-- v4.5.39 — every destination on this card is inside the
                     team, so a viewer who is not in it gets none of them. The
                     card is still worth showing: a public message is published
                     *to* them, and reading it is the whole transaction. -->
                <NcButton
                    v-if="canOpenTeam"
                    variant="tertiary"
                    class="feed-card__open"
                    :aria-label="openLabel"
                    :title="openLabel"
                    @click="onOpen">
                    <template #icon>
                        <OpenInNew :size="ICON_BODY" />
                    </template>
                </NcButton>

                <NcActions
                    v-if="canOpenTeam"
                    :aria-label="t('teamhub', 'More actions for {title}', { title })">
                    <NcActionButton @click="$emit('open-team', item)">
                        <template #icon><AccountGroup :size="ICON_BODY" /></template>
                        {{ t('teamhub', 'Open team') }}
                    </NcActionButton>
                    <NcActionButton v-if="isTalk" @click="$emit('open-talk', item)">
                        <template #icon><Forum :size="ICON_BODY" /></template>
                        {{ t('teamhub', 'Open chat') }}
                    </NcActionButton>
                    <!-- v4.9.7 — OpenProject news: the project itself. -->
                    <NcActionButton v-if="isOpenProject && openProjectUrl" @click="openExternal(openProjectUrl)">
                        <template #icon><BriefcaseOutline :size="ICON_BODY" /></template>
                        {{ t('teamhub', 'Open project in OpenProject') }}
                    </NcActionButton>
                </NcActions>
            </div>
        </div>
    </li>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { formatDate, formatTime, formatDateTime, todayIso, shiftIsoDate, fromDateInput } from '../../lib/localDate.js'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { renderMarkdown } from '../../lib/messageMarkdown.js'
import { NcActions, NcActionButton, NcAvatar, NcButton } from '@nextcloud/vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import At from 'vue-material-design-icons/At.vue'
import Check from 'vue-material-design-icons/Check.vue'
import ChartBox from 'vue-material-design-icons/ChartBox.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Earth from 'vue-material-design-icons/Earth.vue'
import Forum from 'vue-material-design-icons/Forum.vue'
import Gavel from 'vue-material-design-icons/Gavel.vue'
import HelpCircle from 'vue-material-design-icons/HelpCircle.vue'
import Message from 'vue-material-design-icons/Message.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Poll from 'vue-material-design-icons/Poll.vue'
// v4.9.7 — OpenProject news rows: the source glyph.
import BriefcaseOutline from 'vue-material-design-icons/BriefcaseOutline.vue'
import FeedItemThread from './FeedItemThread.vue'
import { ICON_INLINE, ICON_BODY, ICON_TOOLBAR } from '../../constants/uiTokens.js'
import { feedItemKind, feedKindLabel, feedKindTone } from '../../constants/feed.js'

/** Author avatar on the card. One step up from the thread's 24px. */
const AVATAR_SIZE = 28

/**
 * Glyph per kind. Talk threads and Talk polls share the cyan tone but not the
 * icon — the two are different things and the icon is what says so.
 */
const KIND_ICONS = {
    'talk-poll': ChartBox,
    'talk-thread': Forum,
    'talk-mention': At,
    system: Cog,
    question: HelpCircle,
    poll: Poll,
    decision: Gavel,
    public: Earth,
    message: Message,
    openproject: BriefcaseOutline,
}

export default {
    name: 'FeedItemCard',
    components: {
        NcActions,
        NcActionButton,
        NcAvatar,
        NcButton,
        FeedItemThread,
        AccountGroup,
        BriefcaseOutline,
        Check,
        Forum,
        OpenInNew,
    },

    props: {
        item: {
            type: Object,
            required: true,
        },
        /** Body-preview text, extracted by the parent so the regex runs once. */
        preview: {
            type: String,
            default: '',
        },
    },

    emits: ['open-item', 'open-team', 'open-talk', 'count-changed', 'voted'],

    data() {
        return {
            voting: false,
            voteError: '',
            ICON_INLINE,
            ICON_BODY,
            ICON_TOOLBAR,
            AVATAR_SIZE,
        }
    },

    computed: {
        kind() {
            return feedItemKind(this.item)
        },

        tone() {
            return feedKindTone(this.kind)
        },

        kindLabel() {
            return feedKindLabel(this.kind)
        },

        glyphIcon() {
            return KIND_ICONS[this.kind] || Message
        },

        isTalk() {
            return this.kind === 'talk-poll'
                || this.kind === 'talk-thread'
                || this.kind === 'talk-mention'
        },

        isTalkPoll() {
            return this.kind === 'talk-poll'
        },

        /** v4.5.33 — a Talk chat message that names the viewer. */
        isTalkMention() {
            return this.kind === 'talk-mention'
        },

        /** v4.9.9 — a team message the news mirror wrote. */
        isMirroredNews() {
            // A System post, so not keyed on the message kind: the ledger's
            // stamp is the fact, whatever badge the row wears.
            return !this.isOpenProject && this.item.origin?.kind === 'openproject'
        },
        originUrl() {
            return this.isMirroredNews ? this.safeExternal(this.item.origin?.url) : ''
        },

        /** v4.9.7 — an OpenProject news row. */
        isOpenProject() {
            return this.kind === 'openproject'
        },

        openProjectName() {
            return this.item.project?.name || ''
        },

        /**
         * Links on an OpenProject row come from the server, which builds them
         * from the administrator's configured host and a numeric id — but a
         * URL is still checked for an http(s) scheme before the browser gets
         * it (the same rule My Work's navigation actions follow).
         */
        openProjectUrl() {
            return this.safeExternal(this.item.project?.url)
        },

        /**
         * Whether this row has anywhere to go (v4.5.39).
         *
         * False only for a public message from a team the viewer is not in —
         * the one row in the feed that is deliberately readable without
         * membership. Every destination the card offers (the team name, Open,
         * Open team, Open chat) lands inside that team, where a non-member sees
         * an empty page.
         *
         * A row from a pre-4.5.39 server carries no flag; missing means yes, so
         * the affordances behave exactly as they did rather than all vanishing
         * against a server that has not been upgraded yet.
         */
        canOpenTeam() {
            return this.item.can_open_team !== false
        },

        title() {
            if (this.isTalkPoll) {
                return this.item.question || t('teamhub', 'Untitled poll')
            }
            if (this.isTalkMention) {
                // A chat message has no subject, so the person who wrote it is
                // the most useful heading — the body is right underneath.
                return t('teamhub', '{author} mentioned you', {
                    author: this.authorName || t('teamhub', 'Someone'),
                })
            }
            return this.item.subject || t('teamhub', 'Untitled')
        },

        subtitle() {
            // A poll's options are rendered in full below, so repeating the
            // body as a preview would say the same thing twice.
            if (this.isTalkPoll) return ''
            // v4.9.7 — an OpenProject news row's line is its summary, plain
            // text as the server reduced it (the parent's preview of it).
            return this.preview
        },

        /**
         * The message body as sanitised HTML, for TeamHub messages only
         * (v4.6.25). Empty for anything Talk-sourced and for polls, which fall
         * back to the plain `preview` string above.
         *
         * No members map is passed: the feed spans every team the viewer is in
         * and the card has no roster for any of them. `resolveMentionToken`
         * answers null for an id it does not know and the renderer then leaves
         * the text exactly as written, so a mention shows as `@jdoek` rather
         * than a display name. That is the intended degradation, not a gap —
         * fetching a roster per card to style one span is not worth a
         * round-trip.
         */
        renderedBody() {
            if (this.isTalk || this.isTalkPoll || this.isOpenProject) return ''
            return renderMarkdown(this.item.message || '')
        },

        teamName() {
            return this.item.team_name || this.item.team_id || t('teamhub', 'Unknown team')
        },

        authorName() {
            return this.item.author_display_name || this.item.author_id || ''
        },

        /**
         * A thread is offered when there is something to read or something the
         * viewer may write.
         *
         * v4.8.7 — a public post from a team the viewer is not in now has the
         * first of those: its comments are readable by everyone, so the thread
         * renders. Writing is still members-only, and that is a separate flag
         * — `FeedItemThread` gates its composer on `can_comment`, so the
         * thread comes up read-only rather than offering a box the server
         * would refuse.
         *
         * The card still ends at its body for a *non-public* post from a team
         * they are not in, and for a message type the team takes no comments
         * on.
         */
        showThread() {
            if (this.isTalkPoll || this.isOpenProject) return false
            if (this.kind === 'talk-thread' || this.isTalkMention) return true
            return !!this.item.can_view_comments
        },

        /**
         * The right-hand pill. Only rendered when there is a fact worth
         * promoting out of the body — an empty pill slot is better than a pill
         * that says "normal".
         */
        statusPill() {
            if (this.isTalkPoll) {
                if (this.item.status !== 0) {
                    return { text: t('teamhub', 'Closed'), tone: 'neutral' }
                }
                const v = this.item.num_voters || 0
                return {
                    text: n('teamhub', '{n} voter', '{n} voters', v, { n: v }),
                    tone: 'neutral',
                }
            }
            if (this.kind === 'talk-thread') {
                const r = this.item.num_replies || 0
                return {
                    text: n('teamhub', '{n} reply', '{n} replies', r, { n: r }),
                    tone: 'neutral',
                }
            }
            // v4.5.39 — no "Mentioned you" pill. The card already says it
            // twice: the title reads "{author} mentioned you" and the badge
            // beside it reads "Talk mention". A third statement of the same
            // fact was the loudest thing on the row, and in warning tone.
            if (this.kind === 'question' && this.item.questionSolved) {
                return { text: t('teamhub', 'Solved'), tone: 'success' }
            }
            if (this.kind === 'poll' && this.item.pollClosed) {
                return { text: t('teamhub', 'Closed'), tone: 'neutral' }
            }
            if (this.item.priority === 'urgent') {
                return { text: t('teamhub', 'Urgent'), tone: 'urgent' }
            }
            if (this.item.priority === 'high') {
                return { text: t('teamhub', 'High'), tone: 'warning' }
            }
            return null
        },

        openLabel() {
            if (this.isOpenProject) {
                // TRANSLATORS: button on a What's new card — opens the news item in OpenProject, in a new browser tab
                return t('teamhub', 'Open in OpenProject (new tab)')
            }
            if (this.isTalk) {
                return t('teamhub', 'Open chat')
            }
            if (this.kind === 'decision') {
                return t('teamhub', 'Open decision')
            }
            return t('teamhub', 'Open message in {team}', { team: this.teamName })
        },

        pollOptions() {
            return Array.isArray(this.item.options) ? this.item.options : []
        },

        /**
         * Sum of all option votes — not `num_voters`. A multi-choice poll has
         * sum(votes) > num_voters because each voter picks several options, so
         * using num_voters as the denominator makes the bars overflow.
         */
        totalVotes() {
            const v = this.item.votes || {}
            return Object.keys(v).reduce((sum, k) => sum + (v[k] | 0), 0)
        },

        /**
         * True when the source app does not record when this was created —
         * currently only Talk polls, on schemas with no timestamp column. The
         * feed used to fabricate a date here; v4.5.26 stopped, because a
         * made-up "3 minutes ago" is worse than an honest blank.
         */
        dateUnknown() {
            return !!this.item.date_unknown || !this.item.created_at
        },

        absoluteTime() {
            if (this.dateUnknown) {
                return t('teamhub', 'Talk does not record when this poll was created.')
            }
            return formatDateTime(this.item.created_at * 1000)
        },

        /**
         * Short form for the right rail: a clock time for today, a weekday for
         * the last week, a date beyond that. The full timestamp is on the
         * title attribute, so nothing is lost.
         */
        relativeTime() {
            if (this.dateUnknown) {
                // An em dash rather than an empty cell — blank reads as a
                // rendering bug, this reads as "not applicable".
                return '—'
            }
            const secs = this.item.created_at
            const ms = secs * 1000
            // Each boundary is a real midnight for the reader rather than a
            // multiple of 86400 subtracted from one — the day either side of
            // a DST change is 23 or 25 hours long, which put the "Yesterday"
            // cutoff an hour out twice a year.
            const today = todayIso()
            const todayStart = fromDateInput(today)
            const yesterdayStart = fromDateInput(shiftIsoDate(today, { days: -1 }))
            const weekStart = fromDateInput(shiftIsoDate(today, { days: -6 }))

            if (secs >= todayStart) {
                return formatTime(ms, { hour: '2-digit', minute: '2-digit' })
            }
            if (secs >= yesterdayStart) {
                return t('teamhub', 'Yesterday')
            }
            if (secs >= weekStart) {
                return formatDate(ms, { weekday: 'short' })
            }
            return formatDate(ms, { month: 'short', day: 'numeric' })
        },
    },

    methods: {
        t,
        n,

        /**
         * v4.5.26 — Open goes to the item itself. It used to emit `open-team`,
         * which landed you on the team and left you to find the message; the
         * parent now routes on what the row is. The ⋮ menu keeps a plain
         * "Open team" for when the team, not the item, is what you wanted.
         */
        onOpen() {
            this.$emit('open-item', this.item)
        },

        /**
         * v4.9.7 — a link that leaves TeamHub: new tab, no opener, and only
         * ever an http(s) URL (`safeExternal` refused anything else already).
         */
        openExternal(url) {
            const safe = this.safeExternal(url)
            if (safe) {
                window.open(safe, '_blank', 'noopener,noreferrer')
            }
        },

        safeExternal(url) {
            if (typeof url !== 'string' || url === '') return ''
            try {
                const parsed = new URL(url)
                return parsed.protocol === 'https:' || parsed.protocol === 'http:' ? parsed.href : ''
            } catch (e) {
                return ''
            }
        },

        // Talk persists tallies as {optionIndex(string) → count(int)}. Coerce
        // the key both ways — some Talk versions ship int keys, others string,
        // and JSON stringifies both.
        voteCount(i) {
            const v = this.item.votes || {}
            return (v[i] ?? v[String(i)] ?? 0) | 0
        },

        votePercent(i) {
            if (this.totalVotes <= 0) return 0
            return Math.round((this.voteCount(i) / this.totalVotes) * 100)
        },

        hasVoted(i) {
            return Array.isArray(this.item.my_votes) && this.item.my_votes.includes(i)
        },

        /**
         * Toggle option `i`. Talk treats an empty option list as retracting the
         * vote, so unticking your only choice is a valid call rather than
         * something to block client-side.
         */
        async vote(i) {
            if (this.voting || !this.item.can_vote) return

            const current = Array.isArray(this.item.my_votes) ? [...this.item.my_votes] : []
            const at = current.indexOf(i)
            if (at >= 0) {
                current.splice(at, 1)
            } else {
                current.push(i)
            }

            this.voting = true
            this.voteError = ''
            try {
                const { data } = await axios.post(
                    generateUrl('/apps/teamhub/api/v1/feed/talk/{token}/polls/{pollId}/vote', {
                        token: this.item.room_token,
                        pollId: this.item.id,
                    }),
                    { optionIds: current },
                )
                // The parent owns the item, so it applies the new state — this
                // component never mutates a prop. The response carries the
                // fresh tallies, so nothing has to refetch the page.
                this.$emit('voted', {
                    item: this.item,
                    myVotes: data?.my_votes || current,
                    votes: data?.votes ?? null,
                    numVoters: data?.num_voters ?? null,
                    status: data?.status ?? null,
                })
            } catch (e) {
                this.voteError = e?.response?.data?.error
                    || t('teamhub', 'Could not record your vote.')
            } finally {
                this.voting = false
            }
        },
    },
}
</script>

<style scoped lang="scss">
.feed-card {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--th-radius-card, var(--border-radius-large));
    padding: 12px 14px;
}

.feed-card__main {
    display: flex;
    gap: 12px;
    align-items: flex-start;
}

// Square tinted chip. Fixed on every axis so a taller card can't stretch it
// into a rectangle — the same failure mode SKILLS.md § "UI shapes" documents
// for circles, and the same fix: pin width AND height AND their min/max, and
// keep it out of the flex sizing.
.feed-card__glyph {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    box-sizing: border-box;
    width: 32px;
    height: 32px;
    min-width: 32px;
    min-height: 32px;
    max-width: 32px;
    max-height: 32px;
    padding: 0;
    border-radius: var(--th-radius-chip, 10px);
    background: var(--th-feed-tone-soft);
    color: var(--th-feed-tone-ink);
}

.feed-card__body {
    flex: 1 1 auto;
    min-width: 0;
}

.feed-card__titleline {
    display: flex;
    align-items: baseline;
    gap: 8px;
    flex-wrap: wrap;
}

.feed-card__title {
    font-size: var(--th-font-body, 14px);
    font-weight: var(--th-font-weight-semibold, 600);
    line-height: var(--th-line-height-tight, 1.2);
    overflow-wrap: anywhere;
}

.feed-card__badge {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    border-radius: var(--th-radius-pill, 999px);
    font-size: var(--th-font-micro, 11px);
    font-weight: var(--th-font-weight-semibold, 600);
    line-height: 1.2;
    white-space: nowrap;
    background: var(--th-feed-tone-soft);
    color: var(--th-feed-tone-ink);
}

.feed-card__subtitle {
    margin: 4px 0 0;
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta, 12px);
    line-height: var(--th-line-height-body, 1.4);
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* v4.6.25 — the rendered-markdown variant.
 *
 * `-webkit-line-clamp` only clamps inline content inside a `-webkit-box`, so
 * it cannot be reused here: a <ul> or a <br> makes the box a block container
 * and the clamp silently stops applying. Height-capping instead keeps the
 * feed's rows to a scannable size whatever the body contains, and the fade
 * says "there is more" without a JS measurement.
 *
 * Every rule below needs `:deep()`. Scoped CSS attaches its data attribute to
 * the last compound selector, and `v-html` children never carry one — a plain
 * `.feed-card__subtitle--rich p` would compile to `p[data-v-…]` and match
 * nothing. This is the trap HANDOFF records against `.th-dv__detail-answer-text--md`.
 */
.feed-card__subtitle--rich {
    display: block;
    max-height: 7.2em;   /* ~5 lines at the 1.4 line-height above */
    overflow: hidden;
}

/* No truncation fade, deliberately.
 *
 * The first version painted a 2.1em gradient at `bottom: 0`. A one-line body
 * makes the box ~1.4em tall, so the fade was taller than the content and
 * greyed out the whole line — on the most common card in the feed, and on
 * content that was not truncated at all. Anchoring the gradient to the top
 * instead (so `overflow: hidden` clips it while the box is short) shrinks that
 * window but does not close it: anything between the fade's start and the
 * max-height still fades without being cut.
 *
 * A fade is only honest if it knows the content overflowed, and CSS cannot
 * know that — it needs a measurement. Not worth a ResizeObserver per card for
 * a decoration, so the body simply ends at the cap and "Open" is what shows
 * the rest.
 */

/* Tame the block elements the renderer can emit so a card stays a card.
   Headings drop to body weight rather than heading size — a feed row is not
   the place for an H1, but the author's emphasis is still worth keeping. */
.feed-card__subtitle--rich :deep(p),
.feed-card__subtitle--rich :deep(ul),
.feed-card__subtitle--rich :deep(ol) {
    margin: 0 0 4px;
}

/* NC's global stylesheet sets `ul { list-style: none }` and resets font-style,
   so a bulleted post renders as unmarked lines and italics render upright
   unless each is restored. `MessageCard` carries the same three rules for the
   same reason — this is not optional styling, it is undoing a global reset. */
.feed-card__subtitle--rich :deep(ul),
.feed-card__subtitle--rich :deep(ol) {
    padding-inline-start: 1.2em;
}

.feed-card__subtitle--rich :deep(ul) {
    list-style: disc outside;
}

.feed-card__subtitle--rich :deep(ol) {
    list-style: decimal outside;
}

.feed-card__subtitle--rich :deep(li) {
    margin: 0;
}

.feed-card__subtitle--rich :deep(em) {
    font-style: italic;
}

.feed-card__subtitle--rich :deep(strong) {
    font-weight: var(--th-font-weight-bold, 700);
}

.feed-card__subtitle--rich :deep(h1),
.feed-card__subtitle--rich :deep(h2),
.feed-card__subtitle--rich :deep(h3) {
    margin: 0 0 2px;
    font-size: var(--th-font-meta, 12px);
    font-weight: var(--th-font-weight-semibold, 600);
    color: var(--color-main-text);
}

/* Code can arrive arbitrarily wide; the card must not scroll sideways. */
.feed-card__subtitle--rich :deep(pre) {
    margin: 0 0 4px;
    padding: 4px 6px;
    overflow-x: auto;
    background: var(--color-background-dark);
    border-radius: var(--th-radius-chip, 10px);
}

.feed-card__subtitle--rich :deep(code) {
    font-size: var(--th-font-micro, 11px);
}

/* An inline image in a feed row is a thumbnail, not the content. Without this
   a 2000px-wide upload would set the height of the whole row. */
.feed-card__subtitle--rich :deep(img) {
    max-width: 100%;
    max-height: 3em;
    width: auto;
    border-radius: var(--th-radius-chip, 10px);
    vertical-align: middle;
}

/* The card itself is the click target, so a link inside the preview should
   read as text rather than compete with it — underline on hover only. */
.feed-card__subtitle--rich :deep(a) {
    color: inherit;
    text-decoration: underline;
    text-decoration-style: dotted;
}

.feed-card__subtitle--rich :deep(a:hover),
.feed-card__subtitle--rich :deep(a:focus-visible) {
    color: var(--color-primary-element);
    text-decoration-style: solid;
}

.feed-card__teamline {
    display: flex;
    align-items: center;
    gap: 4px;
    margin-top: 4px;
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-micro, 11px);
    flex-wrap: wrap;
}

/* v4.9.9 — the Source pill of a mirrored news item, in the OpenProject
   ink the news card's glyph uses, so the two rows read as one source. */
.feed-card__origin-pill {
    color: var(--th-feed-openproject-ink);
    text-decoration: none;

    &:hover,
    &:focus-visible {
        background: var(--th-feed-openproject-soft);
    }

    &:focus-visible {
        outline: 2px solid var(--color-primary-element);
        outline-offset: 2px;
    }
}

.feed-card__team-btn {
    background: none;
    border: none;
    padding: 0;
    cursor: pointer;
    color: inherit;
    font-size: inherit;
    font-weight: var(--th-font-weight-semibold, 600);

    &:hover {
        text-decoration: underline;
        color: var(--color-primary-element);
    }

    &:focus-visible {
        outline: 2px solid var(--color-primary-element);
        outline-offset: 2px;
        border-radius: 2px;
    }
}

/* The same weight the button carries, so the team line reads identically
   whether or not it is a link — the difference is the affordance, not the
   typography. */
.feed-card__team-name {
    font-weight: var(--th-font-weight-semibold, 600);
}

.feed-card__sep {
    opacity: 0.6;
}

// ── Right rail ───────────────────────────────────────────────────────────
.feed-card__aside {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 0 0 auto;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.feed-card__pill {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    border-radius: var(--th-radius-pill, 999px);
    font-size: var(--th-font-micro, 11px);
    font-weight: var(--th-font-weight-bold, 700);
    line-height: 1.2;
    white-space: nowrap;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.feed-card__pill--neutral {
    background: var(--color-background-dark);
    color: var(--color-main-text);
}

// The -text variants throughout: SKILLS.md § NC design guidelines requires
// them wherever a status colour carries text.
.feed-card__pill--success {
    background: var(--color-success-hover, var(--color-background-dark));
    color: var(--color-success-text);
}

.feed-card__pill--warning {
    background: var(--color-warning-hover, var(--color-background-dark));
    color: var(--color-warning-text);
}

.feed-card__pill--urgent {
    background: var(--color-error-hover, var(--color-background-dark));
    color: var(--color-error-text);
}

.feed-card__time {
    font-size: var(--th-font-micro, 11px);
    color: var(--color-text-maxcontrast);
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.feed-card__avatar,
.feed-card__open {
    flex: 0 0 auto;
}

// ── Poll ─────────────────────────────────────────────────────────────────
.feed-card__poll {
    list-style: none;
    margin: 8px 0 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.feed-card__poll-option {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.feed-card__poll-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 8px;
    width: 100%;
    background: none;
    border: none;
    padding: 2px 4px;
    margin: 0;
    text-align: left;
    font-size: var(--th-font-body, 14px);
    color: inherit;
    border-radius: var(--th-radius-control, var(--border-radius));
}

// Raw <button> for a votable option: a full-width card row with its own
// chrome, which SKILLS.md § "NcButton is the default" lists as a carve-out.
.feed-card__poll-row--votable {
    cursor: pointer;

    &:hover {
        background: var(--color-background-hover);
    }

    // Split from :hover so the keyboard ring survives — grouping them under
    // one selector is the trap SKILLS.md § Focus visibility calls out.
    &:focus-visible {
        background: var(--color-background-hover);
        outline: 2px solid var(--color-primary-element);
        outline-offset: -2px;
    }

    &:disabled {
        cursor: progress;
        opacity: 0.7;
    }
}

.feed-card__poll-row--mine .feed-card__poll-label {
    font-weight: var(--th-font-weight-semibold, 600);
}

.feed-card__poll-label {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-weight: var(--th-font-weight-medium, 500);
}

.feed-card__poll-check {
    color: var(--color-success-text);
    flex: 0 0 auto;
}

.feed-card__poll-count {
    flex: 0 0 auto;
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta, 12px);
    font-variant-numeric: tabular-nums;
}

.feed-card__poll-bar {
    height: 6px;
    background: var(--color-background-dark);
    border-radius: 3px;
    overflow: hidden;
}

.feed-card__poll-fill {
    height: 100%;
    background: var(--th-feed-tone-ink);
    border-radius: 3px;
    transition: width 200ms ease-out;
}

.feed-card__error {
    margin: 6px 0 0;
    color: var(--color-error-text);
    font-size: var(--th-font-meta, 12px);
}

// ── Tone plumbing ────────────────────────────────────────────────────────
// One pair of local variables per card, so every tinted element inside reads
// the same two values and adding a kind means adding one block here, not
// touching five selectors.
.feed-card--message  { --th-feed-tone-soft: var(--th-feed-message-soft);  --th-feed-tone-ink: var(--th-feed-message-ink); }
.feed-card--question { --th-feed-tone-soft: var(--th-feed-question-soft); --th-feed-tone-ink: var(--th-feed-question-ink); }
.feed-card--poll     { --th-feed-tone-soft: var(--th-feed-poll-soft);     --th-feed-tone-ink: var(--th-feed-poll-ink); }
.feed-card--decision { --th-feed-tone-soft: var(--th-feed-decision-soft); --th-feed-tone-ink: var(--th-feed-decision-ink); }
.feed-card--public   { --th-feed-tone-soft: var(--th-feed-public-soft);   --th-feed-tone-ink: var(--th-feed-public-ink); }
.feed-card--talk     { --th-feed-tone-soft: var(--th-feed-talk-soft);     --th-feed-tone-ink: var(--th-feed-talk-ink); }
.feed-card--system   { --th-feed-tone-soft: var(--th-feed-system-soft);   --th-feed-tone-ink: var(--th-feed-system-ink); }
.feed-card--openproject { --th-feed-tone-soft: var(--th-feed-openproject-soft); --th-feed-tone-ink: var(--th-feed-openproject-ink); }

@media (max-width: 700px) {
    .feed-card__main {
        flex-wrap: wrap;
    }

    .feed-card__aside {
        width: 100%;
        justify-content: flex-start;
        padding-left: 44px; // lines up under the body, past the glyph chip
    }
}
</style>
