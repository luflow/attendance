<template>
	<div class="appointment-widget-container">
		<NcDashboardWidget :items="items"
			:show-more-url="showMoreUrl"
			:show-more-text="title"
			:loading="state === 'loading'"
			class="appointment-widget">
			<template #empty-content>
				<NcEmptyContent :title="t('attendance', 'No appointments found')">
					<template #icon>
						<CalendarIcon />
					</template>
				</NcEmptyContent>
			</template>
			<template #default="{ item }">
				<div class="appointment-item">

					<!-- Check-in Section (for admins when appointment is ready for check-in) -->
					<div v-if="showCheckinButton(item)" class="checkin-section">
						<NcButton
							variant="primary"
							class="checkin-button"
							@click="openCheckinView(item.id)">
							<template #icon>
								<ListStatusIcon />
							</template>
							{{ t('attendance', 'Start check-in') }}
						</NcButton>
					</div>
					<div class="appointment-header">
						<h3 class="clickable" @click="openAppointmentDetail(item.id)">{{ item.mainText }}</h3>
						<span class="appointment-time">{{ formatDate(item.subText) }}</span>
					</div>
					<div v-if="item.description" class="appointment-description clickable" @click="openAppointmentDetail(item.id)">
						{{ renderDescriptionMarkdown(item.description) }}
					</div>

					<!-- Response Section -->
					<div class="response-section">
						<div class="response-buttons" :class="{ 'has-response': item.userResponse }">
							<NcButton
								:class="{ active: item.userResponse?.response === 'yes' }"
								variant="success"
								:text="t('attendance', 'Yes')"
								@click="respond(item.id, 'yes')" />
							<NcButton
								:class="{ active: item.userResponse?.response === 'maybe' }"
								variant="warning"
								:text="t('attendance', 'Maybe')"
								@click="respond(item.id, 'maybe')" />
							<NcButton
								:class="{ active: item.userResponse?.response === 'no' }"
								variant="error"
								:text="t('attendance', 'No')"
								@click="respond(item.id, 'no')" />
							<!-- Comment Toggle Button -->
							<NcButton
								:class="{ 'comment-active': commentExpanded[item.id], 'comment-toggle': true }"
								type="tertiary"
								@click="toggleComment(item.id)">
								<template #icon>
									<CommentIcon :size="14" />
								</template>
							</NcButton>
						</div>

						<!-- Comment Section -->
						<div v-if="commentExpanded[item.id]" class="comment-section">
							<div class="textarea-container">
								<NcTextArea
									resize="vertical"
									:value="responseComments[item.id] || item.userResponse?.comment || undefined"
									:placeholder="t('attendance', 'Comment (optional)')"
									@input="onCommentInput(item.id, $event.target.value)" />
								
								<div v-if="savingComments[item.id]" class="saving-spinner">
									<div class="spinner"></div>
								</div>
								<div v-else-if="savedComments[item.id]" class="saved-indicator">
									<CheckIcon :size="16" class="check-icon" />
								</div>
							</div>
						</div>
					</div>
				</div>
			</template>
		</NcDashboardWidget>

		<!-- Show All Button -->
		<div class="widget-footer">
			<NcButton 
				variant="primary" 
				wide 
				@click="goToAttendanceApp">
				{{ t('attendance', 'Show all appointments') }}
			</NcButton>
		</div>
	</div>

	<!-- Comment Dialog -->
	<NcModal v-if="showDialog" @close="closeDialog">
		<div class="comment-dialog">
			<h2>{{ t('attendance', 'Add comment') }}</h2>
			<NcTextArea
				v-model="comment"
				:placeholder="t('attendance', 'Optional: Reason for your response')"
				rows="3" />
			<div class="dialog-buttons">
				<NcButton type="secondary" @click="closeDialog">
					{{ t('attendance', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" @click="submitResponse">
					{{ t('attendance', 'Confirm') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import ListStatusIcon from 'vue-material-design-icons/ListStatus.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CommentIcon from 'vue-material-design-icons/Comment.vue'

import { NcDashboardWidget, NcEmptyContent, NcButton, NcModal, NcTextArea } from '@nextcloud/vue'

import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import axios from '@nextcloud/axios'

// Props
defineProps({
	title: {
		type: String,
		required: true,
	},
})

// State initialization
let initialAppointments = []
let initialState = 'ok'

try {
	initialAppointments = loadState('attendance', 'dashboard-widget-items')
} catch (error) {
	console.error('Error loading appointments:', error)
	initialState = 'error'
}

const appointments = ref(initialAppointments)
const showMoreUrl = ref(generateUrl('/apps/attendance'))
const state = ref(initialState)
const showDialog = ref(false)
const comment = ref('')
const selectedAppointmentId = ref(null)
const selectedResponse = ref(null)
const responseComments = reactive({})
const savingComments = reactive({})
const savedComments = reactive({})
const commentTimeouts = reactive({})
const commentExpanded = reactive({})
const permissions = reactive({
	canManageAppointments: false,
	canCheckin: false,
})

// Computed
const items = computed(() => {
	return appointments.value.map((appointment) => {
		return {
			id: appointment.id,
			mainText: appointment.name,
			subText: appointment.startDatetime,
			description: appointment.description,
			link: generateUrl('/apps/attendance'),
			iconUrl: generateUrl('/svg/attendance/calendar'),
			userResponse: appointment.userResponse,
		}
	})
})

// Methods
const respond = (appointmentId, response) => {
	submitResponseToServer(appointmentId, response, '')
}

const showCommentDialog = (appointmentId, response) => {
	selectedAppointmentId.value = appointmentId
	selectedResponse.value = response
	comment.value = ''
	showDialog.value = true
}

const closeDialog = () => {
	showDialog.value = false
	selectedAppointmentId.value = null
	selectedResponse.value = null
	comment.value = ''
}

const submitResponse = () => {
	if (selectedAppointmentId.value && selectedResponse.value) {
		submitResponseToServer(selectedAppointmentId.value, selectedResponse.value, comment.value)
		closeDialog()
	}
}

const submitResponseToServer = async (appointmentId, response, commentText) => {
	try {
		const url = generateUrl('/apps/attendance/api/appointments/{id}/respond', { id: appointmentId })
		await axios.post(url, {
			response,
			comment: commentText,
		})

		// Update local state
		const appointmentIndex = appointments.value.findIndex(a => a.id === appointmentId)
		if (appointmentIndex !== -1) {
			appointments.value[appointmentIndex].userResponse = {
				response,
				comment: commentText,
			}
		}

		// Response saved successfully
	} catch (error) {
		// Failed to save response
	}
}

const getResponseText = (response) => {
	switch (response) {
		case 'yes': return window.t('attendance', 'Yes')
		case 'no': return window.t('attendance', 'No')
		case 'maybe': return window.t('attendance', 'Maybe')
		default: return response
	}
}

const formatDate = (datetime) => {
	try {
		const date = new Date(datetime)
		const options = { dateStyle: 'short', timeStyle: 'short' }
		return date.toLocaleString(['de-DE', 'en-EN'], options)
	} catch (error) {
		return datetime
	}
}

const renderDescriptionMarkdown = (description) => {
	if (!description) return ''
	// Strip markdown formatting for compact widget display
	let text = description
		.replace(/\*\*([^*]+)\*\*/g, '$1')  // Remove bold: **text** -> text
		.replace(/\*([^*]+)\*/g, '$1')      // Remove italic: *text* -> text
		.replace(/\n/g, ' ')                 // Remove newlines
		.trim()
	return text
}

const toggleComment = (appointmentId) => {
	commentExpanded[appointmentId] = !commentExpanded[appointmentId]
}

const onCommentInput = (appointmentId, value) => {
	responseComments[appointmentId] = value

	if (commentTimeouts[appointmentId]) {
		clearTimeout(commentTimeouts[appointmentId])
	}

	commentTimeouts[appointmentId] = setTimeout(() => {
		autoSaveComment(appointmentId, value)
	}, 500)
}

const autoSaveComment = async (appointmentId, commentText) => {
	const appointment = appointments.value.find(a => a.id === appointmentId)
	if (appointment && appointment.userResponse) {
		savingComments[appointmentId] = true
		savedComments[appointmentId] = false

		try {
			await submitResponseToServer(appointmentId, appointment.userResponse.response, commentText)
			
			setTimeout(() => {
				savingComments[appointmentId] = false
				savedComments[appointmentId] = true
				
				setTimeout(() => {
					savedComments[appointmentId] = false
				}, 2000)
			}, 500)
		} catch (error) {
			savingComments[appointmentId] = false
		}
	}
}

const updateComment = async (appointmentId) => {
	const commentText = responseComments[appointmentId] || ''
	const appointment = appointments.value.find(a => a.id === appointmentId)
	if (appointment && appointment.userResponse) {
		await submitResponseToServer(appointmentId, appointment.userResponse.response, commentText)
	}
}

const initializeComment = (appointmentId) => {
	if (!(appointmentId in responseComments)) {
		responseComments[appointmentId] = ''
	}
}

const goToAttendanceApp = () => {
	window.location.href = generateUrl('/apps/attendance/')
}

const openAppointmentDetail = (appointmentId) => {
	window.location.href = generateUrl(`/apps/attendance/appointment/${appointmentId}`)
}

const loadPermissions = async () => {
	try {
		const url = generateUrl('/apps/attendance/api/user/permissions')
		const response = await axios.get(url)
		permissions.canManageAppointments = response.data.canManageAppointments
		permissions.canCheckin = response.data.canCheckin
	} catch (error) {
		console.error('Failed to load permissions:', error)
		permissions.canManageAppointments = false
		permissions.canCheckin = false
	}
}

const showCheckinButton = (item) => {
	if (!permissions.canCheckin) {
		return false
	}

	// Show check-in button 30 minutes before start time
	const now = new Date()
	const startTime = new Date(item.subText)
	const checkinTime = new Date(startTime.getTime() - 30 * 60 * 1000) // 30 minutes before

	return now >= checkinTime
}

const openCheckinView = (appointmentId) => {
	// Navigate to check-in view
	const checkinUrl = generateUrl('/apps/attendance/checkin/{id}', { id: appointmentId })
	window.location.href = checkinUrl
}

// Lifecycle
onMounted(() => {
	loadPermissions()
})
</script>

<style scoped lang="scss">
.appointment-widget-container {
	display: flex;
	flex-direction: column;
	height: 100%;
	max-height: 450px;
}

.appointment-widget {
	overflow-y: auto;
	flex: 1;
	min-height: 0;
}

.widget-footer {
	flex-shrink: 0;
	padding: 12px 12px 20px 12px;
}

.appointment-item {
	padding: 0 14px 12px 14px;
	border-bottom: 1px solid var(--color-border);
	overflow: hidden;
	word-wrap: break-word;

	&:last-child {
		border-bottom: none;
	}
}

.appointment-header {
	display: flex;
	justify-content: space-between;
	align-items: center;

	h3 {
		margin: 0;
		font-size: 18px;
		font-weight: 600;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		flex: 1;
		margin-right: 8px;
		
		&.clickable {
			cursor: pointer;
			
			&:hover {
				text-decoration: underline;
			}
		}
	}

	.appointment-time {
		font-size: 12px;
		color: var(--color-text-maxcontrast);
		flex-shrink: 0;
	}
}

.appointment-description {
	font-size: 12px;
	color: var(--color-text-light);
	margin-bottom: 8px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	
	&.clickable {
		cursor: pointer;
		
		&:hover {
			text-decoration: underline;
			color: var(--color-text-maxcontrast);
		}
	}
}

.checkin-section {
	margin-bottom: 8px;

	.checkin-button {
		font-size: 11px;
		padding: 4px 12px;
		min-height: 28px;
		font-weight: 600;
	}
}

.response-section {
	margin-top: 8px;

	h4 {
		margin: 0 0 8px 0;
		font-size: 12px;
		font-weight: 600;
		color: var(--color-text-maxcontrast);
	}

	.response-buttons {
		display: flex;
		gap: 6px;
		margin-bottom: 8px;
		flex-wrap: wrap;

		:deep(.button-vue) {
			font-size: 11px;
			padding: 4px 12px;
			height: 32px;
		}

		// Apply neutral styling to non-active buttons only when there's a response
		// Exclude the comment toggle button from this styling
		&.has-response {
			:deep(.button-vue:not(.active):not(.comment-toggle)) {
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
		:deep(.button-vue.active) {
			font-weight: bold;
		}

		// Comment toggle button
		:deep(.button-vue.comment-active) {
			background-color: var(--color-primary-element) !important;
			color: white !important;
		}
	}

	.comment-section {
		margin-top: 8px;

		.textarea-container {
			position: relative;
		}

		// Override Nextcloud Vue's placeholder hiding behavior
		:deep(.textarea__input:not(:focus)::placeholder) {
			opacity: 1 !important;
		}

		:deep(.textarea__input) {
			height: calc(var(--default-clickable-area) * 1.4);
		}

		.saving-spinner,
		.saved-indicator {
			position: absolute;
			top: 15px;
			right: 15px;
			pointer-events: none;
			z-index: 10;
		}

		.saving-spinner {
			.spinner {
				width: 16px;
				height: 16px;
				border: 2px solid var(--color-border);
				border-top: 2px solid var(--color-primary);
				border-radius: 50%;
				animation: spin 1s linear infinite;
			}
		}

		.saved-indicator {
			display: flex;
			align-items: center;
			justify-content: center;
			width: 16px;
			height: 16px;
			background-color: green;
			border-radius: 50%;
			animation: fadeIn 0.3s ease-in;

			.check-icon {
				color: white;
			}
		}

		@keyframes spin {
			0% { transform: rotate(0deg); }
			100% { transform: rotate(360deg); }
		}

		@keyframes fadeIn {
			from { opacity: 0; transform: scale(0.5); }
			to { opacity: 1; transform: scale(1); }
		}

		.comment-actions {
			margin-top: 6px;
			display: flex;
			justify-content: flex-end;

			.button-vue {
				font-size: 11px;
				padding: 4px 8px;
				min-height: 28px;
			}
		}
	}
}

.comment-dialog {
	padding: 20px;
	min-width: 400px;

	h2 {
		margin-top: 0;
		margin-bottom: 16px;
	}

	.dialog-buttons {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
		margin-top: 16px;
	}
}
</style>
