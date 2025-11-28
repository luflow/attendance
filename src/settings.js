/**
 * Admin settings Vue app entry point
 */

import { createApp } from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import AdminSettings from './views/AdminSettings.vue'

const app = createApp(AdminSettings)

// Make translation functions available globally
app.config.globalProperties.t = translate
app.config.globalProperties.n = translatePlural

app.mount('#attendance-admin-settings-vue')
