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

			<div v-if="loading" class="onboarding__loading">
				<NcLoadingIcon :size="32" />
				<p>{{ t('attendance', 'Loading settings …') }}</p>
			</div>

			<div v-else class="onboarding__body">
				<!-- 1. Welcome -->
				<template v-if="currentStep.id === 'welcome'">
					<p class="onboarding__lead">
						{{ t('attendance', 'Attendance collects replies for your appointments, records who actually turned up, and breaks it all down by your Nextcloud groups.') }}
					</p>
					<p>{{ t('attendance', 'The next steps set up who may do what. On a fresh installation most of it is open to every user, so it is worth a minute.') }}</p>
					<ul class="onboarding__topics">
						<li v-for="topic in welcomeTopics" :key="topic">
							{{ topic }}
						</li>
					</ul>
					<NcNoteCard type="info">
						{{ t('attendance', 'Every step is saved as soon as you continue. You can stop any time and pick up later from the admin settings.') }}
					</NcNoteCard>
				</template>

				<!-- 2. Appointments -->
				<template v-else-if="currentStep.id === 'appointments'">
					<p class="onboarding__lead">
						{{ t('attendance', 'Someone has to create the appointments people reply to. Usually that is a small group — a board, a team of trainers, the office.') }}
					</p>
					<PermissionRow v-for="row in permissionRows.appointments"
						:key="row.name"
						:modelValue="permissions[row.name]"
						:title="row.title"
						:hint="row.hint"
						:implication="row.implication"
						:warningWhenAll="row.warningWhenAll"
						:options="availableGroups"
						:dataTest="`onboarding-permission-${row.name}`"
						@update:modelValue="permissions[row.name] = $event" />
				</template>

				<!-- 3. Check-in -->
				<template v-else-if="currentStep.id === 'checkin'">
					<p class="onboarding__lead">
						{{ t('attendance', 'At the appointment itself, check-in records who really showed up. The check-in screen shows every attendee with a single tick per person.') }}
					</p>
					<PermissionRow v-for="row in permissionRows.checkin"
						:key="row.name"
						:modelValue="permissions[row.name]"
						:title="row.title"
						:hint="row.hint"
						:warningWhenAll="row.warningWhenAll"
						:options="availableGroups"
						:dataTest="`onboarding-permission-${row.name}`"
						@update:modelValue="permissions[row.name] = $event" />
				</template>

				<!-- 4. Responses -->
				<template v-else-if="currentStep.id === 'responses'">
					<p class="onboarding__lead">
						{{ t('attendance', 'Who gets to see the replies of everyone else? The detailed summary shows names, the counts show only how many said yes, no or maybe.') }}
					</p>
					<PermissionRow v-for="row in permissionRows.responses"
						:key="row.name"
						:modelValue="permissions[row.name]"
						:title="row.title"
						:hint="row.hint"
						:implication="row.implication"
						:warningWhenAll="row.warningWhenAll"
						:options="availableGroups"
						:dataTest="`onboarding-permission-${row.name}`"
						@update:modelValue="permissions[row.name] = $event" />
				</template>

				<!-- 5. Response summary groups -->
				<template v-else-if="currentStep.id === 'groups'">
					<p class="onboarding__lead">
						{{ t('attendance', 'The response summary splits attendees by Nextcloud group — sopranos, altos, tenors, basses, or whatever your groups are called.') }}
					</p>
					<p>{{ t('attendance', 'Pick the groups that should get their own section. Everyone else appears under Others. Leave it empty to include all groups.') }}</p>
					<GroupSelect
						v-model="whitelistedGroups"
						:options="availableGroups"
						:placeholder="t('attendance', 'Select groups …')"
						data-test="onboarding-summary-groups" />
				</template>

				<!-- 6. Reminders -->
				<template v-else-if="currentStep.id === 'reminders'">
					<p class="onboarding__lead">
						{{ t('attendance', 'People forget to reply. Attendance can nudge them automatically before the appointment, so you do not have to chase anyone.') }}
					</p>
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
								<NcCheckboxRadioSwitch v-model="reminders.reminderTarget"
									type="radio"
									value="non_responders"
									name="onboarding-reminder-target">
									{{ t('attendance', 'Only users who have not responded') }}
								</NcCheckboxRadioSwitch>
								<NcCheckboxRadioSwitch v-model="reminders.reminderTarget"
									type="radio"
									value="maybe"
									name="onboarding-reminder-target">
									{{ t('attendance', 'Only users who answered maybe') }}
								</NcCheckboxRadioSwitch>
								<NcCheckboxRadioSwitch v-model="reminders.reminderTarget"
									type="radio"
									value="both"
									name="onboarding-reminder-target">
									{{ t('attendance', 'Both') }}
								</NcCheckboxRadioSwitch>
							</div>
						</div>
					</template>
				</template>

				<!-- 7. Self-check-in -->
				<template v-else-if="currentStep.id === 'self-checkin'">
					<p class="onboarding__lead">
						{{ t('attendance', 'Instead of one person ticking off a list, attendees can check themselves in by scanning a QR code or tapping an NFC tag at the entrance. One code works for all appointments — the app matches by time.') }}
					</p>
					<NcNoteCard type="info">
						{{ t('attendance', 'Self-check-in only works with the Attendance mobile app.') }}
					</NcNoteCard>
					<PermissionRow v-for="row in permissionRows.selfCheckin"
						:key="row.name"
						:modelValue="permissions[row.name]"
						:title="row.title"
						:hint="row.hint"
						:options="availableGroups"
						:dataTest="`onboarding-permission-${row.name}`"
						@update:modelValue="permissions[row.name] = $event" />
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

				<!-- 8. Mobile app -->
				<template v-else-if="currentStep.id === 'mobile'">
					<p class="onboarding__lead">
						{{ t('attendance', 'Attendance has a mobile app for iOS and Android. It is the difference between people replying and people meaning to reply.') }}
					</p>
					<ul class="onboarding__benefits">
						<li v-for="benefit in mobileBenefits" :key="benefit.text">
							<component :is="benefit.icon" :size="20" class="onboarding__benefit-icon" />
							<span>{{ benefit.text }}</span>
						</li>
					</ul>

					<div class="onboarding__stores">
						<NcButton variant="primary"
							:href="APPLE_STORE_URL"
							target="_blank"
							rel="noopener"
							data-test="onboarding-store-apple">
							<template #icon>
								<AppleIcon :size="20" />
							</template>
							{{ t('attendance', 'App Store') }}
						</NcButton>
						<NcButton variant="primary"
							:href="GOOGLE_STORE_URL"
							target="_blank"
							rel="noopener"
							data-test="onboarding-store-google">
							<template #icon>
								<GoogleIcon :size="20" />
							</template>
							{{ t('attendance', 'Google Play') }}
						</NcButton>
					</div>

					<NcCheckboxRadioSwitch v-model="pushEnabled"
						type="switch"
						data-test="onboarding-push-enabled">
						{{ t('attendance', 'Enable push notifications') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="mobileAppBannerEnabled"
						type="switch"
						data-test="onboarding-banner-enabled">
						{{ t('attendance', 'Show a banner in the web app pointing users to the mobile app') }}
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
				@click="goBack">
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
import AppleIcon from 'vue-material-design-icons/Apple.vue'
import BellRingIcon from 'vue-material-design-icons/BellRing.vue'
import GoogleIcon from 'vue-material-design-icons/Google.vue'
import LightningBoltIcon from 'vue-material-design-icons/LightningBolt.vue'
import NfcIcon from 'vue-material-design-icons/Nfc.vue'
import WifiOffIcon from 'vue-material-design-icons/WifiOff.vue'
import PermissionRow from '../admin/PermissionRow.vue'
import GroupSelect from '../common/GroupSelect.vue'
import { APPLE_STORE_URL, GOOGLE_STORE_URL } from '../../utils/mobileApp.js'

const props = defineProps({
	open: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits(['close', 'finished'])

const STEPS = [
	{ id: 'welcome', label: t('attendance', 'Welcome') },
	{ id: 'appointments', label: t('attendance', 'Appointments') },
	{ id: 'checkin', label: t('attendance', 'Check-in') },
	{ id: 'responses', label: t('attendance', 'Responses') },
	{ id: 'groups', label: t('attendance', 'Response summary groups') },
	{ id: 'reminders', label: t('attendance', 'Appointment reminders') },
	{ id: 'self-checkin', label: t('attendance', 'Self-check-in') },
	{ id: 'mobile', label: t('attendance', 'Mobile app') },
]

// Titles and hints deliberately mirror the admin settings, so the wizard
// teaches the labels people meet again later.
const permissionRows = {
	appointments: [
		{
			name: 'manage_appointments',
			title: t('attendance', 'May manage appointments'),
			hint: t('attendance', 'Create, edit and delete any appointment and manage its responses.'),
			warningWhenAll: t('attendance', 'Every user can create, edit and delete all appointments.'),
		},
		{
			name: 'create_appointments',
			title: t('attendance', 'May create own appointments'),
			hint: t('attendance', 'Create appointments and manage them as organizer.'),
			implication: t('attendance', 'Users who can manage appointments can always create appointments.'),
		},
	],
	checkin: [
		{
			name: 'checkin',
			title: t('attendance', 'May check in attendees'),
			hint: t('attendance', 'Access the check-in interface and check in attendees.'),
			warningWhenAll: t('attendance', 'Every user can check in every attendee.'),
		},
	],
	responses: [
		{
			name: 'see_response_overview',
			title: t('attendance', 'May see detailed response & check-in summary'),
			hint: t('attendance', 'See the detailed summary including names — who answered what and who checked in.'),
			implication: t('attendance', 'Organizers always see the summary of their own appointments.'),
			warningWhenAll: t('attendance', 'Every user can see who answered what.'),
		},
		{
			name: 'see_response_counts',
			title: t('attendance', 'May see response counts'),
			hint: t('attendance', 'See how many people answered yes, no or maybe — without any names.'),
			implication: t('attendance', 'Users who see the detailed summary always see the counts.'),
		},
		{
			name: 'see_comments',
			title: t('attendance', 'May see comments'),
			hint: t('attendance', 'See comments in the response overview.'),
		},
	],
	selfCheckin: [
		{
			name: 'self_checkin',
			title: t('attendance', 'May check in themselves'),
			hint: t('attendance', 'Check in themselves via QR code, NFC tag or deep link.'),
		},
	],
}

const PERMISSION_NAMES = Object.values(permissionRows).flatMap((rows) => rows.map((row) => row.name))

const welcomeTopics = [
	t('attendance', 'Who may create appointments'),
	t('attendance', 'Who may check attendees in'),
	t('attendance', 'Who may see the replies'),
	t('attendance', 'Automatic reminders for people who have not replied'),
]

const mobileBenefits = [
	{ icon: BellRingIcon, text: t('attendance', 'Push notifications for new appointments and reminders — replies arrive in minutes instead of days.') },
	{ icon: LightningBoltIcon, text: t('attendance', 'Reply with one tap from the notification, without opening a browser or logging in again.') },
	{ icon: NfcIcon, text: t('attendance', 'Check-in by QR code and NFC tag — including writing the tags themselves.') },
	{ icon: WifiOffIcon, text: t('attendance', 'Appointments stay readable without a connection, which matters in rehearsal rooms and gyms.') },
]

const stepIndex = ref(0)
const loading = ref(true)
const saving = ref(false)
const availableGroups = ref([])
const whitelistedGroups = ref([])
const permissions = reactive(Object.fromEntries(PERMISSION_NAMES.map((name) => [name, { mode: 'all', groups: [] }])))
const reminders = reactive({ enabled: false, reminderDays: 7, reminderFrequency: 0, reminderTarget: 'non_responders' })
const selfCheckinWindowMinutes = ref(30)
const pushEnabled = ref(true)
const mobileAppBannerEnabled = ref(true)
const notificationsAppEnabled = ref(true)

const currentStep = computed(() => STEPS[stepIndex.value])
const isLastStep = computed(() => stepIndex.value === STEPS.length - 1)
const progressPercent = computed(() => ((stepIndex.value + 1) / STEPS.length) * 100)

// Which settings each step owns — saved on the way to the next step.
const STEP_PAYLOAD = {
	appointments: () => ({ permissions: permissionPayload('manage_appointments', 'create_appointments') }),
	checkin: () => ({ permissions: permissionPayload('checkin') }),
	responses: () => ({ permissions: permissionPayload('see_response_overview', 'see_response_counts', 'see_comments') }),
	groups: () => ({ whitelistedGroups: whitelistedGroups.value.map((group) => group.id) }),
	reminders: () => ({
		reminders: {
			enabled: reminders.enabled,
			reminderTarget: reminders.reminderTarget,
			...(Number.isFinite(reminders.reminderDays) ? { reminderDays: reminders.reminderDays } : {}),
			...(Number.isFinite(reminders.reminderFrequency) ? { reminderFrequency: reminders.reminderFrequency } : {}),
		},
	}),
	'self-checkin': () => ({
		permissions: permissionPayload('self_checkin'),
		...(Number.isFinite(selfCheckinWindowMinutes.value) ? { selfCheckinWindowMinutes: selfCheckinWindowMinutes.value } : {}),
	}),
	mobile: () => ({
		pushEnabled: pushEnabled.value,
		mobileAppBannerEnabled: mobileAppBannerEnabled.value,
		// Last step — from here admins get the "create your first appointment"
		// prompt that creators without admin rights see from the start.
		onboardingCompleted: true,
	}),
}

function permissionPayload(...names) {
	return Object.fromEntries(names.map((name) => [name, {
		mode: permissions[name].mode,
		groups: permissions[name].groups.map((group) => group.id),
	}]))
}

async function loadSettings() {
	loading.value = true
	try {
		const [settingsRes, capabilitiesRes] = await Promise.all([
			axios.get(generateUrl('/apps/attendance/api/admin/settings')),
			axios.get(generateUrl('/apps/attendance/api/capabilities')),
		])
		const config = settingsRes.data.config
		const groups = settingsRes.data.groups || []
		availableGroups.value = groups

		const groupsById = new Map(groups.map((group) => [group.id, group]))
		const toGroupObjects = (ids) => (ids || [])
			.map((id) => groupsById.get(id))
			.filter((group) => group !== undefined)

		whitelistedGroups.value = toGroupObjects(config.whitelistedGroups)

		for (const name of PERMISSION_NAMES) {
			const setting = config.permissions?.[name]
			if (setting) {
				permissions[name] = { mode: setting.mode, groups: toGroupObjects(setting.groups) }
			}
		}

		reminders.enabled = config.reminders.enabled || false
		reminders.reminderDays = config.reminders.reminderDays || 7
		reminders.reminderFrequency = config.reminders.reminderFrequency || 0
		reminders.reminderTarget = config.reminders.reminderTarget || 'non_responders'
		selfCheckinWindowMinutes.value = config.selfCheckinWindowMinutes ?? 30
		pushEnabled.value = config.pushEnabled !== false
		mobileAppBannerEnabled.value = config.mobileAppBannerEnabled !== false
		notificationsAppEnabled.value = capabilitiesRes.data.notificationsAppEnabled !== false
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
	const buildPayload = STEP_PAYLOAD[currentStep.value.id]
	if (!buildPayload) {
		return true
	}

	saving.value = true
	try {
		await axios.post(generateUrl('/apps/attendance/api/admin/settings'), buildPayload())
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
		emit('finished')
		handleClose(false)
		return
	}
	stepIndex.value++
}

function goBack() {
	stepIndex.value = Math.max(0, stepIndex.value - 1)
}

function handleClose(isOpen) {
	if (isOpen) {
		return
	}
	emit('close')
}

watch(() => props.open, (open) => {
	if (open) {
		stepIndex.value = 0
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

.onboarding__loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	padding: 40px 0;
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
.onboarding__benefits {
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

.onboarding__benefits li {
	display: flex;
	align-items: flex-start;
	gap: 10px;
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
