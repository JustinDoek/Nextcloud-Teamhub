<template>
    <div class="ctv">
        <div class="ctv__inner">
            <div class="ctv__header">
                <h2 class="ctv__title">{{ t('teamhub', 'Create new team') }}</h2>
                <p v-if="mode === 'single'" class="ctv__subtitle">{{ wizardDescription || templateProfile.subtitle }}</p>
                <p v-else class="ctv__subtitle">{{ t('teamhub', 'Create several teams at once from a table') }}</p>
            </div>

            <!-- ── One team / Multiple teams ──────────────────────────────
                 v4.8.13 — shown whenever there is more than one mode, with the
                 bulk tab **disabled and explained** when the caller cannot use
                 it. It used to be hidden outright, which meant somebody who
                 needed the feature had no way to learn it existed or what it
                 wanted from them. Same rule as the profile editor: grey it out
                 and say why, rather than make the control vanish. -->
            <div v-if="showModeTabs" class="ctv__modes-tabs" role="tablist" :aria-label="t('teamhub', 'How many teams')">
                <button
                    v-for="tab in modeTabs"
                    :key="tab.id"
                    :class="['ctv__mode-tab', {
                        'ctv__mode-tab--active': mode === tab.id,
                        'ctv__mode-tab--disabled': tab.id === 'bulk' && !canBulkCreate,
                    }]"
                    role="tab"
                    :aria-selected="mode === tab.id ? 'true' : 'false'"
                    :disabled="tab.id === 'bulk' && !canBulkCreate"
                    @click="mode = tab.id">
                    {{ tab.label }}
                </button>
            </div>
            <p v-if="showModeTabs && !canBulkCreate" class="ctv__hint ctv__modes-reason">
                <LockOutline :size="14" aria-hidden="true" />
                {{ bulkUnavailableReason }}
            </p>

            <BulkCreateTeams
                v-if="mode === 'bulk'"
                :templates="templates"
                :profiles="policyProfiles"
                @done="$emit('cancel')" />

            <template v-else>

            <!-- Step indicator -->
            <div class="ctv__steps">
                <div v-for="(s, i) in steps" :key="i" class="ctv__step-wrap">
                    <div :class="['ctv__step', { 'ctv__step--active': step === i+1, 'ctv__step--done': step > i+1 }]">
                        <span class="ctv__step-num">{{ i+1 }}</span>
                        <span class="ctv__step-label">{{ s }}</span>
                    </div>
                    <div v-if="i < steps.length - 1" class="ctv__step-line" />
                </div>
            </div>

            <!-- ── STEP 1: Name, description, type ── -->
            <div v-if="currentStepKey === 'details'" class="ctv__section">
                <div class="ctv__field">
                    <NcTextField
                        v-model="form.name"
                        :label="t('teamhub', 'Team name')"
                        :placeholder="namePlaceholder"
                        :error="!!nameError"
                        :helper-text="nameError || ''" />
                </div>

                <div class="ctv__field">
                    <NcTextArea
                        v-model="form.description"
                        :label="t('teamhub', 'Description')"
                        :placeholder="t('teamhub', 'What is this team about?')"
                        :rows="3" />
                </div>

                <div class="ctv__field">
                    <label class="ctv__label">{{ t('teamhub', 'Team type') }}</label>
                    <div class="ctv__types">
                        <div
                            v-for="type in teamTypes"
                            :key="type.id"
                            :class="['ctv__type', 'ctv__type--' + type.accent, {
                                'ctv__type--selected': form.teamType === type.id,
                                'ctv__type--locked': type.locked,
                            }]"
                            role="button"
                            :tabindex="type.locked ? -1 : 0"
                            :aria-disabled="type.locked ? 'true' : 'false'"
                            :aria-pressed="form.teamType === type.id ? 'true' : 'false'"
                            :title="type.lockedReason || ''"
                            @click="!type.locked && (form.teamType = type.id)"
                            @keydown.enter.prevent="!type.locked && (form.teamType = type.id)"
                            @keydown.space.prevent="!type.locked && (form.teamType = type.id)">
                            <component :is="type.icon" :size="32" class="ctv__type-icon" />
                            <span class="ctv__type-name">
                                {{ type.label }}
                                <LockOutline v-if="type.locked" :size="14" class="ctv__mode-lock" />
                            </span>
                            <span class="ctv__type-desc">{{ type.description }}</span>
                        </div>
                    </div>
                    <!-- v4.9.3 — why the OpenProject card is locked, in the
                         words the capability check chose. -->
                    <span v-if="openProjectLockedReason" class="ctv__hint">{{ openProjectLockedReason }}</span>
                </div>

                <!-- ── Policy (v4.8.5) ────────────────────────────────────
                     Required, and the person creating the team picks it. The
                     template preselects one; changing the template moves the
                     selection with it unless the user has chosen otherwise.

                     Every creator may pick, not just an administrator
                     (Justin, 2026-09-01) — DESIGN §2.110. Reclassifying a team
                     afterwards is still a Nextcloud administrator's job. -->
                <div v-if="policyProfiles.length" class="ctv__field">
                    <label class="ctv__label" for="ctv-policy">{{ t('teamhub', 'Policy') }}</label>
                    <select
                        id="ctv-policy"
                        v-model="form.profileKey"
                        class="ctv__select"
                        :class="{ 'ctv__select--error': !!policyError }"
                        required
                        @change="policyTouched = true; policyError = ''">
                        <option value="" disabled>{{ t('teamhub', 'Choose a policy…') }}</option>
                        <option v-for="p in policyProfiles" :key="p.profileKey" :value="p.profileKey">
                            {{ p.label }}
                        </option>
                    </select>
                    <span v-if="policyError" class="ctv__error">{{ policyError }}</span>

                    <!-- v4.8.7 — the administrator's own words about this
                         policy, given room. It used to render as small grey
                         hint text indistinguishable from the sentence below
                         it; this is the thing the creator is supposed to read
                         before choosing. -->
                    <div v-if="activeProfile?.description" class="ctv__policy-desc">
                        {{ activeProfile.description }}
                    </div>
                    <span v-if="activeProfile" class="ctv__hint">
                        {{ t('teamhub', 'The policy sets this team\'s sharing and privacy settings. A team administrator can review them afterwards in Manage team.') }}
                    </span>
                </div>

                <!-- Project mode — only for the Project template -->
                <div v-if="form.teamType === 'project'" class="ctv__field">
                    <label id="project-mode-label" class="ctv__label">{{ t('teamhub', 'Project setup') }}</label>
                    <div class="ctv__modes" role="radiogroup" aria-labelledby="project-mode-label">
                        <div
                            v-for="m in projectModes"
                            :key="m.id"
                            :class="['ctv__mode', {
                                'ctv__mode--selected': form.projectMode === m.id,
                                'ctv__mode--locked':   m.locked,
                            }]"
                            role="radio"
                            :tabindex="m.locked ? -1 : 0"
                            :aria-checked="form.projectMode === m.id ? 'true' : 'false'"
                            :aria-disabled="m.locked ? 'true' : 'false'"
                            @click="!m.locked && (form.projectMode = m.id)"
                            @keydown.enter.prevent="!m.locked && (form.projectMode = m.id)"
                            @keydown.space.prevent="!m.locked && (form.projectMode = m.id)">
                            <span class="ctv__mode-name">
                                {{ m.label }}
                                <LockOutline v-if="m.locked" :size="14" class="ctv__mode-lock" />
                            </span>
                            <span class="ctv__mode-desc">{{ m.description }}</span>
                        </div>
                    </div>
                </div>

                <!-- v4.6.13 — optional expiration date.
                     Hidden for Department: a department is a standing part of
                     the organisation, not a piece of work with an end, so the
                     field would only invite a yearly ritual of extending
                     something that was never going to finish. -->
                <div v-if="expiryAvailable" class="ctv__field">
                    <label for="ctv-expires-on" class="ctv__label">
                        {{ isWorkspace ? t('teamhub', 'End date') : t('teamhub', 'Expiration date') }}
                        <span class="ctv__label-optional">{{ t('teamhub', '(optional)') }}</span>
                    </label>
                    <p id="ctv-expires-hint" class="ctv__hint">
                        {{ t('teamhub', 'Leave empty for a team with no end date. If you set one, the team keeps working after it passes — nothing is deleted — but its administrators and a Nextcloud administrator are reminded a week beforehand, and the date can be extended at any time.') }}
                    </p>
                    <div class="ctv__expiry-row">
                        <input
                            id="ctv-expires-on"
                            v-model="form.expiresOn"
                            type="date"
                            class="ctv__date-input"
                            :min="expiryMinDate"
                            aria-describedby="ctv-expires-hint"
                            @focus="onExpiryFocus" />
                        <NcButton
                            v-if="form.expiresOn"
                            variant="tertiary"
                            :aria-label="t('teamhub', 'Clear the expiration date')"
                            :title="t('teamhub', 'Clear the expiration date')"
                            @click="form.expiresOn = ''">
                            <template #icon><Close :size="20" /></template>
                        </NcButton>
                    </div>
                </div>

            </div>

            <!-- v4.8.5 — the Settings and Apps steps are gone.
                 Settings are the policy's: showing them here let a creator
                 pick values the policy then overwrote. Apps and modules are
                 the template's. The wizard now creates a team that is
                 compliant with its template and policy by construction; a
                 team admin who needs something different does it from Manage
                 team afterwards. Decided by Justin 2026-09-01. -->

            <!-- ── STEP 2: Members (was step 3; Settings and Apps removed in v4.8.5) ── -->
            <div v-if="currentStepKey === 'members'" class="ctv__section">
                <div class="ctv__field">
                    <p class="ctv__hint">{{ t('teamhub', 'Invite people to join this team. You can also add members later.') }}</p>
                    <div class="ctv__member-search">
                        <NcTextField
                            v-model="memberSearch"
                            :label="t('teamhub', 'Search members')"
                            :placeholder="t('teamhub', 'Search by name or username...')"
                            @input="onMemberSearch" />
                        <div v-if="userResults.length > 0" class="ctv__user-results">
                            <div
                                v-for="user in userResults"
                                :key="(user.type || 'user') + ':' + user.id"
                                class="ctv__user-result"
                                @click="addMember(user)">
                                <div v-if="user.type === 'group'" class="ctv__group-avatar">
                                    <AccountGroup :size="20" />
                                </div>
                                <NcAvatar v-else :user="user.id" :display-name="user.displayName" :size="32" :show-user-status="false" />
                                <div class="ctv__user-info">
                                    <span class="ctv__user-name">{{ user.displayName }}</span>
                                    <span class="ctv__user-id">{{ user.type === 'group' ? t('teamhub', 'Group') : user.id }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- v4.8.7 — members carry a role. A list rather than the
                         old chip row: a chip is the wrong shape once each one
                         needs a control beside it. -->
                    <ul v-if="form.members.length > 0" class="ctv__members">
                        <li
                            v-for="m in form.members"
                            :key="(m.type || 'user') + ':' + m.id"
                            class="ctv__member">
                            <div v-if="m.type === 'group'" class="ctv__group-avatar ctv__group-avatar--small">
                                <AccountGroup :size="16" aria-hidden="true" />
                            </div>
                            <NcAvatar v-else :user="m.id" :display-name="m.displayName" :size="24" :show-user-status="false" />

                            <span class="ctv__member-name">{{ m.displayName }}</span>

                            <label class="ctv__sr" :for="'ctv-role-' + (m.type || 'user') + '-' + m.id">
                                {{ t('teamhub', 'Role for {name}', { name: m.displayName }) }}
                            </label>
                            <select
                                :id="'ctv-role-' + (m.type || 'user') + '-' + m.id"
                                :value="m.level || 1"
                                class="ctv__select ctv__select--role"
                                @change="setMemberLevel(m, Number($event.target.value))">
                                <option :value="1">{{ t('teamhub', 'Member') }}</option>
                                <option :value="4">{{ t('teamhub', 'Moderator') }}</option>
                                <option :value="8">{{ t('teamhub', 'Team admin') }}</option>
                                <!-- A group cannot act, so it cannot own.
                                     Greyed rather than hidden, with the reason
                                     below, per the rule on the profile editor. -->
                                <option :value="9" :disabled="m.type === 'group'">
                                    {{ t('teamhub', 'Team owner') }}
                                </option>
                            </select>

                            <button class="ctv__chip-remove"
                                :aria-label="t('teamhub', 'Remove {name}', { name: m.displayName })"
                                :title="t('teamhub', 'Remove {name}', { name: m.displayName })"
                                @click="removeMember(m.id, m.type)">
                                <Close :size="14" aria-hidden="true" />
                            </button>
                        </li>
                    </ul>

                    <p v-if="form.members.some(m => m.type === 'group')" class="ctv__hint">
                        {{ t('teamhub', 'A group cannot own a team, so Team owner is unavailable for groups.') }}
                    </p>

                    <!-- v4.8.10 — says what actually happens to the creator.
                         The default is that they leave: somebody provisioning
                         teams to a policy is not a member of them. Adding
                         yourself to the list is how you stay. -->
                    <p v-if="appointedOwner" class="ctv__policy-note">
                        <AccountArrowRight :size="16" aria-hidden="true" />
                        {{ creatorStaysOnTeam
                            ? t('teamhub', '{name} becomes the owner at the end. You stay on the team, because you added yourself to the list.', { name: appointedOwner.displayName })
                            : t('teamhub', '{name} becomes the owner at the end and you leave the team. Add yourself to the list above if you want to stay on it.', { name: appointedOwner.displayName }) }}
                    </p>

                    <!-- v4.9.6 — the OpenProject Workspace template: every
                         member with the OpenProject role the mapping gives
                         them, and a decision for those without an account. -->
                    <ProvisioningRolePreview
                        v-if="isWorkspace"
                        :mode="form.openProjectSetup.mode || 'create'"
                        :project-id="form.openProjectSetup.project?.id || 0"
                        :members="form.members"
                        :apps="workspaceApps"
                        :decisions="form.decisions"
                        @update:decisions="form.decisions = $event"
                        @ready="rolesReady = $event" />
                </div>
            </div>

            <!-- ── v4.9.6 — OpenProject Workspace: the project ── -->
            <div v-if="currentStepKey === 'openproject'" class="ctv__section">
                <div v-if="provisioningLoading" class="ctv__progress-task">
                    <NcLoadingIcon :size="20" />
                    <span class="ctv__progress-label">{{ t('teamhub', 'Asking OpenProject what is available to you') }}</span>
                </div>
                <p v-else-if="provisioningOptionsError" class="ctv__error" role="alert">{{ provisioningOptionsError }}</p>
                <OpenProjectSetupStep
                    v-else-if="provisioningOptions"
                    v-model="form.openProjectSetup"
                    :options="provisioningOptions"
                    :team-name="form.name"
                    :error="openProjectError"
                    @identifier-state="identifierState = $event" />
            </div>

            <!-- ── v4.9.6 — OpenProject Workspace: review ── -->
            <div v-if="currentStepKey === 'review'" class="ctv__section">
                <ProvisioningReviewStep :summary="reviewSummary" />
            </div>

            <!-- ── v4.9.6 — OpenProject Workspace: the operation, live ──
                 Driven by the server (POST /provisioning, then run until it
                 rests); survives a reload because the operation id is the
                 state, not this component. -->
            <div v-if="currentStepKey === 'progress' && isWorkspace && !creationDone" class="ctv__progress ctv__progress--workspace">
                <ProvisioningProgress
                    :state="provisioningState"
                    :can-act="true"
                    :busy="provisioningBusy"
                    @retry="retryProvisioning"
                    @continue="pumpProvisioning"
                    @rollback="rollbackProvisioning" />
                <div v-if="provisioningState && !provisioningActive" class="ctv__done-actions">
                    <NcButton v-if="provisioningState.status === 'attention' && provisioningState.teamId" variant="primary" @click="finishWorkspace">
                        <template #icon><ArrowRight :size="20" /></template>
                        {{ t('teamhub', 'Open the workspace anyway') }}
                    </NcButton>
                    <NcButton v-if="provisioningState.status === 'rolled_back' || (provisioningState.status === 'failed' && !provisioningState.teamId)" variant="secondary" @click="backToReview">
                        {{ t('teamhub', 'Back to the review') }}
                    </NcButton>
                </div>
            </div>

            <!-- ── STEP 3: Progress ── -->
            <div v-if="currentStepKey === 'progress' && !isWorkspace && !creationDone" class="ctv__progress">
                <div v-for="(task, i) in progressTasks" :key="i" class="ctv__progress-task">
                    <NcLoadingIcon v-if="task.status === 'running'" :size="20" />
                    <CheckCircle v-else-if="task.status === 'done'" :size="20" class="ctv__progress-done" />
                    <AlertCircle v-else-if="task.status === 'error'" :size="20" class="ctv__progress-error" />
                    <span v-else class="ctv__progress-dot" />
                    <span :class="['ctv__progress-label', { 'ctv__progress-label--dim': task.status === 'waiting' }]">
                        {{ task.label }}
                    </span>
                </div>
            </div>

            <!-- ── Success hand-off (v4.4.5, onboarding plan § 3.3) ──
                 The wizard used to end on the finished progress list with a
                 single "Open team" button in the footer, which is the moment
                 the new owner's intent is highest and the least is offered.
                 This replaces it with what was actually provisioned plus the
                 three things worth doing next. The recommended action moves
                 depending on whether step 3 was skipped: a team with no
                 members needs people before anything else is useful. -->
            <div v-else-if="currentStepKey === 'progress' && creationDone" class="ctv__done">
                <div class="ctv__done-head">
                    <CheckCircle :size="40" class="ctv__done-icon" aria-hidden="true" />
                    <!-- TRANSLATORS: success heading after a team is created.
                         {team} is the team's own name as typed by the user
                         (e.g. "Marketing"), not the word "team". -->
                    <h3 class="ctv__done-title">
                        {{ t('teamhub', '{team} is ready', { team: form.name.trim() }) }}
                    </h3>
                </div>

                <!-- v4.8.7 — a handover that did not stick is the one outcome
                     the reader must not miss. Shown at the top of the success
                     screen, because everything below it says "ready". -->
                <p v-if="roleResult?.owner?.status === 'failed'" class="ctv__done-warn">
                    <AlertCircle :size="18" aria-hidden="true" />
                    {{ t('teamhub', 'The team was created, but ownership could not be transferred. You are still the owner — you can hand over from Manage team.') }}
                </p>
                <!-- v4.9.6 — a workspace that finished with something to look at
                     is usable but not done; the team page keeps saying so. -->
                <p v-else-if="isWorkspace && provisioningState?.status === 'attention'" class="ctv__done-warn">
                    <AlertCircle :size="18" aria-hidden="true" />
                    {{ t('teamhub', 'The workspace is usable, but part of the setup needs attention. The team page shows what, and lets you retry.') }}
                </p>
                <p v-else-if="roleResult?.owner?.status === 'transferred'" class="ctv__done-note">
                    <AccountArrowRight :size="18" aria-hidden="true" />
                    {{ creatorLeftTeam
                        ? t('teamhub', '{name} is now the owner and you have left the team.', { name: appointedOwnerName })
                        : t('teamhub', '{name} is now the owner of this team.', { name: appointedOwnerName }) }}
                </p>

                <p v-if="provisioned.length" class="ctv__done-summary">
                    {{ t('teamhub', 'Set up for this team:') }}
                    <span class="ctv__done-chips">
                        <span v-for="item in provisioned" :key="item" class="ctv__done-chip">{{ item }}</span>
                    </span>
                </p>

                <!-- v4.8.10 — none of the hand-off actions work once the
                     creator has left: Open team, Invite people and Review apps
                     all need membership. Offering them would hand the reader
                     three buttons that fail. -->
                <template v-if="creatorLeftTeam">
                    <p class="ctv__done-hint">
                        {{ t('teamhub', '{name} can now set the team up further. You are no longer a member, so there is nothing left for you to do here.', { name: appointedOwnerName }) }}
                    </p>
                    <div class="ctv__done-actions">
                        <NcButton variant="primary" @click="$emit('cancel')">
                            <template #icon><Check :size="20" /></template>
                            {{ t('teamhub', 'Done') }}
                        </NcButton>
                    </div>
                </template>

                <template v-else>
                    <p class="ctv__done-next-label">{{ t('teamhub', 'What’s next') }}</p>
                    <div class="ctv__done-actions">
                        <NcButton
                            :variant="noMembersInvited ? 'primary' : 'secondary'"
                            @click="finish('invite')">
                            <template #icon><AccountPlus :size="20" /></template>
                            {{ noMembersInvited ? t('teamhub', 'Invite people') : t('teamhub', 'Invite more people') }}
                        </NcButton>
                        <NcButton variant="secondary" @click="finish('manage')">
                            <template #icon><CogOutline :size="20" /></template>
                            {{ t('teamhub', 'Review team apps') }}
                        </NcButton>
                        <NcButton
                            :variant="noMembersInvited ? 'secondary' : 'primary'"
                            @click="finish(null)">
                            <template #icon><ArrowRight :size="20" /></template>
                            {{ t('teamhub', 'Open team') }}
                        </NcButton>
                    </div>
                    <p class="ctv__done-hint">
                        {{ noMembersInvited
                            ? t('teamhub', 'This team has no members yet. You can also invite people later from the team menu in the sidebar.')
                            : t('teamhub', 'You can rearrange the team’s tabs from the tab bar on its home page.') }}
                    </p>
                </template>
            </div>

            </template><!-- /single-team mode -->
        </div>

        <!-- Footer — always at bottom.
             v4.4.5: hidden once creation completes. The success panel owns the
             hand-off actions now, and leaving an "Open team" button here too
             would duplicate the one in that panel.
             v4.8.9: the bulk table owns its own actions, so no footer there. -->
        <div v-if="mode === 'single' && currentStepKey !== 'progress'" class="ctv__footer">
            <NcButton variant="tertiary" @click="$emit('cancel')">
                {{ t('teamhub', 'Cancel') }}
            </NcButton>
            <div class="ctv__footer-right">
                <NcButton v-if="step > 1" variant="secondary" @click="step--">
                    {{ t('teamhub', 'Back') }}
                </NcButton>
                <NcButton v-if="currentStepKey !== lastFormStepKey" variant="primary" @click="nextStep">
                    {{ t('teamhub', 'Next') }}
                </NcButton>
                <NcButton v-if="currentStepKey === lastFormStepKey" variant="primary" @click="submit">
                    <template #icon><Check :size="20" /></template>
                    {{ t('teamhub', 'Create team') }}
                </NcButton>
            </div>
        </div>
    </div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { shiftToday } from '../lib/localDate.js'
import { generateUrl } from '@nextcloud/router'
// v4.8.10 — to tell whether the creator named themselves as a member, which
// decides whether they stay on the team after handing it over.
import { getCurrentUser } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { NcButton, NcTextField, NcTextArea, NcAvatar, NcLoadingIcon, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Chat from 'vue-material-design-icons/Chat.vue'
import Folder from 'vue-material-design-icons/Folder.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import CardText from 'vue-material-design-icons/CardText.vue'
import Briefcase from 'vue-material-design-icons/Briefcase.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
// v4.8.7 — the handover note on the members step.
import AccountArrowRight from 'vue-material-design-icons/AccountArrowRight.vue'
// v4.8.9 — the bulk-create table, mounted from the "Multiple teams" tab.
import BulkCreateTeams from './BulkCreateTeams.vue'
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'
import Gavel from 'vue-material-design-icons/Gavel.vue'
import AccountClock from 'vue-material-design-icons/AccountClock.vue'
import TimelineClockOutline from 'vue-material-design-icons/TimelineClockOutline.vue'
import MessageOutline from 'vue-material-design-icons/MessageOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import BookOpenOutline from 'vue-material-design-icons/BookOpenOutline.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import BriefcaseCheckOutline from 'vue-material-design-icons/BriefcaseCheckOutline.vue'
// v4.9.3 — OpenProject template: the project picker.
import OpenProjectProjectPicker from './OpenProjectProjectPicker.vue'
import { CODES as OP_CODES, errorMessage as opErrorMessage, classifyError as opClassifyError, isModuleCode as isOpModuleCode } from '../lib/openProject.js'
// v4.9.6 — Phase 2: the OpenProject Workspace flow (blueprint-driven,
// server-side provisioning). The step components and the pump.
import OpenProjectSetupStep from './OpenProjectSetupStep.vue'
import ProvisioningRolePreview from './ProvisioningRolePreview.vue'
import ProvisioningReviewStep from './ProvisioningReviewStep.vue'
import ProvisioningProgress from './ProvisioningProgress.vue'
import {
    classifyComponent, componentLabel, isActive as provisioningIsActive, newIdempotencyKey,
    pumpOperation, roleLabel,
} from '../lib/provisioning.js'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'

// Canonical Circles config bit values — see src/constants/circlesConfig.js
import {
    CFG_VISIBLE,
    CFG_OPEN,
    CFG_INVITE,
    CFG_REQUEST,
    CFG_PROTECTED,
} from '../constants/circlesConfig.js'

/**
 * Wizard config key → policy field key (v4.8.4).
 *
 * The wizard's keys are short (`open`), the policy field keys are prefixed
 * (`cfg_open`). An explicit map rather than a prefix convention: a convention
 * would silently start governing any future wizard config key that happened to
 * match a field name. `cfg_root` has no wizard control and so is absent.
 */
const CONFIG_FIELD_KEYS = {
    open: 'cfg_open',
    invite: 'cfg_invite',
    request: 'cfg_request',
    visible: 'cfg_visible',
    protected: 'cfg_protected',
}

export default {
    name: 'CreateTeamView',
    components: {
        NcButton, NcTextField, NcTextArea, NcAvatar, NcLoadingIcon, NcCheckboxRadioSwitch,
        Check, Close, CheckCircle, AlertCircle,
        Chat, Folder, Calendar, CardText, Briefcase, AccountMultiple, AccountGroup, AccountArrowRight, OfficeBuildingOutline,
        Gavel, AccountClock, TimelineClockOutline, FileDocumentOutline, BookOpenOutline, LockOutline,
        BriefcaseCheckOutline, OpenProjectProjectPicker,
        AccountPlus, CogOutline, ArrowRight,
        BulkCreateTeams,
        OpenProjectSetupStep, ProvisioningRolePreview, ProvisioningReviewStep, ProvisioningProgress,
    },
    emits: ['created', 'cancel'],
    data() {
        return {
            step: 1,
            nameError: '',
            memberSearch: '',
            userResults: [],
            searchTimer: null,
            progressTasks: [],
            intravoxAvailable: false,
            collectivesAvailable: false,
            talkAvailable: true,
            calendarAvailable: true,
            deckAvailable: true,
            groupfoldersAvailable: false,
            presenceModuleEnabled: false,
            decisionsModuleEnabled: false,
            // v4.8.3 — the admin-editable template rows, fetched once in
            // mounted() from GET /api/v1/templates. This is what replaced the
            // hand-maintained `templateProfile()` copy of the PHP constants;
            // empty until the fetch lands, and empty on failure, which
            // preselects nothing rather than blocking the wizard.
            templates: [],
            // v4.8.5 — every policy, for every creator. Required field; the
            // template preselects one. Empty until the fetch lands and on
            // failure, in which case the field is not rendered and the team is
            // created unclassified rather than the wizard refusing to open.
            policyProfiles: [],
            policyError: '',
            // v4.8.9 — 'single' | 'bulk'. The tab only renders when
            // canBulkCreate is true, so an instance without the licence or the
            // permission sees the wizard exactly as before.
            mode: 'single',
            canBulkCreate: false,
            bulkLicensed: false,
            bulkPermitted: false,
            // v4.8.7 — what the creation-roles call reported. Read on the
            // success screen; a failed handover has to be visible there,
            // because everything else on that screen says the team is ready.
            roleResult: null,
            // Captured before the transfer, because the member list is not
            // re-read afterwards and the success screen still has to name them.
            appointedOwnerName: '',
            // Set once the user picks for themselves, so switching template no
            // longer moves their choice out from under them.
            policyTouched: false,
            wizardDescription: '',
            // v4.6.13 — 'YYYY-MM-DD', six months out, computed server-side and
            // delivered with the admin settings. Only used when the user opens
            // the expiry picker; empty until the settings fetch lands.
            expiryDefaultDate: '',
            creationDone: false,
            createdTeam: null,
            // v3.100.1 — cheap license entitlements probe. Fetched
            // once in mounted() from GET /license/entitlements (member-
            // callable, minimal payload). Drives whether the "Advanced"
            // project-mode tile is selectable or shown as a locked upsell.
            licenseCanCreateAdvanced: true,   // optimistic default; corrected on mount
            // v4.9.3 — the OpenProject capability check for the creator. Null
            // until loaded; the OpenProject card is locked while it says the
            // integration cannot be used by this person.
            openProjectCaps: null,
            // The sentence under the project picker: a missing pick at
            // step 1, or (v4.9.4) the reason the create call refused the
            // project — the wizard comes back here with no team made.
            openProjectError: '',
            // v4.9.6 — Phase 2. The answer of GET /provisioning/options for
            // the OpenProject Workspace template (capabilities, templates,
            // components, role mapping); null until loaded, and on failure
            // `provisioningOptionsError` says why the step cannot render.
            provisioningOptions: null,
            provisioningOptionsError: '',
            provisioningLoading: false,
            // What the identifier field last learned from OpenProject.
            identifierState: 'unknown',
            // Whether the role preview is satisfied (every unmatched member
            // has a decision); the Next button on the members step waits.
            rolesReady: true,
            // The request key for this submission, made once when the review
            // step is reached so a double click makes one workspace.
            provisioningKey: '',
            // The operation as the server reports it, while it runs and after.
            provisioningState: null,
            provisioningBusy: false,
            form: {
                name: '',
                description: '',
                teamType: 'collaboration',
                // v4.8.4 — the classification an NC admin picked. Empty means
                // unclassified; ignored for everyone else, who get the instance
                // default applied server-side whatever this holds.
                profileKey: '',
                // Project Teams (v3.88.0) — only meaningful when teamType==='project'.
                // 'advanced' = guided PMC lifecycle (default, "force into project mode");
                // 'basic' = the historical cosmetic project preset, still recorded.
                projectMode: 'advanced',
                // v4.9.3 — the OpenProject project summary picked for the
                // OpenProject template ({ id, identifier, name, … }), or null.
                // Since v4.9.6 the OpenProject template runs the workspace
                // flow below and leaves this null; kept for the classic
                // create call's shape.
                openProject: null,
                // v4.9.6 — the OpenProject Workspace flow's own fields, all
                // on the OpenProject step (one place for everything
                // OpenProject — Justin, 2026-09-13). The apps and modules are
                // the template's and are not a field at all.
                openProjectSetup: {
                    mode: '', identifier: '', templateId: null, template: null,
                    parentId: null, parent: null, project: null,
                    visibility: 'private', startDate: '', category: '',
                },
                // "type:id" → 'teamhub_only' | 'omit' for members without an
                // OpenProject account.
                decisions: {},
                // v4.6.13 — optional expiration date as 'YYYY-MM-DD'.
                // Empty is the default and means "no end date"; the six-month
                // default only appears once the user actually opens the picker,
                // so an untouched field never quietly commits the team to a
                // deadline nobody chose.
                expiresOn: '',
                members: [],
                apps: {
                    talk:     { mode: null, resourceId: null, name: '' },
                    files:    { mode: null, resourceId: null, name: '' },
                    calendar: { mode: null, resourceId: null, name: '' },
                    deck:     { mode: null, resourceId: null, name: '' },
                },
                modules: {
                    decisions: true,
                    presence: false,
                    timeline: false,
                    messages: true,
                    pages: true,
                    // v4.3.6 — Wiki (Collectives) module. Default off so
                    // teams opt in; heavier substrate than Intranet.
                    wiki: false,
                },
                config: {
                    open: false,         // anyone can join
                    invite: true,        // members can invite
                    request: false,      // requests need approval
                    visible: false,      // visible to all
                    protected: false,    // password-protect shared files
                },
            },
        }
    },
    computed: {
        /**
         * v4.9.6 — the OpenProject template runs the workspace flow: five
         * steps instead of two, and a server-driven operation at the end.
         * Every other template is exactly as before.
         */
        isWorkspace() {
            return this.form.teamType === 'openproject'
        },
        /** The step keys, in order; the last one is the progress screen. */
        stepKeys() {
            return this.isWorkspace
                ? ['details', 'openproject', 'members', 'review', 'progress']
                : ['details', 'members', 'progress']
        },
        currentStepKey() {
            return this.stepKeys[this.step - 1] || 'details'
        },
        /** The step whose footer button submits. */
        lastFormStepKey() {
            return this.stepKeys[this.stepKeys.length - 2]
        },
        steps() {
            return this.isWorkspace
                ? [t('teamhub', 'Details'), t('teamhub', 'OpenProject'), t('teamhub', 'Members and roles'), t('teamhub', 'Review')]
                : [t('teamhub', 'Details'), t('teamhub', 'Members')]
        },
        provisioningActive() {
            return provisioningIsActive(this.provisioningState?.status)
        },
        // The template's applications (the row's; every one of them), minus
        // what is not installed or not created.
        workspaceApps() {
            const comps = this.provisioningOptions?.components || []
            return comps
                .filter(c => c.kind === 'app' && c.installed && c.action !== 'none')
                .map(c => c.id)
        },
        /**
         * The review step's summary: everything that will be created or
         * linked, classified, plus members with both roles.
         */
        reviewSummary() {
            const comps = this.provisioningOptions?.components || []
            const setup = this.form.openProjectSetup
            const resources = []
            if (setup.mode === 'link' && setup.project) {
                resources.push({ id: 'openproject', label: t('teamhub', 'OpenProject project'), kind: 'link', note: setup.project.name })
            } else {
                resources.push({
                    id: 'openproject',
                    label: t('teamhub', 'OpenProject project'),
                    kind: 'new',
                    note: setup.template
                        ? t('teamhub', '{identifier}, from the template {template}', { identifier: setup.identifier, template: setup.template.name })
                        : t('teamhub', '{identifier}, without a template', { identifier: setup.identifier }),
                })
            }
            resources.push({ id: 'team', label: t('teamhub', 'TeamHub team'), kind: 'new', note: this.form.name.trim() })
            for (const c of comps) {
                // Every component the template lists is wanted; the row decides.
                const kind = c.kind === 'module'
                    ? (c.missing ? 'unavailable' : 'new')
                    : classifyComponent(c, true)
                let note = ''
                if (kind === 'link') note = t('teamhub', 'The project folder OpenProject manages')
                if (kind === 'required-missing') note = t('teamhub', 'An administrator has to install this first')
                if (c.id === 'files' && kind === 'new') note = t('teamhub', 'Team folder; the OpenProject project folder is linked when it exists')
                resources.push({ id: c.kind + ':' + c.id, label: componentLabel(c.id), kind, note })
            }
            resources.push({ id: 'dashboard', label: componentLabel('dashboard'), kind: 'new', note: t('teamhub', 'Project info, files, conversation, calendar, knowledge and quick actions') })

            const mapping = this.provisioningOptions?.roleMapping || {}
            const members = this.form.members.map(m => {
                const key = (m.type || 'user') + ':' + m.id
                const roleKey = (m.type || 'user') !== 'user' && (m.type || 'user') !== 'group' ? 'guest'
                    : (m.level >= 9 ? 'owner' : m.level >= 8 ? 'admin' : m.level >= 4 ? 'moderator' : 'member')
                const decision = this.form.decisions[key]
                const opRole = mapping[roleKey]?.name
                let note = opRole ? t('teamhub', 'OpenProject: {role}', { role: opRole }) : t('teamhub', 'No OpenProject access')
                if (decision === 'teamhub_only') note = t('teamhub', 'Team only — no OpenProject account')
                return { id: m.id, type: m.type || 'user', displayName: m.displayName || m.id, teamRole: roleKey, note, omitted: decision === 'omit' }
            })

            const warnings = []
            if ((this.provisioningOptions?.missingRequired || []).length) {
                warnings.push(t('teamhub', 'A required application is not installed: {apps}.', { apps: this.provisioningOptions.missingRequired.map(componentLabel).join(', ') }))
            }
            for (const [key, role] of Object.entries(mapping)) {
                if (role?.missing) {
                    warnings.push(t('teamhub', 'The OpenProject role "{role}" for {teamRole} does not exist in this OpenProject.', { role: role.name, teamRole: roleLabel(key) }))
                }
            }
            if (setup.mode === 'create' && this.identifierState === 'taken') {
                warnings.push(t('teamhub', 'The project identifier is already taken in OpenProject.'))
            }

            return {
                name: this.form.name.trim(),
                description: this.form.description.trim(),
                policyLabel: this.activeProfile?.label || '',
                startDate: this.form.openProjectSetup.startDate,
                endDate: this.form.expiresOn,
                category: this.form.openProjectSetup.category,
                visibility: this.form.openProjectSetup.visibility,
                resources,
                members,
                ownerName: this.appointedOwner?.displayName || '',
                warnings,
            }
        },

        modeTabs() {
            return [
                { id: 'single', label: t('teamhub', 'One team') },
                { id: 'bulk', label: t('teamhub', 'Multiple teams') },
            ]
        },
        teamTypes() {
            const types = [
                { id: 'project', label: t('teamhub', 'Project'), description: t('teamhub', 'Time-bound work with clear goals'), icon: 'Briefcase', accent: 'project' },
                { id: 'collaboration', label: t('teamhub', 'Collaboration'), description: t('teamhub', 'Ongoing team knowledge sharing'), icon: 'AccountMultiple', accent: 'collaboration' },
                { id: 'department', label: t('teamhub', 'Department'), description: t('teamhub', 'Organizational department or unit'), icon: 'OfficeBuildingOutline', accent: 'department' },
            ]
            // v4.9.16 — no card at all while TeamHub's OpenProject module is
            // unlicensed or switched off: a module that is off is hidden, not
            // explained (the Presence and Decisions rule). Locked-with-reason
            // below is for the module being on and *this creator* unable to
            // use it yet.
            if (!this.openProjectModuleOff) {
                // v4.9.3 — a project whose engine is OpenProject. Locked, with
                // the reason, when the creator cannot use the integration:
                // the project must be picked here, so a card that cannot
                // finish is a card that must say so before it is clicked.
                types.push({
                    id: 'openproject',
                    label: t('teamhub', 'OpenProject project'),
                    description: t('teamhub', 'A project managed in OpenProject'),
                    icon: 'BriefcaseCheckOutline',
                    accent: 'openproject',
                    locked: !!this.openProjectLockedReason,
                    lockedReason: this.openProjectLockedReason,
                })
            }
            return types
        },

        /**
         * v4.9.16 — the capability check said TeamHub's OpenProject module is
         * unlicensed or switched off. Null capabilities (still loading) are
         * not "off": the card is locked meanwhile, not absent, so the row of
         * cards does not reflow when the answer arrives on an instance that
         * has the module.
         */
        openProjectModuleOff() {
            if (this.openProjectCaps === null) return false
            if (this.openProjectCaps.moduleAvailable === false) return true
            return isOpModuleCode(this.openProjectCaps.errorCode)
        },

        /**
         * v4.9.3 — why the OpenProject template cannot be chosen right now,
         * or '' when it can. Null capabilities (still loading) lock it too:
         * a card that unlocks a second later is better than one that lets
         * the user pick a template they then cannot finish.
         */
        openProjectLockedReason() {
            if (this.openProjectCaps === null) return ''
            if (!this.openProjectCaps.errorCode) return ''
            return this.openProjectCaps.userMessage || opErrorMessage(this.openProjectCaps.errorCode)
        },
        /**
         * Whether this kind of team can be given an expiration date.
         *
         * **v4.8.3 — read off the template row**, which an administrator sets
         * on Admin → TeamHub → Policy ("Enable team expiration"). It used to be
         * hard-coded to Collaboration and Project, mirroring
         * `TeamExpiryService::ELIGIBLE_TYPES`.
         *
         * Falls back to the old rule while `templates` is still empty, so the
         * field does not flicker in and then out during the mount fetch.
         *
         * The server agrees: `TeamExpiryService::isEligibleTemplate()` reads
         * the same row, so enabling expiration on the Department template both
         * shows the field here and has the date accepted there. The two used to
         * be a hard-coded pair in two places, which is exactly the shape of bug
         * this change exists to remove.
         */
        expiryAvailable() {
            if (this.templates.length) {
                return !!this.activeTemplate?.expiryEnabled
            }
            return this.form.teamType === 'collaboration' || this.form.teamType === 'project'
        },

        /**
         * The earliest date the picker will accept: tomorrow. The server
         * rejects anything at or before now, and letting the browser refuse it
         * first saves a round trip to be told so.
         */
        expiryMinDate() {
            return shiftToday({ days: 1 })
        },

        projectModes() {
            const advancedLocked = !this.licenseCanCreateAdvanced
            return [
                {
                    id: 'advanced',
                    // TRANSLATORS: project setup mode — the full guided project experience
                    label: t('teamhub', 'Advanced'),
                    description: advancedLocked
                        // TRANSLATORS: shown on the Advanced project mode tile when the instance has no valid business license
                        ? t('teamhub', 'This feature requires a license — ask your admin.')
                        : t('teamhub', 'Guided project lifecycle — phases (Initiation, Planning, Execution, Closing) and project tools.'),
                    locked: advancedLocked,
                },
                {
                    id: 'basic',
                    // TRANSLATORS: project setup mode — the simple, no-lifecycle project experience
                    label: t('teamhub', 'Basic'),
                    description: t('teamhub', 'A project-flavoured team with the familiar setup — no lifecycle tools.'),
                    locked: false,
                },
            ]
        },
        appOptions() {
            const filesDesc = this.groupfoldersAvailable
                ? t('teamhub', 'Create a team folder for this team')
                : t('teamhub', 'Create a shared folder for this team')
            const all = [
                { id: 'talk',     label: 'Talk',     description: t('teamhub', 'Create a group conversation for this team'), icon: Chat,     available: this.talkAvailable },
                { id: 'files',    label: 'Files',    description: filesDesc,                                                  icon: Folder,   available: true },
                { id: 'calendar', label: 'Calendar', description: t('teamhub', 'Create a shared calendar for this team'),     icon: Calendar, available: this.calendarAvailable },
                { id: 'deck',     label: 'Deck',     description: t('teamhub', 'Create a task board for this team'),          icon: CardText, available: this.deckAvailable },
            ]
            // v4.8.4 — an integration the classification does not permit is
            // removed, not disabled. A disabled row invites the user to wonder
            // what they would have to do to enable it; the answer is "be a
            // different kind of team", which is not an action. The server
            // filters the same list in ResourceService, so this is the
            // courtesy half — DESIGN §2.103 on why the UI is never the gate.
            const allowed = this.allowedIntegrations
            return all
                .filter(a => a.available)
                .filter(a => allowed === null || allowed.includes(a.id))
        },
        moduleOptions() {
            return [
                {
                    id: 'decisions',
                    label: t('teamhub', 'Decisions'),
                    description: t('teamhub', 'Track and record team decisions'),
                    icon: Gavel,
                    available: this.decisionsModuleEnabled,
                },
                {
                    id: 'presence',
                    label: t('teamhub', 'Presence'),
                    description: t('teamhub', 'Track team member availability'),
                    icon: AccountClock,
                    available: this.presenceModuleEnabled,
                },
                {
                    id: 'timeline',
                    label: t('teamhub', 'Timeline'),
                    description: t('teamhub', 'Visualize team activity over time'),
                    icon: TimelineClockOutline,
                    available: true,
                },
                {
                    id: 'messages',
                    label: t('teamhub', 'Messages'),
                    description: t('teamhub', 'Team message stream — posts, questions, polls, pinned messages'),
                    icon: MessageOutline,
                    available: true,
                },
            ]
        },

        /**
         * v4.4.10 — Intravox + Collective sit in the App integrations block,
         * not the Team modules block. They each provision a resource (a
         * documentation page / a wiki collective) rather than toggle a
         * feature, so they belong next to Calendar and Deck rather than next
         * to Decisions and Presence. Rendered as toggle-only rows (no
         * New/Existing chooser, no ResourcePicker) because there is no
         * existing intravox page or collective to connect to — always create.
         *
         * `moduleKey` names the boolean in form.modules that stores the
         * choice, so submit()/progressTasks/applyTemplateDefaults keep
         * working against the same data model.
         *
         * Labels are proper app names, deliberately unwrapped by t() to match
         * the pattern for Talk / Files / Calendar / Deck above.
         */
        toggleOnlyAppOptions() {
            return [
                {
                    id: 'intravox',
                    moduleKey: 'pages',
                    label: 'Intravox',
                    description: t('teamhub', 'Create a team page on the intranet provided by Intravox.'),
                    icon: FileDocumentOutline,
                    available: this.intravoxAvailable,
                },
                {
                    id: 'collective',
                    moduleKey: 'wiki',
                    label: 'Collectives',
                    description: t('teamhub', 'Create a collective for this team.'),
                    icon: BookOpenOutline,
                    available: this.collectivesAvailable,
                },
            ]
        },

        /**
         * v4.4.10 — single source of truth for module availability, consumed
         * by applyTemplateDefaults(). Previously that walked moduleOptions
         * and treated "not in the list" as "unavailable"; now the toggle-only
         * apps are not in moduleOptions but their form.modules entries still
         * need to be gated the same way, so both lists reference this map.
         */
        moduleAvailability() {
            return {
                decisions: this.decisionsModuleEnabled,
                presence: this.presenceModuleEnabled,
                timeline: true,
                messages: true,
                pages: this.intravoxAvailable,
                wiki: this.collectivesAvailable,
            }
        },
        /**
         * The active template's defaults.
         *
         * **v4.8.3 — the mirror is gone.** This used to be a hand-maintained
         * copy of `lib/Constants/TeamTemplateProfiles.php`, and the two had to
         * be edited together. Templates are admin-editable rows now; the whole
         * set is fetched once in `mounted()` from `GET /api/v1/templates`, so
         * switching template is still a local lookup and the round trip is not
         * in the critical path — which was the objection that kept the mirror
         * alive.
         *
         * `subtitle` and `placeholder` stay here: they are wizard copy, never
         * had a server side, and belong with the other translated strings.
         *
         * `templates` is empty until the fetch lands. Returning a null-shaped
         * profile rather than a guess means `applyTemplateDefaults()` ticks
         * nothing before the answer arrives; it is called again on load.
         */
        templateProfile() {
            const row = this.templates.find(x => x.templateKey === this.form.teamType)
                || this.templates.find(x => x.templateKey === 'collaboration')

            const copy = this.templateCopy[this.form.teamType] || this.templateCopy.collaboration

            if (!row) {
                return { apps: {}, config: {}, modules: {}, ...copy }
            }

            // The server sends lists; the wizard's form model is keyed maps.
            // `'create'` rather than `true` because the app rows offer a
            // New/Existing chooser and 'create' is what that stores.
            const apps = {}
            for (const id of ['talk', 'files', 'calendar', 'deck']) {
                apps[id] = row.apps.includes(id) ? 'create' : null
            }
            const modules = {}
            for (const id of ['decisions', 'presence', 'timeline', 'messages', 'pages', 'wiki']) {
                modules[id] = row.modules.includes(id)
            }

            return { apps, modules, config: this.configFromBitmask(row.preselectConfig), ...copy }
        },

        /**
         * Wizard-only copy per template. Not server data — an admin renaming a
         * template changes its name, not the sentence above the form.
         */
        templateCopy() {
            return {
                project: {
                    subtitle: t('teamhub', 'Set up a project team in a few steps'),
                    placeholder: t('teamhub', 'e.g. Website Redesign'),
                },
                collaboration: {
                    subtitle: t('teamhub', 'Set up a collaboration space in a few steps'),
                    placeholder: t('teamhub', 'e.g. Design Guild'),
                },
                department: {
                    subtitle: t('teamhub', 'Set up a department team in a few steps'),
                    placeholder: t('teamhub', 'e.g. Human Resources'),
                },
            }
        },

        /** The row for the template currently selected, or null before load. */
        activeTemplate() {
            return this.templates.find(x => x.templateKey === this.form.teamType) || null
        },
        /**
         * v4.4.5 — labels for everything the wizard actually set up, for the
         * success hand-off. Reads the submitted form rather than the progress
         * task list, because tasks are batched ("Creating new app resources"
         * covers all four apps) and the owner wants to see the resources, not
         * the steps.
         *
         * Module labels come from moduleOptions so they stay translated in one
         * place and this adds no duplicate keys.
         */
        provisioned() {
            // v4.9.6 — the workspace flow reads the operation's result: what
            // the server actually made or linked, not what the form asked for.
            if (this.isWorkspace) {
                const result = this.provisioningState?.result || {}
                const out = []
                if (result.project?.name) {
                    out.push(t('teamhub', 'OpenProject: {name}', { name: result.project.name }))
                }
                const seen = new Set()
                for (const r of Array.isArray(result.resources) ? result.resources : []) {
                    if (r.app === 'openproject' || seen.has(r.app + '/' + r.type)) continue
                    seen.add(r.app + '/' + r.type)
                    if (r.type === 'openproject_folder') {
                        out.push(t('teamhub', 'OpenProject project folder'))
                    } else {
                        out.push(componentLabel(r.app === 'files' ? 'files' : r.app))
                    }
                }
                for (const m of (this.provisioningState?.request?.components?.modules || [])) out.push(componentLabel(m))
                return out
            }
            const appLabels = {
                talk:     t('teamhub', 'Talk room'),
                files:    t('teamhub', 'Team folder'),
                calendar: t('teamhub', 'Calendar'),
                deck:     t('teamhub', 'Deck board'),
            }
            const out = []
            for (const [key, val] of Object.entries(this.form.apps)) {
                if (val.mode !== null && appLabels[key]) out.push(appLabels[key])
            }
            for (const a of this.toggleOnlyAppOptions) {
                if (a.available && this.form.modules[a.moduleKey]) out.push(a.label)
            }
            for (const m of this.moduleOptions) {
                if (m.available && this.form.modules[m.id]) out.push(m.label)
            }
            // v4.9.3 — the linked OpenProject project, by name. Since v4.9.4
            // the link is made by the create call itself, so a team on this
            // screen always has it.
            if (this.form.teamType === 'openproject' && this.form.openProject) {
                out.push(t('teamhub', 'OpenProject: {name}', { name: this.form.openProject.name }))
            }
            return out
        },

        /**
         * Step 3 is skippable, and a team with nobody in it is the one case
         * where the next action is not "open it". Drives which hand-off button
         * is primary and which hint is shown.
         */
        noMembersInvited() {
            return this.form.members.length === 0
        },

        namePlaceholder() {
            return this.templateProfile.placeholder
        },
        // `configOptions` / `inviteConfigOptions` / `privacyConfigOptions` /
        // `lockedConfigKeys` lived here until v4.8.5. They rendered the
        // Settings step, which is gone: those values are the policy's, and
        // `applyPolicyToForm()` writes them into `form.config` directly.

        /**
         * The member appointed team owner, if any (v4.8.7).
         *
         * Null means the creator keeps it, which is what Circles does anyway —
         * so the common case costs no extra call at the end of creation.
         */
        appointedOwner() {
            return this.form.members.find(m => m.level === 9 && m.type !== 'group') || null
        },

        /**
         * **Every** member, with their level — not only the promoted ones.
         *
         * The server skips the level-1 writes itself (level 1 is what the
         * invite already wrote, and re-writing it churns the member row and the
         * Circles membership cache for nothing). It needs the full list for a
         * different question: whether the creator named *themselves*, which
         * decides if they stay on the team after handing it over. Sending only
         * the promoted members would make a creator who added themselves at
         * plain Member level look like somebody who did not.
         */
        memberRoles() {
            return this.form.members.map(m => ({
                id: m.id,
                type: m.type || 'user',
                level: m.level === 9 ? 1 : (m.level || 1),
            }))
        },

        /**
         * Did the handover actually take the creator off the team?
         *
         * Read from the server's answer rather than recomputed client-side —
         * the transfer can fail, and a failed transfer leaves them owner.
         */
        creatorLeftTeam() {
            return this.roleResult?.owner?.status === 'transferred'
                && this.roleResult?.owner?.creatorLeft === true
        },

        /** Is anyone being promoted above plain Member? */
        hasElevatedRole() {
            return this.form.members.some(m => (m.level || 1) > 1 && m.level !== 9)
        },

        /**
         * Does the creator stay on the team after handing it over?
         *
         * Only if they added themselves. Mirrors the server's rule so the note
         * on the members step says what will actually happen.
         */
        creatorStaysOnTeam() {
            const me = getCurrentUser()?.uid
            return !!me && this.form.members.some(m => (m.type || 'user') === 'user' && m.id === me)
        },

        /**
         * Show the mode tabs at all?
         *
         * On an unlicensed instance bulk create does not exist as a thing to be
         * told about, so the tabs stay hidden and the wizard looks exactly as
         * it did. On a licensed one they are always shown — a permitted user
         * gets the feature, and an unpermitted one gets to find out it exists
         * and what it needs, which is the whole point of not hiding it.
         */
        showModeTabs() {
            return this.bulkLicensed
        },

        /** Why the bulk tab is unavailable, naming the gate that is closed. */
        bulkUnavailableReason() {
            if (!this.bulkPermitted) {
                return t('teamhub', 'Creating several teams at once is available to members of the team creation group. Where no such group is set, only Nextcloud administrators can do it. Ask an administrator if you need it.')
            }
            return t('teamhub', 'Creating several teams at once requires a license.')
        },

        /** The policy that will apply, or null before one is chosen. */
        activeProfile() {
            if (!this.form.profileKey) return null
            return this.policyProfiles.find(p => p.profileKey === this.form.profileKey) || null
        },

        /**
         * Integrations the classification permits, or null when it does not
         * say. Null and an empty allow-list both mean "everything", which is
         * why callers only handle null — see PolicyField's
         * `integrations_allowed`.
         */
        allowedIntegrations() {
            const list = this.activeProfile?.values?.integrations_allowed
            return Array.isArray(list) && list.length ? list : null
        },
        configValue() {
            let v = 0
            if (this.form.config.open)         v |= CFG_OPEN
            if (this.form.config.invite)        v |= CFG_INVITE
            if (this.form.config.request)       v |= CFG_REQUEST
            if (this.form.config.protected)     v |= CFG_PROTECTED
            if (this.form.config.visible)       v |= CFG_VISIBLE
            // System bits (CFG_SINGLE, CFG_SYSTEM, CFG_NO_OWNER, CFG_HIDDEN,
            // CFG_BACKEND) are never written by TeamHub — Circles manages them
            // internally and setting them on a user team corrupts its state.
            // Team-as-member prevention is enforced server-side in MemberService.
            return v
        },
    },
    watch: {
        // v4.8.4 — switching classification re-applies it over the template's
        // proposal, and re-locks. Same order as mount: template first, profile
        // second, profile wins.
        'form.profileKey'() {
            this.applyTemplateDefaults()
            this.applyPolicyToForm()
        },
        'form.teamType': {
            handler() {
                this.applyTemplateDefaults()
                // v4.8.5 — a new template brings its own default policy, unless
                // the user has already chosen one.
                this.applyTemplateDefaultPolicy()
                this.applyPolicyToForm()
                // v4.6.13 — switching to Department hides the field, so a date
                // chosen under another template must not be carried along
                // invisibly and then dropped server-side without explanation.
                if (!this.expiryAvailable) {
                    this.form.expiresOn = ''
                }
                // v4.9.3 — a project picked under the OpenProject template must
                // not ride along invisibly into another one.
                if (this.form.teamType !== 'openproject') {
                    this.form.openProject = null
                    this.openProjectError = ''
                } else if (this.provisioningOptions === null && !this.provisioningLoading) {
                    // v4.9.6 — the workspace flow's options, fetched once the
                    // template is picked (not on mount: it probes OpenProject).
                    this.loadProvisioningOptions()
                }
            },
            immediate: true,
        },
    },
    async mounted() {
        await this.checkIntravox()
        // v4.8.3 — before applyTemplateDefaults(), because that is what it
        // reads. The immediate `form.teamType` watcher has already run once
        // against an empty set and ticked nothing; this is the pass that
        // actually preselects.
        await this.loadTemplates()
        this.applyTemplateDefaults()
        // v4.8.4 — after the template defaults, because the classification
        // overrides them: template proposes, profile constrains.
        await this.loadPolicyContext()
        this.applyPolicyToForm()
        await this.loadWizardDescription()
        await this.loadLicenseEntitlements()
        await this.loadBulkEntitlement()
        await this.loadOpenProjectCapabilities()
    },
    methods: {
        t,

        /**
         * v4.6.13 — prefill the picker with six months out the first time it is
         * focused, and only then.
         *
         * Setting it in `data()` would mean every team created by an admin who
         * never looked at this field silently acquired a deadline. Setting it
         * on focus makes the default a suggestion the user can see and change,
         * while an untouched field still means "no end date".
         *
         * `expiryDefaultDate` is computed server-side and delivered with the
         * admin settings so the wizard, the CSV importer's documentation and
         * the admin panel cannot drift on what "the default" is; the local
         * calculation is the fallback for a settings fetch that failed.
         */
        onExpiryFocus() {
            if (this.form.expiresOn) {
                return
            }
            // v4.8.3 — the template's own default period wins. An admin who set
            // "90 days" on the Project template meant it for project teams
            // specifically, which is the whole reason the field moved off the
            // instance setting and onto the template.
            const days = this.activeTemplate?.expiryDefaultDays || 0
            if (days > 0) {
                this.form.expiresOn = shiftToday({ days })
                return
            }
            if (this.expiryDefaultDate) {
                this.form.expiresOn = this.expiryDefaultDate
                return
            }
            this.form.expiresOn = shiftToday({ months: 6 })
        },

        /**
         * Named booleans from a Circles config integer — the inverse of
         * `configValue`, and it must stay bit-for-bit consistent with it.
         *
         * Only the five the wizard exposes. Anything else in the integer is
         * ignored rather than surfaced: the server masks writes to
         * MANAGED_BITS, and a bit the wizard cannot show is a bit it must not
         * claim to be setting.
         */
        configFromBitmask(bitmask) {
            const v = Number(bitmask) || 0
            return {
                open: (v & CFG_OPEN) !== 0,
                invite: (v & CFG_INVITE) !== 0,
                request: (v & CFG_REQUEST) !== 0,
                protected: (v & CFG_PROTECTED) !== 0,
                visible: (v & CFG_VISIBLE) !== 0,
            }
        },

        /**
         * The template set, fetched once.
         *
         * A failure leaves `templates` empty, which makes `templateProfile`
         * return an all-off shape: the wizard still works and still creates a
         * team, it just preselects nothing. That is the right failure — a
         * wizard that refuses to open because a defaults fetch failed is worse
         * than one that opens with everything unticked.
         */
        async loadTemplates() {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/templates'))
                this.templates = data.templates || []
            } catch (e) {
                this.templates = []
            }
        },

        /**
         * The classification context (v4.8.4).
         *
         * Same failure posture as the templates fetch: on error everything
         * stays empty, `activeProfile` is null, nothing is locked and nothing
         * is assigned. A wizard that refuses to open because a policy lookup
         * failed would be worse than one that creates an unclassified team an
         * administrator can classify afterwards.
         */
        /**
         * May this user create teams in bulk? (v4.8.9)
         *
         * Two gates behind one boolean — the licence, and the team-creator
         * group (administrators where no group is set). On failure the tab
         * stays hidden and the wizard behaves as it always has, which is the
         * right posture for a feature nobody is currently using.
         */
        async loadBulkEntitlement() {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/teams-bulk-entitlement'))
                this.canBulkCreate = !!data.canBulkCreate
                // Kept apart so the tab can say *which* gate is closed. An
                // unlicensed instance and an unprivileged user are different
                // problems with different fixes, and "unavailable" tells the
                // reader neither of them.
                this.bulkLicensed = !!data.licensed
                this.bulkPermitted = !!data.permitted
            } catch (e) {
                this.canBulkCreate = false
                this.bulkLicensed = false
                this.bulkPermitted = false
            }
        },

        async loadPolicyContext() {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/policy/creation'))
                this.policyProfiles = data.profiles || []
                this.applyTemplateDefaultPolicy()
            } catch (e) {
                this.policyProfiles = []
            }
        },

        /**
         * Preselect the template's default policy.
         *
         * Skipped once the user has picked for themselves — switching template
         * to compare options must not silently discard a deliberate choice.
         */
        applyTemplateDefaultPolicy() {
            if (this.policyTouched) {
                return
            }
            const key = this.activeTemplate?.defaultProfileKey || ''
            this.form.profileKey = this.policyProfiles.some(p => p.profileKey === key) ? key : ''
        },

        /**
         * Force the classification's values onto the form.
         *
         * The server applies them regardless — `TeamService::updateTeamConfig`
         * overlays governed bits on every write — so this is about the form
         * agreeing with what will actually happen, not about enforcement. A
         * checkbox that shows one thing while the server stores another is how
         * an administrator stops trusting the screen.
         */
        applyPolicyToForm() {
            const values = this.activeProfile?.values || {}
            for (const [wizardKey, fieldKey] of Object.entries(CONFIG_FIELD_KEYS)) {
                if (Object.prototype.hasOwnProperty.call(values, fieldKey)) {
                    this.form.config[wizardKey] = values[fieldKey] === true
                }
            }
            // An app the classification does not permit drops off appOptions,
            // so clear anything already selected that just disappeared.
            const allowed = this.allowedIntegrations
            if (allowed !== null) {
                for (const appId of Object.keys(this.form.apps)) {
                    if (!allowed.includes(appId)) {
                        this.form.apps[appId].mode = null
                        this.form.apps[appId].resourceId = null
                        this.form.apps[appId].name = ''
                    }
                }
            }
        },

        applyTemplateDefaults() {
            const profile = this.templateProfile
            const availableIds = new Set(this.appOptions.map(a => a.id))
            for (const [appId, mode] of Object.entries(profile.apps)) {
                if (this.form.apps[appId]) {
                    this.form.apps[appId].mode = availableIds.has(appId) ? mode : null
                    if (this.form.apps[appId].mode === null) {
                        this.form.apps[appId].resourceId = null
                        this.form.apps[appId].name = ''
                    }
                }
            }
            for (const [key, val] of Object.entries(profile.config)) {
                this.form.config[key] = val
            }
            for (const [modId, val] of Object.entries(profile.modules)) {
                this.form.modules[modId] = this.moduleAvailability[modId] ? val : false
            }
        },

        nextStep() {
            this.nameError = ''
            if (this.step === 1) {
                const trimmed = this.form.name.trim()
                if (!trimmed) {
                    this.nameError = t('teamhub', 'Team name is required')
                    return
                }
                // v4.6.19 — 120, not 255: `circles_circle.name` is
                // varchar(127), so the old cap was validating against a width
                // the database could not store. Mirrors
                // TeamService::assertValidTeamName, which explains the seven
                // characters of headroom.
                //
                // Spread-then-length, not `.length`: the latter counts UTF-16
                // code units, so every astral character — emoji, which the
                // v4.6.18 character rule admits — would count twice and the
                // wizard would refuse names the server accepts. Iterating the
                // string yields codepoints, which is what PHP's `mb_strlen()`
                // counts on the other side of this mirror.
                if ([...trimmed].length > 120) {
                    this.nameError = t('teamhub', 'Team name is too long (max 120 characters).')
                    return
                }
                // v4.6.18 — mirrors TeamService::NAME_FORBIDDEN_PATTERN. This
                // was an ASCII allowlist until v4.6.18 and rejected every
                // accented character in the six languages this app ships in;
                // it is now a blocklist of characters that break something
                // concrete — path separators, control characters, and the
                // zero-width / bidi-override set used for homograph spoofing.
                // The PHP side is the authority and re-checks all of it; keep
                // the two classes in step. Existing teams are unaffected either
                // way — the rule applies to new names only.
                if (/[\/\\\x00-\x1F\x7F\u200B-\u200F\u202A-\u202E\u2066-\u2069\uFEFF]/u.test(trimmed)) {
                    this.nameError = t('teamhub', 'Team name may not contain slashes, control characters, or invisible formatting characters.')
                    return
                }
                if (/^\.+$/.test(trimmed)) {
                    this.nameError = t('teamhub', 'Team name cannot consist only of dots.')
                    return
                }
                // v4.6.18 — duplicate pre-check, and deliberately only a
                // pre-check. The store holds the teams this user is in, not
                // every team on the instance, so this catches the common case
                // (duplicating a team you can already see) at step 1 instead of
                // after the whole wizard is filled in. The authority is
                // TeamService::assertTeamNameAvailable(), which checks
                // instance-wide; a name that is taken by a team the user cannot
                // see still fails at submit, where the error surfaces through
                // the existing "Failed to create team: {error}" path.
                const lower = trimmed.toLowerCase()
                const clash = (this.$store.state.teams || [])
                    .some(team => String(team?.name || '').trim().toLowerCase() === lower)
                if (clash) {
                    this.nameError = t('teamhub', 'A team with this name already exists. Team names must be unique.')
                    return
                }

                // v4.8.5 — the policy is required. Checked here rather than
                // only at submit so the user is told on the step that holds
                // the field, not two screens later.
                if (this.policyProfiles.length && !this.form.profileKey) {
                    this.policyError = t('teamhub', 'Choose a policy for this team.')
                    return
                }
            }
            // v4.9.6 — the workspace flow's own steps. (The v4.9.3 project
            // check that stood on step 1 lives on the OpenProject step now.)
            if (this.currentStepKey === 'openproject') {
                this.openProjectError = ''
                const setup = this.form.openProjectSetup
                if (!this.provisioningOptions || this.provisioningOptions.capabilities?.errorCode) {
                    this.openProjectError = this.provisioningOptions?.capabilities?.userMessage
                        || t('teamhub', 'OpenProject is not available right now.')
                    return
                }
                if (!setup.mode) {
                    this.openProjectError = t('teamhub', 'Choose whether to create a new project or connect an existing one.')
                    return
                }
                if (setup.mode === 'create') {
                    if (this.identifierState === 'invalid' || !setup.identifier) {
                        this.openProjectError = t('teamhub', 'Enter a valid project identifier.')
                        return
                    }
                    if (this.identifierState === 'taken') {
                        this.openProjectError = t('teamhub', 'The project identifier is already taken in OpenProject.')
                        return
                    }
                } else {
                    if (!setup.project) {
                        this.openProjectError = t('teamhub', 'Choose the OpenProject project for this team.')
                        return
                    }
                    // v4.9.4's rule, unchanged: a taken project is refused here
                    // and again by the server before anything is made.
                    if (setup.project.linkedTeam) {
                        this.openProjectError = t('teamhub', 'That OpenProject project is already linked to another team. Choose a different project.')
                        return
                    }
                }
            }
            // The template's applications are checked here too: a required one
            // that is not installed cannot be provisioned, and the person should
            // hear it before typing the members in.
            if (this.currentStepKey === 'openproject' && (this.provisioningOptions?.missingRequired || []).length) {
                this.openProjectError = t('teamhub', 'A required application is not installed. Ask your administrator.')
                return
            }
            if (this.currentStepKey === 'members' && this.isWorkspace && !this.rolesReady) {
                showError(t('teamhub', 'Decide what to do with the members who have no OpenProject account.'))
                return
            }
            if (this.stepKeys[this.step] === 'review' && !this.provisioningKey) {
                this.provisioningKey = newIdempotencyKey()
            }
            this.step++
        },

        // ── v4.9.6 — the workspace flow ─────────────────────────────────

        async loadProvisioningOptions() {
            this.provisioningLoading = true
            this.provisioningOptionsError = ''
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/provisioning/options'), { params: { templateKey: 'openproject' } })
                this.provisioningOptions = data
            } catch (e) {
                this.provisioningOptionsError = opClassifyError(e).message
            } finally {
                this.provisioningLoading = false
            }
        },

        /** The request the server records: everything the wizard collected, nothing more. */
        buildProvisioningRequest() {
            const setup = this.form.openProjectSetup
            return {
                idempotencyKey: this.provisioningKey,
                templateKey: 'openproject',
                mode: setup.mode || 'create',
                name: this.form.name.trim(),
                description: this.form.description.trim(),
                visibility: this.form.openProjectSetup.visibility,
                startDate: this.form.openProjectSetup.startDate || '',
                endDate: this.expiryAvailable ? (this.form.expiresOn || '') : '',
                category: (this.form.openProjectSetup.category || '').trim(),
                ownerUid: this.appointedOwner ? this.appointedOwner.id : '',
                profileKey: this.form.profileKey || '',
                preselectConfig: this.configValue,
                openProject: {
                    projectId: setup.mode === 'link' ? (setup.project?.id || 0) : 0,
                    templateId: setup.mode === 'create' ? (setup.templateId || 0) : 0,
                    parentId: setup.mode === 'create' ? (setup.parentId || 0) : 0,
                    identifier: setup.mode === 'create' ? setup.identifier : '',
                },
                members: this.form.members.map(m => ({
                    id: m.id,
                    type: m.type || 'user',
                    level: m.level || 1,
                    displayName: m.displayName || m.id,
                    decision: this.form.decisions[(m.type || 'user') + ':' + m.id] || null,
                })),
            }
        },

        async submitProvisioning() {
            if (!this.provisioningKey) this.provisioningKey = newIdempotencyKey()
            this.step = this.stepKeys.length
            this.provisioningBusy = true
            try {
                const { data } = await axios.post(generateUrl('/apps/teamhub/api/v1/provisioning'), this.buildProvisioningRequest())
                this.provisioningState = data
            } catch (e) {
                const err = opClassifyError(e)
                showError(t('teamhub', 'The workspace could not be started: {error}', { error: err.message }))
                this.provisioningBusy = false
                this.step = this.stepKeys.indexOf('review') + 1
                return
            }
            this.provisioningBusy = false
            await this.pumpProvisioning()
        },

        async pumpProvisioning() {
            if (!this.provisioningState || this.provisioningBusy) return
            this.provisioningBusy = true
            const id = this.provisioningState.id
            try {
                const final = await pumpOperation(
                    async () => (await axios.post(generateUrl(`/apps/teamhub/api/v1/provisioning/${id}/run`))).data,
                    state => { this.provisioningState = state },
                )
                if (final?.status === 'completed') {
                    this.finishWorkspace()
                }
            } catch (e) {
                showError(opClassifyError(e).message)
            } finally {
                this.provisioningBusy = false
            }
        },

        async retryProvisioning(stepKey) {
            if (!this.provisioningState || this.provisioningBusy) return
            this.provisioningBusy = true
            try {
                const { data } = await axios.post(generateUrl(`/apps/teamhub/api/v1/provisioning/${this.provisioningState.id}/retry`), { step: stepKey })
                this.provisioningState = data
            } catch (e) {
                showError(opClassifyError(e).message)
                this.provisioningBusy = false
                return
            }
            this.provisioningBusy = false
            if (this.provisioningActive) {
                await this.pumpProvisioning()
            }
        },

        async rollbackProvisioning() {
            if (!this.provisioningState || this.provisioningBusy) return
            this.provisioningBusy = true
            try {
                const url = generateUrl(`/apps/teamhub/api/v1/provisioning/${this.provisioningState.id}/rollback`)
                let { data } = await axios.post(url, { confirm: false })
                if (data.status === 'confirm_required') {
                    const parts = (data.needsConfirm || []).map(k => k).join(', ')
                    // eslint-disable-next-line no-alert
                    if (!window.confirm(t('teamhub', 'These parts may already hold content: {parts}. Remove the team and everything created for it? The OpenProject project is never deleted by TeamHub.', { parts }))) {
                        return
                    }
                    ({ data } = await axios.post(url, { confirm: true }))
                }
                this.provisioningState = data
                if (data.status === 'rolled_back') {
                    showSuccess(t('teamhub', 'Removed what was created.'))
                }
            } catch (e) {
                showError(opClassifyError(e).message)
            } finally {
                this.provisioningBusy = false
            }
        },

        /** The workspace exists: hand over to the success screen. */
        finishWorkspace() {
            const state = this.provisioningState
            if (!state?.teamId) return
            this.createdTeam = { id: state.teamId, name: this.form.name.trim() }
            this.roleResult = state.result?.handover?.owner ? { owner: state.result.handover.owner } : null
            this.appointedOwnerName = this.appointedOwner?.displayName || ''
            this.creationDone = true
        },

        backToReview() {
            this.provisioningState = null
            this.provisioningKey = newIdempotencyKey()
            this.step = this.stepKeys.indexOf('review') + 1
        },

        onMemberSearch() {
            clearTimeout(this.searchTimer)
            if (this.memberSearch.length < 2) { this.userResults = []; return }
            this.searchTimer = setTimeout(async () => {
                try {
                    const { data } = await axios.get(
                        generateUrl('/apps/teamhub/api/v1/users/search'),
                        { params: { q: this.memberSearch } }
                    )
                    const added = new Set(this.form.members.map(m => (m.type || 'user') + ':' + m.id))
                    this.userResults = (data || [])
                        .filter(u => !added.has((u.type || 'user') + ':' + u.id))
                        .map(u => ({ id: u.id, displayName: u.displayName || u.id, type: u.type || 'user' }))
                } catch { this.userResults = [] }
            }, 300)
        },

        /**
         * Set a member's role. Owner is single-select across the whole list.
         *
         * Appointing a second owner demotes the first to Team admin rather than
         * to Member: somebody you had picked to own the team is not somebody
         * you meant to leave with no rights, and silently dropping them two
         * rungs is the kind of thing nobody notices until it matters.
         */
        setMemberLevel(member, level) {
            if (level === 9) {
                for (const m of this.form.members) {
                    if (m !== member && m.level === 9) {
                        m.level = 8
                    }
                }
            }
            member.level = level
        },

        addMember(user) {
            // Level 1 (Member) unless changed — what Circles writes on invite
            // anyway, so an untouched list costs no extra calls.
            this.form.members.push({ ...user, level: 1 })
            this.memberSearch = ''
            this.userResults = []
        },

        removeMember(userId, type) {
            const t = type || 'user'
            this.form.members = this.form.members.filter(m => !(m.id === userId && (m.type || 'user') === t))
        },

        setTask(index, status) {
            if (this.progressTasks[index]) {
                this.progressTasks[index] = { ...this.progressTasks[index], status }
            }
        },

        // `onAppToggle` and `onAppModeSwitch` lived here until v4.8.5. They
        // drove the Apps step's checkbox and its Create-new / Connect-existing
        // choice, both of which are gone: apps come from the template, always
        // created new. Connecting an existing resource is done from Manage team
        // → Integrations, which is where it also has to be done for a team that
        // already exists.

        async submit() {
            // v4.9.6 — the OpenProject Workspace template is provisioned by the
            // server, step by step; the classic client-driven sequence below is
            // every other template's.
            if (this.isWorkspace) {
                await this.submitProvisioning()
                return
            }
            // The "connect existing" validation that stood here went with the
            // Apps step — nothing can set mode 'connect' any more, so the guard
            // was unreachable.

            // Apps split by mode.
            const appsToCreate  = Object.entries(this.form.apps)
                .filter(([, v]) => v.mode === 'create').map(([k]) => k)
            const appsToConnect = Object.entries(this.form.apps)
                .filter(([, v]) => v.mode === 'connect' && v.resourceId)
                .map(([k, v]) => ({ app: k, resourceId: v.resourceId }))
            const anyAppEnabled = Object.values(this.form.apps).some(v => v.mode !== null)

            // Build task list
            //
            // v4.9.4 — an OpenProject team is linked by the create call itself
            // (POST /teams with openProjectId): the server checks the project
            // before the team exists and deletes the team again if the link
            // still fails, so there is no separate link task any more — and no
            // team without its project. The first task says so.
            const linksOpenProject = this.form.teamType === 'openproject' && !!this.form.openProject
            const tasks = [{
                label: linksOpenProject
                    ? t('teamhub', 'Creating team and linking the OpenProject project')
                    : t('teamhub', 'Creating team'),
                status: 'waiting',
            }]
            if (this.form.description.trim()) tasks.push({ label: t('teamhub', 'Saving description'), status: 'waiting' })
            if (this.form.members.length > 0) tasks.push({ label: t('teamhub', 'Inviting members'), status: 'waiting' })
            if (appsToCreate.length > 0) tasks.push({ label: t('teamhub', 'Creating new app resources'), status: 'waiting' })
            if (appsToConnect.length > 0) tasks.push({ label: t('teamhub', 'Connecting existing app resources'), status: 'waiting' })
            if (this.intravoxAvailable && this.form.modules.pages) tasks.push({ label: t('teamhub', 'Creating documentation page'), status: 'waiting' })
            // v4.3.6 — Wiki (Collectives) module. Backend auto-creates the
            // collective on toggle-on, so a single PUT after team-create is
            // enough — no separate page-create step to schedule here.
            if (this.collectivesAvailable && this.form.modules.wiki) tasks.push({ label: t('teamhub', 'Enabling Collectives'), status: 'waiting' })
            const hasModuleConfig = this.form.modules.presence || this.form.modules.decisions || !this.form.modules.timeline
            if (hasModuleConfig) tasks.push({ label: t('teamhub', 'Configuring team modules'), status: 'waiting' })
            if (this.form.teamType === 'project') tasks.push({ label: t('teamhub', 'Setting up project'), status: 'waiting' })
            // v4.8.7 — last step. Labelled for the handover when there is one,
            // because that is the part worth watching.
            if (this.hasElevatedRole || this.appointedOwner) {
                tasks.push({
                    label: this.appointedOwner
                        ? t('teamhub', 'Setting roles and handing over the team')
                        : t('teamhub', 'Setting member roles'),
                    status: 'waiting',
                })
            }

            // Build the full app-state payload for ALL known apps so the backend can
            // persist enabled/disabled in teamhub_team_apps immediately after team creation.
            // An app counts as "enabled" if it has any non-null mode (create or connect).
            //
            // Note: the wizard uses 'talk' but teamhub_team_apps stores 'spreed' (NC app name).
            const wizardToAppId = { talk: 'spreed' }
            const appStates = Object.entries(this.form.apps).map(([k, v]) => ({
                app_id: wizardToAppId[k] || k,
                enabled: v.mode !== null,
            }))
            if (this.intravoxAvailable) {
                appStates.push({ app_id: 'intravox', enabled: this.form.modules.pages })
            }

            this.progressTasks = tasks
            this.step = this.stepKeys.length  // Progress is the last step

            let i = 0
            let team = null

            try {
                // 1. Create team
                this.setTask(i, 'running')
                const { data } = await axios.post(generateUrl('/apps/teamhub/api/v1/teams'), {
                    name: this.form.name.trim(),
                    // v4.8.4 — honoured only for a Nextcloud admin; the server
                    // re-checks and substitutes the instance default for
                    // anybody else, so sending it is not a decision.
                    profileKey: this.form.profileKey || '',
                    // The fallback if the policy is somehow blank — the server
                    // uses the template's default rather than leaving the team
                    // unclassified.
                    templateKey: this.form.teamType || '',
                    // v4.9.4 — the OpenProject project, checked and linked in
                    // this same call. Required by the server for this template.
                    openProjectId: linksOpenProject ? this.form.openProject.id : 0,
                })
                team = data
                this.setTask(i++, 'done')

                // 2. Save config (always — even default 0 is meaningful)
                const configVal = this.configValue
                if (configVal > 0) {
                    try {
                        await axios.put(
                            generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/config`),
                            { config: configVal }
                        )
                    } catch (e) { /* non-fatal */ }
                }

                // 3. Save description
                if (this.form.description.trim()) {
                    this.setTask(i, 'running')
                    try {
                        await axios.put(
                            generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/description`),
                            { description: this.form.description.trim() }
                        )
                        this.setTask(i++, 'done')
                    } catch { this.setTask(i++, 'error') }
                }

                // 4. Invite members
                if (this.form.members.length > 0) {
                    this.setTask(i, 'running')
                    try {
                        await axios.post(
                            generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/invite-members`),
                            { members: this.form.members.map(m => ({ id: m.id, type: m.type || 'user' })) }
                        )
                        this.setTask(i++, 'done')
                    } catch { this.setTask(i++, 'error') }
                }

                // 5a. Create new app resources for "create" mode apps (also persists appStates).
                if (appsToCreate.length > 0) {
                    this.setTask(i, 'running')
                    try {
                        // Project Teams (v3.90.x) — when Deck is among appsToCreate and this
                        // is an Advanced project, DeckService seeds the "Project management"
                        // stack + starter cards. Irrelevant for the other create-resources
                        // calls below (persist-only / connect-existing paths never create a
                        // new Deck board, so they don't need it).
                        const projectMode = this.form.teamType === 'project' ? this.form.projectMode : null
                        const { data: resourceResults } = await axios.post(
                            generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/create-resources`),
                            { apps: appsToCreate, teamName: team.name, appStates, projectMode }
                        )
                        const anyError = Object.values(resourceResults).some(r => r?.error)
                        this.setTask(i++, anyError ? 'error' : 'done')
                    } catch (e) {
                        this.setTask(i++, 'error')
                    }
                } else if (!anyAppEnabled || appsToConnect.length === 0) {
                    // Persist appStates even when nothing is being created — keeps manage view honest.
                    axios.post(
                        generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/create-resources`),
                        { apps: [], teamName: team.name, appStates }
                    ).catch(() => {})
                }

                // 5b. Connect existing app resources for "connect" mode apps.
                //     The connect endpoint persists each app's team_apps row itself, so we
                //     only need to call it per-app. If we had no "create" call, we still
                //     need to make sure appStates is persisted — do it via the empty
                //     create-resources call above (the else-if path).
                if (appsToConnect.length > 0) {
                    if (appsToCreate.length === 0) {
                        // No create call has fired — persist appStates for non-connecting apps now.
                        axios.post(
                            generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/create-resources`),
                            { apps: [], teamName: team.name, appStates }
                        ).catch(() => {})
                    }

                    this.setTask(i, 'running')
                    let connectErrors = 0
                    for (const { app, resourceId } of appsToConnect) {
                        try {
                            await axios.post(
                                generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/resources/${app}/connect`),
                                { resourceId }
                            )
                        } catch (e) {
                            connectErrors++
                            const detail = e?.response?.data?.error || e?.message || ''
                            showError(detail
                                // TRANSLATORS: error shown when connecting an existing resource fails. {app} is e.g. "Calendar", {error} is the detail.
                                ? t('teamhub', 'Could not connect {app}: {error}', { app, error: detail })
                                : t('teamhub', 'Could not connect {app}', { app })
                            )
                        }
                    }
                    this.setTask(i++, connectErrors > 0 ? 'error' : 'done')
                }

                // 6. IntraVox page (only if installed and pages module enabled)
                if (this.intravoxAvailable && this.form.modules.pages) {
                    this.setTask(i, 'running')
                    try {
                        await this.createIntravoxPage(team)
                        this.setTask(i++, 'done')
                    } catch { this.setTask(i++, 'error') }
                }

                // 6b. Wiki (Collectives) — enable via /collectives/config PUT.
                // Backend auto-creates the collective and binds it to the team
                // circle (v4.3.6). Failures are non-fatal; the admin can
                // retry from Manage Team.
                if (this.collectivesAvailable && this.form.modules.wiki) {
                    this.setTask(i, 'running')
                    try {
                        await this.enableCollectivesForTeam(team)
                        this.setTask(i++, 'done')
                    } catch { this.setTask(i++, 'error') }
                }

                // 7. Configure team modules (presence, decisions, timeline)
                if (hasModuleConfig) {
                    this.setTask(i, 'running')
                    try {
                        await this.saveModuleConfig(team.id)
                        this.setTask(i++, 'done')
                    } catch { this.setTask(i++, 'error') }
                }

                // 8. Persist project-ness (Project template only) — records mode so the
                //    team is a real project (basic or advanced) from creation onward.
                if (this.form.teamType === 'project') {
                    this.setTask(i, 'running')
                    try {
                        await axios.put(
                            generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/project`),
                            { mode: this.form.projectMode }
                        )
                        // Project-owner onboarding (v3.90.x) — one-shot signal read
                        // once by TeamView on first open of this team to auto-show
                        // the phase guide. Advanced only; Basic has no phase to guide.
                        if (this.form.projectMode === 'advanced') {
                            this.$store.commit('SET_JUST_CREATED_ADVANCED_PROJECT', team.id)
                        }
                        this.setTask(i++, 'done')
                    } catch { this.setTask(i++, 'error') }
                }

                // v4.0.2 — persist the template label so the Team info widget
                // and Browse Teams can render it. Fire-and-forget: a failure
                // here shouldn't block team creation completion; the label
                // just won't show until an admin re-saves the team type.
                // v4.6.13 — the optional expiration date rides this same call.
                // It is only meaningful next to the template (Department teams
                // never expire), the server re-checks that, and sending it here
                // means one round trip instead of two. Blank is sent as blank
                // and means "no end date".
                try {
                    await axios.put(
                        generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/type`),
                        {
                            type: this.form.teamType,
                            expiresOn: this.expiryAvailable ? (this.form.expiresOn || '') : '',
                        }
                    )
                } catch { /* non-fatal */ }

                // (8b, the separate OpenProject link step of v4.9.3, is gone:
                // the link is part of step 1 since v4.9.4.)

                // 9. Member roles, and the handover — last, deliberately.
                //
                // `assignOwner()` demotes the outgoing owner to moderator, so
                // the creator loses level 9 here. Everything above needs to
                // have run while they still had it: resource creation, module
                // config and the type/expiry write are all owner- or
                // admin-gated. Moving this earlier breaks the steps after it.
                //
                // Reported, never fatal: the team exists and is usable, and a
                // failed handover leaves the creator as owner, which is
                // recoverable from Manage team. The result is rendered rather
                // than swallowed — HANDOFF records ownership transfer failing
                // silently on this instance for an upstream reason, and a
                // silent failure here would look identical to success.
                if (this.hasElevatedRole || this.appointedOwner) {
                    this.setTask(i, 'running')
                    this.appointedOwnerName = this.appointedOwner?.displayName || ''
                    try {
                        const { data: roleResult } = await axios.post(
                            generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/creation-roles`),
                            {
                                roles: this.memberRoles,
                                newOwner: this.appointedOwner ? this.appointedOwner.id : '',
                            }
                        )
                        this.roleResult = roleResult
                        const ownerFailed = roleResult?.owner?.status === 'failed'
                        const levelFailed = Object.values(roleResult?.levels || {})
                            .some(v => v !== 'ok')
                        this.setTask(i++, (ownerFailed || levelFailed) ? 'error' : 'done')
                    } catch { this.setTask(i++, 'error') }
                }

                // Show completed progress for a moment, then show "Open team" button
                await new Promise(r => setTimeout(r, 600))
                this.createdTeam = team
                this.creationDone = true

            } catch (error) {
                if (i < this.progressTasks.length) this.setTask(i, 'error')

                // v4.9.4 — the create call refused the OpenProject project
                // (taken by another team, not administered, gone, or the
                // integration is unusable). No team was made: the server
                // checks before creating and deletes on a late failure. The
                // pick is cleared and the reason waits under the picker,
                // which re-lists the project as taken when it comes back.
                // A 403 without a code is the "not administered, not public"
                // refusal, whose sentence comes as `error`.
                const opCode = error.response?.data?.code
                const opRefusal = (opCode && Object.values(OP_CODES).includes(opCode)) || error.response?.status === 403
                if (linksOpenProject && opRefusal) {
                    if (opCode === OP_CODES.PROJECT_ALREADY_LINKED) {
                        this.openProjectError = t('teamhub', 'That OpenProject project is already linked to another team. Choose a different project.')
                    } else if (!opCode) {
                        this.openProjectError = t('teamhub', 'You can only link a project you administer in OpenProject, or a public project.')
                    } else {
                        this.openProjectError = opClassifyError(error).message
                    }
                    this.form.openProject = null
                    showError(t('teamhub', 'The team was not created: {error}', { error: this.openProjectError }))
                    setTimeout(() => { this.step = 1 }, 1500)
                    return
                }

                const msg = error.response?.data?.error || error.response?.data?.message
                showError(msg
                    ? t('teamhub', 'Failed to create team: {error}', { error: msg })
                    : t('teamhub', 'Failed to create team')
                )
                setTimeout(() => { this.step = 1 }, 1500)
            }
        },

        /**
         * v4.4.5 — leave the wizard via one of the three hand-off actions.
         *
         * `intent` is passed to the parent alongside the team so App.vue can
         * land the user in the right place: 'invite' opens the team with the
         * invite modal queued, 'manage' opens Manage team, null opens the team
         * home. Kept as a parent-side concern because CreateTeamView doesn't
         * own routing.
         */
        finish(intent) {
            this.$emit('created', this.createdTeam, intent)
        },

        async checkIntravox() {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/apps/check'))
                this.intravoxAvailable        = !!data.intravox
                this.collectivesAvailable     = !!data.collectives
                this.talkAvailable            = !!data.talk
                this.calendarAvailable        = !!data.calendar
                this.deckAvailable            = !!data.deck
                this.groupfoldersAvailable    = !!data.groupfolders
                this.presenceModuleEnabled    = !!data.presenceModuleEnabled
                this.decisionsModuleEnabled   = !!data.decisionsModuleEnabled
            } catch (e) {
                this.intravoxAvailable    = false
                this.collectivesAvailable = false
            }
        },

        async loadWizardDescription() {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/admin/settings'))
                this.wizardDescription = data.wizardDescription || ''
                // v4.6.13 — same fetch, so the expiry picker's default costs no
                // extra request. Empty falls back to a local calculation in
                // onExpiryFocus().
                this.expiryDefaultDate = data.expiryDefaultDate || ''
            } catch {
                this.wizardDescription = ''
                this.expiryDefaultDate = ''
            }
        },

        /**
         * v3.100.1 — Check whether the instance's TeamHub Business
         * license allows creating new Advanced projects. Endpoint is
         * member-callable and returns only { canCreateAdvanced,
         * enforcementLevel } — no sensitive license detail.
         *
         * When Advanced is locked out and the wizard defaulted to
         * projectMode='advanced', silently flip to 'basic' so the user
         * has a working selection preselected — the tile remains
         * visible but greyed to explain why they can't pick it.
         */
        async loadLicenseEntitlements() {
            try {
                const { data } = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/license/entitlements')
                )
                this.licenseCanCreateAdvanced = !!data?.canCreateAdvanced
                if (!this.licenseCanCreateAdvanced && this.form.projectMode === 'advanced') {
                    this.form.projectMode = 'basic'
                }
            } catch {
                // Endpoint error → fail-open (assume Advanced allowed).
                // The backend upsert() still enforces the gate on submit,
                // so the worst case is we don't grey out the tile — the
                // user gets a clean 403 later instead of the pre-flight
                // upsell. Old behavior.
                this.licenseCanCreateAdvanced = true
            }
        },

        /**
         * v4.9.3 — can this creator use OpenProject. Not probed (no request
         * to OpenProject on wizard open); the picker's first search is the
         * probe, and its own error state says what is wrong.
         */
        async loadOpenProjectCapabilities() {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/openproject/capabilities'))
                this.openProjectCaps = data || {}
            } catch (e) {
                this.openProjectCaps = { errorCode: opClassifyError(e).code }
            }
            if (this.openProjectLockedReason && this.form.teamType === 'openproject') {
                this.form.teamType = 'collaboration'
            }
        },

        async saveModuleConfig(teamId) {
            const calls = []
            if (this.form.modules.presence && this.presenceModuleEnabled) {
                calls.push(
                    axios.put(
                        generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/presence/config`),
                        { presence_enabled: 1 }
                    )
                )
            }
            if (this.form.modules.decisions && this.decisionsModuleEnabled) {
                calls.push(
                    axios.put(
                        generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/decisions/config`),
                        { decisions_enabled: 1 }
                    )
                )
            }
            if (!this.form.modules.timeline) {
                calls.push(
                    axios.put(
                        generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/timeline/config`),
                        { timeline_enabled: 0 }
                    )
                )
            }
            // v3.104.1 — Messages is default on. Only PUT when unchecked so
            // the backend default holds otherwise (fewer create-time calls).
            if (!this.form.modules.messages) {
                calls.push(
                    axios.put(
                        generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/messages/config`),
                        { messages_enabled: 0 }
                    )
                )
            }
            await Promise.all(calls)
        },

        async enableCollectivesForTeam(team) {
            // v4.3.6 — turn on the Wiki team-app for the newly-created team.
            // Same endpoint the Manage Team toggle uses. Failures propagate
            // to the caller as a task-error status; the admin retries from
            // Manage Team where the error is surfaced properly (this wizard
            // stage only shows a generic ✗ mark).
            const params = new URLSearchParams()
            params.set('collectives_enabled', '1')
            await axios.put(
                generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/collectives/config`),
                params.toString(),
                { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } },
            )
        },

        async createIntravoxPage(team) {
            // Route through TeamHub's IntravoxService — reads admin config for parentPath,
            // uses in-process PageService call (no loopback HTTP).
            // Project Teams (v3.88.x) — Advanced projects get the 9-element charter
            // seeded server-side; Basic/Collaboration/Department keep the blank page.
            const projectMode = this.form.teamType === 'project' ? this.form.projectMode : null
            await axios.post(
                generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/intravox/page`),
                { projectMode }
            )
        },

        toSlug(text) {
            return (text || '').toLowerCase()
                .replace(/[^a-z0-9\s-]/g, '').trim()
                .replace(/\s+/g, '-').replace(/-+/g, '-') || 'team'
        },
    },
}
</script>

<style scoped>
/* Canvas layout: natural document flow, footer follows content */
.ctv {
    display: flex;
    flex-direction: column;
}

/* v4.8.11 — 1200px, matching `.browse-teams-view`. Was 680px, which is a
   comfortable width for a one-column form and far too narrow for the bulk
   table: a row carrying name, template, policy, expiry and owner has nowhere
   to go at 680. The two views sit side by side in the same shell, so they
   should not disagree about how wide the content area is.

   The footer below uses the same number for the same reason — it is a separate
   element and would otherwise stop lining up with the body above it. */
.ctv__inner {
    max-width: 1200px;
    width: 100%;
    margin: 0 auto;
    padding: 40px 40px 0;
    box-sizing: border-box;
}

.ctv__header { margin-bottom: 32px; }

.ctv__title {
    font-size: 26px;
    font-weight: 700;
    margin: 0 0 6px;
}

.ctv__subtitle {
    color: var(--color-text-maxcontrast);
    margin: 0;
}

/* Steps */
.ctv__steps {
    display: flex;
    align-items: center;
    margin-bottom: 36px;
}

.ctv__step-wrap {
    display: flex;
    align-items: center;
    flex: 1;
}

.ctv__step-wrap:last-child { flex: 0; }

.ctv__step {
    display: flex;
    align-items: center;
    gap: 10px;
    white-space: nowrap;
    opacity: 0.4;
    transition: opacity 0.2s;
}

.ctv__step--active, .ctv__step--done { opacity: 1; }

.ctv__step-num {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--color-border);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
    flex-shrink: 0;
}

.ctv__step--active .ctv__step-num {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

.ctv__step--done .ctv__step-num {
    background: var(--color-success);
    color: var(--color-success-text);
}

.ctv__step-label { font-size: var(--th-font-body); font-weight: 500; }

.ctv__step-line {
    flex: 1;
    height: 2px;
    background: var(--color-border);
    margin: 0 16px;
}

/* Content sections */
/* The single-team steps keep a readable column inside the wider shell.
   `.ctv__inner` went to 1200px so the bulk table has room; the one-column form
   does not want that width — a 1100px "Team name" field is not a form, it is a
   ruler. BulkCreateTeams is mounted outside this element, so it still gets the
   full width. */
.ctv__section {
    display: flex;
    flex-direction: column;
    gap: 24px;
    max-width: 760px;
    padding-bottom: 24px;
}

.ctv__field { display: flex; flex-direction: column; gap: 8px; }

.ctv__label { font-size: var(--th-font-body); font-weight: 600; }
.ctv__hint { font-size: 13px; color: var(--color-text-maxcontrast); margin: 0 0 4px; }

/* v4.8.4 — classification */
.ctv__select {
    max-width: 320px;
    border-radius: var(--th-radius-control);
}

.ctv__select:focus {
    outline: none;
    border-color: var(--color-primary-element);
}

.ctv__select:focus-visible {
    box-shadow: 0 0 0 2px var(--color-primary-element);
}

/* v4.8.9 — One team / Multiple teams. Raw <button> with role="tab": a tab bar
   with aria-selected is one of the documented carve-outs from NcButton. */
.ctv__modes-tabs {
    display: flex;
    gap: 4px;
    margin-bottom: 16px;
    border-bottom: 1px solid var(--color-border);
}

/* Greyed, not gone. The reason sits underneath at full contrast. */
.ctv__mode-tab--disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.ctv__modes-reason {
    display: flex;
    align-items: center;
    gap: 6px;
    max-width: 70ch;
    margin: -8px 0 16px;
}

.ctv__mode-tab {
    background: transparent;
    border: none;
    border-bottom: 2px solid transparent;
    padding: 8px 14px;
    font-size: var(--th-font-body);
    color: var(--color-text-maxcontrast);
    cursor: pointer;
}

.ctv__mode-tab:hover {
    background: var(--color-background-hover);
}

/* Split from :hover on purpose — grouping the two is what silences the
   keyboard focus ring. */
.ctv__mode-tab:focus-visible {
    background: var(--color-background-hover);
    outline: 2px solid var(--color-primary-element);
    outline-offset: -2px;
}

.ctv__mode-tab--active {
    color: var(--color-main-text);
    font-weight: var(--th-font-weight-semibold);
    border-bottom-color: var(--color-primary-element);
}

/* v4.8.7 — member rows with a role control */
.ctv__members {
    list-style: none;
    padding: 0;
    margin: 8px 0 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.ctv__member {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 4px 0;
}

.ctv__member-name {
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: var(--th-font-body);
}

.ctv__select--role {
    flex: 0 0 auto;
    max-width: 160px;
}

.ctv__sr {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}

.ctv__done-warn,
.ctv__done-note {
    display: flex;
    align-items: center;
    gap: 8px;
    max-width: 70ch;
    margin: 0 0 12px;
    padding: 8px 12px;
    border-radius: var(--th-radius-control);
    font-size: var(--th-font-body);
}

.ctv__done-warn {
    color: var(--color-warning-text);
    border-inline-start: 3px solid var(--color-warning-text);
    background: var(--color-background-hover);
}

.ctv__done-note {
    color: var(--color-main-text);
    border-inline-start: 3px solid var(--color-success-text);
    background: var(--color-background-hover);
}

.ctv__policy-desc {
    max-width: 70ch;
    margin-top: 4px;
    padding: 8px 12px;
    border-inline-start: 3px solid var(--color-primary-element);
    background: var(--color-background-hover);
    border-radius: var(--th-radius-control);
    font-size: var(--th-font-body);
    line-height: var(--th-line-height-body);
    color: var(--color-main-text);
    white-space: pre-line;
}

.ctv__select--error {
    border-color: var(--color-error-text);
}

.ctv__error {
    font-size: var(--th-font-meta);
    color: var(--color-error-text);
}

.ctv__policy-note {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    margin: 4px 0 8px;
}

.ctv__setting-lock {
    display: inline-flex;
    vertical-align: middle;
    margin-inline-start: 4px;
    color: var(--color-text-maxcontrast);
}

/* v4.6.13 — optional expiration date */
.ctv__label-optional {
    font-weight: var(--th-font-weight-regular);
    color: var(--color-text-maxcontrast);
    margin-inline-start: 4px;
}
.ctv__expiry-row {
    display: flex;
    align-items: center;
    gap: 8px;
}
.ctv__date-input {
    /* Matches NcTextField's metrics so the row does not read as a different
       kind of control from the fields above it. A native <input type="date">
       rather than NcDateTimePicker: the value is a plain calendar date with no
       time component, which is exactly what the native control produces and
       what the server parses. */
    min-height: 44px;
    padding: 0 12px;
    border: 2px solid var(--color-border-maxcontrast);
    border-radius: var(--th-radius-control);
    background-color: var(--color-main-background);
    color: var(--color-main-text);
    font-size: var(--th-font-body);
}
.ctv__date-input:focus {
    /* NC's form-field convention: no outline, primary border on focus. See
       SKILLS.md § Focus visibility standard — this is the accepted pattern
       for inputs, and :focus-visible adds a ring on top. */
    outline: none;
    border-color: var(--color-primary-element);
}
.ctv__date-input:focus-visible {
    box-shadow: 0 0 0 2px var(--color-primary-element);
}

/* Team types */
.ctv__types {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}

.ctv__type {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 20px 12px;
    /* v3.100.14: neutral resting border (was --color-success-hover — a
       transient hover token, and the resting border isn't a state signal
       anyway; the icon accent below carries the type/state cue). */
    border: 2px solid var(--color-border);
    border-radius: var(--border-radius-large);
    cursor: pointer;
    text-align: center;
    transition: border-color 0.15s, background 0.15s;
}

/* Icon accent — one consistent colour across all three template-type cards.
   Only selection state distinguishes a card now, not which type it is. */
.ctv__type-icon { color: var(--color-success); }

.ctv__type:hover { background: var(--color-background-hover); }

/* Selected state — full-saturation primary token + matching text token
   (SKILLS.md state-colour rule). Matches the Basic/Advanced mode selector's
   treatment below and the phase stepper's active/info markers. Supersedes the
   earlier soft-tint exception (DESIGN.md §2.37) — reverted per Justin's
   follow-up request for visual consistency across all selection tiles.
   Uses --color-primary-element (not --color-success) so a *selected* tile
   doesn't read as a "success/done" state — success is reserved for the
   phase stepper's completed markers. */
.ctv__type--selected,
.ctv__type--selected:hover {
    border-color: var(--color-primary-element);
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

.ctv__type-name { font-weight: 600; font-size: var(--th-font-body); }
.ctv__type-desc { font-size: var(--th-font-meta); color: var(--color-text-maxcontrast); line-height: 1.4; }
.ctv__type--selected .ctv__type-desc { color: var(--color-primary-element-text); }

/* Project mode selector (Basic / Advanced) */
.ctv__modes {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.ctv__mode {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 14px 16px;
    border: 2px solid var(--color-border);
    border-radius: var(--border-radius-large);
    cursor: pointer;
    transition: border-color 0.15s, background 0.15s;
}

.ctv__mode:hover { border-color: var(--color-primary-element); background: var(--color-background-hover); }
.ctv__mode:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

/* Selected mode — full-saturation primary token + matching text (SKILLS.md). */
.ctv__mode--selected {
    border-color: var(--color-primary-element);
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

/* Re-assert selected colours on hover — .ctv__mode:hover has equal CSS
   specificity (class + pseudo-class) to .ctv__mode--selected (single class)
   and was winning the tie, reverting a hovered selected tile to the grey
   hover background while its text stayed white — unreadable. */
.ctv__mode--selected:hover {
    border-color: var(--color-primary-element);
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

.ctv__mode-name { font-weight: 600; font-size: var(--th-font-body); }
.ctv__mode-desc { font-size: var(--th-font-meta); line-height: 1.4; color: var(--color-text-maxcontrast); }
.ctv__mode--selected .ctv__mode-desc { color: var(--color-primary-element-text); }

/* v3.100.1 — locked-out project mode (Advanced without a valid license).
   Not disabled outright — we still render the tile so the user sees the
   feature exists — but the click handler no-ops and the tile is muted so
   it doesn't compete visually with the picked mode. */
.ctv__mode--locked {
    cursor: not-allowed;
    opacity: 0.55;
    background: var(--color-background-hover);
}
/* v4.9.3 — same treatment for a template card the creator cannot finish. */
.ctv__type--locked,
.ctv__type--locked:hover {
    cursor: not-allowed;
    opacity: 0.55;
    background: var(--color-background-hover);
    border-color: var(--color-border);
}
.ctv__mode--locked:hover {
    border-color: var(--color-border);
    background: var(--color-background-hover);
}
/* MDI icons render as an inline-block <span> containing the SVG; nudge
   alignment so the lock sits on the text baseline of the label. */
.ctv__mode-lock {
    display: inline-flex;
    align-items: center;
    margin-left: 6px;
    vertical-align: -2px;
    color: var(--color-text-maxcontrast);
}

/* Member search */
.ctv__member-search { position: relative; }

.ctv__user-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    z-index: 200;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    max-height: 240px;
    overflow-y: auto;
}

.ctv__user-result {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    cursor: pointer;
}

.ctv__user-result:hover { background: var(--color-background-hover); }

.ctv__group-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    /* v3.100.14: neutral surface for a decorative "not a photo"
       avatar per SKILLS.md — the primary-coloured icon inside carries
       the accent. Was --color-primary-element-light which is a state
       token and shouldn't back non-state surfaces. */
    background: var(--color-background-dark);
    color: var(--color-primary-element);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.ctv__group-avatar--small {
    width: 24px;
    height: 24px;
}

.ctv__user-info { display: flex; flex-direction: column; }
.ctv__user-name { font-size: var(--th-font-body); font-weight: 500; }
.ctv__user-id { font-size: var(--th-font-meta); color: var(--color-text-maxcontrast); }

.ctv__chips { display: flex; flex-wrap: wrap; gap: 8px; }

.ctv__chip {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 4px 10px 4px 6px;
    background: var(--color-background-dark);
    border-radius: var(--border-radius-pill);
    font-size: 13px;
}

.ctv__chip-remove {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    display: flex;
    align-items: center;
    color: var(--color-text-maxcontrast);
}

.ctv__chip-remove:hover { color: var(--color-error-text); }

/* App options */
.ctv__apps { display: flex; flex-direction: column; gap: 10px; }

.ctv__app {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    cursor: pointer;
    transition: background 0.15s, border-color 0.15s;
}

.ctv__app:hover { background: var(--color-background-hover); }
/* v3.104.5: reverted 3.100.14's full-saturation treatment. The Apps
   step is a MULTI-select (a project team ticks all four apps by
   default), so all four tiles turn into solid dark-green blocks with
   white text on them — the eye reads the whole card as a "primary
   button" and the app name washes out. Reverted to the light-tint
   background + dark border pattern used pre-3.100.14: the border tells
   you it's selected, the light tint is a soft state cue, and both the
   app name and description keep their normal (readable) text colours.
   Same reasoning applied to .ctv__module below. */
.ctv__app:has(.ctv__app-check:checked) {
    border-color: var(--color-primary-element);
    background: var(--color-primary-element-light);
}

/* v4.4.10 — parallels .ctv__module--disabled. Applied to the two toggle-
   only app rows (Intravox, Collective) when their backing NC app isn't
   installed. */
.ctv__app--disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.ctv__app--disabled:hover { background: transparent; }

.ctv__app-check {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: var(--color-primary-element);
    flex-shrink: 0;
}

.ctv__app-icon { color: var(--color-primary-element); flex-shrink: 0; }
.ctv__app-text { display: flex; flex-direction: column; gap: 2px; }
.ctv__app-name { font-size: var(--th-font-body); font-weight: 600; }
.ctv__app-desc { font-size: var(--th-font-meta); color: var(--color-text-maxcontrast); }

/* Compact variant: header row with inline toggle, picker below only when needed */
.ctv__app--compact {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 0;
    cursor: default;
}

.ctv__app-row {
    display: flex;
    align-items: center;
    gap: 10px;
}

.ctv__app-header {
    display: flex;
    align-items: center;
    gap: 14px;
    cursor: pointer;
    flex: 1;
    min-width: 0;
}

/* v4.4.15 — pair of NcButtons acting as a select. Flex so the two
   buttons sit side by side, small gap so they read as related without
   the segmented-pill look Justin flagged. No custom button styling —
   NcButton owns the primary vs secondary variants that carry which
   mode is active. */
.ctv__app-mode {
    display: flex;
    flex-shrink: 0;
    gap: 4px;
}

.ctv__app-picker {
    margin-left: 56px;
    margin-top: 8px;
    margin-bottom: 4px;
    max-width: 360px;
}

/* Progress */
.ctv__progress {
    display: flex;
    flex-direction: column;
    gap: 18px;
    padding: 32px 0;
}

/* v4.9.6 — the server-driven progress list brings its own spacing; the
   actions row under it reuses the success panel's. */
.ctv__progress--workspace {
    gap: var(--th-space-lg);
    padding: var(--th-space-lg) 0;
}

.ctv__progress-task {
    display: flex;
    align-items: center;
    gap: 14px;
    font-size: 15px;
}

.ctv__progress-done { color: var(--color-success-text); }
.ctv__progress-error { color: var(--color-error-text); }
.ctv__progress-dot {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: var(--color-border);
    display: inline-block;
    flex-shrink: 0;
}
.ctv__progress-label--dim { color: var(--color-text-maxcontrast); }

/* Success hand-off (v4.4.5) */
.ctv__done {
    display: flex;
    flex-direction: column;
    padding: 24px 0 8px;
}

.ctv__done-head {
    display: flex;
    align-items: center;
    gap: 14px;
}

.ctv__done-icon { color: var(--color-success-text); }

.ctv__done-title {
    margin: 0;
    font-size: var(--th-font-heading-lg);
    font-weight: var(--th-font-weight-bold);
    line-height: var(--th-line-height-tight);
    color: var(--color-main-text);
    /* Team names can be long; wrap rather than overflow the panel. */
    overflow-wrap: anywhere;
}

.ctv__done-summary {
    margin: 18px 0 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.ctv__done-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 8px;
}

.ctv__done-chip {
    padding: 3px 10px;
    border-radius: var(--th-radius-pill);
    background: var(--color-background-dark);
    color: var(--color-main-text);
    font-size: var(--th-font-micro);
    font-weight: var(--th-font-weight-medium);
}

.ctv__done-next-label {
    margin: 28px 0 10px;
    font-size: var(--th-font-meta);
    font-weight: var(--th-font-weight-semibold);
    color: var(--color-main-text);
}

.ctv__done-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.ctv__done-hint {
    margin: 16px 0 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    max-width: 62ch;
}

/* Footer */
.ctv__footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    /* Kept equal to .ctv__inner — see the note there. */
    max-width: 1200px;
    width: 100%;
    margin: 0 auto;
    padding: 24px 40px;
    border-top: 1px solid var(--color-border);
    box-sizing: border-box;
}

.ctv__footer-right { display: flex; gap: 8px; }

/* Team settings */
.ctv__settings-groups {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.ctv__settings-group {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.ctv__settings-group-label {
    font-size: var(--th-font-meta);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-maxcontrast);
    margin-bottom: 6px;
    display: block;
}

.ctv__setting {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 10px 12px;
    border-radius: var(--border-radius-large);
    cursor: pointer;
    transition: background 0.12s;
}

.ctv__setting:hover { background: var(--color-background-hover); }

.ctv__setting-name { font-size: var(--th-font-body); font-weight: 500; line-height: 1.3; display: block; }
.ctv__setting-desc { font-size: var(--th-font-meta); color: var(--color-text-maxcontrast); line-height: 1.4; display: block; }

/* Files — team folders hint */
.ctv__app-hint {
    font-size: var(--th-font-micro);
    color: var(--color-text-maxcontrast);
    font-style: italic;
    display: block;
    margin-top: 2px;
}

/* Team modules */
.ctv__modules { display: flex; flex-direction: column; gap: 8px; }

.ctv__module {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 14px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    cursor: pointer;
    transition: background 0.15s, border-color 0.15s;
}

.ctv__module:hover { background: var(--color-background-hover); }
/* v3.104.5: reverted 3.100.14's full-saturation treatment — same
   reasoning as .ctv__app above. Team modules is a multi-select and a
   fully saturated selected state read as "everything is a primary
   action" with unreadable module names in white on dark green.
   Light-tint background + dark border is the correct pattern. */
.ctv__module:has(.ctv__module-check:checked) {
    border-color: var(--color-primary-element);
    background: var(--color-primary-element-light);
}

.ctv__module--disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.ctv__module--disabled:hover { background: transparent; }

.ctv__module-check {
    width: 16px;
    height: 16px;
    margin-top: 2px;
    accent-color: var(--color-primary-element);
    flex-shrink: 0;
    cursor: inherit;
}

.ctv__module-icon { color: var(--color-primary-element); flex-shrink: 0; margin-top: 1px; }
.ctv__module--disabled .ctv__module-icon { color: var(--color-text-maxcontrast); }
.ctv__module-text { display: flex; flex-direction: column; gap: 2px; }
.ctv__module-name { font-size: var(--th-font-body); font-weight: 600; }
.ctv__module-desc { font-size: var(--th-font-meta); color: var(--color-text-maxcontrast); }

.ctv__module-unavailable {
    font-size: var(--th-font-micro);
    color: var(--color-warning-text);
    font-style: italic;
    margin-top: 2px;
}
</style>
