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
							type="primary"
							class="checkin-button"
							@click="openCheckinView(item.id)">
							<template #icon>
								<ListStatusIcon />
							</template>
							{{ t('attendance', 'Start check-in') }}
						</NcButton>
					</div>
					<div class="appointment-header">
						<h3>{{ item.mainText }}</h3>
						<span class="appointment-time">{{ formatDate(item.subText) }}</span>
					</div>
					<div v-if="item.description" class="appointment-description">
						{{ item.description }}
					</div>

					<!-- Response Section -->
					<div class="response-section">
						<div class="response-buttons" :class="{ 'has-response': item.userResponse }">
							<NcButton
								:class="{ active: item.userResponse?.response === 'yes' }"
								type="success"
								@click="respond(item.id, 'yes')">
								{{ t('attendance', 'Yes') }}
							</NcButton>
							<NcButton
								:class="{ active: item.userResponse?.response === 'maybe' }"
								type="warning"
								@click="respond(item.id, 'maybe')">
								{{ t('attendance', 'Maybe') }}
							</NcButton>
							<NcButton
								:class="{ active: item.userResponse?.response === 'no' }"
								type="error"
								@click="respond(item.id, 'no')">
								{{ t('attendance', 'No') }}
							</NcButton>
						</div>

						<!-- Comment Section -->
						<div v-if="item.userResponse" class="comment-section">
							<div class="textarea-container">
								<NcTextArea
									resize="vertical"
									:value="responseComments[item.id] || item.userResponse?.comment || undefined"
									:placeholder="t('attendance', 'Comment (optional)')"
									@input="onCommentInput(item.id, $event.target.value)" />
								
								<div v-if="savingComments[item.id]" class="saving-spinner">
									<div class="spinner"></div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</template>
		</NcDashboardWidget>

		<!-- Show All Button -->
		<div class="widget-footer">
			<NcButton type="primary" wide @click="goToAttendanceApp">
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

<script>
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import ListStatusIcon from 'vue-material-design-icons/ListStatus.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import HelpIcon from 'vue-material-design-icons/Help.vue'

import NcDashboardWidget from '@nextcloud/vue/dist/Components/NcDashboardWidget.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcTextArea from '@nextcloud/vue/dist/Components/NcTextArea.js'

import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import axios from '@nextcloud/axios'

export default {
	name: 'AppointmentWidget',

	components: {
		CalendarIcon,
		ListStatusIcon,
		CloseIcon,
		HelpIcon,
		NcDashboardWidget,
		NcEmptyContent,
		NcButton,
		NcModal,
		NcTextArea,
	},

	props: {
		title: {
			type: String,
			required: true,
		},
	},

	data() {
		try {
			const appointments = loadState('attendance', 'dashboard-widget-items')
			return {
				appointments,
				showMoreUrl: generateUrl('/apps/attendance'),
				state: 'ok',
				showDialog: false,
				comment: '',
				selectedAppointmentId: null,
				selectedResponse: null,
				responseComments: {},
				savingComments: {},
				commentTimeouts: {},
				title: t('attendance', 'Attendance'),
				isAdmin: false,
			}
		} catch (error) {
			console.error('Error loading appointments:', error)
			return {
				appointments: [],
				showMoreUrl: generateUrl('/apps/attendance'),
				state: 'error',
				showDialog: false,
				comment: '',
				selectedAppointmentId: null,
				selectedResponse: null,
				responseComments: {},
				savingComments: {},
				commentTimeouts: {},
				title: t('attendance', 'Attendance'),
				isAdmin: false,
			}
		}
	},

	computed: {
		items() {
			const items = this.appointments.map((appointment) => {
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
			return items
		},
	},

	created() {
	},

	beforeMount() {
	},

	mounted() {
		this.checkAdminStatus()
	},

	methods: {
		respond(appointmentId, response) {
			this.submitResponseToServer(appointmentId, response, '')
		},

		showCommentDialog(appointmentId, response) {
			this.selectedAppointmentId = appointmentId
			this.selectedResponse = response
			this.comment = ''
			this.showDialog = true
		},

		closeDialog() {
			this.showDialog = false
			this.selectedAppointmentId = null
			this.selectedResponse = null
			this.comment = ''
		},

		submitResponse() {
			if (this.selectedAppointmentId && this.selectedResponse) {
				this.submitResponseToServer(this.selectedAppointmentId, this.selectedResponse, this.comment)
				this.closeDialog()
			}
		},

		async submitResponseToServer(appointmentId, response, comment) {
			try {
				const url = generateUrl('/apps/attendance/api/appointments/{id}/respond', { id: appointmentId })
				await axios.post(url, {
					response,
					comment,
				})

				// Update local state
				const appointmentIndex = this.appointments.findIndex(a => a.id === appointmentId)
				if (appointmentIndex !== -1) {
					this.appointments[appointmentIndex].userResponse = {
						response,
						comment,
					}
				}

				// Response saved successfully
			} catch (error) {
				// Failed to save response
			}
		},

		getResponseText(response) {
			switch (response) {
			case 'yes': return this.t('attendance', 'Yes')
			case 'no': return this.t('attendance', 'No')
			case 'maybe': return this.t('attendance', 'Maybe')
			default: return response
			}
		},

		formatDate(datetime) {
			try {
				const date = new Date(datetime)
				const options = {dateStyle:'short', timeStyle:'short'}
				return date.toLocaleString(['de-DE','en-EN'], options)
			} catch (error) {
				return datetime
			}
		},

		onCommentInput(appointmentId, value) {
			// Update the comment value immediately for UI responsiveness
			this.$set(this.responseComments, appointmentId, value)

			// Clear existing timeout for this appointment
			if (this.commentTimeouts[appointmentId]) {
				clearTimeout(this.commentTimeouts[appointmentId])
			}

			// Set new timeout to auto-save after 1.5 seconds of no typing
			this.commentTimeouts[appointmentId] = setTimeout(() => {
				this.autoSaveComment(appointmentId, value)
			}, 500)
		},

		async autoSaveComment(appointmentId, comment) {
			const appointment = this.appointments.find(a => a.id === appointmentId)
			if (appointment && appointment.userResponse) {
				// Set saving indicator
				this.$set(this.savingComments, appointmentId, true)

				try {
					await this.submitResponseToServer(appointmentId, appointment.userResponse.response, comment)
				} catch (error) {
					console.error('Auto-save failed:', error)
				} finally {
					// Remove saving indicator after a short delay
					setTimeout(() => {
						this.$set(this.savingComments, appointmentId, false)
					}, 500)
				}
			}
		},

		async updateComment(appointmentId) {
			const comment = this.responseComments[appointmentId] || ''
			const appointment = this.appointments.find(a => a.id === appointmentId)
			if (appointment && appointment.userResponse) {
				await this.submitResponseToServer(appointmentId, appointment.userResponse.response, comment)
			}
		},

		initializeComment(appointmentId) {
			if (!(appointmentId in this.responseComments)) {
				this.$set(this.responseComments, appointmentId, '')
			}
		},

		goToAttendanceApp() {
			window.location.href = generateUrl('/apps/attendance/')
		},

		async checkAdminStatus() {
			try {
				const url = generateUrl('/apps/attendance/api/user/admin-status')
				const response = await axios.get(url)
				this.isAdmin = response.data.isAdmin
			} catch (error) {
				console.error('Failed to check admin status:', error)
				this.isAdmin = false
			}
		},

		showCheckinButton(item) {
			if (!this.isAdmin) {
				return false
			}

			// Show check-in button 30 minutes before start time
			const now = new Date()
			const startTime = new Date(item.subText)
			const checkinTime = new Date(startTime.getTime() - 30 * 60 * 1000) // 30 minutes before

			return now >= checkinTime
		},

		openCheckinView(appointmentId) {
			// Navigate to check-in view
			const checkinUrl = generateUrl('/apps/attendance/checkin/{id}', { id: appointmentId })
			window.location.href = checkinUrl
		},
	},
}
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
	padding: 12px;
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
		font-size: 14px;
		font-weight: 600;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		flex: 1;
		margin-right: 8px;
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
	word-wrap: break-word;
	overflow-wrap: break-word;
	hyphens: auto;
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

		.button-vue {
			font-size: 11px;
			padding: 4px 12px;
			min-height: 28px;

			&.active {
				font-weight: bold;
				opacity: 1;
			}
		}

		// Apply neutral styling to non-active buttons only when there's a response
		&.has-response .button-vue:not(.active) {
			background: var(--color-background-dark) !important;
			color: var(--color-text-lighter) !important;
			border-color: var(--color-border-dark) !important;

			&:hover {
				background: var(--color-background-hover) !important;
				color: var(--color-text) !important;
			}
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

		.saving-spinner {
			position: absolute;
			top: 15px;
			right: 15px;
			pointer-events: none;
			z-index: 10;

			.spinner {
				width: 16px;
				height: 16px;
				border: 2px solid var(--color-border);
				border-top: 2px solid var(--color-primary);
				border-radius: 50%;
				animation: spin 1s linear infinite;
			}
		}

		@keyframes spin {
			0% { transform: rotate(0deg); }
			100% { transform: rotate(360deg); }
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
