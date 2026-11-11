<template>
    <!-- v4.13.1 — the team's standing notices, lifted out of the Team info
         widget card (DESIGN §2.185).

         They lived inside that card until the banner took over the name and
         the description and Team info became hidden by default: a notice that
         only appears while a widget nobody shows is on screen is not a
         notice. Nothing about the three strips themselves changed — same
         wording, same gates, same destinations — they are simply somewhere
         that cannot be switched off.

         Order is deliberate and is "what stops the team working" first:
         an expiry that has passed, then resources that need review, then a
         workspace that was never finished setting up. -->
    <div v-if="hasAnyNotice" class="th-team-notices">

        <!-- Expiration — admin-only, because the only thing to do about it is
             request an extension and that is a team-admin action
             (CLAUDE.md § Permissions: hide what a member cannot do). Only
             inside the warning window or after the date has passed; a team
             expiring in eight months has nothing to say here. -->
        <div
            v-if="isTeamAdmin && expiryNoticeVisible"
            class="th-team-notices__strip"
            :class="{ 'th-team-notices__strip--expired': teamExpiry.expired }"
            role="status"
            aria-live="polite">
            <AlertCircle :size="ICON_INLINE" class="th-team-notices__icon" aria-hidden="true" />
            <span class="th-team-notices__text">
                <template v-if="teamExpiry.expired">
                    {{ t('teamhub', 'This team passed its expiration date on {date}.', { date: teamExpiry.expiresOn }) }}
                </template>
                <template v-else>
                    <!-- TRANSLATORS: N is a whole number of days until this team's expiration date -->
                    {{ n('teamhub',
                         'This team expires in %n day.',
                         'This team expires in %n days.',
                         teamExpiry.daysRemaining,
                         { n: teamExpiry.daysRemaining }) }}
                </template>
            </span>
            <NcButton
                class="th-team-notices__link"
                variant="tertiary"
                :aria-label="t('teamhub', 'Open team maintenance to request an extension')"
                :title="t('teamhub', 'Open team maintenance to request an extension')"
                @click="openMaintenanceForExpiry">
                <template #icon>
                    <ChevronRightIcon :size="ICON_BODY" aria-hidden="true" />
                </template>
            </NcButton>
        </div>

        <!-- Resources that need an administrator's decision. -->
        <div
            v-if="isTeamAdmin && resourceWarningTotal > 0"
            class="th-team-notices__strip"
            role="alert"
            aria-live="polite">
            <AlertCircle :size="ICON_INLINE" class="th-team-notices__icon" aria-hidden="true" />
            <span class="th-team-notices__text">
                <!-- TRANSLATORS: N is the number of connected resources that need review -->
                {{ n('teamhub', '%n resource needs review.', '%n resources need review.', resourceWarningTotal, { n: resourceWarningTotal }) }}
            </span>
            <NcButton
                class="th-team-notices__link"
                variant="tertiary"
                :aria-label="t('teamhub', 'Open modules & integrations to review resources')"
                :title="t('teamhub', 'Open modules & integrations to review resources')"
                @click="openSettingsAtRisk">
                <template #icon>
                    <ChevronRightIcon :size="ICON_BODY" aria-hidden="true" />
                </template>
            </NcButton>
        </div>

        <!-- v4.9.6 said this to every member, with the details and the
             actions kept for the creator, an administrator and the team's
             admins. **Justin, 2026-10-26: a member should not see it at all.**
             The sentence names a setup step by its internal label and offers a
             member nothing to do about it; whoever can act is exactly whoever
             the chevron was already for, so the banner now follows the same
             audience rather than being a notice with no reader.

             It is deliberately NOT gated on the status: a failed required step
             still shows, to the same three people, because that is the case
             where somebody has to act. -->
        <ProvisioningBanner
            v-if="provisioning && !provisioning.complete && canSeeProvisioning"
            :provisioning="provisioning"
            :is-team-admin="isTeamAdmin"
            @changed="$emit('provisioning-changed')" />
    </div>
</template>

<script>
import { mapMutations, mapState } from 'vuex'
import { getCurrentUser } from '@nextcloud/auth'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'

import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'

import ProvisioningBanner from './ProvisioningBanner.vue'
import { ICON_BODY, ICON_INLINE } from '../constants/uiTokens.js'

export default {
    name: 'TeamNotices',

    components: { NcButton, ProvisioningBanner, AlertCircle, ChevronRightIcon },

    emits: ['manage-team', 'provisioning-changed'],

    data() {
        return {
            ICON_BODY,
            ICON_INLINE,
        }
    },

    computed: {
        ...mapState(['members', 'resourceWarnings', 'teamExpiry', 'provisioning']),

        /** Combined count of pending + at-risk resources. */
        resourceWarningTotal() {
            const w = this.resourceWarnings || {}
            return (w.pending || 0) + (w.atRisk || 0)
        },

        /**
         * `warning` is computed server-side against the instance's configured
         * window, so this strip, the My Work item and the admin table all
         * agree on what "close" means.
         */
        expiryNoticeVisible() {
            const e = this.teamExpiry
            return !!e && (e.expired || e.warning)
        },

        isTeamAdmin() {
            if (!this.members?.length) return false
            const uid = getCurrentUser()?.uid
            if (!uid) return false
            const m = this.members.find(m => m.userId === uid)
            return !!m && m.level >= 8
        },

        /**
         * Who the provisioning banner is for: the team's admins, a Nextcloud
         * administrator, and the person who created the workspace.
         *
         * The same three people ProvisioningBanner's own `canOpen` lets open
         * the details, deliberately — a sentence naming a setup step is only
         * worth showing to somebody who can go and look at it. The duplication
         * is the point: if this list and `canOpen` ever disagree, somebody sees
         * a banner with no way in.
         */
        canSeeProvisioning() {
            if (this.isTeamAdmin) return true
            const user = getCurrentUser()
            if (user?.isAdmin) return true
            return !!user?.uid && this.provisioning?.createdBy === user.uid
        },

        /**
         * Whether anything at all is on screen. Without this the wrapper
         * would contribute its border and its height to every team that has
         * nothing to say, which on a fixed-height column is a visible gap.
         */
        hasAnyNotice() {
            if (this.provisioning && !this.provisioning.complete) return true
            if (!this.isTeamAdmin) return false
            return this.expiryNoticeVisible || this.resourceWarningTotal > 0
        },
    },

    methods: {
        t,
        n,

        ...mapMutations(['SET_RESOURCE_WARNING_FOCUS', 'SET_MANAGE_TEAM_DEEP_LINK']),

        /**
         * Called from the resource strip's chevron.
         *
         * Order matters and is load-bearing: the flag is committed first, then
         * the emit makes App.vue render ManageTeamView, which reads the flag in
         * its own mounted() hook (v4.5.36). Committing after the emit would be
         * too late.
         */
        openSettingsAtRisk() {
            this.SET_RESOURCE_WARNING_FOCUS(true)
            this.$emit('manage-team')
        },

        /**
         * v4.6.13 - open Manage team on the Maintenance tab, where the
         * expiration panel and the request form live. Uses the same deep-link
         * mutation the Project Compass uses, so ManageTeamView lands on the
         * right tab whether it is already mounted or not.
         */
        openMaintenanceForExpiry() {
            this.SET_MANAGE_TEAM_DEEP_LINK({ tab: 'danger', section: 'expiry' })
            this.$emit('manage-team')
        },
    },
}
</script>

<style scoped>
.th-team-notices {
    flex: 0 0 auto;
}

.th-team-notices__strip {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
    padding: var(--th-space-sm) var(--th-space-lg);
    background: var(--color-warning);
    border-bottom: 1px solid var(--color-warning-text);
    font-size: var(--th-font-meta);
    color: var(--color-warning-text);
    line-height: var(--th-line-height-tight);
}

.th-team-notices__icon {
    flex-shrink: 0;
    color: var(--color-warning-text);
}

/* v4.6.13 — a date that has already passed escalates the same strip from
   warning to error. The wording changes too ("passed its expiration date"),
   so the colour is reinforcement rather than the signal (WCAG 1.4.1). */
.th-team-notices__strip--expired {
    background: var(--color-error);
    border-bottom-color: var(--color-error-text);
    color: var(--color-error-text);
}

/* v4.13.1 — this pair was one grouped selector in TeamWidgetGrid and the
   grouping was wrong: it gave the *icon of an expired strip* `flex: 1`
   instead of the error ink, so the icon stayed warning-coloured inside a
   red strip and the text had no flex rule at all on that path. Split. */
.th-team-notices__strip--expired .th-team-notices__icon {
    color: var(--color-error-text);
}

.th-team-notices__text {
    flex: 1;
    min-width: 0;
}

.th-team-notices__link {
    margin: 0;
    display: flex;
    align-items: center;
    opacity: 0.75;
    flex-shrink: 0;
}

.th-team-notices__link:hover,
.th-team-notices__link:focus-visible {
    opacity: 1;
}
</style>
