<template>
	<NcContent app-name="attendance">
		<!-- Navigation sidebar (hidden during checkin) -->
		<NcAppNavigation v-if="currentView !== 'checkin'">
			<template #list>
				<!-- Unanswered Appointments Section -->
				<NcAppNavigationItem 
					v-if="unansweredAppointments.length > 0"
					:name="t('attendance', 'Unanswered')"
					:active="currentView === 'unanswered'"
					data-test="nav-unanswered"
					@click.prevent="setView('unanswered')">
					<template #icon>
						<BellAlertIcon :size="20" />
					</template>
					<!-- Nested unanswered appointments -->
					<template v-if="unansweredAppointments.length > 0">
						<NcAppNavigationItem
							v-for="appointment in unansweredAppointments"
							:key="appointment.id"
							:name="formatAppointmentDisplay(appointment)"
							:active="currentView === 'appointment' && appointmentDetailId === appointment.id"
							data-test="nav-unanswered-appointment"
							@click.prevent="navigateToAppointment(appointment.id)">
							<template #icon>
								<ProgressQuestion :size="20" />
							</template>
						</NcAppNavigationItem>
					</template>
				</NcAppNavigationItem>
				
				<NcAppNavigationItem 
					:name="t('attendance', 'Upcoming Appointments')"
					:active="currentView === 'current'"
					data-test="nav-upcoming"
					@click.prevent="setView('current')">
					<template #icon>
						<CalendarIcon :size="20" />
					</template>
					<!-- Nested current/upcoming appointments (only answered ones) -->
					<template v-if="answeredAppointments.length > 0">
						<NcAppNavigationItem
							v-for="appointment in answeredAppointments"
							:key="appointment.id"
							:name="formatAppointmentDisplay(appointment)"
							:active="currentView === 'appointment' && appointmentDetailId === appointment.id"
							data-test="nav-upcoming-appointment"
							@click.prevent="navigateToAppointment(appointment.id)">
							<template #icon>
								<CheckCircle v-if="appointment.userResponse?.response === 'yes'" :size="20" />
								<HelpCircle v-else-if="appointment.userResponse?.response === 'maybe'" :size="20" />
								<CloseCircle v-else-if="appointment.userResponse?.response === 'no'" :size="20" />
							</template>
						</NcAppNavigationItem>
					</template>
				</NcAppNavigationItem>
				
				<NcAppNavigationItem 
					:name="t('attendance', 'Past Appointments')"
					:active="currentView === 'past'"
					data-test="nav-past"
					@click.prevent="setView('past')">
					<template #icon>
						<CalendarClockIcon :size="20" />
					</template>
					<!-- Nested past appointments -->
					<template v-if="pastAppointments.length > 0">
						<NcAppNavigationItem
							v-for="appointment in pastAppointments"
							:key="appointment.id"
							:name="formatAppointmentDisplay(appointment)"
							:active="currentView === 'appointment' && appointmentDetailId === appointment.id"
							:data-test="`nav-past-appointment-${appointment.id}`"
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
					v-if="permissions.canManageAppointments"
					:name="t('attendance', 'Create Appointment')"
					data-test="button-create-appointment"
					@click.prevent="createNewAppointment">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					v-if="permissions.canManageAppointments"
					:name="t('attendance', 'Export')"
					data-test="button-export"
					@click.prevent="exportAppointments">
					<template #icon>
						<DownloadIcon :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('attendance', 'Calendar Feed')"
					data-test="button-calendar-feed"
					@click.prevent="showIcalFeedModal = true">
					<template #icon>
						<CalendarSyncIcon :size="20" />
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<!-- Main content area -->
		<NcAppContent>
			<!-- Check-in View -->
			<CheckinView v-if="currentView === 'checkin'" :appointment-id="checkinAppointmentId" />
			
			<!-- Appointment Detail View -->
			<AppointmentDetail 
				v-else-if="currentView === 'appointment'" 
				:appointment-id="appointmentDetailId"
				@response-updated="loadAppointments" />
			
			<!-- All Appointments View -->
			<AllAppointments 
				v-else-if="currentView === 'current' || currentView === 'past' || currentView === 'unanswered'" 
				:show-past="currentView === 'past'"
				:show-unanswered="currentView === 'unanswered'" 
				:key="currentView"
				@response-updated="loadAppointments" />
			
			<!-- Loading state while routing is determined -->
			<div v-else class="loading-state">
				<div class="loading-spinner"></div>
			</div>
		</NcAppContent>
		
		<!-- Create Appointment Modal -->
		<AppointmentFormModal
			:show="showCreateForm"
			:appointment="null"
			:notifications-app-enabled="notificationsAppEnabled"
			@close="handleCreateModalClose"
			@submit="handleCreateModalSubmit" />

		<!-- iCal Feed Modal -->
		<IcalFeedModal
			:show="showIcalFeedModal"
			@close="showIcalFeedModal = false" />
	</NcContent>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import CheckinView from './views/Checkin.vue'
import AllAppointments from './views/AllAppointments.vue'
import AppointmentDetail from './views/AppointmentDetail.vue'
import AppointmentFormModal from './components/appointment/AppointmentFormModal.vue'
import IcalFeedModal from './components/IcalFeedModal.vue'
import { NcContent, NcAppNavigation, NcAppNavigationItem, NcAppContent } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import CalendarClockIcon from 'vue-material-design-icons/CalendarClock.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import HelpCircle from 'vue-material-design-icons/HelpCircle.vue'
import ProgressQuestion from 'vue-material-design-icons/ProgressQuestion.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import BellAlertIcon from 'vue-material-design-icons/BellAlert.vue'
import CalendarSyncIcon from 'vue-material-design-icons/CalendarSync.vue'
import { usePermissions } from './composables/usePermissions.js'
import { formatDateTime, toServerTimezone } from './utils/datetime.js'

const currentView = ref(null) // 'current', 'past', 'unanswered', 'appointment', 'checkin', or null
const checkinAppointmentId = ref(null)
const appointmentDetailId = ref(null)
const currentAppointments = ref([])
const pastAppointments = ref([])
const showCreateForm = ref(false)
const showIcalFeedModal = ref(false)
const notificationsAppEnabled = ref(false)

// Use the shared permissions composable
const { permissions, loadPermissions } = usePermissions()

// Computed property for unanswered appointments
const unansweredAppointments = computed(() => {
	return currentAppointments.value.filter(appointment => {
		return !appointment.userResponse || appointment.userResponse === null
	})
})

// Computed property for answered appointments (to show under "Upcoming")
const answeredAppointments = computed(() => {
	return currentAppointments.value.filter(appointment => {
		return appointment.userResponse && appointment.userResponse !== null
	})
})

const setView = (view) => {
	currentView.value = view
	
	const baseUrl = window.location.pathname.replace(/\/(past|unanswered|appointment\/\d+|checkin\/\d+)?$/, '')
	let newUrl = baseUrl
	
	if (view === 'past') {
		newUrl = baseUrl + '/past'
	} else if (view === 'unanswered') {
		newUrl = baseUrl + '/unanswered'
	} else if (view === 'current') {
		newUrl = baseUrl
	}
	
	window.history.pushState({ view }, '', newUrl)
}

const navigateToAppointment = (appointmentId) => {
	currentView.value = 'appointment'
	appointmentDetailId.value = appointmentId
	
	const baseUrl = window.location.pathname.replace(/\/(past|unanswered|appointment\/\d+|checkin\/\d+)?$/, '')
	const newUrl = baseUrl + '/appointment/' + appointmentId
	
	window.history.pushState({ view: 'appointment', appointmentId }, '', newUrl)
}

const loadAppointments = async () => {
	try {
		// Use lightweight navigation endpoint (single call, minimal data)
		const response = await axios.get(generateUrl('/apps/attendance/api/appointments/navigation'))
		currentAppointments.value = response.data.current
		pastAppointments.value = response.data.past
	} catch (error) {
		console.error('Failed to load appointments for navigation:', error)
	}
}

const loadNotificationsAppStatus = async () => {
	try {
		const response = await axios.get(generateUrl('/apps/attendance/api/admin/settings'))
		if (response.data.success && response.data.reminders) {
			notificationsAppEnabled.value = response.data.reminders.notificationsAppEnabled !== false
		}
	} catch (error) {
		// If user doesn't have admin access, that's fine - notifications checkbox won't be shown
		console.debug('Could not load notifications app status:', error)
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
		const startDatetimeWithTz = toServerTimezone(formData.startDatetime)
		const endDatetimeWithTz = toServerTimezone(formData.endDatetime)

		const response = await axios.post(generateUrl('/apps/attendance/api/appointments'), {
			name: formData.name,
			description: formData.description,
			startDatetime: startDatetimeWithTz,
			endDatetime: endDatetimeWithTz,
			visibleUsers: formData.visibleUsers || [],
			visibleGroups: formData.visibleGroups || [],
			sendNotification: formData.sendNotification || false,
		})

		showSuccess(t('attendance', 'Appointment created successfully'))
		handleCreateModalClose()

		await loadAppointments()

		// Navigate to the newly created appointment's detail view
		if (response.data && response.data.id) {
			navigateToAppointment(response.data.id)
		} else if (currentView.value !== 'current') {
			setView('current')
		}
	} catch (error) {
		console.error('Failed to create appointment:', error)
		showError(t('attendance', 'Error creating appointment'))
	}
}

const formatAppointmentDisplay = (appointment) => {
	if (!appointment.startDatetime) {
		return appointment.name
	}
	const dateTimeStr = formatDateTime(appointment.startDatetime)
	return `${appointment.name}\n${dateTimeStr}`
}

const exportAppointments = async () => {
	try {
		const response = await axios.post(generateUrl('/apps/attendance/api/export'))
		
		if (response.data.success) {
			showSuccess(t('attendance', 'Export created successfully: {filename}', { filename: response.data.filename }))
			
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
	const path = window.location.pathname
	const checkinMatch = path.match(/\/checkin\/(\d+)/)
	const appointmentMatch = path.match(/\/appointment\/(\d+)/)
	const isPastRoute = path.endsWith('/past')
	const isUnansweredRoute = path.endsWith('/unanswered')
	
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
	} else if (isUnansweredRoute) {
		currentView.value = 'unanswered'
		checkinAppointmentId.value = null
		appointmentDetailId.value = null
	} else {
		currentView.value = 'current'
		checkinAppointmentId.value = null
		appointmentDetailId.value = null
	}
}

// Track if appointments have been loaded for navigation
const appointmentsLoaded = ref(false)

// Load appointments for navigation if not already loaded
const ensureAppointmentsLoaded = async () => {
	if (!appointmentsLoaded.value) {
		await loadAppointments()
		appointmentsLoaded.value = true
	}
}

// Watch for view changes to load appointments when needed for navigation
watch(currentView, async (newView, oldView) => {
	// If switching from checkin to a view that shows navigation, load appointments
	if (oldView === 'checkin' && newView !== 'checkin') {
		await ensureAppointmentsLoaded()
	}
})

onMounted(async () => {
	checkRouting()
	await loadPermissions()
	await loadNotificationsAppStatus()

	// Skip loading appointments only for checkin view (no navigation sidebar)
	// All other views show navigation and need appointment data
	if (currentView.value !== 'checkin') {
		await loadAppointments()
		appointmentsLoaded.value = true

		// Auto-navigate to unanswered appointments if any exist and we're on the default route
		if (currentView.value === 'current' && unansweredAppointments.value.length > 0) {
			setView('unanswered')
		}
	}

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

/* Style for appointment navigation items - only for nested items */
:deep(.app-navigation-entry__children .app-navigation-entry__name) {
	white-space: pre-line !important;
	line-height: 1.3;
	margin: 6px 0;
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

/* Dark Mode (explicit): Warning elements need black text for better contrast */
body[data-theme-dark] #attendance[data-nc-version="31"] .nc-chip--warning .nc-chip__text {
	color: black !important;
}

body[data-theme-dark] #attendance[data-nc-version="31"] .response-buttons:not(.has-response) .button-vue--warning .button-vue__text,
body[data-theme-dark] #attendance[data-nc-version="31"] .response-buttons .button-vue--warning.active .button-vue__text {
	color: black !important;
}

/* Dark Mode (system preference): Only when using default theme */
@media (prefers-color-scheme: dark) {
	body[data-theme-default] #attendance[data-nc-version="31"] .nc-chip--warning .nc-chip__text {
		color: black !important;
	}
	
	body[data-theme-default] #attendance[data-nc-version="31"] .response-buttons:not(.has-response) .button-vue--warning .button-vue__text,
	body[data-theme-default] #attendance[data-nc-version="31"] .response-buttons .button-vue--warning.active .button-vue__text {
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
