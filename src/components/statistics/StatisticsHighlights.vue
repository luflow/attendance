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
					<span class="highlights__value">{{ formatValue(card, row) }}</span>
					<span v-if="selectable" class="highlights__names">
						<button
							v-for="person in row.people"
							:key="person.userId"
							type="button"
							class="highlights__person"
							@click="emit('selectPerson', person)">
							{{ nameOf(card, person) }}
						</button>
					</span>
					<span v-else class="highlights__names">
						{{ row.people.map((person) => nameOf(card, person)).join(", ") }}
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
		return row.never ? t('attendance', 'Never') : formatDate(new Date(row.value).toISOString(), 'short')
	}
	if (card.basis) {
		return formatRate(row.value)
	}
	return String(row.value)
}

/**
 * Rate cards carry the denominator behind each name: two people can share
 * 100 % on wildly different numbers of appointments, and the reader deserves
 * to see which.
 *
 * @param {object} card - Card descriptor.
 * @param {object} person - A person row.
 * @return {string} The name, with its basis where one applies.
 */
function nameOf(card, person) {
	if (!card.basis) {
		return person.displayName
	}
	return `${person.displayName} (${n('attendance', '%n appointment', '%n appointments', person[card.basis])})`
}
</script>

<style scoped>
.highlights {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
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
    gap: 4px;
}

.highlights__row {
    display: flex;
    font-size: 13px;
    gap: 8px;
}

.highlights__value {
    color: var(--color-main-text);
    font-weight: bold;
    white-space: nowrap;
}

.highlights__names {
    color: var(--color-text-maxcontrast);
}

/* Reset rather than restyle: these sit inline inside a comma-separated run, so
   any of the button chrome the server theme adds would break the line up. */
.highlights__person {
    background: none;
    border: none;
    color: inherit;
    cursor: pointer;
    font: inherit;
    margin: 0;
    padding: 0;
    text-align: start;
}

.highlights__person:hover {
    text-decoration: underline;
}

.highlights__person:not(:first-child)::before {
    content: ", ";
}
</style>
