<template>
	<div class="appointment-detail" data-test="appointment-detail-view">
		<div v-if="loading" class="loading-state" data-test="loading-state">
			{{ t('attendance', 'Loading …') }}
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

		<!-- Single Appointment Export Dialog -->
		<SingleAppointmentExportDialog
			:show="exportDialogVisible"
			:appointment="appointment"
			@close="exportDialogVisible = false" />
	</div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue'
import { NcButton } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import AppointmentCard from '../components/appointment/AppointmentCard.vue'
import SingleAppointmentExportDialog from '../components/SingleAppointmentExportDialog.vue'
import { usePermissions } from '../composables/usePermissions.js'
import { useAppointmentResponse } from '../composables/useAppointmentResponse.js'

const props = defineProps({
	appointmentId: {
		type: Number,
		required: true,
	},
})

const emit = defineEmits(['response-updated', 'edit-appointment', 'copy-appointment'])

const appointment = ref(null)
const loading = ref(true)
const error = ref(null)
const exportDialogVisible = ref(false)

// Use the shared permissions composable
const { permissions, loadPermissions } = usePermissions()

// Use the shared response composable
const { submitResponse: submitResponseApi } = useAppointmentResponse({
	onSuccess: () => {
		emit('response-updated')
		loadAppointmentSilently()
	},
	onError: () => {
		loadAppointment()
	},
})

const goBack = () => {
	window.history.back()
}

const startCheckin = (appointmentId) => {
	window.location.href = generateUrl(`/apps/attendance/checkin/${appointmentId}`)
}

const editAppointment = (apt) => {
	emit('edit-appointment', apt)
}

const copyAppointment = (apt) => {
	emit('copy-appointment', apt)
}

const deleteAppointment = async (appointmentId) => {
	if (confirm(t('attendance', 'Are you sure you want to delete this appointment?'))) {
		try {
			await axios.delete(generateUrl(`/apps/attendance/api/appointments/${appointmentId}`))
			showSuccess(t('attendance', 'Appointment deleted'))
			goBack()
		} catch (error) {
			console.error('Failed to delete appointment:', error)
			showError(t('attendance', 'Error deleting appointment'))
		}
	}
}

const showExportDialog = () => {
	exportDialogVisible.value = true
}

const submitResponse = async (appointmentId, response) => {
	const comment = appointment.value.userResponse?.comment || ''

	// Optimistic update
	if (!appointment.value.userResponse) {
		appointment.value.userResponse = {}
	}
	appointment.value.userResponse.response = response

	await submitResponseApi(appointmentId, response, comment)
}

const updateComment = async (appointmentId, comment) => {
	const response = appointment.value.userResponse?.response || 'yes'

	// Optimistic update
	if (!appointment.value.userResponse) {
		appointment.value.userResponse = {}
	}
	appointment.value.userResponse.comment = comment

	await submitResponseApi(appointmentId, response, comment)
}

const loadAppointmentSilently = async () => {
	try {
		const response = await axios.get(generateUrl(`/apps/attendance/api/appointments/${props.appointmentId}`))
		appointment.value = response.data
	} catch (err) {
		console.error('Failed to reload appointment silently:', err)
	}
}

const loadAppointment = async () => {
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

onMounted(async () => {
	await loadPermissions()
	await loadAppointment()
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
