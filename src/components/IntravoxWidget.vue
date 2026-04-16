<template>
    <div class="intravox-widget">
        <div v-if="loading" class="intravox-widget__loading">
            <NcLoadingIcon :size="20" />
        </div>

        <!-- Page exists -->
        <div v-else-if="teamPage" class="intravox-widget__content">
            <a :href="getPageUrl(teamPage)" target="_blank" class="intravox-page-link intravox-page-link--main">
                <FileDocumentOutline :size="20" />
                <span class="intravox-page-title">{{ teamPage.title }}</span>
                <OpenInNew :size="14" class="intravox-page-icon" />
            </a>
            <div v-if="subPages.length > 0" class="intravox-subpages">
                <a
                    v-for="page in subPages"
                    :key="page.uniqueId"
                    :href="getPageUrl(page)"
                    target="_blank"
                    class="intravox-page-link intravox-page-link--sub">
                    <FileDocumentOutline :size="16" />
                    <span class="intravox-page-title">{{ page.title }}</span>
                    <OpenInNew :size="12" class="intravox-page-icon" />
                </a>
            </div>
        </div>

        <!-- Error state (only shown to admins who tried to create) -->
        <div v-else-if="error" class="intravox-widget__empty">
            <p class="intravox-widget__message">{{ error }}</p>
        </div>
    </div>
</template>

<script>
import { mapState, mapGetters } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { NcLoadingIcon } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'

export default {
    name: 'IntravoxWidget',
    components: { NcLoadingIcon, FileDocumentOutline, OpenInNew },
    props: {
        // Whether the current user has permission to create/delete pages.
        // Passed from TeamView, which already has the isTeamModerator computed.
        canAct: { type: Boolean, default: false },
    },
    emits: ['pages-loaded'],
    data() {
        return {
            loading: true,
            teamPage: null,
            subPages: [],
            allPages: [],
            error: null,
            teamhubRoot: null,
        }
    },
    computed: {
        ...mapState(['members', 'currentUser', 'intravoxParentPath']),
        ...mapGetters(['currentTeam']),
        canCreatePage() {
            const member = this.members.find(m => m.userId === this.currentUser?.uid)
            return member && member.level >= 8
        },
        teamName() {
            return this.currentTeam?.name || ''
        },
    },
    mounted() {
        this.initDocumentationPage()
    },
    methods: {
        t,

        getPageUrl(page) {
            // IntraVox URL format: /apps/intravox/#page-{uuid}
            // page.uniqueId is always in "page-{uuid}" format; page.id may be a slug like "my-team"
            const pageId = page.uniqueId || page.id
            return generateUrl('/apps/intravox/') + '#' + pageId
        },

        async initDocumentationPage() {
            this.loading = true
            this.error = null
            try {
                const teamId = this.currentTeam?.id
                if (!teamId) return

                const teamNameLower = this.teamName.toLowerCase()
                const slug = teamNameLower.replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')

                // 1. Find the team's main IntraVox page from the full page list
                const response = await axios.get(generateUrl('/apps/intravox/api/pages'))
                if (!response.data || !Array.isArray(response.data)) return

                const pages = response.data
                const existingTeamPage = pages.find(p =>
                    p.title?.toLowerCase() === teamNameLower &&
                    !p.uniqueId?.startsWith('template-')
                ) || null

                if (existingTeamPage) {
                    // Inject path and id since IntraVox API doesn't return them
                    if (!existingTeamPage.path) {
                        existingTeamPage.path = (this.intravoxParentPath || 'en/teamhub') + '/' + slug
                    }
                    if (!existingTeamPage.id) {
                        existingTeamPage.id = slug
                    }
                    this.teamPage = existingTeamPage

                    // 2. Fetch sub-pages via TeamHub backend (uses getPageTree in-process)
                    try {
                        const subResp = await axios.get(
                            generateUrl('/apps/teamhub/api/v1/teams/' + teamId + '/intravox/subpages')
                        )
                        this.subPages = Array.isArray(subResp.data) ? subResp.data : []
                    } catch (e) {
                        this.subPages = []
                    }
                }

                this.allPages = pages

                this.$emit('pages-loaded', {
                    teamPage:    this.teamPage,
                    subPages:    this.subPages,
                    teamhubRoot: this.teamhubRoot,
                    allPages:    this.allPages,
                })
            } catch (error) {
            } finally {
                this.loading = false
            }
        },


        /**
         * Called by TeamView after a create or delete action completes.
         * Busts the server-side sub-pages cache then re-fetches.
         */
        async refresh() {
            const teamId = this.currentTeam?.id
            if (teamId) {
                try {
                    await axios.delete(generateUrl('/apps/teamhub/api/v1/teams/' + teamId + '/intravox/subpages/cache'))
                } catch (e) { /* non-fatal */ }
            }
            await this.initDocumentationPage()
        },
    },
}
</script>

<style scoped>
.intravox-widget {
    padding: 8px 16px 12px;
}

.intravox-widget__loading {
    display: flex;
    justify-content: center;
    padding: 12px;
}

.intravox-widget__empty {
    padding: 12px 8px;
}

.intravox-widget__message {
    color: var(--color-text-maxcontrast);
    font-size: 13px;
}

.intravox-widget__content {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.intravox-page-link {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border-radius: var(--border-radius);
    text-decoration: none;
    color: var(--color-main-text);
    transition: background-color 0.2s;
}

.intravox-page-link:hover {
    background-color: var(--color-background-hover);
}

/* No background by default — highlight on hover only */
.intravox-page-link--main {
    font-weight: 600;
}

.intravox-page-link--main:hover {
    background-color: var(--color-background-hover);
}

.intravox-page-link--sub {
    font-size: 13px;
    padding-left: 24px;
}

.intravox-page-title {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.intravox-page-icon {
    opacity: 0.5;
    flex-shrink: 0;
}

.intravox-page-link:hover .intravox-page-icon {
    opacity: 1;
}

.intravox-subpages {
    display: flex;
    flex-direction: column;
    gap: 2px;
    margin-top: 4px;
}
</style>
