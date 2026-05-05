<template>
    <NcModal
        :name="t('teamhub', 'Add task')"
        @close="$emit('close')">
        <div class="addtask-modal">
            <h3 class="addtask-modal__title">
                <CheckboxMarkedOutline :size="20" />
                {{ t('teamhub', 'Add task') }}
            </h3>

            <div class="addtask-modal__field">
                <NcTextField
                    v-model="form.title"
                    :label="t('teamhub', 'Task title')"
                    :placeholder="t('teamhub', 'e.g. Review design mockups')"
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
                        <CheckboxMarkedOutline v-else :size="18" />
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
import CheckboxMarkedOutline from 'vue-material-design-icons/CheckboxMarkedOutline.vue'

export default {
    name: 'AddTaskModal',
    components: { NcModal, NcButton, NcLoadingIcon, NcTextField, NcTextArea, CheckboxMarkedOutline },
    props: {
        boardId: { type: [String, Number], required: true },
    },
    emits: ['close', 'created'],
    data() {
        return {
            saving: false,
            errors: {},
            stacks: [],
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
            return `${n.getFullYear()}-${pad(n.getMonth()+1)}-${pad(n.getDate())}`
        },
    },
    async mounted() {
        // Pre-fetch stacks so we can pick the first one on submit
        try {
            const { data } = await axios.get(
                generateUrl(`/apps/deck/api/v1.0/boards/${this.boardId}/stacks`),
                { headers: { 'OCS-APIRequest': 'true' } }
            )
            this.stacks = Array.isArray(data) ? data : []
        } catch (e) {
            // Will show error on submit if no stack found
        }
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

            if (this.stacks.length === 0) {
                this.errors.general = t('teamhub', 'No stack found on this board. Please add a stack in Deck first.')
                this.saving = false
                return
            }

            const stackId = this.stacks[0].id
            const payload = { title: this.form.title.trim() }

            if (this.form.description.trim()) {
                payload.description = this.form.description.trim()
            }

            if (this.form.dueDate) {
                const dueIso = new Date(`${this.form.dueDate}T${this.form.dueTime}:00`).toISOString()
                payload.duedate = dueIso
            }

            try {
                await axios.post(
                    generateUrl(`/apps/deck/api/v1.0/boards/${this.boardId}/stacks/${stackId}/cards`),
                    payload,
                    { headers: { 'OCS-APIRequest': 'true' } }
                )
                showSuccess(t('teamhub', 'Task added'))
                this.$emit('created')
                this.$emit('close')
            } catch (e) {
                const msg = e?.response?.data?.message || ''
                this.errors.general = msg ? t('teamhub', 'Failed to add task: {error}', { error: msg }) : t('teamhub', 'Failed to add task')
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
    gap: 10px;
    font-size: 18px;
    font-weight: 700;
    margin: 0 0 24px;
    color: var(--color-main-text);
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
    font-size: 13px;
    color: var(--color-error-text);
    margin: 0 0 16px;
}

.addtask-modal__actions {
    display: flex;
    gap: 12px;
    margin-top: 8px;
}
</style>
