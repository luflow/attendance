<template>
	<div class="appointment-info">
		<template v-if="displayOrder === 'date_first'">
			<h2>{{ t('attendance', 'Check-in') }}: {{ formatDateRange(appointment.startDatetime, appointment.endDatetime) }}</h2>
			<p class="appointment-date-range">
				{{ appointment.name }}
			</p>
		</template>
		<template v-else>
			<h2>{{ t('attendance', 'Check-in') }}: {{ appointment.name }}</h2>
			<p class="appointment-date-range">
				{{ formatDateRange(appointment.startDatetime, appointment.endDatetime) }}
			</p>
		</template>
		<div v-if="appointment.description" class="appointment-description" v-html="renderedDescription" />
	</div>
</template>

<script setup>
import { computed } from 'vue'
import { formatDateRange } from '../../utils/datetime.js'
import { renderMarkdown, sanitizeHtml } from '../../utils/markdown.js'

const props = defineProps({
	appointment: {
		type: Object,
		required: true,
	},
	displayOrder: {
		type: String,
		default: 'name_first',
	},
})

const renderedDescription = computed(() => {
	if (!props.appointment?.description) return ''
	return sanitizeHtml(renderMarkdown(props.appointment.description, true))
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

	.appointment-description {
		margin: 15px 0 0 0;
		color: var(--color-main-text);

		:deep(strong) {
			font-weight: bold;
			color: var(--color-main-text);
		}

		:deep(em) {
			font-style: italic;
		}

		:deep(del) {
			text-decoration: line-through;
		}

		:deep(a) {
			color: var(--color-primary-element);
			text-decoration: none;

			&:hover {
				text-decoration: underline;
			}
		}

		:deep(code) {
			background-color: var(--color-background-dark);
			padding: 2px 6px;
			border-radius: var(--border-radius-small);
			font-family: monospace;
			font-size: 0.9em;
		}
	}
}
</style>
