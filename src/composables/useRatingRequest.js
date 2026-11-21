import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { usePermissions } from './usePermissions.js'

let shown = false

/** The request to rate the app: once per person, who is eligible decides the server. */
export function useRatingRequest() {
	const { config } = usePermissions()

	/** Decides at a celebration whether the request appears; it counts as asked the moment it does. */
	function takeCelebrationTurn() {
		if (shown || !config.ratingPromptDue) {
			return false
		}
		shown = true
		axios.post(generateUrl('/apps/attendance/api/user/rating-prompt'))
			.catch((error) => console.error('Failed to note the rating request:', error))
		return true
	}

	return { takeCelebrationTurn }
}
