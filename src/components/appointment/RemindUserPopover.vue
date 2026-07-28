<template>
	<NcPopover
		:shown="open"
		popupRole="dialog"
		class="remind-popover-wrapper"
		@update:shown="(value) => open = value">
		<template #trigger>
			<slot :pending="pending" />
		</template>
		<div class="remind-popover" role="dialog" aria-modal="true">
			<p>{{ t('attendance', 'Send a reminder to {name}?', { name: displayName }) }}</p>
			<NcButton
				variant="primary"
				:disabled="pending"
				@click="confirm">
				<template #icon>
					<BellRingOutlineIcon :size="20" />
				</template>
				{{ t('attendance', 'Send reminder') }}
			</NcButton>
		</div>
	</NcPopover>
</template>

<script setup>
import { NcButton, NcPopover } from '@nextcloud/vue'
import { ref } from 'vue'
import BellRingOutlineIcon from 'vue-material-design-icons/BellRingOutline.vue'

const props = defineProps({
	userId: {
		type: String,
		required: true,
	},
	displayName: {
		type: String,
		required: true,
	},
	// A reminder for this user is already on its way.
	pending: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits(['remind'])

const open = ref(false)

function confirm() {
	open.value = false
	emit('remind', props.userId)
}
</script>

<style scoped lang="scss">
.remind-popover-wrapper {
    display: inline-flex;
}
</style>

<style lang="scss">
// Rendered into the popover teleport target, so it cannot be scoped.
.remind-popover {
    padding: 12px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;

    p {
        margin: 0 0 10px 0;
    }
}
</style>
