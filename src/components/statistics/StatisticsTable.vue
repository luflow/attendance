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
								v-if="activeSort.key === 'displayName'"
								:size="16" />
						</button>
					</th>
					<th v-if="showSections" scope="col" class="statistics-table__sections">
						{{ sectionColumnLabel }}
					</th>
					<th v-for="column in columns" :key="column.key" scope="col">
						<button
							type="button"
							class="statistics-table__sort"
							:title="column.hint"
							:data-test="`statistics-sort-${column.key}`"
							@click="toggleSort(column.key)">
							{{ column.label }}
							<component
								:is="sortIcon"
								v-if="activeSort.key === column.key"
								:size="16" />
						</button>
					</th>
				</tr>
			</thead>

			<tbody v-if="reduced" data-test="statistics-own-row">
				<tr v-if="ownPerson" class="statistics-table__own">
					<th scope="row">
						<!-- TRANSLATORS: Row label on the one row a user without the statistics permission sees — their own counts. -->
						{{ t("attendance", "Your numbers") }}
					</th>
					<td v-if="showSections" class="statistics-table__sections">
						{{ sectionsOf(ownPerson) }}
					</td>
					<td v-for="column in columns" :key="column.key">
						{{ cell(ownPerson, column) }}
					</td>
				</tr>
				<tr v-else>
					<td :colspan="columnCount">
						{{ t("attendance", "You were not invited to any appointment in this period") }}
					</td>
				</tr>
			</tbody>

			<tbody v-for="body in bodies" :key="body.id">
				<tr
					v-if="body.section"
					class="statistics-table__section"
					:data-test="`statistics-section-${body.section.id}`">
					<th scope="row">
						{{ body.section.displayName }}
						<span class="statistics-table__count">
							{{ n("attendance", "%n person", "%n people", body.section.personCount) }}
						</span>
					</th>
					<td v-for="column in columns" :key="column.key">
						{{ cell(body.section, column) }}
					</td>
				</tr>
				<tr
					v-for="person in body.people"
					:key="`${body.id}-${person.userId}`"
					:class="{
						'statistics-table__person': selectable,
						'statistics-table__person--self': person.userId === ownUserId,
					}"
					data-test="statistics-person-row"
					:tabindex="selectable ? 0 : undefined"
					@click="selectable && emit('selectPerson', person)"
					@keydown.enter="selectable && emit('selectPerson', person)">
					<th scope="row">
						{{ person.displayName }}
						<span v-if="person.isGuest" class="statistics-table__badge">
							<!-- TRANSLATORS: Badge next to a name — this person was invited by email and has no account on this server. -->
							{{ t("attendance", "Guest") }}
						</span>
					</th>
					<td v-if="showSections" class="statistics-table__sections">
						{{ sectionsOf(person) }}
					</td>
					<td v-for="column in columns" :key="column.key">
						{{ cell(person, column) }}
					</td>
				</tr>
			</tbody>

			<tfoot v-if="!reduced">
				<tr data-test="statistics-totals">
					<th scope="row">
						<!-- TRANSLATORS: Label of the last table row, summing every person above it — each person counted once, even when listed under several groups. -->
						{{ t("attendance", "Total") }}
					</th>
					<td v-if="showSections" />
					<td v-for="column in columns" :key="column.key">
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
import { formatRate, SECTIONS_COLUMN, sectionsColumnLabel, STATISTICS_COLUMNS } from '../../utils/statisticsColumns.js'

const props = defineProps({
	sections: { type: Array, required: true },
	people: { type: Array, required: true },
	totals: { type: Object, required: true },
	reduced: { type: Boolean, default: false },
	selectable: { type: Boolean, default: false },
	grouped: { type: Boolean, required: true },
	visibleColumns: { type: Array, required: true },
	groupBy: { type: String, default: 'groups' },
	ownUserId: { type: String, default: '' },
	search: { type: String, default: '' },
})

const emit = defineEmits(['selectPerson'])

const sortKey = ref('displayName')
const sortAsc = ref(true)

const columns = computed(() => STATISTICS_COLUMNS.filter((column) => props.visibleColumns.includes(column.key)))

// A column the compact view drops takes its sort with it, arrow and all. Derived
// rather than reset, so no render can fall between the two.
const activeSort = computed(() => {
	const shown = sortKey.value === 'displayName'
		|| columns.value.some((column) => column.key === sortKey.value)
	return shown ? { key: sortKey.value, asc: sortAsc.value } : { key: 'displayName', asc: true }
})

const sortIcon = computed(() => (activeSort.value.asc ? MenuUpIcon : MenuDownIcon))

// Ungrouped, the membership a section heading would have carried becomes a
// column of its own — it is the one thing a flat list would otherwise lose.
// Grouped, the headings already say it, so the column goes whatever is ticked.
const showSections = computed(() => !props.grouped && props.visibleColumns.includes(SECTIONS_COLUMN))

// The name column is always there; the sections column joins it when shown.
const columnCount = computed(() => columns.value.length + 1 + (showSections.value ? 1 : 0))

const sectionColumnLabel = computed(() => sectionsColumnLabel(props.groupBy))

const sectionNames = computed(() => {
	return Object.fromEntries(props.sections.map((section) => [section.id, section.displayName]))
})

const ownPerson = computed(() => props.people.find((person) => person.userId === props.ownUserId) ?? null)

const visiblePeople = computed(() => {
	const needle = props.search.trim().toLowerCase()
	const matching = needle === ''
		? [...props.people]
		: props.people.filter((person) => person.displayName.toLowerCase().includes(needle))

	const { key, asc } = activeSort.value
	const direction = asc ? 1 : -1

	return matching.sort((a, b) => {
		if (key === 'displayName') {
			return direction * a.displayName.localeCompare(b.displayName)
		}
		// Rates are null without a basis; those rows belong at the end either way.
		const left = a[key]
		const right = b[key]
		if (left === null) return 1
		if (right === null) return -1
		return direction * (left - right)
	})
})

// One `tbody` per rendered block: a section with its people when grouped, a
// single unheaded block otherwise. Ungrouped lists each person once, so it is
// also the only view whose rows add up to the totals.
const bodies = computed(() => {
	if (props.reduced) {
		return []
	}
	if (!props.grouped) {
		return [{ id: 'all', section: null, people: visiblePeople.value }]
	}

	return props.sections.map((section) => ({
		id: section.id,
		section,
		people: visiblePeople.value.filter((person) => person.sections.includes(section.id)),
	}))
})

/**
 * @param {object} person - A person row.
 * @return {string} The sections they belong to, named.
 */
function sectionsOf(person) {
	return person.sections.map((id) => sectionNames.value[id] ?? id).join(', ')
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
	return formatRate(value)
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

/* The name has to survive scrolling right — without it the numbers under the
   cursor belong to nobody. It paints its own background, so it takes whatever
   colour the row is carrying rather than a hardcoded one. Paired with the
   sticky header below, the top-left corner needs the higher stacking order or
   the two overlap. */
.statistics-table th[scope="row"],
.statistics-table thead th:first-child,
.statistics-table tfoot th {
    background-color: var(--row-background, var(--color-main-background));
    inset-inline-start: 0;
    position: sticky;
    z-index: 1;
}

.statistics-table thead th:first-child {
    z-index: 3;
}

/* Compounded with the element: the generic `th`/`td` rule above is a class plus
   an element, so a bare class would lose to it. */
.statistics-table th[scope="row"],
.statistics-table th.statistics-table__name,
.statistics-table th.statistics-table__sections,
.statistics-table td.statistics-table__sections {
    text-align: start;
}

/* Names are the one variable-width column; without a floor the numeric columns
   squeeze it until "Christina Vogel" has less room than "Nicht erfasst". */
.statistics-table__name {
    min-width: 12rem;
}

/* The one other column carrying prose, and the only one allowed to wrap — a
   person in four groups would otherwise stretch the table past the viewport. */
.statistics-table__sections {
    color: var(--color-text-maxcontrast);
    max-width: 16rem;
    white-space: normal;
}

.statistics-table thead th {
    position: sticky;
    top: 0;
    background-color: var(--color-main-background);
    z-index: 2;
}

.statistics-table__sort {
    align-items: center;
    background: none;
    border: none;
    color: var(--color-main-text);
    cursor: pointer;
    /* Inline-flex, so the sort arrow sits beside the label instead of being
       pushed onto a second line — and so the cell's text-align still places
       the whole button. */
    display: inline-flex;
    font-weight: bold;
    gap: 2px;
    padding: 0;
}

/* Row states set a custom property rather than a background: it inherits into
   every cell including the sticky one, so no rule has to out-specify another. */
.statistics-table tbody td,
.statistics-table tbody th {
    background-color: var(--row-background, transparent);
}

.statistics-table__section {
    --row-background: var(--color-background-hover);
    font-weight: bold;
}

/* On the cells as well as the row: the row's value only reaches them by
   inheritance, which any `th`/`td` rule from the server theme outranks. */
.statistics-table__person,
.statistics-table__person > * {
    cursor: pointer;
}

.statistics-table__person:hover {
    --row-background: var(--color-background-hover);
}

.statistics-table__own,
.statistics-table__person--self,
.statistics-table__person--self:hover {
    --row-background: var(--color-primary-element-light);
    font-weight: bold;
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
