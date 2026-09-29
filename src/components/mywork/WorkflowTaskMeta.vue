<template>
	<!-- v4.10.44 — the task a row is about and the role it needs, on one line:
	     the words in grey, the values in the primary colour (Justin,
	     2026-09-25: "task: in grey, <task> in primary color, role: in grey
	     and <role> in primary color so it's more unity"). Used by the
	     service team's queue, My Work and the request's detail view. Extra
	     pairs (who has it, what it waits for) come through `extra`. -->
	<span v-if="pairs.length" class="wf-task-meta">
		<span v-for="pair in pairs" :key="pair.key" class="wf-task-meta__pair">
			<span class="wf-task-meta__label">{{ pair.label }}</span>
			<span class="wf-task-meta__value">{{ pair.value }}</span>
		</span>
	</span>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'WorkflowTaskMeta',

	props: {
		/** One step (task) of a workflow view, or null. */
		step: { type: Object, default: null },
		/** Show the task's name; off where the row already names it. */
		showTask: { type: Boolean, default: true },
		/** More `{ key, label, value }` pairs to put on the same line. */
		extra: { type: Array, default: () => [] },
	},

	computed: {
		pairs() {
			const out = []
			const task = String(this.step?.label || '').trim()
			const role = String(this.step?.roleLabel || '').trim()
			if (this.showTask && task) {
				// TRANSLATORS: before the name of the task a row is about, e.g. "Task: Privacy check"
				out.push({ key: 'task', label: t('teamhub', 'Task:'), value: task })
			}
			if (role) {
				// TRANSLATORS: before the role a task needs, e.g. "Role: Privacy officer"
				out.push({ key: 'role', label: t('teamhub', 'Role:'), value: role })
			}
			return out.concat(this.extra.filter(pair => pair && pair.value))
		},
	},
}
</script>

<style scoped lang="scss">
.wf-task-meta {
	display: inline-flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 0 var(--th-space-md, 12px);
	font-size: var(--th-font-meta, 13px);
}

.wf-task-meta__pair {
	display: inline-flex;
	gap: var(--th-space-xs, 4px);
	min-width: 0;
}

.wf-task-meta__label {
	color: var(--color-text-maxcontrast);
}

.wf-task-meta__value {
	color: var(--color-primary-element);
	font-weight: var(--th-font-weight-semibold, 600);
	overflow-wrap: anywhere;
}
</style>
