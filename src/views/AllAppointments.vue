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
					:can-manage-appointments="canManageAppointments"
					:can-checkin="canCheckin"
					:can-see-response-overview="canSeeResponseOverview"
					:can-see-comments="canSeeComments"
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
import { ref, reactive, computed, onMounted } from 'vue'
import AppointmentCard from '../components/appointment/AppointmentCard.vue'
import AppointmentFormModal from '../components/appointment/AppointmentFormModal.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { fromZonedTime } from 'date-fns-tz'

// Props
const props = defineProps({
	showPast: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits(['response-updated'])

// State
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
const permissions = reactive({
	canManageAppointments: false,
	canCheckin: false,
	canSeeResponseOverview: false,
	canSeeComments: false,
})

// Computed
const canManageAppointments = computed(() => permissions.canManageAppointments)
const canCheckin = computed(() => permissions.canCheckin)
const canSeeResponseOverview = computed(() => permissions.canSeeResponseOverview)
const canSeeComments = computed(() => permissions.canSeeComments)

// Methods
const loadPermissions = async () => {
	try {
		const response = await axios.get(generateUrl('/apps/attendance/api/user/permissions'))
		permissions.canManageAppointments = response.data.canManageAppointments
		permissions.canCheckin = response.data.canCheckin
		permissions.canSeeResponseOverview = response.data.canSeeResponseOverview
		permissions.canSeeComments = response.data.canSeeComments
	} catch (error) {
		console.error('Failed to load permissions:', error)
	}
}

const loadAppointments = async (skipLoadingSpinner = false) => {
	try {
		// Don't show loading spinner when refreshing data
		if (!skipLoadingSpinner) {
			loading.value = true
		}
		const params = props.showPast ? '?showPastAppointments=true' : ''
		const response = await axios.get(generateUrl('/apps/attendance/api/appointments') + params)
		appointments.value = response.data

		// Initialize response comments
		appointments.value.forEach(appointment => {
			if (appointment.userResponse) {
				responseComments[appointment.id] = appointment.userResponse.comment || ''
			}
		})

		// Load detailed responses for users who can manage appointments
		if (canManageAppointments.value) {
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
		
		// Update existing appointment
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
		// Get existing comment from appointment if available
		const appointment = appointments.value.find(a => a.id === appointmentId)
		const comment = appointment?.userResponse?.comment || ''
		
		const axiosResponse = await axios.post(generateUrl(`/apps/attendance/api/appointments/${appointmentId}/respond`), {
			response,
			comment,
		})
		
		// Check if response status is 2xx
		if (axiosResponse.status < 200 || axiosResponse.status >= 300) {
			throw new Error(`API returned status ${axiosResponse.status}`)
		}
		
		showSuccess(t('attendance', 'Response updated successfully'))
		
		// Emit event to update sidebar
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
		
		// Check if response status is 2xx
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
	// Navigate to check-in page for this appointment
	window.location.href = generateUrl(`/apps/attendance/checkin/${appointmentId}`)
}

// Lifecycle
onMounted(async () => {
	await loadPermissions()
	await loadAppointments()
})
</script>

<style scoped lang="scss">
.attendance-container {
	padding: 20px;
	max-width: 1200px;
	margin: 0 auto;
}

.attendance-header {
	display: flex;
	justify-content: center;
	align-items: center;
	margin-bottom: 30px;

	.header-buttons {
		display: flex;
		gap: 10px;
		align-items: center;
	}

	h1 {
		margin: 0;
	}
}
.modal-content {
	padding: 20px;

	h2 {
		margin-top: 0;
	}

	.input-field,
	.textarea,
	.native-datetime-picker {
		margin-bottom: 15px;
	}

	input[type="datetime-local"] {
		width: 100%;
		padding: 8px 12px;
		border: 1px solid var(--color-border);
		border-radius: 4px;
		background: var(--color-main-background);
		color: var(--color-text);
		font-size: 14px;

		&:focus {
			outline: none;
			border-color: var(--color-primary);
			box-shadow: 0 0 0 2px rgba(var(--color-primary-rgb), 0.2);
		}
	}

	.form-actions {
		display: flex;
		gap: 10px;
		justify-content: flex-end;
		margin-top: 20px;
	}
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

.appointment-card {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 20px;
	margin-bottom: 20px;
	background: var(--color-main-background);

	.appointment-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 10px;

		h3 {
			margin: 0;
		}

		.appointment-actions {
			display: flex;
			gap: 10px;
		}
	}

	.appointment-description {
		color: var(--color-text-lighter);
		margin-bottom: 15px;
	}

	.appointment-time {
		margin-bottom: 20px;
		font-size: 14px;
	}
}

.response-summary {
	border-top: 1px solid var(--color-border);
    margin-top: 30px;
}

.response-section {
	border-top: 1px solid var(--color-border);
	padding-top: 20px;
	margin-top: 25px;

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

		.comment-actions {
			margin-top: 10px;
			display: flex;
			justify-content: flex-start;
		}
	}
}


.non-responding-header {
	font-weight: bold;
	margin-bottom: 5px;
	color: var(--color-text-lighter);
}


.non-responding-users {
	padding: 10px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	font-size: 0.9em;

	.non-responding-list {
		display: flex;
		flex-wrap: wrap;
		gap: 5px;

		.non-responding-user {
			color: var(--color-text-lighter);

			&:not(:last-child)::after {
				content: ",";
			}

			&:last-child::after {
				content: "";
			}
		}
	}

	.non-responding-users-section {
		margin-top: 20px;
		padding: 15px;
		background: var(--color-background-hover);
		border-radius: var(--border-radius);

		h4 {
			margin-top: 0;
			margin-bottom: 10px;
			color: var(--color-text-lighter);
		}

		.non-responding-users-list {
			display: flex;
			flex-wrap: wrap;
			gap: 8px;

			.non-responding-user {
				background: var(--color-background-darker);
				padding: 4px 10px;
				border-radius: 12px;
				font-size: 0.9em;
				color: var(--color-text-lighter);

				&:not(:last-child)::after {
					content: "";
				}
			}
		}
	}
}


.summary-stats {

	.stat {
		display: inline-block;
		padding: 5px 10px;
		border-radius: 4px;
		font-size: 14px;
		color: #fff;
		margin-right: 5px;
		margin-bottom: 5px;

		&.yes {
			background: var(--color-success);
		}

		&.maybe {
			background: var(--color-warning);
		}
		
		body[data-theme-dark] &.maybe {
			color: black;
		}
		
		@media (prefers-color-scheme: dark) {
			body[data-theme-default] &.maybe {
				color: black;
			}
		}

		&.no {
			background: var(--color-error);
		}

		&.no-response {
			background: var(--color-background-dark);
			color: var(--color-text-lighter);
		}
	}
}

.group-details {
	margin-top: 8px;
	margin-left: 20px;
	padding: 12px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 4px;
}


.no-responses {
	color: var(--color-text-lighter);
	font-style: italic;
	text-align: center;
	padding: 10px;
}

.group-responses {
	.response-item {
		border-bottom: 1px solid var(--color-border);
		padding: 10px 0;

		&:last-child {
			border-bottom: none;
		}

		.response-header {
			display: flex;
			align-items: center;
			justify-content: space-between;
			margin-bottom: 5px;
			gap: 10px;

			.user-info {
				display: flex;
				align-items: center;
				gap: 10px;
			}

			.checkin-info {
				display: flex;
				align-items: center;
				gap: 8px;
			}

			.checkin-badge {
				font-size: 12px;
				font-weight: bold;
			}

			.response-badge {
				padding: 2px 8px;
				border-radius: 12px;
				font-size: 12px;
				font-weight: bold;
				color: #fff;

				&.yes {
					background: var(--color-success);
				}

				&.maybe {
					background: var(--color-warning);
				}
				
				body[data-theme-dark] &.maybe {
					color: black;
				}
				
				@media (prefers-color-scheme: dark) {
					body[data-theme-default] &.maybe {
						color: black;
					}
				}

				&.no {
					background: var(--color-error);
				}
			}
		}

		.response-comment {
			margin: 8px 0;
			padding: 8px;
			background: var(--color-background-hover);
			border-radius: 4px;
			border-left: 3px solid var(--color-primary);
			font-style: italic;
		}

		.response-date {
			font-size: 12px;
			color: var(--color-text-lighter);
			text-align: right;
		}
	}
}


.group-summary {
	margin-top: 20px;

	h5 {
		margin: 0 0 10px 0;
		font-size: 16px;
		color: var(--color-text);
	}

	.group-container {
		margin-bottom: 8px;
	}

	.group-stats {
		display: flex;
		align-items: center;
		padding: 8px;
		background: var(--color-background-hover);
		border-radius: 4px;
		transition: background-color 0.2s ease;

		&.clickable {
			cursor: pointer;

			&:hover {
				background: var(--color-background-dark);
			}
		}

		.group-name {
			min-width: 100px;
			font-weight: 500;
			color: var(--color-text);
			margin-right: 15px;
			display: flex;
			align-items: center;
			gap: 8px;

			.expand-icon {
				font-size: 12px;
				transition: transform 0.2s ease;
				color: var(--color-text-lighter);

				&.expanded {
					transform: rotate(90deg);
				}
			}
		}

		.group-counts {
			display: flex;
			gap: 10px;

			.stat {
				color: #ffffff;
				padding: 3px 8px;
				border-radius: 3px;
				font-size: 12px;
				font-weight: bold;
				min-width: 35px;
				text-align: center;

				&.yes {
					background: var(--color-success);
				}

				&.maybe {
					background: var(--color-warning);
				}
				
				body[data-theme-dark] &.maybe {
					color: black;
				}
				
				@media (prefers-color-scheme: dark) {
					body[data-theme-default] &.maybe {
						color: black;
					}
				}

				&.no {
					background: var(--color-error);
				}

				&.no-response {
					background: var(--color-background-dark);
					color: var(--color-text-lighter);
				}
			}
		}
	}

	.group-details {
		margin-top: 8px;
		margin-left: 20px;
		padding: 12px;
		background: var(--color-main-background);
		border: 1px solid var(--color-border);
		border-radius: 4px;

		.no-responses {
			color: var(--color-text-lighter);
			font-style: italic;
			text-align: center;
			padding: 10px;
		}

		.group-responses {
			.response-item {
				border-bottom: 1px solid var(--color-border);
				padding: 10px 0;

				&:last-child {
					border-bottom: none;
				}

				.response-header {
					display: flex;
					align-items: center;
					margin-bottom: 5px;
					gap: 10px;

					.response-badge {
						padding: 2px 8px;
						border-radius: 12px;
						font-size: 12px;
						font-weight: bold;

						&.yes {
							background: var(--color-success);
						}

						&.maybe {
							background: var(--color-warning);
						}

						&.no {
							background: var(--color-error);
						}
					}
				}

				.response-comment {
					margin: 8px 0;
					padding: 8px;
					background: var(--color-background-hover);
					border-radius: 4px;
					border-left: 3px solid var(--color-primary);
					font-style: italic;
				}

				.response-date {
					font-size: 12px;
					color: var(--color-text-lighter);
					text-align: right;
				}
			}
		}
	}
}

.admin-comments {
	border-top: 1px solid var(--color-border);
	padding-top: 15px;
	margin-top: 15px;

	h4 {
		margin: 0 0 10px 0;
	}

	.no-comments {
		color: var(--color-text-lighter);
		font-style: italic;
		padding: 10px 0;
	}

	.comments-list {
		.comment-item {
			border: 1px solid var(--color-border);
			border-radius: 4px;
			padding: 10px;
			margin-bottom: 10px;
			background: var(--color-background-hover);

			.comment-header {
				display: flex;
				align-items: center;
				margin-bottom: 5px;
				gap: 10px;

				.response-badge {
					padding: 2px 8px;
					border-radius: 12px;
					font-size: 12px;
					font-weight: bold;

					&.yes {
						background: var(--color-success);
					}

					&.maybe {
						background: var(--color-warning);
					}
					
					body[data-theme-dark] &.maybe {
						color: black;
					}
					
					@media (prefers-color-scheme: dark) {
						body[data-theme-default] &.maybe {
							color: black;
						}
					}

					&.no {
						background: var(--color-error);
					}
				}
			}

			.comment-text {
				margin: 8px 0;
				padding: 8px;
				background: var(--color-main-background);
				border-radius: 4px;
				border-left: 3px solid var(--color-primary);
			}

			.comment-date {
				font-size: 12px;
				color: var(--color-text-lighter);
				text-align: right;
			}
		}
	}
}
</style>
