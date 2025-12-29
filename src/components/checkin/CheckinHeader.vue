<template>
	<div class="checkin-header">
		<NcButton variant="tertiary" data-test="button-back" @click="$emit('back')">
			<template #icon>
				<ArrowLeftIcon />
			</template>
			{{ t('attendance', 'Back') }}
		</NcButton>
	</div>
</template>

<script setup>
import { NcButton } from '@nextcloud/vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'

defineEmits(['back'])
</script>

<style scoped lang="scss">
.checkin-header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 24px;

	h1 {
		margin: 0;
		font-size: 24px;
		font-weight: 600;
	}
}
</style>
