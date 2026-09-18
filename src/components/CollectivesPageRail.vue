<template>
    <div class="th-cpr">
        <!-- Collective header. Clicking it goes back to the landing page,
             which is the only page the tree cannot show as a row (it is the
             root every other page hangs off). -->
        <div class="th-cpr__head">
            <!-- Raw <button>: a full-width card row with its own chrome, per
                 SKILLS.md § "NcButton is the default; custom <button> needs a
                 reason". NcButton would impose its own padding and 44px floor
                 on a row that has to line up with the tree beneath it. -->
            <button
                class="th-cpr__row th-cpr__row--root"
                :class="{ 'th-cpr__row--active': isActive(rootUrl) }"
                :aria-current="isActive(rootUrl) ? 'page' : undefined"
                @click="open(rootUrl)">
                <span v-if="collective && collective.emoji" class="th-cpr__emoji" aria-hidden="true">{{ collective.emoji }}</span>
                <BookOpenOutline v-else :size="iconBody" aria-hidden="true" />
                <span class="th-cpr__title">{{ collectiveName }}</span>
            </button>

            <NcButton
                variant="tertiary"
                :aria-label="t('teamhub', 'New page')"
                :title="t('teamhub', 'New page')"
                @click="$emit('create')">
                <template #icon><Plus :size="iconBody" /></template>
            </NcButton>
            <NcButton
                variant="tertiary"
                :aria-label="t('teamhub', 'Refresh page list')"
                :title="t('teamhub', 'Refresh page list')"
                @click="refresh">
                <template #icon><Refresh :size="iconBody" /></template>
            </NcButton>
        </div>

        <div class="th-cpr__filter">
            <!-- Bare on purpose. NcTextField's trailing-button props are
                 declared on NcInputField underneath rather than on the
                 wrapper, so whether the clear button's click reaches us is
                 a fall-through question this has not verified — and a clear
                 button is not worth guessing an NC prop contract for
                 (SKILLS.md § NC component uncertainty rule). Emptying the
                 field by hand restores the full tree. -->
            <NcTextField
                v-model="filter"
                :label="t('teamhub', 'Search pages')" />
        </div>

        <div v-if="loading" class="th-cpr__state">
            <NcLoadingIcon :size="iconNav" />
        </div>

        <p v-else-if="error" class="th-cpr__state th-cpr__state--error">
            {{ t('teamhub', 'Could not load the page list') }}
        </p>

        <p v-else-if="rows.length === 0 && filter" class="th-cpr__state">
            {{ t('teamhub', 'No matching pages') }}
        </p>

        <p v-else-if="rows.length === 0" class="th-cpr__state">
            {{ t('teamhub', 'No pages yet') }}
        </p>

        <!-- Deliberately a plain list rather than role="tree" (v4.8.8).
             role="tree" is a promise of the full treeview keyboard contract —
             arrow-key roving focus, Home/End, type-ahead — and claiming it
             without implementing it is worse for a screen-reader user than
             not claiming it. As a list of buttons every row is in the normal
             tab order, the disclosure state is on aria-expanded, and the page
             being shown carries aria-current. -->
        <ul v-else class="th-cpr__list">
            <li v-for="row in rows" :key="row.page.id" class="th-cpr__item">
                <div class="th-cpr__line" :style="{ paddingInlineStart: indentFor(row.depth) }">
                    <!-- Twisty and row are separate controls on purpose: one
                         opens the page, the other only reveals its children. -->
                    <button
                        v-if="row.hasChildren"
                        class="th-cpr__twisty"
                        :aria-expanded="row.expanded ? 'true' : 'false'"
                        :aria-label="expandLabel(row)"
                        @click="toggle(row.page.id)">
                        <ChevronDown v-if="row.expanded" :size="iconInline" aria-hidden="true" />
                        <ChevronRight v-else :size="iconInline" aria-hidden="true" />
                    </button>
                    <span v-else class="th-cpr__twisty th-cpr__twisty--empty" aria-hidden="true" />

                    <!-- Raw <button>: same card-row carve-out as the header. -->
                    <button
                        class="th-cpr__row"
                        :class="{ 'th-cpr__row--active': isActive(row.page.url) }"
                        :aria-current="isActive(row.page.url) ? 'page' : undefined"
                        @click="open(row.page.url)">
                        <span v-if="row.page.emoji" class="th-cpr__emoji" aria-hidden="true">{{ row.page.emoji }}</span>
                        <FileDocumentOutline v-else :size="iconBody" aria-hidden="true" />
                        <span class="th-cpr__title">{{ titleOf(row.page) }}</span>
                    </button>
                </div>
            </li>
        </ul>

        <p v-if="truncated" class="th-cpr__state th-cpr__state--note">
            {{ n('teamhub', 'Only the first %n page is listed', 'Only the first %n pages are listed', maxPages, { n: maxPages }) }}
        </p>
    </div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import BookOpenOutline from 'vue-material-design-icons/BookOpenOutline.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import { ICON_INLINE, ICON_BODY, ICON_NAV } from '../constants/uiTokens.js'

/**
 * Collectives page rail (v4.8.8).
 *
 * The page navigation for the team's collective, drawn by TeamHub beside the
 * Collectives iframe. It exists because the embed strips NC's app navigation
 * (AppEmbed's chrome-stripping CSS) and in Collectives that navigation *is*
 * the page tree — so the tab used to show a page with no way to reach any
 * other one.
 *
 * The tree comes from `GET /collectives/page-tree`, which mirrors Collectives'
 * own ordering; see `CollectivesService::getPageTree()` for how parentage and
 * `subpageOrder` are read.
 *
 * Clicking a row does not navigate this window. It emits `open` with the
 * page's app-relative URL; TeamView commits it to the store and the iframe
 * routes there in place (AppEmbed::tryInPlaceNavigation), so following the
 * tree costs no app reboot.
 *
 * Staleness: pages created or deleted inside the iframe are invisible to us
 * until something refetches. The Refresh button is the explicit answer; the
 * `activeUrl` watcher is the implicit one — landing on a page the tree has
 * never heard of refetches once, which covers "created a page in Collectives
 * and it is not in the list".
 */
export default {
    name: 'CollectivesPageRail',

    components: {
        NcButton, NcLoadingIcon, NcTextField,
        BookOpenOutline, ChevronDown, ChevronRight, FileDocumentOutline, Plus, Refresh,
    },

    props: {
        teamId: { type: String, required: true },
        /**
         * App-relative URL the iframe is currently showing, as AppEmbed
         * reports it. Empty until the frame has loaded once — the rail then
         * highlights nothing, which is correct rather than guessing.
         */
        activeUrl: { type: String, default: '' },
    },

    emits: ['open', 'create'],

    data() {
        return {
            collective: null,
            root: null,
            truncated: false,
            maxPages: 0,
            loading: true,
            error: false,
            filter: '',
            /** Page ids whose children are shown. Top-level rows are always shown. */
            expandedIds: [],
            /** Guards the auto-refetch below against firing twice for one URL. */
            unknownUrlSeen: '',
            iconInline: ICON_INLINE,
            iconBody: ICON_BODY,
            iconNav: ICON_NAV,
        }
    },

    computed: {
        rootUrl() {
            return this.root?.url || ''
        },

        collectiveName() {
            return this.collective?.name || this.root?.title || t('teamhub', 'Collectives')
        },

        /**
         * The tree flattened to the rows actually on screen, each carrying its
         * depth so the template can indent it.
         *
         * Filtering deliberately drops the hierarchy: a search result list
         * that keeps its indentation reads as a tree with holes in it, and the
         * depth of a match is not what the user is looking at.
         */
        rows() {
            const needle = this.filter.trim().toLowerCase()
            if (needle) {
                const flat = []
                const walk = (nodes) => {
                    for (const page of nodes) {
                        if (this.titleOf(page).toLowerCase().includes(needle)) {
                            flat.push({ page, depth: 0, hasChildren: false, expanded: false })
                        }
                        walk(page.children || [])
                    }
                }
                walk(this.root?.children || [])
                return flat
            }

            const out = []
            const walk = (nodes, depth) => {
                for (const page of nodes) {
                    const children = page.children || []
                    const expanded = this.expandedIds.includes(page.id)
                    out.push({ page, depth, hasChildren: children.length > 0, expanded })
                    if (expanded) {
                        walk(children, depth + 1)
                    }
                }
            }
            walk(this.root?.children || [], 0)
            return out
        },

        /** page id → [ancestor ids], for auto-expanding the path to a page. */
        ancestorsById() {
            const map = {}
            const walk = (nodes, trail) => {
                for (const page of nodes) {
                    map[page.id] = trail
                    walk(page.children || [], trail.concat(page.id))
                }
            }
            walk(this.root?.children || [], [])
            return map
        },
    },

    watch: {
        teamId() {
            this.filter = ''
            this.expandedIds = []
            this.load()
        },

        activeUrl(url) {
            this.revealActive(url)
        },
    },

    mounted() {
        this.load()
    },

    methods: {
        t,
        n,

        /**
         * Label for the disclosure control. Built here rather than inline so
         * the TRANSLATORS hints sit next to the strings they explain.
         */
        expandLabel(row) {
            return row.expanded
                // TRANSLATORS: label on the control that hides the subpages listed under a page; {title} is the page name
                ? t('teamhub', 'Collapse {title}', { title: this.titleOf(row.page) })
                // TRANSLATORS: label on the control that reveals the subpages listed under a page; {title} is the page name
                : t('teamhub', 'Expand {title}', { title: this.titleOf(row.page) })
        },

        titleOf(page) {
            // A page whose title did not survive the read still needs a row —
            // dropping it would drop its children with it.
            return page.title || t('teamhub', 'Untitled')
        },

        indentFor(depth) {
            return (depth * 16) + 'px'
        },

        toggle(id) {
            const at = this.expandedIds.indexOf(id)
            if (at === -1) {
                this.expandedIds.push(id)
            } else {
                this.expandedIds.splice(at, 1)
            }
        },

        open(url) {
            if (url) {
                this.$emit('open', url)
            }
        },

        /**
         * Every form a page URL can be compared on.
         *
         * Collectives addresses a page two different ways depending on whether
         * it has a slug: without one, the file path plus `?fileId=123`; with
         * one, a slug path and no query at all (PageService::getPageLink). The
         * two sides of a comparison are not guaranteed to be the same form —
         * ours is built when the tree was fetched, the frame's is whatever the
         * app routed to — so returning a list and testing for any overlap is
         * what keeps the highlight when the forms diverge. Comparing one
         * canonical key would silently lose it.
         *
         * The path form is decoded and stripped back to `/apps/collectives/…`
         * because our URLs are app-relative while the frame reports a
         * server-absolute path carrying `/index.php` and any sub-path.
         *
         * @param {string} url
         * @return {string[]} comparison keys, most specific first
         */
        pageKeys(url) {
            if (!url) {
                return []
            }
            const keys = []
            try {
                const u = new URL(url, window.location.origin)
                const fileId = u.searchParams.get('fileId')
                if (fileId) {
                    keys.push('fileId:' + fileId)
                }
                const marker = '/apps/collectives/'
                const at = u.pathname.indexOf(marker)
                if (at !== -1) {
                    keys.push('path:' + decodeURIComponent(u.pathname.slice(at)).replace(/\/+$/, ''))
                }
            } catch (e) {
                // Malformed URL. Nothing to compare on, which reads as "not
                // the page being shown" — the safe answer for a highlight.
            }
            return keys
        },

        isActive(url) {
            const mine = this.pageKeys(url)
            if (mine.length === 0) {
                return false
            }
            const theirs = this.pageKeys(this.activeUrl)
            return mine.some(k => theirs.includes(k))
        },

        /**
         * Open the path down to whatever the iframe is showing, so a page
         * reached from inside the page body is visible in the rail rather than
         * hidden three collapsed levels down.
         *
         * When the page is not in the tree at all, refetch once — that is the
         * shape of "somebody added a page in Collectives". Once per URL, so a
         * page that genuinely is not ours (a link out of the collective) does
         * not put the rail in a refetch loop.
         */
        revealActive(url, allowRefetch = true) {
            const keys = this.pageKeys(url)
            if (keys.length === 0 || !this.root) {
                return
            }
            const key = keys[0]
            let found = null
            const walk = (nodes) => {
                for (const page of nodes) {
                    if (found === null && this.isActive(page.url)) {
                        found = page
                    }
                    walk(page.children || [])
                }
            }
            walk(this.root.children || [])

            if (found) {
                for (const id of this.ancestorsById[found.id] || []) {
                    if (!this.expandedIds.includes(id)) {
                        this.expandedIds.push(id)
                    }
                }
                return
            }
            if (!allowRefetch || this.isActive(this.rootUrl) || this.loading || this.unknownUrlSeen === key) {
                return
            }
            this.unknownUrlSeen = key
            this.load()
        },

        async load() {
            this.loading = true
            this.error = false
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/collectives/page-tree`),
                )
                this.collective = data?.collective || null
                this.root = data?.root || null
                this.truncated = !!data?.truncated
                this.maxPages = Number(data?.maxPages) || 0
            } catch (e) {
                this.error = true
                this.collective = null
                this.root = null
                this.truncated = false
            } finally {
                this.loading = false
            }
            // allowRefetch false: this IS the refetch. Letting the reveal
            // trigger another one would make every load that lands on an
            // unknown page cost two requests.
            this.revealActive(this.activeUrl, false)
        },

        /** Called by TeamView after a page is created, and by the toolbar. */
        refresh() {
            this.unknownUrlSeen = ''
            return this.load()
        },
    },
}
</script>

<style scoped>
.th-cpr {
    display: flex;
    flex-direction: column;
    width: 260px;
    flex: 0 0 260px;
    min-width: 0;
    height: 100%;
    overflow: hidden;
    border-inline-end: 1px solid var(--color-border);
    background-color: var(--color-main-background);
}

.th-cpr__head {
    display: flex;
    align-items: center;
    gap: 2px;
    padding: 6px 4px 6px 8px;
    border-block-end: 1px solid var(--color-border);
}

.th-cpr__filter {
    padding: 6px 8px;
}

.th-cpr__list {
    flex: 1 1 auto;
    overflow-y: auto;
    padding: 4px 4px 8px;
    margin: 0;
    list-style: none;
}

.th-cpr__line {
    display: flex;
    align-items: center;
    gap: 2px;
    min-width: 0;
}

/* Pinned on every axis for the reason SKILLS.md § "UI shapes" gives: NC's
   global button rule sets min-width AND min-height to 44px, and per spec
   min-* beats an unqualified width/height. Not a circle, so no border-radius
   lock — but the same six locks otherwise, or the twisty renders as a 44px
   block that pushes every row out. */
.th-cpr__twisty {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    box-sizing: border-box;
    width: 20px;
    height: 20px;
    min-width: 20px;
    min-height: 20px;
    max-width: 20px;
    max-height: 20px;
    padding: 0;
    border: none;
    border-radius: var(--th-radius-control);
    background-color: transparent;
    color: var(--color-text-maxcontrast);
    cursor: pointer;
}

.th-cpr__twisty:hover {
    background-color: var(--color-background-hover);
}

/* Split from :hover on purpose — grouping them under one selector is what
   silences the keyboard focus ring (SKILLS.md § Focus visibility standard). */
.th-cpr__twisty:focus-visible {
    background-color: var(--color-background-hover);
    outline: 2px solid var(--color-primary-element);
    outline-offset: -2px;
}

.th-cpr__twisty--empty {
    cursor: default;
}

.th-cpr__row {
    display: flex;
    align-items: center;
    gap: 6px;
    flex: 1 1 auto;
    min-width: 0;
    min-height: 0;
    padding: 5px 8px;
    border: none;
    border-radius: var(--th-radius-control);
    background-color: transparent;
    color: var(--color-main-text);
    font-size: var(--th-font-body);
    line-height: var(--th-line-height-body);
    text-align: start;
    cursor: pointer;
}

.th-cpr__row:hover {
    background-color: var(--color-background-hover);
}

.th-cpr__row:focus-visible {
    background-color: var(--color-background-hover);
    outline: 2px solid var(--color-primary-element);
    outline-offset: -2px;
}

/* Active state carries a weight change as well as the tint, so it is not
   colour alone (WCAG 1.4.1). aria-current says the same thing to a reader. */
.th-cpr__row--active {
    background-color: var(--color-primary-element-light);
    font-weight: var(--th-font-weight-semibold);
}

.th-cpr__row--root {
    font-weight: var(--th-font-weight-semibold);
}

.th-cpr__emoji {
    flex: 0 0 auto;
    width: var(--th-icon-body);
    text-align: center;
    line-height: 1;
}

.th-cpr__title {
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.th-cpr__state {
    padding: 12px;
    margin: 0;
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
    text-align: center;
}

.th-cpr__state--error {
    color: var(--color-error-text);
}

.th-cpr__state--note {
    border-block-start: 1px solid var(--color-border);
    text-align: start;
}

/* Below NC's own mobile breakpoint the tab is too narrow to spend 260px on a
   rail; the widget on the Home tab is the page list at that size. */
@media only screen and (max-width: 1024px) {
    .th-cpr {
        display: none;
    }
}
</style>
