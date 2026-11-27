<template>
	<div class="ical-feed">
		<div v-if="organizerAvailable"
			class="kind-switch"
			role="radiogroup"
			:aria-label="t('attendance', 'Calendar subscription')">
			<NcCheckboxRadioSwitch v-model="kind"
				value="personal"
				name="ical_feed_kind"
				type="radio"
				buttonVariant
				buttonVariantGrouped="horizontal"
				data-test="radio-feed-personal">
				{{ t('attendance', 'Personal calendar') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="kind"
				value="organizer"
				name="ical_feed_kind"
				type="radio"
				buttonVariant
				buttonVariantGrouped="horizontal"
				data-test="radio-feed-organizer">
				{{ t('attendance', 'Organizer calendar') }}
			</NcCheckboxRadioSwitch>
		</div>

		<p class="description">
			{{ isOrganizerFeed
				? t('attendance', 'Subscribe to the appointments you organize or manage. Every calendar entry shows how many people are attending and who answered what.')
				: t('attendance', 'Subscribe to your appointments in external calendar apps like Google Calendar, Apple Calendar, or Thunderbird.') }}
		</p>

		<LoadingState v-if="loading" />

		<template v-else>
			<div class="feed-url-section">
				<label class="feed-url-label">{{ t('attendance', 'Subscription URL') }}</label>
				<div class="feed-url-row">
					<code class="feed-url-display" data-test="input-ical-feed-url">{{ feedUrl }}</code>
					<NcButton variant="secondary"
						:aria-label="t('attendance', 'Copy URL')"
						data-test="button-copy-url"
						@click="copyToClipboard">
						<template #icon>
							<ContentCopy :size="20" />
						</template>
						{{ t('attendance', 'Copy') }}
					</NcButton>
				</div>
			</div>

			<div class="quick-subscribe-section">
				<label class="quick-subscribe-label">{{ t('attendance', 'Quick subscribe') }}</label>
				<div class="quick-subscribe-buttons">
					<NcButton variant="secondary"
						:href="googleCalendarUrl"
						target="_blank"
						data-test="button-google-calendar">
						<template #icon>
							<GoogleIcon :size="20" />
						</template>
						{{ t('attendance', 'Google Calendar') }}
					</NcButton>
					<NcButton variant="secondary"
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
				<!-- TRANSLATORS: Label before a timestamp saying when a calendar app last fetched this iCal feed — the feed being polled, not a person opening something. -->
				<span class="last-used-label">{{ t('attendance', 'Last accessed') }}:</span>
				<span>{{ formatDateTime(lastUsedAt) }}</span>
			</div>

			<NcNoteCard type="warning" class="security-warning">
				{{ isOrganizerFeed
					? t('attendance', 'Keep this URL private! Anyone with this link can see who answered what.')
					: t('attendance', 'Keep this URL private! Anyone with this link can see your appointments.') }}
			</NcNoteCard>

			<NcButton variant="tertiary"
				data-test="button-regenerate-url"
				@click="showRegenerateConfirm = true">
				<template #icon>
					<Refresh :size="20" />
				</template>
				{{ t('attendance', 'Regenerate URL') }}
			</NcButton>
		</template>

		<NcDialog :open="showRegenerateConfirm"
			:name="t('attendance', 'Regenerate subscription URL?')"
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
	</div>
</template>

<script setup>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcNoteCard } from '@nextcloud/vue'
import { computed, ref, watch } from 'vue'
import AppleIcon from 'vue-material-design-icons/Apple.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import GoogleIcon from 'vue-material-design-icons/Google.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import LoadingState from './common/LoadingState.vue'
import { useIcalFeed } from '../composables/useIcalFeed.js'
import { formatDateTime } from '../utils/datetime.js'

// Members never see the switch, so they stay on the personal feed.
const kind = ref('personal')
const isOrganizerFeed = computed(() => kind.value === 'organizer')
const showRegenerateConfirm = ref(false)

const { feedUrl, webcalUrl, googleCalendarUrl, lastUsedAt, organizerAvailable, loading, loadToken, regenerateToken, copyToClipboard } = useIcalFeed(kind)

watch(kind, loadToken, { immediate: true })

async function handleRegenerate() {
	showRegenerateConfirm.value = false
	await regenerateToken()
}
</script>

<style scoped>
.kind-switch {
	display: flex;
	margin-bottom: 16px;
}

.description {
	margin: 0 0 20px 0;
	color: var(--color-text-maxcontrast);
}

.feed-url-section,
.quick-subscribe-section {
	margin-bottom: 16px;
}

.feed-url-label,
.quick-subscribe-label {
	display: block;
	margin-bottom: 8px;
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
	padding: 10px 12px;
	background-color: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-family: monospace;
	font-size: 12px;
	word-break: break-all;
	user-select: all;
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
	margin-inline-end: 4px;
}

.security-warning {
	margin: 16px 0;
}
</style>
