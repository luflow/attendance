<template>
	<NcModal v-if="show" @close="handleClose" data-test="appointment-form-modal">
		<div class="modal-content">
			<h2 data-test="form-title">{{ isEdit ? t('attendance', 'Edit Appointment') : t('attendance', 'Create Appointment') }}</h2>
			<form @submit.prevent="handleSubmit" data-test="appointment-form">
				<NcTextField 
					v-model="formData.name" 
					:label="t('attendance', 'Appointment Name')"
					data-test="input-appointment-name"
					required />
				
				<NcTextArea 
					v-model="formData.description" 
					:label="t('attendance', 'Description')"
					:placeholder="t('attendance', 'You can use **bold** and *italic* formatting')"
					data-test="input-appointment-description"
					:rows="6" />
				
				<div class="form-field">
					<label for="start-datetime">{{ t('attendance', 'Start Date & Time') }}</label>
					<input 
						id="start-datetime"
						v-model="formData.startDatetime"
						type="datetime-local" 
						data-test="input-start-datetime"
						required 
						@blur="handleStartDateBlur" />
				</div>
				
				<div class="form-field">
					<label for="end-datetime">{{ t('attendance', 'End Date & Time') }}</label>
					<input 
						id="end-datetime"
						ref="endDatetimePicker"
						v-model="formData.endDatetime"
						type="datetime-local" 
						data-test="input-end-datetime"
						required />
				</div>
				
				<div class="form-field">
					<label>{{ t('attendance', 'Restrict Access') }}</label>
					<p class="hint-text">{{ t('attendance', 'Controls who can see and respond to this appointment. Leave empty to allow all users.') }}</p>
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
					<NcButton type="secondary" @click="handleClose" data-test="button-cancel">
						{{ t('attendance', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" native-type="submit" data-test="button-save">
						{{ t('attendance', 'Save') }}
					</NcButton>
				</div>
			</form>
		</div>
	</NcModal>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { NcModal, NcButton, NcTextField, NcTextArea, NcSelect, NcNoteCard } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Account from 'vue-material-design-icons/Account.vue'

const props = defineProps({
	show: {
		type: Boolean,
		required: true,
	},
	appointment: {
		type: Object,
		default: null,
	},
})

const emit = defineEmits(['close', 'submit'])

const endDatetimePicker = ref(null)

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
const trackingGroups = ref([])

// Fetch tracking groups from admin settings
const loadTrackingGroups = async () => {
	try {
		const response = await axios.get(
			generateUrl('/apps/attendance/api/admin/settings')
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
	// No mismatch if no visibility restriction is set
	if (formData.visibleGroups.length === 0 && formData.visibleUsers.length === 0) {
		return false
	}
	// No mismatch if no tracking groups are configured (all groups are tracked)
	if (trackingGroups.value.length === 0) {
		return false
	}
	// Check if any selected group is in the tracking groups
	const hasOverlappingGroup = formData.visibleGroups.some(
		groupId => trackingGroups.value.includes(groupId)
	)
	// If only users are selected (no groups), responses will go to "Others" unless users are in tracking groups
	// We can't easily check this, so show warning if no groups overlap
	return !hasOverlappingGroup
})

const adminSettingsUrl = computed(() => {
	return generateUrl('/settings/admin/attendance')
})

onMounted(() => {
	loadTrackingGroups()
})

// Watch for changes to visibilityItems to update formData
watch(visibilityItems, (selected) => {
	const selectedArray = Array.isArray(selected) ? selected : (selected ? [selected] : [])
	// Split into users and groups based on the type property
	// Use 'value' property which contains the original user/group ID
	formData.visibleUsers = selectedArray.filter(item => item && item.type === 'user').map(item => item.value)
	formData.visibleGroups = selectedArray.filter(item => item && item.type === 'group').map(item => item.value)
})

const isEdit = computed(() => !!props.appointment)

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
		
		// Load visibility settings
		const users = newAppointment.visibleUsers || []
		const groups = newAppointment.visibleGroups || []
		formData.visibleUsers = users
		formData.visibleGroups = groups
		
		// Convert to visibility items for NcSelect
		// Create items directly from stored IDs, then optionally fetch display names
		const items = []

		for (const userId of users) {
			items.push({ id: `user:${userId}`, value: userId, label: userId, type: 'user' })
		}
		for (const groupId of groups) {
			items.push({ id: `group:${groupId}`, value: groupId, label: groupId, type: 'group' })
		}

		// Set options first, then selected values
		searchResults.value = [...items]
		visibilityItems.value = [...items]

		// Optionally fetch display names in background
		if (items.length > 0) {
			try {
				const updatedItems = []
				for (const userId of users) {
					const response = await axios.get(
						generateUrl('/apps/attendance/api/search/users-groups'),
						{ params: { search: userId } }
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
						{ params: { search: groupId } }
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
				// Update with proper display names
				searchResults.value = [...updatedItems]
				visibilityItems.value = [...updatedItems]
			} catch (error) {
				console.error('Failed to load user/group display names:', error)
			}
		}
	} else {
		// Reset form for create
		formData.name = ''
		formData.description = ''
		formData.startDatetime = ''
		formData.endDatetime = ''
		formData.visibleUsers = []
		formData.visibleGroups = []
		visibilityItems.value = []
		searchResults.value = []
	}
}, { immediate: true })

// Reset form when modal opens in create mode
watch(() => props.show, (isShowing) => {
	if (isShowing && !props.appointment) {
		// Modal opened in create mode - ensure form is reset
		formData.name = ''
		formData.description = ''
		formData.startDatetime = ''
		formData.endDatetime = ''
		formData.visibleUsers = []
		formData.visibleGroups = []
		visibilityItems.value = []
		searchResults.value = []
	}
})

const handleStartDateBlur = () => {
	// Auto-set endDatetime if it's empty and startDatetime is set
	if (formData.startDatetime && !formData.endDatetime) {
		const startDate = new Date(formData.startDatetime)
		const endDate = new Date(startDate.getTime() + 2.5 * 60 * 60 * 1000) // Add 2.5 hours
		formData.endDatetime = formatDateTimeForInput(endDate.toISOString())
	}
}

const handleClose = () => {
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
			generateUrl('/apps/attendance/api/search/users-groups'),
			{ params: { search: query } }
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
				item => item.id === result.id
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

const handleSubmit = () => {
	emit('submit', {
		id: props.appointment?.id,
		name: formData.name,
		description: formData.description,
		startDatetime: formData.startDatetime,
		endDatetime: formData.endDatetime,
		visibleUsers: formData.visibleUsers,
		visibleGroups: formData.visibleGroups,
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
		margin-top: 0;
		margin-bottom: 20px;
	}
	
	form {
		display: flex;
		flex-direction: column;
		gap: 15px;
		
		// Override NcTextArea height
		:deep(.textarea__main-wrapper) {
			min-height: calc(var(--default-clickable-area) * 4);
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
		
		input[type="datetime-local"] {
			width: 100%;
			padding: 10px;
			border: 2px solid var(--color-border-dark);
			border-radius: var(--border-radius);
			background-color: var(--color-main-background);
			color: var(--color-main-text);
			font-size: 14px;
			font-family: inherit;
			
			&:focus {
				border-color: var(--color-primary-element);
				outline: none;
			}
			
			&:hover {
				border-color: var(--color-primary-element-light);
			}
		}
		
		.hint-text {
			font-size: 12px;
			color: var(--color-text-maxcontrast);
			margin: 5px 0;
		}

		.visibility-warning {
			margin-top: 8px;

			a {
				text-decoration: underline;
				color: inherit;
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
			margin-bottom: 15px;
		}
		
		form {
			gap: 12px;
		}
		
		.form-field {
			input[type="datetime-local"] {
				padding: 8px;
				font-size: 16px;
				max-width: 100%;
			}
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
