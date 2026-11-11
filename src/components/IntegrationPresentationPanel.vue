<template>
	<div class="th-present">

		<div v-if="loading" class="th-present__loading">
			<NcLoadingIcon :size="ICON_BODY" />
			<span>{{ t('teamhub', 'Loading integrations') }}</span>
		</div>

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
			<template #action>
				<NcButton variant="secondary" @click="load">
					{{ t('teamhub', 'Try again') }}
				</NcButton>
			</template>
		</NcNoteCard>

		<!-- The empty state says who decides, because the honest answer to
		     "why is there nothing here" is "an administrator has not put one
		     in your template", and a team administrator cannot fix it
		     themselves. -->
		<NcEmptyContent
			v-else-if="packages.length === 0"
			:name="t('teamhub', 'No integrations in this team')"
			:description="t('teamhub', 'A Nextcloud administrator decides which integrations a team template includes. When one is added, its widgets and tabs appear here and you choose what to show.')">
			<template #icon>
				<PuzzleIcon :size="ICON_HERO" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<p class="th-present__lead">
				{{ t('teamhub', 'These integrations come with this team\'s template. Everything is shown by default. Hide what this team does not need. Hiding is a layout choice: it does not switch the integration off and does not affect My Work.') }}
			</p>

			<div class="th-present__status" role="status" aria-live="polite">
				<p v-if="notice" class="th-present__notice">{{ notice }}</p>
			</div>

			<section
				v-for="pkg in packages"
				:key="pkg.pkg_id"
				class="th-present__package">

				<header class="th-present__package-head">
					<img
						v-if="!iconFailed[pkg.app_id]"
						:src="appIconUrl(pkg.app_id)"
						:alt="''"
						class="th-present__package-icon"
						aria-hidden="true"
						@error="iconFailed[pkg.app_id] = true">
					<PuzzleIcon v-else :size="ICON_BODY" class="th-present__package-icon" aria-hidden="true" />

					<h4 class="th-present__package-title">{{ pkg.title }}</h4>
				</header>

				<p v-if="pkg.description" class="th-present__package-desc">
					{{ pkg.description }}
				</p>

				<!-- Widgets and team tabs are separate groups with separate
				     ordering: they are two surfaces, and one sequence across
				     both would make moving a widget move a tab. -->
				<div v-if="pkg.widgets.length" class="th-present__group">
					<h5 class="th-present__group-title">{{ t('teamhub', 'Widgets') }}</h5>
					<ul class="th-present__caps">
						<li
							v-for="(capability, index) in pkg.widgets"
							:key="capability.cap_id"
							class="th-present__cap">
							<span class="th-present__cap-text">
								<span class="th-present__cap-title">{{ capability.title }}</span>
								<span v-if="capability.description" class="th-present__cap-desc">
									{{ capability.description }}
								</span>
							</span>

							<span class="th-present__cap-controls">
								<NcButton
									v-if="isTeamAdmin"
									variant="tertiary"
									size="small"
									:disabled="index === 0 || busy"
									:aria-label="t('teamhub', 'Move {title} up', { title: capability.title })"
									:title="t('teamhub', 'Move {title} up', { title: capability.title })"
									@click="move(pkg, 'widgets', index, -1)">
									<template #icon>
										<ArrowUpIcon :size="ICON_BODY" />
									</template>
								</NcButton>
								<NcButton
									v-if="isTeamAdmin"
									variant="tertiary"
									size="small"
									:disabled="index === pkg.widgets.length - 1 || busy"
									:aria-label="t('teamhub', 'Move {title} down', { title: capability.title })"
									:title="t('teamhub', 'Move {title} down', { title: capability.title })"
									@click="move(pkg, 'widgets', index, 1)">
									<template #icon>
										<ArrowDownIcon :size="ICON_BODY" />
									</template>
								</NcButton>

								<NcCheckboxRadioSwitch
									v-if="isTeamAdmin"
									type="switch"
									:model-value="capability.shown_for_team"
									:disabled="busy"
									:aria-label="capability.shown_for_team
										? t('teamhub', 'Hide {title}', { title: capability.title })
										: t('teamhub', 'Show {title}', { title: capability.title })"
									@update:model-value="setShown(capability, $event)">
									{{ capability.shown_for_team ? t('teamhub', 'Shown') : t('teamhub', 'Hidden') }}
								</NcCheckboxRadioSwitch>

								<NcChip
									v-else
									no-close
									:variant="capability.shown_for_team ? 'success' : 'tertiary'"
									:text="capability.shown_for_team ? t('teamhub', 'Shown') : t('teamhub', 'Hidden')" />
							</span>
						</li>
					</ul>
				</div>

				<div v-if="pkg.menu_items.length" class="th-present__group">
					<h5 class="th-present__group-title">{{ t('teamhub', 'Team tabs') }}</h5>
					<ul class="th-present__caps">
						<li
							v-for="(capability, index) in pkg.menu_items"
							:key="capability.cap_id"
							class="th-present__cap">
							<span class="th-present__cap-text">
								<span class="th-present__cap-title">{{ capability.title }}</span>
								<span v-if="capability.description" class="th-present__cap-desc">
									{{ capability.description }}
								</span>
							</span>

							<span class="th-present__cap-controls">
								<NcButton
									v-if="isTeamAdmin"
									variant="tertiary"
									size="small"
									:disabled="index === 0 || busy"
									:aria-label="t('teamhub', 'Move {title} up', { title: capability.title })"
									:title="t('teamhub', 'Move {title} up', { title: capability.title })"
									@click="move(pkg, 'menu_items', index, -1)">
									<template #icon>
										<ArrowUpIcon :size="ICON_BODY" />
									</template>
								</NcButton>
								<NcButton
									v-if="isTeamAdmin"
									variant="tertiary"
									size="small"
									:disabled="index === pkg.menu_items.length - 1 || busy"
									:aria-label="t('teamhub', 'Move {title} down', { title: capability.title })"
									:title="t('teamhub', 'Move {title} down', { title: capability.title })"
									@click="move(pkg, 'menu_items', index, 1)">
									<template #icon>
										<ArrowDownIcon :size="ICON_BODY" />
									</template>
								</NcButton>

								<NcCheckboxRadioSwitch
									v-if="isTeamAdmin"
									type="switch"
									:model-value="capability.shown_for_team"
									:disabled="busy"
									:aria-label="capability.shown_for_team
										? t('teamhub', 'Hide {title}', { title: capability.title })
										: t('teamhub', 'Show {title}', { title: capability.title })"
									@update:model-value="setShown(capability, $event)">
									{{ capability.shown_for_team ? t('teamhub', 'Shown') : t('teamhub', 'Hidden') }}
								</NcCheckboxRadioSwitch>

								<NcChip
									v-else
									no-close
									:variant="capability.shown_for_team ? 'success' : 'tertiary'"
									:text="capability.shown_for_team ? t('teamhub', 'Shown') : t('teamhub', 'Hidden')" />
							</span>
						</li>
					</ul>
				</div>

				<!-- What else the integration does, with no control beside it.
				     A team administrator should be able to see that it also
				     feeds My Work — it is the honest answer to "what is this
				     doing" — without being handed a switch for something they
				     do not decide. -->
				<p v-if="pkg.informational.length" class="th-present__also">
					{{ t('teamhub', 'Also contributes to: {capabilities}', {
						capabilities: pkg.informational.map(capability => capability.title).join(', '),
					}) }}
				</p>
			</section>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

import ArrowDownIcon from 'vue-material-design-icons/ArrowDown.vue'
import ArrowUpIcon from 'vue-material-design-icons/ArrowUp.vue'
import PuzzleIcon from 'vue-material-design-icons/Puzzle.vue'

import { ICON_BODY, ICON_HERO } from '../constants/uiTokens.js'

/**
 * Manage team → Integrations, as a presentation manager (v4.13.23).
 *
 * It used to be an activation screen: a team administrator switched an
 * integration on for their team, and that was the only governance there was.
 * It is now the fourth and last of TeamHub's governance levels — *shown or
 * hidden* — and the three above it belong to a Nextcloud administrator.
 *
 * So there is deliberately no package-level switch here, no way to add an
 * integration the template does not include, and no control anywhere near My
 * Work. What a team administrator can do is show a widget, hide a widget,
 * show a tab, hide a tab, and order the visible ones.
 *
 * ## Reorder is two buttons, not a drag handle
 *
 * The screen it replaces used HTML5 drag-and-drop, which has no keyboard
 * path. Up and down buttons are keyboard-reachable by construction and
 * announce what they move, so the WCAG requirement is met without
 * hand-written key handling. They also make the two separate orderings
 * obvious: a widget's buttons can only ever move it among widgets.
 *
 * ## Optimistic, then reconciled
 *
 * A show, a hide and a move all repaint immediately and then persist. A
 * failure reloads from the server rather than trying to undo locally — the
 * server is the only thing that knows what the state actually is, and a
 * half-applied reorder guessed at locally is worse than a visible snap back.
 */
export default {
	name: 'IntegrationPresentationPanel',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcChip,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		ArrowDownIcon,
		ArrowUpIcon,
		PuzzleIcon,
	},

	props: {
		/** The team being managed. Only its id is used. */
		teamId: {
			type: String,
			required: true,
		},

		/**
		 * Whether the viewer holds admin level in this team.
		 *
		 * Controls are hidden rather than disabled for anybody else, which is
		 * TeamHub's rule for a role-restricted action. The server refuses them
		 * regardless — this is rendering, not the boundary.
		 */
		isTeamAdmin: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			ICON_BODY,
			ICON_HERO,

			loading: true,
			error: null,
			notice: null,
			busy: false,

			packages: [],
			iconFailed: {},
		}
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
			this.error = null
			try {
				const { data } = await axios.get(
					generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/integrations/presentation`),
				)
				this.packages = Array.isArray(data.packages) ? data.packages : []
			} catch (e) {
				this.error = e?.response?.data?.error
					|| t('teamhub', 'Could not load this team\'s integrations.')
			} finally {
				this.loading = false
			}
		},

		appIconUrl(appId) {
			return generateUrl(`/apps/${appId}/img/app.svg`)
		},

		/** Show or hide one capability. */
		async setShown(capability, shown) {
			const previous = capability.shown_for_team
			capability.shown_for_team = shown
			this.busy = true
			this.notice = null

			const action = shown ? 'show' : 'hide'
			try {
				await axios.post(
					generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/integrations/presentation/${capability.cap_id}/${action}`),
				)
				this.notice = shown
					? t('teamhub', '{title} is shown in this team.', { title: capability.title })
					: t('teamhub', '{title} is hidden in this team. The integration keeps working.', { title: capability.title })
				this.$emit('changed')
			} catch (e) {
				capability.shown_for_team = previous
				showError(e?.response?.data?.error
					|| t('teamhub', 'Could not change what this team shows.'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * Move one capability within its own group.
		 *
		 * @param {object} pkg   the package the capability belongs to
		 * @param {string} group 'widgets' or 'menu_items'
		 * @param {number} index its current position
		 * @param {number} delta -1 to move up, 1 to move down
		 */
		async move(pkg, group, index, delta) {
			const list = pkg[group]
			const target = index + delta
			if (target < 0 || target >= list.length) {
				return
			}

			const reordered = [...list]
			const [moved] = reordered.splice(index, 1)
			reordered.splice(target, 0, moved)
			pkg[group] = reordered

			this.busy = true
			this.notice = null
			try {
				await axios.put(
					generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/integrations/presentation/reorder`),
					{
						capType: group === 'widgets' ? 'widget' : 'menu_item',
						order: reordered.map(capability => capability.cap_id),
					},
				)
				this.notice = t('teamhub', 'Order saved.')
				this.$emit('changed')
			} catch (e) {
				showError(e?.response?.data?.error
					|| t('teamhub', 'Could not save the new order.'))
				// The server knows what the order actually is; a local undo of
				// a partially applied reorder would be a guess.
				await this.load()
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.th-present__loading {
	display: flex;
	align-items: center;
	gap: var(--th-space-sm);
	padding: var(--th-space-md) 0;
	color: var(--color-text-maxcontrast);
}

.th-present__lead {
	margin: 0 0 var(--th-space-md);
	max-width: 70ch;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta);
}

.th-present__status {
	min-height: var(--th-space-xl);
}

.th-present__notice {
	margin: 0 0 var(--th-space-sm);
	color: var(--color-text-success);
}

.th-present__package {
	padding: var(--th-space-md);
	margin-block-end: var(--th-space-sm);
	border: 1px solid var(--color-border);
	border-radius: var(--th-radius-card);
	background: var(--color-main-background);
}

.th-present__package-head {
	display: flex;
	align-items: center;
	gap: var(--th-space-sm);
}

.th-present__package-icon {
	width: var(--th-icon-body);
	height: var(--th-icon-body);
	flex: 0 0 auto;
}

.th-present__package-title {
	margin: 0;
	font-size: var(--th-font-heading);
	font-weight: 600;
}

.th-present__package-desc {
	margin: var(--th-space-xs) 0 0;
	font-size: var(--th-font-meta);
	color: var(--color-text-maxcontrast);
}

.th-present__group {
	margin-block-start: var(--th-space-md);
}

.th-present__group-title {
	margin: 0 0 var(--th-space-xs);
	font-size: var(--th-font-meta);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.th-present__caps {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs);
}

.th-present__cap {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--th-space-md);
	padding: var(--th-space-xs) var(--th-space-sm);
	border-radius: var(--th-radius-control);
	background: var(--color-background-hover);
}

.th-present__cap-text {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.th-present__cap-title {
	font-size: var(--th-font-body);
}

.th-present__cap-desc {
	font-size: var(--th-font-meta);
	color: var(--color-text-maxcontrast);
}

.th-present__cap-controls {
	display: flex;
	align-items: center;
	gap: var(--th-space-xs);
	flex: 0 0 auto;
}

.th-present__also {
	margin: var(--th-space-md) 0 0;
	font-size: var(--th-font-meta);
	color: var(--color-text-maxcontrast);
}

@media (max-width: 640px) {
	.th-present__cap {
		flex-direction: column;
		align-items: flex-start;
		gap: var(--th-space-xs);
	}
}
</style>
