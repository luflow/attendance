<template>
	<div class="checkin-header">
		<BackButton @click="$emit('back')" />
	</div>
</template>

<script setup>
import BackButton from '../common/BackButton.vue'

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
