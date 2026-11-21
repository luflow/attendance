<template>
	<div class="mobile-app-invitation">
		<div class="mobile-app-invitation__content">
			<CellphoneIcon class="mobile-app-invitation__icon" :size="28" />
			<div class="mobile-app-invitation__text">
				<strong>{{ t('attendance', 'Attendance on your phone') }}</strong>
				<span>{{ t('attendance', 'Get reminders as push notifications, answer with one tap and check yourself in on arrival, without anyone ticking you off a list.') }}</span>
			</div>
		</div>
		<div class="mobile-app-invitation__actions">
			<NcButton v-if="onPhone"
				variant="primary"
				:href="joinLink"
				target="_blank"
				rel="noopener"
				data-test="mobile-app-invitation-link">
				<template #icon>
					<DownloadIcon :size="20" />
				</template>
				{{ t('attendance', 'Get the app') }}
			</NcButton>
			<a v-else-if="qrDataUrl"
				class="mobile-app-invitation__qr"
				:href="joinLink"
				target="_blank"
				rel="noopener"
				data-test="mobile-app-invitation-qr">
				<img :src="qrDataUrl"
					:alt="t('attendance', 'QR code to get the mobile app for this server')"
					width="112"
					height="112">
				<span>{{ t('attendance', 'Scan with your phone') }}</span>
			</a>
			<NcButton variant="tertiary"
				:aria-label="t('attendance', 'Dismiss')"
				data-test="mobile-app-invitation-dismiss"
				@click="emit('dismiss')">
				<template #icon>
					<CloseIcon :size="20" />
				</template>
			</NcButton>
		</div>
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { getRootUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import QRCode from 'qrcode'
import { onMounted, ref } from 'vue'
import CellphoneIcon from 'vue-material-design-icons/Cellphone.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import { joinLinkFor } from '../../utils/mobileApp.js'

const emit = defineEmits(['dismiss'])

// Rendered locally: showing the invitation must not contact anwesenheit.app.
const joinLink = joinLinkFor(window.location.origin + getRootUrl())
// A phone cannot scan its own screen, so it gets the link instead.
const onPhone = window.matchMedia?.('(hover: none) and (pointer: coarse)').matches ?? false
const qrDataUrl = ref(null)

onMounted(async () => {
	if (!onPhone) {
		qrDataUrl.value = await QRCode.toDataURL(joinLink, { width: 224, margin: 1 })
	}
})
</script>

<style scoped>
.mobile-app-invitation {
	display: flex;
	align-items: center;
	gap: 16px;
	padding: 10px 16px;
	background-color: var(--color-primary-element-light, var(--color-background-hover));
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	flex-wrap: wrap;
	text-align: start;
}

.mobile-app-invitation__content {
	display: flex;
	align-items: center;
	gap: 12px;
	flex: 1 1 240px;
	min-width: 0;
}

.mobile-app-invitation__icon {
	flex-shrink: 0;
	color: var(--color-primary-element);
}

.mobile-app-invitation__text {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	color: var(--color-main-text);
}

.mobile-app-invitation__text strong {
	font-weight: 600;
}

.mobile-app-invitation__text span {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.mobile-app-invitation__actions {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-inline-start: auto;
}

.mobile-app-invitation__qr {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.mobile-app-invitation__qr img {
	display: block;
	border-radius: var(--border-radius);
}
</style>
