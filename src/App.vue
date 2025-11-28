<template>
	<NcContent app-name="attendance">
		<!-- Navigation sidebar (hidden during checkin) -->
		<NcAppNavigation v-if="currentView !== 'checkin'">
			<template #list>
				<NcAppNavigationItem 
					:name="t('attendance', 'Current Appointments')"
					:open="currentAppointmentsOpen"
					:active="currentView === 'current'"
					@click.prevent="setView('current')">
					<template #icon>
						<CalendarIcon :size="20" />
					</template>
					<!-- Nested current/upcoming appointments -->
					<template v-if="currentAppointments.length > 0">
						<NcAppNavigationItem
							v-for="appointment in currentAppointments"
							:key="appointment.id"
							:name="appointment.name"
							:active="currentView === 'appointment' && appointmentDetailId === appointment.id"
							@click.prevent="navigateToAppointment(appointment.id)">
							<template #icon>
								<HelpCircleOutline v-if="!appointment.userResponse" :size="20" />
								<ChevronRightIcon v-else :size="20" />
							</template>
						</NcAppNavigationItem>
					</template>
				</NcAppNavigationItem>
				
				<NcAppNavigationItem 
					:name="t('attendance', 'Past Appointments')"
					:open="pastAppointmentsOpen"
					:active="currentView === 'past'"
					@click.prevent="setView('past')">
					<template #icon>
						<CalendarClockIcon :size="20" />
					</template>
					<!-- Nested past appointments -->
					<template v-if="pastAppointments.length > 0">
						<NcAppNavigationItem
							v-for="appointment in pastAppointments"
							:key="appointment.id"
							:name="appointment.name"
							:active="currentView === 'appointment' && appointmentDetailId === appointment.id"
							@click.prevent="navigateToAppointment(appointment.id)">
							<template #icon>
								<ChevronRightIcon :size="20" />
							</template>
						</NcAppNavigationItem>
					</template>
				</NcAppNavigationItem>
			</template>
			
			<!-- Bottom button for creating new appointment -->
			<template #footer>
				<NcAppNavigationItem
					v-if="canManageAppointments"
					:name="t('attendance', 'Create Appointment')"
					@click.prevent="createNewAppointment">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					v-if="canManageAppointments"
					:name="t('attendance', 'Export')"
					@click.prevent="exportAppointments">
					<template #icon>
						<DownloadIcon :size="20" />
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<!-- Main content area -->
		<NcAppContent>
			<!-- Check-in View -->
			<CheckinView v-if="currentView === 'checkin'" :appointment-id="checkinAppointmentId" />
			
			<!-- Appointment Detail View -->
			<AppointmentDetail v-else-if="currentView === 'appointment'" :appointment-id="appointmentDetailId" />
			
			<!-- All Appointments View -->
			<AllAppointments v-else-if="currentView === 'current' || currentView === 'past'" 
				:show-past="currentView === 'past'" 
				:key="currentView" />
			
			<!-- Loading state while routing is determined -->
			<div v-else class="loading-state">
				<div class="loading-spinner"></div>
			</div>
		</NcAppContent>
		
		<!-- Create Appointment Modal -->
		<AppointmentFormModal
			:show="showCreateForm"
			:appointment="null"
			@close="handleCreateModalClose"
			@submit="handleCreateModalSubmit" />
	</NcContent>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue'
import CheckinView from './views/Checkin.vue'
import AllAppointments from './views/AllAppointments.vue'
import AppointmentDetail from './views/AppointmentDetail.vue'
import AppointmentFormModal from './components/appointment/AppointmentFormModal.vue'
import { NcContent, NcAppNavigation, NcAppNavigationItem, NcAppContent } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { fromZonedTime } from 'date-fns-tz'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import CalendarClockIcon from 'vue-material-design-icons/CalendarClock.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'

const currentView = ref(null) // 'current', 'past', 'appointment', 'checkin', or null
const checkinAppointmentId = ref(null)
const appointmentDetailId = ref(null)
const currentAppointments = ref([])
const pastAppointments = ref([])
const currentAppointmentsOpen = ref(true)
const pastAppointmentsOpen = ref(false)
const canManageAppointments = ref(false)
const showCreateForm = ref(false)

const setView = (view) => {
	currentView.value = view
	
	// Update URL based on view
	const baseUrl = window.location.pathname.replace(/\/(past|appointment\/\d+|checkin\/\d+)?$/, '')
	let newUrl = baseUrl
	
	if (view === 'past') {
		newUrl = baseUrl + '/past'
	} else if (view === 'current') {
		newUrl = baseUrl
	}
	
	// Update browser history
	window.history.pushState({ view }, '', newUrl)
}

const navigateToAppointment = (appointmentId) => {
	currentView.value = 'appointment'
	appointmentDetailId.value = appointmentId
	
	// Determine which section this appointment belongs to and open it
	const isCurrentAppointment = currentAppointments.value.some(apt => apt.id === appointmentId)
	const isPastAppointment = pastAppointments.value.some(apt => apt.id === appointmentId)
	
	if (isCurrentAppointment) {
		currentAppointmentsOpen.value = true
		pastAppointmentsOpen.value = false
	} else if (isPastAppointment) {
		currentAppointmentsOpen.value = false
		pastAppointmentsOpen.value = true
	}
	
	// Update URL
	const baseUrl = window.location.pathname.replace(/\/(past|appointment\/\d+|checkin\/\d+)?$/, '')
	const newUrl = baseUrl + '/appointment/' + appointmentId
	
	// Update browser history
	window.history.pushState({ view: 'appointment', appointmentId }, '', newUrl)
}

const loadAppointments = async () => {
	try {
		// Load current/upcoming appointments (end_datetime >= now)
		const currentResponse = await axios.get(generateUrl('/apps/attendance/api/appointments'), {
			params: { showPastAppointments: false },
		})
		currentAppointments.value = currentResponse.data
		
		// Load past appointments (end_datetime < now)
		const pastResponse = await axios.get(generateUrl('/apps/attendance/api/appointments'), {
			params: { showPastAppointments: true },
		})
		pastAppointments.value = pastResponse.data
	} catch (error) {
		console.error('Failed to load appointments for navigation:', error)
	}
}

const loadPermissions = async () => {
	try {
		const response = await axios.get(generateUrl('/apps/attendance/api/user/permissions'))
		canManageAppointments.value = response.data.canManageAppointments
	} catch (error) {
		console.error('Failed to load permissions:', error)
	}
}

const createNewAppointment = () => {
	showCreateForm.value = true
}

const handleCreateModalClose = () => {
	showCreateForm.value = false
}

const handleCreateModalSubmit = async (formData) => {
	try {
		const startDatetimeWithTz = fromZonedTime(formData.startDatetime, 'Europe/Berlin')
		const endDatetimeWithTz = fromZonedTime(formData.endDatetime, 'Europe/Berlin')
		
		await axios.post(generateUrl('/apps/attendance/api/appointments'), {
			name: formData.name,
			description: formData.description,
			startDatetime: startDatetimeWithTz,
			endDatetime: endDatetimeWithTz,
		})
		
		showSuccess(t('attendance', 'Appointment created successfully'))
		handleCreateModalClose()
		
		// Reload appointments in sidebar and refresh current view
		await loadAppointments()
		
		// If not on current view, navigate there
		if (currentView.value !== 'current') {
			setView('current')
		}
	} catch (error) {
		console.error('Failed to create appointment:', error)
		showError(t('attendance', 'Error creating appointment'))
	}
}

const exportAppointments = async () => {
	try {
		const response = await axios.post(generateUrl('/apps/attendance/api/export'))
		
		if (response.data.success) {
			showSuccess(t('attendance', 'Export created successfully: {filename}', { filename: response.data.filename }))
			
			// Navigate to the Attendance folder in Files app
			const filesUrl = generateUrl('/apps/files/?dir=/Attendance')
			window.location.href = filesUrl
		} else {
			showError(t('attendance', 'Failed to export appointments'))
		}
	} catch (error) {
		console.error('Failed to export appointments:', error)
		const errorMessage = error.response?.data?.error || t('attendance', 'Failed to export appointments')
		showError(errorMessage)
	}
}

const checkRouting = () => {
	// Check current URL path
	const path = window.location.pathname
	const checkinMatch = path.match(/\/checkin\/(\d+)/)
	const appointmentMatch = path.match(/\/appointment\/(\d+)/)
	const isPastRoute = path.endsWith('/past')
	
	if (checkinMatch) {
		currentView.value = 'checkin'
		checkinAppointmentId.value = parseInt(checkinMatch[1])
		appointmentDetailId.value = null
	} else if (appointmentMatch) {
		currentView.value = 'appointment'
		appointmentDetailId.value = parseInt(appointmentMatch[1])
		checkinAppointmentId.value = null
	} else if (isPastRoute) {
		currentView.value = 'past'
		checkinAppointmentId.value = null
		appointmentDetailId.value = null
	} else {
		currentView.value = 'current' // Default to current appointments
		checkinAppointmentId.value = null
		appointmentDetailId.value = null
	}
}

onMounted(async () => {
	checkRouting()
	await loadPermissions()
	await loadAppointments()
	
	// Listen for browser back/forward navigation
	window.addEventListener('popstate', () => {
		checkRouting()
	})
})
</script>

<style scoped>
.loading-state {
	display: flex;
	justify-content: center;
	align-items: center;
	height: 200px;
}

.loading-spinner {
	width: 32px;
	height: 32px;
	border: 3px solid var(--color-loading-light);
	border-top: 3px solid var(--color-loading-dark);
	border-radius: 50%;
	animation: spin 1s linear infinite;
}

@keyframes spin {
	0% { transform: rotate(0deg); }
	100% { transform: rotate(360deg); }
}
</style>

<style>
/* Global Nextcloud 31 fallback for NcChip text color */
/* Only apply to colored chip variants that need white text */
#attendance[data-nc-version="31"] .nc-chip--error .nc-chip__text,
#attendance[data-nc-version="31"] .nc-chip--warning .nc-chip__text,
#attendance[data-nc-version="31"] .nc-chip--success .nc-chip__text {
	color: white !important;
}

/* Nextcloud 31 Dark Mode: Warning elements need black text for better contrast */
body[data-theme-dark] #attendance[data-nc-version="31"] .nc-chip--warning .nc-chip__text {
	color: black !important;
}

body[data-theme-dark] #attendance[data-nc-version="31"] .button-vue--warning .button-vue__text {
	color: black !important;
}

/* Also handle default dark mode preference */
@media (prefers-color-scheme: dark) {
	body[data-theme-default] #attendance[data-nc-version="31"] .nc-chip--warning .nc-chip__text {
		color: black !important;
	}
	
	body[data-theme-default] #attendance[data-nc-version="31"] .button-vue--warning .button-vue__text {
		color: black !important;
	}
}

/* Keep textarea placeholder visible in comment sections */
.comment-section .textarea__input::placeholder {
	opacity: 1 !important;
	visibility: visible !important;
}

.comment-section .textarea__input:not(:focus)::placeholder {
	opacity: 1 !important;
	visibility: visible !important;
}
</style>
