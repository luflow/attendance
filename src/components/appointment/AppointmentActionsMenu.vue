<template>
	<div class="appointment-actions">
		<NcActions :forceMenu="true" data-test="appointment-actions-menu">
			<NcActionButton
				:closeAfterClick="true"
				data-test="action-share-link"
				@click="copyShareLink">
				<template #icon>
					<ShareVariantIcon :size="20" />
				</template>
				<!-- TRANSLATORS: Menu action that copies the appointment link so it can be passed on — a verb ("share the link"), not a noun for the link itself. -->
				{{ t("attendance", "Share link") }}
			</NcActionButton>

			<!-- TRANSLATORS: Heading over the menu actions that deal with the answers: check-in, reminders, closing the inquiry. -->
			<NcActionCaption v-if="showCaptions && hasResponseActions" :name="t('attendance', 'Responses')" />
			<NcActionButton
				v-if="permissions.canCheckin"
				:closeAfterClick="true"
				data-test="action-start-checkin"
				@click="emit('startCheckin', appointment.id)">
				<template #icon>
					<ListStatusIcon :size="20" />
				</template>
				{{ t("attendance", "Start check-in") }}
			</NcActionButton>
			<NcActionButton
				v-if="canManage && !isClosed"
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
				v-if="canManage"
				:closeAfterClick="true"
				:disabled="togglingClosed"
				:data-test="isClosed ? 'action-reopen-inquiry' : 'action-close-inquiry'"
				@click="toggleClosed">
				<template #icon>
					<LockOpenIcon v-if="isClosed" :size="20" />
					<LockIcon v-else :size="20" />
				</template>
				{{ closeToggleLabel }}
			</NcActionButton>

			<NcActionCaption v-if="showCaptions && hasTalkActions" :name="t('attendance', 'Talk room')" />
			<NcActionLink
				v-if="talkLink"
				:href="talkLink"
				:closeAfterClick="true"
				data-test="action-open-conversation-link">
				<template #icon>
					<MessageTextIcon :size="20" />
				</template>
				<!-- TRANSLATORS: Menu action that jumps to the Talk conversation belonging to this appointment. -->
				{{ t("attendance", "Open Talk room") }}
			</NcActionLink>
			<NcActionButton
				v-if="canOpenTalkRoom"
				:closeAfterClick="true"
				:disabled="openingTalkRoom"
				data-test="action-open-conversation"
				@click="openTalkRoom">
				<template #icon>
					<MessageTextIcon :size="20" />
				</template>
				<!-- TRANSLATORS: Menu action that opens a Talk conversation holding the organizers and everyone who got a place, so the remaining details can be sorted out there. -->
				{{ t("attendance", "Open a Talk room") }}
			</NcActionButton>
			<NcActionButton
				v-if="canDeleteTalkRoom"
				:closeAfterClick="true"
				:disabled="deletingTalkRoom"
				data-test="action-delete-conversation"
				@click="showDeleteTalkRoomDialog = true">
				<template #icon>
					<MessageMinusIcon :size="20" />
				</template>
				<!-- TRANSLATORS: Menu action that deletes the Talk conversation belonging to this appointment, messages and all. -->
				{{ t("attendance", "Delete Talk room") }}
			</NcActionButton>

			<!-- TRANSLATORS: Heading over the menu actions on the appointment itself: editing, copying, calling it off, deleting. -->
			<NcActionCaption v-if="showCaptions && hasAppointmentActions" :name="t('attendance', 'Appointment')" />
			<NcActionButton
				v-if="canManage"
				:closeAfterClick="true"
				data-test="action-edit"
				@click="emit('edit', appointment)">
				<template #icon>
					<Pencil :size="20" />
				</template>
				{{ t("attendance", "Edit") }}
			</NcActionButton>
			<NcActionButton
				v-if="permissions.canCreateAppointments"
				:closeAfterClick="true"
				data-test="action-copy"
				@click="emit('copy', appointment)">
				<template #icon>
					<ContentCopy :size="20" />
				</template>
				{{ t("attendance", "Copy") }}
			</NcActionButton>
			<NcActionButton
				v-if="permissions.canManageAppointments"
				:closeAfterClick="true"
				data-test="action-export"
				@click="emit('export', appointment.id)">
				<template #icon>
					<DownloadIcon :size="20" />
				</template>
				{{ t("attendance", "Export") }}
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
				v-if="canCancel"
				:closeAfterClick="true"
				:disabled="togglingCancelled"
				:data-test="isCancelled ? 'action-reactivate-appointment' : 'action-cancel-appointment'"
				@click="toggleCancelled">
				<template #icon>
					<CalendarRefreshIcon v-if="isCancelled" :size="20" />
					<CalendarRemoveIcon v-else :size="20" />
				</template>
				{{ cancelToggleLabel }}
			</NcActionButton>
			<NcActionButton
				v-if="canManage"
				:closeAfterClick="true"
				data-test="action-delete"
				@click="emit('delete', appointment.id)">
				<template #icon>
					<Delete :size="20" />
				</template>
				{{ t("attendance", "Delete") }}
			</NcActionButton>
		</NcActions>

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
					@click="remindAll('non_responders')">
					<!-- TRANSLATORS: Button in the "Send reminders" dialog — reminds everyone who has not responded yet. People who answered "no" are deliberately not reminded. -->
					{{ t('attendance', 'Non-responders') }}
				</NcButton>
				<NcButton
					variant="secondary"
					wide
					:disabled="sendingReminders"
					data-test="remind-maybe"
					@click="remindAll('maybe')">
					<!-- TRANSLATORS: Button in the "Send reminders" dialog — reminds everyone who answered "maybe". -->
					{{ t('attendance', 'Maybe responders') }}
				</NcButton>
				<NcButton
					variant="secondary"
					wide
					:disabled="sendingReminders"
					data-test="remind-both"
					@click="remindAll('both')">
					<!-- TRANSLATORS: Button in the "Send reminders" dialog — reminds both groups: non-responders and maybe responders. -->
					{{ t('attendance', 'Both') }}
				</NcButton>
			</div>
		</NcDialog>

		<NcDialog
			v-if="showDeleteTalkRoomDialog"
			:name="t('attendance', 'Delete Talk room')"
			@closing="showDeleteTalkRoomDialog = false">
			<p>{{ t('attendance', 'Do you want to delete the Talk room?') }}</p>
			<!-- TRANSLATORS: Warning in the delete-Talk-room dialog — deleting takes the conversation away from every participant, not just the organizer. -->
			<p>{{ t('attendance', 'The conversation and its messages are gone for everyone.') }}</p>
			<template #actions>
				<NcButton variant="tertiary" @click="showDeleteTalkRoomDialog = false">
					{{ t('attendance', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="error"
					data-test="confirm-delete-conversation"
					@click="confirmDeleteTalkRoom">
					{{ t('attendance', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>

		<CloseInquiryDialog
			v-if="showCloseDialog"
			:groups="dialogGroups"
			:disabled="togglingClosed"
			@confirm="confirmClose"
			@cancel="showCloseDialog = false" />
	</div>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcActionButton, NcActionCaption, NcActionLink, NcActions, NcButton, NcDialog } from '@nextcloud/vue'
import { computed, ref } from 'vue'
import BellRingIcon from 'vue-material-design-icons/BellRing.vue'
import CalendarRefreshIcon from 'vue-material-design-icons/CalendarRefresh.vue'
import CalendarRemoveIcon from 'vue-material-design-icons/CalendarRemove.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import HistoryIcon from 'vue-material-design-icons/History.vue'
import ListStatusIcon from 'vue-material-design-icons/ListStatus.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import LockOpenIcon from 'vue-material-design-icons/LockOpen.vue'
import MessageMinusIcon from 'vue-material-design-icons/MessageMinus.vue'
import MessageTextIcon from 'vue-material-design-icons/MessageText.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ShareVariantIcon from 'vue-material-design-icons/ShareVariant.vue'
import CloseInquiryDialog from './CloseInquiryDialog.vue'
import { useAppointmentLifecycle } from '../../composables/useAppointmentLifecycle.js'
import { usePermissions } from '../../composables/usePermissions.js'
import { appointmentDetailUrl, talkRoomLink } from '../../utils/appointment.js'
import { copyToClipboard } from '../../utils/clipboard.js'

const props = defineProps({
	appointment: {
		type: Object,
		required: true,
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
	'showAuditLog',
	'closedToggled',
])

const { permissions } = usePermissions()

const {
	isClosed,
	isCancelled,
	canManage,
	canCancel,
	togglingClosed,
	togglingCancelled,
	showCloseDialog,
	dialogGroups,
	toggleClosed,
	confirmClose,
	toggleCancelled,
	canOpenTalkRoom,
	openingTalkRoom,
	openTalkRoom,
	canDeleteTalkRoom,
	deletingTalkRoom,
	deleteTalkRoom,
} = useAppointmentLifecycle(() => props.appointment, {
	onUpdated: (updated) => emit('closedToggled', updated),
})

// Lives here instead of inline in the template: the string extractor only
// associates a TRANSLATORS comment with the first t() call on the very
// next line, which a multi-line ternary cannot provide.
// One computed per label, never an inline ternary: the extractor's regex for the
// Vue template merges two t() calls on one line into a single broken string.
const closeToggleLabel = computed(() => {
	if (isClosed.value) {
		// TRANSLATORS: Menu action that lets people answer again after the inquiry was closed — the counterpart of "Close inquiry".
		return t('attendance', 'Reopen inquiry')
	}
	// TRANSLATORS: Menu action that stops the appointment from accepting further responses; the answers stay visible.
	return t('attendance', 'Close inquiry')
})

const cancelToggleLabel = computed(() => {
	if (isCancelled.value) {
		// TRANSLATORS: Menu action that takes a cancellation back — the appointment will take place again.
		return t('attendance', 'Reactivate appointment')
	}
	// TRANSLATORS: Menu action that calls off the appointment — it will not take place (German "Termin absagen", not "abbrechen").
	return t('attendance', 'Cancel appointment')
})

// Only members are sent the token, so a link at all means the viewer is in.
const talkLink = computed(() => talkRoomLink(props.appointment))

const hasResponseActions = computed(() => permissions.canCheckin || canManage.value)
const hasTalkActions = computed(() => Boolean(talkLink.value)
	|| canOpenTalkRoom.value
	|| canDeleteTalkRoom.value)
const hasAppointmentActions = computed(() => canManage.value
	|| canCancel.value
	|| permissions.canManageAppointments
	|| permissions.canCreateAppointments
	|| props.canSeeAuditLog)

// Headings earn their space once there is more than one group to tell apart —
// somebody who only gets "Share link" should not read a table of contents.
const showCaptions = computed(() => [
	hasResponseActions.value,
	hasTalkActions.value,
	hasAppointmentActions.value,
].filter(Boolean).length > 1)

function copyShareLink() {
	return copyToClipboard(window.location.origin + appointmentDetailUrl(props.appointment.id), {
		successMessage: t('attendance', 'Link copied to clipboard'),
	})
}

const sendingReminders = ref(false)
const showRemindDialog = ref(false)
const showDeleteTalkRoomDialog = ref(false)

async function confirmDeleteTalkRoom() {
	showDeleteTalkRoomDialog.value = false
	await deleteTalkRoom()
}

async function remindAll(target) {
	showRemindDialog.value = false
	sendingReminders.value = true
	try {
		const response = await axios.post(
			generateUrl(`/apps/attendance/api/appointments/${props.appointment.id}/remind`),
			{ target },
		)
		const sent = response.data.sent || 0
		showSuccess(n('attendance', '{count} reminder sent', '{count} reminders sent', sent, { count: sent }))
	} catch (error) {
		console.error('Failed to send reminders:', error)
		showError(t('attendance', 'Failed to send reminders'))
	} finally {
		sendingReminders.value = false
	}
}
</script>

<style scoped lang="scss">
.remind-target-choices {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 8px 0;
}
</style>
