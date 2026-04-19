<template>
	<div id="attendance-personal-settings">
		<NcSettingsSection :name="t('attendance', 'Calendar subscription')"
			:description="t('attendance', 'Subscribe to your appointments in external calendar apps like Google Calendar, Apple Calendar, or Thunderbird.')">
			<div v-if="icalLoading" class="loading-section">
				<NcLoadingIcon :size="32" />
			</div>

			<template v-else>
				<div class="quick-subscribe-section">
					<label class="quick-subscribe-label">{{ t('attendance', 'Quick subscribe') }}</label>
					<div class="quick-subscribe-buttons">
						<NcButton variant="secondary"
							:href="googleCalendarUrl"
							target="_blank">
							<template #icon>
								<GoogleIcon :size="20" />
							</template>
							{{ t('attendance', 'Google Calendar') }}
						</NcButton>
						<NcButton variant="secondary"
							:href="webcalUrl">
							<template #icon>
								<AppleIcon :size="20" />
							</template>
							{{ t('attendance', 'Apple Calendar') }}
						</NcButton>
					</div>
				</div>

				<div class="feed-url-section">
					<label class="feed-url-label">{{ t('attendance', 'Subscription URL') }}</label>
					<div class="feed-url-row">
						<code class="feed-url-display">{{ feedUrl }}</code>
						<NcButton variant="secondary"
							:aria-label="t('attendance', 'Copy URL')"
							@click="handleCopy">
							<template #icon>
								<ContentCopy :size="20" />
							</template>
							{{ t('attendance', 'Copy') }}
						</NcButton>
					</div>
				</div>

				<div v-if="lastUsedAt" class="last-used-info">
					<span class="last-used-label">{{ t('attendance', 'Last accessed') }}:</span>
					<span>{{ formatDateTime(lastUsedAt) }}</span>
				</div>

				<NcNoteCard type="warning" class="security-warning">
					{{ t('attendance', 'Keep this URL private! Anyone with this link can see your appointments.') }}
				</NcNoteCard>

				<NcButton variant="tertiary"
					:disabled="icalLoading"
					@click="showRegenerateConfirm = true">
					<template #icon>
						<Refresh :size="20" />
					</template>
					{{ t('attendance', 'Regenerate URL') }}
				</NcButton>
			</template>

			<!-- Regenerate confirmation dialog -->
			<NcDialog :open="showRegenerateConfirm"
				:name="t('attendance', 'Regenerate Subscription URL?')"
				@update:open="showRegenerateConfirm = false">
				<p>{{ t('attendance', 'This will invalidate your current calendar subscription URL. Any calendar apps using the old URL will need to be updated with the new URL.') }}</p>
				<template #actions>
					<NcButton variant="secondary" @click="showRegenerateConfirm = false">
						{{ t('attendance', 'Cancel') }}
					</NcButton>
					<NcButton variant="error" @click="handleRegenerate">
						{{ t('attendance', 'Regenerate') }}
					</NcButton>
				</template>
			</NcDialog>
		</NcSettingsSection>

		<NcSettingsSection :name="t('attendance', 'Calendar reminders')"
			:description="t('attendance', 'Configure reminders for appointments you accepted or tentatively accepted in your calendar subscription (iCal feed). You can select multiple reminder times.')">
			<div v-if="settingsLoading" class="loading-section">
				<NcLoadingIcon :size="32" />
				<p>{{ t('attendance', 'Loading settings\u00A0\u2026') }}</p>
			</div>

			<div v-else>
				<div class="setting-row">
					<label for="ical-reminder-triggers">{{ t('attendance', 'Reminders before appointment') }}</label>
					<NcSelect v-model="selectedReminders"
						input-id="ical-reminder-triggers"
						:options="reminderOptions"
						:multiple="true"
						:close-on-select="false"
						:disabled="saving"
						:placeholder="t('attendance', 'No reminders')"
						label="label"
						track-by="value"
						/>
				</div>

				<NcButton variant="primary"
					:disabled="saving"
					class="save-button"
					@click="save">
					<template #icon>
						<NcLoadingIcon v-if="saving" />
					</template>
					{{ t('attendance', 'Save') }}
				</NcButton>
			</div>
		</NcSettingsSection>
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import {
	NcSettingsSection,
	NcSelect,
	NcButton,
	NcLoadingIcon,
	NcNoteCard,
	NcDialog,
} from '@nextcloud/vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import GoogleIcon from 'vue-material-design-icons/Google.vue'
import AppleIcon from 'vue-material-design-icons/Apple.vue'
import { useIcalFeed } from '../composables/useIcalFeed.js'
import { formatDateTime } from '../utils/datetime.js'

// iCal feed
const { feedUrl, webcalUrl, googleCalendarUrl, lastUsedAt, loading: icalLoading, loadToken, regenerateToken, copyToClipboard } = useIcalFeed()
const showRegenerateConfirm = ref(false)

const handleCopy = () => copyToClipboard()

const handleRegenerate = async () => {
	showRegenerateConfirm.value = false
	await regenerateToken()
}

// Reminder settings
const settingsLoading = ref(true)
const saving = ref(false)
const icalReminderTriggers = ref([])

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
			.map(v => reminderOptions.find(o => o.value === v))
			.filter(Boolean)
	},
	set(options) {
		icalReminderTriggers.value = options.map(o => o.value)
	},
})

async function loadSettings() {
	try {
		const response = await axios.get(generateUrl('/apps/attendance/api/user/settings'))
		icalReminderTriggers.value = response.data.icalReminderTriggers ?? []
	} catch (e) {
		showError(t('attendance', 'Failed to load settings'))
	} finally {
		settingsLoading.value = false
	}
}

async function save() {
	saving.value = true
	try {
		await axios.post(generateUrl('/apps/attendance/api/user/settings'), {
			icalReminderTriggers: icalReminderTriggers.value,
		})
		showSuccess(t('attendance', 'Settings saved'))
	} catch (e) {
		showError(t('attendance', 'Failed to save settings'))
	} finally {
		saving.value = false
	}
}

onMounted(() => {
	loadToken()
	loadSettings()
})
</script>

<style scoped>
.loading-section {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 20px 0;
}

.feed-url-section {
	margin-bottom: 16px;
}

.feed-url-label,
.quick-subscribe-label {
	display: block;
	margin-bottom: 6px;
	font-weight: 600;
}

.feed-url-row {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.feed-url-display {
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

.quick-subscribe-section {
	margin-bottom: 16px;
}

.quick-subscribe-buttons {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.last-used-info {
	margin-bottom: 16px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.last-used-label {
	margin-right: 4px;
}

.security-warning {
	margin: 16px 0;
}

.setting-row {
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-width: 400px;
}

.setting-row label {
	font-weight: bold;
}

.save-button {
	margin-top: 12px;
}
</style>
