<template>
    <NcAppNavigationItem
        :name="integration.title"
        :allow-collapse="true"
        :open="true"
        class="teamhub-widget-item teamhub-integration-widget">

        <template #icon>
            <component :is="resolvedIcon" :size="20" />
        </template>

        <!-- 3-dot action menu — only shown when action_url is configured -->
        <template v-if="integration.action_url" #actions>
            <NcActionButton @click="openActionModal">
                <template #icon>
                    <PlusIcon :size="20" />
                </template>
                {{ integration.action_label || t('teamhub', 'Action') }}
            </NcActionButton>
        </template>

        <template #default>
            <div class="teamhub-integration-widget__body">

                <!-- Loading state -->
                <div v-if="loading" class="teamhub-integration-widget__loading">
                    <NcLoadingIcon :size="24" />
                </div>

                <!-- Error state -->
                <p v-else-if="loadError" class="teamhub-integration-widget__error">
                    {{ t('teamhub', 'Widget failed to load') }}
                </p>

                <!-- Empty state -->
                <p v-else-if="items.length === 0" class="teamhub-integration-widget__empty">
                    {{ t('teamhub', 'No items') }}
                </p>

                <!-- Item list — mirrors CalendarWidget / DeckWidget pattern -->
                <ul v-else class="teamhub-integration-widget__list">
                    <li
                        v-for="(item, index) in items"
                        :key="index"
                        class="teamhub-integration-widget__item">
                        <component
                            :is="item.url ? 'a' : 'span'"
                            :href="item.url || undefined"
                            :target="item.url ? '_blank' : undefined"
                            :rel="item.url ? 'noopener noreferrer' : undefined"
                            class="teamhub-integration-widget__item-link">
                            <component
                                :is="resolveItemIcon(item.icon)"
                                v-if="item.icon"
                                :size="16"
                                class="teamhub-integration-widget__item-icon" />
                            <span class="teamhub-integration-widget__item-label">{{ item.label }}</span>
                            <span v-if="item.value" class="teamhub-integration-widget__item-value">{{ item.value }}</span>
                        </component>
                    </li>
                </ul>
            </div>
        </template>

        <!-- Action modal -->
        <NcModal
            v-if="showActionModal"
            :name="actionModalTitle"
            @close="closeActionModal">
            <div class="teamhub-integration-widget__modal">
                <div v-if="actionLoading" class="teamhub-integration-widget__loading">
                    <NcLoadingIcon :size="24" />
                </div>
                <p v-else-if="actionError" class="teamhub-integration-widget__error">
                    {{ actionError }}
                </p>
                <template v-else>
                    <!-- Render fields defined by the external app -->
                    <div
                        v-for="field in actionFields"
                        :key="field.name"
                        class="teamhub-integration-widget__field">
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
                    <div class="teamhub-integration-widget__modal-actions">
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
    </NcAppNavigationItem>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import {
    NcAppNavigationItem,
    NcLoadingIcon,
    NcModal,
    NcButton,
    NcTextField,
    NcTextArea,
    NcCheckboxRadioSwitch,
    NcActionButton,
} from '@nextcloud/vue'

// Icon imports — fallback icon when registered icon is not in this map.
// Extend as needed; keeping it explicit controls bundle size.
import PuzzleIcon           from 'vue-material-design-icons/Puzzle.vue'
import PlusIcon             from 'vue-material-design-icons/Plus.vue'
import CalendarMonthIcon    from 'vue-material-design-icons/CalendarMonth.vue'
import ViewDashboardIcon    from 'vue-material-design-icons/ViewDashboard.vue'
import AccountGroupIcon     from 'vue-material-design-icons/AccountGroup.vue'
import ChartBarIcon         from 'vue-material-design-icons/ChartBar.vue'
import BellIcon             from 'vue-material-design-icons/Bell.vue'
import FileDocumentIcon     from 'vue-material-design-icons/FileDocument.vue'
import CheckCircleIcon      from 'vue-material-design-icons/CheckCircle.vue'
import AlertCircleIcon      from 'vue-material-design-icons/AlertCircle.vue'

const ICON_MAP = {
    Puzzle:          'PuzzleIcon',
    CalendarMonth:   'CalendarMonthIcon',
    ViewDashboard:   'ViewDashboardIcon',
    AccountGroup:    'AccountGroupIcon',
    ChartBar:        'ChartBarIcon',
    Bell:            'BellIcon',
    FileDocument:    'FileDocumentIcon',
    CheckCircle:     'CheckCircleIcon',
    AlertCircle:     'AlertCircleIcon',
}

export default {
    name: 'IntegrationWidget',

    components: {
        NcAppNavigationItem,
        NcLoadingIcon,
        NcModal,
        NcButton,
        NcTextField,
        NcTextArea,
        NcCheckboxRadioSwitch,
        NcActionButton,
        PuzzleIcon,
        PlusIcon,
        CalendarMonthIcon,
        ViewDashboardIcon,
        AccountGroupIcon,
        ChartBarIcon,
        BellIcon,
        FileDocumentIcon,
        CheckCircleIcon,
        AlertCircleIcon,
    },

    props: {
        /**
         * A widget row from getEnabledIntegrations().widgets.
         * Shape: { registry_id, app_id, integration_type, title, icon,
         *          data_url, action_url, action_label, ... }
         */
        integration: {
            type: Object,
            required: true,
        },
        teamId: {
            type: String,
            required: true,
        },
    },

    data() {
        return {
            loading:     true,
            loadError:   false,
            items:       [],

            // Action modal state
            showActionModal:    false,
            actionLoading:      false,
            actionError:        null,
            actionFields:       [],
            actionFieldValues:  {},
            actionSubmitLabel:  null,
            actionSubmitting:   false,
        }
    },

    computed: {
        actionModalTitle() {
            return this.integration.action_label || this.t('teamhub', 'Action')
        },

        resolvedIcon() {
            const name = this.integration.icon
            return (name && ICON_MAP[name]) ? ICON_MAP[name] : 'PuzzleIcon'
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
                this.items = Array.isArray(data.items) ? data.items : []
                if (data.error) {
                }
            } catch (e) {
                this.loadError = true
                this.items     = []
            } finally {
                this.loading = false
            }
        },

        resolveItemIcon(iconName) {
            return (iconName && ICON_MAP[iconName]) ? ICON_MAP[iconName] : null
        },

        async openActionModal() {
            this.showActionModal   = true
            this.actionLoading     = true
            this.actionError       = null
            this.actionFields      = []
            this.actionFieldValues = {}

            try {
                const url = generateUrl(
                    `/apps/teamhub/api/v1/teams/${this.teamId}/integrations/action/${this.integration.registry_id}`
                )
                const { data } = await axios.get(url)
                this.actionFields     = Array.isArray(data.fields) ? data.fields : []
                this.actionSubmitLabel = data.submit_label || null

                // Pre-populate field values from default values in definition.
                const vals = {}
                for (const field of this.actionFields) {
                    vals[field.name] = field.value !== undefined ? field.value : ''
                }
                this.actionFieldValues = vals
            } catch (e) {
                this.actionError = this.t('teamhub', 'Failed to load action')
            } finally {
                this.actionLoading = false
            }
        },

        closeActionModal() {
            this.showActionModal = false
        },

        async submitAction() {
            this.actionSubmitting = true
            try {
                await axios.post(this.integration.action_url, {
                    teamId: this.teamId,
                    fields: this.actionFieldValues,
                })
                this.showActionModal = false
                // Refresh widget data after action.
                this.loadData()
            } catch (e) {
                this.actionError = this.t('teamhub', 'Action failed. Please try again.')
            } finally {
                this.actionSubmitting = false
            }
        },
    },
}
</script>

<style scoped>
.teamhub-integration-widget__body {
    width: 100%;
}

.teamhub-integration-widget__loading {
    display: flex;
    justify-content: center;
    padding: 12px 0;
}

.teamhub-integration-widget__error,
.teamhub-integration-widget__empty {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    padding: 8px 12px;
    margin: 0;
}

.teamhub-integration-widget__error {
    color: var(--color-error);
}

.teamhub-integration-widget__list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.teamhub-integration-widget__item {
    border-bottom: 1px solid var(--color-border);
}

.teamhub-integration-widget__item:last-child {
    border-bottom: none;
}

.teamhub-integration-widget__item-link {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    text-decoration: none;
    color: var(--color-main-text);
    font-size: 13px;
    transition: background 0.1s;
}

a.teamhub-integration-widget__item-link:hover {
    background: var(--color-background-hover);
    border-radius: var(--border-radius);
}

.teamhub-integration-widget__item-icon {
    flex-shrink: 0;
    color: var(--color-text-maxcontrast);
}

.teamhub-integration-widget__item-label {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.teamhub-integration-widget__item-value {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    flex-shrink: 0;
}

/* Modal */
.teamhub-integration-widget__modal {
    padding: 16px 20px 20px;
    min-width: 320px;
}

.teamhub-integration-widget__field {
    margin-bottom: 12px;
}

.teamhub-integration-widget__field label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 4px;
    color: var(--color-text-light);
}

.teamhub-integration-widget__modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 16px;
}
</style>
