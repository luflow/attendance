<template>
	<ul class="event-list">
		<li v-for="entry in entries"
			:key="entry.seriesId"
			class="event-item"
			@click="$emit('toggle', entry.seriesId)">
			<NcCheckboxRadioSwitch
				:modelValue="selected.includes(entry.seriesId)"
				class="event-checkbox">
				<div class="event-info">
					<span class="event-name">{{ entry.name }}</span>
					<span class="event-date">{{ summary(entry) }}</span>
				</div>
			</NcCheckboxRadioSwitch>
		</li>
	</ul>
</template>

<script setup>
import { translatePlural as n } from '@nextcloud/l10n'
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { allDaySpan, formatDate } from '../../utils/datetime.js'

defineProps({
	entries: {
		type: Array,
		required: true,
	},
	selected: {
		type: Array,
		required: true,
	},
})

defineEmits(['toggle'])

/**
 * @param {object} entry - A series as the export endpoint reports it.
 * @return {string} Appointment count and the span the series covers.
 */
function summary(entry) {
	// An all-day series ends at the midnight after its last day.
	const [first, last] = entry.isAllDay
		? allDaySpan(entry.startDatetime, entry.endDatetime)
		: [entry.startDatetime, entry.endDatetime]
	const range = `${formatDate(first, 'short')} – ${formatDate(last, 'short')}`

	return n(
		'attendance',
		'{count} appointment · {range}',
		'{count} appointments · {range}',
		entry.appointmentCount,
		{ count: entry.appointmentCount, range },
	)
}
</script>

<style scoped>
.event-list {
	list-style: none;
	padding: 0;
	margin: 0;
	max-height: 300px;
	overflow-y: auto;
}

.event-item {
	display: flex;
	align-items: center;
	padding: 12px 12px 12px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: 8px;
	cursor: pointer;
	transition: background-color 0.15s ease;
}

.event-item:hover {
	background-color: var(--color-background-hover);
}

.event-checkbox {
	pointer-events: none;
	width: 100%;
	margin: -4px 0;
}

.event-checkbox :deep(.checkbox-content__icon) {
	margin-block: auto !important;
	margin-inline-end: 8px;
}

.event-info {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.event-name {
	flex: 1;
	font-weight: 500;
}

.event-date {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}
</style>
