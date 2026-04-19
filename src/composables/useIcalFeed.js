import { ref, computed } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { copyToClipboard as copyTextToClipboard } from '../utils/clipboard.js'

/**
 * Composable for managing iCal feed functionality
 */
export function useIcalFeed() {
	const feedUrl = ref('')
	const createdAt = ref(null)
	const lastUsedAt = ref(null)
	const loading = ref(true) // Start true to avoid flash of empty state
	const error = ref(null)

	/**
	 * Load the current iCal token and feed URL
	 */
	const loadToken = async () => {
		loading.value = true
		error.value = null

		try {
			const url = generateUrl('/apps/attendance/api/ical/token')
			const response = await axios.get(url)

			feedUrl.value = response.data.feedUrl || ''
			createdAt.value = response.data.createdAt || null
			lastUsedAt.value = response.data.lastUsedAt || null
		} catch (err) {
			console.error('Failed to load iCal token:', err)
			error.value = err
			feedUrl.value = ''
			createdAt.value = null
			lastUsedAt.value = null
		} finally {
			loading.value = false
		}
	}

	/**
	 * Regenerate the iCal token (invalidates old URL)
	 */
	const regenerateToken = async () => {
		loading.value = true
		error.value = null

		try {
			const url = generateUrl('/apps/attendance/api/ical/token/regenerate')
			const response = await axios.post(url)

			feedUrl.value = response.data.feedUrl || ''
			createdAt.value = response.data.createdAt || null
			lastUsedAt.value = response.data.lastUsedAt || null

			showSuccess(t('attendance', 'Subscription URL regenerated'))
		} catch (err) {
			console.error('Failed to regenerate iCal token:', err)
			error.value = err
			showError(t('attendance', 'Failed to regenerate subscription URL'))
		} finally {
			loading.value = false
		}
	}

	const webcalUrl = computed(() => {
		if (!feedUrl.value) return ''
		return feedUrl.value.replace(/^https?:\/\//, 'webcal://')
	})

	const googleCalendarUrl = computed(() => {
		if (!webcalUrl.value) return ''
		return `https://calendar.google.com/calendar/r?cid=${encodeURIComponent(webcalUrl.value)}`
	})

	const copyToClipboard = async () => {
		if (!feedUrl.value) {
			return
		}
		await copyTextToClipboard(feedUrl.value, {
			successMessage: t('attendance', 'URL copied to clipboard'),
			errorMessage: t('attendance', 'Failed to copy URL'),
		})
	}

	return {
		feedUrl,
		webcalUrl,
		googleCalendarUrl,
		createdAt,
		lastUsedAt,
		loading,
		error,
		loadToken,
		regenerateToken,
		copyToClipboard,
	}
}
