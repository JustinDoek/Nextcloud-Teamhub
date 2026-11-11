<template>
    <div class="roster-templates" data-section="roster-templates">
        <h2 class="roster-templates__title">{{ t('teamhub', 'Roster templates') }}</h2>
        <p class="roster-templates__intro">
            {{ t('teamhub', 'A template is one week: the role you need somebody for, and the shift it runs on. Draft a week from it on the Roster tab and all that is left is putting people in it. Editing a template never changes a week that was already drafted from it.') }}
        </p>

        <div v-if="loading" class="roster-templates__loading">
            <NcLoadingIcon :size="ICON_LARGE" />
        </div>
        <NcNoteCard v-else-if="error" type="error">
            {{ error }}
            <template #action>
                <NcButton variant="secondary" @click="load">{{ t('teamhub', 'Try again') }}</NcButton>
            </template>
        </NcNoteCard>

        <template v-else>
            <!-- The shift types are the Nextcloud administrator's, so a team
                 with none has nothing to build a template out of and is told
                 where they come from rather than handed an empty picker. -->
            <NcNoteCard v-if="types.length === 0" type="warning">
                {{ t('teamhub', 'There are no shift types yet. A Nextcloud administrator adds them under Admin, TeamHub, People, and a template is built out of them.') }}
            </NcNoteCard>

            <template v-else>
                <NcButton variant="primary" class="roster-templates__add" @click="openCreate">
                    <template #icon><PlusIcon :size="ICON_BODY" /></template>
                    {{ t('teamhub', 'Add template') }}
                </NcButton>

                <NcEmptyContent
                    v-if="templates.length === 0"
                    :name="t('teamhub', 'No templates yet')"
                    :description="t('teamhub', 'Build the week this team normally works once, then draft every new week from it.')">
                    <template #icon><CalendarClockIcon :size="ICON_HERO" /></template>
                </NcEmptyContent>

                <ul v-else class="roster-templates__list" role="list">
                    <li
                        v-for="template in templates"
                        :key="template.id"
                        class="roster-template"
                        :class="{ 'roster-template--off': !template.isActive }">

                        <div class="roster-template__head">
                            <span class="roster-template__name">{{ template.name }}</span>
                            <span v-if="!template.isActive" class="roster-template__badge">
                                {{ t('teamhub', 'Switched off') }}
                            </span>
                            <span class="roster-template__total">{{ weeklyLabel(template) }}</span>
                            <NcButton
                                variant="tertiary"
                                :aria-label="editLabel(template)"
                                :title="editLabel(template)"
                                @click="openEdit(template)">
                                <template #icon><PencilIcon :size="ICON_BODY" /></template>
                            </NcButton>
                            <NcButton
                                variant="tertiary"
                                :aria-label="deleteLabel(template)"
                                :title="deleteLabel(template)"
                                @click="askDelete(template)">
                                <template #icon><DeleteIcon :size="ICON_BODY" /></template>
                            </NcButton>
                        </div>

                        <p v-if="template.description" class="roster-template__desc">
                            {{ template.description }}
                        </p>

                        <!-- The week, read only: a day per column with its
                             roles stacked in it, the same shape as the Roster
                             tab. A role nobody is needed for has no row, so a
                             quiet day is visibly quiet. -->
                        <div class="roster-template__week" :aria-label="weekLabel(template)">
                            <div v-for="day in dayNames" :key="day.index" class="roster-template__day">
                                <span class="roster-template__day-name">{{ day.label }}</span>
                                <ul v-if="linesOn(template, day.index).length" class="roster-template__day-lines" role="list">
                                    <li
                                        v-for="(line, i) in linesOn(template, day.index)"
                                        :key="day.index + ':' + i"
                                        class="roster-template__line">
                                        <span
                                            class="roster-template__swatch"
                                            :style="line.color ? { backgroundColor: line.color } : null"></span>
                                        <span class="roster-template__role">{{ line.role }}</span>
                                        <span class="roster-template__hours">{{ hoursOf(line) }}</span>
                                        <span v-if="line.requiredCount > 1" class="roster-template__count">
                                            {{ peopleLabel(line.requiredCount) }}
                                        </span>
                                    </li>
                                </ul>
                                <span v-else class="roster-template__day-empty">
                                    {{ t('teamhub', 'Nothing') }}
                                </span>
                            </div>
                        </div>
                    </li>
                </ul>
            </template>
        </template>

        <!-- ── Create / edit ─────────────────────────────────────────────── -->
        <NcDialog
            v-if="dialog.open"
            :name="dialog.id ? t('teamhub', 'Edit template') : t('teamhub', 'Add template')"
            size="large"
            :open="dialog.open"
            @update:open="dialog.open = $event">

            <div class="roster-dialog">
                <NcNoteCard v-if="dialog.error" type="error">{{ dialog.error }}</NcNoteCard>

                <NcTextField
                    v-model="dialog.name"
                    :label="t('teamhub', 'Name')"
                    :maxlength="128" />

                <NcTextField
                    v-model="dialog.description"
                    :label="t('teamhub', 'Description')"
                    :maxlength="255" />

                <NcCheckboxRadioSwitch
                    :model-value="dialog.isActive"
                    type="switch"
                    @update:model-value="dialog.isActive = $event">
                    {{ t('teamhub', 'Offer this template when drafting a week') }}
                </NcCheckboxRadioSwitch>

                <p class="roster-dialog__hint">
                    {{ t('teamhub', 'Add one line per person you need: the role, the shift it runs on, and how many people. A day with no lines has no shifts.') }}
                </p>

                <!-- A day at a time, because that is how somebody describes a
                     week out loud: "Monday we need a receptionist on Early and
                     two baristas on Late." -->
                <div
                    v-for="day in dayNames"
                    :key="day.index"
                    class="roster-dialog__day">

                    <div class="roster-dialog__day-head">
                        <h3 class="roster-dialog__day-name">{{ day.label }}</h3>
                        <NcButton variant="tertiary" @click="addLine(day.index)">
                            <template #icon><PlusIcon :size="ICON_BODY" /></template>
                            {{ t('teamhub', 'Add role') }}
                        </NcButton>
                        <!-- The one shortcut worth having: most weeks are the
                             same five days, and typing them out five times is
                             how a template stops being worth making. -->
                        <NcButton
                            v-if="dialog.lines.filter(l => l.dayOfWeek === day.index).length > 0"
                            variant="tertiary"
                            :aria-label="copyLabel(day)"
                            :title="copyLabel(day)"
                            @click="copyToWeekdays(day.index)">
                            <template #icon><ContentCopyIcon :size="ICON_BODY" /></template>
                        </NcButton>
                    </div>

                    <p v-if="editLinesOn(day.index).length === 0" class="roster-dialog__day-empty">
                        {{ t('teamhub', 'No shifts on this day.') }}
                    </p>

                    <div
                        v-for="line in editLinesOn(day.index)"
                        :key="line.key"
                        class="roster-dialog__line">
                        <NcTextField
                            v-model="line.role"
                            class="roster-dialog__role"
                            :label="t('teamhub', 'Role')"
                            :maxlength="128"
                            :placeholder="t('teamhub', 'e.g. Receptionist')" />
                        <NcSelect
                            v-model="line.shiftTypeId"
                            class="roster-dialog__shift"
                            label-outside
                            :input-id="'tpl-shift-' + line.key"
                            :aria-label-combobox="t('teamhub', 'Shift')"
                            :options="typeOptions"
                            :reduce="o => o.id"
                            :clearable="false"
                            label="label" />
                        <NcTextField
                            v-model.number="line.requiredCount"
                            class="roster-dialog__count"
                            type="number"
                            min="1"
                            max="99"
                            :label="t('teamhub', 'People')" />
                        <NcButton
                            variant="tertiary"
                            :aria-label="t('teamhub', 'Remove this line')"
                            :title="t('teamhub', 'Remove this line')"
                            @click="removeLine(line.key)">
                            <template #icon><DeleteIcon :size="ICON_BODY" /></template>
                        </NcButton>
                    </div>
                </div>
            </div>

            <template #actions>
                <NcButton variant="tertiary" @click="dialog.open = false">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton variant="primary" :disabled="saving || !dialog.name" @click="save">
                    {{ t('teamhub', 'Save') }}
                </NcButton>
            </template>
        </NcDialog>

        <!-- Deleting is destructive, so it confirms first and says what it does
             NOT touch: a week already drafted from the template stays. -->
        <NcDialog
            v-if="pendingDelete"
            :name="t('teamhub', 'Delete template')"
            :open="!!pendingDelete"
            @update:open="pendingDelete = null">
            <p>
                {{ t('teamhub', 'Delete the template {name}? Weeks already drafted from it stay exactly as they are.', { name: pendingDelete.name }) }}
            </p>
            <template #actions>
                <NcButton variant="tertiary" @click="pendingDelete = null">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton variant="error" @click="confirmDelete">
                    {{ t('teamhub', 'Delete') }}
                </NcButton>
            </template>
        </NcDialog>
    </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import {
    NcButton, NcLoadingIcon, NcDialog, NcTextField, NcSelect,
    NcCheckboxRadioSwitch, NcNoteCard, NcEmptyContent,
} from '@nextcloud/vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue'
import CalendarClockIcon from 'vue-material-design-icons/CalendarClock.vue'
import { ICON_BODY, ICON_LARGE, ICON_HERO } from '../constants/uiTokens.js'

/**
 * A team's roster week templates (v4.13.74, DESIGN §2.240).
 *
 * Manage team → Module settings, below the other module blocks and only while
 * Shifts is switched on for the team.
 *
 * ## Why this screen exists at all
 *
 * The shift **types** are the Nextcloud administrator's vocabulary (*Early is
 * 07:00 to 15:00*) and stay in Admin, TeamHub. The weekly **pattern** an
 * administrator could already compose there is instance-wide, and its endpoint
 * is Nextcloud-admin only — so the Roster tab's picker was permanently empty
 * for the one person it was built for, a team admin. Justin's division,
 * 2026-11-02: the shifts are the instance's, the templates built from them are
 * the team's.
 *
 * ## Why the editor is a list of lines per day, not a grid of numbers
 *
 * The obvious shape is a grid — types × weekdays, a count in each cell — and
 * that is what the instance-wide pattern of §2.234 was, back when there was
 * one. It is right for a countable pattern. A template line carries a
 * **role**, which is free text, and a role is the thing that cannot go in a
 * cell. It also means a Monday may hold *Receptionist on Early* and *Barista
 * on Early* at once, which a grid cannot express at all.
 *
 * So a day is a list, and `Add role` is the verb. The cost is that a five-day
 * week is typed five times, which is what the copy control on each day is for.
 */
export default {
    name: 'RosterTemplatesManager',

    components: {
        NcButton, NcLoadingIcon, NcDialog, NcTextField, NcSelect,
        NcCheckboxRadioSwitch, NcNoteCard, NcEmptyContent,
        PlusIcon, PencilIcon, DeleteIcon, ContentCopyIcon, CalendarClockIcon,
    },

    props: {
        teamId: { type: String, required: true },
    },

    emits: ['changed'],

    data() {
        return {
            ICON_BODY,
            ICON_LARGE,
            ICON_HERO,
            loading: false,
            saving: false,
            error: null,
            templates: [],
            types: [],
            pendingDelete: null,
            dialog: {
                open: false, id: null, name: '', description: '', isActive: true,
                lines: [], error: null,
            },
            /**
             * Keys for the editor's lines. A line has no id of its own until
             * it is saved, and `v-for` over an index would re-use the wrong
             * input when a line in the middle is removed.
             */
            nextKey: 1,
        }
    },

    computed: {
        /**
         * 0 = Monday, matching the stored day_of_week.
         *
         * The short forms: seven columns of a week do not have room for
         * "Wednesday".
         */
        dayNames() {
            const labels = [
                t('teamhub', 'Mon'), t('teamhub', 'Tue'), t('teamhub', 'Wed'),
                t('teamhub', 'Thu'), t('teamhub', 'Fri'), t('teamhub', 'Sat'), t('teamhub', 'Sun'),
            ]
            return labels.map((label, index) => ({ index, label }))
        },

        typeOptions() {
            return this.types.map(type => ({
                id: type.id,
                label: type.startTime
                    ? t('teamhub', '{shift}, {from} to {to}', {
                        shift: type.label, from: type.startTime, to: type.endTime,
                    })
                    : type.label,
            }))
        },
    },

    mounted() {
        this.load()
    },

    methods: {
        t, n,

        async load() {
            if (!this.teamId) return
            this.loading = true
            this.error = null
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/roster/templates`),
                )
                this.templates = data.templates || []
                this.types = data.types || []
            } catch (err) {
                this.error = err?.response?.data?.error
                    || t('teamhub', 'Could not load the roster templates.')
            } finally {
                this.loading = false
            }
        },

        weeklyLabel(template) {
            return n('teamhub',
                '{count} shift a week',
                '{count} shifts a week',
                template.weeklyTotal,
                { count: template.weeklyTotal, n: template.weeklyTotal },
            )
        },

        peopleLabel(count) {
            return n('teamhub', '{count} person', '{count} people', count, { count, n: count })
        },

        editLabel(template) {
            return t('teamhub', 'Edit {name}', { name: template.name })
        },

        deleteLabel(template) {
            return t('teamhub', 'Delete {name}', { name: template.name })
        },

        weekLabel(template) {
            return t('teamhub', 'The week in {name}', { name: template.name })
        },

        copyLabel(day) {
            return t('teamhub', 'Copy {day} to the other weekdays', { day: day.label })
        },

        hoursOf(line) {
            if (!line.startTime) return line.shiftTypeName
            return t('teamhub', '{from} to {to}', { from: line.startTime, to: line.endTime })
        },

        /** A saved template's lines on one weekday, for the read-only week. */
        linesOn(template, day) {
            return (template.lines || []).filter(line => line.dayOfWeek === day)
        },

        /** The editor's lines on one weekday. */
        editLinesOn(day) {
            return this.dialog.lines.filter(line => line.dayOfWeek === day)
        },

        openCreate() {
            this.dialog = {
                open: true, id: null, name: '', description: '', isActive: true,
                lines: [], error: null,
            }
        },

        openEdit(template) {
            this.dialog = {
                open: true,
                id: template.id,
                name: template.name,
                description: template.description,
                isActive: template.isActive,
                lines: (template.lines || []).map(line => ({
                    key: this.nextKey++,
                    dayOfWeek: line.dayOfWeek,
                    shiftTypeId: line.shiftTypeId,
                    role: line.role,
                    requiredCount: line.requiredCount,
                })),
                error: null,
            }
        },

        addLine(day) {
            this.dialog.lines.push({
                key: this.nextKey++,
                dayOfWeek: day,
                // The first type rather than nothing: a line whose shift is
                // unset cannot be saved, and the picker is a choice between
                // real shifts rather than a thing to remember to fill in.
                shiftTypeId: this.types.length ? this.types[0].id : 0,
                role: '',
                requiredCount: 1,
            })
        },

        removeLine(key) {
            this.dialog.lines = this.dialog.lines.filter(line => line.key !== key)
        },

        /**
         * Copy one day's lines onto the other four weekdays, replacing
         * whatever was on them.
         *
         * Replacing rather than appending, because the control is used to say
         * "the week is like this day" and appending would quietly double a day
         * somebody pressed it on twice. The weekend is deliberately left out:
         * a team that works Saturdays almost never works them the same way.
         */
        copyToWeekdays(day) {
            const source = this.editLinesOn(day)
            const kept = this.dialog.lines.filter(
                line => line.dayOfWeek === day || line.dayOfWeek > 4,
            )
            const copies = []
            for (let target = 0; target <= 4; target++) {
                if (target === day) continue
                for (const line of source) {
                    copies.push({
                        key: this.nextKey++,
                        dayOfWeek: target,
                        shiftTypeId: line.shiftTypeId,
                        role: line.role,
                        requiredCount: line.requiredCount,
                    })
                }
            }
            this.dialog.lines = [...kept, ...copies]
        },

        async save() {
            this.saving = true
            this.dialog.error = null

            const lines = this.dialog.lines.map(line => ({
                dayOfWeek: Number(line.dayOfWeek),
                shiftTypeId: Number(line.shiftTypeId),
                role: (line.role || '').trim(),
                requiredCount: Number(line.requiredCount) || 1,
            }))

            // Said here as well as on the server, because the server's refusal
            // names no line and this one can be read beside the empty field.
            if (lines.some(line => line.role === '')) {
                this.dialog.error = t('teamhub', 'Every line needs the role you need somebody for.')
                this.saving = false
                return
            }

            const payload = {
                name: this.dialog.name,
                description: this.dialog.description,
                isActive: this.dialog.isActive,
                lines,
            }

            try {
                const base = `/apps/teamhub/api/v1/teams/${this.teamId}/roster/templates`
                if (this.dialog.id) {
                    await axios.put(generateUrl(`${base}/${this.dialog.id}`), payload)
                } else {
                    await axios.post(generateUrl(base), payload)
                }
                this.dialog.open = false
                await this.load()
                this.$emit('changed')
            } catch (err) {
                this.dialog.error = err?.response?.data?.error
                    || t('teamhub', 'Could not save the template.')
            } finally {
                this.saving = false
            }
        },

        askDelete(template) {
            this.pendingDelete = template
        },

        async confirmDelete() {
            const template = this.pendingDelete
            this.pendingDelete = null
            if (!template) return
            try {
                await axios.delete(generateUrl(
                    `/apps/teamhub/api/v1/teams/${this.teamId}/roster/templates/${template.id}`,
                ))
                await this.load()
                this.$emit('changed')
            } catch (err) {
                this.error = err?.response?.data?.error
                    || t('teamhub', 'Could not delete the template.')
            }
        },
    },
}
</script>

<style scoped>
/* The heading and intro are styled here rather than borrowing Manage team's
   `.manage-section-desc`: that class lives in the parent's *scoped* styles and
   does not cross into a child component, so reusing the name would have looked
   right in the markup and rendered unstyled. */
.roster-templates__title {
    font-size: var(--th-font-heading);
    font-weight: var(--th-font-weight-bold);
    margin: 0 0 var(--th-space-xs);
}

.roster-templates__intro {
    margin: 0 0 var(--th-space-md);
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    line-height: var(--th-line-height-body);
    max-width: 70ch;
}

.roster-templates__loading {
    display: flex;
    justify-content: center;
    padding: var(--th-space-lg);
}

.roster-templates__add {
    margin-block-end: var(--th-space-md);
}

.roster-templates__list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--th-space-md);
}

.roster-template {
    border: 1px solid var(--color-border);
    border-radius: var(--th-radius-card);
    padding: var(--th-space-md);
}

.roster-template--off {
    opacity: 0.6;
}

.roster-template__head {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
    flex-wrap: wrap;
}

.roster-template__name {
    font-weight: 600;
    font-size: var(--th-font-heading);
}

/* Tinted fill with its own ink, per the NC state pair. */
.roster-template__badge {
    padding-inline: var(--th-space-xs);
    border-radius: var(--th-radius-chip);
    background: var(--color-warning);
    color: var(--color-warning-text);
    font-size: var(--th-font-meta);
}

.roster-template__total {
    margin-inline-start: auto;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
}

.roster-template__desc {
    margin: var(--th-space-xs) 0 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

/* ── The week, read only ───────────────────────────────────────── */
.roster-template__week {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: var(--th-space-xs);
    margin-block-start: var(--th-space-md);
}

/* One column per day is unreadable on a phone, so the week stacks. */
@media (max-width: 1024px) {
    .roster-template__week {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 640px) {
    .roster-template__week {
        grid-template-columns: minmax(0, 1fr);
    }
}

.roster-template__day {
    border: 1px solid var(--color-border);
    border-radius: var(--th-radius-card);
    padding: var(--th-space-xs);
    min-width: 0;
}

.roster-template__day-name {
    display: block;
    font-size: var(--th-font-meta);
    font-weight: 600;
    color: var(--color-text-maxcontrast);
    margin-block-end: var(--th-space-xs);
}

.roster-template__day-lines {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
}

.roster-template__line {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--th-space-xs);
    font-size: var(--th-font-meta);
    min-width: 0;
}

/* Circle-locked so content cannot stretch it into an oval. */
.roster-template__swatch {
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

/* The role takes the line, as it does on the roster card (§2.236). */
.roster-template__role {
    font-weight: 600;
    overflow-wrap: anywhere;
}

.roster-template__hours,
.roster-template__count {
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
}

.roster-template__day-empty {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

/* ── The editor ────────────────────────────────────────────────── */
.roster-dialog {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-md);
    padding: var(--th-space-sm) 0;
}

.roster-dialog__hint {
    margin: 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.roster-dialog__day {
    border-block-start: 1px solid var(--color-border);
    padding-block-start: var(--th-space-sm);
}

.roster-dialog__day-head {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
    flex-wrap: wrap;
}

.roster-dialog__day-name {
    margin: 0;
    font-size: var(--th-font-body);
    font-weight: 600;
}

.roster-dialog__day-empty {
    margin: var(--th-space-xs) 0 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

/* A grid rather than a flex row, because the three fields have to fit.
   v4.13.75 — as a wrapping flex row with pixel bases (180 + 200 + 96 + the
   button) the line could want more width than the dialog had, and what gives
   first is the smallest field: the People box was clipped to the point that
   its own label was cut off. `minmax(0, …)` is the whole fix — a grid track
   whose minimum is 0 shrinks instead of overflowing, where a flex item's
   default `min-width: auto` refuses to go below its content. The count keeps
   a fixed width because two digits never need more, and the delete button
   sizes to itself so it is never the thing that gets squeezed. */
.roster-dialog__line {
    display: grid;
    grid-template-columns: minmax(0, 2fr) minmax(0, 2fr) 5.5rem auto;
    align-items: center;
    gap: var(--th-space-sm);
    margin-block-start: var(--th-space-sm);
}

/* Narrow: the role takes its own line and the rest sits under it, rather than
   three fields fighting over 320px. */
@media (max-width: 720px) {
    .roster-dialog__line {
        grid-template-columns: minmax(0, 1fr) 5.5rem auto;
    }

    .roster-dialog__role {
        grid-column: 1 / -1;
    }
}

/* Grid children default to `min-width: auto` as well, so each one says it
   may shrink. Without this the track minimums above do nothing. */
.roster-dialog__role,
.roster-dialog__shift,
.roster-dialog__count {
    min-width: 0;
}
</style>
