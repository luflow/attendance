<template>
	<div class="attendance-container">
		<!-- Unanswered reminder banner (shown on upcoming view when there are unanswered appointments) -->
		<div v-if="!showUnanswered && !showPast && !showAll && !loading && unansweredCount > 0" class="unanswered-banner-container">
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
		<!-- Filter row, Files-app pattern. Each filter is a popover trigger;
		     active values surface as chips on the row below, prefixed with
		     "Active filters:". Search lives in the navigation sidebar
		     (App.vue → NcAppNavigationSearch); when active it appears here
		     as a chip too. -->
		<h2 v-if="pageHeading && !hideHeading" class="page-heading" data-test="page-heading">
			{{ pageHeading }}
		</h2>

		<div
			v-if="!loading && !showUnanswered && (appointments.length > 0 || hasActiveFilters)"
			class="filter-bar"
			data-test="appointment-filters">
			<div class="filter-bar__triggers">
				<NcPopover v-for="filter in filters" :key="filter.id">
					<template #trigger>
						<NcButton
							:variant="filter.value ? 'secondary' : 'tertiary'"
							:data-test="`filter-${filter.id}`">
							<template #icon>
								<component :is="filter.icon" :size="20" />
							</template>
							{{ filter.label }}
						</NcButton>
					</template>
					<template #default>
						<ul class="filter-bar__options" role="menu">
							<li v-for="opt in filter.options" :key="opt.id" role="presentation">
								<NcButton
									role="menuitemradio"
									:aria-checked="filter.value?.id === opt.id"
									alignment="start"
									wide
									variant="tertiary"
									@click="setFilter(filter.id, opt)">
									<span class="filter-bar__option">
										{{ opt.label }}
										<CheckIcon v-if="filter.value?.id === opt.id" :size="18" />
									</span>
								</NcButton>
							</li>
							<li v-if="filter.value" role="presentation" class="filter-bar__reset">
								<NcButton
									alignment="start"
									wide
									variant="tertiary"
									@click="setFilter(filter.id, null)">
									{{ t('attendance', 'Reset filter') }}
								</NcButton>
							</li>
						</ul>
					</template>
				</NcPopover>
			</div>
			<div v-if="hasActiveFilters" class="filter-bar__active">
				<span class="filter-bar__active-label">{{ t('attendance', 'Active filters:') }}</span>
				<NcChip
					v-if="activeSearch"
					:text="t('attendance', 'Search: {query}', { query: activeSearch })"
					data-test="active-search-chip"
					@close="emit('clearSearch')" />
				<NcChip
					v-for="filter in activeFilters"
					:key="filter.id"
					:text="`${filter.label}: ${filter.value.label}`"
					@close="setFilter(filter.id, null)" />
			</div>
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
import { NcButton, NcChip, NcPopover } from '@nextcloud/vue'
import AppointmentCard from '../components/appointment/AppointmentCard.vue'
import SingleAppointmentExportDialog from '../components/SingleAppointmentExportDialog.vue'
import DeleteAppointmentDialog from '../components/appointment/DeleteAppointmentDialog.vue'
import ProgressQuestion from 'vue-material-design-icons/ProgressQuestion.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'
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
	// "All" view: fetches upcoming + past in parallel and concatenates.
	// This is where the sidebar search lands so the user gets one unified
	// hit list across both halves without view-scoped surprises.
	showAll: {
		type: Boolean,
		default: false,
	},
	// Lifted to App.vue so the search input lives in the sidebar
	// (NcAppNavigationSearch). Empty string = no search active.
	searchQuery: {
		type: String,
		default: '',
	},
})

const emit = defineEmits([
	'responseUpdated',
	'editAppointment',
	'copyAppointment',
	'navigateToUpcoming',
	'navigateToUnanswered',
	'appointmentDeleted',
	'clearSearch',
])

const activeSearch = computed(() => props.searchQuery.trim())

const pageHeading = computed(() => {
	if (props.showUnanswered) return t('attendance', 'Unanswered')
	if (props.showAll) return t('attendance', 'All appointments')
	if (props.showPast) return t('attendance', 'Past appointments')
	return t('attendance', 'Upcoming appointments')
})

// On the Unanswered view the empty state is the celebratory "Hurray!" banner.
// Repeating "Unanswered" above it just adds noise.
const hideHeading = computed(() =>
	props.showUnanswered && !loading.value && appointments.value.length === 0,
)

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

// Filter definitions: id, display label, icon, list of {id, label}.
// Adding a filter = one entry here; the template renders it generically.
// `visible` lets a filter opt-out per role (e.g. "Only mine" only makes
// sense for managers, who see appointments created by other people too).
const filterDefs = computed(() => [
	{
		id: 'status',
		label: t('attendance', 'Inquiry status'),
		icon: LockIcon,
		options: [
			{ id: 'open', label: t('attendance', 'Opened') },
			{ id: 'closed', label: t('attendance', 'Closed') },
		],
	},
	{
		id: 'response',
		label: t('attendance', 'Your response'),
		icon: CheckCircleIcon,
		options: [
			{ id: 'yes', label: t('attendance', 'Yes') },
			{ id: 'maybe', label: t('attendance', 'Maybe') },
			{ id: 'no', label: t('attendance', 'No') },
			{ id: 'none', label: t('attendance', 'No response') },
		],
	},
	{
		// "Audience" filter, server-side via VisibilityService::isUserTargetAttendee.
		// Without it, managers see every appointment in the system; with it,
		// only those that target the manager directly (visibleUsers/Groups/Teams
		// or "everyone").
		id: 'audience',
		label: t('attendance', 'Audience'),
		icon: AccountIcon,
		visible: permissions.canManageAppointments,
		options: [
			{ id: 'me', label: t('attendance', 'Only for me') },
		],
	},
])

const filterValues = ref(loadStoredFilterValues())

function loadStoredFilterValues() {
	try {
		const parsed = JSON.parse(window.localStorage.getItem(FILTER_STORAGE_KEY) || '{}')
		// Only keep string values; drop anything else (legacy keys, garbage).
		return Object.fromEntries(
			Object.entries(parsed).filter(([, v]) => typeof v === 'string'),
		)
	} catch (e) {
		return {}
	}
}

const filters = computed(() => filterDefs.value
	.filter(def => def.visible !== false)
	.map(def => ({
		...def,
		value: def.options.find(opt => opt.id === filterValues.value[def.id]) ?? null,
	})))

const activeFilters = computed(() => filters.value.filter(f => f.value))

const setFilter = (id, opt) => {
	const next = { ...filterValues.value }
	if (opt) {
		next[id] = opt.id
	} else {
		delete next[id]
	}
	filterValues.value = next
}

let persistTimer = null
watch(filterValues, (next) => {
	clearTimeout(persistTimer)
	persistTimer = setTimeout(() => {
		try {
			window.localStorage.setItem(FILTER_STORAGE_KEY, JSON.stringify(next))
		} catch (e) {
			// Storage may be unavailable (private mode, quota).
		}
	}, 300)
}, { deep: true })

const hasActiveFilters = computed(() =>
	Boolean(props.searchQuery.trim() || activeFilters.value.length),
)

const visibleAppointments = computed(() => {
	const query = props.searchQuery.trim().toLowerCase()
	const status = filterValues.value.status
	const response = filterValues.value.response
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
		const url = generateUrl('/apps/attendance/api/appointments')
		const onlyForMe = filterValues.value.audience === 'me'
		if (props.showAll) {
			// Two endpoints, one logical view. Audience filter applies to both.
			const baseParams = onlyForMe ? { onlyForMe: true } : {}
			const [upcoming, past] = await Promise.all([
				axios.get(url, { params: baseParams }),
				axios.get(url, { params: { ...baseParams, showPastAppointments: true } }),
			])
			appointments.value = [...upcoming.data, ...past.data]
		} else {
			const params = {}
			if (props.showPast) params.showPastAppointments = true
			if (props.showUnanswered) params.unansweredOnly = true
			if (onlyForMe) params.onlyForMe = true
			const response = await axios.get(url, { params })
			appointments.value = response.data
		}

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

// The audience filter is server-side, so flipping it requires a refetch.
watch(() => filterValues.value.audience, () => {
	loadAppointments(true)
})

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

.page-heading {
	max-width: 800px;
	margin: 0 auto 12px;
	font-size: 1.4em;
	font-weight: 600;
	color: var(--color-main-text);
}

.filter-bar {
	max-width: 800px;
	margin: 0 auto 12px;
	display: flex;
	flex-direction: column;
	gap: 8px;

	&__triggers {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
	}

	&__options {
		min-width: 200px;
		padding: 4px 0;
		list-style: none;
		margin: 0;
	}

	// Make filter-option labels normal weight — Nc tertiary buttons render
	// bold by default which doesn't read like a list of choices.
	&__options :deep(.button-vue__text) {
		font-weight: normal;
	}

	// Push the trailing tick icon to the right edge of the option button.
	&__option {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		width: 100%;
	}

	&__reset :deep(.button-vue__text) {
		color: var(--color-error-text);
	}

	&__active {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
	}

	&__active-label {
		font-weight: 600;
		color: var(--color-text-maxcontrast);
		margin-inline-end: 4px;
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
