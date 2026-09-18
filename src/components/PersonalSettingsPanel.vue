<template>
    <div class="th-personal">
        <!-- v4.4.12 — Getting started. Always rendered, independent of the
             Presence module, so Settings → Personal → TeamHub is never an
             empty page. -->
        <section class="th-personal__section">
            <h2 class="th-personal__title">{{ t('teamhub', 'Getting started') }}</h2>
            <p class="th-personal__desc">
                {{ t('teamhub', 'Show a short reminder in the TeamHub sidebar pointing at the help and documentation button.') }}
            </p>

            <NcCheckboxRadioSwitch
                :model-value="gettingStartedHint"
                :disabled="saving"
                type="switch"
                @update:model-value="onToggle">
                {{ t('teamhub', 'Getting started') }}
            </NcCheckboxRadioSwitch>

            <p v-if="saveError" class="th-personal__error" role="alert">
                {{ saveError }}
            </p>
            <!-- The reminder only exists on instances without a licence, so
                 say so rather than letting a licensed user wonder why the
                 switch appears to do nothing. -->
            <p class="th-personal__note">
                {{ t('teamhub', 'The reminder only appears on instances without a TeamHub license.') }}
            </p>
        </section>

        <MyPresencePanel v-if="presenceModuleEnabled" />
    </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import MyPresencePanel from './MyPresencePanel.vue'

export default {
    name: 'PersonalSettingsPanel',
    components: { NcCheckboxRadioSwitch, MyPresencePanel },
    props: {
        presenceModuleEnabled: {
            type: Boolean,
            default: false,
        },
        initialGettingStartedHint: {
            type: Boolean,
            default: true,
        },
    },
    data() {
        return {
            // Seeded from the template's data attribute so the switch renders
            // in its correct position immediately — no GET on mount, no flash
            // of the default-on state for a user who turned it off.
            gettingStartedHint: this.initialGettingStartedHint,
            saving: false,
            saveError: '',
        }
    },
    methods: {
        t,
        n,

        async onToggle(value) {
            const previous = this.gettingStartedHint
            this.gettingStartedHint = value
            this.saving = true
            this.saveError = ''
            try {
                await axios.put(generateUrl('/apps/teamhub/api/v1/preferences'), {
                    gettingStartedHint: value,
                })
            } catch (error) {
                // Revert so the switch never shows a state the server rejected.
                this.gettingStartedHint = previous
                this.saveError = t('teamhub', 'Could not save the setting. Please try again.')
            } finally {
                this.saving = false
            }
        },
    },
}
</script>

<style scoped>
.th-personal__section {
    margin-bottom: 24px;
    max-width: 700px;
}

.th-personal__title {
    font-size: var(--th-font-heading);
    font-weight: var(--th-font-weight-bold);
    margin: 0 0 4px;
}

.th-personal__desc {
    font-size: var(--th-font-body);
    color: var(--color-text-maxcontrast);
    line-height: var(--th-line-height-body);
    margin: 0 0 8px;
}

.th-personal__note {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    margin: 6px 0 0;
}

.th-personal__error {
    font-size: var(--th-font-meta);
    color: var(--color-error-text);
    margin: 6px 0 0;
}
</style>
