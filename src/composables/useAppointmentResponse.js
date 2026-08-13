/**
 * Composable for handling appointment responses and comments.
 * Centralizes response submission and comment saving.
 */

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { onBeforeUnmount, ref } from 'vue'

/**
 * Short cooldown for the yes/maybe/no buttons that prevents button-smashing
 * from accidentally toggling a just-submitted answer back to null.
 *
 * @param {import('vue').Ref<string|null>|(() => string|null)} currentResponse
 *   Reactive source for the user's current response (ref or getter).
 * @param {number} cooldownMs - Disable duration in milliseconds.
 * @return {{ responseCooldown: import('vue').Ref<boolean>, resolveNext: (clicked: string) => string|null, startCooldown: () => void }}
 */
export function useResponseCooldown(currentResponse, cooldownMs = 800) {
	const responseCooldown = ref(false)
	let timer = null

	const read = () => (typeof currentResponse === 'function'
		? currentResponse()
		: currentResponse?.value)

	const resolveNext = (clicked) => (read() === clicked ? null : clicked)

	const startCooldown = () => {
		responseCooldown.value = true
		if (timer) clearTimeout(timer)
		timer = setTimeout(() => {
			responseCooldown.value = false
			timer = null
		}, cooldownMs)
	}

	onBeforeUnmount(() => {
		if (timer) clearTimeout(timer)
	})

	return { responseCooldown, resolveNext, startCooldown }
}

/**
 * Create a response handler for a specific appointment.
 *
 * @param {object} options - Configuration options
 * @param {(data: object) => void} options.onSuccess - Called with the response
 *   payload after a response or comment was saved.
 * @param {(error: Error) => void} options.onError - Called with the error after a
 *   response or comment failed to save.
 * @return {object} Response handling functions and state
 */
export function useAppointmentResponse(options = {}) {
	const { onSuccess, onError } = options

	// Comment state
	const savingComment = ref(false)
	const errorComment = ref(false)
	let errorIndicatorTimeout = null

	/**
	 * Submit a response to an appointment.
	 *
	 * @param {number} appointmentId - The appointment ID
	 * @param {string|null} response - The response (yes, no, maybe) or null to withdraw
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

			showSuccess(response === null
				? t('attendance', 'Response withdrawn')
				: t('attendance', 'Response updated'))

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
	 * Clear error indicator timeout.
	 */
	const clearErrorIndicator = () => {
		if (errorIndicatorTimeout) {
			clearTimeout(errorIndicatorTimeout)
			errorIndicatorTimeout = null
		}
	}

	/**
	 * Save a comment. Only ever called from an explicit user action (save
	 * button, Enter) — comments are never sent while typing.
	 *
	 * @param {number} appointmentId - The appointment ID
	 * @param {string} currentResponse - The current response value
	 * @param {string} commentText - The comment text
	 * @return {Promise<void>}
	 */
	const saveComment = async (appointmentId, currentResponse, commentText) => {
		if (!currentResponse) return

		const t = window.t || ((app, text) => text)

		clearErrorIndicator()

		savingComment.value = true
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

			savingComment.value = false
			showSuccess(t('attendance', 'Comment updated'))

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
	 * Reset all state.
	 */
	const reset = () => {
		clearErrorIndicator()
		savingComment.value = false
		errorComment.value = false
	}

	return {
		// State
		savingComment,
		errorComment,

		// Methods
		submitResponse,
		saveComment,
		reset,
	}
}
