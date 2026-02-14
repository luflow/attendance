<template>
	<div class="appointment-widget-container" :data-nc-version="ncVersion" data-test="widget-container">
		<NcDashboardWidget
			:items="items"
			:loading="state === 'loading'"
			class="appointment-widget"
			data-test="appointment-widget">
			<template #empty-content>
				<NcEmptyContent :title="t('attendance', 'No appointments found')">
					<template #icon>
						<CalendarIcon />
					</template>
				</NcEmptyContent>
			</template>
			<template #default="{ item }">
				<WidgetAppointmentItem
					:item="item"
					:show-checkin-button="showCheckinButton(item)"
					:display-order="displayOrder"
					@respond="respond"
					@open-checkin="openCheckinView"
					@open-detail="openAppointmentDetail" />
			</template>
		</NcDashboardWidget>

		<!-- Show All Button -->
		<div class="widget-footer">
			<NcButton
				variant="primary"
				wide
				data-test="button-show-all"
				@click="goToAttendanceApp">
				{{ t('attendance', 'Show all appointments') }}
			</NcButton>
		</div>
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import { NcDashboardWidget, NcEmptyContent, NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import { usePermissions } from '../composables/usePermissions.js'
import { useAppointmentResponse } from '../composables/useAppointmentResponse.js'
import { canCheckinNow } from '../utils/datetime.js'
import WidgetAppointmentItem from '../components/widget/WidgetAppointmentItem.vue'

defineProps({
	title: {
		type: String,
		required: true,
	},
})

// Load initial state
let initialAppointments = []
let initialState = 'ok'
let ncVersionState = 31
let displayOrderState = 'name_first'

try {
	initialAppointments = loadState('attendance', 'dashboard-widget-items')
} catch (error) {
	console.error('Error loading appointments:', error)
	initialState = 'error'
}

try {
	ncVersionState = loadState('attendance', 'nc-version')
} catch (error) {
	console.debug('nc-version not available, defaulting to 31')
}

try {
	displayOrderState = loadState('attendance', 'display-order')
} catch (error) {
	console.debug('display-order not available, defaulting to name_first')
}

// State
const appointments = ref(initialAppointments)
const state = ref(initialState)
const ncVersion = ref(ncVersionState)
const displayOrder = ref(displayOrderState)

const { permissions, loadPermissions } = usePermissions()

// Use the shared response composable
const { submitResponse: submitResponseApi } = useAppointmentResponse()

// Computed
const items = computed(() => {
	return appointments.value.map((appointment) => ({
		id: appointment.id,
		mainText: appointment.name,
		subText: appointment.startDatetime,
		description: appointment.description,
		link: generateUrl('/apps/attendance'),
		iconUrl: generateUrl('/svg/attendance/calendar'),
		userResponse: appointment.userResponse,
	}))
})

// Methods
const respond = async (appointmentId, response) => {
	try {
		await submitResponseApi(appointmentId, response, '')
		// Update local state after successful response
		const appointmentIndex = appointments.value.findIndex(a => a.id === appointmentId)
		if (appointmentIndex !== -1) {
			appointments.value[appointmentIndex].userResponse = {
				response,
				comment: '',
			}
		}
	} catch (error) {
		// Error already handled by composable
	}
}

const goToAttendanceApp = () => {
	window.location.href = generateUrl('/apps/attendance/')
}

const openAppointmentDetail = (appointmentId) => {
	window.location.href = generateUrl(`/apps/attendance/appointment/${appointmentId}`)
}

const showCheckinButton = (item) => {
	if (!permissions.canCheckin) {
		return false
	}
	return canCheckinNow(item.subText, 30)
}

const openCheckinView = (appointmentId) => {
	const checkinUrl = generateUrl('/apps/attendance/checkin/{id}', { id: appointmentId })
	window.location.href = checkinUrl
}

// Lifecycle
onMounted(async () => {
	await loadPermissions()
})
</script>

<style scoped lang="scss">
/* Dark mode: explicit dark theme */
body[data-theme-dark] .appointment-widget-container[data-nc-version="31"] :deep(.button-vue--warning) {
	color: black !important;
}

/* Dark mode: system preference when using default theme */
@media (prefers-color-scheme: dark) {
	body[data-theme-default] .appointment-widget-container[data-nc-version="31"] :deep(.button-vue--warning) {
		color: black !important;
	}
}

.appointment-widget-container {
	display: flex;
	flex-direction: column;
	height: 100%;
	max-height: 450px;
}

.appointment-widget {
	overflow-y: auto;
	flex: 1;
	min-height: 0;
}

.widget-footer {
	flex-shrink: 0;
	padding: 12px 12px 20px 12px;
}
</style>
