<template>
	<div class="checkin-view">
		<div class="checkin-header">
			<NcButton type="tertiary" @click="goBack">
				<template #icon>
					<ArrowLeftIcon />
				</template>
				{{ t('attendance', 'Back') }}
			</NcButton>
			<h1>{{ t('attendance', 'Check-in') }}</h1>
		</div>

		<div v-if="loading" class="loading-container">
			<NcLoadingIcon />
			<p>{{ t('attendance', 'Loading appointment data...') }}</p>
		</div>

		<div v-else-if="error" class="error-container">
			<NcEmptyContent :title="t('attendance', 'Error loading appointment')">
				<template #icon>
					<AlertIcon />
				</template>
				<template #description>
					{{ error }}
				</template>
			</NcEmptyContent>
		</div>

		<div v-if="!loading && !error" class="checkin-content">
			<!-- Appointment info -->
			<div class="appointment-info">
				<h2>{{ appointment.name }}</h2>
				<p class="appointment-details">
					<strong>{{ t('attendance', 'Start') }}:</strong> {{ formatDateTime(appointment.startDatetime) }}<br>
					<strong>{{ t('attendance', 'End') }}:</strong> {{ formatDateTime(appointment.endDatetime) }}
				</p>
				<p v-if="appointment.description" class="appointment-description">{{ appointment.description }}</p>
			</div>

			<!-- Search and filter controls -->
			<div class="controls-section">
				<div class="search-container">
					<NcTextField
						:value.sync="searchQuery"
						:label="t('attendance', 'Search by name...')"
						@input="onSearchInput">
						<MagnifyIcon :size="16" />
					</NcTextField>
				</div>
				<div class="group-filter">
					<NcSelect
						v-model="selectedGroup"
						:options="groupOptions"
						:placeholder="t('attendance', 'Filter by group')"
						:clearable="true"
						@input="onGroupFilterChange">
					</NcSelect>
				</div>
			</div>

			<div class="user-lists">
				<!-- Single unified user list -->
				<div class="user-section">
					<div class="section-header">
						<div class="section-actions">
							<NcButton
								size="small"
								type="success"
								:disabled="bulkProcessing"
								@click="bulkCheckinAll('yes')">
								{{ t('attendance', 'All Present') }}
							</NcButton>
							<NcButton
								size="small"
								type="error"
								:disabled="bulkProcessing"
								@click="bulkCheckinAll('no')">
								{{ t('attendance', 'All Absent') }}
							</NcButton>
						</div>
					</div>
					<div class="user-list">
						<div v-for="user in filteredAllUsers" :key="user.userId" class="user-item">
							<!-- Normal view when not in comment mode -->
							<template v-if="!showCommentInput[user.userId]">
								<div class="user-info">
									<NcAvatar :user="user.userId" :size="32" :show-user-status="false" />
									<div class="user-details">
										<div class="user-name">{{ user.displayName }}</div>
										<div class="response-row">
											<span v-if="user.response" class="response-badge" :class="user.response">
												{{ getResponseText(user.response) }}
											</span>
											<span v-else class="response-badge no-response">{{ t('attendance', 'No response') }}</span>
										</div>
										<div v-if="user.comment && user.comment.trim()" class="user-comment">
											<CommentIcon :size="14" class="comment-icon" />
											{{ user.comment }}
										</div>
									</div>
								</div>
								<div class="user-actions">
									<div class="action-buttons">
										<NcButton
											:class="{ active: user.checkinState === 'yes' }"
											:type="user.checkinState && user.checkinState !== 'yes' ? 'tertiary' : 'success'"
											size="small"
											@click="checkinUser(user.userId, 'yes')">
											{{ t('attendance', 'Present') }}
										</NcButton>
										<NcButton
											:class="{ active: user.checkinState === 'no' }"
											:type="user.checkinState && user.checkinState !== 'no' ? 'tertiary' : 'error'"
											size="small"
											@click="checkinUser(user.userId, 'no')">
											{{ t('attendance', 'Absent') }}
										</NcButton>
										<NcButton
											type="tertiary"
											size="small"
											@click="toggleCommentInput(user.userId)"
											:aria-label="t('attendance', 'Add comment')">
											<template #icon>
												<CommentIcon :size="16" />
											</template>
										</NcButton>
									</div>
									<!-- Check-in comment below buttons -->
									<div v-if="user.checkinComment && user.checkinComment.trim()" class="checkin-comment">
										<CommentIcon :size="14" class="checkin-icon" />
										{{ user.checkinComment }}
									</div>
								</div>
							</template>
							
							<!-- Comment overlay mode -->
							<template v-if="showCommentInput[user.userId]">
								<div class="comment-overlay">
									<div class="comment-overlay-header">
										<NcAvatar :user="user.userId" :size="32" :show-user-status="false" />
										<div class="comment-overlay-info">
											<div class="user-name">{{ user.displayName }}</div>
											<div class="response-row">
												<span v-if="user.response" class="response-badge" :class="user.response">
													{{ getResponseText(user.response) }}
												</span>
												<span v-else class="response-badge no-response">{{ t('attendance', 'No response') }}</span>
											</div>
										</div>
									</div>
									<div class="comment-overlay-input">
										<NcTextArea 
											v-model="checkinComments[user.userId]"
											:label="t('attendance', 'Check-in comment')"
											:placeholder="t('attendance', 'Add a comment for this check-in...')"
											rows="2" />
										<div class="comment-actions">
											<NcButton type="primary" size="small" @click="saveCheckinComment(user.userId)">
												{{ t('attendance', 'Save') }}
											</NcButton>
											<NcButton type="tertiary" size="small" @click="cancelCommentInput(user.userId)">
												{{ t('attendance', 'Cancel') }}
											</NcButton>
										</div>
									</div>
								</div>
							</template>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import CommentIcon from 'vue-material-design-icons/Comment.vue'

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcTextArea from '@nextcloud/vue/dist/Components/NcTextArea.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcAvatar from '@nextcloud/vue/dist/Components/NcAvatar.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'

import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

export default {
	name: 'CheckinView',

	components: {
		ArrowLeftIcon,
		AlertIcon,
		MagnifyIcon,
		CommentIcon,
		NcButton,
		NcTextField,
		NcTextArea,
		NcSelect,
		NcAvatar,
		NcLoadingIcon,
		NcEmptyContent,
	},

	props: {
		appointmentId: {
			type: [String, Number],
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			error: null,
			appointment: null,
			respondingUsers: [],
			nonRespondingUsers: [],
			bulkProcessing: false,
			searchQuery: '',
			selectedGroup: null,
			userGroups: [],
			showCommentInput: {},
			checkinComments: {},
		}
	},

	computed: {
		totalUsers() {
			return this.respondingUsers.length + this.nonRespondingUsers.length
		},

		groupOptions() {
			return this.userGroups.map(group => ({
				id: group,
				label: group
			}))
		},

		filteredAllUsers() {
			const allUsers = [...this.respondingUsers, ...this.nonRespondingUsers]
			const filtered = this.filterUsers(allUsers)
			// Always sort by display name alphabetically
			return filtered.sort((a, b) => a.displayName.localeCompare(b.displayName))
		},
	},

	async mounted() {
		await this.loadAppointmentData()
	},

	methods: {
		async loadAppointmentData(preserveScrollPosition = false) {
			let scrollPosition = 0
			if (preserveScrollPosition) {
				scrollPosition = window.pageYOffset || document.documentElement.scrollTop
			}

			try {
				// Don't show loading spinner when preserving scroll position
				if (!preserveScrollPosition) {
				this.loading = true
				}
				this.error = null

				const url = generateUrl('/apps/attendance/api/appointments/{id}/checkin-data', { id: this.appointmentId })
				const response = await axios.get(url)

				this.appointment = response.data.appointment
				this.respondingUsers = response.data.respondingUsers || []
				this.nonRespondingUsers = response.data.nonRespondingUsers || []
				this.userGroups = response.data.userGroups || []
			} catch (error) {
				console.error('Failed to load appointment data:', error)
				this.error = error.response?.data?.message || t('attendance', 'Failed to load appointment data')
			} finally {
				this.loading = false
				
				if (preserveScrollPosition) {
					this.$nextTick(() => {
						window.scrollTo(0, scrollPosition)
					})
				}
			}
		},

		async checkinUser(userId, response, reloadData = true) {
			try {
				const url = generateUrl('/apps/attendance/api/appointments/{id}/checkin/{userId}', {
					id: this.appointmentId,
					userId: userId,
				})
				
				await axios.post(url, {
					response: response,
				})

				// Reload data to reflect changes (unless bulk operation)
				if (reloadData) {
					await this.loadAppointmentData(true)
				}
			} catch (error) {
				console.error('Failed to check in user:', error)
				// Could add a toast notification here
			}
		},


		formatDateTime(datetime) {
			try {
				const date = new Date(datetime)
				const options = { dateStyle: 'medium', timeStyle: 'short' }
				return date.toLocaleString(['de-DE', 'en-EN'], options)
			} catch (error) {
				return datetime
			}
		},

		async bulkCheckinSection(section, response) {
			if (this.bulkProcessing) return

			this.bulkProcessing = true
			const users = section === 'responding' ? this.respondingUsers : this.nonRespondingUsers
			
			try {
				for (const user of users) {
					await this.checkinUser(user.userId, response, false)
				}
				
				// Reload data after bulk operation
				await this.loadAppointmentData(true)
			} catch (error) {
				console.error('Bulk section check-in failed:', error)
			} finally {
				this.bulkProcessing = false
			}
		},

		async bulkCheckinAll(response) {
			if (this.bulkProcessing) return

			this.bulkProcessing = true
			
			try {
				for (const user of this.filteredAllUsers) {
					await this.checkinUser(user.userId, response, false)
				}
				
				// Reload data after bulk operation
				await this.loadAppointmentData(true)
			} catch (error) {
				console.error('Failed to bulk checkin users:', error)
			} finally {
				this.bulkProcessing = false
			}
		},

		filterUsers(users) {
			let filtered = users
			
			// Filter by search query
			if (this.searchQuery) {
				const query = this.searchQuery.toLowerCase()
				filtered = filtered.filter(user => 
					user.displayName.toLowerCase().includes(query) ||
					user.userId.toLowerCase().includes(query)
				)
			}
			
			// Filter by selected group
			if (this.selectedGroup) {
				filtered = filtered.filter(user => 
					user.groups && user.groups.includes(this.selectedGroup.id)
				)
			}
			
			return filtered
		},

		onSearchInput() {
			// Search is reactive through computed properties
		},

		onGroupFilterChange() {
			// Group filter is reactive through computed properties
		},

		getResponseText(response) {
			const responses = {
				yes: this.t('attendance', 'Yes'),
				maybe: this.t('attendance', 'Maybe'),
				no: this.t('attendance', 'No'),
			}
			return responses[response] || response
		},

		goBack() {
			window.history.back()
		},

		toggleCommentInput(userId) {
			this.$set(this.showCommentInput, userId, !this.showCommentInput[userId])
			// Initialize comment with existing value or empty
			if (!this.checkinComments[userId]) {
				const user = this.filteredAllUsers.find(u => u.userId === userId)
				const existingComment = user?.checkinComment || ''
				this.$set(this.checkinComments, userId, existingComment)
			}
		},

		async saveCheckinComment(userId) {
			try {
				const url = generateUrl('/apps/attendance/api/appointments/{id}/checkin/{userId}', {
					id: this.appointmentId,
					userId: userId,
				})
				
				await axios.post(url, {
					response: null, // Don't change the check-in response
					comment: this.checkinComments[userId] || '',
				})

				// Hide comment input and reload data
				this.$set(this.showCommentInput, userId, false)
				await this.loadAppointmentData(true)
			} catch (error) {
				console.error('Failed to save admin comment:', error)
				// Could add a toast notification here
			}
		},

		cancelCommentInput(userId) {
			// Reset comment to original value
			const user = this.filteredAllUsers.find(u => u.userId === userId)
			const originalComment = user?.checkinComment || ''
			this.$set(this.checkinComments, userId, originalComment)
			this.$set(this.showCommentInput, userId, false)
		},
	},
}
</script>

<style scoped lang="scss">
.checkin-view {
	padding: 20px;
	max-width: 800px;
	margin: 0 auto;
}

.checkin-header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 24px;

	h1 {
		margin: 0;
		font-size: 24px;
		font-weight: 600;
	}
}

.loading-container,
.error-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	min-height: 200px;
	text-align: center;
}

.appointment-info {
	margin-bottom: 30px;
	padding: 20px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);

	h2 {
		margin: 0 0 10px 0;
		color: var(--color-main-text);
	}

	.appointment-details {
		margin: 10px 0;
		color: var(--color-text-maxcontrast);
	}

	.appointment-description {
		margin: 15px 0 0 0;
		color: var(--color-main-text);
		font-style: italic;
	}
}

.controls-section {
	margin-bottom: 30px;
	padding: 20px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);

	.search-container {
		margin-bottom: 15px;
		max-width: 400px;
	}

	.group-filter {
		max-width: 300px;
		.v-select.select {
			min-width: 200px;
		}
	}
}

.user-section {
	margin-bottom: 24px;

	&.hidden {
		display: none;
	}

	.section-header {
		display: flex;
		justify-content: right;
		align-items: center;
		margin-bottom: 16px;
		padding: 0 4px;

		.section-actions {
			display: flex;
			gap: 8px;
		}
	}

	// Legacy support for sections without header
	h3 {
		margin: 0 0 16px 0;
		font-size: 18px;
		font-weight: 600;
		color: var(--color-text-light);
	}
}

.user-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.user-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);

	.user-info {
		display: flex;
		align-items: center;
		gap: 12px;
		flex: 1;

		.user-details {
			flex: 1;
			margin-left: 12px;

			.user-name {
				font-weight: 600;
				color: var(--color-main-text);
				margin-bottom: 4px;
			}

			.response-row {
				margin-bottom: 5px;
			}

			.response-badge {
				padding: 2px 8px;
				border-radius: 12px;
				font-size: 12px;
				font-weight: bold;

				&.yes {
					background: var(--color-success);
					color: white;
				}

				&.maybe {
					background: var(--color-warning);
					color: white;
				}

				&.no {
					background: var(--color-error);
					color: white;
				}

				&.no-response {
					background: var(--color-text-maxcontrast);
					color: white;
				}
			}

			.checkin-badge {
				padding: 2px 6px;
				border-radius: 8px;
				font-size: 10px;
				font-weight: bold;
				background: var(--color-primary);
				color: white;
			}
			.user-comment {
				font-size: 12px;
				color: var(--color-text-maxcontrast);
				font-style: italic;
				margin: 4px 0;
				display: flex;
				align-items: center;
				gap: 6px;

				.comment-icon {
					color: var(--color-text-maxcontrast);
					flex-shrink: 0;
				}
			}
		}

	}

	.checkin-comment {
		font-size: 12px;
		color: var(--color-primary);
		margin: 0;
		display: flex;
		align-items: center;
		gap: 6px;

		.checkin-icon {
			color: var(--color-primary);
			flex-shrink: 0;
		}
	}

	.user-actions {
		display: flex;
		flex-direction: column;
		gap: 8px;
		flex-shrink: 0;

		.action-buttons {
			display: flex;
			gap: 8px;

			@media (max-width: 768px) {
				flex-direction: column;
			}
		}

		button {
			&:not(.active) {
				background: var(--color-background-dark) !important;
				color: var(--color-text-lighter) !important;
				border-color: var(--color-border-dark) !important;

				&:hover {
					opacity: 0.8;
				}
			}

			&.active {
				font-weight: bold;
				opacity: 1;
			}
		}
	}

	.comment-overlay {
		display: flex;
		align-items: flex-start;
		gap: 16px;
		padding: 12px;
		background: var(--color-background-hover);
		border-radius: var(--border-radius-large);
		border: 2px solid var(--color-primary-element);
		width: 100%;

		.comment-overlay-header {
			display: flex;
			align-items: center;
			gap: 12px;
			flex-shrink: 0;

			.comment-overlay-info {
				display: flex;
				flex-direction: column;
				gap: 4px;

				.user-name {
					font-weight: 600;
					color: var(--color-main-text);
				}

				.response-badge {
					padding: 2px 8px;
					border-radius: 12px;
					font-size: 12px;
					font-weight: bold;

					&.yes {
						background: var(--color-success);
						color: white;
					}

					&.maybe {
						background: var(--color-warning);
						color: white;
					}

					&.no {
						background: var(--color-error);
						color: white;
					}

					&.no-response {
						background: var(--color-text-maxcontrast);
						color: white;
					}
				}
			}
		}

		.comment-overlay-input {
			flex: 1;

			.comment-actions {
				display: flex;
				gap: 8px;
				justify-content: flex-end;
				margin-top: 8px;
			}
		}
	}
}
</style>
