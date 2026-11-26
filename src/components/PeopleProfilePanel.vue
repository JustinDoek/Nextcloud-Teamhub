<template>
    <section class="th-people-profile" aria-labelledby="th-people-profile-title">
        <div class="th-people-profile__heading">
            <h2 id="th-people-profile-title">{{ t('teamhub', 'My collaboration profile') }}</h2>
            <p class="th-people-profile__subtitle">{{ t('teamhub', 'Your work, your colleagues, your connections') }}</p>
        </div>
        <p class="th-people-profile__hint">
            {{ t('teamhub', 'Help colleagues get to know you and find you. Your profile details stay private until People sharing features are available. You can change sharing preferences below.') }}
        </p>

        <NcLoadingIcon v-if="loading" :size="24" />
        <p v-else-if="loadError" role="alert" class="th-people-profile__error">{{ loadError }}</p>
        <form v-else class="th-people-profile__form" @submit.prevent="saver.now()">
            <div class="th-people-profile__row">
                <div class="th-people-profile__field">
                    <label for="th-people-department">{{ t('teamhub', 'Default department') }}</label>
                    <NcSelect
                        id="th-people-department"
                        :model-value="form.departmentTeamId || null"
                        :options="departmentOptions"
                        :reduce="option => option.id"
                        :placeholder="t('teamhub', 'Choose a department')"
                        :aria-label-combobox="t('teamhub', 'Default department')"
                        label-outside
                        :clearable="true"
                        @update:model-value="changeDepartment" />
                    <small>{{ t('teamhub', 'Only Department teams you belong to are available.') }}</small>
                </div>
                <div class="th-people-profile__field">
                    <label for="th-people-room">{{ t('teamhub', 'Usual workplace') }}</label>
                    <NcSelect
                        id="th-people-room"
                        :model-value="form.roomId"
                        :options="roomOptions"
                        :reduce="option => option.id"
                        :placeholder="t('teamhub', 'Choose a room')"
                        :aria-label-combobox="t('teamhub', 'Usual workplace')"
                        label-outside
                        :clearable="true"
                        @update:model-value="setField('roomId', $event ?? null)" />
                    <small>{{ t('teamhub', 'Rooms are grouped by building and floor. Your scheduled presence may differ.') }}</small>
                </div>
            </div>

            <div class="th-people-profile__row">
                <div class="th-people-profile__field">
                    <label for="th-people-expertise">{{ t('teamhub', 'Expertise and skills') }}</label>
                    <NcTextArea id="th-people-expertise" :model-value="form.expertise" :maxlength="500" :rows="2" @update:model-value="setText('expertise', $event)" />
                </div>
                <div class="th-people-profile__field">
                    <label for="th-people-ask">{{ t('teamhub', 'Ask me about') }}</label>
                    <NcTextArea id="th-people-ask" :model-value="form.askMeAbout" :maxlength="500" :rows="2" @update:model-value="setText('askMeAbout', $event)" />
                </div>
            </div>
            <div class="th-people-profile__row">
                <div class="th-people-profile__field">
                    <label for="th-people-hobbies">{{ t('teamhub', 'Hobbies and interests') }}</label>
                    <NcTextArea id="th-people-hobbies" :model-value="form.hobbies" :maxlength="500" :rows="2" @update:model-value="setText('hobbies', $event)" />
                </div>
                <div class="th-people-profile__field">
                    <label for="th-people-languages">{{ t('teamhub', 'Languages') }}</label>
                    <NcTextField id="th-people-languages" :model-value="form.languages" :maxlength="200" @update:model-value="setText('languages', $event)" />
                </div>
            </div>
            <div class="th-people-profile__row">
                <div class="th-people-profile__field">
                    <fieldset class="th-people-profile__contact">
                        <legend>{{ t('teamhub', 'Preferred contact methods') }}</legend>
                        <NcCheckboxRadioSwitch
                            v-for="method in contactMethodOptions"
                            :key="method.value"
                            type="checkbox"
                            :model-value="form.contactMethods.includes(method.value)"
                            @update:model-value="toggleContactMethod(method.value, $event)">
                            {{ method.label }}
                        </NcCheckboxRadioSwitch>
                    </fieldset>
                </div>
                <div class="th-people-profile__field">
                    <label for="th-people-fun">{{ t('teamhub', 'A fun fact about me') }}</label>
                    <NcTextField id="th-people-fun" :model-value="form.funFact" :maxlength="300" @update:model-value="setText('funFact', $event)" />
                </div>
            </div>
            <div class="th-people-profile__row">
                <div class="th-people-profile__field">
                    <label id="th-people-company-label" for="th-people-company-date">{{ t('teamhub', 'Company start date') }}</label>
                    <output aria-labelledby="th-people-company-label" id="th-people-company-date" class="th-people-profile__readonly">{{ form.companyStartDate || t('teamhub', 'Not available') }}</output>
                    <small>{{ t('teamhub', 'Account creation date when recorded; otherwise first login if known. This is not your employment start date.') }}</small>
                </div>
                <div class="th-people-profile__field">
                    <label id="th-people-department-label" for="th-people-department-date">{{ t('teamhub', 'Department start date') }}</label>
                    <output aria-labelledby="th-people-department-label" id="th-people-department-date" class="th-people-profile__readonly">{{ form.departmentStartDate || t('teamhub', 'Not available') }}</output>
                    <small>{{ t('teamhub', 'Based on your direct membership join date in the selected department.') }}</small>
                </div>
            </div>
            <fieldset class="th-people-profile__privacy">
                <legend>{{ t('teamhub', 'People and celebrations privacy') }}</legend>
                <p class="th-people-profile__hint">{{ t('teamhub', 'Birthday and anniversary sharing control personal celebrations. People-directory sharing will be used by future features.') }}</p>
                <NcCheckboxRadioSwitch :model-value="form.shareBirthday" type="switch" @update:model-value="setField('shareBirthday', $event)">
                    {{ t('teamhub', 'Allow my birthday in a department birthday widget') }}
                </NcCheckboxRadioSwitch>
                <NcCheckboxRadioSwitch :model-value="form.shareAnniversary" type="switch" @update:model-value="setField('shareAnniversary', $event)">
                    {{ t('teamhub', 'Allow my work anniversary celebration') }}
                </NcCheckboxRadioSwitch>
                <NcCheckboxRadioSwitch :model-value="form.shareHobbies" type="switch" @update:model-value="setField('shareHobbies', $event)">
                    {{ t('teamhub', 'Allow colleagues to see my interests in a future people directory') }}
                </NcCheckboxRadioSwitch>
            </fieldset>
            <!-- v4.20.12 (DESIGN §2.265) — no Save button: every field
                 writes itself, a text field once typing pauses, everything
                 else at once. -->
            <AutosaveStatus
                :saving="saving"
                :saved="saved"
                :error="saveError"
                @retry="saver.now()" />
        </form>
    </section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import { NcCheckboxRadioSwitch, NcLoadingIcon, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import { createAutosaver } from '../lib/autosave.js'
import AutosaveStatus from './AutosaveStatus.vue'

const emptyProfile = () => ({
    departmentTeamId: '', roomId: null, expertise: '', askMeAbout: '',
    hobbies: '', languages: '', funFact: '', contactMethods: [],
    companyStartDate: '', departmentStartDate: '',
    shareBirthday: true, shareAnniversary: true, shareHobbies: true,
})

export default {
    name: 'PeopleProfilePanel',
    components: { AutosaveStatus, NcCheckboxRadioSwitch, NcLoadingIcon, NcSelect, NcTextArea, NcTextField },
    data() {
        return {
            loading: true, loadError: '', saving: false, saveError: '', saved: false,
            form: emptyProfile(), departments: [], locations: [],
            // Bumped on every local edit. A save response is applied whole
            // only when nothing changed while it was in flight; otherwise it
            // would put back the text typed after the request left.
            revision: 0,
            saver: null,
            savedTimer: null,
        }
    },
    computed: {
        departmentOptions() {
            return this.departments.map(d => ({ id: d.id, label: d.name }))
        },
        contactMethodOptions() {
            return [
                { value: 'chat', label: t('teamhub', 'Chat') },
                { value: 'email', label: t('teamhub', 'Email') },
                { value: 'phone', label: t('teamhub', 'Phone') },
                { value: 'in_person', label: t('teamhub', 'In person') },
            ]
        },
        roomOptions() {
            const options = []
            for (const building of this.locations) {
                for (const floor of building.floors || []) {
                    for (const room of floor.rooms || []) {
                        options.push({ id: room.id, label: `${building.name} · ${floor.name} · ${room.name}` })
                    }
                }
            }
            return options
        },
    },
    created() {
        this.saver = createAutosaver({ save: () => this.saveProfile(), delay: 800 })
    },
    mounted() {
        this.loadProfile()
    },
    beforeUnmount() {
        this.saver.flush()
        clearTimeout(this.savedTimer)
    },
    methods: {
        t,
        async loadProfile() {
            this.loading = true
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/people/profile'))
                this.form = { ...emptyProfile(), ...data.profile }
                this.departments = data.departments || []
                this.locations = data.locations || []
            } catch (e) {
                this.loadError = t('teamhub', 'Could not load your collaboration profile.')
            } finally {
                this.loading = false
            }
        },
        /** A discrete choice (select, switch, checkbox): write at once. */
        setField(key, value) {
            this.form[key] = value
            this.revision++
            this.saved = false
            this.saver.now()
        },
        /** Typing: write once the person pauses. */
        setText(key, value) {
            this.form[key] = value
            this.revision++
            this.saved = false
            this.saver.schedule()
        },
        changeDepartment(value) {
            // Date is server-owned and fetched for the selected membership.
            this.form.departmentStartDate = ''
            if (value) this.refreshDepartmentStartDate(value)
            this.setField('departmentTeamId', value || '')
        },
        toggleContactMethod(method, checked) {
            const next = new Set(this.form.contactMethods)
            if (checked) next.add(method)
            else next.delete(method)
            this.setField('contactMethods', [...next])
        },
        async refreshDepartmentStartDate(departmentId) {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/people/profile'), { params: { department: departmentId } })
                // Avoid races when the user selects another department rapidly.
                if (this.form.departmentTeamId === departmentId) {
                    this.form.departmentStartDate = data?.profile?.departmentStartDate || ''
                }
            } catch {
                // Not available is safer than showing a stale membership date.
            }
        },
        /** Called by the autosaver only; reads the form when it runs. */
        async saveProfile() {
            const sentRevision = this.revision
            this.saving = true
            this.saveError = ''
            this.saved = false
            try {
                const { data } = await axios.put(generateUrl('/apps/teamhub/api/v1/people/profile'), { profile: { ...this.form } })
                if (this.revision === sentRevision) {
                    this.form = { ...emptyProfile(), ...data.profile }
                } else {
                    // Edited while saving: keep what is on screen, take only
                    // the dates the server owns. The follow-up save sends the
                    // rest.
                    this.form.companyStartDate = data.profile?.companyStartDate || ''
                }
                this.departments = data.departments || []
                this.locations = data.locations || []
                this.saved = true
                clearTimeout(this.savedTimer)
                this.savedTimer = setTimeout(() => { this.saved = false }, 2000)
            } catch (error) {
                this.saveError = error?.response?.data?.error || t('teamhub', 'Could not save the collaboration profile. Try again.')
            } finally {
                this.saving = false
            }
        },
    },
}
</script>

<style scoped>
.th-people-profile {
    max-width: 860px; margin: 0 0 32px; padding: 0;
}
.th-people-profile__heading { margin-bottom: 16px; }
.th-people-profile__subtitle { color: var(--color-text-maxcontrast); margin: 4px 0 0; }
.th-people-profile h2 { font-size: var(--th-font-heading); margin-bottom: 4px; }
.th-people-profile__hint, .th-people-profile small { color: var(--color-text-maxcontrast); }
.th-people-profile__hint { margin: 0 0 16px; line-height: 1.5; }
.th-people-profile__form { display: flex; flex-direction: column; gap: 18px; }
.th-people-profile__row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.th-people-profile__field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.th-people-profile__field label, .th-people-profile legend { font-weight: 600; }
.th-people-profile__field input, .th-people-profile__field textarea {
    box-sizing: border-box; width: 100%; max-width: 100%; padding: 8px 10px;
    background: var(--color-main-background); color: var(--color-main-text);
    border: 1px solid var(--color-border-maxcontrast); border-radius: var(--border-radius-large);
    font: inherit;
}
.th-people-profile__contact { padding: 0; border: 0; margin: 0; display: flex; flex-direction: column; gap: 4px; }
.th-people-profile__contact legend { font-weight: 600; margin-bottom: 7px; }
.th-people-profile__readonly { display: block; padding: 10px 12px; min-height: 40px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); background: var(--color-background-dark); color: var(--color-main-text); }
.th-people-profile__privacy { border: 0; padding: 0; margin: 0; display: flex; flex-wrap: wrap; gap: 10px 16px; }
.th-people-profile__privacy legend { margin-bottom: 8px; }
.th-people-profile__privacy { display: flex; flex-direction: column; }
.th-people-profile__error { color: var(--color-text-error); }
.th-people-profile__success { color: var(--color-success); }
@media (max-width: 600px) { .th-people-profile__row { grid-template-columns: minmax(0, 1fr); } }
</style>
