<template>
	<div class="onboarding-banner" data-test="onboarding-banner">
		<div class="onboarding-banner__content">
			<RocketLaunchIcon class="onboarding-banner__icon" :size="28" />
			<div class="onboarding-banner__text">
				<strong>{{ t('attendance', 'No appointments yet') }}</strong>
				<span>{{ t('attendance', 'The setup wizard walks you through permissions, reminders and check-in so the first appointment lands right.') }}</span>
			</div>
		</div>
		<NcButton variant="primary"
			data-test="onboarding-banner-start"
			@click="$emit('start')">
			<template #icon>
				<RocketLaunchIcon :size="20" />
			</template>
			{{ t('attendance', 'Start setup') }}
		</NcButton>
	</div>
</template>

<script setup>
import { NcButton } from '@nextcloud/vue'
import RocketLaunchIcon from 'vue-material-design-icons/RocketLaunch.vue'

defineEmits(['start'])
</script>

<style scoped>
.onboarding-banner {
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

.onboarding-banner__content {
	display: flex;
	align-items: center;
	gap: 12px;
	flex: 1 1 240px;
	min-width: 0;
}

.onboarding-banner__icon {
	flex-shrink: 0;
	color: var(--color-primary-element);
}

.onboarding-banner__text {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.onboarding-banner__text strong {
	font-weight: 600;
}

.onboarding-banner__text span {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
