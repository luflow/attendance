import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { formatGroupLabel } from './groups.js'

/**
 * @param {string} type 'user', 'group' or 'team'.
 * @param {{id: string, label: string, isGuest?: boolean}} ref What the server knows about it.
 * @return {object} The entry as the audience pickers hold it.
 */
export function toAudienceItem(type, ref) {
	return {
		id: `${type}:${ref.id}`,
		value: ref.id,
		label: type === 'group' ? formatGroupLabel(ref.id, ref.label) : ref.label,
		type,
		isGuest: !!ref.isGuest,
	}
}

/**
 * @param {?object} source An appointment or a category template, with its enriched visibility lists.
 * @return {Array<object>} The audience as picker items; empty without a source.
 */
export function toAudienceItems(source) {
	const items = (type, refs) => (refs ?? []).map((ref) => toAudienceItem(type, ref))
	return [
		...items('user', source?.visibleUsers),
		...items('group', source?.visibleGroups),
		...items('team', source?.visibleTeams),
	]
}

/**
 * @param {Array<object>} items Picker items.
 * @return {{visibleUsers: string[], visibleGroups: string[], visibleTeams: string[]}} The ID lists the API takes.
 */
export function toAudienceIds(items) {
	const ids = (type) => items.filter((item) => item?.type === type).map((item) => item.value)
	return { visibleUsers: ids('user'), visibleGroups: ids('group'), visibleTeams: ids('team') }
}

/**
 * Query the directory endpoint and merge the results with the already
 * selected items (selected entries always stay available in the dropdown).
 *
 * @param {string} query Search term
 * @param {Array} selectedItems Currently selected select items
 * @param {object} options Options
 * @param {boolean} options.usersOnly Restrict results to non-guest users (organizer picker)
 * @return {Promise<Array>} Merged select items
 */
export async function searchDirectory(query, selectedItems, { usersOnly = false } = {}) {
	const response = await axios.get(
		generateUrl('/apps/attendance/api/search/users-groups-teams'),
		{ params: { search: query } },
	)

	const newResults = response.data
		.filter((item) => !usersOnly || (item.type === 'user' && !item.isGuest))
		.map((item) => toAudienceItem(item.type, item))

	const merged = [...selectedItems]
	for (const result of newResults) {
		if (!selectedItems.some((item) => item.id === result.id)) {
			merged.push(result)
		}
	}
	return merged
}
