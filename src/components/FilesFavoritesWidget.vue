<template>
    <div class="th-widget">
        <!-- Loading state -->
        <div v-if="loading" class="th-widget__state">
            <span class="th-widget__spinner" aria-hidden="true" />
            <span class="th-widget__state-text">{{ t('teamhub', 'Loading…') }}</span>
        </div>

        <!-- No files resource for this team -->
        <div v-else-if="!resources.files" class="th-widget__state th-widget__state--empty">
            <FolderIcon :size="18" aria-hidden="true" />
            <span class="th-widget__state-text">{{ t('teamhub', 'No team folder configured') }}</span>
        </div>

        <!-- Empty — no favourites inside team folder -->
        <div v-else-if="files.length === 0" class="th-widget__state th-widget__state--empty">
            <StarIcon :size="18" aria-hidden="true" />
            <span class="th-widget__state-text">{{ t('teamhub', 'No favourited files in this team folder') }}</span>
        </div>

        <!-- File list -->
        <ul v-else class="th-widget__rows">
            <li
                v-for="file in files"
                :key="file.id"
                class="th-widget__row">

                <!-- File-type icon badge -->
                <div class="th-files-fav__badge" aria-hidden="true">
                    <component :is="fileIcon(file)" :size="18" />
                </div>

                <!-- Main content — size-driven hierarchy -->
                <div class="th-files-fav__body">
                    <div class="th-files-fav__title-row">
                        <a
                            :href="fileUrl(file)"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="th-files-fav__title"
                            :title="file.name"
                            @click="onOpen($event, file)">
                            {{ file.name }}
                        </a>
                        <StarIcon :size="13" class="th-files-fav__star-badge" />
                    </div>
                    <div class="th-files-fav__meta">
                        <span>{{ formatDate(file.mtime) }}</span>
                        <span v-if="file.path && file.path !== file.name" class="th-files-fav__meta-sep">
                            {{ folderPath(file) }}
                        </span>
                    </div>
                </div>
            </li>
        </ul>
    </div>
</template>

<script>
import { mapState } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcLoadingIcon } from '@nextcloud/vue'

// Icons
import FolderIcon           from 'vue-material-design-icons/Folder.vue'
import StarIcon             from 'vue-material-design-icons/Star.vue'
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

export default {
    name: 'FilesFavoritesWidget',

    components: {
        NcLoadingIcon,
        FolderIcon, StarIcon, FileIcon, FileImageIcon, FilePdfBoxIcon,
        FileWordIcon, FileExcelIcon, FilePowerpointIcon, FileVideoIcon,
        FileMusicIcon, FileCodeIcon, FileDocumentIcon,
    },

    data() {
        return {
            loading: false,
            files: [],
        }
    },

    computed: {
        ...mapState(['currentTeamId', 'resources']),
    },

    watch: {
        currentTeamId: { immediate: true, handler() { this.loadFiles() } },
    },

    methods: {
        t,

        async loadFiles() {
            if (!this.currentTeamId) return
            this.loading = true
            this.files = []
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/files/favorites`)
                )
                this.files = Array.isArray(data) ? data : []
            } catch (e) {
                this.files = []
            } finally {
                this.loading = false
            }
        },

        /**
         * Open the file in its native NC editor / viewer by file ID.
         * NC resolves the correct app (Text, Collabora, OnlyOffice, etc.)
         * based on mimetype when using the /f/{id} route.
         */
        fileUrl(file) {
            return generateUrl(`/f/${file.id}`)
        },

        /**
         * Open the file inside TeamHub's files-view iframe instead of a new tab.
         * Modified clicks (ctrl/cmd/shift/middle) keep the native new-tab open.
         */
        onOpen(event, file) {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) {
                return
            }
            event.preventDefault()
            this.$store.dispatch('openFileInEmbed', this.fileUrl(file))
        },

        /**
         * Display the parent folder path relative to the team root,
         * stripping the filename itself.
         */
        folderPath(file) {
            const parts = file.path.split('/')
            if (parts.length <= 1) return ''
            return parts.slice(0, -1).join('/')
        },

        formatDate(mtime) {
            if (!mtime) return ''
            // mtime is a Unix timestamp (seconds).
            const d = new Date(mtime * 1000)
            const now = new Date()
            const diffDays = Math.floor((now - d) / 86400000)
            if (diffDays === 0) return t('teamhub', 'Today')
            if (diffDays === 1) return t('teamhub', 'Yesterday')
            return d.toLocaleDateString([], { month: 'short', day: 'numeric' })
        },

        /**
         * Map mimetype / extension to a display icon component.
         */
        fileIcon(file) {
            const mime = (file.mimetype || '').toLowerCase()
            const ext  = (file.extension || '').toLowerCase()

            if (mime.startsWith('image/'))                           return 'FileImageIcon'
            if (mime.startsWith('video/'))                           return 'FileVideoIcon'
            if (mime.startsWith('audio/'))                           return 'FileMusicIcon'
            if (mime === 'application/pdf')                          return 'FilePdfBoxIcon'
            if (['doc', 'docx', 'odt'].includes(ext))               return 'FileWordIcon'
            if (['xls', 'xlsx', 'ods'].includes(ext))               return 'FileExcelIcon'
            if (['ppt', 'pptx', 'odp'].includes(ext))               return 'FilePowerpointIcon'
            if (['js', 'ts', 'py', 'php', 'html', 'css', 'json', 'xml', 'sh'].includes(ext)) return 'FileCodeIcon'
            if (['txt', 'md'].includes(ext))                         return 'FileDocumentIcon'
            return 'FileIcon'
        },
    },
}
</script>

<style scoped>
/* Widget-specific only — shared classes from widget-tokens.css */

.th-files-fav__badge {
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

.th-files-fav__body {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.th-files-fav__title-row {
    display: flex;
    align-items: center;
    gap: 6px;
    min-width: 0;
}

.th-files-fav__title {
    flex: 1;
    font-size: var(--th-widget-row-primary-size);
    font-weight: var(--th-widget-row-primary-weight);
    color: var(--color-main-text);
    text-decoration: none;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.th-files-fav__title:hover {
    color: var(--color-primary-element);
    text-decoration: underline;
}

/* v3.100.16: NC theme token (was --th-color-warning hex). */
.th-files-fav__star-badge {
    flex-shrink: 0;
    color: var(--color-warning-text);
}

.th-files-fav__meta {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: var(--th-widget-row-meta-size);
    font-weight: var(--th-widget-row-meta-weight);
    color: var(--th-widget-meta-color);
    flex-wrap: wrap;
}

.th-files-fav__meta-sep::before {
    content: '·';
    margin-right: 4px;
    color: var(--color-border-dark);
}
</style>
