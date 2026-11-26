<template>
    <!--
        v4.20.29 — the quick task. One icon on every team tab and on My Work:
        you see something that is not right, you have no time for it or it is
        not yours, and you hand it to yourself, a colleague or the team without
        leaving the page. The page goes with the task (src/lib/pageLink.js), so
        whoever picks it up lands where the problem was.

        The parent decides whether it shows (Tasks app installed, a team
        calendar to write to). Hidden, not disabled, when it cannot work.
    -->
    <span class="th-quicktask">
        <NcButton
            variant="tertiary"
            :aria-label="buttonLabel"
            :title="buttonLabel"
            @click="open">
            <template #icon>
                <ClipboardPlusOutline :size="iconSize || ICON_BODY" />
            </template>
        </NcButton>
        <AddPersonalTaskModal
            v-if="dialogOpen"
            :team-id="teamId"
            :link="openedLink"
            :link-label="openedLabel"
            :initial-title="openedTitle"
            @created="$emit('created')"
            @close="dialogOpen = false" />
    </span>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import ClipboardPlusOutline from 'vue-material-design-icons/ClipboardPlusOutline.vue'
import AddPersonalTaskModal from './AddPersonalTaskModal.vue'
import { ICON_BODY } from '../constants/uiTokens.js'

export default {
    name: 'QuickTaskButton',

    components: { NcButton, ClipboardPlusOutline, AddPersonalTaskModal },

    props: {
        // The team on screen; empty on a page across teams, where the dialog asks.
        teamId: { type: String, default: '' },
        // The page's address and its name, read when the dialog opens.
        link: { type: String, default: '' },
        linkLabel: { type: String, default: '' },
        // v4.20.30 — the follow-up entries on a message, a comment and a
        // decision: their own button name, a starting title, and the smaller
        // icon a comment row uses.
        label: { type: String, default: '' },
        initialTitle: { type: String, default: '' },
        iconSize: { type: Number, default: 0 },
    },

    emits: ['created'],

    data() {
        return {
            ICON_BODY,
            dialogOpen: false,
            openedLink: '',
            openedLabel: '',
            openedTitle: '',
        }
    },

    computed: {
        buttonLabel() {
            return this.label || t('teamhub', 'Create task')
        },
    },

    methods: {
        t,

        /**
         * Taken at the click, not bound live: an embedded app keeps moving
         * under an open dialog (a poll follows its address), and the task is
         * about the page the person was looking at when they decided to make it.
         */
        open() {
            this.openedLink = this.link
            this.openedLabel = this.linkLabel
            this.openedTitle = this.initialTitle
            this.dialogOpen = true
        },
    },
}
</script>

<style scoped>
.th-quicktask {
    display: inline-flex;
    flex-shrink: 0;
}
</style>
