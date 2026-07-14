<template>
    <div class="th-widget">
        <!-- Loading state -->
        <div v-if="loading" class="th-widget__state">
            <span class="th-widget__spinner" aria-hidden="true" />
            <span class="th-widget__state-text">{{ t('teamhub', 'Loading…') }}</span>
        </div>

        <!-- Toggle is on but nothing has been shared yet -->
        <div v-else-if="items.length === 0" class="th-widget__state th-widget__state--empty">
            <ShareVariantIcon :size="18" aria-hidden="true" />
            <span class="th-widget__state-text">{{ t('teamhub', 'Nothing shared with this team yet') }}</span>
        </div>

        <!-- Item list -->
        <template v-else>
            <ul class="th-widget__rows">
                <li
                    v-for="item in items"
                    :key="item.id"
                    class="th-widget__row">

                    <!-- Type icon badge -->
                    <div class="th-files-shared__badge" aria-hidden="true">
                        <component :is="itemIcon(item)" :size="18" />
                    </div>

                    <!-- Main content -->
                    <div class="th-files-shared__body">
                        <a
                            :href="itemUrl(item)"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="th-files-shared__title"
                            :title="item.name"
                            @click="onOpen($event, item)">
                            {{ item.name }}
                        </a>
                        <div class="th-files-shared__meta">
                            <NcAvatar
                                :user="item.shared_by_id"
                                :display-name="item.shared_by"
                                :show-user-status="false"
                                :size="16"
                                class="th-files-shared__avatar" />
                            <span>{{ item.shared_by }}</span>
                            <span class="th-files-shared__meta-sep">{{ formatDate(item.shared_at) }}</span>
                        </div>
                    </div>
                </li>
            </ul>

            <!-- Pagination footer
                 v3.100.15: prev/next NcButtons — icon-only tertiary variant;
                 retires the custom .th-files-shared__page-btn CSS. -->
            <div v-if="totalPages > 1" class="th-files-shared__pagination">
                <NcButton
                    variant="tertiary"
                    :disabled="page === 1"
                    :aria-label="t('teamhub', 'Previous page')"
                    @click="goToPage(page - 1)">
                    <template #icon>
                        <ChevronLeftIcon :size="16" />
                    </template>
                </NcButton>
                <span class="th-files-shared__page-info">
                    {{ page }} / {{ totalPages }}
                </span>
                <NcButton
                    variant="tertiary"
                    :disabled="page === totalPages"
                    :aria-label="t('teamhub', 'Next page')"
                    @click="goToPage(page + 1)">
                    <template #icon>
                        <ChevronRightIcon :size="16" />
                    </template>
                </NcButton>
            </div>
        </template>
    </div>
</template>

<script>
import { mapState } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcLoadingIcon, NcAvatar, NcButton } from '@nextcloud/vue'

// Icons

import ShareVariantIcon     from 'vue-material-design-icons/ShareVariant.vue'
import FolderIcon           from 'vue-material-design-icons/Folder.vue'
import FileIcon             from 'vue-material-design-icons/File.vue'
import FileImageIcon        from 'vue-material-design-icons/FileImage.vue'
import FilePdfBoxIcon       from 'vue-material-design-icons/FilePdfBox.vue'
import FileWordIcon         from 'vue-material-design-icons/FileWord.vue'
import FileExcelIcon        from 'vue-material-design-icons/FileExcel.vue'
import FilePowerpointIcon   from 'vue-material-design-icons/FilePowerpoint.vue'
import FileVideoIcon        from 'vue-material-design-icons/FileVideo.vue'
import FileMusicIcon        from 'vue-material-design-icons/FileMusic.vue'
import FileCodeIcon         from 'vue-material-design-icons/FileCode.vue'
import FileDocumentIcon     from 'vue-material-design-icons/FileDocument.vue'
import NoteTextIcon         from 'vue-material-design-icons/NoteText.vue'
import ChevronLeftIcon      from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRightIcon     from 'vue-material-design-icons/ChevronRight.vue'

const LIMIT = 10

export default {
    name: 'FilesSharedWidget',

    components: {
        NcLoadingIcon, NcAvatar, NcButton,
        ShareVariantIcon, FolderIcon, FileIcon,
        FileImageIcon, FilePdfBoxIcon, FileWordIcon, FileExcelIcon,
        FilePowerpointIcon, FileVideoIcon, FileMusicIcon, FileCodeIcon,
        FileDocumentIcon, NoteTextIcon, ChevronLeftIcon, ChevronRightIcon,
    },

    data() {
        return {
            loading: false,
            items: [],
            total: 0,
            page: 1,
        }
    },

    computed: {
        ...mapState(['currentTeamId']),

        totalPages() {
            return Math.max(1, Math.ceil(this.total / LIMIT))
        },
    },

    watch: {
        currentTeamId: { immediate: true, handler() { this.page = 1; this.loadItems() } },
    },

    methods: {
        t,

        async loadItems() {
            if (!this.currentTeamId) return
            this.loading = true
            this.items = []
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/files/shared`),
                    { params: { page: this.page, limit: LIMIT } },
                )
                this.items = Array.isArray(data.items) ? data.items : []
                this.total = typeof data.total === 'number' ? data.total : 0
            } catch (e) {
                this.items = []
                this.total = 0
            } finally {
                this.loading = false
            }
        },

        goToPage(p) {
            if (p < 1 || p > this.totalPages) return
            this.page = p
            this.loadItems()
        },

        /**
         * Open the file/folder in NC Files via the /f/{id} shortlink.
         * NC resolves the correct viewer or folder based on node type and mimetype.
         */
        itemUrl(item) {
            return generateUrl(`/f/${item.id}`)
        },

        /**
         * Open the file inside TeamHub's files-view iframe instead of a new tab.
         * Modified clicks (ctrl/cmd/shift/middle) fall through to the native
         * target="_blank" so power users can still open a real new tab.
         */
        onOpen(event, item) {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) {
                return
            }
            event.preventDefault()
            this.$store.dispatch('openFileInEmbed', this.itemUrl(item))
        },

        formatDate(stime) {
            if (!stime) return ''
            const d = new Date(stime * 1000)
            const now = new Date()
            const diffDays = Math.floor((now - d) / 86400000)
            if (diffDays === 0) return t('teamhub', 'Today')
            if (diffDays === 1) return t('teamhub', 'Yesterday')
            return d.toLocaleDateString([], { month: 'short', day: 'numeric' })
        },

        /**
         * Map item type, mimetype and extension to a display icon.
         * Nextcloud Notes files are .md — give them a dedicated note icon.
         */
        itemIcon(item) {
            if (item.item_type === 'folder') return 'FolderIcon'

            const mime = (item.mimetype || '').toLowerCase()
            const ext  = (item.extension || '').toLowerCase()

            // Nextcloud Notes stores notes as .md files
            if (ext === 'md' || ext === 'markdown')                return 'NoteTextIcon'

            if (mime.startsWith('image/'))                         return 'FileImageIcon'
            if (mime.startsWith('video/'))                         return 'FileVideoIcon'
            if (mime.startsWith('audio/'))                         return 'FileMusicIcon'
            if (mime === 'application/pdf')                        return 'FilePdfBoxIcon'
            if (['doc', 'docx', 'odt'].includes(ext))             return 'FileWordIcon'
            if (['xls', 'xlsx', 'ods'].includes(ext))             return 'FileExcelIcon'
            if (['ppt', 'pptx', 'odp'].includes(ext))             return 'FilePowerpointIcon'
            if (['js', 'ts', 'py', 'php', 'html', 'css',
                 'json', 'xml', 'sh'].includes(ext))              return 'FileCodeIcon'
            if (ext === 'txt')                                     return 'FileDocumentIcon'
            return 'FileIcon'
        },
    },
}
</script>

<style scoped>
/* Widget-specific only — shared classes from widget-tokens.css */

.th-files-shared__badge {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 38px;
    height: 38px;
    border-radius: var(--border-radius-large);
    background: var(--color-background-dark);
    border: 1px solid var(--color-border);
    color: var(--color-primary-element);
}

.th-files-shared__body {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.th-files-shared__title {
    font-size: var(--th-widget-row-primary-size);
    font-weight: var(--th-widget-row-primary-weight);
    color: var(--color-main-text);
    text-decoration: none;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.th-files-shared__title:hover {
    color: var(--color-primary-element);
    text-decoration: underline;
}

.th-files-shared__meta {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: var(--th-widget-row-meta-size);
    font-weight: var(--th-widget-row-meta-weight);
    color: var(--th-widget-meta-color);
    flex-wrap: wrap;
}
.th-files-shared__avatar { flex-shrink: 0; }

.th-files-shared__meta-sep::before {
    content: '·';
    margin-right: 4px;
    color: var(--color-border-dark);
}

/* Pagination footer */
.th-files-shared__pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 8px 14px;
    border-top: 1px solid var(--color-border);
}
/* v3.100.15: .th-files-shared__page-btn block retired — the two prev/next
   raw <button>s are now NcButtons. */
.th-files-shared__page-info {
    font-size: var(--th-widget-row-meta-size);
    color: var(--th-widget-meta-color);
    min-width: 40px;
    text-align: center;
}
</style>
