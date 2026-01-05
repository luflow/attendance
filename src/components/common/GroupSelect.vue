<template>
	<NcSelect
		:model-value="modelValue"
		:options="options"
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
import { NcSelect } from '@nextcloud/vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'

defineProps({
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
</script>
