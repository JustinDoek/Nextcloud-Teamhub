<template>
    <NcDialog
        v-if="open"
        :name="title"
        :open="open"
        @update:open="$emit('update:open', $event)">
        <div class="phase-guide">
            <p class="phase-guide__intro">{{ intro }}</p>

            <ul v-if="checklist.length" class="phase-guide__checklist">
                <li v-for="(item, i) in checklist" :key="i">{{ item }}</li>
            </ul>

            <div class="phase-guide__footer">
                <p class="phase-guide__advance">
                    {{ t('teamhub', 'To move to the next phase, open Manage Team → Project and choose a new phase from the dropdown.') }}
                </p>
                <NcButton variant="primary" @click="$emit('open-project-settings')">
                    {{ t('teamhub', 'Open Project settings') }}
                </NcButton>
            </div>
        </div>
    </NcDialog>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcDialog, NcButton } from '@nextcloud/vue'

export default {
    name: 'ProjectPhaseGuide',
    components: { NcDialog, NcButton },
    props: {
        open: {
            type: Boolean,
            default: false,
        },
        // Current phase of an advanced project: initiation|planning|execution|closing.
        // v3.99.3 — Initiation now has its own title/intro/checklist since
        // Justin's phase-model reshuffle intent is to have Advanced projects
        // start there (blocked on the 3.99.1 regression — see HANDOFF).
        phase: {
            type: String,
            default: 'planning',
        },
    },
    emits: ['update:open', 'open-project-settings'],
    computed: {
        title() {
            switch (this.phase) {
            case 'initiation':
                return t('teamhub', 'Initiation phase: what to do now')
            case 'execution':
                return t('teamhub', 'Execution phase: what to do now')
            case 'closing':
                return t('teamhub', 'Closing phase: what to do now')
            default:
                return t('teamhub', 'Planning phase: what to do now')
            }
        },
        intro() {
            switch (this.phase) {
            case 'initiation':
                return t('teamhub', "You're getting the project off the ground. Here's what to focus on:")
            case 'execution':
                // v3.99.3 — dropped the "coming in a future update" line;
                // budget/time/decisions tooling now exists.
                return t('teamhub', 'Your project is underway. Focus on carrying out your planned activities and tracking progress in Deck, Budget, Time and Decisions.')
            case 'closing':
                // v3.99.3 — dropped the "coming in a future update" line;
                // Closing artifact + archive-policy flow now exists.
                return t('teamhub', 'Time to wrap up. Tie off loose ends, evaluate the project with your team, and archive it once everything is settled.')
            default:
                return t('teamhub', "You're setting up your project. Here's what to focus on:")
            }
        },
        checklist() {
            if (this.phase === 'execution' || this.phase === 'closing') return []
            if (this.phase === 'initiation') {
                // Same labels as the Compass Initiation readiness items —
                // reusing the existing translations keeps the two surfaces
                // aligned and avoids the drift where the walkthrough and
                // the checklist say near-identical things differently.
                return [
                    t('teamhub', 'Set project start and target end dates'),
                    t('teamhub', 'Invite the project team'),
                    t('teamhub', 'Set the project total budget'),
                    t('teamhub', 'Set available hours per member'),
                ]
            }
            // v3.99.3 — first bullet updated: "on the page in the Pages
            // widget" (was "under the Pages tab"). Dates/invite/budget/
            // time moved to the Initiation checklist above.
            return [
                t('teamhub', 'Fill in the project contract on the page in the Pages widget'),
                t('teamhub', 'Set up your Deck board for tasks and activities'),
                t('teamhub', 'Add milestones for key dates'),
                t('teamhub', 'Schedule your planning meetings'),
            ]
        },
    },
    methods: { t, n },
}
</script>

<style scoped>
.phase-guide {
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding: 4px 4px 12px;
}

.phase-guide__intro {
    margin: 0;
    font-size: var(--th-font-body);
}

.phase-guide__checklist {
    margin: 0;
    padding-left: 20px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    font-size: var(--th-font-body);
}

.phase-guide__footer {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding-top: 8px;
    border-top: 1px solid var(--color-border);
}

.phase-guide__advance {
    margin: 0;
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}
</style>
