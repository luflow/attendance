<template>
	<NcDialog :open="show"
		:name="t('attendance', 'Calendar subscription')"
		@update:open="emit('close')">
		<!-- Mounted per opening, so the link is read fresh each time. -->
		<IcalFeedSubscription v-if="show" class="ical-feed-modal" />
	</NcDialog>
</template>

<script setup>
import { NcDialog } from '@nextcloud/vue'
import IcalFeedSubscription from './IcalFeedSubscription.vue'

defineProps({
	show: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits(['close'])
</script>

<style scoped>
.ical-feed-modal {
	padding: 12px 0;
}
</style>
