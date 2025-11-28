import { createApp } from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import AppointmentWidget from './views/Widget.vue'

document.addEventListener('DOMContentLoaded', () => {
	OCA.Dashboard.register('attendance-vue-widget', (el, { widget }) => {
		try {
			const app = createApp(AppointmentWidget, {
				title: widget.title,
			})

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
