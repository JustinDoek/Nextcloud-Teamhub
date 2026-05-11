<template>
    <NcDialog
        :name="t('teamhub', 'Folder migration required')"
        :open="true"
        :can-close="!migrating"
        size="normal"
        @closing="$emit('close')">

        <!-- ── Screen 1: Intro (choose manual or auto) ── -->
        <div v-if="screen === 'intro'" class="fmm-screen">
            <div class="fmm-info-block">
                <FolderIcon :size="32" class="fmm-icon" aria-hidden="true" />
                <h3 class="fmm-heading">{{ t('teamhub', 'A group folder is now connected to this team') }}</h3>
            </div>
            <p class="fmm-body">
                <!-- TRANSLATORS: {groupFolder} and {sharedFolder} are folder names -->
                {{ t('teamhub', 'The group folder "{groupFolder}" has been added to this team. Your team currently also has access to the shared folder "{sharedFolder}".', { groupFolder: groupFolderName, sharedFolder: sharedFolderName }) }}
            </p>
            <p class="fmm-body">
                {{ t('teamhub', 'The group folder will become the team\'s primary folder. The shared folder will no longer be connected to the team, but the owner can keep or delete it.') }}
            </p>
            <p class="fmm-body fmm-body--question">
                {{ t('teamhub', 'Would you like to move the files from the shared folder into the group folder automatically, or do it manually?') }}
            </p>

            <div class="fmm-actions">
                <NcButton
                    type="tertiary"
                    :aria-label="t('teamhub', 'I will move the files manually')"
                    @click="chooseManual">
                    {{ t('teamhub', 'Migrate manually') }}
                </NcButton>
                <NcButton
                    type="primary"
                    :aria-label="t('teamhub', 'Let TeamHub move the files automatically')"
                    @click="chooseAuto">
                    {{ t('teamhub', 'Migrate automatically') }}
                </NcButton>
            </div>
        </div>

        <!-- ── Screen 2: Auto preflight + confirmation ── -->
        <div v-else-if="screen === 'auto-preflight'" class="fmm-screen">
            <div v-if="loadingPreflight" class="fmm-loading">
                <NcLoadingIcon :size="32" />
                <span>{{ t('teamhub', 'Checking available space…') }}</span>
            </div>
            <template v-else>
                <h3 class="fmm-heading">{{ t('teamhub', 'Automatic migration') }}</h3>

                <table class="fmm-space-table" aria-label="t('teamhub', 'Space check')">
                    <tbody>
                        <tr>
                            <th scope="row">{{ t('teamhub', 'Files to move') }}</th>
                            <td>{{ formatBytes(preflight.sharedFolderBytes) }}</td>
                        </tr>
                        <tr>
                            <th scope="row">{{ t('teamhub', 'Available in group folder') }}</th>
                            <td :class="preflight.canAutoMigrate ? 'fmm-ok' : 'fmm-warn'">
                                {{ preflight.groupFolderFree < 0 || preflight.groupFolderFree >= Number.MAX_SAFE_INTEGER
                                    ? t('teamhub', 'Unlimited')
                                    : formatBytes(preflight.groupFolderFree) }}
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-if="!preflight.canAutoMigrate" class="fmm-warning-text">
                    {{ t('teamhub', 'There is not enough space in the group folder for an automatic migration. Please migrate manually: move the files yourself, then return here to connect the group folder.') }}
                </p>
                <p v-else class="fmm-body">
                    {{ t('teamhub', 'TeamHub will copy all files from the shared folder into the group folder. If the group folder already has files, they will first be moved to a backup folder called "team_files_backup". The shared folder will remain intact but the team will no longer have access to it.') }}
                </p>

                <div class="fmm-actions">
                    <NcButton type="tertiary" @click="screen = 'intro'">
                        {{ t('teamhub', 'Back') }}
                    </NcButton>
                    <NcButton
                        v-if="preflight.canAutoMigrate"
                        type="primary"
                        :aria-label="t('teamhub', 'Start automatic file migration')"
                        @click="runAutoMigrate">
                        {{ t('teamhub', 'Start migration') }}
                    </NcButton>
                    <NcButton
                        v-else
                        type="secondary"
                        @click="chooseManual">
                        {{ t('teamhub', 'Migrate manually instead') }}
                    </NcButton>
                </div>
            </template>
        </div>

        <!-- ── Screen 3: Migration in progress ── -->
        <div v-else-if="screen === 'migrating'" class="fmm-screen fmm-screen--centered">
            <NcLoadingIcon :size="48" />
            <p class="fmm-body fmm-body--loading">
                {{ t('teamhub', 'Migrating files… This may take a moment. Please do not close this window.') }}
            </p>
        </div>

        <!-- ── Screen 4: Success ── -->
        <div v-else-if="screen === 'success'" class="fmm-screen fmm-screen--centered">
            <CheckCircleIcon :size="48" class="fmm-icon fmm-icon--success" aria-hidden="true" />
            <h3 class="fmm-heading">{{ t('teamhub', 'Migration complete') }}</h3>
            <p v-if="resultMode === 'auto'" class="fmm-body">
                {{ t('teamhub', 'Files have been moved into the group folder. The shared folder is no longer connected to the team.') }}
            </p>
            <p v-else class="fmm-body">
                {{ t('teamhub', 'The group folder is now the team\'s primary folder. Please move your files from the shared folder into the group folder manually.') }}
            </p>
            <div class="fmm-actions fmm-actions--centered">
                <NcButton type="primary" @click="$emit('done')">
                    {{ t('teamhub', 'Done') }}
                </NcButton>
            </div>
        </div>

        <!-- ── Screen 5: Auto-migration partial failure ── -->
        <div v-else-if="screen === 'partial-failure'" class="fmm-screen">
            <div class="fmm-warning-block">
                <AlertIcon :size="32" class="fmm-icon fmm-icon--warn" aria-hidden="true" />
                <h3 class="fmm-heading">{{ t('teamhub', 'Automatic migration did not complete') }}</h3>
            </div>
            <p class="fmm-body">
                {{ t('teamhub', 'The automatic file copy failed: {error}', { error: migrationError }) }}
            </p>
            <p class="fmm-body">
                {{ t('teamhub', 'The group folder is now connected as the team\'s primary folder. The shared folder is no longer connected to the team, but the owner can still access it and move the files manually.') }}
            </p>
            <div class="fmm-actions fmm-actions--centered">
                <NcButton type="primary" @click="$emit('done')">
                    {{ t('teamhub', 'OK') }}
                </NcButton>
            </div>
        </div>

    </NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcButton, NcDialog, NcLoadingIcon } from '@nextcloud/vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'

export default {
    name: 'FolderMigrationModal',

    components: {
        NcButton,
        NcDialog,
        NcLoadingIcon,
        FolderIcon,
        CheckCircleIcon,
        AlertIcon,
    },

    props: {
        teamId:           { type: String,  required: true },
        sharedResourceId: { type: String,  required: true },
        groupResourceId:  { type: String,  required: true },
        sharedFolderName: { type: String,  default: '' },
        groupFolderName:  { type: String,  default: '' },
    },

    emits: ['close', 'done'],

    data() {
        return {
            screen:          'intro',  // intro | auto-preflight | migrating | success | partial-failure
            loadingPreflight: false,
            migrating:        false,
            preflight:        null,
            migrationError:   '',
            resultMode:       '',      // 'auto' | 'manual'
        }
    },

    methods: {
        t,

        async chooseAuto() {
            this.screen          = 'auto-preflight'
            this.loadingPreflight = true
            this.preflight        = null
            try {
                console.log('[TeamHub][FolderMigrationModal] fetching preflight', {
                    teamId: this.teamId, shared: this.sharedResourceId, gf: this.groupResourceId,
                })
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/resources/files/migration-preflight`),
                    { params: { sharedResourceId: this.sharedResourceId, groupResourceId: this.groupResourceId } }
                )
                this.preflight = data
                console.log('[TeamHub][FolderMigrationModal] preflight result', data)
            } catch (e) {
                console.error('[TeamHub][FolderMigrationModal] preflight failed', e)
                // Fall back to manual if preflight fails
                this.chooseManual()
            } finally {
                this.loadingPreflight = false
            }
        },

        chooseManual() {
            this.runMigrate('manual')
        },

        async runAutoMigrate() {
            this.runMigrate('auto')
        },

        async runMigrate(mode) {
            this.migrating = true
            this.screen    = 'migrating'

            try {
                console.log('[TeamHub][FolderMigrationModal] migrate start', { mode })
                const { data } = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/resources/files/migrate`),
                    {
                        mode,
                        sharedResourceId: this.sharedResourceId,
                        groupResourceId:  this.groupResourceId,
                    }
                )
                console.log('[TeamHub][FolderMigrationModal] migrate response', data)

                if (data.success) {
                    this.resultMode = mode
                    this.screen     = 'success'
                } else {
                    // Partial failure (auto copy failed but GF was activated)
                    this.migrationError = data.error || t('teamhub', 'Unknown error')
                    this.screen         = 'partial-failure'
                }
            } catch (e) {
                console.error('[TeamHub][FolderMigrationModal] migrate request failed', e)
                this.migrationError = e?.response?.data?.error || t('teamhub', 'Request failed')
                this.screen         = 'partial-failure'
            } finally {
                this.migrating = false
            }
        },

        formatBytes(bytes) {
            if (!bytes || bytes <= 0) return '0 B'
            const units = ['B', 'KB', 'MB', 'GB', 'TB']
            const i = Math.floor(Math.log(bytes) / Math.log(1024))
            return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + units[Math.min(i, units.length - 1)]
        },
    },
}
</script>

<style scoped>
.fmm-screen {
    padding: 8px 4px 16px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.fmm-screen--centered {
    align-items: center;
    text-align: center;
    padding-top: 24px;
}

.fmm-info-block,
.fmm-warning-block {
    display: flex;
    align-items: center;
    gap: 12px;
}

.fmm-icon {
    flex-shrink: 0;
    color: var(--color-primary-element);
}

.fmm-icon--success {
    color: var(--color-success-text);
}

.fmm-icon--warn {
    color: var(--color-warning-text);
}

.fmm-heading {
    font-size: 1.05rem;
    font-weight: 600;
    color: var(--color-main-text);
    margin: 0;
}

.fmm-body {
    margin: 0;
    color: var(--color-main-text);
    line-height: 1.5;
}

.fmm-body--question {
    font-weight: 500;
}

.fmm-body--loading {
    color: var(--color-text-maxcontrast);
}

.fmm-loading {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 24px 0;
    color: var(--color-text-maxcontrast);
}

.fmm-space-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}

.fmm-space-table th,
.fmm-space-table td {
    padding: 6px 8px;
    border-bottom: 1px solid var(--color-border);
    text-align: left;
}

.fmm-space-table th {
    color: var(--color-text-maxcontrast);
    font-weight: normal;
    width: 55%;
}

.fmm-ok  { color: var(--color-success-text); font-weight: 600; }
.fmm-warn { color: var(--color-warning-text); font-weight: 600; }

.fmm-warning-text {
    background: color-mix(in srgb, var(--color-warning) 10%, transparent);
    border: 1px solid var(--color-warning);
    border-radius: var(--border-radius);
    padding: 10px 14px;
    color: var(--color-warning-text);
    margin: 0;
    font-size: 0.9rem;
    line-height: 1.5;
}

.fmm-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: flex-end;
    margin-top: 8px;
}

.fmm-actions--centered {
    justify-content: center;
}
</style>
