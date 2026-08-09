import { translate as t } from '@nextcloud/l10n'

/**
 * The permission catalogue, shared by the admin settings and the setup wizard
 * so a reworded title reaches both screens — and Transifex sees one string,
 * not two that drifted apart.
 */
export const PERMISSION_ROWS = {
	manage_appointments: {
		group: 'appointments',
		title: t('attendance', 'May manage appointments'),
		// TRANSLATORS: Permission description. "manage its responses" is a second ability alongside create/edit/delete, not a consequence of them.
		hint: t('attendance', 'Create, edit and delete any appointment and manage its responses.'),
		warningWhenAll: t('attendance', 'Every user can create, edit and delete all appointments.'),
	},
	create_appointments: {
		group: 'appointments',
		title: t('attendance', 'May create own appointments'),
		hint: t('attendance', 'Create appointments and manage them as organizer.'),
		implication: t('attendance', 'Users who can manage appointments can always create appointments.'),
	},
	see_response_overview: {
		group: 'responses',
		title: t('attendance', 'May see detailed response & check-in summary'),
		hint: t('attendance', 'See the detailed summary including names — who answered what and who checked in.'),
		implication: t('attendance', 'Organizers always see the summary of their own appointments.'),
		warningWhenAll: t('attendance', 'Every user can see who answered what.'),
	},
	see_response_counts: {
		group: 'responses',
		title: t('attendance', 'May see response counts'),
		hint: t('attendance', 'See how many people answered yes, no or maybe — without any names.'),
		// TRANSLATORS: Note under a permission. "the counts" are the yes/no/maybe numbers; the sentence says this permission is implied by the detailed-summary one.
		implication: t('attendance', 'Users who see the detailed summary always see the counts.'),
	},
	see_comments: {
		group: 'responses',
		title: t('attendance', 'May see comments'),
		hint: t('attendance', 'See comments in the response overview.'),
	},
	see_statistics: {
		group: 'responses',
		title: t('attendance', 'May see everyone\'s statistics'),
		hint: t('attendance', 'See the evaluation of responses and attendance across all appointments, per person.'),
		implication: t('attendance', 'Without this, users only see their own numbers.'),
		warningWhenAll: t('attendance', 'Every user can see how often each person responded and attended.'),
	},
	respond_for_others: {
		group: 'responses',
		// TRANSLATORS: Permission title. "set" means recording or changing an existing answer on someone's behalf — nothing new is created.
		title: t('attendance', 'May set responses for other users'),
		hint: t('attendance', 'Record or clear an answer on behalf of another person.'),
		implication: t('attendance', 'Not granted automatically to users who can manage appointments.'),
	},
	checkin: {
		group: 'checkin',
		title: t('attendance', 'May check in attendees'),
		hint: t('attendance', 'Access the check-in interface and check in attendees.'),
		warningWhenAll: t('attendance', 'Every user can check in every attendee.'),
	},
	self_checkin: {
		group: 'checkin',
		title: t('attendance', 'May check in themselves'),
		hint: t('attendance', 'Check in themselves via QR code, NFC tag or deep link.'),
		implication: t('attendance', 'QR codes and NFC tags can be set up in the {section} section.'),
		implicationSection: 'self-checkin',
	},
}

export const PERMISSION_NAMES = Object.keys(PERMISSION_ROWS)

/**
 * Section headings of the admin settings, in display order. Which rows land
 * under which heading comes from the catalogue's `group`, so a permission
 * cannot be added to the catalogue and then quietly fail to render.
 */
const PERMISSION_GROUPS = [
	{ key: 'appointments', label: t('attendance', 'Appointments') },
	{ key: 'responses', label: t('attendance', 'Responses') },
	{ key: 'checkin', label: t('attendance', 'Check-in') },
]

/**
 * @return {Array<{key: string, label: string, names: Array<string>}>} Sections with their rows.
 */
export function permissionGroups() {
	return PERMISSION_GROUPS.map((group) => ({
		...group,
		names: PERMISSION_NAMES.filter((name) => PERMISSION_ROWS[name].group === group.key),
	}))
}

/** Fresh state for the mode/groups editors, before the server values arrive. */
export function emptyPermissionState() {
	return Object.fromEntries(PERMISSION_NAMES.map((name) => [name, { mode: 'all', groups: [] }]))
}

/**
 * Wire shape of POST /api/admin/settings `permissions`.
 *
 * @param {object} permissions - Current mode/groups state, keyed by permission name.
 * @param {Array<string>} names - Which permissions to include.
 * @return {object} The payload fragment.
 */
export function permissionPayload(permissions, names) {
	return Object.fromEntries(names.map((name) => [name, {
		mode: permissions[name].mode,
		groups: permissions[name].groups.map((group) => group.id),
	}]))
}

/** Matches ConfigService::VALID_AUDIT_LOG_VISIBILITIES. */
export const AUDIT_VISIBILITIES = [
	{ value: 'managers', label: t('attendance', 'Only users who can manage appointments') },
	{ value: 'all_with_response_overview', label: t('attendance', 'Everyone who can see the response overview') },
]

/** Matches ConfigService::VALID_REMINDER_TARGETS. */
export const REMINDER_TARGETS = [
	{
		value: 'non_responders',
		// TRANSLATORS: Radio option for automatic reminders — remind only people who have not responded yet. People who answered "no" are deliberately not reminded.
		label: t('attendance', 'Non-responders only'),
	},
	{
		value: 'maybe',
		// TRANSLATORS: Radio option for automatic reminders — remind only people who answered "maybe".
		label: t('attendance', 'Maybe responders only'),
	},
	{
		value: 'both',
		label: t('attendance', 'Both non-responders and maybe responders'),
	},
]
