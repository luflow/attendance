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
					:comment-expanded="commentExpanded[item.id]"
					:comment-value="responseComments[item.id] || ''"
					:saving="savingComments[item.id]"
					:saved="savedComments[item.id]"
					:error="errorComments[item.id]"
					:show-checkin-button="showCheckinButton(item)"
					@respond="respond"
					@toggle-comment="toggleComment"
					@comment-input="onCommentInput"
					@update:comment-value="updateCommentValue(item.id, $event)"
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
import { ref, reactive, computed, onMounted, nextTick } from 'vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import { NcDashboardWidget, NcEmptyContent, NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { usePermissions } from '../composables/usePermissions.js'
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

// State
const appointments = ref(initialAppointments)
const state = ref(initialState)
const ncVersion = ref(ncVersionState)
const responseComments = reactive({})
const savingComments = reactive({})
const savedComments = reactive({})
const errorComments = reactive({})
const commentTimeouts = reactive({})
const commentExpanded = reactive({})

const { permissions, loadPermissions } = usePermissions()

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
	const t = window.t || ((app, text) => text)
	try {
		await submitResponseToServer(appointmentId, response, '')
	} catch (error) {
		showError(t('attendance', 'Failed to save response'))
	}
}

const submitResponseToServer = async (appointmentId, response, commentText) => {
	const url = generateUrl('/apps/attendance/api/appointments/{id}/respond', { id: appointmentId })
	const axiosResponse = await axios.post(url, {
		response,
		comment: commentText,
	})

	if (axiosResponse.status < 200 || axiosResponse.status >= 300) {
		throw new Error(`API returned status ${axiosResponse.status}`)
	}

	const appointmentIndex = appointments.value.findIndex(a => a.id === appointmentId)
	if (appointmentIndex !== -1) {
		appointments.value[appointmentIndex].userResponse = {
			response,
			comment: commentText,
		}
	}
}

const toggleComment = (appointmentId) => {
	const isExpanding = !commentExpanded[appointmentId]
	commentExpanded[appointmentId] = isExpanding

	if (isExpanding && !(appointmentId in responseComments)) {
		const appointment = appointments.value.find(a => a.id === appointmentId)
		responseComments[appointmentId] = appointment?.userResponse?.comment || ''
	}
}

const updateCommentValue = (appointmentId, value) => {
	responseComments[appointmentId] = value
}

const onCommentInput = (appointmentId) => {
	if (commentTimeouts[appointmentId]) {
		clearTimeout(commentTimeouts[appointmentId])
	}

	commentTimeouts[appointmentId] = setTimeout(async () => {
		await nextTick()
		const value = responseComments[appointmentId]
		autoSaveComment(appointmentId, value)
	}, 500)
}

const autoSaveComment = async (appointmentId, commentText) => {
	const t = window.t || ((app, text) => text)
	const appointment = appointments.value.find(a => a.id === appointmentId)

	if (appointment && appointment.userResponse) {
		savingComments[appointmentId] = true
		savedComments[appointmentId] = false
		errorComments[appointmentId] = false

		try {
			await submitResponseToServer(appointmentId, appointment.userResponse.response, commentText)

			setTimeout(() => {
				savingComments[appointmentId] = false
				savedComments[appointmentId] = true

				setTimeout(() => {
					savedComments[appointmentId] = false
				}, 2000)
			}, 500)
		} catch (error) {
			savingComments[appointmentId] = false
			errorComments[appointmentId] = true
			showError(t('attendance', 'Comment could not be saved'))

			setTimeout(() => {
				errorComments[appointmentId] = false
			}, 3000)
		}
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
