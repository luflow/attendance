import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, ref, toValue } from 'vue'
import { copyToClipboard as copyTextToClipboard } from '../utils/clipboard.js'

/**
 * Composable for managing iCal feed functionality
 *
 * @param {import('vue').MaybeRefOrGetter<'personal'|'organizer'>} kind Which of the user's feeds
 */
export function useIcalFeed(kind = 'personal') {
	const feedUrl = ref('')
	const createdAt = ref(null)
	const lastUsedAt = ref(null)
	// Whether this user is offered the organizer feed at all; the server says so with every token.
	const organizerAvailable = ref(false)
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
			const response = await axios.get(url, { params: { kind: toValue(kind) } })

			feedUrl.value = response.data.feedUrl || ''
			createdAt.value = response.data.createdAt || null
			lastUsedAt.value = response.data.lastUsedAt || null
			organizerAvailable.value = response.data.organizerAvailable === true
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
			const response = await axios.post(url, { kind: toValue(kind) })

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
		organizerAvailable,
		loading,
		error,
		loadToken,
		regenerateToken,
		copyToClipboard,
	}
}
