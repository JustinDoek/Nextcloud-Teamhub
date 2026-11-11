<template>
    <li class="th-member-row">
        <!-- Avatar with merged presence dot -->
        <div class="th-member-row__avatar-wrap">
            <!-- v4.14.16 - a colleague whose account is on another Nextcloud
                 has no avatar here to load and no contacts menu to open, so
                 the globe takes the avatar's place and carries their server
                 as its title (DESIGN 2.257). Until this version they were not
                 in this list at all, which is the bug. -->
            <span
                v-if="member.isRemote"
                class="th-member-row__remote"
                :title="t('teamhub', 'Account on {instance}', { instance: member.instance })">
                <Earth :size="ICON_BODY" />
            </span>
            <NcAvatar
                v-else
                :user="member.userId"
                :display-name="member.displayName"
                :hide-status="true"
                :disable-menu="false"
                :size="AVATAR_MD" />
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
            <!-- v4.14.16 - for somebody on another server the second line is
                 their server, not a status: this instance has no presence, no
                 last login and no profile for them, so "Current status:
                 Offline" would be an invention. -->
            <div v-if="member.isRemote" class="th-member-row__status">
                <span class="th-member-row__status-value">{{ member.instance }}</span>
            </div>
            <div v-else class="th-member-row__status">
                <span class="th-member-row__status-label">{{ t('teamhub', 'Current status') }}</span>
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
                <MessageIcon :size="ICON_BODY" aria-hidden="true" />
            </a>
            <a
                v-if="member.phone"
                :href="`tel:${member.phone}`"
                class="th-member-row__icon"
                :title="t('teamhub', 'Call {phone}', { phone: member.phone })"
                :aria-label="t('teamhub', 'Call {name} at {phone}', { name: member.displayName, phone: member.phone })">
                <PhoneIcon :size="ICON_BODY" aria-hidden="true" />
            </a>
            <a
                v-if="emailHref"
                :href="emailHref"
                :target="mailAvailable ? '_blank' : null"
                :rel="mailAvailable ? 'noopener noreferrer' : null"
                class="th-member-row__icon"
                :title="emailTitle"
                :aria-label="emailAriaLabel">
                <EmailIcon :size="ICON_BODY" aria-hidden="true" />
            </a>
        </div>
    </li>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcAvatar } from '@nextcloud/vue'
import { ICON_BODY, AVATAR_MD } from '../../constants/uiTokens.js'

import Earth from 'vue-material-design-icons/Earth.vue'
import MessageIcon from 'vue-material-design-icons/MessageOutline.vue'
import PhoneIcon   from 'vue-material-design-icons/Phone.vue'
import EmailIcon   from 'vue-material-design-icons/EmailOutline.vue'

export default {
    name: 'MemberRow',

    components: { NcAvatar, Earth, MessageIcon, PhoneIcon, EmailIcon },

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
        /**
         * Whether the *viewer* can compose in Nextcloud Mail — the Mail app is
         * enabled for them AND they have an account configured. This is about
         * the person clicking, not the person being contacted: the member only
         * needs an address. False keeps the pre-4.5.34 behaviour, a plain
         * `mailto:` handed off to whatever the OS has registered.
         */
        mailAvailable: { type: Boolean, default: false },
    },

    data() {
        return {
            ICON_BODY,
            AVATAR_MD,
        }
    },

    computed: {
        /**
         * Hard, saturated dot colour for NC live status — matches the palette
         * used by the team's avatar-stack widget (and by NC core for status
         * indicators), so the user gets a consistent visual signal across
         * widgets.
         */
        presenceDotColor() {
            // v3.100.16: return CSS variable references (were raw hex
            // #00c853/#ffab00/#d50000) — the consumer binds them via
            // :style so the browser resolves the tokens at render time,
            // and the dot follows the NC theme in light and dark modes.
            const s = this.member?.ncStatus?.status
            switch (s) {
                case 'online': return 'var(--color-success)'
                case 'away':   return 'var(--color-warning)'
                case 'dnd':
                case 'busy':   return 'var(--color-error)'
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

        /**
         * The member's address, but only when it is a plain addr-spec we can
         * safely drop into a URL.
         *
         * A `mailto:` URI reads everything after `?` as headers, so an address
         * carrying `?bcc=…` would silently copy a third party on every message
         * a colleague sends from this row. The address comes from the member's
         * own NC profile, so they control it — a weak vector, but the check is
         * one regex and it protects the Mail query string too. Anything that
         * fails renders no icon at all, exactly as a member with no address.
         *
         * The excluded set is deliberately only what can change the meaning of
         * the URL: `? & #` (headers and fragment), `, ;` (extra recipients),
         * `< > "` (angle-addr and quoting), backslash, whitespace, and a second
         * `@`. Apostrophes stay legal — `o'brien@…` is a real address and is
         * harmless once encoded, so excluding it would cost that member their
         * email icon for nothing.
         */
        safeEmail() {
            const raw = (this.member?.email || '').trim()
            return /^[^\s<>"?&#,;\\@]+@[^\s<>"?&#,;\\@]+$/.test(raw) ? raw : null
        },

        /**
         * Where the email icon points. Nextcloud Mail's `/mailto` route reads
         * `to` / `cc` / `bcc` / `subject` / `body` off the query string and
         * opens its composer — verified against Mail's `src/router.js` and
         * `src/views/Home.vue`. `generateUrl` keeps the webroot and any
         * index.php prefix correct, so this is never a hardcoded path.
         */
        emailHref() {
            if (!this.safeEmail) return null
            if (this.mailAvailable) {
                return generateUrl('/apps/mail/mailto') + '?to=' + encodeURIComponent(this.safeEmail)
            }
            return 'mailto:' + this.safeEmail
        },

        emailTitle() {
            return this.mailAvailable
                // TRANSLATORS: tooltip on the email icon — opens Nextcloud Mail's composer. "Nextcloud Mail" is a product name and stays untranslated.
                ? t('teamhub', 'Email {email} in Nextcloud Mail', { email: this.safeEmail })
                : t('teamhub', 'Email {email}', { email: this.safeEmail })
        },

        emailAriaLabel() {
            const name = this.member.displayName
            return this.mailAvailable
                // TRANSLATORS: accessible name for the email icon — opens Nextcloud Mail's composer. "Nextcloud Mail" is a product name and stays untranslated.
                ? t('teamhub', 'Email {name} at {email} in Nextcloud Mail', { name, email: this.safeEmail })
                : t('teamhub', 'Email {name} at {email}', { name, email: this.safeEmail })
        },
    },

    methods: { t },
}
</script>

<style scoped>
.th-member-row {
    display: flex;
    align-items: center;
    gap: 8px;
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

/* v4.14.16 - stands exactly where the avatar would, so a mixed list keeps one
   column. Six locks rather than width/height alone: Nextcloud's global button
   and clickable-area rules are not in play on a <span>, but the flex parent
   is, and `flex: 0 0 auto` plus the max pair is what keeps the circle a circle
   when a long name pulls on the row (`/ui-standards` round shapes). */
.th-member-row__remote {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    box-sizing: border-box;
    width: var(--th-avatar-md);
    height: var(--th-avatar-md);
    min-width: var(--th-avatar-md);
    min-height: var(--th-avatar-md);
    max-width: var(--th-avatar-md);
    max-height: var(--th-avatar-md);
    padding: 0;
    border-radius: 50%;
    background: var(--color-background-dark);
    color: var(--color-primary-element);
}

.th-member-row__dot {
    position: absolute;
    bottom: 0;
    inset-inline-end: 0;
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
    font-size: var(--th-font-meta);
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
    font-size: var(--th-font-micro);
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
    gap: 4px;
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
    transition: background var(--animation-quick), color var(--animation-quick);
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
