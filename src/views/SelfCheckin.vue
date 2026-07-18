<template>
	<div class="self-checkin-landing">
		<CellphoneIcon class="self-checkin-landing__icon" :size="56" />
		<h2>{{ t('attendance', 'Check in with the Attendance app') }}</h2>
		<p class="self-checkin-landing__text">
			{{ t('attendance', 'Self-check-in works in the Attendance mobile app. Open the app and scan this code again, or get the app below.') }}
		</p>

		<div class="self-checkin-landing__open">
			<NcButton variant="primary" :href="deepLink">
				<template #icon>
					<OpenInAppIcon :size="20" />
				</template>
				{{ t('attendance', 'Open in the app') }}
			</NcButton>
		</div>

		<div class="self-checkin-landing__stores">
			<NcButton variant="secondary"
				:href="APPLE_STORE_URL"
				target="_blank"
				rel="noopener">
				<template #icon>
					<AppleIcon :size="20" />
				</template>
				{{ t('attendance', 'App Store') }}
			</NcButton>
			<NcButton variant="secondary"
				:href="GOOGLE_STORE_URL"
				target="_blank"
				rel="noopener">
				<template #icon>
					<GoogleIcon :size="20" />
				</template>
				{{ t('attendance', 'Google Play') }}
			</NcButton>
		</div>
	</div>
</template>

<script setup>
import { getRootUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import { onMounted } from 'vue'
import AppleIcon from 'vue-material-design-icons/Apple.vue'
import CellphoneIcon from 'vue-material-design-icons/Cellphone.vue'
import GoogleIcon from 'vue-material-design-icons/Google.vue'
import OpenInAppIcon from 'vue-material-design-icons/OpenInApp.vue'
import { APPLE_STORE_URL, GOOGLE_STORE_URL } from '../utils/mobileApp.js'

const ANDROID_PACKAGE = 'de.krautnerds.attendance'

const isAndroid = /android/i.test(navigator.userAgent)
const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent)

const server = window.location.origin + getRootUrl()
const encodedServer = encodeURIComponent(server)
const schemeLink = 'nc-attendance://self-checkin?server=' + encodedServer

// On Android an intent:// URL opens the app when installed and falls back
// to the Play Store otherwise — better than the bare scheme, which errors
// silently without the app.
const deepLink = isAndroid
	? 'intent://self-checkin?server=' + encodedServer
	+ '#Intent;scheme=nc-attendance;package=' + ANDROID_PACKAGE
	+ ';S.browser_fallback_url=' + encodeURIComponent(GOOGLE_STORE_URL) + ';end'
	: schemeLink

onMounted(() => {
	// Best-effort auto-open on phones so the scan goes straight into the
	// app without tapping the button. Universal links are not an option
	// here (every Nextcloud instance has its own domain), so this may be
	// blocked without a user gesture — the button stays as fallback.
	if (isAndroid || isIos) {
		window.location.href = deepLink
	}
})
</script>

<style scoped>
.self-checkin-landing {
	max-width: 420px;
	margin: 40px auto;
	padding: 32px 24px;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	text-align: center;
	background-color: var(--color-main-background);
	border-radius: var(--border-radius-large);
	box-shadow: 0 0 10px var(--color-box-shadow);
}

.self-checkin-landing__icon {
	color: var(--color-primary-element);
}

.self-checkin-landing__text {
	color: var(--color-text-maxcontrast);
}

.self-checkin-landing__open {
	margin-top: 8px;
}

.self-checkin-landing__stores {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
	justify-content: center;
	margin-top: 4px;
}
</style>
