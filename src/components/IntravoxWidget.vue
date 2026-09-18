<template>
    <div class="pages-widget">
        <div v-if="loading" class="pages-widget__loading">
            <NcLoadingIcon :size="20" />
        </div>

        <template v-else>
            <!-- Intranet (Intravox) section — rendered whenever the team has
                 an Intravox page. Hidden entirely when the Intranet team-app
                 is off or the team has no page yet. -->
            <div v-if="teamPage" class="pages-widget__section">
                <a :href="getIntravoxUrl(teamPage)" target="_blank" class="pages-page-link pages-page-link--main">
                    <FileDocumentOutline :size="20" />
                    <span class="pages-page-title">{{ teamPage.title }}</span>
                    <OpenInNew :size="14" class="pages-page-icon" />
                </a>
                <div v-if="subPages.length > 0" class="pages-subpages">
                    <a
                        v-for="page in subPages"
                        :key="page.uniqueId"
                        :href="getIntravoxUrl(page)"
                        target="_blank"
                        class="pages-page-link pages-page-link--sub">
                        <FileDocumentOutline :size="16" />
                        <span class="pages-page-title">{{ page.title }}</span>
                        <OpenInNew :size="12" class="pages-page-icon" />
                    </a>
                </div>
            </div>

            <!-- Wiki (Collectives) section (v4.3.7). Clicks open the
                 target INSIDE the Wiki iframe tab (v4.3.13); Ctrl/Cmd/
                 Shift/middle-click keep the native new-tab open. -->
            <div v-if="collective" class="pages-widget__section">
                <a :href="collective.url" class="pages-page-link pages-page-link--main" @click="openInWikiEmbed($event, collective.url)">
                    <span v-if="collective.emoji" class="pages-page-emoji" aria-hidden="true">{{ collective.emoji }}</span>
                    <BookOpenOutline v-else :size="20" />
                    <span class="pages-page-title">{{ collective.name }}</span>
                    <OpenInNew :size="14" class="pages-page-icon" />
                </a>
                <div v-if="collectivePages.length > 0" class="pages-subpages">
                    <a
                        v-for="page in collectivePages"
                        :key="'coll-' + page.id"
                        :href="page.url"
                        class="pages-page-link pages-page-link--sub"
                        @click="openInWikiEmbed($event, page.url)">
                        <FileDocumentOutline :size="16" />
                        <span class="pages-page-title">{{ page.title }}</span>
                        <OpenInNew :size="12" class="pages-page-icon" />
                    </a>
                </div>
            </div>

            <!-- Admin-only hint when Wiki enabled but the fetch failed. Silent
                 for non-admins so members never see plumbing errors. -->
            <div v-if="collectivesError && canAct" class="pages-widget__empty">
                <p class="pages-widget__message">{{ collectivesError }}</p>
            </div>
        </template>
    </div>
</template>

<script>
import { mapState, mapGetters } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import BookOpenOutline from 'vue-material-design-icons/BookOpenOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'

/**
 * Pages widget (formerly IntravoxWidget — filename kept so grid + mobile +
 * tablet imports stay valid without a rename churn).
 *
 * v4.3.7 — Unified surface for both the Intranet (Intravox) and Wiki
 * (Collectives) team-apps. Renders whichever section(s) are enabled and
 * have content, side by side. When both team-apps are off the widget is
 * empty and its outer card is hidden by the grid gate.
 *
 * Data flow:
 *   /api/v1/teams/{id}/intravox/team-page      → header link (Intranet section)
 *   /api/v1/teams/{id}/intravox/subpages       → subpage list
 *   /api/v1/teams/{id}/collectives/team-collective → header link (Wiki section)
 *   /api/v1/teams/{id}/collectives/subpages    → collective's top-level pages
 *
 * Emits (both kept for TeamView.vue's existing wiring):
 *   pages-loaded         — Intravox payload (parent tracks it for Files-tab deep-links etc.)
 *   collective-loaded    — Collectives payload (parent caches for the Wiki iframe tab URL)
 */
export default {
    name: 'IntravoxWidget',
    components: { NcLoadingIcon, BookOpenOutline, FileDocumentOutline, OpenInNew },
    props: {
        canAct: { type: Boolean, default: false },
    },
    emits: ['pages-loaded', 'collective-loaded'],
    data() {
        return {
            loading: true,
            // Intravox
            teamPage: null,
            subPages: [],
            allPages: [],
            teamhubRoot: null,
            // Collectives
            collective: null,
            collectivePages: [],
            collectivesError: null,
            // Legacy field kept so admins used to seeing the Intravox error
            // banner in the widget don't lose it. Currently unused because
            // the previous logic caught silently and rendered nothing.
            error: null,
        }
    },
    computed: {
        ...mapState(['members', 'currentUser', 'intravoxParentPath', 'collectivesConfig']),
        ...mapGetters(['currentTeam']),
        teamName() {
            return this.currentTeam?.name || ''
        },
    },
    mounted() {
        this.load()
    },
    methods: {
        t,

        getIntravoxUrl(page) {
            const pageId = page.uniqueId || page.id
            return generateUrl('/apps/intravox/') + '#' + pageId
        },

        async load() {
            this.loading = true
            this.error = null
            this.collectivesError = null
            try {
                const teamId = this.currentTeam?.id
                if (!teamId) return

                // Fire both providers' fetches concurrently. Independent
                // try/catches so one failure doesn't hide the other's data.
                await Promise.all([
                    this.loadIntravox(teamId),
                    this.loadCollectives(teamId),
                ])
            } finally {
                this.loading = false
            }
        },

        async loadIntravox(teamId) {
            try {
                const teamPageResp = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/teams/' + teamId + '/intravox/team-page'),
                )
                const existingTeamPage = teamPageResp.data || null
                if (existingTeamPage) {
                    this.teamPage = existingTeamPage
                    try {
                        const subResp = await axios.get(
                            generateUrl('/apps/teamhub/api/v1/teams/' + teamId + '/intravox/subpages'),
                        )
                        this.subPages = Array.isArray(subResp.data) ? subResp.data : []
                    } catch (e) {
                        this.subPages = []
                    }
                }
                this.$emit('pages-loaded', {
                    teamPage:    this.teamPage,
                    subPages:    this.subPages,
                    teamhubRoot: this.teamhubRoot,
                    allPages:    this.allPages,
                })
            } catch (e) {
                // Silent — same behaviour as before the merge.
            }
        },

        async loadCollectives(teamId) {
            // Skip the fetch entirely when Wiki is off for this team;
            // saves a round-trip on teams that never enabled it.
            if (!this.collectivesConfig?.collectives_enabled) {
                return
            }
            try {
                const collResp = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/teams/' + teamId + '/collectives/team-collective'),
                )
                this.collective = collResp.data || null
                if (this.collective) {
                    try {
                        const pagesResp = await axios.get(
                            generateUrl('/apps/teamhub/api/v1/teams/' + teamId + '/collectives/subpages'),
                        )
                        this.collectivePages = Array.isArray(pagesResp.data) ? pagesResp.data : []
                    } catch (e) {
                        this.collectivePages = []
                    }
                }
                this.$emit('collective-loaded', {
                    collective: this.collective,
                    pages:      this.collectivePages,
                })
            } catch (e) {
                // 403 (non-member) stays silent; other statuses surface to
                // admins only, in a small line below the section.
                if (e?.response?.status && e.response.status !== 403) {
                    this.collectivesError = e?.response?.data?.error || t('teamhub', 'Could not load Collectives')
                }
            }
        },

        /**
         * Open a Collectives target inside the Wiki iframe tab
         * (v4.3.13). Mirrors the FilesFavoritesWidget/RecentWidget's
         * `onOpen` pattern — modified clicks (Ctrl / Cmd / Shift / middle)
         * fall through to the browser's native new-tab open so a user
         * can still keep the widget open while inspecting a page in a
         * separate tab.
         *
         * Commits SET_COLLECTIVES_EMBED_PAGE_URL then SET_VIEW; the
         * store clears the embed URL on the next SET_VIEW away from
         * 'collectives', so a later re-open of the Wiki tab lands back
         * on the collective's landing view.
         */
        openInWikiEmbed(event, url) {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) {
                return
            }
            event.preventDefault()
            this.$store.commit('SET_COLLECTIVES_EMBED_PAGE_URL', url)
            this.$store.commit('SET_VIEW', 'collectives')
        },

        /**
         * Called by TeamView after a create or delete Intravox action so the
         * widget picks up the change without waiting for the cache to expire.
         * Also refreshes the Collectives side because the admin may have
         * flipped the Wiki toggle between paints.
         */
        async refresh() {
            const teamId = this.currentTeam?.id
            if (teamId) {
                try {
                    await axios.delete(generateUrl('/apps/teamhub/api/v1/teams/' + teamId + '/intravox/subpages/cache'))
                } catch (e) { /* non-fatal */ }
                try {
                    await axios.delete(generateUrl('/apps/teamhub/api/v1/teams/' + teamId + '/collectives/subpages/cache'))
                } catch (e) { /* non-fatal */ }
            }
            // Reset both providers' state so refresh() doesn't leak stale
            // rows if the fetch fails.
            this.teamPage = null
            this.subPages = []
            this.collective = null
            this.collectivePages = []
            await this.load()
        },
    },
}
</script>

<style scoped>
.pages-widget {
    padding: 8px 16px 12px;
}

.pages-widget__loading {
    display: flex;
    justify-content: center;
    padding: 12px;
}

.pages-widget__empty {
    padding: 12px 8px;
}

.pages-widget__message {
    color: var(--color-text-maxcontrast);
    font-size: 13px;
}

.pages-widget__section {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

/* Extra spacing between the Intranet and Wiki sections when both render. */
.pages-widget__section + .pages-widget__section {
    margin-top: 12px;
}

.pages-page-link {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border-radius: var(--border-radius);
    text-decoration: none;
    color: var(--color-main-text);
    transition: background-color 0.2s;
}

.pages-page-link:hover {
    background-color: var(--color-background-hover);
}

.pages-page-link--main {
    font-weight: 600;
}

.pages-page-link--sub {
    font-size: 13px;
    padding-left: 24px;
}

.pages-page-emoji {
    font-size: 18px;
    line-height: 1;
    width: 20px;
    text-align: center;
    flex-shrink: 0;
}

.pages-page-title {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.pages-page-icon {
    opacity: 0.5;
    flex-shrink: 0;
}

.pages-page-link:hover .pages-page-icon {
    opacity: 1;
}

.pages-subpages {
    display: flex;
    flex-direction: column;
    gap: 2px;
    margin-top: 4px;
}
</style>
