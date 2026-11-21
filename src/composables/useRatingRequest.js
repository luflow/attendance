import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { computed, reactive } from 'vue'
import { usePermissions } from './usePermissions.js'

const state = reactive({ shown: false })

/** The request to rate the app: once per person, who is eligible decides the server. */
export function useRatingRequest() {
	const { config } = usePermissions()

	const due = computed(() => config.ratingPromptDue && !state.shown)

	// Counts as asked the moment it shows, whatever the person then does.
	function markShown() {
		if (state.shown) {
			return
		}
		state.shown = true
		axios.post(generateUrl('/apps/attendance/api/user/rating-prompt'))
			.catch((error) => console.error('Failed to note the rating request:', error))
	}

	return { due, markShown }
}
