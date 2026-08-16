import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { formatDateTime } from './datetime.js'

/**
 * Relative URL of an appointment's detail page.
 *
 * @param {number} appointmentId The appointment id.
 * @return {string} Relative URL, e.g. "/apps/attendance/appointment/42".
 */
export function appointmentDetailUrl(appointmentId) {
	return generateUrl('/apps/attendance/appointment/{id}', { id: appointmentId })
}

/**
 * OpenStreetMap search URL for a free-text location.
 *
 * @param {string} location The location text.
 * @return {string} Absolute URL.
 */
export function openStreetMapUrl(location) {
	return `https://www.openstreetmap.org/search?query=${encodeURIComponent(location)}`
}

/**
 * Link to the Talk conversation opened for an appointment's scheduled people.
 *
 * @param {object} appointment The appointment payload.
 * @return {string|null} Relative URL, or null when no conversation is linked.
 */
export function talkRoomLink(appointment) {
	const token = appointment?.talkRoomToken
	return token ? generateUrl('/call/{token}', { token }) : null
}

/**
 * Deeplink that opens the appointment's source event in the Calendar app.
 *
 * @param {object} appointment The appointment payload.
 * @return {string|null} Relative URL, or null when the appointment is not
 *         backed by a calendar event.
 */
export function calendarDeepLink(appointment) {
	const { calendarUri, calendarEventUid, startDatetime, createdBy } = appointment ?? {}
	if (!calendarUri || !calendarEventUid || !startDatetime) {
		return null
	}

	const date = new Date(startDatetime)
	const dateStr = [
		date.getFullYear(),
		String(date.getMonth() + 1).padStart(2, '0'),
		String(date.getDate()).padStart(2, '0'),
	].join('-')

	// The calendar lives under the principal of whoever synced it in, not of
	// whoever is looking — addressing it as the viewer yields "Event does not
	// exist" for everyone else.
	const owner = createdBy || window.OC?.currentUser
	if (!owner) {
		return null
	}

	// We store the event UID; the DAV collection addresses it by filename.
	// Tolerate rows that already carry the extension.
	const fileName = calendarEventUid.endsWith('.ics') ? calendarEventUid : `${calendarEventUid}.ics`
	const davPath = `/remote.php/dav/calendars/${owner}/${calendarUri}/${fileName}`

	// The object id goes into a path segment, and base64's alphabet includes
	// "/" — unescaped it would split into extra segments and miss the route.
	// "next" as recurrence id opens the upcoming occurrence of a series and is
	// the correct value for one-off events too.
	const objectId = encodeURIComponent(btoa(davPath))
	return generateUrl(`/apps/calendar/dayGridMonth/${dateStr}/edit/popover/${objectId}/next`)
}

/**
 * The logged-in user's *final* scheduling verdict on an appointment.
 *
 * The server stores the raw state per response as `bookingStatus`: 'booked'
 * (planned in), 'declined' (not planned in) or null (undecided). It only
 * becomes a verdict worth showing once the inquiry is closed — while it is
 * open the manager is still moving people in and out — and only when the
 * instance runs the scheduling feature at all.
 *
 * @param {object} appointment The appointment payload.
 * @param {boolean} bookingEnabled Instance-wide scheduling capability.
 * @return {'booked'|'declined'|null} The verdict, or null when there is none.
 */
export function finalScheduleStatus(appointment, bookingEnabled) {
	if (!bookingEnabled || !appointment?.closedAt) {
		return null
	}
	const status = appointment.userResponse?.bookingStatus
	return status === 'booked' || status === 'declined' ? status : null
}

/**
 * Human label for a called-off appointment.
 *
 * @param {string|null|undefined} cancelledAt UTC timestamp of the cancellation.
 * @return {string} Translated label, e.g. "Cancelled on Thu, Aug 6 09:12".
 */
export function formatCancelledLabel(cancelledAt) {
	// TRANSLATORS: States when the appointment was called off (German "abgesagt", not "abgebrochen").
	return t('attendance', 'Cancelled on {when}', { when: formatDateTime(cancelledAt) })
}

/**
 * Human label for a closed inquiry. Distinguishes auto-close-by-deadline
 * from a manual close so the message matches across the appointment card
 * and the public quick-response page.
 *
 * @param {string|null|undefined} closedAt UTC timestamp the inquiry closed.
 * @param {string|null|undefined} responseDeadline The configured deadline,
 *        if any — presence implies the close was the auto-close cron firing.
 * @return {string} Translated label, e.g. "Closed on Tue, May 15 09:00".
 */
export function formatClosedLabel(closedAt, responseDeadline) {
	if (!closedAt) {
		return t('attendance', 'Inquiry closed')
	}
	const when = formatDateTime(closedAt)
	return responseDeadline
		? t('attendance', 'Closed automatically on {when}', { when })
		: t('attendance', 'Closed on {when}', { when })
}
