<template>
	<NcModal v-if="show"
		:name="t('attendance', 'Export appointment')"
		size="normal"
		@close="$emit('close')">
		<div class="single-export-dialog">
			<h2>{{ t('attendance', 'Export appointment') }}</h2>

			<div class="appointment-info">
				<h3>{{ appointment?.name }}</h3>
				<p>{{ formatStart(appointment?.startDatetime, appointment?.isAllDay) }}</p>
			</div>

			<div class="export-options">
				<h3>{{ t('attendance', 'Export options') }}</h3>
				<ExportColumnOptions
					v-model="columns"
					:hasAnyColumn="hasAnyColumn" />
			</div>

			<div class="button-row">
				<NcButton
					@click="$emit('close')">
					{{ t('attendance', 'Cancel') }}
				</NcButton>
				<NcButton
					:disabled="exporting || !hasAnyColumn"
					variant="primary"
					@click="handleExport">
					<template #icon>
						<NcLoadingIcon v-if="exporting" :size="20" />
						<DownloadIcon v-else :size="20" />
					</template>
					{{ exporting ? t('attendance', 'Exporting …') : t('attendance', 'Export') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcModal } from '@nextcloud/vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import ExportColumnOptions from './export/ExportColumnOptions.vue'
import { useExportColumns } from '../composables/useExportColumns.js'
import { useExportRequest } from '../composables/useExportRequest.js'
import { formatStart } from '../utils/datetime.js'

const props = defineProps({
	show: {
		type: Boolean,
		required: true,
	},
	appointment: {
		type: Object,
		default: null,
	},
})

const emit = defineEmits(['close'])

const { columns, hasAnyColumn } = useExportColumns()
const { exporting, runExport } = useExportRequest(t('attendance', 'Failed to export appointment'))

async function handleExport() {
	if (!props.appointment || !hasAnyColumn.value) return

	const created = await runExport({
		appointmentIds: [props.appointment.id],
		...columns.value,
	})

	if (created) {
		emit('close')
	}
}
</script>

<style scoped>
.single-export-dialog {
	padding: 20px;
	min-width: 400px;
}

.single-export-dialog h2 {
	margin: 0 0 20px 0;
	font-size: 1.5em;
}

.single-export-dialog h3 {
	margin: 20px 0 10px 0;
	font-size: 1.2em;
}

.appointment-info {
	background: var(--color-background-hover);
	padding: 15px;
	border-radius: var(--border-radius);
	margin-bottom: 20px;
}

.appointment-info h3 {
	margin: 0 0 5px 0;
	color: var(--color-main-text);
}

.appointment-info p {
	margin: 0;
	color: var(--color-text-lighter);
}

.export-options {
	margin-bottom: 20px;
}

.button-row {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	margin-top: 20px;
	padding-top: 15px;
	border-top: 1px solid var(--color-border);
}
</style>
