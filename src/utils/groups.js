import { translate as t } from '@nextcloud/l10n'

// Mirrors GuestService::GUESTS_SYSTEM_GROUP. The Guests app provisions every
// external invitee into this hidden group; rendering its raw id ("guest_app")
// in a user-facing label looks like a misconfiguration to admins.
export const GUESTS_SYSTEM_GROUP = 'guest_app'

/**
 * Map a raw group id to a label suitable for the UI.
 *
 * Currently only special-cases the Guests app's system group so it renders
 * as a translatable "Guests" string instead of the raw id. Every other
 * group falls through to its existing display name (or id if none).
 *
 * @param {string} groupId The Nextcloud group identifier.
 * @param {string|null} [fallback] The display name the backend already
 *        provided for this group (e.g. group.getDisplayName()).
 * @returns {string} The label to render.
 */
export function formatGroupLabel(groupId, fallback = null) {
	if (groupId === GUESTS_SYSTEM_GROUP) {
		return t('attendance', 'Guests')
	}
	return fallback ?? groupId
}
