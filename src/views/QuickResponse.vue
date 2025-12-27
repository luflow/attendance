<template>
	<div class="quick-response-container guest-box" :data-nc-version="ncVersion">
		<!-- Error State -->
		<template v-if="error">
			<NcNoteCard type="error">
				<h2>{{ t('attendance', 'Unable to process response') }}</h2>
				<p>{{ errorMessage }}</p>
			</NcNoteCard>
			<div class="button-row">
				<NcButton variant="primary" :href="appUrl">
					{{ t('attendance', 'Open Attendance App') }}
				</NcButton>
			</div>
		</template>

		<!-- Success State -->
		<template v-else-if="confirmed">
			<NcNoteCard type="success">
				<h2>{{ t('attendance', 'Thank you for your response!') }}</h2>
				<p>{{ t('attendance', 'Your response has been saved successfully.') }}</p>
			</NcNoteCard>

			<div class="appointment-details">
				<h3>{{ appointmentName }}</h3>
				<p class="date-time">
					<CalendarIcon :size="18" />
					<strong>{{ formattedDate }}</strong>
				</p>
				<p class="date-time">
					<ClockIcon :size="18" />
					{{ formattedTime }}
				</p>
				<p class="response-display">
					{{ t('attendance', 'Your response:') }}
					<NcChip :text="responseLabel" :variant="responseVariant" no-close />
				</p>
			</div>

			<div class="button-row">
				<NcButton :href="appointmentUrl">
					{{ t('attendance', 'View Appointment Details') }}
				</NcButton>
				<NcButton variant="primary" :href="appUrl">
					{{ t('attendance', 'Open Attendance App') }}
				</NcButton>
			</div>
		</template>

		<!-- Confirmation State -->
		<template v-else>
			<h2>{{ t('attendance', 'Confirm your response?') }}</h2>
			<p class="responding-as">{{ t('attendance', 'You are answering as') }} <strong>{{ userName }}</strong></p>

			<div class="appointment-details">
				<h3>{{ appointmentName }}</h3>
				<p class="date-time">
					<CalendarIcon :size="18" />
					<strong>{{ formattedDate }}</strong>
				</p>
				<p class="date-time">
					<ClockIcon :size="18" />
					{{ formattedTime }}
				</p>
				<p class="response-display">
					{{ t('attendance', 'You are about to respond with') }}
					<NcChip :text="responseLabel" :variant="responseVariant" no-close />
				</p>
			</div>

			<div class="button-row">
				<NcButton
					variant="primary"
					:disabled="submitting"
					@click="confirmResponse">
					<template #icon>
						<NcLoadingIcon v-if="submitting" :size="20" />
						<CheckIcon v-else :size="20" />
					</template>
					{{ t('attendance', 'Confirm Response') }}
				</NcButton>
			</div>

			<div class="link-row">
				<a :href="appointmentUrl">{{ t('attendance', 'View Appointment Details') }}</a>
				<span class="separator">·</span>
				<a :href="appUrl">{{ t('attendance', 'Open Attendance App') }}</a>
			</div>
		</template>
	</div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { NcButton, NcNoteCard, NcLoadingIcon, NcChip } from '@nextcloud/vue'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import ClockIcon from 'vue-material-design-icons/Clock.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import { formatDate, formatTime } from '../utils/datetime.js'
import { getResponseVariant } from '../utils/response.js'

// Load initial state from server
const initialState = loadState('attendance', 'quick-response-data', {})

// Load NC version for CSS compatibility
let ncVersionState = 31
try {
	ncVersionState = loadState('attendance', 'nc-version')
} catch (error) {
	console.debug('nc-version not available, defaulting to 31')
}
const ncVersion = ref(ncVersionState)

// Reactive state
const error = ref(initialState.error || false)
const errorMessage = ref(initialState.errorMessage || '')
const confirmed = ref(initialState.confirmed || false)
const submitting = ref(false)

// Appointment data
const appointmentId = initialState.appointmentId || 0
const appointmentName = initialState.appointmentName || ''
const appointmentDatetime = initialState.appointmentDatetime || ''
const response = initialState.response || ''
const responseLabel = initialState.responseLabel || ''
const token = initialState.token || ''
const userId = initialState.userId || ''
const userName = initialState.userName || ''

// Computed date/time using app utilities
const formattedDate = computed(() => formatDate(appointmentDatetime, 'long'))
const formattedTime = computed(() => formatTime(appointmentDatetime))

// Computed URLs
const appUrl = computed(() => generateUrl('/apps/attendance/'))
const appointmentUrl = computed(() => generateUrl('/apps/attendance/appointment/{id}', { id: appointmentId }))

// Computed response variant for NcChip
const responseVariant = computed(() => getResponseVariant(response))

// Methods
const confirmResponse = async () => {
	submitting.value = true
	try {
		const url = generateUrl('/apps/attendance/respond/{appointmentId}/confirm', { appointmentId })
		await axios.post(url, {
			response,
			token,
			userId,
		})
		confirmed.value = true
	} catch (err) {
		error.value = true
		errorMessage.value = err.response?.data?.message || 'An error occurred while recording your response.'
	} finally {
		submitting.value = false
	}
}
</script>

<style scoped lang="scss">
.quick-response-container {
	max-width: 500px;
	margin: 0 auto;
	padding: 20px;

	h2 {
		margin-bottom: 10px;
	}
}

.responding-as {
	color: var(--color-text-maxcontrast);
	margin-bottom: 20px;
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

	.response-display {
		display: flex;
		align-items: center;
		gap: 12px;
		margin-top: 16px;
	}
}

.button-row {
	display: flex;
	gap: 12px;
	justify-content: center;
	margin-top: 20px;
	flex-wrap: wrap;
}

.link-row {
	display: flex;
	gap: 8px;
	justify-content: center;
	margin-top: 16px;
	font-size: 14px;

	a {
		color: var(--color-primary-element);
		text-decoration: none;

		&:hover {
			text-decoration: underline;
		}
	}

	.separator {
		color: var(--color-text-maxcontrast);
	}
}
</style>

<style>
/* NcChip text color overrides for Nextcloud 31 */
#attendance-quick-response [data-nc-version="31"] .nc-chip--error .nc-chip__text,
#attendance-quick-response [data-nc-version="31"] .nc-chip--warning .nc-chip__text,
#attendance-quick-response [data-nc-version="31"] .nc-chip--success .nc-chip__text {
	color: white !important;
}

/* Dark Mode (explicit): Warning elements need black text for better contrast */
body[data-theme-dark] #attendance-quick-response [data-nc-version="31"] .nc-chip--warning .nc-chip__text {
	color: black !important;
}

/* Dark Mode (system preference): Only when using default theme */
@media (prefers-color-scheme: dark) {
	body[data-theme-default] #attendance-quick-response [data-nc-version="31"] .nc-chip--warning .nc-chip__text {
		color: black !important;
	}
}
</style>
