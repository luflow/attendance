import { showConfirmation } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { onBeforeUnmount, onMounted } from 'vue'

// Reports whether the form on screen has unsaved changes; null while none is.
let isDirty = null
let asking = false

/**
 * Whether leaving the current view would throw unsaved changes away.
 *
 * @return {boolean}
 */
export function hasUnsavedChanges() {
	return isDirty?.() === true
}

/**
 * Ask before unsaved changes are thrown away.
 *
 * @return {Promise<boolean>} True when leaving is fine.
 */
export async function confirmLeave() {
	if (!hasUnsavedChanges()) return true
	// A second attempt while the question is open is settled by the first.
	if (asking) return false
	asking = true
	try {
		return await showConfirmation({
			name: t('attendance', 'Unsaved changes'),
			text: t('attendance', 'Your changes have not been saved. Do you want to discard them?'),
			labelConfirm: t('attendance', 'Discard changes'),
			labelReject: t('attendance', 'Keep editing'),
			severity: 'warning',
		})
	} catch {
		// Closed without an answer, which showConfirmation reports by rejecting.
		return false
	} finally {
		asking = false
	}
}

/**
 * Guard the calling form against being left with unsaved changes: in-app
 * navigation asks through confirmLeave(), closing or reloading the tab through
 * the browser's own prompt.
 *
 * @param {() => boolean} dirty Whether the form has unsaved changes right now.
 */
export function useUnsavedChanges(dirty) {
	function onBeforeUnload(event) {
		if (!dirty()) return
		event.preventDefault()
		// Chrome and Edge before 119 only ask when returnValue is set.
		event.returnValue = true
	}

	onMounted(() => {
		isDirty = dirty
		window.addEventListener('beforeunload', onBeforeUnload)
	})

	onBeforeUnmount(() => {
		isDirty = null
		window.removeEventListener('beforeunload', onBeforeUnload)
	})
}
