<template>
	<NcDialog
		:name="t('attendance', 'Cancel appointment')"
		isForm
		data-test="cancel-appointment-dialog"
		@submit="emit('confirm', reason.trim())"
		@closing="emit('cancel')">
		<div class="cancel-confirm">
			<p class="cancel-confirm__hint">
				<!-- TRANSLATORS: Hint in the dialog for calling off an appointment (German "absagen", not "abbrechen"). -->
				{{ t('attendance', 'Everyone invited is notified that the appointment will not take place.') }}
			</p>
			<NcTextArea
				v-model="reason"
				:label="t('attendance', 'Reason (optional)')"
				:helperText="t('attendance', 'Shown in the notification and on the appointment.')"
				:maxlength="REASON_MAX_LENGTH"
				rows="3"
				data-test="input-cancel-reason" />
		</div>
		<template #actions>
			<NcButton
				variant="tertiary"
				data-test="cancel-appointment-back"
				@click="emit('cancel')">
				{{ t('attendance', 'Back') }}
			</NcButton>
			<NcButton
				variant="error"
				type="submit"
				:disabled="disabled"
				data-test="cancel-appointment-confirm">
				{{ t('attendance', 'Cancel appointment') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup>
import { NcButton, NcDialog, NcTextArea } from '@nextcloud/vue'
import { ref } from 'vue'

defineProps({
	disabled: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits(['confirm', 'cancel'])

// Same bound as AppointmentService::CANCEL_REASON_MAX_LENGTH, which answers 400 above it.
const REASON_MAX_LENGTH = 500

const reason = ref('')
</script>

<style scoped lang="scss">
.cancel-confirm {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding-bottom: 8px;

    &__hint {
        color: var(--color-text-maxcontrast);
    }
}
</style>
