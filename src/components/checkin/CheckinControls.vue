<template>
	<div class="controls-section">
		<div class="search-container">
			<NcTextField
				:modelValue="searchQuery"
				:label="t('attendance', 'Search by name …')"
				data-test="input-search"
				@update:modelValue="onSearchInput">
				<MagnifyIcon :size="16" />
			</NcTextField>
		</div>
		<div class="group-filter">
			<NcSelect
				:modelValue="selectedGroup"
				:options="groupOptions"
				:placeholder="t('attendance', 'Filter by group')"
				:clearable="true"
				label="label"
				data-test="select-group-filter"
				@update:modelValue="onGroupChange" />
		</div>
	</div>
</template>

<script setup>
import { NcSelect, NcTextField } from '@nextcloud/vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'

defineProps({
	searchQuery: {
		type: String,
		default: '',
	},
	selectedGroup: {
		type: Object,
		default: null,
	},
	groupOptions: {
		type: Array,
		default: () => [],
	},
})

const emit = defineEmits(['update:searchQuery', 'update:selectedGroup'])

function onSearchInput(value) {
	emit('update:searchQuery', value)
}

function onGroupChange(value) {
	emit('update:selectedGroup', value)
}
</script>

<style scoped lang="scss">
.controls-section {
	margin-bottom: 30px;
	padding: 20px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);

	.search-container {
		margin-bottom: 15px;
		max-width: 400px;
	}

	.group-filter {
		max-width: 300px;

		.v-select.select {
			min-width: 200px;
		}
	}
}
</style>
