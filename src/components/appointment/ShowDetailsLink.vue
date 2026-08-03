<template>
	<a
		:href="detailUrl"
		class="show-details"
		data-test="button-show-details"
		@click.prevent="emit('open', appointmentId)">
		{{ t("attendance", "Show details") }}
		<ChevronRightIcon :size="15" />
	</a>
</template>

<script setup>
import { computed } from 'vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import { appointmentDetailUrl } from '../../utils/appointment.js'

const props = defineProps({
	appointmentId: {
		type: Number,
		required: true,
	},
})

const emit = defineEmits(['open'])

const detailUrl = computed(() => appointmentDetailUrl(props.appointmentId))
</script>

<style scoped lang="scss">
// Trails whichever row it is dropped into — the response bar's legend, or the
// answer row when there is no bar to hang off.
.show-details {
    margin-inline-start: auto;
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 13.5px;
    font-weight: 600;
    color: var(--color-primary-element);
    text-decoration: none;

    &:hover {
        text-decoration: underline;
    }
}
</style>
