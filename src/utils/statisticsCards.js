import { translate as t } from '@nextcloud/l10n'

/**
 * At most this many rows per card. Rows are *values*, not people — everyone
 * tied at a value shares one row, so a five-row card can name any number of
 * people without inventing a rank order between equals.
 */
const MAX_ROWS = 5

/**
 * A rate over very few appointments is not comparable with one over the whole
 * period: somebody invited to their first appointment and present at it holds
 * 100 % and would lead every card. Only people whose denominator reaches this
 * share of the widest one in the period are considered. Relative rather than a
 * fixed number, because a quarter with four appointments and a year with sixty
 * have nothing in common.
 */
const MIN_BASIS_SHARE = 0.5

/**
 * Score of somebody who was never recorded present. Sorts to the head of the
 * ascending date card instead of dropping out of it, and Number.isFinite keeps
 * it out of the mean for free. Named so the component can ask about it without
 * spelling the sentinel a second time.
 */
const NEVER_PRESENT = -Infinity

/**
 * Every card, in display order.
 *
 * `direction` says which end is interesting and therefore which side of the
 * average survives: `desc` keeps everyone at or above it, `asc` everyone at or
 * below. The award cards are `desc`, so nobody is ever shown as the tail of a
 * ranking; "longest not attended" is `asc`, where the tail is the whole point.
 *
 * `basis` marks a rate: those cards carry their denominator per name and drop
 * anyone below MIN_BASIS_SHARE. Count cards need neither — a small denominator
 * cannot win a count.
 */
export const STATISTICS_CARDS = [
	{
		key: 'attendanceRate',
		// TRANSLATORS: Card title. Lists the people who turned up most reliably.
		label: t('attendance', 'Highest attendance rate'),
		metric: 'attendanceRate',
		basis: 'attendanceBase',
		direction: 'desc',
		default: true,
	},
	{
		key: 'acceptRate',
		// TRANSLATORS: Card title. Lists the people who accepted most often.
		label: t('attendance', 'Highest acceptance rate'),
		metric: 'acceptRate',
		basis: 'targetCount',
		direction: 'desc',
		default: true,
	},
	{
		key: 'maybe',
		// TRANSLATORS: Card title. Lists the people who answered "maybe" most often — a light-hearted one, not a reproach.
		label: t('attendance', 'Most maybe answers'),
		metric: 'maybe',
		direction: 'desc',
		default: true,
	},
	{
		key: 'lastPresent',
		// TRANSLATORS: Card title. Lists who has not been to an appointment for the longest, so nobody quietly drops off the radar.
		label: t('attendance', 'Longest not attended'),
		metric: 'lastPresentAt',
		direction: 'asc',
		date: true,
		default: true,
	},
	{
		key: 'responseRate',
		// TRANSLATORS: Card title. Lists the people who answered most reliably, whatever the answer was.
		label: t('attendance', 'Highest response rate'),
		metric: 'responseRate',
		basis: 'targetCount',
		direction: 'desc',
	},
	{
		key: 'scheduledRate',
		// TRANSLATORS: Card title. Lists the people whose acceptances most often got them a place.
		label: t('attendance', 'Highest scheduling rate'),
		metric: 'scheduledRate',
		basis: 'schedulingBase',
		direction: 'desc',
		scheduling: true,
	},
	{
		key: 'scheduled',
		// TRANSLATORS: Card title. Lists who got a place in the most appointments.
		label: t('attendance', 'Most times scheduled'),
		metric: 'scheduled',
		direction: 'desc',
		scheduling: true,
	},
]

/**
 * @param {boolean} scheduling - Whether the planning mode is switched on.
 * @return {Array<object>} The cards that mean anything on this instance.
 */
export function availableCards(scheduling) {
	return STATISTICS_CARDS.filter((card) => scheduling || !card.scheduling)
}

/**
 * @param {boolean} scheduling - Whether the planning mode is switched on.
 * @return {Array<string>} Keys of the cards shown until somebody says otherwise.
 */
export function defaultCards(scheduling) {
	return availableCards(scheduling).filter((card) => card.default).map((card) => card.key)
}

/**
 * Build one card's rows.
 *
 * @param {object} card - A descriptor from STATISTICS_CARDS.
 * @param {Array<object>} people - The person rows of the evaluation.
 * @param {object} totals - The totals row, used as the average for rates.
 * @return {Array<object>|null} Rows, or null when the card has nothing to say.
 */
export function cardRows(card, people, totals) {
	const descending = card.direction === 'desc'

	// Scored once: the rest of this function only ever compares numbers, and on
	// the date card each score is a Date.parse nobody should pay four times.
	const scored = eligiblePeople(card, people).map((person) => ({ person, score: value(card, person) }))
	if (scored.length === 0) {
		return null
	}

	const threshold = averageOf(card, scored, totals)
	// A zero never earns a line on a counting card: with nobody saying maybe all
	// period everyone sits at zero, clears the average and the card would crown
	// the whole team for something that never happened. The date card is exempt
	// — there "never attended" is precisely what deserves a line.
	const keep = card.date
		? (score) => threshold === null || score <= threshold
		: (score) => score > 0 && (threshold === null || (descending ? score >= threshold : score <= threshold))

	/** @type {Map<number, Array<object>>} */
	const byValue = new Map()
	for (const { person, score } of scored) {
		if (!keep(score)) {
			continue
		}
		const bucket = byValue.get(score)
		if (bucket) {
			bucket.push(person)
		} else {
			byValue.set(score, [person])
		}
	}

	if (byValue.size === 0) {
		return null
	}

	return [...byValue.entries()]
		.sort(([a], [b]) => (descending ? b - a : a - b))
		.slice(0, MAX_ROWS)
		.map(([score, group]) => ({ value: score, never: score === NEVER_PRESENT, people: group }))
}

/**
 * @param {object} card - Card descriptor.
 * @param {object} person - A person row.
 * @return {number} The sortable value, with "never present" as -Infinity so it
 *   leads the ascending date card rather than dropping out of it.
 */
function value(card, person) {
	if (!card.date) {
		return person[card.metric]
	}
	const raw = person[card.metric]
	return raw ? Date.parse(raw) : NEVER_PRESENT
}

/**
 * @param {object} card - Card descriptor.
 * @param {Array<object>} people - The person rows.
 * @return {Array<object>} Those the card may consider at all.
 */
function eligiblePeople(card, people) {
	if (card.date) {
		return people
	}

	const withValue = people.filter((person) => person[card.metric] !== null && person[card.metric] !== undefined)
	if (!card.basis) {
		return withValue
	}

	const widest = Math.max(0, ...withValue.map((person) => person[card.basis]))
	return withValue.filter((person) => person[card.basis] >= widest * MIN_BASIS_SHARE)
}

/**
 * The line the card cuts at. Rates take the totals row, which is the weighted
 * average the table already shows; counts and dates take the plain mean over
 * the people the card considers.
 *
 * @param {object} card - Card descriptor.
 * @param {Array<object>} scored - The people under consideration, with scores.
 * @param {object} totals - The totals row.
 * @return {?number} The threshold, or null when there is nothing to cut at.
 */
function averageOf(card, scored, totals) {
	if (card.basis && totals[card.metric] !== null && totals[card.metric] !== undefined) {
		return totals[card.metric]
	}

	const values = scored.map(({ score }) => score).filter(Number.isFinite)
	if (values.length === 0) {
		return null
	}

	return values.reduce((sum, v) => sum + v, 0) / values.length
}
