<template>
    <div class="browse-teams-view">
        <div class="browse-teams-header">
            <h2>{{ t('teamhub', 'Browse Teams') }}</h2>
            <p class="browse-teams-subtitle">
                {{ t('teamhub', 'Discover and join teams in your organization') }}
            </p>
        </div>

        <!-- Search and view controls -->
        <div class="browse-teams-controls">
            <!-- NcTextField default slot in @nextcloud/vue 8.x renders a
                 trailing icon button that overlays the input and intercepts
                 clicks — the input never receives focus, so no cursor and
                 no typing. The proven maintenance-tab pattern omits the
                 slot; we match it here. -->
            <NcTextField
                v-model="searchQuery"
                :label="t('teamhub', 'Search teams')"
                :placeholder="t('teamhub', 'Search by name or description...')"
                class="browse-teams-search" />

            <div class="browse-teams-view-toggle">
                <NcButton
                    :variant="viewMode === 'grid' ? 'primary' : 'tertiary'"
                    :aria-label="t('teamhub', 'Grid view')"
                    @click="viewMode = 'grid'">
                    <template #icon><ViewGrid :size="20" /></template>
                </NcButton>
                <NcButton
                    :variant="viewMode === 'list' ? 'primary' : 'tertiary'"
                    :aria-label="t('teamhub', 'List view')"
                    @click="viewMode = 'list'">
                    <template #icon><ViewList :size="20" /></template>
                </NcButton>
            </div>
        </div>

        <div v-if="loading" class="browse-teams-loading">
            <NcLoadingIcon :size="64" />
            <p>{{ t('teamhub', 'Loading teams...') }}</p>
        </div>

        <div v-else-if="filteredTeams.length === 0" class="browse-teams-empty">
            <AccountGroup :size="64" />
            <h3>{{ t('teamhub', searchQuery ? 'No teams match your search' : 'No teams found') }}</h3>
            <p>{{ searchQuery ? t('teamhub', 'Try a different search term') : t('teamhub', 'There are no teams available to join') }}</p>
        </div>

        <div v-else :class="['browse-teams-list', `browse-teams-list--${viewMode}`]">
            <div v-for="team in filteredTeams" :key="team.id" class="team-card">
                <!-- Header: icon + info side by side -->
                <div class="team-card__header">
                    <!-- Fixed-size icon container keeps card heights consistent -->
                    <div class="team-card__icon-wrap">
                        <img
                            v-if="team.image_url"
                            :src="team.image_url"
                            :alt="team.name"
                            class="team-card__icon team-card__icon--image" />
                        <AccountGroup v-else :size="48" class="team-card__icon" />
                    </div>
                    <div class="team-card__info">
                        <div class="team-card__name-row">
                            <h3 class="team-card__name">{{ team.name }}</h3>
                            <!-- v4.0.2 — template badge fed by teamhub_team_type.
                                 Legacy teams (team.type=null) render nothing. -->
                            <span
                                v-if="teamTypeLabel(team.type)"
                                class="team-card__type-badge"
                                :title="teamTypeTooltip(team.type)">
                                {{ teamTypeLabel(team.type) }}
                            </span>
                        </div>
                        <p v-if="team.description" class="team-card__description">
                            {{ team.description }}
                        </p>
                        <p v-else class="team-card__description team-card__description--empty">
                            {{ t('teamhub', 'No description') }}
                        </p>
                    </div>
                </div>

                <!-- Actions always pinned to card bottom -->
                <div class="team-card__actions">
                    <!-- Direct member: Open + Leave -->
                    <template v-if="team.isMember && team.isDirectMember">
                        <NcButton
                            variant="secondary"
                            :disabled="actionInProgress[team.id]"
                            @click="openTeam(team)">
                            <template #icon>
                                <OpenInApp :size="20" />
                            </template>
                            {{
                                // TRANSLATORS: button label to open this team and view its content
                                t('teamhub', 'Open')
                            }}
                        </NcButton>
                        <NcButton
                            variant="error"
                            :disabled="actionInProgress[team.id]"
                            @click="leaveTeam(team)">
                            <template #icon>
                                <NcLoadingIcon v-if="actionInProgress[team.id]" :size="20" />
                                <ExitToApp v-else :size="20" />
                            </template>
                            {{
                                // TRANSLATORS: button label to leave (depart from) a team the user is currently a member of
                                t('teamhub', 'Leave')
                            }}
                        </NcButton>
                    </template>

                    <!-- Indirect member (via group/team): Open + disabled Leave with tooltip -->
                    <template v-else-if="team.isMember && !team.isDirectMember">
                        <NcButton
                            variant="secondary"
                            :disabled="actionInProgress[team.id]"
                            @click="openTeam(team)">
                            <template #icon>
                                <OpenInApp :size="20" />
                            </template>
                            {{ t('teamhub', 'Open') }}
                        </NcButton>
                        <span
                            class="team-card__indirect-label"
                            :title="t('teamhub', 'You were added to this team through a group or another team. Ask your administrator to remove you.')">
                            <NcButton variant="tertiary" :disabled="true">
                                <template #icon><ExitToApp :size="20" /></template>
                                {{
                                    // TRANSLATORS: disabled button label; user cannot leave because they were added via a group
                                    t('teamhub', 'Leave')
                                }}
                            </NcButton>
                            <span class="team-card__via-badge">{{ t('teamhub', 'via group') }}</span>
                        </span>
                    </template>

                    <!-- Non-member open circle: Join immediately -->
                    <NcButton
                        v-else-if="!team.requiresApproval"
                        variant="primary"
                        :disabled="actionInProgress[team.id]"
                        @click="joinTeam(team)">
                        <template #icon>
                            <NcLoadingIcon v-if="actionInProgress[team.id]" :size="20" />
                            <Plus v-else :size="20" />
                        </template>
                        {{
                            // TRANSLATORS: button label to join (become a member of) a team
                            t('teamhub', 'Join')
                        }}
                    </NcButton>

                    <!-- Non-member closed circle: Request access -->
                    <NcButton
                        v-else
                        variant="secondary"
                        :disabled="actionInProgress[team.id]"
                        @click="requestAccess(team)">
                        <template #icon>
                            <NcLoadingIcon v-if="actionInProgress[team.id]" :size="20" />
                            <AccountQuestion v-else :size="20" />
                        </template>
                        {{ t('teamhub', 'Request Access') }}
                    </NcButton>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { NcButton, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import AccountGroup    from 'vue-material-design-icons/AccountGroup.vue'
import AccountQuestion from 'vue-material-design-icons/AccountQuestion.vue'
import ExitToApp       from 'vue-material-design-icons/ExitToApp.vue'
import OpenInApp       from 'vue-material-design-icons/OpenInApp.vue'
import Plus            from 'vue-material-design-icons/Plus.vue'
import ViewGrid        from 'vue-material-design-icons/ViewGrid.vue'
import ViewList        from 'vue-material-design-icons/ViewList.vue'

export default {
    name: 'BrowseTeamsView',
    components: {
        NcButton,
        NcLoadingIcon,
        NcTextField,
        AccountGroup,
        AccountQuestion,
        ExitToApp,
        OpenInApp,
        Plus,
        ViewGrid,
        ViewList,
    },
    data() {
        return {
            loading: false,
            teams: [],
            searchQuery: '',
            viewMode: 'grid',
            // Tracks which team ID has a pending action to prevent double-clicks.
            actionInProgress: {},
        }
    },
    computed: {
        filteredTeams() {
            if (!this.searchQuery) {
                return this.teams
            }
            const query = this.searchQuery.toLowerCase()
            return this.teams.filter(team => {
                const nameMatch  = team.name.toLowerCase().includes(query)
                const descMatch  = team.description?.toLowerCase().includes(query)
                // v4.0.9 — also match on the localized template badge so a
                // user browsing in Dutch can type "Samenwerking" and land on
                // every collaboration team. teamTypeLabel returns null for
                // legacy teams without a stored type — those simply skip the
                // label check and fall back to name/description matching.
                const labelMatch = this.teamTypeLabel(team.type)?.toLowerCase().includes(query)
                return nameMatch || descMatch || labelMatch
            })
        },
    },
    mounted() {
        this.loadTeams()
    },
    methods: {
        t,

        /**
         * v4.0.2 — team.type comes straight from teamhub_team_type
         * ('collaboration'|'project'|'department') or null for legacy teams.
         * Legacy teams show no badge (per team-type feature fallback design).
         */
        teamTypeLabel(type) {
            if (type === 'collaboration') return t('teamhub', 'Collaboration')
            if (type === 'project')       return t('teamhub', 'Project')
            if (type === 'department')    return t('teamhub', 'Department')
            return null
        },
        teamTypeTooltip(type) {
            if (type === 'collaboration') return t('teamhub', 'Created from the Collaboration template.')
            if (type === 'project')       return t('teamhub', 'Created from the Project template.')
            if (type === 'department')    return t('teamhub', 'Created from the Department template.')
            return ''
        },

        async loadTeams() {
            this.loading = true
            try {
                const response = await axios.get(generateUrl('/apps/teamhub/api/v1/teams/browse'))
                this.teams = response.data || []
            } catch (error) {
                showError(t('teamhub', 'Failed to load teams'))
            } finally {
                this.loading = false
            }
        },

        async joinTeam(team) {
            this.actionInProgress[team.id] = true
            try {
                await axios.post(generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/join`), {})
                showSuccess(t('teamhub', 'You have joined {team}', { team: team.name }))
                team.isMember = true
                team.isDirectMember = true
                this.$emit('team-joined', team.id)
            } catch (error) {
                showError(t('teamhub', 'Failed to join team'))
            } finally {
                this.actionInProgress[team.id] = false
            }
        },

        async requestAccess(team) {
            this.actionInProgress[team.id] = true
            try {
                await axios.post(generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/join`), {})
                showSuccess(t('teamhub', 'Access requested for {team}', { team: team.name }))
                // Don't flip isMember — user is in Requesting state, not yet approved
            } catch (error) {
                showError(t('teamhub', 'Failed to request access'))
            } finally {
                this.actionInProgress[team.id] = false
            }
        },

        openTeam(team) {
            this.$emit('team-opened', team.id)
        },

        async leaveTeam(team) {
            this.actionInProgress[team.id] = true
            try {
                await axios.post(generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/leave`), {})
                showSuccess(t('teamhub', 'You have left {team}', { team: team.name }))
                team.isMember = false
                this.$emit('team-left', team.id)
            } catch (error) {
                const msg = error.response?.data?.error || ''
                showError(msg || t('teamhub', 'Failed to leave team'))
            } finally {
                this.actionInProgress[team.id] = false
            }
        },
    },
}
</script>

<style scoped>
.browse-teams-view {
    padding: 40px;
    max-width: 1200px;
    margin: 0 auto;
}

.browse-teams-header {
    margin-bottom: 32px;
}

.browse-teams-header h2 {
    font-size: 28px;
    font-weight: 600;
    margin: 0 0 8px 0;
}

.browse-teams-subtitle {
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-heading);
    margin: 0;
}

.browse-teams-controls {
    display: flex;
    gap: 12px;
    margin-bottom: 24px;
    align-items: flex-end;
}

.browse-teams-search {
    flex: 1;
}

.browse-teams-view-toggle {
    display: flex;
    gap: 4px;
}

.browse-teams-loading,
.browse-teams-empty {
    text-align: center;
    padding: 80px 20px;
}

.browse-teams-loading p {
    margin-top: 16px;
    color: var(--color-text-maxcontrast);
}

.browse-teams-empty {
    color: var(--color-text-maxcontrast);
}

.browse-teams-empty h3 {
    margin: 16px 0 8px 0;
}

.browse-teams-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
    gap: 20px;
}

/* Card: flex column so actions are always at the bottom */
.team-card {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: 8px;
    padding: 24px;
    display: flex;
    flex-direction: column;
    transition: box-shadow 0.2s ease;
}

.team-card:hover {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.team-card__header {
    display: flex;
    gap: 16px;
    /* Grow to fill available space, pushing actions to the bottom */
    flex: 1;
    margin-bottom: 20px;
}

/*
 * Fixed-size container for the icon (image or MDI).
 * 64px × 64px keeps both variants the same footprint so the card body
 * height is consistent whether a team has a photo or not.
 */
.team-card__icon-wrap {
    flex-shrink: 0;
    width: 64px;
    height: 64px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.team-card__icon {
    color: var(--color-primary);
}

.team-card__icon--image {
    width: 64px;
    height: 64px;
    border-radius: var(--border-radius-large);
    object-fit: cover;
    border: 1px solid var(--color-border);
}

.team-card__info {
    flex: 1;
    min-width: 0;
}

.team-card__name-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin: 0 0 8px 0;
}

.team-card__name {
    font-size: 18px;
    font-weight: 600;
    margin: 0;
}

/* v4.0.2 — template badge (Collaboration/Project/Department) next to the
   team name. Neutral primary tone; sits at the same visual weight as the
   circles-config labels shown inside the Team info widget. */
.team-card__type-badge {
    font-size: var(--th-font-micro);
    font-weight: 600;
    padding: 2px 8px;
    border-radius: var(--border-radius-pill);
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    white-space: nowrap;
}

.team-card__description {
    margin: 0;
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-body);
    line-height: 1.5;
}

.team-card__description--empty {
    font-style: italic;
}

/* Actions: right-aligned, never pushed around by header height */
.team-card__actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 0; /* header margin-bottom already provides separation */
}

/* Indirect member wrapper — wraps the disabled Leave button + badge */
.team-card__indirect-label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: default;
}
/* v3.100.14: full-saturation warning badge per SKILLS.md
   (was a 15% color-mix() soft tint). */
.team-card__via-badge {
    font-size: var(--th-font-micro);
    font-weight: 600;
    padding: 2px 7px;
    border-radius: var(--border-radius-pill);
    background: var(--color-warning);
    color: var(--color-warning-text);
    white-space: nowrap;
}

/* ── List view ───────────────────────────────────────────────────── */
.browse-teams-list--list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.browse-teams-list--list .team-card {
    flex-direction: row;
    align-items: center;
    padding: 16px 20px;
}

.browse-teams-list--list .team-card__header {
    flex: 1;
    margin-bottom: 0;
}

.browse-teams-list--list .team-card__actions {
    flex-shrink: 0;
    margin-left: 20px;
}
</style>
