<template>
	<div class="appointment-detail">
		<div v-if="loading" class="loading-state">
			{{ t('attendance', 'Loading...') }}
		</div>
		<div v-else-if="error" class="error-state">
			<p>{{ error }}</p>
			<NcButton @click="goBack">
				{{ t('attendance', 'Back') }}
			</NcButton>
		</div>
		<div v-else-if="appointment" class="appointment-content">
			<!-- Use reusable AppointmentCard component -->
			<AppointmentCard
				:appointment="appointment"
				:can-manage-appointments="canManageAppointments"
				:can-checkin="canCheckin"
				@start-checkin="startCheckin"
				@edit="editAppointment"
				@delete="deleteAppointment"
				@submit-response="submitResponse"
				@update-comment="updateComment" />
		</div>
		
		<!-- Edit Appointment Modal -->
		<AppointmentFormModal
			:show="showEditForm"
			:appointment="editingAppointment.id ? editingAppointment : null"
			@close="handleModalClose"
			@submit="handleModalSubmit" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted, watch } from 'vue'
import { NcButton } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { fromZonedTime } from 'date-fns-tz'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import AppointmentCard from '../components/appointment/AppointmentCard.vue'
import AppointmentFormModal from '../components/appointment/AppointmentFormModal.vue'

const props = defineProps({
	appointmentId: {
		type: Number,
		required: true,
	},
})

const emit = defineEmits(['response-updated'])

const appointment = ref(null)
const loading = ref(true)
const error = ref(null)
const canManageAppointments = ref(false)
const canCheckin = ref(false)
const showEditForm = ref(false)
const editingAppointment = reactive({
	id: null,
	name: '',
	description: '',
	startDatetime: '',
	endDatetime: '',
})

const goBack = () => {
	window.history.back()
}

const startCheckin = (appointmentId) => {
	window.location.href = generateUrl(`/apps/attendance/checkin/${appointmentId}`)
}

const editAppointment = (apt) => {
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
	
	const formattedStart = formatDateTimeForInput(apt.startDatetime)
	const formattedEnd = formatDateTimeForInput(apt.endDatetime)
	
	Object.assign(editingAppointment, {
		id: apt.id,
		name: apt.name,
		description: apt.description || '',
		startDatetime: formattedStart,
		endDatetime: formattedEnd,
	})
	
	showEditForm.value = true
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
		await loadAppointment()
	} catch (error) {
		console.error('Failed to update appointment:', error)
		showError(t('attendance', 'Error updating appointment'))
	}
}

const deleteAppointment = async (appointmentId) => {
	if (confirm(t('attendance', 'Are you sure you want to delete this appointment?'))) {
		try {
			await axios.delete(generateUrl(`/apps/attendance/api/appointments/${appointmentId}`))
			showSuccess(t('attendance', 'Appointment deleted successfully'))
			goBack()
		} catch (error) {
			console.error('Failed to delete appointment:', error)
			showError(t('attendance', 'Error deleting appointment'))
		}
	}
}

const submitResponse = async (appointmentId, response) => {
	try {
		const comment = appointment.value.userResponse?.comment || ''
		
		if (!appointment.value.userResponse) {
			appointment.value.userResponse = {}
		}
		appointment.value.userResponse.response = response
		
		await axios.post(generateUrl(`/apps/attendance/api/appointments/${appointmentId}/respond`), {
			response,
			comment,
		})
		showSuccess(t('attendance', 'Response updated successfully'))
		
		emit('response-updated')
		await loadAppointmentSilently()
	} catch (error) {
		console.error('Failed to submit response:', error)
		showError(t('attendance', 'Error updating response'))
		await loadAppointment()
	}
}

const updateComment = async (appointmentId, comment, silent = false) => {
	try {
		const response = appointment.value.userResponse?.response || 'yes'
		
		if (!appointment.value.userResponse) {
			appointment.value.userResponse = {}
		}
		appointment.value.userResponse.comment = comment
		
		await axios.post(generateUrl(`/apps/attendance/api/appointments/${appointmentId}/respond`), {
			response,
			comment,
		})
		
		if (!silent) {
			showSuccess(t('attendance', 'Comment updated successfully'))
		}
		
		await loadAppointmentSilently()
	} catch (error) {
		console.error('Failed to update comment:', error)
		showError(t('attendance', 'Error updating comment'))
		await loadAppointment()
	}
}

const loadAppointmentSilently = async () => {
	try {
		let response = await axios.get(generateUrl('/apps/attendance/api/appointments'), {
			params: { showPastAppointments: false },
		})
		
		let found = response.data.find(apt => apt.id === props.appointmentId)
		
		if (!found) {
			response = await axios.get(generateUrl('/apps/attendance/api/appointments'), {
				params: { showPastAppointments: true },
			})
			found = response.data.find(apt => apt.id === props.appointmentId)
		}
		
		if (found) {
			appointment.value = found
		}
	} catch (err) {
		console.error('Failed to reload appointment silently:', err)
	}
}

const loadAppointment = async () => {
	loading.value = true
	error.value = null
	
	try {
		// Try loading from current appointments first
		let response = await axios.get(generateUrl('/apps/attendance/api/appointments'), {
			params: { showPastAppointments: false },
		})
		
		let found = response.data.find(apt => apt.id === props.appointmentId)
		
		// If not found in current, try past appointments
		if (!found) {
			response = await axios.get(generateUrl('/apps/attendance/api/appointments'), {
				params: { showPastAppointments: true },
			})
			found = response.data.find(apt => apt.id === props.appointmentId)
		}
		
		if (!found) {
			error.value = t('attendance', 'Appointment not found')
			return
		}
		
		appointment.value = found
	} catch (err) {
		console.error('Failed to load appointment:', err)
		error.value = t('attendance', 'Error loading appointment')
	} finally {
		loading.value = false
	}
}

const loadPermissions = async () => {
	try {
		const response = await axios.get(generateUrl('/apps/attendance/api/user/permissions'))
		canManageAppointments.value = response.data.canManageAppointments
		canCheckin.value = response.data.canCheckin
	} catch (error) {
		console.error('Failed to load permissions:', error)
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

.appointment-detail-header {
	margin-bottom: 20px;
}

.appointment-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	margin-bottom: 20px;
}

.appointment-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 15px;

	h2 {
		margin: 0;
		font-size: 24px;
		color: var(--color-main-text);
		flex: 1;
	}

	.appointment-actions {
		margin-left: 10px;
	}
}

.appointment-description {
	color: var(--color-text-lighter);
	margin-bottom: 15px;
	white-space: pre-wrap;
}

.appointment-time {
	padding: 10px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	margin-bottom: 20px;
	font-size: 14px;

	strong {
		color: var(--color-main-text);
	}
}

.response-section {
	border-top: 1px solid var(--color-border);
	padding-top: 20px;
	margin-top: 20px;

	h4 {
		margin: 0 0 10px 0;
	}

	.response-buttons {
		display: flex;
		gap: 10px;
		margin-bottom: 15px;

		// Apply colors to all buttons based on type attribute
		:deep(button[type="success"]) {
			background-color: var(--color-success) !important;
			border-color: var(--color-success) !important;
		}

		:deep(button[type="warning"]) {
			background-color: var(--color-warning) !important;
			border-color: var(--color-warning) !important;
		}

		:deep(button[type="error"]) {
			background-color: var(--color-error) !important;
			border-color: var(--color-error) !important;
		}

		// When a response exists, gray out non-active buttons
		&.has-response {
			:deep(button:not(.active)) {
				background-color: var(--color-background-dark) !important;
				color: var(--color-text-lighter) !important;
				border-color: var(--color-border-dark) !important;

				&:hover {
					background-color: var(--color-background-hover) !important;
					color: var(--color-text) !important;
				}
			}
		}

		// Active button styles - keep bold
		:deep(button.active) {
			font-weight: bold;
		}
	}

	.comment-section {
		margin-top: 10px;
	}
}

.response-summary {
	border-top: 1px solid var(--color-border);
	padding-top: 20px;
	margin-top: 20px;

	h4 {
		margin: 0 0 15px 0;
	}

	.summary-stats {
		display: flex;
		gap: 20px;
		justify-content: space-around;

		.stat-item {
			text-align: center;
			padding: 15px;
			border-radius: var(--border-radius);
			flex: 1;

			strong {
				display: block;
				font-size: 28px;
				margin-bottom: 5px;
			}

			span {
				font-size: 14px;
				color: var(--color-text-lighter);
			}

			&.yes {
				background: var(--color-success-light);
				
				strong {
					color: var(--color-success-text);
				}
			}

			&.maybe {
				background: var(--color-warning-light);
				
				strong {
					color: var(--color-warning-text);
				}
			}

			&.no {
				background: var(--color-error-light);
				
				strong {
					color: var(--color-error-text);
				}
			}
		}
	}
}
</style>
