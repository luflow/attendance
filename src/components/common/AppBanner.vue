<template>
	<div class="app-banner">
		<div class="app-banner__content">
			<slot name="icon" />
			<div class="app-banner__text">
				<strong>{{ title }}</strong>
				<span>{{ description }}</span>
			</div>
		</div>
		<div class="app-banner__actions">
			<slot name="actions" />
		</div>
	</div>
</template>

<script setup>
defineProps({
	title: {
		type: String,
		required: true,
	},
	description: {
		type: String,
		required: true,
	},
})
</script>

<style scoped>
.app-banner {
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

.app-banner__content {
	display: flex;
	align-items: center;
	gap: 12px;
	flex: 1 1 240px;
	min-width: 0;
}

.app-banner__content :deep(.material-design-icon) {
	flex-shrink: 0;
	color: var(--color-primary-element);
}

.app-banner__text {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.app-banner__text strong {
	font-weight: 600;
}

.app-banner__text span {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.app-banner__actions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}
</style>
