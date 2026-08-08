<template>
	<div class="statistics-table__scroll">
		<table class="statistics-table" data-test="statistics-table">
			<thead>
				<tr>
					<th scope="col" class="statistics-table__name">
						<button
							type="button"
							class="statistics-table__sort"
							@click="toggleSort('displayName')">
							{{ t("attendance", "Name") }}
							<component
								:is="sortIcon"
								v-if="sortKey === 'displayName'"
								:size="16" />
						</button>
					</th>
					<th v-for="column in COLUMNS" :key="column.key" scope="col">
						<button
							type="button"
							class="statistics-table__sort"
							:title="column.hint"
							:data-test="`statistics-sort-${column.key}`"
							@click="toggleSort(column.key)">
							{{ column.label }}
							<component
								:is="sortIcon"
								v-if="sortKey === column.key"
								:size="16" />
						</button>
					</th>
				</tr>
			</thead>

			<tbody v-if="reduced" data-test="statistics-own-row">
				<tr v-if="ownPerson" class="statistics-table__own">
					<th scope="row">
						{{ t("attendance", "Your numbers") }}
					</th>
					<td v-for="column in COLUMNS" :key="column.key">
						{{ cell(ownPerson, column) }}
					</td>
				</tr>
			</tbody>

			<tbody v-for="section in sections" :key="section.id">
				<tr class="statistics-table__section" :data-test="`statistics-section-${section.id}`">
					<th scope="row">
						{{ section.displayName }}
						<span class="statistics-table__count">
							{{ n("attendance", "%n person", "%n people", section.personCount) }}
						</span>
					</th>
					<td v-for="column in COLUMNS" :key="column.key">
						{{ cell(section, column) }}
					</td>
				</tr>
				<tr
					v-for="person in peopleIn(section.id)"
					:key="`${section.id}-${person.userId}`"
					class="statistics-table__person"
					data-test="statistics-person-row"
					tabindex="0"
					@click="emit('selectPerson', person)"
					@keydown.enter="emit('selectPerson', person)">
					<th scope="row">
						{{ person.displayName }}
						<span v-if="person.isGuest" class="statistics-table__badge">
							{{ t("attendance", "Guest") }}
						</span>
					</th>
					<td v-for="column in COLUMNS" :key="column.key">
						{{ cell(person, column) }}
					</td>
				</tr>
			</tbody>

			<tfoot>
				<tr data-test="statistics-totals">
					<th scope="row">
						{{ t("attendance", "Total") }}
					</th>
					<td v-for="column in COLUMNS" :key="column.key">
						{{ cell(totals, column) }}
					</td>
				</tr>
			</tfoot>
		</table>
	</div>
</template>

<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import MenuDownIcon from 'vue-material-design-icons/MenuDown.vue'
import MenuUpIcon from 'vue-material-design-icons/MenuUp.vue'

const props = defineProps({
	sections: { type: Array, required: true },
	people: { type: Array, required: true },
	totals: { type: Object, required: true },
	reduced: { type: Boolean, default: false },
	ownUserId: { type: String, default: '' },
	search: { type: String, default: '' },
})

const emit = defineEmits(['selectPerson'])

const COLUMNS = [
	{ key: 'targetCount', label: t('attendance', 'Appointments') },
	{ key: 'yes', label: t('attendance', 'Yes') },
	{ key: 'no', label: t('attendance', 'No') },
	{ key: 'maybe', label: t('attendance', 'Maybe') },
	{ key: 'noResponse', label: t('attendance', 'No response') },
	{ key: 'present', label: t('attendance', 'Present') },
	{ key: 'absent', label: t('attendance', 'Absent') },
	{ key: 'notRecorded', label: t('attendance', 'Not recorded') },
	{
		key: 'noShow',
		label: t('attendance', 'No-show'),
		hint: t('attendance', 'Said yes but was recorded as absent'),
	},
	{ key: 'responseRate', label: t('attendance', 'Response rate'), rate: true },
	{ key: 'acceptRate', label: t('attendance', 'Acceptance rate'), rate: true },
	{ key: 'attendanceRate', label: t('attendance', 'Attendance rate'), rate: true },
]

const sortKey = ref('displayName')
const sortAsc = ref(true)

const sortIcon = computed(() => (sortAsc.value ? MenuUpIcon : MenuDownIcon))

const ownPerson = computed(() => props.people.find((person) => person.userId === props.ownUserId) ?? null)

const visiblePeople = computed(() => {
	const needle = props.search.trim().toLowerCase()
	const matching = needle === ''
		? [...props.people]
		: props.people.filter((person) => person.displayName.toLowerCase().includes(needle))

	return matching.sort((a, b) => {
		const direction = sortAsc.value ? 1 : -1
		if (sortKey.value === 'displayName') {
			return direction * a.displayName.localeCompare(b.displayName)
		}
		// Rates are null without a basis; those rows belong at the end either way.
		const left = a[sortKey.value]
		const right = b[sortKey.value]
		if (left === null) return 1
		if (right === null) return -1
		return direction * (left - right)
	})
})

/**
 * @param {string} sectionId - Section to list.
 * @return {Array<object>} People shown under that section.
 */
function peopleIn(sectionId) {
	if (props.reduced) {
		return []
	}
	return visiblePeople.value.filter((person) => person.sections.includes(sectionId))
}

/**
 * @param {string} key - Column key.
 */
function toggleSort(key) {
	if (sortKey.value === key) {
		sortAsc.value = !sortAsc.value
		return
	}
	sortKey.value = key
	// Counts read most usefully highest-first, names alphabetically.
	sortAsc.value = key === 'displayName'
}

/**
 * @param {object} row - Person, section or totals row.
 * @param {object} column - Column descriptor.
 * @return {string} Rendered value.
 */
function cell(row, column) {
	const value = row[column.key]
	if (!column.rate) {
		return String(value ?? 0)
	}
	return value === null || value === undefined
		? '–'
		: `${Math.round(value * 1000) / 10} %`
}
</script>

<style scoped>
.statistics-table__scroll {
    overflow-x: auto;
}

.statistics-table {
    border-collapse: collapse;
    min-width: 100%;
}

.statistics-table th,
.statistics-table td {
    border-bottom: 1px solid var(--color-border);
    padding: 6px 10px;
    text-align: end;
    white-space: nowrap;
}

.statistics-table th[scope="row"],
.statistics-table__name {
    text-align: start;
}

.statistics-table thead th {
    position: sticky;
    top: 0;
    background-color: var(--color-main-background);
    z-index: 1;
}

.statistics-table__sort {
    background: none;
    border: none;
    color: var(--color-main-text);
    cursor: pointer;
    font-weight: bold;
    padding: 0;
}

.statistics-table__section > * {
    background-color: var(--color-background-hover);
    font-weight: bold;
}

.statistics-table__own > * {
    background-color: var(--color-primary-element-light);
    font-weight: bold;
}

.statistics-table__person {
    cursor: pointer;
}

.statistics-table__person:hover > * {
    background-color: var(--color-background-hover);
}

.statistics-table__count,
.statistics-table__badge {
    color: var(--color-text-maxcontrast);
    font-weight: normal;
    margin-inline-start: 6px;
}

.statistics-table tfoot th,
.statistics-table tfoot td {
    border-top: 2px solid var(--color-border-dark);
    font-weight: bold;
}
</style>
