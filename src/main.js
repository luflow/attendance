import { translate, translatePlural } from '@nextcloud/l10n'
import { createApp } from 'vue'
import App from './App.vue'

import './toasts.css'

const app = createApp(App)

// Make translation functions available globally
app.config.globalProperties.t = translate
app.config.globalProperties.n = translatePlural

app.mount('#attendance')
