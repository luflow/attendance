import { translate, translatePlural } from '@nextcloud/l10n'
import { createApp } from 'vue'
import App from './App.vue'

// Toasts are styled by CSS modules since @nextcloud/dialogs 7.5.0 — the server's
// global styles no longer cover them, so every entry point showing one needs both.
import '@nextcloud/dialogs/style.css'
import './toast-position.css'

const app = createApp(App)

// Make translation functions available globally
app.config.globalProperties.t = translate
app.config.globalProperties.n = translatePlural

app.mount('#attendance')
