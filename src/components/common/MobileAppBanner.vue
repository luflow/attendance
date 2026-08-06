<template>
	<AppBanner v-if="visible"
		:title="t('attendance', 'Self check-in is now in the mobile app')"
		:description="t('attendance', 'Participants scan a QR code or NFC tag and check themselves in — no clipboard, no manual list.')"
		data-test="mobile-app-banner">
		<template #icon>
			<QrcodeScanIcon :size="28" />
		</template>
		<template #actions>
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
		</template>
	</AppBanner>
</template>

<script setup>
import { NcButton } from '@nextcloud/vue'
import { onMounted, ref } from 'vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import QrcodeScanIcon from 'vue-material-design-icons/QrcodeScan.vue'
import AppBanner from './AppBanner.vue'
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
