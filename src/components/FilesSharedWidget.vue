<template>
    <div class="th-widget">
        <!-- Loading state -->
        <div v-if="loading" class="th-widget__state">
            <NcLoadingIcon :size="20" />
        </div>

        <!-- Toggle is off — team owner has not enabled this widget -->
        <div v-else-if="!resources.shared_files" class="th-widget__state">
            <FolderAccountIcon :size="36" class="th-widget__empty-icon" />
            <span>{{ t('teamhub', 'Not enabled by the team owner') }}</span>
        </div>

        <!-- Toggle is on but nothing has been shared yet -->
        <div v-else-if="items.length === 0" class="th-widget__state">
            <ShareVariantIcon :size="36" class="th-widget__empty-icon" />
            <span>{{ t('teamhub', 'Nothing shared with this team yet') }}</span>
        </div>

        <!-- Item list -->
        <template v-else>
            <ul class="th-widget__list">
                <li
                    v-for="item in items"
                    :key="item.id"
                    class="th-widget__row">

                    <!-- Type icon badge -->
                    <div class="th-widget__badge" aria-hidden="true">
                        <component :is="itemIcon(item)" :size="18" />
                    </div>

                    <!-- Main content -->
                    <div class="th-widget__body">
                        <div class="th-widget__row-top">
                            <a
                                :href="itemUrl(item)"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="th-widget__title th-widget__title--link"
                                :title="item.name">
                                {{ item.name }}
                            </a>
                        </div>
                        <div class="th-widget__row-bottom">
                            <NcAvatar
                                :user="item.shared_by_id"
                                :display-name="item.shared_by"
                                :show-user-status="false"
                                :size="16"
                                class="th-widget__avatar" />
                            <span class="th-widget__meta">{{ item.shared_by }}</span>
                            <span class="th-widget__meta th-widget__meta--sep">{{ formatDate(item.shared_at) }}</span>
                        </div>
                    </div>
                </li>
            </ul>

            <!-- Pagination footer -->
            <div v-if="totalPages > 1" class="th-widget__pagination">
                <button
                    class="th-widget__page-btn"
                    :disabled="page === 1"
                    :aria-label="t('teamhub', 'Previous page')"
                    @click="goToPage(page - 1)">
                    <ChevronLeftIcon :size="16" />
                </button>
                <span class="th-widget__page-info">
                    {{ page }} / {{ totalPages }}
                </span>
                <button
                    class="th-widget__page-btn"
                    :disabled="page === totalPages"
                    :aria-label="t('teamhub', 'Next page')"
                    @click="goToPage(page + 1)">
                    <ChevronRightIcon :size="16" />
                </button>
            </div>
        </template>
    </div>
</template>

<script>
import { mapState } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcLoadingIcon, NcAvatar } from '@nextcloud/vue'

// Icons
import FolderAccountIcon    from 'vue-material-design-icons/FolderAccount.vue'
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
        NcLoadingIcon, NcAvatar,
        FolderAccountIcon, ShareVariantIcon, FolderIcon, FileIcon,
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
        ...mapState(['currentTeamId', 'resources']),

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
            console.log('[TeamHub][FilesSharedWidget] loadItems — page:', this.page, 'team:', this.currentTeamId)
            this.loading = true
            this.items = []
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/files/shared`),
                    { params: { page: this.page, limit: LIMIT } },
                )
                console.log('[TeamHub][FilesSharedWidget] response:', data)
                this.items = Array.isArray(data.items) ? data.items : []
                this.total = typeof data.total === 'number' ? data.total : 0
            } catch (e) {
                console.error('[TeamHub][FilesSharedWidget] loadItems failed:', e)
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
.th-widget { padding: 0; }

/* Loading / empty states */
.th-widget__state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 20px 16px;
    color: var(--color-text-maxcontrast);
    font-size: 15px;
    text-align: center;
}
.th-widget__empty-icon {
    opacity: 0.35;
    color: var(--color-primary-element);
}

/* List */
.th-widget__list { list-style: none; padding: 0; margin: 0; }
.th-widget__row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    border-bottom: 1px solid var(--color-border);
}
.th-widget__row:last-child { border-bottom: none; }

/* Type icon badge */
.th-widget__badge {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 38px;
    height: 38px;
    border-radius: var(--border-radius-large);
    background: var(--color-background-dark, #f4f4f4);
    border: 1px solid var(--color-border);
    color: var(--color-primary-element);
}

/* Body */
.th-widget__body {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.th-widget__row-top {
    display: flex;
    align-items: center;
    gap: 6px;
    min-width: 0;
}
.th-widget__row-bottom {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

/* Title link */
.th-widget__title {
    flex: 1;
    font-size: 14px;
    font-weight: 500;
    color: var(--color-main-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.th-widget__title--link {
    text-decoration: none;
    color: var(--color-main-text);
}
.th-widget__title--link:hover {
    color: var(--color-primary-element);
    text-decoration: underline;
}

/* Avatar in meta row */
.th-widget__avatar {
    flex-shrink: 0;
}

/* Meta line */
.th-widget__meta {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 120px;
}
.th-widget__meta--sep::before {
    content: '·';
    margin-right: 4px;
    color: var(--color-border-dark);
}

/* Pagination footer */
.th-widget__pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 8px 14px;
    border-top: 1px solid var(--color-border);
}
.th-widget__page-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    border: 1px solid var(--color-border);
    background: var(--color-main-background);
    color: var(--color-primary-element);
    border-radius: var(--border-radius);
    cursor: pointer;
    transition: background 0.15s, border-color 0.15s;
}
.th-widget__page-btn:hover:not(:disabled) {
    background: var(--color-background-hover);
    border-color: var(--color-primary-element);
}
.th-widget__page-btn:disabled {
    opacity: 0.35;
    cursor: default;
}
.th-widget__page-info {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    min-width: 40px;
    text-align: center;
}
</style>
