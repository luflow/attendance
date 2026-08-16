/**
 * Closing / reopening an inquiry and calling an appointment off.
 *
 * Two places offer these actions — the ⋮ menu and the status banners on the
 * detail card — so the state (in-flight flags, the close-confirmation dialog)
 * and the permission gates live here rather than in either component.
 */

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { computed, ref, unref } from 'vue'
import { usePermissions } from './usePermissions.js'

/**
 * @param {object|import('vue').Ref<object>} appointmentSource The appointment payload.
 * @param {object} options Callbacks.
 * @param {(updated: object) => void} options.onUpdated Called with the updated
 *        appointment after a successful close/reopen/cancel/reactivate.
 * @return {object} Reactive state and the action handlers.
 */
export function useAppointmentLifecycle(appointmentSource, { onUpdated } = {}) {
	const { capabilities, permissions } = usePermissions()

	const appointment = computed(() => (typeof appointmentSource === 'function'
		? appointmentSource()
		: unref(appointmentSource)))

	const isClosed = computed(() => Boolean(appointment.value.closedAt))
	const isCancelled = computed(() => Boolean(appointment.value.cancelledAt))
	const canManage = computed(() => permissions.canManageAppointments
		|| appointment.value.myPermissions?.canEdit === true)

	// Cancelling is gated behind the server capability, so instances (and older
	// servers) that don't offer it never show the UI.
	const canCancel = computed(() => capabilities.cancelling && canManage.value)

	const togglingClosed = ref(false)
	const togglingCancelled = ref(false)
	const showCloseDialog = ref(false)

	// Yes-responders split into scheduled-in / not-scheduled-in, deduped by
	// user. Drives the close-confirmation dialog and mirrors the server's
	// close-time notification wave.
	const bookingGroups = computed(() => {
		const summary = appointment.value.responseSummary
		const booked = new Map()
		const declined = new Map()
		if (!summary) return { booked: [], declined: [] }
		const sections = [
			...Object.values(summary.by_group ?? {}),
			...Object.values(summary.by_team ?? {}),
			...(summary.others ? [summary.others] : []),
		]
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

	async function post(action, messages) {
		const url = generateUrl(`/apps/attendance/api/appointments/${appointment.value.id}/${action}`)
		try {
			const response = await axios.post(url)
			showSuccess(messages.success)
			onUpdated?.(response.data)
		} catch (error) {
			console.error(`Failed to ${action} appointment:`, error)
			showError((messages.errorFromServer && error.response?.data?.error) || messages.error)
		}
	}

	async function performToggleClosed(wantsClose) {
		if (togglingClosed.value) return
		togglingClosed.value = true
		await post(wantsClose ? 'close' : 'reopen', wantsClose
			? { success: t('attendance', 'Inquiry closed'), error: t('attendance', 'Failed to close inquiry') }
			: { success: t('attendance', 'Inquiry re-opened'), error: t('attendance', 'Failed to re-open inquiry') })
		togglingClosed.value = false
	}

	async function toggleClosed() {
		if (togglingClosed.value) return
		const wantsClose = !isClosed.value
		// Closing with scheduled-in people triggers a notification wave — confirm
		// the two named groups first. Without scheduling / without anyone
		// scheduled in, close stays a direct click.
		if (wantsClose && capabilities.bookingEnabled && bookingGroups.value.booked.length >= 1) {
			showCloseDialog.value = true
			return
		}
		await performToggleClosed(wantsClose)
	}

	async function confirmClose() {
		showCloseDialog.value = false
		await performToggleClosed(true)
	}

	async function toggleCancelled() {
		if (togglingCancelled.value) return
		togglingCancelled.value = true
		const wantsCancel = !isCancelled.value
		// TRANSLATORS: Success toast — the appointment was called off (German "abgesagt", not "abgebrochen").
		const cancelled = t('attendance', 'Appointment cancelled')
		// TRANSLATORS: Success toast — the cancellation was taken back, the appointment takes place again.
		const reactivated = t('attendance', 'Appointment reactivated')
		await post(wantsCancel ? 'cancel' : 'uncancel', wantsCancel
			? { success: cancelled, error: t('attendance', 'Failed to cancel appointment') }
			: { success: reactivated, error: t('attendance', 'Failed to reactivate appointment') })
		togglingCancelled.value = false
	}

	// The counterpart to the create-time opt-in, and the way in for inquiries the
	// deadline closed while nobody was looking. Mirrors the server's own rule:
	// with planning on the room waits for the close, because only then is it
	// settled who holds a place.
	const canOpenTalkRoom = computed(() => capabilities.talkRoomsAvailable
		&& canManage.value
		&& (isClosed.value || !capabilities.bookingEnabled)
		&& !appointment.value.talkRoomToken)

	const openingTalkRoom = ref(false)

	async function openTalkRoom() {
		if (openingTalkRoom.value) return
		openingTalkRoom.value = true
		await post('talk-room', {
			success: t('attendance', 'Talk room opened'),
			error: t('attendance', 'The Talk room could not be opened'),
			errorFromServer: true,
		})
		openingTalkRoom.value = false
	}

	const dialogGroups = computed(() => [
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

	return {
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
	}
}
