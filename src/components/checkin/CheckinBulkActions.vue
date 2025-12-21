<template>
	<div class="section-header">
		<div class="section-actions">
			<NcButton
				variant="success"
				:disabled="disabled"
				data-test="button-bulk-present"
				@click="$emit('bulk-checkin', 'yes')">
				{{ t('attendance', 'All Present') }}
			</NcButton>
			<NcButton
				variant="error"
				:disabled="disabled"
				data-test="button-bulk-absent"
				@click="$emit('bulk-checkin', 'no')">
				{{ t('attendance', 'All Absent') }}
			</NcButton>
		</div>
	</div>
</template>

<script setup>
import { NcButton } from '@nextcloud/vue'

defineProps({
	disabled: {
		type: Boolean,
		default: false,
	},
})

defineEmits(['bulk-checkin'])
</script>

<style scoped lang="scss">
.section-header {
	display: flex;
	justify-content: right;
	align-items: center;
	margin-bottom: 16px;
	padding: 0 4px;

	.section-actions {
		display: flex;
		gap: 8px;
	}
}
</style>
