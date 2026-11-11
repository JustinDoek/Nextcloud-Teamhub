<template>
    <div class="team-roster">

        <!-- The statistics take the whole tab rather than floating over it:
             a year of figures does not fit a dialog, and a dialog over the
             grid invites reading one against the other, which is not what
             the numbers are for. Back is where the eye already is. -->
        <RosterStatsOverlay
            v-if="statsScope"
            :team-id="teamId"
            :scope="statsScope"
            @close="statsScope = null" />

        <template v-else>
        <!-- ── Toolbar: week navigation, and the publish control ─────────── -->
        <div class="roster-toolbar" role="toolbar" :aria-label="t('teamhub', 'Roster controls')">
            <div class="roster-toolbar__nav" role="group" :aria-label="t('teamhub', 'Date navigation')">
                <NcButton
                    variant="tertiary"
                    :aria-label="t('teamhub', 'Previous week')"
                    @click="shiftWeek(-7)">
                    <template #icon><ChevronLeftIcon :size="ICON_BODY" /></template>
                </NcButton>

                <span class="roster-toolbar__period" aria-live="polite">{{ periodLabel }}</span>

                <NcButton
                    variant="tertiary"
                    :aria-label="t('teamhub', 'Next week')"
                    @click="shiftWeek(7)">
                    <template #icon><ChevronRightIcon :size="ICON_BODY" /></template>
                </NcButton>

                <NcButton v-if="!isCurrentWeek" variant="tertiary" @click="goToToday">
                    {{ t('teamhub', 'Today') }}
                </NcButton>

                <NcButton
                    variant="tertiary"
                    :aria-label="t('teamhub', 'My shift statistics')"
                    :title="t('teamhub', 'My shift statistics')"
                    @click="statsScope = 'user'">
                    <template #icon><ChartBarIcon :size="ICON_BODY" /></template>
                </NcButton>

                <!-- v4.14.8 — the viewer's published shifts this week into
                     their own calendar, in one action (DESIGN §2.250). Shown
                     only when there is something of theirs to add: a button
                     that opens onto "nothing to add" is a button that lied. -->
                <NcButton
                    v-if="myPublishedShifts.length > 0"
                    variant="tertiary"
                    :aria-label="t('teamhub', 'Add my shifts to my calendar')"
                    :title="t('teamhub', 'Add my shifts to my calendar')"
                    @click="openCalendarDialog">
                    <template #icon><CalendarPlusIcon :size="ICON_BODY" /></template>
                </NcButton>

                <NcButton
                    v-if="canManage"
                    variant="tertiary"
                    :aria-label="t('teamhub', 'Team shift statistics')"
                    :title="t('teamhub', 'Team shift statistics')"
                    @click="statsScope = 'team'">
                    <template #icon><ChartBoxIcon :size="ICON_BODY" /></template>
                </NcButton>
            </div>

            <!-- Every control in here is a team admin's. The server refuses
                 each of them for anybody else, so this v-if is about not
                 showing a member a button they cannot use, not about
                 security (CLAUDE.md: a restricted action is hidden). -->
            <div v-if="canManage" class="roster-toolbar__publish">
                <span class="roster-toolbar__state" :class="stateClass">{{ stateLabel }}</span>

                <NcButton
                    v-if="draftCount > 0"
                    variant="primary"
                    :disabled="busy"
                    @click="publish">
                    {{ t('teamhub', 'Publish week') }}
                </NcButton>

                <template v-else-if="shifts.length > 0">
                    <NcButton variant="secondary" :disabled="busy" @click="unpublish">
                        {{ t('teamhub', 'Back to concept') }}
                    </NcButton>
                    <!-- Announcing is separate from publishing: a week may be
                         published several times while it settles, and a team
                         does not want a message each time. -->
                    <NcButton variant="secondary" :disabled="busy" @click="notify">
                        {{ t('teamhub', 'Notify team') }}
                    </NcButton>
                </template>
            </div>
        </div>

        <!-- ── Lay out a week from a template (team admin only) ───────────
             v4.13.74 — the picker offers **this team's own templates**, which
             a team admin builds under Manage team, Module settings. Before
             that release it offered only the instance-wide schedules, whose
             endpoint is Nextcloud-admin only, so for an ordinary team admin
             it was permanently empty and the hint beside it pointed at a
             screen they cannot open (DESIGN §2.240). v4.14.4 removed that
             second source altogether: the week belongs to the team, and one
             picker over one kind of pattern is the simpler object
             (§2.247). -->
        <div v-if="canManage" class="roster-plan">
            <div class="roster-plan__field">
                <label for="roster-plan">{{ t('teamhub', 'Template') }}</label>
                <NcSelect
                    input-id="roster-plan"
                    label-outside
                    :aria-label-combobox="t('teamhub', 'Template')"
                    v-model="planKey"
                    :options="planOptions"
                    :reduce="o => o.key"
                    :clearable="false"
                    :placeholder="t('teamhub', 'Pick a template')"
                    label="label" />
            </div>
            <NcButton
                variant="secondary"
                :disabled="busy || !planKey"
                @click="applyPlan">
                {{ t('teamhub', 'Lay out this week') }}
            </NcButton>
            <span v-if="planOptions.length === 0" class="roster-plan__hint">
                {{ t('teamhub', 'Build one under Manage team, Module settings. A template says which roles you need on which days.') }}
            </span>
        </div>

        <!-- A member never sees a draft, so they are told why a day is empty
             rather than left to assume the team has no shifts. -->
        <NcNoteCard v-if="!canManage && shifts.length === 0 && !loading" type="info">
            {{ t('teamhub', 'No roster has been published for this week yet.') }}
        </NcNoteCard>

        <!-- v4.13.76 — an empty week offers the thing you do to an empty week.
             The picker above is the explicit control and stays; this is the
             one an admin actually meets, because an empty week is exactly when
             a template is worth having. Justin's specification was "if we
             draft a new week the team admin only needs to assign members to a
             role and shift", and a strip of controls above the grid was not
             reading as the way to draft one. -->
        <NcEmptyContent
            v-if="canManage && !loading && !error && shifts.length === 0 && planOptions.length > 0"
            class="roster-draft-cta"
            :name="t('teamhub', 'This week has no shifts yet')"
            :description="t('teamhub', 'Draft it from a template and all that is left is assigning people.')">
            <template #icon><CalendarClockIcon :size="ICON_LARGE" /></template>
            <template #action>
                <NcButton variant="primary" :disabled="busy" @click="applyPlan">
                    {{ t('teamhub', 'Lay out this week') }}
                </NcButton>
            </template>
        </NcEmptyContent>

        <div v-if="loading" class="roster-loading">
            <NcLoadingIcon :size="ICON_LARGE" />
        </div>
        <NcNoteCard v-else-if="error" type="error">
            {{ error }}
            <template #action>
                <NcButton variant="secondary" @click="load">{{ t('teamhub', 'Try again') }}</NcButton>
            </template>
        </NcNoteCard>

        <!-- ── The week: one column per day, one card per shift ──────────── -->
        <div v-else class="roster-week" :aria-label="t('teamhub', 'Roster: week view')">
            <section
                v-for="day in weekDays"
                :key="day.iso"
                class="roster-day"
                :class="{
                    'roster-day--today': day.iso === todayIso,
                    'roster-day--holiday': !!holidayOn(day),
                }">

                <header class="roster-day__header">
                    <span class="roster-day__name">{{ day.label }}</span>
                    <span class="roster-day__date">{{ day.dateLabel }}</span>
                    <!-- A public holiday is a marker, not a lock: the team may
                         well be rostered on it, and the colour is so nobody
                         staffs Christmas without noticing. -->
                    <span
                        v-if="holidayOn(day)"
                        class="roster-day__holiday"
                        :title="holidayTitle(day)">
                        {{ holidayOn(day) }}
                    </span>
                    <!-- Open places on this day, over every shift on it. The
                         number somebody scans the week for. -->
                    <span
                        v-if="openOn(day) > 0"
                        class="roster-day__open"
                        :title="openLabel(day)">
                        {{ openOn(day) }}
                    </span>
                </header>

                <p v-if="shiftsOn(day).length === 0" class="roster-day__empty">
                    {{ t('teamhub', 'Nothing rostered') }}
                </p>

                <article
                    v-for="shift in shiftsOn(day)"
                    :key="shift.id"
                    class="roster-shift"
                    :class="{
                        'roster-shift--draft': !shift.isPublished,
                        'roster-shift--open': shift.openCount > 0,
                    }">

                    <!-- Role first, in bold, where the shift type's name used
                         to be: "Receptionist 08:00-12:00". The role is what a
                         person recognises their own shift by; the type is the
                         hours, and the hours are right beside it. The type's
                         name stays in the title for anybody who needs it, and
                         the swatch keeps its colour either way. -->
                    <header class="roster-shift__head" :title="shiftTitle(shift)">
                        <span
                            class="roster-shift__swatch"
                            :style="shift.color ? { backgroundColor: shift.color } : null"></span>
                        <span class="roster-shift__name">{{ displayLabel(shift) }}</span>
                        <span class="roster-shift__time">{{ timeLabel(shift) }}</span>
                    </header>

                    <!-- Draft is said in words, not only by a dashed border. -->
                    <span v-if="!shift.isPublished" class="roster-shift__draft">
                        {{ t('teamhub', 'Draft') }}
                    </span>

                    <ul class="roster-shift__people">
                        <li v-for="person in shift.assigned" :key="person.userId" class="roster-shift__person">
                            <div class="roster-shift__person-row">
                                <NcAvatar
                                    :user="person.userId"
                                    :display-name="person.displayName"
                                    :size="AVATAR_SM"
                                    :disable-tooltip="true"
                                    :disable-menu="false" />
                                <span class="roster-shift__person-name">{{ person.displayName }}</span>

                                <!-- This person asked to be replaced. The mark
                                     sits on their own row, where the eye is
                                     already looking for who works the shift,
                                     and opens the reason and the way to step
                                     in. Collapsed by default: a roster is read
                                     far more often than it is swapped. -->
                                <NcButton
                                    v-if="isAsking(shift, person)"
                                    class="roster-shift__alert"
                                    variant="tertiary"
                                    size="small"
                                    :aria-expanded="isHandoverOpen(shift) ? 'true' : 'false'"
                                    :aria-controls="'handover-' + shift.handover.id"
                                    :aria-label="askingLabel(person)"
                                    :title="askingLabel(person)"
                                    @click="toggleHandover(shift)">
                                    <template #icon><AlertOutlineIcon :size="ICON_INLINE" /></template>
                                </NcButton>

                                <NcButton
                                    v-if="canRemove(person)"
                                    variant="tertiary"
                                    size="small"
                                    :aria-label="removeLabel(person, shift)"
                                    :title="removeLabel(person, shift)"
                                    @click="unassign(shift, person)">
                                    <template #icon><CloseIcon :size="ICON_INLINE" /></template>
                                </NcButton>
                            </div>

                            <!-- Opened by the mark above: the way to take it
                                 over, and why they asked. The icon is the
                                 action; the reason is beside it because a
                                 colleague decides whether to step in on the
                                 why, not on the fact. -->
                            <div
                                v-if="isAsking(shift, person) && isHandoverOpen(shift)"
                                :id="'handover-' + shift.handover.id"
                                class="roster-shift__handover">
                                <NcButton
                                    v-if="canTakeOver(shift)"
                                    variant="secondary"
                                    size="small"
                                    :disabled="busy"
                                    :aria-label="takeOverLabel(shift, person)"
                                    :title="takeOverLabel(shift, person)"
                                    @click="claimHandover(shift)">
                                    <template #icon><AccountSwitchIcon :size="ICON_INLINE" /></template>
                                </NcButton>
                                <span class="roster-shift__handover-reason">
                                    {{ shift.handover.reason || t('teamhub', 'No reason given') }}
                                </span>
                            </div>
                        </li>
                    </ul>

                    <p v-if="shift.openCount > 0" class="roster-shift__gap">
                        {{ gapLabel(shift) }}
                    </p>


                    <div class="roster-shift__actions">
                        <NcButton
                            v-if="canClaim(shift)"
                            variant="secondary"
                            :disabled="busy"
                            @click="claim(shift)">
                            {{ t('teamhub', 'Take this shift') }}
                        </NcButton>

                        <NcButton
                            v-if="canManage && shift.openCount > 0"
                            variant="secondary"
                            :disabled="busy"
                            @click="openAssign(shift)">
                            {{ t('teamhub', 'Assign someone') }}
                        </NcButton>

                        <NcButton
                            v-if="canAskHandover(shift)"
                            variant="tertiary"
                            :disabled="busy"
                            @click="openHandover(shift)">
                            {{ t('teamhub', 'Ask to be replaced') }}
                        </NcButton>

                        <NcButton
                            v-if="canWithdraw(shift)"
                            variant="tertiary"
                            :disabled="busy"
                            @click="withdrawHandover(shift)">
                            {{ t('teamhub', 'Withdraw the request') }}
                        </NcButton>

                        <NcButton
                            v-if="canManage"
                            variant="tertiary"
                            :aria-label="t('teamhub', 'Remove this shift')"
                            :title="t('teamhub', 'Remove this shift')"
                            :disabled="busy"
                            @click="confirmDelete(shift)">
                            <template #icon><DeleteIcon :size="ICON_INLINE" /></template>
                        </NcButton>
                    </div>
                </article>

                <NcButton
                    v-if="canManage"
                    class="roster-day__add"
                    variant="tertiary"
                    :disabled="busy || types.length === 0"
                    @click="openAdd(day)">
                    <template #icon><PlusIcon :size="ICON_INLINE" /></template>
                    {{ t('teamhub', 'Add shift') }}
                </NcButton>
            </section>
        </div>

        <!-- ── Add a shift ───────────────────────────────────────────────── -->
        <NcDialog
            v-if="addDialog.open"
            :name="addDialogName"
            :open="addDialog.open"
            @update:open="addDialog.open = $event">
            <div class="roster-dialog">
                <div class="roster-dialog__field">
                    <label for="roster-add-type">{{ t('teamhub', 'Shift') }}</label>
                    <NcSelect
                        input-id="roster-add-type"
                        label-outside
                        :aria-label-combobox="t('teamhub', 'Shift')"
                        v-model="addDialog.typeId"
                        :options="typeOptions"
                        :reduce="o => o.id"
                        :clearable="false"
                        label="label" />
                </div>
                <div class="roster-dialog__field">
                    <label for="roster-add-count">{{ t('teamhub', 'People needed') }}</label>
                    <NcTextField
                        id="roster-add-count"
                        v-model.number="addDialog.requiredCount"
                        type="number"
                        :label="t('teamhub', 'People needed')"
                        label-outside
                        :min="1"
                        :max="99" />
                </div>
                <div class="roster-dialog__field">
                    <label for="roster-add-role">{{ t('teamhub', 'Role') }}</label>
                    <NcTextField
                        id="roster-add-role"
                        v-model="addDialog.role"
                        :label="t('teamhub', 'Role')"
                        label-outside
                        :maxlength="255" />
                    <span class="roster-dialog__hint">
                        {{ t('teamhub', 'What this shift is for, if it matters: Receptionist, First aid, Driver. Shown instead of the shift name.') }}
                    </span>
                </div>
            </div>
            <template #actions>
                <NcButton variant="tertiary" @click="addDialog.open = false">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton variant="primary" :disabled="busy || !addDialog.typeId" @click="createShift">
                    {{ t('teamhub', 'Add shift') }}
                </NcButton>
            </template>
        </NcDialog>

        </template>

        <!-- ── Ask to be replaced ────────────────────────────────────────── -->
        <NcDialog
            v-if="handoverDialog.open"
            :name="t('teamhub', 'Ask to be replaced')"
            :open="handoverDialog.open"
            @update:open="handoverDialog.open = $event">
            <div class="roster-dialog">
                <p class="roster-dialog__hint">
                    {{ t('teamhub', 'This posts a message in the team conversation with a link a colleague can use to take the shift.') }}
                </p>
                <div class="roster-dialog__field">
                    <label for="roster-handover-reason">{{ t('teamhub', 'Why, if you want to say') }}</label>
                    <NcTextField
                        id="roster-handover-reason"
                        v-model="handoverDialog.reason"
                        :label="t('teamhub', 'Why, if you want to say')"
                        label-outside
                        :maxlength="255" />
                </div>
            </div>
            <template #actions>
                <NcButton variant="tertiary" @click="handoverDialog.open = false">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton variant="primary" :disabled="busy" @click="requestHandover">
                    {{ t('teamhub', 'Ask the team') }}
                </NcButton>
            </template>
        </NcDialog>

        <!-- ── Assign somebody ───────────────────────────────────────────── -->
        <NcDialog
            v-if="assignDialog.open"
            :name="t('teamhub', 'Assign someone')"
            :open="assignDialog.open"
            @update:open="assignDialog.open = $event">
            <div class="roster-dialog">
                <NcNoteCard v-if="assignDialog.error" type="error">{{ assignDialog.error }}</NcNoteCard>

                <div v-if="assignDialog.loading" class="roster-dialog__loading">
                    <NcLoadingIcon :size="ICON_BODY" />
                </div>

                <!-- Who can work it, and what stands in each person's way.
                     Available first, then those who said this half-day does
                     not suit, then those already on another shift at these
                     hours or with time off (both refuse). Somebody who said no
                     can still be picked: it is advice, and a rota that refuses
                     the conversation is a rota people keep on paper. -->
                <!-- v4.14.11 — a table, not sentences (Justin, 2026-11-10:
                     "explaining texts short like: not available"). The
                     column the fair order runs on (DESIGN §2.251) carries the
                     sort arrow instead of a line of explanation, the figures
                     are bare numbers under a heading, and what stands in
                     somebody's way is a two-word chip. The server's full
                     sentence stays on the chip's title for whoever hovers. -->
                <div
                    v-if="!assignDialog.loading && staffing.length > 0"
                    class="roster-staffing"
                    :class="{ 'roster-staffing--weekend': staffingSort === 'weekend' }"
                    role="radiogroup"
                    :aria-label="t('teamhub', 'Assign someone')">
                    <div class="roster-staffing__head" aria-hidden="true">
                        <span>{{ t('teamhub', 'Name') }}</span>
                        <span v-if="staffingSort === 'weekend'" class="roster-staffing__num roster-staffing__sorted">
                            {{ t('teamhub', 'Weekends') }}
                            <MenuDownIcon :size="ICON_INLINE" />
                        </span>
                        <span class="roster-staffing__num" :class="{ 'roster-staffing__sorted': staffingSort !== 'weekend' }">
                            {{ t('teamhub', 'This week') }}
                            <MenuDownIcon v-if="staffingSort !== 'weekend'" :size="ICON_INLINE" />
                        </span>
                        <span></span>
                    </div>

                    <div
                        v-for="option in staffing"
                        :key="option.userId"
                        class="roster-staffing__row"
                        :class="{ 'roster-staffing__row--blocked': isBlocked(option) }">
                        <NcCheckboxRadioSwitch
                            type="radio"
                            name="roster-assign-user"
                            :model-value="assignDialog.userId"
                            :value="option.userId"
                            :disabled="isBlocked(option)"
                            @update:model-value="assignDialog.userId = $event">
                            {{ option.displayName }}
                        </NcCheckboxRadioSwitch>
                        <span v-if="staffingSort === 'weekend'" class="roster-staffing__num">
                            {{ option.weekendShifts || 0 }}
                        </span>
                        <span class="roster-staffing__num">{{ hoursShort(option.weekMinutes) }}</span>
                        <span class="roster-staffing__status">
                            <NcChip
                                v-if="statusChip(option)"
                                no-close
                                :variant="statusChip(option).variant"
                                :text="statusChip(option).text"
                                :title="option.reason || statusChip(option).text" />
                        </span>
                    </div>
                </div>
            </div>
            <template #actions>
                <NcButton variant="tertiary" @click="assignDialog.open = false">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton variant="primary" :disabled="busy || !assignDialog.userId" @click="assign">
                    {{ t('teamhub', 'Assign someone') }}
                </NcButton>
            </template>
        </NcDialog>

        <!-- ── My shifts into my calendar (v4.14.8) ──────────────────────
             One action for the whole week: which calendar, busy or free,
             and the list of what will be added so nothing lands unseen.
             Running it again rewrites the same events, so the shifts that
             are already in a calendar say so rather than being left out. -->
        <NcDialog
            v-if="calendarDialog.open"
            :name="t('teamhub', 'Add my shifts to my calendar')"
            :open="calendarDialog.open"
            @update:open="calendarDialog.open = $event">
            <div class="roster-dialog">
                <div v-if="calendarDialog.loading" class="roster-dialog__loading">
                    <NcLoadingIcon :size="ICON_BODY" />
                </div>

                <template v-else>
                    <NcNoteCard v-if="calendarDialog.error" type="error">{{ calendarDialog.error }}</NcNoteCard>

                    <ul class="roster-calendar__shifts" :aria-label="t('teamhub', 'Shifts to add')">
                        <li v-for="item in calendarDialog.shifts" :key="item.id" class="roster-calendar__shift">
                            <span class="roster-calendar__day">{{ calendarDayLabel(item) }}</span>
                            <span class="roster-calendar__name">{{ item.label }}</span>
                            <span class="roster-calendar__time">{{ timeLabel(item) }}</span>
                            <span v-if="item.inCalendar" class="roster-calendar__already">
                                {{ t('teamhub', 'Already in a calendar, will be updated') }}
                            </span>
                        </li>
                    </ul>

                    <NcNoteCard v-if="calendarDialog.calendars.length === 0" type="warning">
                        {{ t('teamhub', 'You have no calendar of your own to add shifts to. Create one in Calendar first.') }}
                    </NcNoteCard>

                    <template v-else>
                        <div class="roster-dialog__field">
                            <label for="roster-calendar-target">{{ t('teamhub', 'Calendar') }}</label>
                            <NcSelect
                                input-id="roster-calendar-target"
                                label-outside
                                :aria-label-combobox="t('teamhub', 'Calendar')"
                                v-model="calendarDialog.calendarUri"
                                :options="calendarOptions"
                                :reduce="o => o.id"
                                :clearable="false"
                                label="label" />
                        </div>

                        <fieldset class="roster-calendar__transp">
                            <legend>{{ t('teamhub', 'Show these hours as') }}</legend>
                            <NcCheckboxRadioSwitch
                                type="radio"
                                name="roster-calendar-transp"
                                value="OPAQUE"
                                :model-value="calendarDialog.transp"
                                @update:model-value="calendarDialog.transp = $event">
                                {{ t('teamhub', 'Busy') }}
                            </NcCheckboxRadioSwitch>
                            <span class="roster-dialog__hint">
                                {{ t('teamhub', 'Somebody planning a meeting sees you are not available.') }}
                            </span>
                            <NcCheckboxRadioSwitch
                                type="radio"
                                name="roster-calendar-transp"
                                value="TRANSPARENT"
                                :model-value="calendarDialog.transp"
                                @update:model-value="calendarDialog.transp = $event">
                                {{ t('teamhub', 'Free') }}
                            </NcCheckboxRadioSwitch>
                            <span class="roster-dialog__hint">
                                {{ t('teamhub', 'In your calendar for reference. It does not block meetings.') }}
                            </span>
                        </fieldset>

                        <p class="roster-dialog__hint">
                            {{ t('teamhub', 'If you come off one of these shifts later, it is removed from your calendar.') }}
                        </p>
                    </template>
                </template>
            </div>
            <template #actions>
                <NcButton variant="tertiary" @click="calendarDialog.open = false">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton
                    variant="primary"
                    :disabled="calendarDialog.saving || calendarDialog.loading || !calendarDialog.calendarUri || calendarDialog.shifts.length === 0"
                    @click="exportToCalendar">
                    {{ calendarActionLabel }}
                </NcButton>
            </template>
        </NcDialog>
    </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t, translatePlural as n, getCanonicalLocale } from '@nextcloud/l10n'
import { todayIso, toIsoDate, shiftIsoDate, formatIsoDate } from '../lib/localDate.js'
import {
    NcButton, NcLoadingIcon, NcAvatar, NcSelect, NcDialog, NcTextField, NcNoteCard,
    NcCheckboxRadioSwitch, NcEmptyContent, NcChip,
} from '@nextcloud/vue'
import MenuDownIcon from 'vue-material-design-icons/MenuDown.vue'
import ChevronLeftIcon from 'vue-material-design-icons/ChevronLeft.vue'
import ChartBarIcon from 'vue-material-design-icons/ChartBar.vue'
import ChartBoxIcon from 'vue-material-design-icons/ChartBoxOutline.vue'
import RosterStatsOverlay from './RosterStatsOverlay.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import AlertOutlineIcon from 'vue-material-design-icons/AlertOutline.vue'
import AccountSwitchIcon from 'vue-material-design-icons/AccountSwitchOutline.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import CalendarClockIcon from 'vue-material-design-icons/CalendarClock.vue'
import CalendarPlusIcon from 'vue-material-design-icons/CalendarPlusOutline.vue'
import { ICON_BODY, ICON_INLINE, ICON_LARGE, AVATAR_SM } from '../constants/uiTokens.js'

/**
 * The team roster: who works when (v4.13.67, DESIGN §2.233).
 *
 * ## Why this is a column per day and not a grid of people
 *
 * The presence tab beside it is members × days, because the question there is
 * "where is this person". A roster's question is the other way round: "is
 * Thursday covered". So the day is the unit, each day stacks the shifts on it,
 * and an uncovered place is a number on the day header you can see from across
 * the week. A members × days grid cannot show an *open* shift at all, because
 * an open shift belongs to nobody and has no row to sit in.
 *
 * ## Draft and published
 *
 * A team admin sees both and the server marks which is which. A member is
 * served published shifts only — a draft is explicitly not yet a
 * communication, and showing one would be worse than showing nothing, since
 * nobody can tell a plan from a decision by looking at it.
 */
/**
 * What a shift is called: the Role when one was given, else the shift type.
 *
 * A plain function rather than a method, because two labels resolve it and
 * one of them builds the accessible name of a button — they must agree, and
 * the Talk message uses the same rule server-side.
 *
 * @param {object} shift a serialised shift
 * @return {string}
 */
function displayLabelOf(shift) {
    const role = (shift.role || '').trim()
    return role !== '' ? role : shift.typeLabel
}

export default {
    name: 'TeamRosterView',

    components: {
        NcButton, NcLoadingIcon, NcAvatar, NcSelect, NcDialog, NcTextField, NcNoteCard,
        NcCheckboxRadioSwitch, NcEmptyContent, NcChip, MenuDownIcon,
        ChevronLeftIcon, ChevronRightIcon, CloseIcon, DeleteIcon, PlusIcon,
        ChartBarIcon, ChartBoxIcon, RosterStatsOverlay,
        AlertOutlineIcon, AccountSwitchIcon, CalendarClockIcon, CalendarPlusIcon,
    },

    props: {
        teamId: { type: String, required: true },
        currentUserId: { type: String, default: '' },
    },

    data() {
        return {
            ICON_BODY,
            ICON_INLINE,
            ICON_LARGE,
            AVATAR_SM,
            anchorDate: todayIso(),
            loading: false,
            busy: false,
            error: null,
            shifts: [],
            types: [],
            members: [],
            canManage: false,
            draftCount: 0,
            /** This team's own week templates (v4.13.74). */
            templates: [],
            /** The picked template's id, or null when there is none. */
            planKey: null,
            // null, 'user' or 'team' — which statistics page is open.
            statsScope: null,
            // The hand-over request whose detail is open, by id. One at a
            // time: two open blocks in a narrow day column push the rest
            // of the week off the screen.
            openHandoverId: null,
            // ISO date to holiday name, for the day headers.
            holidays: {},
            addDialog: { open: false, date: '', typeId: null, requiredCount: 1, role: '' },
            assignDialog: { open: false, shiftId: 0, userId: null, error: null, loading: false },
            staffing: [],
            // 'week' or 'weekend': what the staffing list is ordered by.
            staffingSort: 'week',
            handoverDialog: { open: false, shiftId: 0, reason: '' },
            calendarDialog: {
                open: false,
                loading: false,
                saving: false,
                error: null,
                shifts: [],
                calendars: [],
                calendarUri: null,
                transp: 'OPAQUE',
            },
        }
    },

    computed: {
        todayIso() {
            return todayIso()
        },

        /** Mon–Sun of the anchored week. */
        weekDays() {
            const anchor = new Date(this.anchorDate + 'T12:00:00')
            const dow = anchor.getDay() === 0 ? 6 : anchor.getDay() - 1
            const monday = new Date(anchor)
            monday.setDate(anchor.getDate() - dow)
            const names = [
                t('teamhub', 'Mon'), t('teamhub', 'Tue'), t('teamhub', 'Wed'),
                t('teamhub', 'Thu'), t('teamhub', 'Fri'), t('teamhub', 'Sat'), t('teamhub', 'Sun'),
            ]
            return Array.from({ length: 7 }, (_, i) => {
                const d = new Date(monday)
                d.setDate(monday.getDate() + i)
                const iso = toIsoDate(d)
                return {
                    iso,
                    label: names[i],
                    dateLabel: formatIsoDate(iso, { month: 'short', day: 'numeric' }),
                }
            })
        },

        apiFrom() { return this.weekDays[0].iso },
        apiTo() { return this.weekDays[6].iso },

        periodLabel() {
            return t('teamhub', '{from} to {to}', {
                from: this.weekDays[0].dateLabel,
                to: this.weekDays[6].dateLabel,
            })
        },

        isCurrentWeek() {
            return this.weekDays.some(d => d.iso === this.todayIso)
        },

        draftLabel() {
            return n('teamhub',
                '{count} shift is still a draft',
                '{count} shifts are still a draft',
                this.draftCount,
                { count: this.draftCount, n: this.draftCount },
            )
        },

        /** Concept while anything in the week is still a draft. */
        isPublished() {
            return this.shifts.length > 0 && this.draftCount === 0
        },

        stateLabel() {
            if (this.shifts.length === 0) return t('teamhub', 'Not planned')
            return this.isPublished ? t('teamhub', 'Published') : this.draftLabel
        },

        stateClass() {
            if (this.shifts.length === 0) return 'roster-toolbar__state--none'
            return this.isPublished ? 'roster-toolbar__state--live' : 'roster-toolbar__state--draft'
        },

        /**
         * What this week can be laid out from: the team's own templates.
         *
         * v4.14.4 — one source, where there were two. The second was the
         * instance-wide schedule a Nextcloud administrator composed, and it is
         * gone (§2.247), so the key is the template's own id rather than a
         * `tpl:`/`sched:` pair naming which endpoint to post to.
         */
        planOptions() {
            return this.templates
                .filter(tpl => tpl.isActive)
                .map(tpl => ({
                    key: tpl.id,
                    // Built from data, not from a translated fragment, so it
                    // needs no key of its own.
                    label: `${tpl.name} (${tpl.weeklyTotal})`,
                }))
        },

        addDialogName() {
            return t('teamhub', 'Add a shift on {day}', { day: this.addDialog.date })
        },

        typeOptions() {
            return this.types.map(type => ({
                id: type.id,
                label: `${type.label} (${type.startTime}-${type.endTime})`,
            }))
        },

        memberOptions() {
            return this.members.map(m => ({ id: m.userId, label: m.displayName || m.userId }))
        },

        /** The viewer's own published shifts this week: what the calendar button is for. */
        myPublishedShifts() {
            if (!this.currentUserId) return []
            return this.shifts.filter(s => s.isPublished && s.assigned.some(p => p.userId === this.currentUserId))
        },

        calendarOptions() {
            return this.calendarDialog.calendars.map(c => ({ id: c.uri, label: c.name || c.uri }))
        },

        calendarActionLabel() {
            const count = this.calendarDialog.shifts.length
            return n('teamhub',
                'Add {count} shift',
                'Add {count} shifts',
                count,
                { count, n: count },
            )
        },
    },

    watch: {
        teamId: { immediate: true, handler() { this.load() } },
        apiFrom() { this.load() },
    },

    methods: {
        t, n,

        async load() {
            if (!this.teamId) return
            this.loading = true
            this.error = null
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/roster`),
                    { params: { from: this.apiFrom, to: this.apiTo } },
                )
                this.shifts = data.shifts || []
                this.types = data.types || []
                this.members = data.members || []
                this.canManage = !!data.can_manage
                this.draftCount = data.draft_count || 0
                this.holidays = data.holidays || {}
                // Where a week can be laid out from. A team admin's own
                // endpoint, so it should always answer; forgiving of a refusal
                // all the same, because the grid beside it is still worth
                // drawing when the picker has nothing to offer.
                if (this.canManage) {
                    const templates = await axios
                        .get(generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/roster/templates`))
                        .catch(() => ({ data: {} }))
                    this.templates = templates.data.templates || []

                    // v4.13.76 — **arm the picker.**
                    //
                    // `planKey` started null, so the picker read "Pick a
                    // template" and *Lay out this week* was disabled until the
                    // administrator opened the dropdown and chose. A greyed
                    // secondary button beside a list that visibly contains
                    // your template reads as a broken button, which is what
                    // "templates never apply" was: no request ever left the
                    // browser, so there was nothing to find server-side
                    // either (DESIGN §2.242).
                    //
                    // Re-pointed rather than only defaulted: a template that
                    // was deleted or switched off must not leave the control
                    // armed with an id the server will refuse.
                    const keys = this.planOptions.map(option => option.key)
                    if (keys.length > 0 && !keys.includes(this.planKey)) {
                        this.planKey = keys[0]
                    } else if (keys.length === 0) {
                        this.planKey = null
                    }
                }
            } catch (err) {
                this.error = err?.response?.data?.error || t('teamhub', 'Could not load the roster.')
            } finally {
                this.loading = false
            }
        },

        /** The holiday falling on this day, or '' when it is an ordinary one. */
        holidayOn(day) {
            return this.holidays[day.iso] || ''
        },

        holidayTitle(day) {
            return t('teamhub', '{name}. Normally not a working day, but the team can still be rostered.', {
                name: this.holidayOn(day),
            })
        },

        shiftsOn(day) {
            return this.shifts.filter(s => s.date === day.iso)
        },

        /** Open places across every shift on a day. */
        openOn(day) {
            return this.shiftsOn(day).reduce((sum, s) => sum + s.openCount, 0)
        },

        openLabel(day) {
            const count = this.openOn(day)
            return n('teamhub',
                '{count} place still open on {day}',
                '{count} places still open on {day}',
                count,
                { count, day: day.label, n: count },
            )
        },

        gapLabel(shift) {
            return n('teamhub',
                '{count} more person needed',
                '{count} more people needed',
                shift.openCount,
                { count: shift.openCount, n: shift.openCount },
            )
        },

        /**
         * What the card calls this shift.
         *
         * The Role when one was given, because that is what identifies the
         * shift to the person working it; the shift type's name otherwise.
         * Same resolution the Talk message uses, so the two agree.
         */
        displayLabel(shift) {
            return displayLabelOf(shift)
        },

        /** The type's own name, for anybody who needs it behind the role. */
        shiftTitle(shift) {
            const role = (shift.role || '').trim()
            return role !== ''
                ? t('teamhub', '{role}, on the {shift} shift', { role, shift: shift.typeLabel })
                : shift.typeLabel
        },

        timeLabel(shift) {
            if (!shift.startTime || !shift.endTime) return ''
            return shift.endsNextDay
                ? t('teamhub', '{start} to {end} the next day', { start: shift.startTime, end: shift.endTime })
                : `${shift.startTime}-${shift.endTime}`
        },

        removeLabel(person, shift) {
            return t('teamhub', 'Take {name} off {shift}', {
                name: person.displayName,
                shift: shift.typeLabel,
            })
        },

        /**
         * A member may drop only a shift they took themselves; an assigned one
         * is an instruction, and the server refuses it too.
         */
        canRemove(person) {
            if (this.canManage) return true
            return person.userId === this.currentUserId && person.origin === 'claimed'
        },

        canClaim(shift) {
            if (!shift.isPublished || shift.openCount === 0) return false
            return !shift.assigned.some(p => p.userId === this.currentUserId)
        },

        /** On the shift, published, and not already asking. */
        canAskHandover(shift) {
            if (!shift.isPublished || shift.handover) return false
            return shift.assigned.some(p => p.userId === this.currentUserId)
        },

        canWithdraw(shift) {
            return !!shift.handover && shift.handover.requesterUid === this.currentUserId
        },

        /**
         * Somebody else asked, and this viewer is not already on the shift.
         *
         * A hand-over replaces one person with another, so being on it already
         * would mean working it twice.
         */
        canTakeOver(shift) {
            if (!shift.handover || shift.handover.requesterUid === this.currentUserId) return false
            return !shift.assigned.some(p => p.userId === this.currentUserId)
        },

        /** Is this the person who asked to be replaced on this shift? */
        isAsking(shift, person) {
            return !!shift.handover && shift.handover.requesterUid === person.userId
        },

        isHandoverOpen(shift) {
            return !!shift.handover && this.openHandoverId === shift.handover.id
        },

        toggleHandover(shift) {
            if (!shift.handover) return
            this.openHandoverId = this.isHandoverOpen(shift) ? null : shift.handover.id
        },

        askingLabel(person) {
            return t('teamhub', '{name} is asking to be replaced. Show why.', {
                name: person.displayName,
            })
        },

        takeOverLabel(shift, person) {
            return t('teamhub', 'Take the {shift} shift over from {name}', {
                shift: displayLabelOf(shift),
                name: person.displayName,
            })
        },

        openHandover(shift) {
            this.handoverDialog = { open: true, shiftId: shift.id, reason: '' }
        },

        async requestHandover() {
            const ok = await this.send(
                () => axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/roster/shifts/${this.handoverDialog.shiftId}/handover`),
                    { reason: this.handoverDialog.reason },
                ),
                t('teamhub', 'Could not ask about the shift.'),
            )
            if (ok !== false) this.handoverDialog.open = false
        },

        async claimHandover(shift) {
            await this.send(
                () => axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/roster/handovers/${shift.handover.id}/claim`),
                ),
                t('teamhub', 'Could not take over the shift.'),
            )
        },

        async withdrawHandover(shift) {
            await this.send(
                () => axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/roster/handovers/${shift.handover.id}/withdraw`),
                ),
                t('teamhub', 'Could not withdraw the request.'),
            )
        },

        // ── Navigation ────────────────────────────────────────────────

        shiftWeek(days) {
            this.anchorDate = shiftIsoDate(this.anchorDate, { days })
        },

        goToToday() {
            this.anchorDate = todayIso()
        },

        // ── Writes ────────────────────────────────────────────────────

        openAdd(day) {
            this.addDialog = {
                open: true,
                date: day.iso,
                typeId: this.types[0]?.id ?? null,
                requiredCount: 1,
                role: '',
            }
        },

        async createShift() {
            await this.send(
                () => axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/roster/shifts`),
                    {
                        date: this.addDialog.date,
                        shiftTypeId: this.addDialog.typeId,
                        requiredCount: this.addDialog.requiredCount,
                        role: this.addDialog.role,
                    },
                ),
                t('teamhub', 'Could not add the shift.'),
            )
            this.addDialog.open = false
        },

        async openAssign(shift) {
            this.assignDialog = { open: true, shiftId: shift.id, userId: null, error: null, loading: true }
            this.staffing = []
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/roster/shifts/${shift.id}/staffing`),
                )
                this.staffing = data.options || []
                this.staffingSort = data.sortedBy === 'weekend' ? 'weekend' : 'week'
            } catch (err) {
                this.assignDialog.error = err?.response?.data?.error
                    || t('teamhub', 'Could not load who is available.')
            } finally {
                this.assignDialog.loading = false
            }
        },

        async assign() {
            this.busy = true
            this.assignDialog.error = null
            try {
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/roster/shifts/${this.assignDialog.shiftId}/assign`),
                    { userId: this.assignDialog.userId },
                )
                this.assignDialog.open = false
                await this.load()
            } catch (err) {
                // Reported inside the dialog: the refusal is about the person
                // who was just picked, and the dialog is where they picked it.
                this.assignDialog.error = err?.response?.data?.error
                    || t('teamhub', 'Could not assign the shift.')
            } finally {
                this.busy = false
            }
        },

        async claim(shift) {
            await this.send(
                () => axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/roster/shifts/${shift.id}/claim`),
                ),
                t('teamhub', 'Could not take the shift.'),
            )
        },

        async unassign(shift, person) {
            await this.send(
                () => axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/roster/shifts/${shift.id}/unassign`),
                    { userId: person.userId },
                ),
                t('teamhub', 'Could not change the shift.'),
            )
        },

        async confirmDelete(shift) {
            // eslint-disable-next-line no-alert
            if (!window.confirm(t('teamhub', 'Remove this shift and everyone on it?'))) {
                return
            }
            await this.send(
                () => axios.delete(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/roster/shifts/${shift.id}`),
                ),
                t('teamhub', 'Could not remove the shift.'),
            )
        },

        /**
         * Lay this week out from the picked template.
         *
         * v4.14.4 — one endpoint, where there were two. The instance-wide
         * schedule and its `/roster/apply` are gone (§2.247), so there is no
         * longer a source to read out of the key.
         */
        async applyPlan() {
            // Tolerant of both shapes: `reduce` is a vue-select pass-through,
            // so the model is the reduced key, and an option object is what
            // arrives if a future version stops honouring it.
            const picked = this.planKey
            const id = Number((picked && typeof picked === 'object') ? picked.key : picked)
            if (!id) {
                // Says so rather than returning. A control that does nothing
                // and reports nothing is indistinguishable from a broken one,
                // which is how this whole path came to be reported.
                this.error = t('teamhub', 'Pick a template first.')
                return
            }

            await this.send(
                () => axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/roster/apply-template`),
                    { weekStart: this.apiFrom, templateId: id },
                ),
                t('teamhub', 'Could not lay out the week.'),
            )
        },

        async publish() {
            await this.send(
                () => axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/roster/publish`),
                    { from: this.apiFrom, to: this.apiTo },
                ),
                t('teamhub', 'Could not publish the roster.'),
            )
        },

        async unpublish() {
            // eslint-disable-next-line no-alert
            if (!window.confirm(t('teamhub', 'Take this week back to concept? The team stops seeing it until you publish again.'))) {
                return
            }
            await this.send(
                () => axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/roster/unpublish`),
                    { from: this.apiFrom, to: this.apiTo },
                ),
                t('teamhub', 'Could not take the roster back to concept.'),
            )
        },

        async notify() {
            const ok = await this.send(
                () => axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/roster/notify`),
                    { from: this.apiFrom, to: this.apiTo },
                ),
                t('teamhub', 'Could not announce the roster.'),
            )
            if (ok) showSuccess(t('teamhub', 'The team has been told.'))
        },

        /**
         * Open the calendar dialog and ask the server what it would add.
         *
         * The server's answer is the list, not the grid's: it is the one that
         * decides what is the viewer's own and published, and it also knows
         * which shifts are in a calendar already.
         */
        async openCalendarDialog() {
            this.calendarDialog = {
                ...this.calendarDialog,
                open: true,
                loading: true,
                saving: false,
                error: null,
                shifts: [],
                calendars: [],
            }
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/roster/calendar`),
                    { params: { week: this.apiFrom } },
                )
                this.calendarDialog.shifts = data.shifts || []
                this.calendarDialog.calendars = data.calendars || []
                this.calendarDialog.calendarUri = data.calendarUri || null
                this.calendarDialog.transp = data.transp === 'TRANSPARENT' ? 'TRANSPARENT' : 'OPAQUE'
            } catch (err) {
                this.calendarDialog.error = err?.response?.data?.error || t('teamhub', 'Could not load your shifts.')
            } finally {
                this.calendarDialog.loading = false
            }
        },

        async exportToCalendar() {
            this.calendarDialog.saving = true
            this.calendarDialog.error = null
            try {
                const { data } = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/roster/calendar`),
                    {
                        week: this.apiFrom,
                        calendarUri: this.calendarDialog.calendarUri,
                        transp: this.calendarDialog.transp,
                    },
                )
                const done = (data.added || 0) + (data.updated || 0)
                if (data.failed > 0) {
                    // Keep the dialog open: the member has to know which week
                    // is only partly in their calendar.
                    this.calendarDialog.error = n('teamhub',
                        '{count} shift could not be added to your calendar.',
                        '{count} shifts could not be added to your calendar.',
                        data.failed,
                        { count: data.failed, n: data.failed },
                    )
                    return
                }
                this.calendarDialog.open = false
                showSuccess(n('teamhub',
                    '{count} shift is in your calendar',
                    '{count} shifts are in your calendar',
                    done,
                    { count: done, n: done },
                ))
            } catch (err) {
                this.calendarDialog.error = err?.response?.data?.error || t('teamhub', 'Could not add your shifts to the calendar.')
            } finally {
                this.calendarDialog.saving = false
            }
        },

        /** Time off, a double booking, or already on it: not a choice. */
        isBlocked(option) {
            return option.state === 'timeoff' || option.state === 'overlap' || option.onShift
        },

        /**
         * Minutes as "7.5 h", one decimal only where it earns it, in the
         * viewer's number format. The column heading says whose hours.
         */
        hoursShort(minutes) {
            const hours = (minutes || 0) / 60
            let formatted
            try {
                formatted = new Intl.NumberFormat(getCanonicalLocale(), { maximumFractionDigits: 1 }).format(hours)
            } catch (e) {
                formatted = String(Math.round(hours * 10) / 10)
            }
            // TRANSLATORS: a number of hours in a narrow column; "h" abbreviates hours
            return t('teamhub', '{hours} h', { hours: formatted })
        },

        /**
         * What stands in this person's way, in two words, or null.
         * Colour is never the only signal: the chip carries the words.
         */
        statusChip(option) {
            if (option.onShift) return { text: t('teamhub', 'On this shift'), variant: 'tertiary' }
            switch (option.state) {
            case 'timeoff': return { text: t('teamhub', 'Time off'), variant: 'error' }
            case 'overlap': return { text: t('teamhub', 'Already working'), variant: 'error' }
            case 'unavailable': return { text: t('teamhub', 'Not available'), variant: 'warning' }
            default: return null
            }
        },

        /** "Mon 16 Nov", from the shift's floating date. */
        calendarDayLabel(item) {
            return formatIsoDate(item.date, { weekday: 'short', month: 'short', day: 'numeric' })
        },

        /**
         * Every write goes through here: one busy flag, one error surface, and
         * a reload, because a shift's open count depends on rows this client
         * did not necessarily write.
         */
        async send(request, fallbackMessage) {
            this.busy = true
            this.error = null
            try {
                await request()
                await this.load()
                return true
            } catch (err) {
                this.error = err?.response?.data?.error || fallbackMessage
                return false
            } finally {
                this.busy = false
            }
        },
    },
}
</script>

<style scoped>
.team-roster {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
}

/* ── Toolbar ───────────────────────────────────────────────────── */
.roster-toolbar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--th-space-sm) var(--th-space-md);
    padding: var(--th-space-sm) var(--th-space-lg);
    border-bottom: 1px solid var(--color-border);
    flex-shrink: 0;
}

.roster-toolbar__nav {
    display: flex;
    align-items: center;
    gap: var(--th-space-xs);
}

.roster-toolbar__period {
    font-size: var(--th-font-body);
    font-weight: 500;
    min-width: 160px;
    text-align: center;
}

/* Nothing here may shrink below its own label. */
.roster-toolbar__publish {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
    flex-shrink: 0;
    margin-inline-start: auto;
}

.roster-toolbar__draft {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
}

/* Concept or published, as a tinted fill with its own ink — the NC state
   pair. Never colour alone: the chip carries the words. */
.roster-toolbar__state {
    padding-inline: var(--th-space-sm);
    border-radius: var(--th-radius-chip);
    font-size: var(--th-font-meta);
    font-weight: 500;
    white-space: nowrap;
}

.roster-toolbar__state--live {
    background: var(--color-success);
    color: var(--color-success-text);
}

.roster-toolbar__state--draft {
    background: var(--color-warning);
    color: var(--color-warning-text);
}

.roster-toolbar__state--none {
    background: var(--color-background-hover);
    color: var(--color-text-maxcontrast);
}

/* ── Lay out a week (team admin only) ──────────────────────────── */
.roster-plan {
    display: flex;
    align-items: flex-end;
    flex-wrap: wrap;
    gap: var(--th-space-sm) var(--th-space-md);
    padding: var(--th-space-sm) var(--th-space-lg);
    border-bottom: 1px solid var(--color-border);
    flex-shrink: 0;
}

/* v4.14.10 — a width of its own, not the width of its text. The field used
   to shrink-wrap the select, and the select shrink-wraps its selected value.
   Opening it takes that value out of the flow (vue-select overlays it,
   faded, behind the search input), so the box collapsed to an empty input
   while the list underneath kept the text's width: a sliver of a control
   above a wider menu (Justin, 2026-11-10). A fixed inline size on the field,
   which the select fills, makes closed, open and the list one width.
   64 grid steps is 256px, the width NcSelect is drawn at elsewhere in NC. */
.roster-plan__field {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    flex-shrink: 0;
    inline-size: calc(64 * var(--default-grid-baseline));
    max-inline-size: 100%;
}

.roster-plan__field :deep(.v-select.select) {
    inline-size: 100%;
}

.roster-plan__field label {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

/* NcSelect carries `margin: 0 0 var(--default-grid-baseline)` of its own, for
   the stacked form it is usually in. This row is not one: it is
   `align-items: flex-end`, so that 4px bottom margin lifts the control off the
   line *Lay out this week* sits on (Justin, 2026-11-03). Taken off here rather
   than padded around on the button, because the margin is the thing that is
   wrong for this row.

   Three classes deep through `:deep()` on purpose. `@nextcloud/vue` styles
   `.v-select.select` at specificity (0,2,0), and a scoped single class
   compiles to `.roster-plan__field[data-v-…]`, which is (0,2,0) too, so which
   of the two won would come down to the order the stylesheets happened to
   load (`/ui-standards`, § NcActionText). This selector is (0,4,0) and wins
   wherever it lands. */
.roster-plan__field :deep(.v-select.select) {
    margin-block-end: 0;
}

.roster-plan__hint {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    align-self: center;
}

/* ── Week ──────────────────────────────────────────────────────── */
.roster-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 1;
}

.roster-week {
    display: grid;
    grid-template-columns: repeat(7, minmax(160px, 1fr));
    gap: var(--th-space-sm);
    padding: var(--th-space-md) var(--th-space-lg);
    overflow: auto;
    flex: 1;
    min-width: 0;
}

.roster-day {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-sm);
    min-width: 0;
}

.roster-day__header {
    display: flex;
    align-items: baseline;
    gap: var(--th-space-xs);
    padding-block-end: var(--th-space-xs);
    border-block-end: 1px solid var(--color-border);
}

/* A day the organisation normally does not work. A tinted fill with its own
   ink, per the NC state pair, and the holiday's name beside it so the colour
   is never the only thing saying it. */
.roster-day--holiday .roster-day__header {
    border-block-end-color: var(--color-warning-text);
}

.roster-day__holiday {
    padding-inline: var(--th-space-xs);
    border-radius: var(--th-radius-chip);
    background: var(--color-warning);
    color: var(--color-warning-text);
    font-size: var(--th-font-meta);
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    max-width: 100%;
}

.roster-day--today .roster-day__header {
    border-block-end-color: var(--color-primary-element);
}

.roster-day__name { font-weight: 600; font-size: var(--th-font-body); }
.roster-day__date { font-size: var(--th-font-meta); color: var(--color-text-maxcontrast); }

/* Open places on this day. A tinted fill with its own ink, per the NC state
   pair — never a saturated colour, and never colour alone: the title names
   the number and the day. */
.roster-day__open {
    margin-inline-start: auto;
    padding-inline: var(--th-space-xs);
    border-radius: var(--th-radius-chip);
    background: var(--color-warning);
    color: var(--color-warning-text);
    font-size: var(--th-font-meta);
    font-weight: 700;
}

.roster-day__empty {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    margin: 0;
}

/* ── One shift ─────────────────────────────────────────────────── */
.roster-shift {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    padding: var(--th-space-sm);
    border: 1px solid var(--color-border);
    border-radius: var(--th-radius-card);
    background: var(--color-main-background);
}

/* A draft is said in the card's own words as well; the dashes alone would be
   a meaning carried by a border style. */
.roster-shift--draft {
    border-style: dashed;
    border-color: var(--color-text-maxcontrast);
}

.roster-shift--open {
    border-color: var(--color-warning-text);
}

.roster-shift__head {
    display: flex;
    align-items: center;
    gap: var(--th-space-xs);
    min-width: 0;
}

/* The shift type's colour. Circle-locked so content cannot oval it. */
.roster-shift__swatch {
    display: inline-block;
    flex: 0 0 auto;
    box-sizing: border-box;
    width: 10px;
    height: 10px;
    min-width: 10px;
    min-height: 10px;
    max-width: 10px;
    max-height: 10px;
    padding: 0;
    border-radius: 50%;
    background: var(--color-text-maxcontrast);
}

/* Bold, not semibold: the Role is the first thing to read on the card. */
.roster-shift__name {
    font-weight: 700;
    font-size: var(--th-font-meta);
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.roster-shift__time {
    margin-inline-start: auto;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
}

.roster-shift__draft {
    align-self: flex-start;
    padding-inline: var(--th-space-xs);
    border-radius: var(--th-radius-chip);
    background: var(--color-background-hover);
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
}

.roster-shift__people {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
}

.roster-shift__person {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.roster-shift__person-name {
    font-size: var(--th-font-meta);
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.roster-shift__person-row {
    display: flex;
    align-items: center;
    gap: var(--th-space-xs);
    min-width: 0;
}

/* The mark takes the primary colour (Justin, 2026-11-02): the warning ink is
   near black in the light theme, so the triangle read as another grey glyph
   rather than something to press.

   `:deep()` because the icon is a child component that renders its own SVG,
   and `currentColor` only reaches it if the colour is set on an element the
   SVG actually inherits from. NcButton owns everything else about the
   control. */
.roster-shift__alert,
.roster-shift__alert:deep(.material-design-icon) {
    color: var(--color-primary-element);
}

/* The detail, indented under the person it belongs to. */
.roster-shift__handover {
    display: flex;
    align-items: center;
    gap: var(--th-space-xs);
    margin-block-start: var(--th-space-xs);
    margin-inline-start: var(--th-space-lg);
    min-width: 0;
}

.roster-shift__handover-reason {
    font-size: var(--th-font-meta);
    color: var(--color-warning-text);
    overflow: hidden;
    text-overflow: ellipsis;
}

.roster-dialog__hint {
    margin: 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.roster-shift__gap {
    margin: 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-error);
}

.roster-shift__actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--th-space-xs);
}

.roster-day__add {
    align-self: flex-start;
}

/* ── Dialogs ───────────────────────────────────────────────────── */
.roster-dialog {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-md);
    padding: var(--th-space-sm) 0;
    /* NcSelect carries min-width: 260px of its own; give the dialog room for
       it rather than pinning a smaller width it would fight. */
    min-width: 300px;
}

.roster-dialog__loading {
    display: flex;
    justify-content: center;
    padding: var(--th-space-md);
}

/* v4.14.11 — one grid template for the heading and every row, so the
   figures and the chips line up in columns down the list. Name takes the
   rest; a figure column is 16 grid steps; the status column is wide enough
   for the longest two-word chip. A weekend shift adds the Weekends column
   ahead of This week, because that is the one it is sorted on. */
.roster-staffing {
    --roster-staffing-cols: minmax(0, 1fr) calc(16 * var(--default-grid-baseline)) calc(32 * var(--default-grid-baseline));
    display: flex;
    flex-direction: column;
    max-height: calc(80 * var(--default-grid-baseline));
    overflow-y: auto;
}

.roster-staffing--weekend {
    --roster-staffing-cols: minmax(0, 1fr) calc(16 * var(--default-grid-baseline)) calc(16 * var(--default-grid-baseline)) calc(32 * var(--default-grid-baseline));
}

.roster-staffing__head,
.roster-staffing__row {
    display: grid;
    grid-template-columns: var(--roster-staffing-cols);
    align-items: center;
    column-gap: var(--th-space-sm);
}

.roster-staffing__head {
    position: sticky;
    inset-block-start: 0;
    z-index: 1;
    padding-block: var(--th-space-xs);
    padding-inline-start: var(--th-space-sm);
    background: var(--color-main-background);
    border-block-end: 1px solid var(--color-border);
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.roster-staffing__row {
    border-block-end: 1px solid var(--color-border);
}

.roster-staffing__row:last-child {
    border-block-end: none;
}

.roster-staffing__num {
    display: inline-flex;
    align-items: center;
    justify-content: flex-end;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.roster-staffing__row .roster-staffing__num {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

/* The column the list is ordered by reads as the heading that matters. */
.roster-staffing__sorted {
    color: var(--color-main-text);
    font-weight: 600;
}

.roster-staffing__status {
    display: flex;
    justify-content: flex-end;
}

/* Somebody who cannot be picked is quieter, and the chip says why. */
.roster-staffing__row--blocked .roster-staffing__num {
    opacity: .6;
}

.roster-dialog__field {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
}

/* ── Calendar export (v4.14.8) ─────────────────────────────────── */
.roster-calendar__shifts {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    margin: 0;
    padding: 0;
    list-style: none;
}

.roster-calendar__shift {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: var(--th-space-xs) var(--th-space-sm);
}

.roster-calendar__day {
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
}

.roster-calendar__name {
    font-weight: 600;
}

.roster-calendar__time,
.roster-calendar__already {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.roster-calendar__already {
    flex-basis: 100%;
}

.roster-calendar__transp {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    margin: 0;
    padding: 0;
    border: 0;
}

.roster-calendar__transp legend {
    margin-block-end: var(--th-space-xs);
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.roster-calendar__transp .roster-dialog__hint {
    padding-inline-start: var(--th-space-xxl);
}

.roster-dialog__field label {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

@media (max-width: 1024px) {
    .roster-week {
        grid-template-columns: 1fr;
    }
}
</style>
