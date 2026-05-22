<template>
    <div
        class="presence-grid-cell"
        :class="{
            'presence-grid-cell--filled': cell !== null,
            'presence-grid-cell--locked': cell && cell.is_locked,
        }"
        :style="cellStyle"
        :title="tooltip"
        aria-hidden="true">
        <span v-if="cell && cell.label" class="presence-grid-cell__label">
            {{ cell.label }}
        </span>
        <!-- When hide_reasons=true, label is null; show a colour block only -->
    </div>
</template>

<script>
/**
 * Read-only display cell for the team presence grid.
 *
 * Receives a slot cell object from the parent grid or null for "no data".
 * The parent supplies fully-serialised cell data (colour already chosen
 * server-side — either the type's own colour or the 3-tone palette colour
 * when hide_reasons=true).
 *
 * Intentionally stateless and click-free in B3.
 * B4/Feature A will add click-through to a member schedule.
 */
export default {
    name: 'PresenceGridCell',
    props: {
        /**
         * Slot cell object from PresenceTeamService::serializeSlotCell, or null.
         * Shape: { color, label, icon, slug, requires_location, location_room_id, source, is_locked }
         */
        cell: {
            type: Object,
            default: null,
        },
    },
    computed: {
        cellStyle() {
            if (!this.cell || !this.cell.color) return {}
            return {
                backgroundColor: this.cell.color,
                color: this.textColor(this.cell.color),
            }
        },
        tooltip() {
            if (!this.cell) return ''
            if (this.cell.label) return this.cell.label
            // hide_reasons case — label is null but colour is present
            return ''
        },
    },
    methods: {
        /**
         * Luminance-based text colour for WCAG contrast on coloured backgrounds.
         * Same algorithm as PresenceCell.vue.
         */
        textColor(hex) {
            if (!hex || !hex.startsWith('#')) return 'var(--color-main-text)'
            let h = hex.slice(1)
            if (h.length === 3) h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2]
            const r = parseInt(h.slice(0, 2), 16) / 255
            const g = parseInt(h.slice(2, 4), 16) / 255
            const b = parseInt(h.slice(4, 6), 16) / 255
            const lum = 0.299 * r + 0.587 * g + 0.114 * b
            return lum > 0.55 ? '#000000' : '#ffffff'
        },
    },
}
</script>

<style scoped>
.presence-grid-cell {
    width: 100%;
    height: 36px;
    border-radius: var(--border-radius);
    background: var(--color-background-hover);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    font-size: 10px;
    font-weight: 500;
    text-align: center;
    line-height: 1.2;
    padding: 2px;
    word-break: break-word;
}

.presence-grid-cell--filled {
    background: var(--color-background-dark);
}

.presence-grid-cell--locked {
    /* Subtle diagonal stripe indicates admin-locked holiday */
    background-image: repeating-linear-gradient(
        -45deg,
        transparent,
        transparent 3px,
        rgba(0, 0, 0, 0.08) 3px,
        rgba(0, 0, 0, 0.08) 6px
    );
}

.presence-grid-cell__label {
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}
</style>
