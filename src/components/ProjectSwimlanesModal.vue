<template>
    <NcDialog
        v-if="open"
        :name="t('teamhub', 'Define project workstreams')"
        :open="open"
        @update:open="$emit('update:open', $event)">
        <div class="th-swimlanes">
            <p class="th-swimlanes__intro">
                {{ t('teamhub', 'Add lanes to the Deck board as they become known. Each lane is a workstream — a milestone owns cards from the lanes above it, and Budget can allocate a share to each. New Advanced projects start with only the Project management lane; add the rest here as your plan takes shape.') }}
            </p>

            <div v-if="loadingList" class="th-swimlanes__loading">
                {{ t('teamhub', 'Loading workstreams') }}
            </div>

            <div v-else class="th-swimlanes__list-wrap">
                <h3 class="th-swimlanes__section-title">{{ t('teamhub', 'Existing workstreams') }}</h3>
                <ul v-if="stacks.length" class="th-swimlanes__list" role="list">
                    <li
                        v-for="s in stacks"
                        :key="s.stackId"
                        class="th-swimlanes__row">
                        <span class="th-swimlanes__order">#{{ (s.order ?? 0) + 1 }}</span>
                        <span class="th-swimlanes__title">{{ s.stackTitle }}</span>
                    </li>
                </ul>
                <p v-else class="th-swimlanes__empty">
                    {{ t('teamhub', 'No workstreams yet.') }}
                </p>
            </div>

            <div class="th-swimlanes__add">
                <h3 class="th-swimlanes__section-title">{{ t('teamhub', 'Add a workstream') }}</h3>
                <div class="th-swimlanes__add-row">
                    <input
                        v-model="newLaneTitle"
                        type="text"
                        maxlength="255"
                        class="th-swimlanes__input"
                        :placeholder="t('teamhub', 'e.g. Design')"
                        :disabled="saving"
                        @keydown.enter.prevent="submitLane">
                    <NcButton
                        variant="primary"
                        :disabled="saving || !newLaneTitle.trim()"
                        @click="submitLane">
                        {{ saving ? t('teamhub', 'Adding') : t('teamhub', 'Add lane') }}
                    </NcButton>
                </div>
                <p v-if="errorMessage" class="th-swimlanes__error" role="alert">
                    {{ errorMessage }}
                </p>
            </div>

            <div class="th-swimlanes__footer">
                <NcButton variant="secondary" @click="$emit('update:open', false)">
                    {{ t('teamhub', 'Done') }}
                </NcButton>
            </div>
        </div>
    </NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcDialog, NcButton } from '@nextcloud/vue'
import { mapState } from 'vuex'

/**
 * ProjectSwimlanesModal (v3.98.2).
 *
 * Planning-phase "Define workstreams" activity. Advanced project boards
 * now start with only the Project management stack; admins add the real
 * project lanes here as they're known. Add-only for this bump — deletion
 * lives in Deck itself.
 *
 * Emits:
 *   - update:open(bool)   — dialog close
 *   - lanes-changed()     — a lane was created (so the Compass can refetch
 *                           readiness and mark workstreams_defined done)
 */
export default {
    name: 'ProjectSwimlanesModal',

    components: { NcDialog, NcButton },

    props: {
        open: { type: Boolean, default: false },
    },

    emits: ['update:open', 'lanes-changed'],

    data() {
        return {
            stacks: [],
            loadingList: false,
            saving: false,
            newLaneTitle: '',
            errorMessage: '',
        }
    },

    computed: {
        ...mapState(['currentTeamId']),
    },

    watch: {
        open(isOpen) {
            if (isOpen) {
                this.errorMessage = ''
                this.newLaneTitle = ''
                this.loadStacks()
            }
        },
    },

    methods: {
        t,

        /**
         * Reuse the Timeline endpoint's stack list — same source of truth
         * ProjectSwimlaneView + BudgetView + TimeView already use, so a
         * lane added here shows up consistently everywhere. TimelineService
         * ::getDeckStacks returns { boardId, boardTitle, stackId, stackTitle, order }.
         */
        async loadStacks() {
            if (!this.currentTeamId) return
            this.loadingList = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/timeline`),
                )
                this.stacks = (data && Array.isArray(data.stacks) ? data.stacks : [])
                    .slice()
                    .sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
            } catch (e) {
                this.errorMessage = e?.response?.data?.error || e?.message || t('teamhub', 'Unknown error')
            } finally {
                this.loadingList = false
            }
        },

        async submitLane() {
            const title = this.newLaneTitle.trim()
            if (!title || this.saving) return
            this.saving = true
            this.errorMessage = ''
            try {
                const { data } = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/deck/stacks`),
                    { title },
                )
                // Optimistically append; also refetch so we pick up ordering
                // and any board metadata the response omits.
                this.stacks.push({
                    stackId:    data.stackId,
                    stackTitle: data.title,
                    boardId:    data.boardId,
                    order:      data.order,
                })
                this.newLaneTitle = ''
                this.$emit('lanes-changed')
            } catch (e) {
                this.errorMessage = e?.response?.data?.error || e?.message || t('teamhub', 'Failed to add workstream')
            } finally {
                this.saving = false
            }
        },
    },
}
</script>

<style scoped>
.th-swimlanes {
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding: 8px 4px 4px;
    min-width: 340px;
}

.th-swimlanes__intro {
    margin: 0;
    color: var(--color-main-text);
    font-size: 13px;
    line-height: 1.5;
}

.th-swimlanes__loading,
.th-swimlanes__empty {
    padding: 6px 0;
    color: var(--color-text-maxcontrast);
    font-size: 13px;
}

.th-swimlanes__section-title {
    margin: 0 0 6px;
    font-size: var(--th-font-meta);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--color-text-maxcontrast);
}

.th-swimlanes__list {
    display: flex;
    flex-direction: column;
    gap: 4px;
    list-style: none;
    padding: 0;
    margin: 0;
    max-height: 240px;
    overflow-y: auto;
}

.th-swimlanes__row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 10px;
    background: var(--color-background-hover);
    border-radius: var(--border-radius);
}

.th-swimlanes__order {
    flex: 0 0 auto;
    font-size: var(--th-font-micro);
    font-weight: 600;
    color: var(--color-text-maxcontrast);
    min-width: 20px;
}

.th-swimlanes__title {
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 13px;
    color: var(--color-main-text);
}

.th-swimlanes__add-row {
    display: flex;
    gap: 8px;
    align-items: center;
}

.th-swimlanes__input {
    flex: 1 1 auto;
    padding: 6px 10px;
    border: 1px solid var(--color-border-dark);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 13px;
}

.th-swimlanes__input:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}

.th-swimlanes__error {
    margin: 6px 0 0;
    padding: 6px 10px;
    background: var(--color-error);
    color: var(--color-error-text);
    border-radius: var(--border-radius);
    font-size: var(--th-font-meta);
}

.th-swimlanes__footer {
    display: flex;
    justify-content: flex-end;
    margin-top: 4px;
}
</style>
