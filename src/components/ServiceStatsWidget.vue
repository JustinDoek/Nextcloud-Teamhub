<template>
	<div class="svc-stats-w">
		<div class="svc-stats-w__periods" role="group" :aria-label="t('teamhub', 'Period')">
			<NcButton
				v-for="days in periods"
				:key="days"
				size="small"
				variant="tertiary"
				:pressed="period === days"
				@click="setPeriod(days)">
				{{ periodLabel(days) }}
			</NcButton>
		</div>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
			<template #action>
				<NcButton variant="secondary" @click="load">{{ t('teamhub', 'Try again') }}</NcButton>
			</template>
		</NcNoteCard>

		<div v-else-if="loading && !stats" class="svc-stats-w__loading">
			<NcLoadingIcon :size="ICON_LARGE" />
		</div>

		<template v-else-if="stats">
			<!-- The period's counts, then what is waiting right now, then the
			     two medians. Each figure is a <dt>/<dd> pair so a screen
			     reader hears the label with its number. -->
			<dl class="svc-stats-w__figures">
				<div v-for="figure in figures" :key="figure.key" class="svc-stats-w__figure">
					<dt class="svc-stats-w__label">{{ figure.label }}</dt>
					<!-- The number sits behind its label, in the same inverted
					     bubble the queue widget's tabs use; a duration is a
					     phrase, not a count, and stays plain text. -->
					<dd class="svc-stats-w__value">
						<span v-if="figure.text" class="svc-stats-w__duration">{{ figure.value }}</span>
						<NcCounterBubble v-else class="svc-count" :count="Number(figure.value) || 0" />
					</dd>
				</div>
			</dl>

			<section v-if="stats.services.length" class="svc-stats-w__services">
				<h3 class="svc-stats-w__heading">{{ t('teamhub', 'Per service') }}</h3>
				<table class="svc-stats-w__table">
					<thead>
						<tr>
							<th scope="col">{{ t('teamhub', 'Service') }}</th>
							<th scope="col" class="svc-stats-w__num">{{ t('teamhub', 'Received') }}</th>
							<th scope="col" class="svc-stats-w__num">{{ t('teamhub', 'Closed') }}</th>
							<th scope="col" class="svc-stats-w__num">{{ t('teamhub', 'Time to close') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="service in stats.services" :key="service.serviceKey">
							<th scope="row">{{ service.label }}</th>
							<td class="svc-stats-w__num">{{ service.received }}</td>
							<td class="svc-stats-w__num">{{ service.closed }}</td>
							<td class="svc-stats-w__num">{{ formatDuration(service.medianClose) }}</td>
						</tr>
					</tbody>
				</table>
			</section>

			<p class="svc-stats-w__note">
				{{ t('teamhub', 'Times are medians, measured from when a request arrives in the queue.') }}
			</p>
		</template>
	</div>
</template>

<script>
import { mapState } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcCounterBubble, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { STATISTICS_PERIODS, formatDuration, periodLabel } from '../constants/serviceTeams.js'
import { ICON_LARGE } from '../constants/uiTokens.js'

/**
 * The service team's request statistics (WorkflowHub phase B, v4.10.27).
 *
 * Justin, 2026-09-24: counts per period, time to first claim, time to close,
 * and the same per service — for every member of the team, because the team
 * is the desk. What each number means is written down once, on the server
 * (`ServiceDeskStatisticsService`); this widget only lays it out.
 *
 * Read when the team opens and when the period changes, never polled: the
 * numbers move slowly, and a desk that wants the latest reloads the page.
 */
export default {
	name: 'ServiceStatsWidget',

	components: { NcButton, NcCounterBubble, NcLoadingIcon, NcNoteCard },

	data() {
		return {
			ICON_LARGE,
			periods: STATISTICS_PERIODS,
			period: 30,
			stats: null,
			loading: false,
			error: '',
		}
	},

	computed: {
		...mapState(['currentTeamId']),

		/** The queue on screen as counts per bucket — what the watcher compares. */
		queueShape() {
			const svc = this.$store.state.serviceTeams
			if (!svc || svc.selectedId !== this.currentTeamId) {
				return ''
			}
			const q = svc.queue || {}
			return ['unclaimed', 'mine', 'others', 'closed'].map(b => (q[b] || []).map(r => r.id).join(',')).join('|')
		},

		figures() {
			const totals = this.stats?.totals || {}
			return [
				// TRANSLATORS: statistic - requests that arrived in the chosen period
				{ key: 'received', label: t('teamhub', 'Received'), value: totals.received ?? 0 },
				// TRANSLATORS: statistic - requests a team member claimed in the chosen period
				{ key: 'claimed', label: t('teamhub', 'Claimed'), value: totals.claimed ?? 0 },
				// TRANSLATORS: statistic - requests the team answered or rejected in the chosen period
				{ key: 'closed', label: t('teamhub', 'Closed'), value: totals.closed ?? 0 },
				// TRANSLATORS: statistic - requests the requester cancelled in the chosen period
				{ key: 'withdrawn', label: t('teamhub', 'Withdrawn'), value: totals.withdrawn ?? 0 },
				// TRANSLATORS: statistic - requests open right now, whatever the period
				{ key: 'open', label: t('teamhub', 'Open now'), value: totals.open ?? 0 },
				// TRANSLATORS: statistic - open requests nobody has claimed, right now
				{ key: 'unclaimed', label: t('teamhub', 'Unclaimed now'), value: totals.unclaimed ?? 0 },
				// TRANSLATORS: statistic - median time from a request arriving to somebody claiming it
				{ key: 'firstClaim', label: t('teamhub', 'Time to first claim'), value: formatDuration(totals.medianFirstClaim), text: true },
				// TRANSLATORS: statistic - median time from a request arriving to the team answering it
				{ key: 'close', label: t('teamhub', 'Time to close'), value: formatDuration(totals.medianClose), text: true },
			]
		},
	},

	watch: {
		currentTeamId: {
			immediate: true,
			handler(teamId) {
				if (teamId) {
					this.stats = null
					this.load()
				}
			},
		},

		/**
		 * v4.10.28 — follow the queue widget beside it. Found on the instance:
		 * claiming or answering a request there left this widget reading
		 * "Open now 1, Closed 0" until the page was reloaded. The queue is the
		 * one thing on the page that knows a request moved, so a change in its
		 * shape is the cue to re-read — debounced, because one claim is two
		 * store writes.
		 */
		queueShape(now, before) {
			if (before !== undefined && now !== before && this.stats) {
				clearTimeout(this._queueTimer)
				this._queueTimer = setTimeout(() => this.load(), 400)
			}
		},
	},

	beforeUnmount() {
		clearTimeout(this._queueTimer)
	},

	methods: {
		t,
		formatDuration,
		periodLabel,

		setPeriod(days) {
			if (days !== this.period) {
				this.period = days
				this.load()
			}
		},

		async load() {
			const teamId = this.currentTeamId
			if (!teamId) {
				return
			}
			this.loading = true
			this.error = ''
			try {
				const stats = await this.$store.dispatch('serviceTeams/statistics', { teamId, days: this.period })
				// A team switched while the request was out must not get the
				// previous team's numbers.
				if (teamId === this.currentTeamId) {
					this.stats = stats
				}
			} catch (e) {
				this.error = e?.message || t('teamhub', 'Could not load the statistics.')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.svc-stats-w {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-md, 12px);
	/* v4.10.43 — 16px from the card's edge, like the other widgets. */
	padding: var(--th-space-sm, 8px) var(--th-space-lg, 16px);
}

.svc-stats-w__periods {
	display: flex;
	flex-wrap: wrap;
	gap: var(--th-space-xs, 4px);
}

.svc-stats-w__loading {
	display: flex;
	justify-content: center;
	padding: var(--th-space-lg, 16px);
}

/* Two figures per row in the narrow column, more when the widget is wide. */
.svc-stats-w__figures {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
	gap: var(--th-space-sm, 8px);
	margin: 0;
}

/* Label and number on one line, the number right behind the label (Justin,
   2026-09-24) — the same reading as the queue widget's tabs. */
.svc-stats-w__figure {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--th-space-xs, 4px) var(--th-space-sm, 8px);
	padding: var(--th-space-sm, 8px);
	border-radius: var(--th-radius-control, var(--border-radius-element));
	background: var(--color-background-hover);
}

.svc-stats-w__label {
	font-size: var(--th-font-meta, 13px);
}

.svc-stats-w__value {
	display: inline-flex;
	margin: 0;
}

.svc-stats-w__duration {
	font-weight: var(--th-font-weight-semibold, 600);
	white-space: nowrap;
}

/* The inverted bubble, as on the queue widget's unpressed tabs: the text
   colour as fill, the canvas as number. Two classes outrank
   NcCounterBubble's own rule without !important. */
.svc-stats-w__figures .svc-count {
	background-color: var(--color-main-text);
	color: var(--color-main-background);
}

.svc-stats-w__services {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
}

.svc-stats-w__heading {
	margin: 0;
	font-size: var(--th-font-body, 15px);
	font-weight: var(--th-font-weight-semibold, 600);
}

.svc-stats-w__table {
	width: 100%;
	border-collapse: collapse;
	font-size: var(--th-font-meta, 13px);

	th,
	td {
		padding: var(--th-space-xs, 4px);
		text-align: start;
		border-block-end: 1px solid var(--color-border);
	}

	thead th {
		color: var(--th-widget-meta-color, var(--color-text-maxcontrast));
		font-weight: var(--th-font-weight-regular, 400);
	}

	tbody th {
		font-weight: var(--th-font-weight-medium, 500);
	}

	// Nested so it outranks the `th, td` rule above without !important.
	.svc-stats-w__num {
		text-align: end;
		white-space: nowrap;
	}
}

.svc-stats-w__note {
	margin: 0;
	color: var(--th-widget-meta-color, var(--color-text-maxcontrast));
	font-size: var(--th-font-meta, 13px);
}
</style>
