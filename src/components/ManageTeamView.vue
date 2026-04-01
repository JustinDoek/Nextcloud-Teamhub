<template>
    <div class="manage-team-view">
        <div class="manage-team-header">
            <h2>{{ t('teamhub', 'Manage Team') }}</h2>
            <p class="manage-team-subtitle">{{ team.name }}</p>
        </div>

        <!-- Description -->
        <div class="manage-section">
            <h3>{{ t('teamhub', 'Team Description') }}</h3>
            <div class="manage-description-form">
                <NcTextArea
                    v-model="editedDescription"
                    :label="t('teamhub', 'Description')"
                    :placeholder="t('teamhub', 'Enter team description...')"
                    :rows="3" />
                <div class="manage-description-actions">
                    <NcButton
                        type="primary"
                        :disabled="(editedDescription === (team.description || '')) || saving"
                        @click="saveDescription">
                        <template #icon>
                            <NcLoadingIcon v-if="saving" :size="20" />
                            <ContentSave v-else :size="20" />
                        </template>
                        {{ t('teamhub', 'Save Description') }}
                    </NcButton>
                </div>
            </div>
        </div>

        <!-- Circle Settings -->
        <div class="manage-section">
            <h3>{{ t('teamhub', 'Team Settings') }}</h3>
            <div v-if="loadingConfig" class="section-loading">
                <NcLoadingIcon :size="24" />
            </div>
            <div v-else class="manage-settings">
                <div class="manage-settings-group">
                    <h4>{{ t('teamhub', 'Invitations') }}</h4>
                    <NcCheckboxRadioSwitch
                        v-for="opt in invitationOptions"
                        :key="opt.key"
                        :checked.sync="circleConfig[opt.key]"
                        type="checkbox"
                        @update:checked="saveConfig">
                        {{ opt.label }}
                    </NcCheckboxRadioSwitch>
                </div>
                <div class="manage-settings-group">
                    <h4>{{ t('teamhub', 'Membership') }}</h4>
                    <NcCheckboxRadioSwitch
                        v-for="opt in membershipOptions"
                        :key="opt.key"
                        :checked.sync="circleConfig[opt.key]"
                        type="checkbox"
                        @update:checked="saveConfig">
                        {{ opt.label }}
                    </NcCheckboxRadioSwitch>
                </div>
                <div class="manage-settings-group">
                    <h4>{{ t('teamhub', 'Privacy') }}</h4>
                    <NcCheckboxRadioSwitch
                        v-for="opt in privacyOptions"
                        :key="opt.key"
                        :checked.sync="circleConfig[opt.key]"
                        type="checkbox"
                        @update:checked="saveConfig">
                        {{ opt.label }}
                    </NcCheckboxRadioSwitch>
                </div>
                <p v-if="configSaved" class="manage-settings-saved">
                    <CheckCircle :size="14" />{{ t('teamhub', 'Settings saved') }}
                </p>
            </div>
        </div>

        <!-- Members -->
        <div class="manage-section">
            <h3>{{ t('teamhub', 'Team Members') }} ({{ members.length }})</h3>
            <div v-if="loadingMembers" class="section-loading">
                <NcLoadingIcon :size="32" />
            </div>
            <div v-else class="members-list">
                <div
                    v-for="member in members"
                    :key="member.userId || member.displayName"
                    class="member-item">
                    <NcAvatar
                        v-if="member.userId"
                        :user="member.userId"
                        :display-name="member.displayName"
                        :size="32"
                        :show-user-status="false" />
                    <div v-else class="member-avatar-fallback">
                        {{ (member.displayName || '?').charAt(0).toUpperCase() }}
                    </div>
                    <div class="member-info">
                        <span class="member-name">{{ member.displayName }}</span>
                    </div>

                    <!-- Role dropdown — disabled for owners and for the current user -->
                    <select
                        v-if="canChangeLevel(member)"
                        :value="member.level"
                        :disabled="changingLevel === member.userId"
                        class="member-level-select"
                        :aria-label="t('teamhub', 'Change role for {name}', { name: member.displayName })"
                        @change="changeLevel(member, Number($event.target.value))">
                        <option :value="1">{{ t('teamhub', 'Member') }}</option>
                        <option :value="4">{{ t('teamhub', 'Moderator') }}</option>
                        <!-- Admin option only shown to owners -->
                        <option v-if="currentUserIsOwner" :value="8">{{ t('teamhub', 'Admin') }}</option>
                    </select>
                    <!-- Static label for owners and current user -->
                    <span v-else class="member-role-static">{{ getMemberRoleLabel(member.level) }}</span>

                    <NcButton
                        v-if="canRemoveMember(member)"
                        type="error"
                        :aria-label="t('teamhub', 'Remove member')"
                        @click="confirmRemoveMember(member)">
                        <template #icon><AccountRemove :size="20" /></template>
                        {{ t('teamhub', 'Remove') }}
                    </NcButton>
                </div>
            </div>
        </div>

        <!-- Pending Join Requests -->
        <div class="manage-section">
            <h3>{{ t('teamhub', 'Pending Join Requests') }}</h3>
            <div v-if="loadingPending" class="section-loading">
                <NcLoadingIcon :size="32" />
            </div>
            <div v-else-if="pendingRequests.length === 0" class="no-pending">
                {{ t('teamhub', 'No pending requests') }}
            </div>
            <div v-else class="pending-list">
                <div v-for="req in pendingRequests" :key="req.userId" class="pending-item">
                    <NcAvatar
                        v-if="req.userId"
                        :user="req.userId"
                        :display-name="req.displayName"
                        :size="32"
                        :show-user-status="false" />
                    <div v-else class="member-avatar-fallback">
                        {{ (req.displayName || '?').charAt(0).toUpperCase() }}
                    </div>
                    <div class="pending-info">
                        <span class="pending-name">{{ req.displayName }}</span>
                        <span class="pending-date">{{ req.userId }}</span>
                    </div>
                    <div class="pending-actions">
                        <NcButton type="primary" @click="approve(req)">
                            <template #icon><Check :size="20" /></template>
                            {{ t('teamhub', 'Approve') }}
                        </NcButton>
                        <NcButton type="error" @click="reject(req)">
                            <template #icon><Close :size="20" /></template>
                            {{ t('teamhub', 'Reject') }}
                        </NcButton>
                    </div>
                </div>
            </div>
        </div>

        <!-- Danger zone: Delete Team -->
        <div class="manage-section manage-section--danger">
            <h3>{{ t('teamhub', 'Danger Zone') }}</h3>
            <div class="manage-danger-row">
                <div class="manage-danger-info">
                    <span class="manage-danger-title">{{ t('teamhub', 'Delete this team') }}</span>
                    <span class="manage-danger-desc">{{ t('teamhub', 'Permanently delete the team and all its settings. Resources (files, calendar, chat) are not deleted.') }}</span>
                </div>
                <NcButton
                    type="error"
                    :disabled="deleting"
                    @click="confirmDeleteTeam">
                    <template #icon>
                        <NcLoadingIcon v-if="deleting" :size="20" />
                        <Delete v-else :size="20" />
                    </template>
                    {{ t('teamhub', 'Delete team') }}
                </NcButton>
            </div>
        </div>
    </div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { getCurrentUser } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { NcButton, NcLoadingIcon, NcAvatar, NcTextArea, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import AccountRemove from 'vue-material-design-icons/AccountRemove.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

// Circles config bitmask constants (match MANAGED_BITS in TeamService.php)
const CFG_OPEN         = 1
const CFG_INVITE       = 2
const CFG_REQUEST      = 4
const CFG_PROTECTED    = 16
const CFG_VISIBLE      = 512
const CFG_SINGLE       = 1024

export default {
    name: 'ManageTeamView',
    components: {
        NcButton, NcLoadingIcon, NcAvatar, NcTextArea, NcCheckboxRadioSwitch,
        ContentSave, AccountRemove, Check, Close, CheckCircle, Delete,
    },
    props: {
        team: { type: Object, required: true },
    },
    emits: ['description-updated', 'team-deleted'],
    data() {
        return {
            editedDescription: this.team.description || '',
            members: [],
            pendingRequests: [],
            loadingMembers: false,
            loadingPending: false,
            loadingConfig: false,
            saving: false,
            configSaved: false,
            deleting: false,
            // userId of the member whose level is currently being saved, or null
            changingLevel: null,
            circleConfig: {
                open: false,
                invite: false,
                request: false,
                visible: false,
                protected: false,
                singleMember: false,
            },
        }
    },
    computed: {
        invitationOptions() {
            return [
                { key: 'open',    label: t('teamhub', 'Anyone can join (no invitation needed)') },
                { key: 'invite',  label: t('teamhub', 'Members can invite others') },
                { key: 'request', label: t('teamhub', 'Membership requests must be approved by a Moderator (requires "Anyone can join")') },
            ]
        },
        membershipOptions() {
            return [
                { key: 'singleMember', label: t('teamhub', 'Prevent teams from being a member of another team') },
            ]
        },
        privacyOptions() {
            return [
                { key: 'visible',   label: t('teamhub', 'Visible to everyone') },
                { key: 'protected', label: t('teamhub', 'Enforce password protection on files shared with this team') },
            ]
        },
        configValue() {
            let v = 0
            if (this.circleConfig.open)        v |= CFG_OPEN
            if (this.circleConfig.invite)       v |= CFG_INVITE
            if (this.circleConfig.request)      v |= CFG_REQUEST
            if (this.circleConfig.visible)      v |= CFG_VISIBLE
            if (this.circleConfig.protected)    v |= CFG_PROTECTED
            if (this.circleConfig.singleMember) v |= CFG_SINGLE
            return v
        },
        currentUserId() {
            return getCurrentUser()?.uid
        },
        // The caller's own level in this team
        currentUserLevel() {
            const me = this.members.find(m => m.userId === this.currentUserId)
            return me ? (me.level || 1) : 1
        },
        currentUserIsOwner() {
            return this.currentUserLevel >= 9
        },
    },
    watch: {
        'team.id'() {
            this.editedDescription = this.team.description || ''
            this.loadMembers()
            this.loadPendingRequests()
            this.loadConfig()
        },
    },
    mounted() {
        this.loadMembers()
        this.loadPendingRequests()
        this.loadConfig()
    },
    methods: {
        t,

        getMemberRoleLabel(level) {
            if (level >= 9) return t('teamhub', 'Owner')
            if (level >= 8) return t('teamhub', 'Admin')
            if (level >= 4) return t('teamhub', 'Moderator')
            return t('teamhub', 'Member')
        },

        // Show the dropdown when the current user is admin/owner, the target is
        // not the owner, and the target is not the current user themselves.
        canChangeLevel(member) {
            if (this.currentUserLevel < 8) return false
            if (member.userId === this.currentUserId) return false
            if (member.level >= 9) return false
            return true
        },

        canRemoveMember(member) {
            return member.userId !== this.currentUserId && member.level < 9
        },

        async changeLevel(member, newLevel) {
            if (newLevel === member.level) return
            this.changingLevel = member.userId
            try {
                const { data } = await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/members/${member.userId}/level`),
                    { level: newLevel }
                )
                // Backend returns the updated full member list
                this.members = Array.isArray(data) ? data : this.members
                showSuccess(t('teamhub', 'Role updated'))
            } catch (error) {
                const msg = error.response?.data?.error || ''
                showError(t('teamhub', 'Failed to update role') + (msg ? `: ${msg}` : ''))
                // Revert the dropdown visually by re-fetching
                await this.loadMembers()
            } finally {
                this.changingLevel = null
            }
        },

        async saveDescription() {
            this.saving = true
            try {
                await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/description`),
                    { description: this.editedDescription }
                )
                showSuccess(t('teamhub', 'Description updated'))
                this.$emit('description-updated', this.editedDescription)
            } catch (error) {
                const msg = error.response?.data?.error || ''
                showError(t('teamhub', 'Failed to update description') + (msg ? `: ${msg}` : ''))
            } finally {
                this.saving = false
            }
        },

        async loadConfig() {
            this.loadingConfig = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/config`)
                )
                const v = data.config || 0
                this.circleConfig.open         = !!(v & CFG_OPEN)
                this.circleConfig.invite        = !!(v & CFG_INVITE)
                this.circleConfig.request       = !!(v & CFG_REQUEST)
                this.circleConfig.visible       = !!(v & CFG_VISIBLE)
                this.circleConfig.protected     = !!(v & CFG_PROTECTED)
                this.circleConfig.singleMember  = !!(v & CFG_SINGLE)
            } catch {
                // Silently ignore — config is optional
            } finally {
                this.loadingConfig = false
            }
        },

        async saveConfig() {
            try {
                await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/config`),
                    { config: this.configValue }
                )
                this.configSaved = true
                setTimeout(() => { this.configSaved = false }, 2000)
            } catch (error) {
                showError(t('teamhub', 'Failed to save settings'))
            }
        },

        async loadMembers() {
            this.loadingMembers = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/members`)
                )
                this.members = Array.isArray(data) ? data : []
            } catch {
                showError(t('teamhub', 'Failed to load members'))
            } finally {
                this.loadingMembers = false
            }
        },

        async loadPendingRequests() {
            this.loadingPending = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/pending-requests`)
                )
                this.pendingRequests = Array.isArray(data) ? data : []
            } catch {
                this.pendingRequests = []
            } finally {
                this.loadingPending = false
            }
        },

        async confirmRemoveMember(member) {
            if (!window.confirm(
                t('teamhub', 'Are you sure you want to remove {name} from this team?', { name: member.displayName })
            )) return
            try {
                await axios.delete(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/members/${member.userId}`)
                )
                showSuccess(t('teamhub', 'Member removed'))
                await this.loadMembers()
            } catch {
                showError(t('teamhub', 'Failed to remove member'))
            }
        },

        async approve(req) {
            try {
                await axios.post(generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/approve/${req.userId}`))
                showSuccess(t('teamhub', '{name} has been approved', { name: req.displayName }))
                await Promise.all([this.loadMembers(), this.loadPendingRequests()])
            } catch {
                showError(t('teamhub', 'Failed to approve request'))
            }
        },

        async reject(req) {
            try {
                await axios.post(generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/reject/${req.userId}`))
                showSuccess(t('teamhub', 'Request rejected'))
                await this.loadPendingRequests()
            } catch {
                showError(t('teamhub', 'Failed to reject request'))
            }
        },

        async confirmDeleteTeam() {
            this.deleting = true
            try {
                await axios.delete(generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}`))
                showSuccess(t('teamhub', 'Team deleted'))
                this.$emit('team-deleted')
            } catch (error) {
                const msg = error.response?.data?.error || ''
                showError(t('teamhub', 'Failed to delete team') + (msg ? ': ' + msg : ''))
            } finally {
                this.deleting = false
            }
        },
    },
}
</script>

<style scoped>
.manage-team-view {
    padding: 40px;
    max-width: 900px;
    margin: 0 auto;
}

.manage-team-header { margin-bottom: 32px; }
.manage-team-header h2 { font-size: 22px; font-weight: 700; margin: 0 0 4px; }
.manage-team-subtitle { color: var(--color-text-maxcontrast); margin: 0; }

.manage-section {
    margin-bottom: 36px;
    padding-bottom: 36px;
    border-bottom: 1px solid var(--color-border);
}
.manage-section:last-child { border-bottom: none; }
.manage-section h3 { font-size: 15px; font-weight: 600; margin: 0 0 16px; }

.section-loading { padding: 12px 0; }

/* Description */
.manage-description-form { display: flex; flex-direction: column; gap: 12px; }
.manage-description-actions { display: flex; justify-content: flex-end; }

/* Settings */
.manage-settings { display: flex; flex-direction: column; gap: 20px; }
.manage-settings-group { display: flex; flex-direction: column; gap: 4px; }
.manage-settings-group h4 {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-maxcontrast);
    margin: 0 0 4px;
}
.manage-settings-saved {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--color-success);
    margin: 4px 0 0;
}

/* Members */
.members-list { display: flex; flex-direction: column; gap: 8px; }
.member-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 12px;
    border-radius: var(--border-radius-large);
    background: var(--color-background-dark);
}
.member-avatar-fallback {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 700;
    flex-shrink: 0;
}
.member-info { flex: 1; display: flex; flex-direction: column; }
.member-name { font-size: 14px; font-weight: 500; }

/* Role dropdown */
.member-level-select {
    padding: 5px 8px;
    border-radius: var(--border-radius);
    border: 1px solid var(--color-border-dark);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 13px;
    cursor: pointer;
    min-width: 110px;
}
.member-level-select:disabled {
    opacity: 0.6;
    cursor: wait;
}
.member-role-static {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    min-width: 110px;
    text-align: center;
}

/* Pending */
.no-pending { font-size: 14px; color: var(--color-text-maxcontrast); }
.pending-list { display: flex; flex-direction: column; gap: 10px; }
.pending-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
}
.pending-info { flex: 1; display: flex; flex-direction: column; }
.pending-name { font-size: 14px; font-weight: 500; }
.pending-date { font-size: 12px; color: var(--color-text-maxcontrast); }
.pending-actions { display: flex; gap: 8px; }

/* Danger zone */
.manage-section--danger {
    border: 1px solid var(--color-border);
    border-left: 3px solid var(--color-error);
    border-radius: var(--border-radius-large);
    padding: 20px 24px;
    margin-top: 8px;
    background: color-mix(in srgb, var(--color-error) 5%, transparent);
}
.manage-section--danger h3 { color: var(--color-error); }
.manage-danger-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}
.manage-danger-info { display: flex; flex-direction: column; gap: 4px; flex: 1; }
.manage-danger-title { font-size: 14px; font-weight: 500; }
.manage-danger-desc { font-size: 13px; color: var(--color-text-maxcontrast); }
</style>
