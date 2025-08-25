import Vue from 'vue'
import './vueBootstrap.js'
import AppointmentWidget from './views/AppointmentWidget.vue'

document.addEventListener('DOMContentLoaded', () => {
	OCA.Dashboard.register('attendance-vue-widget', (el, { widget }) => {
		try {
			const View = Vue.extend(AppointmentWidget)
			const instance = new View({
				propsData: { title: widget.title },
			})

			instance.$mount(el)
		} catch (error) {
			console.error('Error creating/mounting Vue instance:', error)
			el.innerHTML = '<div style="color: red;">Error loading widget: ' + error.message + '</div>'
		}
	})
})
