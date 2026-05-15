import {
	test,
	expect,
	createAppointmentViaAPI,
	createGroupViaOCS,
	addUserToGroupViaOCS,
	deleteAllAppointments,
	forceWipeAllAppointments,
	saveAdminSettings,
	resetAdminSettings,
} from './fixtures/nextcloud.js'

const BASE = `${process.env.NEXTCLOUD_URL || 'http://localhost:8080'}/index.php`
const auth = (u, p) => 'Basic ' + Buffer.from(`${u}:${p}`).toString('base64')

const adminHeaders = {
	Authorization: auth('admin', 'admin'),
	'Content-Type': 'application/json',
	'OCS-APIREQUEST': 'true',
	Cookie: '',
}

async function getResponseSummary(request, appointmentId) {
	const resp = await request.get(
		`${BASE}/apps/attendance/api/appointments/${appointmentId}`,
		{ headers: adminHeaders },
	)
	expect(resp.ok()).toBeTruthy()
	const data = await resp.json()
	return data.responseSummary
}

test.describe('Response summary — directly-addressed user without a whitelisted group', () => {
	const skillGroup = 'attendance-e2e-skill-group'

	test.beforeAll(async ({ request }) => {
		await forceWipeAllAppointments(request)
		// "test" is the regular non-admin user; "test2" plays the fresh hire
		// who is NOT a member of any whitelisted skill group.
		await createGroupViaOCS(request, skillGroup)
		await addUserToGroupViaOCS(request, 'test', skillGroup)

		// Tracking whitelist = just the skill group. test2 is not in it.
		await saveAdminSettings(request, {
			whitelistedGroups: [skillGroup],
			whitelistedTeams: [],
			permissions: {
				manage_appointments: [],
				checkin: [],
				see_response_overview: [],
				see_comments: [],
			},
			reminders: { enabled: false },
		})
	})

	test.afterAll(async ({ request }) => {
		await deleteAllAppointments(request)
		await resetAdminSettings(request)
	})

	test('shows up in the Others bucket so admins can see their invitee', async ({ request }) => {
		// Regression: a user added only via visibleUsers (and not via a
		// whitelisted group) used to drop out of collectMissingResponders.
		const apt = await createAppointmentViaAPI(request, {
			name: 'Summary Direct User Plus Group',
			daysFromNow: 6,
			visibleUsers: ['test2'],
			visibleGroups: [skillGroup],
		})

		const summary = await getResponseSummary(request, apt.id)
		expect(summary).toBeDefined()

		// The skill group section must exist and contain "test".
		const groupSection = summary.by_group?.[skillGroup]
		expect(groupSection).toBeDefined()
		const groupUserIds = (groupSection.non_responding_users || []).map(u => u.userId)
		expect(groupUserIds).toContain('test')

		// test2 has no whitelisted-group membership — must surface in Others.
		const othersIds = (summary.others?.non_responding_users || []).map(u => u.userId)
		expect(othersIds).toContain('test2')

		// And the global non-responder list must count test2 too.
		const allNonRespondingIds = (summary.non_responding_users || []).map(u => u.userId)
		expect(allNonRespondingIds).toContain('test2')
	})

	test('still shows up when invited as the sole attendee', async ({ request }) => {
		// Same fix, narrower setup: only the direct visibleUsers entry, no
		// group on the appointment. Used to be invisible.
		const apt = await createAppointmentViaAPI(request, {
			name: 'Summary Direct User Only',
			daysFromNow: 7,
			visibleUsers: ['test2'],
		})

		const summary = await getResponseSummary(request, apt.id)
		const othersIds = (summary.others?.non_responding_users || []).map(u => u.userId)
		expect(othersIds).toContain('test2')
	})
})
