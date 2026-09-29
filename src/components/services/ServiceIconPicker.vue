<template>
	<!-- v4.10.45 — pick a service's or a category's icon (Justin,
	     2026-09-25: every built service showed the question mark). The
	     chosen icon and a button that opens the grid below it; picking one
	     closes the grid again. Each icon is a toggle button with its name
	     as accessible name and tooltip. -->
	<div class="svc-icon-picker">
		<div class="svc-icon-picker__current">
			<span class="svc-icon-picker__preview">
				<ServiceIcon :name="value" :size="ICON_LARGE" />
			</span>
			<NcButton
				variant="secondary"
				:disabled="disabled"
				:aria-expanded="open ? 'true' : 'false'"
				:aria-controls="gridId"
				@click="open = !open">
				{{ open ? t('teamhub', 'Close the icons') : t('teamhub', 'Choose icon') }}
			</NcButton>
		</div>
		<div
			v-if="open"
			:id="gridId"
			class="svc-icon-picker__grid"
			role="group"
			:aria-label="label || t('teamhub', 'Icon')">
			<NcButton
				v-for="name in names"
				:key="name"
				variant="tertiary"
				:pressed="name === selected"
				:aria-label="iconLabel(name)"
				:title="iconLabel(name)"
				:disabled="disabled"
				@update:pressed="pick(name)">
				<template #icon><ServiceIcon :name="name" :size="ICON_BODY" /></template>
			</NcButton>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import ServiceIcon from './ServiceIcon.vue'
import { ICON_BODY, ICON_LARGE } from '../../constants/uiTokens.js'
import { SERVICE_ICON_NAMES, serviceIconLabel, serviceIconName } from '../../constants/serviceIcons.js'

let uid = 0

export default {
	name: 'ServiceIconPicker',

	components: { NcButton, ServiceIcon },

	props: {
		/** The chosen name; '' is the default icon. */
		value: { type: String, default: '' },
		/** The group's accessible name, when "Icon" is not enough. */
		label: { type: String, default: '' },
		disabled: { type: Boolean, default: false },
	},

	emits: ['update:value'],

	data() {
		uid += 1
		return {
			ICON_BODY,
			ICON_LARGE,
			names: SERVICE_ICON_NAMES,
			open: false,
			gridId: `svc-icon-picker-${uid}`,
		}
	},

	computed: {
		selected() {
			return serviceIconName(this.value)
		},
	},

	methods: {
		t,
		iconLabel: serviceIconLabel,

		pick(name) {
			this.$emit('update:value', name)
			this.open = false
		},
	},
}
</script>

<style scoped lang="scss">
.svc-icon-picker {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm, 8px);
}

.svc-icon-picker__current {
	display: flex;
	align-items: center;
	gap: var(--th-space-md, 12px);
}

.svc-icon-picker__preview {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	color: var(--color-primary-element);
}

.svc-icon-picker__grid {
	display: flex;
	flex-wrap: wrap;
	gap: var(--th-space-xs, 4px);
	padding: var(--th-space-sm, 8px);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-container);
}
</style>
