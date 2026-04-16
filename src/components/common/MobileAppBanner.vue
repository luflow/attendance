<template>
	<div v-if="visible" class="mobile-app-banner" data-test="mobile-app-banner">
		<div class="mobile-app-banner__content">
			<CellphoneIcon class="mobile-app-banner__icon" :size="28" />
			<div class="mobile-app-banner__text">
				<strong>{{ t('attendance', 'Attendance is now on your phone') }}</strong>
				<span>{{ t('attendance', 'Get the mobile app for faster access and push notifications.') }}</span>
			</div>
		</div>
		<div class="mobile-app-banner__actions">
			<NcButton variant="secondary"
				:href="APPLE_STORE_URL"
				target="_blank"
				rel="noopener"
				data-test="mobile-app-banner-apple">
				<template #icon>
					<AppleIcon :size="20" />
				</template>
				{{ t('attendance', 'App Store') }}
			</NcButton>
			<NcButton variant="secondary"
				:href="GOOGLE_STORE_URL"
				target="_blank"
				rel="noopener"
				data-test="mobile-app-banner-google">
				<template #icon>
					<GoogleIcon :size="20" />
				</template>
				{{ t('attendance', 'Google Play') }}
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
import { ref, onMounted } from 'vue'
import { NcButton } from '@nextcloud/vue'
import AppleIcon from 'vue-material-design-icons/Apple.vue'
import GoogleIcon from 'vue-material-design-icons/Google.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import CellphoneIcon from 'vue-material-design-icons/Cellphone.vue'
import { APPLE_STORE_URL, GOOGLE_STORE_URL } from '../../utils/mobileApp.js'

const DISMISS_KEY = 'attendance:mobile-app-banner-dismissed'

const visible = ref(false)

const dismiss = () => {
	visible.value = false
	try {
		window.localStorage.setItem(DISMISS_KEY, '1')
	} catch (e) {
		// localStorage may be unavailable (private mode, quota); dismissal is transient in that case
	}
}

onMounted(() => {
	try {
		visible.value = window.localStorage.getItem(DISMISS_KEY) !== '1'
	} catch (e) {
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
	margin: 12px auto 0;
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
