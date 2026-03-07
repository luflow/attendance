/**
 * Self Check-in Vue app entry point
 * Used for the web fallback page when users scan NFC sticker without the app
 */

import { createApp } from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import SelfCheckin from './views/SelfCheckin.vue'

const app = createApp(SelfCheckin)

// Make translation functions available globally
app.config.globalProperties.t = translate
app.config.globalProperties.n = translatePlural

app.mount('#attendance-self-checkin')
