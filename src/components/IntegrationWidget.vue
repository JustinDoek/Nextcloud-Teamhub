<template>
    <div class="teamhub-int-widget">

        <!-- Loading state -->
        <div v-if="loading" class="teamhub-int-widget__state">
            <NcLoadingIcon :size="28" />
        </div>

        <!-- Error state -->
        <div v-else-if="loadError" class="teamhub-int-widget__state teamhub-int-widget__state--error">
            <AlertCircleIcon :size="36" class="teamhub-int-widget__state-icon" />
            <span>{{ t('teamhub', 'Widget failed to load') }}</span>
        </div>

        <!-- Empty state — centered icon + message.
             Prefer the NC app icon (same URL strategy as the widget card header in TeamView)
             so the empty state matches the header branding. Falls back to MDI icon if the
             app has no app.svg / app.png, and finally to PuzzleIcon. -->
        <div v-else-if="items.length === 0" class="teamhub-int-widget__state">
            <img
                v-if="integration.app_id && !appIconFailed"
                :src="appIconUrl"
                :alt="integration.app_id"
                class="teamhub-int-widget__state-app-icon"
                @error="onAppIconError" />
            <component
                v-else
                :is="resolvedIcon"
                :size="36"
                class="teamhub-int-widget__state-icon" />
            <span>{{ t('teamhub', 'No items') }}</span>
        </div>

        <!-- Item list -->
        <ul v-else class="teamhub-int-widget__list">
            <li
                v-for="(item, index) in items"
                :key="index"
                class="teamhub-int-widget__item">
                <component
                    :is="item.url ? 'a' : 'span'"
                    :href="item.url || undefined"
                    class="teamhub-int-widget__item-link">
                    <component
                        :is="resolveItemIcon(item.icon)"
                        v-if="item.icon"
                        :size="16"
                        class="teamhub-int-widget__item-icon" />
                    <span class="teamhub-int-widget__item-label">{{ item.label }}</span>
                    <span v-if="item.value" class="teamhub-int-widget__item-value">{{ item.value }}</span>
                </component>
            </li>
        </ul>

        <!-- Action modal — opened by openAction(), or by parent via this.$refs.widget.openAction() -->
        <NcModal
            v-if="showActionModal"
            :name="actionModalTitle"
            @close="closeActionModal">
            <div class="teamhub-int-widget__modal">
                <div v-if="actionLoading" class="teamhub-int-widget__state">
                    <NcLoadingIcon :size="28" />
                </div>
                <p v-else-if="actionError" class="teamhub-int-widget__error-msg">
                    {{ actionError }}
                </p>
                <template v-else>
                    <div
                        v-for="field in actionFields"
                        :key="field.name"
                        class="teamhub-int-widget__field">
                        <label :for="'action-field-' + field.name">{{ field.label }}</label>
                        <NcTextField
                            v-if="field.type === 'text' || field.type === 'email'"
                            :id="'action-field-' + field.name"
                            v-model="actionFieldValues[field.name]"
                            :label="field.label"
                            :type="field.type" />
                        <NcTextArea
                            v-else-if="field.type === 'textarea'"
                            :id="'action-field-' + field.name"
                            v-model="actionFieldValues[field.name]"
                            :label="field.label" />
                        <NcCheckboxRadioSwitch
                            v-else-if="field.type === 'checkbox'"
                            :id="'action-field-' + field.name"
                            v-model="actionFieldValues[field.name]">
                            {{ field.label }}
                        </NcCheckboxRadioSwitch>
                    </div>
                    <div class="teamhub-int-widget__modal-actions">
                        <NcButton @click="closeActionModal">
                            {{ t('teamhub', 'Cancel') }}
                        </NcButton>
                        <NcButton
                            type="primary"
                            :disabled="actionSubmitting"
                            @click="submitAction">
                            <template v-if="actionSubmitting" #icon>
                                <NcLoadingIcon :size="20" />
                            </template>
                            {{ actionSubmitLabel || t('teamhub', 'Submit') }}
                        </NcButton>
                    </div>
                </template>
            </div>
        </NcModal>
    </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import {
    NcLoadingIcon,
    NcModal,
    NcButton,
    NcTextField,
    NcTextArea,
    NcCheckboxRadioSwitch,
} from '@nextcloud/vue'

import PuzzleIcon        from 'vue-material-design-icons/Puzzle.vue'
import CalendarMonthIcon from 'vue-material-design-icons/CalendarMonth.vue'
import ViewDashboardIcon from 'vue-material-design-icons/ViewDashboard.vue'
import AccountGroupIcon  from 'vue-material-design-icons/AccountGroup.vue'
import ChartBarIcon      from 'vue-material-design-icons/ChartBar.vue'
import BellIcon          from 'vue-material-design-icons/Bell.vue'
import FileDocumentIcon  from 'vue-material-design-icons/FileDocument.vue'
import CheckCircleIcon   from 'vue-material-design-icons/CheckCircle.vue'
import AlertCircleIcon   from 'vue-material-design-icons/AlertCircle.vue'

const ICON_MAP = {
    Puzzle:        PuzzleIcon,
    CalendarMonth: CalendarMonthIcon,
    ViewDashboard: ViewDashboardIcon,
    AccountGroup:  AccountGroupIcon,
    ChartBar:      ChartBarIcon,
    Bell:          BellIcon,
    FileDocument:  FileDocumentIcon,
    CheckCircle:   CheckCircleIcon,
    AlertCircle:   AlertCircleIcon,
}

export default {
    name: 'IntegrationWidget',

    components: {
        NcLoadingIcon, NcModal, NcButton, NcTextField, NcTextArea, NcCheckboxRadioSwitch,
        PuzzleIcon, CalendarMonthIcon, ViewDashboardIcon, AccountGroupIcon,
        ChartBarIcon, BellIcon, FileDocumentIcon, CheckCircleIcon, AlertCircleIcon,
    },

    props: {
        integration: { type: Object, required: true },
        teamId:      { type: String, required: true },
    },

    emits: ['actions-loaded'],

    data() {
        return {
            loading:           true,
            loadError:         false,
            items:             [],
            // Dynamic actions returned by the widget data endpoint.
            // Shape: [ { label: string, icon?: string, url: string } ]
            // Empty array when the endpoint returns none (falls back to registration action_url).
            dynamicActions:    [],
            appIconFailed:     false,
            showActionModal:   false,
            actionLoading:     false,
            actionError:       null,
            actionFields:      [],
            actionFieldValues: {},
            actionSubmitLabel: null,
            actionSubmitting:  false,
            // The actionId and label currently open in the modal.
            activeActionId:    null,
            activeActionLabel: null,
        }
    },

    computed: {
        actionModalTitle() {
            return this.activeActionLabel || this.t('teamhub', 'Action')
        },

        /**
         * NC app icon URL — same strategy as TeamView header.
         * Points to /apps/{app_id}/img/app.svg; onAppIconError() falls back to .png,
         * then hides the img so the MDI resolvedIcon shows instead.
         */
        appIconUrl() {
            return generateUrl(`/apps/${this.integration.app_id}/img/app.svg`)
        },

        resolvedIcon() {
            const name = this.integration.icon
            return (name && ICON_MAP[name]) ? ICON_MAP[name] : PuzzleIcon
        },

        /**
         * Effective actions for the 3-dot menu in the card header.
         * Actions come from the widget's getWidgetData() response.
         * Each entry: { label, icon?, actionId?, url? }
         * actionId → native TeamHub modal; url-only → new tab.
         */
        effectiveActions() {
            return this.dynamicActions
        },

        hasAction() {
            return this.effectiveActions.length > 0
        },
    },

    mounted() {
        this.loadData()
    },

    methods: {
        t,

        async loadData() {
            this.loading   = true
            this.loadError = false
            try {
                const url = generateUrl(
                    `/apps/teamhub/api/v1/teams/${this.teamId}/integrations/widget-data/${this.integration.registry_id}`
                )
                const { data } = await axios.get(url)
                this.items          = Array.isArray(data.items) ? data.items : []
                this.dynamicActions = Array.isArray(data.actions) ? data.actions : []
            } catch (e) {
                this.loadError      = true
                this.items          = []
                this.dynamicActions = []
            } finally {
                this.loading = false
                // Emit effective actions so TeamView can update the card header menu reactively.
                this.$emit('actions-loaded', {
                    registryId: this.integration.registry_id,
                    actions:    this.effectiveActions,
                })
            }
        },

        resolveItemIcon(iconName) {
            return (iconName && ICON_MAP[iconName]) ? ICON_MAP[iconName] : null
        },

        resolveActionIcon(iconName) {
            return (iconName && ICON_MAP[iconName]) ? ICON_MAP[iconName] : null
        },

        /**
         * App icon fallback — mirrors TeamView.onAppIconError().
         * svg → png → hide img (MDI resolvedIcon will take over via v-else on the component).
         */
        onAppIconError(event) {
            const img = event.target
            if (img.src.endsWith('.svg')) {
                img.src = img.src.replace('.svg', '.png')
            } else {
                img.style.display = 'none'
                this.appIconFailed = true
            }
        },

        /**
         * Open the native action modal.
         *
         * Called by TeamView via this.$refs['intWidget-{id}'][0].openAction(action).
         *
         * If the action has an actionId, fetch the form from TeamHub's action-form
         * endpoint (which calls getActionForm() in-process on the widget class) and
         * render it natively. If the action has only a url, open it in a new tab.
         */
        async openAction(action) {

            // url-only fallback: no actionId → open in new tab
            if (!action || (!action.actionId && action.url)) {
                window.open(action.url, '_blank', 'noopener noreferrer')
                return
            }

            if (!action || !action.actionId) {
                return
            }

            // Native modal — fetch form definition
            this.activeActionId    = action.actionId
            this.activeActionLabel = action.label || this.t('teamhub', 'Action')
            this.showActionModal   = true
            this.actionLoading     = true
            this.actionError       = null
            this.actionFields      = []
            this.actionFieldValues = {}
            this.actionSubmitLabel = null

            try {
                const url = generateUrl(
                    `/apps/teamhub/api/v1/teams/${this.teamId}/integrations/action-form/${this.integration.registry_id}`
                )
                const { data } = await axios.get(url, { params: { actionId: action.actionId } })

                if (!data.fields || data.fields.length === 0) {
                    // No fields returned — fall back to new tab
                    this.showActionModal = false
                    if (action.url) {
                        window.open(action.url, '_blank', 'noopener noreferrer')
                    }
                    return
                }

                this.actionFields      = data.fields
                this.actionSubmitLabel = data.submit_label || null
                if (data.title) { this.activeActionLabel = data.title }

                const vals = {}
                for (const field of this.actionFields) {
                    vals[field.name] = field.value !== undefined ? field.value : (field.type === 'checkbox' ? false : '')
                }
                this.actionFieldValues = vals
            } catch (e) {
                this.actionError = this.t('teamhub', 'Failed to load action form')
            } finally {
                this.actionLoading = false
            }
        },

        closeActionModal() {
            this.showActionModal   = false
            this.activeActionId    = null
            this.activeActionLabel = null
        },

        async submitAction() {
            this.actionSubmitting = true
            try {
                const url = generateUrl(
                    `/apps/teamhub/api/v1/teams/${this.teamId}/integrations/action-submit/${this.integration.registry_id}`
                )
                const { data } = await axios.post(url, {
                    actionId: this.activeActionId,
                    fields:   this.actionFieldValues,
                })
                this.showActionModal = false

                if (data.message) {
                    const { showSuccess, showError } = await import('@nextcloud/dialogs')
                    data.success ? showSuccess(data.message) : showError(data.message)
                }
                if (data.refresh) {
                    this.loadData()
                }
            } catch (e) {
                const msg = e?.response?.data?.error || ''
                this.actionError = this.t('teamhub', 'Action failed') + (msg ? ': ' + msg : '')
            } finally {
                this.actionSubmitting = false
            }
        },
    },
}
</script>

<style scoped>
.teamhub-int-widget {
    display: flex;
    flex-direction: column;
    height: 100%;
}

/* Centred state: loading / empty / error */
.teamhub-int-widget__state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    flex: 1;
    padding: 20px 16px;
    color: var(--color-text-maxcontrast);
    font-size: 15px;
    text-align: center;
}

.teamhub-int-widget__state-icon {
    opacity: 0.35;
    color: var(--color-primary-element);
}

/* App icon image in empty state — same size as the MDI icon (36px) */
.teamhub-int-widget__state-app-icon {
    width: 36px;
    height: 36px;
    object-fit: contain;
    opacity: 0.7;
}

.teamhub-int-widget__state--error { color: var(--color-error); }
.teamhub-int-widget__state--error .teamhub-int-widget__state-icon {
    color: var(--color-error);
    opacity: 0.6;
}

/* Item list */
.teamhub-int-widget__list {
    list-style: none;
    margin: 0;
    padding: 0;
    flex: 1;
    overflow-y: auto;
}

.teamhub-int-widget__item { border-bottom: 1px solid var(--color-border); }
.teamhub-int-widget__item:last-child { border-bottom: none; }

.teamhub-int-widget__item-link {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    text-decoration: none;
    color: var(--color-main-text);
    font-size: 15px;
    transition: background 0.1s;
}

a.teamhub-int-widget__item-link:hover {
    background: var(--color-background-hover);
}

.teamhub-int-widget__item-icon {
    flex-shrink: 0;
    color: var(--color-text-maxcontrast);
}

.teamhub-int-widget__item-label {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.teamhub-int-widget__item-value {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    flex-shrink: 0;
}

/* Modal */
.teamhub-int-widget__modal {
    padding: 16px 20px 20px;
    min-width: 320px;
}

.teamhub-int-widget__error-msg {
    font-size: 14px;
    color: var(--color-error);
    margin: 0 0 12px;
}

.teamhub-int-widget__field { margin-bottom: 12px; }

.teamhub-int-widget__field label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 4px;
    color: var(--color-text-light);
}

.teamhub-int-widget__modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 16px;
}
</style>
