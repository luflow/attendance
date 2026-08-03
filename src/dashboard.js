import { translate, translatePlural } from '@nextcloud/l10n'
import { createApp } from 'vue'
import AppointmentWidget from './views/Widget.vue'

import './dashboard.css'

document.addEventListener('DOMContentLoaded', () => {
	OCA.Dashboard.register('attendance-vue-widget', (el) => {
		try {
			// No props: the dashboard renders the widget title in its own frame,
			// so passing widget.title down would only land on the root element as
			// a fallthrough attribute and show up as a tooltip.
			const app = createApp(AppointmentWidget)

			// Make translation functions available globally
			app.config.globalProperties.t = translate
			app.config.globalProperties.n = translatePlural

			app.mount(el)
		} catch (error) {
			console.error('Error creating/mounting Vue instance:', error)
			el.innerHTML = '<div style="color: red;">Error loading widget: ' + error.message + '</div>'
		}
	})
})
