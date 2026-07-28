<template>
	<!-- TRANSLATORS: Status badge on an appointment that was called off (German "Abgesagt", not "Abgebrochen"). The generic dialog button "Cancel" (German "Abbrechen") is a different string. -->
	<NcChip
		v-if="isCancelled"
		class="status-chip status-chip--cancelled"
		:text="t('attendance', 'Cancelled')"
		variant="error"
		noClose
		data-test="cancelled-badge" />
	<NcChip
		v-else-if="scheduleStatus"
		class="status-chip"
		:text="scheduleLabel"
		:variant="scheduleStatus === 'booked' ? 'success' : 'error'"
		noClose
		data-test="schedule-badge">
		<template #icon>
			<CalendarCheckIcon v-if="scheduleStatus === 'booked'" :size="14" />
			<CalendarRemoveIcon v-else :size="14" />
		</template>
	</NcChip>
	<NcChip
		v-else-if="isClosed"
		class="status-chip status-chip--closed"
		:text="t('attendance', 'Closed')"
		variant="tertiary"
		noClose
		data-test="closed-badge">
		<template #icon>
			<LockIcon :size="14" />
		</template>
	</NcChip>
</template>

<script setup>
import { NcChip } from '@nextcloud/vue'
import { computed } from 'vue'
import CalendarCheckIcon from 'vue-material-design-icons/CalendarCheck.vue'
import CalendarRemoveIcon from 'vue-material-design-icons/CalendarRemove.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import { usePermissions } from '../../composables/usePermissions.js'
import { finalScheduleStatus } from '../../utils/appointment.js'

const props = defineProps({
	appointment: {
		type: Object,
		required: true,
	},
})

const { capabilities } = usePermissions()

const isCancelled = computed(() => Boolean(props.appointment.cancelledAt))
const isClosed = computed(() => Boolean(props.appointment.closedAt))
const scheduleStatus = computed(() => finalScheduleStatus(props.appointment, capabilities.bookingEnabled))

const scheduleLabel = computed(() => {
	if (scheduleStatus.value === 'booked') {
		// TRANSLATORS: Status badge on the appointment — the logged-in user got a place in the appointment (German "Eingeplant", not "Geplant").
		return t('attendance', 'Scheduled')
	}
	// TRANSLATORS: Status badge on the appointment — the logged-in user did not get a place in the appointment (German "Nicht eingeplant").
	return t('attendance', 'Not scheduled')
})
</script>

<style scoped lang="scss">
.status-chip {
    flex-shrink: 0;

    // The closed state is easy to overlook next to a plain title, so it gets a
    // stronger outline than the default chip.
    &--closed {
        border: 1.5px solid var(--color-border-dark);
    }
}
</style>
