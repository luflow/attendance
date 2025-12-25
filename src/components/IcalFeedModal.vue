<template>
	<NcDialog :open="show"
		:name="t('attendance', 'Calendar Subscription')"
		@update:open="handleClose">
		<div class="ical-feed-modal">
			<p class="description">
				{{ t('attendance', 'Subscribe to your appointments in external calendar apps like Google Calendar, Apple Calendar, or Thunderbird.') }}
			</p>

			<div v-if="loading" class="loading-container">
				<NcLoadingIcon :size="32" />
			</div>

			<template v-else>
				<div class="feed-url-section">
					<label class="feed-url-label">{{ t('attendance', 'Subscription URL') }}</label>
					<code class="feed-url-display" data-test="input-ical-feed-url">{{ feedUrl }}</code>
					<NcButton type="secondary"
						:aria-label="t('attendance', 'Copy URL')"
						data-test="button-copy-url"
						@click="handleCopy">
						<template #icon>
							<ContentCopy :size="20" />
						</template>
						{{ t('attendance', 'Copy') }}
					</NcButton>
				</div>

				<div class="quick-subscribe-section">
					<label class="quick-subscribe-label">{{ t('attendance', 'Quick subscribe') }}</label>
					<div class="quick-subscribe-buttons">
						<NcButton type="secondary"
							:href="googleCalendarUrl"
							target="_blank"
							data-test="button-google-calendar">
							<template #icon>
								<GoogleIcon :size="20" />
							</template>
							{{ t('attendance', 'Google Calendar') }}
						</NcButton>
						<NcButton type="secondary"
							:href="webcalUrl"
							data-test="button-apple-calendar">
							<template #icon>
								<AppleIcon :size="20" />
							</template>
							{{ t('attendance', 'Apple Calendar') }}
						</NcButton>
					</div>
				</div>

				<div v-if="lastUsedAt" class="last-used-info">
					<span class="last-used-label">{{ t('attendance', 'Last accessed') }}:</span>
					<span class="last-used-date">{{ formatDate(lastUsedAt) }}</span>
				</div>

				<NcNoteCard type="warning" class="security-warning">
					{{ t('attendance', 'Keep this URL private! Anyone with this link can see your appointments.') }}
				</NcNoteCard>

				<div class="regenerate-section">
					<NcButton type="tertiary"
						:disabled="loading"
						data-test="button-regenerate-url"
						@click="showRegenerateConfirm = true">
						<template #icon>
							<Refresh :size="20" />
						</template>
						{{ t('attendance', 'Regenerate URL') }}
					</NcButton>
				</div>
			</template>
		</div>

		<!-- Regenerate confirmation dialog -->
		<NcDialog :open="showRegenerateConfirm"
			:name="t('attendance', 'Regenerate Subscription URL?')"
			@update:open="showRegenerateConfirm = false">
			<p>{{ t('attendance', 'This will invalidate your current calendar subscription URL. Any calendar apps using the old URL will need to be updated with the new URL.') }}</p>
			<template #actions>
				<NcButton type="secondary" @click="showRegenerateConfirm = false">
					{{ t('attendance', 'Cancel') }}
				</NcButton>
				<NcButton type="error" @click="handleRegenerate">
					{{ t('attendance', 'Regenerate') }}
				</NcButton>
			</template>
		</NcDialog>
	</NcDialog>
</template>

<script setup>
import { ref, watch, computed } from 'vue'
import { NcDialog, NcButton, NcNoteCard, NcLoadingIcon } from '@nextcloud/vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import GoogleIcon from 'vue-material-design-icons/Google.vue'
import AppleIcon from 'vue-material-design-icons/Apple.vue'
import { useIcalFeed } from '../composables/useIcalFeed.js'
import { formatDateTime } from '../utils/datetime.js'

const props = defineProps({
	show: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits(['close'])

const showRegenerateConfirm = ref(false)

const { feedUrl, lastUsedAt, loading, loadToken, regenerateToken, copyToClipboard } = useIcalFeed()

// Convert https:// URL to webcal:// for Apple Calendar
const webcalUrl = computed(() => {
	if (!feedUrl.value) return ''
	return feedUrl.value.replace(/^https?:\/\//, 'webcal://')
})

// Google Calendar subscription URL
const googleCalendarUrl = computed(() => {
	if (!webcalUrl.value) return ''
	return `https://calendar.google.com/calendar/r?cid=${encodeURIComponent(webcalUrl.value)}`
})

// Load token when modal opens
watch(() => props.show, async (newValue) => {
	if (newValue) {
		await loadToken()
	}
})

const handleClose = () => {
	emit('close')
}

const handleCopy = () => {
	copyToClipboard()
}

const handleRegenerate = async () => {
	showRegenerateConfirm.value = false
	await regenerateToken()
}

const formatDate = (dateString) => {
	if (!dateString) return ''
	return formatDateTime(dateString)
}
</script>

<style scoped>
.ical-feed-modal {
	padding: 12px 0;
}

.description {
	margin: 0 0 20px 0;
	color: var(--color-text-maxcontrast);
}

.loading-container {
	display: flex;
	justify-content: center;
	padding: 40px;
}

.feed-url-section {
	margin-bottom: 16px;
}

.feed-url-label {
	display: block;
	margin-bottom: 8px;
	font-weight: 600;
}

.feed-url-display {
	display: block;
	width: 100%;
	padding: 10px 12px;
	margin-bottom: 12px;
	background-color: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-family: monospace;
	font-size: 12px;
	word-break: break-all;
	user-select: all;
}

.last-used-info {
	margin-bottom: 16px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.last-used-label {
	margin-right: 4px;
}

.quick-subscribe-section {
	margin-bottom: 16px;
}

.quick-subscribe-label {
	display: block;
	margin-bottom: 8px;
	font-weight: 600;
}

.quick-subscribe-buttons {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.security-warning {
	margin: 16px 0;
}

.regenerate-section {
	margin-top: 16px;
}
</style>
