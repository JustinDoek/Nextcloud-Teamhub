<template>
    <div class="teamhub-admin">
        <h2>{{ t('teamhub', 'TeamHub Settings') }}</h2>

        <div v-if="loading" class="teamhub-admin__loading">
            <span>{{ t('teamhub', 'Loading…') }}</span>
        </div>

        <div v-else class="teamhub-admin__form">
            <!-- Wizard description -->
            <div class="teamhub-admin__field">
                <label for="wizardDescription" class="teamhub-admin__label">
                    {{ t('teamhub', 'Team wizard description') }}
                </label>
                <p class="teamhub-admin__hint">
                    {{ t('teamhub', 'This text appears at the top of the "Create new team" wizard to guide users. Supports plain text. Leave empty to show no description.') }}
                </p>
                <textarea
                    id="wizardDescription"
                    v-model="settings.wizardDescription"
                    class="teamhub-admin__textarea"
                    rows="4"
                    :placeholder="t('teamhub', 'e.g. Fill in the details below to create a new team. All fields can be changed later.')" />
            </div>

            <div class="teamhub-admin__actions">
                <button
                    class="button-vue button-vue--vue-primary"
                    :disabled="saving"
                    @click="save">
                    {{ saving ? t('teamhub', 'Saving…') : t('teamhub', 'Save settings') }}
                </button>
                <span v-if="saved" class="teamhub-admin__saved">✓ {{ t('teamhub', 'Settings saved') }}</span>
                <span v-if="error" class="teamhub-admin__error">{{ error }}</span>
            </div>
        </div>
    </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
    name: 'AdminSettings',
    data() {
        return {
            loading: true,
            saving: false,
            saved: false,
            error: null,
            settings: {
                wizardDescription: '',
            },
        }
    },
    mounted() {
        this.load()
    },
    methods: {
        t: window.t || ((app, str) => str),

        async load() {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/admin/settings'))
                this.settings = { ...this.settings, ...data }
            } catch (e) {
                this.error = 'Failed to load settings'
            } finally {
                this.loading = false
            }
        },

        async save() {
            this.saving = true
            this.saved = false
            this.error = null
            try {
                await axios.post(generateUrl('/apps/teamhub/api/v1/admin/settings'), this.settings)
                this.saved = true
                setTimeout(() => { this.saved = false }, 3000)
            } catch (e) {
                this.error = 'Failed to save settings'
            } finally {
                this.saving = false
            }
        },
    },
}
</script>

<style scoped>
.teamhub-admin {
    max-width: 600px;
    padding: 20px 30px;
}

.teamhub-admin h2 {
    font-size: 20px;
    font-weight: 700;
    margin: 0 0 24px;
}

.teamhub-admin__loading {
    color: var(--color-text-maxcontrast);
}

.teamhub-admin__field {
    margin-bottom: 24px;
}

.teamhub-admin__label {
    display: block;
    font-weight: 600;
    margin-bottom: 4px;
    font-size: 14px;
}

.teamhub-admin__hint {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    margin: 0 0 8px;
    line-height: 1.5;
}

.teamhub-admin__textarea {
    width: 100%;
    border: 1px solid var(--color-border-dark);
    border-radius: var(--border-radius-large);
    padding: 10px 12px;
    font-size: 14px;
    resize: vertical;
    background: var(--color-main-background);
    color: var(--color-main-text);
    box-sizing: border-box;
}

.teamhub-admin__textarea:focus {
    outline: none;
    border-color: var(--color-primary-element);
    box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

.teamhub-admin__actions {
    display: flex;
    align-items: center;
    gap: 16px;
}

.teamhub-admin__saved {
    color: var(--color-success);
    font-size: 14px;
    font-weight: 500;
}

.teamhub-admin__error {
    color: var(--color-error);
    font-size: 14px;
}
</style>
