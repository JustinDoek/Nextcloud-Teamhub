<template>
    <div class="th-widget">
        <!-- Loading state -->
        <div v-if="loading" class="th-widget__state">
            <NcLoadingIcon :size="20" />
        </div>

        <!-- No files resource for this team -->
        <div v-else-if="!resources.files" class="th-widget__state">
            <FolderIcon :size="36" class="th-widget__empty-icon" />
            <span>{{ t('teamhub', 'No team folder configured') }}</span>
        </div>

        <!-- Empty — no favourites inside team folder -->
        <div v-else-if="files.length === 0" class="th-widget__state">
            <StarIcon :size="36" class="th-widget__empty-icon" />
            <span>{{ t('teamhub', 'No favourited files in this team folder') }}</span>
        </div>

        <!-- File list -->
        <ul v-else class="th-widget__list">
            <li
                v-for="file in files"
                :key="file.id"
                class="th-widget__row">

                <!-- File-type icon badge -->
                <div class="th-widget__badge th-widget__badge--file" aria-hidden="true">
                    <component :is="fileIcon(file)" :size="18" />
                </div>

                <!-- Main content -->
                <div class="th-widget__body">
                    <div class="th-widget__row-top">
                        <a
                            :href="fileUrl(file)"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="th-widget__title th-widget__title--link"
                            :title="file.name">
                            {{ file.name }}
                        </a>
                        <StarIcon :size="13" class="th-widget__star-badge" />
                    </div>
                    <div class="th-widget__row-bottom">
                        <span class="th-widget__meta">{{ formatDate(file.mtime) }}</span>
                        <span v-if="file.path && file.path !== file.name" class="th-widget__meta th-widget__meta--sep">
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

/* File icon badge */
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
}
.th-widget__badge--file { color: var(--color-primary-element); }

/* Body */
.th-widget__body {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 3px;
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
    color: var(--color-main-text);
    text-decoration: none;
}
.th-widget__title--link:hover {
    color: var(--color-primary-element);
    text-decoration: underline;
}

/* Inline star badge next to the title */
.th-widget__star-badge {
    flex-shrink: 0;
    color: var(--color-warning, #f6c342);
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
    max-width: 140px;
}
.th-widget__meta--sep::before {
    content: '·';
    margin-right: 4px;
    color: var(--color-border-dark);
}
</style>
