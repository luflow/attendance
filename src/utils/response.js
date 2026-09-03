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
 * Display order for the three answers, best-case first. Object key order is not
 * something the UI should depend on, so anything that renders all three — the
 * answer buttons, the summary bar and its legend — iterates this instead.
 */
export const RESPONSE_ORDER = [RESPONSES.YES, RESPONSES.MAYBE, RESPONSES.NO]

/**
 * The answers an appointment offers, in display order. Appointments that turned
 * "Maybe" off — and the ones with a limit, which never offer it — drop it here
 * so no button appears for an answer the server would reject.
 *
 * @param {boolean} [allowMaybe] The appointment's resolved allowMaybe flag.
 * @return {string[]} Response values to render.
 */
export function responseOptionsFor(allowMaybe) {
	if (allowMaybe === false) {
		return RESPONSE_ORDER.filter((response) => response !== RESPONSES.MAYBE)
	}
	return RESPONSE_ORDER
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
let responseTexts = null

export function getResponseText(response) {
	const t = window.t || ((app, text) => text)
	// Built on first use, not per call: the list renders this for every card,
	// every dot and every bar legend, and t() is not free.
	responseTexts ??= {
		yes: t('attendance', 'Yes'),
		no: t('attendance', 'No'),
		maybe: t('attendance', 'Maybe'),
	}
	return responseTexts[response] || response
}

/**
 * Get the display text for a check-in state. Shares the yes/no values with a
 * response, but never its wording — somebody is present, not "yes".
 *
 * @param {string} state - The check-in state (yes, no)
 * @return {string} The translated display text
 */
let checkinTexts = null

export function getCheckinText(state) {
	const t = window.t || ((app, text) => text)
	checkinTexts ??= {
		yes: t('attendance', 'Present'),
		no: t('attendance', 'Absent'),
	}
	return checkinTexts[state] || t('attendance', 'Not recorded')
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
 * Bar segments for a response summary, in display order.
 *
 * @param {object} summary The `responseSummary` payload.
 * @return {Array} Segments for ResponseBar.
 */
export function responseSegments(summary) {
	const t = window.t || ((app, text) => text)
	return [
		...RESPONSE_ORDER.map((response) => ({
			key: response,
			variant: getResponseVariant(response),
			count: summary?.[response] ?? 0,
			label: getResponseText(response),
		})),
		// No answer is the empty part of the track — legend only, no fill.
		{ key: 'none', variant: 'tertiary', count: summary?.no_response ?? 0, label: t('attendance', 'No response') },
	]
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
 * @param {'filled'|'outline'} [style] - Icon family
 * @return {string} The icon name
 */
export function getResponseIcon(response, style = 'filled') {
	const base = {
		yes: 'CheckCircle',
		no: 'CloseCircle',
		maybe: 'HelpCircle',
	}[response]

	if (!base) {
		return style === 'outline' ? 'CheckCircleOutline' : 'ProgressQuestion'
	}
	return style === 'outline' ? `${base}Outline` : base
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
	return responses.filter((r) => r[responseKey] === type)
}

/**
 * Check if an appointment has a user response.
 *
 * @param {object} appointment - The appointment object
 * @return {boolean} True if user has responded
 */
export function hasUserResponse(appointment) {
	const response = appointment?.userResponse?.response
	return response !== null && response !== undefined
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
