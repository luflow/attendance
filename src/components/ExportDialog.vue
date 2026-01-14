<template>
	<NcModal v-if="show"
		@close="$emit('close')"
		:name="t('attendance', 'Export appointments')"
		size="normal">
		<div class="export-dialog">
			<h2>{{ t('attendance', 'Export appointments') }}</h2>

			<!-- Filter Type Selection -->
			<div class="filter-section">
				<h3>{{ t('attendance', 'Export options') }}</h3>

				<NcNoteCard type="info" class="info-card">
					<p>{{ t('attendance', 'Select which appointments to export and the date range.') }}</p>
				</NcNoteCard>

				<div class="radio-group">
					<NcCheckboxRadioSwitch
						v-model="filterType"
						value="all"
						name="filter_type"
						type="radio">
						{{ t('attendance', 'All appointments') }}
					</NcCheckboxRadioSwitch>

					<NcCheckboxRadioSwitch
						v-model="filterType"
						value="selected"
						name="filter_type"
						type="radio">
						{{ t('attendance', 'Selected appointments') }}
					</NcCheckboxRadioSwitch>

					<NcCheckboxRadioSwitch
						v-model="filterType"
						value="dateRange"
						name="filter_type"
						type="radio">
						{{ t('attendance', 'Date range') }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>

			<!-- Selected Appointments -->
			<div v-if="filterType === 'selected'" class="filter-section">
				<h3>{{ t('attendance', 'Select appointments') }}</h3>

				<!-- Select All / Deselect All controls -->
				<div class="select-controls">
					<NcButton
						type="tertiary"
						:disabled="selectedAppointments.length === availableAppointments.length"
						@click="selectAllAppointments">
						{{ t('attendance', 'Select all') }}
					</NcButton>
					<NcButton
						type="tertiary"
						:disabled="selectedAppointments.length === 0"
						@click="deselectAllAppointments">
						{{ t('attendance', 'Deselect all') }}
					</NcButton>
					<span v-if="selectedAppointments.length > 0" class="selection-count">
						{{ n('attendance', '{count} appointment selected', '{count} appointments selected', selectedAppointments.length, { count: selectedAppointments.length }) }}
					</span>
				</div>

				<div class="appointment-list">
					<div
						v-for="appointment in availableAppointments"
						:key="appointment.id"
						class="appointment-checkbox-item">
						<input
							:id="`appointment-${appointment.id}`"
							v-model="selectedAppointments"
							:value="appointment.id"
							type="checkbox"
							class="appointment-checkbox">
						<label :for="`appointment-${appointment.id}`" class="appointment-label">
							<span class="appointment-item">
								<strong>{{ appointment.name }}</strong>
								<br>
								<span class="appointment-date">{{ formatDateTime(appointment.startDatetime) }}</span>
							</span>
						</label>
					</div>
				</div>
			</div>

			<!-- Date Range Options -->
			<div v-if="filterType === 'dateRange'" class="filter-section">
				<h3>{{ t('attendance', 'Date range') }}</h3>

				<div class="radio-group">
					<NcCheckboxRadioSwitch
						v-model="dateRangePreset"
						value="month"
						name="date_preset"
						type="radio">
						{{ t('attendance', 'Current month') }}
					</NcCheckboxRadioSwitch>

					<NcCheckboxRadioSwitch
						v-model="dateRangePreset"
						value="quarter"
						name="date_preset"
						type="radio">
						{{ t('attendance', 'Current quarter') }}
					</NcCheckboxRadioSwitch>

					<NcCheckboxRadioSwitch
						v-model="dateRangePreset"
						value="year"
						name="date_preset"
						type="radio">
						{{ t('attendance', 'Current year') }}
					</NcCheckboxRadioSwitch>

					<NcCheckboxRadioSwitch
						v-model="dateRangePreset"
						value="custom"
						name="date_preset"
						type="radio">
						{{ t('attendance', 'Custom range') }}
					</NcCheckboxRadioSwitch>
				</div>

				<!-- Custom Date Range Inputs -->
				<div v-if="dateRangePreset === 'custom'" class="date-inputs">
					<div class="date-input">
						<label>{{ t('attendance', 'Start date') }}</label>
						<input
							v-model="customStartDate"
							type="date"
							class="date-field">
					</div>
					<div class="date-input">
						<label>{{ t('attendance', 'End date') }}</label>
						<input
							v-model="customEndDate"
							type="date"
							class="date-field">
					</div>
				</div>

				<!-- Date Range Preview -->
				<div v-if="dateRangePreset !== 'custom'" class="date-preview">
					<p><strong>{{ t('attendance', 'Date range') }}:</strong> {{ getDateRangePreview() }}</p>
				</div>
			</div>

			<!-- Export Options -->
			<div class="filter-section">
				<h3>{{ t('attendance', 'Export options') }}</h3>
				<NcCheckboxRadioSwitch
					v-model="includeComments"
					type="checkbox">
					{{ t('attendance', 'Include comments in export') }}
				</NcCheckboxRadioSwitch>
			</div>

			<!-- Export Button -->
			<div class="button-row">
				<NcButton
					:disabled="!canExport || exporting"
					type="primary"
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
import { ref, computed, watch } from 'vue'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'

import { NcModal, NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcLoadingIcon } from '@nextcloud/vue'

import DownloadIcon from 'vue-material-design-icons/Download.vue'

import { formatDateTime } from '../utils/datetime.js'

const props = defineProps({
	show: {
		type: Boolean,
		required: true,
	},
	availableAppointments: {
		type: Array,
		default: () => [],
	},
})

const emit = defineEmits(['close'])

// Export filter options
const filterType = ref('all')
const selectedAppointments = ref([])
const dateRangePreset = ref('month')
const customStartDate = ref('')
const customEndDate = ref('')
const includeComments = ref(false)
const exporting = ref(false)

// Watch filter type changes to reset selections
watch(filterType, (newType) => {
	if (newType !== 'selected') {
		selectedAppointments.value = []
	}
})

// Computed properties
const canExport = computed(() => {
	if (filterType.value === 'all') return true
	if (filterType.value === 'selected') return selectedAppointments.value.length > 0
	if (filterType.value === 'dateRange' && dateRangePreset.value === 'custom') {
		return customStartDate.value !== '' && customEndDate.value !== ''
	}
	return filterType.value === 'dateRange'
})

// Methods (toggleAppointment removed - using v-model directly)

const getDateRangePreview = () => {
	const now = new Date()

	switch (dateRangePreset.value) {
		case 'month':
			const monthStart = new Date(now.getFullYear(), now.getMonth(), 1)
			const monthEnd = new Date(now.getFullYear(), now.getMonth() + 1, 0)
			return `${formatDate(monthStart)} - ${formatDate(monthEnd)}`

		case 'quarter':
			const quarter = Math.floor(now.getMonth() / 3)
			const quarterStart = new Date(now.getFullYear(), quarter * 3, 1)
			const quarterEnd = new Date(now.getFullYear(), quarter * 3 + 3, 0)
			return `${formatDate(quarterStart)} - ${formatDate(quarterEnd)}`

		case 'year':
			const yearStart = new Date(now.getFullYear(), 0, 1)
			const yearEnd = new Date(now.getFullYear(), 11, 31)
			return `${formatDate(yearStart)} - ${formatDate(yearEnd)}`

		default:
			return ''
	}
}

const formatDate = (date) => {
	return date.toLocaleDateString()
}

const selectAllAppointments = () => {
	selectedAppointments.value = availableAppointments.value.map(appointment => appointment.id)
}

const deselectAllAppointments = () => {
	selectedAppointments.value = []
}


const handleExport = async () => {
	if (!canExport.value) return

	exporting.value = true

	try {
		const exportData = buildExportData()
		const response = await axios.post(generateUrl('/apps/attendance/api/export'), exportData)

		if (response.data.success) {
			showSuccess(t('attendance', 'Export created: {filename}', { filename: response.data.filename }))

			// Redirect to Files app to show the exported file
			const filesUrl = generateUrl('/apps/files/?dir=/Attendance')
			window.location.href = filesUrl

			emit('close')
		} else {
			showError(t('attendance', 'Failed to export appointments'))
		}
	} catch (error) {
		console.error('Failed to export appointments:', error)
		const errorMessage = error.response?.data?.error || t('attendance', 'Failed to export appointments')
		showError(errorMessage)
	} finally {
		exporting.value = false
	}
}

const buildExportData = () => {
	const data = {
		includeComments: includeComments.value
	}

	if (filterType.value === 'selected') {
		data.appointmentIds = selectedAppointments.value
		data.preset = 'all'
	} else if (filterType.value === 'dateRange') {
		data.preset = dateRangePreset.value

		if (dateRangePreset.value === 'custom') {
			data.startDate = customStartDate.value
			data.endDate = customEndDate.value
		}
	} else {
		data.preset = 'all'
	}

	return data
}

// Reset form when dialog closes
watch(() => props.show, (show) => {
	if (!show) {
		filterType.value = 'all'
		selectedAppointments.value = []
		dateRangePreset.value = 'month'
		customStartDate.value = ''
		customEndDate.value = ''
		includeComments.value = false
		exporting.value = false
	}
})
</script>

<style scoped>
.export-dialog {
	padding: 20px;
	min-width: 500px;
}

.export-dialog h2 {
	margin: 0 0 20px 0;
	font-size: 1.5em;
}

.export-dialog h3 {
	margin: 20px 0 10px 0;
	font-size: 1.2em;
}

.filter-section {
	margin-bottom: 20px;
}

.info-card {
	margin-bottom: 15px;
}

.radio-group {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.select-controls {
	display: flex;
	gap: 10px;
	align-items: center;
	margin-bottom: 10px;
	padding: 10px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.selection-count {
	color: var(--color-text-lighter);
	font-size: 0.9em;
	margin-left: auto;
}

.appointment-list {
	max-height: 300px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 10px;
}

.appointment-checkbox-item {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	margin-bottom: 10px;
	padding: 8px;
	border-radius: var(--border-radius);
}

.appointment-checkbox-item:hover {
	background-color: var(--color-background-hover);
}

.appointment-checkbox {
	margin-top: 2px;
	cursor: pointer;
}

.appointment-label {
	cursor: pointer;
	flex: 1;
}

.appointment-item {
	display: block;
}

.appointment-date {
	color: var(--color-text-lighter);
	font-size: 0.9em;
}

.date-inputs {
	display: flex;
	gap: 20px;
	margin-top: 15px;
}

.date-input {
	flex: 1;
}

.date-input label {
	display: block;
	margin-bottom: 5px;
	font-weight: bold;
}

.date-field {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.date-preview {
	margin-top: 10px;
	padding: 10px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.button-row {
	display: flex;
	justify-content: flex-end;
	margin-top: 20px;
	padding-top: 15px;
	border-top: 1px solid var(--color-border);
}
</style>