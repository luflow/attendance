import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { ref } from 'vue'

/**
 * Posts an export and takes the user to the file it produced.
 *
 * Both export dialogs differ only in the filter they assemble; everything after
 * the POST — the toast, the jump to the Attendance folder, the error fallback —
 * is the same, so it lives here rather than in each of them.
 *
 * @param {string} failureMessage - Shown when the server sends no error of its own.
 * @return {{exporting: import('vue').Ref<boolean>, runExport: (payload: object) => Promise<boolean>}}
 */
export function useExportRequest(failureMessage) {
	const exporting = ref(false)

	/**
	 * @param {object} payload - Filter and column options for the export endpoint.
	 * @return {Promise<boolean>} Whether the export was created.
	 */
	async function runExport(payload) {
		exporting.value = true

		try {
			const response = await axios.post(generateUrl('/apps/attendance/api/export'), payload)

			showSuccess(t('attendance', 'Export created: {filename}', {
				filename: response.data.filename,
			}))

			window.location.href = generateUrl('/apps/files/?dir=/Attendance')
			return true
		} catch (error) {
			console.error('Failed to export appointments:', error)
			showError(error.response?.data?.error || failureMessage)
			return false
		} finally {
			exporting.value = false
		}
	}

	return { exporting, runExport }
}
