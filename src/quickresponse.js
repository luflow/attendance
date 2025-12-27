/**
 * Quick Response Vue app entry point
 * Used for the public quick response confirmation page
 */

import { createApp } from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import QuickResponse from './views/QuickResponse.vue'

const app = createApp(QuickResponse)

// Make translation functions available globally
app.config.globalProperties.t = translate
app.config.globalProperties.n = translatePlural

app.mount('#attendance-quick-response')
