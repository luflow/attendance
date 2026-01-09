<template>
	<div id="attendance-admin-settings" data-test="admin-settings">
		<NcSettingsSection :name="t('attendance', 'Attendance')"
			:description="t('attendance', 'Configure attendance management and reminders')" />

		<div v-if="loadingData" class="loading-section">
			<NcLoadingIcon :size="32" />
			<p>{{ t('attendance', 'Loading settings …') }}</p>
		</div>

		<template v-else>
			<!-- TRANSLATORS: Admin settings section title. The "Response summary" is the main feature of this app - it shows attendance statistics on the appointment detail page, counting users by their Nextcloud group membership. Groups selected here will have their own sections in the summary; users not in these groups appear under "Others". -->
			<NcSettingsSection :name="t('attendance', 'Response summary groups')"
				:description="t('attendance', 'Select which groups to include in response summaries. Users outside these groups will appear under Others. Leave empty to include all groups.')"
				data-test="section-tracking-groups">
				<GroupSelect
					v-model="selectedGroups"
					:options="availableGroups"
					:placeholder="t('attendance', 'Select groups …')"
					:disabled="loading"
					data-test="select-whitelisted-groups" />
				<p class="hint-text">
					{{ n('attendance', '%n group selected', '%n groups selected', selectedGroups.length, { n: selectedGroups.length }) }}
				</p>
			</NcSettingsSection>

			<!-- TRANSLATORS: Admin settings section title. Similar to groups above, but for Nextcloud Teams (formerly Circles). Teams selected here will have their own sections in the attendance statistics on the appointment detail page, showing how many team members responded yes/no/maybe. -->
			<NcSettingsSection v-if="teamsAvailable" :name="t('attendance', 'Response summary teams')"
				:description="t('attendance', 'Select which teams to include in response summaries. Team members will be grouped together like regular groups.')"
				data-test="section-tracking-teams">
				<NcSelect
					v-model="selectedTeams"
					:options="teamSearchResults"
					:placeholder="t('attendance', 'Search and select teams …')"
					:multiple="true"
					:disabled="loading"
					:loading="isSearchingTeams"
					:filterable="false"
					label="label"
					track-by="id"
					data-test="select-whitelisted-teams"
					@search="searchTeams">
					<template #option="{ label }">
						<span style="display: flex; align-items: center; gap: 8px;">
							<AccountStar :size="20" />
							<span>{{ label }}</span>
						</span>
					</template>
					<template #selected-option="{ label }">
						<span style="display: flex; align-items: center; gap: 8px;">
							<AccountStar :size="16" />
							<span>{{ label }}</span>
						</span>
					</template>
				</NcSelect>
				<p class="hint-text">
					{{ n('attendance', '%n team selected', '%n teams selected', selectedTeams.length, { n: selectedTeams.length }) }}
				</p>
			</NcSettingsSection>

			<NcSettingsSection :name="t('attendance', 'Permissions')"
				:description="t('attendance', 'Configure which groups can perform specific actions. Users must belong to at least one of the selected groups to access the feature. If no group is selected, all users have access to the feature.')">
				<div class="subsection">
					<h4>{{ t('attendance', 'Manage appointments') }}</h4>
					<p class="subsection-hint">
						{{ t('attendance', 'Groups that can create, update, and delete appointments') }}
					</p>
					<GroupSelect
						v-model="selectedManageAppointmentsRoles"
						:options="availableGroups"
						:placeholder="t('attendance', 'Select groups …')"
						:disabled="loading"
						data-test="select-manage-appointments-roles" />
					<p class="hint-text">
						{{ n('attendance', '%n group selected', '%n groups selected', selectedManageAppointmentsRoles.length, { n: selectedManageAppointmentsRoles.length }) }}
					</p>
				</div>

				<div class="subsection">
					<h4>{{ t('attendance', 'Check-in access') }}</h4>
					<p class="subsection-hint">
						{{ t('attendance', 'Groups that can access the check-in interface and execute check-ins') }}
					</p>
					<GroupSelect
						v-model="selectedCheckinRoles"
						:options="availableGroups"
						:placeholder="t('attendance', 'Select groups …')"
						:disabled="loading"
						data-test="select-checkin-roles" />
					<p class="hint-text">
						{{ n('attendance', '%n group selected', '%n groups selected', selectedCheckinRoles.length, { n: selectedCheckinRoles.length }) }}
					</p>
				</div>

				<div class="subsection">
					<h4>{{ t('attendance', 'See response overview') }}</h4>
					<p class="subsection-hint">
						{{ t('attendance', 'Groups that can see the response overview with details') }}
					</p>
					<GroupSelect
						v-model="selectedSeeResponseOverviewRoles"
						:options="availableGroups"
						:placeholder="t('attendance', 'Select groups …')"
						:disabled="loading"
						data-test="select-see-response-overview-roles" />
					<p class="hint-text">
						{{ n('attendance', '%n group selected', '%n groups selected', selectedSeeResponseOverviewRoles.length, { n: selectedSeeResponseOverviewRoles.length }) }}
					</p>
				</div>

				<div class="subsection">
					<h4>{{ t('attendance', 'See comments') }}</h4>
					<p class="subsection-hint">
						{{ t('attendance', 'Groups that can see comments in the response overview') }}
					</p>
					<GroupSelect
						v-model="selectedSeeCommentsRoles"
						:options="availableGroups"
						:placeholder="t('attendance', 'Select groups …')"
						:disabled="loading"
						data-test="select-see-comments-roles" />
					<p class="hint-text">
						{{ n('attendance', '%n group selected', '%n groups selected', selectedSeeCommentsRoles.length, { n: selectedSeeCommentsRoles.length }) }}
					</p>
				</div>
			</NcSettingsSection>

			<NcSettingsSection :name="t('attendance', 'Appointment reminders')"
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
						type="switch"
						data-test="switch-reminders-enabled">
						{{ t('attendance', 'Enable automatic reminders') }}
					</NcCheckboxRadioSwitch>

					<div v-if="remindersEnabled" class="reminder-config">
						<NcInputField
							v-model.number="reminderDays"
							type="number"
							:label="t('attendance', 'Days before appointment')"
							:helper-text="t('attendance', 'Send reminders this many days before the appointment (1-30 days)')"
							data-test="input-reminder-days"
							:input-props="{ min: 1, max: 30 }" />

						<NcInputField
							v-model.number="reminderFrequency"
							type="number"
							class="reminder-frequency-field"
							:label="t('attendance', 'Reminder frequency (days)')"
							:helper-text="t('attendance', 'How often to remind users who haven\'t responded. Set to 0 to only remind once, or 1-30 to repeat reminders every N days.')"
							data-test="input-reminder-frequency"
							:input-props="{ min: 0, max: 30 }" />
					</div>
				</template>
			</NcSettingsSection>

			<div id="calendar-sync">
				<NcSettingsSection
					:name="t('attendance', 'Calendar sync')"
					:description="t('attendance', 'Automatically update attendance appointments when their linked calendar events are modified.')">
					<NcNoteCard v-if="!calendarSyncAvailable" type="warning">
						<p>{{ t('attendance', 'Calendar sync requires Nextcloud 32 or newer.') }}</p>
						<p class="hint-text">
							{{ t('attendance', 'This feature uses calendar event hooks that are only available in Nextcloud 32 and later versions.') }}
						</p>
					</NcNoteCard>

					<template v-else>
						<NcCheckboxRadioSwitch
							v-model="calendarSyncEnabled"
							type="switch"
							data-test="switch-calendar-sync-enabled">
							{{ t('attendance', 'Enable automatic calendar sync') }}
						</NcCheckboxRadioSwitch>
						<p class="hint-text">
							{{ t('attendance', 'When enabled, changes to calendar events will automatically update linked attendance appointments (title, description, date/time).') }}
						</p>
					</template>
				</NcSettingsSection>
			</div>

			<NcSettingsSection>
				<NcButton
					variant="primary"
					:disabled="loading"
					data-test="button-save-settings"
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
import { ref, onMounted, nextTick } from 'vue'
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
	NcNoteCard,
} from '@nextcloud/vue'
import AccountStar from 'vue-material-design-icons/AccountStar.vue'
import GroupSelect from '../components/common/GroupSelect.vue'

// State
const availableGroups = ref([])
const selectedGroups = ref([])
const selectedTeams = ref([])
const teamSearchResults = ref([])
const isSearchingTeams = ref(false)
const teamsAvailable = ref(false)
const selectedManageAppointmentsRoles = ref([])
const selectedCheckinRoles = ref([])
const selectedSeeResponseOverviewRoles = ref([])
const selectedSeeCommentsRoles = ref([])
const remindersEnabled = ref(false)
const reminderDays = ref(7)
const reminderFrequency = ref(0)
const notificationsAppEnabled = ref(true)
const calendarSyncEnabled = ref(false)
const calendarSyncAvailable = ref(false)
const loading = ref(false)
const loadingData = ref(true)

// Methods
const loadSettings = async () => {
	loadingData.value = true

	try {
		const response = await axios.get(
			generateUrl('/apps/attendance/api/admin/settings'),
		)

		if (response.data.success) {
			availableGroups.value = response.data.groups
			// Convert selected IDs to selected group objects for NcSelect, preserving database order
			selectedGroups.value = response.data.whitelistedGroups
				.map(id => response.data.groups.find(group => group.id === id))
				.filter(group => group !== undefined)

			// Load teams settings
			teamsAvailable.value = response.data.teamsAvailable || false
			if (response.data.whitelistedTeams) {
				selectedTeams.value = response.data.whitelistedTeams
				// Also add to search results so they appear in the dropdown
				teamSearchResults.value = [...response.data.whitelistedTeams]
			}

			// Load permission settings, preserving database order
			if (response.data.permissions) {
				selectedManageAppointmentsRoles.value = response.data.permissions.manage_appointments
					.map(id => response.data.groups.find(group => group.id === id))
					.filter(group => group !== undefined)
				selectedCheckinRoles.value = response.data.permissions.checkin
					.map(id => response.data.groups.find(group => group.id === id))
					.filter(group => group !== undefined)
				selectedSeeResponseOverviewRoles.value = (response.data.permissions.see_response_overview || [])
					.map(id => response.data.groups.find(group => group.id === id))
					.filter(group => group !== undefined)
				selectedSeeCommentsRoles.value = (response.data.permissions.see_comments || [])
					.map(id => response.data.groups.find(group => group.id === id))
					.filter(group => group !== undefined)
			}

			// Load reminder settings
			if (response.data.reminders) {
				remindersEnabled.value = response.data.reminders.enabled || false
				reminderDays.value = response.data.reminders.reminderDays || 7
				reminderFrequency.value = response.data.reminders.reminderFrequency || 0
				notificationsAppEnabled.value = response.data.reminders.notificationsAppEnabled !== false
			}

			// Load calendar sync settings
			if (response.data.calendarSync) {
				calendarSyncEnabled.value = response.data.calendarSync.enabled || false
				calendarSyncAvailable.value = response.data.calendarSync.available || false
			}
		} else {
			showError(window.t('attendance', 'Failed to load settings')
				+ (response.data.error ? ': ' + response.data.error : ''))
		}
	} catch (error) {
		console.error('Error loading settings:', error)
		showError(window.t('attendance', 'Failed to load settings'))
	} finally {
		loadingData.value = false
	}
}

const searchTeams = async (query) => {
	if (!query || query.length < 1) {
		// Keep selected teams visible in results
		teamSearchResults.value = [...selectedTeams.value]
		return
	}

	isSearchingTeams.value = true
	try {
		const response = await axios.get(
			generateUrl('/apps/attendance/api/search/users-groups-teams'),
			{ params: { search: query } },
		)
		// Filter to only show teams
		const teams = response.data
			.filter(item => item.type === 'team')
			.map(item => ({ id: item.id, label: item.label, type: 'team' }))

		// Merge with selected teams to keep them visible
		const selectedIds = selectedTeams.value.map(t => t.id)
		const newTeams = teams.filter(t => !selectedIds.includes(t.id))
		teamSearchResults.value = [...selectedTeams.value, ...newTeams]
	} catch (error) {
		console.error('Error searching teams:', error)
	} finally {
		isSearchingTeams.value = false
	}
}

const saveSettings = async () => {
	loading.value = true

	try {
		const response = await axios.post(
			generateUrl('/apps/attendance/api/admin/settings'),
			{
				whitelistedGroups: selectedGroups.value.map(g => g.id),
				whitelistedTeams: selectedTeams.value.map(t => t.id),
				permissions: {
					PERMISSION_MANAGE_APPOINTMENTS: selectedManageAppointmentsRoles.value.map(g => g.id),
					PERMISSION_CHECKIN: selectedCheckinRoles.value.map(g => g.id),
					PERMISSION_SEE_RESPONSE_OVERVIEW: selectedSeeResponseOverviewRoles.value.map(g => g.id),
					PERMISSION_SEE_COMMENTS: selectedSeeCommentsRoles.value.map(g => g.id),
				},
				reminders: {
					enabled: remindersEnabled.value,
					reminderDays: reminderDays.value,
					reminderFrequency: reminderFrequency.value,
				},
				calendarSync: {
					enabled: calendarSyncEnabled.value,
				},
			},
		)

		if (response.data.success) {
			showSuccess(window.t('attendance', 'Settings saved'))
		} else {
			showError(window.t('attendance', 'Failed to save settings')
				+ (response.data.error ? ': ' + response.data.error : ''))
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

	// Handle hash navigation after content is loaded
	await nextTick()
	if (window.location.hash) {
		const element = document.querySelector(window.location.hash)
		if (element) {
			element.scrollIntoView({ behavior: 'smooth' })
		}
	}
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
