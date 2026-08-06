<template>
	<AppBanner :title="t('attendance', 'No appointments yet')"
		:description="t('attendance', 'The setup wizard walks you through permissions, reminders and check-in so the first appointment lands right.')"
		data-test="onboarding-banner">
		<template #icon>
			<RocketLaunchIcon :size="28" />
		</template>
		<template #actions>
			<NcButton variant="primary"
				data-test="onboarding-banner-start"
				@click="$emit('start')">
				<template #icon>
					<RocketLaunchIcon :size="20" />
				</template>
				{{ t('attendance', 'Start setup') }}
			</NcButton>
		</template>
	</AppBanner>
</template>

<script setup>
import { NcButton } from '@nextcloud/vue'
import RocketLaunchIcon from 'vue-material-design-icons/RocketLaunch.vue'
import AppBanner from '../common/AppBanner.vue'

defineEmits(['start'])
</script>
