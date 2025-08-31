<template>
	<NcAppContent>
		<!-- Check-in View -->
		<CheckinView v-if="currentView === 'checkin'" :appointment-id="checkinAppointmentId" />
		
		<!-- All Appointments View -->
		<AllAppointments v-else-if="currentView === 'main'" />
		
		<!-- Loading state while routing is determined -->
		<div v-else class="loading-state">
			<div class="loading-spinner"></div>
		</div>
	</NcAppContent>
</template>

<script>
import CheckinView from './views/CheckinView.vue'
import AllAppointments from './views/AllAppointments.vue'
import NcAppContent from '@nextcloud/vue/dist/Components/NcAppContent.js'

export default {
	name: 'App',
	components: {
		CheckinView,
		AllAppointments,
		NcAppContent,
	},
	data() {
		return {
			currentView: null, // Don't load any view until routing is determined
			checkinAppointmentId: null,
		}
	},
	mounted() {
		this.checkRouting()
	},
	methods: {
		checkRouting() {
			// Check if we're on a check-in route
			const path = window.location.pathname
			const checkinMatch = path.match(/\/checkin\/(\d+)/)
			
			if (checkinMatch) {
				this.currentView = 'checkin'
				this.checkinAppointmentId = parseInt(checkinMatch[1])
			} else {
				this.currentView = 'main'
				this.checkinAppointmentId = null
			}
		},
	},
}
</script>

<style scoped>
.loading-state {
	display: flex;
	justify-content: center;
	align-items: center;
	height: 200px;
}

.loading-spinner {
	width: 32px;
	height: 32px;
	border: 3px solid var(--color-loading-light);
	border-top: 3px solid var(--color-loading-dark);
	border-radius: 50%;
	animation: spin 1s linear infinite;
}

@keyframes spin {
	0% { transform: rotate(0deg); }
	100% { transform: rotate(360deg); }
}
</style>
