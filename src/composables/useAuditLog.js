import { ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Fetches paginated audit events for a single appointment. Pagination state
 * (items, total, hasMore) is owned by the composable — pass `append: true`
 * to extend the existing list instead of replacing it.
 */
export function useAuditLog() {
	const items = ref([])
	const total = ref(0)
	const hasMore = ref(false)
	const loading = ref(false)
	const error = ref(null)

	const load = async (appointmentId, { limit = 50, offset = 0, append = false } = {}) => {
		loading.value = true
		error.value = null
		try {
			const response = await axios.get(
				generateUrl('/apps/attendance/api/appointments/{id}/audit', { id: appointmentId }),
				{ params: { limit, offset } },
			)
			const newItems = response.data.items ?? []
			items.value = append ? [...items.value, ...newItems] : newItems
			total.value = response.data.total ?? 0
			hasMore.value = response.data.hasMore === true
		} catch (e) {
			// 412 = audit log disabled via admin setting; treat as empty (capability
			// flag should have prevented the request, but stay resilient if not).
			if (e?.response?.status === 412) {
				items.value = []
				total.value = 0
				hasMore.value = false
			} else {
				error.value = e
			}
		} finally {
			loading.value = false
		}
	}

	return {
		items,
		total,
		hasMore,
		loading,
		error,
		load,
	}
}
