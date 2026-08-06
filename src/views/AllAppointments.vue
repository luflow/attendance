<template>
	<div class="attendance-container">
		<!-- Unanswered reminder banner (shown on upcoming view when there are unanswered appointments) -->
		<div v-if="!showUnanswered && !showPast && !showAll && !loading && unansweredCount > 0" class="unanswered-banner-container">
			<div class="unanswered-banner pending clickable" role="button" @click="emit('navigateToUnanswered')">
				<ProgressQuestion :size="20" />
				<span>{{ n('attendance', '%n appointment awaiting your response', '%n appointments awaiting your response', unansweredCount) }}</span>
				<span class="banner-action">{{ t('attendance', 'View all') }} →</span>
			</div>
		</div>

		<!-- Unanswered Banner (only shown on unanswered view after loading) -->
		<div v-if="showUnanswered && !loading" class="unanswered-banner-container">
			<div v-if="appointments.length > 0" class="unanswered-banner pending">
				<ProgressQuestion :size="20" />
				<span>{{ n('attendance', '%n appointment awaiting your response', '%n appointments awaiting your response', appointments.length) }}</span>
			</div>
			<div v-else class="unanswered-banner complete">
				<span>{{ t('attendance', 'Hurray! You responded to all appointments.') }}</span>
				<NcButton
					@click="goToUpcoming">
					{{ t('attendance', 'Show upcoming appointments') }}
				</NcButton>
			</div>
		</div>

		<h2 v-if="!hideHeading" class="page-heading" data-test="page-heading">
			{{ pageHeading }}
		</h2>

		<div
			v-if="!loading && !showUnanswered && (appointments.length > 0 || hasActiveFilters)"
			class="filter-bar"
			data-test="appointment-filters">
			<div class="filter-bar__triggers">
				<NcPopover v-for="filter in filters" :key="filter.id">
					<template #trigger>
						<NcButton
							:variant="filter.value ? 'secondary' : 'tertiary'"
							:data-test="`filter-${filter.id}`">
							<template #icon>
								<component :is="filter.icon" :size="20" />
							</template>
							{{ filter.label }}
						</NcButton>
					</template>
					<template #default>
						<ul class="filter-bar__options" role="menu">
							<li v-for="opt in filter.options" :key="opt.id" role="presentation">
								<NcButton
									role="menuitemradio"
									:aria-checked="filter.value?.id === opt.id"
									alignment="start"
									wide
									variant="tertiary"
									@click="setFilter(filter.id, opt)">
									<span class="filter-bar__option">
										{{ opt.label }}
										<CheckIcon v-if="filter.value?.id === opt.id" :size="18" />
									</span>
								</NcButton>
							</li>
							<li v-if="filter.value" role="presentation" class="filter-bar__reset">
								<NcButton
									alignment="start"
									wide
									variant="tertiary"
									@click="setFilter(filter.id, null)">
									{{ t('attendance', 'Reset filter') }}
								</NcButton>
							</li>
						</ul>
					</template>
				</NcPopover>
				<NcPopover v-if="capabilities.locationsAvailable && availableLocations.length">
					<template #trigger>
						<NcButton
							:variant="selectedLocations.length ? 'secondary' : 'tertiary'"
							data-test="filter-location">
							<template #icon>
								<MapMarkerIcon :size="20" />
							</template>
							<!-- TRANSLATORS: Filter button in the appointment list, filtering by the venue an appointment takes place at — not a file or storage path. -->
							{{ t('attendance', 'Location') }}
						</NcButton>
					</template>
					<template #default>
						<ul class="filter-bar__options" role="menu">
							<li v-for="location in availableLocations" :key="location" role="presentation">
								<NcButton
									role="menuitemcheckbox"
									:aria-checked="selectedLocations.includes(location)"
									alignment="start"
									wide
									variant="tertiary"
									@click="toggleLocationFilter(location)">
									<span class="filter-bar__option">
										{{ location }}
										<CheckIcon v-if="selectedLocations.includes(location)" :size="18" />
									</span>
								</NcButton>
							</li>
							<li v-if="selectedLocations.length" role="presentation" class="filter-bar__reset">
								<NcButton
									alignment="start"
									wide
									variant="tertiary"
									@click="selectedLocations = []">
									{{ t('attendance', 'Reset filter') }}
								</NcButton>
							</li>
						</ul>
					</template>
				</NcPopover>
				<NcPopover v-if="capabilities.categoriesAvailable && availableCategories.length">
					<template #trigger>
						<NcButton
							:variant="selectedCategoryIds.length ? 'secondary' : 'tertiary'"
							data-test="filter-category">
							<template #icon>
								<TagIcon :size="20" />
							</template>
							{{ t('attendance', 'Category') }}
						</NcButton>
					</template>
					<template #default>
						<ul class="filter-bar__options" role="menu">
							<li v-for="category in availableCategories" :key="category.id" role="presentation">
								<NcButton
									role="menuitemcheckbox"
									:aria-checked="selectedCategoryIds.includes(category.id)"
									alignment="start"
									wide
									variant="tertiary"
									@click="toggleCategoryFilter(category.id)">
									<template #icon>
										<component :is="categoryIconComponent(category.icon)" :size="18" />
									</template>
									<span class="filter-bar__option">
										{{ category.name }}
										<CheckIcon v-if="selectedCategoryIds.includes(category.id)" :size="18" />
									</span>
								</NcButton>
							</li>
							<li v-if="selectedCategoryIds.length" role="presentation" class="filter-bar__reset">
								<NcButton
									alignment="start"
									wide
									variant="tertiary"
									@click="selectedCategoryIds = []">
									{{ t('attendance', 'Reset filter') }}
								</NcButton>
							</li>
						</ul>
					</template>
				</NcPopover>
			</div>
			<div v-if="hasActiveFilters" class="filter-bar__active">
				<span class="filter-bar__active-label">{{ t('attendance', 'Active filters:') }}</span>
				<NcChip
					v-if="activeSearch"
					:text="t('attendance', 'Search: {query}', { query: activeSearch })"
					data-test="active-search-chip"
					@close="emit('clearSearch')" />
				<NcChip
					v-for="filter in activeFilters"
					:key="filter.id"
					:text="`${filter.label}: ${filter.value.label}`"
					@close="setFilter(filter.id, null)" />
				<NcChip
					v-for="location in selectedLocations"
					:key="location"
					:text="t('attendance', 'Location: {location}', { location })"
					@close="toggleLocationFilter(location)" />
				<NcChip
					v-for="category in selectedCategoryChips"
					:key="category.id"
					@close="toggleCategoryFilter(category.id)">
					<span class="filter-bar__category-chip">
						<span v-if="categoryChipLabel.before">{{ categoryChipLabel.before }}</span>
						<component :is="categoryIconComponent(category.icon)" :size="16" />
						<span>{{ category.name }}</span>
						<span v-if="categoryChipLabel.after">{{ categoryChipLabel.after }}</span>
					</span>
				</NcChip>
			</div>
		</div>

		<!-- Appointments List -->
		<div class="appointments-list">
			<div v-if="loading" class="loading">
				{{ t('attendance', 'Loading\u00A0…') }}
			</div>
			<div v-else-if="visibleAppointments.length === 0 && !showUnanswered" class="empty-state">
				<p>
					{{ hasActiveFilters
						? t('attendance', 'No appointments match the active filters.')
						: t('attendance', 'No appointments found') }}
				</p>
				<NcButton v-if="showFirstAppointmentPrompt"
					variant="primary"
					data-test="button-create-first-appointment"
					@click="emit('createAppointment')">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
					{{ t('attendance', 'Create your first appointment') }}
				</NcButton>
			</div>
			<div v-else>
				<template v-for="section in visibleSections" :key="section.key">
					<h3 v-if="section.label" class="section-heading">
						{{ section.label }}
					</h3>
					<AppointmentListCard
						v-for="appointment in section.items"
						:key="appointment.id"
						:appointment="appointment"
						@openDetail="(id) => emit('openDetail', id)"
						@startCheckin="startCheckin"
						@edit="editAppointment"
						@copy="copyAppointment"
						@delete="deleteAppointment"
						@export="showExportDialog"
						@submitResponse="submitResponse"
						@closedToggled="handleClosedToggled"
						@showAuditLog="(id) => emit('showAuditLog', id)" />
				</template>
			</div>
		</div>

		<!-- Single Appointment Export Dialog -->
		<SingleAppointmentExportDialog
			:show="exportDialogVisible"
			:appointment="selectedAppointmentForExport"
			@close="exportDialogVisible = false" />

		<!-- Delete Appointment Dialog -->
		<DeleteAppointmentDialog
			:show="showDeleteDialog"
			:appointment="pendingDeleteAppointment"
			@confirm="handleDeleteConfirm"
			@cancel="showDeleteDialog = false" />
	</div>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcChip, NcPopover } from '@nextcloud/vue'
import { create as createConfetti } from 'canvas-confetti'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import MapMarkerIcon from 'vue-material-design-icons/MapMarkerOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import ProgressQuestion from 'vue-material-design-icons/ProgressQuestion.vue'
import TagIcon from 'vue-material-design-icons/TagOutline.vue'
import AppointmentListCard from '../components/appointment/AppointmentListCard.vue'
import DeleteAppointmentDialog from '../components/appointment/DeleteAppointmentDialog.vue'
import SingleAppointmentExportDialog from '../components/SingleAppointmentExportDialog.vue'
import { useAppointmentResponse } from '../composables/useAppointmentResponse.js'
import { useCategories } from '../composables/useCategories.js'
import { usePermissions } from '../composables/usePermissions.js'
import { categoryIconComponent } from '../utils/categoryIcons.js'

const props = defineProps({
	showPast: {
		type: Boolean,
		default: false,
	},
	showUnanswered: {
		type: Boolean,
		default: false,
	},
	// "All" view: fetches upcoming + past in parallel and concatenates.
	showAll: {
		type: Boolean,
		default: false,
	},
	searchQuery: {
		type: String,
		default: '',
	},
	unansweredCount: {
		type: Number,
		default: 0,
	},
})

const emit = defineEmits([
	'responseUpdated',
	'editAppointment',
	'copyAppointment',
	'navigateToUpcoming',
	'navigateToUnanswered',
	'appointmentDeleted',
	'clearSearch',
	'showAuditLog',
	'openDetail',
	'createAppointment',
])

const activeSearch = computed(() => props.searchQuery.trim())

const viewKey = computed(() => {
	if (props.showUnanswered) return 'unanswered'
	if (props.showAll) return 'all'
	if (props.showPast) return 'past'
	return 'current'
})
const pageHeading = computed(() => ({
	unanswered: t('attendance', 'Unanswered'),
	all: t('attendance', 'All appointments'),
	past: t('attendance', 'Past appointments'),
	current: t('attendance', 'Upcoming appointments'),
}[viewKey.value]))

const appointments = ref([])
const exportDialogVisible = ref(false)
const selectedAppointmentForExport = ref(null)
const showDeleteDialog = ref(false)
const pendingDeleteAppointment = ref(null)

function goToUpcoming() {
	emit('navigateToUpcoming')
}

const FILTER_STORAGE_KEY = 'attendance:list-filters'

// Wire IDs centralised so a typo breaks at parse, not silently at runtime.
const F = Object.freeze({
	RESPONSE: 'response',
	STATUS: 'status',
	AUDIENCE: 'audience',
})
const RESPONSE = Object.freeze({ YES: 'yes', MAYBE: 'maybe', NO: 'no', NONE: 'none' })
const STATUS = Object.freeze({ OPEN: 'open', CLOSED: 'closed', CANCELLED: 'cancelled' })
const AUDIENCE = Object.freeze({ ME: 'me', ME_SCHEDULED: 'me-scheduled', ME_BOOKED: 'me-booked' })

const { permissions, capabilities, config, loadPermissions } = usePermissions()
const { categories, loadCategories } = useCategories()
loadCategories()

const filterDefs = computed(() => [
	{
		id: F.RESPONSE,
		label: t('attendance', 'Your response'),
		icon: CheckCircleIcon,
		options: [
			{ id: RESPONSE.YES, label: t('attendance', 'Yes') },
			{ id: RESPONSE.MAYBE, label: t('attendance', 'Maybe') },
			{ id: RESPONSE.NO, label: t('attendance', 'No') },
			{ id: RESPONSE.NONE, label: t('attendance', 'No response') },
		],
	},
	{
		id: F.STATUS,
		label: t('attendance', 'Inquiry status'),
		icon: LockIcon,
		options: [
			{ id: STATUS.OPEN, label: t('attendance', 'Opened') },
			{ id: STATUS.CLOSED, label: t('attendance', 'Closed') },
			// TRANSLATORS: Filter option — appointments that were called off (German "Abgesagt", not "Abgebrochen").
			{ id: STATUS.CANCELLED, label: t('attendance', 'Cancelled') },
		],
	},
	{
		// Server-side: the audience check via VisibilityService::isUserTargetAttendee,
		// the scheduling check via BookingService::isScheduledOut. Two steps of
		// the same axis, so they share one filter and exclude each other.
		id: F.AUDIENCE,
		label: t('attendance', 'Relevance'),
		icon: AccountIcon,
		options: [
			{
				// Without it, managers see every appointment in the system.
				// Everyone else only ever gets their own — a no-op, so hide it.
				id: AUDIENCE.ME,
				label: t('attendance', 'Only for me'),
				visible: permissions.canManageAppointments,
			},
			{
				id: AUDIENCE.ME_SCHEDULED,
				// TRANSLATORS: Filter option in the appointment list. Hides appointments
				// where the user was explicitly not given a place after scheduling
				// finished (German "nicht ausgeplant" — not "abgesagt", which this app
				// uses for calling off an appointment).
				label: t('attendance', 'Only for me, not scheduled out'),
				visible: capabilities.bookingEnabled,
			},
			{
				id: AUDIENCE.ME_BOOKED,
				// TRANSLATORS: Filter option in the appointment list, deliberately terse.
				// "Scheduled" refers to the person, not the appointment: it keeps only
				// appointments the user was given a place in when the organizer planned
				// the lineup (German "eingeplant" — "Nur eingeplante"). Read it as
				// "Only [appointments I am] scheduled [in]".
				label: t('attendance', 'Only scheduled'),
				visible: capabilities.bookingEnabled && capabilities.scheduledFilter,
			},
		],
	},
])

const filterValues = ref(loadStoredFilterValues())

// Location is a separate, multi-select axis (several previously-used
// locations at once) — the shared filterDefs/filterValues above are
// single-select-per-group, so this stays independent rather than forcing a
// checkbox mode into that radio-style popover.
const LOCATION_FILTER_STORAGE_KEY = 'attendance:list-filters:locations'
const selectedLocations = ref(loadStoredLocationFilter())

function loadStoredLocationFilter() {
	try {
		const parsed = JSON.parse(window.localStorage.getItem(LOCATION_FILTER_STORAGE_KEY) || '[]')
		return Array.isArray(parsed) ? parsed.filter((v) => typeof v === 'string') : []
	} catch {
		return []
	}
}

const availableLocations = computed(() => [...new Set(appointments.value.map((a) => a.location).filter(Boolean))].sort())

function toggleLocationFilter(location) {
	selectedLocations.value = selectedLocations.value.includes(location)
		? selectedLocations.value.filter((l) => l !== location)
		: [...selectedLocations.value, location]
}

// Same independent multi-select axis as location, but keyed by categoryId
// (categories are admin-managed, so the value is a stable id, not text).
const CATEGORY_FILTER_STORAGE_KEY = 'attendance:list-filters:categories'
const selectedCategoryIds = ref(loadStoredCategoryFilter())

function loadStoredCategoryFilter() {
	try {
		const parsed = JSON.parse(window.localStorage.getItem(CATEGORY_FILTER_STORAGE_KEY) || '[]')
		return Array.isArray(parsed) ? parsed.filter((v) => typeof v === 'number') : []
	} catch {
		return []
	}
}

const availableCategories = computed(() => {
	const usedIds = new Set(appointments.value.map((a) => a.categoryId).filter((id) => id !== null && id !== undefined))
	return categories.filter((category) => usedIds.has(category.id))
})

// Stale ids (category deleted after being stored/selected) are dropped
// rather than shown as an unresolvable chip.
const selectedCategoryChips = computed(() => selectedCategoryIds.value
	.map((id) => categories.find((category) => category.id === id))
	.filter(Boolean))

// Deliberately unsubstituted: splitting the translated label at its own
// placeholder puts the icon next to the name, in the translator's order.
const categoryChipLabel = computed(() => {
	const [before = '', after = ''] = t('attendance', 'Category: {category}').split('{category}')
	return { before: before.trim(), after: after.trim() }
})

function toggleCategoryFilter(categoryId) {
	selectedCategoryIds.value = selectedCategoryIds.value.includes(categoryId)
		? selectedCategoryIds.value.filter((id) => id !== categoryId)
		: [...selectedCategoryIds.value, categoryId]
}

function loadStoredFilterValues() {
	try {
		const parsed = JSON.parse(window.localStorage.getItem(FILTER_STORAGE_KEY) || '{}')
		// Only keep string values; drop anything else (legacy keys, garbage).
		return Object.fromEntries(Object.entries(parsed).filter(([, v]) => typeof v === 'string'))
	} catch {
		return {}
	}
}

const filters = computed(() => filterDefs.value
	.map((def) => ({
		...def,
		options: def.options.filter((opt) => opt.visible !== false),
	}))
	// A filter whose every option is hidden has nothing left to offer.
	.filter((def) => def.options.length > 0)
	.map((def) => ({
		...def,
		value: def.options.find((opt) => opt.id === filterValues.value[def.id]) ?? null,
	})))

// Read filter state through the *visible* filters, never through filterValues
// directly: a value stored back when an option was still available (planning
// feature later switched off, manage permission revoked) must stop taking
// effect, not keep filtering invisibly.
const activeAudience = computed(() => {
	return filters.value.find((f) => f.id === F.AUDIENCE)?.value?.id ?? null
})

const activeFilters = computed(() => filters.value.filter((f) => f.value))

function setFilter(id, opt) {
	const next = { ...filterValues.value }
	if (opt) {
		next[id] = opt.id
	} else {
		delete next[id]
	}
	filterValues.value = next
}

// Debounced localStorage write shared by every persisted filter: skips
// unchanged values, coalesces bursts of changes into one write, and stays
// quiet if storage is unavailable (private mode, quota). Returns a cleanup
// function that cancels a still-pending write, for onBeforeUnmount.
function debouncedLocalStorageWriter(key, initialValue) {
	let timer = null
	let lastJson = JSON.stringify(initialValue)
	const write = (value) => {
		const json = JSON.stringify(value)
		if (json === lastJson) return
		clearTimeout(timer)
		timer = setTimeout(() => {
			try {
				window.localStorage.setItem(key, json)
				lastJson = json
			} catch {
				// Storage may be unavailable (private mode, quota).
			}
		}, 300)
	}
	write.cancel = () => clearTimeout(timer)
	return write
}

const persistFilterValues = debouncedLocalStorageWriter(FILTER_STORAGE_KEY, filterValues.value)
watch(filterValues, persistFilterValues, { deep: true })

const persistSelectedLocations = debouncedLocalStorageWriter(LOCATION_FILTER_STORAGE_KEY, selectedLocations.value)
watch(selectedLocations, persistSelectedLocations, { deep: true })

const persistSelectedCategoryIds = debouncedLocalStorageWriter(CATEGORY_FILTER_STORAGE_KEY, selectedCategoryIds.value)
watch(selectedCategoryIds, persistSelectedCategoryIds, { deep: true })

const hasActiveFilters = computed(() => Boolean(props.searchQuery.trim() || activeFilters.value.length || selectedLocations.value.length || selectedCategoryIds.value.length))

// The server decides who gets the prompt; the filter state is ours — an empty
// filter result is not an empty instance.
const showFirstAppointmentPrompt = computed(() => !hasActiveFilters.value
	&& config.onboarding.firstAppointmentPrompt)

const visibleAppointments = computed(() => {
	const query = props.searchQuery.trim().toLowerCase()
	const status = filterValues.value[F.STATUS]
	const response = filterValues.value[F.RESPONSE]
	return appointments.value.filter((appointment) => {
		// Cancelled appointments drop out of the active lists — they are only
		// reachable on the "All" view (own section + cancelled status filter).
		if (!props.showAll && appointment.cancelledAt) return false
		if (query) {
			const haystack = `${appointment.name} ${appointment.description ?? ''}`.toLowerCase()
			if (!haystack.includes(query)) return false
		}
		if (status === STATUS.OPEN && (appointment.closedAt || appointment.cancelledAt)) return false
		if (status === STATUS.CLOSED && (!appointment.closedAt || appointment.cancelledAt)) return false
		if (status === STATUS.CANCELLED && !appointment.cancelledAt) return false
		if (response) {
			const userResponse = appointment.userResponse?.response ?? null
			if (response === RESPONSE.NONE && userResponse !== null) return false
			if (response !== RESPONSE.NONE && userResponse !== response) return false
		}
		if (selectedLocations.value.length && !selectedLocations.value.includes(appointment.location)) return false
		if (selectedCategoryIds.value.length && !selectedCategoryIds.value.includes(appointment.categoryId)) return false
		return true
	})
})

// All view shows upcoming + past back-to-back; subdivide so the user knows
// where the boundary is. Other views are a single homogeneous list.
const visibleSections = computed(() => {
	if (!props.showAll) {
		return [{ key: 'all', label: '', items: visibleAppointments.value }]
	}
	// Cancelled appointments get their own section at the bottom, regardless of
	// past/upcoming, so they stay visible but clearly set apart.
	const active = visibleAppointments.value.filter((a) => !a.cancelledAt)
	const cancelled = visibleAppointments.value.filter((a) => a.cancelledAt)
	const upcoming = active.filter((a) => !a._isPast)
	const past = active.filter((a) => a._isPast)
	return [
		upcoming.length && { key: 'upcoming', label: t('attendance', 'Upcoming'), items: upcoming },
		past.length && { key: 'past', label: t('attendance', 'Past'), items: past },
		// TRANSLATORS: Group heading for appointments that were called off (German "Abgesagt", not "Abgebrochen").
		cancelled.length && { key: 'cancelled', label: t('attendance', 'Cancelled'), items: cancelled },
	].filter(Boolean)
})

function handleClosedToggled(updated) {
	const index = appointments.value.findIndex((a) => a.id === updated.id)
	if (index !== -1) {
		appointments.value[index] = { ...appointments.value[index], ...updated }
	}
	// Closing/reopening changes how the appointment is bucketed in the nav menu
	// (a closed-but-unanswered inquiry is no longer "Unanswered" — it belongs
	// under "Upcoming"). The parent recomputes those sections from freshly
	// loaded data, so trigger the same refresh answering does.
	emit('responseUpdated')
}
const loading = ref(true)

// On the Unanswered view the empty state is the celebratory "Hurray!" banner;
// repeating "Unanswered" above it just adds noise.
const hideHeading = computed(() => props.showUnanswered && !loading.value && appointments.value.length === 0)

// Use the shared response composable
const { submitResponse: submitResponseApi } = useAppointmentResponse({
	onSuccess: () => {
		emit('responseUpdated')
		loadAppointments(true)
	},
})

async function loadAppointments(skipLoadingSpinner = false) {
	try {
		if (!skipLoadingSpinner) {
			loading.value = true
		}
		const url = generateUrl('/apps/attendance/api/appointments')
		// notScheduledOut implies onlyForMe server-side, so it never needs both.
		const audienceParams = {
			[AUDIENCE.ME]: { onlyForMe: true },
			[AUDIENCE.ME_SCHEDULED]: { notScheduledOut: true },
			[AUDIENCE.ME_BOOKED]: { onlyScheduled: true },
		}[activeAudience.value] ?? {}
		if (props.showAll) {
			const [upcoming, past] = await Promise.all([
				axios.get(url, { params: audienceParams }),
				axios.get(url, { params: { ...audienceParams, showPastAppointments: true } }),
			])
			// Tag for the All-view section split — the server already partitions,
			// don't re-derive from end_datetime in the browser.
			appointments.value = [
				...upcoming.data.map((a) => ({ ...a, _isPast: false })),
				...past.data.map((a) => ({ ...a, _isPast: true })),
			]
		} else {
			const params = { ...audienceParams }
			if (props.showPast) params.showPastAppointments = true
			if (props.showUnanswered) params.unansweredOnly = true
			const response = await axios.get(url, { params })
			appointments.value = response.data
		}

		if (permissions.canManageAppointments) {
			await loadDetailedResponses()
		}
	} catch (error) {
		console.error('Failed to load appointments:', error)
	} finally {
		loading.value = false
	}
}

// The audience filter is server-side, so flipping it requires a refetch. Watch
// the raw value, which only ever moves through setFilter — activeAudience also
// flips when permissions arrive at mount, which onMounted already fetches for.
watch(() => filterValues.value[F.AUDIENCE], () => {
	loadAppointments(true)
})

async function loadDetailedResponses() {
	// Hundreds of serial XHRs in the All view used to take seconds. The
	// requests are independent — fan them out and let the browser pipeline.
	await Promise.all(appointments.value.map(async (appointment) => {
		try {
			const response = await axios.get(generateUrl(`/apps/attendance/api/appointments/${appointment.id}/responses`))
			appointment.detailedResponses = response.data
		} catch (error) {
			console.error(`Failed to load detailed responses for appointment ${appointment.id}:`, error)
		}
	}))
}

async function submitResponse(appointmentId, response) {
	const appointment = appointments.value.find((a) => a.id === appointmentId)
	const comment = appointment?.userResponse?.comment || ''
	await submitResponseApi(appointmentId, response, comment)
}

function deleteAppointment(appointmentId) {
	const appointment = appointments.value.find((a) => a.id === appointmentId)
	pendingDeleteAppointment.value = appointment
	showDeleteDialog.value = true
}

async function handleDeleteConfirm(scope) {
	showDeleteDialog.value = false
	if (!pendingDeleteAppointment.value) return

	try {
		await axios.delete(generateUrl(`/apps/attendance/api/appointments/${pendingDeleteAppointment.value.id}`), {
			data: { scope },
		})
		await loadAppointments(true)
		emit('appointmentDeleted')
	} catch (error) {
		console.error('Failed to delete appointment:', error)
	}
}

function editAppointment(appointment) {
	emit('editAppointment', appointment)
}

function copyAppointment(appointment) {
	emit('copyAppointment', appointment)
}

function startCheckin(appointmentId) {
	window.location.href = generateUrl(`/apps/attendance/checkin/${appointmentId}`)
}

function showExportDialog(appointmentId) {
	const appointment = appointments.value.find((apt) => apt.id === appointmentId)
	selectedAppointmentForExport.value = appointment
	exportDialogVisible.value = true
}

// Create confetti instance without worker to comply with NC 32+ CSP
let confettiInstance = null
let confettiCanvas = null
function getConfetti() {
	if (!confettiInstance) {
		confettiCanvas = document.createElement('canvas')
		confettiCanvas.style.position = 'fixed'
		confettiCanvas.style.top = '0'
		confettiCanvas.style.left = '0'
		confettiCanvas.style.width = '100%'
		confettiCanvas.style.height = '100%'
		confettiCanvas.style.pointerEvents = 'none'
		confettiCanvas.style.zIndex = '9999'
		document.body.appendChild(confettiCanvas)
		confettiInstance = createConfetti(confettiCanvas, { resize: true, useWorker: false })
	}
	return confettiInstance
}

onBeforeUnmount(() => {
	if (confettiInstance) {
		confettiInstance.reset()
		confettiInstance = null
	}
	if (confettiCanvas) {
		confettiCanvas.remove()
		confettiCanvas = null
	}
	persistFilterValues.cancel()
	persistSelectedLocations.cancel()
	persistSelectedCategoryIds.cancel()
})

function triggerConfetti() {
	getConfetti()({
		particleCount: 200,
		spread: 100,
		origin: { x: 0.5, y: 1 },
		angle: 90,
		startVelocity: 60,
	})
}

// Watch for when all appointments are answered
watch(loading, (isLoading) => {
	if (!isLoading && props.showUnanswered && appointments.value.length === 0) {
		triggerConfetti()
	}
})

// Also trigger confetti when appointments list becomes empty (after responding to last one)
watch(() => appointments.value.length, (newLength, oldLength) => {
	if (props.showUnanswered && !loading.value && newLength === 0 && oldLength > 0) {
		triggerConfetti()
	}
})

onMounted(async () => {
	await loadPermissions()
	await loadAppointments()
})
</script>

<style scoped lang="scss">
.attendance-container {
	padding: 20px;
	max-width: 1200px;
	margin: 0 auto;
}

.appointments-list {
	max-width: 800px;
	margin: 0 auto;

	.loading,
	.empty-state {
		text-align: center;
		padding: 40px;
		color: var(--color-text-lighter);
	}

	.empty-state {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 16px;
	}
}

.unanswered-banner-container {
	max-width: 800px;
	margin: 0 auto 20px;
}

.page-heading {
	max-width: 800px;
	margin: 0 auto 12px;
	font-size: 1.4em;
	font-weight: 600;
	color: var(--color-main-text);
}

.section-heading {
	font-size: 1.05em;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	margin: 16px 0 8px;
	&:first-child {
		margin-top: 0;
	}
}

.filter-bar {
	max-width: 800px;
	margin: 0 auto 12px;
	display: flex;
	flex-direction: column;
	gap: 8px;

	&__triggers {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
	}

	&__options {
		min-width: 200px;
		padding: 4px 0;
		list-style: none;
		margin: 0;
	}

	// Make filter-option labels normal weight — Nc tertiary buttons render
	// bold by default which doesn't read like a list of choices.
	&__options :deep(.button-vue__text) {
		font-weight: normal;
	}

	// Push the trailing tick icon to the right edge of the option button.
	&__option {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		width: 100%;
	}

	&__reset :deep(.button-vue__text) {
		color: var(--color-error-text);
	}

	&__active {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
	}

	&__category-chip {
		display: inline-flex;
		align-items: center;
		gap: 4px;
	}

	&__active-label {
		font-weight: 600;
		color: var(--color-text-maxcontrast);
		margin-inline-end: 4px;
	}
}

.banner-action {
	margin-inline-start: auto;
	font-weight: normal;
	white-space: nowrap;
	opacity: 0.85;
}

.unanswered-banner {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 16px 20px;
	border-radius: var(--border-radius-large);

	&.pending {
		background: #ff8c00;
		color: white;
		border-inline-start: 4px solid #ff6600;

		&.clickable {
			cursor: pointer;

			* {
				cursor: pointer;
			}

			&:hover {
				background: #ff6600;
			}
		}
		font-weight: 600;
	}

	&.complete {
		flex-direction: column;
		text-align: center;
		color: var(--color-text-maxcontrast);
		gap: 16px;
	}
}
</style>
