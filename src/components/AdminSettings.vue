<template>
    <div>
        <NcSettingsSection
            :name="t('teamhub', 'Team creation wizard')"
            :description="t('teamhub', 'This text appears at the top of the Create new team dialog. Leave empty to show no description.')">
            <NcTextArea
                v-model="form.wizardDescription"
                :label="t('teamhub', 'Wizard introduction text')"
                :placeholder="t('teamhub', 'e.g. Fill in the details below to create a new team.')"
                :rows="3" />
        </NcSettingsSection>

        <NcSettingsSection
            :name="t('teamhub', 'Team creation permissions')"
            :description="t('teamhub', 'Restrict who can create new teams to members of a specific Nextcloud group. Leave empty to allow all users.')">
            <NcTextField
                v-model="form.createTeamGroup"
                :label="t('teamhub', 'Group allowed to create teams')"
                :placeholder="t('teamhub', 'e.g. team-managers (leave empty for everyone)')" />
        </NcSettingsSection>

        <NcSettingsSection
            :name="t('teamhub', 'Allowed invite types')"
            :description="t('teamhub', 'Choose which types of accounts team admins can invite.')">
            <div class="admin-checks">
                <NcCheckboxRadioSwitch
                    :checked="true"
                    :disabled="true"
                    type="checkbox">
                    {{ t('teamhub', 'Local users') }}
                    <template #description>{{ t('teamhub', 'Always enabled — local Nextcloud accounts') }}</template>
                </NcCheckboxRadioSwitch>
                <NcCheckboxRadioSwitch
                    v-model="inviteGroup"
                    type="checkbox">
                    {{ t('teamhub', 'Groups') }}
                    <template #description>{{ t('teamhub', 'Add all members of a Nextcloud group at once') }}</template>
                </NcCheckboxRadioSwitch>
                <NcCheckboxRadioSwitch
                    v-model="inviteEmail"
                    type="checkbox">
                    {{ t('teamhub', 'Email addresses') }}
                    <template #description>{{ t('teamhub', 'Invite external people by email (requires Circles federation)') }}</template>
                </NcCheckboxRadioSwitch>
                <NcCheckboxRadioSwitch
                    v-model="inviteFederated"
                    type="checkbox">
                    {{ t('teamhub', 'Federated users') }}
                    <template #description>{{ t('teamhub', 'Invite users from other Nextcloud instances (requires Circles federation)') }}</template>
                </NcCheckboxRadioSwitch>
            </div>
        </NcSettingsSection>

        <NcSettingsSection
            :name="t('teamhub', 'Pin messages')"
            :description="t('teamhub', 'Minimum member role required to pin or unpin a message in a team. One message can be pinned per team at a time.')">
            <div class="admin-select-row">
                <label for="teamhub-pin-level" class="admin-select-label">
                    {{ t('teamhub', 'Minimum role to pin') }}
                </label>
                <select
                    id="teamhub-pin-level"
                    v-model="form.pinMinLevel"
                    class="admin-select">
                    <option value="member">{{ t('teamhub', 'Member') }}</option>
                    <option value="moderator">{{ t('teamhub', 'Moderator') }}</option>
                    <option value="admin">{{ t('teamhub', 'Admin / Owner') }}</option>
                </select>
            </div>
        </NcSettingsSection>

        <div class="admin-save-row">
            <NcButton
                type="primary"
                :disabled="saving"
                @click="save">
                <template #icon>
                    <NcLoadingIcon v-if="saving" :size="18" />
                    <ContentSave v-else :size="18" />
                </template>
                {{ saving ? t('teamhub', 'Saving…') : t('teamhub', 'Save settings') }}
            </NcButton>
            <span v-if="saved" class="admin-save-ok">✓ {{ t('teamhub', 'Settings saved') }}</span>
            <span v-if="saveError" class="admin-save-err">{{ saveError }}</span>
        </div>
    </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcSettingsSection, NcButton, NcLoadingIcon, NcTextField, NcTextArea, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'

export default {
    name: 'AdminSettings',
    components: {
        NcSettingsSection,
        NcButton,
        NcLoadingIcon,
        NcTextField,
        NcTextArea,
        NcCheckboxRadioSwitch,
        ContentSave,
    },
    data() {
        return {
            loading: true,
            saving: false,
            saved: false,
            saveError: null,
            form: {
                wizardDescription: '',
                createTeamGroup: '',
                pinMinLevel: 'moderator',
                inviteTypes: 'user,group',
            },
            // Separate booleans for the checkboxes
            inviteGroup: true,
            inviteEmail: false,
            inviteFederated: false,
        }
    },
    mounted() {
        this.load()
    },
    methods: {
        t: (app, str) => window.t ? window.t(app, str) : str,

        async load() {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/admin/settings'))
                this.form.wizardDescription = data.wizardDescription || ''
                this.form.createTeamGroup   = data.createTeamGroup   || ''
                this.form.pinMinLevel       = data.pinMinLevel        || 'moderator'
                const types = (data.inviteTypes || 'user,group').split(',').map(s => s.trim())
                this.inviteGroup      = types.includes('group')
                this.inviteEmail      = types.includes('email')
                this.inviteFederated  = types.includes('federated')
            } catch (e) {
                this.saveError = this.t('teamhub', 'Failed to load settings')
            } finally {
                this.loading = false
            }
        },

        async save() {
            this.saving   = true
            this.saved    = false
            this.saveError = null

            const types = ['user']
            if (this.inviteGroup)     types.push('group')
            if (this.inviteEmail)     types.push('email')
            if (this.inviteFederated) types.push('federated')

            const params = new URLSearchParams()
            params.set('wizardDescription', this.form.wizardDescription)
            params.set('createTeamGroup',   this.form.createTeamGroup)
            params.set('pinMinLevel',        this.form.pinMinLevel)
            params.set('inviteTypes',        types.join(','))

            try {
                await axios.post(
                    generateUrl('/apps/teamhub/api/v1/admin/settings'),
                    params.toString(),
                    { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } }
                )
                this.saved = true
                setTimeout(() => { this.saved = false }, 3000)
            } catch (e) {
                this.saveError = this.t('teamhub', 'Failed to save settings')
            } finally {
                this.saving = false
            }
        },
    },
}
</script>

<style scoped>
.admin-checks {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 4px;
}

.admin-select-row {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-top: 4px;
    flex-wrap: wrap;
}

.admin-select-label {
    font-size: 14px;
    font-weight: 500;
    min-width: 180px;
}

.admin-select {
    padding: 8px 12px;
    border-radius: var(--border-radius-large);
    border: 2px solid var(--color-border-maxcontrast);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 14px;
    min-width: 180px;
    cursor: pointer;
}

.admin-select:focus {
    outline: none;
    border-color: var(--color-primary-element);
}

.admin-save-row {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 0 16px 24px;
}

.admin-save-ok {
    font-size: 14px;
    color: var(--color-success);
    font-weight: 500;
}

.admin-save-err {
    font-size: 14px;
    color: var(--color-error);
}
</style>
