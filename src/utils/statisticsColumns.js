import { translate as t } from '@nextcloud/l10n'

/**
 * The membership a section heading carries when the table is grouped. Ungrouped
 * it has to become a column, which is the one thing a flat list would otherwise
 * lose — and the widest column on the page, so it is worth being able to drop.
 */
export const SECTIONS_COLUMN = 'sections'

/**
 * Every data column the table can show, in display order.
 *
 * `compact` marks the ones that answer the question at a glance: who was asked,
 * what they said, whether they came. `scheduling` marks the ones that only mean
 * anything while the planning mode is switched on.
 */
export const STATISTICS_COLUMNS = [
	{ key: 'targetCount', label: t('attendance', 'Appointments') },
	{ key: 'yes', label: t('attendance', 'Yes'), compact: true },
	{ key: 'no', label: t('attendance', 'No'), compact: true },
	{ key: 'maybe', label: t('attendance', 'Maybe'), compact: true },
	{ key: 'noResponse', label: t('attendance', 'No response') },
	{ key: 'present', label: t('attendance', 'Present'), compact: true },
	{ key: 'absent', label: t('attendance', 'Absent') },
	// TRANSLATORS: Column header — for how many appointments nobody wrote down whether this person was there. Not the same as being absent.
	{ key: 'notRecorded', label: t('attendance', 'Not recorded') },
	{
		key: 'noShow',
		// TRANSLATORS: Column header — the person said yes and then was not there. English uses the noun "no-show"; other languages usually need a short phrase.
		label: t('attendance', 'No-show'),
		hint: t('attendance', 'Said yes but was recorded as absent'),
	},
	{
		key: 'scheduled',
		// TRANSLATORS: Column header — how often the person accepted and then got a place in the appointment.
		label: t('attendance', 'Scheduled'),
		compact: true,
		scheduling: true,
	},
	{
		key: 'notScheduled',
		// TRANSLATORS: Column header — the person accepted and the inquiry was closed, but they did not get a place.
		label: t('attendance', 'Not scheduled'),
		scheduling: true,
	},
	// TRANSLATORS: Column header — share of appointments the person answered at all, whatever the answer was.
	{ key: 'responseRate', label: t('attendance', 'Response rate'), rate: true, compact: true },
	// TRANSLATORS: Column header — share of appointments the person answered with yes. Sits next to "Response rate" and "Attendance rate", which count different things.
	{ key: 'acceptRate', label: t('attendance', 'Acceptance rate'), rate: true, compact: true },
	// TRANSLATORS: Column header — share of appointments the person was actually there for, counted only over appointments where somebody worked the check-in list.
	{ key: 'attendanceRate', label: t('attendance', 'Attendance rate'), rate: true, compact: true },
	{
		key: 'scheduledRate',
		// TRANSLATORS: Column header — share of the person's acceptances that got a place. Sits next to "Acceptance rate", which counts something else.
		label: t('attendance', 'Scheduling rate'),
		hint: t('attendance', 'Counted only over closed inquiries where somebody was scheduled'),
		rate: true,
		compact: true,
		scheduling: true,
	},
]

/**
 * What the sections column is called, which depends on what the evaluation was
 * grouped by. Lives here because the table header and the column picker both
 * have to say the same word.
 *
 * @param {string} groupBy - 'groups' or 'teams'.
 * @return {string} The column label.
 */
export function sectionsColumnLabel(groupBy) {
	return groupBy === 'teams' ? t('attendance', 'Teams') : t('attendance', 'Groups')
}

/**
 * Every column that makes sense on this instance, sections column first.
 *
 * @param {boolean} scheduling - Whether the planning mode is switched on.
 * @param {string} groupBy - 'groups' or 'teams'.
 * @return {Array<object>} Column descriptors.
 */
export function availableColumns(scheduling, groupBy) {
	return [
		{ key: SECTIONS_COLUMN, label: sectionsColumnLabel(groupBy), compact: true },
		...STATISTICS_COLUMNS.filter((column) => scheduling || !column.scheduling),
	]
}

/**
 * The two presets behind the compact/full switch. Both include the sections
 * column — the table drops it on its own while grouped, where the section
 * headings already carry it.
 *
 * @param {'compact'|'full'} detail - Which preset.
 * @param {boolean} scheduling - Whether the planning mode is switched on.
 * @return {Array<string>} Column keys.
 */
export function presetColumns(detail, scheduling) {
	return availableColumns(scheduling, 'groups')
		.filter((column) => detail === 'full' || column.compact)
		.map((column) => column.key)
}

/**
 * A rate as a percentage, one decimal. Shared so the table, the charts and the
 * highlight cards cannot drift apart on rounding or on the space before the
 * sign — they render the same numbers side by side.
 *
 * @param {?number} rate - A rate between 0 and 1, or null without a basis.
 * @param {string} fallback - Rendered instead when there is no rate.
 * @return {string} The formatted percentage.
 */
export function formatRate(rate, fallback = '–') {
	return rate === null || rate === undefined
		? fallback
		: `${Math.round(rate * 1000) / 10} %`
}

/**
 * A selection with `value` flipped in or out. Written once because this view
 * alone toggles cards, columns and categories, and copies of it drift — the
 * card toggle shipped with the column toggle's doc comment.
 *
 * @param {Array<string|number>} list - The current selection.
 * @param {string|number} value - What to flip.
 * @return {Array<string|number>} A new selection.
 */
export function toggled(list, value) {
	return list.includes(value) ? list.filter((entry) => entry !== value) : [...list, value]
}
