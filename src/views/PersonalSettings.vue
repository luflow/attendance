<template>
	<div id="attendance-personal-settings">
		<NcSettingsSection :name="t('attendance', 'Calendar subscription')">
			<IcalFeedSubscription />
		</NcSettingsSection>

		<NcSettingsSection :name="t('attendance', 'Calendar reminders')"
			:description="t('attendance', 'Configure reminders for appointments you accepted or tentatively accepted in your calendar subscription (iCal feed). You can select multiple reminder times.')">
			<LoadingState v-if="settingsLoading" :text="t('attendance', 'Loading settings\u00A0\u2026')" />

			<div v-else>
				<div class="setting-row">
					<label for="ical-reminder-triggers">{{ t('attendance', 'Reminders before appointment') }}</label>
					<NcSelect v-model="selectedReminders"
						inputId="ical-reminder-triggers"
						:options="reminderOptions"
						:multiple="true"
						keepOpen
						:disabled="saving"
						:placeholder="t('attendance', 'No reminders')"
						label="label"
						trackBy="value"
						@update:modelValue="save" />
				</div>
			</div>
		</NcSettingsSection>

		<NcSettingsSection v-if="notifyResponseChanges !== null"
			:name="t('attendance', 'Response notifications')"
			:description="t('attendance', 'Get notified when team members respond to appointments. Only available for users who can manage or organize appointments.')">
			<NcCheckboxRadioSwitch v-model="notifyResponseChanges"
				type="switch"
				data-test="switch-notify-response-changes"
				:disabled="saving"
				@update:modelValue="save">
				{{ t('attendance', 'Receive notifications for response changes') }}
			</NcCheckboxRadioSwitch>
		</NcSettingsSection>

		<NcSettingsSection v-if="capabilities.vacationManagementEnabled"
			:name="t('attendance', 'My vacations')"
			data-test="section-my-vacations">
			<MyVacations @changed="teamVacationCalendar?.reload()" />
		</NcSettingsSection>

		<NcSettingsSection v-if="capabilities.vacationManagementEnabled && permissions.canSeeTeamVacations"
			:name="t('attendance', 'Team vacation calendar')"
			data-test="section-team-vacations">
			<TeamVacationCalendar ref="teamVacationCalendar" />
		</NcSettingsSection>
	</div>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcCheckboxRadioSwitch,
	NcSelect,
	NcSettingsSection,
} from '@nextcloud/vue'
import { computed, onMounted, ref } from 'vue'
import LoadingState from '../components/common/LoadingState.vue'
import IcalFeedSubscription from '../components/IcalFeedSubscription.vue'
import MyVacations from '../components/vacation/MyVacations.vue'
import TeamVacationCalendar from '../components/vacation/TeamVacationCalendar.vue'
import { usePermissions } from '../composables/usePermissions.js'

const { capabilities, permissions, loadPermissions } = usePermissions()
// Own entries show up in the team calendar too, so it reloads when they change.
const teamVacationCalendar = ref(null)

// Reminder settings
const settingsLoading = ref(true)
const saving = ref(false)
const icalReminderTriggers = ref([])
// null = toggle hidden (user is not eligible). Boolean = toggle visible.
const notifyResponseChanges = ref(null)

const reminderOptions = [
	{ value: 'PT15M', label: t('attendance', '15 minutes before') },
	{ value: 'PT30M', label: t('attendance', '30 minutes before') },
	{ value: 'PT1H', label: t('attendance', '1 hour before') },
	{ value: 'PT2H', label: t('attendance', '2 hours before') },
	{ value: 'P1D', label: t('attendance', '1 day before') },
	{ value: 'P2D', label: t('attendance', '2 days before') },
]

const selectedReminders = computed({
	get() {
		return icalReminderTriggers.value
			.map((v) => reminderOptions.find((o) => o.value === v))
			.filter(Boolean)
	},
	set(options) {
		icalReminderTriggers.value = options.map((o) => o.value)
	},
})

async function loadSettings() {
	try {
		const response = await axios.get(generateUrl('/apps/attendance/api/user/settings'))
		icalReminderTriggers.value = response.data.icalReminderTriggers ?? []
		notifyResponseChanges.value = Object.hasOwn(response.data, 'notifyResponseChanges')
			? response.data.notifyResponseChanges === true
			: null
	} catch {
		showError(t('attendance', 'Failed to load settings'))
	} finally {
		settingsLoading.value = false
	}
}

async function save() {
	saving.value = true
	try {
		const payload = {
			icalReminderTriggers: icalReminderTriggers.value,
		}
		if (notifyResponseChanges.value !== null) {
			payload.notifyResponseChanges = notifyResponseChanges.value
		}
		await axios.post(generateUrl('/apps/attendance/api/user/settings'), payload)
		showSuccess(t('attendance', 'Settings saved'))
	} catch {
		showError(t('attendance', 'Failed to save settings'))
	} finally {
		saving.value = false
	}
}

onMounted(() => {
	loadSettings()
	loadPermissions()
})
</script>

<style scoped>
.setting-row {
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-width: 400px;
}

.setting-row label {
	font-weight: bold;
}
</style>
