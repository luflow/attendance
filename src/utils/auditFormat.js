import { translate as t } from '@nextcloud/l10n'
import { getResponseIcon, getResponseVariant } from './response.js'

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
	// TRANSLATORS: Noun — audit-log source label: the response was recorded
	// via the check-in screen.
	admin_checkin: () => t('attendance', 'Check-in'),
	legacy_backfill: () => t('attendance', 'Historic'),
	auto_close: () => t('attendance', 'Automatic'),
}

export function formatSource(source) {
	if (!source) return ''
	const fn = SOURCE_LABELS[source]
	return fn ? fn() : source
}

// The templates below reach t() only indirectly through buildSegments(), so
// the string extractor would miss them — register them here explicitly.
// TRANSLATORS: Audit-log entries. {actor}/{subject} are names, {response},
// {from}, {to} and {state} render as response chips (yes/no/maybe/…).
t('attendance', '{actor} answered {response}')
t('attendance', '{actor} changed response from {from} to {to}')
t('attendance', '{actor} recorded check-in for {subject}: {state}')
t('attendance', 'Check-in recorded: {state}')
t('attendance', '{actor} updated check-in for {subject}: {state}')
t('attendance', 'Check-in updated: {state}')

const CHIP_SENTINEL = /CHIP(\d+)/g

/**
 * Substitute chip placeholders via a sentinel so translated strings can
 * still reorder placeholders freely — the renderer reassembles the segments
 * in whatever order the localiser chose.
 *
 * @param {string} template English source string with `{key}` placeholders
 * @param {object} params text-only placeholder values
 * @param {Array<{key: string, value: string}>} chips placeholders that should
 *   render as response chips instead of plain text
 * @return {Array<{type: 'text', value: string}|{type: 'response', value: string}>}
 */
function buildSegments(template, params, chips) {
	const tParams = { ...params }
	chips.forEach((c, i) => {
		tParams[c.key] = `CHIP${i}`
	})
	const localized = t('attendance', template, tParams)

	return localized.split(CHIP_SENTINEL).map((piece, idx) => {
		// Every odd index is a captured CHIP-number group from the split.
		if (idx % 2 === 1) {
			return { type: 'response', value: chips[Number(piece)].value }
		}
		return { type: 'text', value: piece }
	}).filter((seg) => seg.type !== 'text' || seg.value !== '')
}

function textOnly(value) {
	return [{ type: 'text', value }]
}

// Human labels for the appointment fields that can change. They are joined into
// a comma-separated list inside the audit-log line "{actor} changed {fields}",
// so each label is lower-case and reads as a noun (e.g. "changed name, time").
const FIELD_LABELS = {
	// TRANSLATORS: Appointment field in the audit-log change list — the
	// appointment's title/name.
	name: () => t('attendance', 'name'),
	// TRANSLATORS: Appointment field in the audit-log change list — the
	// appointment's description text.
	description: () => t('attendance', 'description'),
	// TRANSLATORS: Appointment field in the audit-log change list — the
	// appointment's scheduled date & time (start/end). Clock time, NOT a count
	// like "one time".
	time: () => t('attendance', 'time'),
	// TRANSLATORS: Appointment field in the audit-log change list — who the
	// appointment is visible to (users/groups/teams).
	visibility: () => t('attendance', 'visibility'),
	// TRANSLATORS: Appointment field in the audit-log change list — the deadline
	// after which responses are no longer accepted.
	deadline: () => t('attendance', 'response deadline'),
}

function fieldLabel(key) {
	return FIELD_LABELS[key]?.() ?? key
}

function formatCheckin(icon, subject, state, withSubjectTpl, anonTpl, actor) {
	return {
		icon,
		iconVariant: getResponseVariant(state),
		segments: subject
			? buildSegments(withSubjectTpl, { actor, subject }, [{ key: 'state', value: state }])
			: buildSegments(anonTpl, {}, [{ key: 'state', value: state }]),
	}
}

/**
 * Map an audit-event verb to an icon name (from vue-material-design-icons),
 * an optional icon variant (success/error/warning), and an ordered list of
 * segments the timeline renders. Unknown verbs fall through to a generic
 * rendering so the UI keeps working when the backend ships new verbs the
 * frontend doesn't recognise yet.
 *
 * @param {object} event the audit event payload from the API
 * @return {{ icon: string, iconVariant: string, segments: Array<object> }}
 */
export function formatAuditEvent(event) {
	const actor = actorLabel(event)
	const subject = subjectLabel(event)
	const meta = event.meta ?? {}

	switch (event.verb) {
		case 'response.submitted':
			return {
				icon: getResponseIcon(meta.response, 'outline'),
				iconVariant: getResponseVariant(meta.response),
				segments: buildSegments(
					'{actor} answered {response}',
					{ actor },
					[{ key: 'response', value: meta.response }],
				),
			}
		case 'response.changed':
			return {
				icon: getResponseIcon(meta.to, 'outline'),
				iconVariant: getResponseVariant(meta.to),
				segments: buildSegments(
					'{actor} changed response from {from} to {to}',
					{ actor },
					[
						{ key: 'from', value: meta.from },
						{ key: 'to', value: meta.to },
					],
				),
			}
		case 'response.rescinded':
			return {
				icon: 'UndoVariant',
				iconVariant: 'default',
				segments: textOnly(t('attendance', '{actor} took back their response', { actor })),
			}
		case 'response.comment_updated':
			return {
				icon: 'CommentEdit',
				iconVariant: 'default',
				segments: textOnly(t('attendance', '{actor} updated their comment', { actor })),
			}
		case 'checkin.recorded':
			return formatCheckin(
				'AccountCheck',
				subject,
				meta.checkinState,
				'{actor} recorded check-in for {subject}: {state}',
				'Check-in recorded: {state}',
				actor,
			)
		case 'checkin.changed':
			return formatCheckin(
				'AccountSync',
				subject,
				meta.checkinState,
				'{actor} updated check-in for {subject}: {state}',
				'Check-in updated: {state}',
				actor,
			)
		case 'appointment.created':
			return {
				icon: 'CalendarPlus',
				iconVariant: 'default',
				// TRANSLATORS: Audit-log entry. {actor} is a person's name. "inquiry"
				// is the appointment's response collection (the request for people to
				// answer yes/no/maybe), not the event itself.
				segments: textOnly(t('attendance', '{actor} created this inquiry', { actor })),
			}
		case 'appointment.updated': {
			const fields = (meta.fields ?? []).map(fieldLabel).filter(Boolean)
			return {
				icon: 'CalendarEditOutline',
				iconVariant: 'default',
				segments: textOnly(fields.length > 0
					// TRANSLATORS: Audit-log entry. {actor} is a person's name,
					// {fields} is a comma-separated list of the appointment
					// fields that changed (e.g. "name, time").
					? t('attendance', '{actor} changed {fields}', { actor, fields: fields.join(', ') })
					// TRANSLATORS: Audit-log entry when the appointment was edited
					// but no specific field could be attributed. {actor} is a name.
					: t('attendance', '{actor} edited this appointment', { actor })),
			}
		}
		case 'appointment.closed':
			return {
				icon: 'LockOutline',
				iconVariant: 'default',
				segments: textOnly(event.actor
					// TRANSLATORS: Audit-log entry. {actor} is a person's name.
					// Closing an "inquiry" stops new responses; the appointment
					// itself still takes place (this is not a cancellation).
					? t('attendance', '{actor} closed the inquiry', { actor })
					// TRANSLATORS: Audit-log entry when the inquiry was closed by
					// the system (e.g. its deadline passed), not by a person.
					: t('attendance', 'Inquiry closed automatically')),
			}
		case 'appointment.reopened':
			return {
				icon: 'LockOpenVariantOutline',
				iconVariant: 'default',
				// TRANSLATORS: Audit-log entry. {actor} is a person's name.
				// Re-opening an "inquiry" resumes accepting responses after it was
				// closed.
				segments: textOnly(t('attendance', '{actor} re-opened the inquiry', { actor })),
			}
		case 'appointment.cancelled':
			return {
				icon: 'CalendarRemoveOutline',
				iconVariant: 'default',
				segments: textOnly(t('attendance', '{actor} cancelled the appointment', { actor })),
			}
		case 'appointment.uncancelled':
			return {
				icon: 'CalendarRefreshOutline',
				iconVariant: 'default',
				segments: textOnly(t('attendance', '{actor} reactivated the appointment', { actor })),
			}
		default:
			return {
				icon: 'Information',
				iconVariant: 'default',
				segments: textOnly(t('attendance', '{actor} performed {verb}', { actor, verb: event.verb })),
			}
	}
}
