<template>
	<div class="attendance-container">
		<div v-if="viewDef.showsAwaitingBanner && !loading && unansweredCount > 0" class="unanswered-banner-container">
			<div class="unanswered-banner pending clickable" role="button" @click="emit('navigateToUnanswered')">
				<ProgressQuestion :size="20" />
				<span>{{ n('attendance', '%n appointment awaiting your response', '%n appointments awaiting your response', unansweredCount) }}</span>
				<span class="banner-action">{{ t('attendance', 'View all') }} →</span>
			</div>
		</div>

		<div v-if="viewDef.celebratesEmpty && !loading" class="unanswered-banner-container">
			<div v-if="total > 0" class="unanswered-banner pending">
				<ProgressQuestion :size="20" />
				<span>{{ n('attendance', '%n appointment awaiting your response', '%n appointments awaiting your response', total) }}</span>
			</div>
			<div v-else class="unanswered-banner complete">
				<!-- TRANSLATORS: Empty state when nothing is left to answer. "responded to" means an answer was given, NOT that the person attended. -->
				<span>{{ t('attendance', 'Hurray! You responded to all appointments.') }}</span>
				<NcButton
					@click="goToUpcoming">
					{{ t('attendance', 'Show upcoming appointments') }}
				</NcButton>
			</div>
		</div>

		<h2 v-if="!showCelebration" class="page-heading" data-test="page-heading">
			{{ pageHeading }}
		</h2>

		<div
			v-if="!loading && viewDef.filtersApply && (appointments.length > 0 || hasActiveFilters)"
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
		<div class="appointments-list" data-test="appointment-list" :aria-busy="busy">
			<LoadingState v-if="loading" :text="t('attendance', 'Loading\u00A0…')" />
			<NcEmptyContent v-else-if="appointments.length === 0 && !showCelebration"
				:name="emptyState.name"
				:description="emptyState.description"
				data-test="appointments-empty-state">
				<template #icon>
					<component :is="emptyState.icon" :size="20" />
				</template>
				<template v-if="showSetupPrompt || showFirstAppointmentPrompt" #action>
					<NcButton v-if="showSetupPrompt"
						variant="primary"
						data-test="button-start-onboarding-empty"
						@click="emit('startOnboarding')">
						<template #icon>
							<RocketLaunchIcon :size="20" />
						</template>
						{{ t('attendance', 'Start setup') }}
					</NcButton>
					<NcButton v-else
						variant="primary"
						data-test="button-create-first-appointment"
						@click="emit('createAppointment')">
						<template #icon>
							<PlusIcon :size="20" />
						</template>
						{{ t('attendance', 'Create your first appointment') }}
					</NcButton>
				</template>
			</NcEmptyContent>
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
				<!-- Scrolling near the end loads the next page; the button is the
				     same action for keyboards and for a browser that never fires. -->
				<div v-if="hasMore"
					ref="loadMoreSentinel"
					class="load-more"
					data-test="appointments-load-more">
					<NcLoadingIcon v-if="loadingMore" :size="28" />
					<NcButton v-else variant="tertiary" @click="loadMore">
						{{ t('attendance', 'Load more') }}
					</NcButton>
				</div>
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
import { NcButton, NcChip, NcEmptyContent, NcLoadingIcon, NcPopover } from '@nextcloud/vue'
import { create as createConfetti } from 'canvas-confetti'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import AccountIcon from 'vue-material-design-icons/AccountOutline.vue'
import CalendarBlankIcon from 'vue-material-design-icons/CalendarBlank.vue'
import CalendarCheckIcon from 'vue-material-design-icons/CalendarCheck.vue'
import CalendarPlusIcon from 'vue-material-design-icons/CalendarPlus.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import MapMarkerIcon from 'vue-material-design-icons/MapMarkerOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import ProgressQuestion from 'vue-material-design-icons/ProgressQuestion.vue'
import RocketLaunchIcon from 'vue-material-design-icons/RocketLaunch.vue'
import TagIcon from 'vue-material-design-icons/TagOutline.vue'
import AppointmentListCard from '../components/appointment/AppointmentListCard.vue'
import DeleteAppointmentDialog from '../components/appointment/DeleteAppointmentDialog.vue'
import LoadingState from '../components/common/LoadingState.vue'
import SingleAppointmentExportDialog from '../components/SingleAppointmentExportDialog.vue'
import { useAppointmentResponse } from '../composables/useAppointmentResponse.js'
import { useCategories } from '../composables/useCategories.js'
import { useCategoryFilterChips } from '../composables/useCategoryFilterChips.js'
import { usePagedAppointments } from '../composables/usePagedAppointments.js'
import { usePermissions } from '../composables/usePermissions.js'
import { categoryIconComponent } from '../utils/categoryIcons.js'
import { VIEWS } from './appointmentViews.js'

const props = defineProps({
	view: {
		type: String,
		default: 'current',
		validator: (value) => value in VIEWS,
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
	'startOnboarding',
])

const activeSearch = computed(() => props.searchQuery.trim())

const viewDef = computed(() => VIEWS[props.view])
const pageHeading = computed(() => viewDef.value.heading())

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
	SCHEDULING: 'scheduling',
	ROLE: 'role',
})
const RESPONSE = Object.freeze({ YES: 'yes', MAYBE: 'maybe', NO: 'no', NONE: 'none' })
const STATUS = Object.freeze({ OPEN: 'open', CLOSED: 'closed', CANCELLED: 'cancelled' })
const SCHEDULING = Object.freeze({ NOT_OUT: 'not-scheduled-out', ONLY_IN: 'only-scheduled' })
const ROLE = Object.freeze({ ATTENDEE: 'attendee', ORGANIZER: 'organizer', UNINVOLVED: 'uninvolved' })

const {
	appointments,
	total,
	facets,
	loading,
	loadingMore,
	busy,
	hasMore,
	reload,
	loadMore,
} = usePagedAppointments(currentListParams)

// A role is only offered while it tells some appointments apart from others —
// a member who is simply invited everywhere gets no role filter at all. The
// server works that out over the whole list, not just the loaded part.
const splittingRoles = computed(() => facets.value.roles)

const { capabilities, config, loadPermissions } = usePermissions()
const { categories, loadCategories, getCategory } = useCategories()
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
		// Server-side, via BookingService::isScheduledOut/isScheduledIn. Two
		// steps of the same axis, so they share one filter and exclude each
		// other. Both narrow to the user's own appointments on their way.
		id: F.SCHEDULING,
		// TRANSLATORS: Filter group in the appointment list, about whether the
		// user got a place when the organizer planned the lineup (German "Einplanung").
		label: t('attendance', 'Scheduling'),
		icon: CalendarCheckIcon,
		options: [
			{
				id: SCHEDULING.NOT_OUT,
				// TRANSLATORS: Filter option in the appointment list. Hides appointments
				// where the user was explicitly not given a place after scheduling
				// finished (German "nicht ausgeplant" — not "abgesagt", which this app
				// uses for calling off an appointment).
				label: t('attendance', 'Not scheduled out'),
				visible: capabilities.bookingEnabled,
			},
			{
				id: SCHEDULING.ONLY_IN,
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
	{
		id: F.ROLE,
		// TRANSLATORS: Filter group in the appointment list, about how the user relates to an appointment: invited to it, organizing it, or neither (German "Meine Rolle").
		label: t('attendance', 'My role'),
		icon: AccountIcon,
		options: [
			{
				id: ROLE.ATTENDEE,
				// TRANSLATORS: Filter option under "My role". Keeps the appointments the user is invited to, whatever they answered — not "I said yes" (German "Ich nehme teil").
				label: t('attendance', 'I am an attendee'),
				visible: splittingRoles.value.includes(ROLE.ATTENDEE),
			},
			{
				id: ROLE.ORGANIZER,
				// TRANSLATORS: Filter option under "My role". Keeps the appointments the user is listed as an organizer of (German "Ich organisiere").
				label: t('attendance', 'I am an organizer'),
				visible: splittingRoles.value.includes(ROLE.ORGANIZER),
			},
			{
				id: ROLE.UNINVOLVED,
				// TRANSLATORS: Filter option under "My role". Keeps the appointments the user is neither invited to nor organizing — they only see them through a permission (German "Nicht beteiligt").
				label: t('attendance', 'Not involved'),
				visible: splittingRoles.value.includes(ROLE.UNINVOLVED),
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

const { selectedCategoryChips, categoryChipLabel } = useCategoryFilterChips(selectedCategoryIds, getCategory)

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
// feature later switched off) must stop taking effect, not keep filtering
// invisibly.
function activeFilterValue(id) {
	if (!viewDef.value.filtersApply) return null
	return filters.value.find((f) => f.id === id)?.value?.id ?? null
}

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

const hasActiveFilters = computed(() => Boolean(props.searchQuery.trim()
	|| (viewDef.value.filtersApply && (activeFilters.value.length || selectedLocations.value.length || selectedCategoryIds.value.length))))

// The server decides who gets a prompt; the filter state is ours — an empty
// filter result is not an empty instance.
const showSetupPrompt = computed(() => !hasActiveFilters.value && config.onboarding.setupPrompt)
const showFirstAppointmentPrompt = computed(() => !hasActiveFilters.value
	&& config.onboarding.firstAppointmentPrompt)

const emptyState = computed(() => {
	if (showSetupPrompt.value) {
		return {
			icon: RocketLaunchIcon,
			name: t('attendance', 'No appointments yet'),
			description: t('attendance', 'The setup wizard walks you through permissions, reminders and check-in so the first appointment lands right.'),
		}
	}
	if (showFirstAppointmentPrompt.value) {
		return {
			icon: CalendarPlusIcon,
			name: t('attendance', 'No appointments yet'),
			description: t('attendance', 'Create one and everybody can reply straight away.'),
		}
	}
	return {
		icon: CalendarBlankIcon,
		name: t('attendance', 'No appointments found'),
		description: hasActiveFilters.value ? t('attendance', 'No appointments match the active filters.') : '',
	}
})

// Typing must not fire a request per keystroke; everything else applies at once.
const debouncedSearch = ref(activeSearch.value)
let searchTimer = null
watch(activeSearch, (query) => {
	clearTimeout(searchTimer)
	searchTimer = setTimeout(() => {
		debouncedSearch.value = query
	}, 300)
})

// Every filter runs on the server — the list held here is only a page of it.
const listParams = computed(() => {
	const { params, filtersApply, mixesPastAndUpcoming } = viewDef.value
	// Both imply onlyForMe server-side, so neither needs to send it.
	const scheduling = {
		[SCHEDULING.NOT_OUT]: { notScheduledOut: true },
		[SCHEDULING.ONLY_IN]: { onlyScheduled: true },
	}[activeFilterValue(F.SCHEDULING)] ?? {}
	const filterParams = filtersApply
		? {
				status: activeFilterValue(F.STATUS),
				response: activeFilterValue(F.RESPONSE),
				// Sent as stored: which roles are on offer is only known once the
				// list is in, and the server ignores one that does not split it.
				role: filterValues.value[F.ROLE] ?? null,
				locations: selectedLocations.value,
				categoryIds: selectedCategoryIds.value,
			}
		: {}
	return {
		...params,
		...scheduling,
		...filterParams,
		search: debouncedSearch.value || null,
		// The All view keeps cancelled appointments in a section of their own.
		cancelledLast: mixesPastAndUpcoming || null,
	}
})

function currentListParams() {
	return listParams.value
}

// The filters offer what occurs in the whole list, not just in the loaded part.
const availableLocations = computed(() => facets.value.locations)
const availableCategories = computed(() => categories.filter((category) => facets.value.categoryIds.includes(category.id)))

// Reload what is on screen without a spinner or a jump back to the top.
const refresh = () => reload({ silent: true, keepLoaded: true })

// All view shows upcoming + past back-to-back; subdivide so the user knows
// where the boundary is. Other views are a single homogeneous list.
const visibleSections = computed(() => {
	if (!viewDef.value.mixesPastAndUpcoming) {
		return [{ key: 'all', label: '', items: appointments.value }]
	}
	// Cancelled appointments get their own section at the bottom, regardless of
	// past/upcoming, so they stay visible but clearly set apart. The server
	// sorts the pages the same way (cancelledLast) and says which half an
	// appointment belongs to — don't re-derive that from end_datetime here.
	const active = appointments.value.filter((a) => !a.cancelledAt)
	const cancelled = appointments.value.filter((a) => a.cancelledAt)
	const upcoming = active.filter((a) => !a.isPast)
	const past = active.filter((a) => a.isPast)
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
	// With a status filter on, the appointment may no longer belong here.
	if (activeFilterValue(F.STATUS)) {
		refresh()
	}
}

// Nothing left to answer: the celebratory "Hurray!" banner is the empty state
// here, so neither the heading nor the generic empty-state card belongs.
const showCelebration = computed(() => viewDef.value.celebratesEmpty && !loading.value && total.value === 0)

// Use the shared response composable
const { submitResponse: submitResponseApi } = useAppointmentResponse({
	onSuccess: () => {
		emit('responseUpdated')
		refresh()
	},
})

// Capabilities arriving at mount can flip the scheduling filter; the first
// load in onMounted already covers that, so only later changes refetch.
let listReady = false
watch(listParams, (next, previous) => {
	if (listReady && JSON.stringify(next) !== JSON.stringify(previous)) {
		reload({ silent: true })
	}
})

const loadMoreSentinel = ref(null)
let loadMoreObserver = null
// The sentinel only exists while there is more to load, so follow it around.
watch(loadMoreSentinel, (element) => {
	loadMoreObserver?.disconnect()
	if (!element || typeof IntersectionObserver === 'undefined') return
	loadMoreObserver = new IntersectionObserver((entries) => {
		if (entries.some((entry) => entry.isIntersecting)) {
			loadMore()
		}
	}, { rootMargin: '600px 0px' })
	loadMoreObserver.observe(element)
})
// An observer only reports changes: a sentinel still in view after a page
// arrived would never fire again, so it is observed afresh each time.
watch(() => appointments.value.length, async () => {
	await nextTick()
	if (loadMoreObserver && loadMoreSentinel.value) {
		loadMoreObserver.unobserve(loadMoreSentinel.value)
		loadMoreObserver.observe(loadMoreSentinel.value)
	}
})

async function submitResponse(appointmentId, response, acceptWaitlist = false) {
	const appointment = appointments.value.find((a) => a.id === appointmentId)
	const comment = appointment?.userResponse?.comment || ''
	await submitResponseApi(appointmentId, response, comment, acceptWaitlist)
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
		await refresh()
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
	clearTimeout(searchTimer)
	loadMoreObserver?.disconnect()
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
watch(loading, () => {
	if (showCelebration.value) {
		triggerConfetti()
	}
})

// Also trigger confetti when appointments list becomes empty (after responding to last one)
watch(total, (_, oldTotal) => {
	if (showCelebration.value && oldTotal > 0) {
		triggerConfetti()
	}
})

onMounted(async () => {
	await loadPermissions()
	await reload()
	listReady = true
})
</script>

<style scoped lang="scss">
.attendance-container {
	// No padding at the top: the app content already clears the navigation
	// toggle for every view (App.vue).
	padding: 0 20px 20px;
	max-width: 1200px;
	margin: 0 auto;
}

.appointments-list {
	max-width: 800px;
	margin: 0 auto;
}

.unanswered-banner-container {
	max-width: 800px;
	margin: 0 auto 20px;
}

// Size and top margin come from the shared rule in App.vue; only the column
// this view centres its heading in is local.
.page-heading {
	max-width: 800px;
	margin-inline: auto;
}

.load-more {
	display: flex;
	justify-content: center;
	min-height: 44px;
	margin-top: 4px;
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
