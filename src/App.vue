<template>
	<NcContent appName="attendance">
		<!-- Navigation sidebar (hidden during checkin) -->
		<NcAppNavigation v-if="currentView !== 'checkin'">
			<template #search>
				<NcAppNavigationSearch
					v-model="searchQuery"
					:label="t('attendance', 'Search appointments')"
					@update:modelValue="onSearchInput" />
			</template>
			<template #list>
				<NcAppNavigationItem
					:name="t('attendance', 'All appointments')"
					:active="currentView === 'all'"
					data-test="nav-all"
					@click.prevent="setView('all')">
					<template #icon>
						<FormatListBulletedIcon :size="20" />
					</template>
				</NcAppNavigationItem>

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
								currentView === 'appointment'
									&& appointmentDetailId === appointment.id
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
								currentView === 'appointment'
									&& appointmentDetailId === appointment.id
							"
							data-test="nav-upcoming-appointment"
							@click.prevent="
								navigateToAppointment(appointment.id)
							">
							<template #icon>
								<CheckCircle
									v-if="
										appointment.userResponse?.response
											=== 'yes'
									"
									:size="20" />
								<HelpCircle
									v-else-if="
										appointment.userResponse?.response
											=== 'maybe'
									"
									:size="20" />
								<CloseCircle
									v-else-if="
										appointment.userResponse?.response
											=== 'no'
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
							pastAppointmentsExpanded
								&& pastAppointments.length > 0
						">
						<NcAppNavigationItem
							v-for="appointment in pastAppointments"
							:key="appointment.id"
							:name="formatAppointmentDisplay(appointment)"
							:active="
								currentView === 'appointment'
									&& appointmentDetailId === appointment.id
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
				:appointmentId="checkinAppointmentId" />

			<!-- Appointment Form View (Create/Edit/Copy) -->
			<AppointmentForm
				v-else-if="
					currentView === 'create'
						|| currentView === 'edit'
						|| currentView === 'copy'
				"
				:mode="currentView"
				:appointmentId="formAppointmentId"
				:notificationsAppEnabled="capabilities.notificationsAppEnabled"
				:calendarAvailable="capabilities.calendarAvailable"
				:calendarSyncEnabled="capabilities.calendarSyncEnabled"
				@saved="handleFormSaved"
				@cancelled="handleFormCancelled" />

			<!-- Appointment Detail View -->
			<AppointmentDetail
				v-else-if="currentView === 'appointment'"
				:appointmentId="appointmentDetailId"
				:unansweredCount="unansweredAppointments.length"
				:scrollTarget="appointmentDetailScrollTarget"
				@responseUpdated="loadAppointments"
				@appointmentDeleted="handleAppointmentDeleted"
				@editAppointment="editAppointment"
				@copyAppointment="copyAppointment"
				@navigateToUnanswered="setView('unanswered')"
				@scrollTargetConsumed="appointmentDetailScrollTarget = null" />

			<!-- All Appointments View -->
			<AllAppointments
				v-else-if="
					currentView === 'current'
						|| currentView === 'past'
						|| currentView === 'unanswered'
						|| currentView === 'all'
				"
				:key="currentView"
				:showPast="currentView === 'past'"
				:showUnanswered="currentView === 'unanswered'"
				:showAll="currentView === 'all'"
				:searchQuery="searchQuery"
				:unansweredCount="unansweredAppointments.length"
				@responseUpdated="loadAppointments"
				@appointmentDeleted="loadAppointments"
				@editAppointment="editAppointment"
				@copyAppointment="copyAppointment"
				@navigateToUpcoming="setView('current')"
				@navigateToUnanswered="setView('unanswered')"
				@showAuditLog="openAuditLog"
				@clearSearch="searchQuery = ''" />

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
			:availableAppointments="allAppointments"
			@close="showExportDialog = false" />
	</NcContent>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcAppContent,
	NcAppNavigation,
	NcAppNavigationItem,
	NcAppNavigationSearch,
	NcContent,
} from '@nextcloud/vue'
import { computed, onMounted, ref, watch } from 'vue'
import BellAlertIcon from 'vue-material-design-icons/BellAlert.vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import CalendarClockIcon from 'vue-material-design-icons/CalendarClock.vue'
import CalendarSyncIcon from 'vue-material-design-icons/CalendarSync.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import FormatListBulletedIcon from 'vue-material-design-icons/FormatListBulleted.vue'
import HelpCircle from 'vue-material-design-icons/HelpCircle.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import ProgressQuestion from 'vue-material-design-icons/ProgressQuestion.vue'
import MobileAppBanner from './components/common/MobileAppBanner.vue'
import ExportDialog from './components/ExportDialog.vue'
import IcalFeedModal from './components/IcalFeedModal.vue'
import AllAppointments from './views/AllAppointments.vue'
import AppointmentDetail from './views/AppointmentDetail.vue'
import AppointmentForm from './views/AppointmentForm.vue'
import CheckinView from './views/Checkin.vue'
import { usePermissions } from './composables/usePermissions.js'
import { formatDateTime } from './utils/datetime.js'

t('attendance', 'Connect')
t('attendance', 'Start over')
t('attendance', 'Open browser again')
t('attendance', 'Add account')
t('attendance', 'Please enter a server address')
t('attendance', 'Could not connect to server')
t('attendance', 'Could not start login')
t('attendance', 'Login timed out. Please try again.')
t('attendance', 'Complete the login in your browser')
t('attendance', 'After granting access, return to this app')
t('attendance', 'Enter the domain of your Nextcloud server to get started.')
t('attendance', 'Nextcloud is not fully installed on this server')
t('attendance', 'Setting up …')
t('attendance', "Let's go!")

t('attendance', 'Settings')
t('attendance', 'Accent color')
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

t('attendance', 'Push Notifications')
t('attendance', 'Connecting …')
t('attendance', 'Connected')
t('attendance', 'Connection failed — tap to retry')
t('attendance', 'Push notifications are not enabled')
t('attendance', 'Notifications Disabled')
t('attendance', 'Open Settings')
t('attendance', 'Stay up to date')
t('attendance', 'Get notified about new appointments and reminders.')
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
t('attendance', 'One license for your whole Nextcloud server. No per-user fees.')
t('attendance', 'yr')
t('attendance', 'mo')
t('attendance', 'wk')
t('attendance', 'More than 100 active app users?')
t('attendance', 'Restore Purchases')
t('attendance', 'Terms of Service')
t('attendance', 'Privacy Policy')
t('attendance', 'By connecting, you agree to our [Terms of Service](terms) and acknowledge our [Privacy Policy](privacy).')
t('attendance', 'Current plan')
t('attendance', 'Expired')
t('attendance', 'See plans')
t('attendance', 'No subscription plans available at this time.')
t('attendance', 'No previous purchases found.')
t('attendance', 'Purchase failed. Please try again.')
t('attendance', 'Trial extended by 14 days')
t('attendance', 'Trial extension is not available.')
n('attendance', '%n day left for your team', '%n days left for your team', 1)
t('attendance', 'Get in touch')

t('attendance', 'OK')
t('attendance', 'Something went wrong')
t('attendance', 'Not available on this server')
t('attendance', 'All notifications are end-to-end encrypted — only you can read them.')
t('attendance', 'To receive notifications about new appointments and reminders, please enable notifications in your device settings.')

t('attendance', 'About')
t('attendance', 'Open source licenses')
t('attendance', 'Version {version} ({build})')

t('attendance', 'After this date, the inquiry is automatically closed and no further responses are accepted. Reminders are scheduled relative to the deadline.')

// Strings that only appear in the Flutter mobile app. Registered here so
// Transifex extracts them and the community can translate them centrally; the
// translations are synced back into the app's own l10n files. Keep the English
// source identical to the `.tr()` keys used in the attendance-flutter repo.
t('attendance', 'All languages')
t('attendance', 'Already checked in')
t('attendance', 'Another reason')
t('attendance', 'App developer')
t('attendance', 'Ask each time')
t('attendance', 'Attendees scan this code with the Attendance app to check in. One code works for all appointments.')
t('attendance', 'Bring it to your team')
t('attendance', 'Cancelled on {when}')
t('attendance', 'Check in')
t('attendance', 'Check-in code')
t('attendance', 'Check-in failed')
t('attendance', 'Check-in opens at {time}')
t('attendance', 'Check-in scanning')
t('attendance', 'Checked in at {time}')
t('attendance', 'Common')
t('attendance', 'Could not load language and group options: {error}')
t('attendance', 'Create')
t('attendance', 'Created guest account for {email}')
t('attendance', 'Default')
t('attendance', 'Display name')
t('attendance', 'Done')
t('attendance', 'Email')
t('attendance', 'Failed to write the tag.')
t('attendance', 'Got it')
t('attendance', 'Groups (optional)')
t('attendance', 'Groups (required)')
t('attendance', 'Hold a tag to the back of your phone …')
t('attendance', 'Hold your iPhone near the check-in tag')
t('attendance', 'Hold your iPhone near the tag to write it')
t('attendance', 'I built this app on my own and pay for the push servers out of my own pocket. Your subscription keeps them running and the app improving — thank you! 🙏')
t('attendance', "I built this app on my own — no team, no corporation, and it's not part of Nextcloud GmbH. The push notifications run through servers I pay for every month. Your subscription covers those costs and gives me time to keep improving the app. Thank you for making that possible. 🙏")
t('attendance', 'I need an invoice')
t('attendance', "I'm missing a feature")
t('attendance', "I'm still evaluating")
t('attendance', 'Invite guest')
t('attendance', 'Invoice/association')
t('attendance', "It's not my decision alone")
t('attendance', 'Just for me, not my organization')
t('attendance', 'Language')
t('attendance', 'NFC is not available.')
t('attendance', 'Name (optional)')
t('attendance', 'Next appointment')
t('attendance', 'No appointment right now')
t('attendance', 'Not ready to decide? Add 14 days, free')
t('attendance', 'Not signed in on this server')
t('attendance', 'One license keeps Attendance running for your whole Nextcloud server — no per-person fees.')
t('attendance', 'Pick how the check-in button scans, or ask each time.')
t('attendance', 'Point your camera at the check-in code, or hold your phone to the NFC tag.')
t('attendance', 'Point your camera at the check-in code.')
t('attendance', 'Questions?')
t('attendance', 'Rather book directly?')
t('attendance', 'Scan NFC tag')
t('attendance', 'Scan again')
t('attendance', 'Search by name …')
t('attendance', 'Select the appointment you want to check into:')
t('attendance', 'Send')
t('attendance', 'Send invitation email')
t('attendance', 'Share QR code')
t('attendance', 'Show the check-in code at the entrance or write it to NFC tags.')
t('attendance', 'Sign in')
t('attendance', 'Skip')
t('attendance', 'Tag written')
t('attendance', 'Tag written. Scan it to test the check-in.')
t('attendance', 'Tell me more (optional)')
t('attendance', 'Thanks — that really helps.')
t('attendance', 'There is no appointment to check into right now.')
t('attendance', 'This appointment has been cancelled.')
t('attendance', 'This code belongs to {host}.')
t('attendance', 'This is not an Attendance check-in tag.')
t('attendance', 'This tag is not supported.')
t('attendance', 'This tag is write-protected.')
t('attendance', 'Too expensive')
t('attendance', "Totally fair — a tool for the whole team deserves a shared decision. Talk it over with your team lead, your association's board, or your Nextcloud admin. Any questions? Just send me an email, I'd love to hear from you! 🙌")
t('attendance', 'Try again')
t('attendance', "Use the recipient's server locale")
t('attendance', 'Using account {user} on {server}')
t('attendance', 'What would you be willing to pay?')
t('attendance', "What's still holding you back?")
t('attendance', 'Which appointment?')
t('attendance', 'Write NFC tag')
t('attendance', 'Write email')
t('attendance', "You can book directly through me — no App Store purchase needed, and you'll get a proper invoice. Just send me an email!")
t('attendance', 'You can only assign guests to groups you administer. Ask a server admin to grant you sub-admin rights if you need this.')
t('attendance', 'Your 14 extra days are yours either way — this just helps me make the app worth keeping.')
t('attendance', 'Your fair price')
t('attendance', 'Your free trial has ended')
t('attendance', 'Your message')
// TRANSLATORS: Audit-log entries in the mobile app for a person recording
// their own check-in; the web timeline uses the neutral variants instead.
t('attendance', '{actor} checked themselves in: {state}')
t('attendance', '{actor} updated their own check-in: {state}')

const currentView = ref(null) // 'current', 'past', 'unanswered', 'appointment', 'checkin', 'create', 'edit', 'copy', or null
const checkinAppointmentId = ref(null)
const appointmentDetailId = ref(null)
const formAppointmentId = ref(null) // For edit/copy modes
const currentAppointments = ref([])
const pastAppointments = ref([])
// Scroll target carried into the appointment-detail view; cleared by the
// child once it has scrolled. Used by the "Show activity history" action.
const appointmentDetailScrollTarget = ref(null)
const showIcalFeedModal = ref(false)
const showExportDialog = ref(false)

const pastAppointmentsExpanded = ref(false)

// Intentionally not persisted — a search term carrying across reloads is
// more confusing than helpful. Filters in AllAppointments.vue are persisted.
const searchQuery = ref('')
function onSearchInput() {
	if (!searchQuery.value) return
	// Admin's default landing leaves currentView='all' but the URL bare —
	// re-run setView so the URL also reflects the searchable All view.
	const urlMatches = window.location.pathname.endsWith('/all')
	if (currentView.value === 'all' && urlMatches) return
	setView('all')
}

// Use the shared permissions composable
const { permissions, capabilities, config, loadPermissions } = usePermissions()

const unansweredAppointments = computed(() => {
	return currentAppointments.value.filter((appointment) => {
		const noResponse = !appointment.userResponse || appointment.userResponse === null
		const open = !appointment.closedAt
		// Managers see everything via canUserSeeAppointment; only flag the
		// ones actually addressed to them as unanswered to-dos.
		return noResponse && open && appointment.inAudience
	})
})

// Closed-but-unanswered appointments bucket here so they don't vanish from
// the UI (no longer "unanswered", never answered).
const answeredAppointments = computed(() => {
	return currentAppointments.value.filter((appointment) => {
		const hasResponse = appointment.userResponse && appointment.userResponse !== null
		const closedWithoutResponse = !hasResponse && appointment.closedAt
		return hasResponse || closedWithoutResponse
	})
})

const allAppointments = computed(() => {
	return [...currentAppointments.value, ...pastAppointments.value]
})

function setView(view) {
	// Scoped views (Upcoming/Past/Unanswered) reset the search; the "All"
	// view is the search target, so navigating there preserves the term.
	if (['current', 'past', 'unanswered'].includes(view) && view !== currentView.value) {
		searchQuery.value = ''
	}
	currentView.value = view

	const baseUrl = window.location.pathname.replace(
		/\/(all|past|unanswered|appointment\/\d+|checkin\/\d+|create|edit\/\d+|copy\/\d+)?$/,
		'',
	)
	let newUrl = baseUrl

	if (view === 'past') {
		newUrl = baseUrl + '/past'
	} else if (view === 'unanswered') {
		newUrl = baseUrl + '/unanswered'
	} else if (view === 'all') {
		newUrl = baseUrl + '/all'
	} else if (view === 'current') {
		newUrl = baseUrl
	}

	window.history.pushState({ view }, '', newUrl)
}

function navigateToAppointment(appointmentId) {
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

function openAuditLog(appointmentId) {
	appointmentDetailScrollTarget.value = 'audit'
	navigateToAppointment(appointmentId)
}

async function loadAppointments() {
	try {
		// Use lightweight navigation endpoint (single call, minimal data)
		const response = await axios.get(generateUrl('/apps/attendance/api/appointments/navigation'))
		currentAppointments.value = response.data.current
		pastAppointments.value = response.data.past
	} catch (error) {
		console.error('Failed to load appointments for navigation:', error)
	}
}

function createNewAppointment() {
	currentView.value = 'create'
	formAppointmentId.value = null

	const baseUrl = window.location.pathname.replace(
		/\/(past|unanswered|appointment\/\d+|checkin\/\d+|create|edit\/\d+|copy\/\d+)?$/,
		'',
	)
	const newUrl = baseUrl + '/create'

	window.history.pushState({ view: 'create' }, '', newUrl)
}

function editAppointment(appointment) {
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

function copyAppointment(appointment) {
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

async function handleAppointmentDeleted() {
	await loadAppointments()
	setView('current')
}

async function handleFormSaved(appointmentId) {
	await loadAppointments()

	// Navigate to the saved appointment's detail view
	if (appointmentId) {
		navigateToAppointment(appointmentId)
	} else {
		setView('current')
	}
}

function handleFormCancelled() {
	// Go back to the previous view or default to current
	if (window.history.length > 1) {
		window.history.back()
	} else {
		setView('current')
	}
}

function formatAppointmentDisplay(appointment) {
	if (!appointment.startDatetime) {
		return appointment.name
	}
	const dateTimeStr = formatDateTime(appointment.startDatetime)
	if (config.displayOrder === 'date_first') {
		return `${dateTimeStr}\n${appointment.name}`
	}
	return `${appointment.name}\n${dateTimeStr}`
}

function checkRouting() {
	const path = window.location.pathname
	const checkinMatch = path.match(/\/checkin\/(\d+)/)
	const appointmentMatch = path.match(/\/appointment\/(\d+)/)
	const editMatch = path.match(/\/edit\/(\d+)/)
	const copyMatch = path.match(/\/copy\/(\d+)/)
	const isCreateRoute = path.endsWith('/create')
	const isPastRoute = path.endsWith('/past')
	const isUnansweredRoute = path.endsWith('/unanswered')
	const isAllRoute = path.endsWith('/all')

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
	} else if (isAllRoute) {
		currentView.value = 'all'
	} else {
		// Default landing: admins drop into "All appointments" (their natural
		// overview); everyone else lands in "Unanswered" so the pending
		// action is right in front of them.
		currentView.value = permissions.canManageAppointments ? 'all' : 'unanswered'
	}
}

// Track if appointments have been loaded for navigation
const appointmentsLoaded = ref(false)

// Load appointments for navigation if not already loaded
async function ensureAppointmentsLoaded() {
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
	// Both server fetches are independent — fan out before awaiting either.
	// checkRouting then resolves the bare-URL default view (admin → All,
	// others → Unanswered) once permissions are in.
	const permissionsPromise = loadPermissions()
	const isCheckinView = /\/checkin\/\d+/.test(window.location.pathname)
	const appointmentsPromise = isCheckinView ? null : loadAppointments()

	await permissionsPromise
	checkRouting()

	if (appointmentsPromise) {
		await appointmentsPromise
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
