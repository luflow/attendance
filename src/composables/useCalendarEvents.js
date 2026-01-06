import { ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

/**
 * Composable for fetching calendars and events from Nextcloud Calendar
 */
export function useCalendarEvents() {
	const calendars = ref([])
	const events = ref([])
	const loadingCalendars = ref(false)
	const loadingEvents = ref(false)
	const error = ref(null)

	/**
	 * Load all calendars for the current user
	 */
	const loadCalendars = async () => {
		loadingCalendars.value = true
		error.value = null

		try {
			const url = generateUrl('/apps/attendance/api/calendar/calendars')
			const response = await axios.get(url)
			calendars.value = response.data || []
		} catch (err) {
			console.error('Failed to load calendars:', err)
			error.value = err
			calendars.value = []
			showError(t('attendance', 'Failed to load calendars'))
		} finally {
			loadingCalendars.value = false
		}
	}

	/**
	 * Load events from a specific calendar
	 * @param {string} calendarUri - The URI of the calendar to load events from
	 * @param {number} days - Number of days to look ahead (default 60)
	 */
	const loadEvents = async (calendarUri, days = 60) => {
		loadingEvents.value = true
		error.value = null
		events.value = []

		try {
			const url = generateUrl('/apps/attendance/api/calendar/events')
			const response = await axios.get(url, {
				params: {
					calendarUri,
					days,
				},
			})
			events.value = response.data.events || []
		} catch (err) {
			console.error('Failed to load calendar events:', err)
			error.value = err
			events.value = []
			showError(t('attendance', 'Failed to load calendar events'))
		} finally {
			loadingEvents.value = false
		}
	}

	/**
	 * Clear events (useful when switching calendars or closing picker)
	 */
	const clearEvents = () => {
		events.value = []
	}

	/**
	 * Reset all state
	 */
	const reset = () => {
		calendars.value = []
		events.value = []
		loadingCalendars.value = false
		loadingEvents.value = false
		error.value = null
	}

	return {
		calendars,
		events,
		loadingCalendars,
		loadingEvents,
		error,
		loadCalendars,
		loadEvents,
		clearEvents,
		reset,
	}
}
