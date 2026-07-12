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
import { NcButton } from '@nextcloud/vue'
import { getRootUrl } from '@nextcloud/router'
import AppleIcon from 'vue-material-design-icons/Apple.vue'
import GoogleIcon from 'vue-material-design-icons/Google.vue'
import CellphoneIcon from 'vue-material-design-icons/Cellphone.vue'
import OpenInAppIcon from 'vue-material-design-icons/OpenInApp.vue'
import { APPLE_STORE_URL, GOOGLE_STORE_URL } from '../utils/mobileApp.js'

const server = window.location.origin + getRootUrl()
const deepLink = 'krautnerds://attendance/self-checkin?server=' + encodeURIComponent(server)
</script>

<style scoped>
.self-checkin-landing {
	max-width: 420px;
	margin: 40px auto;
	padding: 20px;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	text-align: center;
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
