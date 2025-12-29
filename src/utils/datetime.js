/**
 * Centralized datetime utilities for the Attendance app.
 * Consolidates all date/time formatting and conversion functions.
 */

import { fromZonedTime } from 'date-fns-tz'

/**
 * Get the user's timezone from browser or default to Europe/Berlin.
 * @return {string} The timezone string
 */
export function getUserTimezone() {
	try {
		return Intl.DateTimeFormat().resolvedOptions().timeZone || 'Europe/Berlin'
	} catch {
		return 'Europe/Berlin'
	}
}

/**
 * Format a datetime for display.
 * Uses localized format with short date and time.
 *
 * @param {string|Date} datetime - The datetime to format
 * @param {object} options - Formatting options
 * @param {string} options.locale - Locale string (default: browser locale)
 * @param {string} options.dateStyle - Date style: 'short', 'medium', 'long' (default: 'short')
 * @param {string} options.timeStyle - Time style: 'short', 'medium', 'long' (default: 'short')
 * @return {string} Formatted datetime string
 */
export function formatDateTime(datetime, options = {}) {
	if (!datetime) return ''

	try {
		const date = datetime instanceof Date ? datetime : new Date(datetime)
		if (isNaN(date.getTime())) return ''

		const locales = options.locale ? [options.locale] : ['de-DE', 'en-EN']
		const formatOptions = {
			dateStyle: options.dateStyle || 'short',
			timeStyle: options.timeStyle || 'short',
		}

		return date.toLocaleString(locales, formatOptions)
	} catch {
		return String(datetime)
	}
}

/**
 * Format a datetime for display with medium date style.
 *
 * @param {string|Date} datetime - The datetime to format
 * @return {string} Formatted datetime string
 */
export function formatDateTimeMedium(datetime) {
	return formatDateTime(datetime, { dateStyle: 'medium', timeStyle: 'short' })
}

/**
 * Format a datetime for HTML datetime-local input.
 * Returns format: YYYY-MM-DDTHH:MM
 *
 * @param {string|Date} datetime - The datetime to format
 * @return {string} Formatted string for datetime-local input
 */
export function formatDateTimeForInput(datetime) {
	if (!datetime) return ''

	try {
		const date = datetime instanceof Date ? datetime : new Date(datetime)
		if (isNaN(date.getTime())) return ''

		const year = date.getFullYear()
		const month = String(date.getMonth() + 1).padStart(2, '0')
		const day = String(date.getDate()).padStart(2, '0')
		const hours = String(date.getHours()).padStart(2, '0')
		const minutes = String(date.getMinutes()).padStart(2, '0')

		return `${year}-${month}-${day}T${hours}:${minutes}`
	} catch {
		return ''
	}
}

/**
 * Parse a datetime-local input value to a Date object.
 *
 * @param {string} inputValue - The value from datetime-local input
 * @return {Date|null} Parsed Date or null if invalid
 */
export function parseDateTimeInput(inputValue) {
	if (!inputValue) return null

	try {
		const date = new Date(inputValue)
		return isNaN(date.getTime()) ? null : date
	} catch {
		return null
	}
}

/**
 * Convert a local datetime to server timezone for API submission.
 *
 * @param {string|Date} datetime - The local datetime
 * @param {string} timezone - Target timezone (default: Europe/Berlin for server)
 * @return {Date} Date object adjusted for server timezone
 */
export function toServerTimezone(datetime, timezone = 'Europe/Berlin') {
	if (!datetime) return null

	try {
		const localDate = datetime instanceof Date ? datetime : new Date(datetime)
		if (isNaN(localDate.getTime())) return null

		return fromZonedTime(localDate, timezone)
	} catch {
		return null
	}
}

/**
 * Format a date for display (date only, no time).
 *
 * @param {string|Date} datetime - The datetime to format
 * @param {string} style - Date style: 'short', 'medium', 'long' (default: 'medium')
 * @return {string} Formatted date string
 */
export function formatDate(datetime, style = 'medium') {
	if (!datetime) return ''

	try {
		const date = datetime instanceof Date ? datetime : new Date(datetime)
		if (isNaN(date.getTime())) return ''

		return date.toLocaleDateString(['de-DE', 'en-EN'], { dateStyle: style })
	} catch {
		return String(datetime)
	}
}

/**
 * Format a time for display (time only, no date).
 *
 * @param {string|Date} datetime - The datetime to format
 * @param {string} style - Time style: 'short', 'medium', 'long' (default: 'short')
 * @return {string} Formatted time string
 */
export function formatTime(datetime, style = 'short') {
	if (!datetime) return ''

	try {
		const date = datetime instanceof Date ? datetime : new Date(datetime)
		if (isNaN(date.getTime())) return ''

		return date.toLocaleTimeString(['de-DE', 'en-EN'], { timeStyle: style })
	} catch {
		return String(datetime)
	}
}

/**
 * Check if a datetime is in the past.
 *
 * @param {string|Date} datetime - The datetime to check
 * @return {boolean} True if datetime is in the past
 */
export function isPast(datetime) {
	if (!datetime) return false

	try {
		const date = datetime instanceof Date ? datetime : new Date(datetime)
		return date < new Date()
	} catch {
		return false
	}
}

/**
 * Check if a datetime is in the future.
 *
 * @param {string|Date} datetime - The datetime to check
 * @return {boolean} True if datetime is in the future
 */
export function isFuture(datetime) {
	if (!datetime) return false

	try {
		const date = datetime instanceof Date ? datetime : new Date(datetime)
		return date > new Date()
	} catch {
		return false
	}
}

/**
 * Check if check-in is allowed (30 minutes before start time).
 *
 * @param {string|Date} startDatetime - The appointment start time
 * @param {number} minutesBefore - Minutes before start to allow check-in (default: 30)
 * @return {boolean} True if check-in is allowed
 */
export function canCheckinNow(startDatetime, minutesBefore = 30) {
	if (!startDatetime) return false

	try {
		const startTime = startDatetime instanceof Date ? startDatetime : new Date(startDatetime)
		const checkinTime = new Date(startTime.getTime() - minutesBefore * 60 * 1000)
		return new Date() >= checkinTime
	} catch {
		return false
	}
}

/**
 * Add hours to a datetime.
 *
 * @param {string|Date} datetime - The base datetime
 * @param {number} hours - Hours to add
 * @return {Date|null} New Date with hours added
 */
export function addHours(datetime, hours) {
	if (!datetime) return null

	try {
		const date = datetime instanceof Date ? datetime : new Date(datetime)
		if (isNaN(date.getTime())) return null

		return new Date(date.getTime() + hours * 60 * 60 * 1000)
	} catch {
		return null
	}
}
