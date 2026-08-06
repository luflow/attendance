<template>
	<AppBanner :title="copy.title" :description="copy.description" data-test="onboarding-banner">
		<template #icon>
			<RocketLaunchIcon :size="28" />
		</template>
		<template #actions>
			<NcButton :variant="copy.variant"
				data-test="onboarding-banner-start"
				@click="$emit('start')">
				<template #icon>
					<RocketLaunchIcon :size="20" />
				</template>
				{{ copy.action }}
			</NcButton>
		</template>
	</AppBanner>
</template>

<script setup>
import { NcButton } from '@nextcloud/vue'
import { computed } from 'vue'
import RocketLaunchIcon from 'vue-material-design-icons/RocketLaunch.vue'
import AppBanner from '../common/AppBanner.vue'

const props = defineProps({
	/** 'start' before the wizard has been walked, 'review' afterwards */
	prompt: {
		type: String,
		default: 'start',
	},
})

defineEmits(['start'])

const copy = computed(() => (props.prompt === 'review'
	? {
			title: t('attendance', 'Setup finished'),
			description: t('attendance', 'Permissions, reminders and check-in are configured. Run the wizard again any time to change them.'),
			action: t('attendance', 'Review setup'),
			variant: 'secondary',
		}
	: {
			title: t('attendance', 'No appointments yet'),
			description: t('attendance', 'The setup wizard walks you through permissions, reminders and check-in so the first appointment lands right.'),
			action: t('attendance', 'Start setup'),
			variant: 'primary',
		}))
</script>
