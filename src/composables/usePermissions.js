import { reactive, readonly } from 'vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

// Shared state - will be initialized only once
const state = reactive({
	permissions: {
		canManageAppointments: false,
		canCheckin: false,
		canSeeResponseOverview: false,
		canSeeComments: false,
	},
	loading: false,
	loaded: false,
	error: null,
})

/**
 * Composable for managing user permissions
 * Loads permissions only once and shares them across all components
 */
export function usePermissions() {
	/**
	 * Load permissions from the server
	 * Will only make the API call once unless force=true
	 */
	const loadPermissions = async (force = false) => {
		// Skip if already loaded and not forcing reload
		if (state.loaded && !force) {
			return state.permissions
		}

		// Skip if already loading
		if (state.loading) {
			return state.permissions
		}

		state.loading = true
		state.error = null

		try {
			const url = generateUrl('/apps/attendance/api/user/permissions')
			const response = await axios.get(url)
			
			// Update all permission flags
			state.permissions.canManageAppointments = response.data.canManageAppointments || false
			state.permissions.canCheckin = response.data.canCheckin || false
			state.permissions.canSeeResponseOverview = response.data.canSeeResponseOverview || false
			state.permissions.canSeeComments = response.data.canSeeComments || false
			
			state.loaded = true
		} catch (error) {
			console.error('Failed to load permissions:', error)
			state.error = error
			
			// Set all permissions to false on error
			state.permissions.canManageAppointments = false
			state.permissions.canCheckin = false
			state.permissions.canSeeResponseOverview = false
			state.permissions.canSeeComments = false
		} finally {
			state.loading = false
		}

		return state.permissions
	}

	/**
	 * Reset the permissions state
	 * Useful for testing or when user context changes
	 */
	const resetPermissions = () => {
		state.loaded = false
		state.loading = false
		state.error = null
		state.permissions.canManageAppointments = false
		state.permissions.canCheckin = false
		state.permissions.canSeeResponseOverview = false
		state.permissions.canSeeComments = false
	}

	return {
		permissions: readonly(state.permissions),
		loading: readonly(state.loading),
		loaded: readonly(state.loaded),
		error: readonly(state.error),
		loadPermissions,
		resetPermissions,
	}
}
