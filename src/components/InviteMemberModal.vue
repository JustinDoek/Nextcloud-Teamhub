<template>
    <NcModal
        :name="t('teamhub', 'Invite to team')"
        size="small"
        @close="$emit('close')">
        <div class="invite-modal">
            <h2 class="invite-modal__title">
                {{ t('teamhub', 'Invite members') }}
            </h2>
            <p class="invite-modal__subtitle">
                {{ t('teamhub', 'Search users and groups to add to this team') }}
            </p>

            <!-- Search input -->
            <NcTextField
                v-model="query"
                :label="t('teamhub', 'Search users or groups')"
                :placeholder="searchPlaceholder"
                @input="onSearch" />

            <!-- Search results: loading / results / empty — mutually exclusive -->
            <div class="invite-modal__search-results">
                <div v-if="searching" class="invite-modal__searching">
                    <NcLoadingIcon :size="20" />
                </div>
                <ul v-else-if="results.length" class="invite-modal__results">
                    <li
                        v-for="item in results"
                        :key="item.type + ':' + item.id"
                        class="invite-modal__result"
                        @click="addItem(item)">
                        <div class="invite-modal__result-avatar" :class="'invite-modal__result-avatar--' + item.type">
                            <AccountGroup v-if="item.type === 'group'" :size="20" />
                            <AccountMultiple v-else-if="item.type === 'circle'" :size="20" />
                            <EmailOutline v-else-if="item.type === 'email'" :size="20" />
                            <EarthArrowRight v-else-if="item.type === 'federated'" :size="20" />
                            <NcAvatar v-else :user="item.id" :display-name="item.label" :size="32" :disable-menu="true" />
                        </div>
                        <div class="invite-modal__result-info">
                            <span class="invite-modal__result-name">{{ item.label }}</span>
                            <span class="invite-modal__result-id">
                                <span v-if="item.type === 'group'">{{ t('teamhub', 'Group') }}</span>
                                <span v-else-if="item.type === 'circle'">{{ t('teamhub', 'Team') }}</span>
                                <span v-else-if="item.type === 'email'">{{ t('teamhub', 'Email invite') }}</span>
                                <span v-else-if="item.type === 'federated'">{{ t('teamhub', 'Federated user') }}</span>
                                <span v-else>{{ item.id }}</span>
                            </span>
                        </div>
                        <Plus :size="18" class="invite-modal__result-add" />
                    </li>
                </ul>
                <p v-else-if="query.length >= 2" class="invite-modal__empty">
                    {{ t('teamhub', 'No users or groups found') }}
                </p>
            </div>

            <!-- Staged -->
            <div v-if="staged.length" class="invite-modal__staged">
                <span class="invite-modal__staged-label">{{ t('teamhub', 'To be invited:') }}</span>
                <div class="invite-modal__chips">
                    <span v-for="u in staged" :key="u.type + ':' + u.id" class="invite-modal__chip">
                        <AccountGroup v-if="u.type === 'group'" :size="16" />
                        <AccountMultiple v-else-if="u.type === 'circle'" :size="16" />
                        <EmailOutline v-else-if="u.type === 'email'" :size="16" />
                        <EarthArrowRight v-else-if="u.type === 'federated'" :size="16" />
                        <NcAvatar v-else :user="u.id" :display-name="u.label" :size="20" :disable-menu="true" />
                        {{ u.label }}
                        <button class="invite-modal__chip-remove" @click="removeStaged(u)">×</button>
                    </span>
                </div>
            </div>

            <!-- Actions -->
            <div class="invite-modal__actions">
                <NcButton
                    type="primary"
                    :disabled="!staged.length || sending"
                    @click="sendInvites">
                    <template #icon>
                        <NcLoadingIcon v-if="sending" :size="20" />
                        <AccountPlus v-else :size="20" />
                    </template>
                    {{ t('teamhub', 'Invite {n}', { n: staged.length }) }}
                </NcButton>
                <NcButton type="tertiary" @click="$emit('close')">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
            </div>
        </div>
    </NcModal>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { NcModal, NcButton, NcTextField, NcAvatar, NcLoadingIcon } from '@nextcloud/vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import EarthArrowRight from 'vue-material-design-icons/EarthArrowRight.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
    name: 'InviteMemberModal',
    components: { NcModal, NcButton, NcTextField, NcAvatar, NcLoadingIcon, AccountPlus, AccountGroup, AccountMultiple, EmailOutline, EarthArrowRight, Plus },
    props: {
        teamId: { type: String, required: true },
    },
    emits: ['close', 'invited'],
    data() {
        return {
            query: '',
            results: [],
            staged: [],
            searching: false,
            sending: false,
            searchTimer: null,
            allowedTypes: ['user'],
        }
    },
    computed: {
        searchPlaceholder() {
            const labels = []
            if (this.allowedTypes.includes('user'))      labels.push(t('teamhub', 'name or username'))
            if (this.allowedTypes.includes('group'))     labels.push(t('teamhub', 'group'))
            if (this.allowedTypes.includes('email'))     labels.push(t('teamhub', 'email address'))
            if (this.allowedTypes.includes('federated')) labels.push(t('teamhub', 'user@remote.example'))
            labels.push(t('teamhub', 'team name'))
            return labels.join(', ') + '…'
        },
    },
    async created() {
        try {
            const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/invite-types'))
            if (data && Array.isArray(data.types)) {
                this.allowedTypes = data.types
            }
        } catch { /* keep defaults */ }
    },
    methods: {
        t,
        onSearch() {
            clearTimeout(this.searchTimer)
            this.results = []
            if (this.query.length < 2) return
            this.searching = true
            this.searchTimer = setTimeout(async () => {
                try {
                    const { data } = await axios.get(
                        generateUrl('/apps/teamhub/api/v1/users/search'),
                        { params: { q: this.query } }
                    )
                    const stagedKeys = new Set(this.staged.map(u => u.type + ':' + u.id))
                    this.results = (data || [])
                        .filter(u => !stagedKeys.has(u.type + ':' + u.id))
                        .map(u => ({ id: u.id, label: u.displayName || u.id, type: u.type || 'user' }))
                } catch {
                    this.results = []
                } finally {
                    this.searching = false
                }
            }, 300)
        },
        addItem(item) {
            const key = item.type + ':' + item.id
            if (!this.staged.find(u => u.type + ':' + u.id === key)) {
                this.staged.push(item)
            }
            this.query = ''
            this.results = []
        },
        removeStaged(item) {
            this.staged = this.staged.filter(u => !(u.id === item.id && u.type === item.type))
        },
        async sendInvites() {
            if (!this.staged.length) return
            this.sending = true
            try {
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/invite-members`),
                    { members: this.staged.map(u => ({ id: u.id, type: u.type })) }
                )
                // TRANSLATORS: success message after inviting, e.g. "1 member invited" or "3 members invited"
                showSuccess(n('teamhub', '{n} member invited', '{n} members invited', this.staged.length, { n: this.staged.length }))
                this.$emit('invited')
                this.$emit('close')
            } catch (e) {
                const msg = e?.response?.data?.error || e?.message || ''
                showError(msg ? t('teamhub', 'Failed to invite members: {error}', { error: msg }) : t('teamhub', 'Failed to invite members'))
            } finally {
                this.sending = false
            }
        },
    },
}
</script>

<style scoped>
.invite-modal {
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    min-width: 340px;
}

/* Allow the modal to shrink below 340px on narrow screens. */
@media (max-width: 768px) {
    .invite-modal {
        min-width: 0;
        padding: 16px;
    }
}

.invite-modal__title {
    font-size: 18px;
    font-weight: 700;
    margin: 0;
}

.invite-modal__subtitle {
    color: var(--color-text-maxcontrast);
    margin: 0;
    font-size: 13px;
}

.invite-modal__search-results {
    min-height: 32px;
}

.invite-modal__results {
    list-style: none;
    padding: 0;
    margin: 0;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    overflow: hidden;
    max-height: 220px;
    overflow-y: auto;
}

.invite-modal__result {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    cursor: pointer;
    transition: background 0.1s;
}

.invite-modal__result:hover {
    background: var(--color-background-hover);
}

.invite-modal__result-avatar {
    flex-shrink: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.invite-modal__result-avatar--group,
.invite-modal__result-avatar--circle,
.invite-modal__result-avatar--email,
.invite-modal__result-avatar--federated {
    background: var(--color-primary-element-light);
    border-radius: 50%;
    color: var(--color-primary-element);
}

.invite-modal__result-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.invite-modal__result-name {
    font-weight: 500;
    font-size: 14px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.invite-modal__result-id {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
}

.invite-modal__result-add {
    color: var(--color-primary-element);
    flex-shrink: 0;
}

.invite-modal__empty {
    color: var(--color-text-maxcontrast);
    font-size: 13px;
    text-align: center;
    margin: 0;
    padding: 8px;
}

.invite-modal__searching {
    display: flex;
    justify-content: center;
    padding: 8px;
}

.invite-modal__staged {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.invite-modal__staged-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--color-text-maxcontrast);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.invite-modal__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.invite-modal__chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 8px 4px 6px;
    background: var(--color-primary-element-light);
    border: 1px solid var(--color-primary-element);
    border-radius: var(--border-radius-pill);
    font-size: 13px;
    font-weight: 500;
}

.invite-modal__chip-remove {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 16px;
    line-height: 1;
    color: var(--color-text-maxcontrast);
    padding: 0 2px;
}

.invite-modal__chip-remove:hover {
    color: var(--color-error-text);
}

.invite-modal__actions {
    display: flex;
    gap: 8px;
    padding-top: 4px;
}
</style>
