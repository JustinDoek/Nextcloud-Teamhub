<template>
    <div class="th-shared">
        <div v-if="loading" class="th-shared__loading">
            <NcLoadingIcon :size="ICON_BODY" :name="t('teamhub', 'Loading')" />
        </div>

        <NcNoteCard v-else-if="error" type="error">
            <p class="th-shared__note">{{ t('teamhub', 'Could not load what is shared with this team.') }}</p>
            <NcButton variant="secondary" @click="load">
                {{ t('teamhub', 'Retry') }}
            </NcButton>
        </NcNoteCard>

        <p v-else-if="!available" class="th-shared__empty">
            {{ t('teamhub', 'This Nextcloud cannot list what is shared with teams.') }}
        </p>

        <template v-else>
            <p v-if="!providers.length && !failed.length" class="th-shared__empty">
                {{ t('teamhub', 'Nothing else is shared with this team.') }}
            </p>

            <section
                v-for="group in providers"
                :key="group.id"
                class="th-shared__group">
                <h3 class="th-shared__app">
                    <img
                        v-if="iconSrc(group.icon)"
                        :src="iconSrc(group.icon)"
                        alt=""
                        class="th-shared__app-icon">
                    <span class="th-shared__app-name">{{ group.name }}</span>
                    <span class="th-shared__count">{{ group.resources.length }}</span>
                </h3>
                <ul class="th-shared__list">
                    <li
                        v-for="item in visibleResources(group)"
                        :key="item.id"
                        class="th-shared__item">
                        <a
                            :href="item.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="th-shared__link">
                            <span v-if="item.icon && item.icon.type === 'emoji'" class="th-shared__emoji" aria-hidden="true">{{ item.icon.value }}</span>
                            <img
                                v-else-if="iconSrc(item.icon)"
                                :src="iconSrc(item.icon)"
                                alt=""
                                class="th-shared__item-icon">
                            <LinkVariant v-else :size="ICON_INLINE" class="th-shared__fallback-icon" />
                            <span class="th-shared__label">{{ item.label }}</span>
                            <OpenInNew
                                :size="ICON_INLINE"
                                class="th-shared__external"
                                :title="t('teamhub', 'Opens in a new tab')" />
                        </a>
                    </li>
                </ul>
                <NcButton
                    v-if="group.resources.length > COLLAPSED_COUNT"
                    variant="tertiary"
                    size="small"
                    :aria-expanded="String(!!expanded[group.id])"
                    @click="toggle(group.id)">
                    <!-- TRANSLATORS: button under a short list of items one app shared with the team; %n is how many more items are hidden -->
                    {{ expanded[group.id] ? t('teamhub', 'Show fewer') : n('teamhub', 'Show %n more', 'Show %n more', group.resources.length - COLLAPSED_COUNT, { n: group.resources.length - COLLAPSED_COUNT }) }}
                </NcButton>
            </section>

            <NcNoteCard v-if="failed.length" type="warning">
                <!-- TRANSLATORS: {apps} is a list of one or more app names, for example "Polls and Tables", that did not answer when asked what they share with the team -->
                <p class="th-shared__note">{{ t('teamhub', 'No answer from {apps}. This list may be incomplete.', { apps: failedNames }) }}</p>
            </NcNoteCard>
        </template>
    </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n, getLanguage } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { mapState } from 'vuex'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import { ICON_BODY, ICON_INLINE } from '../constants/uiTokens.js'

/**
 * Shared with team (v4.20.39): what other apps registered for this team
 * through Nextcloud's Teams API, grouped by app.
 *
 * Fetched by the card itself, after the grid has drawn, from its own
 * read-only endpoint: a slow third-party provider delays this card and
 * nothing else. The server leaves out TeamHub's own provider and every
 * resource TeamHub already shows in another card; a provider that failed
 * arrives in `failed` and is named in a warning, so a missing app never
 * looks like an app with nothing shared.
 *
 * Icons come from other apps. An SVG is drawn as an `<img>` data URI, never
 * inlined, because an SVG inside `<img>` cannot run script; URLs were
 * checked on the server (SharedTeamResourceService::safeUrl()).
 *
 * Every link opens in a new tab (v4.20.47), so opening a record in another
 * app never takes the person away from the team. The icon says so on each
 * row, because a link that opens a tab unannounced is a surprise (WCAG G201).
 */
const COLLAPSED_COUNT = 3

export default {
    name: 'SharedWithTeamWidget',
    components: { NcButton, NcLoadingIcon, NcNoteCard, LinkVariant, OpenInNew },
    data() {
        return {
            ICON_BODY,
            ICON_INLINE,
            COLLAPSED_COUNT,
            loading: false,
            error: false,
            available: true,
            providers: [],
            failed: [],
            expanded: {},
        }
    },
    computed: {
        ...mapState(['currentTeamId']),
        failedNames() {
            const names = this.failed.map(f => f.name)
            try {
                return new Intl.ListFormat(getLanguage(), { style: 'long', type: 'conjunction' }).format(names)
            } catch (e) {
                return names.join(', ')
            }
        },
    },
    watch: {
        currentTeamId: { immediate: true, handler() { this.load() } },
    },
    methods: {
        t,
        n,
        async load() {
            const teamId = this.currentTeamId
            this.providers = []
            this.failed = []
            this.expanded = {}
            this.error = false
            this.available = true
            if (!teamId) return
            this.loading = true
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/teams/{teamId}/shared-resources', { teamId }))
                if (teamId !== this.currentTeamId) return
                this.available = data?.available !== false
                this.providers = Array.isArray(data?.providers) ? data.providers : []
                this.failed = Array.isArray(data?.failed) ? data.failed : []
                console.log('[TeamHub][SharedWithTeamWidget] loaded:', { providers: this.providers.length, failed: this.failed.map(f => f.id) })
            } catch (e) {
                if (teamId === this.currentTeamId) this.error = true
            } finally {
                if (teamId === this.currentTeamId) this.loading = false
            }
        },
        visibleResources(group) {
            return this.expanded[group.id] ? group.resources : group.resources.slice(0, COLLAPSED_COUNT)
        },
        toggle(id) {
            this.expanded = { ...this.expanded, [id]: !this.expanded[id] }
        },
        iconSrc(icon) {
            if (!icon) return null
            if (icon.type === 'url') return icon.value
            if (icon.type === 'svg') {
                const bytes = new TextEncoder().encode(icon.value)
                let binary = ''
                bytes.forEach(b => { binary += String.fromCharCode(b) })
                return 'data:image/svg+xml;base64,' + window.btoa(binary)
            }
            return null
        },
    },
}
</script>

<style scoped>
.th-shared {
    padding: var(--th-space-sm) var(--th-space-md);
}
.th-shared__loading {
    display: flex;
    justify-content: center;
    padding: var(--th-space-md);
}
.th-shared__empty {
    margin: 0;
    color: var(--color-text-maxcontrast);
}
.th-shared__note {
    margin: 0 0 var(--th-space-sm);
}
.th-shared__group + .th-shared__group {
    margin-block-start: var(--th-space-md);
}
.th-shared__app {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
    margin: 0 0 var(--th-space-xs);
    font-size: var(--th-font-meta);
    font-weight: var(--th-font-weight-regular);
    color: var(--color-text-maxcontrast);
}
.th-shared__app-icon,
.th-shared__item-icon {
    width: var(--th-icon-inline);
    height: var(--th-icon-inline);
    flex: 0 0 auto;
    object-fit: contain;
    /* Single-colour app glyphs are drawn in black inside an <img>. */
    filter: var(--background-invert-if-dark);
}
.th-shared__app-name {
    flex: 1;
    min-width: 0;
    overflow-wrap: anywhere;
}
.th-shared__list {
    list-style: none;
    margin: 0;
    padding: 0;
}
.th-shared__link {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
    padding: var(--th-space-xs) var(--th-space-sm);
    border-radius: var(--border-radius-element);
    color: var(--color-main-text);
    text-decoration: none;
}
.th-shared__link:hover {
    background: var(--color-background-hover);
}
.th-shared__link:focus-visible {
    background: var(--color-background-hover);
    outline: 2px solid var(--color-primary-element);
}
.th-shared__emoji {
    width: var(--th-icon-inline);
    text-align: center;
    flex: 0 0 auto;
}
.th-shared__fallback-icon,
.th-shared__external {
    color: var(--color-text-maxcontrast);
    flex: 0 0 auto;
}
.th-shared__label {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
</style>
