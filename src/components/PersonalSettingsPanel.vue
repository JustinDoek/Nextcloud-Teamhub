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

        <!-- v4.13.5 — the viewer's own OpenProject account. Hidden entirely
             unless an administrator has configured a connection: a member
             on an instance with no OpenProject has no use for a section
             explaining one, and CLAUDE.md's rule is that what somebody
             cannot act on is hidden, not shown disabled. -->
        <!-- v4.13.21 — `id` so every Connect button in the app can land on
             this section rather than at the top of the page; `openProjectUrl`
             in src/lib/openProject.js builds that link. -->
        <section v-if="showOpenProject" id="openproject" class="th-personal__section">
            <h2 class="th-personal__title">{{ t('teamhub', 'OpenProject') }}</h2>

            <!-- v4.13.21 — this instance's OpenProject runs through the
                 official integration app, which owns the tokens. A Connect
                 button here would write a credential TeamHub's own transport
                 is not using, so the section says where the account actually
                 lives instead. The alternative — hiding the section — is what
                 produced the bug: every Connect button in the app pointed at
                 this page and this page had nothing on it. -->
            <template v-if="opViaIntegrationApp">
                <p class="th-personal__desc">
                    {{ t('teamhub', 'This instance connects to OpenProject through the OpenProject integration app, which is where your account is linked.') }}
                </p>
                <div class="th-personal__actions">
                    <NcButton variant="secondary" :href="integrationAppSettingsUrl()">
                        {{ t('teamhub', 'Open OpenProject settings') }}
                    </NcButton>
                </div>
            </template>

            <template v-else>
                <p class="th-personal__desc">
                    {{ opConn.connected
                        ? t('teamhub', 'Your OpenProject account is connected. TeamHub shows you only what OpenProject lets you see.')
                        : t('teamhub', 'Connect your OpenProject account to see your projects and work packages in TeamHub.') }}
                </p>

                <p v-if="opConn.connected && opConn.openProjectUser" class="th-personal__desc">
                    {{ t('teamhub', 'Connected as {name}', { name: opConn.openProjectUser }) }}
                </p>

                <div class="th-personal__actions">
                    <NcButton v-if="!opConn.connected" variant="primary" :disabled="opBusy" @click="connectOpenProject">
                        {{ t('teamhub', 'Connect OpenProject account') }}
                    </NcButton>
                    <NcButton v-else :disabled="opBusy" @click="disconnectOpenProject">
                        {{ t('teamhub', 'Disconnect') }}
                    </NcButton>
                </div>
            </template>

            <p v-if="opMessage" class="th-personal__note" role="status">{{ opMessage }}</p>
            <p v-if="opError" class="th-personal__error" role="alert">{{ opError }}</p>
        </section>

        <!-- People contains the former Presence schedules and the new optional collaboration profile. -->
        <template v-if="presenceModuleEnabled">
            <PeopleProfilePanel />
            <MyPresencePanel />
            <!-- v4.13.71 — when this person can be put on a shift. Shown with
                 the People module rather than behind the Shifts licence: it is
                 a statement about their own week, it costs nothing to keep,
                 and an instance that licenses Shifts later finds its people
                 have already answered. -->
            <ShiftAvailabilityPanel />
        </template>
    </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { integrationAppSettingsUrl } from '../lib/openProject.js'
import MyPresencePanel from './MyPresencePanel.vue'
import PeopleProfilePanel from './PeopleProfilePanel.vue'
import ShiftAvailabilityPanel from './ShiftAvailabilityPanel.vue'

export default {
    name: 'PersonalSettingsPanel',
    components: {
        NcButton, NcCheckboxRadioSwitch, MyPresencePanel, PeopleProfilePanel,
        ShiftAvailabilityPanel,
    },
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
            // v4.13.5 — the viewer's own OpenProject connection.
            opConn: {
                configured: false,
                connected: false,
                openProjectUser: null,
                // v4.13.21 — 'direct' | 'integration_app' | null. See
                // showOpenProject.
                transport: null,
            },
            opBusy: false,
            opMessage: '',
            opError: '',
        }
    },
    computed: {
        /**
         * v4.13.21 — whether this instance's OpenProject runs through the
         * official integration app rather than TeamHub's own transport.
         */
        opViaIntegrationApp() {
            return this.opConn.transport === 'integration_app'
        },

        /**
         * Show the section when there is anything true to say about this
         * viewer's OpenProject account.
         *
         * `configured` alone was the old condition, and it answers a narrower
         * question than it looks: it is about **TeamHub's own** OAuth client,
         * so it is false on a perfectly working instance whose OpenProject
         * goes through the official app. That is what produced the v4.13.21
         * bug from the other end — every Connect button in the app was sent
         * to this page, and on exactly those instances this page had nothing
         * on it.
         *
         * Still hidden when neither is true: a member on an instance with no
         * OpenProject at all has no use for a section explaining one, and
         * CLAUDE.md's rule is that what somebody cannot act on is hidden.
         */
        showOpenProject() {
            return this.opConn.configured || this.opViaIntegrationApp
        },
    },
    mounted() {
        this.loadOpenProjectConnection()
        this.readOpenProjectCallbackResult()
    },
    methods: {
        t,
        n,
        integrationAppSettingsUrl,

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
                this.saveError = t('teamhub', 'Could not save the setting. Try again.')
            } finally {
                this.saving = false
            }
        },

        // ── OpenProject (v4.13.5) ────────────────────────────────────────

        async loadOpenProjectConnection() {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/openproject/connection'))
                this.opConn.configured = !!data?.configured && !!data?.methodSupported
                this.opConn.connected = !!data?.connected
                this.opConn.openProjectUser = data?.openProjectUser || null
                this.opConn.transport = data?.transport || null
            } catch (error) {
                // A 403 here is the ordinary case on an instance where the
                // OpenProject module is off — the section stays hidden and
                // nothing is said, which is the right outcome.
                this.opConn.configured = false
                this.opConn.transport = null
            }
        },

        /**
         * The callback redirects back here with `?openprojectResult=…`.
         * Read it, say what happened, and strip it from the URL so a
         * reload does not repeat a stale message.
         */
        readOpenProjectCallbackResult() {
            const params = new URLSearchParams(window.location.search)
            const result = params.get('openprojectResult')
            if (!result) {
                return
            }
            if (result === 'connected') {
                this.opMessage = t('teamhub', 'Your OpenProject account is connected.')
            } else if (result === 'declined') {
                this.opError = t('teamhub', 'The connection was not approved in OpenProject.')
            } else if (result === 'state_mismatch') {
                this.opError = t('teamhub', 'That took too long, or the response did not match. Try connecting again.')
            } else {
                this.opError = t('teamhub', 'OpenProject did not complete the connection. Ask your administrator to check the OpenProject connection settings.')
            }
            params.delete('openprojectResult')
            const query = params.toString()
            window.history.replaceState({}, '', window.location.pathname + (query ? '?' + query : '') + window.location.hash)
        },

        async connectOpenProject() {
            this.opBusy = true
            this.opError = ''
            this.opMessage = ''
            try {
                const { data } = await axios.post(generateUrl('/apps/teamhub/api/v1/openproject/connection'))
                if (data?.authorizeUrl) {
                    // A full navigation, not a popup: the authorization
                    // happens on the OpenProject origin and comes back to
                    // this page through the callback.
                    window.location.href = data.authorizeUrl
                    return
                }
                this.opError = t('teamhub', 'OpenProject did not complete the connection. Ask your administrator to check the OpenProject connection settings.')
            } catch (error) {
                this.opError = error?.response?.data?.error
                    || t('teamhub', 'OpenProject did not complete the connection. Ask your administrator to check the OpenProject connection settings.')
            } finally {
                this.opBusy = false
            }
        },

        async disconnectOpenProject() {
            this.opBusy = true
            this.opError = ''
            this.opMessage = ''
            try {
                await axios.delete(generateUrl('/apps/teamhub/api/v1/openproject/connection'))
                this.opConn.connected = false
                this.opConn.openProjectUser = null
                this.opMessage = t('teamhub', 'Your OpenProject account is disconnected.')
            } catch (error) {
                this.opError = t('teamhub', 'Could not disconnect. Try again.')
            } finally {
                this.opBusy = false
            }
        },
    },
}
</script>

<style scoped>
/* ── Wrapper ─────────────────────────────────────────────────────────────── */
/* v4.13.74 — the same left edge the admin panel took in v4.13.58, for the same
   reason: Nextcloud positions its navigation-toggle button over the top-left
   of the settings content whenever the settings navigation is closed, and this
   page carried no inline padding at all, so the first heading sat under it.
   The value is copied from `.teamhub-admin` deliberately rather than guessed,
   so Settings → Personal → TeamHub and Settings → Administration → TeamHub
   line up on the same edge when somebody moves between them. */
.th-personal {
    display: flex;
    flex-direction: column;
    box-sizing: border-box;
    padding-inline: calc(var(--default-clickable-area) + var(--th-space-xl)) var(--th-space-lg);
}

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
    margin: 8px 0 0;
}

.th-personal__error {
    font-size: var(--th-font-meta);
    color: var(--color-text-error);
    margin: 8px 0 0;
}

/* v4.13.5 — the OpenProject connect/disconnect button row. */
.th-personal__actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 8px;
}
</style>
