<template>
	<NcAppContent>
		<div id="attendance">
			<div class="attendance-header">
				<div class="header-buttons">
					<NcButton v-if="isAdmin" type="primary" @click="showCreateForm = true">
						{{ t('attendance', 'Create Appointment') }}
					</NcButton>
					<NcButton v-if="isAdmin" :type="showPastAppointments ? 'secondary' : 'tertiary'"
						@click="togglePastAppointments">
						{{ showPastAppointments ? t('attendance', 'Hide Past Appointments') : t('attendance', 'Show Past Appointments') }}
					</NcButton>
				</div>
			</div>

			<!-- Create Appointment Modal -->
			<NcModal v-if="showCreateForm" @close="showCreateForm = false">
				<div class="modal-content">
					<h2>{{ t('attendance', 'Create Appointment') }}</h2>
					<form @submit.prevent="createAppointment">
						<NcTextField v-model="newAppointment.name" :label="t('attendance', 'Appointment Name')"
							required />
						<NcTextArea v-model="newAppointment.description" :label="t('attendance', 'Description')" />
						<NcDateTimePickerNative v-model="newAppointment.startDatetime"
							:label="t('attendance', 'Start Date & Time')" type="datetime-local" required />
						<NcDateTimePickerNative v-model="newAppointment.endDatetime"
							:label="t('attendance', 'End Date & Time')" type="datetime-local" required />
						<div class="form-actions">
							<NcButton type="secondary" @click="showCreateForm = false">
								{{ t('attendance', 'Cancel') }}
							</NcButton>
							<NcButton type="primary" native-type="submit">
								{{ t('attendance', 'Save') }}
							</NcButton>
						</div>
					</form>
				</div>
			</NcModal>

			<!-- Edit Appointment Modal -->
			<NcModal v-if="showEditForm" @close="showEditForm = false">
				<div class="modal-content">
					<h2>{{ t('attendance', 'Edit Appointment') }}</h2>
					<form @submit.prevent="updateAppointment">
						<NcTextField v-model="editingAppointment.name" :label="t('attendance', 'Appointment Name')"
							required />
						<NcTextArea v-model="editingAppointment.description" :label="t('attendance', 'Description')" />
						<div class="form-field">
							<label>{{ t('attendance', 'Start Date & Time') }}</label>
							<input v-model="editingAppointment.startDatetime" type="datetime-local" required>
						</div>
						<div class="form-field">
							<label>{{ t('attendance', 'End Date & Time') }}</label>
							<input v-model="editingAppointment.endDatetime" type="datetime-local" required>
						</div>
						<div class="form-actions">
							<NcButton type="secondary" @click="showEditForm = false">
								{{ t('attendance', 'Cancel') }}
							</NcButton>
							<NcButton type="primary" native-type="submit">
								{{ t('attendance', 'Save') }}
							</NcButton>
						</div>
					</form>
				</div>
			</NcModal>

			<!-- Appointments List -->
			<div class="appointments-list">
				<div v-if="loading" class="loading">
					{{ t('attendance', 'Loading...') }}
				</div>
				<div v-else-if="appointments.length === 0" class="empty-state">
					{{ t('attendance', 'No appointments found') }}
				</div>
				<div v-else>
					<div v-for="appointment in appointments" :key="appointment.id" class="appointment-card">
						<div class="appointment-header">
							<h3>{{ appointment.name }}</h3>
							<div class="appointment-actions">
								<NcButton v-if="canEdit(appointment)" type="tertiary"
									@click="editAppointment(appointment)">
									{{ t('attendance', 'Edit Appointment') }}
								</NcButton>
								<NcButton v-if="canEdit(appointment)" type="tertiary"
									@click="deleteAppointment(appointment.id)">
									{{ t('attendance', 'Delete Appointment') }}
								</NcButton>
							</div>
						</div>
						<p v-if="appointment.description" class="appointment-description">
							{{ appointment.description }}
						</p>
						<div class="appointment-time">
							<strong>{{ t('attendance', 'Start Date & Time') }}:</strong> {{
								formatDateTime(appointment.startDatetime) }}<br>
							<strong>{{ t('attendance', 'End Date & Time') }}:</strong> {{
								formatDateTime(appointment.endDatetime) }}
						</div>

						<!-- Response Section -->
						<div class="response-section">
							<h4>{{ t('attendance', 'Your Response') }}</h4>
							<div class="response-buttons">
								<NcButton :class="{ active: getUserResponse(appointment) === 'yes' }" type="success"
									@click="submitResponse(appointment.id, 'yes')">
									{{ t('attendance', 'Yes') }}
								</NcButton>
								<NcButton :class="{ active: getUserResponse(appointment) === 'maybe' }" type="warning"
									@click="submitResponse(appointment.id, 'maybe')">
									{{ t('attendance', 'Maybe') }}
								</NcButton>
								<NcButton :class="{ active: getUserResponse(appointment) === 'no' }" type="error"
									@click="submitResponse(appointment.id, 'no')">
									{{ t('attendance', 'No') }}
								</NcButton>
							</div>

							<!-- Comment Section -->
							<div v-if="getUserResponse(appointment)" class="comment-section">
								<NcTextArea v-model="responseComments[appointment.id]"
									:label="t('attendance', 'Comment (optional)')" />
								<div class="comment-actions">
									<NcButton type="primary" @click="updateComment(appointment.id)">
										{{ t('attendance', 'Save') }}
									</NcButton>
								</div>
							</div>
						</div>

						<!-- Response Summary -->
						<div v-if="appointment.responseSummary" class="response-summary">
							<h4>{{ t('attendance', 'Response Summary') }}</h4>
							<div class="summary-stats">
								<span class="stat yes">{{ t('attendance', 'Attending') }}: {{
								appointment.responseSummary.yes }}</span>
								<span class="stat maybe">{{ t('attendance', 'Maybe Attending') }}: {{
								appointment.responseSummary.maybe }}</span>
								<span class="stat no">{{ t('attendance', 'Not Attending') }}: {{
								appointment.responseSummary.no }}</span>
								<span class="stat no-response">{{ t('attendance', 'No Response') }}: {{
								appointment.responseSummary.no_response }}</span>
							</div>

							<!-- Group-based Summary -->
							<div v-if="appointment.responseSummary.by_group && Object.keys(appointment.responseSummary.by_group).length > 0"
								class="group-summary">
								<h5>{{ t('attendance', 'By Group') }}</h5>
								<div v-for="(groupStats, groupId) in appointment.responseSummary.by_group"
									:key="groupId" class="group-container">
									<div class="group-stats clickable"
										@click="toggleGroupExpansion(appointment.id, groupId)">
										<div class="group-name">
											<span class="expand-icon"
												:class="{ expanded: isGroupExpanded(appointment.id, groupId) }">▶</span>
											{{ groupId }}
										</div>
										<div class="group-counts">
											<span class="stat yes">{{ groupStats.yes }}</span>
											<span class="stat maybe">{{ groupStats.maybe }}</span>
											<span class="stat no">{{ groupStats.no }}</span>
											<span class="stat no-response">{{ groupStats.no_response }}</span>
										</div>
									</div>

									<!-- Expanded Group Details -->
									<div v-if="isGroupExpanded(appointment.id, groupId)" class="group-details">
										<!-- Show responses if any exist -->
										<div v-if="getGroupResponses(appointment, groupId).length > 0" class="group-responses">
											<div v-for="response in getGroupResponses(appointment, groupId)"
												:key="response.id" class="response-item">
												<div class="response-header">
													<strong>{{ response.userName }}</strong>
													<span class="response-badge" :class="response.response">{{
														getResponseText(response.response) }}</span>
												</div>
												<div v-if="response.comment && response.comment.trim()"
													class="response-comment">
													{{ response.comment }}
												</div>
											</div>
										</div>

										<!-- Always show non-responding users if any exist -->
										<div v-if="appointment.responseSummary.by_group[groupId].non_responding_users && appointment.responseSummary.by_group[groupId].non_responding_users.length > 0"
											class="non-responding-users">
											<div class="non-responding-header">
												{{ t('attendance', 'No response yet:') }}
											</div>
											<div class="non-responding-list">
												{{ appointment.responseSummary.by_group[groupId].non_responding_users.join(', ') }}
											</div>
										</div>

										<!-- Only show generic message if no responses AND no non-responding users -->
										<div v-if="getGroupResponses(appointment, groupId).length === 0 && (!appointment.responseSummary.by_group[groupId].non_responding_users || appointment.responseSummary.by_group[groupId].non_responding_users.length === 0)"
											class="no-responses">
											{{ t('attendance', 'No responses from this group yet') }}
										</div>
									</div>
								</div>
							</div>
						</div>

						<!-- Non-responding users section -->
						<div v-if="isAdmin && appointment.responseSummary && appointment.responseSummary.non_responding_users && appointment.responseSummary.non_responding_users.length > 0"
							class="non-responding-users-section">
							<h4>{{ t('attendance', 'Non-responding users') }}</h4>
							<div class="non-responding-users-list">
								{{ appointment.responseSummary.non_responding_users.join(', ') }}
							</div>
						</div>

						<!-- Admin All Comments View (only shown if no groups exist) -->
						<div v-if="isAdmin && appointment.detailedResponses && (!appointment.responseSummary.by_group || Object.keys(appointment.responseSummary.by_group).length === 0)"
							class="admin-comments">
							<h4>{{ t('attendance', 'All Comments') }}</h4>
							<div v-if="appointment.detailedResponses.length === 0" class="no-comments">
								{{ t('attendance', 'No responses yet') }}
							</div>
							<div v-else class="comments-list">
								<div v-for="response in appointment.detailedResponses"
									v-if="response.comment && response.comment.trim()" :key="response.id"
									class="comment-item">
									<div class="comment-header">
										<strong>{{ response.userName }}</strong>
										<span class="response-badge" :class="response.response">{{
											getResponseText(response.response) }}</span>
									</div>
									<div class="comment-text">
										{{ response.comment }}
									</div>
									<div class="comment-date">
										{{ formatDateTime(response.respondedAt) }}
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import NcAppContent from '@nextcloud/vue/dist/Components/NcAppContent.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcTextArea from '@nextcloud/vue/dist/Components/NcTextArea.js'
import NcDateTimePickerNative from '@nextcloud/vue/dist/Components/NcDateTimePickerNative.js'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'

export default {
	name: 'App',
	components: {
		NcAppContent,
		NcButton,
		NcModal,
		NcTextField,
		NcTextArea,
		NcDateTimePickerNative,
	},
	data() {
		return {
			appointments: [],
			loading: true,
			showCreateForm: false,
			showEditForm: false,
			newAppointment: {
				name: '',
				description: '',
				startDatetime: '',
				endDatetime: '',
			},
			editingAppointment: {
				id: null,
				name: '',
				description: '',
				startDatetime: '',
				endDatetime: '',
			},
			responseComments: {},
			currentUser: getCurrentUser(),
			showPastAppointments: false,
			expandedGroups: {}, // Track which groups are expanded for each appointment
		}
	},
	computed: {
		isAdmin() {
			return this.currentUser?.isAdmin || false
		},
	},
	async mounted() {
		await this.loadAppointments()
	},
	methods: {
		async loadAppointments() {
			try {
				this.loading = true
				const params = this.showPastAppointments ? '?showPast=true' : ''
				const response = await axios.get(generateUrl('/apps/attendance/api/appointments') + params)
				this.appointments = response.data

				// Initialize response comments
				this.appointments.forEach(appointment => {
					if (appointment.userResponse) {
						this.$set(this.responseComments, appointment.id, appointment.userResponse.comment || '')
					}
				})

				// Load detailed responses for admins
				if (this.isAdmin) {
					await this.loadDetailedResponses()
				}
			} catch (error) {
				console.error('Failed to load appointments:', error)
			} finally {
				this.loading = false
			}
		},
		async loadDetailedResponses() {
			for (const appointment of this.appointments) {
				try {
					const response = await axios.get(generateUrl(`/apps/attendance/api/appointments/${appointment.id}/responses`))
					this.$set(appointment, 'detailedResponses', response.data)
				} catch (error) {
					console.error(`Failed to load detailed responses for appointment ${appointment.id}:`, error)
				}
			}
		},
		async createAppointment() {
			try {
				await axios.post(generateUrl('/apps/attendance/api/appointments'), this.newAppointment)
				this.showCreateForm = false
				this.newAppointment = {
					name: '',
					description: '',
					startDatetime: '',
					endDatetime: '',
				}
				await this.loadAppointments()
			} catch (error) {
				console.error('Failed to create appointment:', error)
			}
		},
		async submitResponse(appointmentId, response) {
			try {
				const comment = this.responseComments[appointmentId] || ''
				await axios.post(generateUrl(`/apps/attendance/api/appointments/${appointmentId}/respond`), {
					response,
					comment,
				})
				await this.loadAppointments()
			} catch (error) {
				console.error('Failed to submit response:', error)
			}
		},
		async updateComment(appointmentId) {
			const appointment = this.appointments.find(a => a.id === appointmentId)
			if (appointment && appointment.userResponse) {
				await this.submitResponse(appointmentId, appointment.userResponse.response)
			}
		},
		async deleteAppointment(appointmentId) {
			if (confirm(this.t('attendance', 'Are you sure you want to delete this appointment?'))) {
				try {
					await axios.delete(generateUrl(`/apps/attendance/api/appointments/${appointmentId}`))
					await this.loadAppointments()
				} catch (error) {
					console.error('Failed to delete appointment:', error)
				}
			}
		},
		getUserResponse(appointment) {
			return appointment.userResponse?.response || null
		},
		canEdit(appointment) {
			return this.isAdmin || appointment.createdBy === this.currentUser?.uid
		},
		formatDateTime(dateTime) {
			return new Date(dateTime).toLocaleString()
		},
		getResponseText(response) {
			const responses = {
				yes: this.t('attendance', 'Yes'),
				maybe: this.t('attendance', 'Maybe'),
				no: this.t('attendance', 'No'),
			}
			return responses[response] || response
		},
		editAppointment(appointment) {
			const formattedStart = this.formatDateTimeForInput(appointment.startDatetime)
			const formattedEnd = this.formatDateTimeForInput(appointment.endDatetime)

			this.editingAppointment = {
				id: appointment.id,
				name: appointment.name,
				description: appointment.description || '',
				startDatetime: formattedStart,
				endDatetime: formattedEnd,
			}

			this.showEditForm = true
		},
		async updateAppointment() {
			try {
				await axios.put(generateUrl(`/apps/attendance/api/appointments/${this.editingAppointment.id}`), {
					name: this.editingAppointment.name,
					description: this.editingAppointment.description,
					startDatetime: this.editingAppointment.startDatetime,
					endDatetime: this.editingAppointment.endDatetime,
				})
				this.showEditForm = false
				this.editingAppointment = {
					id: null,
					name: '',
					description: '',
					startDatetime: '',
					endDatetime: '',
				}
				await this.loadAppointments()
			} catch (error) {
				console.error('Failed to update appointment:', error)
			}
		},
		formatDateTimeForInput(dateTime) {
			// Convert datetime to format required by datetime-local input
			if (!dateTime) return ''

			const date = new Date(dateTime)

			// Check if date is valid
			if (isNaN(date.getTime())) {
				console.warn('Invalid date:', dateTime)
				return ''
			}

			// Format as YYYY-MM-DDTHH:MM (required by datetime-local input)
			const year = date.getFullYear()
			const month = String(date.getMonth() + 1).padStart(2, '0')
			const day = String(date.getDate()).padStart(2, '0')
			const hours = String(date.getHours()).padStart(2, '0')
			const minutes = String(date.getMinutes()).padStart(2, '0')

			return `${year}-${month}-${day}T${hours}:${minutes}`
		},
		async togglePastAppointments() {
			this.showPastAppointments = !this.showPastAppointments
			await this.loadAppointments()
		},
		toggleGroupExpansion(appointmentId, groupId) {
			const key = `${appointmentId}-${groupId}`
			this.$set(this.expandedGroups, key, !this.expandedGroups[key])
		},
		isGroupExpanded(appointmentId, groupId) {
			const key = `${appointmentId}-${groupId}`
			return !!this.expandedGroups[key]
		},
		getGroupResponses(appointment, groupId) {
			if (!appointment.responseSummary?.by_group?.[groupId]?.responses) return []

			// Return pre-filtered responses from backend
			return appointment.responseSummary.by_group[groupId].responses
		},
	},
}
</script>

<style scoped lang="scss">
#attendance {
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
	min-width: 400px;

	h2 {
		margin-top: 0;
	}

	.form-field {
		margin-bottom: 15px;

		label {
			display: block;
			margin-bottom: 5px;
			font-weight: 500;
			color: var(--color-text);
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

.response-section {
	border-top: 1px solid var(--color-border);
	padding-top: 15px;
	margin-top: 15px;

	h4 {
		margin: 0 0 10px 0;
	}

	.response-buttons {
		display: flex;
		gap: 10px;
		margin-bottom: 15px;

		button {
			&:not(.active) {
				background: var(--color-background-dark) !important;
				color: var(--color-text-lighter) !important;
				border-color: var(--color-border-dark) !important;

				&:hover {
					background: var(--color-background-hover) !important;
					color: var(--color-text) !important;
				}
			}

			&.active {
				font-weight: bold;
				opacity: 1;
			}
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
	display: flex;
	gap: 20px;

	.stat {
		padding: 5px 10px;
		border-radius: 4px;
		font-size: 14px;
		color: #ffffff;

		&.yes {
			background: var(--color-success);
		}

		&.maybe {
			background: var(--color-warning);
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
			min-width: 120px;
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
				min-width: 20px;
				text-align: center;

				&.yes {
					background: var(--color-success);
				}

				&.maybe {
					background: var(--color-warning);
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
