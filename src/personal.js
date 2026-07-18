/**
 * Personal settings Vue app entry point
 */

import { translate, translatePlural } from '@nextcloud/l10n'
import { createApp } from 'vue'
import PersonalSettings from './views/PersonalSettings.vue'

const app = createApp(PersonalSettings)

app.config.globalProperties.t = translate
app.config.globalProperties.n = translatePlural

app.mount('#attendance-personal-settings-vue')
