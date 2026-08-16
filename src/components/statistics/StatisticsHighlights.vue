<template>
	<div v-if="cards.length" class="highlights" data-test="statistics-highlights">
		<section
			v-for="card in cards"
			:key="card.key"
			class="highlights__card"
			:data-test="`statistics-highlight-${card.key}`">
			<h3 class="highlights__title">
				{{ card.label }}
			</h3>
			<ul class="highlights__rows">
				<li v-for="row in card.rows" :key="row.value" class="highlights__row">
					<span class="highlights__value">
						{{ formatValue(card, row) }}
						<span v-if="sharedBasis(card, row)" class="highlights__basis">
							{{ sharedBasis(card, row) }}
						</span>
					</span>
					<span v-if="selectable" class="highlights__names">
						<button
							v-for="person in row.people"
							:key="person.userId"
							type="button"
							class="highlights__person"
							@click="emit('selectPerson', person)">
							{{ nameOf(card, row, person) }}
						</button>
					</span>
					<span v-else class="highlights__names">
						{{ row.people.map((person) => nameOf(card, row, person)).join(", ") }}
					</span>
				</li>
			</ul>
		</section>
	</div>
</template>

<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { computed } from 'vue'
import { formatDate } from '../../utils/datetime.js'
import { cardRows, STATISTICS_CARDS } from '../../utils/statisticsCards.js'
import { formatRate } from '../../utils/statisticsColumns.js'

const props = defineProps({
	people: { type: Array, required: true },
	totals: { type: Object, required: true },
	visibleCards: { type: Array, required: true },
	selectable: { type: Boolean, default: false },
})

const emit = defineEmits(['selectPerson'])

// A card with nothing to say is dropped rather than rendered empty — an empty
// box reads as a bug, a missing one as "nothing to report".
const cards = computed(() => STATISTICS_CARDS
	.filter((card) => props.visibleCards.includes(card.key))
	.map((card) => ({ ...card, rows: cardRows(card, props.people, props.totals) }))
	.filter((card) => card.rows !== null))

/**
 * @param {object} card - Card descriptor.
 * @param {object} row - The row to render.
 * @return {string} Rendered for display.
 */
function formatValue(card, row) {
	if (card.date) {
		// TRANSLATORS: Shown instead of a date for somebody who was not recorded present at any appointment in the period.
		// Medium like the person sidebar: here the date is the content, not an
		// axis label, and "21 July 2026" beats "21/07/2026" for reading.
		return row.never ? t('attendance', 'Never') : formatDate(new Date(row.value))
	}
	if (card.basis) {
		return formatRate(row.value)
	}
	return String(row.value)
}

/**
 * The denominator a rate row rests on, when everybody in it shares one — which
 * is the normal case, the card only ever considering comparable bases. Said
 * once beside the value instead of after every name, where four repetitions of
 * "(4 appointments)" drown out the names themselves.
 *
 * @param {object} card - Card descriptor.
 * @param {object} row - The row to label.
 * @return {?string} The basis, or null when it differs within the row.
 */
function sharedBasis(card, row) {
	if (!card.basis) {
		return null
	}
	const first = row.people[0][card.basis]
	return row.people.every((person) => person[card.basis] === first)
		? `(${n('attendance', '%n appointment', '%n appointments', first)})`
		: null
}

/**
 * Two people can share a rate on different numbers of appointments. Where that
 * happens the row cannot label itself, so each name carries its own basis.
 *
 * @param {object} card - Card descriptor.
 * @param {object} row - The row the name sits in.
 * @param {object} person - A person row.
 * @return {string} The name, with its basis where the row could not say it.
 */
function nameOf(card, row, person) {
	if (!card.basis || sharedBasis(card, row)) {
		return person.displayName
	}
	return `${person.displayName} (${n('attendance', '%n appointment', '%n appointments', person[card.basis])})`
}
</script>

<style scoped>
.highlights {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    margin-bottom: 16px;
}

/* Same chrome as the chart cards below, which render directly next to these —
   only the track is narrower, because the content is. */
.highlights__card {
    background-color: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    padding: 10px 14px;
}

.highlights__title {
    font-size: 13px;
    font-weight: bold;
    margin: 0 0 6px;
}

.highlights__rows {
    display: flex;
    flex-direction: column;
    /* Groups need more air between them than a value has to its own names, or
       the reader has to work out which names belong to which value. */
    gap: 14px;
}

.highlights__row {
    font-size: 13px;
    line-height: 1.35;
}

/* The value heads its group rather than sitting beside it: it is nowrap and
   carries the basis, so as a column it left the names a strip too narrow to
   fit one. Above them they get the whole card. */
.highlights__value {
    display: block;
    font-weight: bold;
}

.highlights__basis {
    color: var(--color-text-maxcontrast);
    font-weight: normal;
}

/* The separator is a gap plus a pseudo-element, not literal text: relying on
   the whitespace between the buttons put a space in front of every comma and
   left a stray one at the start of a wrapped line. */
.highlights__names {
    color: var(--color-text-maxcontrast);
    column-gap: 4px;
    display: flex;
    flex-wrap: wrap;
}

/* Nextcloud styles every button as a full-width block with its own min-height;
   these sit in a comma-separated run, so the reset has to take the box model
   with it, not just the colours. */
.highlights__person {
    background: none;
    border: none;
    color: inherit;
    cursor: pointer;
    font: inherit;
    line-height: inherit;
    margin: 0;
    min-height: 0;
    padding: 0;
    text-align: start;
    width: auto;
}

.highlights__person:hover {
    text-decoration: underline;
}

.highlights__person:not(:last-child)::after {
    content: ",";
}
</style>
