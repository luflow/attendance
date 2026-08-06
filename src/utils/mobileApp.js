import { translate as t } from '@nextcloud/l10n'
import AppleIcon from 'vue-material-design-icons/Apple.vue'
import GoogleIcon from 'vue-material-design-icons/Google.vue'

export const APPLE_STORE_URL = 'https://apps.apple.com/app/id6759988681'
export const GOOGLE_STORE_URL = 'https://play.google.com/store/apps/details?id=de.krautnerds.attendance'

/** Every surface that links the apps renders this list. */
export const MOBILE_APP_STORES = [
	{ id: 'apple', icon: AppleIcon, url: APPLE_STORE_URL, label: t('attendance', 'App Store'), longLabel: t('attendance', 'App Store (iOS)') },
	{ id: 'google', icon: GoogleIcon, url: GOOGLE_STORE_URL, label: t('attendance', 'Google Play'), longLabel: t('attendance', 'Google Play (Android)') },
]
