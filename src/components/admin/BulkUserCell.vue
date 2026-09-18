<template>
	<div class="buc">
		<ul v-if="selected.length" class="buc__chips">
			<li v-for="u in selected" :key="keyOf(u)" class="buc__chip">
				<AccountGroup v-if="u.type === 'group'" :size="12" aria-hidden="true" />
				<span class="buc__chip-name">{{ u.displayName }}</span>
				<button
					type="button"
					class="buc__chip-remove"
					:aria-label="t('teamhub', 'Remove {name}', { name: u.displayName })"
					@click="remove(u)">
					<Close :size="12" aria-hidden="true" />
				</button>
			</li>
		</ul>

		<!-- Single-select hides its input once something is chosen: a second
		     box that silently replaces the first pick is worse than no box. -->
		<input
			v-if="multiple || selected.length === 0"
			:id="inputId"
			v-model="query"
			type="text"
			class="buc__input"
			role="combobox"
			aria-autocomplete="list"
			:aria-expanded="open ? 'true' : 'false'"
			:aria-controls="listId"
			:placeholder="placeholder"
			autocomplete="off"
			@input="onInput"
			@focus="onInput"
			@keydown.down.prevent="move(1)"
			@keydown.up.prevent="move(-1)"
			@keydown.enter.prevent="pickCursor"
			@keydown.esc="close"
			@blur="onBlur">

		<div v-if="open" :id="listId" class="buc__results" role="listbox">
			<p v-if="searching" class="buc__state">{{ t('teamhub', 'Searching…') }}</p>
			<p v-else-if="results.length === 0" class="buc__state">
				{{ t('teamhub', 'No match. Only existing accounts can be used.') }}
			</p>
			<button
				v-for="(r, i) in results"
				:key="keyOf(r)"
				type="button"
				role="option"
				:aria-selected="i === cursor ? 'true' : 'false'"
				:class="['buc__result', { 'buc__result--active': i === cursor }]"
				@mousedown.prevent="pick(r)">
				<AccountGroup v-if="r.type === 'group'" :size="14" aria-hidden="true" />
				<span class="buc__result-name">{{ r.displayName }}</span>
				<span class="buc__result-meta">{{ r.type === 'group' ? t('teamhub', 'Group') : r.id }}</span>
			</button>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import Close from 'vue-material-design-icons/Close.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'

let cellSeq = 0

/**
 * A user/group picker sized for a table cell (v4.8.12).
 *
 * **Why this exists.** The bulk table used to take account names as free text
 * and check them in a separate pass afterwards, which meant a typo was found
 * one screen and one click later — and it was never obvious that the field took
 * more than one name. Resolving as you type removes both problems: **you cannot
 * select an account that does not exist**, so the separate check has nothing
 * left to find, and chips make "several people go here" visible rather than
 * something you have to read a placeholder to discover.
 *
 * Selection is always a real account. The parent still sends names to the
 * server and the server still resolves them — this narrows what can be typed,
 * it does not replace the server's own validation.
 */
export default {
	name: 'BulkUserCell',
	components: { Close, AccountGroup },

	props: {
		/** @type {Array<{id: string, displayName: string, type?: string}>} */
		modelValue: { type: Array, default: () => [] },
		/** Single-select hides the input once filled; multi keeps it. */
		multiple: { type: Boolean, default: false },
		/** Groups are members, never owners or administrators — they cannot act. */
		allowGroups: { type: Boolean, default: false },
		placeholder: { type: String, default: '' },
	},

	emits: ['update:modelValue'],

	data() {
		cellSeq += 1
		return {
			query: '',
			results: [],
			open: false,
			searching: false,
			cursor: -1,
			timer: null,
			inputId: 'buc-in-' + cellSeq,
			listId: 'buc-list-' + cellSeq,
		}
	},

	computed: {
		selected() {
			return this.modelValue || []
		},
	},

	beforeUnmount() {
		clearTimeout(this.timer)
	},

	methods: {
		t,

		keyOf(u) {
			return (u.type || 'user') + ':' + u.id
		},

		onInput() {
			clearTimeout(this.timer)
			const q = this.query.trim()
			if (q.length < 2) {
				this.results = []
				this.open = false
				return
			}
			// Debounced: a table of five rows can otherwise fire a request per
			// keystroke per cell.
			this.timer = setTimeout(() => this.search(q), 250)
		},

		async search(q) {
			this.searching = true
			this.open = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/teamhub/api/v1/users/search'),
					{ params: { q } },
				)
				const taken = new Set(this.selected.map(this.keyOf))
				this.results = (data || [])
					.map(u => ({ id: u.id, displayName: u.displayName || u.id, type: u.type || 'user' }))
					.filter(u => this.allowGroups || u.type !== 'group')
					.filter(u => !taken.has(this.keyOf(u)))
					.slice(0, 8)
				this.cursor = this.results.length ? 0 : -1
			} catch (e) {
				// A failed lookup shows "no match" rather than an error: the
				// cell is one of many on the page and a red banner per cell
				// would bury the table.
				this.results = []
			} finally {
				this.searching = false
			}
		},

		move(delta) {
			if (!this.open || this.results.length === 0) return
			this.cursor = (this.cursor + delta + this.results.length) % this.results.length
		},

		pickCursor() {
			if (this.cursor >= 0 && this.results[this.cursor]) {
				this.pick(this.results[this.cursor])
			}
		},

		pick(user) {
			const next = this.multiple ? [...this.selected, user] : [user]
			this.$emit('update:modelValue', next)
			this.query = ''
			this.results = []
			this.open = false
			this.cursor = -1
		},

		remove(user) {
			this.$emit('update:modelValue', this.selected.filter(u => this.keyOf(u) !== this.keyOf(user)))
		},

		close() {
			this.open = false
		},

		onBlur() {
			// Deferred: a mousedown on a result fires before blur resolves, and
			// closing immediately would swallow the click.
			setTimeout(() => { this.open = false }, 120)
		},
	},
}
</script>

<style scoped>
.buc {
	position: relative;
	min-width: 150px;
}

.buc__chips {
	list-style: none;
	margin: 0 0 2px;
	padding: 0;
	display: flex;
	flex-wrap: wrap;
	gap: 2px;
}

.buc__chip {
	display: inline-flex;
	align-items: center;
	gap: 2px;
	max-width: 100%;
	padding: 1px 2px 1px 6px;
	border-radius: var(--th-radius-pill);
	background: var(--color-background-dark);
	font-size: var(--th-font-micro);
}

/* Same reason as .buc__result-name: without min-width the ellipsis is inert
   and a long display name widens the chip past the cell. */
.buc__chip-name {
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* Six locks — NC's global button rule sets min-width AND min-height to 44px,
   and per spec min-* beats an unqualified width/height. SKILLS.md § UI shapes. */
.buc__chip-remove {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: 0 0 auto;
	box-sizing: border-box;
	width: 16px;
	height: 16px;
	min-width: 16px;
	min-height: 16px;
	max-width: 16px;
	max-height: 16px;
	padding: 0;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}

.buc__chip-remove:hover {
	background: var(--color-background-hover);
}

.buc__chip-remove:focus-visible {
	outline: 2px solid var(--color-primary-element);
}

.buc__input {
	width: 100%;
	min-width: 0;
	box-sizing: border-box;
	font-size: var(--th-font-meta);
	border-radius: var(--th-radius-control);
}

.buc__input:focus {
	outline: none;
	border-color: var(--color-primary-element);
}

.buc__input:focus-visible {
	box-shadow: 0 0 0 2px var(--color-primary-element);
}

.buc__results {
	position: absolute;
	z-index: 20;
	inset-inline-start: 0;
	top: 100%;
	min-width: 220px;
	max-width: 320px;
	max-height: 240px;
	/* Both axes stated. `overflow-y: auto` on its own leaves overflow-x at
	   `visible`, which the spec then computes to `auto` — so a row one pixel
	   too wide grows a horizontal scrollbar nobody asked for. The rows below
	   are made to fit; this makes sure a future one cannot scroll instead. */
	overflow-x: hidden;
	overflow-y: auto;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--th-radius-card);
	box-shadow: 0 2px 8px var(--color-box-shadow);
}

.buc__state {
	margin: 0;
	padding: 8px 10px;
	font-size: var(--th-font-meta);
	color: var(--color-text-maxcontrast);
}

.buc__result {
	display: flex;
	align-items: center;
	gap: 6px;
	width: 100%;
	padding: 6px 10px;
	border: none;
	background: transparent;
	text-align: start;
	cursor: pointer;
	font-size: var(--th-font-meta);
	color: var(--color-main-text);
}

.buc__result:hover,
.buc__result--active {
	background: var(--color-background-hover);
}

.buc__result:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}

/* `min-width: 0` is what makes the ellipsis rules beside it do anything. A
   flex item's min-width defaults to `auto`, i.e. its min-content width, so
   without this the name refuses to shrink, `overflow: hidden` never fires and
   the row pushes the dropdown wider than its own max-width instead.
   Unrelated to SKILLS.md's "don't use min-width: 0" note — that is about
   pinning a circular *button*, not about letting text inside one truncate. */
.buc__result-name {
	flex: 1 1 auto;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* Shrinkable too: this is the account id, and an id long enough to need the
   room is exactly the case that was overflowing. */
.buc__result-meta {
	flex: 0 1 auto;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-micro);
}
</style>
