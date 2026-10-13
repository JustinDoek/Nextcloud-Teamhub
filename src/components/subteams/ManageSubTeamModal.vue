<template>
    <!-- TRANSLATORS: dialog title — {team} is the sub-team's name -->
    <NcDialog
        :name="t('teamhub', 'Manage {team}', { team: subTeam.name })"
        size="normal"
        :can-close="!busy"
        @closing="$emit('close')">
        <div class="th-subman">
            <NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>

            <!-- ── Name and visibility ──────────────────────────────────── -->
            <div class="th-subman__field">
                <NcTextField
                    v-model="name"
                    :label="t('teamhub', 'Name')"
                    :disabled="busy" />
                <!-- The folder is named "{parent} – {child}" at creation and
                     TeamHub cannot rename a team folder, so a rename here and
                     the name in Files part company. Said rather than left to
                     be discovered — see TeamService::renameTeam(). -->
                <span class="th-subman__hint">
                    {{ t('teamhub', 'The files folder keeps the name it was created with.') }}
                </span>
            </div>

            <!-- v4.12.8 — set when the sub-team was created and unreachable
                 ever since: there was no field here, and the gate behind it
                 answered to a direct row on the sub-team, which a parent admin
                 who did not create it does not have (DESIGN §2.182). -->
            <div class="th-subman__field">
                <NcTextArea
                    v-model="description"
                    :label="t('teamhub', 'Description')"
                    :rows="2"
                    :disabled="busy" />
            </div>

            <fieldset class="th-subman__field">
                <legend class="th-subman__label">{{ t('teamhub', 'Who can see it') }}</legend>
                <label class="th-subman__radio">
                    <input
                        v-model="visibility"
                        type="radio"
                        name="th-subman-visibility"
                        value="private"
                        :disabled="busy">
                    <span>
                        <span class="th-subman__radio-title">{{ t('teamhub', 'Private') }}</span>
                        <span class="th-subman__hint">{{ t('teamhub', 'Only its own members and this team\'s administrators know it exists.') }}</span>
                    </span>
                </label>
                <label class="th-subman__radio">
                    <input
                        v-model="visibility"
                        type="radio"
                        name="th-subman-visibility"
                        value="listed"
                        :disabled="busy">
                    <span>
                        <span class="th-subman__radio-title">{{ t('teamhub', 'Visible to the team') }}</span>
                        <span class="th-subman__hint">{{ t('teamhub', 'Everyone in this team sees its name but cannot open it.') }}</span>
                    </span>
                </label>
            </fieldset>

            <!-- ── Template and policy (v4.12.6, DESIGN §2.180) ─────────── -->
            <!-- Both are picked when the sub-team is created and were frozen
                 there until now: a sub-team has no Manage team of its own and
                 no module switch anywhere, so a template picked wrongly in a
                 dialog could only be undone by deleting the sub-team. Changing
                 the policy of a *main* team stays a Nextcloud administrator's
                 act — the sub-team is the one exception, and §2.180 records
                 what that costs. -->
            <div v-if="templateOptions.length" class="th-subman__field">
                <label class="th-subman__label" for="th-subman-template">
                    {{ t('teamhub', 'Template') }}
                </label>
                <NcSelect
                    v-model="templateKey"
                    input-id="th-subman-template"
                    label-outside
                    :aria-label-combobox="t('teamhub', 'Template')"
                    :options="templateOptions"
                    :reduce="o => o.id"
                    :clearable="false"
                    :disabled="busy" />
                <span class="th-subman__hint">
                    {{ t('teamhub', 'The template decides which modules and apps this sub-team gets.') }}
                </span>
            </div>

            <div v-if="policyOptions.length" class="th-subman__field">
                <label class="th-subman__label" for="th-subman-policy">
                    {{ t('teamhub', 'Policy') }}
                </label>
                <NcSelect
                    v-model="profileKey"
                    input-id="th-subman-policy"
                    label-outside
                    :aria-label-combobox="t('teamhub', 'Policy')"
                    :options="policyOptions"
                    :reduce="o => o.id"
                    :clearable="false"
                    :disabled="busy" />
                <span class="th-subman__hint">
                    {{ t('teamhub', 'The policy decides what this sub-team may do. A Nextcloud administrator defines the ones on offer.') }}
                </span>
            </div>

            <!-- ── Expiry (v4.12.9, DESIGN §2.183) ──────────────────────── -->
            <!-- A date on a sub-team used to be a Nextcloud administrator's to
                 set, from Maintenance, because that is the only screen that has
                 the control and a sub-team has no Manage team of its own. It
                 belongs to the parent's admins, like the template and the
                 policy. Eligibility stays the template's: Collaboration and
                 Project only, which is why the field is replaced by the reason
                 rather than disabled when the template does not offer it. -->
            <div class="th-subman__field">
                <label class="th-subman__label" for="th-subman-expiry">
                    {{ t('teamhub', 'Expiration date') }}
                </label>
                <template v-if="subTeam.expiryEligible">
                    <!-- Native, which is what the NC design guide asks for and
                         what every other date in this app uses. -->
                    <input
                        id="th-subman-expiry"
                        v-model="expiresOn"
                        type="date"
                        class="th-subman__date"
                        :min="tomorrowIso"
                        :disabled="busy">
                    <span class="th-subman__hint">
                        {{ t('teamhub', 'Leave it empty for a sub-team that does not expire.') }}
                    </span>
                </template>
                <span v-else class="th-subman__hint">
                    {{ t('teamhub', 'Only Collaboration and Project teams can have an expiration date.') }}
                </span>
            </div>

            <!-- Its own button, not the dialog's Save, and the destructive
                 half says what it destroys before it is pressed. A nested
                 NcDialog is not a pattern this app has anywhere and CLAUDE.md
                 asks not to guess at one, so the confirmation is the warning
                 above a deliberate second press — the same grammar this dialog
                 already uses for a member removal, which also acts at once
                 rather than waiting for Save. -->
            <div v-if="classificationChanging" class="th-subman__field th-subman__apply">
                <NcNoteCard v-if="droppedLabels" type="warning">
                    {{ t('teamhub', 'Switching to {template} switches off {apps}, and what those hold is deleted for good. The files folder and everything in it stays.', { template: pickedTemplateLabel, apps: droppedLabels }) }}
                </NcNoteCard>
                <NcButton variant="primary" :disabled="busy" @click="applyClassification">
                    <template v-if="applying" #icon><NcLoadingIcon :size="iconBody" /></template>
                    {{ t('teamhub', 'Apply template and policy') }}
                </NcButton>
            </div>

            <!-- What the apply actually did. A note card and not a toast: it
                 names resources that were switched off or left behind, which is
                 a sentence somebody has to be able to read twice. -->
            <NcNoteCard v-if="reportLines.length" :type="reportType">
                <p v-for="line in reportLines" :key="line" class="th-subman__report">
                    {{ line }}
                </p>
            </NcNoteCard>

            <!-- ── Members ──────────────────────────────────────────────── -->
            <div class="th-subman__field">
                <span class="th-subman__label">{{ t('teamhub', 'Members') }}</span>

                <NcLoadingIcon v-if="loadingMembers" :size="iconBody" />

                <ul v-else-if="people.length" class="th-subman__people">
                    <li v-for="person in people" :key="person.key" class="th-subman__person">
                        <!-- PersonRow is the one place a person's
                             disambiguating line is rendered (DESIGN §2.139),
                             so the administrator's "Telling people apart"
                             fields read the same here as in every other
                             picker. Where this row came from is a *state*, so
                             it is a chip beside the name rather than a second
                             subline competing with it. -->
                        <PersonRow
                            :id="person.userId"
                            :display-name="person.displayName"
                            :subline="person.subline"
                            compact>
                            <NcChip no-close variant="tertiary" :text="person.origin" />
                            <!-- Hidden, not disabled, for the people who
                                 cannot be removed from here: the owner (the
                                 admin who made the sub-team — MemberService
                                 refuses it) and anybody who is in through a
                                 group, who has to leave the group instead.
                                 CLAUDE.md § Permissions. -->
                            <NcButton
                                v-if="person.removable"
                                variant="tertiary"
                                :disabled="busy"
                                :aria-label="t('teamhub', 'Remove {name}', { name: person.displayName })"
                                :title="t('teamhub', 'Remove {name}', { name: person.displayName })"
                                @click="remove(person)">
                                <template #icon><AccountRemove :size="iconBody" /></template>
                            </NcButton>
                        </PersonRow>
                    </li>
                </ul>

                <NcEmptyContent
                    v-else
                    :name="t('teamhub', 'Nobody is in this sub-team yet')">
                    <template #icon><AccountMultipleOutline :size="iconHero" /></template>
                </NcEmptyContent>
            </div>

            <!-- ── Add from the main team ───────────────────────────────── -->
            <div class="th-subman__field">
                <label class="th-subman__label" for="th-subman-add">
                    {{ t('teamhub', 'Add from the main team') }}
                </label>
                <!-- Whole option objects rather than `:reduce`, as
                     CreateSubTeamModal does and for the same reason: `multiple`
                     with `:reduce` is used nowhere else in this app. -->
                <NcSelect
                    v-model="picked"
                    input-id="th-subman-add"
                    label-outside
                    :multiple="true"
                    :close-on-select="false"
                    :aria-label-combobox="t('teamhub', 'Add from the main team')"
                    :options="candidateOptions"
                    :loading="loadingCandidates"
                    :disabled="busy"
                    :placeholder="t('teamhub', 'Pick people from this team')" />
            </div>

            <!-- ── Invite from outside ──────────────────────────────────── -->
            <div class="th-subman__field">
                <!-- A span, not a <label for>: NcTextField owns its own label
                     element through the `label` prop, and a second one
                     pointing at an id the component may not put on the input
                     is a label that announces nothing. -->
                <span class="th-subman__label">
                    {{ t('teamhub', 'Invite someone from outside') }}
                </span>
                <NcTextField
                    v-model="query"
                    :label="t('teamhub', 'Invite someone from outside')"
                    :label-visible="false"
                    :placeholder="t('teamhub', 'Search by name or username')"
                    :disabled="busy"
                    @input="onSearch" />

                <NcLoadingIcon v-if="searching" :size="iconBody" />
                <ul v-else-if="results.length" class="th-subman__results">
                    <li
                        v-for="item in results"
                        :key="item.id"
                        class="th-subman__result">
                        <!-- The add button is the control, not the row. A <li>
                             with a click handler is not keyboard reachable
                             (which is what InviteMemberModal does and should
                             not), and a <div> inside a <button> is invalid
                             markup — so the control goes in PersonRow's own
                             trailing slot, which is what it is for. -->
                        <PersonRow
                            :id="item.id"
                            :display-name="item.label"
                            :subline="item.subline"
                            compact>
                            <NcButton
                                variant="tertiary"
                                :disabled="busy"
                                :aria-label="t('teamhub', 'Add {name}', { name: item.label })"
                                :title="t('teamhub', 'Add {name}', { name: item.label })"
                                @click="stage(item)">
                                <template #icon><Plus :size="iconBody" /></template>
                            </NcButton>
                        </PersonRow>
                    </li>
                </ul>
                <p v-else-if="query.length >= 2" class="th-subman__hint">
                    {{ t('teamhub', 'Nobody found outside this team.') }}
                </p>

                <div v-if="staged.length" class="th-subman__chips">
                    <NcChip
                        v-for="u in staged"
                        :key="u.id"
                        variant="primary"
                        :text="u.label"
                        :aria-label-close="t('teamhub', 'Remove {name}', { name: u.label })"
                        @close="unstage(u)" />
                </div>
                <!-- An outsider is a new licensed seat and gets access to this
                     sub-team only, never to the main team (DESIGN §2.175).
                     Worth saying where the choice is made. -->
                <span class="th-subman__hint">
                    {{ t('teamhub', 'Someone from outside joins this sub-team only, and counts as a licensed user.') }}
                </span>
            </div>
        </div>

        <template #actions>
            <NcButton variant="tertiary" :disabled="busy" @click="$emit('close')">
                {{ t('teamhub', 'Close') }}
            </NcButton>
            <NcButton variant="primary" :disabled="!canSave" @click="save">
                <template v-if="saving" #icon><NcLoadingIcon :size="iconBody" /></template>
                {{ t('teamhub', 'Save changes') }}
            </NcButton>
        </template>
    </NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess } from '@nextcloud/dialogs'
import {
    NcButton, NcChip, NcDialog, NcEmptyContent, NcLoadingIcon,
    NcNoteCard, NcSelect, NcTextArea, NcTextField,
} from '@nextcloud/vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import AccountRemove from 'vue-material-design-icons/AccountRemove.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import PersonRow from '../PersonRow.vue'
import { ICON_BODY, ICON_HERO } from '../../constants/uiTokens.js'
// v4.12.6 - the label rules for a template, a profile and anything a template
// switches on. Shared with the admin Policy panel rather than re-derived here.
import {
    integrationLabel, profileDisplayName, templateAllowedForSubTeam, templateDisplayName, templateDrops,
} from '../../constants/policy.js'

/**
 * Manage one sub-team: its name, who can see it, and who is in it
 * (v4.12.5, Ship 2 of DESIGN §2.175).
 *
 * **This is the only place a sub-team is administered**, which is why
 * everything is in one dialog rather than spread over the sub-team's own
 * Manage team — it does not have one, and a parent admin who did not create
 * the sub-team has no row on it at all, so they cannot reach its sidebar
 * actions either. If adding a guest were left to the sidebar's Invite action
 * (gated on level >= 4, which such an admin does not have), they could never
 * add one.
 *
 * Two pickers rather than one search, deliberately: the server keeps `members`
 * and `outsiders` apart and refuses a list that mixes them, so a control per
 * list means the reader is choosing the same two things the rule is written
 * about. The search excludes anyone already on the main team's roster for the
 * same reason — the refusal exists for an API caller, not for a form to walk
 * the reader into.
 *
 * Name and visibility are saved by the button; a member add or removal takes
 * effect at once, as the Members tab's own do.
 *
 * v4.12.6 adds the template and the policy (DESIGN §2.180), which also act at
 * once and on their own button. Two reasons they are not folded into Save:
 * changing a template can **delete** a Talk room, a calendar, a Deck board, an
 * Intravox space or a Collective, so the press that does it has to be the
 * press that read the warning; and the apply answers with what it added,
 * switched off and left behind, which belongs beside the control rather than
 * in a toast after the dialog has closed.
 */
export default {
    name: 'ManageSubTeamModal',

    components: {
        NcButton, NcChip, NcDialog, NcEmptyContent, NcLoadingIcon,
        NcNoteCard, NcSelect, NcTextArea, NcTextField,
        AccountMultipleOutline, AccountRemove, Plus,
        PersonRow,
    },

    props: {
        parentTeamId: {
            type: String,
            required: true,
        },
        subTeam: {
            type: Object,
            required: true,
        },
    },

    emits: ['close', 'changed'],

    data() {
        return {
            name: this.subTeam.name || '',
            description: this.subTeam.description || '',
            expiresOn: this.subTeam.expiresOn || '',
            visibility: this.subTeam.visibility || 'private',
            // v4.12.6 - what the selects hold, and beside each what the server
            // last confirmed. The pair is what tells "changed" from "applied":
            // the `subTeam` prop is the panel's row object and this dialog is
            // keyed on its id, so it does not come back refreshed after an
            // apply - these two do.
            templateKey: this.subTeam.templateKey || '',
            profileKey: this.subTeam.profileKey || '',
            appliedTemplateKey: this.subTeam.templateKey || '',
            appliedProfileKey: this.subTeam.profileKey || '',
            templates: [],
            policyProfiles: [],
            applying: false,
            report: null,
            roster: null,
            candidates: [],
            picked: [],
            query: '',
            results: [],
            staged: [],
            searchTimer: null,
            searching: false,
            loadingMembers: true,
            loadingCandidates: true,
            saving: false,
            error: '',
        }
    },

    computed: {
        iconBody() {
            return ICON_BODY
        },
        iconHero() {
            return ICON_HERO
        },
        busy() {
            return this.saving || this.applying
        },

        /**
         * The same label rule the panel's rows and the admin Policy panel
         * apply: a seeded template or profile is translated by key, one an
         * administrator renamed is shown as they typed it.
         */
        templateOptions() {
            return this.templates
                // A sub-team may not become a service desk (§2.175), and the
                // type row is what would make it one — so that template is not
                // offered. The one it already carries stays in the list even if
                // it would not be offered now, so the select never shows blank
                // for a value the sub-team really has.
                .filter(tpl => templateAllowedForSubTeam(tpl.templateKey)
                    || tpl.templateKey === this.appliedTemplateKey)
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
         * The earliest date the picker offers. A sub-team that expires today
         * is one somebody has to argue about today; the Maintenance grid's own
         * date input uses the same floor.
         */
        tomorrowIso() {
            const d = new Date()
            d.setDate(d.getDate() + 1)
            return d.toISOString().slice(0, 10)
        },

        pickedTemplateLabel() {
            return this.templateOptions.find(o => o.id === this.templateKey)?.label || this.templateKey
        },

        /** Either select differs from what the server last confirmed. */
        classificationChanging() {
            return (this.templateKey !== '' && this.templateKey !== this.appliedTemplateKey)
                || (this.profileKey !== '' && this.profileKey !== this.appliedProfileKey)
        },

        /**
         * What the new template drops that the old one asked for, named.
         *
         * The set difference itself is `templateDrops()` in
         * `src/constants/policy.js` — pure, tested, and documented there,
         * including why `files` is excluded from it. This computed only turns
         * it into a sentence fragment.
         */
        droppedLabels() {
            if (this.templateKey === '' || this.templateKey === this.appliedTemplateKey) {
                return ''
            }
            return templateDrops(
                this.templates.find(x => x.templateKey === this.appliedTemplateKey),
                this.templates.find(x => x.templateKey === this.templateKey),
            ).map(integrationLabel).join(', ')
        },

        /**
         * The apply's own account of itself, one sentence per outcome. Only
         * what happened: a template change that only added things says so and
         * nothing more.
         */
        reportLines() {
            const r = this.report
            if (!r) {
                return []
            }
            const names = ids => (ids || []).map(integrationLabel).join(', ')
            const lines = []
            if (r.added?.length) {
                // TRANSLATORS: result of changing a sub-team's template — {apps} is a comma-separated list of apps and modules the new template switched on, e.g. "Talk, Decisions"
                lines.push(t('teamhub', 'Switched on: {apps}', { apps: names(r.added) }))
            }
            if (r.removed?.length) {
                // TRANSLATORS: result of changing a sub-team's template — {apps} is a comma-separated list of apps and modules the new template switched off, and whose contents are gone
                lines.push(t('teamhub', 'Switched off: {apps}', { apps: names(r.removed) }))
            }
            if (r.manual?.length) {
                // TRANSLATORS: {apps} is a comma-separated list of apps the new template no longer asks for but which were kept anyway, because deleting them would delete the files inside them — the team folder is the usual case
                lines.push(t('teamhub', 'Left in place, because removing it would take the contents with it: {apps}', { apps: names(r.manual) }))
            }
            if (r.errors?.length) {
                // TRANSLATORS: {apps} is a comma-separated list of apps and modules the template change could not switch on or off
                lines.push(t('teamhub', 'Could not be changed: {apps}', { apps: names(r.errors) }))
            }
            return lines
        },

        reportType() {
            if (this.report?.errors?.length) {
                return 'error'
            }
            return this.report?.removed?.length || this.report?.manual?.length ? 'warning' : 'success'
        },

        /**
         * Direct members first, then the people who are only in through a
         * group. One flat list, because the distinction the reader of this
         * dialog cares about is "can I take them out from here", and that is
         * what `removable` says.
         */
        people() {
            const r = this.roster
            if (!r) {
                return []
            }
            const direct = (r.direct || []).map(p => ({
                key: 'd:' + p.userId,
                userId: p.userId,
                displayName: p.displayName || p.userId,
                subline: p.subline || '',
                origin: p.fromParent
                    // TRANSLATORS: chip on a sub-team member's row — they were picked out of the main team's roster
                    ? t('teamhub', 'From the main team')
                    // TRANSLATORS: chip on a sub-team member's row — they are a guest, not a member of the main team
                    : t('teamhub', 'Invited from outside'),
                // The owner is the admin who created the sub-team.
                // MemberService::removeMember() refuses them, so the button
                // that would fail is absent instead.
                removable: (p.level || 0) < 9,
            }))
            const inherited = (r.inherited || []).map(p => ({
                key: 'i:' + p.userId,
                userId: p.userId,
                displayName: p.displayName || p.userId,
                subline: p.subline || '',
                origin: p.via
                    // TRANSLATORS: chip on a sub-team member's row — this person reaches it through the named group rather than on their own row; {group} is a group or team name
                    ? t('teamhub', 'Through {group}', { group: p.via })
                    // TRANSLATORS: chip on a sub-team member's row — this person reaches it through a group whose name could not be resolved
                    : t('teamhub', 'Through a group'),
                removable: false,
            }))
            return [...direct, ...inherited]
        },

        /**
         * Everyone on the parent's roster who is not already in.
         *
         * The subline goes **into the option's label**, joined with the same
         * ` · ` the server uses inside a subline. `NcSelect` renders `label`
         * and nothing else: no component in this app passes it a custom option
         * slot, and CLAUDE.md asks not to guess at a prop. One line here and
         * two lines in a `PersonRow` is the honest trade — what matters is
         * that the fields the administrator chose under *Telling people apart*
         * are on screen while you pick, which is what was missing.
         */
        candidateOptions() {
            const inAlready = new Set(this.people.map(p => p.userId))
            const stagedIds = new Set(this.staged.map(u => u.id))
            return this.candidates
                .filter(c => c.userId && !inAlready.has(c.userId) && !stagedIds.has(c.userId))
                .map(c => {
                    const name = c.displayName || c.userId
                    return {
                        id: c.userId,
                        label: c.subline ? `${name} · ${c.subline}` : name,
                    }
                })
        },

        /** Uids on the parent's roster — what makes somebody not an outsider. */
        parentRoster() {
            return new Set(this.candidates.map(c => c.userId).filter(Boolean))
        },

        canSave() {
            // `busy`, not `saving`: the template and the policy have their own
            // button, and Save must not run while that one is working.
            if (this.busy) {
                return false
            }
            const trimmed = this.name.trim()
            if (trimmed === '') {
                return false
            }
            return trimmed !== (this.subTeam.name || '')
                || this.description.trim() !== (this.subTeam.description || '')
                || this.expiresOn !== (this.subTeam.expiresOn || '')
                || this.visibility !== (this.subTeam.visibility || 'private')
                || this.picked.length > 0
                || this.staged.length > 0
        },
    },

    mounted() {
        this.loadMembers()
        this.loadCandidates()
        this.loadTemplates()
        this.loadPolicyProfiles()
    },

    beforeUnmount() {
        clearTimeout(this.searchTimer)
    },

    methods: {
        t,
        n,

        async loadMembers() {
            this.loadingMembers = true
            try {
                this.roster = await this.$store.dispatch('fetchSubTeamMembers', {
                    teamId: this.parentTeamId,
                    subTeamId: this.subTeam.id,
                })
            } catch (e) {
                this.error = this.messageOf(e, t('teamhub', 'Could not load the members'))
                this.roster = { direct: [], inherited: [] }
            } finally {
                this.loadingMembers = false
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

        /**
         * The same two endpoints `CreateSubTeamModal` reads, and neither is
         * admin-gated: `/api/v1/templates` is the wizard's own set and
         * `/api/v1/policy/creation` is every profile, because whoever creates
         * a team picks one (DESIGN §2.110).
         *
         * Both fail open. A select that does not render is better than a
         * dialog that will not open because a defaults fetch failed, and
         * everything else in here still works.
         */
        async loadTemplates() {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/templates'))
                this.templates = data.templates || []
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

        /**
         * Apply the template, the policy, or both.
         *
         * Only the keys that differ are sent, so the server's own "nothing
         * changed" arm is never relied on to be a no-op — and a template whose
         * apps failed to provision comes back with the failure in `errors`
         * rather than as an exception, because the label and the assignment
         * are already written and correct.
         *
         * A template change can delete a Talk room, a calendar, a Deck board,
         * an Intravox space or a Collective, which is what the warning above
         * the button says. It cannot delete the team folder.
         */
        async applyClassification() {
            if (!this.classificationChanging || this.busy) {
                return
            }
            this.applying = true
            this.error = ''
            this.report = null
            try {
                const changes = {}
                if (this.templateKey !== '' && this.templateKey !== this.appliedTemplateKey) {
                    changes.templateKey = this.templateKey
                }
                if (this.profileKey !== '' && this.profileKey !== this.appliedProfileKey) {
                    changes.profileKey = this.profileKey
                }
                const data = await this.$store.dispatch('updateSubTeam', {
                    teamId: this.parentTeamId,
                    subTeamId: this.subTeam.id,
                    ...changes,
                })

                // What the server confirms, not what was asked: a template
                // whose apps half-failed still changed the label, and the
                // selects have to agree with the row the panel now shows.
                const applied = data?.classification || {}
                this.appliedTemplateKey = applied.templateKey || this.appliedTemplateKey
                this.appliedProfileKey = applied.profileKey || this.appliedProfileKey
                this.templateKey = this.appliedTemplateKey
                this.profileKey = this.appliedProfileKey
                this.report = applied

                showSuccess(t('teamhub', 'Sub-team updated'))
                this.$emit('changed')
            } catch (e) {
                this.error = this.messageOf(e, t('teamhub', 'Could not change the template or the policy'))
            } finally {
                this.applying = false
            }
        },

        onSearch() {
            clearTimeout(this.searchTimer)
            this.results = []
            if (this.query.trim().length < 2) {
                this.searching = false
                return
            }
            this.searching = true
            this.searchTimer = setTimeout(this.runSearch, 300)
        },

        async runSearch() {
            try {
                const { data } = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/users/search'),
                    { params: { q: this.query.trim() } },
                )
                const inAlready = new Set(this.people.map(p => p.userId))
                const stagedIds = new Set(this.staged.map(u => u.id))
                this.results = (data || [])
                    // Local accounts only. A group or a team is not a person
                    // the two-list rule can classify, and the server would
                    // refuse one as an outsider it cannot test against a
                    // roster of uids.
                    .filter(u => (u.type || 'user') === 'user')
                    // Anyone on the main team belongs in the picker above, not
                    // here — which is the server's rule, kept out of the
                    // reader's way rather than enforced at them.
                    .filter(u => !this.parentRoster.has(u.id))
                    .filter(u => !inAlready.has(u.id) && !stagedIds.has(u.id))
                    // The subline is kept, not dropped: /api/v1/users/search
                    // goes through PersonSearchService, which already answers
                    // with the administrator's "Telling people apart" fields,
                    // and PersonRow renders them.
                    .map(u => ({ id: u.id, label: u.displayName || u.id, subline: u.subline || '' }))
            } catch (e) {
                this.results = []
            } finally {
                this.searching = false
            }
        },

        stage(item) {
            if (!this.staged.some(u => u.id === item.id)) {
                this.staged.push(item)
            }
            this.query = ''
            this.results = []
        },

        unstage(item) {
            this.staged = this.staged.filter(u => u.id !== item.id)
        },

        async remove(person) {
            this.error = ''
            try {
                await this.$store.dispatch('removeSubTeamMember', {
                    teamId: this.parentTeamId,
                    subTeamId: this.subTeam.id,
                    userId: person.userId,
                })
                showSuccess(t('teamhub', '{name} was removed', { name: person.displayName }))
                this.$emit('changed')
                await this.loadMembers()
            } catch (e) {
                this.error = this.messageOf(e, t('teamhub', 'Could not remove this member'))
            }
        },

        /**
         * Name, visibility and both member lists in one press. The order is
         * load-bearing only in one place: the rename runs before the member
         * adds, so a failed rename does not leave people added to a team whose
         * new name was refused.
         */
        async save() {
            if (!this.canSave) {
                return
            }
            this.saving = true
            this.error = ''
            try {
                const changes = {}
                const trimmed = this.name.trim()
                if (trimmed !== (this.subTeam.name || '')) {
                    changes.name = trimmed
                }
                if (this.description.trim() !== (this.subTeam.description || '')) {
                    changes.description = this.description.trim()
                }
                if (this.visibility !== (this.subTeam.visibility || 'private')) {
                    changes.visibility = this.visibility
                }
                // null clears it, and the server tells null from absent with
                // array_key_exists() - an empty picker means "no end date".
                if (this.expiresOn !== (this.subTeam.expiresOn || '')) {
                    changes.expiresOn = this.expiresOn || null
                }
                if (Object.keys(changes).length) {
                    await this.$store.dispatch('updateSubTeam', {
                        teamId: this.parentTeamId,
                        subTeamId: this.subTeam.id,
                        ...changes,
                    })
                }

                if (this.picked.length || this.staged.length) {
                    await this.$store.dispatch('addSubTeamMembers', {
                        teamId: this.parentTeamId,
                        subTeamId: this.subTeam.id,
                        members: this.picked.map(m => (typeof m === 'string' ? m : m.id)),
                        outsiders: this.staged.map(u => ({ id: u.id, type: 'user' })),
                    })
                }

                showSuccess(t('teamhub', 'Sub-team updated'))
                this.$emit('changed')
                this.$emit('close')
            } catch (e) {
                // The server's sentence is the useful one: the name rules and
                // the two-list rule are both enforced there and both answer in
                // words written for the reader.
                this.error = this.messageOf(e, t('teamhub', 'Could not save the changes'))
            } finally {
                this.saving = false
            }
        },

        messageOf(e, fallback) {
            return e?.response?.data?.error || fallback
        },
    },
}
</script>

<style scoped lang="scss">
.th-subman {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-lg);
    padding-block-end: var(--th-space-sm);
}

.th-subman__field {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    /* A <fieldset> carries a border and padding by default. */
    border: none;
    padding: 0;
    margin: 0;
    min-width: 0;
}

.th-subman__label {
    font-size: var(--th-font-meta);
    font-weight: var(--th-font-weight-medium);
    color: var(--color-text-maxcontrast);
    padding: 0;
}

.th-subman__hint {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    line-height: var(--th-line-height-body);
}

.th-subman__radio {
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

.th-subman__radio-title {
    font-size: var(--th-font-body);
}

.th-subman__people {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    list-style: none;
    padding: 0;
    margin: 0;
}

.th-subman__person {
    display: flex;
    align-items: center;
    gap: var(--th-space-md);
    padding: var(--th-space-xs) var(--th-space-sm);
    border-radius: var(--th-radius-control);

    &:hover {
        background: var(--color-background-hover);
    }
}

.th-subman__results {
    list-style: none;
    padding: 0;
    margin: 0;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-element);
    overflow: hidden;
}

.th-subman__result {
    /* PersonRow owns the row's own layout; this adds only the separator and
       the hover the list needs. */
    padding: var(--th-space-xs) var(--th-space-sm);

    &:not(:last-child) {
        border-block-end: 1px solid var(--color-border);
    }

    &:hover {
        background: var(--color-background-hover);
    }
}

.th-subman__chips {
    display: flex;
    flex-wrap: wrap;
    gap: var(--th-space-xs);
    margin-block-start: var(--th-space-xs);
}

/* The native date input, which /ui-standards keeps as a carve-out: this is
   NC's own recommended picker. `outline: none` on the base rule with a focus
   border is the NC pattern for the surviving native fields. */
.th-subman__date {
    align-self: flex-start;
    min-height: var(--default-clickable-area);
    padding-inline: var(--th-space-sm);
    border: 2px solid var(--color-border-maxcontrast);
    border-radius: var(--border-radius-element);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: var(--th-font-body);

    &:focus,
    &:focus-visible {
        border-color: var(--color-primary-element);
        outline: none;
    }
}

/* The field wrapper stretches its children; the Apply button is a control and
   should be as wide as its label, like every other button in the app. */
.th-subman__apply {
    align-items: flex-start;
}

/* One line per outcome of a template change, inside the note card. */
.th-subman__report {
    margin: 0;
    line-height: var(--th-line-height-body);

    &:not(:first-child) {
        margin-block-start: var(--th-space-xs);
    }
}
</style>
