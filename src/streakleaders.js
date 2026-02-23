import { createApp } from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import StreakLeadersWidget from './views/StreakLeadersWidget.vue'

document.addEventListener('DOMContentLoaded', () => {
	OCA.Dashboard.register('attendance-streak-leaders', (el, { widget }) => {
		try {
			const app = createApp(StreakLeadersWidget, {
				title: widget.title,
			})

			app.config.globalProperties.t = translate
			app.config.globalProperties.n = translatePlural

			app.mount(el)
		} catch (error) {
			console.error('Error creating/mounting streak leaders widget:', error)
		}
	})
})
