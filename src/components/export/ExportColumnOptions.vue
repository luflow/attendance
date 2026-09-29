<template>
	<div class="export-columns">
		<label class="section-label">{{ t('attendance', 'Columns per appointment') }}</label>

		<div class="column-switches">
			<NcCheckboxRadioSwitch v-model="columns.includeRsvp" type="checkbox">
				{{ t('attendance', 'RSVP') }}
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch v-model="columns.includeCheckin" type="checkbox">
				{{ t('attendance', 'Check-in') }}
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch v-model="columns.includeComments" type="checkbox">
				{{ t('attendance', 'Comments') }}
			</NcCheckboxRadioSwitch>
		</div>

		<NcNoteCard v-if="!hasAnyColumn" type="warning" class="column-warning">
			{{ t('attendance', 'Select at least one column') }}
		</NcNoteCard>

		<NcCheckboxRadioSwitch v-model="columns.includeOrganizers" type="checkbox" class="organizer-option">
			{{ t('attendance', 'Add a row with the organizers of each appointment') }}
		</NcCheckboxRadioSwitch>
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'

const columns = defineModel({ type: Object, required: true })

defineProps({
	hasAnyColumn: {
		type: Boolean,
		required: true,
	},
})
</script>

<style scoped>
.section-label {
	display: block;
	margin-bottom: 12px;
	font-weight: 600;
}

.column-switches {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.column-warning {
	margin: 10px 0 0 0;
}

.organizer-option {
	margin-top: 16px;
}
</style>
