import { reactive, readonly } from 'vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

const state = reactive({
	permissions: {
		canManageAppointments: false,
		canCheckin: false,
		canSeeResponseOverview: false,
		canSeeComments: false,
		canSelfCheckin: false,
	},
	capabilities: {
		calendarAvailable: false,
		calendarSyncEnabled: false,
		teamsAvailable: false,
		calendarSyncAvailable: false,
		notificationsAppEnabled: false,
		guestInvitation: false,
	},
	config: {
		displayOrder: 'name_first',
		mobileAppBannerEnabled: true,
		hasPushDevice: false,
	},
	loading: false,
	loaded: false,
	error: null,
})

/**
 * Composable for managing user permissions, capabilities, and config
 * Loads data only once and shares it across all components
 */
export function usePermissions() {
	/**
	 * Load permissions, capabilities, and config from the server
	 * Will only make the API calls once unless force=true
	 * @param {boolean} force - Force reload even if already loaded
	 */
	const loadPermissions = async (force = false) => {
		if (state.loaded && !force) {
			return state.permissions
		}

		if (state.loading) {
			return state.permissions
		}

		state.loading = true
		state.error = null

		try {
			const [permissionsRes, capabilitiesRes, configRes] = await Promise.all([
				axios.get(generateUrl('/apps/attendance/api/user/permissions')),
				axios.get(generateUrl('/apps/attendance/api/capabilities')),
				axios.get(generateUrl('/apps/attendance/api/user/config')),
			])

			state.permissions.canManageAppointments = permissionsRes.data.canManageAppointments || false
			state.permissions.canCheckin = permissionsRes.data.canCheckin || false
			state.permissions.canSeeResponseOverview = permissionsRes.data.canSeeResponseOverview || false
			state.permissions.canSeeComments = permissionsRes.data.canSeeComments || false
			state.permissions.canSelfCheckin = permissionsRes.data.canSelfCheckin || false

			state.capabilities.calendarAvailable = capabilitiesRes.data.calendarAvailable || false
			state.capabilities.calendarSyncEnabled = capabilitiesRes.data.calendarSyncEnabled || false
			state.capabilities.teamsAvailable = capabilitiesRes.data.teamsAvailable || false
			state.capabilities.calendarSyncAvailable = capabilitiesRes.data.calendarSyncAvailable || false
			state.capabilities.notificationsAppEnabled = capabilitiesRes.data.notificationsAppEnabled !== false
			state.capabilities.guestInvitation = capabilitiesRes.data.guestInvitation === true

			state.config.displayOrder = configRes.data.displayOrder || 'name_first'
			state.config.mobileAppBannerEnabled = configRes.data.mobileAppBannerEnabled !== false
			state.config.hasPushDevice = configRes.data.hasPushDevice === true

			state.loaded = true
		} catch (error) {
			console.error('Failed to load permissions:', error)
			state.error = error

			state.permissions.canManageAppointments = false
			state.permissions.canCheckin = false
			state.permissions.canSeeResponseOverview = false
			state.permissions.canSeeComments = false
			state.permissions.canSelfCheckin = false
			state.capabilities.calendarAvailable = false
			state.capabilities.calendarSyncEnabled = false
			state.capabilities.teamsAvailable = false
			state.capabilities.calendarSyncAvailable = false
			state.capabilities.notificationsAppEnabled = false
			state.config.displayOrder = 'name_first'
			state.config.mobileAppBannerEnabled = true
			state.config.hasPushDevice = false
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
		state.permissions.canSelfCheckin = false
	}

	return {
		permissions: readonly(state.permissions),
		capabilities: readonly(state.capabilities),
		config: readonly(state.config),
		loading: readonly(state.loading),
		loaded: readonly(state.loaded),
		error: readonly(state.error),
		loadPermissions,
		resetPermissions,
	}
}
