import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { reactive, readonly } from 'vue'

// Every reset path derives from this, so a flag added to the shape cannot be
// forgotten in one of them. The success path stays explicit: the fields have
// genuinely different fallbacks (notificationsAppEnabled defaults to on).
const DEFAULTS = {
	permissions: {
		canManageAppointments: false,
		canCreateAppointments: false,
		canCheckin: false,
		canSeeResponseOverview: false,
		canSeeComments: false,
		canSelfCheckin: false,
		canRespondForOthers: false,
		canSeeStatistics: false,
	},
	capabilities: {
		calendarAvailable: false,
		calendarSyncEnabled: false,
		teamsAvailable: false,
		calendarSyncAvailable: false,
		notificationsAppEnabled: false,
		guestInvitation: false,
		auditLog: false,
		cancelling: false,
		bookingEnabled: false,
		scheduledFilter: false,
		organizers: false,
		locationsAvailable: false,
		categoriesAvailable: false,
		statisticsAvailable: false,
	},
	config: {
		displayOrder: 'name_first',
		mobileAppBannerEnabled: true,
		hasPushDevice: false,
		onboarding: { setupPrompt: false, firstAppointmentPrompt: false },
	},
}

const state = reactive({
	permissions: { ...DEFAULTS.permissions },
	capabilities: { ...DEFAULTS.capabilities },
	config: { ...DEFAULTS.config, onboarding: { ...DEFAULTS.config.onboarding } },
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
	 *
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
			state.permissions.canCreateAppointments = permissionsRes.data.canCreateAppointments || false
			state.permissions.canCheckin = permissionsRes.data.canCheckin || false
			state.permissions.canSeeResponseOverview = permissionsRes.data.canSeeResponseOverview || false
			state.permissions.canSeeComments = permissionsRes.data.canSeeComments || false
			state.permissions.canSelfCheckin = permissionsRes.data.canSelfCheckin || false
			state.permissions.canRespondForOthers = permissionsRes.data.canRespondForOthers === true
			state.permissions.canSeeStatistics = permissionsRes.data.canSeeStatistics === true

			state.capabilities.calendarAvailable = capabilitiesRes.data.calendarAvailable || false
			state.capabilities.calendarSyncEnabled = capabilitiesRes.data.calendarSyncEnabled || false
			state.capabilities.teamsAvailable = capabilitiesRes.data.teamsAvailable || false
			state.capabilities.calendarSyncAvailable = capabilitiesRes.data.calendarSyncAvailable || false
			state.capabilities.notificationsAppEnabled = capabilitiesRes.data.notificationsAppEnabled !== false
			state.capabilities.guestInvitation = capabilitiesRes.data.guestInvitation === true
			state.capabilities.auditLog = capabilitiesRes.data.auditLog === true
			state.capabilities.cancelling = capabilitiesRes.data.cancelling === true
			state.capabilities.bookingEnabled = capabilitiesRes.data.bookingEnabled === true
			state.capabilities.scheduledFilter = capabilitiesRes.data.scheduledFilter === true
			state.capabilities.organizers = capabilitiesRes.data.organizers === true
			state.capabilities.locationsAvailable = capabilitiesRes.data.locationsAvailable === true
			state.capabilities.categoriesAvailable = capabilitiesRes.data.categoriesAvailable === true
			state.capabilities.statisticsAvailable = capabilitiesRes.data.statisticsAvailable === true

			state.config.displayOrder = configRes.data.displayOrder || 'name_first'
			state.config.mobileAppBannerEnabled = configRes.data.mobileAppBannerEnabled !== false
			state.config.hasPushDevice = configRes.data.hasPushDevice === true
			// Absent on older servers, which simply means no prompt.
			state.config.onboarding = {
				setupPrompt: configRes.data.onboarding?.setupPrompt === true,
				firstAppointmentPrompt: configRes.data.onboarding?.firstAppointmentPrompt === true,
			}

			state.loaded = true
		} catch (error) {
			console.error('Failed to load permissions:', error)
			state.error = error

			Object.assign(state.permissions, DEFAULTS.permissions)
			Object.assign(state.capabilities, DEFAULTS.capabilities)
			Object.assign(state.config, DEFAULTS.config, { onboarding: { ...DEFAULTS.config.onboarding } })
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
		Object.assign(state.permissions, DEFAULTS.permissions)
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
