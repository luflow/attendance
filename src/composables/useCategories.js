import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { reactive, readonly } from 'vue'

const state = reactive({
	items: [],
	loading: false,
	loaded: false,
})

/**
 * Composable for the admin-managed category list. Loads once and shares the
 * result across every card/filter/form that needs to resolve a categoryId to
 * its name.
 */
export function useCategories() {
	const loadCategories = async (force = false) => {
		if ((state.loaded || state.loading) && !force) {
			return state.items
		}

		state.loading = true
		try {
			const response = await axios.get(generateUrl('/apps/attendance/api/categories'))
			// Mutate in place rather than reassigning — callers destructure
			// `categories` once and hold that array reference; replacing
			// state.items with a new array would orphan them from future updates.
			state.items.splice(0, state.items.length, ...response.data)
			state.loaded = true
		} catch (error) {
			console.error('Failed to load categories:', error)
			state.items.splice(0, state.items.length)
		} finally {
			state.loading = false
		}

		return state.items
	}

	/**
	 * @param {?number} categoryId The appointment's category id.
	 * @return {?object} The category ({ id, name, icon }), or null when unset
	 *         or the category has since been deleted.
	 */
	const getCategory = (categoryId) => {
		if (categoryId === null || categoryId === undefined) {
			return null
		}
		return state.items.find((category) => category.id === categoryId) ?? null
	}

	return {
		categories: readonly(state.items),
		loading: readonly(state.loading),
		loaded: readonly(state.loaded),
		loadCategories,
		getCategory,
	}
}
