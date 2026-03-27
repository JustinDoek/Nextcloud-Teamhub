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
            <NcTextField
                v-model="searchQuery"
                :label="t('teamhub', 'Search teams')"
                :placeholder="t('teamhub', 'Search by name or description...')"
                class="browse-teams-search">
                <Magnify :size="20" />
            </NcTextField>
            
            <div class="browse-teams-view-toggle">
                <NcButton
                    :type="viewMode === 'grid' ? 'primary' : 'tertiary'"
                    :aria-label="t('teamhub', 'Grid view')"
                    @click="viewMode = 'grid'">
                    <template #icon><ViewGrid :size="20" /></template>
                </NcButton>
                <NcButton
                    :type="viewMode === 'list' ? 'primary' : 'tertiary'"
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
                <div class="team-card__header">
                    <AccountGroup :size="48" class="team-card__icon" />
                    <div class="team-card__info">
                        <h3 class="team-card__name">{{ team.name }}</h3>
                        <p v-if="team.description" class="team-card__description">
                            {{ team.description }}
                        </p>
                        <p v-else class="team-card__description team-card__description--empty">
                            {{ t('teamhub', 'No description') }}
                        </p>
                    </div>
                </div>

                <div class="team-card__actions">
                    <NcButton
                        v-if="team.isMember"
                        type="primary"
                        disabled>
                        <template #icon>
                            <Check :size="20" />
                        </template>
                        {{ t('teamhub', 'Member') }}
                    </NcButton>
                    <NcButton
                        v-else
                        type="primary"
                        @click="requestJoin(team)">
                        <template #icon>
                            <Plus :size="20" />
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
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import ViewGrid from 'vue-material-design-icons/ViewGrid.vue'
import ViewList from 'vue-material-design-icons/ViewList.vue'

export default {
    name: 'BrowseTeamsView',
    components: {
        NcButton,
        NcLoadingIcon,
        NcTextField,
        AccountGroup,
        Plus,
        Check,
        Magnify,
        ViewGrid,
        ViewList,
    },
    data() {
        return {
            loading: false,
            teams: [],
            searchQuery: '',
            viewMode: 'grid', // 'grid' or 'list'
        }
    },
    computed: {
        filteredTeams() {
            if (!this.searchQuery) {
                return this.teams
            }
            const query = this.searchQuery.toLowerCase()
            return this.teams.filter(team => {
                const nameMatch = team.name.toLowerCase().includes(query)
                const descMatch = team.description?.toLowerCase().includes(query)
                return nameMatch || descMatch
            })
        },
    },
    mounted() {
        this.loadTeams()
    },
    methods: {
        t,
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
        async requestJoin(team) {
            try {
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/join`),
                    {},
                    
                )
                showSuccess(t('teamhub', 'Access requested for {team}', { team: team.name }))
                team.isMember = true // Optimistic update
                this.$emit('team-joined', team.id)
            } catch (error) {
                showError(t('teamhub', 'Failed to request access'))
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
    font-size: 16px;
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

.team-card {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: 8px;
    padding: 24px;
    transition: box-shadow 0.2s ease;
}

.team-card:hover {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.team-card__header {
    display: flex;
    gap: 16px;
    margin-bottom: 20px;
}

.team-card__icon {
    flex-shrink: 0;
    color: var(--color-primary);
}

.team-card__info {
    flex: 1;
    min-width: 0;
}

.team-card__name {
    font-size: 18px;
    font-weight: 600;
    margin: 0 0 8px 0;
}

.team-card__description {
    margin: 0;
    color: var(--color-text-maxcontrast);
    font-size: 14px;
    line-height: 1.5;
}

.team-card__description--empty {
    font-style: italic;
}

.team-card__actions {
    display: flex;
    justify-content: flex-end;
}

/* List view styles */
.browse-teams-list--list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.browse-teams-list--list .team-card {
    display: flex;
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
}

.browse-teams-list--list .team-card__header {
    flex-direction: row;
    gap: 16px;
    flex: 1;
}

.browse-teams-list--list .team-card__icon {
    flex-shrink: 0;
}

.browse-teams-list--list .team-card__info {
    flex: 1;
}

.browse-teams-list--list .team-card__actions {
    flex-shrink: 0;
    margin-left: 20px;
}
</style>
