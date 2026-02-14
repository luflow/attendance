<template>
	<NcModal v-if="show"
		@close="$emit('close')"
		:name="t('attendance', 'Export appointment')"
		size="normal">
		<div class="single-export-dialog">
			<h2>{{ t('attendance', 'Export appointment') }}</h2>

			<div class="appointment-info">
				<h3>{{ appointment?.name }}</h3>
				<p>{{ formatDateTime(appointment?.startDatetime) }}</p>
			</div>

			<div class="export-options">
				<h3>{{ t('attendance', 'Export options') }}</h3>
				<NcCheckboxRadioSwitch
					v-model="includeComments"
					type="checkbox">
					{{ t('attendance', 'Include comments in export') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div class="button-row">
				<NcButton
					@click="$emit('close')">
					{{ t('attendance', 'Cancel') }}
				</NcButton>
				<NcButton
					:disabled="exporting"
					variant="primary"
					@click="handleExport">
					<template #icon>
						<NcLoadingIcon v-if="exporting" :size="20" />
						<DownloadIcon v-else :size="20" />
					</template>
					{{ exporting ? t('attendance', 'Exporting …') : t('attendance', 'Export') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script setup>
import { ref } from 'vue'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'

import { NcModal, NcButton, NcCheckboxRadioSwitch, NcLoadingIcon } from '@nextcloud/vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'

import { formatDateTime } from '../utils/datetime.js'

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

const includeComments = ref(false)
const exporting = ref(false)

const handleExport = async () => {
	if (!props.appointment) return

	exporting.value = true

	try {
		const response = await axios.post(generateUrl('/apps/attendance/api/export'), {
			appointmentIds: [props.appointment.id],
			includeComments: includeComments.value,
		})

		if (response.data.success) {
			showSuccess(t('attendance', 'Export created: {filename}', { filename: response.data.filename }))

			// Redirect to Files app to show the exported file
			const filesUrl = generateUrl('/apps/files/?dir=/Attendance')
			window.location.href = filesUrl

			emit('close')
		} else {
			showError(t('attendance', 'Failed to export appointment'))
		}
	} catch (error) {
		console.error('Failed to export appointment:', error)
		const errorMessage = error.response?.data?.error || t('attendance', 'Failed to export appointment')
		showError(errorMessage)
	} finally {
		exporting.value = false
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