<template>
	<NcSelect
		:model-value="modelValue"
		:options="decoratedOptions"
		:placeholder="placeholder"
		:multiple="true"
		:disabled="disabled"
		label="displayName"
		track-by="id"
		@update:model-value="$emit('update:modelValue', $event)">
		<template #option="{ displayName }">
			<span style="display: flex; align-items: center; gap: 8px;">
				<AccountGroup :size="20" />
				<span>{{ displayName }}</span>
			</span>
		</template>
		<template #selected-option="{ displayName }">
			<span style="display: flex; align-items: center; gap: 8px;">
				<AccountGroup :size="16" />
				<span>{{ displayName }}</span>
			</span>
		</template>
	</NcSelect>
</template>

<script setup>
import { computed } from 'vue'
import { NcSelect } from '@nextcloud/vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import { formatGroupLabel } from '../../utils/groups.js'

const props = defineProps({
	modelValue: {
		type: Array,
		default: () => [],
	},
	options: {
		type: Array,
		default: () => [],
	},
	placeholder: {
		type: String,
		default: '',
	},
	disabled: {
		type: Boolean,
		default: false,
	},
})

defineEmits(['update:modelValue'])

// Rewrite the displayName for known system groups (e.g. guest_app → "Guests")
// before they reach NcSelect, so the option list, the selected-options pill,
// and the search filter all see the same friendly label.
const decoratedOptions = computed(() =>
	props.options.map(option => ({
		...option,
		displayName: formatGroupLabel(option.id, option.displayName),
	})),
)
</script>
