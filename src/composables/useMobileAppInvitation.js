import { computed, reactive } from 'vue'
import { usePermissions } from './usePermissions.js'

// Suffixed so the member invitation reaches everyone who dismissed one of the
// earlier banners. Bump again on the next relaunch.
const DISMISS_KEY = 'attendance:mobile-app-banner-dismissed:join'
const CELEBRATION_KEY = 'attendance:mobile-app-invitation:celebration'
const CELEBRATION_INTERVAL_MS = 30 * 24 * 60 * 60 * 1000

function readStorage(key) {
	try {
		return window.localStorage.getItem(key)
	} catch {
		return null
	}
}

function writeStorage(key, value) {
	try {
		window.localStorage.setItem(key, value)
	} catch {
		// localStorage may be unavailable (private mode, quota); dismissal is transient in that case
	}
}

const state = reactive({
	bannerDismissed: readStorage(DISMISS_KEY) === '1',
	// Once shown, the celebration invitation stays for the rest of this page load.
	celebrationShownAt: null,
})

/**
 * The invitation to the mobile app: a banner until dismissed, then a quieter
 * reminder at the "Hurray!" banner that returns every 30 days until dismissed too.
 */
export function useMobileAppInvitation() {
	const { config } = usePermissions()

	const offered = computed(() => config.mobileAppBannerEnabled && !config.hasPushDevice)
	const bannerVisible = computed(() => offered.value && !state.bannerDismissed)

	function dismissBanner() {
		state.bannerDismissed = true
		writeStorage(DISMISS_KEY, '1')
	}

	/** Decides at a celebration whether the invitation appears, and counts it as shown. */
	function takeCelebrationTurn() {
		if (!offered.value || !state.bannerDismissed) {
			return false
		}
		if (state.celebrationShownAt !== null) {
			return true
		}
		const stored = readStorage(CELEBRATION_KEY)
		if (stored === 'dismissed') {
			return false
		}
		const lastShown = Number(stored)
		if (lastShown > 0 && Date.now() - lastShown < CELEBRATION_INTERVAL_MS) {
			return false
		}
		state.celebrationShownAt = Date.now()
		writeStorage(CELEBRATION_KEY, String(state.celebrationShownAt))
		return true
	}

	function dismissCelebration() {
		writeStorage(CELEBRATION_KEY, 'dismissed')
	}

	return {
		bannerVisible,
		dismissBanner,
		takeCelebrationTurn,
		dismissCelebration,
	}
}
