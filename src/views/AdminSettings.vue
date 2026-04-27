<template>
	<div id="attendance-admin-settings" data-test="admin-settings">
		<NcSettingsSection :name="t('attendance', 'Attendance')"
			:description="t('attendance', 'Configure attendance management and reminders')" />

		<div v-if="loadingData" class="loading-section">
			<NcLoadingIcon :size="32" />
			<p>{{ t('attendance', 'Loading settings\u00A0…') }}</p>
		</div>

		<template v-else>
			<!-- TRANSLATORS: Admin settings section title. The "Response summary" is the main feature of this app - it shows attendance statistics on the appointment detail page, counting users by their Nextcloud group membership. Groups selected here will have their own sections in the summary; users not in these groups appear under "Others". -->
			<NcSettingsSection :name="t('attendance', 'Response summary groups')"
				:description="t('attendance', 'Select which groups to include in response summaries. Users outside these groups will appear under Others. Leave empty to include all groups.')"
				data-test="section-tracking-groups">
				<GroupSelect
					v-model="selectedGroups"
					:options="availableGroups"
					:placeholder="t('attendance', 'Select groups\u00A0…')"
					:disabled="loading"
					data-test="select-whitelisted-groups" />
				<p class="hint-text">
					{{ n('attendance', '%n group selected', '%n groups selected', selectedGroups.length, { n: selectedGroups.length }) }}
				</p>
			</NcSettingsSection>

			<!-- TRANSLATORS: Admin settings section title. Similar to groups above, but for Nextcloud Teams (formerly Circles). Teams selected here will have their own sections in the attendance statistics on the appointment detail page, showing how many team members responded yes/no/maybe. -->
			<NcSettingsSection v-if="teamsAvailable"
				:name="t('attendance', 'Response summary teams')"
				:description="t('attendance', 'Select which teams to include in response summaries. Team members will be grouped together like regular groups.')"
				data-test="section-tracking-teams">
				<NcSelect
					v-model="selectedTeams"
					:options="teamSearchResults"
					:placeholder="t('attendance', 'Search and select teams\u00A0…')"
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
						:placeholder="t('attendance', 'Select groups\u00A0…')"
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
						:placeholder="t('attendance', 'Select groups\u00A0…')"
						:disabled="loading"
						data-test="select-checkin-roles" />
					<p class="hint-text">
						{{ n('attendance', '%n group selected', '%n groups selected', selectedCheckinRoles.length, { n: selectedCheckinRoles.length }) }}
					</p>
				</div>

				<div class="subsection">
					<h4>{{ t('attendance', 'See response & check-in summary') }}</h4>
					<p class="subsection-hint">
						{{ t('attendance', 'Groups that can see the response summary and check-in summary') }}
					</p>
					<GroupSelect
						v-model="selectedSeeResponseOverviewRoles"
						:options="availableGroups"
						:placeholder="t('attendance', 'Select groups\u00A0…')"
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
						:placeholder="t('attendance', 'Select groups\u00A0…')"
						:disabled="loading"
						data-test="select-see-comments-roles" />
					<p class="hint-text">
						{{ n('attendance', '%n group selected', '%n groups selected', selectedSeeCommentsRoles.length, { n: selectedSeeCommentsRoles.length }) }}
					</p>
				</div>

				<div class="subsection">
					<h4>{{ t('attendance', 'Self-check-in') }}</h4>
					<p class="subsection-hint">
						{{ t('attendance', 'Groups that can self-check-in via NFC sticker or deep link') }}
					</p>
					<GroupSelect
						v-model="selectedSelfCheckinRoles"
						:options="availableGroups"
						:placeholder="t('attendance', 'Select groups …')"
						:disabled="loading"
						data-test="select-self-checkin-roles" />
					<p class="hint-text">
						{{ n('attendance', '%n group selected', '%n groups selected', selectedSelfCheckinRoles.length, { n: selectedSelfCheckinRoles.length }) }}
					</p>
				</div>
			</NcSettingsSection>

			<NcSettingsSection :name="t('attendance', 'Appointment reminders')"
				:description="reminderSectionDescription">
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

						<div class="reminder-target-section">
							<label class="reminder-target-label">
								{{ t('attendance', 'Remind recipients') }}
							</label>
							<NcCheckboxRadioSwitch
								v-model="reminderTarget"
								type="radio"
								value="non_responders"
								name="reminder-target"
								data-test="radio-reminder-target-non-responders">
								{{ t('attendance', 'Non-responders only') }}
							</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
								v-model="reminderTarget"
								type="radio"
								value="maybe"
								name="reminder-target"
								data-test="radio-reminder-target-maybe">
								{{ t('attendance', 'Maybe responders only') }}
							</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
								v-model="reminderTarget"
								type="radio"
								value="both"
								name="reminder-target"
								data-test="radio-reminder-target-both">
								{{ t('attendance', 'Both non-responders and maybe responders') }}
							</NcCheckboxRadioSwitch>
						</div>
					</div>

					<div v-if="remindersEnabled" class="reminder-preview" data-test="reminder-preview">
						<h4>{{ t('attendance', 'Next reminder run') }}</h4>
						<p v-if="nextReminderRun" class="reminder-preview-context">
							{{ t('attendance', 'Approximately {datetime}. The exact time depends on when the server background job runs.', { datetime: formatDateTimeMedium(nextReminderRun + 'Z') }) }}
						</p>
						<p v-else class="reminder-preview-context">
							{{ t('attendance', 'As soon as the background job has run for the first time, the next approximate run time will be displayed here.') }}
						</p>
						<h4>{{ t('attendance', 'Preview') }}</h4>
						<template v-if="nextAppointment">
							<p class="reminder-preview-context">
								{{ t('attendance', 'Based on your next appointment: {name} ({date})', {
									name: nextAppointment.name,
									date: formatDateTimeMedium(nextAppointment.startDatetime),
								}) }}
							</p>
							<template v-if="reminderPreviewDates.length > 0">
								<ul class="reminder-preview-list">
									<li v-for="(entry, index) in reminderPreviewDates" :key="index">
										<strong>{{ formatDate(entry.date) }}</strong>
										<span class="reminder-preview-label">
											{{ entry.daysBefore === 0
												? t('attendance', '(day of appointment)')
												: n('attendance', '({count} day before)', '({count} days before)', entry.daysBefore, { count: entry.daysBefore })
											}}
										</span>
									</li>
								</ul>
								<NcButton
									variant="tertiary"
									:disabled="sendingTestReminder"
									class="test-reminder-button"
									data-test="button-test-reminder"
									@click="sendTestReminder">
									<template #icon>
										<BellRingIcon :size="20" />
									</template>
									{{ t('attendance', 'Send test reminder to myself') }}
								</NcButton>
							</template>
							<p v-else class="reminder-preview-context">
								{{ t('attendance', 'The reminder window for this appointment has already passed.') }}
							</p>
						</template>
						<p v-else class="reminder-preview-context">
							{{ t('attendance', 'No upcoming appointments. The preview will appear when an appointment is scheduled.') }}
						</p>
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

			<NcSettingsSection :name="t('attendance', 'Mobile apps')"
				:description="t('attendance', 'Share these links with your colleagues to install the Attendance mobile app.')">
				<div class="mobile-app-links">
					<div v-for="store in mobileAppStores" :key="store.id" class="mobile-app-link">
						<label class="mobile-app-link__label">
							<component :is="store.icon" :size="20" />
							{{ store.label }}
						</label>
						<div class="mobile-app-link__row">
							<code class="mobile-app-link__url" :data-test="`input-${store.id}-store-url`">{{ store.url }}</code>
							<NcButton variant="secondary"
								:aria-label="t('attendance', 'Copy URL')"
								:data-test="`button-copy-${store.id}-url`"
								@click="copyStoreUrl(store.url)">
								<template #icon>
									<ContentCopy :size="20" />
								</template>
								{{ t('attendance', 'Copy') }}
							</NcButton>
							<NcButton variant="tertiary"
								:href="store.url"
								target="_blank"
								rel="noopener"
								:data-test="`button-open-${store.id}-url`">
								<template #icon>
									<OpenInNew :size="20" />
								</template>
								{{ t('attendance', 'Open') }}
							</NcButton>
						</div>
					</div>
				</div>

				<div class="subsection">
					<h4>{{ t('attendance', 'Mobile App promotion banner') }}</h4>
					<p class="subsection-hint">
						{{ t('attendance', 'Show a banner at the top of the web app advertising the mobile apps. Users can dismiss the banner, and users who already have a push device connected will not see it.') }}
					</p>
					<NcCheckboxRadioSwitch
						v-model="mobileAppBannerEnabled"
						type="switch"
						:disabled="loading"
						data-test="switch-mobile-app-banner-enabled">
						{{ t('attendance', 'Show promotion banner') }}
					</NcCheckboxRadioSwitch>
				</div>

				<div class="subsection">
					<h4>{{ t('attendance', 'Push notifications') }}</h4>
					<p class="subsection-hint">
						{{ t('attendance', 'Enable push notifications for the mobile app.') }}
					</p>
					<NcCheckboxRadioSwitch
						v-model="pushEnabled"
						type="switch"
						:disabled="loading"
						data-test="switch-push-enabled">
						{{ t('attendance', 'Enable push notifications') }}
					</NcCheckboxRadioSwitch>

					<template v-if="pushEnabled">
						<div class="push-device-status">
							<NcNoteCard v-if="pushDeviceCount === 0" type="warning">
								<p>{{ t('attendance', 'No push device registered for your account. Connect the Attendance mobile app to receive push notifications.') }}</p>
							</NcNoteCard>
							<template v-else>
								<p class="push-device-info">
									<CellphoneCheck :size="20" />
									{{ n('attendance', '{count} device registered for push notifications', '{count} devices registered for push notifications', pushDeviceCount, { count: pushDeviceCount }) }}
								</p>
								<NcButton
									variant="tertiary"
									:disabled="sendingTestReminder"
									class="test-reminder-button"
									data-test="button-test-push"
									@click="sendTestReminder">
									<template #icon>
										<BellRingIcon :size="20" />
									</template>
									{{ t('attendance', 'Send test notification') }}
								</NcButton>
							</template>
						</div>
					</template>
				</div>
			</NcSettingsSection>

			<NcSettingsSection :name="t('attendance', 'Display options')"
				:description="t('attendance', 'Choose how appointments are displayed across the app.')">
				<NcCheckboxRadioSwitch
					v-model="displayOrder"
					value="name_first"
					name="display_order"
					type="radio"
					data-test="radio-name-first">
					{{ t('attendance', 'Name first') }}
					<template #subtext>
						{{ t('attendance', 'Show appointment name prominently, with date below') }}
					</template>
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="displayOrder"
					value="date_first"
					name="display_order"
					type="radio"
					data-test="radio-date-first">
					{{ t('attendance', 'Date first') }}
					<template #subtext>
						{{ t('attendance', 'Show date and time prominently, with name below') }}
					</template>
				</NcCheckboxRadioSwitch>
			</NcSettingsSection>

			<NcSettingsSection :name="t('attendance', 'Guest invitation')"
				:description="t('attendance', 'Invite people without a Nextcloud account by integrating with the Nextcloud Guests app.')"
				data-test="section-guests-warning">
				<template v-if="guestsHintVariant === 'install'">
					<NcNoteCard type="info" data-test="guests-install-hint">
						{{ t('attendance', 'Want to invite guests? Install the Nextcloud Guests app — once enabled, organizers can create guest accounts directly from the appointment editor.') }}
					</NcNoteCard>
					<p class="guests-warning-actions">
						<NcButton variant="primary"
							:href="guestsAppStoreUrl"
							target="_blank"
							rel="noopener"
							data-test="button-open-guests-app-store">
							<template #icon>
								<OpenInNew :size="20" />
							</template>
							{{ t('attendance', 'Open in app store') }}
						</NcButton>
					</p>
				</template>
				<template v-else-if="guestsHintVariant === 'whitelist'">
					<NcNoteCard type="warning" data-test="guests-whitelist-warning">
						{{ t('attendance', 'The Guests app is enabled but Attendance is not in its allowed apps list. Invited guests will not see this app.') }}
					</NcNoteCard>
					<div class="guests-occ-row">
						<code class="guests-occ-row__command" data-test="input-guests-occ">{{ guestsWhitelistOccCommand }}</code>
						<NcButton variant="secondary"
							:aria-label="t('attendance', 'Copy occ command')"
							data-test="button-copy-guests-occ"
							@click="copyGuestsOccCommand">
							<template #icon>
								<ContentCopy :size="20" />
							</template>
							{{ t('attendance', 'Copy') }}
						</NcButton>
					</div>
					<p class="guests-warning-or">{{ t('attendance', 'or') }}</p>
					<p class="guests-warning-actions">
						<NcButton variant="primary"
							:href="guestsAdminUrl"
							data-test="button-open-guests-settings">
							<template #icon>
								<OpenInNew :size="20" />
							</template>
							{{ t('attendance', 'Open Guests app settings') }}
						</NcButton>
					</p>
				</template>

				<div class="guests-info-block">
					<h4>{{ t('attendance', 'How guest accounts are restricted') }}</h4>
					<p>
						{{ t('attendance', 'Guest users can submit RSVPs and self-check-in but can never manage appointments or check in other attendees. This is enforced server-side and cannot be granted via group permissions.') }}
					</p>
				</div>

				<div class="guests-info-block">
					<h4>{{ t('attendance', 'Converting guests to full accounts') }}</h4>
					<p>
						{{ t('attendance', 'When a guest later registers a full Nextcloud account with the same email (for example via SAML or LDAP), the Guests app converts them automatically. Past attendance responses remain attached to the original guest user ID — they are not migrated to the new account.') }}
					</p>
				</div>
			</NcSettingsSection>

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
import { ref, computed, onMounted, nextTick } from 'vue'
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
import BellRingIcon from 'vue-material-design-icons/BellRing.vue'
import CellphoneCheck from 'vue-material-design-icons/CellphoneCheck.vue'
import AppleIcon from 'vue-material-design-icons/Apple.vue'
import GoogleIcon from 'vue-material-design-icons/Google.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import GroupSelect from '../components/common/GroupSelect.vue'
import { formatDate, formatDateTimeMedium } from '../utils/datetime.js'
import { APPLE_STORE_URL, GOOGLE_STORE_URL } from '../utils/mobileApp.js'
import { copyToClipboard } from '../utils/clipboard.js'

const mobileAppStores = [
	{ id: 'apple', icon: AppleIcon, url: APPLE_STORE_URL, label: t('attendance', 'App Store (iOS)') },
	{ id: 'google', icon: GoogleIcon, url: GOOGLE_STORE_URL, label: t('attendance', 'Google Play (Android)') },
]

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
const selectedSelfCheckinRoles = ref([])
const remindersEnabled = ref(false)
const reminderDays = ref(7)
const reminderFrequency = ref(0)
const reminderTarget = ref('non_responders')
const notificationsAppEnabled = ref(true)
const nextAppointment = ref(null)
const nextReminderRun = ref(null)
const calendarSyncEnabled = ref(false)
const calendarSyncAvailable = ref(false)
const pushEnabled = ref(true)
const mobileAppBannerEnabled = ref(true)
const displayOrder = ref('name_first')
const pushDeviceCount = ref(0)
const loading = ref(false)
const loadingData = ref(true)
const sendingTestReminder = ref(false)
const guestsApp = ref({ enabled: false, whitelistEnabled: false, attendanceInWhitelist: false })

// Computed
// 'install' = Guests app missing (offer to install)
// 'whitelist' = Guests app enabled but `attendance` missing from its whitelist
// null = nothing to surface (either fully configured or a state we don't act on)
const guestsHintVariant = computed(() => {
	if (!guestsApp.value.enabled) {
		return 'install'
	}
	if (guestsApp.value.whitelistEnabled && !guestsApp.value.attendanceInWhitelist) {
		return 'whitelist'
	}
	return null
})

const guestsAdminUrl = computed(() => generateUrl('/settings/admin/guests'))
const guestsAppStoreUrl = 'https://apps.nextcloud.com/apps/guests'
const guestsWhitelistOccCommand = 'occ config:app:set guests whitelist --value=$(occ config:app:get guests whitelist),attendance'

const reminderSectionDescription = computed(() => {
	return t('attendance', 'Reminders are sent to users in the groups configured under "Response summary groups" and "Response summary teams". If an appointment has restricted access, only users matching that restriction will be reminded.')
})

const reminderPreviewDates = computed(() => {
	if (!nextAppointment.value) return []

	const appointmentDate = new Date(nextAppointment.value.startDatetime)
	const today = new Date()
	today.setHours(0, 0, 0, 0)

	const days = reminderDays.value || 7
	const frequency = reminderFrequency.value || 0

	// Window start = appointmentDate - reminderDays, clamped to today
	const windowStart = new Date(appointmentDate)
	windowStart.setDate(windowStart.getDate() - days)
	windowStart.setHours(0, 0, 0, 0)

	const effectiveStart = windowStart < today ? today : windowStart

	// If the window has already passed entirely
	const appointmentDay = new Date(appointmentDate)
	appointmentDay.setHours(0, 0, 0, 0)
	if (effectiveStart > appointmentDay) return []

	const dates = []
	if (frequency === 0) {
		// Single reminder at window start
		const daysBefore = Math.round((appointmentDay - effectiveStart) / (1000 * 60 * 60 * 24))
		dates.push({ date: new Date(effectiveStart), daysBefore, isFirst: true, isSingle: true })
	} else {
		// Repeated reminders every N days
		const current = new Date(effectiveStart)
		let isFirst = true
		// eslint-disable-next-line no-unmodified-loop-condition -- current is mutated via setDate()
		while (current <= appointmentDay) {
			const daysBefore = Math.round((appointmentDay - current) / (1000 * 60 * 60 * 24))
			dates.push({ date: new Date(current), daysBefore, isFirst, isSingle: false })
			current.setDate(current.getDate() + frequency)
			isFirst = false
		}
	}

	return dates
})

// Methods
const loadSettings = async () => {
	loadingData.value = true

	try {
		const [settingsRes, capabilitiesRes] = await Promise.all([
			axios.get(generateUrl('/apps/attendance/api/admin/settings')),
			axios.get(generateUrl('/apps/attendance/api/capabilities')),
		])

		const { config, status, groups } = settingsRes.data
		const caps = capabilitiesRes.data

		availableGroups.value = groups
		// Convert selected IDs to selected group objects for NcSelect, preserving database order
		selectedGroups.value = config.whitelistedGroups
			.map(id => groups.find(group => group.id === id))
			.filter(group => group !== undefined)

		// Load teams settings
		teamsAvailable.value = caps.teamsAvailable || false
		if (config.whitelistedTeams) {
			selectedTeams.value = config.whitelistedTeams
			// Also add to search results so they appear in the dropdown
			teamSearchResults.value = [...config.whitelistedTeams]
		}

		// Load permission settings, preserving database order
		if (config.permissions) {
			selectedManageAppointmentsRoles.value = config.permissions.manage_appointments
				.map(id => groups.find(group => group.id === id))
				.filter(group => group !== undefined)
			selectedCheckinRoles.value = config.permissions.checkin
				.map(id => groups.find(group => group.id === id))
				.filter(group => group !== undefined)
			selectedSeeResponseOverviewRoles.value = (config.permissions.see_response_overview || [])
				.map(id => groups.find(group => group.id === id))
				.filter(group => group !== undefined)
			selectedSeeCommentsRoles.value = (config.permissions.see_comments || [])
				.map(id => groups.find(group => group.id === id))
				.filter(group => group !== undefined)
			selectedSelfCheckinRoles.value = (config.permissions.self_checkin || [])
				.map(id => groups.find(group => group.id === id))
				.filter(group => group !== undefined)
		}

		// Load reminder settings
		remindersEnabled.value = config.reminders.enabled || false
		reminderDays.value = config.reminders.reminderDays || 7
		reminderFrequency.value = config.reminders.reminderFrequency || 0
		reminderTarget.value = config.reminders.reminderTarget || 'non_responders'
		notificationsAppEnabled.value = caps.notificationsAppEnabled !== false
		nextAppointment.value = status.nextAppointment || null
		nextReminderRun.value = status.nextReminderRun || null

		// Load calendar sync settings
		calendarSyncEnabled.value = config.calendarSync.enabled || false
		calendarSyncAvailable.value = caps.calendarSyncAvailable || false

		// Load display order
		displayOrder.value = config.displayOrder || 'name_first'

		// Load push notifications
		pushEnabled.value = config.pushEnabled !== false
		pushDeviceCount.value = status.pushDeviceCount || 0

		// Load mobile app banner setting
		mobileAppBannerEnabled.value = config.mobileAppBannerEnabled !== false

		// Load guests app status (for whitelist warning)
		if (config.guestsApp) {
			guestsApp.value = config.guestsApp
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
		await axios.post(
			generateUrl('/apps/attendance/api/admin/settings'),
			{
				whitelistedGroups: selectedGroups.value.map(g => g.id),
				whitelistedTeams: selectedTeams.value.map(t => t.id),
				permissions: {
					PERMISSION_MANAGE_APPOINTMENTS: selectedManageAppointmentsRoles.value.map(g => g.id),
					PERMISSION_CHECKIN: selectedCheckinRoles.value.map(g => g.id),
					PERMISSION_SEE_RESPONSE_OVERVIEW: selectedSeeResponseOverviewRoles.value.map(g => g.id),
					PERMISSION_SEE_COMMENTS: selectedSeeCommentsRoles.value.map(g => g.id),
					PERMISSION_SELF_CHECKIN: selectedSelfCheckinRoles.value.map(g => g.id),
				},
				reminders: {
					enabled: remindersEnabled.value,
					reminderDays: reminderDays.value,
					reminderFrequency: reminderFrequency.value,
					reminderTarget: reminderTarget.value,
				},
				calendarSync: {
					enabled: calendarSyncEnabled.value,
				},
				displayOrder: displayOrder.value,
				pushEnabled: pushEnabled.value,
				mobileAppBannerEnabled: mobileAppBannerEnabled.value,
			},
		)

		showSuccess(window.t('attendance', 'Settings saved'))
	} catch (error) {
		console.error('Error saving settings:', error)
		showError(window.t('attendance', 'Failed to save settings'))
	} finally {
		loading.value = false
	}
}

const copyStoreUrl = (url) => copyToClipboard(url, {
	successMessage: window.t('attendance', 'Link copied'),
	errorMessage: window.t('attendance', 'Failed to copy link'),
})

const copyGuestsOccCommand = () => copyToClipboard(
	guestsWhitelistOccCommand,
	{
		successMessage: window.t('attendance', 'Command copied'),
		errorMessage: window.t('attendance', 'Failed to copy command'),
	},
)

const sendTestReminder = async () => {
	sendingTestReminder.value = true
	try {
		const response = await axios.post(
			generateUrl('/apps/attendance/api/admin/test-reminder'),
		)
		const name = response.data.appointmentName || ''
		showSuccess(window.t('attendance', 'Test reminder sent for {name}', { name }))
	} catch (error) {
		if (error.response?.status === 404) {
			showError(window.t('attendance', 'No upcoming appointment found'))
		} else {
			console.error('Error sending test reminder:', error)
			showError(window.t('attendance', 'Failed to send test reminder'))
		}
	} finally {
		sendingTestReminder.value = false
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

.guests-warning-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 12px;
}

.guests-occ-row {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	margin-top: 8px;
}

.guests-warning-or {
	margin: 8px 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	font-style: italic;
}

.guests-info-block {
	margin-top: 20px;

	h4 {
		margin: 0 0 6px 0;
		font-size: 14px;
		font-weight: 600;
	}

	p {
		margin: 0 0 8px 0;
		color: var(--color-text-lighter);
		font-size: 13px;
		line-height: 1.5;
	}
}

.guests-occ-row__command {
	flex: 1 1 300px;
	padding: 8px 12px;
	background-color: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-family: monospace;
	font-size: 12px;
	word-break: break-all;
	user-select: all;
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

.reminder-target-section {
	margin-top: 24px;
}

.reminder-target-label {
	display: block;
	font-weight: 600;
	margin-bottom: 8px;
	font-size: 14px;
}

.reminder-preview {
	margin-top: 20px;
	padding: 16px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large);
}

.reminder-preview h4 {
	margin: 0 0 8px 0;
	font-size: 15px;
	font-weight: 600;
}

.reminder-preview h4 + p + h4 {
	margin-top: 20px;
}

.reminder-preview-context {
	margin: 0 0 12px 0;
	color: var(--color-text-maxcontrast);
}

.reminder-preview-list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.reminder-preview-list li {
	padding: 6px 0;
	display: flex;
	align-items: center;
	gap: 8px;
}

.reminder-preview-label {
	color: var(--color-text-maxcontrast);
}

.test-reminder-button {
	margin-top: 12px;
}

.push-device-status {
	margin-top: 16px;
}

.push-device-info {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-success);
	font-weight: 500;
}

.mobile-app-links {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.mobile-app-link__label {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 6px;
	font-weight: 600;
}

.mobile-app-link__row {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.mobile-app-link__url {
	flex: 1 1 300px;
	padding: 8px 12px;
	background-color: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-family: monospace;
	font-size: 12px;
	word-break: break-all;
	user-select: all;
}
</style>
