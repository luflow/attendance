<template>
	<div class="appointment-widget-container" :data-nc-version="ncVersion" data-test="widget-container">
		<NcDashboardWidget :items="items"
			:loading="state === 'loading'"
			class="appointment-widget"
			data-test="appointment-widget">
			<template #empty-content>
				<NcEmptyContent :title="t('attendance', 'No appointments found')">
					<template #icon>
						<CalendarIcon />
					</template>
				</NcEmptyContent>
			</template>
			<template #default="{ item }">
				<div class="appointment-item" data-test="widget-appointment-item">

					<!-- Check-in Section (for admins when appointment is ready for check-in) -->
					<div v-if="showCheckinButton(item)" class="checkin-section">
						<NcButton
							variant="primary"
							class="checkin-button"
							data-test="button-widget-checkin"
							@click="openCheckinView(item.id)">
							<template #icon>
								<ListStatusIcon />
							</template>
							{{ t('attendance', 'Start check-in') }}
						</NcButton>
					</div>
					<div class="appointment-header">
						<h3 class="clickable" data-test="widget-appointment-title" @click="openAppointmentDetail(item.id)">{{ item.mainText }}</h3>
						<span class="appointment-time">{{ formatDate(item.subText) }}</span>
					</div>
					<div v-if="item.description" class="appointment-description clickable" @click="openAppointmentDetail(item.id)">
						{{ renderStrippedDescription(item.description) }}
					</div>

					<!-- Response Section -->
					<div class="response-section">
						<div class="response-buttons" :class="{ 'has-response': item.userResponse }">
							<NcButton
								:class="{ active: item.userResponse?.response === 'yes' }"
								variant="success"
								:text="t('attendance', 'Yes')"
								data-test="widget-response-yes"
								@click="respond(item.id, 'yes')" />
							<NcButton
								:class="{ active: item.userResponse?.response === 'maybe' }"
								variant="warning"
								:text="t('attendance', 'Maybe')"
								data-test="widget-response-maybe"
								@click="respond(item.id, 'maybe')" />
							<NcButton
								:class="{ active: item.userResponse?.response === 'no' }"
								variant="error"
								:text="t('attendance', 'No')"
								data-test="widget-response-no"
								@click="respond(item.id, 'no')" />
							<!-- Comment Toggle Button -->
							<NcButton
								:class="{ 'comment-active': commentExpanded[item.id], 'comment-toggle': true }"
								type="tertiary"
								data-test="button-widget-toggle-comment"
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
									v-model="responseComments[item.id]"
									:placeholder="t('attendance', 'Comment (optional)')"
									data-test="widget-response-comment"
									@input="onCommentInput(item.id)" />
								
								<div v-if="savingComments[item.id]" class="saving-spinner">
									<div class="spinner"></div>
								</div>
								<div v-else-if="savedComments[item.id]" class="saved-indicator">
									<CheckIcon :size="16" class="check-icon" />
								</div>
								<div v-else-if="errorComments[item.id]" class="error-indicator">
									<CloseCircle :size="16" class="error-icon" />
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
				data-test="button-show-all"
				@click="goToAttendanceApp">
				{{ t('attendance', 'Show all appointments') }}
			</NcButton>
		</div>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, nextTick } from 'vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import ListStatusIcon from 'vue-material-design-icons/ListStatus.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CommentIcon from 'vue-material-design-icons/Comment.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'

import { NcDashboardWidget, NcEmptyContent, NcButton, NcModal, NcTextArea } from '@nextcloud/vue'

import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { usePermissions } from '../composables/usePermissions.js'

defineProps({
	title: {
		type: String,
		required: true,
	},
})

let initialAppointments = []
let initialState = 'ok'
let ncVersionState = 31

try {
	initialAppointments = loadState('attendance', 'dashboard-widget-items')
} catch (error) {
	console.error('Error loading appointments:', error)
	initialState = 'error'
}

try {
	ncVersionState = loadState('attendance', 'nc-version')
} catch (error) {
	console.debug('nc-version not available, defaulting to 31')
}

const appointments = ref(initialAppointments)
const state = ref(initialState)
const ncVersion = ref(ncVersionState)
const responseComments = reactive({})
const savingComments = reactive({})
const savedComments = reactive({})
const errorComments = reactive({})
const commentTimeouts = reactive({})
const commentExpanded = reactive({})

const { permissions, loadPermissions } = usePermissions()

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

const respond = async (appointmentId, response) => {
	try {
		await submitResponseToServer(appointmentId, response, '')
	} catch (error) {
		showError(t('attendance', 'Failed to save response'))
	}
}


const submitResponseToServer = async (appointmentId, response, commentText) => {
	try {
		const url = generateUrl('/apps/attendance/api/appointments/{id}/respond', { id: appointmentId })
		const axiosResponse = await axios.post(url, {
			response,
			comment: commentText,
		})

		if (axiosResponse.status < 200 || axiosResponse.status >= 300) {
			throw new Error(`API returned status ${axiosResponse.status}`)
		}

		const appointmentIndex = appointments.value.findIndex(a => a.id === appointmentId)
		if (appointmentIndex !== -1) {
			appointments.value[appointmentIndex].userResponse = {
				response,
				comment: commentText,
			}
		}
	} catch (error) {
		console.error('Failed to save response:', error)
		throw error
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

const renderStrippedDescription = (description) => {
	if (!description) return ''
	let text = description
		.replace(/\*\*([^*]+)\*\*/g, '$1')  // Remove bold: **text** -> text
		.replace(/\*([^*]+)\*/g, '$1')      // Remove italic: *text* -> text
		.replace(/\n/g, ' ')                 // Remove newlines
		.trim()
	return text
}

const toggleComment = (appointmentId) => {
	const isExpanding = !commentExpanded[appointmentId]
	commentExpanded[appointmentId] = isExpanding
	
	if (isExpanding && !(appointmentId in responseComments)) {
		const appointment = appointments.value.find(a => a.id === appointmentId)
		responseComments[appointmentId] = appointment?.userResponse?.comment || ''
	}
}

const onCommentInput = (appointmentId) => {
	if (commentTimeouts[appointmentId]) {
		clearTimeout(commentTimeouts[appointmentId])
	}

	commentTimeouts[appointmentId] = setTimeout(async () => {
		await nextTick()
		const value = responseComments[appointmentId]
		autoSaveComment(appointmentId, value)
	}, 500)
}

const autoSaveComment = async (appointmentId, commentText) => {
	const appointment = appointments.value.find(a => a.id === appointmentId)
	if (appointment && appointment.userResponse) {
		savingComments[appointmentId] = true
		savedComments[appointmentId] = false
		errorComments[appointmentId] = false

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
			errorComments[appointmentId] = true
			showError(t('attendance', 'Comment could not be saved'))
			
			setTimeout(() => {
				errorComments[appointmentId] = false
			}, 3000)
		}
	}
}

const goToAttendanceApp = () => {
	window.location.href = generateUrl('/apps/attendance/')
}

const openAppointmentDetail = (appointmentId) => {
	window.location.href = generateUrl(`/apps/attendance/appointment/${appointmentId}`)
}

const showCheckinButton = (item) => {
	if (!permissions.canCheckin) {
		return false
	}

	const now = new Date()
	const startTime = new Date(item.subText)
	const checkinTime = new Date(startTime.getTime() - 30 * 60 * 1000) // 30 minutes before

	return now >= checkinTime
}

const openCheckinView = (appointmentId) => {
	const checkinUrl = generateUrl('/apps/attendance/checkin/{id}', { id: appointmentId })
	window.location.href = checkinUrl
}

onMounted(async () => {
	await loadPermissions()
})
</script>

<style scoped lang="scss">
@use '../styles/shared.scss';

@media (prefers-color-scheme: dark) {
	.appointment-widget-container[data-nc-version="31"] :deep(.button-vue--warning) {
		color: black !important;
	}
}

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

		:deep(.button-vue.active) {
			font-weight: bold;
		}

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

		:deep(.textarea__input:not(:focus)::placeholder) {
			opacity: 1 !important;
		}

		:deep(.textarea__input) {
			height: calc(var(--default-clickable-area) * 1.4);
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
