import { translate as t } from '@nextcloud/l10n'

// One row per appointment-list view: every per-view difference is decided here,
// so a pair like "filter bar hidden" and "filters still applied" cannot drift.
//
// The audience split runs through `params.onlyForMe`: with it the server keeps
// the appointments the user attends or organizes, without it everything they
// may see — which is the whole instance for holders of see_all_appointments.
//
// Headings are thunks so t() runs when the heading is read, not at import time.
export const VIEWS = Object.freeze({
	current: {
		// TRANSLATORS: Appointment list scoped to the user — the ones they are invited to or organize.
		heading: () => t('attendance', 'My appointments'),
		params: { onlyForMe: true },
		mixesPastAndUpcoming: false,
		includesCancelled: true,
		filtersApply: true,
		showsAwaitingBanner: true,
		celebratesEmpty: false,
		resetsSearch: true,
	},
	past: {
		heading: () => t('attendance', 'Past appointments'),
		params: { timeframe: 'past', onlyForMe: true },
		mixesPastAndUpcoming: false,
		includesCancelled: true,
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
		// Deliberately unscoped: everything the user may see.
		heading: () => t('attendance', 'All appointments'),
		params: { timeframe: 'all' },
		mixesPastAndUpcoming: true,
		includesCancelled: true,
		filtersApply: true,
		showsAwaitingBanner: true,
		celebratesEmpty: false,
		// The search target, so navigating here keeps the term.
		resetsSearch: false,
	},
})
