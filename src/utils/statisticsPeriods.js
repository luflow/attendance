import { translate as t } from '@nextcloud/l10n'

const YEARS_BACK = 5
const HALVES_BACK = 6
const QUARTERS_BACK = 8

/**
 * Boundaries are built from the browser's local date, so a quarter ends when
 * it ends where the user is — not where the server stands.
 *
 * @param {Date} date - Date to format.
 * @return {string} The date as Y-m-d.
 */
function toIsoDate(date) {
	const month = String(date.getMonth() + 1).padStart(2, '0')
	const day = String(date.getDate()).padStart(2, '0')
	return `${date.getFullYear()}-${month}-${day}`
}

/**
 * @param {number} year - Full year.
 * @param {number} startMonth - Zero-based first month of the span.
 * @param {number} monthCount - How many months the span covers.
 * @return {{from: string, to: string}} Inclusive range.
 */
function span(year, startMonth, monthCount) {
	return {
		from: toIsoDate(new Date(year, startMonth, 1)),
		to: toIsoDate(new Date(year, startMonth + monthCount, 0)),
	}
}

/**
 * Selectable periods, newest first.
 *
 * @param {string} type - 'year', 'half' or 'quarter'.
 * @param {Date} [now] - Reference date, injectable for tests.
 * @return {Array<{key: string, label: string, from: string, to: string}>} Options.
 */
export function periodOptions(type, now = new Date()) {
	const year = now.getFullYear()
	const options = []

	if (type === 'year') {
		for (let offset = 0; offset < YEARS_BACK; offset++) {
			const target = year - offset
			options.push({ key: String(target), label: String(target), ...span(target, 0, 12) })
		}
		return options
	}

	if (type === 'half') {
		let cursorYear = year
		let cursorHalf = now.getMonth() < 6 ? 1 : 2
		for (let offset = 0; offset < HALVES_BACK; offset++) {
			options.push({
				key: `${cursorYear}-H${cursorHalf}`,
				label: t('attendance', 'H{half} {year}', { half: cursorHalf, year: cursorYear }),
				...span(cursorYear, (cursorHalf - 1) * 6, 6),
			})
			cursorHalf--
			if (cursorHalf === 0) {
				cursorHalf = 2
				cursorYear--
			}
		}
		return options
	}

	if (type === 'quarter') {
		let cursorYear = year
		let cursorQuarter = Math.floor(now.getMonth() / 3) + 1
		for (let offset = 0; offset < QUARTERS_BACK; offset++) {
			options.push({
				key: `${cursorYear}-Q${cursorQuarter}`,
				label: t('attendance', 'Q{quarter} {year}', { quarter: cursorQuarter, year: cursorYear }),
				...span(cursorYear, (cursorQuarter - 1) * 3, 3),
			})
			cursorQuarter--
			if (cursorQuarter === 0) {
				cursorQuarter = 4
				cursorYear--
			}
		}
		return options
	}

	return options
}

/**
 * Resolve a stored period key back to its range, so a shared link reopens the
 * same period rather than a frozen pair of dates.
 *
 * @param {string} key - Period key such as "2026", "2026-H1" or "2026-Q3".
 * @return {?{key: string, label: string, from: string, to: string}} The period, or null when unparseable.
 */
export function periodFromKey(key) {
	const yearMatch = /^(\d{4})$/.exec(key)
	if (yearMatch) {
		const year = Number(yearMatch[1])
		return { key, label: String(year), ...span(year, 0, 12) }
	}

	const halfMatch = /^(\d{4})-H([12])$/.exec(key)
	if (halfMatch) {
		const year = Number(halfMatch[1])
		const half = Number(halfMatch[2])
		return {
			key,
			label: t('attendance', 'H{half} {year}', { half, year }),
			...span(year, (half - 1) * 6, 6),
		}
	}

	const quarterMatch = /^(\d{4})-Q([1-4])$/.exec(key)
	if (quarterMatch) {
		const year = Number(quarterMatch[1])
		const quarter = Number(quarterMatch[2])
		return {
			key,
			label: t('attendance', 'Q{quarter} {year}', { quarter, year }),
			...span(year, (quarter - 1) * 3, 3),
		}
	}

	return null
}

/**
 * @param {string} key - Period key.
 * @return {string} 'year', 'half', 'quarter' or 'custom'.
 */
export function periodTypeForKey(key) {
	if (/^\d{4}$/.test(key)) return 'year'
	if (/^\d{4}-H[12]$/.test(key)) return 'half'
	if (/^\d{4}-Q[1-4]$/.test(key)) return 'quarter'
	return 'custom'
}

/**
 * @param {Date} [now] - Reference date.
 * @return {{key: string, label: string, from: string, to: string}} The running year.
 */
export function defaultPeriod(now = new Date()) {
	return periodFromKey(String(now.getFullYear()))
}
