import {
	test,
	expect,
	createAppointmentViaAPI,
	createGroupViaOCS,
	addUserToGroupViaOCS,
	deleteAllAppointments,
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
		// "test" is the regular non-admin user; "user1" plays the fresh hire
		// who is NOT a member of any whitelisted skill group.
		await createGroupViaOCS(request, skillGroup)
		await addUserToGroupViaOCS(request, 'test', skillGroup)

		// Tracking whitelist = just the skill group. user1 is not in it.
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
		// Andreas's scenario: invite a fresh hire (user1, no whitelisted group)
		// alongside an existing skill group. Previously user1 disappeared from
		// the summary entirely because collectMissingResponders dropped users
		// without any allowed/visible group.
		const apt = await createAppointmentViaAPI(request, {
			name: 'A2 Direct User Plus Group',
			daysFromNow: 6,
			visibleUsers: ['user1'],
			visibleGroups: [skillGroup],
		})

		const summary = await getResponseSummary(request, apt.id)
		expect(summary).toBeDefined()

		// The skill group section must exist and contain "test".
		const groupSection = summary.by_group?.[skillGroup]
		expect(groupSection).toBeDefined()
		const groupUserIds = (groupSection.non_responding_users || []).map(u => u.userId)
		expect(groupUserIds).toContain('test')

		// user1 has no whitelisted-group membership — must surface in Others.
		const othersIds = (summary.others?.non_responding_users || []).map(u => u.userId)
		expect(othersIds).toContain('user1')

		// And the global non-responder list must count user1 too.
		const allNonRespondingIds = (summary.non_responding_users || []).map(u => u.userId)
		expect(allNonRespondingIds).toContain('user1')
	})

	test('still shows up when invited as the sole attendee', async ({ request }) => {
		// Same fix, narrower setup: only the direct visibleUsers entry, no
		// group on the appointment. Used to be invisible.
		const apt = await createAppointmentViaAPI(request, {
			name: 'A2 Direct User Only',
			daysFromNow: 7,
			visibleUsers: ['user1'],
		})

		const summary = await getResponseSummary(request, apt.id)
		const othersIds = (summary.others?.non_responding_users || []).map(u => u.userId)
		expect(othersIds).toContain('user1')
	})
})
