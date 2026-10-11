<template>
    <button
        type="button"
        class="th-info"
        :class="{ 'th-info--open': open }"
        :aria-expanded="open ? 'true' : 'false'"
        :aria-controls="controls"
        :aria-label="label"
        :title="label"
        @click="$emit('toggle')">
        <InformationOutline :size="ICON_INLINE" />
    </button>
</template>

<script>
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import { ICON_INLINE } from '../../constants/uiTokens.js'
import { translate as t } from '@nextcloud/l10n'

/**
 * The round (i) beside a setting's label (v4.11.33).
 *
 * Why this exists: Justin's rule for the admin panel is that a control and
 * its label line up in a grid and the explanation lives behind an (i) — a
 * paragraph of prose under every field turned the Team creation tab into a
 * wall of text. So each row keeps its label and its control, and the sentence
 * that used to sit under it opens on demand.
 *
 * **A raw `<button>` on purpose.** `/ui-standards` lists the round help
 * affordance as one of the documented carve-outs from "NcButton is the
 * default": NcButton owns its own padding and sizing, and this has to be a
 * perfect 20px circle. The scoped style below is the six-lock recipe from
 * that skill verbatim — Nextcloud's global button rule sets **both**
 * `min-width` and `min-height` to `--default-clickable-area`, and per CSS
 * spec `min-*` beats an unqualified `width`/`height`, so without all six
 * locks this renders as an oval.
 *
 * The component is the button only. The hint paragraph belongs to the parent,
 * because in a two-column grid it has to span both columns — which it can
 * only do as a direct grid child. The parent owns the open state (one map
 * keyed by id) and passes `controls` so `aria-controls` points at the real
 * paragraph.
 */
export default {
    name: 'InfoHint',
    components: { InformationOutline },
    props: {
        /** Whether the hint this button controls is currently open. */
        open: {
            type: Boolean,
            default: false,
        },
        /** The id of the paragraph the parent renders for this hint. */
        controls: {
            type: String,
            required: true,
        },
        /** Accessible name. Defaults to a generic one when the row has none. */
        label: {
            type: String,
            default: () => t('teamhub', 'Show the explanation'),
        },
    },
    emits: ['toggle'],
    data() {
        return { ICON_INLINE }
    },
    methods: { t },
}
</script>

<style scoped>
/* The six locks — see the component note. Do not replace with `min-width: 0`
   (some browsers zero the whole button) and do not reach for `!important`. */
.th-info {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    box-sizing: border-box;
    width: 20px;
    height: 20px;
    min-width: 20px;
    min-height: 20px;
    max-width: 20px;
    max-height: 20px;
    padding: 0;
    margin-inline-start: var(--th-space-xs);
    border: none;
    border-radius: 50%;
    line-height: 1;
    background: transparent;
    color: var(--color-text-maxcontrast);
    cursor: pointer;
    vertical-align: middle;
}

.th-info:hover {
    background: var(--color-background-hover);
    color: var(--color-main-text);
}

/* Split from :hover so the keyboard ring is never silenced by the shared
   background rule — the trap `/ui-standards` names. */
.th-info:focus-visible {
    background: var(--color-background-hover);
    color: var(--color-main-text);
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}

.th-info--open {
    background: var(--color-primary-element-light);
    color: var(--color-main-text);
}
</style>
