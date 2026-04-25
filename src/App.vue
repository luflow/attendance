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
							:active="
								currentView === 'appointment' &&
									appointmentDetailId === appointment.id
							"
							data-test="nav-unanswered-appointment"
							@click.prevent="
								navigateToAppointment(appointment.id)
							">
							<template #icon>
								<ProgressQuestion :size="20" />
							</template>
						</NcAppNavigationItem>
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem
					:name="t('attendance', 'Upcoming appointments')"
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
							:active="
								currentView === 'appointment' &&
									appointmentDetailId === appointment.id
							"
							data-test="nav-upcoming-appointment"
							@click.prevent="
								navigateToAppointment(appointment.id)
							">
							<template #icon>
								<CheckCircle
									v-if="
										appointment.userResponse?.response ===
											'yes'
									"
									:size="20" />
								<HelpCircle
									v-else-if="
										appointment.userResponse?.response ===
											'maybe'
									"
									:size="20" />
								<CloseCircle
									v-else-if="
										appointment.userResponse?.response ===
											'no'
									"
									:size="20" />
								<LockIcon
									v-else-if="appointment.closedAt"
									:size="20" />
							</template>
						</NcAppNavigationItem>
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem
					:name="t('attendance', 'Past appointments')"
					:active="currentView === 'past'"
					:open="pastAppointmentsExpanded"
					data-test="nav-past"
					@update:open="pastAppointmentsExpanded = $event"
					@click.prevent="
						setView('past');
						pastAppointmentsExpanded = true;
					">
					<template #icon>
						<CalendarClockIcon :size="20" />
					</template>
					<!-- Nested past appointments -->
					<template
						v-if="
							pastAppointmentsExpanded &&
								pastAppointments.length > 0
						">
						<NcAppNavigationItem
							v-for="appointment in pastAppointments"
							:key="appointment.id"
							:name="formatAppointmentDisplay(appointment)"
							:active="
								currentView === 'appointment' &&
									appointmentDetailId === appointment.id
							"
							:data-test="`nav-past-appointment-${appointment.id}`"
							@click.prevent="
								navigateToAppointment(appointment.id)
							">
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
					:name="t('attendance', 'Create appointment')"
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
					@click.prevent="showExportDialog = true">
					<template #icon>
						<DownloadIcon :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('attendance', 'Calendar subscription')"
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
			<div v-if="currentView !== 'checkin' && config.mobileAppBannerEnabled && !config.hasPushDevice"
				class="mobile-banner-container">
				<MobileAppBanner />
			</div>

			<!-- Check-in View -->
			<CheckinView
				v-if="currentView === 'checkin'"
				:appointment-id="checkinAppointmentId" />

			<!-- Appointment Form View (Create/Edit/Copy) -->
			<AppointmentForm
				v-else-if="
					currentView === 'create' ||
						currentView === 'edit' ||
						currentView === 'copy'
				"
				:mode="currentView"
				:appointment-id="formAppointmentId"
				:notifications-app-enabled="capabilities.notificationsAppEnabled"
				:calendar-available="capabilities.calendarAvailable"
				:calendar-sync-enabled="capabilities.calendarSyncEnabled"
				@saved="handleFormSaved"
				@cancelled="handleFormCancelled" />

			<!-- Appointment Detail View -->
			<AppointmentDetail
				v-else-if="currentView === 'appointment'"
				:appointment-id="appointmentDetailId"
				:unanswered-count="unansweredAppointments.length"
				@response-updated="loadAppointments"
				@appointment-deleted="handleAppointmentDeleted"
				@edit-appointment="editAppointment"
				@copy-appointment="copyAppointment"
				@navigate-to-unanswered="setView('unanswered')" />

			<!-- All Appointments View -->
			<AllAppointments
				v-else-if="
					currentView === 'current' ||
						currentView === 'past' ||
						currentView === 'unanswered'
				"
				:key="currentView"
				:show-past="currentView === 'past'"
				:show-unanswered="currentView === 'unanswered'"
				@response-updated="loadAppointments"
				@appointment-deleted="loadAppointments"
				@edit-appointment="editAppointment"
				@copy-appointment="copyAppointment"
				@navigate-to-upcoming="setView('current')"
				@navigate-to-unanswered="setView('unanswered')" />

			<!-- Loading state while routing is determined -->
			<div v-else class="loading-state">
				<div class="loading-spinner" />
			</div>
		</NcAppContent>

		<!-- iCal Feed Modal -->
		<IcalFeedModal
			:show="showIcalFeedModal"
			@close="showIcalFeedModal = false" />

		<!-- Export Dialog -->
		<ExportDialog
			:show="showExportDialog"
			:available-appointments="allAppointments"
			@close="showExportDialog = false" />
	</NcContent>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import CheckinView from './views/Checkin.vue'
import AllAppointments from './views/AllAppointments.vue'
import AppointmentDetail from './views/AppointmentDetail.vue'
import AppointmentForm from './views/AppointmentForm.vue'
import IcalFeedModal from './components/IcalFeedModal.vue'
import ExportDialog from './components/ExportDialog.vue'
import MobileAppBanner from './components/common/MobileAppBanner.vue'
import {
	NcContent,
	NcAppNavigation,
	NcAppNavigationItem,
	NcAppContent,
} from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
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
import LockIcon from 'vue-material-design-icons/Lock.vue'
import { usePermissions } from './composables/usePermissions.js'
import { formatDateTime } from './utils/datetime.js'

t('attendance', 'Connect')
t('attendance', 'Start over')
t('attendance', 'Open browser again')
t('attendance', 'Use existing account')
t('attendance', 'Add account')
t('attendance', 'Please enter a server address')
t('attendance', 'Could not connect to server')
t('attendance', 'Could not start login')
t('attendance', 'Login timed out. Please try again.')
t('attendance', 'Complete the login in your browser')
t('attendance', 'After granting access, return to this app')
t('attendance', 'Enter the domain of your Nextcloud server to get started.')
t('attendance', 'Nextcloud is not fully installed on this server')
t('attendance', 'Setting up …')
t('attendance', "Let's go!")

t('attendance', 'Settings')
t('attendance', 'Accent color')
t('attendance', 'Account')
t('attendance', 'Log out')
t('attendance', 'Are you sure you want to log out?')
t('attendance', 'Reset to server color')
t('attendance', 'Appearance')
t('attendance', 'Background style')
t('attendance', 'Gradient')
t('attendance', 'Color')
t('attendance', 'Tiled')
t('attendance', 'None')
t('attendance', 'Theme')
t('attendance', 'System')
t('attendance', 'Light')
t('attendance', 'Dark')

t('attendance', 'Notifications')
t('attendance', 'Push Notifications')
t('attendance', 'Receive notifications for new appointments and reminders')
t('attendance', 'Connecting…')
t('attendance', 'Connected')
t('attendance', 'Connection failed — tap to retry')
t('attendance', 'Notifications blocked — tap to open settings')
t('attendance', 'Push not available on this server')
t('attendance', 'Push notifications are not enabled')
t('attendance', 'Notifications Disabled')
t('attendance', 'Open Settings')
t('attendance', 'Stay up to date')
t('attendance', 'Get notified about new appointments and reminders.')
t('attendance', 'Please allow notifications when prompted.')
t('attendance', 'Enable Notifications')
t('attendance', 'Not now')
t('attendance', 'Server Update Available')
t('attendance', "Your server's Attendance app may be outdated. Please update to version 1.34.0 or later for the best experience.")

t('attendance', 'Attendance is not available')
t('attendance', 'The Attendance app could not be reached on your Nextcloud server. This usually has one of two reasons:')
t('attendance', 'The app is not installed on the server.')
t('attendance', 'Ask an administrator to install the Attendance app from the Nextcloud app store.')
t('attendance', 'The app is limited to specific groups.')
t('attendance', 'Ask an administrator to allow your account under Administration settings → Apps → Attendance → "Limit to groups".')

t('attendance', 'Checked in')
t('attendance', 'Not checked in')
t('attendance', 'Happening now')
t('attendance', 'Error loading data')
t('attendance', 'Reset failed')
t('attendance', 'Active filters')
t('attendance', 'Check-in failed. Please try again.')
t('attendance', 'No results match the active filters.')

t('attendance', 'Subscribe')
t('attendance', 'Subscription')
t('attendance', 'Active')
t('attendance', 'Active app users')
t('attendance', 'Manage Subscription')
t('attendance', 'Trial')
t('attendance', 'days remaining')
t('attendance', 'Full access to the Attendance app for your entire Nextcloud server.')
t('attendance', 'yr')
t('attendance', 'mo')
t('attendance', 'wk')
t('attendance', 'More than 100 active app users?')
t('attendance', 'Restore Purchases')
t('attendance', 'Terms of Service')
t('attendance', 'Privacy Policy')
t('attendance', 'By connecting, you agree to our {terms} and acknowledge our {privacy}.')
t('attendance', 'Current plan')
t('attendance', 'Expired')
t('attendance', 'Your trial has ended')
t('attendance', 'View subscription plans')
t('attendance', 'No subscription plans available at this time.')
t('attendance', 'No previous purchases found.')
t('attendance', 'Purchase failed. Please try again.')
t('attendance', 'One subscription covers everyone on your server — no per-user fees.')
t('attendance', 'Thanks for trying Attendance! To continue using all features, activate a subscription for your organization.')

t('attendance', 'OK')
t('attendance', 'Something went wrong')
t('attendance', 'Not available on this server')
t('attendance', 'All notifications are end-to-end encrypted — only you can read them.')
t('attendance', 'To receive notifications about new appointments and reminders, please enable notifications in your device settings.')

t('attendance', 'About')
t('attendance', 'Open source licenses')
t('attendance', 'Version {version} ({build})')

t('attendance', 'Closed')
t('attendance', 'Show only open')
t('attendance', 'Close inquiry')
t('attendance', 'Reopen inquiry')
t('attendance', 'Reopen')
t('attendance', 'Inquiry closed')
t('attendance', 'Inquiry re-opened')
t('attendance', 'Failed to close inquiry')
t('attendance', 'Failed to re-open inquiry')
t('attendance', 'Closed on {when}')
t('attendance', 'Closed automatically on {when}')
t('attendance', 'Response deadline')
t('attendance', 'No deadline')
t('attendance', 'Response deadline must be before the appointment starts')
t('attendance', 'Response deadline must be in the future')
t('attendance', 'After this date, the inquiry is automatically closed and no further responses are accepted. Reminders are scheduled relative to the deadline.')
t('attendance', 'Responses possible until {when}')
t('attendance', 'This appointment is closed and no longer accepts responses.')

const currentView = ref(null) // 'current', 'past', 'unanswered', 'appointment', 'checkin', 'create', 'edit', 'copy', or null
const checkinAppointmentId = ref(null)
const appointmentDetailId = ref(null)
const formAppointmentId = ref(null) // For edit/copy modes
const currentAppointments = ref([])
const pastAppointments = ref([])
const showIcalFeedModal = ref(false)
const showExportDialog = ref(false)

const pastAppointmentsExpanded = ref(false)

// Use the shared permissions composable
const { permissions, capabilities, config, loadPermissions } = usePermissions()

const unansweredAppointments = computed(() => {
	return currentAppointments.value.filter((appointment) => {
		const noResponse = !appointment.userResponse || appointment.userResponse === null
		const open = !appointment.closedAt
		return noResponse && open
	})
})

// Closed-but-unanswered appointments bucket here so the user can still find
// them — they would otherwise vanish (no longer "unanswered", never answered).
const answeredAppointments = computed(() => {
	return currentAppointments.value.filter((appointment) => {
		const hasResponse = appointment.userResponse && appointment.userResponse !== null
		const closedWithoutResponse = !hasResponse && appointment.closedAt
		return hasResponse || closedWithoutResponse
	})
})

// Computed property for all appointments for export dialog
const allAppointments = computed(() => {
	return [...currentAppointments.value, ...pastAppointments.value]
})

const setView = (view) => {
	currentView.value = view

	const baseUrl = window.location.pathname.replace(
		/\/(past|unanswered|appointment\/\d+|checkin\/\d+|create|edit\/\d+|copy\/\d+)?$/,
		'',
	)
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

	const baseUrl = window.location.pathname.replace(
		/\/(past|unanswered|appointment\/\d+|checkin\/\d+|create|edit\/\d+|copy\/\d+)?$/,
		'',
	)
	const newUrl = baseUrl + '/appointment/' + appointmentId

	window.history.pushState(
		{ view: 'appointment', appointmentId },
		'',
		newUrl,
	)
}

const loadAppointments = async () => {
	try {
		// Use lightweight navigation endpoint (single call, minimal data)
		const response = await axios.get(
			generateUrl('/apps/attendance/api/appointments/navigation'),
		)
		currentAppointments.value = response.data.current
		pastAppointments.value = response.data.past
	} catch (error) {
		console.error('Failed to load appointments for navigation:', error)
	}
}

const createNewAppointment = () => {
	currentView.value = 'create'
	formAppointmentId.value = null

	const baseUrl = window.location.pathname.replace(
		/\/(past|unanswered|appointment\/\d+|checkin\/\d+|create|edit\/\d+|copy\/\d+)?$/,
		'',
	)
	const newUrl = baseUrl + '/create'

	window.history.pushState({ view: 'create' }, '', newUrl)
}

const editAppointment = (appointment) => {
	currentView.value = 'edit'
	formAppointmentId.value = appointment.id

	const baseUrl = window.location.pathname.replace(
		/\/(past|unanswered|appointment\/\d+|checkin\/\d+|create|edit\/\d+|copy\/\d+)?$/,
		'',
	)
	const newUrl = baseUrl + '/edit/' + appointment.id

	window.history.pushState(
		{ view: 'edit', appointmentId: appointment.id },
		'',
		newUrl,
	)
}

const copyAppointment = (appointment) => {
	currentView.value = 'copy'
	formAppointmentId.value = appointment.id

	const baseUrl = window.location.pathname.replace(
		/\/(past|unanswered|appointment\/\d+|checkin\/\d+|create|edit\/\d+|copy\/\d+)?$/,
		'',
	)
	const newUrl = baseUrl + '/copy/' + appointment.id

	window.history.pushState(
		{ view: 'copy', appointmentId: appointment.id },
		'',
		newUrl,
	)
}

const handleAppointmentDeleted = async () => {
	await loadAppointments()
	setView('current')
}

const handleFormSaved = async (appointmentId) => {
	await loadAppointments()

	// Navigate to the saved appointment's detail view
	if (appointmentId) {
		navigateToAppointment(appointmentId)
	} else {
		setView('current')
	}
}

const handleFormCancelled = () => {
	// Go back to the previous view or default to current
	if (window.history.length > 1) {
		window.history.back()
	} else {
		setView('current')
	}
}

const formatAppointmentDisplay = (appointment) => {
	if (!appointment.startDatetime) {
		return appointment.name
	}
	const dateTimeStr = formatDateTime(appointment.startDatetime)
	if (config.displayOrder === 'date_first') {
		return `${dateTimeStr}\n${appointment.name}`
	}
	return `${appointment.name}\n${dateTimeStr}`
}

const checkRouting = () => {
	const path = window.location.pathname
	const checkinMatch = path.match(/\/checkin\/(\d+)/)
	const appointmentMatch = path.match(/\/appointment\/(\d+)/)
	const editMatch = path.match(/\/edit\/(\d+)/)
	const copyMatch = path.match(/\/copy\/(\d+)/)
	const isCreateRoute = path.endsWith('/create')
	const isPastRoute = path.endsWith('/past')
	const isUnansweredRoute = path.endsWith('/unanswered')

	// Reset all state
	checkinAppointmentId.value = null
	appointmentDetailId.value = null
	formAppointmentId.value = null

	if (checkinMatch) {
		currentView.value = 'checkin'
		checkinAppointmentId.value = parseInt(checkinMatch[1])
	} else if (isCreateRoute) {
		currentView.value = 'create'
	} else if (editMatch) {
		currentView.value = 'edit'
		formAppointmentId.value = parseInt(editMatch[1])
	} else if (copyMatch) {
		currentView.value = 'copy'
		formAppointmentId.value = parseInt(copyMatch[1])
	} else if (appointmentMatch) {
		currentView.value = 'appointment'
		appointmentDetailId.value = parseInt(appointmentMatch[1])
	} else if (isPastRoute) {
		currentView.value = 'past'
	} else if (isUnansweredRoute) {
		currentView.value = 'unanswered'
	} else {
		currentView.value = 'current'
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

	// Skip loading appointments only for checkin view (no navigation sidebar)
	// All other views show navigation and need appointment data
	if (currentView.value !== 'checkin') {
		await loadAppointments()
		appointmentsLoaded.value = true
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
    0% {
        transform: rotate(0deg);
    }
    100% {
        transform: rotate(360deg);
    }
}

.mobile-banner-container {
    padding: 20px 20px 0;
    max-width: 1200px;
    margin: 0 auto;
}

/* Style for appointment navigation items - only for nested items */
:deep(.app-navigation-entry__children .app-navigation-entry__name) {
    white-space: pre-line !important;
    line-height: 1.3;
    margin: 6px 0;
}
</style>

<style>
/* Global Nextcloud 31 fallback for NcChip text and icon color */
/* Only apply to colored chip variants that need white text/icons */
#attendance[data-nc-version="31"] .nc-chip--error .nc-chip__text,
#attendance[data-nc-version="31"] .nc-chip--warning .nc-chip__text,
#attendance[data-nc-version="31"] .nc-chip--success .nc-chip__text {
    color: white !important;
}

#attendance[data-nc-version="31"] .nc-chip--error .nc-chip__icon,
#attendance[data-nc-version="31"] .nc-chip--warning .nc-chip__icon,
#attendance[data-nc-version="31"] .nc-chip--success .nc-chip__icon {
    color: white !important;
}

/* Dark Mode (explicit): Warning elements need black text/icons for better contrast */
body[data-theme-dark]
    #attendance[data-nc-version="31"]
    .nc-chip--warning
    .nc-chip__text,
body[data-theme-dark]
    #attendance[data-nc-version="31"]
    .nc-chip--warning
    .nc-chip__icon {
    color: black !important;
}

body[data-theme-dark]
    #attendance[data-nc-version="31"]
    .response-buttons:not(.has-response)
    .button-vue--warning
    .button-vue__text,
body[data-theme-dark]
    #attendance[data-nc-version="31"]
    .response-buttons
    .button-vue--warning.active
    .button-vue__text {
    color: black !important;
}

/* Dark Mode (system preference): Only when using default theme */
@media (prefers-color-scheme: dark) {
    body[data-theme-default]
        #attendance[data-nc-version="31"]
        .nc-chip--warning
        .nc-chip__text,
    body[data-theme-default]
        #attendance[data-nc-version="31"]
        .nc-chip--warning
        .nc-chip__icon {
        color: black !important;
    }

    body[data-theme-default]
        #attendance[data-nc-version="31"]
        .response-buttons:not(.has-response)
        .button-vue--warning
        .button-vue__text,
    body[data-theme-default]
        #attendance[data-nc-version="31"]
        .response-buttons
        .button-vue--warning.active
        .button-vue__text {
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
