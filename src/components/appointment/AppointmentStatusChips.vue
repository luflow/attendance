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
		v-if="neutralChip"
		class="status-chip status-chip--neutral"
		:text="neutralChip.text"
		variant="tertiary"
		noClose
		:data-test="neutralChip.test">
		<template #icon>
			<component :is="neutralChip.icon" :size="14" />
		</template>
	</NcChip>
</template>

<script setup>
import { NcChip } from '@nextcloud/vue'
import { computed } from 'vue'
import CalendarCheckIcon from 'vue-material-design-icons/CalendarCheck.vue'
import CalendarRemoveIcon from 'vue-material-design-icons/CalendarRemove.vue'
import HistoryIcon from 'vue-material-design-icons/History.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import { usePermissions } from '../../composables/usePermissions.js'
import { finalScheduleStatus } from '../../utils/appointment.js'
import { isPast as isPastDate } from '../../utils/datetime.js'

const props = defineProps({
	appointment: {
		type: Object,
		required: true,
	},
})

const { capabilities } = usePermissions()

const isCancelled = computed(() => Boolean(props.appointment.cancelledAt))
const isClosed = computed(() => Boolean(props.appointment.closedAt))
// List rows carry the server's verdict; the detail payload has none.
const isPast = computed(() => props.appointment.isPast ?? isPastDate(props.appointment.endDatetime))
const scheduleStatus = computed(() => finalScheduleStatus(props.appointment, capabilities.bookingEnabled))

// Over is the stronger reason nobody can answer any more, so it replaces "Closed".
const neutralChip = computed(() => {
	if (isCancelled.value) return null
	if (isPast.value) {
		// TRANSLATORS: Status badge on a single appointment that is over (German "Vergangen").
		return { text: t('attendance', 'Past'), icon: HistoryIcon, test: 'past-badge' }
	}
	if (isClosed.value && !scheduleStatus.value) {
		return { text: t('attendance', 'Closed'), icon: LockIcon, test: 'closed-badge' }
	}
	return null
})

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

    // Grey on grey is easy to overlook next to a plain title, so the neutral
    // states get a stronger outline than the default chip.
    &--neutral {
        border: 1.5px solid var(--color-border-dark);
    }
}
</style>
