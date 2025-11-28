<template>
	<div id="attendance-admin-settings">
		<div class="attendance-setting">			
			<div v-if="loadingData" class="section">
				<NcLoadingIcon />
				<p>{{ t('attendance', 'Loading settings...') }}</p>
			</div>

			<div v-else class="section">
				<h2>{{ t('attendance', 'Whitelisted Groups') }}</h2>
				<p class="settings-hint">
					{{ t('attendance', 'Select user groups that should be included in attendance statistics and check-in list filters. If no groups are selected, all groups are included.') }}
				</p>

				<div class="form-group">
					<NcSelect
						v-model="selectedGroups"
						:options="availableGroups"
						:placeholder="t('attendance', 'Select groups...')"
						:multiple="true"
						:disabled="loading"
						label="displayName"
						track-by="id"
						@input="onGroupSelectionChange">
					</NcSelect>
					<p class="form-hint">
						{{ n('attendance', '%n group selected', '%n groups selected', selectedGroups.length, { n: selectedGroups.length }) }}
					</p>
				</div>

				<h2>{{ t('attendance', 'Permissions') }}</h2>
				<p class="settings-hint">
					{{ t('attendance', 'Configure which groups can perform specific actions. Users must belong to at least one of the selected groups to access the feature.') }}
				</p>

				<div class="permission-section">
					<h3>{{ t('attendance', 'Manage Appointments') }}</h3>
					<p class="permission-hint">
						{{ t('attendance', 'Groups that can create, update, and delete appointments') }}
					</p>
					<div class="form-group">
						<NcSelect
							v-model="selectedManageAppointmentsRoles"
							:options="availableGroups"
							:placeholder="t('attendance', 'Select groups...')"
							:multiple="true"
							:disabled="loading"
							label="displayName"
							track-by="id"
							@input="onManageAppointmentsRolesChange">
						</NcSelect>
						<p class="form-hint">
							{{ n('attendance', '%n group selected', '%n groups selected', selectedManageAppointmentsRoles.length, { n: selectedManageAppointmentsRoles.length }) }}
						</p>
					</div>
				</div>

				<div class="permission-section">
					<h3>{{ t('attendance', 'Check-in Access') }}</h3>
					<p class="permission-hint">
						{{ t('attendance', 'Groups that can access the check-in interface and execute check-ins') }}
					</p>
					<div class="form-group">
						<NcSelect
							v-model="selectedCheckinRoles"
							:options="availableGroups"
							:placeholder="t('attendance', 'Select groups...')"
							:multiple="true"
							:disabled="loading"
							label="displayName"
							track-by="id"
							@input="onCheckinRolesChange">
						</NcSelect>
						<p class="form-hint">
							{{ n('attendance', '%n group selected', '%n groups selected', selectedCheckinRoles.length, { n: selectedCheckinRoles.length }) }}
						</p>
					</div>
				</div>

				<div class="form-actions">
					<NcButton
						type="primary"
						:disabled="loading"
						@click="saveSettings">
						<template #icon>
							<NcLoadingIcon v-if="loading" />
						</template>
						{{ t('attendance', 'Save') }}
					</NcButton>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { NcSelect, NcButton, NcLoadingIcon } from '@nextcloud/vue'

// State
const availableGroups = ref([])
const selectedGroups = ref([])
const selectedManageAppointmentsRoles = ref([])
const selectedCheckinRoles = ref([])
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

const saveSettings = async () => {
	loading.value = true

	try {
		// Convert selected group objects to IDs for API
		const selectedGroupIds = selectedGroups.value.map(group => group.id)
		
		const response = await axios.post(
			generateUrl('/apps/attendance/api/admin/settings'),
			{
				whitelistedGroups: selectedGroupIds,
				permissions: {
					manage_appointments: selectedManageAppointmentsRoles.value.map(role => role.id),
					checkin: selectedCheckinRoles.value.map(role => role.id)
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

.attendance-groups-container {
	margin: 15px 0;
}

.attendance-groups-container label {
	display: block;
	margin-bottom: 8px;
	font-weight: 600;
}

.attendance-multiselect {
	width: 100%;
	max-width: 400px;
}

.attendance-actions {
	margin-top: 20px;
}

.permission-section {
	margin: 20px 0;
	padding: 15px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.permission-section h3 {
	margin: 0 0 8px 0;
	font-size: 16px;
	font-weight: 600;
}

.permission-hint {
	margin: 0 0 12px 0;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.settings-hint {
	margin: 0 0 15px 0;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.form-group {
	margin: 15px 0;
}

.form-hint {
	margin: 8px 0 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.form-actions {
	margin-top: 25px;
	padding-top: 15px;
	border-top: 1px solid var(--color-border);
}

</style>
