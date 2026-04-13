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
        ...mapState(['members', 'currentUser']),
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

        toSlug(text) {
            return (text || '')
                .toLowerCase()
                .replace(/[^a-z0-9\s-]/g, '')
                .trim()
                .replace(/\s+/g, '-')
                .replace(/-+/g, '-') || 'team-page'
        },

        async initDocumentationPage() {
            this.loading = true
            this.error = null
            try {
                const response = await axios.get(generateUrl('/apps/intravox/api/pages'))
                if (!response.data || !Array.isArray(response.data)) return

                const pages = response.data

                // Find TeamHub root — prefer exact id="teamhub", fall back to title match
                const rootCandidates = pages.filter(p => p.title?.toLowerCase() === 'teamhub')
                this.teamhubRoot = rootCandidates.find(p => p.id === 'teamhub') || rootCandidates[0] || null

                // Find the team page: title matches team name, not a template
                const existingTeamPage = pages.find(p =>
                    p.title?.toLowerCase() === this.teamName.toLowerCase() &&
                    p.uniqueId !== this.teamhubRoot?.uniqueId &&
                    !p.uniqueId?.startsWith('template-')
                ) || null

                this.allPages = pages

                if (existingTeamPage) {
                    this.teamPage = existingTeamPage
                    // Collect ALL pages that are descendants of the team page.
                    // IntraVox pages have a `parentId` field that references the parent's uniqueId.
                    // We do a breadth-first expansion: start with the team page's direct children,
                    // then their children, etc. — so any depth of subpage shows up in the widget.
                    this.subPages = this.collectDescendants(pages, existingTeamPage)
                } else if (this.canCreatePage) {
                    // Auto-create for admins/owners — no button needed
                    await this.createDocumentationPage(pages)
                }
                // Non-admins with no page: widget stays empty silently

                // Emit current page state so TeamView can power the action menu
                this.$emit('pages-loaded', {
                    teamPage:    this.teamPage,
                    subPages:    this.subPages,
                    teamhubRoot: this.teamhubRoot,
                    allPages:    this.allPages,
                })
            } catch (error) {
                // Silently fail — Intravox may not be installed
            } finally {
                this.loading = false
            }
        },

        /**
         * Return all pages that are descendants of `root` (any depth).
         * Tries parentId match first, then falls back to path/slug containment.
         */
        collectDescendants(allPages, root) {
            const teamUniqueId = root.uniqueId

            console.log('[TeamHub][IntravoxWidget] collectDescendants: starting BFS from', teamUniqueId)

            // BFS from the team page, matching strictly on parentId.
            // The previous implementation had two additional fallbacks (path-contains
            // and id-prefix) that could pull in pages from other teams when the slug
            // happened to be a prefix of another page id. Now that pages are always
            // created with the correct parentPath (inside the team page folder),
            // parentId is reliably populated — the fallbacks are not needed.
            const result = []
            const visited = new Set([teamUniqueId])
            const queue = [teamUniqueId]

            while (queue.length) {
                const parentUniqueId = queue.shift()
                for (const p of allPages) {
                    if (visited.has(p.uniqueId)) continue

                    if (p.parentId === parentUniqueId) {
                        console.log('[TeamHub][IntravoxWidget] collectDescendants: found child', p.title, 'parentId', p.parentId)
                        visited.add(p.uniqueId)
                        result.push(p)
                        queue.push(p.uniqueId)
                    }
                }
            }

            console.log('[TeamHub][IntravoxWidget] collectDescendants: total descendants found', result.length)
            return result
        },

        async createDocumentationPage(pages) {
            try {
                // Step 1: Ensure TeamHub root exists
                if (!this.teamhubRoot) {
                    // Detect language from existing pages, fall back to 'nl'
                    const langCounts = {}
                    for (const p of pages) {
                        if (p.language) langCounts[p.language] = (langCounts[p.language] || 0) + 1
                    }
                    const lang = Object.keys(langCounts).length
                        ? Object.entries(langCounts).sort((a, b) => b[1] - a[1])[0][0]
                        : 'nl'

                    const rootResp = await axios.post(generateUrl('/apps/intravox/api/pages'), {
                        id: 'teamhub',
                        title: 'TeamHub',
                        language: lang,
                    })
                    this.teamhubRoot = rootResp.data
                }

                // parentPath places the team page inside the root folder:
                // e.g. "nl/teamhub" → intravox/nl/teamhub/sales-team/
                const rootLang = this.teamhubRoot.language || 'nl'
                const rootId = this.teamhubRoot.id || 'teamhub'
                const parentPath = `${rootLang}/${rootId}`

                // Step 2: Create the team page nested under TeamHub root
                const teamResp = await axios.post(generateUrl('/apps/intravox/api/pages'), {
                    id: this.toSlug(this.teamName),
                    title: this.teamName,
                    language: rootLang,
                    parentPath,
                })
                this.teamPage = teamResp.data

            } catch (e) {
                if (e.response?.status !== 404) {
                    // 404 = Intravox not installed, stay silent
                    const msg = e.response?.data?.message || e.response?.data?.error
                    this.error = msg
                        ? t('teamhub', 'Failed to create page: {error}', { error: msg })
                        : t('teamhub', 'Failed to create documentation page')
                    showError(this.error)
                }
            }
        },

        /**
         * Called by TeamView after a create or delete action completes.
         * Re-fetches all pages from Intravox and re-emits pages-loaded.
         */
        async refresh() {
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
