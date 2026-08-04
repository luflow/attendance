<template>
	<NcPopover>
		<template #trigger>
			<NcButton variant="tertiary" :aria-label="t('attendance', 'Choose icon')" :data-test="dataTest">
				<template #icon>
					<component :is="categoryIconComponent(modelValue)" :size="20" />
				</template>
			</NcButton>
		</template>
		<template #default>
			<ul class="category-icon-picker" role="listbox" :aria-label="t('attendance', 'Choose icon')">
				<li v-for="icon in CATEGORY_ICON_KEYS" :key="icon" role="presentation">
					<NcButton
						variant="tertiary"
						:pressed="icon === modelValue"
						:aria-label="icon"
						:data-test="`category-icon-option-${icon}`"
						@click="emit('update:modelValue', icon)">
						<template #icon>
							<component :is="categoryIconComponent(icon)" :size="20" />
						</template>
					</NcButton>
				</li>
			</ul>
		</template>
	</NcPopover>
</template>

<script setup>
import { NcButton, NcPopover } from '@nextcloud/vue'
import { CATEGORY_ICONS, categoryIconComponent } from '../../utils/categoryIcons.js'

defineProps({
	modelValue: {
		type: String,
		required: true,
	},
	dataTest: {
		type: String,
		default: undefined,
	},
})

const emit = defineEmits(['update:modelValue'])

const CATEGORY_ICON_KEYS = Object.keys(CATEGORY_ICONS)
</script>

<style scoped>
.category-icon-picker {
	display: grid;
	grid-template-columns: repeat(5, 1fr);
	gap: 4px;
	padding: 8px;
	max-width: 220px;
}
</style>
