import { translate as t } from '@nextcloud/l10n'

// Mirrors GuestService::GUESTS_SYSTEM_GROUP. Rendering the raw id in user-
// facing UI looks like a misconfiguration to admins.
export const GUESTS_SYSTEM_GROUP = 'guest_app'

/**
 * Map a group id to a UI label, translating known system groups.
 *
 * @param {string} groupId - The Nextcloud group id to label.
 * @param {string|null} fallback - Label to use for groups without a translation.
 *   Defaults to the group id itself.
 * @return {string} The label to render.
 */
export function formatGroupLabel(groupId, fallback = null) {
	if (groupId === GUESTS_SYSTEM_GROUP) {
		return t('attendance', 'Guests')
	}
	return fallback ?? groupId
}
