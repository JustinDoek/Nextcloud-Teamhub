<template>
    <NcModal
        :name="t('teamhub', 'Create personal task')"
        @close="$emit('close')">
        <div class="addtask-modal">
            <h3 class="addtask-modal__title">
                <ClipboardPlusOutline :size="ICON_BODY" />
                {{ t('teamhub', 'Create personal task') }}
            </h3>

            <p class="addtask-modal__hint">
                {{ t('teamhub', 'This task will be added to the team calendar and visible in the Tasks app.') }}
            </p>

            <div class="addtask-modal__field">
                <NcTextField
                    v-model="form.title"
                    :label="t('teamhub', 'Task title')"
                    :placeholder="t('teamhub', 'e.g. Prepare presentation')"
                    :error="!!errors.title"
                    :helper-text="errors.title || ''" />
            </div>

            <!--
                v4.11.15 — who the task is for. Without it the task names
                nobody, and My Work has to guess between a to-do somebody wrote
                for themselves and one the whole team owes — it guessed wrong in
                both directions. Defaults to you, which is also what makes this
                dialog's own name honest.

                `input-id` + `label-outside` with the label above: the same
                pattern as AddOpenProjectWorkPackageModal, where the reasoning
                for it is written out.
            -->
            <div class="addtask-modal__field">
                <label class="addtask-modal__label" for="th-task-assignee">
                    {{ t('teamhub', 'Who the task is for') }}
                </label>
                <NcSelect
                    input-id="th-task-assignee"
                    label-outside
                    v-model="form.assignee"
                    :options="assigneeOptions"
                    :clearable="false"
                    :disabled="saving"
                    label="label" />
                <p class="addtask-modal__hint addtask-modal__hint--field">
                    {{ assigneeHint }}
                </p>
            </div>

            <div class="addtask-modal__field">
                <NcTextArea
                    v-model="form.description"
                    :label="t('teamhub', 'Description (optional)')"
                    :placeholder="t('teamhub', 'More details about the task…')"
                    :rows="3" />
            </div>

            <div class="addtask-modal__row">
                <div class="addtask-modal__field">
                    <label class="addtask-modal__label">{{ t('teamhub', 'Due date (optional)') }}</label>
                    <input
                        v-model="form.dueDate"
                        type="date"
                        class="addtask-modal__input"
                        :min="todayDate" />
                </div>
                <div class="addtask-modal__field">
                    <label class="addtask-modal__label">{{ t('teamhub', 'Due time') }}</label>
                    <input
                        v-model="form.dueTime"
                        type="time"
                        class="addtask-modal__input"
                        :disabled="!form.dueDate" />
                </div>
            </div>

            <p v-if="errors.general" class="addtask-modal__error">{{ errors.general }}</p>

            <div class="addtask-modal__actions">
                <NcButton variant="primary" :disabled="saving" @click="submit">
                    <template #icon>
                        <NcLoadingIcon v-if="saving" :size="ICON_BODY" />
                        <ClipboardPlusOutline v-else :size="ICON_BODY" />
                    </template>
                    {{ saving ? t('teamhub', 'Adding…') : t('teamhub', 'Add task') }}
                </NcButton>
                <NcButton variant="tertiary" @click="$emit('close')">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
            </div>
        </div>
    </NcModal>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { getCurrentUser } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { NcModal, NcButton, NcLoadingIcon, NcTextField, NcTextArea, NcSelect } from '@nextcloud/vue'
import ClipboardPlusOutline from 'vue-material-design-icons/ClipboardPlusOutline.vue'
import { ICON_BODY } from '../constants/uiTokens.js'

/** Mirrors `TaskService::ASSIGN_SELF` / `::ASSIGN_TEAM`. Keep in sync. */
const ASSIGN_SELF = 'self'
const ASSIGN_TEAM = 'team'

export default {
    name: 'AddPersonalTaskModal',

    components: { NcModal, NcButton, NcLoadingIcon, NcTextField, NcTextArea, NcSelect, ClipboardPlusOutline },

    props: {
        teamId: { type: String, required: true },
    },

    emits: ['close', 'created'],

    data() {
        return {
            ICON_BODY,
            saving: false,
            errors: {},
            /** Team members, for the "For" picker. Self is filtered out — `Me` is already an option. */
            members: [],
            form: {
                title:       '',
                description: '',
                dueDate:     '',
                dueTime:     '09:00',
                // Yourself, until you say otherwise.
                assignee:    null,
            },
        }
    },

    computed: {
        todayDate() {
            const n = new Date()
            const pad = v => String(v).padStart(2, '0')
            return `${n.getFullYear()}-${pad(n.getMonth() + 1)}-${pad(n.getDate())}`
        },

        /**
         * Yourself, then the team, then everybody else in it.
         *
         * `self` and `team` are sent as keywords rather than as your uid and an
         * empty string, so the backend resolves them and the payload says what
         * was meant rather than what it happened to evaluate to.
         */
        assigneeOptions() {
            const options = [
                { id: ASSIGN_SELF, label: t('teamhub', 'Me') },
                { id: ASSIGN_TEAM, label: t('teamhub', 'The whole team') },
            ]

            const me = getCurrentUser()?.uid
            for (const member of this.members || []) {
                const uid = member.userId || member.uid
                if (!uid || uid === me) {
                    continue
                }
                options.push({ id: uid, label: member.displayName || member.displayname || uid })
            }

            return options
        },

        /** What picking this option actually does, in one line. */
        assigneeHint() {
            const id = this.form.assignee?.id
            if (id === ASSIGN_TEAM) {
                return t('teamhub', 'Everybody in the team sees it on their My Work page.')
            }
            if (!id || id === ASSIGN_SELF) {
                return t('teamhub', 'It appears on your My Work page and nobody else’s.')
            }
            return t('teamhub', 'It appears on their My Work page and they are notified. You can follow it under Waiting for others.')
        },
    },

    created() {
        // The default has to be an object from the options list, or NcSelect
        // shows its placeholder instead of the selection.
        this.form.assignee = this.assigneeOptions[0]
    },

    mounted() {
        this.loadMembers()
    },

    methods: {
        t,

        /**
         * The team's members, for the picker.
         *
         * Best-effort: a failure leaves *Me* and *The whole team*, which is
         * every case the dialog had before this field existed. A task you
         * cannot assign is better than a dialog that will not open.
         */
        async loadMembers() {
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/members/all`),
                )
                const list = Array.isArray(data) ? data : (Array.isArray(data?.members) ? data.members : [])
                const me = (getCurrentUser()?.uid || '').toLowerCase()
                this.members = list.filter(m => (m.userId || '').toLowerCase() !== me)
            } catch (e) {
                this.members = []
            }
        },

        validate() {
            this.errors = {}
            if (!this.form.title.trim()) {
                this.errors.title = t('teamhub', 'Task title is required')
            }
            return Object.keys(this.errors).length === 0
        },

        async submit() {
            if (!this.validate()) return
            this.saving = true
            this.errors = {}

            const payload = {
                title:    this.form.title.trim(),
                // `self` / `team` / a uid — see TaskService::resolveAssignee().
                assignee: this.form.assignee?.id || ASSIGN_SELF,
            }

            if (this.form.description.trim()) {
                payload.description = this.form.description.trim()
            }

            if (this.form.dueDate) {
                payload.duedate = new Date(`${this.form.dueDate}T${this.form.dueTime}:00`).toISOString()
            }


            try {
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/tasks`),
                    payload,
                )
                showSuccess(t('teamhub', 'Task added'))
                this.$emit('created')
                this.$emit('close')
            } catch (e) {
                // Issue #106 — the server's own sentence, and the correlation
                // id when it sends one, in both the inline error and the toast.
                // On hosting that only ships logs to syslog the id is the one
                // thing a user can quote back.
                const data = e?.response?.data
                const msg = data?.ref
                    ? `${data.error || t('teamhub', 'Server error')} (ref ${data.ref})`
                    : data?.error
                const sentence = msg
                    ? t('teamhub', 'Failed to add task: {error}', { error: msg })
                    : t('teamhub', 'Failed to add task')
                this.errors.general = sentence
                showError(sentence)
            } finally {
                this.saving = false
            }
        },
    },
}
</script>

<style scoped>
.addtask-modal {
    padding: 24px;
    max-width: 520px;
    min-width: 340px;
}

/* Allow the modal to shrink below 340px on narrow screens. */
@media (max-width: 768px) {
    .addtask-modal {
        min-width: 0;
        padding: 16px;
    }
}

.addtask-modal__title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: var(--th-font-heading);
    font-weight: 700;
    margin: 0 0 8px;
    color: var(--color-main-text);
}

.addtask-modal__hint {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    margin: 0 0 20px;
}

/* The line under the "For" picker explains that one field, not the dialog. */
.addtask-modal__hint--field {
    margin: 4px 0 0;
}

.addtask-modal__field { margin-bottom: 16px; }

.addtask-modal__row {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}

.addtask-modal__row .addtask-modal__field {
    flex: 1;
    min-width: 140px;
    margin-bottom: 0;
}

.addtask-modal__label {
    display: block;
    font-size: var(--th-font-meta);
    font-weight: 500;
    color: var(--color-text-maxcontrast);
    margin-bottom: 4px;
}

.addtask-modal__input {
    width: 100%;
    padding: 8px 12px;
    border: 2px solid var(--color-border-maxcontrast);
    border-radius: var(--border-radius-element);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: var(--th-font-body);
    font-family: inherit;
    box-sizing: border-box;
}

.addtask-modal__input:focus {
    border-color: var(--color-primary-element);
}

.addtask-modal__input:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

.addtask-modal__input:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.addtask-modal__error {
    font-size: var(--th-font-meta);
    color: var(--color-text-error);
    margin: 0 0 16px;
}

.addtask-modal__actions {
    display: flex;
    gap: 12px;
    margin-top: 8px;
}
</style>
