import { showSuccess, showError } from '@nextcloud/dialogs'

/**
 * Copy text to the clipboard and show a toast.
 *
 * @param {string} text - The text to copy.
 * @param {object} [options]
 * @param {string} [options.successMessage] - Toast shown on success. Omit to skip.
 * @param {string} [options.errorMessage] - Toast shown on failure. Omit to skip.
 * @return {Promise<boolean>} Whether the copy succeeded.
 */
export async function copyToClipboard(text, { successMessage, errorMessage } = {}) {
	try {
		await navigator.clipboard.writeText(text)
		if (successMessage) {
			showSuccess(successMessage)
		}
		return true
	} catch (err) {
		console.error('Failed to copy to clipboard:', err)
		if (errorMessage) {
			showError(errorMessage)
		}
		return false
	}
}
