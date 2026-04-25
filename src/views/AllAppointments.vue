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
		<!-- Filter row, Files-app pattern: each filter is a popover trigger,
		     active filters surface as chips alongside. Search lives in the
		     navigation sidebar (App.vue → NcAppNavigationSearch); when active
		     it shows up here as a chip too. -->
		<div
			v-if="!loading && !showUnanswered && (appointments.length > 0 || hasActiveFilters)"
			class="filter-bar"
			data-test="appointment-filters">
			<NcPopover v-for="filter in filters" :key="filter.id">
				<template #trigger>
					<NcButton
						variant="tertiary"
						:pressed="!!filter.value"
						:data-test="`filter-${filter.id}`">
						<template #icon>
							<component :is="filter.icon" :size="20" />
						</template>
						{{ filter.value?.label ?? filter.label }}
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
								{{ opt.label }}
							</NcButton>
						</li>
					</ul>
				</template>
			</NcPopover>
			<div v-if="hasActiveFilters" class="filter-bar__chips">
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

const currentUserUid = window.OC?.getCurrentUser?.()?.uid || window.OC?.currentUser || null

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
			{ id: 'open', label: t('attendance', 'Open') },
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
		id: 'owner',
		label: t('attendance', 'Owner'),
		icon: AccountIcon,
		visible: permissions.canManageAppointments && Boolean(currentUserUid),
		options: [
			{ id: 'mine', label: t('attendance', 'Only mine') },
			{ id: 'others', label: t('attendance', 'Created by others') },
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
	const owner = filterValues.value.owner
	if (!query && !status && !response && !owner) {
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
		if (owner === 'mine' && appointment.createdBy !== currentUserUid) return false
		if (owner === 'others' && appointment.createdBy === currentUserUid) return false
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
		// Active search ignores the active view — type "x" while on Upcoming
		// and the result still includes past matches. Fetch both halves in
		// parallel and concat.
		if (activeSearch.value) {
			const [upcoming, past] = await Promise.all([
				axios.get(url, { params: {} }),
				axios.get(url, { params: { showPastAppointments: true } }),
			])
			appointments.value = [...upcoming.data, ...past.data]
		} else {
			const params = {}
			if (props.showPast) params.showPastAppointments = true
			if (props.showUnanswered) params.unansweredOnly = true
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

// Refetch when the search switches between "active" and "inactive" — moves
// us between the single-endpoint and dual-endpoint paths.
watch(activeSearch, (now, prev) => {
	if (Boolean(now) !== Boolean(prev)) {
		loadAppointments(true)
	}
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

.filter-bar {
	max-width: 800px;
	margin: 0 auto 12px;
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;

	&__options {
		min-width: 160px;
		padding: 4px 0;
		list-style: none;
		margin: 0;
	}

	&__chips {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
		margin-inline-start: 8px;
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
