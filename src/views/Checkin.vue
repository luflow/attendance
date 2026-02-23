<template>
	<div class="checkin-view" data-test="checkin-view">
		<CheckinHeader @back="goBack" />

		<div v-if="loading" class="loading-container">
			<NcLoadingIcon />
			<p>{{ t('attendance', 'Loading appointment data …') }}</p>
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

		<div v-if="!loading && !error && timeWarning && !timeWarningAccepted" class="time-warning-gate">
			<CheckinAppointmentInfo :appointment="appointment" :display-order="permissions.displayOrder" />
			<NcNoteCard type="error" :heading="t('attendance', 'Caution')" :show-alert="true">
				<p>{{ timeWarning }}</p>
				<div class="time-warning-action">
					<NcButton variant="error" @click="timeWarningAccepted = true">
						{{ t('attendance', 'Continue anyway') }}
					</NcButton>
				</div>
			</NcNoteCard>
		</div>

		<div v-if="!loading && !error && (!timeWarning || timeWarningAccepted)" class="checkin-content">
			<CheckinAppointmentInfo :appointment="appointment" :display-order="permissions.displayOrder" />

			<CheckinStatus
				:all-checked-in="checkinStatus.allCheckedIn"
				:not-checked-in-count="checkinStatus.notCheckedIn" />

			<div class="filter-section">
				<NcRadioGroup
					v-model="showFilter"
					:label="t('attendance', 'Filter users')"
					hide-label>
					<NcRadioGroupButton
						:label="t('attendance', 'All users')"
						value="all"
						data-test="filter-all" />
					<NcRadioGroupButton
						:label="showPendingLabel"
						value="not-checked-in"
						data-test="filter-not-checked-in" />
				</NcRadioGroup>
			</div>

			<CheckinControls
				v-model:search-query="searchQuery"
				v-model:selected-group="selectedGroup"
				:group-options="groupOptions" />

			<div class="user-lists">
				<div class="user-section">
					<CheckinBulkActions
						v-if="filteredAllUsers.length > 0"
						:disabled="bulkProcessing"
						@bulk-checkin="confirmBulkCheckin" />

					<div v-if="filteredAllUsers.length > 0" class="user-list">
						<CheckinUserItem
							v-for="user in filteredAllUsers"
							:key="user.userId"
							:user="user"
							:show-comment-input="showCommentInput[user.userId]"
							:comment-value="checkinComments[user.userId] || ''"
							:can-see-comments="permissions.canSeeComments"
							@checkin="checkinUser"
							@toggle-comment="toggleCommentInput"
							@save-comment="saveCheckinComment"
							@cancel-comment="cancelCommentInput"
							@update:comment-value="updateCommentValue(user.userId, $event)" />
					</div>

					<NcEmptyContent
						v-else-if="showFilter === 'not-checked-in'"
						:name="t('attendance', 'All attendees checked in')"
						data-test="empty-all-checked-in">
						<template #icon>
							<CheckIcon />
						</template>
						<template #action>
							<NcButton @click="showFilter = 'all'">
								{{ t('attendance', 'Show all users') }}
							</NcButton>
						</template>
					</NcEmptyContent>

					<NcEmptyContent
						v-else-if="searchQuery || selectedGroup"
						:name="t('attendance', 'No users found')"
						data-test="empty-no-results">
						<template #icon>
							<AccountSearchIcon />
						</template>
						<template #description>
							{{ t('attendance', 'Try adjusting your search or filter') }}
						</template>
					</NcEmptyContent>
				</div>
			</div>

			<div class="reset-section">
				<NcButton variant="tertiary" @click="showResetDialog = true">
					{{ t('attendance', 'Reset check-in') }}
				</NcButton>
			</div>
		</div>

		<!-- Confirmation Dialog -->
		<NcDialog
			:open="showConfirmDialog"
			:name="t('attendance', 'Confirm bulk action')"
			:message="confirmMessage"
			data-test="dialog-confirm-bulk"
			@closing="cancelBulkAction">
			<template #actions>
				<NcButton data-test="button-bulk-cancel" @click="cancelBulkAction">
					{{ t('attendance', 'Cancel') }}
				</NcButton>
				<NcButton
					:variant="pendingBulkAction === 'yes' ? 'success' : 'error'"
					data-test="button-bulk-confirm"
					@click="executeBulkAction">
					{{ t('attendance', 'Confirm') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Reset Check-in Dialog -->
		<NcDialog
			:open="showResetDialog"
			:name="t('attendance', 'Reset check-in')"
			@closing="showResetDialog = false">
			<p v-if="appointment">
				<strong>{{ appointment.name }}</strong><br>
				{{ formatDateRange(appointment.startDatetime, appointment.endDatetime) }}
			</p>
			<p>{{ t('attendance', 'Do you want to reset the check-in for this appointment? This will remove all check-in entries.') }}</p>
			<template #actions>
				<NcButton @click="showResetDialog = false">
					{{ t('attendance', 'Cancel') }}
				</NcButton>
				<NcButton variant="error" @click="executeResetCheckin">
					{{ t('attendance', 'Reset check-in') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { NcButton, NcLoadingIcon, NcEmptyContent, NcDialog, NcNoteCard, NcRadioGroup, NcRadioGroupButton } from '@nextcloud/vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import AccountSearchIcon from 'vue-material-design-icons/AccountSearch.vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { usePermissions } from '../composables/usePermissions.js'
import { formatDateRange } from '../utils/datetime.js'

// Split components
import CheckinHeader from '../components/checkin/CheckinHeader.vue'
import CheckinAppointmentInfo from '../components/checkin/CheckinAppointmentInfo.vue'
import CheckinStatus from '../components/checkin/CheckinStatus.vue'
import CheckinControls from '../components/checkin/CheckinControls.vue'
import CheckinBulkActions from '../components/checkin/CheckinBulkActions.vue'
import CheckinUserItem from '../components/checkin/CheckinUserItem.vue'

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
const showFilter = ref('all')
const showCommentInput = reactive({})
const checkinComments = reactive({})
const showConfirmDialog = ref(false)
const pendingBulkAction = ref(null)
const confirmMessage = ref('')
const timeWarningAccepted = ref(false)
const showResetDialog = ref(false)

const { permissions, loadPermissions } = usePermissions()

// Computed
const TIME_THRESHOLD_MS = 8 * 60 * 60 * 1000

const timeWarning = computed(() => {
	if (!appointment.value) return null
	const now = Date.now()
	const start = new Date(appointment.value.startDatetime).getTime()
	const end = new Date(appointment.value.endDatetime).getTime()

	if (start - now > TIME_THRESHOLD_MS) {
		return t('attendance', 'This appointment is way in the future')
	}
	if (now - end > TIME_THRESHOLD_MS) {
		return t('attendance', 'This appointment is way in the past')
	}
	return null
})
const groupOptions = computed(() => userGroups.value.map(group => ({
	id: group,
	label: t('attendance', group),
})))

const showPendingLabel = computed(() => {
	return t('attendance', 'Only pending ({count})', { count: checkinStatus.value.notCheckedIn })
})

const filteredAllUsers = computed(() => {
	let filtered = users.value

	// Filter by checkin status (all vs not checked in)
	if (showFilter.value === 'not-checked-in') {
		filtered = filtered.filter(user => !user.checkinState)
	}

	// Filter by search query
	if (searchQuery.value) {
		const query = searchQuery.value.toLowerCase()
		filtered = filtered.filter(user =>
			user.displayName.toLowerCase().includes(query)
			|| user.userId.toLowerCase().includes(query),
		)
	}

	// Filter by selected group
	if (selectedGroup.value) {
		filtered = filtered.filter(user =>
			user.groups && user.groups.includes(selectedGroup.value.id),
		)
	}

	// Sort by display name alphabetically
	return filtered.sort((a, b) => a.displayName.localeCompare(b.displayName))
})

const checkinStatus = computed(() => {
	const checkedInUsers = users.value.filter(user => user.checkinState)
	const notCheckedInCount = users.value.length - checkedInUsers.length

	return {
		total: users.value.length,
		checkedIn: checkedInUsers.length,
		notCheckedIn: notCheckedInCount,
		allCheckedIn: notCheckedInCount === 0,
	}
})

// Methods
const goBack = () => {
	window.history.back()
}

const loadAppointmentData = async (skipLoadingSpinner = false) => {
	try {
		if (!skipLoadingSpinner) {
			loading.value = true
		}
		error.value = null

		const url = generateUrl('/apps/attendance/api/appointments/{id}/checkin-data', { id: props.appointmentId })
		const response = await axios.get(url)

		appointment.value = response.data.appointment
		users.value = response.data.users || []
		userGroups.value = response.data.userGroups || []
	} catch (err) {
		console.error('Failed to load appointment data:', err)
		error.value = err.response?.data?.message || t('attendance', 'Failed to load appointment data')
	} finally {
		loading.value = false
	}
}

const checkinUser = async (userId, response, reloadData = true) => {
	try {
		const url = generateUrl('/apps/attendance/api/appointments/{id}/checkin/{userId}', {
			id: props.appointmentId,
			userId,
		})

		await axios.post(url, { response })

		if (reloadData) {
			await loadAppointmentData(true)
		}
	} catch (err) {
		console.error('Failed to check in user:', err)
	}
}

const confirmBulkCheckin = (response) => {
	const userCount = filteredAllUsers.value.length
	const actionText = response === 'yes' ? t('attendance', 'attending') : t('attendance', 'not attending')

	confirmMessage.value = t('attendance', 'Do you want to set {count} users to {action}?', { count: userCount, action: actionText })

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

const executeResetCheckin = async () => {
	showResetDialog.value = false
	try {
		const url = generateUrl('/apps/attendance/api/appointments/{id}/checkin-reset', { id: props.appointmentId })
		await axios.delete(url)
		await loadAppointmentData(true)
	} catch (err) {
		console.error('Failed to reset check-in:', err)
	}
}

const toggleCommentInput = (userId) => {
	showCommentInput[userId] = !showCommentInput[userId]
	if (showCommentInput[userId] && !checkinComments[userId]) {
		const user = filteredAllUsers.value.find(u => u.userId === userId)
		checkinComments[userId] = user?.checkinComment || ''
	}
}

const updateCommentValue = (userId, value) => {
	checkinComments[userId] = value
}

const saveCheckinComment = async (userId) => {
	try {
		const url = generateUrl('/apps/attendance/api/appointments/{id}/checkin/{userId}', {
			id: props.appointmentId,
			userId,
		})

		await axios.post(url, {
			response: null,
			comment: checkinComments[userId] || '',
		})

		showCommentInput[userId] = false
		await loadAppointmentData(true)
	} catch (err) {
		console.error('Failed to save admin comment:', err)
	}
}

const cancelCommentInput = (userId) => {
	const user = filteredAllUsers.value.find(u => u.userId === userId)
	checkinComments[userId] = user?.checkinComment || ''
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

.loading-container,
.error-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	min-height: 200px;
	text-align: center;
}

.time-warning-action {
	margin: 12px 0;
}

.filter-section {
	margin-bottom: 20px;
}

.user-section {
	margin-bottom: 24px;
}

.user-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.reset-section {
	margin-top: 24px;
	text-align: center;
}
</style>
