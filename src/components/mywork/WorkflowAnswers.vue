<template>
	<dl class="wf-answers">
		<!-- v4.20.54 — what a service's request form asked and what was
		     answered, under the names it was asked under. -->
		<div v-for="entry in answers" :key="entry.key" class="wf-answers__row">
			<dt class="wf-answers__label">{{ labelOf(entry) }}</dt>
			<dd class="wf-answers__value">{{ answerText(entry) }}</dd>
		</div>
	</dl>
</template>

<script>
import { answerText, answersOf, fieldLabel } from '../../constants/requestForm.js'

export default {
	name: 'WorkflowAnswers',

	props: {
		/** The server's workflow view; the answers are its `data.form`. */
		workflow: { type: Object, default: null },
		/** v4.20.71 — or answers given directly: a task's own. */
		entries: { type: Array, default: null },
	},

	computed: {
		answers() {
			return this.entries || answersOf(this.workflow)
		},
	},

	methods: {
		answerText,

		labelOf(entry) {
			return fieldLabel(entry) || entry.key
		},
	},
}
</script>

<style scoped lang="scss">
.wf-answers {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-md, 12px);
	margin: 0;
	padding: 0;
}

/* v4.20.55 — server.css lays `dt`/`dd` out as inline blocks, the label 130px
   wide, right-aligned and padded. Found on the instance. */
.wf-answers__label {
	display: block;
	width: auto;
	padding: 0;
	text-align: start;
	white-space: normal;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

.wf-answers__value {
	display: block;
	margin: 0;
	padding: 0;
	overflow-wrap: anywhere;
	white-space: pre-wrap;
}
</style>
