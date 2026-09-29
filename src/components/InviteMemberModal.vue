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
                {{ t('teamhub', 'Search users, groups, and teams to add to this team') }}
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
                    <NcLoadingIcon :size="ICON_BODY" />
                </div>
                <ul v-else-if="results.length" class="invite-modal__results">
                    <li
                        v-for="item in results"
                        :key="item.type + ':' + item.id"
                        class="invite-modal__result"
                        @click="addItem(item)">
                        <!-- v4.10.7 — the shared person row: avatar, name and
                             the subline that says which colleague this is. -->
                        <PersonRow
                            :id="item.id"
                            :display-name="item.label"
                            :type="item.type"
                            :subline="item.subline" />
                        <Plus :size="ICON_BODY" class="invite-modal__result-add" />
                    </li>
                </ul>
                <div v-else-if="query.length >= 2" class="invite-modal__empty">
                    <p class="invite-modal__empty-headline">
                        {{ t('teamhub', 'No users or groups found') }}
                    </p>
                    <!-- TRANSLATORS: hint shown when a user-search returns zero results — explains that SSO-provisioned users (Microsoft Entra, SAML, OIDC) only appear after their first Nextcloud login -->
                    <p class="invite-modal__empty-hint">
                        {{ t('teamhub', 'If this colleague was just added via single sign-on (Microsoft Entra, SAML, or OIDC), they need to sign in to Nextcloud once before they can be added to a team.') }}
                    </p>
                </div>
            </div>

            <!-- Staged -->
            <div v-if="staged.length" class="invite-modal__staged">
                <span class="invite-modal__staged-label">{{ t('teamhub', 'To be invited:') }}</span>
                <div class="invite-modal__chips">
                    <!-- v4.10.10: a staged invitee is a removable NcChip (design
                         guide § Chips: selected values in a multi-value input). -->
                    <NcChip
                        v-for="u in staged"
                        :key="u.type + ':' + u.id"
                        variant="primary"
                        :text="u.label"
                        :aria-label-close="t('teamhub', 'Remove {name}', { name: u.label })"
                        @close="removeStaged(u)">
                        <template #icon>
                            <AccountGroup v-if="u.type === 'group'" :size="ICON_INLINE" />
                            <AccountMultiple v-else-if="u.type === 'circle'" :size="ICON_INLINE" />
                            <EmailOutline v-else-if="u.type === 'email'" :size="ICON_INLINE" />
                            <EarthArrowRight v-else-if="u.type === 'federated'" :size="ICON_INLINE" />
                            <NcAvatar v-else :user="u.id" :display-name="u.label" :size="AVATAR_SM" :disable-menu="true" />
                        </template>
                    </NcChip>
                </div>
            </div>

            <!-- Actions -->
            <div class="invite-modal__actions">
                <NcButton
                    variant="primary"
                    :disabled="!staged.length || sending"
                    @click="sendInvites">
                    <template #icon>
                        <NcLoadingIcon v-if="sending" :size="ICON_BODY" />
                        <AccountPlus v-else :size="ICON_BODY" />
                    </template>
                    {{ t('teamhub', 'Invite {n}', { n: staged.length }) }}
                </NcButton>
                <NcButton variant="tertiary" @click="$emit('close')">
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
import { NcModal, NcButton, NcTextField, NcAvatar, NcLoadingIcon, NcChip } from '@nextcloud/vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import EarthArrowRight from 'vue-material-design-icons/EarthArrowRight.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import PersonRow from './PersonRow.vue'
import { ICON_BODY, ICON_INLINE, AVATAR_SM } from '../constants/uiTokens.js'
// v3.100.14: MDI icon for the chip remove button — replaces the ×
// multiplication-sign character (gui.md § 13).

export default {
    name: 'InviteMemberModal',
    components: { NcModal, NcButton, NcTextField, NcAvatar, NcLoadingIcon, NcChip, AccountPlus, AccountGroup, AccountMultiple, EmailOutline, EarthArrowRight, Plus, PersonRow },
    props: {
        teamId: { type: String, required: true },
    },
    emits: ['close', 'invited'],
    data() {
        return {
            ICON_BODY,
            ICON_INLINE,
            AVATAR_SM,
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
            if (this.allowedTypes.includes('circle'))    labels.push(t('teamhub', 'team name'))
            if (this.allowedTypes.includes('email'))     labels.push(t('teamhub', 'email address'))
            if (this.allowedTypes.includes('federated')) labels.push(t('teamhub', 'user@remote.example'))
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
                        { params: { q: this.query, teamId: this.teamId } }
                    )
                    const stagedKeys = new Set(this.staged.map(u => u.type + ':' + u.id))
                    this.results = (data || [])
                        .filter(u => !stagedKeys.has(u.type + ':' + u.id))
                        .map(u => ({ id: u.id, label: u.displayName || u.id, type: u.type || 'user', subline: u.subline || '' }))
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
    font-size: var(--th-font-heading);
    font-weight: 700;
    margin: 0;
}

.invite-modal__subtitle {
    color: var(--color-text-maxcontrast);
    margin: 0;
    font-size: var(--th-font-meta);
}

.invite-modal__search-results {
    min-height: 32px;
}

.invite-modal__results {
    list-style: none;
    padding: 0;
    margin: 0;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-element);
    overflow: hidden;
    max-height: 220px;
    overflow-y: auto;
}

.invite-modal__result {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    cursor: pointer;
    transition: background var(--animation-quick);
}

.invite-modal__result:hover {
    background: var(--color-background-hover);
}

.invite-modal__result-add {
    color: var(--color-primary-element);
    flex-shrink: 0;
}

.invite-modal__empty {
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
    text-align: center;
    margin: 0;
    padding: 8px;
}

.invite-modal__empty-headline {
    margin: 0 0 4px 0;
    font-weight: 600;
    color: var(--color-main-text);
}

.invite-modal__empty-hint {
    margin: 0;
    font-size: var(--th-font-meta);
    line-height: 1.4;
    color: var(--color-text-maxcontrast);
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
    font-size: var(--th-font-meta);
    font-weight: 600;
    color: var(--color-text-maxcontrast);
}

.invite-modal__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.invite-modal__actions {
    display: flex;
    gap: 8px;
    padding-top: 4px;
}
</style>
