<template>
	<div class="loading-state" :class="{ 'loading-state--inline': inline }">
		<NcLoadingIcon :size="size" />
		<p v-if="text" class="loading-state__text">
			{{ text }}
		</p>
	</div>
</template>

<script setup>
import { NcLoadingIcon } from '@nextcloud/vue'

defineProps({
	// Label shown next to the spinner; omit for a spinner-only state
	text: {
		type: String,
		default: '',
	},
	size: {
		type: Number,
		default: 32,
	},
	// Compact single-row variant for states inside a panel or list
	inline: {
		type: Boolean,
		default: false,
	},
})
</script>

<style scoped>
.loading-state {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 12px;
	padding: 40px 0;
	color: var(--color-text-maxcontrast);
}

.loading-state--inline {
	flex-direction: row;
	justify-content: flex-start;
	gap: 8px;
	padding: 0;
}

.loading-state__text {
	margin: 0;
	text-align: center;
}
</style>
