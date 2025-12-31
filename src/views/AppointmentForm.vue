<template>
	<div class="appointment-form-view" data-test="appointment-form-view">
		<div class="form-header">
			<NcButton variant="tertiary" data-test="button-back" @click="goBack">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				{{ t('attendance', 'Back') }}
			</NcButton>
			<h2 data-test="form-title">
				{{ pageTitle }}
			</h2>
		</div>

		<div v-if="loading" class="loading-state">
			{{ t('attendance', 'Loading...') }}
		</div>

		<form v-else
			class="appointment-form"
			data-test="appointment-form"
			@submit.prevent="handleSubmit">
			<div class="form-section">
				<NcTextField
					v-model="formData.name"
					:label="t('attendance', 'Appointment Name')"
					data-test="input-appointment-name" />

				<MarkdownEditor
					v-model="formData.description"
					:label="t('attendance', 'Description')"
					:placeholder="t('attendance', 'Write your description here...')"
					data-test="input-appointment-description"
					min-height="150px" />
			</div>

			<div class="form-section">
				<h3>{{ t('attendance', 'Date & Time') }}</h3>
				<div class="datetime-fields">
					<NcDateTimePickerNative
						id="start-datetime"
						:model-value="startDateObject"
						type="datetime-local"
						:label="t('attendance', 'Start Date & Time')"
						data-test="input-start-datetime"
						@update:model-value="onStartDatetimeChange"
						@blur="onStartDatetimeBlur" />

					<NcDateTimePickerNative
						id="end-datetime"
						:model-value="endDateObject"
						type="datetime-local"
						:label="t('attendance', 'End Date & Time')"
						data-test="input-end-datetime"
						@update:model-value="onEndDatetimeChange" />
				</div>
			</div>

			<div v-if="notificationsAppEnabled && mode === 'create'" class="form-section">
				<h3>{{ t('attendance', 'Notification') }}</h3>
				<p class="hint-text">
					{{ t('attendance', 'Notify users who can see this appointment about its creation') }}
				</p>
				<NcCheckboxRadioSwitch
					v-model="sendNotification"
					data-test="checkbox-send-notification">
					{{ t('attendance', 'Send notification') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div class="form-section">
				<h3>{{ t('attendance', 'Attachments') }}</h3>
				<p class="hint-text">
					{{ t('attendance', 'Files that are important for this appointment can be selected here.') }}
				</p>
				<div v-if="attachments.length > 0" class="attachment-list" data-test="attachment-list">
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
				</div>
				<NcButton variant="secondary"
					native-type="button"
					data-test="button-add-attachment"
					@click.stop.prevent="openFilePicker">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('attendance', 'Add from Files') }}
				</NcButton>
			</div>

			<div class="form-section">
				<h3>{{ t('attendance', 'Restrict Access') }}</h3>
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
					:placeholder="t('attendance', 'Search users or groups...')"
					data-test="select-visibility"
					@search="onSearch">
					<template #option="{ label, type }">
						<span style="display: flex; align-items: center; gap: 8px;">
							<AccountGroup v-if="type === 'group'" :size="20" />
							<Account v-else :size="20" />
							<span>{{ label }}</span>
						</span>
					</template>
					<template #selected-option="{ label, type }">
						<span style="display: flex; align-items: center; gap: 8px;">
							<AccountGroup v-if="type === 'group'" :size="16" />
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
				<NcButton variant="secondary" data-test="button-cancel" @click="goBack">
					{{ t('attendance', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary"
					:disabled="saving"
					data-test="button-save"
					@click="handleSubmit">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('attendance', 'Save') }}
				</NcButton>
			</div>
		</form>
	</div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { NcButton, NcTextField, NcSelect, NcNoteCard, NcCheckboxRadioSwitch, NcChip, NcLoadingIcon, NcDateTimePickerNative } from '@nextcloud/vue'
import { getFilePickerBuilder, showSuccess, showError } from '@nextcloud/dialogs'
import MarkdownEditor from '../components/common/MarkdownEditor.vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Account from 'vue-material-design-icons/Account.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import '@nextcloud/dialogs/style.css'

const props = defineProps({
	mode: {
		type: String,
		required: true,
		validator: (value) => ['create', 'edit', 'copy'].includes(value),
	},
	appointmentId: {
		type: Number,
		default: null,
	},
	notificationsAppEnabled: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits(['saved', 'cancelled'])

// Start with loading=true for edit/copy modes to prevent form flicker
const loading = ref(props.mode === 'edit' || props.mode === 'copy')
const saving = ref(false)

const formData = reactive({
	name: '',
	description: '',
	startDatetime: '',
	endDatetime: '',
	visibleUsers: [],
	visibleGroups: [],
})

const visibilityItems = ref([])
const searchResults = ref([])
const isSearching = ref(false)
const sendNotification = ref(false)
const trackingGroups = ref([])
const attachments = ref([])

const pageTitle = computed(() => {
	switch (props.mode) {
	case 'edit':
		return t('attendance', 'Edit Appointment')
	case 'copy':
		return t('attendance', 'Copy Appointment')
	default:
		return t('attendance', 'Create Appointment')
	}
})

const hasTrackingMismatch = computed(() => {
	if (formData.visibleGroups.length === 0 && formData.visibleUsers.length === 0) {
		return false
	}
	if (trackingGroups.value.length === 0) {
		return false
	}
	const hasOverlappingGroup = formData.visibleGroups.some(
		groupId => trackingGroups.value.includes(groupId),
	)
	return !hasOverlappingGroup
})

const adminSettingsUrl = computed(() => {
	return generateUrl('/settings/admin/attendance')
})

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

// Watch for changes to visibilityItems to update formData
watch(visibilityItems, (selected) => {
	const selectedArray = Array.isArray(selected) ? selected : (selected ? [selected] : [])
	formData.visibleUsers = selectedArray.filter(item => item && item.type === 'user').map(item => item.value)
	formData.visibleGroups = selectedArray.filter(item => item && item.type === 'group').map(item => item.value)
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

const loadAppointment = async () => {
	if (!props.appointmentId) return

	loading.value = true
	try {
		const response = await axios.get(generateUrl(`/apps/attendance/api/appointments/${props.appointmentId}`))
		const appointment = response.data

		formData.name = props.mode === 'copy'
			? `${appointment.name} (${t('attendance', 'Copy')})`
			: appointment.name
		formData.description = appointment.description || ''

		// For copy mode, leave dates empty
		if (props.mode === 'copy') {
			formData.startDatetime = ''
			formData.endDatetime = ''
		} else {
			formData.startDatetime = formatDateTimeForInput(appointment.startDatetime)
			formData.endDatetime = formatDateTimeForInput(appointment.endDatetime)
		}

		// Load visibility settings
		const users = appointment.visibleUsers || []
		const groups = appointment.visibleGroups || []
		formData.visibleUsers = users
		formData.visibleGroups = groups

		// Convert to visibility items for NcSelect
		const items = []
		for (const userId of users) {
			items.push({ id: `user:${userId}`, value: userId, label: userId, type: 'user' })
		}
		for (const groupId of groups) {
			items.push({ id: `group:${groupId}`, value: groupId, label: groupId, type: 'group' })
		}
		searchResults.value = [...items]
		visibilityItems.value = [...items]

		// Load attachments
		attachments.value = (appointment.attachments || []).map(a => ({
			fileId: a.fileId,
			fileName: a.fileName,
			filePath: a.filePath,
		}))

		// Fetch display names in background
		await loadDisplayNames(users, groups)
	} catch (error) {
		console.error('Failed to load appointment:', error)
		showError(t('attendance', 'Error loading appointment'))
	} finally {
		loading.value = false
	}
}

const loadDisplayNames = async (users, groups) => {
	if (users.length === 0 && groups.length === 0) return

	try {
		const updatedItems = []
		for (const userId of users) {
			const response = await axios.get(
				generateUrl('/apps/attendance/api/search/users-groups'),
				{ params: { search: userId } },
			)
			const found = response.data.find(item => item.id === userId && item.type === 'user')
			if (found) {
				updatedItems.push({
					id: `user:${found.id}`,
					value: found.id,
					label: found.label,
					type: 'user',
				})
			} else {
				updatedItems.push({ id: `user:${userId}`, value: userId, label: userId, type: 'user' })
			}
		}
		for (const groupId of groups) {
			const response = await axios.get(
				generateUrl('/apps/attendance/api/search/users-groups'),
				{ params: { search: groupId } },
			)
			const found = response.data.find(item => item.id === groupId && item.type === 'group')
			if (found) {
				updatedItems.push({
					id: `group:${found.id}`,
					value: found.id,
					label: found.label,
					type: 'group',
				})
			} else {
				updatedItems.push({ id: `group:${groupId}`, value: groupId, label: groupId, type: 'group' })
			}
		}
		searchResults.value = [...updatedItems]
		visibilityItems.value = [...updatedItems]
	} catch (error) {
		console.error('Failed to load display names:', error)
	}
}

const loadTrackingGroups = async () => {
	try {
		const response = await axios.get(generateUrl('/apps/attendance/api/admin/settings'))
		if (response.data.success && response.data.whitelistedGroups) {
			trackingGroups.value = response.data.whitelistedGroups
		}
	} catch (error) {
		console.debug('Could not load tracking groups:', error)
	}
}

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

const goBack = () => {
	emit('cancelled')
}

const onSearch = async (query) => {
	if (!query || query.length < 1) {
		searchResults.value = [...visibilityItems.value]
		return
	}

	isSearching.value = true
	try {
		const response = await axios.get(
			generateUrl('/apps/attendance/api/search/users-groups'),
			{ params: { search: query } },
		)

		const newResults = response.data.map(item => ({
			id: `${item.type}:${item.id}`,
			value: item.id,
			label: item.label,
			type: item.type,
		}))

		const mergedResults = [...visibilityItems.value]
		for (const result of newResults) {
			const isAlreadySelected = visibilityItems.value.some(item => item.id === result.id)
			if (!isAlreadySelected) {
				mergedResults.push(result)
			}
		}

		searchResults.value = mergedResults
	} catch (error) {
		console.error('Failed to search:', error)
		searchResults.value = [...visibilityItems.value]
	} finally {
		isSearching.value = false
	}
}

const openFilePicker = async () => {
	try {
		const picker = getFilePickerBuilder(t('attendance', 'Choose files to attach'))
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
			.build()
		await picker.pick()
	} catch (error) {
		console.debug('File picker cancelled:', error)
	}
}

const addAttachment = async (node) => {
	if (attachments.value.some(a => a.fileId === node.fileid)) {
		return
	}

	const attachment = {
		fileId: node.fileid,
		fileName: node.basename,
		filePath: node.path,
	}

	// If editing existing appointment, call API immediately
	if (props.mode === 'edit' && props.appointmentId) {
		try {
			const response = await axios.post(
				generateUrl(`/apps/attendance/api/appointments/${props.appointmentId}/attachments`),
				{ fileId: node.fileid },
			)
			attachments.value.push(response.data)
			showSuccess(t('attendance', 'Attachment added'))
		} catch (error) {
			console.error('Failed to add attachment:', error)
			showError(t('attendance', 'Failed to add attachment'))
		}
	} else {
		attachments.value.push(attachment)
	}
}

const removeAttachment = async (fileId) => {
	if (props.mode === 'edit' && props.appointmentId) {
		try {
			await axios.delete(
				generateUrl(`/apps/attendance/api/appointments/${props.appointmentId}/attachments/${fileId}`),
			)
			attachments.value = attachments.value.filter(a => a.fileId !== fileId)
			showSuccess(t('attendance', 'Attachment removed'))
		} catch (error) {
			console.error('Failed to remove attachment:', error)
			showError(t('attendance', 'Failed to remove attachment'))
		}
	} else {
		attachments.value = attachments.value.filter(a => a.fileId !== fileId)
	}
}

const toServerTimezone = (datetime) => {
	if (!datetime) return datetime
	const date = new Date(datetime)
	return date.toISOString()
}

const handleSubmit = async () => {
	// Manual validation for datetime fields
	if (!formData.name?.trim()) {
		showError(t('attendance', 'Please enter an appointment name'))
		return
	}
	if (!formData.startDatetime) {
		showError(t('attendance', 'Please select a start date and time'))
		return
	}
	if (!formData.endDatetime) {
		showError(t('attendance', 'Please select an end date and time'))
		return
	}

	saving.value = true

	try {
		const startDatetimeWithTz = toServerTimezone(formData.startDatetime)
		const endDatetimeWithTz = toServerTimezone(formData.endDatetime)

		let appointmentId = props.appointmentId

		if (props.mode === 'edit') {
			// Update existing appointment
			await axios.put(generateUrl(`/apps/attendance/api/appointments/${props.appointmentId}`), {
				name: formData.name,
				description: formData.description,
				startDatetime: startDatetimeWithTz,
				endDatetime: endDatetimeWithTz,
				visibleUsers: formData.visibleUsers || [],
				visibleGroups: formData.visibleGroups || [],
			})
			showSuccess(t('attendance', 'Appointment updated successfully'))
		} else {
			// Create new appointment (or copy)
			const response = await axios.post(generateUrl('/apps/attendance/api/appointments'), {
				name: formData.name,
				description: formData.description,
				startDatetime: startDatetimeWithTz,
				endDatetime: endDatetimeWithTz,
				visibleUsers: formData.visibleUsers || [],
				visibleGroups: formData.visibleGroups || [],
				sendNotification: sendNotification.value,
			})
			appointmentId = response.data?.id

			// Add attachments for new/copied appointments
			if (appointmentId && attachments.value.length > 0) {
				for (const attachment of attachments.value) {
					try {
						await axios.post(
							generateUrl(`/apps/attendance/api/appointments/${appointmentId}/attachments`),
							{ fileId: attachment.fileId },
						)
					} catch (attachError) {
						console.error('Failed to add attachment:', attachError)
					}
				}
			}
			showSuccess(t('attendance', 'Appointment created successfully'))
		}

		emit('saved', appointmentId)
	} catch (error) {
		console.error('Failed to save appointment:', error)
		showError(props.mode === 'edit'
			? t('attendance', 'Error updating appointment')
			: t('attendance', 'Error creating appointment'))
	} finally {
		saving.value = false
	}
}

onMounted(async () => {
	await loadTrackingGroups()
	if (props.mode === 'edit' || props.mode === 'copy') {
		await loadAppointment()
	}
})
</script>

<style scoped lang="scss">
.appointment-form-view {
	padding: 20px;
	max-width: 800px;
	margin: 0 auto;
}

.form-header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 24px;

	h2 {
		margin: 0;
	}
}

.loading-state {
	text-align: center;
	padding: 40px;
	color: var(--color-text-lighter);
}

.appointment-form {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.form-section {
	display: flex;
	flex-direction: column;
	gap: 12px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;

	h3 {
		margin: 0 0 4px 0;
		font-size: 16px;
		font-weight: 600;
	}

	.hint-text {
		font-size: 12px;
		color: var(--color-text-maxcontrast);
		margin: 0 0 8px 0;
	}

	.visibility-warning {
		margin-top: 12px;

		a {
			text-decoration: underline;
			color: inherit;
		}
	}
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

}

.attachment-list {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	min-height: 32px;
	padding: 4px 0;
	margin-bottom: 8px;

	.no-attachments {
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}
}

.form-actions {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}
</style>
