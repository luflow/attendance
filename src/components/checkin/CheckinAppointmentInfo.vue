<template>
	<div class="appointment-info">
		<template v-if="displayOrder === 'date_first'">
			<!-- TRANSLATORS: Noun — page heading on the check-in screen, followed by the appointment date or name. -->
			<h2>{{ t('attendance', 'Check-in') }}: {{ formatDateRange(appointment.startDatetime, appointment.endDatetime) }}</h2>
			<p class="appointment-date-range">
				{{ appointment.name }}
			</p>
		</template>
		<template v-else>
			<!-- TRANSLATORS: Noun — page heading on the check-in screen, followed by the appointment date or name. -->
			<h2>{{ t('attendance', 'Check-in') }}: {{ appointment.name }}</h2>
			<p class="appointment-date-range">
				{{ formatDateRange(appointment.startDatetime, appointment.endDatetime) }}
			</p>
		</template>
	</div>
</template>

<script setup>
import { formatDateRange } from '../../utils/datetime.js'

defineProps({
	appointment: {
		type: Object,
		required: true,
	},
	displayOrder: {
		type: String,
		default: 'name_first',
	},
})
</script>

<style scoped lang="scss">
.appointment-info {
	margin-bottom: 20px;
	padding: 20px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);

	h2 {
		margin: 0 0 10px 0;
		color: var(--color-main-text);
	}

	.appointment-date-range {
		margin: 2px 0 10px 0;
		color: var(--color-text-maxcontrast);
		font-size: 14px;
	}
}
</style>
