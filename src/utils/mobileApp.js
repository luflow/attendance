import { translate as t } from '@nextcloud/l10n'
import AppleIcon from 'vue-material-design-icons/Apple.vue'
import GoogleIcon from 'vue-material-design-icons/Google.vue'

export const APPLE_STORE_URL = 'https://apps.apple.com/app/id6759988681'
export const GOOGLE_STORE_URL = 'https://play.google.com/store/apps/details?id=de.krautnerds.attendance'
// The website picks the visitor's language and keeps the anchor.
export const INVOICE_ORDER_URL = 'https://anwesenheit.app/mobile#invoice'

const JOIN_PAGE_URL = 'https://anwesenheit.app/mobile'

/**
 * The link that brings someone into the app with this server preset; the
 * website picks the store. Matches inviteLinkFor() in the mobile app.
 *
 * @param {string} server Base URL as the mobile app keeps it: origin plus webroot, no trailing slash
 * @return {string}
 */
export function joinLinkFor(server) {
	return JOIN_PAGE_URL + '?server=' + encodeURIComponent(server)
}

/** Every surface that links the apps renders this list. */
export const MOBILE_APP_STORES = [
	{ id: 'apple', icon: AppleIcon, url: APPLE_STORE_URL, label: t('attendance', 'App Store'), longLabel: t('attendance', 'App Store (iOS)') },
	{ id: 'google', icon: GoogleIcon, url: GOOGLE_STORE_URL, label: t('attendance', 'Google Play'), longLabel: t('attendance', 'Google Play (Android)') },
]
