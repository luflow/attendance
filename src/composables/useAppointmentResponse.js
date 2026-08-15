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

	const savingComment = ref(false)

	/**
	 * The one transport both save paths share: POST to the respond endpoint
	 * and throw on a non-2xx status.
	 *
	 * @param {number} appointmentId - The appointment ID
	 * @param {string|null} response - The response (yes, no, maybe) or null to withdraw
	 * @param {string} comment - The comment text
	 * @return {Promise<object>} The axios response
	 */
	const postRespond = async (appointmentId, response, comment) => {
		const url = generateUrl('/apps/attendance/api/appointments/{id}/respond', { id: appointmentId })
		const axiosResponse = await axios.post(url, {
			response,
			comment,
		})

		if (axiosResponse.status < 200 || axiosResponse.status >= 300) {
			throw new Error(`API returned status ${axiosResponse.status}`)
		}

		return axiosResponse
	}

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
			const axiosResponse = await postRespond(appointmentId, response, comment)

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
	 * Save a comment. Only ever called from an explicit user action (save
	 * button, Enter) — comments are never sent while typing.
	 *
	 * @param {number} appointmentId - The appointment ID
	 * @param {string} currentResponse - The current response value
	 * @param {string} commentText - The comment text
	 * @return {Promise<boolean>} Whether the save succeeded
	 */
	const saveComment = async (appointmentId, currentResponse, commentText) => {
		if (!currentResponse) return false

		const t = window.t || ((app, text) => text)

		savingComment.value = true

		try {
			const axiosResponse = await postRespond(appointmentId, currentResponse, commentText)

			showSuccess(t('attendance', 'Comment updated'))

			if (onSuccess) {
				onSuccess(axiosResponse.data)
			}

			return true
		} catch (error) {
			console.error('Failed to save comment:', error)
			showError(t('attendance', 'Comment could not be saved'))

			if (onError) {
				onError(error)
			}

			return false
		} finally {
			savingComment.value = false
		}
	}

	return {
		// State
		savingComment,

		// Methods
		submitResponse,
		saveComment,
	}
}
