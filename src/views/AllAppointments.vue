<template>
	<div id="attendance">
		<div class="attendance-header">
			<div class="header-buttons">
				<NcButton v-if="canManageAppointments" type="primary" @click="showCreateForm = true">
					{{ t('attendance', 'Create Appointment') }}
				</NcButton>
				<NcActions v-if="canManageAppointments" :force-menu="true">
					<NcActionButton @click="togglePastAppointments" :close-after-click="true">
						<template #icon>
							<History :size="20" />
						</template>
						{{ showPastAppointments ? t('attendance', 'Hide Past Appointments') : t('attendance', 'Show Past Appointments') }}
					</NcActionButton>
				</NcActions>
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
						:label="t('attendance', 'Start')" type="datetime-local" required 
						@blur="onStartDatetimeBlur" />
					<NcDateTimePickerNative ref="endDatetimePicker" v-model="newAppointment.endDatetime"
						:label="t('attendance', 'End')" type="datetime-local" required 
						:key="newAppointment.endDatetime" />
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
				<h2>{{ t('attendance', 'Edit') }}</h2>
				<form @submit.prevent="updateAppointment">
					<NcTextField v-model="editingAppointment.name" :label="t('attendance', 'Appointment Name')"
						required />
					<NcTextArea v-model="editingAppointment.description" :label="t('attendance', 'Description')" />
					<div class="form-field">
						<label>{{ t('attendance', 'Start') }}</label>
						<input v-model="editingAppointment.startDatetime" type="datetime-local" required>
					</div>
					<div class="form-field">
						<label>{{ t('attendance', 'End') }}</label>
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
							<NcActions v-if="canManageAppointments || canCheckin" :force-menu="true">
								<NcActionButton v-if="canCheckin" @click="startCheckin(appointment.id)" :close-after-click="true">
									<template #icon>
										<ListStatusIcon :size="20" />
									</template>
									{{ t('attendance', 'Start check-in') }}
								</NcActionButton>
								<NcActionButton v-if="canManageAppointments" @click="editAppointment(appointment)" :close-after-click="true">
									<template #icon>
										<Pencil :size="20" />
									</template>
									{{ t('attendance', 'Edit') }}
								</NcActionButton>
								<NcActionButton v-if="canManageAppointments" @click="deleteAppointment(appointment.id)" :close-after-click="true">
									<template #icon>
										<Delete :size="20" />
									</template>
									{{ t('attendance', 'Delete') }}
								</NcActionButton>
							</NcActions>
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
							<span class="stat yes">{{ t('attendance', 'Yes') }}: {{
							appointment.responseSummary.yes }}</span>
							<span class="stat maybe">{{ t('attendance', 'Maybe') }}: {{
							appointment.responseSummary.maybe }}</span>
							<span class="stat no">{{ t('attendance', 'No') }}: {{
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
												<span v-if="response.isCheckedIn" class="checkin-badge">CheckIn</span>
												<span v-if="response.isCheckedIn" class="response-badge" :class="response.checkinState">
													{{ getResponseText(response.checkinState) }}
												</span>
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
											{{ appointment.responseSummary.by_group[groupId].non_responding_users.map(u => u.displayName).join(', ') }}
										</div>
									</div>
								</div>
							</div>

							<!-- Others Section -->
							<div v-if="appointment.responseSummary.others && hasOthersResponses(appointment)"
								class="group-container">
								<div class="group-stats clickable"
									@click="toggleGroupExpansion(appointment.id, 'others')">
									<div class="group-name">
										<span class="expand-icon"
											:class="{ expanded: isGroupExpanded(appointment.id, 'others') }">▶</span>
										{{ t('attendance', 'Others') }}
									</div>
									<div class="group-counts">
										<span class="stat yes">{{ appointment.responseSummary.others.yes }}</span>
										<span class="stat maybe">{{ appointment.responseSummary.others.maybe }}</span>
										<span class="stat no">{{ appointment.responseSummary.others.no }}</span>
									</div>
								</div>

								<!-- Expanded Others Details -->
								<div v-if="isGroupExpanded(appointment.id, 'others')" class="group-details">
									<div v-if="appointment.responseSummary.others.responses.length > 0" class="group-responses">
										<div v-for="response in appointment.responseSummary.others.responses"
											:key="response.id" class="response-item">
											<div class="response-header">
												<strong>{{ response.userName }}</strong>
												<span class="response-badge" :class="response.response">{{
													getResponseText(response.response) }}</span>
												<span v-if="response.isCheckedIn" class="checkin-badge">CheckIn</span>
												<span v-if="response.isCheckedIn" class="response-badge" :class="response.checkinState">
													{{ getResponseText(response.checkinState) }}
												</span>
											</div>
											<div v-if="response.comment && response.comment.trim()"
												class="response-comment">
												{{ response.comment }}
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Non-responding users section -->
					<div v-if="appointment.responseSummary && appointment.responseSummary.non_responding_users && appointment.responseSummary.non_responding_users.length > 0"
						class="non-responding-users-section">
						<h4>{{ t('attendance', 'Non-responding users') }}</h4>
						<div class="non-responding-users-list">
							{{ appointment.responseSummary.non_responding_users.map(u => u.displayName).join(', ') }}
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcTextArea from '@nextcloud/vue/dist/Components/NcTextArea.js'
import NcDateTimePickerNative from '@nextcloud/vue/dist/Components/NcDateTimePickerNative.js'
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js'
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import { fromZonedTime } from 'date-fns-tz'
import ListStatusIcon from 'vue-material-design-icons/ListStatus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import History from 'vue-material-design-icons/History.vue'

export default {
	name: 'AllAppointments',
	components: {
		NcButton,
		NcModal,
		NcTextField,
		NcTextArea,
		NcDateTimePickerNative,
		NcActions,
		NcActionButton,
		ListStatusIcon,
		Pencil,
		Delete,
		History,
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
			expandedGroups: {},
			permissions: {
				canManageAppointments: false,
				canCheckin: false,
			},
		}
	},
	computed: {
		canManageAppointments() {
			return this.permissions.canManageAppointments
		},
		canCheckin() {
			return this.permissions.canCheckin
		},
	},
	async mounted() {
		await this.loadPermissions()
		await this.loadAppointments()
	},
	methods: {
		async loadAppointments(skipLoadingSpinner = false) {
			try {
				// Don't show loading spinner when refreshing data
				if (!skipLoadingSpinner) {
					this.loading = true
				}
				const params = this.showPastAppointments ? '?showPast=true' : ''
				const response = await axios.get(generateUrl('/apps/attendance/api/appointments') + params)
				this.appointments = response.data

				// Initialize response comments
				this.appointments.forEach(appointment => {
					if (appointment.userResponse) {
						this.$set(this.responseComments, appointment.id, appointment.userResponse.comment || '')
					}
				})

				// Load detailed responses for users who can manage appointments
				if (this.canManageAppointments) {
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
				await this.loadAppointments(true)
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
				await this.loadAppointments(true)
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
					await this.loadAppointments(true)
				} catch (error) {
					console.error('Failed to delete appointment:', error)
				}
			}
		},
		getUserResponse(appointment) {
			return appointment.userResponse?.response || null
		},
		async loadPermissions() {
			try {
				const response = await axios.get(generateUrl('/apps/attendance/api/user/permissions'))
				this.permissions = response.data
			} catch (error) {
				console.error('Failed to load permissions:', error)
			}
		},
		formatDateTime(dateTime) {
			const options = {dateStyle:'short', timeStyle:'short'}
			return new Date(dateTime).toLocaleString(['de-DE','en-EN'], options)
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
				// Convert datetime to Europe/Berlin timezone using date-fns-tz
				const startDatetimeWithTz = fromZonedTime(this.editingAppointment.startDatetime, 'Europe/Berlin');
				const endDatetimeWithTz = fromZonedTime(this.editingAppointment.endDatetime, 'Europe/Berlin');

				await axios.put(generateUrl(`/apps/attendance/api/appointments/${this.editingAppointment.id}`), {
					name: this.editingAppointment.name,
					description: this.editingAppointment.description,
					startDatetime: startDatetimeWithTz,
					endDatetime: endDatetimeWithTz,
				})
				this.showEditForm = false
				this.editingAppointment = {
					id: null,
					name: '',
					description: '',
					startDatetime: '',
					endDatetime: '',
				}
				await this.loadAppointments(true)
			} catch (error) {
				console.error('Failed to update appointment:', error)
			}
		},
		formatDateTimeForInput(dateTime) {
			// Convert datetime to format "yyyy-MM-ddThh:mm" required by datetime-local input
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
			await this.loadAppointments(true)
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
		hasOthersResponses(appointment) {
			return appointment.responseSummary.others && appointment.responseSummary.others.responses.length > 0
		},
		onStartDatetimeBlur() {
			// Auto-set endDatetime if it's empty and startDatetime is set
			if (this.newAppointment.startDatetime && !this.newAppointment.endDatetime) {
				const startDate = new Date(this.newAppointment.startDatetime)
				const endDate = new Date(startDate.getTime() + 2.5 * 60 * 60 * 1000) // Add 2,5 hours
				const formattedEndDate = this.formatDateTimeForInput(endDate.toISOString())
				
				// Set input value and let the input event update Vue state
				// Could not do it via state change, so we have to do it via DOM
				if (this.$refs.endDatetimePicker && this.$refs.endDatetimePicker.$el) {
					const input = this.$refs.endDatetimePicker.$el.querySelector('input[type="datetime-local"]')
					if (input) {
						input.value = formattedEndDate
						input.dispatchEvent(new Event('input', { bubbles: true }))
					}
				}
			}
		},
		startCheckin(appointmentId) {
			// Navigate to check-in page for this appointment
			window.location.href = generateUrl(`/apps/attendance/checkin/${appointmentId}`)
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
			
			body[data-theme-dark] &.active.button-vue--vue-warning {
				color: black !important;
			}
			
			@media (prefers-color-scheme: dark) {
				body[data-theme-default] &.active.button-vue--vue-warning {
					color: black !important;
				}
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
			margin-bottom: 5px;
			gap: 10px;

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
