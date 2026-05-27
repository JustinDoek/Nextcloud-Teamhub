<template>
    <NcModal
        :name="t('teamhub', 'Manage Team Links')"
        @close="$emit('close')">
        <div class="links-modal">
            <!-- Existing links -->
            <div v-if="webLinks.length" class="links-modal__existing">
                <h3>{{ t('teamhub', 'Current links') }}</h3>
                <ul class="links-list">
                    <li v-for="link in webLinks" :key="link.id" class="links-list__item">
                        <Web v-if="isNcRelativeUrl(link.url)" :size="16" />
                        <LinkVariant v-else :size="16" />
                        <div class="links-list__info">
                            <span class="links-list__title">{{ link.title }}</span>
                            <!-- NC-relative links: show path with iframe badge, no clickable href -->
                            <span v-if="isNcRelativeUrl(link.url)" class="links-list__url links-list__url--nc">
                                {{ link.url }}
                                <span class="links-list__nc-badge">{{ t('teamhub', 'iframe') }}</span>
                            </span>
                            <a v-else :href="link.url" target="_blank" class="links-list__url">{{ link.url }}</a>
                        </div>
                        <NcButton
                            variant="tertiary"
                            :aria-label="t('teamhub', 'Delete link')"
                            @click="remove(link.id)">
                            <template #icon><Delete :size="18" /></template>
                        </NcButton>
                    </li>
                </ul>
            </div>

            <NcEmptyContent
                v-else
                :name="t('teamhub', 'No links yet')"
                :description="t('teamhub', 'Add your first link below')">
                <template #icon><LinkVariant :size="48" /></template>
            </NcEmptyContent>

            <!-- Add link form -->
            <div class="links-modal__add">
                <h3>{{ t('teamhub', 'Add new link') }}</h3>
                <div class="links-modal__fields">
                    <NcTextField
                        v-model="newTitle"
                        :label="t('teamhub', 'Title')"
                        :placeholder="t('teamhub', 'e.g. Project Wiki')" />
                    <NcTextField
                        v-model="newUrl"
                        :label="t('teamhub', 'URL')"
                        :placeholder="t('teamhub', 'https://… or apps/collectives/…')"
                        :error="!!urlError"
                        :helper-text="urlError || t('teamhub', 'Use https://… for external links or apps/… for Nextcloud apps (opens in iframe)')"
                        @input="validateUrl" />
                </div>
                <NcButton
                    variant="primary"
                    :disabled="!newTitle.trim() || !!urlError || !newUrl.trim() || saving"
                    @click="save">
                    <template #icon>
                        <NcLoadingIcon v-if="saving" :size="20" />
                        <Plus v-else :size="20" />
                    </template>
                    {{ t('teamhub', 'Save Link') }}
                </NcButton>
            </div>
        </div>
    </NcModal>
</template>

<script>
import { mapState, mapActions } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import {
    NcModal,
    NcButton,
    NcTextField,
    NcEmptyContent,
    NcLoadingIcon,
} from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Web from 'vue-material-design-icons/Web.vue'

export default {
    name: 'ManageLinksModal',
    components: { NcModal, NcButton, NcTextField, NcEmptyContent, NcLoadingIcon, Delete, LinkVariant, Plus, Web },
    emits: ['close'],
    data() {
        return {
            newTitle: '',
            newUrl: '',
            urlError: '',
            saving: false,
        }
    },
    computed: {
        ...mapState(['webLinks']),
    },
    methods: {
        t,
        ...mapActions(['saveWebLink', 'deleteWebLink']),

        /** Mirrors WebLinkService::normaliseUrl — true when URL opens in iframe. */
        isNcRelativeUrl(url) {
            if (!url) return false
            return url.startsWith('/apps/') || url.startsWith('/index.php/')
        },
        validateUrl() {
            const v = this.newUrl.trim()
            if (!v) { this.urlError = ''; return }
            const isExternal  = v.startsWith('http://') || v.startsWith('https://')
            const isNcRelative = v.startsWith('/apps/') || v.startsWith('apps/')
                || v.startsWith('/index.php/') || v.startsWith('index.php/')
            if (!isExternal && !isNcRelative) {
                // TRANSLATORS: validation error shown below the URL input in the manage links modal
                this.urlError = t('teamhub', 'URL must start with https://, http://, or apps/')
            } else {
                this.urlError = ''
            }
        },
        async save() {
            this.validateUrl()
            if (this.urlError || !this.newTitle.trim() || !this.newUrl.trim()) return
            this.saving = true
            try {
                await this.saveWebLink({ title: this.newTitle.trim(), url: this.newUrl.trim() })
                showSuccess(t('teamhub', 'Link saved'))
                this.newTitle = ''
                this.newUrl = ''
            } catch (e) {
                showError(t('teamhub', 'Failed to save link'))
            } finally {
                this.saving = false
            }
        },
        async remove(id) {
            try {
                await this.deleteWebLink(id)
                showSuccess(t('teamhub', 'Link deleted'))
            } catch (e) {
                showError(t('teamhub', 'Failed to delete link'))
            }
        },
    },
}
</script>

<style scoped>
.links-modal {
    padding: 20px;
    min-width: 420px;
    display: flex;
    flex-direction: column;
    gap: 20px;
}

/*
 * On narrow viewports the 420px min-width forces horizontal overflow.
 * Drop it so NcModal can scale to the available width.
 */
@media (max-width: 768px) {
    .links-modal {
        min-width: 0;
        padding: 16px;
    }
}

.links-modal h3 {
    font-size: 14px;
    font-weight: 600;
    margin: 0 0 10px;
}

.links-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.links-list__item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px;
    border-radius: var(--border-radius);
    border: 1px solid var(--color-border);
}

.links-list__info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.links-list__title { font-size: 13px; font-weight: 500; }

.links-list__url {
    font-size: 11px;
    color: var(--color-text-maxcontrast);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.links-list__url--nc {
    display: flex;
    align-items: center;
    gap: 6px;
}

.links-list__nc-badge {
    display: inline-block;
    padding: 1px 6px;
    font-size: 10px;
    font-weight: 600;
    border-radius: 10px;
    background: var(--color-primary-light);
    color: var(--color-primary-element);
    flex-shrink: 0;
}

.links-modal__add { border-top: 1px solid var(--color-border); padding-top: 16px; }

.links-modal__fields {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 12px;
}
</style>
