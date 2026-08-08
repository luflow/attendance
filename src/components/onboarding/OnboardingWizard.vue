<template>
	<NcDialog :open="open"
		size="large"
		:name="t('attendance', 'Set up Attendance')"
		data-test="onboarding-wizard"
		@update:open="handleClose">
		<div class="onboarding">
			<div class="onboarding__progress">
				<span class="onboarding__step-count">
					{{ t('attendance', 'Step {current} of {total}', { current: stepIndex + 1, total: STEPS.length }) }}
				</span>
				<span class="onboarding__step-label">{{ currentStep.label }}</span>
				<NcProgressBar :value="progressPercent" size="medium" />
			</div>

			<LoadingState v-if="loading" :text="t('attendance', 'Loading settings …')" />

			<div v-else class="onboarding__body">
				<p v-if="currentStep.lead" class="onboarding__lead">
					{{ currentStep.lead }}
				</p>

				<NcNoteCard v-if="currentStep.id === 'self-checkin'" type="info">
					{{ t('attendance', 'Self-check-in only works with the Attendance mobile app.') }}
				</NcNoteCard>

				<PermissionRow v-for="name in currentStep.permissions || []"
					:key="name"
					:modelValue="permissions[name]"
					:title="PERMISSION_ROWS[name].title"
					:hint="PERMISSION_ROWS[name].hint"
					:implication="PERMISSION_ROWS[name].implication"
					:warningWhenAll="PERMISSION_ROWS[name].warningWhenAll"
					:options="availableGroups"
					:dataTest="`onboarding-permission-${name}`"
					@update:modelValue="permissions[name] = $event" />

				<!-- Welcome -->
				<template v-if="currentStep.id === 'welcome'">
					<ul class="onboarding__topics">
						<li v-for="topic in welcomeTopics" :key="topic">
							{{ topic }}
						</li>
					</ul>
					<NcNoteCard type="info">
						{{ t('attendance', 'Every step is saved as soon as you continue. You can stop any time and pick up later from the admin settings.') }}
					</NcNoteCard>
				</template>

				<!-- Response summary groups -->
				<template v-else-if="currentStep.id === 'groups'">
					<p>{{ t('attendance', 'Pick the groups that should get their own section. Everyone else appears under Others. Leave it empty to include all groups.') }}</p>
					<GroupSelect
						v-model="whitelistedGroups"
						:options="availableGroups"
						:placeholder="t('attendance', 'Select groups …')"
						data-test="onboarding-summary-groups" />
				</template>

				<!-- Audit log -->
				<template v-else-if="currentStep.id === 'audit'">
					<NcCheckboxRadioSwitch v-model="audit.enabled"
						type="switch"
						data-test="onboarding-audit-enabled">
						{{ t('attendance', 'Enable audit log') }}
					</NcCheckboxRadioSwitch>
					<p class="onboarding__hint">
						{{ t('attendance', 'Disabling stops new events from being recorded and silences response notifications. Existing entries are kept and reappear once you re-enable.') }}
					</p>

					<div v-if="audit.enabled" class="onboarding__radio-group">
						<label class="onboarding__radio-label">{{ t('attendance', 'Who can see the audit log?') }}</label>
						<NcCheckboxRadioSwitch v-for="visibility in AUDIT_VISIBILITIES"
							:key="visibility.value"
							v-model="audit.visibility"
							type="radio"
							:value="visibility.value"
							name="onboarding-audit-visibility">
							{{ visibility.label }}
						</NcCheckboxRadioSwitch>
					</div>
				</template>

				<!-- Reminders -->
				<template v-else-if="currentStep.id === 'reminders'">
					<NcNoteCard v-if="!notificationsAppEnabled" type="warning">
						<p>{{ t('attendance', 'The Notifications app is not enabled. Please enable it to use appointment reminders.') }}</p>
						<p class="onboarding__hint">
							{{ t('attendance', 'You can enable it in the Apps section of your Nextcloud settings.') }}
						</p>
					</NcNoteCard>
					<template v-else>
						<NcCheckboxRadioSwitch v-model="reminders.enabled"
							type="switch"
							data-test="onboarding-reminders-enabled">
							{{ t('attendance', 'Enable automatic reminders') }}
						</NcCheckboxRadioSwitch>

						<div v-if="reminders.enabled" class="onboarding__fields">
							<NcInputField v-model.number="reminders.reminderDays"
								type="number"
								:label="t('attendance', 'Days before appointment')"
								:helperText="t('attendance', 'Send reminders this many days before the appointment (1-30 days)')"
								data-test="onboarding-reminder-days"
								:inputProps="{ min: 1, max: 30 }" />
							<NcInputField v-model.number="reminders.reminderFrequency"
								type="number"
								:label="t('attendance', 'Reminder frequency (days)')"
								:helperText="t('attendance', 'How often to remind users who haven\'t responded. Set to 0 to only remind once, or 1-30 to repeat reminders every N days.')"
								data-test="onboarding-reminder-frequency"
								:inputProps="{ min: 0, max: 30 }" />
							<div class="onboarding__radio-group">
								<label class="onboarding__radio-label">{{ t('attendance', 'Remind recipients') }}</label>
								<NcCheckboxRadioSwitch v-for="target in REMINDER_TARGETS"
									:key="target.value"
									v-model="reminders.reminderTarget"
									type="radio"
									:value="target.value"
									name="onboarding-reminder-target">
									{{ target.label }}
								</NcCheckboxRadioSwitch>
							</div>
						</div>
					</template>
				</template>

				<!-- Self-check-in -->
				<template v-else-if="currentStep.id === 'self-checkin'">
					<div v-if="permissions.self_checkin.mode !== 'nobody'" class="onboarding__fields">
						<NcInputField v-model.number="selfCheckinWindowMinutes"
							type="number"
							:label="t('attendance', 'Check-in window (minutes before start)')"
							:helperText="t('attendance', 'How many minutes before an appointment starts attendees can check in. The window always closes when the appointment ends.')"
							data-test="onboarding-self-checkin-window"
							:inputProps="{ min: 0, max: 1440 }" />
					</div>
					<p class="onboarding__hint">
						{{ t('attendance', 'The QR code to print and the URL for NFC tags are waiting in the admin settings.') }}
					</p>
				</template>

				<!-- More settings -->
				<template v-else-if="currentStep.id === 'more-settings'">
					<ul class="onboarding__features">
						<li v-for="feature in moreSettings" :key="feature.title">
							<component :is="feature.icon" :size="20" class="onboarding__benefit-icon" />
							<span>
								<strong>{{ feature.title }}</strong>
								<span>{{ feature.text }}</span>
							</span>
						</li>
					</ul>
					<!-- New tab, so following the link does not abandon the last step -->
					<NcButton variant="secondary"
						:href="adminSettingsUrl"
						target="_blank"
						rel="noopener"
						data-test="onboarding-open-admin-settings">
						<template #icon>
							<OpenInNewIcon :size="20" />
						</template>
						{{ t('attendance', 'Open admin settings') }}
					</NcButton>
				</template>

				<!-- Mobile app -->
				<template v-else-if="currentStep.id === 'mobile'">
					<ul class="onboarding__benefits">
						<li v-for="benefit in mobileBenefits" :key="benefit.text">
							<component :is="benefit.icon" :size="20" class="onboarding__benefit-icon" />
							<span>{{ benefit.text }}</span>
						</li>
					</ul>

					<div class="onboarding__stores">
						<NcButton v-for="store in MOBILE_APP_STORES"
							:key="store.id"
							variant="primary"
							:href="store.url"
							target="_blank"
							rel="noopener"
							:data-test="`onboarding-store-${store.id}`">
							<template #icon>
								<component :is="store.icon" :size="20" />
							</template>
							{{ store.label }}
						</NcButton>
					</div>

					<NcCheckboxRadioSwitch v-model="pushEnabled"
						type="switch"
						data-test="onboarding-push-enabled">
						{{ t('attendance', 'Enable push notifications') }}
					</NcCheckboxRadioSwitch>
				</template>
			</div>
		</div>

		<template #actions>
			<NcButton variant="tertiary"
				data-test="onboarding-close"
				@click="handleClose(false)">
				{{ isLastStep ? t('attendance', 'Close') : t('attendance', 'Finish later') }}
			</NcButton>
			<NcButton v-if="stepIndex > 0"
				variant="secondary"
				:disabled="saving"
				data-test="onboarding-back"
				@click="stepIndex--">
				{{ t('attendance', 'Back') }}
			</NcButton>
			<NcButton variant="primary"
				:disabled="loading || saving"
				data-test="onboarding-next"
				@click="goNext">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ isLastStep ? t('attendance', 'Done') : t('attendance', 'Continue') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcInputField,
	NcLoadingIcon,
	NcNoteCard,
	NcProgressBar,
} from '@nextcloud/vue'
import { computed, reactive, ref, watch } from 'vue'
import AccountPlusIcon from 'vue-material-design-icons/AccountPlus.vue'
import BellRingIcon from 'vue-material-design-icons/BellRing.vue'
import CalendarMultipleIcon from 'vue-material-design-icons/CalendarMultiple.vue'
import CalendarSyncIcon from 'vue-material-design-icons/CalendarSync.vue'
import ClipboardCheckIcon from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import FormatListBulletedIcon from 'vue-material-design-icons/FormatListBulleted.vue'
import LightningBoltIcon from 'vue-material-design-icons/LightningBolt.vue'
import NfcIcon from 'vue-material-design-icons/Nfc.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import TagIcon from 'vue-material-design-icons/TagOutline.vue'
import WifiOffIcon from 'vue-material-design-icons/WifiOff.vue'
import PermissionRow from '../admin/PermissionRow.vue'
import GroupSelect from '../common/GroupSelect.vue'
import LoadingState from '../common/LoadingState.vue'
import { toGroupObjects } from '../../utils/groups.js'
import { MOBILE_APP_STORES } from '../../utils/mobileApp.js'
import { AUDIT_VISIBILITIES, emptyPermissionState, PERMISSION_ROWS, permissionPayload, REMINDER_TARGETS } from '../../utils/permissions.js'

const props = defineProps({
	open: {
		type: Boolean,
		default: false,
	},
	/** Both hosts already hold this, so the wizard does not refetch capabilities */
	notificationsAppEnabled: {
		type: Boolean,
		required: true,
	},
})

const emit = defineEmits(['close', 'saved'])

const stepIndex = ref(0)
const loading = ref(true)
const saving = ref(false)
const savedAnything = ref(false)
const availableGroups = ref([])
const whitelistedGroups = ref([])
const permissions = reactive(emptyPermissionState())
const reminders = reactive({ enabled: false, reminderDays: 7, reminderFrequency: 0, reminderTarget: 'non_responders' })
const audit = reactive({ enabled: true, visibility: 'managers' })
const selfCheckinWindowMinutes = ref(30)
const pushEnabled = ref(true)

// `permissions` names the rows a step edits; `extra` builds whatever else it
// owns. Both feed the same save, so a step's rows and its payload cannot drift.
const STEPS = [
	{
		id: 'welcome',
		label: t('attendance', 'Welcome'),
		lead: t('attendance', 'Attendance collects replies for your appointments, records who actually turned up, and breaks it all down by your Nextcloud groups. The next steps set up who may do what — on a fresh installation most of it is open to every user.'),
	},
	{
		id: 'appointments',
		label: t('attendance', 'Appointments'),
		lead: t('attendance', 'Someone has to create the appointments people reply to. Usually that is a small group — a board, a team of trainers, the office.'),
		permissions: ['manage_appointments', 'create_appointments'],
	},
	{
		id: 'checkin',
		label: t('attendance', 'Check-in'),
		lead: t('attendance', 'At the appointment itself, check-in records who really showed up. The check-in screen shows every attendee with a single tick per person.'),
		permissions: ['checkin'],
	},
	{
		id: 'responses',
		label: t('attendance', 'Responses'),
		lead: t('attendance', 'Who gets to see the replies of everyone else? The detailed summary shows names, the counts show only how many said yes, no or maybe.'),
		permissions: ['see_response_overview', 'see_response_counts', 'see_comments'],
	},
	{
		id: 'audit',
		label: t('attendance', 'Audit log'),
		lead: t('attendance', 'Attendance can record every reply, change, withdrawal and check-in — who did what, when, and from where. The timeline shows up on each appointment, and it is what lets managers get a push notification when somebody changes their mind.'),
		extra: () => ({ audit: { enabled: audit.enabled, visibility: audit.visibility } }),
	},
	{
		id: 'groups',
		label: t('attendance', 'Response summary groups'),
		lead: t('attendance', 'The response summary splits attendees by Nextcloud group — sopranos, altos, tenors, basses, or whatever your groups are called.'),
		extra: () => ({ whitelistedGroups: whitelistedGroups.value.map((group) => group.id) }),
	},
	{
		id: 'reminders',
		label: t('attendance', 'Appointment reminders'),
		lead: t('attendance', 'People forget to reply. Attendance can nudge them automatically before the appointment, so you do not have to chase anyone.'),
		extra: () => ({
			reminders: {
				enabled: reminders.enabled,
				reminderTarget: reminders.reminderTarget,
				...(Number.isFinite(reminders.reminderDays) ? { reminderDays: reminders.reminderDays } : {}),
				...(Number.isFinite(reminders.reminderFrequency) ? { reminderFrequency: reminders.reminderFrequency } : {}),
			},
		}),
	},
	{
		id: 'self-checkin',
		label: t('attendance', 'Self-check-in'),
		lead: t('attendance', 'Instead of one person ticking off a list, attendees can check themselves in by scanning a QR code or tapping an NFC tag at the entrance. One code works for all appointments — the app matches by time.'),
		permissions: ['self_checkin'],
		extra: () => (Number.isFinite(selfCheckinWindowMinutes.value)
			? { selfCheckinWindowMinutes: selfCheckinWindowMinutes.value }
			: {}),
	},
	{
		id: 'more-settings',
		label: t('attendance', 'More settings'),
		lead: t('attendance', 'That covers the essentials. These wait for you in the admin settings, and are worth a look once your first appointments are running.'),
	},
	{
		id: 'mobile',
		label: t('attendance', 'Mobile app'),
		lead: t('attendance', 'Attendance has a mobile app for iOS and Android. It is the difference between people replying and people meaning to reply.'),
		extra: () => ({
			pushEnabled: pushEnabled.value,
			// Last step — from here admins get the "create your first appointment"
			// prompt that creators without admin rights see from the start.
			onboardingCompleted: true,
		}),
	},
]

const welcomeTopics = [
	t('attendance', 'Who may create appointments'),
	t('attendance', 'Who may check attendees in'),
	t('attendance', 'Who may see the replies'),
	t('attendance', 'Automatic reminders for people who have not replied'),
]

// Titles match the admin settings section headings so they can be found again.
const moreSettings = [
	{
		icon: TagIcon,
		title: t('attendance', 'Categories'),
		text: t('attendance', 'Classify appointments — rehearsal, concert, sectional, board meeting — each with its own icon.'),
	},
	{
		icon: CalendarSyncIcon,
		title: t('attendance', 'Calendar sync'),
		text: t('attendance', 'Pull events out of Nextcloud Calendar instead of typing them again, and keep them in sync afterwards.'),
	},
	{
		icon: CalendarMultipleIcon,
		title: t('attendance', 'Organization calendar'),
		text: t('attendance', 'Write every appointment into a shared calendar, so the whole group sees it in the Calendar app.'),
	},
	{
		icon: ClipboardCheckIcon,
		title: t('attendance', 'Scheduling'),
		text: t('attendance', 'Mark the people you actually need out of everyone who said yes, and notify exactly those.'),
	},
	{
		icon: AccountPlusIcon,
		title: t('attendance', 'Guest invitation'),
		text: t('attendance', 'Invite people without a Nextcloud account by email address.'),
	},
	{
		icon: FormatListBulletedIcon,
		title: t('attendance', 'Display options'),
		text: t('attendance', 'Whether appointment lists lead with the name or the date.'),
	},
]

const mobileBenefits = [
	{ icon: BellRingIcon, text: t('attendance', 'Push notifications for new appointments and reminders — replies arrive in minutes instead of days.') },
	{ icon: LightningBoltIcon, text: t('attendance', 'Reply with one tap from the notification, without opening a browser or logging in again.') },
	{ icon: NfcIcon, text: t('attendance', 'Check-in by QR code and NFC tag — including writing the tags themselves.') },
	{ icon: WifiOffIcon, text: t('attendance', 'Appointments stay readable without a connection, which matters in rehearsal rooms and gyms.') },
]

const adminSettingsUrl = generateUrl('/settings/admin/attendance')
const currentStep = computed(() => STEPS[stepIndex.value])
const isLastStep = computed(() => stepIndex.value === STEPS.length - 1)
const progressPercent = computed(() => ((stepIndex.value + 1) / STEPS.length) * 100)

async function loadSettings() {
	loading.value = true
	try {
		const { data } = await axios.get(generateUrl('/apps/attendance/api/admin/settings'))
		const config = data.config
		const groups = data.groups || []
		availableGroups.value = groups

		whitelistedGroups.value = toGroupObjects(config.whitelistedGroups, groups)

		for (const [name, setting] of Object.entries(config.permissions || {})) {
			if (permissions[name]) {
				permissions[name] = { mode: setting.mode, groups: toGroupObjects(setting.groups, groups) }
			}
		}

		reminders.enabled = config.reminders.enabled || false
		reminders.reminderDays = config.reminders.reminderDays || 7
		reminders.reminderFrequency = config.reminders.reminderFrequency || 0
		reminders.reminderTarget = config.reminders.reminderTarget || 'non_responders'
		audit.enabled = config.audit.enabled !== false
		audit.visibility = config.audit.visibility || 'managers'
		selfCheckinWindowMinutes.value = config.selfCheckinWindowMinutes ?? 30
		pushEnabled.value = config.pushEnabled !== false
	} catch (error) {
		console.error('Failed to load onboarding settings:', error)
		showError(t('attendance', 'Could not load settings'))
	} finally {
		loading.value = false
	}
}

/**
 * Persist the current step. Returns false when the write failed, so the
 * wizard can stay put instead of pretending the setting was stored.
 *
 * @return {Promise<boolean>} whether the step was saved
 */
async function saveCurrentStep() {
	const step = currentStep.value
	const payload = {
		...(step.permissions ? { permissions: permissionPayload(permissions, step.permissions) } : {}),
		...(step.extra ? step.extra() : {}),
	}
	if (!Object.keys(payload).length) {
		return true
	}

	saving.value = true
	try {
		await axios.post(generateUrl('/apps/attendance/api/admin/settings'), payload)
		savedAnything.value = true
		return true
	} catch (error) {
		console.error('Failed to save onboarding step:', error)
		showError(t('attendance', 'Could not save settings'))
		return false
	} finally {
		saving.value = false
	}
}

async function goNext() {
	if (!await saveCurrentStep()) {
		return
	}
	if (isLastStep.value) {
		handleClose(false)
		return
	}
	stepIndex.value++
}

// Steps save as they go, so leaving early still dirties the shared settings —
// hosts learn that from `saved`, never from the bare close.
function handleClose(isOpen) {
	if (isOpen) {
		return
	}
	if (savedAnything.value) {
		emit('saved')
	}
	emit('close')
}

watch(() => props.open, (open) => {
	if (open) {
		stepIndex.value = 0
		savedAnything.value = false
		loadSettings()
	}
}, { immediate: true })
</script>

<style scoped>
.onboarding {
	display: flex;
	flex-direction: column;
	gap: 20px;
	min-height: 360px;
}

.onboarding__progress {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.onboarding__step-count {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.onboarding__step-label {
	font-weight: 600;
	font-size: 17px;
	margin-bottom: 4px;
}

.onboarding__body {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.onboarding__lead {
	font-size: 15px;
}

.onboarding__hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.onboarding__topics,
.onboarding__benefits,
.onboarding__features {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.onboarding__topics li {
	padding-inline-start: 20px;
	position: relative;
}

.onboarding__topics li::before {
	content: '→';
	position: absolute;
	inset-inline-start: 0;
	color: var(--color-primary-element);
}

.onboarding__benefits li,
.onboarding__features li {
	display: flex;
	align-items: flex-start;
	gap: 10px;
}

.onboarding__features li > span {
	display: flex;
	flex-direction: column;
}

.onboarding__features strong {
	font-weight: 600;
}

.onboarding__features li > span > span {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.onboarding__benefit-icon {
	flex-shrink: 0;
	color: var(--color-primary-element);
}

.onboarding__stores {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 4px 0 12px;
}

.onboarding__fields {
	display: flex;
	flex-direction: column;
	gap: 16px;
	margin-top: 12px;
}

.onboarding__radio-group {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.onboarding__radio-label {
	font-weight: 600;
	margin-bottom: 4px;
}
</style>
