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
                <ul v-else-if="visibleResults.length" class="invite-modal__results">
                    <li
                        v-for="item in visibleResults"
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
                <!-- Only when the *search* found nobody. A list emptied by
                     staging everybody in it is not an empty search, and
                     telling the admin "No users or groups found" at the moment
                     they have just taken all five would be a lie. -->
                <div v-else-if="query.length >= 2 && !searchResults.length" class="invite-modal__empty">
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
            /** Exactly what the last search returned, staged people included. */
            searchResults: [],
            staged: [],
            searching: false,
            sending: false,
            searchTimer: null,
            allowedTypes: ['user'],
        }
    },
    computed: {
        /**
         * The rows still worth clicking: the search, minus whoever is already
         * staged.
         *
         * v4.13.74 — this used to be a data array that `addItem()` emptied, so
         * one search could only ever contribute **one** invitee: the list
         * vanished under the first click and the query was wiped with it.
         * Picking three colleagues out of one result list was not expressible,
         * which is what "it only invites the first one I selected" was.
         *
         * Derived rather than spliced, so removing a chip puts the row back
         * where it was instead of needing the search run again.
         */
        visibleResults() {
            const stagedKeys = new Set(this.staged.map(u => u.type + ':' + u.id))
            return this.searchResults.filter(u => !stagedKeys.has(u.type + ':' + u.id))
        },
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
            this.searchResults = []
            if (this.query.length < 2) return
            this.searching = true
            this.searchTimer = setTimeout(async () => {
                try {
                    const { data } = await axios.get(
                        generateUrl('/apps/teamhub/api/v1/users/search'),
                        { params: { q: this.query, teamId: this.teamId } }
                    )
                    // Staged people are filtered out by `visibleResults`, not
                    // here: the raw answer is what tells the empty state
                    // whether the search found anybody at all.
                    this.searchResults = (data || [])
                        .map(u => ({ id: u.id, label: u.displayName || u.id, type: u.type || 'user', subline: u.subline || '' }))
                } catch {
                    this.searchResults = []
                } finally {
                    this.searching = false
                }
            }, 300)
        },
        /**
         * Stage one person. The query and the result list both stay, so the
         * next name in the same list is one more click away.
         */
        addItem(item) {
            const key = item.type + ':' + item.id
            if (!this.staged.find(u => u.type + ':' + u.id === key)) {
                this.staged.push(item)
            }
        },
        removeStaged(item) {
            this.staged = this.staged.filter(u => !(u.id === item.id && u.type === item.type))
        },
        /**
         * Send the whole batch and report what the server says happened to
         * each entry.
         *
         * v4.13.74 — the toast used to repeat the number *asked for*, so a
         * batch in which four of five entries were refused still read "5
         * members invited" and the admin had no way to know. The endpoint has
         * always answered with a status per id; now it is read.
         */
        async sendInvites() {
            if (!this.staged.length) return
            this.sending = true
            const batch = this.staged.slice()
            try {
                const { data } = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/invite-members`),
                    { members: batch.map(u => ({ id: u.id, type: u.type })) }
                )
                const results = (data && typeof data === 'object') ? data : {}
                const invited = []
                const already = []
                const failed  = []
                for (const u of batch) {
                    const status = String(results[u.id] ?? 'invited')
                    if (status.startsWith('failed')) failed.push(u)
                    else if (status === 'already_invited') already.push(u)
                    else invited.push(u)
                }

                if (invited.length) {
                    // TRANSLATORS: success message after inviting, e.g. "1 member invited" or "3 members invited"
                    showSuccess(n('teamhub', '{n} member invited', '{n} members invited', invited.length, { n: invited.length }))
                }
                if (already.length) {
                    // TRANSLATORS: shown when somebody picked for an invite already had one, or was already a member
                    showSuccess(n('teamhub', '{n} person already had an invite', '{n} people already had an invite', already.length, { n: already.length }))
                }
                if (failed.length) {
                    showError(t('teamhub', 'Could not invite {names}', { names: failed.map(u => u.label).join(', ') }))
                }

                // Refresh the member list either way: an entry that failed may
                // have been a duplicate of somebody who is in the team already.
                this.$emit('invited')
                if (failed.length) {
                    // Keep the dialog open with only the refusals staged, so
                    // the retry re-sends those and not the ones that worked.
                    this.staged = failed
                } else {
                    this.$emit('close')
                }
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
