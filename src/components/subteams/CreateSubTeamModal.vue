<template>
    <NcDialog
        :name="t('teamhub', 'New sub-team of {team}', { team: parentTeamName })"
        size="normal"
        :can-close="!saving"
        @closing="$emit('close')">
        <div class="th-newsub">
            <NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>

            <div class="th-newsub__field">
                <NcTextField
                    v-model="name"
                    :label="t('teamhub', 'Name')"
                    :placeholder="t('teamhub', 'What is this sub-team for?')"
                    :disabled="saving" />
            </div>

            <div class="th-newsub__field">
                <NcTextArea
                    v-model="description"
                    :label="t('teamhub', 'Description')"
                    :rows="2"
                    :disabled="saving" />
            </div>

            <!-- A select, where the team wizard uses a grid of cards. Not a
                 drifted copy: the wizard is a full page with room for five
                 cards and their descriptions, this is a dialog, and
                 /ui-standards names NcSelect as the control for a
                 select-shaped choice. Both read the same
                 GET /api/v1/templates, so there is one source of templates
                 and two presentations of it. -->
            <div v-if="templateOptions.length" class="th-newsub__field">
                <label class="th-newsub__label" for="th-newsub-template">
                    {{ t('teamhub', 'Template') }}
                </label>
                <NcSelect
                    v-model="templateKey"
                    input-id="th-newsub-template"
                    label-outside
                    :aria-label-combobox="t('teamhub', 'Template')"
                    :options="templateOptions"
                    :reduce="o => o.id"
                    :clearable="false"
                    :disabled="saving" />
                <!-- The template decides which modules and apps the sub-team
                     gets, and it decides them once: there is no per-sub-team
                     module switch anywhere, so this choice is worth stating
                     rather than leaving to be discovered. -->
                <span class="th-newsub__hint">
                    {{ t('teamhub', 'The template decides which modules and apps this sub-team gets.') }}
                </span>
            </div>

            <div v-if="policyOptions.length" class="th-newsub__field">
                <label class="th-newsub__label" for="th-newsub-policy">
                    {{ t('teamhub', 'Policy') }}
                </label>
                <NcSelect
                    v-model="profileKey"
                    input-id="th-newsub-policy"
                    label-outside
                    :aria-label-combobox="t('teamhub', 'Policy')"
                    :options="policyOptions"
                    :reduce="o => o.id"
                    :clearable="false"
                    :disabled="saving" />
            </div>

            <fieldset class="th-newsub__field">
                <legend class="th-newsub__label">{{ t('teamhub', 'Who can see it') }}</legend>
                <label class="th-newsub__radio">
                    <input
                        v-model="visibility"
                        type="radio"
                        name="th-newsub-visibility"
                        value="private"
                        :disabled="saving">
                    <span>
                        <span class="th-newsub__radio-title">{{ t('teamhub', 'Private') }}</span>
                        <span class="th-newsub__hint">{{ t('teamhub', 'Only its own members and this team\'s administrators know it exists.') }}</span>
                    </span>
                </label>
                <label class="th-newsub__radio">
                    <input
                        v-model="visibility"
                        type="radio"
                        name="th-newsub-visibility"
                        value="listed"
                        :disabled="saving">
                    <span>
                        <span class="th-newsub__radio-title">{{ t('teamhub', 'Visible to the team') }}</span>
                        <span class="th-newsub__hint">{{ t('teamhub', 'Everyone in this team sees its name but cannot open it.') }}</span>
                    </span>
                </label>
            </fieldset>

            <div class="th-newsub__field">
                <label class="th-newsub__label" for="th-newsub-members">
                    {{ t('teamhub', 'Members') }}
                </label>
                <!-- Whole option objects, not reduced ids: `multiple` with
                     `:reduce` is used nowhere else in this app, and every
                     other multiple select here binds objects and maps them at
                     submit. `submit()` does the mapping. -->
                <NcSelect
                    v-model="members"
                    input-id="th-newsub-members"
                    label-outside
                    :multiple="true"
                    :close-on-select="false"
                    :aria-label-combobox="t('teamhub', 'Members')"
                    :options="candidateOptions"
                    :loading="loadingCandidates"
                    :disabled="saving"
                    :placeholder="t('teamhub', 'Pick people from this team')" />
                <!-- Members come from this team; anyone else is invited after
                     the sub-team exists, through the sub-team's own Invite
                     action. Keeping the two apart here is what lets the
                     server refuse a list that mixes them (DESIGN §2.175). -->
                <span class="th-newsub__hint">
                    {{ t('teamhub', 'Members are picked from this team. You can invite people from outside once the sub-team exists.') }}
                </span>
            </div>
        </div>

        <template #actions>
            <NcButton variant="tertiary" :disabled="saving" @click="$emit('close')">
                {{ t('teamhub', 'Cancel') }}
            </NcButton>
            <NcButton variant="primary" :disabled="!canSubmit" @click="submit">
                <template v-if="saving" #icon><NcLoadingIcon :size="iconBody" /></template>
                {{ t('teamhub', 'Create sub-team') }}
            </NcButton>
        </template>
    </NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
    NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcSelect, NcTextArea, NcTextField,
} from '@nextcloud/vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { ICON_BODY } from '../../constants/uiTokens.js'
import { profileDisplayName, templateAllowedForSubTeam, templateDisplayName } from '../../constants/policy.js'

/**
 * Create a sub-team of a team (v4.12.2, DESIGN §2.175).
 *
 * An `NcDialog` rather than a branch of `CreateTeamView.vue`, which is a
 * full-page stepper that posts to the provisioning engine and takes over the
 * content area: this form is opened from inside Manage team, which is itself
 * a full page, so navigating away would lose the context the reader is in.
 *
 * One POST. If a template demanding four resources ever outgrows the request
 * timeout, the way out is `ProvisioningService` — `ProvisioningProgress.vue`
 * is a component and renders inside a dialog — not a second create path.
 */
export default {
    name: 'CreateSubTeamModal',

    components: {
        NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcSelect, NcTextArea, NcTextField,
    },

    props: {
        parentTeamId: {
            type: String,
            required: true,
        },
        parentTeamName: {
            type: String,
            default: '',
        },
    },

    emits: ['close', 'created'],

    data() {
        return {
            name: '',
            description: '',
            templateKey: '',
            profileKey: '',
            visibility: 'private',
            members: [],
            templates: [],
            policyProfiles: [],
            candidates: [],
            loadingCandidates: true,
            saving: false,
            error: '',
        }
    },

    computed: {
        iconBody() {
            return ICON_BODY
        },

        /**
         * v4.12.6 — through the shared label rule, not `row.label`. A seeded
         * row's stored label is English, so a Dutch reader was offered
         * "Project" here and shown the translated name everywhere the same
         * template is named: the Sub-teams row, the admin Policy panel and the
         * Maintenance grid all go through these two functions.
         */
        templateOptions() {
            return this.templates
                // v4.12.6 — a sub-team may not become a service desk
                // (§2.175), and `teamhub_team_type.type === 'service'` is what
                // would make it one. The create dialog has offered it since
                // 4.12.2; the server refuses it now, and it is not shown.
                .filter(tpl => templateAllowedForSubTeam(tpl.templateKey))
                .map(tpl => ({
                    id: tpl.templateKey,
                    label: templateDisplayName(tpl.templateKey, tpl.label, tpl.isSeeded),
                }))
        },

        policyOptions() {
            return this.policyProfiles.map(p => ({
                id: p.profileKey,
                label: profileDisplayName(p.profileKey, p.label, p.isSeeded),
            }))
        },

        /**
         * v4.12.5 — the subline goes into the option's label, joined with the
         * same ` · ` the server uses inside one. `candidates()` carries it now;
         * before, these two sub-team pickers were the only person pickers in
         * the app showing a bare display name, so the administrator's
         * *Telling people apart* fields were invisible exactly where two
         * colleagues with the same name have to be told apart.
         *
         * In the label rather than a custom option row: `NcSelect` renders
         * `label` and nothing else, no component in this app passes it an
         * option slot, and CLAUDE.md asks not to guess at a prop. The same
         * decision `ManageSubTeamModal` records.
         */
        candidateOptions() {
            return this.candidates
                .filter(c => c.userId)
                .map(c => {
                    const name = c.displayName || c.userId
                    return {
                        id: c.userId,
                        label: c.subline ? `${name} · ${c.subline}` : name,
                    }
                })
        },

        canSubmit() {
            return !this.saving && this.name.trim().length > 0
        },
    },

    watch: {
        /**
         * A template may name the policy profile it expects. Only applied
         * while the reader has not chosen one themselves — the same rule the
         * team wizard follows, so a deliberate choice is never overwritten.
         */
        templateKey(key) {
            if (this.profileKey) {
                return
            }
            const tpl = this.templates.find(x => x.templateKey === key)
            if (tpl?.defaultProfileKey) {
                this.profileKey = tpl.defaultProfileKey
            }
        },
    },

    mounted() {
        this.loadTemplates()
        this.loadPolicyProfiles()
        this.loadCandidates()
    },

    methods: {
        t,
        n,

        /**
         * Every load below fails open: a dialog that will not render because
         * a defaults fetch failed is worse than one that opens with a choice
         * unmade. The name is the only required field, and the server applies
         * its own defaults for the rest.
         */
        async loadTemplates() {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/templates'))
                this.templates = data.templates || []
                // From the offered list, not the raw one: the first template by
                // sort order could be one a sub-team may not have.
                if (!this.templateKey && this.templateOptions.length) {
                    this.templateKey = this.templateOptions[0].id
                }
            } catch (e) {
                this.templates = []
            }
        },

        async loadPolicyProfiles() {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/policy/creation'))
                this.policyProfiles = data.profiles || []
            } catch (e) {
                this.policyProfiles = []
            }
        },

        async loadCandidates() {
            this.loadingCandidates = true
            try {
                this.candidates = await this.$store.dispatch('fetchSubTeamCandidates', this.parentTeamId)
            } catch (e) {
                this.candidates = []
            } finally {
                this.loadingCandidates = false
            }
        },

        async submit() {
            if (!this.canSubmit) {
                return
            }
            this.saving = true
            this.error = ''
            try {
                const subTeam = await this.$store.dispatch('createSubTeam', {
                    teamId: this.parentTeamId,
                    name: this.name.trim(),
                    description: this.description.trim(),
                    templateKey: this.templateKey,
                    profileKey: this.profileKey,
                    visibility: this.visibility,
                    // The picker holds `{ id, label }` objects; the API wants
                    // uids. Mapped here rather than with `:reduce`, see the
                    // note on the select.
                    members: this.members.map(m => (typeof m === 'string' ? m : m.id)),
                })
                this.$emit('created', subTeam)
            } catch (e) {
                // The server's message is the useful one here: it is where the
                // name rules and the "picked from this team" rule are enforced,
                // and both produce a sentence written for the reader.
                this.error = e?.response?.data?.error || t('teamhub', 'Could not create the sub-team')
            } finally {
                this.saving = false
            }
        },
    },
}
</script>

<style scoped lang="scss">
.th-newsub {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-lg);
    padding-block-end: var(--th-space-sm);
}

.th-newsub__field {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    /* A <fieldset> carries a border and padding by default. */
    border: none;
    padding: 0;
    margin: 0;
    min-width: 0;
}

.th-newsub__label {
    font-size: var(--th-font-meta);
    font-weight: var(--th-font-weight-medium);
    color: var(--color-text-maxcontrast);
    padding: 0;
}

.th-newsub__hint {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    line-height: var(--th-line-height-body);
}

.th-newsub__radio {
    display: flex;
    align-items: flex-start;
    gap: var(--th-space-sm);
    padding: var(--th-space-sm);
    border-radius: var(--th-radius-control);
    cursor: pointer;

    &:hover {
        background: var(--color-background-hover);
    }

    /* Split from :hover deliberately — grouping them under one selector is
       what silences the keyboard ring (/ui-standards § Focus visibility). */
    &:focus-within {
        background: var(--color-background-hover);
        outline: 2px solid var(--color-primary-element);
    }

    > span {
        display: flex;
        flex-direction: column;
        gap: 0;
    }
}

.th-newsub__radio-title {
    font-size: var(--th-font-body);
}
</style>
