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
	admin_checkin: () => t('attendance', 'Check-in'),
	legacy_backfill: () => t('attendance', 'Historic'),
	auto_close: () => t('attendance', 'Automatic'),
}

export function formatSource(source) {
	if (!source) return ''
	const fn = SOURCE_LABELS[source]
	return fn ? fn() : source
}

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
	chips.forEach((c, i) => { tParams[c.key] = `CHIP${i}` })
	const localized = t('attendance', template, tParams)

	return localized.split(CHIP_SENTINEL).map((piece, idx) => {
		// Every odd index is a captured CHIP-number group from the split.
		if (idx % 2 === 1) {
			return { type: 'response', value: chips[Number(piece)].value }
		}
		return { type: 'text', value: piece }
	}).filter(seg => seg.type !== 'text' || seg.value !== '')
}

function textOnly(value) {
	return [{ type: 'text', value }]
}

const FIELD_LABELS = {
	name: () => t('attendance', 'name'),
	description: () => t('attendance', 'description'),
	time: () => t('attendance', 'time'),
	visibility: () => t('attendance', 'visibility'),
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
			segments: textOnly(t('attendance', '{actor} created this inquiry', { actor })),
		}
	case 'appointment.updated': {
		const fields = (meta.fields ?? []).map(fieldLabel).filter(Boolean)
		return {
			icon: 'CalendarEditOutline',
			iconVariant: 'default',
			segments: textOnly(
				fields.length > 0
					? t('attendance', '{actor} edited this inquiry: {fields}', { actor, fields: fields.join(', ') })
					: t('attendance', '{actor} edited this inquiry', { actor }),
			),
		}
	}
	case 'appointment.closed':
		return {
			icon: 'LockOutline',
			iconVariant: 'default',
			segments: textOnly(
				event.actor
					? t('attendance', '{actor} closed the inquiry', { actor })
					: t('attendance', 'Inquiry closed automatically'),
			),
		}
	case 'appointment.reopened':
		return {
			icon: 'LockOpenVariantOutline',
			iconVariant: 'default',
			segments: textOnly(t('attendance', '{actor} re-opened the inquiry', { actor })),
		}
	default:
		return {
			icon: 'Information',
			iconVariant: 'default',
			segments: textOnly(t('attendance', '{actor} performed {verb}', { actor, verb: event.verb })),
		}
	}
}
