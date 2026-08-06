<template>
	<div v-if="visible"
		class="mobile-app-banner"
		data-test="mobile-app-banner">
		<div class="mobile-app-banner__content">
			<QrcodeScanIcon class="mobile-app-banner__icon" :size="28" />
			<div class="mobile-app-banner__text">
				<strong>{{ t('attendance', 'Self check-in is now in the mobile app') }}</strong>
				<span>{{ t('attendance', 'Participants scan a QR code or NFC tag and check themselves in — no clipboard, no manual list.') }}</span>
			</div>
		</div>
		<div class="mobile-app-banner__actions">
			<NcButton v-for="store in MOBILE_APP_STORES"
				:key="store.id"
				variant="secondary"
				:href="store.url"
				target="_blank"
				rel="noopener"
				:data-test="`mobile-app-banner-${store.id}`">
				<template #icon>
					<component :is="store.icon" :size="20" />
				</template>
				{{ store.label }}
			</NcButton>
			<NcButton variant="tertiary"
				:aria-label="t('attendance', 'Dismiss')"
				data-test="mobile-app-banner-dismiss"
				@click="dismiss">
				<template #icon>
					<CloseIcon :size="20" />
				</template>
			</NcButton>
		</div>
	</div>
</template>

<script setup>
import { NcButton } from '@nextcloud/vue'
import { onMounted, ref } from 'vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import QrcodeScanIcon from 'vue-material-design-icons/QrcodeScan.vue'
import { MOBILE_APP_STORES } from '../../utils/mobileApp.js'

// Suffixed so the self-check-in announcement reaches everyone who already
// dismissed the previous "get the app" banner. Bump again on the next relaunch.
const DISMISS_KEY = 'attendance:mobile-app-banner-dismissed:self-checkin'

const visible = ref(false)

function dismiss() {
	visible.value = false
	try {
		window.localStorage.setItem(DISMISS_KEY, '1')
	} catch {
		// localStorage may be unavailable (private mode, quota); dismissal is transient in that case
	}
}

onMounted(() => {
	try {
		visible.value = window.localStorage.getItem(DISMISS_KEY) !== '1'
	} catch {
		visible.value = true
	}
})
</script>

<style scoped>
.mobile-app-banner {
	display: flex;
	align-items: center;
	gap: 16px;
	padding: 10px 16px;
	margin: 0 auto;
	max-width: 800px;
	background-color: var(--color-primary-element-light, var(--color-background-hover));
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	flex-wrap: wrap;
}

.mobile-app-banner__content {
	display: flex;
	align-items: center;
	gap: 12px;
	flex: 1 1 240px;
	min-width: 0;
}

.mobile-app-banner__icon {
	flex-shrink: 0;
	color: var(--color-primary-element);
}

.mobile-app-banner__text {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.mobile-app-banner__text strong {
	font-weight: 600;
}

.mobile-app-banner__text span {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.mobile-app-banner__actions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}
</style>
