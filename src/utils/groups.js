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

/**
 * Turn stored group ids into the objects NcSelect binds to, preserving
 * database order and dropping ids whose group no longer exists.
 *
 * @param {Array<string>} ids - Stored group ids.
 * @param {Array<{id: string, displayName: string}>} groups - Available groups.
 * @return {Array<{id: string, displayName: string}>} The matching group objects.
 */
export function toGroupObjects(ids, groups) {
	const groupsById = new Map(groups.map((group) => [group.id, group]))
	return (ids || []).map((id) => groupsById.get(id)).filter((group) => group !== undefined)
}
