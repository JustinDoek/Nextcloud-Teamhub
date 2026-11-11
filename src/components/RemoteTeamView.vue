<template>
    <div class="th-remote-team">

        <!-- ── Loading ──────────────────────────────────────────────────── -->
        <div v-if="loading" class="th-remote-team__loading">
            <NcLoadingIcon :size="ICON_LARGE" :aria-label="t('teamhub', 'Loading')" />
        </div>

        <!-- ── Error ────────────────────────────────────────────────────── -->
        <!-- A recoverable error is a note card with a retry, never an empty
             state (`/ui-standards`). -->
        <NcNoteCard v-else-if="error" type="error">
            <p>{{ error }}</p>
            <NcButton variant="secondary" @click="load">
                {{ t('teamhub', 'Retry') }}
            </NcButton>
        </NcNoteCard>

        <template v-else-if="team">
            <!-- ── Header ───────────────────────────────────────────────── -->
            <header class="th-remote-team__head">
                <h2 class="th-remote-team__title">
                    <Earth :size="ICON_BODY" aria-hidden="true" />
                    <span>{{ team.name }}</span>
                </h2>
                <p class="th-remote-team__owner">{{ ownerLine }}</p>
                <p v-if="team.description" class="th-remote-team__description">
                    {{ team.description }}
                </p>
            </header>

            <!-- ── What this instance has, and what it has not ──────────── -->
            <!-- Said once, plainly, instead of drawing seven module tabs that
                 would each be empty: the team's own data lives in TeamHub's
                 tables on the owning server, and this server holds none of
                 it. The sentence is the honest version of the shell until the
                 TeamHub-to-TeamHub proxy exists. -->
            <NcNoteCard type="info">
                <p>{{ explanation }}</p>
            </NcNoteCard>

            <!-- ── The roster Nextcloud Teams synced here ───────────────── -->
            <section class="th-remote-team__people">
                <h3 class="th-remote-team__subtitle">
                    {{ t('teamhub', 'Members') }}
                    <span class="th-remote-team__count">{{ memberCountLabel }}</span>
                </h3>

                <NcEmptyContent
                    v-if="!members.length"
                    :name="t('teamhub', 'No members yet')">
                    <template #icon>
                        <AccountGroup :size="ICON_HERO" />
                    </template>
                </NcEmptyContent>

                <ul v-else class="th-remote-team__list">
                    <li
                        v-for="member in members"
                        :key="member.userId + '@' + (member.instance || '')"
                        class="th-remote-team__row">
                        <!-- PersonRow is this app's one way of drawing a
                             person: a local account gets its avatar, somebody
                             on another server gets the globe and a line
                             saying so. The role rides in its slot as a chip,
                             which keeps the two lines for the name. -->
                        <PersonRow
                            :id="member.userId"
                            :display-name="member.displayName"
                            :type="member.is_remote ? 'federated' : 'user'">
                            <NcChip
                                no-close
                                variant="tertiary"
                                :text="roleLabel(member.role)" />
                        </PersonRow>
                    </li>
                </ul>
            </section>
        </template>
    </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcButton, NcChip, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Earth from 'vue-material-design-icons/Earth.vue'
import PersonRow from './PersonRow.vue'
import { ICON_BODY, ICON_LARGE, ICON_HERO } from '../constants/uiTokens.js'

/**
 * A team this server does not own (v4.14.15, DESIGN §2.256).
 *
 * Rendered by App.vue in place of `TeamView` whenever the selected sidebar
 * row carries `is_remote`. It mounts no module component and makes exactly one
 * request, `GET /api/v1/federated-teams/{teamId}`, which reads rows Circles
 * already synced onto this instance. Nothing here asks the owning server
 * anything, and nothing here writes.
 *
 * That is the whole point of a separate view rather than a flag inside
 * `TeamView`: every module in there reads and writes TeamHub tables keyed by
 * `team_id` on *this* instance, and for a team owned elsewhere those tables
 * are empty and must stay that way. A tab that posted a message here would
 * write something the owning server, and therefore the rest of the team, will
 * never see. The members-only shell is honest; a greyed-out copy of the full
 * view would not be.
 *
 * Deliberately no "open it on that server" link. The owning instance is known
 * by hostname only, and Circles has no idea whether Nextcloud sits at the web
 * root there or under a path, so every URL this view could build would be a
 * guess, and wrong on exactly the kind of install that has one (the test pair
 * this was built against has Nextcloud under `/nextcloud` on one side).
 */
export default {
    name: 'RemoteTeamView',

    components: {
        NcButton, NcChip, NcEmptyContent, NcLoadingIcon, NcNoteCard,
        AccountGroup, Earth,
        PersonRow,
    },

    props: {
        /** The synced copy's circle id, the same id the owning server uses. */
        teamId: {
            type: String,
            required: true,
        },
    },

    data() {
        return {
            team: null,
            loading: true,
            error: '',
            ICON_BODY,
            ICON_LARGE,
            ICON_HERO,
        }
    },

    computed: {
        members() {
            return this.team?.members || []
        },

        memberCountLabel() {
            const count = this.members.length
            return n('teamhub', '{n} member', '{n} members', count, { n: count })
        },

        ownerLine() {
            // TRANSLATORS: {instance} is the hostname of the Nextcloud server
            // that owns this team, for example dev.example.com
            return t('teamhub', 'Owned by {instance}', { instance: this.team?.remote_instance || '' })
        },

        explanation() {
            return t(
                'teamhub',
                'This team belongs to {instance}. Its messages, files, decisions and other modules stay on that server, so TeamHub here shows who is in the team. Open the team on {instance} to work in it.',
                { instance: this.team?.remote_instance || '' },
            )
        },
    },

    watch: {
        teamId() {
            this.load()
        },
    },

    mounted() {
        this.load()
    },

    methods: {
        t,
        n,

        async load() {
            this.loading = true
            this.error = ''
            try {
                const { data } = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/federated-teams/{teamId}', { teamId: this.teamId }),
                )
                this.team = data?.team || null
                if (!this.team) {
                    this.error = t('teamhub', 'Could not load the team. Try again.')
                }
            } catch (e) {
                this.error = t('teamhub', 'Could not load the team. Try again.')
            } finally {
                this.loading = false
            }
        },

        /**
         * The role names the rest of the app uses. A switch rather than a
         * built key, so every label is a literal a translator can find.
         *
         * Owner and admin both read *Team admin*, as on every surface inside a
         * team since 4.13.56 (DESIGN §2.223): the Circles owner level still
         * exists, but no screen inside a team names it.
         *
         * @param {string} role one of owner | admin | moderator | member
         * @return {string} the translated label
         */
        roleLabel(role) {
            switch (role) {
            case 'owner':
            case 'admin':
                return t('teamhub', 'Team admin')
            case 'moderator':
                return t('teamhub', 'Moderator')
            default:
                return t('teamhub', 'Member')
            }
        },
    },
}
</script>

<style scoped>
.th-remote-team {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-lg);
    padding: var(--th-space-lg);
    max-width: 760px;
}

.th-remote-team__loading {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--th-space-xxl);
}

.th-remote-team__title {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
    margin: 0;
    font-size: var(--th-font-title);
    font-weight: var(--th-font-weight-bold);
}

.th-remote-team__owner {
    margin: var(--th-space-xs) 0 0;
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
}

.th-remote-team__description {
    margin: var(--th-space-sm) 0 0;
    font-size: var(--th-font-body);
}

.th-remote-team__subtitle {
    display: flex;
    align-items: baseline;
    gap: var(--th-space-sm);
    margin: 0 0 var(--th-space-sm);
    font-size: var(--th-font-heading);
    font-weight: var(--th-font-weight-bold);
}

.th-remote-team__count {
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
    font-weight: var(--th-font-weight-regular);
}

.th-remote-team__list {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    list-style: none;
    margin: 0;
    padding: 0;
}

.th-remote-team__row {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
    padding: var(--th-space-xs) var(--th-space-sm);
    border-radius: var(--th-radius-control);
    background: var(--color-background-hover);
}
</style>
