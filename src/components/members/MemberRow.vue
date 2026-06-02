<template>
    <li class="th-member-row">
        <!-- Avatar with merged presence dot -->
        <div class="th-member-row__avatar-wrap">
            <NcAvatar
                :user="member.userId"
                :display-name="member.displayName"
                :show-user-status="false"
                :disable-menu="false"
                :size="32" />
            <span
                v-if="presenceDotColor"
                class="th-member-row__dot"
                :style="{ backgroundColor: presenceDotColor }"
                :aria-label="presenceLabel"
                aria-hidden="false">
            </span>
        </div>

        <!-- Name + status text -->
        <div class="th-member-row__body">
            <div class="th-member-row__name" :title="member.displayName">
                {{ member.displayName }}
            </div>
            <div class="th-member-row__status">
                <span class="th-member-row__status-label">{{ t('teamhub', 'Current Status') }}</span>
                <span class="th-member-row__status-value">{{ statusText }}</span>
            </div>
        </div>

        <!-- Contact icons — only rendered when the corresponding data is set -->
        <div class="th-member-row__actions" role="group" :aria-label="t('teamhub', 'Contact actions for {name}', { name: member.displayName })">
            <a
                v-if="talkAvailable"
                :href="talkUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="th-member-row__icon"
                :title="t('teamhub', 'Open Talk conversation with {name}', { name: member.displayName })"
                :aria-label="t('teamhub', 'Open Talk conversation with {name}', { name: member.displayName })">
                <MessageIcon :size="18" aria-hidden="true" />
            </a>
            <a
                v-if="member.phone"
                :href="`tel:${member.phone}`"
                class="th-member-row__icon"
                :title="t('teamhub', 'Call {phone}', { phone: member.phone })"
                :aria-label="t('teamhub', 'Call {name} at {phone}', { name: member.displayName, phone: member.phone })">
                <PhoneIcon :size="18" aria-hidden="true" />
            </a>
            <a
                v-if="member.email"
                :href="`mailto:${member.email}`"
                class="th-member-row__icon"
                :title="t('teamhub', 'Email {email}', { email: member.email })"
                :aria-label="t('teamhub', 'Email {name} at {email}', { name: member.displayName, email: member.email })">
                <EmailIcon :size="18" aria-hidden="true" />
            </a>
        </div>
    </li>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcAvatar } from '@nextcloud/vue'

import MessageIcon from 'vue-material-design-icons/MessageOutline.vue'
import PhoneIcon   from 'vue-material-design-icons/Phone.vue'
import EmailIcon   from 'vue-material-design-icons/EmailOutline.vue'

export default {
    name: 'MemberRow',

    components: { NcAvatar, MessageIcon, PhoneIcon, EmailIcon },

    props: {
        /**
         * The member object as enriched by the backend:
         *   { userId, displayName, email?, phone?, ncStatus? }
         */
        member: { type: Object, required: true },
        /**
         * Whether the Talk (spreed) app is available for the current user.
         * Controlled by the parent so the icon is hidden uniformly when Talk
         * isn't installed or isn't enabled for the viewer.
         */
        talkAvailable: { type: Boolean, default: false },
    },

    computed: {
        /**
         * Hard, saturated dot colour for NC live status — matches the palette
         * used by the team's avatar-stack widget (and by NC core for status
         * indicators), so the user gets a consistent visual signal across
         * widgets.
         */
        presenceDotColor() {
            const s = this.member?.ncStatus?.status
            switch (s) {
                case 'online': return '#00c853'
                case 'away':   return '#ffab00'
                case 'dnd':
                case 'busy':   return '#d50000'
                default:       return null
            }
        },

        /**
         * Human-friendly status text shown on every row.
         * Priority:
         *   1. The user's status message (e.g. "In Meeting"), if set.
         *   2. The standard NC status label (Online/Away/DND/Busy).
         *   3. "Active" when no status info is available (the user simply
         *      has an account and may or may not be online).
         */
        statusText() {
            const ns = this.member?.ncStatus
            if (ns?.message) return ns.message
            switch (ns?.status) {
                // TRANSLATORS: NC user status — user is online/active
                case 'online':    return t('teamhub', 'Online')
                // TRANSLATORS: NC user status — user is away
                case 'away':      return t('teamhub', 'Away')
                // TRANSLATORS: NC user status — do not disturb
                case 'dnd':       return t('teamhub', 'Do not disturb')
                // TRANSLATORS: NC user status — busy
                case 'busy':      return t('teamhub', 'Busy')
                // TRANSLATORS: NC user status — invisible/offline-appearing
                case 'invisible': return t('teamhub', 'Invisible')
                // TRANSLATORS: NC user status — offline
                case 'offline':   return t('teamhub', 'Offline')
                // TRANSLATORS: fallback status text when NC has no live status info for the user
                default:          return t('teamhub', 'Active')
            }
        },

        presenceLabel() {
            // For the dot's aria-label — same text as the status line above.
            return this.statusText
        },

        /**
         * Open / create a 1:1 Talk conversation with the target user.
         * Confirmed pattern for NC32 — the spreed frontend reads the
         * callUser query parameter and routes to / creates the 1:1.
         */
        talkUrl() {
            return generateUrl('/apps/spreed/') + '?callUser=' + encodeURIComponent(this.member.userId)
        },
    },

    methods: { t },
}
</script>

<style scoped>
.th-member-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    border-bottom: 1px solid var(--color-border);
    min-width: 0;
}
.th-member-row:last-child { border-bottom: none; }
.th-member-row:hover { background: var(--color-background-hover); }

.th-member-row__avatar-wrap {
    position: relative;
    flex-shrink: 0;
    display: inline-flex;
}

.th-member-row__dot {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    border: 2px solid var(--color-main-background);
    pointer-events: none;
}

.th-member-row__body {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 1px;
}

.th-member-row__name {
    font-size: 13px;
    font-weight: 500;
    color: var(--color-main-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.th-member-row__status {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 4px;
    font-size: 11px;
    line-height: 1.3;
    min-width: 0;
}

.th-member-row__status-label {
    color: var(--color-text-maxcontrast);
}

.th-member-row__status-value {
    color: var(--color-main-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
}

.th-member-row__actions {
    display: flex;
    align-items: center;
    gap: 2px;
    flex-shrink: 0;
}

.th-member-row__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    color: var(--color-text-maxcontrast);
    background: transparent;
    border: none;
    cursor: pointer;
    text-decoration: none;
    transition: background 0.12s, color 0.12s;
}
.th-member-row__icon:hover {
    background: var(--color-background-dark, var(--color-background-hover));
    color: var(--color-primary-element);
}
.th-member-row__icon:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}
</style>
