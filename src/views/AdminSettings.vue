<template>
	<div id="attendance-admin-settings" data-test="admin-settings">
		<NcSettingsSection :name="t('attendance', 'Attendance')"
			:description="t('attendance', 'Configure attendance management and reminders. Changes are saved automatically.')">
			<nav class="anchor-nav" :aria-label="t('attendance', 'Settings sections')">
				<a v-for="section in navSections"
					:key="section.id"
					:href="`#${section.id}`"
					class="anchor-nav__link"
					@click.prevent="scrollToSection(section.id)">
					{{ section.label }}
				</a>
			</nav>
		</NcSettingsSection>

		<div v-if="loadingData" class="loading-section">
			<NcLoadingIcon :size="32" />
			<p>{{ t('attendance', 'Loading settings …') }}</p>
		</div>

		<template v-else>
			<NcSettingsSection id="permissions"
				:name="t('attendance', 'Permissions')"
				:description="t('attendance', 'Control who can do what. Every permission is either open to all users, limited to specific groups, or granted to nobody.')">
				<div v-for="group in permissionGroups" :key="group.key" class="permission-group">
					<h4 class="permission-group__title">
						{{ group.label }}
					</h4>
					<PermissionRow v-for="row in group.rows"
						:key="row.name"
						:modelValue="permissions[row.name]"
						:title="row.title"
						:hint="row.hint"
						:implication="row.implication"
						:warningWhenAll="row.warningWhenAll"
						:options="availableGroups"
						:dataTest="`permission-${row.name}`"
						@update:modelValue="onPermissionChange(row.name, $event)" />
				</div>
			</NcSettingsSection>

			<!-- TRANSLATORS: Admin settings section title. The "Response summary" is the main feature of this app - it shows attendance statistics on the appointment detail page, counting users by their Nextcloud group membership. Groups selected here will have their own sections in the summary; users not in these groups appear under "Others". -->
			<NcSettingsSection id="response-summary"
				:name="t('attendance', 'Response summary groups')"
				:description="t('attendance', 'Select which groups to include in response summaries. Users outside these groups will appear under Others. Leave empty to include all groups.')"
				data-test="section-tracking-groups">
				<GroupSelect
					v-model="selectedGroups"
					:options="availableGroups"
					:placeholder="t('attendance', 'Select groups …')"
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
					:placeholder="t('attendance', 'Search and select teams …')"
					:multiple="true"
					:loading="isSearchingTeams"
					:filterable="false"
					label="label"
					trackBy="id"
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

			<NcSettingsSection id="self-checkin"
				:name="t('attendance', 'Self-check-in')"
				:description="t('attendance', 'Attendees check themselves in via QR code or NFC tag. One code works for all appointments — the app matches by time.')">
				<!-- Wrapper div: scoped attrs don't reach the NcInputField root,
				     so the spacing lives on an own template element. -->
				<div class="self-checkin-window-field">
					<NcInputField
						v-model.number="selfCheckinWindowMinutes"
						type="number"
						:label="t('attendance', 'Check-in window (minutes before start)')"
						:helperText="t('attendance', 'How many minutes before an appointment starts attendees can check in. The window always closes when the appointment ends.')"
						data-test="input-self-checkin-window"
						:inputProps="{ min: 0, max: 1440 }" />
				</div>

				<div class="self-checkin-qr">
					<h6>{{ t('attendance', 'QR code') }}</h6>
					<p class="subsection-hint">
						{{ t('attendance', 'Print this QR code and put it up at the entrance.') }}
					</p>
					<img v-if="qrDataUrl"
						:src="qrDataUrl"
						:alt="t('attendance', 'Self-check-in QR code')"
						class="self-checkin-qr__image"
						data-test="self-checkin-qr">
					<div class="self-checkin-qr__actions">
						<NcButton variant="secondary" @click="downloadQrCode">
							<template #icon>
								<Download :size="20" />
							</template>
							{{ t('attendance', 'Download QR code') }}
						</NcButton>
					</div>

					<h6>{{ t('attendance', 'NFC tag') }}</h6>
					<p class="subsection-hint">
						{{ t('attendance', 'Write the check-in URL to NFC tags and stick them at the entrance.') }}
					</p>
					<div class="self-checkin-qr__actions">
						<NcButton variant="secondary" @click="copySelfCheckinUrl">
							<template #icon>
								<ContentCopy :size="20" />
							</template>
							{{ t('attendance', 'Copy URL for NFC tags') }}
						</NcButton>
					</div>
					<p class="hint-text">
						{{ t('attendance', 'NFC tag shopping advice: use NXP NTAG213 tags (or newer) of at least 25 mm, and on-metal tags for metal surfaces. Avoid MIFARE Classic tags — they do not work with iPhones.') }}
					</p>
					<p class="hint-text">
						<a class="self-checkin-qr__app-link" href="#mobile-apps" @click.prevent="scrollToSection('mobile-apps')">
							{{ t('attendance', 'You can also write NFC tags directly with the Attendance mobile app.') }}
						</a>
					</p>
				</div>
			</NcSettingsSection>

			<NcSettingsSection id="reminders"
				:name="t('attendance', 'Appointment reminders')"
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
							:helperText="t('attendance', 'Send reminders this many days before the appointment (1-30 days)')"
							data-test="input-reminder-days"
							:inputProps="{ min: 1, max: 30 }" />

						<NcInputField
							v-model.number="reminderFrequency"
							type="number"
							class="reminder-frequency-field"
							:label="t('attendance', 'Reminder frequency (days)')"
							:helperText="t('attendance', 'How often to remind users who haven\'t responded. Set to 0 to only remind once, or 1-30 to repeat reminders every N days.')"
							data-test="input-reminder-frequency"
							:inputProps="{ min: 0, max: 30 }" />

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
								<!-- TRANSLATORS: Radio option for automatic reminders — remind only people who have not responded yet. People who answered "no" are deliberately not reminded. -->
								{{ t('attendance', 'Non-responders only') }}
							</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
								v-model="reminderTarget"
								type="radio"
								value="maybe"
								name="reminder-target"
								data-test="radio-reminder-target-maybe">
								<!-- TRANSLATORS: Radio option for automatic reminders — remind only people who answered "maybe". -->
								{{ t('attendance', 'Maybe responders only') }}
							</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
								v-model="reminderTarget"
								type="radio"
								value="both"
								name="reminder-target"
								data-test="radio-reminder-target-both">
								<!-- TRANSLATORS: Radio option for automatic reminders — remind both groups: people without a response and people who answered "maybe". -->
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

			<NcSettingsSection id="calendar-sync"
				:name="t('attendance', 'Calendar sync')"
				:description="t('attendance', 'Automatically update attendance appointments when their linked calendar events are modified.')">
				<NcCheckboxRadioSwitch
					v-model="calendarSyncEnabled"
					type="switch"
					data-test="switch-calendar-sync-enabled">
					{{ t('attendance', 'Enable automatic calendar sync') }}
				</NcCheckboxRadioSwitch>
				<p class="hint-text">
					{{ t('attendance', 'When enabled, changes to calendar events will automatically update linked attendance appointments (title, description, date/time).') }}
				</p>
			</NcSettingsSection>

			<NcSettingsSection id="org-calendar"
				:name="t('attendance', 'Organization calendar')"
				:description="t('attendance', 'Automatically create and update events in a shared calendar for every appointment, so everyone can see them in the Calendar app.')">
				<NcNoteCard v-if="!calendarAvailable" type="warning">
					<p>{{ t('attendance', 'The Calendar app is not enabled. Enable it to use the organization calendar.') }}</p>
				</NcNoteCard>

				<template v-else>
					<NcCheckboxRadioSwitch
						v-model="orgCalendarEnabled"
						type="switch"
						data-test="switch-org-calendar-enabled">
						{{ t('attendance', 'Create calendar events for appointments') }}
					</NcCheckboxRadioSwitch>

					<div v-if="orgCalendarEnabled" class="subsection">
						<h4>{{ t('attendance', 'Target calendar') }}</h4>
						<NcNoteCard v-if="!orgCalendarOptions.length" type="warning">
							<p>{{ t('attendance', 'No writable calendar found. Create one in the Calendar app first.') }}</p>
						</NcNoteCard>
						<NcSelect v-else
							v-model="selectedOrgCalendar"
							:options="orgCalendarOptions"
							label="displayName"
							:placeholder="t('attendance', 'Select a calendar …')"
							data-test="select-org-calendar" />
						<p class="hint-text">
							{{ t('attendance', 'Share the selected calendar with your groups in the Calendar app so everyone can see the events.') }}
						</p>
						<p class="hint-text">
							{{ t('attendance', 'When you select a calendar, all upcoming appointments are transferred to it. Past appointments are not transferred.') }}
						</p>
						<p class="hint-text">
							{{ t('attendance', 'Events are created for all appointments, regardless of their visibility restrictions. Changing the target calendar does not move events that were already created.') }}
						</p>
						<p v-if="orgCalendarUserId" class="hint-text">
							{{ t('attendance', 'Events are written using the account of {user}.', { user: orgCalendarUserId }) }}
						</p>
						<NcButton
							v-if="selectedOrgCalendar"
							variant="tertiary"
							:disabled="syncingOrgCalendar"
							data-test="button-sync-org-calendar"
							@click="syncOrgCalendar">
							<template #icon>
								<NcLoadingIcon v-if="syncingOrgCalendar" :size="20" />
								<CalendarSyncIcon v-else :size="20" />
							</template>
							{{ t('attendance', 'Sync upcoming appointments now') }}
						</NcButton>
						<p class="hint-text">
							{{ t('attendance', 'Creates or updates the calendar events for all upcoming appointments. This also runs automatically when you enable the feature or change the calendar.') }}
						</p>
					</div>
				</template>
			</NcSettingsSection>

			<NcSettingsSection id="audit-log"
				:name="t('attendance', 'Audit log')"
				:description="t('attendance', 'Records who responded what and when, and surfaces a timeline on every appointment. Also drives the response-change push notifications that managers can opt into in their personal settings.')">
				<NcCheckboxRadioSwitch v-model="auditLogEnabled"
					type="switch"
					data-test="switch-audit-log-enabled">
					{{ t('attendance', 'Enable audit log') }}
				</NcCheckboxRadioSwitch>
				<p class="hint-text">
					{{ t('attendance', 'Disabling stops new events from being recorded and silences response notifications. Existing entries are kept and reappear once you re-enable.') }}
				</p>

				<template v-if="auditLogEnabled">
					<div class="subsection">
						<h4>{{ t('attendance', 'Who can see the audit log?') }}</h4>
						<NcCheckboxRadioSwitch v-model="auditLogVisibility"
							value="managers"
							name="audit_log_visibility"
							type="radio"
							data-test="radio-audit-visibility-managers">
							{{ t('attendance', 'Only users who can manage appointments') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="auditLogVisibility"
							value="all_with_response_overview"
							name="audit_log_visibility"
							type="radio"
							data-test="radio-audit-visibility-overview">
							{{ t('attendance', 'Everyone who can see the response overview') }}
						</NcCheckboxRadioSwitch>
					</div>
				</template>
			</NcSettingsSection>

			<!-- TRANSLATORS: Admin settings section title for the scheduling feature: managers give people who answered "yes" a place in the appointment ("schedule someone in"). German: the feature/noun is "Planung", the per-person action is "einplanen" and a scheduled person is "eingeplant" — not "planen"/"geplant". The description and the "Enable scheduling" switch below use the same meaning. -->
			<NcSettingsSection id="scheduling"
				:name="t('attendance', 'Scheduling')"
				:description="t('attendance', 'Let managers mark yes-responders as scheduled for an appointment. When off, no scheduling controls are shown anywhere.')">
				<NcCheckboxRadioSwitch v-model="bookingEnabled"
					type="switch"
					data-test="switch-booking-enabled">
					<!-- TRANSLATORS: Switch label — turns the scheduling feature on (German: "Planung aktivieren"). -->
					{{ t('attendance', 'Enable scheduling') }}
				</NcCheckboxRadioSwitch>
			</NcSettingsSection>

			<NcSettingsSection id="display-options"
				:name="t('attendance', 'Display options')"
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

			<NcSettingsSection id="mobile-apps"
				:name="t('attendance', 'Mobile apps')"
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
								<!-- TRANSLATORS: Verb (imperative) — button that opens the app-store link in a new browser tab. -->
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
									variant="secondary"
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

			<NcSettingsSection id="guests"
				:name="t('attendance', 'Guest invitation')"
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
					<p class="guests-warning-or">
						{{ t('attendance', 'or') }}
					</p>
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

				<template v-if="guestsApp.enabled">
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
				</template>
			</NcSettingsSection>
		</template>
	</div>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcInputField,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcSettingsSection,
} from '@nextcloud/vue'
import QRCode from 'qrcode'
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import AccountStar from 'vue-material-design-icons/AccountStar.vue'
import AppleIcon from 'vue-material-design-icons/Apple.vue'
import BellRingIcon from 'vue-material-design-icons/BellRing.vue'
import CalendarSyncIcon from 'vue-material-design-icons/CalendarSync.vue'
import CellphoneCheck from 'vue-material-design-icons/CellphoneCheck.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Download from 'vue-material-design-icons/Download.vue'
import GoogleIcon from 'vue-material-design-icons/Google.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import PermissionRow from '../components/admin/PermissionRow.vue'
import GroupSelect from '../components/common/GroupSelect.vue'
import { copyToClipboard } from '../utils/clipboard.js'
import { formatDate, formatDateTimeMedium } from '../utils/datetime.js'
import { APPLE_STORE_URL, GOOGLE_STORE_URL } from '../utils/mobileApp.js'

const mobileAppStores = [
	{ id: 'apple', icon: AppleIcon, url: APPLE_STORE_URL, label: t('attendance', 'App Store (iOS)') },
	{ id: 'google', icon: GoogleIcon, url: GOOGLE_STORE_URL, label: t('attendance', 'Google Play (Android)') },
]

const navSections = [
	{ id: 'permissions', label: t('attendance', 'Permissions') },
	{ id: 'response-summary', label: t('attendance', 'Response summary') },
	{ id: 'self-checkin', label: t('attendance', 'Self-check-in') },
	{ id: 'reminders', label: t('attendance', 'Appointment reminders') },
	{ id: 'calendar-sync', label: t('attendance', 'Calendar sync') },
	{ id: 'org-calendar', label: t('attendance', 'Organization calendar') },
	{ id: 'audit-log', label: t('attendance', 'Audit log') },
	{ id: 'scheduling', label: t('attendance', 'Scheduling') },
	{ id: 'display-options', label: t('attendance', 'Display options') },
	{ id: 'mobile-apps', label: t('attendance', 'Mobile apps') },
	{ id: 'guests', label: t('attendance', 'Guest invitation') },
]

const permissionGroups = [
	{
		key: 'appointments',
		label: t('attendance', 'Appointments'),
		rows: [
			{
				name: 'manage_appointments',
				title: t('attendance', 'Manage appointments'),
				hint: t('attendance', 'Create, edit and delete any appointment and manage its responses.'),
				warningWhenAll: t('attendance', 'Every user can create, edit and delete all appointments.'),
			},
			{
				name: 'create_appointments',
				title: t('attendance', 'Create own appointments'),
				hint: t('attendance', 'Create appointments and manage them as organizer.'),
				implication: t('attendance', 'Users who can manage appointments can always create appointments.'),
			},
		],
	},
	{
		key: 'responses',
		label: t('attendance', 'Responses'),
		rows: [
			{
				name: 'see_response_overview',
				title: t('attendance', 'See response & check-in summary'),
				hint: t('attendance', 'See the full response and check-in summary, including names.'),
				implication: t('attendance', 'Organizers always see the summary of their own appointments.'),
			},
			{
				name: 'see_response_counts',
				title: t('attendance', 'See response counts'),
				hint: t('attendance', 'See how many people answered yes, no or maybe — without any names.'),
				implication: t('attendance', 'Users who see the full summary always see the counts.'),
			},
			{
				name: 'see_comments',
				title: t('attendance', 'See comments'),
				hint: t('attendance', 'See comments in the response overview.'),
			},
			{
				name: 'respond_for_others',
				title: t('attendance', 'Set responses for other users'),
				hint: t('attendance', 'Record or clear an answer on behalf of another person.'),
				implication: t('attendance', 'Not granted automatically to users who can manage appointments.'),
			},
		],
	},
	{
		key: 'checkin',
		label: t('attendance', 'Check-in'),
		rows: [
			{
				name: 'checkin',
				title: t('attendance', 'Check-in access'),
				hint: t('attendance', 'Access the check-in interface and check in attendees.'),
			},
			{
				name: 'self_checkin',
				title: t('attendance', 'Self-check-in'),
				hint: t('attendance', 'Check in themselves via QR code, NFC tag or deep link.'),
				implication: t('attendance', 'Set up QR codes and NFC tags in the Self-check-in section.'),
			},
		],
	},
]

const PERMISSION_NAMES = permissionGroups.flatMap((group) => group.rows.map((row) => row.name))

// State
const availableGroups = ref([])
const selectedGroups = ref([])
const selectedTeams = ref([])
const teamSearchResults = ref([])
const isSearchingTeams = ref(false)
const teamsAvailable = ref(false)
const permissions = ref(Object.fromEntries(PERMISSION_NAMES.map((name) => [name, { mode: 'all', groups: [] }])))
const selfCheckinWindowMinutes = ref(30)
const qrDataUrl = ref(null)
const selfCheckinUrl = window.location.origin + generateUrl('/apps/attendance/self-checkin')
const remindersEnabled = ref(false)
const reminderDays = ref(7)
const reminderFrequency = ref(0)
const reminderTarget = ref('non_responders')
const notificationsAppEnabled = ref(true)
const nextAppointment = ref(null)
const nextReminderRun = ref(null)
const calendarSyncEnabled = ref(false)
const calendarAvailable = ref(false)
const orgCalendarEnabled = ref(false)
const selectedOrgCalendar = ref(null)
const orgCalendarUserId = ref(null)
const writableCalendars = ref([])
const auditLogEnabled = ref(true)
const auditLogVisibility = ref('managers')
const pushEnabled = ref(true)
const mobileAppBannerEnabled = ref(true)
const bookingEnabled = ref(false)
const displayOrder = ref('name_first')
const pushDeviceCount = ref(0)
const loadingData = ref(true)
const sendingTestReminder = ref(false)
const syncingOrgCalendar = ref(false)
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

// Keep a stored calendar selectable even when the current admin cannot see it
// (it was picked by a different admin) — otherwise saving would silently drop it.
const orgCalendarOptions = computed(() => {
	const options = [...writableCalendars.value]
	const selected = selectedOrgCalendar.value
	if (selected?.uri && !options.some((c) => c.uri === selected.uri)) {
		options.unshift(selected)
	}
	return options
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

		while (current <= appointmentDay) {
			const daysBefore = Math.round((appointmentDay - current) / (1000 * 60 * 60 * 24))
			dates.push({ date: new Date(current), daysBefore, isFirst, isSingle: false })
			current.setDate(current.getDate() + frequency)
			isFirst = false
		}
	}

	return dates
})

// Auto-save
// Every mutation posts a partial payload; the backend leaves omitted settings
// untouched. Saves are debounced per key so rapid edits collapse into one.
let suppressSaves = true
const saveTimers = {}

function queueSave(key, payloadFactory, delay = 0) {
	if (suppressSaves) return
	clearTimeout(saveTimers[key])
	saveTimers[key] = setTimeout(async () => {
		delete saveTimers[key]
		const payload = payloadFactory()
		if (!payload) return
		try {
			await axios.post(generateUrl('/apps/attendance/api/admin/settings'), payload)
			showSuccess(window.t('attendance', 'Settings saved'))
		} catch (error) {
			console.error('Error saving settings:', error)
			showError(window.t('attendance', 'Failed to save settings'))
		}
	}, delay)
}

function autoSave(source, key, payloadFactory, delay = 0) {
	watch(source, () => queueSave(key, payloadFactory, delay), { deep: true })
}

const SELECT_DEBOUNCE = 800

autoSave(
	selectedGroups,
	'whitelistedGroups',
	() => ({ whitelistedGroups: selectedGroups.value.map((g) => g.id) }),
	SELECT_DEBOUNCE,
)
autoSave(
	selectedTeams,
	'whitelistedTeams',
	() => ({ whitelistedTeams: selectedTeams.value.map((team) => team.id) }),
	SELECT_DEBOUNCE,
)
// Per permission, not the whole map: the backend leaves omitted permissions
// untouched, so concurrent admins and stale local state cannot overwrite
// settings the user never edited.
function onPermissionChange(name, value) {
	permissions.value[name] = value
	queueSave(`permission:${name}`, () => ({
		permissions: {
			[name]: {
				mode: permissions.value[name].mode,
				groups: permissions.value[name].groups.map((g) => g.id),
			},
		},
	}), SELECT_DEBOUNCE)
}
autoSave(selfCheckinWindowMinutes, 'selfCheckinWindowMinutes', () => {
	if (!Number.isFinite(selfCheckinWindowMinutes.value)) return null
	return { selfCheckinWindowMinutes: selfCheckinWindowMinutes.value }
}, SELECT_DEBOUNCE)
autoSave([remindersEnabled, reminderDays, reminderFrequency, reminderTarget], 'reminders', () => {
	const reminders = {
		enabled: remindersEnabled.value,
		reminderTarget: reminderTarget.value,
	}
	if (Number.isFinite(reminderDays.value)) reminders.reminderDays = reminderDays.value
	if (Number.isFinite(reminderFrequency.value)) reminders.reminderFrequency = reminderFrequency.value
	return { reminders }
}, SELECT_DEBOUNCE)
autoSave(
	calendarSyncEnabled,
	'calendarSync',
	() => ({ calendarSync: { enabled: calendarSyncEnabled.value } }),
)
// Debounced although these are discrete clicks: saving triggers the calendar
// backfill server-side, so enable + calendar pick should collapse into one run.
autoSave([orgCalendarEnabled, selectedOrgCalendar], 'orgCalendar', () => ({
	orgCalendar: {
		enabled: orgCalendarEnabled.value,
		...(selectedOrgCalendar.value?.uri ? { calendarUri: selectedOrgCalendar.value.uri } : {}),
	},
}), SELECT_DEBOUNCE)
autoSave(
	[auditLogEnabled, auditLogVisibility],
	'audit',
	() => ({ audit: { enabled: auditLogEnabled.value, visibility: auditLogVisibility.value } }),
)
autoSave(displayOrder, 'displayOrder', () => ({ displayOrder: displayOrder.value }))
autoSave(pushEnabled, 'pushEnabled', () => ({ pushEnabled: pushEnabled.value }))
autoSave(
	mobileAppBannerEnabled,
	'mobileAppBannerEnabled',
	() => ({ mobileAppBannerEnabled: mobileAppBannerEnabled.value }),
)
autoSave(bookingEnabled, 'bookingEnabled', () => ({ bookingEnabled: bookingEnabled.value }))

// Methods
function scrollToSection(id) {
	const element = document.getElementById(id)
	if (element) {
		element.scrollIntoView({ behavior: 'smooth' })
		window.history.replaceState(null, '', `#${id}`)
	}
}

async function loadSettings() {
	loadingData.value = true
	suppressSaves = true

	try {
		const [settingsRes, capabilitiesRes] = await Promise.all([
			axios.get(generateUrl('/apps/attendance/api/admin/settings')),
			axios.get(generateUrl('/apps/attendance/api/capabilities')),
		])

		const { config, status, groups } = settingsRes.data
		const caps = capabilitiesRes.data

		availableGroups.value = groups
		// Convert stored IDs to group objects for NcSelect, preserving database order
		const groupsById = new Map(groups.map((group) => [group.id, group]))
		const toGroupObjects = (ids) => (ids || [])
			.map((id) => groupsById.get(id))
			.filter((group) => group !== undefined)

		selectedGroups.value = toGroupObjects(config.whitelistedGroups)

		// Load teams settings
		teamsAvailable.value = caps.teamsAvailable || false
		if (config.whitelistedTeams) {
			selectedTeams.value = config.whitelistedTeams
			// Also add to search results so they appear in the dropdown
			teamSearchResults.value = [...config.whitelistedTeams]
		}

		// Load permission settings
		if (config.permissions) {
			for (const name of PERMISSION_NAMES) {
				const setting = config.permissions[name]
				if (!setting) continue
				permissions.value[name] = {
					mode: setting.mode,
					groups: toGroupObjects(setting.groups),
				}
			}
		}

		selfCheckinWindowMinutes.value = config.selfCheckinWindowMinutes ?? 30

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
		calendarAvailable.value = caps.calendarAvailable || false

		// Load organization calendar settings
		writableCalendars.value = settingsRes.data.writableCalendars || []
		if (config.orgCalendar) {
			orgCalendarEnabled.value = config.orgCalendar.enabled || false
			orgCalendarUserId.value = config.orgCalendar.userId || null
			const storedUri = config.orgCalendar.calendarUri
			if (storedUri) {
				selectedOrgCalendar.value = writableCalendars.value.find((c) => c.uri === storedUri)
					|| { uri: storedUri, displayName: storedUri }
			}
		}

		// Load audit log settings
		if (config.audit) {
			auditLogEnabled.value = config.audit.enabled !== false
			auditLogVisibility.value = config.audit.visibility || 'managers'
		}

		// Load display order
		displayOrder.value = config.displayOrder || 'name_first'

		// Load push notifications
		pushEnabled.value = config.pushEnabled !== false
		pushDeviceCount.value = status.pushDeviceCount || 0

		// Load mobile app banner setting
		mobileAppBannerEnabled.value = config.mobileAppBannerEnabled !== false

		// Load planning / booking setting (opt-in, defaults off)
		bookingEnabled.value = config.bookingEnabled === true

		// Load guests app status (for whitelist warning)
		if (config.guestsApp) {
			guestsApp.value = config.guestsApp
		}
	} catch (error) {
		console.error('Error loading settings:', error)
		showError(window.t('attendance', 'Failed to load settings'))
	} finally {
		loadingData.value = false
		// Let the watchers flush the load mutations before arming auto-save
		await nextTick()
		suppressSaves = false
	}
}

async function searchTeams(query) {
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
			.filter((item) => item.type === 'team')
			.map((item) => ({ id: item.id, label: item.label, type: 'team' }))

		// Merge with selected teams to keep them visible
		const selectedIds = selectedTeams.value.map((t) => t.id)
		const newTeams = teams.filter((t) => !selectedIds.includes(t.id))
		teamSearchResults.value = [...selectedTeams.value, ...newTeams]
	} catch (error) {
		console.error('Error searching teams:', error)
	} finally {
		isSearchingTeams.value = false
	}
}

async function generateQrCode() {
	try {
		qrDataUrl.value = await QRCode.toDataURL(selfCheckinUrl, { width: 512, margin: 2 })
	} catch (error) {
		console.error('Error generating QR code:', error)
	}
}

function downloadQrCode() {
	if (!qrDataUrl.value) return
	const link = document.createElement('a')
	link.href = qrDataUrl.value
	link.download = 'attendance-self-checkin-qr.png'
	link.click()
}

const copySelfCheckinUrl = () => copyStoreUrl(selfCheckinUrl)

function copyStoreUrl(url) {
	return copyToClipboard(url, {
		successMessage: window.t('attendance', 'Link copied'),
		errorMessage: window.t('attendance', 'Failed to copy link'),
	})
}

function copyGuestsOccCommand() {
	return copyToClipboard(
		guestsWhitelistOccCommand,
		{
			successMessage: window.t('attendance', 'Command copied'),
			errorMessage: window.t('attendance', 'Failed to copy command'),
		},
	)
}

async function syncOrgCalendar() {
	syncingOrgCalendar.value = true
	try {
		const response = await axios.post(generateUrl('/apps/attendance/api/admin/org-calendar/sync'))
		const count = response.data.synced ?? 0
		showSuccess(window.n('attendance', '%n appointment synced to the calendar', '%n appointments synced to the calendar', count))
	} catch (error) {
		console.error('Error syncing organization calendar:', error)
		showError(window.t('attendance', 'Failed to sync appointments to the calendar'))
	} finally {
		syncingOrgCalendar.value = false
	}
}

async function sendTestReminder() {
	sendingTestReminder.value = true
	try {
		const response = await axios.post(generateUrl('/apps/attendance/api/admin/test-reminder'))
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
	generateQrCode()
	await loadSettings()

	// Handle hash navigation after content is loaded
	await nextTick()
	if (window.location.hash) {
		scrollToSection(window.location.hash.slice(1))
	}
})
</script>

<style scoped>
#attendance-admin-settings {
	padding: 20px;
	max-width: 900px;
}

.anchor-nav {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 12px;
}

.anchor-nav__link {
	padding: 6px 14px;
	border-radius: var(--border-radius-pill);
	background-color: var(--color-background-dark);
	color: var(--color-main-text);
	font-size: 13px;
	text-decoration: none;
	white-space: nowrap;
}

.anchor-nav__link:hover,
.anchor-nav__link:focus {
	background-color: var(--color-primary-element-light);
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

.permission-group {
	margin-bottom: 32px;
}

.permission-group:last-child {
	margin-bottom: 0;
}

.permission-group__title {
	margin: 0 0 4px 0;
	padding-bottom: 6px;
	border-bottom: 1px solid var(--color-border-dark);
	font-size: 16px;
	font-weight: 700;
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

.self-checkin-window-field {
	max-width: 400px;
	/* The floating label sits above the input border, so it needs extra
	   room to not collide with the section description above. */
	margin-top: 12px;
}

.self-checkin-qr {
	margin-top: 20px;

	h6 {
		font-weight: 600;
		margin: 16px 0 4px;
	}

	.self-checkin-qr__image {
		width: 180px;
		height: 180px;
		border-radius: var(--border-radius-large);
		background: #fff;
		padding: 8px;
		box-sizing: content-box;
	}

	.self-checkin-qr__actions {
		display: flex;
		gap: 8px;
		flex-wrap: wrap;
		margin: 8px 0;
	}

	.self-checkin-qr__app-link {
		color: var(--color-primary-element);
		text-decoration: underline;
	}
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
