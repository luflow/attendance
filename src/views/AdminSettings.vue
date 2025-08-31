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
					{{ t('attendance', 'Select user groups that should be included in attendance statistics and user lists. If no groups are selected, all users and groups are included.') }}
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

<script>
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

export default {
	name: 'AdminSettings',

	components: {
		NcSelect,
		NcButton,
		NcLoadingIcon,
	},

	data() {
		return {
			availableGroups: [],
			selectedGroups: [],
			loading: false,
			loadingData: true,
		}
	},

	async mounted() {
		await this.loadSettings()
	},

	methods: {
		async loadSettings() {
			this.loadingData = true

			try {
				const response = await axios.get(
					generateUrl('/apps/attendance/admin/settings')
				)

				if (response.data.success) {
					this.availableGroups = response.data.groups
					// Convert selected IDs to selected group objects for NcSelect
					this.selectedGroups = response.data.groups.filter(group => 
						response.data.whitelistedGroups.includes(group.id)
					)
				} else {
					showError(this.t('attendance', 'Failed to load settings') + 
						(response.data.error ? ': ' + response.data.error : ''))
				}
			} catch (error) {
				console.error('Error loading settings:', error)
				showError(this.t('attendance', 'Failed to load settings'))
			} finally {
				this.loadingData = false
			}
		},

		onGroupSelectionChange(selectedGroups) {
			this.selectedGroups = selectedGroups || []
		},

		async saveSettings() {
			this.loading = true

			try {
				// Convert selected group objects to IDs for API
				const selectedGroupIds = this.selectedGroups.map(group => group.id)
				
				const response = await axios.post(
					generateUrl('/apps/attendance/admin/settings'),
					{
						whitelistedGroups: selectedGroupIds,
					}
				)

				if (response.data.success) {
					showSuccess(this.t('attendance', 'Settings saved successfully'))
				} else {
					showError(this.t('attendance', 'Failed to save settings') + 
						(response.data.error ? ': ' + response.data.error : ''))
				}
			} catch (error) {
				console.error('Error saving settings:', error)
				showError(this.t('attendance', 'Failed to save settings'))
			} finally {
				this.loading = false
			}
		},
	},
}
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

</style>
