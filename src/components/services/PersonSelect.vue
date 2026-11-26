<template>
	<!-- v4.20.58 — one picker of a person, or of a group: the search every
	     picker in TeamHub uses, with the restrictions an administrator set on
	     sharing. Out of RequestFormField, which still uses it. -->
	<NcSelect
		:model-value="shown"
		:input-id="inputId"
		label-outside
		label="label"
		:options="found"
		:filterable="false"
		:loading="searching"
		:disabled="disabled"
		:placeholder="placeholder || t('teamhub', 'Search for a person')"
		:aria-label-combobox="label"
		@search="onSearch"
		@update:model-value="pick" />
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcSelect } from '@nextcloud/vue'

export default {
	name: 'PersonSelect',

	components: { NcSelect },

	props: {
		/** `{ id, name }`, or null. */
		modelValue: { type: Object, default: null },
		inputId: { type: String, required: true },
		/** The accessible name. */
		label: { type: String, required: true },
		placeholder: { type: String, default: '' },
		/** `user` or `group`. */
		type: { type: String, default: 'user' },
		disabled: { type: Boolean, default: false },
	},

	emits: ['update:modelValue'],

	data() {
		return {
			found: [],
			searching: false,
			timer: null,
			/** The last thing searched for: a slow answer to an earlier one is dropped. */
			query: '',
		}
	},

	computed: {
		/** What the field shows: the name, with its detail only while it is being picked. */
		shown() {
			return this.modelValue?.id ? { id: this.modelValue.id, label: this.modelValue.name || this.modelValue.id } : null
		},
	},

	watch: {
		type() {
			this.found = []
		},
	},

	beforeUnmount() {
		clearTimeout(this.timer)
	},

	methods: {
		t,

		/** The name goes out without the detail beside it: that is for choosing, not for keeping. */
		pick(option) {
			this.$emit('update:modelValue', option ? { id: option.id, name: option.name || option.label } : null)
		},

		onSearch(query) {
			clearTimeout(this.timer)
			this.query = String(query || '').trim()
			if (this.query.length < 2) {
				this.found = []
				return
			}
			this.timer = setTimeout(() => this.search(this.query), 250)
		},

		async search(query) {
			this.searching = true
			try {
				const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/users/search'), { params: { q: query } })
				if (query !== this.query) {
					return
				}
				this.found = (Array.isArray(data) ? data : [])
					.filter(row => (row.type || 'user') === this.type)
					.map(row => ({
						id: row.id,
						name: row.displayName || row.id,
						label: row.subline
							// TRANSLATORS: a person in a list of people; {name} is their name, {detail} their job title or organisation
							? t('teamhub', '{name} ({detail})', { name: row.displayName || row.id, detail: row.subline })
							: row.displayName || row.id,
					}))
			} catch (e) {
				this.found = []
			} finally {
				this.searching = false
			}
		},
	},
}
</script>
