/**
 * Composable for handling appointment responses and comments.
 * Centralizes response submission and comment auto-save logic.
 */

import { ref, nextTick } from 'vue'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'

/**
 * Create a response handler for a specific appointment.
 *
 * @param {object} options - Configuration options
 * @param {Function} options.onSuccess - Callback after successful response
 * @param {Function} options.onError - Callback after failed response
 * @return {object} Response handling functions and state
 */
export function useAppointmentResponse(options = {}) {
	const { onSuccess, onError } = options

	// Comment state
	const savingComment = ref(false)
	const commentSaved = ref(false)
	const errorComment = ref(false)
	let commentTimeout = null
	let savedIndicatorTimeout = null
	let errorIndicatorTimeout = null

	/**
	 * Submit a response to an appointment.
	 *
	 * @param {number} appointmentId - The appointment ID
	 * @param {string} response - The response (yes, no, maybe)
	 * @param {string} comment - Optional comment
	 * @return {Promise<object>} The API response
	 */
	const submitResponse = async (appointmentId, response, comment = '') => {
		const t = window.t || ((app, text) => text)

		try {
			const url = generateUrl('/apps/attendance/api/appointments/{id}/respond', { id: appointmentId })
			const axiosResponse = await axios.post(url, {
				response,
				comment,
			})

			if (axiosResponse.status < 200 || axiosResponse.status >= 300) {
				throw new Error(`API returned status ${axiosResponse.status}`)
			}

			showSuccess(t('attendance', 'Response updated'))

			if (onSuccess) {
				onSuccess(axiosResponse.data)
			}

			return axiosResponse.data
		} catch (error) {
			console.error('Failed to submit response:', error)
			showError(t('attendance', 'Error updating response'))

			if (onError) {
				onError(error)
			}

			throw error
		}
	}

	/**
	 * Auto-save a comment with debouncing.
	 * Shows visual feedback (spinner, checkmark, error icon).
	 *
	 * @param {number} appointmentId - The appointment ID
	 * @param {string} currentResponse - The current response value
	 * @param {string} commentText - The comment text
	 * @param {boolean} silent - If true, don't show success message
	 * @return {Promise<void>}
	 */
	const autoSaveComment = async (appointmentId, currentResponse, commentText, silent = true) => {
		if (!currentResponse) return

		const t = window.t || ((app, text) => text)

		// Clear any pending timeouts
		clearSavedIndicator()
		clearErrorIndicator()

		savingComment.value = true
		commentSaved.value = false
		errorComment.value = false

		try {
			const url = generateUrl('/apps/attendance/api/appointments/{id}/respond', { id: appointmentId })
			const axiosResponse = await axios.post(url, {
				response: currentResponse,
				comment: commentText,
			})

			if (axiosResponse.status < 200 || axiosResponse.status >= 300) {
				throw new Error(`API returned status ${axiosResponse.status}`)
			}

			// Show saved indicator with delay for visual feedback
			setTimeout(() => {
				savingComment.value = false
				commentSaved.value = true

				// Auto-hide saved indicator after 2 seconds
				savedIndicatorTimeout = setTimeout(() => {
					commentSaved.value = false
				}, 2000)
			}, 500)

			if (!silent) {
				showSuccess(t('attendance', 'Comment updated'))
			}

			if (onSuccess) {
				onSuccess(axiosResponse.data)
			}
		} catch (error) {
			console.error('Failed to save comment:', error)
			savingComment.value = false
			errorComment.value = true
			showError(t('attendance', 'Comment could not be saved'))

			// Auto-hide error indicator after 3 seconds
			errorIndicatorTimeout = setTimeout(() => {
				errorComment.value = false
			}, 3000)

			if (onError) {
				onError(error)
			}
		}
	}

	/**
	 * Create a debounced comment input handler.
	 *
	 * @param {Function} getCommentText - Function to get current comment text
	 * @param {Function} getCurrentResponse - Function to get current response
	 * @param {number} appointmentId - The appointment ID
	 * @param {number} delay - Debounce delay in ms (default: 500)
	 * @return {Function} Input event handler
	 */
	const createCommentInputHandler = (getCommentText, getCurrentResponse, appointmentId, delay = 500) => {
		return () => {
			if (commentTimeout) {
				clearTimeout(commentTimeout)
			}

			commentTimeout = setTimeout(async () => {
				await nextTick()
				const text = getCommentText()
				const response = getCurrentResponse()
				autoSaveComment(appointmentId, response, text)
			}, delay)
		}
	}

	/**
	 * Clear saved indicator timeout.
	 */
	const clearSavedIndicator = () => {
		if (savedIndicatorTimeout) {
			clearTimeout(savedIndicatorTimeout)
			savedIndicatorTimeout = null
		}
	}

	/**
	 * Clear error indicator timeout.
	 */
	const clearErrorIndicator = () => {
		if (errorIndicatorTimeout) {
			clearTimeout(errorIndicatorTimeout)
			errorIndicatorTimeout = null
		}
	}

	/**
	 * Reset all state.
	 */
	const reset = () => {
		if (commentTimeout) {
			clearTimeout(commentTimeout)
			commentTimeout = null
		}
		clearSavedIndicator()
		clearErrorIndicator()
		savingComment.value = false
		commentSaved.value = false
		errorComment.value = false
	}

	return {
		// State
		savingComment,
		commentSaved,
		errorComment,

		// Methods
		submitResponse,
		autoSaveComment,
		createCommentInputHandler,
		reset,
	}
}

/**
 * Create a multi-appointment response handler.
 * Useful for list views where multiple appointments are shown.
 *
 * @param {object} options - Configuration options
 * @return {object} Response handling functions for multiple appointments
 */
export function useMultiAppointmentResponse(options = {}) {
	const { onSuccess, onError } = options

	// Per-appointment state
	const savingComments = {}
	const savedComments = {}
	const errorComments = {}
	const commentTimeouts = {}

	const t = window.t || ((app, text) => text)

	/**
	 * Submit a response to an appointment.
	 * @param appointmentId
	 * @param response
	 * @param comment
	 */
	const submitResponse = async (appointmentId, response, comment = '') => {
		try {
			const url = generateUrl('/apps/attendance/api/appointments/{id}/respond', { id: appointmentId })
			const axiosResponse = await axios.post(url, {
				response,
				comment,
			})

			if (axiosResponse.status < 200 || axiosResponse.status >= 300) {
				throw new Error(`API returned status ${axiosResponse.status}`)
			}

			showSuccess(t('attendance', 'Response updated'))

			if (onSuccess) {
				onSuccess(appointmentId, axiosResponse.data)
			}

			return axiosResponse.data
		} catch (error) {
			console.error('Failed to submit response:', error)
			showError(t('attendance', 'Error updating response'))

			if (onError) {
				onError(appointmentId, error)
			}

			throw error
		}
	}

	/**
	 * Auto-save comment for a specific appointment.
	 * @param appointmentId
	 * @param currentResponse
	 * @param commentText
	 */
	const autoSaveComment = async (appointmentId, currentResponse, commentText) => {
		if (!currentResponse) return

		savingComments[appointmentId] = true
		savedComments[appointmentId] = false
		errorComments[appointmentId] = false

		try {
			const url = generateUrl('/apps/attendance/api/appointments/{id}/respond', { id: appointmentId })
			await axios.post(url, {
				response: currentResponse,
				comment: commentText,
			})

			setTimeout(() => {
				savingComments[appointmentId] = false
				savedComments[appointmentId] = true

				setTimeout(() => {
					savedComments[appointmentId] = false
				}, 2000)
			}, 500)
		} catch (error) {
			console.error('Failed to save comment:', error)
			savingComments[appointmentId] = false
			errorComments[appointmentId] = true
			showError(t('attendance', 'Comment could not be saved'))

			setTimeout(() => {
				errorComments[appointmentId] = false
			}, 3000)
		}
	}

	/**
	 * Handle comment input with debouncing.
	 * @param appointmentId
	 * @param getCommentText
	 * @param getCurrentResponse
	 * @param delay
	 */
	const onCommentInput = (appointmentId, getCommentText, getCurrentResponse, delay = 500) => {
		if (commentTimeouts[appointmentId]) {
			clearTimeout(commentTimeouts[appointmentId])
		}

		commentTimeouts[appointmentId] = setTimeout(async () => {
			await nextTick()
			const text = getCommentText()
			const response = getCurrentResponse()
			autoSaveComment(appointmentId, response, text)
		}, delay)
	}

	/**
	 * Check if comment is being saved for an appointment.
	 * @param appointmentId
	 */
	const isSaving = (appointmentId) => !!savingComments[appointmentId]

	/**
	 * Check if comment was saved for an appointment.
	 * @param appointmentId
	 */
	const isSaved = (appointmentId) => !!savedComments[appointmentId]

	/**
	 * Check if comment save failed for an appointment.
	 * @param appointmentId
	 */
	const hasError = (appointmentId) => !!errorComments[appointmentId]

	return {
		submitResponse,
		autoSaveComment,
		onCommentInput,
		isSaving,
		isSaved,
		hasError,
	}
}
