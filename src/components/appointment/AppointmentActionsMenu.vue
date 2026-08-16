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
				v-if="canOpenConversation"
				:closeAfterClick="true"
				:disabled="openingConversation"
				data-test="action-open-conversation"
				@click="openConversation">
				<template #icon>
					<MessageTextIcon :size="20" />
				</template>
				<!-- TRANSLATORS: Menu action that opens a Talk conversation holding the organizers and everyone who got a place, so the remaining details can be sorted out there. -->
				{{ t("attendance", "Open a Talk room") }}
			</NcActionButton>
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
import { NcActionButton, NcActions, NcButton, NcDialog } from '@nextcloud/vue'
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
import MessageTextIcon from 'vue-material-design-icons/MessageText.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ShareVariantIcon from 'vue-material-design-icons/ShareVariant.vue'
import CloseInquiryDialog from './CloseInquiryDialog.vue'
import { useAppointmentLifecycle } from '../../composables/useAppointmentLifecycle.js'
import { usePermissions } from '../../composables/usePermissions.js'
import { appointmentDetailUrl } from '../../utils/appointment.js'
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

const { permissions, capabilities } = usePermissions()

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

// Offered once the inquiry is closed and no conversation exists yet — the
// counterpart to the create-time opt-in, and the way in for inquiries the
// deadline closed while nobody was looking.
const canOpenConversation = computed(() => capabilities.talkRoomsAvailable === true
	&& canManage.value
	&& isClosed.value
	&& !props.appointment.talkRoomToken)

const openingConversation = ref(false)

async function openConversation() {
	openingConversation.value = true
	try {
		const response = await axios.post(generateUrl(`/apps/attendance/api/appointments/${props.appointment.id}/talk-room`))
		emit('closedToggled', response.data)
		showSuccess(t('attendance', 'Talk room opened'))
	} catch (error) {
		showError(error.response?.data?.error || t('attendance', 'The Talk room could not be opened'))
	} finally {
		openingConversation.value = false
	}
}

function copyShareLink() {
	return copyToClipboard(window.location.origin + appointmentDetailUrl(props.appointment.id), {
		successMessage: t('attendance', 'Link copied to clipboard'),
	})
}

const sendingReminders = ref(false)
const showRemindDialog = ref(false)

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
