<template>
	<div id="attendance-admin-settings">
		<NcSettingsSection :name="t('attendance', 'Attendance')"
			:description="t('attendance', 'Configure attendance management and reminders')">
		</NcSettingsSection>

		<div v-if="loadingData" class="loading-section">
			<NcLoadingIcon :size="32" />
			<p>{{ t('attendance', 'Loading settings...') }}</p>
		</div>

		<template v-else>
			<NcSettingsSection :name="t('attendance', 'Whitelisted Groups')"
				:description="t('attendance', 'Select user groups that should be included in attendance statistics and check-in list filters. If no groups are selected, all groups are included.')">
				<NcSelect
					v-model="selectedGroups"
					:options="availableGroups"
					:placeholder="t('attendance', 'Select groups...')"
					:multiple="true"
					:disabled="loading"
					label="displayName"
					track-by="id"
					@input="onGroupSelectionChange" />
				<p class="hint-text">
					{{ n('attendance', '%n group selected', '%n groups selected', selectedGroups.length, { n: selectedGroups.length }) }}
				</p>
			</NcSettingsSection>

			<NcSettingsSection :name="t('attendance', 'Permissions')"
				:description="t('attendance', 'Configure which groups can perform specific actions. Users must belong to at least one of the selected groups to access the feature. If no group is selected, all users have access to the feature.')">
				
				<div class="subsection">
					<h4>{{ t('attendance', 'Manage Appointments') }}</h4>
					<p class="subsection-hint">
						{{ t('attendance', 'Groups that can create, update, and delete appointments') }}
					</p>
					<NcSelect
						v-model="selectedManageAppointmentsRoles"
						:options="availableGroups"
						:placeholder="t('attendance', 'Select groups...')"
						:multiple="true"
						:disabled="loading"
						label="displayName"
						track-by="id"
						@input="onManageAppointmentsRolesChange" />
					<p class="hint-text">
						{{ n('attendance', '%n group selected', '%n groups selected', selectedManageAppointmentsRoles.length, { n: selectedManageAppointmentsRoles.length }) }}
					</p>
				</div>

				<div class="subsection">
					<h4>{{ t('attendance', 'Check-in Access') }}</h4>
					<p class="subsection-hint">
						{{ t('attendance', 'Groups that can access the check-in interface and execute check-ins') }}
					</p>
					<NcSelect
						v-model="selectedCheckinRoles"
						:options="availableGroups"
						:placeholder="t('attendance', 'Select groups...')"
						:multiple="true"
						:disabled="loading"
						label="displayName"
						track-by="id"
						@input="onCheckinRolesChange" />
					<p class="hint-text">
						{{ n('attendance', '%n group selected', '%n groups selected', selectedCheckinRoles.length, { n: selectedCheckinRoles.length }) }}
					</p>
				</div>

				<div class="subsection">
					<h4>{{ t('attendance', 'See Response Overview') }}</h4>
					<p class="subsection-hint">
						{{ t('attendance', 'Groups that can see the response overview with details') }}
					</p>
					<NcSelect
						v-model="selectedSeeResponseOverviewRoles"
						:options="availableGroups"
						:placeholder="t('attendance', 'Select groups...')"
						:multiple="true"
						:disabled="loading"
						label="displayName"
						track-by="id"
						@input="onSeeResponseOverviewRolesChange" />
					<p class="hint-text">
						{{ n('attendance', '%n group selected', '%n groups selected', selectedSeeResponseOverviewRoles.length, { n: selectedSeeResponseOverviewRoles.length }) }}
					</p>
				</div>

				<div class="subsection">
					<h4>{{ t('attendance', 'See Comments') }}</h4>
					<p class="subsection-hint">
						{{ t('attendance', 'Groups that can see comments in the response overview') }}
					</p>
					<NcSelect
						v-model="selectedSeeCommentsRoles"
						:options="availableGroups"
						:placeholder="t('attendance', 'Select groups...')"
						:multiple="true"
						:disabled="loading"
						label="displayName"
						track-by="id"
						@input="onSeeCommentsRolesChange" />
					<p class="hint-text">
						{{ n('attendance', '%n group selected', '%n groups selected', selectedSeeCommentsRoles.length, { n: selectedSeeCommentsRoles.length }) }}
					</p>
				</div>
			</NcSettingsSection>

			<NcSettingsSection :name="t('attendance', 'Appointment Reminders')"
				:description="t('attendance', 'Send automatic reminder notifications to users who haven\'t responded to appointments.')">
				
				<NcNoteCard v-if="!notificationsAppEnabled" type="warning">
					<p>{{ t('attendance', 'The Notifications app is not enabled. Please enable it to use appointment reminders.') }}</p>
					<p class="hint-text">
						{{ t('attendance', 'You can enable it in the Apps section of your Nextcloud settings.') }}
					</p>
				</NcNoteCard>

				<template v-else>
					<NcCheckboxRadioSwitch
						v-model="remindersEnabled"
						type="switch">
						{{ t('attendance', 'Enable automatic reminders') }}
					</NcCheckboxRadioSwitch>

					<div v-if="remindersEnabled" class="reminder-config">
						<NcInputField
							v-model.number="reminderDays"
							type="number"
							:label="t('attendance', 'Days before appointment')"
							:helper-text="t('attendance', 'Send reminders this many days before the appointment (1-30 days)')"
							:input-props="{ min: 1, max: 30 }" />
						
						<NcInputField
							v-model.number="reminderFrequency"
							type="number"
							class="reminder-frequency-field"
							:label="t('attendance', 'Reminder frequency (days)')"
							:helper-text="t('attendance', 'How often to remind users who haven\'t responded. Set to 0 to only remind once, or 1-30 to repeat reminders every N days.')"
							:input-props="{ min: 0, max: 30 }" />
					</div>
				</template>
			</NcSettingsSection>

			<NcSettingsSection>
				<NcButton
					type="primary"
					:disabled="loading"
					@click="saveSettings">
					<template #icon>
						<NcLoadingIcon v-if="loading" />
					</template>
					{{ t('attendance', 'Save') }}
				</NcButton>
			</NcSettingsSection>
		</template>
	</div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { 
	NcSelect, 
	NcButton, 
	NcLoadingIcon, 
	NcSettingsSection,
	NcCheckboxRadioSwitch,
	NcInputField,
	NcNoteCard
} from '@nextcloud/vue'

// State
const availableGroups = ref([])
const selectedGroups = ref([])
const selectedManageAppointmentsRoles = ref([])
const selectedCheckinRoles = ref([])
const selectedSeeResponseOverviewRoles = ref([])
const selectedSeeCommentsRoles = ref([])
const remindersEnabled = ref(false)
const reminderDays = ref(7)
const reminderFrequency = ref(0)
const notificationsAppEnabled = ref(true)
const loading = ref(false)
const loadingData = ref(true)

// Methods
const loadSettings = async () => {
	loadingData.value = true

	try {
		const response = await axios.get(
			generateUrl('/apps/attendance/api/admin/settings')
		)

		if (response.data.success) {
			availableGroups.value = response.data.groups
			// Convert selected IDs to selected group objects for NcSelect
			selectedGroups.value = response.data.groups.filter(group => 
				response.data.whitelistedGroups.includes(group.id)
			)
			
			// Load permission settings
			if (response.data.permissions) {
				selectedManageAppointmentsRoles.value = response.data.groups.filter(group => 
					response.data.permissions.manage_appointments.includes(group.id)
				)
				selectedCheckinRoles.value = response.data.groups.filter(group => 
					response.data.permissions.checkin.includes(group.id)
				)
				selectedSeeResponseOverviewRoles.value = response.data.groups.filter(group => 
					response.data.permissions.see_response_overview?.includes(group.id)
				)
				selectedSeeCommentsRoles.value = response.data.groups.filter(group => 
					response.data.permissions.see_comments?.includes(group.id)
				)
			}
			
			// Load reminder settings
			if (response.data.reminders) {
				remindersEnabled.value = response.data.reminders.enabled || false
				reminderDays.value = response.data.reminders.reminderDays || 7
				reminderFrequency.value = response.data.reminders.reminderFrequency || 0
				notificationsAppEnabled.value = response.data.reminders.notificationsAppEnabled !== false
			}
		} else {
			showError(window.t('attendance', 'Failed to load settings') + 
				(response.data.error ? ': ' + response.data.error : ''))
		}
	} catch (error) {
		console.error('Error loading settings:', error)
		showError(window.t('attendance', 'Failed to load settings'))
	} finally {
		loadingData.value = false
	}
}

const onGroupSelectionChange = (selected) => {
	selectedGroups.value = selected || []
}

const onManageAppointmentsRolesChange = (selected) => {
	selectedManageAppointmentsRoles.value = selected || []
}

const onCheckinRolesChange = (selected) => {
	selectedCheckinRoles.value = selected || []
}

const onSeeResponseOverviewRolesChange = (selected) => {
	selectedSeeResponseOverviewRoles.value = selected || []
}

const onSeeCommentsRolesChange = (selected) => {
	selectedSeeCommentsRoles.value = selected || []
}

const saveSettings = async () => {
	loading.value = true

	try {
		// Convert selected group objects to IDs for API
		const selectedGroupIds = selectedGroups.value.map(group => group.id)
		
		const response = await axios.post(
			generateUrl('/apps/attendance/api/admin/settings'),
			{
				whitelistedGroups: selectedGroups.value.map(g => g.id),
				permissions: {
					PERMISSION_MANAGE_APPOINTMENTS: selectedManageAppointmentsRoles.value.map(g => g.id),
					PERMISSION_CHECKIN: selectedCheckinRoles.value.map(g => g.id),
					PERMISSION_SEE_RESPONSE_OVERVIEW: selectedSeeResponseOverviewRoles.value.map(g => g.id),
					PERMISSION_SEE_COMMENTS: selectedSeeCommentsRoles.value.map(g => g.id)
				},
				reminders: {
					enabled: remindersEnabled.value,
					reminderDays: reminderDays.value,
					reminderFrequency: reminderFrequency.value
				}
			}
		)

		if (response.data.success) {
			showSuccess(window.t('attendance', 'Settings saved successfully'))
		} else {
			showError(window.t('attendance', 'Failed to save settings') + 
				(response.data.error ? ': ' + response.data.error : ''))
		}
	} catch (error) {
		console.error('Error saving settings:', error)
		showError(window.t('attendance', 'Failed to save settings'))
	} finally {
		loading.value = false
	}
}

// Lifecycle
onMounted(async () => {
	await loadSettings()
})
</script>

<style scoped>
#attendance-admin-settings {
	padding: 20px;
	max-width: 900px;
}

.loading-section {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	padding: 40px;
	color: var(--color-text-maxcontrast);
}

.hint-text {
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.subsection {
	margin: 24px 0;
	padding-bottom: 24px;
	border-bottom: 1px solid var(--color-border);
}

.subsection:last-child {
	border-bottom: none;
}

.subsection h4 {
	margin: 0 0 4px 0;
	font-size: 15px;
	font-weight: 600;
}

.subsection-hint {
	margin: 0 0 12px 0;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.reminder-config {
	margin-top: 16px;
	margin-bottom: 16px;
	max-width: 300px;
}

.input-field.reminder-frequency-field {
	margin-block-start: 40px;
}
</style>
