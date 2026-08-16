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
 * Every card, in display order. Each one names whoever sits highest on its
 * metric — including "absent more often", where the top of the list is the
 * point. Award wording stays with the rates that reflect an effort; the two
 * cards about falling short are titled plainly.
 *
 * `basis` marks a rate: those carry their denominator and drop anyone below
 * MIN_BASIS_SHARE. Count cards need neither — a small denominator cannot win
 * a count.
 */
export const STATISTICS_CARDS = [
	{
		key: 'attendanceRate',
		// TRANSLATORS: Card title. Lists the people who turned up most reliably.
		label: t('attendance', 'Top attendance rate'),
		metric: 'attendanceRate',
		basis: 'attendanceBase',
		default: true,
	},
	{
		key: 'acceptRate',
		// TRANSLATORS: Card title. Lists the people who accepted most often.
		label: t('attendance', 'Top acceptance rate'),
		metric: 'acceptRate',
		basis: 'targetCount',
		default: true,
	},
	{
		key: 'maybe',
		// TRANSLATORS: Card title. Lists the people who answered "maybe" most often — a light-hearted one, not a reproach.
		label: t('attendance', 'Most maybe answers'),
		metric: 'maybe',
		default: true,
	},
	{
		key: 'absenceRate',
		// TRANSLATORS: Card title. Lists who was recorded absent most often, so nobody quietly drops off the radar. Deliberately not phrased as an award.
		label: t('attendance', 'Absent more often'),
		metric: 'absenceRate',
		basis: 'attendanceBase',
		default: true,
	},
	{
		key: 'responseRate',
		// TRANSLATORS: Card title. Lists the people who answered most reliably, whatever the answer was.
		label: t('attendance', 'Top response rate'),
		metric: 'responseRate',
		basis: 'targetCount',
	},
	{
		key: 'scheduledRate',
		// TRANSLATORS: Card title. Lists the people whose acceptances most often got them a place.
		label: t('attendance', 'Top scheduling rate'),
		metric: 'scheduledRate',
		basis: 'schedulingBase',
		scheduling: true,
	},
	{
		key: 'scheduled',
		// TRANSLATORS: Card title. Lists who got a place in the most appointments.
		label: t('attendance', 'Most times scheduled'),
		metric: 'scheduled',
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
	const eligible = eligiblePeople(card, people)
	if (eligible.length === 0) {
		return null
	}

	const threshold = averageOf(card, eligible, totals)

	/** @type {Map<number, Array<object>>} */
	const byValue = new Map()
	for (const person of eligible) {
		const score = person[card.metric]
		// A zero never earns a line: with nobody saying maybe all period everyone
		// sits at zero, clears the average, and the card would name the whole
		// team for something that never happened.
		if (score <= 0 || (threshold !== null && score < threshold)) {
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
		.sort(([a], [b]) => b - a)
		.slice(0, MAX_ROWS)
		.map(([score, group]) => ({ value: score, people: group }))
}

/**
 * @param {object} card - Card descriptor.
 * @param {Array<object>} people - The person rows.
 * @return {Array<object>} Those the card may consider at all.
 */
function eligiblePeople(card, people) {
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
 * @param {Array<object>} eligible - The people under consideration.
 * @param {object} totals - The totals row.
 * @return {?number} The threshold, or null when there is nothing to cut at.
 */
function averageOf(card, eligible, totals) {
	if (card.basis && totals[card.metric] !== null && totals[card.metric] !== undefined) {
		return totals[card.metric]
	}

	if (eligible.length === 0) {
		return null
	}

	return eligible.reduce((sum, person) => sum + person[card.metric], 0) / eligible.length
}
