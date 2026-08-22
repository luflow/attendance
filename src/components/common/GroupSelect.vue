<template>
	<div>
		<NcSelect
			:modelValue="modelValue"
			:options="decoratedOptions"
			:placeholder="placeholder"
			:multiple="true"
			:disabled="disabled"
			label="displayName"
			trackBy="id"
			@update:modelValue="$emit('update:modelValue', $event)">
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
		<OrderedSelectionList v-if="sortable"
			:modelValue="modelValue"
			:formatLabel="groupLabel"
			:dataTest="orderDataTest"
			@update:modelValue="$emit('update:modelValue', $event)" />
	</div>
</template>

<script setup>
import { NcSelect } from '@nextcloud/vue'
import { computed } from 'vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import OrderedSelectionList from './OrderedSelectionList.vue'
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
	/** Offer the selection as a reorderable list — the order groups appear in */
	sortable: {
		type: Boolean,
		default: false,
	},
	orderDataTest: {
		type: String,
		default: 'order-groups',
	},
})

defineEmits(['update:modelValue'])

// Rewrite the displayName for known system groups (e.g. guest_app → "Guests")
// before they reach NcSelect, so the option list, the selected-options pill,
// and the search filter all see the same friendly label.
const decoratedOptions = computed(() => props.options.map((option) => ({
	...option,
	displayName: formatGroupLabel(option.id, option.displayName),
})))

// Stored ids come back undecorated, so the order list relabels them itself.
const groupLabel = (group) => formatGroupLabel(group.id, group.displayName)
</script>
