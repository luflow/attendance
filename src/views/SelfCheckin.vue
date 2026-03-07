<template>
	<div class="self-checkin-container">
		<!-- Loading State -->
		<template v-if="loading">
			<div class="loading-state">
				<NcLoadingIcon :size="44" />
				<p>{{ t('attendance', 'Finding active appointments…') }}</p>
			</div>
		</template>

		<!-- Error State -->
		<template v-else-if="error">
			<NcNoteCard type="error">
				<p>{{ errorMessage }}</p>
			</NcNoteCard>
			<div class="button-row">
				<NcButton variant="primary" @click="loadAppointments">
					{{ t('attendance', 'Try again') }}
				</NcButton>
			</div>
		</template>

		<!-- Success State -->
		<template v-else-if="checkedIn">
			<div class="success-state">
				<NcNoteCard type="success">
					<h2>{{ checkedInAlready ? t('attendance', 'Already checked in') : t('attendance', 'Checked in!') }}</h2>
				</NcNoteCard>

				<div class="appointment-details">
					<h3>{{ checkedInAppointment.name }}</h3>
					<p class="date-time">
						<CalendarIcon :size="18" />
						{{ formatDateRange(checkedInAppointment.startDatetime, checkedInAppointment.endDatetime) }}
					</p>
					<p v-if="checkedInAt" class="checkin-time">
						{{ t('attendance', 'Checked in at {time}', { time: formatTime(checkedInAt) }) }}
					</p>
				</div>
			</div>
		</template>

		<!-- No Appointments -->
		<template v-else-if="appointments.length === 0">
			<NcNoteCard type="info">
				<h2>{{ t('attendance', 'No active appointments') }}</h2>
				<p>{{ t('attendance', 'There are no appointments happening right now.') }}</p>
			</NcNoteCard>
			<div class="button-row">
				<NcButton variant="primary" :href="appUrl">
					{{ t('attendance', 'Open Attendance app') }}
				</NcButton>
			</div>
		</template>

		<!-- Single Appointment: Auto check-in -->
		<template v-else-if="appointments.length === 1">
			<h2>{{ t('attendance', 'Check in') }}</h2>
			<div class="appointment-details">
				<h3>{{ appointments[0].name }}</h3>
				<p class="date-time">
					<CalendarIcon :size="18" />
					{{ formatDateRange(appointments[0].startDatetime, appointments[0].endDatetime) }}
				</p>
			</div>
			<div class="button-row">
				<NcButton
					variant="primary"
					:disabled="submitting"
					@click="doCheckin(appointments[0].id)">
					<template #icon>
						<NcLoadingIcon v-if="submitting" :size="20" />
						<CheckIcon v-else :size="20" />
					</template>
					{{ t('attendance', 'Check in now') }}
				</NcButton>
			</div>
		</template>

		<!-- Multiple Appointments: Let user choose -->
		<template v-else>
			<h2>{{ t('attendance', 'Which appointment?') }}</h2>
			<p class="subtitle">{{ t('attendance', 'Select the appointment you want to check into:') }}</p>
			<div class="appointment-list">
				<div
					v-for="appointment in appointments"
					:key="appointment.id"
					class="appointment-card"
					@click="doCheckin(appointment.id)">
					<div class="appointment-info">
						<h3>{{ appointment.name }}</h3>
						<p class="date-time">
							<CalendarIcon :size="16" />
							{{ formatDateRange(appointment.startDatetime, appointment.endDatetime) }}
						</p>
					</div>
					<NcLoadingIcon v-if="submitting && submittingId === appointment.id" :size="20" />
					<CheckIcon v-else :size="20" />
				</div>
			</div>
		</template>
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { NcButton, NcNoteCard, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import { formatDateRange } from '../utils/datetime.js'

// State
const loading = ref(true)
const error = ref(false)
const errorMessage = ref('')
const appointments = ref([])
const submitting = ref(false)
const submittingId = ref(null)
const checkedIn = ref(false)
const checkedInAlready = ref(false)
const checkedInAppointment = ref(null)
const checkedInAt = ref(null)

// Computed
const appUrl = computed(() => generateUrl('/apps/attendance/'))

// Format time for display
const formatTime = (datetime) => {
	if (!datetime) return ''
	try {
		const date = new Date(datetime)
		return date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
	} catch {
		return datetime
	}
}

// Load active appointments
const loadAppointments = async () => {
	loading.value = true
	error.value = false

	try {
		const url = generateUrl('/apps/attendance/api/self-checkin/appointments')
		const response = await axios.get(url)
		appointments.value = response.data

		// If there's exactly one appointment and the user is already checked in, show that
		if (response.data.length === 1 && response.data[0].alreadyCheckedIn) {
			checkedIn.value = true
			checkedInAlready.value = true
			checkedInAppointment.value = response.data[0]
			checkedInAt.value = response.data[0].checkinAt
		}
	} catch (err) {
		error.value = true
		errorMessage.value = err.response?.data?.error || t('attendance', 'Failed to load appointments.')
	} finally {
		loading.value = false
	}
}

// Perform check-in
const doCheckin = async (appointmentId) => {
	if (submitting.value) return

	submitting.value = true
	submittingId.value = appointmentId

	try {
		const url = generateUrl('/apps/attendance/api/self-checkin')
		const response = await axios.post(url, { appointmentId })

		checkedIn.value = true
		checkedInAlready.value = response.data.alreadyCheckedIn || false
		checkedInAppointment.value = response.data.appointment
		checkedInAt.value = response.data.checkinAt
	} catch (err) {
		error.value = true
		errorMessage.value = err.response?.data?.error || t('attendance', 'Failed to check in.')
	} finally {
		submitting.value = false
		submittingId.value = null
	}
}

onMounted(() => {
	loadAppointments()
})
</script>

<style scoped lang="scss">
.self-checkin-container {
	max-width: 500px;
	margin: 40px auto;
	padding: 20px;

	h2 {
		text-align: center;
		margin-bottom: 8px;
	}

	.subtitle {
		text-align: center;
		color: var(--color-text-maxcontrast);
		margin-bottom: 20px;
	}
}

.loading-state {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 16px;
	padding: 40px 0;

	p {
		color: var(--color-text-maxcontrast);
	}
}

.success-state {
	text-align: center;
}

.appointment-details {
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	padding: 16px;
	margin: 20px 0;

	h3 {
		margin: 0 0 12px 0;
	}

	.date-time {
		display: flex;
		align-items: center;
		gap: 8px;
		margin: 8px 0;
		color: var(--color-text-maxcontrast);
	}

	.checkin-time {
		color: var(--color-text-maxcontrast);
		font-size: 14px;
		margin-top: 8px;
	}
}

.appointment-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.appointment-card {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 16px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	cursor: pointer;
	transition: background 0.2s;

	&:hover {
		background: var(--color-background-dark);
	}

	.appointment-info {
		h3 {
			margin: 0 0 4px 0;
		}

		.date-time {
			display: flex;
			align-items: center;
			gap: 6px;
			color: var(--color-text-maxcontrast);
			font-size: 14px;
		}
	}
}

.button-row {
	display: flex;
	gap: 12px;
	justify-content: center;
	margin-top: 20px;
}
</style>
