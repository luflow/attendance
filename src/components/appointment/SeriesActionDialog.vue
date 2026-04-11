<template>
	<NcDialog
		v-if="show"
		:name="dialogTitle"
		@closing="$emit('cancel')">
		<p>
			{{
				t(
					"attendance",
					"This appointment is part of a series of {count} appointments. How would you like to apply this change?",
					{ count: seriesCount },
				)
			}}
		</p>

		<div class="series-options">
			<NcCheckboxRadioSwitch
				v-model="selectedScope"
				value="single"
				name="series-scope"
				type="radio">
				{{ t("attendance", "This appointment only") }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				v-model="selectedScope"
				value="future"
				name="series-scope"
				type="radio">
				{{
					t(
						"attendance",
						"This and all future appointments",
					)
				}}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				v-model="selectedScope"
				value="all"
				name="series-scope"
				type="radio">
				{{
					t(
						"attendance",
						"All appointments in this series",
					)
				}}
			</NcCheckboxRadioSwitch>
		</div>

		<NcNoteCard v-if="action === 'edit' && selectedScope !== 'single'" type="info">
			{{
				t(
					"attendance",
					"When applying to multiple appointments, date and time changes are applied as a relative shift. For example, moving the start time by one hour will shift all affected appointments by one hour.",
				)
			}}
		</NcNoteCard>

		<template #actions>
			<NcButton variant="tertiary" @click="$emit('cancel')">
				{{ t("attendance", "Cancel") }}
			</NcButton>
			<NcButton
				:variant="action === 'delete' ? 'error' : 'primary'"
				@click="$emit('confirm', selectedScope)">
				{{ confirmLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup>
import { ref, computed } from 'vue'
import {
	NcDialog,
	NcButton,
	NcCheckboxRadioSwitch,
	NcNoteCard,
} from '@nextcloud/vue'

const props = defineProps({
	show: {
		type: Boolean,
		required: true,
	},
	action: {
		type: String,
		required: true,
		validator: (value) => ['edit', 'delete'].includes(value),
	},
	seriesCount: {
		type: Number,
		default: 0,
	},
})

defineEmits(['confirm', 'cancel'])

const selectedScope = ref('single')

const dialogTitle = computed(() => {
	return props.action === 'edit'
		? t('attendance', 'Edit recurring appointment')
		: t('attendance', 'Delete recurring appointment')
})

const confirmLabel = computed(() => {
	return props.action === 'edit'
		? t('attendance', 'Save')
		: t('attendance', 'Delete')
})
</script>

<style scoped lang="scss">
.series-options {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin: 12px 0;
}
</style>
