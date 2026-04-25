<template>
	<div class="attendance-container">
		<!-- Unanswered reminder banner (shown on upcoming view when there are unanswered appointments) -->
		<div v-if="!showUnanswered && !showPast && !loading && unansweredCount > 0" class="unanswered-banner-container">
			<div class="unanswered-banner pending clickable" role="button" @click="emit('navigateToUnanswered')">
				<ProgressQuestion :size="20" />
				<span>{{ n('attendance', '%n appointment awaiting your response', '%n appointments awaiting your response', unansweredCount) }}</span>
				<span class="banner-action">{{ t('attendance', 'View all') }} →</span>
			</div>
		</div>

		<!-- Unanswered Banner (only shown on unanswered view after loading) -->
		<div v-if="showUnanswered && !loading" class="unanswered-banner-container">
			<div v-if="appointments.length > 0" class="unanswered-banner pending">
				<ProgressQuestion :size="20" />
				<span>{{ n('attendance', '%n appointment awaiting your response', '%n appointments awaiting your response', appointments.length) }}</span>
			</div>
			<div v-else class="unanswered-banner complete">
				<span>{{ t('attendance', 'Hurray! You responded to all appointments.') }}</span>
				<NcButton
					@click="goToUpcoming">
					{{ t('attendance', 'Show upcoming appointments') }}
				</NcButton>
			</div>
		</div>

		<!-- Search + filter controls -->
		<div
			v-if="!loading && (appointments.length > 0 || searchQuery || statusFilter || responseFilter)"
			class="filter-bar">
			<NcTextField
				:model-value="searchQuery"
				:label="t('attendance', 'Search appointments\u00A0…')"
				class="filter-bar__search"
				data-test="filter-search"
				@update:model-value="searchQuery = $event">
				<MagnifyIcon :size="16" />
			</NcTextField>
			<NcSelect
				v-if="!showUnanswered"
				v-model="statusFilter"
				:options="statusFilterOptions"
				:placeholder="t('attendance', 'Inquiry status')"
				:clearable="true"
				:searchable="false"
				label="label"
				class="filter-bar__select"
				data-test="filter-status" />
			<NcSelect
				v-if="!showUnanswered"
				v-model="responseFilter"
				:options="responseFilterOptions"
				:placeholder="t('attendance', 'Your response')"
				:clearable="true"
				:searchable="false"
				label="label"
				class="filter-bar__select"
				data-test="filter-response" />
		</div>

		<!-- Appointments List -->
		<div class="appointments-list">
			<div v-if="loading" class="loading">
				{{ t('attendance', 'Loading\u00A0…') }}
			</div>
			<div v-else-if="visibleAppointments.length === 0 && !showUnanswered" class="empty-state">
				{{ hasActiveFilters
					? t('attendance', 'No appointments match the active filters.')
					: t('attendance', 'No appointments found') }}
			</div>
			<div v-else>
				<!-- Use reusable AppointmentCard component -->
				<AppointmentCard
					v-for="appointment in visibleAppointments"
					:key="appointment.id"
					:appointment="appointment"
					:can-manage-appointments="permissions.canManageAppointments"
					:can-checkin="permissions.canCheckin"
					:can-see-response-overview="permissions.canSeeResponseOverview"
					:can-see-comments="permissions.canSeeComments"
					:display-order="config.displayOrder"
					@start-checkin="startCheckin"
					@edit="editAppointment"
					@copy="copyAppointment"
					@delete="deleteAppointment"
					@export="showExportDialog"
					@submit-response="submitResponse"
					@update-comment="updateComment"
					@closed-toggled="handleClosedToggled" />
			</div>
		</div>

		<!-- Single Appointment Export Dialog -->
		<SingleAppointmentExportDialog
			:show="exportDialogVisible"
			:appointment="selectedAppointmentForExport"
			@close="exportDialogVisible = false" />

		<!-- Delete Appointment Dialog -->
		<DeleteAppointmentDialog
			:show="showDeleteDialog"
			:appointment="pendingDeleteAppointment"
			@confirm="handleDeleteConfirm"
			@cancel="showDeleteDialog = false" />
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import AppointmentCard from '../components/appointment/AppointmentCard.vue'
import SingleAppointmentExportDialog from '../components/SingleAppointmentExportDialog.vue'
import DeleteAppointmentDialog from '../components/appointment/DeleteAppointmentDialog.vue'
import ProgressQuestion from 'vue-material-design-icons/ProgressQuestion.vue'
import { create as createConfetti } from 'canvas-confetti'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { usePermissions } from '../composables/usePermissions.js'
import { useAppointmentResponse } from '../composables/useAppointmentResponse.js'

const props = defineProps({
	showPast: {
		type: Boolean,
		default: false,
	},
	showUnanswered: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits(['responseUpdated', 'editAppointment', 'copyAppointment', 'navigateToUpcoming', 'navigateToUnanswered', 'appointmentDeleted'])

const appointments = ref([])
const exportDialogVisible = ref(false)
const selectedAppointmentForExport = ref(null)
const showDeleteDialog = ref(false)
const pendingDeleteAppointment = ref(null)

const goToUpcoming = () => {
	emit('navigateToUpcoming')
}

const unansweredCount = computed(() => {
	return appointments.value.filter(a => !a.userResponse && !a.closedAt).length
})

const FILTER_STORAGE_KEY = 'attendance:list-filters'

const statusFilterOptions = computed(() => [
	{ id: 'open', label: t('attendance', 'Open') },
	{ id: 'closed', label: t('attendance', 'Closed') },
])

const responseFilterOptions = computed(() => [
	{ id: 'yes', label: t('attendance', 'Yes') },
	{ id: 'maybe', label: t('attendance', 'Maybe') },
	{ id: 'no', label: t('attendance', 'No') },
	{ id: 'none', label: t('attendance', 'No response') },
])

const findOptionById = (options, id) =>
	id ? options.find((opt) => opt.id === id) ?? null : null

const loadStoredFilters = () => {
	try {
		const raw = window.localStorage.getItem(FILTER_STORAGE_KEY)
		if (!raw) return { search: '', status: null, response: null }
		const parsed = JSON.parse(raw)
		return {
			search: typeof parsed.search === 'string' ? parsed.search : '',
			status: findOptionById(statusFilterOptions.value, parsed.status),
			response: findOptionById(responseFilterOptions.value, parsed.response),
		}
	} catch (e) {
		return { search: '', status: null, response: null }
	}
}

const stored = loadStoredFilters()
const searchQuery = ref(stored.search)
const statusFilter = ref(stored.status)
const responseFilter = ref(stored.response)

// Debounce so per-keystroke search edits don't churn localStorage on the
// main thread. Same-value guard skips redundant writes (e.g. on first mount,
// when the watcher fires with the just-restored values).
let persistTimer = null
let lastPersistedJson = JSON.stringify({
	search: stored.search,
	status: stored.status?.id ?? null,
	response: stored.response?.id ?? null,
})
watch([searchQuery, statusFilter, responseFilter], ([search, status, response]) => {
	const next = JSON.stringify({
		search: search ?? '',
		status: status?.id ?? null,
		response: response?.id ?? null,
	})
	if (next === lastPersistedJson) return
	clearTimeout(persistTimer)
	persistTimer = setTimeout(() => {
		try {
			window.localStorage.setItem(FILTER_STORAGE_KEY, next)
			lastPersistedJson = next
		} catch (e) {
			// Storage may be unavailable (private mode, quota).
		}
	}, 300)
})

const hasActiveFilters = computed(() =>
	Boolean(searchQuery.value.trim() || statusFilter.value || responseFilter.value),
)

const visibleAppointments = computed(() => {
	const query = searchQuery.value.trim().toLowerCase()
	const status = statusFilter.value?.id ?? null
	const response = responseFilter.value?.id ?? null
	if (!query && !status && !response) {
		return appointments.value
	}
	return appointments.value.filter((appointment) => {
		if (query) {
			const haystack = `${appointment.name} ${appointment.description ?? ''}`.toLowerCase()
			if (!haystack.includes(query)) return false
		}
		if (status === 'open' && appointment.closedAt) return false
		if (status === 'closed' && !appointment.closedAt) return false
		if (response) {
			const userResponse = appointment.userResponse?.response ?? null
			if (response === 'none' && userResponse !== null) return false
			if (response !== 'none' && userResponse !== response) return false
		}
		return true
	})
})

const handleClosedToggled = (updated) => {
	const index = appointments.value.findIndex(a => a.id === updated.id)
	if (index !== -1) {
		appointments.value[index] = { ...appointments.value[index], ...updated }
	}
}
const loading = ref(true)
const responseComments = reactive({})

const { permissions, config, loadPermissions } = usePermissions()

// Use the shared response composable
const { submitResponse: submitResponseApi } = useAppointmentResponse({
	onSuccess: () => {
		emit('responseUpdated')
		loadAppointments(true)
	},
})

const loadAppointments = async (skipLoadingSpinner = false) => {
	try {
		if (!skipLoadingSpinner) {
			loading.value = true
		}
		const params = {}
		if (props.showPast) params.showPastAppointments = true
		if (props.showUnanswered) params.unansweredOnly = true
		const response = await axios.get(generateUrl('/apps/attendance/api/appointments'), { params })
		appointments.value = response.data

		appointments.value.forEach(appointment => {
			if (appointment.userResponse) {
				responseComments[appointment.id] = appointment.userResponse.comment || ''
			}
		})

		if (permissions.canManageAppointments) {
			await loadDetailedResponses()
		}
	} catch (error) {
		console.error('Failed to load appointments:', error)
	} finally {
		loading.value = false
	}
}

const loadDetailedResponses = async () => {
	for (const appointment of appointments.value) {
		try {
			const response = await axios.get(generateUrl(`/apps/attendance/api/appointments/${appointment.id}/responses`))
			appointment.detailedResponses = response.data
		} catch (error) {
			console.error(`Failed to load detailed responses for appointment ${appointment.id}:`, error)
		}
	}
}

const submitResponse = async (appointmentId, response) => {
	const appointment = appointments.value.find(a => a.id === appointmentId)
	const comment = appointment?.userResponse?.comment || ''
	await submitResponseApi(appointmentId, response, comment)
}

const updateComment = async (appointmentId, comment) => {
	const appointment = appointments.value.find(a => a.id === appointmentId)
	const response = appointment?.userResponse?.response || 'yes'
	await submitResponseApi(appointmentId, response, comment)
}

const deleteAppointment = (appointmentId) => {
	const appointment = appointments.value.find(a => a.id === appointmentId)
	pendingDeleteAppointment.value = appointment
	showDeleteDialog.value = true
}

const handleDeleteConfirm = async (scope) => {
	showDeleteDialog.value = false
	if (!pendingDeleteAppointment.value) return

	try {
		await axios.delete(generateUrl(`/apps/attendance/api/appointments/${pendingDeleteAppointment.value.id}`), {
			data: { scope },
		})
		await loadAppointments(true)
		emit('appointmentDeleted')
	} catch (error) {
		console.error('Failed to delete appointment:', error)
	}
}

const editAppointment = (appointment) => {
	emit('editAppointment', appointment)
}

const copyAppointment = (appointment) => {
	emit('copyAppointment', appointment)
}

const startCheckin = (appointmentId) => {
	window.location.href = generateUrl(`/apps/attendance/checkin/${appointmentId}`)
}

const showExportDialog = (appointmentId) => {
	const appointment = appointments.value.find(apt => apt.id === appointmentId)
	selectedAppointmentForExport.value = appointment
	exportDialogVisible.value = true
}

// Create confetti instance without worker to comply with NC 32+ CSP
let confettiInstance = null
let confettiCanvas = null
const getConfetti = () => {
	if (!confettiInstance) {
		confettiCanvas = document.createElement('canvas')
		confettiCanvas.style.position = 'fixed'
		confettiCanvas.style.top = '0'
		confettiCanvas.style.left = '0'
		confettiCanvas.style.width = '100%'
		confettiCanvas.style.height = '100%'
		confettiCanvas.style.pointerEvents = 'none'
		confettiCanvas.style.zIndex = '9999'
		document.body.appendChild(confettiCanvas)
		confettiInstance = createConfetti(confettiCanvas, { resize: true, useWorker: false })
	}
	return confettiInstance
}

onBeforeUnmount(() => {
	if (confettiInstance) {
		confettiInstance.reset()
		confettiInstance = null
	}
	if (confettiCanvas) {
		confettiCanvas.remove()
		confettiCanvas = null
	}
	clearTimeout(persistTimer)
})

const triggerConfetti = () => {
	getConfetti()({
		particleCount: 200,
		spread: 100,
		origin: { x: 0.5, y: 1 },
		angle: 90,
		startVelocity: 60,
	})
}

// Watch for when all appointments are answered
watch(loading, (isLoading) => {
	if (!isLoading && props.showUnanswered && appointments.value.length === 0) {
		triggerConfetti()
	}
})

// Also trigger confetti when appointments list becomes empty (after responding to last one)
watch(() => appointments.value.length, (newLength, oldLength) => {
	if (props.showUnanswered && !loading.value && newLength === 0 && oldLength > 0) {
		triggerConfetti()
	}
})

onMounted(async () => {
	await loadPermissions()
	await loadAppointments()
})
</script>

<style scoped lang="scss">
@use '../styles/shared.scss';

.attendance-container {
	padding: 20px;
	max-width: 1200px;
	margin: 0 auto;
}

.appointments-list {
	max-width: 800px;
	margin: 0 auto;

	.loading,
	.empty-state {
		text-align: center;
		padding: 40px;
		color: var(--color-text-lighter);
	}
}

.unanswered-banner-container {
	max-width: 800px;
	margin: 0 auto 20px;
}

.filter-bar {
	max-width: 800px;
	margin: 0 auto 16px;
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: flex-end;

	&__search {
		flex: 2 1 240px;
		min-width: 200px;
	}

	&__select {
		flex: 1 1 160px;
		min-width: 160px;
	}
}

.banner-action {
	margin-left: auto;
	font-weight: normal;
	white-space: nowrap;
	opacity: 0.85;
}

.unanswered-banner {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 16px 20px;
	border-radius: var(--border-radius-large);

	&.pending {
		background: #ff8c00;
		color: white;
		border-left: 4px solid #ff6600;

		&.clickable {
			cursor: pointer;

			* {
				cursor: pointer;
			}

			&:hover {
				background: #ff6600;
			}
		}
		font-weight: 600;
	}

	&.complete {
		flex-direction: column;
		text-align: center;
		color: var(--color-text-maxcontrast);
		gap: 16px;
	}
}
</style>
