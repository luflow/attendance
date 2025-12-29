/**
 * Centralized response utilities for the Attendance app.
 * Consolidates response-related helper functions and constants.
 */

/**
 * Valid response values.
 */
export const RESPONSES = {
	YES: 'yes',
	NO: 'no',
	MAYBE: 'maybe',
}

/**
 * Response variant mapping for UI components.
 */
export const RESPONSE_VARIANTS = {
	yes: 'success',
	no: 'error',
	maybe: 'warning',
}

/**
 * Get the display text for a response value.
 * Uses the translation function if available.
 *
 * @param {string} response - The response value (yes, no, maybe)
 * @return {string} The translated display text
 */
export function getResponseText(response) {
	const t = window.t || ((app, text) => text)

	const texts = {
		yes: t('attendance', 'Yes'),
		no: t('attendance', 'No'),
		maybe: t('attendance', 'Maybe'),
	}

	return texts[response] || response
}

/**
 * Get the UI variant for a response value.
 * Used for NcButton and NcChip variant props.
 *
 * @param {string} response - The response value (yes, no, maybe)
 * @return {string} The variant (success, error, warning, tertiary)
 */
export function getResponseVariant(response) {
	return RESPONSE_VARIANTS[response] || 'tertiary'
}

/**
 * Check if a response value is valid.
 *
 * @param {string} response - The response value to check
 * @return {boolean} True if valid
 */
export function isValidResponse(response) {
	return Object.values(RESPONSES).includes(response)
}

/**
 * Get the icon name for a response value.
 * For use with Material Design Icons.
 *
 * @param {string} response - The response value (yes, no, maybe)
 * @return {string} The icon name
 */
export function getResponseIcon(response) {
	const icons = {
		yes: 'CheckCircle',
		no: 'CloseCircle',
		maybe: 'HelpCircle',
	}

	return icons[response] || 'ProgressQuestion'
}

/**
 * Calculate response summary counts from a list of responses.
 *
 * @param {Array} responses - Array of response objects with 'response' property
 * @return {object} Summary with yes, no, maybe, and total counts
 */
export function calculateResponseCounts(responses) {
	const counts = {
		yes: 0,
		no: 0,
		maybe: 0,
		total: 0,
	}

	for (const item of responses) {
		const response = item.response || item
		if (counts[response] !== undefined) {
			counts[response]++
			counts.total++
		}
	}

	return counts
}

/**
 * Sort responses by response type (yes first, then maybe, then no).
 *
 * @param {Array} responses - Array of response objects
 * @param {string} responseKey - Key to use for response value (default: 'response')
 * @return {Array} Sorted array
 */
export function sortResponsesByType(responses, responseKey = 'response') {
	const order = { yes: 0, maybe: 1, no: 2 }

	return [...responses].sort((a, b) => {
		const orderA = order[a[responseKey]] ?? 3
		const orderB = order[b[responseKey]] ?? 3
		return orderA - orderB
	})
}

/**
 * Filter responses by response type.
 *
 * @param {Array} responses - Array of response objects
 * @param {string} type - Response type to filter by (yes, no, maybe)
 * @param {string} responseKey - Key to use for response value (default: 'response')
 * @return {Array} Filtered array
 */
export function filterResponsesByType(responses, type, responseKey = 'response') {
	return responses.filter(r => r[responseKey] === type)
}

/**
 * Check if an appointment has a user response.
 *
 * @param {object} appointment - The appointment object
 * @return {boolean} True if user has responded
 */
export function hasUserResponse(appointment) {
	return appointment?.userResponse?.response != null
}

/**
 * Get the user's response from an appointment.
 *
 * @param {object} appointment - The appointment object
 * @return {string|null} The response value or null
 */
export function getUserResponse(appointment) {
	return appointment?.userResponse?.response || null
}

/**
 * Get the user's comment from an appointment.
 *
 * @param {object} appointment - The appointment object
 * @return {string} The comment or empty string
 */
export function getUserComment(appointment) {
	return appointment?.userResponse?.comment || ''
}
