<template>
	<div class="attendance-container">
		<!-- Edit Appointment Modal -->
		<AppointmentFormModal
			:show="showEditForm"
			:appointment="editingAppointment.id ? editingAppointment : null"
			@close="handleModalClose"
			@submit="handleModalSubmit" />
		<!-- Appointments List -->
		<div class="appointments-list">
			<div v-if="loading" class="loading">
				{{ t('attendance', 'Loading...') }}
			</div>
			<div v-else-if="appointments.length === 0" class="empty-state">
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
					@delete="deleteAppointment"
					@submit-response="submitResponse"
					@update-comment="updateComment" />
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import AppointmentCard from '../components/appointment/AppointmentCard.vue'
import AppointmentFormModal from '../components/appointment/AppointmentFormModal.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { fromZonedTime } from 'date-fns-tz'
import { usePermissions } from '../composables/usePermissions.js'

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

const emit = defineEmits(['response-updated'])

const appointments = ref([])
const loading = ref(true)
const showEditForm = ref(false)
const responseComments = reactive({})
const editingAppointment = reactive({
	id: null,
	name: '',
	description: '',
	startDatetime: '',
	endDatetime: '',
})

const { permissions, loadPermissions } = usePermissions()

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

const handleModalClose = () => {
	showEditForm.value = false
	Object.assign(editingAppointment, {
		id: null,
		name: '',
		description: '',
		startDatetime: '',
		endDatetime: '',
	})
}

const handleModalSubmit = async (formData) => {
	try {
		const startDatetimeWithTz = fromZonedTime(formData.startDatetime, 'Europe/Berlin')
		const endDatetimeWithTz = fromZonedTime(formData.endDatetime, 'Europe/Berlin')
		
		await axios.put(generateUrl(`/apps/attendance/api/appointments/${formData.id}`), {
			name: formData.name,
			description: formData.description,
			startDatetime: startDatetimeWithTz,
			endDatetime: endDatetimeWithTz,
		})
		
		showSuccess(t('attendance', 'Appointment updated successfully'))
		handleModalClose()
		await loadAppointments(true)
	} catch (error) {
		console.error('Failed to update appointment:', error)
		showError(t('attendance', 'Error updating appointment'))
	}
}

const submitResponse = async (appointmentId, response) => {
	try {
		const appointment = appointments.value.find(a => a.id === appointmentId)
		const comment = appointment?.userResponse?.comment || ''
		
		const axiosResponse = await axios.post(generateUrl(`/apps/attendance/api/appointments/${appointmentId}/respond`), {
			response,
			comment,
		})
		
		if (axiosResponse.status < 200 || axiosResponse.status >= 300) {
			throw new Error(`API returned status ${axiosResponse.status}`)
		}
		
		showSuccess(t('attendance', 'Response updated successfully'))
		
		emit('response-updated')
		
		await loadAppointments(true)
	} catch (error) {
		console.error('Failed to submit response:', error)
		showError(t('attendance', 'Error updating response'))
	}
}

const updateComment = async (appointmentId, comment, silent = false) => {
	try {
		const appointment = appointments.value.find(a => a.id === appointmentId)
		const response = appointment?.userResponse?.response || 'yes'
		
		const axiosResponse = await axios.post(generateUrl(`/apps/attendance/api/appointments/${appointmentId}/respond`), {
			response,
			comment,
		})
		
		if (axiosResponse.status < 200 || axiosResponse.status >= 300) {
			throw new Error(`API returned status ${axiosResponse.status}`)
		}
		
		if (!silent) {
			showSuccess(t('attendance', 'Comment updated successfully'))
		}
		
		await loadAppointments(true)
	} catch (error) {
		console.error('Failed to update comment:', error)
		if (!silent) {
			showError(t('attendance', 'Error updating comment'))
		}
	}
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
	const formatDateTimeForInput = (dateTime) => {
		if (!dateTime) return ''
		const date = new Date(dateTime)
		if (isNaN(date.getTime())) return ''
		
		const year = date.getFullYear()
		const month = String(date.getMonth() + 1).padStart(2, '0')
		const day = String(date.getDate()).padStart(2, '0')
		const hours = String(date.getHours()).padStart(2, '0')
		const minutes = String(date.getMinutes()).padStart(2, '0')
		
		return `${year}-${month}-${day}T${hours}:${minutes}`
	}
	
	const formattedStart = formatDateTimeForInput(appointment.startDatetime)
	const formattedEnd = formatDateTimeForInput(appointment.endDatetime)

	Object.assign(editingAppointment, {
		id: appointment.id,
		name: appointment.name,
		description: appointment.description || '',
		startDatetime: formattedStart,
		endDatetime: formattedEnd,
	})

	showEditForm.value = true
}

const startCheckin = (appointmentId) => {
	window.location.href = generateUrl(`/apps/attendance/checkin/${appointmentId}`)
}

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
</style>
