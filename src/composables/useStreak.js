import { ref } from 'vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

const streak = ref(null)
const loading = ref(false)
const loaded = ref(false)

/**
 * Composable for managing user streak data.
 * Loads streak only once and shares across all components.
 */
export function useStreak() {
	const loadStreak = async (force = false) => {
		if (loaded.value && !force) {
			return streak.value
		}

		if (loading.value) {
			return streak.value
		}

		loading.value = true

		try {
			const url = generateUrl('/apps/attendance/api/user/streak')
			const response = await axios.get(url)
			streak.value = response.data
			loaded.value = true
		} catch (error) {
			console.error('Failed to load streak:', error)
			streak.value = null
		} finally {
			loading.value = false
		}

		return streak.value
	}

	return {
		streak,
		loading,
		loaded,
		loadStreak,
	}
}
