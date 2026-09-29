<template>
    <section
        class="th-iframe-widget"
        :class="{ 'th-iframe-widget--collapsed': isCollapsed }"
        :aria-labelledby="titleId">
        <header class="th-iframe-widget__header">
            <div :id="titleId" class="th-iframe-widget__title">
                <span v-if="$slots.icon" class="th-iframe-widget__icon" aria-hidden="true">
                    <slot name="icon" />
                </span>
                <span class="th-iframe-widget__title-text">{{ title }}</span>
                <span
                    v-if="badge !== null && badge !== undefined && badge !== ''"
                    class="th-iframe-widget__badge">
                    {{ badge }}
                </span>
            </div>
            <div class="th-iframe-widget__actions">
                <slot name="actions" />
                <NcButton
                    v-if="collapsible"
                    class="th-iframe-widget__collapse-btn"
                    :aria-label="isCollapsed
                        ? t('teamhub', 'Expand {widget}', { widget: title })
                        : t('teamhub', 'Collapse {widget}', { widget: title })"
                    :aria-expanded="String(!isCollapsed)"
                    @click="toggleCollapsed"
                    variant="tertiary">
                    <template #icon>
                        <ChevronDown v-if="isCollapsed" :size="ICON_BODY" />
                        <ChevronUp v-else :size="ICON_BODY" />
                    </template>
                </NcButton>
            </div>
        </header>
        <div v-show="!isCollapsed" class="th-iframe-widget__body">
            <slot />
        </div>
        <div v-if="$slots.footer && !isCollapsed" class="th-iframe-widget__footer">
            <slot name="footer" />
        </div>
    </section>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
/*
 * IframeWidgetCard — the shared widget-card chrome used by the full-tab
 * iframe views (Budget, Time, etc.). Mirrors the visual language of the
 * dashboard home widgets in TeamWidgetGrid: rounded card with a header
 * strip (icon + title, action row on the right, optional collapse
 * chevron), hairline divider, body content, optional footer.
 *
 * Introduced in v3.101.1 (gui.md follow-up) so the Budget and Time
 * iframes can be composed from multiple cards instead of one long
 * scrolling section — same "one concept per card" pattern the dashboard
 * already uses.
 *
 * Design tokens: uses --th-radius-card, --color-main-background,
 * --color-border, --color-primary-element etc. per SKILLS.md
 * § "Design tokens" and § "Colour: NC theme tokens".
 *
 * Slots:
 *  - #icon:    MDI icon component (rendered green, sized 18px by convention).
 *  - #actions: buttons/chips rendered right of the title (e.g. Settings, Add).
 *  - default:  the widget body — any content.
 *  - #footer:  optional footer strip (e.g. "Show all N →" link).
 *
 * The collapse chevron follows the pattern established in
 * WidgetCollapseButton.vue — uses the same {widget} placeholder
 * translation keys.
 */
import { translate as t } from '@nextcloud/l10n'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import { ICON_BODY } from '../constants/uiTokens.js'

let uid = 0

export default {
    name: 'IframeWidgetCard',

    components: {  NcButton, ChevronDown, ChevronUp },

    props: {
        title: { type: String, required: true },
        badge: { type: [String, Number], default: null },
        collapsible: { type: Boolean, default: true },
        defaultCollapsed: { type: Boolean, default: false },
    },

    data() {
        return {
            ICON_BODY,
            isCollapsed: !!this.defaultCollapsed,
            titleId: 'th-iframe-widget-title-' + (++uid),
        }
    },

    methods: {
        t,
        toggleCollapsed() {
            this.isCollapsed = !this.isCollapsed
            this.$emit('toggle', this.isCollapsed)
        },
    },

    emits: ['toggle'],
}
</script>

<style scoped>
.th-iframe-widget {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--th-radius-card);
    margin-bottom: 12px;
    overflow: hidden;
}

/* v3.103.1: min-height sized so the header row always accommodates a
   standard NcButton (34 px) without growing. Prevents the widget header
   from jumping height when the #actions slot swaps content — the Time
   report widget hits this when the per-member select appears next to
   the Log-time button and pushes the header taller than the per-lane
   view. Applies globally so no future consumer of IframeWidgetCard has
   to re-solve the same layout jitter. */
.th-iframe-widget__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 8px 16px;
    min-height: 52px;
    border-bottom: 1px solid var(--color-border);
    background: var(--color-main-background);
}

.th-iframe-widget--collapsed .th-iframe-widget__header {
    border-bottom-color: transparent;
}

.th-iframe-widget__title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: var(--th-font-body);
    font-weight: var(--th-font-weight-semibold);
    color: var(--color-primary-element);
    min-width: 0;
}

.th-iframe-widget__title-text {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.th-iframe-widget__icon {
    display: inline-flex;
    align-items: center;
    flex-shrink: 0;
}

.th-iframe-widget__badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    padding: 0 8px;
    border-radius: var(--border-radius-pill);
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    font-size: var(--th-font-micro);
    font-weight: var(--th-font-weight-semibold);
    line-height: 1;
    flex-shrink: 0;
}

.th-iframe-widget__actions {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-shrink: 0;
}

/* v3.101.1: the header chevron uses the same six-lock circular recipe
   as WidgetCollapseButton so NC's global button min-width doesn't
   stretch it into an oval (SKILLS.md § "UI shapes"). */
.th-iframe-widget__collapse-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    box-sizing: border-box;
    width: 28px;
    max-width: 28px;
}

.th-iframe-widget__body {
    padding: 12px 16px;
}

.th-iframe-widget__footer {
    padding: 8px 16px;
    border-top: 1px solid var(--color-border);
    background: var(--color-background-dark);
    font-size: var(--th-font-meta);
    color: var(--color-primary-element);
    text-align: center;
}
</style>
