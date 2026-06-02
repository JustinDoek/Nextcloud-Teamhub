<template>
    <li class="th-presence-row">
        <!-- Avatar (no presence dot — Tomorrow tab is about schedule, not live status) -->
        <div class="th-presence-row__avatar-wrap">
            <NcAvatar
                :user="member.userId"
                :display-name="member.displayName"
                :show-user-status="false"
                :disable-menu="false"
                :size="32" />
        </div>

        <!-- Name -->
        <div class="th-presence-row__body">
            <div class="th-presence-row__name" :title="member.displayName">
                {{ member.displayName }}
            </div>
        </div>

        <!-- Two pills: morning and afternoon -->
        <div class="th-presence-row__pills" role="group" :aria-label="t('teamhub', 'Scheduled presence tomorrow for {name}', { name: member.displayName })">
            <span
                class="th-presence-row__pill"
                :class="morning ? '' : 'th-presence-row__pill--empty'"
                :style="pillStyle(morning)"
                :title="pillTitle(morning, 'morning')">
                {{ pillLabel(morning) }}
            </span>
            <span
                class="th-presence-row__pill"
                :class="afternoon ? '' : 'th-presence-row__pill--empty'"
                :style="pillStyle(afternoon)"
                :title="pillTitle(afternoon, 'afternoon')">
                {{ pillLabel(afternoon) }}
            </span>
        </div>
    </li>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcAvatar } from '@nextcloud/vue'

export default {
    name: 'MemberPresenceRow',

    components: { NcAvatar },

    props: {
        member:    { type: Object, required: true },
        morning:   { type: Object, default: null }, // slot or null
        afternoon: { type: Object, default: null }, // slot or null
    },

    methods: {
        t,

        /**
         * Pill background colour comes from the slot's own colour (set on
         * the presence type by the admin). For empty slots — no schedule —
         * the pill renders with the empty modifier (faint neutral) instead.
         */
        pillStyle(slot) {
            if (!slot || !slot.color) return {}
            return {
                backgroundColor: slot.color,
                color: this.readableTextOn(slot.color),
                borderColor: slot.color,
            }
        },

        pillLabel(slot) {
            // TRANSLATORS: shown on a presence pill when no schedule is set
            if (!slot) return t('teamhub', 'No schedule')
            return slot.label || ''
        },

        pillTitle(slot, half) {
            // TRANSLATORS: tooltip on the morning presence pill — N is the scheduled label
            const morningTpl   = t('teamhub', 'Tomorrow morning: {label}', { label: slot?.label || t('teamhub', 'No schedule') })
            // TRANSLATORS: tooltip on the afternoon presence pill — N is the scheduled label
            const afternoonTpl = t('teamhub', 'Tomorrow afternoon: {label}', { label: slot?.label || t('teamhub', 'No schedule') })
            return half === 'morning' ? morningTpl : afternoonTpl
        },

        /**
         * Choose black or white text depending on the background colour's
         * perceived brightness. Standard luminance threshold (~0.6) — keeps
         * pill labels legible regardless of which colour an admin picked.
         */
        readableTextOn(hex) {
            const c = (hex || '').replace('#', '')
            if (c.length !== 6 && c.length !== 3) return '#fff'
            const full = c.length === 3 ? c.split('').map(x => x + x).join('') : c
            const r = parseInt(full.slice(0, 2), 16)
            const g = parseInt(full.slice(2, 4), 16)
            const b = parseInt(full.slice(4, 6), 16)
            // Relative luminance (sRGB), 0..1
            const L = (0.299 * r + 0.587 * g + 0.114 * b) / 255
            return L > 0.6 ? '#1a1a1a' : '#ffffff'
        },
    },
}
</script>

<style scoped>
.th-presence-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    border-bottom: 1px solid var(--color-border);
    min-width: 0;
}
.th-presence-row:last-child { border-bottom: none; }
.th-presence-row:hover { background: var(--color-background-hover); }

.th-presence-row__avatar-wrap {
    flex-shrink: 0;
    display: inline-flex;
}

.th-presence-row__body {
    flex: 1;
    min-width: 0;
}

.th-presence-row__name {
    font-size: 13px;
    font-weight: 500;
    color: var(--color-main-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.th-presence-row__pills {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-shrink: 0;
}

.th-presence-row__pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 56px;
    height: 24px;
    padding: 0 10px;
    border-radius: var(--border-radius);
    font-size: 11px;
    font-weight: 600;
    line-height: 1;
    border: 1px solid transparent;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 88px;
}

.th-presence-row__pill--empty {
    background: var(--color-background-dark);
    color: var(--color-text-maxcontrast);
    border-color: var(--color-border);
    font-weight: 500;
    font-style: italic;
}
</style>
