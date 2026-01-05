<template>
	<NcModal v-model:show="internalShow"
		:close-on-click-outside="false"
		data-test="appointment-form-modal"
		@close="handleClose">
		<div ref="modalContentRef" class="modal-content">
			<h2 data-test="form-title">
				{{ isEdit ? t('attendance', 'Edit Appointment') : (isCopy ? t('attendance', 'Copy Appointment') : t('attendance', 'Create Appointment')) }}
			</h2>
			<form data-test="appointment-form" @submit.prevent="handleSubmit">
				<NcTextField
					v-model="formData.name"
					:label="t('attendance', 'Appointment Name')"
					data-test="input-appointment-name"
					required />

				<MarkdownEditor
					v-model="formData.description"
					:label="t('attendance', 'Description')"
					:placeholder="t('attendance', 'Write your description here...')"
					data-test="input-appointment-description"
					min-height="120px" />

				<div class="form-field" data-test="attachment-section">
					<label>{{ t('attendance', 'Attachments') }}</label>
					<div class="attachment-list" data-test="attachment-list">
						<NcChip
							v-for="attachment in attachments"
							:key="attachment.fileId"
							:text="attachment.fileName"
							:data-test="`attachment-chip-${attachment.fileId}`"
							@close="removeAttachment(attachment.fileId)">
							<template #icon>
								<Paperclip :size="16" />
							</template>
						</NcChip>
						<span v-if="attachments.length === 0" class="no-attachments">
							{{ t('attendance', 'No attachments') }}
						</span>
					</div>
					<NcButton variant="secondary"
						native-type="button"
						data-test="button-add-attachment"
						@click.stop="openFilePicker">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('attendance', 'Add from Files') }}
					</NcButton>
				</div>

				<div class="datetime-fields">
					<NcDateTimePickerNative
						id="start-datetime"
						:model-value="startDateObject"
						type="datetime-local"
						:label="t('attendance', 'Start Date & Time')"
						data-test="input-start-datetime"
						required
						@update:model-value="onStartDatetimeChange"
						@blur="onStartDatetimeBlur" />

					<NcDateTimePickerNative
						id="end-datetime"
						ref="endDatetimePicker"
						:model-value="endDateObject"
						type="datetime-local"
						:label="t('attendance', 'End Date & Time')"
						data-test="input-end-datetime"
						required
						@update:model-value="onEndDatetimeChange" />
				</div>

				<div v-if="props.notificationsAppEnabled && !isEdit" class="form-field">
					<label>{{ t('attendance', 'Notification') }}</label>
					<NcCheckboxRadioSwitch
						v-model="sendNotification"
						data-test="checkbox-send-notification">
						{{ t('attendance', 'Send notification') }}
					</NcCheckboxRadioSwitch>
					<p class="hint-text">
						{{ t('attendance', 'Notify users who can see this appointment about its creation') }}
					</p>
				</div>

				<div class="form-field">
					<label>{{ t('attendance', 'Restrict Access') }}</label>
					<p class="hint-text">
						{{ t('attendance', 'Limits who can see this appointment. Leave empty for all users.') }}
					</p>
					<NcSelect
						v-model="visibilityItems"
						:options="searchResults"
						:loading="isSearching"
						:multiple="true"
						:close-on-select="false"
						:filterable="false"
						label="label"
						:placeholder="t('attendance', 'Search users, groups or teams...')"
						data-test="select-visibility"
						@search="onSearch">
						<template #option="{ label, type }">
							<span style="display: flex; align-items: center; gap: 8px;" :title="getTypeLabel(type)">
								<AccountStar v-if="type === 'team'" :size="20" />
								<AccountGroup v-else-if="type === 'group'" :size="20" />
								<Account v-else :size="20" />
								<span>{{ label }}</span>
							</span>
						</template>
						<template #selected-option="{ label, type }">
							<span style="display: flex; align-items: center; gap: 8px;" :title="getTypeLabel(type)">
								<AccountStar v-if="type === 'team'" :size="16" />
								<AccountGroup v-else-if="type === 'group'" :size="16" />
								<Account v-else :size="16" />
								<span>{{ label }}</span>
							</span>
						</template>
					</NcSelect>
					<NcNoteCard v-if="hasTrackingMismatch" type="warning" class="visibility-warning">
						{{ t('attendance', 'Some selections are not in the Response Summary Groups and may therefore appear under "Others".') }}
						<a :href="adminSettingsUrl" target="_blank">{{ t('attendance', 'Configure in Admin Settings') }}</a>
					</NcNoteCard>
				</div>

				<div class="form-actions">
					<NcButton variant="secondary" data-test="button-cancel" @click="handleClose">
						{{ t('attendance', 'Cancel') }}
					</NcButton>
					<NcButton variant="primary" native-type="submit" data-test="button-save">
						{{ t('attendance', 'Save') }}
					</NcButton>
				</div>
			</form>
		</div>
	</NcModal>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, nextTick } from 'vue'
import { NcModal, NcButton, NcTextField, NcSelect, NcNoteCard, NcCheckboxRadioSwitch, NcChip, NcDateTimePickerNative } from '@nextcloud/vue'
import { getFilePickerBuilder, showSuccess, showError } from '@nextcloud/dialogs'
import MarkdownEditor from '../common/MarkdownEditor.vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Account from 'vue-material-design-icons/Account.vue'
import AccountStar from 'vue-material-design-icons/AccountStar.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import '@nextcloud/dialogs/style.css'

const props = defineProps({
	show: {
		type: Boolean,
		required: true,
	},
	appointment: {
		type: Object,
		default: null,
	},
	copyFrom: {
		type: Object,
		default: null,
	},
	notificationsAppEnabled: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits(['close', 'submit'])

const endDatetimePicker = ref(null)
const modalContentRef = ref(null)

const formData = reactive({
	name: '',
	description: '',
	startDatetime: '',
	endDatetime: '',
	visibleUsers: [],
	visibleGroups: [],
	visibleTeams: [],
	sendNotification: false,
})

const visibilityItems = ref([])
const searchResults = ref([])
const isSearching = ref(false)
const sendNotification = ref(false)
const trackingGroups = ref([])
const attachments = ref([]) // Array of { fileId, fileName, filePath, downloadUrl }
const filePickerOpen = ref(false)

// Internal show state that we control
const internalShow = ref(false)

// Sync internal state with prop, but only when file picker is not open
watch(() => props.show, (newValue) => {
	if (!filePickerOpen.value || newValue) {
		internalShow.value = newValue
	}
}, { immediate: true })

// When internal state changes to false (NcModal tries to close), check if we should allow it
watch(internalShow, (newValue) => {
	if (!newValue && filePickerOpen.value) {
		// File picker is open, prevent close by resetting to true
		nextTick(() => {
			internalShow.value = true
		})
	}
})

// Fetch tracking groups from admin settings
const loadTrackingGroups = async () => {
	try {
		const response = await axios.get(
			generateUrl('/apps/attendance/api/admin/settings'),
		)
		if (response.data.success && response.data.whitelistedGroups) {
			trackingGroups.value = response.data.whitelistedGroups
		}
	} catch (error) {
		// If user doesn't have admin access, that's fine - they won't see the warning
		console.debug('Could not load tracking groups (user may not be admin):', error)
	}
}

// Check if selected visibility groups overlap with tracking groups
const hasTrackingMismatch = computed(() => {
	// No selections at all - no warning needed
	if (formData.visibleGroups.length === 0 && formData.visibleUsers.length === 0 && formData.visibleTeams.length === 0) {
		return false
	}
	// No tracking groups configured - no warning needed
	if (trackingGroups.value.length === 0) {
		return false
	}
	// Individual users selected - they might appear under "Others"
	if (formData.visibleUsers.length > 0) {
		return true
	}
	// Teams selected - team members might appear under "Others"
	if (formData.visibleTeams.length > 0) {
		return true
	}
	// Check if any selected group is NOT in tracking groups
	const hasNonTrackingGroup = formData.visibleGroups.some(
		groupId => !trackingGroups.value.includes(groupId),
	)
	return hasNonTrackingGroup
})

const adminSettingsUrl = computed(() => {
	return generateUrl('/settings/admin/attendance')
})

const getTypeLabel = (type) => {
	switch (type) {
	case 'user':
		return t('attendance', 'User')
	case 'group':
		return t('attendance', 'Group')
	case 'team':
		return t('attendance', 'Team')
	default:
		return ''
	}
}

onMounted(() => {
	loadTrackingGroups()
})

// Watch for changes to visibilityItems to update formData
watch(visibilityItems, (selected) => {
	const selectedArray = Array.isArray(selected) ? selected : (selected ? [selected] : [])
	// Split into users, groups and teams based on the type property
	// Use 'value' property which contains the original user/group/team ID
	formData.visibleUsers = selectedArray.filter(item => item && item.type === 'user').map(item => item.value)
	formData.visibleGroups = selectedArray.filter(item => item && item.type === 'group').map(item => item.value)
	formData.visibleTeams = selectedArray.filter(item => item && item.type === 'team').map(item => item.value)
})

const isEdit = computed(() => !!props.appointment)
const isCopy = computed(() => !!props.copyFrom)

// Convert string datetime to Date object for NcDateTimePickerNative
const startDateObject = computed(() => {
	if (!formData.startDatetime) return null
	const date = new Date(formData.startDatetime)
	return isNaN(date.getTime()) ? null : date
})

const endDateObject = computed(() => {
	if (!formData.endDatetime) return null
	const date = new Date(formData.endDatetime)
	return isNaN(date.getTime()) ? null : date
})

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

// Watch for appointment changes to populate form
watch(() => props.appointment, async (newAppointment) => {
	if (newAppointment) {
		formData.name = newAppointment.name || ''
		formData.description = newAppointment.description || ''
		formData.startDatetime = formatDateTimeForInput(newAppointment.startDatetime)
		formData.endDatetime = formatDateTimeForInput(newAppointment.endDatetime)

		// Load visibility settings (enriched data with id, label, type)
		const users = newAppointment.visibleUsers || []
		const groups = newAppointment.visibleGroups || []
		const teams = newAppointment.visibleTeams || []

		// Store raw IDs for form submission
		formData.visibleUsers = users.map(u => u.id)
		formData.visibleGroups = groups.map(g => g.id)
		formData.visibleTeams = teams.map(t => t.id)

		// Convert enriched data to visibility items for NcSelect
		const items = []
		for (const user of users) {
			items.push({ id: `user:${user.id}`, value: user.id, label: user.label, type: 'user' })
		}
		for (const group of groups) {
			items.push({ id: `group:${group.id}`, value: group.id, label: group.label, type: 'group' })
		}
		for (const team of teams) {
			items.push({ id: `team:${team.id}`, value: team.id, label: team.label, type: 'team' })
		}

		searchResults.value = [...items]
		visibilityItems.value = [...items]

		// Load attachments
		attachments.value = newAppointment.attachments || []
	} else {
		// Reset form for create
		formData.name = ''
		formData.description = ''
		formData.startDatetime = ''
		formData.endDatetime = ''
		formData.visibleUsers = []
		formData.visibleGroups = []
		formData.visibleTeams = []
		sendNotification.value = false
		visibilityItems.value = []
		searchResults.value = []
		attachments.value = []
	}
}, { immediate: true })

// Watch for copyFrom changes to pre-fill form with copied data
watch(() => props.copyFrom, async (copySource) => {
	if (copySource) {
		// Pre-fill form with copied data (except dates per spec)
		formData.name = copySource.name ? `${copySource.name} (${t('attendance', 'Copy')})` : ''
		formData.description = copySource.description || ''
		// Dates are intentionally left empty - user must set new dates
		formData.startDatetime = ''
		formData.endDatetime = ''

		// Load visibility settings (enriched data with id, label, type)
		const users = copySource.visibleUsers || []
		const groups = copySource.visibleGroups || []
		const teams = copySource.visibleTeams || []

		// Store raw IDs for form submission
		formData.visibleUsers = users.map(u => u.id)
		formData.visibleGroups = groups.map(g => g.id)
		formData.visibleTeams = teams.map(t => t.id)

		// Convert enriched data to visibility items for NcSelect
		const items = []
		for (const user of users) {
			items.push({ id: `user:${user.id}`, value: user.id, label: user.label, type: 'user' })
		}
		for (const group of groups) {
			items.push({ id: `group:${group.id}`, value: group.id, label: group.label, type: 'group' })
		}
		for (const team of teams) {
			items.push({ id: `team:${team.id}`, value: team.id, label: team.label, type: 'team' })
		}

		searchResults.value = [...items]
		visibilityItems.value = [...items]

		// Copy attachments from source (for local display, actual copy happens on submit)
		attachments.value = (copySource.attachments || []).map(a => ({
			fileId: a.fileId,
			fileName: a.fileName,
			filePath: a.filePath,
		}))

		sendNotification.value = false
	}
}, { immediate: true })

// Reset form when modal opens in create mode
watch(() => props.show, (isShowing) => {
	if (isShowing && !props.appointment && !props.copyFrom) {
		// Modal opened in create mode (not copy) - ensure form is reset
		formData.name = ''
		formData.description = ''
		formData.startDatetime = ''
		formData.endDatetime = ''
		formData.visibleUsers = []
		formData.visibleGroups = []
		formData.visibleTeams = []
		sendNotification.value = false
		visibilityItems.value = []
		searchResults.value = []
		attachments.value = []
	}
})

const onStartDatetimeChange = (newValue) => {
	// newValue is a Date object from NcDateTimePickerNative
	formData.startDatetime = newValue ? formatDateTimeForInput(newValue.toISOString()) : ''
}

const onStartDatetimeBlur = () => {
	// Auto-fill end datetime if not set (only on blur)
	if (formData.startDatetime && !formData.endDatetime) {
		const startDate = new Date(formData.startDatetime)
		if (!isNaN(startDate.getTime())) {
			const endDate = new Date(startDate.getTime() + 2.5 * 60 * 60 * 1000)
			formData.endDatetime = formatDateTimeForInput(endDate.toISOString())
		}
	}
}

const onEndDatetimeChange = (newValue) => {
	// newValue is a Date object from NcDateTimePickerNative
	formData.endDatetime = newValue ? formatDateTimeForInput(newValue.toISOString()) : ''
}

const handleClose = () => {
	// If file picker is open, don't actually close
	if (filePickerOpen.value) {
		return
	}
	emit('close')
}

const onSearch = async (query) => {
	// Always keep selected items in the options list
	if (!query || query.length < 1) {
		searchResults.value = [...visibilityItems.value]
		return
	}

	isSearching.value = true
	try {
		const response = await axios.get(
			generateUrl('/apps/attendance/api/search/users-groups-teams'),
			{ params: { search: query } },
		)

		// Format response for NcSelect
		// Backend returns: { id, label, type, icon }
		// NcSelect expects: { id, value, label, type }
		// id combines type and original id since a user and group can have the same id
		const newResults = response.data.map(item => ({
			id: `${item.type}:${item.id}`,
			value: item.id,
			label: item.label,
			type: item.type,
		}))

		// Merge search results with already selected items to prevent them from disappearing
		const mergedResults = [...visibilityItems.value]

		// Add new search results that aren't already selected
		// Compare by id which now includes type prefix (e.g., "user:admin" vs "group:admin")
		for (const result of newResults) {
			const isAlreadySelected = visibilityItems.value.some(
				item => item.id === result.id,
			)
			if (!isAlreadySelected) {
				mergedResults.push(result)
			}
		}

		searchResults.value = mergedResults
	} catch (error) {
		console.error('Failed to search users/groups:', error)
		searchResults.value = [...visibilityItems.value]
	} finally {
		isSearching.value = false
	}
}

// File picker for attachments
const openFilePicker = async () => {
	// Set flag BEFORE any async operations
	filePickerOpen.value = true

	// Use nextTick to ensure the flag is set before the picker opens
	await nextTick()

	try {
		const builder = getFilePickerBuilder(t('attendance', 'Choose files to attach'))
			.setMultiSelect(true)
			.allowDirectories(true)
			.addButton({
				label: t('attendance', 'Attach'),
				callback: async (nodes) => {
					for (const node of nodes) {
						await addAttachment(node)
					}
				},
			})

		const picker = builder.build()
		await picker.pick()
	} catch (error) {
		// User cancelled the picker
		console.debug('File picker cancelled:', error)
	} finally {
		// Delay resetting the flag to ensure close events are properly blocked
		setTimeout(() => {
			filePickerOpen.value = false
		}, 100)
	}
}

// Add attachment (for new appointments, just add to local list; for editing, call API)
const addAttachment = async (node) => {
	// Check if already added
	if (attachments.value.some(a => a.fileId === node.fileid)) {
		return
	}

	const attachment = {
		fileId: node.fileid,
		fileName: node.basename,
		filePath: node.path,
	}

	// If editing existing appointment, call API immediately
	if (props.appointment?.id) {
		try {
			const response = await axios.post(
				generateUrl(`/apps/attendance/api/appointments/${props.appointment.id}/attachments`),
				{ fileId: node.fileid },
			)
			attachments.value.push(response.data)
			showSuccess(t('attendance', 'Attachment added'))
		} catch (error) {
			console.error('Failed to add attachment:', error)
			showError(t('attendance', 'Failed to add attachment'))
		}
	} else {
		// For new appointments, just add to local list
		attachments.value.push(attachment)
	}
}

// Remove attachment
const removeAttachment = async (fileId) => {
	// If editing existing appointment, call API immediately
	if (props.appointment?.id) {
		try {
			await axios.delete(
				generateUrl(`/apps/attendance/api/appointments/${props.appointment.id}/attachments/${fileId}`),
			)
			attachments.value = attachments.value.filter(a => a.fileId !== fileId)
			showSuccess(t('attendance', 'Attachment removed'))
		} catch (error) {
			console.error('Failed to remove attachment:', error)
			showError(t('attendance', 'Failed to remove attachment'))
		}
	} else {
		// For new appointments, just remove from local list
		attachments.value = attachments.value.filter(a => a.fileId !== fileId)
	}
}

const handleSubmit = () => {
	emit('submit', {
		id: props.appointment?.id,
		name: formData.name,
		description: formData.description,
		startDatetime: formData.startDatetime,
		endDatetime: formData.endDatetime,
		visibleUsers: formData.visibleUsers,
		visibleGroups: formData.visibleGroups,
		visibleTeams: formData.visibleTeams,
		sendNotification: sendNotification.value,
		attachmentFileIds: attachments.value.map(a => a.fileId),
	})
}
</script>

<style scoped lang="scss">
.modal-content {
	padding: 20px;
	min-width: min(500px, 90vw);
	max-height: 90vh;
	overflow-y: auto;

	h2 {
		margin: 0 0 5px 0;
	}

	form {
		display: flex;
		flex-direction: column;
		gap: 15px;
	}

	.datetime-fields {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 16px;

		@media (max-width: 600px) {
			grid-template-columns: 1fr;
		}
	}

	.form-field {
		display: flex;
		flex-direction: column;
		gap: 5px;

		label {
			font-weight: 600;
			font-size: 14px;
			color: var(--color-main-text);
		}

		.hint-text {
			font-size: 12px;
			color: var(--color-text-maxcontrast);
			margin: 0 0 5px 0;
		}

		.visibility-warning {
			margin-top: 8px;

			a {
				text-decoration: underline;
				color: inherit;
			}
		}

		.attachment-list {
			display: flex;
			flex-wrap: wrap;
			gap: 4px;
			min-height: 32px;
			padding: 4px 0;

			.no-attachments {
				color: var(--color-text-maxcontrast);
				font-size: 13px;
			}
		}
	}

	.form-actions {
		display: flex;
		justify-content: flex-end;
		gap: 10px;
		margin-top: 10px;
	}

	@media (max-width: 768px) {
		min-width: unset !important;
		width: 100% !important;
		padding: 15px !important;

		h2 {
			font-size: 18px;
			margin-bottom: 5px;
		}

		form {
			gap: 12px;
		}

		.form-actions {
			flex-direction: column-reverse;

			:deep(button) {
				width: 100%;
			}
		}
	}
}
</style>
