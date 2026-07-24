<template>
	<div class="appointment-card" data-test="appointment-card">
		<div class="appointment-header">
			<div class="appointment-title-block">
				<template v-if="displayOrder === 'date_first'">
					<h3 data-test="appointment-title" class="appointment-date-title">
						{{
							formatDateRange(
								appointment.startDatetime,
								appointment.endDatetime,
							)
						}}
						<a
							v-if="calendarLink"
							:href="calendarLink"
							target="_blank"
							rel="noopener noreferrer"
							class="calendar-link"
							:title="t('attendance', 'Imported from calendar')">
							<CalendarSyncIcon :size="14" />
						</a>
						<span
							v-if="appointment.seriesId"
							class="series-indicator"
							:title="t('attendance', 'Part of a recurring series')">
							<RepeatIcon :size="14" />
						</span>
					</h3>
					<span class="appointment-date-subtitle">
						{{ appointment.name }}
					</span>
				</template>
				<template v-else>
					<h3 data-test="appointment-title">
						{{ appointment.name }}
						<span
							v-if="appointment.seriesId"
							class="series-indicator"
							:title="t('attendance', 'Part of a recurring series')">
							<RepeatIcon :size="14" />
						</span>
					</h3>
					<span class="appointment-date-subtitle">
						{{
							formatDateRange(
								appointment.startDatetime,
								appointment.endDatetime,
							)
						}}
						<a
							v-if="calendarLink"
							:href="calendarLink"
							target="_blank"
							rel="noopener noreferrer"
							class="calendar-link"
							:title="t('attendance', 'Imported from calendar')">
							<CalendarSyncIcon :size="14" />
						</a>
					</span>
				</template>
				<NcChip
					v-if="isCancelled"
					class="cancelled-badge"
					:text="t('attendance', 'Cancelled')"
					variant="error"
					noClose
					data-test="cancelled-badge" />
			</div>
			<div class="appointment-actions">
				<NcActions
					:forceMenu="true"
					data-test="appointment-actions-menu">
					<NcActionButton
						:closeAfterClick="true"
						data-test="action-share-link"
						@click="copyShareLink">
						<template #icon>
							<ShareVariantIcon :size="20" />
						</template>
						{{ t("attendance", "Share link") }}
					</NcActionButton>
					<NcActionButton
						v-if="canCheckin"
						:closeAfterClick="true"
						data-test="action-start-checkin"
						@click="handleStartCheckin">
						<template #icon>
							<ListStatusIcon :size="20" />
						</template>
						{{ t("attendance", "Start check-in") }}
					</NcActionButton>
					<NcActionButton
						v-if="canManageAppointments && !isClosed"
						:closeAfterClick="true"
						:disabled="sendingReminders"
						data-test="action-remind-all"
						@click="showRemindDialog = true">
						<template #icon>
							<BellRingIcon :size="20" />
						</template>
						{{ t("attendance", "Remind") }}
					</NcActionButton>
					<NcActionButton
						v-if="canToggleClosed"
						:closeAfterClick="true"
						:disabled="togglingClosed"
						:data-test="isClosed ? 'action-reopen-inquiry' : 'action-close-inquiry'"
						@click="handleToggleClosed">
						<template #icon>
							<LockOpenIcon v-if="isClosed" :size="20" />
							<LockIcon v-else :size="20" />
						</template>
						{{
							isClosed
								? t("attendance", "Reopen inquiry")
								: t("attendance", "Close inquiry")
						}}
					</NcActionButton>
					<NcActionButton
						v-if="canCancel"
						:closeAfterClick="true"
						:disabled="togglingCancelled"
						:data-test="isCancelled ? 'action-reactivate-appointment' : 'action-cancel-appointment'"
						@click="handleToggleCancelled">
						<template #icon>
							<CalendarRefreshIcon v-if="isCancelled" :size="20" />
							<CalendarRemoveIcon v-else :size="20" />
						</template>
						{{
							isCancelled
								? t("attendance", "Reactivate appointment")
								: t("attendance", "Cancel appointment")
						}}
					</NcActionButton>
					<NcActionButton
						v-if="canManageAppointments"
						:closeAfterClick="true"
						data-test="action-edit"
						@click="handleEdit">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t("attendance", "Edit") }}
					</NcActionButton>
					<NcActionButton
						v-if="canManageAppointments"
						:closeAfterClick="true"
						data-test="action-export"
						@click="handleExport">
						<template #icon>
							<DownloadIcon :size="20" />
						</template>
						{{ t("attendance", "Export") }}
					</NcActionButton>
					<NcActionButton
						v-if="canManageAppointments"
						:closeAfterClick="true"
						data-test="action-copy"
						@click="handleCopy">
						<template #icon>
							<ContentCopy :size="20" />
						</template>
						{{ t("attendance", "Copy") }}
					</NcActionButton>
					<NcActionButton
						v-if="canSeeAuditLog"
						:closeAfterClick="true"
						data-test="action-show-audit-log"
						@click="emit('showAuditLog', appointment.id)">
						<template #icon>
							<HistoryIcon :size="20" />
						</template>
						{{ t("attendance", "Show activity history") }}
					</NcActionButton>
					<NcActionButton
						v-if="canManageAppointments"
						:closeAfterClick="true"
						data-test="action-delete"
						@click="handleDelete">
						<template #icon>
							<Delete :size="20" />
						</template>
						{{ t("attendance", "Delete") }}
					</NcActionButton>
				</NcActions>
			</div>
		</div>

		<!-- eslint-disable vue/no-v-html -- sanitized with DOMPurify -->
		<div
			v-if="appointment.description"
			class="appointment-description"
			v-html="renderedDescription" />
		<!-- eslint-enable vue/no-v-html -->

		<div
			v-if="appointment.attachments?.length"
			class="attachment-chips"
			data-test="attachment-chips">
			<a
				v-for="attachment in appointment.attachments"
				:key="attachment.fileId"
				:href="getAttachmentUrl(attachment)"
				target="_blank"
				rel="noopener noreferrer"
				class="attachment-link"
				:data-test="`attachment-link-${attachment.fileId}`">
				<NcChip :text="attachment.fileName" noClose>
					<template #icon>
						<Paperclip :size="16" />
					</template>
				</NcChip>
			</a>
		</div>

		<!-- Read-only response chip while the inquiry is closed -->
		<div
			v-if="isClosed"
			class="response-section response-section--readonly"
			data-test="response-section-readonly">
			<div class="response-row">
				<h4>{{ t("attendance", "Your response") }}</h4>
				<NcChip
					:text="userResponse ? getResponseText(userResponse) : t('attendance', 'No response')"
					:variant="userResponse ? getResponseVariant(userResponse) : 'tertiary'"
					noClose />
			</div>
			<div v-if="canToggleClosed" class="closed-banner" data-test="closed-banner">
				<LockIcon :size="20" />
				<div class="closed-banner-text">
					<strong>{{ t("attendance", "Inquiry closed") }}</strong>
					<span v-if="formattedClosedAt">{{ closedLabel }}</span>
				</div>
				<NcButton
					variant="secondary"
					:disabled="togglingClosed"
					data-test="banner-reopen-inquiry"
					@click="handleToggleClosed">
					{{ t("attendance", "Reopen") }}
				</NcButton>
			</div>
			<div v-else class="closed-info" data-test="closed-info">
				<LockIcon :size="16" />
				<span>{{ closedLabel }}</span>
			</div>
		</div>

		<!-- Response Section (hidden once the inquiry is closed) -->
		<div
			v-if="!isClosed"
			class="response-section"
			data-test="response-section">
			<h4>{{ t("attendance", "Your response") }}</h4>
			<div
				class="response-buttons"
				:class="{ 'has-response': userResponse }">
				<NcButton
					:class="{ active: userResponse === 'yes' }"
					variant="success"
					:text="t('attendance', 'Yes')"
					:disabled="responseCooldown"
					data-test="response-yes"
					@click="handleResponse('yes')" />
				<NcButton
					:class="{ active: userResponse === 'maybe' }"
					variant="warning"
					:text="t('attendance', 'Maybe')"
					:disabled="responseCooldown"
					data-test="response-maybe"
					@click="handleResponse('maybe')" />
				<NcButton
					:class="{ active: userResponse === 'no' }"
					variant="error"
					:text="t('attendance', 'No')"
					:disabled="responseCooldown"
					data-test="response-no"
					@click="handleResponse('no')" />
				<!-- Comment Toggle Button (only show when user has responded) -->
				<NcButton
					v-if="userResponse"
					class="comment-toggle"
					:class="{
						'comment-active': commentExpanded,
					}"
					variant="tertiary"
					data-test="button-toggle-comment"
					@click="toggleComment">
					<template #icon>
						<CommentIcon :size="20" />
					</template>
				</NcButton>
			</div>

			<div
				v-if="formattedDeadline"
				class="deadline-info"
				data-test="deadline-info">
				<ClockIcon :size="16" />
				<span>{{
					t("attendance", "Responses possible until {when}", {
						when: formattedDeadline,
					})
				}}</span>
			</div>

			<!-- Comment Section -->
			<div v-if="commentExpanded" class="comment-section">
				<div class="textarea-container">
					<NcInputField
						ref="commentInput"
						v-model="localComment"
						type="text"
						:label="t('attendance', 'Comment (optional)')"
						:placeholder="t('attendance', 'Add your comment\u00A0…')"
						data-test="response-comment"
						@update:modelValue="handleCommentInputEvent" />

					<div v-if="savingComment" class="saving-spinner">
						<div class="spinner" />
					</div>
					<div v-else-if="commentSaved" class="saved-indicator">
						<CheckIcon :size="16" class="check-icon" />
					</div>
					<div v-else-if="errorComment" class="error-indicator">
						<CloseCircle :size="16" class="error-icon" />
					</div>
				</div>
			</div>
		</div>

		<!-- Checkin Summary (only shown when checkins exist and user can see response overview) -->
		<div
			v-if="
				canSeeResponseOverview
					&& appointment.checkinSummary?.hasCheckins
			"
			class="checkin-summary"
			data-test="checkin-summary">
			<h4>{{ t("attendance", "Check-in summary") }}</h4>
			<div class="summary-stats">
				<!-- TRANSLATORS: Check-in status chip on the appointment card — the person has checked in. Sibling chips: "{count} absent", "{count} pending". All three describe the current check-in state of the same event, so translate as status labels rather than strict past/present tense. -->
				<NcChip
					:text="
						t('attendance', '{count} attended', {
							count: appointment.checkinSummary.attended,
						})
					"
					variant="success"
					noClose>
					<template #icon>
						<CheckIcon :size="16" />
					</template>
				</NcChip>
				<!-- TRANSLATORS: Check-in status chip — the person was explicitly marked absent. See "{count} attended". -->
				<NcChip
					:text="
						t('attendance', '{count} absent', {
							count: appointment.checkinSummary.absent,
						})
					"
					variant="error"
					noClose>
					<template #icon>
						<CloseIcon :size="16" />
					</template>
				</NcChip>
				<!-- TRANSLATORS: Check-in status chip — no check-in recorded for the person yet. See "{count} attended". -->
				<NcChip
					v-if="appointment.checkinSummary.notCheckedIn > 0"
					:text="
						t('attendance', '{count} pending', {
							count: appointment.checkinSummary.notCheckedIn,
						})
					"
					variant="tertiary"
					noClose>
					<template #icon>
						<HelpCircleOutlineIcon :size="16" />
					</template>
				</NcChip>
			</div>
		</div>

		<!-- Detailed Response Summary -->
		<ResponseSummary
			v-if="canSeeResponseOverview && appointment.responseSummary"
			:responseSummary="appointment.responseSummary"
			:canSeeComments="canSeeComments"
			:canManageAppointments="canManageAppointments"
			:appointmentId="appointment.id"
			:bookingEnabled="capabilities.bookingEnabled"
			:isClosed="isClosed" />

		<!-- Remind target dialog -->
		<NcDialog
			v-if="showRemindDialog"
			:name="t('attendance', 'Send reminders')"
			@closing="showRemindDialog = false">
			<div class="remind-target-choices">
				<NcButton
					variant="primary"
					wide
					:disabled="sendingReminders"
					data-test="remind-non-responders"
					@click="handleRemindAll('non_responders')">
					<!-- TRANSLATORS: Button in the "Send reminders" dialog — reminds everyone who has not responded yet. People who answered "no" are deliberately not reminded. -->
					{{ t('attendance', 'Non-responders') }}
				</NcButton>
				<NcButton
					variant="secondary"
					wide
					:disabled="sendingReminders"
					data-test="remind-maybe"
					@click="handleRemindAll('maybe')">
					<!-- TRANSLATORS: Button in the "Send reminders" dialog — reminds everyone who answered "maybe". -->
					{{ t('attendance', 'Maybe responders') }}
				</NcButton>
				<NcButton
					variant="secondary"
					wide
					:disabled="sendingReminders"
					data-test="remind-both"
					@click="handleRemindAll('both')">
					<!-- TRANSLATORS: Button in the "Send reminders" dialog — reminds both groups: non-responders and maybe responders. -->
					{{ t('attendance', 'Both') }}
				</NcButton>
			</div>
		</NcDialog>

		<!-- Close confirmation with planned-in / not-planned-in groups -->
		<NcDialog
			v-if="showCloseBookingDialog"
			:name="t('attendance', 'Close and notify?')"
			data-test="close-booking-dialog"
			@closing="showCloseBookingDialog = false">
			<div class="booking-confirm">
				<p class="booking-confirm__hint">
					<!-- TRANSLATORS: Hint in the close-inquiry dialog — people get notified whether they got a place in the appointment or not (German: "Planungsstatus"). -->
					{{ t('attendance', 'Closing notifies these people about their scheduling status.') }}
				</p>
				<div
					v-for="group in bookingDialogGroups"
					:key="group.key"
					class="booking-confirm__group">
					<h4>{{ group.label }}</h4>
					<p v-if="group.names.length === 0" class="booking-confirm__empty">
						{{ t('attendance', 'Nobody') }}
					</p>
					<p v-else class="booking-confirm__names">
						{{ visibleBookingNames(group).join(', ') }}
						<NcButton
							v-if="group.names.length > BOOKING_PREVIEW_LIMIT"
							variant="tertiary"
							@click="toggleBookingGroup(group.key)">
							{{ expandedBookingGroups[group.key]
								? t('attendance', 'Show less')
								: t('attendance', '+{count} more', { count: group.names.length - BOOKING_PREVIEW_LIMIT }) }}
						</NcButton>
					</p>
				</div>
			</div>
			<template #actions>
				<NcButton
					variant="tertiary"
					data-test="close-booking-cancel"
					@click="showCloseBookingDialog = false">
					{{ t('attendance', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="togglingClosed"
					data-test="close-booking-confirm"
					@click="confirmCloseWithBookings">
					{{ t('attendance', 'Close inquiry') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcActionButton,
	NcActions,
	NcButton,
	NcChip,
	NcDialog,
	NcInputField,
} from '@nextcloud/vue'
import { computed, nextTick, ref, watch } from 'vue'
import BellRingIcon from 'vue-material-design-icons/BellRing.vue'
import CalendarRefreshIcon from 'vue-material-design-icons/CalendarRefresh.vue'
import CalendarRemoveIcon from 'vue-material-design-icons/CalendarRemove.vue'
import CalendarSyncIcon from 'vue-material-design-icons/CalendarSync.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import ClockIcon from 'vue-material-design-icons/Clock.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import CommentIcon from 'vue-material-design-icons/Comment.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import HelpCircleOutlineIcon from 'vue-material-design-icons/HelpCircleOutline.vue'
import HistoryIcon from 'vue-material-design-icons/History.vue'
import ListStatusIcon from 'vue-material-design-icons/ListStatus.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import LockOpenIcon from 'vue-material-design-icons/LockOpen.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import RepeatIcon from 'vue-material-design-icons/Repeat.vue'
import ShareVariantIcon from 'vue-material-design-icons/ShareVariant.vue'
import ResponseSummary from './ResponseSummary.vue'
import { useAppointmentResponse, useResponseCooldown } from '../../composables/useAppointmentResponse.js'
import { usePermissions } from '../../composables/usePermissions.js'
import { formatClosedLabel } from '../../utils/appointment.js'
import { copyToClipboard } from '../../utils/clipboard.js'
import { formatDateRange, formatDateTime } from '../../utils/datetime.js'
import { renderMarkdown, sanitizeHtml } from '../../utils/markdown.js'
import { getResponseText, getResponseVariant } from '../../utils/response.js'

const props = defineProps({
	appointment: {
		type: Object,
		required: true,
	},
	canManageAppointments: {
		type: Boolean,
		default: false,
	},
	canCheckin: {
		type: Boolean,
		default: false,
	},
	canSeeResponseOverview: {
		type: Boolean,
		default: true,
	},
	canSeeComments: {
		type: Boolean,
		default: true,
	},
	displayOrder: {
		type: String,
		default: 'name_first',
	},
	canSeeAuditLog: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits([
	'startCheckin',
	'edit',
	'copy',
	'delete',
	'export',
	'submitResponse',
	'updateComment',
	'closedToggled',
	'showAuditLog',
])

const { capabilities } = usePermissions()

const currentUserUid = window.OC?.getCurrentUser?.()?.uid || window.OC?.currentUser || null

const localComment = ref(props.appointment.userResponse?.comment || '')
const commentExpanded = ref(false)
const commentInput = ref(null)
let commentTimeout = null

// Use the shared response composable for comment auto-save
const { savingComment, commentSaved, errorComment, autoSaveComment }
	= useAppointmentResponse()

async function toggleComment() {
	commentExpanded.value = !commentExpanded.value
	if (commentExpanded.value) {
		await nextTick()
		commentInput.value?.$el?.querySelector('input')?.focus()
	}
}

const userResponse = computed(() => {
	return props.appointment.userResponse?.response || null
})

const isClosed = computed(() => Boolean(props.appointment.closedAt))

const canToggleClosed = computed(() => {
	if (props.canManageAppointments) return true
	return Boolean(currentUserUid) && props.appointment.createdBy === currentUserUid
})

const isCancelled = computed(() => Boolean(props.appointment.cancelledAt))

// Cancelling is a manager/creator action gated behind the server capability, so
// instances (and older servers) that don't offer it never show the UI.
const canCancel = computed(() => capabilities.cancelling && canToggleClosed.value)

const formattedClosedAt = computed(() => props.appointment.closedAt ? formatDateTime(props.appointment.closedAt) : '')

const formattedDeadline = computed(() => props.appointment.responseDeadline
	? formatDateTime(props.appointment.responseDeadline)
	: '')

const closedLabel = computed(() => formatClosedLabel(props.appointment.closedAt, props.appointment.responseDeadline))

const renderedDescription = computed(() => {
	if (!props.appointment.description) return ''
	const html = renderMarkdown(props.appointment.description, false)
	return sanitizeHtml(html)
})

const calendarLink = computed(() => {
	if (
		!props.appointment.calendarUri
		|| !props.appointment.calendarEventUid
		|| !props.appointment.startDatetime
	) {
		return null
	}

	// Generate deeplink to open the event popup directly in Calendar app
	// URL format: /apps/calendar/{view}/{date}/edit/popover/{base64_dav_path}/{recurrenceId}
	const dateObj = new Date(props.appointment.startDatetime)
	const year = dateObj.getFullYear()
	const month = String(dateObj.getMonth() + 1).padStart(2, '0')
	const day = String(dateObj.getDate()).padStart(2, '0')
	const dateStr = `${year}-${month}-${day}`

	// Get current user from Nextcloud
	const currentUser = window.OC?.currentUser || 'admin'

	// calendarEventUid contains the filename (e.g., "70EB1F77-0025-44EB-88B3-B64F65CC3F84.ics")
	const eventUri = props.appointment.calendarEventUid

	// Build the DAV path: /remote.php/dav/calendars/{user}/{calendar}/{filename}
	const davPath = `/remote.php/dav/calendars/${currentUser}/${props.appointment.calendarUri}/${eventUri}`

	// Base64 encode the path
	const base64Path = btoa(davPath)

	// For non-recurring events, use "next" as recurrenceId
	const recurrenceId = 'next'

	return generateUrl(`/apps/calendar/dayGridMonth/${dateStr}/edit/popover/${base64Path}/${recurrenceId}`)
})

watch(
	() => props.appointment.userResponse,
	(newResponse) => {
		if (!commentTimeout) {
			localComment.value = newResponse?.comment || ''
		}
	},
	{ immediate: true, deep: true },
)

function copyShareLink() {
	const appointmentUrl
		= window.location.origin
			+ generateUrl(`/apps/attendance/appointment/${props.appointment.id}`)
	return copyToClipboard(appointmentUrl, {
		successMessage: t('attendance', 'Link copied to clipboard'),
	})
}

function handleStartCheckin() {
	emit('startCheckin', props.appointment.id)
}

function handleEdit() {
	emit('edit', props.appointment)
}

function handleCopy() {
	emit('copy', props.appointment)
}

function handleDelete() {
	emit('delete', props.appointment.id)
}

function handleExport() {
	emit('export', props.appointment.id)
}

const sendingReminders = ref(false)
const showRemindDialog = ref(false)

async function handleRemindAll(target = 'non_responders') {
	showRemindDialog.value = false
	sendingReminders.value = true
	try {
		const response = await axios.post(
			generateUrl(`/apps/attendance/api/appointments/${props.appointment.id}/remind`),
			{ target },
		)
		const count = response.data.sent || 0
		showSuccess(t('attendance', '{count} reminders sent', { count }))
	} catch (error) {
		console.error('Failed to send reminders:', error)
		showError(t('attendance', 'Failed to send reminders'))
	} finally {
		sendingReminders.value = false
	}
}

const { responseCooldown, resolveNext, startCooldown } = useResponseCooldown(userResponse)

function handleResponse(response) {
	if (responseCooldown.value) return
	startCooldown()
	emit('submitResponse', props.appointment.id, resolveNext(response))
}

const togglingClosed = ref(false)
const showCloseBookingDialog = ref(false)

// Yes-responders split into planned-in / not-planned-in, deduped by user. Drives
// the close-confirmation dialog and mirrors the server's close-time wave.
const bookingGroups = computed(() => {
	const summary = props.appointment.responseSummary
	const booked = new Map()
	const declined = new Map()
	if (!summary) return { booked: [], declined: [] }
	const sections = []
	if (summary.by_group) sections.push(...Object.values(summary.by_group))
	if (summary.by_team) sections.push(...Object.values(summary.by_team))
	if (summary.others) sections.push(summary.others)
	for (const section of sections) {
		for (const r of section.responses || []) {
			if (r.response !== 'yes') continue
			const target = r.bookingStatus === 'booked' ? booked : declined
			target.set(r.userId, r.userName || r.userId)
		}
	}
	for (const uid of booked.keys()) declined.delete(uid)
	return { booked: [...booked.values()], declined: [...declined.values()] }
})

async function handleToggleClosed() {
	if (togglingClosed.value) return
	const wantsClose = !isClosed.value
	// Closing with planned-in people triggers a notification wave — confirm the
	// two named groups first. Without booking / without anyone booked, close is
	// a direct click as before.
	if (wantsClose && capabilities.bookingEnabled && bookingGroups.value.booked.length >= 1) {
		showCloseBookingDialog.value = true
		return
	}
	await performToggleClosed(wantsClose)
}

async function confirmCloseWithBookings() {
	showCloseBookingDialog.value = false
	await performToggleClosed(true)
}

const BOOKING_PREVIEW_LIMIT = 10
const expandedBookingGroups = ref({})
const bookingDialogGroups = computed(() => [
	{
		key: 'booked',
		// TRANSLATORS: Group heading in the close-inquiry dialog — the {count} people who got a place in the appointment (German "Eingeplant", not "Geplant").
		label: t('attendance', 'Scheduled ({count})', { count: bookingGroups.value.booked.length }),
		names: bookingGroups.value.booked,
	},
	{
		key: 'declined',
		// TRANSLATORS: Group heading in the close-inquiry dialog — the {count} people who did not get a place in the appointment (German "Nicht eingeplant").
		label: t('attendance', 'Not scheduled ({count})', { count: bookingGroups.value.declined.length }),
		names: bookingGroups.value.declined,
	},
])
function toggleBookingGroup(key) {
	expandedBookingGroups.value[key] = !expandedBookingGroups.value[key]
}
function visibleBookingNames(group) {
	return expandedBookingGroups.value[group.key]
		? group.names
		: group.names.slice(0, BOOKING_PREVIEW_LIMIT)
}

async function performToggleClosed(wantsClose) {
	if (togglingClosed.value) return
	togglingClosed.value = true
	const url = generateUrl(`/apps/attendance/api/appointments/${props.appointment.id}/${wantsClose ? 'close' : 'reopen'}`)
	try {
		const response = await axios.post(url)
		showSuccess(wantsClose
			? t('attendance', 'Inquiry closed')
			: t('attendance', 'Inquiry re-opened'))
		emit('closedToggled', response.data)
	} catch (error) {
		console.error('Failed to toggle closed state:', error)
		showError(wantsClose
			? t('attendance', 'Failed to close inquiry')
			: t('attendance', 'Failed to re-open inquiry'))
	} finally {
		togglingClosed.value = false
	}
}

const togglingCancelled = ref(false)

async function handleToggleCancelled() {
	if (togglingCancelled.value) return
	togglingCancelled.value = true
	const wantsCancel = !isCancelled.value
	const url = generateUrl(`/apps/attendance/api/appointments/${props.appointment.id}/${wantsCancel ? 'cancel' : 'uncancel'}`)
	try {
		const response = await axios.post(url)
		showSuccess(wantsCancel
			? t('attendance', 'Appointment cancelled')
			: t('attendance', 'Appointment reactivated'))
		// Reuse the closedToggled channel: the parent merges the full updated
		// appointment (incl. cancelledAt) reactively, so no extra wiring needed.
		emit('closedToggled', response.data)
	} catch (error) {
		console.error('Failed to toggle cancelled state:', error)
		showError(wantsCancel
			? t('attendance', 'Failed to cancel appointment')
			: t('attendance', 'Failed to reactivate appointment'))
	} finally {
		togglingCancelled.value = false
	}
}

function getAttachmentUrl(attachment) {
	return attachment.downloadUrl || generateUrl(`/f/${attachment.fileId}`)
}

function handleCommentInputEvent() {
	if (commentTimeout) {
		clearTimeout(commentTimeout)
	}

	commentTimeout = setTimeout(async () => {
		// Wait for Vue to update the DOM and reactive values
		await nextTick()
		autoSaveComment(
			props.appointment.id,
			userResponse.value,
			localComment.value,
		)
	}, 500)
}
</script>

<style scoped lang="scss">
@use "../../styles/shared.scss";

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

.remind-target-choices {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 8px 0;
}

.appointment-card {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    padding: 20px;
    margin-bottom: 20px;
}

.appointment-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    background: var(--color-background-hover);
    margin: -20px -20px 20px -20px;
    padding: 20px;
    border-radius: var(--border-radius-large) var(--border-radius-large) 0 0;

    .appointment-title-block {
        flex: 1;
        min-width: 0;

        h3 {
            margin: 0;
            font-size: 1.5em;
            font-weight: 700;
            color: var(--color-main-text);
        }

        .appointment-date-subtitle {
            display: block;
            font-size: 15px;
            font-weight: 500;
            color: var(--color-text-maxcontrast);
            margin-top: 4px;
        }

        .calendar-link {
            display: inline-flex;
            align-items: center;
            vertical-align: middle;
            margin-left: 4px;
            color: var(--color-primary-element);
        }

        .series-indicator {
            display: inline-flex;
            align-items: center;
            vertical-align: middle;
            margin-left: 4px;
            color: var(--color-text-maxcontrast);
        }
    }

    .appointment-actions {
        margin-left: 10px;
    }
}

.appointment-description {
    color: var(--color-text-lighter);
    margin-bottom: 15px;

    // Markdown formatting
    :deep(strong) {
        font-weight: bold;
        color: var(--color-main-text);
    }

    :deep(em) {
        font-style: italic;
    }

    :deep(del) {
        text-decoration: line-through;
    }

    :deep(a) {
        color: var(--color-primary-element);
        text-decoration: none;

        &:hover {
            text-decoration: underline;
        }
    }

    :deep(code) {
        background-color: var(--color-background-dark);
        padding: 2px 6px;
        border-radius: var(--border-radius-small);
        font-family: monospace;
        font-size: 0.9em;
    }

    :deep(pre) {
        background-color: var(--color-background-dark);
        padding: 12px;
        border-radius: var(--border-radius);
        overflow-x: auto;
        margin: 10px 0;

        code {
            background: none;
            padding: 0;
        }
    }

    :deep(blockquote) {
        border-left: 3px solid var(--color-primary-element);
        margin: 10px 0;
        padding-left: 15px;
        color: var(--color-text-maxcontrast);
    }

    :deep(ul) {
        margin: 10px 0;
        padding-left: 25px;
        list-style-type: disc;
    }

    :deep(ol) {
        margin: 10px 0;
        padding-left: 25px;
        list-style-type: decimal;
    }

    :deep(li) {
        margin: 5px 0;
        display: list-item;
    }

    :deep(h1),
    :deep(h2),
    :deep(h3),
    :deep(h4),
    :deep(h5),
    :deep(h6) {
        color: var(--color-main-text);
        margin: 15px 0 10px 0;
        font-weight: 600;
    }

    :deep(h1) {
        font-size: 1.5em;
    }
    :deep(h2) {
        font-size: 1.3em;
    }
    :deep(h3) {
        font-size: 1.15em;
    }
    :deep(h4) {
        font-size: 1.05em;
    }

    :deep(hr) {
        border: none;
        border-top: 1px solid var(--color-border);
        margin: 15px 0;
    }

    :deep(table) {
        border-collapse: collapse;
        width: 100%;
        margin: 10px 0;
    }

    :deep(th),
    :deep(td) {
        border: 1px solid var(--color-border);
        padding: 8px 12px;
        text-align: left;
    }

    :deep(th) {
        background-color: var(--color-background-dark);
        font-weight: 600;
    }

    :deep(p) {
        margin: 10px 0;

        &:first-child {
            margin-top: 0;
        }

        &:last-child {
            margin-bottom: 0;
        }
    }

    :deep(img) {
        max-width: 100%;
        height: auto;
        border-radius: var(--border-radius);
    }
}

.attachment-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-bottom: 15px;

    .attachment-link {
        text-decoration: none;
        color: inherit;

        &:hover :deep(.nc-chip) {
            background-color: var(--color-background-hover);
        }
    }
}

.checkin-summary {
    border-top: 1px solid var(--color-border);
    padding-top: 15px;
    margin-top: 15px;

    h4 {
        font-size: 1.2em;
        margin: 0 0 10px 0;
    }

    .summary-stats {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }
}

.appointment-description + .response-section,
.attachment-chips + .response-section {
    border-top: 1px solid var(--color-border);
    padding-top: 15px;
}

.response-section {
    margin-top: 15px;

    h4 {
        font-size: 1.2em;
        margin: 0 0 10px 0;
    }

    .response-buttons {
        display: flex;
        gap: 10px;
        margin-bottom: 15px;

        // When a response exists, gray out non-active buttons (except comment toggle)
        &.has-response {
            :deep(.button-vue:not(.active):not(.comment-toggle)) {
                background-color: var(--color-background-dark) !important;
                color: var(--color-text-lighter) !important;
                border-color: var(--color-border-dark) !important;

                &:hover {
                    background-color: var(--color-background-hover) !important;
                    color: var(--color-text) !important;
                }
            }
        }

        // Active button styles - keep bold
        :deep(.button-vue.active) {
            font-weight: bold;
        }

        // Comment toggle active state
        :deep(.button-vue.comment-active) {
            background-color: var(--color-primary-element) !important;
            color: white !important;
        }
    }

    .comment-section {
        margin-top: 10px;

        .textarea-container {
            position: relative;
        }
    }
}

.closed-banner {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    margin-bottom: 16px;
    border-radius: var(--border-radius-large);
    background: var(--color-background-dark);
    border: 1px solid var(--color-border);

    .closed-banner-text {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 2px;

        strong {
            font-weight: 600;
        }

        span {
            font-size: 0.85em;
            color: var(--color-text-maxcontrast);
        }
    }
}

.deadline-info,
.closed-info {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 12px;
    color: var(--color-text-maxcontrast);
    font-size: 0.9em;
}

.response-section--readonly {
    display: flex;
    flex-direction: column;
    gap: 12px;

    .response-row {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    h4 {
        margin: 0;
    }

    .closed-banner {
        margin-bottom: 0;
    }

    .closed-info {
        margin-bottom: 0;
    }
}
</style>
