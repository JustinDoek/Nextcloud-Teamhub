<template>
    <button
        type="button"
        class="teamhub-widget-collapse-btn"
        :aria-label="ariaLabel"
        @click.stop="$emit('toggle')">
        <ChevronUp v-if="!collapsed" :size="iconSize" />
        <ChevronDown v-else :size="iconSize" />
    </button>
</template>

<script>
/*
 * WidgetCollapseButton — the chevron button in every home-widget header
 * that toggles the widget's collapsed state.
 *
 * Extracted (v3.100.14, gui.md § 6) because the 5-line pattern was
 * duplicated 12 times inside TeamWidgetGrid.vue — every widget's
 * template repeated the same button, aria-label ternary, and paired
 * ChevronUp/ChevronDown v-if pair. Any drift (icon size, missing
 * @click.stop, aria-label wording) silently broke consistency.
 *
 * The aria-label uses the `{widget}` placeholder pattern so the label
 * follows the caller's translated widget name and translators can
 * reposition the name within their language's sentence structure. The
 * caller passes an already-translated widget name; this component
 * plugs it into the translated "Expand {widget}" / "Collapse {widget}"
 * templates. Both templates carry TRANSLATORS notes because "Expand"
 * and "Collapse" are ambiguous verbs out of context.
 */
import { translate as t } from '@nextcloud/l10n'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import { ICON_BODY } from '../constants/uiTokens.js'

export default {
    name: 'WidgetCollapseButton',

    components: { ChevronUp, ChevronDown },

    props: {
        // Whether the widget is currently collapsed. Drives the chevron
        // direction (down = collapsed / expandable; up = expanded / collapsible)
        // and the aria-label ternary.
        collapsed: { type: Boolean, required: true },
        // Human-readable name of the widget being toggled, already translated
        // by the caller. Plugged into "Expand {widget}" / "Collapse {widget}"
        // in the aria-label.
        widgetName: { type: String, required: true },
    },

    emits: ['toggle'],

    computed: {
        iconSize() { return ICON_BODY },
        ariaLabel() {
            return this.collapsed
                // TRANSLATORS: aria-label for the chevron button that expands
                // (un-collapses) a widget in the team home view.
                // {widget} is the localised widget name (e.g. "Team Messages").
                ? t('teamhub', 'Expand {widget}', { widget: this.widgetName })
                // TRANSLATORS: aria-label for the chevron button that collapses
                // (hides the body of) a widget in the team home view.
                // {widget} is the localised widget name (e.g. "Team Messages").
                : t('teamhub', 'Collapse {widget}', { widget: this.widgetName })
        },
    },

    methods: { t },
}
</script>
