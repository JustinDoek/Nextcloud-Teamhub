<template>
    <!-- v4.20.19 — meeting wrap-up (DESIGN §2.271). Opened from the
         organiser's My Work row once the meeting is over. Action items found
         in the notes are filled in; whatever nobody wrote down is typed
         here. Proposals are only linked to the notes: the approvers decide. -->
    <NcDialog
        :name="t('teamhub', 'Wrap up meeting')"
        :open="true"
        size="normal"
        @update:open="v => !v && close()">
        <template #default>
            <div v-if="loading" class="wrapup__loading">
                <NcLoadingIcon :size="ICON_BODY" />
                <span>{{ t('teamhub', 'Reading the meeting notes') }}</span>
            </div>

            <NcNoteCard v-else-if="loadError" type="error">
                <p>{{ loadError }}</p>
                <NcButton variant="secondary" @click="load">{{ t('teamhub', 'Try again') }}</NcButton>
            </NcNoteCard>

            <div v-else class="wrapup">
                <p class="wrapup__meeting">
                    <strong>{{ data.meeting.title }}</strong>
                    <span class="wrapup__when">{{ meetingWhen }}</span>
                    <a
                        v-if="safeNotesUrl"
                        :href="safeNotesUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="wrapup__notes-link">
                        <FileDocumentOutlineIcon :size="ICON_INLINE" />
                        {{ t('teamhub', 'Open the notes') }}
                    </a>
                </p>

                <NcNoteCard v-if="data.wrapped" type="success">
                    {{ data.wrapped.status === 'skipped'
                        ? t('teamhub', 'This meeting was marked as needing no wrap-up.')
                        : t('teamhub', 'This meeting is already wrapped up.') }}
                </NcNoteCard>

                <template v-else>
                    <p class="wrapup__hint">
                        <template v-if="!data.notesFound">
                            {{ t('teamhub', 'The notes file could not be found. Add the action items below; nothing is written back.') }}
                        </template>
                        <template v-else-if="data.actionItems.length > 0">
                            {{ n('teamhub', '%n action item was found in the notes. Check who does it and by when.', '%n action items were found in the notes. Check who does each and by when.', data.actionItems.length, { n: data.actionItems.length }) }}
                        </template>
                        <template v-else>
                            {{ t('teamhub', 'No action items were written in the notes. Add them below, or leave this empty.') }}
                        </template>
                    </p>

                    <!-- Action items → team tasks -->
                    <section class="wrapup__section" :aria-labelledby="`${uid}-tasks`">
                        <h3 :id="`${uid}-tasks`" class="wrapup__heading">{{ t('teamhub', 'Action items') }}</h3>
                        <NcNoteCard v-if="!data.tasksAvailable" type="warning">
                            {{ t('teamhub', 'Action items become tasks, which need the Tasks app. Ask your administrator to install it.') }}
                        </NcNoteCard>
                        <template v-else>
                            <p class="wrapup__sub">{{ t('teamhub', 'Each one becomes a team task for the person named, in their My Work.') }}</p>
                            <ul class="wrapup__tasks">
                                <li v-for="(task, i) in tasks" :key="task.key" class="wrapup__task">
                                    <div class="wrapup__task-title">
                                        <label class="wrapup__label" :for="`${uid}-title-${task.key}`">{{ t('teamhub', 'What') }}</label>
                                        <NcTextField
                                            :id="`${uid}-title-${task.key}`"
                                            v-model="task.title"
                                            label-outside
                                            maxlength="255"
                                            :aria-label="t('teamhub', 'What')"
                                            :disabled="saving" />
                                    </div>
                                    <div class="wrapup__task-who">
                                        <label class="wrapup__label" :for="`${uid}-who-${task.key}`">{{ t('teamhub', 'Who') }}</label>
                                        <NcSelect
                                            v-model="task.assignee"
                                            :input-id="`${uid}-who-${task.key}`"
                                            label-outside
                                            :options="assigneeOptions"
                                            :reduce="o => o.id"
                                            :clearable="false"
                                            :aria-label-combobox="t('teamhub', 'Who')"
                                            :disabled="saving" />
                                    </div>
                                    <div class="wrapup__task-due">
                                        <label class="wrapup__label" :for="`${uid}-due-${task.key}`">{{ t('teamhub', 'By when') }}</label>
                                        <!-- Native date input: NC's recommended picker (ui-standards). -->
                                        <input
                                            :id="`${uid}-due-${task.key}`"
                                            v-model="task.due"
                                            type="date"
                                            class="wrapup__date"
                                            :disabled="saving">
                                    </div>
                                    <NcButton
                                        variant="tertiary"
                                        class="wrapup__remove"
                                        :aria-label="t('teamhub', 'Remove action item {n}', { n: i + 1 })"
                                        :title="t('teamhub', 'Remove action item {n}', { n: i + 1 })"
                                        :disabled="saving"
                                        @click="removeTask(i)">
                                        <template #icon><CloseIcon :size="ICON_BODY" /></template>
                                    </NcButton>
                                </li>
                            </ul>
                            <NcButton variant="secondary" :disabled="saving || tasks.length >= 50" @click="addTask()">
                                <template #icon><PlusIcon :size="ICON_BODY" /></template>
                                {{ t('teamhub', 'Add action item') }}
                            </NcButton>
                        </template>
                    </section>

                    <!-- Proposals discussed → a link to the notes in their history -->
                    <section v-if="data.proposals.length > 0" class="wrapup__section" :aria-labelledby="`${uid}-proposals`">
                        <h3 :id="`${uid}-proposals`" class="wrapup__heading">{{ t('teamhub', 'Proposals discussed') }}</h3>
                        <p class="wrapup__sub">{{ t('teamhub', 'Each proposal you tick gets a link to these notes in its history. The approvers still decide in Decisions.') }}</p>
                        <NcCheckboxRadioSwitch
                            v-for="p in data.proposals"
                            :key="p.id"
                            :model-value="picked.includes(p.id)"
                            :disabled="saving"
                            @update:model-value="v => toggleProposal(p.id, v)">
                            {{ p.question }}
                            <span v-if="p.inNotes" class="wrapup__in-notes">{{ t('teamhub', 'On the agenda') }}</span>
                        </NcCheckboxRadioSwitch>
                    </section>

                    <NcNoteCard v-if="saveError" type="error">{{ saveError }}</NcNoteCard>
                </template>
            </div>
        </template>

        <template #actions>
            <NcButton variant="tertiary" :disabled="saving" @click="close">
                {{ data && data.wrapped ? t('teamhub', 'Close') : t('teamhub', 'Cancel') }}
            </NcButton>
            <NcButton
                v-if="data && !data.wrapped && !loadError"
                variant="primary"
                :disabled="saving || loading"
                @click="submit">
                <template #icon>
                    <NcLoadingIcon v-if="saving" :size="ICON_BODY" />
                    <CheckIcon v-else :size="ICON_BODY" />
                </template>
                {{ t('teamhub', 'Wrap up meeting') }}
            </NcButton>
        </template>
    </NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess, showWarning } from '@nextcloud/dialogs'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import { ICON_BODY, ICON_INLINE } from '../constants/uiTokens.js'
import { wrapUpPayload, wrapUpTasks } from '../lib/meetingWrapUp.js'

let instanceCounter = 0

/**
 * MeetingWrapUpDialog (v4.20.19, DESIGN §2.271): the organiser hands out the
 * meeting's action items as team tasks and links the proposals discussed to
 * the notes. `MeetingWrapUpService` does the work and writes the outcome back
 * into the notes file.
 */
export default {
    name: 'MeetingWrapUpDialog',
    components: {
        NcButton,
        NcCheckboxRadioSwitch,
        NcDialog,
        NcLoadingIcon,
        NcNoteCard,
        NcSelect,
        NcTextField,
        CheckIcon,
        CloseIcon,
        FileDocumentOutlineIcon,
        PlusIcon,
    },
    props: {
        teamId: { type: String, required: true },
        objectId: { type: Number, required: true },
        start: { type: Number, required: true },
    },
    emits: ['close', 'done'],
    data() {
        instanceCounter += 1
        return {
            ICON_BODY,
            ICON_INLINE,
            uid: `wrapup-${instanceCounter}`,
            loading: true,
            loadError: '',
            saving: false,
            saveError: '',
            data: null,
            tasks: [],
            picked: [],
            nextKey: 0,
        }
    },
    computed: {
        url() {
            return generateUrl('/apps/teamhub/api/v1/teams/{teamId}/meetings/wrap-up/{objectId}/{start}', {
                teamId: this.teamId,
                objectId: this.objectId,
                start: this.start,
            })
        },
        assigneeOptions() {
            return [
                { id: '', label: t('teamhub', 'The whole team') },
                ...(this.data?.members || []).map(m => ({ id: m.userId, label: m.displayName })),
            ]
        },
        meetingWhen() {
            if (!this.data) return ''
            const start = new Date(this.data.meeting.start * 1000)
            return start.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
        },
        /** The notes link, only when it is a plain web address. */
        safeNotesUrl() {
            const url = this.data?.meeting?.notesUrl || ''
            return /^https:\/\//i.test(url) ? url : ''
        },
    },
    mounted() {
        this.load()
    },
    methods: {
        t,
        n,

        async load() {
            this.loading = true
            this.loadError = ''
            try {
                const { data } = await axios.get(this.url)
                this.data = data
                this.tasks = wrapUpTasks(data.actionItems).map(task => ({ ...task, key: this.nextKey++ }))
                if (this.tasks.length === 0 && data.tasksAvailable) {
                    this.addTask()
                }
                this.picked = (data.proposals || []).filter(p => p.inNotes).map(p => p.id)
            } catch (e) {
                this.loadError = e?.response?.status === 404
                    ? t('teamhub', 'This meeting has no wrap-up for you. Only its organiser can wrap it up, after it has ended.')
                    : t('teamhub', 'The wrap-up could not be loaded.')
            } finally {
                this.loading = false
            }
        },

        addTask() {
            this.tasks.push({ title: '', assignee: '', due: '', key: this.nextKey++ })
        },

        removeTask(index) {
            this.tasks.splice(index, 1)
        },

        toggleProposal(id, on) {
            this.picked = on ? [...new Set([...this.picked, id])] : this.picked.filter(p => p !== id)
        },

        async submit() {
            this.saving = true
            this.saveError = ''
            try {
                const { data } = await axios.post(this.url, wrapUpPayload(this.tasks, this.picked))
                if (data.failedTasks && data.failedTasks.length > 0) {
                    showWarning(n('teamhub', '%n task could not be created: {titles}', '%n tasks could not be created: {titles}', data.failedTasks.length, {
                        n: data.failedTasks.length,
                        titles: data.failedTasks.join(', '),
                    }))
                } else {
                    showSuccess(t('teamhub', 'Meeting wrapped up'))
                }
                this.$emit('done', data)
            } catch (e) {
                this.saveError = e?.response?.data?.error && e.response.status === 400
                    ? e.response.data.error
                    : t('teamhub', 'The meeting could not be wrapped up. Try again.')
            } finally {
                this.saving = false
            }
        },

        close() {
            if (!this.saving) {
                this.$emit('close')
            }
        },
    },
}
</script>

<style scoped>
.wrapup,
.wrapup__section {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-sm);
}

.wrapup {
    gap: var(--th-space-md);
}

.wrapup__loading {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
    padding-block: var(--th-space-md);
}

.wrapup__meeting {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: var(--th-space-xs) var(--th-space-md);
    margin: 0;
}

.wrapup__when,
.wrapup__sub,
.wrapup__hint,
.wrapup__in-notes {
    color: var(--color-text-maxcontrast);
}

.wrapup__sub,
.wrapup__hint {
    margin: 0;
}

.wrapup__notes-link {
    display: inline-flex;
    align-items: center;
    gap: var(--th-space-xs);
    color: var(--color-primary-element);
}

.wrapup__notes-link:focus-visible {
    outline: calc(var(--default-grid-baseline) / 2) solid var(--color-primary-element);
    outline-offset: calc(var(--default-grid-baseline) / 2);
    border-radius: var(--border-radius-small);
}

.wrapup__heading {
    margin: 0;
    font-size: var(--th-font-body);
    font-weight: var(--th-font-weight-medium);
}

.wrapup__tasks {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-sm);
    margin: 0;
    padding: 0;
    list-style: none;
}

.wrapup__task {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: var(--th-space-sm);
}

.wrapup__task-title {
    flex: 3 1 calc(var(--th-space-xxl) * 6);
    min-width: 0;
}

/* The select fills its own box (the Roster picker fix, DESIGN §2.252). */
.wrapup__task-who {
    flex: 2 1 calc(var(--th-space-xxl) * 4);
    min-width: 0;
}

.wrapup__task-who :deep(.v-select.select) {
    inline-size: 100%;
    margin: 0;
}

.wrapup__task-due {
    flex: 0 0 auto;
}

.wrapup__label {
    display: block;
    margin-block-end: var(--th-space-xs);
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

/* Three-part selector so it outranks NC's global input margin rule. */
.wrapup__task-due input.wrapup__date {
    margin: 0;
}

.wrapup__in-notes {
    margin-inline-start: var(--th-space-xs);
    font-size: var(--th-font-meta);
}
</style>
