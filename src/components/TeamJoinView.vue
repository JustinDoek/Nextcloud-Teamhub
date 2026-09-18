<template>
    <div class="th-join">
        <div v-if="loading" class="th-join__loading">
            <NcLoadingIcon :size="iconHero" />
            <p>{{ t('teamhub', 'Loading team…') }}</p>
        </div>

        <!-- A team id that resolves to nothing. Deleted and mistyped share one
             message — the recipient can act on neither, and distinguishing them
             would report on which teams exist. A server error does NOT share it:
             telling somebody their link is broken when the server merely failed
             invites them to throw away a link that works. -->
        <NcEmptyContent
            v-else-if="notFound || loadFailed"
            :name="notFound
                ? t('teamhub', 'Team not found')
                : t('teamhub', 'Could not load this team')"
            :description="notFound
                ? t('teamhub', 'This link does not point at a team. It may have been mistyped, or the team may have been deleted.')
                : t('teamhub', 'Something went wrong while loading the team. Reload the page to try again.')">
            <template #icon>
                <AccountGroup :size="iconHero" />
            </template>
            <template #action>
                <NcButton variant="secondary" @click="$emit('browse')">
                    <template #icon><Magnify :size="iconNav" /></template>
                    {{ t('teamhub', 'Browse Teams') }}
                </NcButton>
            </template>
        </NcEmptyContent>

        <div v-else class="th-join__card">
            <div class="th-join__icon-wrap">
                <img
                    v-if="team.image_url"
                    :src="team.image_url"
                    alt=""
                    class="th-join__icon th-join__icon--image" />
                <AccountGroup v-else :size="iconHero" class="th-join__icon" aria-hidden="true" />
            </div>

            <h2 class="th-join__name">{{ team.name }}</h2>

            <p v-if="team.description" class="th-join__description">
                {{ team.description }}
            </p>

            <!-- Every outcome of pressing a button lands here, so a screen
                 reader hears the result without having to go looking for it. -->
            <div class="th-join__status" aria-live="polite">
                <NcNoteCard
                    v-if="isClosed"
                    type="info"
                    :heading="t('teamhub', 'This team is invite only')">
                    <p>{{ t('teamhub', 'You cannot join {team} on your own. Ask a member of the team to invite you.', { team: team.name }) }}</p>
                </NcNoteCard>

                <NcNoteCard
                    v-else-if="isPending"
                    type="info"
                    :heading="t('teamhub', 'Your request is waiting for approval')">
                    <p>{{ t('teamhub', 'A moderator of {team} has been asked to approve your request. You will be able to open the team once they do.', { team: team.name }) }}</p>
                </NcNoteCard>

                <NcNoteCard
                    v-else-if="needsApproval"
                    type="info"
                    :heading="t('teamhub', 'A moderator approves new members')">
                    <p>{{ t('teamhub', 'Anyone may ask to join this team, but a moderator has to approve the request before you can open it.') }}</p>
                </NcNoteCard>
            </div>

            <div v-if="!isClosed && !isPending" class="th-join__actions">
                <!-- Already in the team — reachable from a stale preview, or
                     from a link followed straight after being invited. -->
                <NcButton
                    v-if="isMember"
                    variant="primary"
                    @click="$emit('open-team', team.id)">
                    <template #icon><OpenInApp :size="iconNav" /></template>
                    {{
                        // TRANSLATORS: button label to open this team and view its content
                        t('teamhub', 'Open')
                    }}
                </NcButton>

                <NcButton
                    v-else
                    variant="primary"
                    :disabled="submitting"
                    @click="submit">
                    <template #icon>
                        <NcLoadingIcon v-if="submitting" :size="iconNav" />
                        <AccountQuestion v-else-if="needsApproval" :size="iconNav" />
                        <Plus v-else :size="iconNav" />
                    </template>
                    {{ needsApproval
                        // TRANSLATORS: button label to ask a moderator for permission to join a team
                        ? t('teamhub', 'Request to join')
                        // TRANSLATORS: button label to join (become a member of) a team
                        : t('teamhub', 'Join') }}
                </NcButton>
            </div>
        </div>
    </div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import AccountGroup    from 'vue-material-design-icons/AccountGroup.vue'
import AccountQuestion from 'vue-material-design-icons/AccountQuestion.vue'
import Magnify         from 'vue-material-design-icons/Magnify.vue'
import OpenInApp       from 'vue-material-design-icons/OpenInApp.vue'
import Plus            from 'vue-material-design-icons/Plus.vue'
import { ICON_NAV, ICON_HERO } from '../constants/uiTokens.js'

/**
 * v4.6.17 — where a copied team link lands somebody who is not in the team.
 *
 * The link (`?team=<id>`, written by the Copy link action) used to be readable
 * only by people who already had access: App.vue checked the id against the
 * user's own team list and, finding nothing, logged a warning and rendered the
 * generic welcome screen. So the one thing the link is for — handing it to
 * somebody who is *not* in the team — was the case it did not serve.
 *
 * What is offered here follows the team's own configuration, which
 * `CirclesConfig::joinPolicy()` reduces to three cases (and Circles enforces
 * independently of anything this component draws):
 *
 *   open    — join, and land in the team immediately
 *   request — ask, and wait for a moderator
 *   closed  — the team is invite-only; there is nothing to press
 *
 * The closed case shows the team's name and no description: the server withholds
 * the description and image for exactly that policy, so there is nothing here to
 * hide that was ever sent.
 */
export default {
    name: 'TeamJoinView',
    components: {
        NcButton,
        NcEmptyContent,
        NcLoadingIcon,
        NcNoteCard,
        AccountGroup,
        AccountQuestion,
        Magnify,
        OpenInApp,
        Plus,
    },
    props: {
        teamId: {
            type: String,
            required: true,
        },
    },
    data() {
        return {
            loading: true,
            notFound: false,
            loadFailed: false,
            submitting: false,
            team: null,
        }
    },
    computed: {
        iconNav()  { return ICON_NAV },
        iconHero() { return ICON_HERO },

        isClosed() {
            return this.team?.joinPolicy === 'closed'
        },
        needsApproval() {
            return this.team?.joinPolicy === 'request'
        },
        isMember() {
            return this.team?.membership === 'member'
        },
        isPending() {
            return this.team?.membership === 'requesting'
        },
    },
    watch: {
        // The view is keyed on teamId in App.vue, but a watcher costs nothing
        // and keeps the component correct if that ever changes.
        teamId: 'loadPreview',
    },
    mounted() {
        this.loadPreview()
    },
    methods: {
        t,

        async loadPreview() {
            this.loading = true
            this.notFound = false
            this.loadFailed = false
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${encodeURIComponent(this.teamId)}/preview`),
                )
                this.team = data
            } catch (e) {
                // 404 is the endpoint's answer for a broken or stale link, and
                // for a team id that was never one. Anything else is the server
                // failing, which is a different thing to tell the user.
                if (e.response?.status === 404) {
                    this.notFound = true
                } else {
                    this.loadFailed = true
                }
                this.team = null
            } finally {
                this.loading = false
            }
        },

        /**
         * Join, or ask to join.
         *
         * One endpoint serves both, and it is the *server* that decides which
         * happened — from the team's config at the moment of the call, not from
         * whichever button this component happened to have drawn. So the
         * outcome is read back rather than assumed: re-read the preview and
         * report what the membership actually became.
         *
         * That costs one extra GET on a once-per-team action, and buys the case
         * where the two disagree. A team switched to "moderator approves" while
         * this page sat open would otherwise announce "you have joined" and hand
         * App.vue a team the user is still only *requesting* — which opens
         * TeamView on a team that is not in the team list.
         */
        async submit() {
            const teamName = this.team.name
            this.submitting = true
            try {
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${encodeURIComponent(this.teamId)}/join`),
                    {},
                )
                await this.loadPreview()

                if (this.isMember) {
                    showSuccess(t('teamhub', 'You have joined {team}', { team: teamName }))
                    this.$emit('joined', this.teamId)
                } else if (this.isPending) {
                    showSuccess(t('teamhub', 'Your request to join {team} has been sent', { team: teamName }))
                }
                // Neither, and no error: nothing to announce that would be true.
                // The reloaded page shows whatever the team now actually offers.
            } catch (e) {
                // invite_only: the policy changed between the preview and the
                // press. Re-reading redraws the page as invite-only rather than
                // leaving a button that cannot work.
                if (e.response?.data?.error === 'invite_only') {
                    showError(t('teamhub', 'This team is invite only. Ask a member of the team to invite you.'))
                    await this.loadPreview()
                    return
                }
                showError(this.needsApproval
                    ? t('teamhub', 'Failed to request access')
                    : t('teamhub', 'Failed to join team'))
            } finally {
                this.submitting = false
            }
        },
    },
}
</script>

<style scoped>
.th-join {
    padding: 40px;
    max-width: 640px;
    margin: 0 auto;
}

.th-join__loading {
    text-align: center;
    padding: 80px 20px;
}

.th-join__loading p {
    margin-top: 16px;
    color: var(--color-text-maxcontrast);
}

.th-join__card {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 16px;
    padding: 32px 24px;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--th-radius-card);
}

/* Fixed well so a team with a photo and one without are the same height. */
.th-join__icon-wrap {
    flex-shrink: 0;
    width: 96px;
    height: 96px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.th-join__icon {
    color: var(--color-primary);
}

.th-join__icon--image {
    width: 96px;
    height: 96px;
    border-radius: var(--border-radius-large);
    object-fit: cover;
    border: 1px solid var(--color-border);
}

.th-join__name {
    margin: 0;
    font-size: var(--th-font-heading-lg);
    font-weight: var(--th-font-weight-semibold);
    line-height: var(--th-line-height-tight);
    /* A team name is free text and can be long; never let it widen the card. */
    overflow-wrap: anywhere;
}

.th-join__description {
    margin: 0;
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-body);
    line-height: var(--th-line-height-relaxed);
    overflow-wrap: anywhere;
}

/* Empty until something needs saying — no margin when there is no note. */
.th-join__status:empty {
    display: none;
}

.th-join__status {
    width: 100%;
    text-align: start;
}

.th-join__status p {
    margin: 0;
}

.th-join__actions {
    display: flex;
    justify-content: center;
    gap: 8px;
}
</style>
