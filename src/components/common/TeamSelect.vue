<template>
	<div>
		<NcSelect
			:modelValue="modelValue"
			:options="options"
			:placeholder="placeholder"
			:multiple="true"
			:loading="isSearching"
			:filterable="false"
			label="label"
			trackBy="id"
			@search="search"
			@update:modelValue="$emit('update:modelValue', $event)">
			<template #option="{ label }">
				<span style="display: flex; align-items: center; gap: 8px;">
					<AccountStar :size="20" />
					<span>{{ label }}</span>
				</span>
			</template>
			<template #selected-option="{ label }">
				<span style="display: flex; align-items: center; gap: 8px;">
					<AccountStar :size="16" />
					<span>{{ label }}</span>
				</span>
			</template>
		</NcSelect>
		<OrderedSelectionList v-if="sortable"
			:modelValue="modelValue"
			labelKey="label"
			:dataTest="orderDataTest"
			@update:modelValue="$emit('update:modelValue', $event)" />
	</div>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcSelect } from '@nextcloud/vue'
import { computed, ref } from 'vue'
import AccountStar from 'vue-material-design-icons/AccountStar.vue'
import OrderedSelectionList from './OrderedSelectionList.vue'

const props = defineProps({
	modelValue: {
		type: Array,
		default: () => [],
	},
	placeholder: {
		type: String,
		default: '',
	},
	/** Offer the selection as a reorderable list — the order teams appear in */
	sortable: {
		type: Boolean,
		default: false,
	},
	orderDataTest: {
		type: String,
		default: 'order-teams',
	},
})

defineEmits(['update:modelValue'])

const searchResults = ref([])
const isSearching = ref(false)

// Teams are searched server-side, so the dropdown would otherwise drop the
// current selection as soon as it no longer matches the query.
const options = computed(() => {
	const selectedIds = props.modelValue.map((team) => team.id)
	return [...props.modelValue, ...searchResults.value.filter((team) => !selectedIds.includes(team.id))]
})

async function search(query) {
	if (!query) {
		searchResults.value = []
		return
	}

	isSearching.value = true
	try {
		const response = await axios.get(
			generateUrl('/apps/attendance/api/search/users-groups-teams'),
			{ params: { search: query } },
		)
		searchResults.value = response.data
			.filter((item) => item.type === 'team')
			.map((item) => ({ id: item.id, label: item.label, type: 'team' }))
	} catch (error) {
		console.error('Error searching teams:', error)
	} finally {
		isSearching.value = false
	}
}
</script>
