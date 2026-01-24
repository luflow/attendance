<template>
	<div class="controls-section">
		<div class="search-container">
			<NcTextField
				:model-value="searchQuery"
				:label="t('attendance', 'Search by name …')"
				data-test="input-search"
				@update:model-value="onSearchInput">
				<MagnifyIcon :size="16" />
			</NcTextField>
		</div>
		<div class="group-filter">
			<NcSelect
				:model-value="selectedGroup"
				:options="groupOptions"
				:placeholder="t('attendance', 'Filter by group')"
				:clearable="true"
				label="label"
				data-test="select-group-filter"
				@update:model-value="onGroupChange" />
		</div>
	</div>
</template>

<script setup>
import { NcTextField, NcSelect } from '@nextcloud/vue'
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

const onSearchInput = (value) => {
	emit('update:searchQuery', value)
}

const onGroupChange = (value) => {
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
