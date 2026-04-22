<template>
    <NcModal
        :name="t('teamhub', 'Create personal task')"
        @close="$emit('close')">
        <div class="addtask-modal">
            <h3 class="addtask-modal__title">
                <ClipboardPlusOutline :size="20" />
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
                <NcButton type="primary" :disabled="saving" @click="submit">
                    <template #icon>
                        <NcLoadingIcon v-if="saving" :size="18" />
                        <ClipboardPlusOutline v-else :size="18" />
                    </template>
                    {{ saving ? t('teamhub', 'Adding…') : t('teamhub', 'Add task') }}
                </NcButton>
                <NcButton type="tertiary" @click="$emit('close')">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
            </div>
        </div>
    </NcModal>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { NcModal, NcButton, NcLoadingIcon, NcTextField, NcTextArea } from '@nextcloud/vue'
import ClipboardPlusOutline from 'vue-material-design-icons/ClipboardPlusOutline.vue'

export default {
    name: 'AddPersonalTaskModal',

    components: { NcModal, NcButton, NcLoadingIcon, NcTextField, NcTextArea, ClipboardPlusOutline },

    props: {
        teamId: { type: String, required: true },
    },

    emits: ['close', 'created'],

    data() {
        return {
            saving: false,
            errors: {},
            form: {
                title:       '',
                description: '',
                dueDate:     '',
                dueTime:     '09:00',
            },
        }
    },

    computed: {
        todayDate() {
            const n = new Date()
            const pad = v => String(v).padStart(2, '0')
            return `${n.getFullYear()}-${pad(n.getMonth() + 1)}-${pad(n.getDate())}`
        },
    },

    methods: {
        t,

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

            const payload = { title: this.form.title.trim() }

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
                const msg = e?.response?.data?.error || ''
                this.errors.general = t('teamhub', 'Failed to add task') + (msg ? `: ${msg}` : '')
                showError(t('teamhub', 'Failed to add task'))
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

.addtask-modal__title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 18px;
    font-weight: 700;
    margin: 0 0 8px;
    color: var(--color-main-text);
}

.addtask-modal__hint {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    margin: 0 0 20px;
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
    font-size: 13px;
    font-weight: 500;
    color: var(--color-text-maxcontrast);
    margin-bottom: 5px;
}

.addtask-modal__input {
    width: 100%;
    padding: 8px 12px;
    border: 2px solid var(--color-border-maxcontrast);
    border-radius: var(--border-radius-large);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 14px;
    font-family: inherit;
    box-sizing: border-box;
}

.addtask-modal__input:focus {
    outline: none;
    border-color: var(--color-primary-element);
}

.addtask-modal__input:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.addtask-modal__error {
    font-size: 13px;
    color: var(--color-error);
    margin: 0 0 16px;
}

.addtask-modal__actions {
    display: flex;
    gap: 12px;
    margin-top: 8px;
}
</style>
