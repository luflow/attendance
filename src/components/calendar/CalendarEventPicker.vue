<template>
	<NcDialog :open="show"
		:name="dialogTitle"
		@update:open="handleClose">
		<div class="calendar-event-picker">
			<p class="description">
				{{ t('attendance', 'Select a calendar event to pre-fill the appointment form.') }}
			</p>

			<!-- Calendar Selection Step -->
			<template v-if="!selectedCalendar">
				<div v-if="loadingCalendars" class="loading-container">
					<NcLoadingIcon :size="32" />
					<span class="loading-text">{{ t('attendance', 'Loading calendars...') }}</span>
				</div>

				<template v-else-if="calendars.length > 0">
					<label class="section-label">{{ t('attendance', 'Select Calendar') }}</label>
					<NcTextField v-if="showSearch"
						v-model="searchQuery"
						:placeholder="t('attendance', 'Search calendars...')"
						class="calendar-search" />
					<ul class="calendar-list">
						<li v-for="calendar in filteredCalendars"
							:key="calendar.uri"
							class="calendar-item"
							@click="selectCalendar(calendar)">
							<span class="calendar-color" :style="{ backgroundColor: calendar.color }" />
							<span class="calendar-name">{{ translateCalendarName(calendar.displayName) }}</span>
							<ChevronRight :size="20" class="calendar-arrow" />
						</li>
					</ul>
					<div v-if="showSearch && filteredCalendars.length === 0" class="empty-state">
						<p>{{ t('attendance', 'No calendars match your search') }}</p>
					</div>
				</template>

				<div v-else class="empty-state">
					<CalendarBlankOutline :size="48" class="empty-icon" />
					<p>{{ t('attendance', 'No calendars found') }}</p>
				</div>
			</template>

			<!-- Event Selection Step -->
			<template v-else>
				<div class="back-navigation">
					<NcButton variant="tertiary" @click="goBack">
						<template #icon>
							<ArrowLeft :size="20" />
						</template>
						{{ t('attendance', 'Back') }}
					</NcButton>
					<span class="selected-calendar-name">
						<span class="calendar-color" :style="{ backgroundColor: selectedCalendar.color }" />
						{{ translateCalendarName(selectedCalendar.displayName) }}
					</span>
				</div>

				<div v-if="loadingEvents" class="loading-container">
					<NcLoadingIcon :size="32" />
					<span class="loading-text">{{ t('attendance', 'Loading events...') }}</span>
				</div>

				<template v-else-if="events.length > 0">
					<label class="section-label">{{ t('attendance', 'Select Event') }}</label>
					<NcTextField v-if="showEventSearch"
						v-model="eventSearchQuery"
						:placeholder="t('attendance', 'Search events...')"
						class="calendar-search" />
					<ul class="event-list">
						<li v-for="event in filteredEvents"
							:key="event.uid"
							class="event-item"
							@click="selectEvent(event)">
							<div class="event-info">
								<span class="event-name">{{ event.summary || t('attendance', 'Untitled Event') }}</span>
								<span class="event-date">{{ formatDateRange(event.dtstart, event.dtend) }}</span>
							</div>
							<ChevronRight :size="20" class="event-arrow" />
						</li>
					</ul>
					<div v-if="showEventSearch && filteredEvents.length === 0" class="empty-state">
						<p>{{ t('attendance', 'No events match your search') }}</p>
					</div>
				</template>

				<div v-else class="empty-state">
					<CalendarBlankOutline :size="48" class="empty-icon" />
					<p>{{ t('attendance', 'No upcoming events in this calendar') }}</p>
				</div>
			</template>
		</div>
	</NcDialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { NcDialog, NcButton, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import CalendarBlankOutline from 'vue-material-design-icons/CalendarBlankOutline.vue'
import { useCalendarEvents } from '../../composables/useCalendarEvents.js'
import { formatDateRange } from '../../utils/datetime.js'

const props = defineProps({
	show: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits(['close', 'select'])

const selectedCalendar = ref(null)
const searchQuery = ref('')
const eventSearchQuery = ref('')

const { calendars, events, loadingCalendars, loadingEvents, loadCalendars, loadEvents, clearEvents, reset } = useCalendarEvents()

const translateCalendarName = (name) => {
	if (!name) return name
	const translated = t('calendar', name)
	return translated !== name ? translated : name
}

const showSearch = computed(() => calendars.value.length > 5)

const filteredCalendars = computed(() => {
	if (!searchQuery.value.trim()) {
		return calendars.value
	}
	const query = searchQuery.value.toLowerCase().trim()
	return calendars.value.filter(calendar => {
		const translatedName = translateCalendarName(calendar.displayName)
		return translatedName?.toLowerCase().includes(query)
	})
})

const showEventSearch = computed(() => events.value.length > 5)

const filteredEvents = computed(() => {
	if (!eventSearchQuery.value.trim()) {
		return events.value
	}
	const query = eventSearchQuery.value.toLowerCase().trim()
	return events.value.filter(event =>
		event.summary?.toLowerCase().includes(query),
	)
})

const dialogTitle = computed(() => {
	if (selectedCalendar.value) {
		return t('attendance', 'Select Event')
	}
	return t('attendance', 'Import from Calendar')
})

// Load calendars when modal opens
watch(() => props.show, async (newValue) => {
	if (newValue) {
		selectedCalendar.value = null
		searchQuery.value = ''
		reset()
		await loadCalendars()
	}
})

const handleClose = () => {
	emit('close')
}

const selectCalendar = async (calendar) => {
	selectedCalendar.value = calendar
	eventSearchQuery.value = ''
	await loadEvents(calendar.uri)
}

const goBack = () => {
	selectedCalendar.value = null
	clearEvents()
}

const selectEvent = (event) => {
	emit('select', {
		name: event.summary || '',
		description: event.description || '',
		startDatetime: event.dtstart,
		endDatetime: event.dtend,
		calendarUri: selectedCalendar.value.uri,
		// Store the uri (filename) for calendar deeplinks
		calendarEventUid: event.uri || event.uid,
	})
	emit('close')
}

</script>

<style scoped>
.calendar-event-picker {
	padding: 12px 0;
	min-height: 200px;
}

.description {
	margin: 0 0 20px 0;
	color: var(--color-text-maxcontrast);
}

.loading-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 40px;
	gap: 12px;
}

.loading-text {
	color: var(--color-text-maxcontrast);
}

.section-label {
	display: block;
	margin-bottom: 12px;
	font-weight: 600;
}

.calendar-search {
	margin-bottom: 12px;
}

.calendar-list,
.event-list {
	list-style: none;
	padding: 0;
	margin: 0;
}

.calendar-item,
.event-item {
	display: flex;
	align-items: center;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: 8px;
	cursor: pointer !important;
	transition: background-color 0.15s ease;

	* {
		cursor: pointer !important;
	}
}

.calendar-item:hover,
.event-item:hover {
	background-color: var(--color-background-hover);
}

.calendar-color {
	width: 16px;
	height: 16px;
	border-radius: 50%;
	margin-right: 12px;
	flex-shrink: 0;
}

.calendar-name,
.event-name {
	flex: 1;
	font-weight: 500;
}

.calendar-arrow,
.event-arrow {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.event-info {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.event-date {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.empty-state {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 40px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.empty-icon {
	margin-bottom: 12px;
	opacity: 0.5;
}

.back-navigation {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
	padding-bottom: 12px;
	border-bottom: 1px solid var(--color-border);
}

.selected-calendar-name {
	display: flex;
	align-items: center;
	font-weight: 500;
}

.selected-calendar-name .calendar-color {
	width: 12px;
	height: 12px;
	margin-right: 8px;
}
</style>
