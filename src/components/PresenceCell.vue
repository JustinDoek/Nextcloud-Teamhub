<template>
    <button
        class="presence-cell"
        :class="{
            'presence-cell--filled': cell.presence_type_id !== null,
            'presence-cell--saving': saving,
            'presence-cell--dirty':  dirty,
        }"
        :style="cellStyle"
        :aria-label="ariaLabel"
        :aria-busy="saving"
        :disabled="saving"
        @click="$emit('pick', $event)">

        <NcLoadingIcon v-if="saving" :size="14" class="presence-cell__spinner" />
        <template v-else-if="typeInfo">
            <span class="presence-cell__label">{{ typeInfo.label }}</span>
        </template>
        <span v-else class="presence-cell__empty">
            +
        </span>
    </button>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon } from '@nextcloud/vue'

/**
 * Single cell in the 7×2 week template grid.
 *
 * Displays the presence type colour and label when set, or an empty
 * placeholder when not. Emits 'pick' when clicked so the parent
 * can open the type-picker dialog.
 *
 * Intentionally stateless — all state lives in MyPresencePanel.
 */
export default {
    name: 'PresenceCell',
    components: { NcLoadingIcon },
    props: {
        cell: {
            type: Object,
            required: true,
        },
        types: {
            type: Array,
            required: true,
        },
        saving: {
            type: Boolean,
            default: false,
        },
        // When true, this cell has a pending draft change not yet saved.
        // Shown as an amber outline so the user knows what will be affected by Save.
        dirty: {
            type: Boolean,
            default: false,
        },
        halfDayLabel: {
            type: String,
            required: true,
        },
        dayLabel: {
            type: String,
            required: true,
        },
    },
    emits: ['pick'],
    computed: {
        typeInfo() {
            if (this.cell.presence_type_id === null) return null
            return this.types.find(t => t.id === this.cell.presence_type_id) || null
        },
        cellStyle() {
            if (!this.typeInfo) return {}
            return {
                backgroundColor: this.typeInfo.color || 'var(--color-background-dark)',
                borderColor: this.typeInfo.color || 'var(--color-border)',
                color: this.textColor(this.typeInfo.color),
            }
        },
        ariaLabel() {
            const base = t('teamhub', '{day} {half}', {
                day: this.dayLabel,
                half: this.halfDayLabel,
            })
            if (this.typeInfo) {
                return t('teamhub', '{cell}: {type}. Click to change.', {
                    cell: base,
                    type: this.typeInfo.label,
                })
            }
            return t('teamhub', '{cell}: not set. Click to set.', { cell: base })
        },
    },
    methods: {
        t,
        /**
         * Determine whether to use black or white text based on the
         * background luminance (WCAG relative luminance approximation).
         */
        textColor(hex) {
            if (!hex || !hex.startsWith('#')) return 'var(--color-main-text)'
            let h = hex.slice(1)
            if (h.length === 3) h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2]
            const r = parseInt(h.slice(0,2), 16) / 255
            const g = parseInt(h.slice(2,4), 16) / 255
            const b = parseInt(h.slice(4,6), 16) / 255
            const lum = 0.299 * r + 0.587 * g + 0.114 * b
            return lum > 0.55 ? '#000000' : '#ffffff'
        },
    },
}
</script>

<style scoped>
.presence-cell {
    width: 100%;
    min-height: 52px;
    border-radius: var(--border-radius);
    border: 2px dashed var(--color-border);
    background: var(--color-main-background);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: filter 0.1s, transform 0.1s;
    padding: 4px;
    font-size: var(--th-font-micro);
    line-height: 1.2;
    text-align: center;
    word-break: break-word;
    overflow: hidden;
}

.presence-cell:hover:not(:disabled) {
    filter: brightness(0.92);
    transform: scale(1.03);
}

.presence-cell:focus-visible {
    outline: 2px solid var(--color-primary);
    outline-offset: 2px;
}

.presence-cell--filled {
    border-style: solid;
    border-width: 0;
}

.presence-cell--saving {
    opacity: 0.6;
    cursor: wait;
}

/* Unsaved draft change — amber outline. v3.100.16: dropped the hex
   fallback (NC always defines --color-warning). */
.presence-cell--dirty {
    outline: 2px solid var(--color-warning);
    outline-offset: 1px;
}

.presence-cell:disabled {
    cursor: not-allowed;
}

.presence-cell__label {
    font-weight: 500;
    /* ensures long labels don't overflow the cell */
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}

.presence-cell__empty {
    font-size: 18px;
    color: var(--color-text-maxcontrast);
    line-height: 1;
}

.presence-cell__spinner {
    flex-shrink: 0;
}
</style>
