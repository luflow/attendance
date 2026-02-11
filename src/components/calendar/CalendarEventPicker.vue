<template>
	<NcDialog :open="show"
		:name="dialogTitle"
		@update:open="handleClose">
		<div class="calendar-event-picker">
			<p class="description">
				{{ t('attendance', 'Select calendar events to import as appointments.') }}
			</p>

			<!-- Calendar Selection Step -->
			<template v-if="!selectedCalendar">
				<div v-if="loadingCalendars" class="loading-container">
					<NcLoadingIcon :size="32" />
					<span class="loading-text">{{ t('attendance', 'Loading calendars …') }}</span>
				</div>

				<template v-else-if="calendars.length > 0">
					<label class="section-label">{{ t('attendance', 'Select calendar') }}</label>
					<NcTextField v-if="showSearch"
						v-model="searchQuery"
						:placeholder="t('attendance', 'Search calendars …')"
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
					<span class="loading-text">{{ t('attendance', 'Loading events …') }}</span>
				</div>

				<template v-else-if="events.length > 0">
					<div class="event-selection-header">
						<label class="section-label">{{ t('attendance', 'Select events') }}</label>
						<NcButton variant="tertiary" @click="toggleSelectAll">
							{{ allFilteredSelected ? t('attendance', 'Deselect all') : t('attendance', 'Select all') }}
						</NcButton>
					</div>
					<NcTextField v-if="showEventSearch"
						v-model="eventSearchQuery"
						:placeholder="t('attendance', 'Search events …')"
						class="calendar-search" />
					<ul class="event-list">
						<li v-for="event in filteredEvents"
							:key="event.uid"
							class="event-item"
							:class="{ 'event-item--selected': isSelected(event) }"
							@click="toggleEvent(event)">
							<NcCheckboxRadioSwitch
								:model-value="isSelected(event)"
								class="event-checkbox"
								@update:model-value="toggleEvent(event)"
								@click.stop />
							<div class="event-info">
								<span class="event-name">{{ event.summary || t('attendance', 'Untitled event') }}</span>
								<span class="event-date">{{ formatDateRange(event.dtstart, event.dtend) }}</span>
							</div>
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

				<!-- Import button -->
				<div v-if="selectedEvents.length > 0" class="import-actions">
					<NcButton variant="primary" @click="importSelected">
						{{ n('attendance', 'Import {count} event', 'Import {count} events', selectedEvents.length, { count: selectedEvents.length }) }}
					</NcButton>
				</div>
			</template>
		</div>
	</NcDialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { NcDialog, NcButton, NcLoadingIcon, NcTextField, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
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
const selectedEvents = ref([])

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
		return t('attendance', 'Select events')
	}
	return t('attendance', 'Import from calendar')
})

const isSelected = (event) => {
	return selectedEvents.value.some(e => e.uid === event.uid)
}

const toggleEvent = (event) => {
	if (isSelected(event)) {
		selectedEvents.value = selectedEvents.value.filter(e => e.uid !== event.uid)
	} else {
		selectedEvents.value = [...selectedEvents.value, event]
	}
}

const allFilteredSelected = computed(() => {
	return filteredEvents.value.length > 0 && filteredEvents.value.every(e => isSelected(e))
})

const toggleSelectAll = () => {
	if (allFilteredSelected.value) {
		// Deselect all filtered events
		const filteredUids = new Set(filteredEvents.value.map(e => e.uid))
		selectedEvents.value = selectedEvents.value.filter(e => !filteredUids.has(e.uid))
	} else {
		// Select all filtered events (merge with existing)
		const existing = new Set(selectedEvents.value.map(e => e.uid))
		const toAdd = filteredEvents.value.filter(e => !existing.has(e.uid))
		selectedEvents.value = [...selectedEvents.value, ...toAdd]
	}
}

// Load calendars when modal opens
watch(() => props.show, async (newValue) => {
	if (newValue) {
		selectedCalendar.value = null
		searchQuery.value = ''
		selectedEvents.value = []
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
	selectedEvents.value = []
	await loadEvents(calendar.uri)
}

const goBack = () => {
	selectedCalendar.value = null
	selectedEvents.value = []
	clearEvents()
}

const importSelected = () => {
	const eventDataList = selectedEvents.value.map(event => ({
		name: event.summary || '',
		description: event.description || '',
		startDatetime: event.dtstart,
		endDatetime: event.dtend,
		calendarUri: selectedCalendar.value.uri,
		calendarEventUid: event.uri || event.uid,
	}))
	emit('select', eventDataList)
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

.event-item--selected {
	background-color: var(--color-primary-element-light);
	border-color: var(--color-primary-element);
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

.event-checkbox {
	flex-shrink: 0;
	margin-right: 8px;
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

.event-selection-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 12px;
}

.event-selection-header .section-label {
	margin: 0;
}

.import-actions {
	display: flex;
	justify-content: flex-end;
	margin-top: 16px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}
</style>
