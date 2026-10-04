<template>
	<NcModal
		v-if="show"
		:name="t('attendance', 'Export appointments')"
		size="normal"
		@close="$emit('close')">
		<div class="export-dialog">
			<h2>{{ t("attendance", "Export appointments") }}</h2>

			<!-- Filter Type Selection -->
			<div class="filter-section">
				<h3>{{ t("attendance", "Filter options") }}</h3>

				<div class="radio-group">
					<NcCheckboxRadioSwitch
						v-model="filterType"
						value="all"
						name="filter_type"
						type="radio">
						{{ t("attendance", "All appointments") }}
					</NcCheckboxRadioSwitch>

					<NcCheckboxRadioSwitch
						v-model="filterType"
						value="selected"
						name="filter_type"
						type="radio">
						{{ t("attendance", "Selected appointments") }}
					</NcCheckboxRadioSwitch>

					<NcCheckboxRadioSwitch
						v-model="filterType"
						value="series"
						name="filter_type"
						type="radio">
						{{ t("attendance", "Appointment series") }}
					</NcCheckboxRadioSwitch>

					<NcCheckboxRadioSwitch
						v-model="filterType"
						value="dateRange"
						name="filter_type"
						type="radio">
						{{ t("attendance", "Date range") }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>

			<!-- Selected Appointments -->
			<div v-if="filterType === 'selected'" class="filter-section">
				<div class="event-list-header">
					<label class="section-label">{{
						t("attendance", "Select appointments")
					}}</label>
					<div class="selection-actions">
						<NcButton
							variant="tertiary"
							:disabled="
								selectedAppointments.length
									=== (availableAppointments?.length ?? 0)
							"
							@click="selectAllAppointments">
							{{ t("attendance", "Select all") }}
						</NcButton>
						<NcButton
							variant="tertiary"
							:disabled="selectedAppointments.length === 0"
							@click="deselectAllAppointments">
							{{ t("attendance", "Deselect all") }}
						</NcButton>
					</div>
				</div>

				<NcLoadingIcon v-if="availableAppointments === null" :size="24" />

				<ul v-else class="event-list">
					<li
						v-for="appointment in availableAppointments"
						:key="appointment.id"
						class="event-item"
						@click="toggleAppointment(appointment.id)">
						<NcCheckboxRadioSwitch
							:modelValue="
								selectedAppointments.includes(appointment.id)
							"
							class="event-checkbox">
							<div class="event-info">
								<span class="event-name">{{
									appointment.name
								}}</span>
								<span class="event-date">{{
									formatStart(appointment.startDatetime, appointment.isAllDay)
								}}</span>
							</div>
						</NcCheckboxRadioSwitch>
					</li>
				</ul>
			</div>

			<!-- Series Selection -->
			<div v-if="filterType === 'series'" class="filter-section">
				<label class="section-label">{{
					t("attendance", "Select series")
				}}</label>

				<NcLoadingIcon v-if="series === null" :size="24" />

				<NcEmptyContent
					v-else-if="series.length === 0"
					:name="t('attendance', 'No series found')"
					:description="
						t(
							'attendance',
							'Series appear here once an appointment repeats regularly.',
						)
					">
					<template #icon>
						<RepeatIcon :size="20" />
					</template>
				</NcEmptyContent>

				<template v-else>
					<NcTextField
						v-if="showSeriesSearch"
						v-model="seriesSearchQuery"
						:placeholder="t('attendance', 'Search series …')"
						class="series-search" />

					<p v-if="filteredSeries.length === 0" class="no-matches">
						{{ t("attendance", "No series match your search") }}
					</p>

					<div v-if="ongoingSeries.length" class="series-group">
						<h4 class="series-group-title">
							{{ t("attendance", "Ongoing and upcoming") }}
						</h4>
						<ExportSeriesList
							:entries="ongoingSeries"
							:selected="selectedSeries"
							@toggle="toggleSeries" />
					</div>

					<div v-if="finishedSeries.length" class="series-group">
						<NcButton
							variant="tertiary"
							class="series-group-toggle"
							@click="showFinishedSeries = !showFinishedSeries">
							<template #icon>
								<ChevronDownIcon
									v-if="showFinishedSeries"
									:size="20" />
								<ChevronRightIcon v-else :size="20" />
							</template>
							{{
								t("attendance", "Finished ({count})", {
									count: finishedSeries.length,
								})
							}}
						</NcButton>

						<ExportSeriesList
							v-if="showFinishedSeries"
							:entries="finishedSeries"
							:selected="selectedSeries"
							@toggle="toggleSeries" />
					</div>
				</template>
			</div>

			<!-- Date Range Options -->
			<div v-if="filterType === 'dateRange'" class="filter-section">
				<h3>{{ t("attendance", "Date range") }}</h3>

				<div class="radio-group">
					<NcCheckboxRadioSwitch
						v-model="dateRangePreset"
						value="month"
						name="date_preset"
						type="radio">
						{{ t("attendance", "Current month") }}
					</NcCheckboxRadioSwitch>

					<NcCheckboxRadioSwitch
						v-model="dateRangePreset"
						value="quarter"
						name="date_preset"
						type="radio">
						{{ t("attendance", "Current quarter") }}
					</NcCheckboxRadioSwitch>

					<NcCheckboxRadioSwitch
						v-model="dateRangePreset"
						value="year"
						name="date_preset"
						type="radio">
						{{ t("attendance", "Current year") }}
					</NcCheckboxRadioSwitch>

					<NcCheckboxRadioSwitch
						v-model="dateRangePreset"
						value="custom"
						name="date_preset"
						type="radio">
						{{ t("attendance", "Custom range") }}
					</NcCheckboxRadioSwitch>
				</div>

				<!-- Custom Date Range Inputs -->
				<div v-if="dateRangePreset === 'custom'" class="date-inputs">
					<div class="date-input">
						<label>{{ t("attendance", "Start date") }}</label>
						<input
							v-model="customStartDate"
							type="date"
							class="date-field">
					</div>
					<div class="date-input">
						<label>{{ t("attendance", "End date") }}</label>
						<input
							v-model="customEndDate"
							type="date"
							class="date-field">
					</div>
				</div>

				<!-- Date Range Preview -->
				<div v-if="dateRangePreset !== 'custom'" class="date-preview">
					<p>
						<strong>{{ t("attendance", "Date range") }}:</strong>
						{{ getDateRangePreview() }}
					</p>
				</div>
			</div>

			<!-- Export Options -->
			<div class="filter-section">
				<h3>{{ t("attendance", "Export options") }}</h3>

				<ExportColumnOptions
					v-model="columns"
					:hasAnyColumn="hasAnyColumn" />
			</div>

			<!-- Export Button -->
			<div class="button-row">
				<NcButton
					:disabled="!canExport || exporting"
					variant="primary"
					@click="handleExport">
					<template #icon>
						<NcLoadingIcon v-if="exporting" :size="20" />
						<DownloadIcon v-else :size="20" />
					</template>
					{{
						exporting
							? t("attendance", "Exporting …")
							: t("attendance", "Export")
					}}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcEmptyContent,
	NcLoadingIcon,
	NcModal,
	NcTextField,
} from '@nextcloud/vue'
import { computed, ref, watch } from 'vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import RepeatIcon from 'vue-material-design-icons/Repeat.vue'
import ExportColumnOptions from './export/ExportColumnOptions.vue'
import ExportSeriesList from './export/ExportSeriesList.vue'
import { useExportColumns } from '../composables/useExportColumns.js'
import { useExportRequest } from '../composables/useExportRequest.js'
import { formatDate, formatStart } from '../utils/datetime.js'
import { toggled } from '../utils/statisticsColumns.js'

const props = defineProps({
	show: {
		type: Boolean,
		required: true,
	},
})

const emit = defineEmits(['close'])

// Export filter options
const filterType = ref('all')
const selectedAppointments = ref([])
const selectedSeries = ref([])
const dateRangePreset = ref('month')
const customStartDate = ref('')
const customEndDate = ref('')

const { columns, hasAnyColumn, resetColumns } = useExportColumns()
const { exporting, runExport } = useExportRequest(t('attendance', 'Failed to export appointments'))

// null until the series filter is picked for the first time, which is also
// what tells the template to show the spinner rather than "no series".
const series = ref(null)
const seriesSearchQuery = ref('')
const showFinishedSeries = ref(false)

// null until the appointment filter is picked, like the series above: the
// sidebar only ever holds a page of the past appointments, not all of them.
const availableAppointments = ref(null)

// Watch filter type changes to reset selections
watch(filterType, (newType) => {
	if (newType !== 'selected') {
		selectedAppointments.value = []
	} else if (availableAppointments.value === null) {
		loadAvailableAppointments()
	}
	if (newType !== 'series') {
		selectedSeries.value = []
		seriesSearchQuery.value = ''
	} else if (series.value === null) {
		loadSeries()
	}
})

async function loadAvailableAppointments() {
	try {
		const response = await axios.get(generateUrl('/apps/attendance/api/appointments/navigation'))
		availableAppointments.value = [...response.data.current, ...response.data.past]
	} catch (error) {
		console.error('Failed to load appointments for the export:', error)
		showError(t('attendance', 'Failed to load appointment data'))
		availableAppointments.value = []
	}
}

async function loadSeries() {
	try {
		const response = await axios.get(generateUrl('/apps/attendance/api/export/series'))
		series.value = response.data
	} catch (error) {
		console.error('Failed to load appointment series:', error)
		showError(t('attendance', 'Failed to load appointment series'))
		series.value = []
	}
}

// Computed properties
const showSeriesSearch = computed(() => (series.value?.length ?? 0) > 5)

const filteredSeries = computed(() => {
	const entries = series.value ?? []
	const query = seriesSearchQuery.value.toLowerCase().trim()
	if (!query) return entries

	return entries.filter((entry) => entry.name.toLowerCase().includes(query))
})

const ongoingSeries = computed(() => filteredSeries.value.filter((entry) => entry.ongoing))

const finishedSeries = computed(() => filteredSeries.value.filter((entry) => !entry.ongoing))

const canExport = computed(() => {
	if (!hasAnyColumn.value) return false
	if (filterType.value === 'all') return true
	if (filterType.value === 'selected') return selectedAppointments.value.length > 0
	if (filterType.value === 'series') return selectedSeries.value.length > 0
	if (
		filterType.value === 'dateRange'
		&& dateRangePreset.value === 'custom'
	) {
		return customStartDate.value !== '' && customEndDate.value !== ''
	}
	return filterType.value === 'dateRange'
})

function toggleSeries(seriesId) {
	selectedSeries.value = toggled(selectedSeries.value, seriesId)
}

function getDateRangePreview() {
	const now = new Date()

	switch (dateRangePreset.value) {
		case 'month': {
			const monthStart = new Date(now.getFullYear(), now.getMonth(), 1)
			const monthEnd = new Date(now.getFullYear(), now.getMonth() + 1, 0)
			return `${formatDate(monthStart)} - ${formatDate(monthEnd)}`
		}
		case 'quarter': {
			const quarter = Math.floor(now.getMonth() / 3)
			const quarterStart = new Date(now.getFullYear(), quarter * 3, 1)
			const quarterEnd = new Date(now.getFullYear(), quarter * 3 + 3, 0)
			return `${formatDate(quarterStart)} - ${formatDate(quarterEnd)}`
		}
		case 'year': {
			const yearStart = new Date(now.getFullYear(), 0, 1)
			const yearEnd = new Date(now.getFullYear(), 11, 31)
			return `${formatDate(yearStart)} - ${formatDate(yearEnd)}`
		}
		default:
			return ''
	}
}

function toggleAppointment(id) {
	const index = selectedAppointments.value.indexOf(id)
	if (index === -1) {
		selectedAppointments.value = [...selectedAppointments.value, id]
	} else {
		selectedAppointments.value = selectedAppointments.value.filter((a) => a !== id)
	}
}

function selectAllAppointments() {
	selectedAppointments.value = (availableAppointments.value ?? []).map((appointment) => appointment.id)
}

function deselectAllAppointments() {
	selectedAppointments.value = []
}

async function handleExport() {
	if (!canExport.value) return

	if (await runExport(buildExportData())) {
		emit('close')
	}
}

function getCalculatedDateRange() {
	const now = new Date()

	switch (dateRangePreset.value) {
		case 'month': {
			const monthStart = new Date(now.getFullYear(), now.getMonth(), 1)
			const monthEnd = new Date(now.getFullYear(), now.getMonth() + 1, 0)
			return {
				startDate: formatIsoDate(monthStart),
				endDate: formatIsoDate(monthEnd),
			}
		}
		case 'quarter': {
			const quarter = Math.floor(now.getMonth() / 3)
			const quarterStart = new Date(now.getFullYear(), quarter * 3, 1)
			const quarterEnd = new Date(now.getFullYear(), quarter * 3 + 3, 0)
			return {
				startDate: formatIsoDate(quarterStart),
				endDate: formatIsoDate(quarterEnd),
			}
		}
		case 'year': {
			const yearStart = new Date(now.getFullYear(), 0, 1)
			const yearEnd = new Date(now.getFullYear(), 11, 31)
			return {
				startDate: formatIsoDate(yearStart),
				endDate: formatIsoDate(yearEnd),
			}
		}
		case 'custom':
			return {
				startDate: customStartDate.value,
				endDate: customEndDate.value,
			}
		default:
			return {}
	}
}

function formatIsoDate(date) {
	const year = date.getFullYear()
	const month = String(date.getMonth() + 1).padStart(2, '0')
	const day = String(date.getDate()).padStart(2, '0')
	return `${year}-${month}-${day}`
}

function buildExportData() {
	const data = { ...columns.value }

	if (filterType.value === 'selected') {
		data.appointmentIds = selectedAppointments.value
	} else if (filterType.value === 'series') {
		data.seriesIds = selectedSeries.value
	} else if (filterType.value === 'dateRange') {
		const { startDate, endDate } = getCalculatedDateRange()
		data.startDate = startDate
		data.endDate = endDate
	}

	return data
}

// Reset form when dialog closes
watch(
	() => props.show,
	(show) => {
		if (!show) {
			filterType.value = 'all'
			selectedAppointments.value = []
			// Refetched next time, so the list never shows a stale state.
			availableAppointments.value = null
			selectedSeries.value = []
			seriesSearchQuery.value = ''
			showFinishedSeries.value = false
			dateRangePreset.value = 'month'
			customStartDate.value = ''
			customEndDate.value = ''
			resetColumns()
		}
	},
)
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

.section-label {
    display: block;
    margin-bottom: 12px;
    font-weight: 600;
}

.event-list-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 4px;
}

.event-list-header .section-label {
    margin-bottom: 0;
}

.selection-actions {
    display: flex;
    gap: 4px;
}

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

.event-name {
    flex: 1;
    font-weight: 500;
}

.event-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.event-date {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

.series-search {
    margin-bottom: 12px;
}

.series-group + .series-group {
    margin-top: 12px;
}

.series-group-title {
    margin: 0 0 8px 0;
    font-size: 13px;
    font-weight: 600;
    color: var(--color-text-maxcontrast);
}

.series-group-toggle {
    margin-bottom: 8px;
}

.no-matches {
    margin: 0;
    color: var(--color-text-maxcontrast);
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
