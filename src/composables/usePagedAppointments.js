import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { computed, ref } from 'vue'

export const PAGE_SIZE = 20
// The server's cap on `limit`; a reload of a long list goes in rounds of this.
const MAX_PAGE_SIZE = 100

/**
 * Pages through the appointment list. The server filters and counts, so what
 * is held here is only ever the part of the list scrolled into so far.
 *
 * @param {() => object} params Scope and filters of the list, read on every request.
 * @return {object} List state plus `reload` and `loadMore`.
 */
export function usePagedAppointments(params) {
	const appointments = ref([])
	const total = ref(0)
	const facets = ref({ locations: [], categoryIds: [], roles: [] })
	const loading = ref(true)
	const loadingMore = ref(false)
	const hasMore = computed(() => appointments.value.length < total.value)
	// True while any request for the list is out, the silent ones included.
	const pending = ref(0)
	const busy = computed(() => pending.value > 0)

	// Bumped by every reload, so an answer to an older request is dropped
	// instead of overwriting the list the newer one produced.
	let generation = 0

	async function fetchPage(offset, limit) {
		const response = await axios.get(generateUrl('/apps/attendance/api/appointments'), {
			params: { ...params(), limit, offset },
		})
		return response.data
	}

	/**
	 * Load the list afresh from its start.
	 *
	 * @param {object} [options] How to reload.
	 * @param {boolean} [options.silent] Keep showing the current list instead of a spinner.
	 * @param {boolean} [options.keepLoaded] Reload as many appointments as are shown, so the scroll position survives.
	 */
	async function reload({ silent = false, keepLoaded = false } = {}) {
		const current = ++generation
		if (!silent) {
			loading.value = true
		}
		pending.value++
		try {
			const wanted = keepLoaded ? Math.max(PAGE_SIZE, appointments.value.length) : PAGE_SIZE
			const loaded = []
			let page
			do {
				page = await fetchPage(loaded.length, Math.min(MAX_PAGE_SIZE, wanted - loaded.length))
				if (current !== generation) return
				loaded.push(...page.appointments)
			} while (page.appointments.length > 0 && loaded.length < Math.min(wanted, page.total))

			appointments.value = loaded
			total.value = page.total
			facets.value = page.facets
		} catch (error) {
			console.error('Failed to load appointments:', error)
		} finally {
			pending.value--
			if (current === generation) {
				loading.value = false
			}
		}
	}

	async function loadMore() {
		if (loading.value || loadingMore.value || !hasMore.value) return
		const current = generation
		loadingMore.value = true
		pending.value++
		try {
			const page = await fetchPage(appointments.value.length, PAGE_SIZE)
			if (current !== generation) return

			// The list may have shifted since the last page (somebody created or
			// cancelled an appointment), which can hand out a row twice.
			const known = new Set(appointments.value.map((appointment) => appointment.id))
			const added = page.appointments.filter((appointment) => !known.has(appointment.id))
			appointments.value = [...appointments.value, ...added]
			// Nothing new means paging has run dry, whatever the count claims.
			total.value = added.length > 0 ? page.total : appointments.value.length
			facets.value = page.facets
		} catch (error) {
			console.error('Failed to load more appointments:', error)
		} finally {
			pending.value--
			loadingMore.value = false
		}
	}

	return { appointments, total, facets, loading, loadingMore, busy, hasMore, reload, loadMore }
}
