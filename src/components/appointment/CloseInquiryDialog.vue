<template>
	<NcDialog
		:name="t('attendance', 'Close and notify?')"
		data-test="close-booking-dialog"
		@closing="emit('cancel')">
		<div class="booking-confirm">
			<p class="booking-confirm__hint">
				<!-- TRANSLATORS: Hint in the close-inquiry dialog — people get notified whether they got a place in the appointment or not (German: "Planungsstatus"). -->
				{{ t('attendance', 'Closing notifies these people about their scheduling status.') }}
			</p>
			<div
				v-for="group in groups"
				:key="group.key"
				class="booking-confirm__group">
				<h4>{{ group.label }}</h4>
				<p v-if="group.names.length === 0" class="booking-confirm__empty">
					{{ t('attendance', 'Nobody') }}
				</p>
				<p v-else class="booking-confirm__names">
					{{ visibleNames(group).join(', ') }}
					<NcButton
						v-if="group.names.length > previewLimit"
						variant="tertiary"
						@click="expanded[group.key] = !expanded[group.key]">
						{{ expanded[group.key]
							? t('attendance', 'Show less')
							: t('attendance', '+{count} more', { count: group.names.length - previewLimit }) }}
					</NcButton>
				</p>
			</div>
		</div>
		<template #actions>
			<NcButton
				variant="tertiary"
				data-test="close-booking-cancel"
				@click="emit('cancel')">
				{{ t('attendance', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="disabled"
				data-test="close-booking-confirm"
				@click="emit('confirm')">
				{{ t('attendance', 'Close inquiry') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { ref } from 'vue'

const props = defineProps({
	// [{ key, label, names }] — scheduled-in and not-scheduled-in.
	groups: {
		type: Array,
		required: true,
	},
	previewLimit: {
		type: Number,
		default: 10,
	},
	disabled: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits(['confirm', 'cancel'])

const expanded = ref({})

function visibleNames(group) {
	return expanded.value[group.key] ? group.names : group.names.slice(0, props.previewLimit)
}
</script>

<style scoped lang="scss">
.booking-confirm {
    display: flex;
    flex-direction: column;
    gap: 12px;

    &__hint {
        color: var(--color-text-maxcontrast);
    }

    &__group h4 {
        margin: 0 0 4px;
    }

    &__names {
        line-height: 1.5;
    }

    &__empty {
        color: var(--color-text-maxcontrast);
        font-style: italic;
    }
}
</style>
