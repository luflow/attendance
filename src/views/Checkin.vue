<template>
	<div class="checkin-view">
		<div class="checkin-header">
			<NcButton type="tertiary" @click="goBack">
				<template #icon>
					<ArrowLeftIcon />
				</template>
				{{ t('attendance', 'Back') }}
			</NcButton>
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
				<h2>{{ t('attendance', 'Check-in') }}: {{ appointment.name }}</h2>
				<p class="appointment-details">
					<strong>{{ t('attendance', 'Start') }}:</strong> {{ formatDateTime(appointment.startDatetime) }}<br>
					<strong>{{ t('attendance', 'End') }}:</strong> {{ formatDateTime(appointment.endDatetime) }}
				</p>
				<div v-if="appointment.description" class="appointment-description" v-html="renderedDescription"></div>
			</div>

			<!-- Check-in status indicator -->
			<div class="checkin-status">
				<div v-if="checkinStatus.allCheckedIn" class="status-complete">
					<CheckIcon :size="20" />
					<span>{{ t('attendance', 'All attendees checked in') }}</span>
				</div>
				<div v-else class="status-pending">
					<AlertIcon :size="20" />
					<span>{{ t('attendance', '{count} attendees not yet checked in', { count: checkinStatus.notCheckedIn }) }}</span>
				</div>
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
								variant="success"
								:disabled="bulkProcessing"
								@click="confirmBulkCheckin('yes')">
								{{ t('attendance', 'All Present') }}
							</NcButton>
							<NcButton
								variant="error"
								:disabled="bulkProcessing"
								@click="confirmBulkCheckin('no')">
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
											<NcChip 
												v-if="user.response"
												:text="getResponseText(user.response)" 
												:variant="getResponseVariant(user.response)" 
												no-close />
											<NcChip 
												v-else
												:text="t('attendance', 'No response')" 
												variant="tertiary" 
												no-close />
										</div>
										<div v-if="canSeeComments && user.comment && user.comment.trim()" class="user-comment">
											<CommentIcon :size="14" class="comment-icon" />
											{{ user.comment }}
										</div>
									</div>
								</div>
								<div class="user-actions">
									<div class="action-buttons">
										<NcButton
											:variant="user.checkinState === 'yes' || !user.checkinState ? 'success' : 'tertiary'"
											@click="checkinUser(user.userId, 'yes')">
											{{ t('attendance', 'Present') }}
										</NcButton>
										<NcButton
											:variant="user.checkinState === 'no' || !user.checkinState ? 'error' : 'tertiary'"
											@click="checkinUser(user.userId, 'no')">
											{{ t('attendance', 'Absent') }}
										</NcButton>
										<NcButton
											variant="tertiary"
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
												<NcChip 
													v-if="user.response"
													:text="getResponseText(user.response)" 
													:variant="getResponseVariant(user.response)" 
													no-close />
												<NcChip 
													v-else
													:text="t('attendance', 'No response')" 
													variant="tertiary" 
													no-close />
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
											<NcButton variant="primary" @click="saveCheckinComment(user.userId)">
												{{ t('attendance', 'Save') }}
											</NcButton>
											<NcButton variant="tertiary" @click="cancelCommentInput(user.userId)">
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

		<!-- Confirmation Dialog -->
		<NcDialog
			:open="showConfirmDialog"
			:name="t('attendance', 'Confirm Bulk Action')"
			:message="confirmMessage"
			@closing="cancelBulkAction">
			<template #actions>
				<NcButton @click="cancelBulkAction">
					{{ t('attendance', 'Cancel') }}
				</NcButton>
				<NcButton
					:variant="pendingBulkAction === 'yes' ? 'success' : 'error'"
					@click="executeBulkAction">
					{{ t('attendance', 'Confirm') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { NcButton, NcTextField, NcTextArea, NcSelect, NcAvatar, NcLoadingIcon, NcEmptyContent, NcDialog, NcChip } from '@nextcloud/vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import CommentIcon from 'vue-material-design-icons/Comment.vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { renderMarkdown, sanitizeHtml } from '../utils/markdown.js'

// Props
const props = defineProps({
	appointmentId: {
		type: [String, Number],
		required: true,
	},
})

// State
const loading = ref(true)
const error = ref(null)
const appointment = ref(null)
const users = ref([])
const bulkProcessing = ref(false)
const searchQuery = ref('')
const selectedGroup = ref(null)
const userGroups = ref([])
const canSeeComments = ref(false)
const showCommentInput = reactive({})
const checkinComments = reactive({})
const showConfirmDialog = ref(false)
const pendingBulkAction = ref(null)
const confirmMessage = ref('')

// Computed
const totalUsers = computed(() => users.value.length)

const groupOptions = computed(() => {
	return userGroups.value.map(group => ({
		id: group,
		label: window.t('attendance', group),
	}))
})

const filteredAllUsers = computed(() => {
	const filtered = filterUsers(users.value)
	// Always sort by display name alphabetically
	return filtered.sort((a, b) => a.displayName.localeCompare(b.displayName))
})

const checkinStatus = computed(() => {
	const checkedInUsers = users.value.filter(user => user.checkinState)
	const notCheckedInCount = users.value.length - checkedInUsers.length
	
	return {
		total: users.value.length,
		checkedIn: checkedInUsers.length,
		notCheckedIn: notCheckedInCount,
		allCheckedIn: notCheckedInCount === 0
	}
})

// Methods
const loadAppointmentData = async (skipLoadingSpinner = false) => {
	try {
		// Don't show loading spinner when refreshing data
		if (!skipLoadingSpinner) {
			loading.value = true
		}
		error.value = null

		const url = generateUrl('/apps/attendance/api/appointments/{id}/checkin-data', { id: props.appointmentId })
		const response = await axios.get(url)

		appointment.value = response.data.appointment
		users.value = response.data.users || []
		userGroups.value = response.data.userGroups || []
	} catch (error) {
		console.error('Failed to load appointment data:', error)
		error.value = error.response?.data?.message || window.t('attendance', 'Failed to load appointment data')
	} finally {
		loading.value = false
	}
}

const checkinUser = async (userId, response, reloadData = true) => {
	try {
		const url = generateUrl('/apps/attendance/api/appointments/{id}/checkin/{userId}', {
			id: props.appointmentId,
			userId: userId,
		})
		
		await axios.post(url, {
			response: response,
		})

		// Reload data to reflect changes (unless bulk operation)
		if (reloadData) {
			await loadAppointmentData(true)
		}
	} catch (err) {
		console.error('Failed to check in user:', err)
		// Could add a toast notification here
	}
}

const formatDateTime = (datetime) => {
	try {
		const date = new Date(datetime)
		const options = { dateStyle: 'medium', timeStyle: 'short' }
		return date.toLocaleString(['de-DE', 'en-EN'], options)
	} catch (err) {
		return datetime
	}
}

const confirmBulkCheckin = (response) => {
	const userCount = filteredAllUsers.value.length
	const actionText = response === 'yes' ? window.t('attendance', 'attending') : window.t('attendance', 'not attending')
	
	// Use string replacement for proper translation
	confirmMessage.value = window.t('attendance', 'Do you really want to set all {count} users to {action}?')
		.replace('{count}', userCount)
		.replace('{action}', actionText)
	
	pendingBulkAction.value = response
	showConfirmDialog.value = true
}

const executeBulkAction = async () => {
	if (bulkProcessing.value) return

	bulkProcessing.value = true
	showConfirmDialog.value = false
	
	try {
		for (const user of filteredAllUsers.value) {
			await checkinUser(user.userId, pendingBulkAction.value, false)
		}
		
		// Reload data after bulk operation
		await loadAppointmentData(true)
	} catch (err) {
		console.error('Failed to bulk checkin users:', err)
	} finally {
		bulkProcessing.value = false
		pendingBulkAction.value = null
	}
}

const cancelBulkAction = () => {
	showConfirmDialog.value = false
	pendingBulkAction.value = null
	confirmMessage.value = ''
}

const filterUsers = (usersArray) => {
	let filtered = usersArray
	
	// Filter by search query
	if (searchQuery.value) {
		const query = searchQuery.value.toLowerCase()
		filtered = filtered.filter(user => 
			user.displayName.toLowerCase().includes(query) ||
			user.userId.toLowerCase().includes(query)
		)
	}
	
	// Filter by selected group
	if (selectedGroup.value) {
		filtered = filtered.filter(user => 
			user.groups && user.groups.includes(selectedGroup.value.id)
		)
	}
	
	return filtered
}

const onSearchInput = () => {
	// Search is reactive through computed properties
}

const onGroupFilterChange = () => {
	// Group filter is reactive through computed properties
}

const getResponseText = (response) => {
	const responses = {
		yes: window.t('attendance', 'Yes'),
		maybe: window.t('attendance', 'Maybe'),
		no: window.t('attendance', 'No'),
	}
	return responses[response] || response
}

const getResponseVariant = (response) => {
	const variants = {
		yes: 'success',
		maybe: 'warning',
		no: 'error',
	}
	return variants[response] || 'tertiary'
}

const renderedDescription = computed(() => {
	if (!appointment.value?.description) return ''
	return sanitizeHtml(renderMarkdown(appointment.value.description, true))
})

const loadPermissions = async () => {
	try {
		const response = await axios.get(generateUrl('/apps/attendance/api/user/permissions'))
		canSeeComments.value = response.data.canSeeComments
	} catch (error) {
		console.error('Failed to load permissions:', error)
	}
}

const goBack = () => {
	window.history.back()
}

const toggleCommentInput = (userId) => {
	showCommentInput[userId] = !showCommentInput[userId]
	// Initialize comment with existing value or empty
	if (!checkinComments[userId]) {
		const user = filteredAllUsers.value.find(u => u.userId === userId)
		const existingComment = user?.checkinComment || ''
		checkinComments[userId] = existingComment
	}
}

const saveCheckinComment = async (userId) => {
	try {
		const url = generateUrl('/apps/attendance/api/appointments/{id}/checkin/{userId}', {
			id: props.appointmentId,
			userId: userId,
		})
		
		await axios.post(url, {
			response: null, // Don't change the check-in response
			comment: checkinComments[userId] || '',
		})

		// Hide comment input and reload data
		showCommentInput[userId] = false
		await loadAppointmentData(true)
	} catch (err) {
		console.error('Failed to save admin comment:', err)
		// Could add a toast notification here
	}
}

const cancelCommentInput = (userId) => {
	// Reset comment to original value
	const user = filteredAllUsers.value.find(u => u.userId === userId)
	const originalComment = user?.checkinComment || ''
	checkinComments[userId] = originalComment
	showCommentInput[userId] = false
}

// Lifecycle
onMounted(async () => {
	await loadPermissions()
	await loadAppointmentData()
})
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
	margin-bottom: 20px;
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
		white-space: pre-wrap;
		
		// Markdown formatting
		:deep(strong) {
			font-weight: bold;
			color: var(--color-main-text);
		}
		
		:deep(em) {
			font-style: italic;
		}
	}
}

.checkin-status {
	margin-bottom: 20px;
	padding: 16px 20px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	border-left: 4px solid transparent;

	.status-complete {
		display: flex;
		align-items: center;
		gap: 12px;
		color: black;
		border-left-color: var(--color-success);

		span {
			font-weight: 600;
		}
	}

	.status-pending {
		display: flex;
		align-items: center;
		gap: 12px;
		background: #ff8c00;
		color: white;
		border-left-color: #ff6600;
		border-radius: var(--border-radius-large);
		padding: 16px 20px;
		margin: -16px -20px;

		span {
			font-weight: 600;
		}
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
		justify-content: flex-end;
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
