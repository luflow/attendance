<template>
	<a class="section-link" :href="`#${sectionId}`" @click.prevent="emit('navigate', sectionId)">
		<slot>{{ label }}</slot>
	</a>
</template>

<script setup>
defineProps({
	/** Anchor id of the target NcSettingsSection on this page */
	sectionId: {
		type: String,
		required: true,
	},
	/** Link text; ignored when the default slot is filled */
	label: {
		type: String,
		default: '',
	},
})

const emit = defineEmits(['navigate'])
</script>

<style scoped>
.section-link {
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>
