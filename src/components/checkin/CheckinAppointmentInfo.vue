<template>
	<div class="appointment-info">
		<h2>{{ t('attendance', 'Check-in') }}: {{ appointment.name }}</h2>
		<p class="appointment-details">
			<strong>{{ t('attendance', 'Start') }}:</strong> {{ formatDateTime(appointment.startDatetime) }}<br>
			<strong>{{ t('attendance', 'End') }}:</strong> {{ formatDateTime(appointment.endDatetime) }}
		</p>
		<div v-if="appointment.description" class="appointment-description" v-html="renderedDescription"></div>
	</div>
</template>

<script setup>
import { computed } from 'vue'
import { formatDateTimeMedium } from '../../utils/datetime.js'
import { renderMarkdown, sanitizeHtml } from '../../utils/markdown.js'

const props = defineProps({
	appointment: {
		type: Object,
		required: true,
	},
})

const formatDateTime = (datetime) => {
	return formatDateTimeMedium(datetime)
}

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

	.appointment-details {
		margin: 10px 0;
		color: var(--color-text-maxcontrast);
	}

	.appointment-description {
		margin: 15px 0 0 0;
		color: var(--color-main-text);
		white-space: pre-wrap;

		:deep(strong) {
			font-weight: bold;
			color: var(--color-main-text);
		}

		:deep(em) {
			font-style: italic;
		}
	}
}
</style>
