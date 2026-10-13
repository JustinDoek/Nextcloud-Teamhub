<template>
    <div class="manage-section" data-section="sub-teams">
        <h3>{{ t('teamhub', 'Sub-teams') }}</h3>
        <p class="manage-section-desc">
            {{ t('teamhub', 'A sub-team is a smaller group inside this team, with its own files and its own members. You pick who is in it from this team, and you can invite people from outside. Everything about a sub-team is managed from here.') }}
        </p>

        <NcLoadingIcon v-if="loading" :size="iconBody" />

        <NcNoteCard v-else-if="error" type="error">
            {{ error }}
            <NcButton variant="secondary" @click="load">
                {{ t('teamhub', 'Try again') }}
            </NcButton>
        </NcNoteCard>

        <template v-else>
            <NcEmptyContent
                v-if="!subTeams.length"
                :name="t('teamhub', 'No sub-teams yet')"
                :description="t('teamhub', 'Create one to give part of this team its own space to work in.')">
                <template #icon><AccountMultipleOutline :size="iconHero" /></template>
            </NcEmptyContent>

            <ul v-else class="th-subteams__list">
                <li v-for="sub in subTeams" :key="sub.id" class="th-subteams__row">
                    <div class="th-subteams__icon">
                        <AccountMultipleOutline :size="iconBody" />
                    </div>
                    <div class="th-subteams__info">
                        <span class="th-subteams__name">{{ sub.name }}</span>
                        <!-- v4.12.6 - the template and the policy that got
                             applied, under the name. They are chosen in a
                             dialog that then closes, so without this the only
                             way to find out what a sub-team carries was to
                             open the dialog again and read the selects
                             (DESIGN 2.180).

                             Chips rather than a sentence, and the same slot the
                             Maintenance grid puts a team's two classification
                             chips in. Each carries an **icon** rather than a
                             "Template:" prefix, because three neutral chips on
                             one row (these two and the visibility one) have to
                             be told apart at a glance and a prefix inside a
                             pill reads as part of the value. The `title` names
                             the chip in words for anyone the icon does not
                             reach - it lands on NcChip's root div, which
                             declares no `inheritAttrs: false` (checked in the
                             installed 9.8.0 dist, not assumed).

                             Both are `tertiary`, the neutral chip: neither is a
                             state and neither is checked against anything, and
                             a coloured chip would claim a conformance test that
                             deliberately does not exist here (DESIGN 2.104, and
                             2.180 on why sub-teams have no compliance). -->
                        <div class="th-subteams__facts">
                            <span class="th-subteams__meta">
                                {{ n('teamhub', '{n} member', '{n} members', sub.members, { n: sub.members }) }}
                            </span>
                            <NcChip
                                v-if="templateOf(sub)"
                                no-close
                                variant="tertiary"
                                :text="templateOf(sub)"
                                :title="t('teamhub', 'Created from the {template} template', { template: templateOf(sub) })">
                                <template #icon><ShapeOutline :size="iconInline" /></template>
                            </NcChip>
                            <NcChip
                                no-close
                                variant="tertiary"
                                :text="policyOf(sub)"
                                :title="policyTitle(sub)">
                                <template #icon><ShieldLockOutline :size="iconInline" /></template>
                            </NcChip>
                        </div>
                    </div>
                    <!-- Private is the default and the common case, so it is
                         the listed ones that are worth marking. A chip rather
                         than a sentence: it is a state, and it sits in a row. -->
                    <NcChip
                        v-if="sub.visibility === 'listed'"
                        no-close
                        variant="tertiary"
                        :text="t('teamhub', 'Visible to the team')" />
                    <NcChip
                        v-else
                        no-close
                        variant="tertiary"
                        :text="t('teamhub', 'Private')" />

                    <!-- v4.12.5 - the sub-team's whole administration, and the
                         only place it exists: it has no Manage team of its own
                         and a parent admin who did not create it has no row on
                         it, so its sidebar actions are out of their reach too. -->
                    <!-- TRANSLATORS: accessible name of the three-dot menu on one sub-team's row — {team} is the sub-team's name -->
                    <NcActions :aria-label="t('teamhub', 'Actions for {team}', { team: sub.name })">
                        <NcActionButton close-after-click @click="manage(sub)">
                            <template #icon><Pencil :size="iconBody" /></template>
                            {{ t('teamhub', 'Manage sub-team') }}
                        </NcActionButton>
                        <NcActionButton close-after-click @click="askDelete(sub)">
                            <template #icon><Delete :size="iconBody" /></template>
                            {{ t('teamhub', 'Delete sub-team') }}
                        </NcActionButton>
                    </NcActions>
                </li>
            </ul>

            <NcButton variant="secondary" @click="createOpen = true">
                <template #icon><Plus :size="iconBody" /></template>
                {{ t('teamhub', 'New sub-team') }}
            </NcButton>
        </template>

        <CreateSubTeamModal
            v-if="createOpen"
            :parent-team-id="team.id"
            :parent-team-name="team.name"
            @close="createOpen = false"
            @created="onCreated" />

        <ManageSubTeamModal
            v-if="managing"
            :key="managing.id"
            :parent-team-id="team.id"
            :sub-team="managing"
            @close="managing = null"
            @changed="load" />

        <!-- Deleting a sub-team takes its storage, its messages and everything
             else keyed on it. CLAUDE.md asks a destructive action to confirm
             first, and the sentence names what goes rather than saying "are
             you sure". -->
        <NcDialog
            v-if="pendingDelete"
            :name="t('teamhub', 'Delete sub-team')"
            :open="true"
            :can-close="!deleting"
            @update:open="pendingDelete = null">
            <p class="th-subteams__confirm">
                {{ t('teamhub', 'Delete {name}? Its files, messages and everything else it holds go with it. This cannot be undone.', { name: pendingDelete.name }) }}
            </p>
            <template #actions>
                <NcButton variant="tertiary" :disabled="deleting" @click="pendingDelete = null">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton variant="error" :disabled="deleting" @click="confirmDelete">
                    <template #icon>
                        <NcLoadingIcon v-if="deleting" :size="iconBody" />
                        <Delete v-else :size="iconBody" />
                    </template>
                    {{ t('teamhub', 'Delete sub-team') }}
                </NcButton>
            </template>
        </NcDialog>
    </div>
</template>

<script>
import {
    NcActionButton, NcActions, NcButton, NcChip, NcDialog, NcEmptyContent,
    NcLoadingIcon, NcNoteCard,
} from '@nextcloud/vue'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
// v4.12.6 - the two classification chips. ShieldLockOutline is the icon
// this app has meant by *policy* since v4.8.2 (AdminSettings reserves
// ShieldCheck for Compliance, which is the evidence rather than the
// control); ShapeOutline is the template - the shape a team is cut from.
import ShapeOutline from 'vue-material-design-icons/ShapeOutline.vue'
import ShieldLockOutline from 'vue-material-design-icons/ShieldLockOutline.vue'
import CreateSubTeamModal from './CreateSubTeamModal.vue'
import ManageSubTeamModal from './ManageSubTeamModal.vue'
import { ICON_BODY, ICON_HERO, ICON_INLINE } from '../../constants/uiTokens.js'
// v4.12.6 - the one label rule for a template and a profile: a seeded row is
// translated by key, a row an administrator renamed is shown as they typed it.
// Shared with the admin Policy panel and the Maintenance grid, because three
// copies of that switch is how the myWork.js drift started.
import { profileDisplayName, templateDisplayName } from '../../constants/policy.js'

/**
 * Manage team → Sub-teams (v4.12.2, DESIGN §2.175).
 *
 * The parent team's whole administrative view of its sub-teams, and the only
 * place they are administered: a sub-team has no Manage team of its own.
 *
 * A component of its own rather than another branch of `ManageTeamView.vue`,
 * which is already ~8,600 lines — the same reason `src/components/members/`
 * and `src/components/services/` exist. `ManageTeamView` keeps only the tab
 * entry and the wrapper div.
 */
export default {
    name: 'SubTeamsPanel',

    components: {
        NcActionButton, NcActions, NcButton, NcChip, NcDialog, NcEmptyContent,
        NcLoadingIcon, NcNoteCard,
        AccountMultipleOutline, Delete, Pencil, Plus, ShapeOutline, ShieldLockOutline,
        CreateSubTeamModal, ManageSubTeamModal,
    },

    props: {
        team: {
            type: Object,
            required: true,
        },
    },

    data() {
        return {
            loading: true,
            error: '',
            createOpen: false,
            // v4.12.5 - the sub-team the manage dialog is open on, and the one
            // the delete confirmation is asking about. Separate, because they
            // are separate questions and nesting one inside the other would
            // make Cancel ambiguous.
            managing: null,
            pendingDelete: null,
            deleting: false,
        }
    },

    computed: {
        subTeams() {
            return this.$store.getters.subTeamsOf(this.team.id)
        },
        iconBody() {
            return ICON_BODY
        },
        iconHero() {
            return ICON_HERO
        },
        /**
         * 16px, the size for an icon beside 13px text. NcChip asks for an icon
         * no taller than `--chip-size`, and this is comfortably under it.
         */
        iconInline() {
            return ICON_INLINE
        },
    },

    watch: {
        // The tab can outlive a team switch, and the list is keyed by parent.
        'team.id'() {
            this.load()
        },
    },

    mounted() {
        this.load()
    },

    methods: {
        t,
        n,

        async load() {
            this.loading = true
            this.error = ''
            try {
                await this.$store.dispatch('fetchSubTeams', this.team.id)
            } catch (e) {
                this.error = e?.response?.data?.error || t('teamhub', 'Could not load the sub-teams')
            } finally {
                this.loading = false
            }
        },

        onCreated(subTeam) {
            this.createOpen = false
            showSuccess(t('teamhub', 'Sub-team {name} created', { name: subTeam?.name || '' }))
        },

        /**
         * The template a sub-team carries, or '' when it carries none - a
         * sub-team made before templates were recorded, or one whose template
         * an administrator has removed. The row leaves the segment out rather
         * than printing "Template: none", which would read as a choice.
         */
        templateOf(sub) {
            return templateDisplayName(sub.templateKey, sub.templateLabel, sub.templateSeeded)
        },

        /**
         * The policy, which is always stated - including when there is none.
         * An unclassified sub-team is governed by nothing, and that is the
         * answer to "which policy got applied", not a gap in the row.
         */
        policyOf(sub) {
            return profileDisplayName(sub.profileKey, sub.profileLabel, sub.profileSeeded)
                || t('teamhub', 'No policy')
        },

        /**
         * The policy chip's `title`, and **null** when there is no policy:
         * *No policy* needs no gloss, and Vue drops an attribute bound to null
         * rather than rendering an empty tooltip.
         */
        policyTitle(sub) {
            const policy = profileDisplayName(sub.profileKey, sub.profileLabel, sub.profileSeeded)
            // TRANSLATORS: tooltip on a sub-team's policy chip — {policy} is the name of the policy profile governing it, e.g. "Confidential"
            return policy ? t('teamhub', 'Governed by the {policy} policy', { policy }) : null
        },

        manage(sub) {
            this.managing = sub
        },

        askDelete(sub) {
            this.pendingDelete = sub
        },

        async confirmDelete() {
            if (!this.pendingDelete || this.deleting) {
                return
            }
            const sub = this.pendingDelete
            this.deleting = true
            this.error = ''
            try {
                await this.$store.dispatch('deleteSubTeam', {
                    teamId: this.team.id,
                    subTeamId: sub.id,
                })
                showSuccess(t('teamhub', 'Sub-team {name} deleted', { name: sub.name }))
                this.pendingDelete = null
            } catch (e) {
                // Into the panel's own error slot rather than a toast: the
                // cascade's refusal names what to do next ("delete the
                // sub-team first"), and a sentence worth acting on should not
                // disappear on a timer.
                this.error = e?.response?.data?.error || t('teamhub', 'Could not delete the sub-team')
                this.pendingDelete = null
            } finally {
                this.deleting = false
            }
        },
    },
}
</script>

<style scoped lang="scss">
.th-subteams__list {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    margin-block: var(--th-space-md);
}

.th-subteams__row {
    display: flex;
    align-items: center;
    gap: var(--th-space-md);
    padding: var(--th-space-sm) var(--th-space-md);
    border-radius: var(--th-radius-control);
    background: var(--color-background-hover);
}

.th-subteams__icon {
    display: flex;
    align-items: center;
    color: var(--color-text-maxcontrast);
}

.th-subteams__info {
    display: flex;
    flex-direction: column;
    /* Takes the slack so the visibility chip sits at the row's end. */
    flex: 1 1 auto;
    min-width: 0;
}

.th-subteams__name {
    font-size: var(--th-font-body);
    font-weight: var(--th-font-weight-medium);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* The member count and the two classification chips on one line, wrapping to
   a second when the panel is narrow rather than squeezing the name. */
.th-subteams__facts {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--th-space-xs);
}

.th-subteams__meta {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.th-subteams__confirm {
    margin: 0;
    line-height: var(--th-line-height-body);
}
</style>
