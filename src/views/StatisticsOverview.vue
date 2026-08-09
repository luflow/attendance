<template>
	<div class="attendance-container statistics">
		<h2 class="page-heading" data-test="page-heading">
			{{ t("attendance", "Statistics") }}
		</h2>

		<div class="statistics__filters" data-test="statistics-filters">
			<div class="statistics__filter">
				<label for="statistics-period-type">{{ t("attendance", "Period") }}</label>
				<select
					id="statistics-period-type"
					v-model="periodType"
					data-test="statistics-period-type"
					@change="onPeriodTypeChange">
					<option value="year">
						{{ t("attendance", "Year") }}
					</option>
					<option value="half">
						{{ t("attendance", "Half-year") }}
					</option>
					<option value="quarter">
						{{ t("attendance", "Quarter") }}
					</option>
					<option value="custom">
						{{ t("attendance", "Custom") }}
					</option>
				</select>
			</div>

			<div v-if="periodType !== 'custom'" class="statistics__filter">
				<label for="statistics-period">{{ t("attendance", "Range") }}</label>
				<select id="statistics-period" v-model="periodKey" data-test="statistics-period">
					<option v-for="option in periodChoices" :key="option.key" :value="option.key">
						{{ option.label }}
					</option>
				</select>
			</div>

			<template v-else>
				<div class="statistics__filter">
					<label for="statistics-from">{{ t("attendance", "Start date") }}</label>
					<input id="statistics-from"
						v-model="customFrom"
						type="date"
						data-test="statistics-from">
				</div>
				<div class="statistics__filter">
					<label for="statistics-to">{{ t("attendance", "End date") }}</label>
					<input id="statistics-to"
						v-model="customTo"
						type="date"
						data-test="statistics-to">
				</div>
			</template>

			<div v-if="capabilities.teamsAvailable" class="statistics__filter">
				<label for="statistics-group-by">{{ t("attendance", "Group by") }}</label>
				<select id="statistics-group-by" v-model="groupBy" data-test="statistics-group-by">
					<option value="groups">
						{{ t("attendance", "Groups") }}
					</option>
					<option value="teams">
						{{ t("attendance", "Teams") }}
					</option>
				</select>
			</div>

			<NcPopover v-if="capabilities.categoriesAvailable && categories.length">
				<template #trigger>
					<NcButton
						:variant="categoryFilterActive ? 'secondary' : 'tertiary'"
						data-test="statistics-filter-category">
						<template #icon>
							<TagIcon :size="20" />
						</template>
						{{ t("attendance", "Category") }}
					</NcButton>
				</template>
				<template #default>
					<ul class="statistics__options" role="menu">
						<li v-for="category in categories" :key="category.id" role="presentation">
							<button
								type="button"
								role="menuitemcheckbox"
								:aria-checked="selectedCategoryIds.includes(category.id)"
								class="statistics__option-button"
								@click="toggleCategory(category.id)">
								<component :is="categoryIconComponent(category.icon)" :size="18" />
								{{ category.name }}
								<CheckIcon v-if="selectedCategoryIds.includes(category.id)" :size="18" />
							</button>
						</li>
						<li role="presentation">
							<button
								type="button"
								role="menuitemcheckbox"
								:aria-checked="includeUncategorized"
								class="statistics__option-button"
								data-test="statistics-filter-uncategorized"
								@click="includeUncategorized = !includeUncategorized">
								{{ t("attendance", "Without category") }}
								<CheckIcon v-if="includeUncategorized" :size="18" />
							</button>
						</li>
					</ul>
				</template>
			</NcPopover>

			<NcTextField
				v-if="!reduced"
				v-model="search"
				:label="t('attendance', 'Search people')"
				class="statistics__search"
				data-test="statistics-search" />

			<NcButton
				v-if="!reduced"
				:disabled="exporting || !statistics"
				data-test="statistics-export"
				@click="exportStatistics">
				<template #icon>
					<DownloadIcon :size="20" />
				</template>
				{{ t("attendance", "Export") }}
			</NcButton>
		</div>

		<LoadingState v-if="loading" :text="t('attendance', 'Loading …')" />

		<NcEmptyContent
			v-else-if="rangeError"
			:name="t('attendance', 'Too many appointments in this period')"
			:description="n('attendance', 'The evaluation handles up to %n appointment at a time. Please choose a shorter period.', 'The evaluation handles up to %n appointments at a time. Please choose a shorter period.', rangeError.limit)"
			data-test="statistics-range-error">
			<template #icon>
				<ChartLineIcon :size="20" />
			</template>
		</NcEmptyContent>

		<NcEmptyContent
			v-else-if="statistics && statistics.appointmentCount === 0"
			:name="t('attendance', 'No appointments in this period')"
			data-test="statistics-empty">
			<template #icon>
				<ChartLineIcon :size="20" />
			</template>
		</NcEmptyContent>

		<template v-else-if="statistics">
			<p class="statistics__summary" data-test="statistics-summary">
				{{ n("attendance", "%n appointment", "%n appointments", statistics.appointmentCount) }}
				·
				{{
					t("attendance", "Attendance recorded for {recorded} of {past} past appointments", {
						recorded: statistics.attendanceRecordedCount,
						past: statistics.pastCount,
					})
				}}
			</p>

			<StatisticsCharts
				v-if="!reduced"
				:statistics="statistics"
				:groupBy="statistics.groupBy" />

			<StatisticsTable
				:sections="statistics.sections"
				:people="statistics.people"
				:totals="statistics.totals"
				:reduced="reduced"
				:ownUserId="ownUserId"
				:search="search"
				@selectPerson="selectedPerson = $event" />

			<p v-if="!reduced" class="statistics__footnote">
				{{ t("attendance", "People in several groups are counted in each of them. The total row counts every person once.") }}
			</p>
		</template>

		<Teleport v-if="selectedPerson" to="#attendance-sidebar-slot">
			<StatisticsPersonSidebar
				:person="selectedPerson"
				:query="query"
				@close="selectedPerson = null" />
		</Teleport>
	</div>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcPopover, NcTextField } from '@nextcloud/vue'
import { computed, defineAsyncComponent, ref, watch } from 'vue'
import ChartLineIcon from 'vue-material-design-icons/ChartLine.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import TagIcon from 'vue-material-design-icons/TagOutline.vue'
import LoadingState from '../components/common/LoadingState.vue'
import StatisticsPersonSidebar from '../components/statistics/StatisticsPersonSidebar.vue'
import StatisticsTable from '../components/statistics/StatisticsTable.vue'
import { useCategories } from '../composables/useCategories.js'
import { usePermissions } from '../composables/usePermissions.js'
import { categoryIconComponent } from '../utils/categoryIcons.js'
import { defaultPeriod, periodFromKey, periodOptions, periodTypeForKey } from '../utils/statisticsPeriods.js'

// chart.js is only ever needed here — keep it out of the bundle every other
// view downloads.
const StatisticsCharts = defineAsyncComponent(() => import('../components/statistics/StatisticsCharts.vue'))

const { permissions, capabilities } = usePermissions()
const { categories, loadCategories } = useCategories()
loadCategories()

const statistics = ref(null)
const loading = ref(true)
const rangeError = ref(null)
const exporting = ref(false)
const search = ref('')
const selectedPerson = ref(null)
const ownUserId = ref(window.OC?.getCurrentUser?.()?.uid ?? '')

const initial = readUrlState()
const periodType = ref(initial.periodType)
const periodKey = ref(initial.periodKey)
const customFrom = ref(initial.from)
const customTo = ref(initial.to)
const selectedCategoryIds = ref(initial.categoryIds)
const includeUncategorized = ref(initial.includeUncategorized)
const groupBy = ref(initial.groupBy)

const reduced = computed(() => !permissions.canSeeStatistics)

const periodChoices = computed(() => periodOptions(periodType.value))

const categoryFilterActive = computed(() => selectedCategoryIds.value.length > 0 || includeUncategorized.value)

const range = computed(() => {
	if (periodType.value === 'custom') {
		return { from: customFrom.value, to: customTo.value }
	}
	const period = periodFromKey(periodKey.value) ?? defaultPeriod()
	return { from: period.from, to: period.to }
})

const query = computed(() => ({
	startDate: range.value.from || undefined,
	endDate: range.value.to || undefined,
	categoryIds: selectedCategoryIds.value,
	includeUncategorized: includeUncategorized.value,
	groupBy: groupBy.value,
}))

// Keyed on the serialized filter, not the object: `query` allocates a fresh
// literal per evaluation, so a deep watcher would refire on every unrelated
// re-render — and each spurious fire is a full server-side evaluation.
watch(() => JSON.stringify(query.value), () => {
	writeUrlState()
	load()
}, { immediate: true })

/**
 * Fetch the evaluation for the filters currently in effect.
 */
async function load() {
	if (periodType.value === 'custom' && (!customFrom.value || !customTo.value)) {
		return
	}

	loading.value = true
	rangeError.value = null
	try {
		const response = await axios.get(generateUrl('/apps/attendance/api/statistics'), {
			params: query.value,
		})
		statistics.value = response.data
	} catch (error) {
		if (error.response?.status === 400 && error.response.data?.limit) {
			rangeError.value = error.response.data
			statistics.value = null
		} else {
			console.error('Failed to load statistics:', error)
			showError(t('attendance', 'Failed to load statistics'))
		}
	} finally {
		loading.value = false
	}
}

/**
 * Write the ODS file into the user's Attendance folder.
 */
async function exportStatistics() {
	exporting.value = true
	try {
		const response = await axios.post(
			generateUrl('/apps/attendance/api/statistics/export'),
			query.value,
		)
		showSuccess(t('attendance', 'Export created: {filename}', { filename: response.data.filename }))
		window.location.href = generateUrl('/apps/files/?dir=/Attendance')
	} catch (error) {
		showError(error.response?.data?.error || t('attendance', 'Failed to export statistics'))
	} finally {
		exporting.value = false
	}
}

/**
 * @param {number} categoryId - Category to toggle.
 */
function toggleCategory(categoryId) {
	selectedCategoryIds.value = selectedCategoryIds.value.includes(categoryId)
		? selectedCategoryIds.value.filter((id) => id !== categoryId)
		: [...selectedCategoryIds.value, categoryId]
}

/**
 * Keep the period list and the selected entry consistent after a type switch.
 */
function onPeriodTypeChange() {
	if (periodType.value === 'custom') {
		customFrom.value = customFrom.value || range.value.from
		customTo.value = customTo.value || range.value.to
		return
	}
	periodKey.value = periodChoices.value[0]?.key ?? defaultPeriod().key
}

/**
 * @return {object} Filter state carried on the URL, falling back to the running year.
 */
function readUrlState() {
	const params = new URLSearchParams(window.location.search)
	const fallback = defaultPeriod()
	const key = params.get('period') ?? fallback.key
	const type = periodTypeForKey(key)

	return {
		periodType: type,
		periodKey: type === 'custom' ? fallback.key : key,
		from: params.get('from') ?? '',
		to: params.get('to') ?? '',
		categoryIds: (params.get('categories') ?? '')
			.split(',')
			.map((id) => Number(id))
			.filter((id) => Number.isInteger(id) && id > 0),
		includeUncategorized: params.get('uncategorized') === '1',
		groupBy: params.get('groupBy') === 'teams' ? 'teams' : 'groups',
	}
}

/**
 * Mirror the filters into the URL so an evaluation can be linked to. Replaces
 * rather than pushes — every filter click would otherwise land in the history.
 */
function writeUrlState() {
	const params = new URLSearchParams()
	params.set('period', periodType.value === 'custom' ? 'custom' : periodKey.value)
	if (periodType.value === 'custom') {
		if (customFrom.value) params.set('from', customFrom.value)
		if (customTo.value) params.set('to', customTo.value)
	}
	if (selectedCategoryIds.value.length) {
		params.set('categories', selectedCategoryIds.value.join(','))
	}
	if (includeUncategorized.value) params.set('uncategorized', '1')
	if (groupBy.value === 'teams') params.set('groupBy', 'teams')

	window.history.replaceState(
		window.history.state,
		'',
		`${window.location.pathname}?${params.toString()}`,
	)
}
</script>

<style scoped>
.statistics {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px 40px;
}

.statistics__filters {
    align-items: flex-end;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 16px;
}

.statistics__filter {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.statistics__filter label {
    color: var(--color-text-maxcontrast);
    font-size: 0.85rem;
}

.statistics__search {
    max-width: 220px;
}

.statistics__options {
    min-width: 220px;
    padding: 4px;
}

.statistics__option-button {
    align-items: center;
    background: none;
    border: none;
    border-radius: var(--border-radius);
    display: flex;
    gap: 8px;
    padding: 6px 8px;
    text-align: start;
    width: 100%;
}

.statistics__option-button:hover {
    background-color: var(--color-background-hover);
}

.statistics__summary {
    color: var(--color-text-maxcontrast);
    margin-bottom: 16px;
}

.statistics__footnote {
    color: var(--color-text-maxcontrast);
    font-size: 0.85rem;
    margin-top: 12px;
}
</style>
