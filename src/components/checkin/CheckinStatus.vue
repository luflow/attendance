<template>
	<div class="checkin-status">
		<div v-if="allCheckedIn" class="status-complete">
			<CheckIcon :size="20" />
			<span>{{ t('attendance', 'All attendees checked in') }}</span>
		</div>
		<div v-else class="status-pending">
			<AlertIcon :size="20" />
			<span>{{ n('attendance', '{count} attendee not yet checked in', '{count} attendees not yet checked in', notCheckedInCount, { count: notCheckedInCount }) }}</span>
		</div>
	</div>
</template>

<script setup>
import CheckIcon from 'vue-material-design-icons/Check.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'

defineProps({
	allCheckedIn: {
		type: Boolean,
		required: true,
	},
	notCheckedInCount: {
		type: Number,
		required: true,
	},
})
</script>

<style scoped lang="scss">
.checkin-status {
	margin-bottom: 20px;
	padding: 16px 20px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	border-left: 4px solid transparent;

	.status-complete {
		display: flex;
		align-items: center;
		gap: 12px;

		span {
			font-weight: 600;
		}
	}

	.status-pending {
		display: flex;
		align-items: center;
		gap: 12px;
		background: #ff8c00;
		color: white;
		border-left-color: #ff6600;
		border-radius: var(--border-radius-large);
		padding: 16px 20px;
		margin: -16px -20px;

		span {
			font-weight: 600;
		}
	}
}
</style>
