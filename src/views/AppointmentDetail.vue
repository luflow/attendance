<template>
	<div class="appointment-detail" data-test="appointment-detail-view">
		<div v-if="unansweredCount > 0"
			class="unanswered-banner"
			role="button"
			@click="emit('navigateToUnanswered')">
			<ProgressQuestion :size="20" />
			<span>{{ n('attendance', '%n appointment awaiting your response', '%n appointments awaiting your response', unansweredCount) }}</span>
			<span class="banner-action">{{ t('attendance', 'View all') }} →</span>
		</div>
		<div v-if="loading" class="loading-state" data-test="loading-state">
			{{ t('attendance', 'Loading\u00A0…') }}
		</div>
		<div v-else-if="error" class="error-state" data-test="error-state">
			<p>{{ error }}</p>
			<NcButton data-test="button-back" @click="goBack">
				{{ t('attendance', 'Back') }}
			</NcButton>
		</div>
		<div v-else-if="appointment" class="appointment-content">
			<!-- Use reusable AppointmentCard component -->
			<AppointmentCard
				:appointment="appointment"
				:canManageAppointments="permissions.canManageAppointments"
				:canCheckin="permissions.canCheckin"
				:canSeeResponseOverview="permissions.canSeeResponseOverview"
				:canSeeComments="permissions.canSeeComments"
				:canSeeAuditLog="canSeeAuditTimeline"
				:displayOrder="config.displayOrder"
				@startCheckin="startCheckin"
				@edit="editAppointment"
				@copy="copyAppointment"
				@delete="deleteAppointment"
				@export="showExportDialog"
				@submitResponse="submitResponse"
				@updateComment="updateComment"
				@closedToggled="onClosedToggled"
				@showAuditLog="scrollToAuditLog" />

			<AuditTimeline v-if="canSeeAuditTimeline" ref="auditTimeline" :appointmentId="appointment.id" />
		</div>

		<!-- Single Appointment Export Dialog -->
		<SingleAppointmentExportDialog
			:show="exportDialogVisible"
			:appointment="appointment"
			@close="exportDialogVisible = false" />

		<!-- Delete Appointment Dialog -->
		<DeleteAppointmentDialog
			:show="showDeleteDialog"
			:appointment="appointment"
			@confirm="handleDeleteConfirm"
			@cancel="showDeleteDialog = false" />
	</div>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import ProgressQuestion from 'vue-material-design-icons/ProgressQuestion.vue'
import AppointmentCard from '../components/appointment/AppointmentCard.vue'
import AuditTimeline from '../components/appointment/AuditTimeline.vue'
import DeleteAppointmentDialog from '../components/appointment/DeleteAppointmentDialog.vue'
import SingleAppointmentExportDialog from '../components/SingleAppointmentExportDialog.vue'
import { useAppointmentResponse } from '../composables/useAppointmentResponse.js'
import { usePermissions } from '../composables/usePermissions.js'

const props = defineProps({
	appointmentId: {
		type: Number,
		required: true,
	},
	unansweredCount: {
		type: Number,
		default: 0,
	},
	// Set to 'audit' to auto-scroll to the activity history after the
	// appointment loads — used when navigating in from another view.
	scrollTarget: {
		type: String,
		default: null,
	},
})

const emit = defineEmits(['responseUpdated', 'editAppointment', 'copyAppointment', 'navigateToUnanswered', 'appointmentDeleted', 'scrollTargetConsumed'])

const auditTimeline = ref(null)

const appointment = ref(null)
const loading = ref(true)
const error = ref(null)
const exportDialogVisible = ref(false)
const showDeleteDialog = ref(false)

// Use the shared permissions composable
const { permissions, capabilities, config, loadPermissions } = usePermissions()

const canSeeAuditTimeline = computed(() => {
	if (!capabilities.auditLog) { return false }
	return permissions.canManageAppointments || permissions.canSeeResponseOverview
})

// Use the shared response composable
const { submitResponse: submitResponseApi } = useAppointmentResponse({
	onSuccess: () => {
		emit('responseUpdated')
		loadAppointmentSilently()
	},
	onError: () => {
		loadAppointment()
	},
})

function goBack() {
	window.history.back()
}

function startCheckin(appointmentId) {
	window.location.href = generateUrl(`/apps/attendance/checkin/${appointmentId}`)
}

function editAppointment(apt) {
	emit('editAppointment', apt)
}

function copyAppointment(apt) {
	emit('copyAppointment', apt)
}

function deleteAppointment() {
	showDeleteDialog.value = true
}

async function handleDeleteConfirm(scope) {
	showDeleteDialog.value = false
	try {
		await axios.delete(generateUrl(`/apps/attendance/api/appointments/${appointment.value.id}`), {
			data: { scope },
		})
		showSuccess(t('attendance', 'Appointment deleted'))
		emit('appointmentDeleted')
	} catch (err) {
		console.error('Failed to delete appointment:', err)
		showError(t('attendance', 'Error deleting appointment'))
	}
}

function showExportDialog() {
	exportDialogVisible.value = true
}

async function submitResponse(appointmentId, response) {
	const comment = appointment.value.userResponse?.comment || ''

	if (response === null) {
		appointment.value.userResponse = null
	} else {
		if (!appointment.value.userResponse) {
			appointment.value.userResponse = {}
		}
		appointment.value.userResponse.response = response
	}

	await submitResponseApi(appointmentId, response, comment)
}

async function updateComment(appointmentId, comment) {
	const response = appointment.value.userResponse?.response || 'yes'

	// Optimistic update
	if (!appointment.value.userResponse) {
		appointment.value.userResponse = {}
	}
	appointment.value.userResponse.comment = comment

	await submitResponseApi(appointmentId, response, comment)
}

function onClosedToggled(updated) {
	if (appointment.value && updated?.id === appointment.value.id) {
		appointment.value = { ...appointment.value, ...updated }
	}
	// Closing/reopening re-buckets the appointment in the nav menu (a
	// closed-but-unanswered inquiry moves out of "Unanswered" into "Upcoming").
	// Trigger the same parent refresh that answering does.
	emit('responseUpdated')
}

async function loadAppointmentSilently() {
	try {
		const response = await axios.get(generateUrl(`/apps/attendance/api/appointments/${props.appointmentId}`))
		appointment.value = response.data
	} catch (err) {
		console.error('Failed to reload appointment silently:', err)
	}
}

async function loadAppointment() {
	loading.value = true
	error.value = null

	try {
		const response = await axios.get(generateUrl(`/apps/attendance/api/appointments/${props.appointmentId}`))
		appointment.value = response.data
	} catch (err) {
		console.error('Failed to load appointment:', err)
		if (err.response?.status === 404) {
			error.value = t('attendance', 'Appointment not found')
		} else {
			error.value = t('attendance', 'Error loading appointment')
		}
	} finally {
		loading.value = false
	}
}

async function scrollToAuditLog() {
	// Wait two ticks: one for the audit ref to bind after appointment loads,
	// one for the timeline's own onMounted fetch to render its first frame.
	await nextTick()
	const el = auditTimeline.value?.$el ?? document.querySelector('[data-test="audit-timeline"]')
	el?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

async function honourScrollTarget() {
	if (props.scrollTarget !== 'audit' || !canSeeAuditTimeline.value) { return }
	await scrollToAuditLog()
	emit('scrollTargetConsumed')
}

onMounted(async () => {
	await loadPermissions()
	await loadAppointment()
	await honourScrollTarget()
})

// Watch for appointmentId changes when navigating between appointments
watch(() => props.appointmentId, async (newId, oldId) => {
	if (newId !== oldId) {
		await loadAppointment()
	}
})
</script>

<style scoped lang="scss">
.appointment-detail {
	padding: 20px;
	max-width: 800px;
	margin: 0 auto;
}

.unanswered-banner {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 16px 20px;
	border-radius: var(--border-radius-large);
	background: #ff8c00;
	color: white;
	border-left: 4px solid #ff6600;
	font-weight: 600;
	margin-bottom: 20px;
	cursor: pointer;

	* {
		cursor: pointer;
	}

	&:hover {
		background: #ff6600;
	}
}

.banner-action {
	margin-left: auto;
	font-weight: normal;
	white-space: nowrap;
	opacity: 0.85;
}

.loading-state {
	text-align: center;
	padding: 40px;
	color: var(--color-text-lighter);
}

.error-state {
	text-align: center;
	padding: 40px;

	p {
		color: var(--color-error);
		margin-bottom: 20px;
	}
}
</style>
