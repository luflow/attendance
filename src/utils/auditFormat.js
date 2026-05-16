import { translate as t } from '@nextcloud/l10n'
import { getResponseText } from './response.js'

function actorLabel(event) {
	return event.actor?.displayName ?? event.actorId ?? t('attendance', 'Someone')
}

function subjectLabel(event) {
	if (!event.subject || event.subject.userId === event.actor?.userId) {
		return null
	}
	return event.subject.displayName ?? event.subjectId
}

export const SOURCE_LABELS = {
	app: () => t('attendance', 'Web'),
	quick_link: () => t('attendance', 'Email link'),
	admin_checkin: () => t('attendance', 'Check-in'),
	legacy_backfill: () => t('attendance', 'Historic'),
}

export function formatSource(source) {
	if (!source) return ''
	const fn = SOURCE_LABELS[source]
	return fn ? fn() : source
}

/**
 * Map an audit-event verb to an icon name (from vue-material-design-icons)
 * and a localised one-line description. Unknown verbs fall through to a
 * generic rendering so the UI keeps working when the backend ships new
 * verbs the frontend doesn't recognise yet.
 *
 * @param {object} event the audit event payload from the API
 * @return {{ icon: string, message: string }}
 */
export function formatAuditEvent(event) {
	const actor = actorLabel(event)
	const subject = subjectLabel(event)
	const meta = event.meta ?? {}

	switch (event.verb) {
	case 'response.submitted':
		return {
			icon: 'CheckCircleOutline',
			message: t('attendance', '{actor} answered {response}', {
				actor,
				response: getResponseText(meta.response),
			}),
		}
	case 'response.changed':
		return {
			icon: 'SwapHorizontal',
			message: t('attendance', '{actor} changed response from {from} to {to}', {
				actor,
				from: getResponseText(meta.from),
				to: getResponseText(meta.to),
			}),
		}
	case 'response.rescinded':
		return {
			icon: 'UndoVariant',
			message: t('attendance', '{actor} took back their response', { actor }),
		}
	case 'response.comment_updated':
		return {
			icon: 'CommentEdit',
			message: t('attendance', '{actor} updated their comment', { actor }),
		}
	case 'checkin.recorded':
		return {
			icon: 'AccountCheck',
			message: subject
				? t('attendance', '{actor} recorded check-in for {subject}: {state}', {
					actor,
					subject,
					state: getResponseText(meta.checkinState),
				})
				: t('attendance', 'Check-in recorded: {state}', {
					state: getResponseText(meta.checkinState),
				}),
		}
	case 'checkin.changed':
		return {
			icon: 'AccountSync',
			message: subject
				? t('attendance', '{actor} updated check-in for {subject}: {state}', {
					actor,
					subject,
					state: getResponseText(meta.checkinState),
				})
				: t('attendance', 'Check-in updated: {state}', {
					state: getResponseText(meta.checkinState),
				}),
		}
	default:
		return {
			icon: 'Information',
			message: t('attendance', '{actor} performed {verb}', {
				actor,
				verb: event.verb,
			}),
		}
	}
}
