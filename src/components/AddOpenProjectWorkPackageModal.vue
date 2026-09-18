<template>
    <NcModal
        :name="t('teamhub', 'Create work package')"
        @close="$emit('close')">
        <div class="th-op-create" :aria-busy="loading || saving">
            <h3 class="th-op-create__title">
                <BriefcaseOutline :size="ICON_NAV" aria-hidden="true" />
                {{ projectName
                    ? t('teamhub', 'New work package in {project}', { project: projectName })
                    : t('teamhub', 'Create work package') }}
            </h3>

            <!-- The form itself comes from OpenProject: the types and the
                 assignees are what OpenProject lets this viewer pick. -->
            <p v-if="loading" class="th-op-create__state">
                <NcLoadingIcon :size="ICON_BODY" aria-hidden="true" />
                {{ t('teamhub', 'Loading form from OpenProject') }}
            </p>

            <p v-else-if="loadError" class="th-op-create__error" role="alert">
                {{ loadError }}
            </p>

            <template v-else>
                <div class="th-op-create__field">
                    <NcTextField
                        v-model="form.subject"
                        :label="t('teamhub', 'Subject')"
                        :maxlength="SUBJECT_MAX_LENGTH"
                        :disabled="saving"
                        :error="!!errors.subject"
                        :helper-text="errors.subject || ''" />
                </div>

                <div class="th-op-create__row">
                    <div class="th-op-create__field">
                        <label for="th-op-create-type" class="th-op-create__label">
                            {{ t('teamhub', 'Type') }}
                            <span class="th-op-create__required" aria-hidden="true">*</span>
                            <!-- v4.10.0 — the asterisk is decoration; a reader
                                 hears the word (WCAG 3.3.2). -->
                            <span class="hidden-visually">{{ t('teamhub', 'Required') }}</span>
                        </label>
                        <!-- v4.10.0 — `input-id` puts the id on NcSelect's own
                             <input>, which is what the <label for> above has to
                             reach; a bare `id` lands on the wrapper div and the
                             label names nothing. `label-outside` tells the
                             component the label is ours (verified in
                             @nextcloud/vue 9.8.0's NcSelect). -->
                        <NcSelect
                            input-id="th-op-create-type"
                            label-outside
                            v-model="form.type"
                            :options="types"
                            :clearable="false"
                            :disabled="saving"
                            label="name"
                            :placeholder="t('teamhub', 'Pick a type')" />
                    </div>

                    <div v-if="!assigneesUnavailable" class="th-op-create__field">
                        <label for="th-op-create-assignee" class="th-op-create__label">
                            {{ t('teamhub', 'Assignee') }}
                        </label>
                        <NcSelect
                            input-id="th-op-create-assignee"
                            label-outside
                            v-model="form.assignee"
                            :options="assigneeOptions"
                            :clearable="false"
                            :disabled="saving"
                            label="name" />
                    </div>
                    <div v-else class="th-op-create__field">
                        <span class="th-op-create__label">{{ t('teamhub', 'Assignee') }}</span>
                        <p class="th-op-create__hint">
                            {{ t('teamhub', 'Assignees could not be loaded from OpenProject; the work package is created unassigned.') }}
                        </p>
                    </div>
                </div>

                <div class="th-op-create__row">
                    <div class="th-op-create__field">
                        <label for="th-op-create-due" class="th-op-create__label">
                            {{ t('teamhub', 'Due date (optional)') }}
                        </label>
                        <input
                            id="th-op-create-due"
                            v-model="form.dueDate"
                            type="date"
                            class="th-op-create__input"
                            :disabled="saving">
                    </div>
                </div>

                <div class="th-op-create__field">
                    <NcTextArea
                        v-model="form.description"
                        :label="t('teamhub', 'Description (optional)')"
                        :maxlength="DESCRIPTION_MAX_LENGTH"
                        :disabled="saving"
                        :rows="4" />
                </div>

                <p v-if="errors.general" class="th-op-create__error" role="alert">{{ errors.general }}</p>

                <div class="th-op-create__actions">
                    <!-- v4.9.18 — no glyph beside the label (Justin: drop the +);
                         the spinner still takes the icon slot while saving so the
                         button does not change width mid-request. -->
                    <NcButton variant="primary" :disabled="!canSubmit" @click="submit">
                        <template v-if="saving" #icon>
                            <NcLoadingIcon :size="ICON_TOOLBAR" />
                        </template>
                        {{ saving ? t('teamhub', 'Creating') : t('teamhub', 'Create work package') }}
                    </NcButton>
                    <NcButton variant="tertiary" :disabled="saving" @click="$emit('close')">
                        {{ t('teamhub', 'Cancel') }}
                    </NcButton>
                </div>
            </template>
        </div>
    </NcModal>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { NcModal, NcButton, NcLoadingIcon, NcTextField, NcTextArea, NcSelect } from '@nextcloud/vue'
import BriefcaseOutline from 'vue-material-design-icons/BriefcaseOutline.vue'
import { ICON_BODY, ICON_TOOLBAR, ICON_NAV } from '../constants/uiTokens.js'
import { classifyError, buildWorkPackagePayload } from '../lib/openProject.js'

/** Mirrors `OpenProjectWorkPackageService::SUBJECT_MAX_LENGTH` / `::DESCRIPTION_MAX_LENGTH`. */
const SUBJECT_MAX_LENGTH = 255
const DESCRIPTION_MAX_LENGTH = 5000

/**
 * AddOpenProjectWorkPackageModal (v4.9.15).
 *
 * The Upcoming tasks widget's "Create work package" (v4.9.18: "OpenProject" dropped from the label — the modal already names the project): subject,
 * type, assignee, due date, description. The types and assignees are
 * OpenProject's own answer for this viewer and this project
 * (`GET …/openproject/work-packages/form`), so the form offers exactly what
 * OpenProject would; the create is one POST as the viewer, and OpenProject's
 * validation sentence — not ours — is what a refused form shows.
 *
 * Opened by TeamView on the grid's `add-openproject-work-package` event, the
 * same way AddTaskModal is; `created` lets TeamView refresh the widgets.
 */
export default {
    name: 'AddOpenProjectWorkPackageModal',

    components: { NcModal, NcButton, NcLoadingIcon, NcTextField, NcTextArea, NcSelect, BriefcaseOutline },

    props: {
        teamId: { type: String, required: true },
        projectName: { type: String, default: '' },
    },

    emits: ['close', 'created'],

    data() {
        return {
            loading: true,
            loadError: '',
            saving: false,
            errors: {},
            types: [],
            assignees: [],
            assigneesUnavailable: false,
            form: {
                subject: '',
                type: null,
                assignee: null,
                dueDate: '',
                description: '',
            },
            SUBJECT_MAX_LENGTH,
            DESCRIPTION_MAX_LENGTH,
            ICON_BODY,
            ICON_TOOLBAR,
            ICON_NAV,
        }
    },

    computed: {
        /** "Nobody" first, then OpenProject's list — so the field always has a value. */
        assigneeOptions() {
            return [this.nobody, ...this.assignees]
        },

        nobody() {
            // TRANSLATORS: the assignee option meaning "no assignee" on the create work package form
            return { id: 0, name: t('teamhub', 'Nobody') }
        },

        canSubmit() {
            return !this.saving && !this.loading && !this.loadError
                && this.form.subject.trim() !== '' && !!this.form.type?.id
        },
    },

    mounted() {
        this.loadForm()
    },

    methods: {
        t, n,

        async loadForm() {
            this.loading = true
            this.loadError = ''
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/openproject/work-packages/form`),
                )
                this.types = Array.isArray(data.types) ? data.types : []
                this.assignees = Array.isArray(data.assignees) ? data.assignees : []
                this.assigneesUnavailable = !!data.assigneesUnavailable
                this.form.type = this.types.find(type => type.id === data.defaultTypeId) || this.types[0] || null
                this.form.assignee = this.nobody
                if (this.types.length === 0) {
                    this.loadError = t('teamhub', 'OpenProject offers no work package type in this project for you.')
                }
            } catch (e) {
                this.loadError = classifyError(e).message
            } finally {
                this.loading = false
            }
        },

        async submit() {
            if (!this.canSubmit) return
            this.errors = {}
            const payload = buildWorkPackagePayload(this.form)
            if (payload.subject.length > SUBJECT_MAX_LENGTH) {
                this.errors.subject = t('teamhub', 'The subject is too long')
                return
            }
            this.saving = true
            try {
                const { data } = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/openproject/work-packages`),
                    payload,
                )
                showSuccess(t('teamhub', 'Work package "{subject}" created in OpenProject', { subject: data.subject || payload.subject }))
                this.$emit('created', data)
                this.$emit('close')
            } catch (e) {
                // OpenProject's own sentence for a refused form (400,
                // `validation_failed`), the classified sentence otherwise.
                this.errors.general = classifyError(e).message
            } finally {
                this.saving = false
            }
        },
    },
}
</script>

<style scoped>
.th-op-create {
    padding: var(--th-space-xl);
    max-width: 560px;
    min-width: 340px;
}

@media (max-width: 768px) {
    .th-op-create {
        min-width: 0;
        padding: var(--th-space-lg);
    }
}

.th-op-create__title {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
    margin: 0 0 var(--th-space-xl);
    font-size: var(--th-font-heading);
    font-weight: var(--th-font-weight-bold);
    color: var(--color-main-text);
}

.th-op-create__state {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
    margin: 0;
    color: var(--color-text-maxcontrast);
}

.th-op-create__field {
    margin-bottom: var(--th-space-lg);
}

.th-op-create__row {
    display: flex;
    flex-wrap: wrap;
    gap: var(--th-space-lg);
    margin-bottom: var(--th-space-lg);
}

.th-op-create__row .th-op-create__field {
    flex: 1;
    min-width: 160px;
    margin-bottom: 0;
}

.th-op-create__label {
    display: block;
    margin-bottom: var(--th-space-xs);
    font-size: var(--th-font-meta);
    font-weight: var(--th-font-weight-medium);
    color: var(--color-text-maxcontrast);
}

.th-op-create__required {
    color: var(--color-error-text);
}

/* Scoped rather than a bare `.hidden-visually` so it cannot collide with
   the server's own copy of the class — TeamNavGroup.vue's pattern. */
.th-op-create__label .hidden-visually {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}

/* Same chrome as the NC text field the subject uses, so the date input
   does not read as a foreign control (AddTaskModal's pattern, tokenised). */
.th-op-create__input {
    width: 100%;
    box-sizing: border-box;
    padding: var(--th-space-sm) var(--th-space-md);
    border: 2px solid var(--color-border-maxcontrast);
    border-radius: var(--th-radius-control);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: var(--th-font-body);
    font-family: inherit;
}

.th-op-create__input:focus {
    border-color: var(--color-primary-element);
}

.th-op-create__input:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

.th-op-create__input:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.th-op-create__hint {
    margin: 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.th-op-create__error {
    margin: 0 0 var(--th-space-lg);
    font-size: var(--th-font-meta);
    color: var(--color-error-text);
}

.th-op-create__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--th-space-sm);
    justify-content: flex-end;
}
</style>
