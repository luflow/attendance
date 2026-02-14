import { RRule } from 'rrule'

const WEEKDAY_MAP = {
	MO: RRule.MO,
	TU: RRule.TU,
	WE: RRule.WE,
	TH: RRule.TH,
	FR: RRule.FR,
	SA: RRule.SA,
	SU: RRule.SU,
}

const FREQUENCY_MAP = {
	DAILY: RRule.DAILY,
	WEEKLY: RRule.WEEKLY,
	MONTHLY: RRule.MONTHLY,
}

/**
 * Compute the "Nth weekday" position of a date within its month.
 * e.g. 2026-02-18 (Wednesday) -> { n: 3, dayIndex: 3 } meaning "3rd Wednesday"
 *
 * @param {Date} date
 * @return {{ n: number, dayIndex: number, dayKey: string }}
 */
export function getMonthlyPosition(date) {
	const dayKeys = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU']
	// JS getDay(): 0=Sun..6=Sat -> convert to 0=Mon..6=Sun
	const jsDay = date.getDay()
	const dayIndex = jsDay === 0 ? 6 : jsDay - 1
	const dayKey = dayKeys[dayIndex]
	const dayOfMonth = date.getDate()
	const n = Math.ceil(dayOfMonth / 7)
	return { n, dayIndex, dayKey }
}

/**
 * Generate occurrence dates from a recurrence config.
 *
 * @param {object} config Recurrence configuration
 * @param {string} config.frequency 'DAILY' | 'WEEKLY' | 'MONTHLY'
 * @param {number} config.interval Every N periods
 * @param {string[]} config.byWeekday For weekly: which days ['MO', 'WE']
 * @param {string} config.monthlyType For monthly: 'dayOfMonth' | 'weekdayPosition'
 * @param {string} config.endType 'count' | 'until'
 * @param {number} config.count Number of occurrences (when endType is 'count')
 * @param {Date|null} config.until End date (when endType is 'until')
 * @param {Date} startDate The start date of the first appointment
 * @return {Date[]} Array of occurrence dates (local times)
 */
export function generateOccurrences(config, startDate) {
	if (!startDate || !config.frequency) {
		return []
	}

	const freq = FREQUENCY_MAP[config.frequency]
	if (freq === undefined) {
		return []
	}

	// Build RRule options. Use UTC values matching the local time
	// so rrule doesn't shift dates across timezone boundaries.
	const options = {
		freq,
		interval: config.interval || 1,
		dtstart: new Date(Date.UTC(
			startDate.getFullYear(),
			startDate.getMonth(),
			startDate.getDate(),
			startDate.getHours(),
			startDate.getMinutes(),
		)),
	}

	// End condition
	if (config.endType === 'until' && config.until) {
		const untilDate = config.until instanceof Date ? config.until : new Date(config.until)
		options.until = new Date(Date.UTC(
			untilDate.getFullYear(),
			untilDate.getMonth(),
			untilDate.getDate(),
			23, 59, 59,
		))
	} else {
		options.count = Math.min(Math.max(config.count || 10, 1), 52)
	}

	// Frequency-specific options
	if (config.frequency === 'WEEKLY' && config.byWeekday?.length > 0) {
		options.byweekday = config.byWeekday
			.map(day => WEEKDAY_MAP[day])
			.filter(Boolean)
	}

	if (config.frequency === 'MONTHLY' && config.monthlyType === 'weekdayPosition') {
		const pos = getMonthlyPosition(startDate)
		const weekday = WEEKDAY_MAP[pos.dayKey]
		if (weekday) {
			options.byweekday = [weekday.nth(pos.n)]
		}
	}

	try {
		const rule = new RRule(options)
		// Convert UTC dates back to local dates
		return rule.all().map(utcDate => new Date(
			utcDate.getUTCFullYear(),
			utcDate.getUTCMonth(),
			utcDate.getUTCDate(),
			utcDate.getUTCHours(),
			utcDate.getUTCMinutes(),
		))
	} catch (e) {
		console.error('Failed to generate occurrences:', e)
		return []
	}
}
