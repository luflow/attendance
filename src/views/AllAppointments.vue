<template>
	<div class="attendance-container">
		<!-- Unanswered Banner (only shown on unanswered view after loading) -->
		<div v-if="showUnanswered && !loading" class="unanswered-banner-container">
			<div v-if="appointments.length > 0" class="unanswered-banner pending">
				<AlertIcon :size="20" />
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

		<!-- Appointments List -->
		<div class="appointments-list">
			<div v-if="loading" class="loading">
				{{ t('attendance', 'Loading …') }}
			</div>
			<div v-else-if="appointments.length === 0 && !showUnanswered" class="empty-state">
				{{ t('attendance', 'No appointments found') }}
			</div>
			<div v-else>
				<!-- Use reusable AppointmentCard component -->
				<AppointmentCard
					v-for="appointment in appointments"
					:key="appointment.id"
					:appointment="appointment"
					:can-manage-appointments="permissions.canManageAppointments"
					:can-checkin="permissions.canCheckin"
					:can-see-response-overview="permissions.canSeeResponseOverview"
					:can-see-comments="permissions.canSeeComments"
					@start-checkin="startCheckin"
					@edit="editAppointment"
					@copy="copyAppointment"
					@delete="deleteAppointment"
					@export="showExportDialog"
					@submit-response="submitResponse"
					@update-comment="updateComment" />
			</div>
		</div>

		<!-- Single Appointment Export Dialog -->
		<SingleAppointmentExportDialog
			:show="exportDialogVisible"
			:appointment="selectedAppointmentForExport"
			@close="exportDialogVisible = false" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted, watch } from 'vue'
import { NcButton } from '@nextcloud/vue'
import AppointmentCard from '../components/appointment/AppointmentCard.vue'
import SingleAppointmentExportDialog from '../components/SingleAppointmentExportDialog.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import confettiLib from 'canvas-confetti'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
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

const emit = defineEmits(['response-updated', 'edit-appointment', 'copy-appointment', 'navigate-to-upcoming'])

const appointments = ref([])
const exportDialogVisible = ref(false)
const selectedAppointmentForExport = ref(null)

const goToUpcoming = () => {
	emit('navigate-to-upcoming')
}
const loading = ref(true)
const responseComments = reactive({})

const { permissions, loadPermissions } = usePermissions()

// Use the shared response composable
const { submitResponse: submitResponseApi } = useAppointmentResponse({
	onSuccess: () => {
		emit('response-updated')
		loadAppointments(true)
	},
})

const loadAppointments = async (skipLoadingSpinner = false) => {
	try {
		if (!skipLoadingSpinner) {
			loading.value = true
		}
		const params = props.showPast ? '?showPastAppointments=true' : ''
		const response = await axios.get(generateUrl('/apps/attendance/api/appointments') + params)

		if (props.showUnanswered) {
			appointments.value = response.data.filter(appointment => {
				return !appointment.userResponse || appointment.userResponse === null
			})
		} else {
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

const deleteAppointment = async (appointmentId) => {
	if (confirm(window.t('attendance', 'Are you sure you want to delete this appointment?'))) {
		try {
			await axios.delete(generateUrl(`/apps/attendance/api/appointments/${appointmentId}`))
			await loadAppointments(true)
		} catch (error) {
			console.error('Failed to delete appointment:', error)
		}
	}
}

const editAppointment = (appointment) => {
	emit('edit-appointment', appointment)
}

const copyAppointment = (appointment) => {
	emit('copy-appointment', appointment)
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
const getConfetti = () => {
	if (!confettiInstance) {
		const canvas = document.createElement('canvas')
		canvas.style.position = 'fixed'
		canvas.style.top = '0'
		canvas.style.left = '0'
		canvas.style.width = '100%'
		canvas.style.height = '100%'
		canvas.style.pointerEvents = 'none'
		canvas.style.zIndex = '9999'
		document.body.appendChild(canvas)
		confettiInstance = confettiLib.create(canvas, { resize: true, useWorker: false })
	}
	return confettiInstance
}

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
