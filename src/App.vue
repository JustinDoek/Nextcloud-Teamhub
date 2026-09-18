<template>
    <NcContent app-name="teamhub">
        <NcAppNavigation :aria-label="t('teamhub', 'Teams navigation')">
            <template #list>
                <!-- Spacer to clear the show/hide sidebar toggle button -->
                <div class="teamhub-nav-spacer" />

                <!-- v4.3.17 — highlighted "primary action" style for the
                     New Team item, matching NC's own sidebar convention
                     (Files "+ New", Mail "Compose", etc. render the top
                     item in the primary-element colour). Applied via a
                     custom class rather than the `:active` prop because
                     `active` semantically means "currently viewing" —
                     we want prominence regardless of the active view. -->
                <NcAppNavigationItem
                    v-if="canCreateTeam"
                    class="teamhub-nav-primary"
                    :name="t('teamhub', 'New Team')"
                    @click="startCreateTeam">
                    <template #icon>
                        <Plus :size="20" />
                    </template>
                </NcAppNavigationItem>

                <NcAppNavigationItem
                    :name="t('teamhub', 'Browse Teams')"
                    :active="activeView === 'browse'"
                    @click="showView('browse')">
                    <template #icon>
                        <Magnify :size="20" />
                    </template>
                </NcAppNavigationItem>

                <!-- v4.2.12 — Personal "What’s new" feed. Aggregates recent
                     messages from every team the user is a member of plus
                     public messages from other teams. Sits under Browse
                     Teams because it's a cross-team view, not a per-team one.
                     v4.3.0 — gated behind an active TeamHub license. The
                     backend endpoint enforces the gate too (SKILLS §Security
                     standards: frontend is not a security boundary). -->
                <NcAppNavigationItem
                    v-if="isLicensed"
                    :name="t('teamhub', 'What’s new')"
                    :active="activeView === 'feed'"
                    @click="showView('feed')">
                    <template #icon>
                        <Rss :size="20" />
                    </template>
                </NcAppNavigationItem>

                <!-- v4.5.21 — My Work. Sits directly under What's new, per the
                     information architecture: What's new is what happened in
                     the teams, My Work is what the teams are waiting on from
                     you. Same licence gate as the feed above it — the backend
                     enforces it too, this only hides the entry.
                     The counter shows Action Required only. Showing the total
                     would put a permanent double-digit badge on the sidebar
                     that nobody can ever clear; Action Required is the number
                     that is genuinely meant to reach zero. -->
                <NcAppNavigationItem
                    v-if="isLicensed"
                    :name="t('teamhub', 'My Work')"
                    :active="activeView === 'mywork'"
                    @click="showMyWork">
                    <template #icon>
                        <ClipboardCheckOutline :size="20" />
                    </template>
                    <template v-if="actionRequiredCount > 0" #counter>
                        <NcCounterBubble type="highlighted" :count="actionRequiredCount" />
                    </template>
                </NcAppNavigationItem>

                <NcAppNavigationCaption :name="t('teamhub', 'My Teams')" />

                <!-- v4.7.3 — personal grouping. Groups first, then the teams
                     that are in none of them as loose rows. There is no
                     catch-all group on purpose: a user who has grouped
                     nothing sees exactly the flat list that shipped before
                     this feature, with one empty Favorites row above it. -->
                <TeamNavGroup
                    v-for="group in sidebarTeamGroups.groups"
                    :key="group.id"
                    :group="group"
                    :active-team-id="activeView === 'team' ? currentTeamId : null"
                    @toggle="onGroupToggle"
                    @rename="onGroupRename"
                    @delete="onGroupDelete"
                    @select="selectTeamFromSidebar"
                    @manage="onSidebarManageTeam"
                    @copy-link="onSidebarCopyLink"
                    @invite="onSidebarInvite"
                    @email-members="onSidebarEmailMembers"
                    @leave="onSidebarLeave"
                    @pick-group="onGroupPickerOpen"
                    @create-group="onGroupCreate" />

                <!-- v4.7.3 — the line between "filed" and "not filed yet".
                     Only drawn when there is something on both sides of it:
                     before the grouping preference has loaded there are no
                     group rows at all, and a user who has filed every team
                     has nothing below it — a rule with one side is just a
                     stray mark. An <li> because NcAppNavigation renders its
                     #list slot inside a <ul>, and aria-hidden because the
                     division is already carried by the group rows
                     themselves; announcing it twice adds nothing. -->
                <li
                    v-if="sidebarTeamGroups.groups.length && sidebarTeamGroups.ungrouped.length"
                    class="teamhub-nav-divider"
                    aria-hidden="true" />

                <TeamNavItem
                    v-for="team in sidebarTeamGroups.ungrouped"
                    :key="team.id"
                    :team="team"
                    :active="team.id === currentTeamId && activeView === 'team'"
                    current-group-id=""
                    @select="selectTeamFromSidebar"
                    @manage="onSidebarManageTeam"
                    @copy-link="onSidebarCopyLink"
                    @invite="onSidebarInvite"
                    @email-members="onSidebarEmailMembers"
                    @leave="onSidebarLeave"
                    @pick-group="onGroupPickerOpen"
                    @create-group="onGroupCreate" />

                <!-- v4.4.3 — role-shaped copy. "Create your first team above"
                     is wrong for a user who cannot create teams: there is no
                     New Team item above them to act on. SKILLS.md § Permissions
                     hides restricted actions rather than disabling them; the
                     same rule applies to advice.
                     Deliberately no buttons here — New Team and Browse Teams
                     already sit three rows up in this same column, so a button
                     would be duplicate chrome in a narrow space. The
                     content-area empty state carries the actions. -->
                <NcEmptyContent
                    v-if="!loading.teams && teams.length === 0"
                    :name="t('teamhub', 'No teams yet')"
                    :description="canCreateTeam
                        ? t('teamhub', 'Create your first team above')
                        : t('teamhub', 'Browse teams to find one to join')">
                    <template #icon>
                        <AccountGroup :size="iconHero" />
                    </template>
                </NcEmptyContent>

                <!-- v4.2.6 — sidebar footer. Rendered only on UNLICENSED
                     instances: brand mark + wordmark + "Community version"
                     label above the docs/help ? button. On licensed instances
                     (Active/Trial/Grace) the whole block is hidden — no
                     brand, no version tag, no help button. License state
                     comes from the member-callable /api/v1/license/entitlements
                     endpoint (loaded once on mount). Fails-open: if the
                     entitlements call fails we assume unlicensed so the
                     branding still appears rather than silently vanishing. -->
                <template v-if="!isLicensed">
                    <div class="teamhub-feedback-separator" />

                    <!-- v4.5.0 — Announcement callout. Auto-visible whenever
                         there is at least one unread announcement matching
                         this instance's version and this user's role. Sits
                         above the getting-started hint so it's the first
                         thing an unlicensed user sees. Purely instructional:
                         the callout points at the envelope button in the
                         brand row (like the getting-started hint points at
                         the ? button), and the envelope is the interactive
                         element. Dismissal happens in the canvas view via
                         "Got it, don't show again" — there is deliberately
                         no X here, so the user must open and read the
                         message to make it go away. -->
                    <li v-if="hasAnnouncements" class="teamhub-announcement-hint">
                        <p class="teamhub-announcement-hint__lead">
                            {{ announcementLead }}
                        </p>
                        <p class="teamhub-announcement-hint__click">
                            <span>{{ t('teamhub', 'Click the') }}</span>
                            <EmailOutline :size="16" aria-hidden="true" />
                            <span>{{ t('teamhub', 'below') }}</span>
                        </p>
                    </li>

                    <!-- v4.4.12 — "Getting started" callout. An unlabelled ?
                         glyph in a sidebar footer is not an affordance anyone
                         acts on; this names the destination before the click.
                         Styled as a speech bubble whose tail points down-right
                         at the button, but it is an ordinary block in the list
                         flow — no portal, no positioning library, and it reads
                         in DOM order for screen readers.
                         Inside the !isLicensed block, so it inherits the same
                         licence gate as the help button it points at: no
                         licensed instance can ever render it. Per-user opt-out
                         lives in Settings → Personal → TeamHub. -->
                    <li v-if="showGettingStartedHint" class="teamhub-hint">
                        <!-- Raw <button>, not NcButton: at 20 px this is below
                             NcButton's 44 px touch-target minimum, the same
                             carve-out SKILLS.md § "NcButton is the default"
                             grants chip-remove buttons. Six-lock circle recipe
                             per SKILLS.md § "UI shapes" — NC's global button
                             reset sets min-width AND min-height to 44 px and
                             would otherwise stretch this into an oval. -->
                        <button
                            type="button"
                            class="teamhub-hint__close"
                            :title="t('teamhub', 'Close')"
                            :aria-label="t('teamhub', 'Close')"
                            @click="dismissGettingStartedHint">
                            <Close :size="14" aria-hidden="true" />
                        </button>
                        <!-- v4.4.15 — second line reads "Click the [icon]
                             below", with the icon rendered inline between
                             two translated fragments so the sentence names
                             the button by its glyph. The icon is aria-hidden
                             — the accessible name for the action lives on
                             the help button itself; the callout is context,
                             not a target. -->
                        <p class="teamhub-hint__lead">
                            {{ t('teamhub', 'Need help getting started?') }}
                        </p>
                        <p class="teamhub-hint__click">
                            <span>{{ t('teamhub', 'Click the') }}</span>
                            <HelpCircleOutlineIcon :size="16" aria-hidden="true" />
                            <span>{{ t('teamhub', 'below') }}</span>
                        </p>
                        <p class="teamhub-hint__sub">
                            {{ t('teamhub', 'You can disable this message by unchecking “Getting started” under Personal settings → TeamHub.') }}
                        </p>
                    </li>

                    <!-- v4.2.8 — brand mark + wordmark + tagline + help button
                         collapsed into a single row. Help button sits on the
                         right, pushed there by margin-left:auto on the button
                         wrapper, so the wordmark + tagline stay left-aligned
                         and the ? affordance mirrors the sidebar header rhythm. -->
                    <li class="teamhub-brand-item">
                        <div class="teamhub-brand__mark" aria-hidden="true">
                            <!-- v4.9.21 — the TeamHub beeldmerk (2026-09 brand sheet),
                                 the same geometry as img/logo.svg, inlined so it costs
                                 no request. A Hub Blue tile split by a Signal Orange
                                 diagonal: the team on the light field, the hub on the
                                 blue. Brand colours are literal by design — the tile is
                                 the same in both themes, which is what makes it read as
                                 the icon. Keep in step with img/logo.svg. -->
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="TeamHub">
                                <defs>
                                    <clipPath id="th-brand-tile"><rect x="2" y="2" width="60" height="60" rx="13" /></clipPath>
                                    <clipPath id="th-brand-field"><path d="M2 2H62L2 62Z" /></clipPath>
                                </defs>
                                <rect x="2" y="2" width="60" height="60" rx="13" fill="#245C80" />
                                <g clip-path="url(#th-brand-tile)">
                                    <path d="M2 2H62L2 62Z" fill="#FFFFFF" />
                                    <g clip-path="url(#th-brand-field)">
                                        <g fill="#328FB7">
                                            <circle cx="8" cy="22" r="4.4" />
                                            <rect x="0.5" y="28.5" width="12.5" height="14" rx="6.25" />
                                        </g>
                                        <g fill="#7FDDEE">
                                            <circle cx="37" cy="18" r="4.4" />
                                            <rect x="28" y="25" width="14" height="14" rx="7" />
                                        </g>
                                        <circle cx="24" cy="14" r="6.4" fill="#FB5000" />
                                        <rect x="14" y="22.5" width="19" height="20" rx="9.5" fill="#245C80" />
                                    </g>
                                    <path d="M2 62L62 2L62 8L8 62Z" fill="#FB5000" />
                                    <g stroke="#FFFFFF" stroke-width="1.6" stroke-linecap="round">
                                        <line x1="46" y1="46" x2="34" y2="53" />
                                        <line x1="46" y1="46" x2="53" y2="34" />
                                        <line x1="46" y1="46" x2="56" y2="56" />
                                        <line x1="46" y1="46" x2="38" y2="40" />
                                    </g>
                                    <g fill="#FFFFFF">
                                        <circle cx="34" cy="53" r="2.6" />
                                        <circle cx="53" cy="34" r="2.6" />
                                        <circle cx="56" cy="56" r="2.6" />
                                        <circle cx="38" cy="40" r="2.6" />
                                    </g>
                                    <circle cx="46" cy="46" r="5.2" fill="#FB5000" stroke="#FFFFFF" stroke-width="1.6" />
                                </g>
                            </svg>
                        </div>
                        <div class="teamhub-brand__text">
                            <!-- The woordmerk: "Team" in Hub Blue (white on dark), "Hub"
                                 in Signal Orange in both themes, per the brand sheet. -->
                            <span class="teamhub-brand__wordmark">Team<span class="teamhub-brand__wordmark-hub">Hub</span></span>
                            <span class="teamhub-brand__tagline">{{ t('teamhub', 'Community version') }}</span>
                        </div>
                        <div class="teamhub-brand__help">
                            <!-- v4.5.0 — Announcements envelope. This is
                                 THE interactive affordance for announcements
                                 — the callout above (.teamhub-announcement-hint)
                                 is purely instructional and points here.
                                 Clicking opens the first unread in the
                                 canvas view. -->

                            <NcButton
                                v-if="hasAnnouncements"
                                variant="tertiary"
                                :title="announcementTitle"
                                :aria-label="announcementTitle"
                                @click="openFirstAnnouncement">
                                <template #icon>
                                    <EmailOutline :size="20" />
                                </template>
                            </NcButton>
                            <NcButton
                                variant="tertiary"
                                :title="t('teamhub', 'Help & Documentation')"
                                :aria-label="t('teamhub', 'Help & Documentation')"
                                @click="openDocs">
                                <template #icon>
                                    <HelpCircleOutlineIcon :size="20" />
                                </template>
                            </NcButton>
                        </div>
                    </li>
                </template>
            </template>
        </NcAppNavigation>

        <NcAppContent>
            <AnnouncementView
                v-if="activeView === 'announcement' && announcementFilename"
                :filename="announcementFilename"
                @dismissed="onAnnouncementDismissed"
                @close="closeAnnouncement" />

            <CreateTeamView
                v-else-if="activeView === 'create'"
                @created="onTeamCreated"
                @cancel="onCreateCancel" />

            <ManageTeamView
                v-else-if="activeView === 'manage' && currentTeam"
                :team="currentTeam"
                @description-updated="onDescriptionUpdated"
                @team-deleted="onTeamDeleted"
                @ownership-transferred="onOwnershipTransferred" />

            <BrowseTeamsView
                v-else-if="activeView === 'browse'"
                @team-joined="onTeamJoined"
                @team-opened="selectTeamFromSidebar" />

            <!-- v4.6.17 — a shared team link, opened by somebody who is not in
                 the team. Checked before the !currentTeamId branch below, which
                 would otherwise swallow it with the generic welcome screen. -->
            <TeamJoinView
                v-else-if="activeView === 'join' && joinTeamId"
                :key="joinTeamId"
                :team-id="joinTeamId"
                @joined="onJoinedViaLink"
                @open-team="onJoinedViaLink"
                @browse="showView('browse')" />

            <WhatsHappeningView
                v-else-if="activeView === 'feed' && isLicensed"
                @open-team="selectTeamFromSidebar"
                @open-team-talk="onOpenTeamTalk"
                @open-item="onOpenFeedItem" />

            <MyWorkView
                v-else-if="activeView === 'mywork' && isLicensed"
                @open-team="selectTeamFromSidebar"
                @open-item="onOpenMyWorkItem"
                @counts-changed="actionRequiredCount = $event" />

            <!-- v4.5.21 — same licence fallback the feed has, for a stale
                 ?mywork deep-link on an instance whose licence was removed. -->
            <NcEmptyContent
                v-else-if="activeView === 'mywork' && !isLicensed"
                :name="t('teamhub', 'License required')"
                :description="t('teamhub', 'My Work requires an active TeamHub license. Add or renew a license in Admin settings → License to unlock your personal work queue.')">
                <template #icon><ClipboardCheckOutline :size="48" /></template>
            </NcEmptyContent>

            <!-- v4.3.0 — license-required fallback for the feed view.
                 Reachable via a stale ?feed deep-link on an instance
                 whose license was removed after the sidebar was rendered. -->
            <NcEmptyContent
                v-else-if="activeView === 'feed' && !isLicensed"
                :name="t('teamhub', 'License required')"
                :description="t('teamhub', 'What’s new requires an active TeamHub license. Add or renew a license in Admin settings → License to unlock the feed.')">
                <template #icon><Rss :size="48" /></template>
            </NcEmptyContent>

            <!-- v4.4.3 — the "no team selected" state covers two situations
                 that used to share one message: a user with no teams at all,
                 who is at an onboarding moment and needs somewhere to go, and
                 a user who simply hasn't picked a team yet, who just needs to
                 pick one. Splitting them lets the first carry actions without
                 showing "create your first team" to someone who has five.
                 While the team list is still loading we fall through to the
                 neutral "select a team" branch rather than flashing the
                 onboarding copy and then replacing it. -->
            <template v-else-if="!currentTeamId">
                <NcEmptyContent
                    v-if="!loading.teams && teams.length === 0"
                    :name="t('teamhub', 'Welcome to TeamHub')"
                    :description="canCreateTeam
                        ? t('teamhub', 'Teams are shared spaces for messages, files, calendars and tasks. Create your first team, or browse the ones you can join.')
                        : t('teamhub', 'Browse the teams on this server to find one to join, or ask a team admin to add you.')">
                    <template #icon>
                        <AccountGroup :size="iconHero" />
                    </template>
                    <template #action>
                        <!-- v4.6.2 — the two buttons used to stack. NcEmptyContent's
                             own `.empty-content__action` is a plain block: the
                             `display: flex` that would lay them out in a row is
                             scoped to `.modal-wrapper .empty-content__action` in
                             NC's stylesheet, and NcButton renders as a block-level
                             flex box, so outside a modal each button takes its own
                             line. Fixed with our own flex row INSIDE the slot —
                             slot content carries this component's scope attribute,
                             so plain scoped CSS reaches it and we never have to
                             :deep() into an NC component's internals. -->
                        <div class="th-welcome-actions">
                            <NcButton
                                v-if="canCreateTeam"
                                variant="primary"
                                @click="startCreateTeam">
                                <template #icon><Plus :size="iconNav" /></template>
                                {{ t('teamhub', 'New Team') }}
                            </NcButton>
                            <NcButton
                                :variant="canCreateTeam ? 'secondary' : 'primary'"
                                @click="showView('browse')">
                                <template #icon><Magnify :size="iconNav" /></template>
                                {{ t('teamhub', 'Browse Teams') }}
                            </NcButton>
                        </div>

                        <!-- v4.6.2 — NC admins land here on a fresh install with
                             nothing configured yet. The setup checklist is the one
                             surface that says what is still unset, and nothing in
                             the app pointed at it, so an admin who never opened
                             Administration settings never learned it existed.
                             Admin-only: a member has no Administration settings to
                             open, and SKILLS.md § Permissions hides what a role
                             cannot act on rather than showing it disabled.
                             It gates a link, never an action — every admin surface
                             behind it enforces its own server-side admin check. -->
                        <NcNoteCard
                            v-if="isNcAdmin"
                            class="th-welcome-admin-note"
                            type="info"
                            :heading="t('teamhub', 'Finish setting up TeamHub')">
                            <!-- TRANSLATORS: the → characters separate a
                                 navigation path through the Nextcloud admin
                                 UI. All three names are labels that appear
                                 elsewhere in this file — "Setup checklist"
                                 especially — so translate them the same way
                                 here or the path will not match what the
                                 admin actually sees on screen. -->
                            <p class="th-welcome-admin-note__body">
                                {{ t('teamhub', 'You are a Nextcloud administrator. Administration settings → TeamHub → Setup checklist shows what still needs configuring on this server.') }}
                            </p>
                            <NcButton
                                variant="secondary"
                                :href="adminSettingsUrl">
                                <template #icon><CogOutline :size="iconNav" /></template>
                                {{ t('teamhub', 'Open setup checklist') }}
                            </NcButton>
                        </NcNoteCard>
                    </template>
                </NcEmptyContent>

                <NcEmptyContent
                    v-else
                    :name="t('teamhub', 'Welcome to TeamHub')"
                    :description="t('teamhub', 'Select a team from the sidebar to get started')">
                    <template #icon>
                        <AccountGroup :size="iconHero" />
                    </template>
                </NcEmptyContent>
            </template>

            <TeamView
                v-else
                :key="currentTeamId"
                @show-manage-team="showView('manage')"
                @team-left="onTeamLeft" />
        </NcAppContent>

        <!-- v4.7.15 — filing a team under a sidebar group. Mounted here
             rather than in TeamView because it is reached from any team's
             row, including teams the reader is not currently looking at.
             v-if rather than an `open` prop so the radios are rebuilt for
             each team, matching how the rest of the app mounts a dialog. -->
        <TeamGroupPickerModal
            v-if="groupPicker"
            :team-id="groupPicker.teamId"
            :team-name="groupPicker.teamName"
            :current-group-id="groupPicker.groupId"
            :groups="sidebarTeamGroups.groups"
            @assign="onTeamAssignGroup"
            @close="groupPicker = null" />

    </NcContent>
</template>

<script>
import { mapState, mapActions, mapGetters, mapMutations } from 'vuex'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { emit } from '@nextcloud/event-bus'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcContent, NcAppNavigation, NcAppNavigationItem, NcAppNavigationCaption, NcAppContent, NcEmptyContent, NcCounterBubble, NcButton, NcNoteCard } from '@nextcloud/vue'
import { showSuccess, showError, showWarning } from '@nextcloud/dialogs'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Rss from 'vue-material-design-icons/Rss.vue'
import HelpCircleOutlineIcon from 'vue-material-design-icons/HelpCircleOutline.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import Close from 'vue-material-design-icons/Close.vue'
// Also the icon on the sidebar's "Email all members" action (v4.6.26).
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import { fileOpenUrl } from './lib/filesCollab.js'

// v4.4.12 — session-scoped "user closed the Getting started callout" flag.
// Wrapped because sessionStorage throws in Safari private mode and is absent
// in SSR/test contexts; a storage failure must never take down the sidebar.
const HINT_CLOSED_KEY = 'teamhub:getting-started-closed'

function readSessionFlag(key) {
    try {
        return window.sessionStorage?.getItem(key) === '1'
    } catch (e) {
        return false
    }
}

function writeSessionFlag(key) {
    try {
        window.sessionStorage?.setItem(key, '1')
    } catch (e) {
        // Non-fatal: the callout simply reappears on the next page load.
    }
}
import TeamView from './components/TeamView.vue'
import BrowseTeamsView from './components/BrowseTeamsView.vue'
import ManageTeamView from './components/ManageTeamView.vue'
import CreateTeamView from './components/CreateTeamView.vue'
import WhatsHappeningView from './components/WhatsHappeningView.vue'
import AnnouncementView from './components/AnnouncementView.vue'
import MyWorkView from './components/MyWorkView.vue'
import TeamJoinView from './components/TeamJoinView.vue'
import TeamGroupPickerModal from './components/TeamGroupPickerModal.vue'
import TeamNavGroup from './components/TeamNavGroup.vue'
import TeamNavItem from './components/TeamNavItem.vue'
import { ICON_NAV, ICON_HERO } from './constants/uiTokens.js'
import { OPEN_KIND, TEAMHUB_VIEW_TARGETS } from './constants/myWork.js'

export default {
    name: 'App',
    components: {
        NcContent, NcAppNavigation, NcAppNavigationItem, NcAppNavigationCaption, NcAppContent, NcEmptyContent, NcCounterBubble, NcButton, NcNoteCard,
        AccountGroup, Plus, Magnify, Rss, HelpCircleOutlineIcon,
        CogOutline, Close, EmailOutline,
        ClipboardCheckOutline,
        TeamView, BrowseTeamsView, ManageTeamView, CreateTeamView, WhatsHappeningView, AnnouncementView,
        MyWorkView, TeamGroupPickerModal, TeamJoinView, TeamNavGroup, TeamNavItem,
    },
    data() {
        return {
            activeView: null,
            // v4.7.15 — the team whose sidebar group is being picked, as
            // { teamId, teamName, groupId }. Null whenever the dialog is shut.
            groupPicker: null,
            // v4.6.17 — team behind a shared ?team= link that the current user
            // is not in. Drives TeamJoinView; null at every other moment.
            joinTeamId: null,
            canCreateTeam: true,
            // v4.6.2 — is the current user an NC admin? Rides the existing
            // can-create-team fetch. Starts FALSE and stays false if that call
            // fails: canCreateTeam fails OPEN because hiding a button a user is
            // entitled to press is the worse error, but this one fails CLOSED —
            // pointing a non-admin at Administration settings is a dead end,
            // and the checklist pointer going quiet costs nothing.
            isNcAdmin: false,
            // True when the NC sidebar renders as an overlay that should
            // auto-close on selection: phone portrait (≤768px) OR tablet
            // portrait (≤1024px and orientation:portrait).
            isMobileSidebar: false,
            _mobileSidebarMql: null,
            _mobileSidebarMqlHandler: null,
            // v4.2.7 — license state for the sidebar footer. Read once on
            // mount via /api/v1/license/entitlements (member-callable, unlike
            // /admin/license which requires admin). enforcementLevel is one
            // of: 'none' (fully active) | 'grace' | 'soft-lock' | 'unlicensed'.
            // Kept nullable — `null` means "not yet loaded or errored"; the
            // isLicensed computed treats null as licensed=true so the brand
            // block on licensed instances never flashes before hiding.
            licenseEntitlements: null,
            // v4.4.12 — per-user "Getting started" callout above the help
            // button. Starts FALSE and is switched on by the preferences
            // fetch, so a user who turned it off never sees it flash back on
            // during load. Errors leave it false — a hint that silently
            // stays hidden is a better failure than one that reappears
            // after the user opted out.
            gettingStartedHint: false,
            // v4.4.12 — the X closes the callout for the rest of the browser
            // session. Deliberately NOT the same thing as the personal-settings
            // switch: the callout's own copy points the user at that switch for
            // a permanent opt-out, so making X write the preference would make
            // that sentence wrong. sessionStorage rather than component state
            // so an F5 doesn't bring it straight back.
            gettingStartedHintClosed: readSessionFlag(HINT_CLOSED_KEY),
            // v4.4.17 — in-app announcements from TeamHub HQ. Unlicensed-only,
            // filtered server-side by version + role + dismissal state. Empty
            // array while the fetch is in flight so the envelope never flashes
            // on a licensed instance that briefly appears unlicensed during load.
            announcements: [],
            // Which announcement is currently open in the main canvas. `null`
            // when the announcement view is not active.
            announcementFilename: null,
            // v4.5.21 — Action Required count for the My Work sidebar badge.
            // Fetched once on mount and refreshed whenever the user leaves the
            // My Work view, deliberately NOT polled: the counts endpoint fans
            // out to every provider, and a per-minute fan-out for every logged
            // in user is a real cost for a number that changes slowly. The
            // badge is therefore "correct when you arrive and after you act",
            // not live.
            actionRequiredCount: 0,
        }
    },
    computed: {
        ...mapState(['teams', 'currentTeamId', 'loading']),
        ...mapGetters(['currentTeam', 'sidebarTeamGroups']),
        // Icon-size tokens for the template. MDI's :size prop needs a number
        // at compile time and cannot read a CSS variable, so the scale is
        // mirrored in JS (src/constants/uiTokens.js). Exposed as computeds to
        // match the pattern in WidgetCollapseButton.vue.
        iconNav() { return ICON_NAV },
        iconHero() { return ICON_HERO },

        /**
         * v4.6.2 — deep link to Administration settings → TeamHub. The section
         * id comes from Settings\AdminSection::getID(); built through
         * generateUrl so it survives a sub-directory install rather than
         * hardcoding an absolute path.
         */
        adminSettingsUrl() {
            return generateUrl('/settings/admin/teamhub')
        },
        /**
         * True when a currently-honoured license is installed (Active, Trial,
         * or Grace). Determines whether the sidebar hides the branding + help
         * footer.
         *
         * v4.2.7 — defaults to TRUE while the entitlements call is in-flight
         * or if it errored, so the branding never briefly flashes on a
         * licensed instance before hiding. The trade-off is that a genuinely
         * unlicensed instance won't see the brand block for the few hundred
         * milliseconds it takes the endpoint to respond — an acceptable
         * silence on unlicensed vs a broken-looking flicker on licensed.
         */
        isLicensed() {
            if (this.licenseEntitlements === null) return true
            const level = this.licenseEntitlements.enforcementLevel
            return level === 'none' || level === 'grace'
        },

        // v4.4.17 — sidebar envelope visibility. The server already filters
        // by license state, but keep the frontend guard so the envelope
        // never renders on a licensed instance if the /announcements call
        // races the /entitlements one.
        hasAnnouncements() {
            return !this.isLicensed && this.announcements.length > 0
        },

        announcementTitle() {
            return n(
                'teamhub',
                'A new message from TeamHub',
                '{n} new messages from TeamHub',
                this.announcements.length,
                { n: this.announcements.length },
            )
        },

        announcementLead() {
            return n(
                'teamhub',
                'A new message from TeamHub is waiting for you.',
                '{n} new messages from TeamHub are waiting for you.',
                this.announcements.length,
                { n: this.announcements.length },
            )
        },

        /**
         * v4.4.12 — true when the content area is showing the "Welcome to
         * TeamHub" landing state rather than a team, the wizard, Browse or
         * the feed.
         *
         * Mirrors the template's else-if chain exactly: the landing branch is
         * `v-else-if="!currentTeamId"` after create / manage / browse / feed.
         * `manage` is deliberately NOT excluded here — its branch also
         * requires `currentTeam`, which cannot be set while `currentTeamId`
         * is empty, so a stray `activeView === 'manage'` with no team falls
         * through to the landing state and should still count as landing.
         */
        isLandingView() {
            if (this.currentTeamId) return false
            return this.activeView !== 'create'
                && this.activeView !== 'browse'
                && this.activeView !== 'feed'
        },

        /**
         * v4.4.12 — the callout renders only when all four hold:
         *   - the instance is positively known to be unlicensed
         *   - the user hasn't opted out in personal settings
         *   - they haven't closed it this browser session
         *   - they are on the landing page, not inside a team
         *
         * The template already sits inside `v-if="!isLicensed"`; repeating
         * the condition here keeps the rule readable at the single place that
         * decides it, and means a future move of the block can't silently
         * drop the gate.
         */
        showGettingStartedHint() {
            return !this.isLicensed
                && this.gettingStartedHint
                && !this.gettingStartedHintClosed
                && this.isLandingView
        },
    },
    watch: {
        /**
         * v4.5.21 — refresh the My Work badge whenever the user leaves the
         * view. A watcher rather than a hook inside showView(), because
         * activeView is also set directly by selectTeamFromSidebar,
         * startCreateTeam and onOpenMyWorkItem — and "leaving My Work by
         * opening one of its items" is precisely the case where the count has
         * most likely just changed.
         */
        activeView(next, previous) {
            if (previous === 'mywork' && next !== 'mywork' && this.isLicensed) {
                this.refreshMyWorkCount()
            }
        },
    },

    async mounted() {
        // Detect viewport states where NC's sidebar renders as an overlay.
        // We auto-close it after the user selects a team / action, matching
        // expected mobile nav behaviour without building a custom drawer.
        // Matches: phone (≤768px any orientation) OR tablet portrait (≤1024px portrait).
        if (typeof window !== 'undefined' && window.matchMedia) {
            const query = '(max-width: 768px), (max-width: 1024px) and (orientation: portrait)'
            this._mobileSidebarMql = window.matchMedia(query)
            this.isMobileSidebar = this._mobileSidebarMql.matches
            this._mobileSidebarMqlHandler = (e) => { this.isMobileSidebar = e.matches }
            if (typeof this._mobileSidebarMql.addEventListener === 'function') {
                this._mobileSidebarMql.addEventListener('change', this._mobileSidebarMqlHandler)
            } else if (typeof this._mobileSidebarMql.addListener === 'function') {
                this._mobileSidebarMql.addListener(this._mobileSidebarMqlHandler)
            }
        }

        await Promise.all([
            this.fetchTeams(),
            this.fetchCanCreateTeam(),
            // v4.7.3 — in parallel, not after: the sidebar needs the teams
            // and their grouping together, and the getter falls back to a
            // flat list until this lands, so a slow read degrades rather
            // than blocks.
            this.fetchTeamGroups(),
        ])

        // v3.75.1 — consume ?team=…&decision=… deep link.
        // Used by approver-meeting descriptions and any future "open this
        // proposal" entry point (Talk message, email link, etc).
        // Done after fetchTeams so the team list is available before we
        // try to select one.
        await this.consumeDeepLink()

        // Poll for new messages every 60s so the unread badge stays current
        // without requiring a page reload. Uses refreshUnreadCounts (silent —
        // no loading spinner) rather than fetchTeams to avoid UI flicker.
        this._unreadPollInterval = setInterval(() => {
            this.$store.dispatch('refreshUnreadCounts')
        }, 60000)

        // v4.2.6 — one-shot license entitlements fetch for the sidebar
        // footer's brand + help gate. Member-callable endpoint (no admin).
        // Errors are swallowed silently — the sidebar just keeps its
        // fails-open "assume unlicensed" state.
        try {
            const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/license/entitlements'))
            this.licenseEntitlements = data || null
        } catch (e) {
            this.licenseEntitlements = null
        }

        // v4.4.12 — per-user preferences for the sidebar "Getting started"
        // callout. Fetched after the entitlements call because the callout
        // can only render on an unlicensed instance anyway; a licensed
        // instance pays one extra GET on load and renders nothing, which is
        // cheaper than threading the preference through the licence
        // endpoint's response shape. Errors leave the hint hidden.
        try {
            const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/preferences'))
            this.gettingStartedHint = !!data?.gettingStartedHint
        } catch (e) {
            this.gettingStartedHint = false
        }

        // v4.4.17 — in-app announcements. Server filters by license state,
        // version, role, and per-user dismissals — a licensed instance
        // always gets []. Errors leave the envelope hidden.
        await this.refreshAnnouncements()

        // v4.5.21 — My Work badge. After the entitlements call, because the
        // endpoint is licence-gated and an unlicensed instance would just 403.
        if (this.isLicensed) {
            this.refreshMyWorkCount()

            // v4.5.26 — and again whenever the tab comes back to the
            // foreground. Work arrives from other people while this page sits
            // open; without this the badge is only ever as fresh as the last
            // reload. Deliberately not a timer: a poll would fire for every
            // open tab of every user forever, and coming back to the tab is
            // exactly when the number starts being looked at.
            this._visibilityHandler = () => {
                if (document.visibilityState === 'visible' && this.activeView !== 'mywork') {
                    this.refreshMyWorkCount()
                }
            }
            document.addEventListener('visibilitychange', this._visibilityHandler)
            window.addEventListener('focus', this._visibilityHandler)
        }
    },

    /**
     * v4.5.26 — was `beforeDestroy()`, a Vue 2 hook that Vue 3 never calls, so
     * none of this teardown had been running: the unread poll interval and the
     * media-query listener both outlived the component. Renamed rather than
     * left alone because the listeners added above would have leaked the same
     * way. (HANDOFF lists other components still carrying the dead hook.)
     */
    beforeUnmount() {
        if (this._unreadPollInterval) {
            clearInterval(this._unreadPollInterval)
            this._unreadPollInterval = null
        }
        if (this._visibilityHandler) {
            document.removeEventListener('visibilitychange', this._visibilityHandler)
            window.removeEventListener('focus', this._visibilityHandler)
            this._visibilityHandler = null
        }
        if (this._mobileSidebarMql && this._mobileSidebarMqlHandler) {
            if (typeof this._mobileSidebarMql.removeEventListener === 'function') {
                this._mobileSidebarMql.removeEventListener('change', this._mobileSidebarMqlHandler)
            } else if (typeof this._mobileSidebarMql.removeListener === 'function') {
                this._mobileSidebarMql.removeListener(this._mobileSidebarMqlHandler)
            }
            this._mobileSidebarMql = null
            this._mobileSidebarMqlHandler = null
        }
    },
    methods: {
        t,
        n,
        ...mapActions(['fetchTeams', 'fetchTeamGroups', 'selectTeam']),
        ...mapMutations(['SET_VIEW', 'SET_DECISIONS_TARGET', 'SET_PENDING_TEAM_ACTION', 'SET_MESSAGE_TARGET']),

        // ------------------------------------------------------------------
        // v4.7.3 — sidebar grouping
        //
        // Every one of these is a thin wrapper over a store action. They live
        // here rather than in the nav components because the components hold
        // no app state by design, and because a failed write has to reach the
        // user as a toast — the store deliberately rethrows on mutations so
        // that this layer, which knows about `showError`, can do it.
        // ------------------------------------------------------------------

        /**
         * The chevron and the "Expanded by default" checkbox both land here;
         * they write one stored value, so a group stays how it was left.
         */
        async onGroupToggle({ groupId, expanded }) {
            try {
                await this.$store.dispatch('setTeamGroupExpanded', { groupId, expanded })
            } catch (e) {
                showError(t('teamhub', 'Could not save the group state'))
            }
        },

        /**
         * Create a group and move the team that asked for it straight in.
         * `teamId` always arrives from a team's action menu — that is the
         * only place a group can be created from.
         */
        async onGroupCreate({ name, teamId }) {
            if (this.groupNameTaken(name)) {
                showError(t('teamhub', 'You already have a group called “{name}”', { name }))
                return
            }
            try {
                await this.$store.dispatch('createTeamGroup', { name, teamId })
                showSuccess(t('teamhub', 'Group “{name}” created', { name }))
            } catch (e) {
                showError(t('teamhub', 'Could not create the group'))
            }
        },

        async onGroupRename({ groupId, name }) {
            if (this.groupNameTaken(name, groupId)) {
                showError(t('teamhub', 'You already have a group called “{name}”', { name }))
                return
            }
            try {
                await this.$store.dispatch('renameTeamGroup', { groupId, name })
            } catch (e) {
                showError(t('teamhub', 'Could not rename the group'))
            }
        },

        /**
         * The duplicate-name check the service also performs.
         *
         * Duplicated on purpose, and not as a security boundary — the server
         * remains the one that decides. It is here so the user gets a
         * translated message naming the group, instantly. Reading the
         * server's own message into the toast instead would put a new
         * untranslated English string in front of every non-English reader,
         * which is the backlog HANDOFF tracks under Track C.
         *
         * @param {string} name candidate name
         * @param {string|null} exceptGroupId ignore this group (a rename is not a clash with itself)
         * @return {boolean}
         */
        groupNameTaken(name, exceptGroupId = null) {
            const candidate = (name || '').trim().toLowerCase()
            return this.sidebarTeamGroups.groups.some(g => (
                !g.builtin
                && g.id !== exceptGroupId
                && (g.name || '').toLowerCase() === candidate
            ))
        },

        /**
         * No confirmation step: nothing is destroyed. The teams inside are
         * untouched and reappear as loose rows, so the action is one
         * re-create away from undone — SKILLS.md asks for a confirm on
         * destructive actions, and this one is not.
         */
        async onGroupDelete(groupId) {
            try {
                await this.$store.dispatch('deleteTeamGroup', groupId)
            } catch (e) {
                showError(t('teamhub', 'Could not delete the group'))
            }
        },

        /**
         * v4.7.15 — opens the group picker for one team.
         *
         * @param {{teamId: string, teamName: string, groupId: string}} payload
         *   from TeamNavItem, which carries the name so the dialog can be
         *   titled without a second lookup
         */
        onGroupPickerOpen(payload) {
            this.groupPicker = payload
        },

        async onTeamAssignGroup({ teamId, groupId }) {
            try {
                await this.$store.dispatch('assignTeamToGroup', { teamId, groupId })
            } catch (e) {
                showError(t('teamhub', 'Could not move the team'))
            }
        },

        /**
         * v4.5.26 — open one row of "What's new" where it actually lives.
         *
         * Until now this just selected the team, which is not what "Open"
         * means when the row is a specific message. Three destinations, and
         * which one is used is decided by what the row *is*:
         *
         *  - a **decision** goes to the team's Decisions tab with the proposal
         *    selected, reusing the mechanism the decisions widget and the
         *    stream already drive (`SET_DECISIONS_TARGET`);
         *  - a **Talk poll or thread** goes to the team's Talk tab, because
         *    that is the only place its conversation exists;
         *  - anything else goes to the team's stream, on the page that holds
         *    the message, highlighted (`SET_MESSAGE_TARGET`).
         *
         * The team is selected first and awaited: the tab has to exist before
         * a target set on it means anything.
         */
        async onOpenFeedItem(item) {
            if (!item?.team_id) {
                return
            }

            // v4.9.7 — an OpenProject news row lives in OpenProject: the same
            // hand-off My Work's OpenProject rows make, with the same "you
            // are leaving TeamHub" notice and the same scheme check.
            if (item.source === 'openproject') {
                this.openMyWorkItemExternally({ resourceUrl: item.news?.url || '' })
                return
            }

            if (item.source === 'talk-poll' || item.source === 'talk-thread') {
                this.onOpenTeamTalk(item.team_id)
                return
            }

            this.activeView = 'team'
            if (this.currentTeamId !== item.team_id) {
                await this.selectTeam(item.team_id)
            }

            if (item.messageType === 'decision') {
                this.SET_VIEW('decisions')
                // nextTick for the same reason consumeDeepLink and
                // openTeamHubView need it — the target has to land after the
                // tab has rendered, or its watcher never sees the change.
                this.$nextTick(() => this.SET_DECISIONS_TARGET(Number(item.id)))
            } else {
                // 'msgstream' is the team's Home view — the one that renders
                // the widget grid, and the message stream inside it.
                this.SET_VIEW('msgstream')
                // v4.8.7 — was `SET_MESSAGE_TARGET(Number(item.id))`, a bare
                // number. Both consumers read `target?.messageId`
                // (MessageStream's focusMessage watcher and TeamWidgetGrid's
                // expand-the-widget watcher), so a number resolved to
                // undefined and neither fired: since v4.5.26 this landed the
                // reader on the team's stream without ever loading the page
                // holding the message, scrolling to it, or highlighting it.
                // The `{ messageId, nonce }` shape is the one MessageStream's
                // watcher documents; the nonce is what lets the same message
                // be opened twice in a row and still re-fire.
                this.$nextTick(() => this.SET_MESSAGE_TARGET({
                    messageId: Number(item.id),
                    nonce: Date.now(),
                }))
            }

            this.closeSidebarIfOverlay()
        },

        // ── Sidebar 3-dot actions (moved from the Team-info widget) ─────
        //
        // Manage/Invite/Leave rely on the currently-open team's state, so we
        // always select the team first (no-op when it's already active).
        // Invite + Leave publish a one-shot intent flag that TeamView consumes
        // after mount, which handles the cold-start case where the team hasn't
        // been opened this session yet.

        /** Manage team — jump to the Manage Team view for the picked team. */
        onSidebarManageTeam(teamId) {
            this.openManageTeam(teamId)
            this.closeSidebarIfOverlay()
        },

        /**
         * Switch to Manage Team, making sure its Dashboard section describes
         * THIS team.
         *
         * v4.6.15 — TeamView is the `v-else` of the same chain that renders
         * ManageTeamView, so on every path that lands here without opening the
         * team first it never mounts, and the Settings → Dashboard pickers were
         * left reading whatever the store still held: nothing on a cold load,
         * which rendered a Default-tab `<select>` with no options that could
         * never fire `@change` — no team on the test instance had ever managed
         * to store a default tab — or the previously-opened team's tabs, which
         * is worse, because saving then wrote them against this team.
         *
         * `SET_CURRENT_TEAM` is committed synchronously at the top of
         * `selectTeam`, so `currentTeam` is already correct when the view
         * switches below; what is awaited is the resource / link / integration
         * fetches the tab list is built from. `ensureTeamDashboardFacts` is a
         * no-op when TeamView already published for this team.
         *
         * @param {string}      teamId   team to manage
         * @param {object|null} deepLink optional { tab, section } to land on
         */
        async openManageTeam(teamId, deepLink = null) {
            const selecting = this.currentTeamId !== teamId ? this.selectTeam(teamId) : null
            this.activeView = 'manage'
            if (deepLink) {
                this.$store.commit('SET_MANAGE_TEAM_DEEP_LINK', deepLink)
            }
            if (selecting) {
                await selecting
            }
            await this.$store.dispatch('ensureTeamDashboardFacts', teamId)
        },

        /** Copy the deep-link that opens this team on any device. */
        onSidebarCopyLink(teamId) {
            const url = window.location.origin + generateUrl(`/apps/teamhub?team=${teamId}`)
            const done = () => showSuccess(t('teamhub', 'Team link copied to clipboard'))
            if (navigator.clipboard?.writeText) {
                navigator.clipboard.writeText(url).then(done).catch(() => this.fallbackCopy(url, done))
            } else {
                this.fallbackCopy(url, done)
            }
            this.closeSidebarIfOverlay()
        },

        /**
         * v4.6.26 — write to every member of a team who has an email address.
         *
         * **Resolved on click, not on load.** The sidebar lists every team the
         * user is in; asking each one for its member addresses up front would
         * be a membership walk per team on every page load, for an action
         * almost nobody takes on any given visit. One request, when asked.
         *
         * The consequence is that the item cannot be hidden when a team has no
         * reachable addresses — we do not know until we ask. That is the right
         * trade here: SKILLS.md § Permissions is about hiding what a *role*
         * may not do, and this is a data condition, not a permission. So the
         * action is always offered and answers honestly when there is nobody
         * to write to.
         *
         * The server decides between Nextcloud Mail and the OS handler and
         * hands back one URL — see `MailClientService`. Both are ordinary
         * navigations: `mailto:` must not go through `window.open`, which
         * leaves an orphaned blank tab behind in Firefox and Safari when the
         * OS handler takes over.
         */
        async onSidebarEmailMembers(teamId) {
            this.closeSidebarIfOverlay()
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/members/mail`),
                )

                if (!data?.url) {
                    showError(t('teamhub', 'No member of this team has an email address on their account.'))
                    return
                }

                // Tell the sender who is NOT going to receive it before the
                // composer opens. Silent when everyone is reachable —
                // confirming the expected case is noise.
                //
                // One count, so one plural selector. An earlier draft put both
                // the recipient and the missing count in a single string, which
                // reads correctly only when the two happen to take the same
                // plural form — `n()` selects on one number for the whole
                // sentence.
                const missing = (data.memberCount || 0) - (data.recipientCount || 0)
                if (missing > 0) {
                    // TRANSLATORS: shown before a mail composer opens. {n} is the number of team members who have no email address on their Nextcloud account.
                    showWarning(n('teamhub',
                        '{n} team member has no email address and will not receive this.',
                        '{n} team members have no email address and will not receive this.',
                        missing,
                        { n: missing }))
                }

                window.location.href = data.url
            } catch (e) {
                showError(t('teamhub', 'Could not work out who to write to. Please try again.'))
            }
        },

        /** Open the Invite modal for the picked team (opens Team view first). */
        onSidebarInvite(teamId) {
            if (this.currentTeamId !== teamId) {
                this.selectTeam(teamId)
            }
            this.activeView = 'team'
            this.SET_PENDING_TEAM_ACTION('invite')
            this.closeSidebarIfOverlay()
        },

        /**
         * v4.4.12 — close the Getting started callout for this browser
         * session. Does not touch the stored preference: the callout tells
         * the user that Personal settings → TeamHub is where it gets turned
         * off for good, and this button would contradict that.
         */
        dismissGettingStartedHint() {
            this.gettingStartedHintClosed = true
            writeSessionFlag(HINT_CLOSED_KEY)
        },

        /** Fire the leave flow via TeamView (which handles routing + toast). */
        onSidebarLeave(teamId) {
            if (this.currentTeamId !== teamId) {
                this.selectTeam(teamId)
            }
            this.activeView = 'team'
            this.SET_PENDING_TEAM_ACTION('leave')
            this.closeSidebarIfOverlay()
        },

        /**
         * document.execCommand('copy') fallback for the sparse browsers that
         * still block Clipboard API on non-secure origins. Matches the pattern
         * TeamView.fallbackCopy uses so both entry points behave identically.
         */
        fallbackCopy(text, onDone) {
            const ta = document.createElement('textarea')
            ta.value = text
            ta.style.cssText = 'position:fixed;left:-999999px'
            document.body.appendChild(ta)
            ta.select()
            try {
                document.execCommand('copy')
                onDone && onDone()
            } catch (e) {
                showError(t('teamhub', 'Copy failed'))
            } finally {
                document.body.removeChild(ta)
            }
        },

        /**
         * v3.75.1 — Consume the ?team=…&decision=… deep link in the URL.
         *
         * Used by approver-meeting calendar descriptions and any future
         * external link into a specific proposal (Talk message, email, etc).
         *
         *   ?team=<teamId>                       → open the team's home view
         *   ?team=<teamId>&decision=<decisionId> → open the team, switch to
         *                                          the Decisions tab, and
         *                                          pre-select the proposal
         *
         * The decision id is resolved to its messageId via a single fetch;
         * decisionsTargetMessageId then drives the existing scroll/select
         * behaviour in TeamDecisionsView.
         */
        async consumeDeepLink() {
            try {
                const params = new URLSearchParams(window.location.search || '')
                const teamId     = params.get('team')
                const decisionId = params.get('decision')
                // v4.8.7 (GitHub #95) — the comment-notification bell links
                // to ?team=…&message=…, so the reader lands on the thread
                // somebody replied in rather than on the team's home.
                const messageId  = params.get('message')

                // v4.5.21 — ?mywork opens the personal work queue. Checked
                // before the team branch because My Work is cross-team: a link
                // to it deliberately carries no team id.
                if (params.has('mywork')) {
                    this.activeView = 'mywork'
                    return
                }

                if (!teamId) return

                // Not one of the user's teams — which is the normal case for a
                // link somebody was given, not a broken one. TeamJoinView reads
                // the team's join policy and offers whatever it allows: join,
                // ask a moderator, or an explanation that the team is invite
                // only. It handles a genuinely dead id too, so this branch does
                // not need to tell the two apart.
                //
                // v4.6.17 — until this version the same condition logged a
                // warning and returned, leaving the recipient on the generic
                // welcome screen with no indication that the link had meant
                // anything. `teams` covers indirect membership via a group or
                // sub-team, so nobody who can already open the team lands here.
                const known = (this.teams || []).some(t => t.id === teamId)
                if (!known) {
                    this.joinTeamId = teamId
                    this.activeView = 'join'
                    return
                }

                await this.selectTeam(teamId)
                this.activeView = 'team'

                if (decisionId) {
                    // Resolve the decision's messageId so the existing
                    // scrollAndSelectTarget watcher in TeamDecisionsView
                    // can highlight the right card.
                    try {
                        const { data } = await axios.get(
                            generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/decisions/${decisionId}`),
                        )
                        const messageId = data?.messageId
                        if (messageId) {
                            // Switch to the Decisions tab and set the target.
                            // The order matters: setting the target before the
                            // view ensures the watcher in TeamDecisionsView
                            // sees the change after the view renders.
                            this.SET_VIEW('decisions')
                            this.$nextTick(() => {
                                this.SET_DECISIONS_TARGET(messageId)
                            })
                        }
                    } catch (e) {
                        console.warn('[TeamHub][App] consumeDeepLink: decision fetch failed', e?.message)
                    }
                } else if (messageId) {
                    // Reuses the mechanism the "What's new" feed uses rather
                    // than adding a second way to point at a message:
                    // MessageStream's watcher loads the page holding it
                    // (`aroundMessageId`), scrolls to it and highlights it,
                    // and TeamWidgetGrid expands the stream widget first if it
                    // is collapsed. Same $nextTick reason as the decision
                    // branch — the target has to land after the tab renders.
                    //
                    // `else if` because a link carrying both would have the
                    // two branches fighting over the active tab. Nothing emits
                    // both today; this makes that explicit rather than
                    // order-dependent.
                    const numericId = Number(messageId)
                    if (Number.isInteger(numericId) && numericId > 0) {
                        this.SET_VIEW('msgstream')
                        this.$nextTick(() => this.SET_MESSAGE_TARGET({
                            messageId: numericId,
                            nonce: Date.now(),
                        }))
                    }
                }
            } catch (e) {
                // Never let a bad URL crash the app.
                console.warn('[TeamHub][App] consumeDeepLink: failed', e?.message)
            }
        },

        async fetchCanCreateTeam() {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/user/can-create-team'))
                this.canCreateTeam = !!data.canCreate
                this.isNcAdmin     = !!data.isAdmin
            } catch (e) {
                // If the endpoint fails, default to showing the button
                this.canCreateTeam = true
                // …but never guess someone into an admin pointer they can't use.
                this.isNcAdmin = false
            }
        },

        showView(view) {
            this.activeView = view
            this.closeSidebarIfOverlay()
        },

        /** v4.5.21 — open the personal work queue. */
        showMyWork() {
            this.activeView = 'mywork'
            this.closeSidebarIfOverlay()
        },

        /**
         * v4.5.21 — Action Required badge. Fails silently: a badge that
         * cannot load should be absent, never an error toast on page load.
         */
        async refreshMyWorkCount() {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/mywork/counts'))
                this.actionRequiredCount = Number(data?.counts?.action_required) || 0
            } catch (e) {
                this.actionRequiredCount = 0
            }
        },

        /**
         * v4.5.21 — open a My Work item without leaving TeamHub.
         *
         * The team is selected first so the team view mounts with its tabs and
         * resources, then the existing one-shot embed pin (DESIGN.md §4.5.9)
         * puts the specific card or file into that team's own tab. My Work's
         * filters, sorting and scroll position live in the Vuex store, so the
         * sidebar entry brings the user straight back to where they were.
         *
         * v4.5.24 — the branch is on the item's `openTarget.kind`, not on its
         * resource type. The provider names one of four mechanisms and this
         * method knows how to drive each; before, opening was inferred here,
         * so every TeamHub-native source cost another `else if` (DESIGN.md
         * §2.71 → §2.72). A provider that names a mechanism TeamHub cannot
         * drive falls through to its own app, and says so first — silently
         * opening a new tab would leave the user wondering where TeamHub went.
         */
        async onOpenMyWorkItem(item) {
            if (!item?.teamId) {
                return
            }

            this.activeView = 'team'
            if (this.currentTeamId !== item.teamId) {
                // Awaited: openDeckCardInEmbed pre-selects a board, and the
                // team's resources must be loaded before that means anything.
                await this.selectTeam(item.teamId)
            }

            const target = item.openTarget || {}
            switch (target.kind) {
            case OPEN_KIND.DECK_CARD:
                this.$store.dispatch('openDeckCardInEmbed', {
                    boardId: Number(target.boardId) || null,
                    cardId: Number(target.cardId) || null,
                    boardName: target.boardName || '',
                })
                break

            case OPEN_KIND.FILE:
                this.$store.dispatch('openFileInEmbed', fileOpenUrl(Number(target.fileId)))
                break

            case OPEN_KIND.CALENDAR_EVENT:
                this.$store.dispatch('openEventInEmbed', {
                    url: target.url,
                    calendarId: Number(target.calendarId) || null,
                })
                break

            case OPEN_KIND.TEAMHUB_VIEW:
                this.openTeamHubView(item, target)
                break

            // v4.5.45 — Manage team at a tab/section, via the deep-link
            // mechanism the Project Compass has used since v3.98.0. The team
            // was already switched to above, so this only has to change screen.
            case OPEN_KIND.MANAGE_TEAM:
                // 'manage' is the shell's key for ManageTeamView — see the
                // v-else-if in the template above. The team was already
                // switched to above, so openManageTeam only has to change
                // screen and publish the dashboard facts.
                this.openManageTeam(item.teamId, {
                    tab: String(target.tab || ''),
                    section: target.section ? String(target.section) : null,
                })
                break

            default:
                this.openMyWorkItemExternally(item)
            }

            this.closeSidebarIfOverlay()
        },

        /**
         * Open a TeamHub tab for a My Work item, optionally selecting a row.
         *
         * `TEAMHUB_VIEW_TARGETS` is the registry of tabs a provider may point
         * at. An absent key means the tab does not exist — a provider bug, and
         * one that must not silently do nothing, so it degrades to the item's
         * own URL like any other unopenable row.
         */
        openTeamHubView(item, target) {
            // hasOwnProperty, not a bare lookup: `view` arrives over the wire,
            // and a value like "constructor" would otherwise resolve to an
            // inherited member and read as a registered view.
            const view = String(target.view || '')
            if (!Object.prototype.hasOwnProperty.call(TEAMHUB_VIEW_TARGETS, view)) {
                this.openMyWorkItemExternally(item)
                return
            }
            const mutation = TEAMHUB_VIEW_TARGETS[view]

            this.SET_VIEW(view)
            const targetId = Number(target.targetId) || 0
            if (mutation && targetId > 0) {
                // The nextTick matters for the same reason it does in
                // consumeDeepLink — the target has to be set after the tab has
                // rendered, or its watcher never sees the change.
                this.$nextTick(() => this.$store.commit(mutation, targetId))
            }
        },

        /**
         * Last resort: the row opens in whichever app actually owns it.
         *
         * v4.9.5 — an absolute URL is opened as it is: OpenProject rows point
         * at the administrator's OpenProject host, not at a Nextcloud path,
         * and generateUrl() would prefix it with this server's base.
         */
        openMyWorkItemExternally(item) {
            if (!item.resourceUrl) {
                return
            }
            // v4.9.7 — an absolute URL is opened only when it is http(s); a
            // relative one goes through generateUrl. Nothing else is handed to
            // the browser, whatever the row carried.
            let url
            if (/^[a-z][a-z0-9+.-]*:/i.test(item.resourceUrl)) {
                if (!/^https?:\/\//i.test(item.resourceUrl)) {
                    return
                }
                url = item.resourceUrl
            } else {
                url = generateUrl(item.resourceUrl)
            }
            showError(t('teamhub', 'This item opens in another app. You are leaving TeamHub.'))
            window.open(url, '_blank', 'noopener,noreferrer')
        },

        openDocs() {
            window.open('https://tldr.host/teamhub/docs/', '_blank', 'noopener,noreferrer')
            this.closeSidebarIfOverlay()
        },

        // v4.4.17 — announcements.
        async refreshAnnouncements() {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/announcements'))
                this.announcements = Array.isArray(data?.announcements) ? data.announcements : []
            } catch (e) {
                this.announcements = []
            }
        },

        openAnnouncement(filename) {
            this.announcementFilename = filename
            this.activeView = 'announcement'
            this.closeSidebarIfOverlay()
        },

        // v4.5.0 — envelope in the brand row opens the first unread
        // announcement's canvas view. In practice most instances will have
        // zero or one active announcement at a time; if there are multiple,
        // opening the first is a defensible default — Got-it in the canvas
        // view dismisses that one and the envelope re-fires on the next.
        openFirstAnnouncement() {
            const first = this.announcements[0]
            if (first) this.openAnnouncement(first.filename)
        },

        closeAnnouncement() {
            this.announcementFilename = null
            this.activeView = this.currentTeamId ? 'team' : null
        },

        async onAnnouncementDismissed() {
            await this.refreshAnnouncements()
            this.closeAnnouncement()
        },

        startCreateTeam() {
            this.activeView = 'create'
            this.closeSidebarIfOverlay()
        },

        selectTeamFromSidebar(teamId) {
            this.activeView = 'team'
            // Guard against re-clicking the currently open team. selectTeam's
            // store action unconditionally resets SET_PROJECT to isProject:false,
            // then relies on TeamView's `currentTeamId` watcher to re-run
            // loadLayout so the project fact + budgetConfig + timeConfig come
            // back. When teamId is unchanged the watcher doesn't fire, so we
            // would leave advanced-project state empty (phase stepper, Budget
            // + Time tabs, Manage Team → Project tab all vanish) until the
            // user reloads. If they were already on 'admin' or 'create' view
            // and clicked their team, activeView above is enough — no need to
            // touch team state.
            if (this.currentTeamId !== teamId) {
                this.selectTeam(teamId)
            }
            this.closeSidebarIfOverlay()
        },

        /**
         * v4.2.14 — feed click-through for Talk items. Opens the team's
         * home view and switches its currentView to 'talk' so the Talk
         * tab is the one rendered on arrival. Deep-linking to a specific
         * message id inside the Talk embed is out of scope; the user
         * lands in the room and can scroll or reply from there.
         */
        onOpenTeamTalk(teamId) {
            this.activeView = 'team'
            if (this.currentTeamId !== teamId) {
                this.selectTeam(teamId)
            }
            // TeamView reads currentView from the store; set it after
            // selectTeam has committed so the view switch doesn't get
            // overwritten by the team-load default-tab logic.
            this.$nextTick(() => {
                this.SET_VIEW('talk')
            })
            this.closeSidebarIfOverlay()
        },

        /**
         * Close NC's sidebar when it is in overlay mode (phone / tablet portrait).
         * v9 NcAppNavigation has no `open` prop — its open state is internal and
         * controlled via the `toggle-navigation` event bus event.
         */
        closeSidebarIfOverlay() {
            if (this.isMobileSidebar) {
                emit('toggle-navigation', { open: false })
            }
        },

        /**
         * v4.4.5 — the create wizard's success hand-off (onboarding plan § 3.3)
         * can now finish in one of three places, so it passes an intent
         * alongside the team.
         *
         *   'invite'  → team home with the invite modal queued
         *   'manage'  → Manage team, for reviewing connected apps
         *   null      → team home (the previous behaviour, still the default)
         *
         * 'invite' reuses the same one-shot pending-action flag the sidebar
         * menu uses; TeamView consumes it after mount, which handles the cold
         * start where the team has never been opened this session.
         */
        async onTeamCreated(team, intent = null) {
            await this.fetchTeams()
            await this.selectTeam(team.id)
            if (intent === 'manage') {
                await this.openManageTeam(team.id)
                return
            }
            this.activeView = 'team'
            if (intent === 'invite') {
                this.SET_PENDING_TEAM_ACTION('invite')
            }
        },

        onCreateCancel() {
            this.activeView = this.currentTeamId ? 'team' : null
        },

        onTeamJoined() {
            this.fetchTeams()
            this.activeView = null
        },

        /**
         * v4.6.17 — a link recipient who just joined (or who turned out to be a
         * member already). Unlike Browse Teams' join, which returns the user to
         * a list they were deliberately looking at, somebody who followed a link
         * to one specific team came here to be in that team — so we open it.
         *
         * fetchTeams has to complete first: selectTeam populates from the team
         * list, and the team is not in it until the join is reflected there.
         */
        async onJoinedViaLink(teamId) {
            await this.fetchTeams()
            this.joinTeamId = null
            this.selectTeamFromSidebar(teamId)
        },

        onDescriptionUpdated(newDescription) {
            if (this.currentTeam) {
                this.currentTeam.description = newDescription
            }
        },

        async onTeamDeleted() {
            this.$store.commit('SET_CURRENT_TEAM', null)
            await this.$store.dispatch('fetchTeams')
            this.activeView = 'default'
        },

        /**
         * The user just handed their team to somebody else (v4.6.20).
         *
         * Back to the team, not to the app default the way `onTeamDeleted`
         * goes: they are still a member, they are simply no longer its owner.
         * Manage team is the one place they can no longer be — the transfer
         * demotes them to moderator and every surface on that screen requires
         * admin — so staying there produced a 403 under a success toast.
         *
         * `fetchTeams` is awaited before the view changes because the sidebar's
         * per-team level badges and the tab set are both derived from it, and
         * both are now stale by exactly this transfer.
         */
        async onOwnershipTransferred() {
            await this.$store.dispatch('fetchTeams')
            this.activeView = 'team'
        },

        async onTeamLeft() {
            this.$store.commit('SET_CURRENT_TEAM', null)
            await this.$store.dispatch('fetchTeams')
            this.activeView = null
        },
    },
}
</script>

<style scoped lang="scss">
// v3.100.17: moved from inline style="height: 44px" (gui.md § 13).
// Spacer at the top of the sidebar list that clears the NC show/hide
// sidebar toggle button so its icon doesn't overlap the first nav item.
.teamhub-nav-spacer {
    height: 44px;
    flex-shrink: 0;
}

/* v4.7.3 — divides the grouped teams from the loose ones.
   Deliberately quiet: a hairline in NC's standard border colour rather
   than a heading or a gap. A caption ("Other teams") would have named a
   bucket that does not exist — the point of the design is that an
   ungrouped team is not in anything — and whitespace alone reads as a
   rendering accident at this density.
   Inset on both sides so it reads as a division inside the list rather
   than a frame drawn around it. No :deep() needed: unlike the group and
   team rows, this <li> is App.vue's own element. */
.teamhub-nav-divider {
    height: 0;
    margin: 8px 12px;
    border-top: 1px solid var(--color-border);
    list-style: none;
}

/* v4.3.18 — softened highlight for the "+ New Team" primary action.
   Uses --color-primary-element-light (the state-tint variant NC uses
   for hover/selected states across the theme) instead of the full
   --color-primary-element brand green, plus rounded corners so it
   matches the softer selected-item style Justin wanted. Text and
   icon inherit the main-text colour so both dark and light themes
   read cleanly on the tinted background.
   :deep() is required because the inner button element lives inside
   NcAppNavigationItem's own scoped template. */
.teamhub-nav-primary :deep(a),
.teamhub-nav-primary :deep(.app-navigation-entry-link) {
    background-color: var(--color-primary-element-light);
    color: var(--color-main-text);
    border-radius: var(--border-radius-large);
}
.teamhub-nav-primary :deep(a:hover),
.teamhub-nav-primary :deep(.app-navigation-entry-link:hover) {
    background-color: var(--color-primary-element-light-hover);
}
.teamhub-nav-primary :deep(.app-navigation-entry__title),
.teamhub-nav-primary :deep(.material-design-icon > svg) {
    color: var(--color-main-text);
    fill: var(--color-main-text);
}

// Visual separator above the feedback item at the bottom of the list.
.teamhub-feedback-separator {
    height: 1px;
    margin: 4px 12px;
    background-color: var(--color-border);
}

// Icon-only feedback button — sits in the nav list but shows only the icon.
// The button itself is now NcButton; only the <li> wrapper's centring stays here.
// v3.100.15: the custom .teamhub-feedback-btn CSS block (44×44 pill with
// bespoke hover / focus-visible rules) was retired because NcButton owns
// all of that behaviour natively.
.teamhub-feedback-item {
    list-style: none;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 4px 0;
}

/* v4.2.6 — Sidebar brand block (unlicensed instances only).
   v4.9.21 — the beeldmerk from the 2026-09 brand sheet (inline SVG in the
   template, literal brand colours, same in both themes) + the two-tone
   wordmark + tagline.
     --th-brand-hub      #245C80  "Team" on light, #FFFFFF on dark
     --th-brand-signal   #FB5000  "Hub", both themes
     --th-brand-tagline  muted secondary label
   The brand hexes live here and nowhere else in scoped CSS — they are the
   brand, not the theme, and must not follow NC's --color-* tokens. */
/* v4.4.12 — "Getting started" callout above the brand + help row.
   Speech-bubble styling with a tail pointing down-right at the ? button,
   but structurally just an <li> in the nav list: no portal, no absolute
   positioning against a moving target, and it reads in DOM order.
   Uses the primary-element-light tint + a solid border, the same
   selected/informational pattern SKILLS.md § multi-select tiles calls for
   rather than a full-saturation state fill. */
.teamhub-hint {
    list-style: none;
    position: relative;
    margin: 4px 12px 10px;
    padding: 10px 12px;
    border: 1px solid var(--color-primary-element);
    border-radius: var(--th-radius-card, var(--border-radius-large));
    background: var(--color-primary-element-light);
    color: var(--color-main-text);
}
/* Tail: a rotated square straddling the bottom edge, positioned toward the
   right so it points at the help button rather than the wordmark. Border
   on two sides only, so the bubble's outline appears continuous. */
.teamhub-hint::after {
    content: '';
    position: absolute;
    bottom: -5px;
    right: 18px;
    width: 8px;
    height: 8px;
    transform: rotate(45deg);
    background: var(--color-primary-element-light);
    border-right: 1px solid var(--color-primary-element);
    border-bottom: 1px solid var(--color-primary-element);
}
/* Six-lock circular icon button — SKILLS.md § "UI shapes: circles, not
   ellipses". NC's global button rule sets BOTH min-width and min-height to
   44 px and per CSS spec min-* beats an unqualified width/height, so all six
   locks are required or this renders as an oval on the deployed instance
   even though it looks correct in dev. Proven pattern, copied from
   .phase-stepper__info in ProjectPhaseStepper.vue. */
.teamhub-hint__close {
    position: absolute;
    top: 4px;
    right: 4px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;          /* 1. no flex-grow inside a flex container */
    box-sizing: border-box;  /* 2. border is inside the pinned box */
    width: 20px;
    height: 20px;
    min-width: 20px;         /* 3. beats NC's 44px min-width */
    min-height: 20px;        /* 4. beats NC's 44px min-height */
    max-width: 20px;         /* 5. content can't push it wider */
    max-height: 20px;        /* 6. content can't push it taller */
    padding: 0;
    margin: 0;
    border: none;
    border-radius: 50%;
    line-height: 1;
    background: transparent;
    color: var(--color-text-maxcontrast);
    cursor: pointer;
}
.teamhub-hint__close:hover {
    background: var(--color-background-hover);
    color: var(--color-main-text);
}
/* Split from :hover so the keyboard ring is never silenced — SKILLS.md
   § "Focus visibility standard". */
.teamhub-hint__close:focus-visible {
    background: var(--color-background-hover);
    color: var(--color-main-text);
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}

.teamhub-hint__lead {
    margin: 0;
    /* Clear the close button so a long first line never runs under it. */
    padding-right: 20px;
    font-size: var(--th-font-meta);
    font-weight: var(--th-font-weight-semibold);
    line-height: var(--th-line-height-body);
}
/* v4.4.14 — the "Click here for help [icon]" line. Icon sits inline with
   the text baseline so the visual reads left-to-right as one clause. */
.teamhub-hint__click {
    display: flex;
    align-items: center;
    gap: 4px;
    margin: 2px 0 0;
    font-size: var(--th-font-meta);
    line-height: var(--th-line-height-body);
}
.teamhub-hint__click .material-design-icon {
    color: var(--color-main-text);
}
.teamhub-hint__sub {
    margin: 4px 0 0;
    font-size: var(--th-font-micro);
    line-height: var(--th-line-height-body);
    color: var(--color-text-maxcontrast);
}

.teamhub-brand-item {
    list-style: none;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px 2px;
    --th-brand-hub: #245C80;
    --th-brand-signal: #FB5000;
    --th-brand-tagline: var(--color-text-maxcontrast);
}
[data-theme-dark] .teamhub-brand-item,
[data-theme-dark-highcontrast] .teamhub-brand-item,
body.theme--dark .teamhub-brand-item {
    --th-brand-hub: #FFFFFF;
}
@media (prefers-color-scheme: dark) {
    .teamhub-brand-item {
        --th-brand-hub: #FFFFFF;
    }
}
.teamhub-brand__mark {
    flex: 0 0 28px;
    width: 28px;
    height: 28px;
    display: block;
    line-height: 0;
}
.teamhub-brand__mark svg {
    width: 100%;
    height: 100%;
    display: block;
}
.teamhub-brand__text {
    display: flex;
    flex-direction: column;
    gap: 0;
    min-width: 0;
}
.teamhub-brand__wordmark {
    font-family: 'Inter', 'Inter var', system-ui, -apple-system, 'Segoe UI', sans-serif;
    font-weight: 700;
    font-size: 15px;
    letter-spacing: -0.02em;
    line-height: 1.1;
    color: var(--th-brand-hub);
}
.teamhub-brand__wordmark-hub {
    color: var(--th-brand-signal);
}
.teamhub-brand__tagline {
    font-size: var(--th-font-micro, 11px);
    font-weight: 500;
    line-height: 1.2;
    color: var(--th-brand-tagline);
    margin-top: 1px;
}
// v4.2.8 — help button sits on the same row, aligned to the right end.
// margin-left:auto lets the text column keep its natural width and pushes
// the help affordance against the sidebar's right edge.
.teamhub-brand__help {
    margin-left: auto;
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    gap: 2px;
}

/* v4.5.0 — Announcement callout. Mirrors .teamhub-hint's speech-bubble
   styling but stacks above it (announcement first per session choice)
   and carries no close button — dismissal is "Got it, don't show
   again" in the canvas view only, by design. Purely instructional:
   points at the envelope button in the brand row via a "Click the
   [envelope] below" second line, matching the getting-started
   callout's pointer pattern. */
.teamhub-announcement-hint {
    list-style: none;
    position: relative;
    margin: 4px 12px 6px;
    padding: 10px 12px;
    border: 1px solid var(--color-primary-element);
    border-radius: var(--th-radius-card, var(--border-radius-large));
    background: var(--color-primary-element-light);
    color: var(--color-main-text);
}

.teamhub-announcement-hint__lead {
    margin: 0;
    font-size: var(--th-font-meta);
    font-weight: var(--th-font-weight-semibold);
    line-height: var(--th-line-height-body);
}

/* "Click the [envelope] below" — same pattern as .teamhub-hint__click.
   Icon sits inline with the text baseline so the sentence reads
   left-to-right as one clause. */
.teamhub-announcement-hint__click {
    display: flex;
    align-items: center;
    gap: 4px;
    margin: 2px 0 0;
    font-size: var(--th-font-meta);
    line-height: var(--th-line-height-body);
}
.teamhub-announcement-hint__click .material-design-icon {
    color: var(--color-main-text);
}

/* ── Welcome empty state (v4.6.2) ────────────────────────────────────────
   New Team and Browse Teams sit on one row. See the template comment: NC
   only flexes `.empty-content__action` inside a modal, so outside one the
   buttons stack. This row is our own element inside the slot, so it carries
   the scope attribute and needs no :deep().
   Wraps rather than overflowing — the two labels are long in de/nl/fr and a
   narrow canvas must break the row, not scroll it sideways. */
.th-welcome-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

/* Admin-only pointer at the setup checklist. Width-capped so the sentence
   wraps at a readable measure instead of spanning a wide canvas, and centred
   under the buttons to stay in the empty state's single column. */
.th-welcome-admin-note {
    max-width: 480px;
    margin: 16px auto 0;
    text-align: start;
}
.th-welcome-admin-note__body {
    margin: 0 0 8px;
    line-height: var(--th-line-height-body);
}
</style>

<!--
    Global (non-scoped) styles for the Tribute.js @-mention autocomplete dropdown.
    NcRichContenteditable appends the .tribute-container ul to document.body so
    scoped styles never reach it. NC vue 8.x uses CSS modules (hashed class names)
    internally but the outer container also retains the plain `tribute-container`
    class. We set explicit colors here so the dropdown is readable in all themes.
-->
<style>
/* Outer container — appended to document.body by Tribute.js */
ul.tribute-container,
[class*="tribute-container"] {
    background-color: var(--color-main-background) !important;
    border: 1px solid var(--color-border) !important;
    border-radius: var(--border-radius-large) !important;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.2) !important;
    z-index: 10000 !important;
    max-height: 240px !important;
    overflow-y: auto !important;
}

ul.tribute-container li,
[class*="tribute-container"] li {
    background-color: var(--color-main-background) !important;
    color: var(--color-main-text) !important;
    cursor: pointer !important;
}

ul.tribute-container li.highlight,
ul.tribute-container li:hover,
[class*="tribute-container"] li.highlight,
[class*="tribute-container"] li:hover {
    background-color: var(--color-background-hover) !important;
    color: var(--color-main-text) !important;
}

/* NC vue renders items inside a div with id="nc-rich-contenteditable-tribute-item-*" */
[id^="nc-rich-contenteditable-tribute-item-"] {
    color: var(--color-main-text) !important;
    background-color: transparent !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    padding: 6px 12px !important;
    font-size: 13px !important;
}

[id^="nc-rich-contenteditable-tribute-item-"] * {
    color: var(--color-main-text) !important;
}
</style>
