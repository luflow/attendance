/**
 * Helpers for date-only 'Y-m-d' strings, as vacations use them. Nothing here
 * goes through `new Date(string)` or `toISOString()`: both speak UTC and would
 * move the day in most timezones.
 */

import { getCanonicalLocale } from '@nextcloud/l10n'

/**
 * @param {string} value - A date as Y-m-d.
 * @return {Date|null} Local midnight of that day, null when malformed.
 */
function parseDateOnly(value) {
	const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value ?? '')
	if (!match) return null

	return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
}

/**
 * @return {string} Today where the user is, as Y-m-d.
 */
export function todayDateOnly() {
	const now = new Date()
	const month = String(now.getMonth() + 1).padStart(2, '0')
	const day = String(now.getDate()).padStart(2, '0')
	return `${now.getFullYear()}-${month}-${day}`
}

/**
 * @param {string} value - A date as Y-m-d.
 * @param {Intl.DateTimeFormatOptions} options - How to render it.
 * @return {string} The date in the user's locale, the raw value when malformed.
 */
function formatDateOnly(value, options) {
	const date = parseDateOnly(value)
	return date ? date.toLocaleDateString(getCanonicalLocale(), options) : String(value ?? '')
}

/**
 * A closed range of whole days; a single day when both ends are the same.
 *
 * @param {string} startDate - First day as Y-m-d.
 * @param {string} endDate - Last day as Y-m-d.
 * @param {Intl.DateTimeFormatOptions} [options] - How to render each end.
 * @return {string} E.g. "Oct 4, 2026 – Oct 11, 2026".
 */
export function formatDateOnlyRange(startDate, endDate, options = { dateStyle: 'medium' }) {
	const start = formatDateOnly(startDate, options)
	return startDate === endDate ? start : `${start} – ${formatDateOnly(endDate, options)}`
}

/**
 * @param {string} value - A date as Y-m-d.
 * @return {string} Its month and year, e.g. "October 2026".
 */
export function formatMonthOfDateOnly(value) {
	return formatDateOnly(value, { month: 'long', year: 'numeric' })
}
