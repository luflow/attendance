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
