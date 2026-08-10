import { translate as t } from '@nextcloud/l10n'

// One row per appointment-list view: every per-view difference is decided here,
// so a pair like "filter bar hidden" and "filters still applied" cannot drift.
//
// Headings are thunks so t() runs when the heading is read, not at import time.
export const VIEWS = Object.freeze({
	current: {
		heading: () => t('attendance', 'Upcoming appointments'),
		params: {},
		mixesPastAndUpcoming: false,
		includesCancelled: false,
		filtersApply: true,
		showsAwaitingBanner: true,
		celebratesEmpty: false,
		resetsSearch: true,
	},
	past: {
		heading: () => t('attendance', 'Past appointments'),
		params: { showPastAppointments: true },
		mixesPastAndUpcoming: false,
		includesCancelled: false,
		filtersApply: true,
		// Nothing is left to answer here.
		showsAwaitingBanner: false,
		celebratesEmpty: false,
		resetsSearch: true,
	},
	unanswered: {
		heading: () => t('attendance', 'Unanswered'),
		params: { unansweredOnly: true },
		mixesPastAndUpcoming: false,
		includesCancelled: false,
		filtersApply: false,
		// This view is the awaiting list; it has its own banner.
		showsAwaitingBanner: false,
		celebratesEmpty: true,
		resetsSearch: true,
	},
	all: {
		heading: () => t('attendance', 'All appointments'),
		params: {},
		mixesPastAndUpcoming: true,
		includesCancelled: true,
		filtersApply: true,
		showsAwaitingBanner: true,
		celebratesEmpty: false,
		// The search target, so navigating here keeps the term.
		resetsSearch: false,
	},
})
