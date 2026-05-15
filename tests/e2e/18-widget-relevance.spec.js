import {
	test,
	expect,
	createAppointmentViaAPI,
	deleteAllAppointments,
} from './fixtures/nextcloud.js'

const BASE = `${process.env.NEXTCLOUD_URL || 'http://localhost:8080'}/index.php`
const auth = (u, p) => 'Basic ' + Buffer.from(`${u}:${p}`).toString('base64')

const headers = (u, p) => ({
	Authorization: auth(u, p),
	'Content-Type': 'application/json',
	'OCS-APIREQUEST': 'true',
	Cookie: '',
})

async function getWidgetAppointments(request, { username, password }) {
	const resp = await request.get(`${BASE}/apps/attendance/api/appointments/widget`, {
		headers: headers(username, password),
	})
	return resp.json()
}

test.describe('Dashboard widget — audience-based filtering', () => {
	test.afterAll(async ({ request }) => {
		await deleteAllAppointments(request)
	})

	test('shows unrestricted "everyone" appointments authored by someone else', async ({ request }) => {
		// Regression: the widget used to filter to appointments created by the
		// current user, which hid the everyday "everyone meeting" appointments
		// authored by a manager.
		await deleteAllAppointments(request)

		const everyoneByAdmin = await createAppointmentViaAPI(request, {
			name: 'Widget Everyone By Admin',
			daysFromNow: 4,
		})
		expect(everyoneByAdmin.id).toBeTruthy()

		const widget = await getWidgetAppointments(request, { username: 'test', password: 'test' })
		expect(Array.isArray(widget)).toBe(true)
		const names = widget.map(a => a.name)
		expect(names).toContain('Widget Everyone By Admin')
	})

	test('hides appointments restricted to other users', async ({ request }) => {
		// Manager admin creates an appointment scoped only to user "test".
		// Other regular users should not see it in their widget — the gate is
		// isUserTargetAttendee, no admin bypass.
		await deleteAllAppointments(request)

		const onlyForTest = await createAppointmentViaAPI(request, {
			name: 'Widget Only For Test',
			daysFromNow: 4,
			visibleUsers: ['test'],
		})
		expect(onlyForTest.id).toBeTruthy()

		// `test` is the target → visible in their widget.
		const testWidget = await getWidgetAppointments(request, { username: 'test', password: 'test' })
		expect(testWidget.map(a => a.name)).toContain('Widget Only For Test')

		// `user1` is not in the audience → must not see it. Admin bypass would
		// not apply here (user1 isn't a manager) but we keep the assertion as a
		// belt-and-braces check.
		const otherWidget = await getWidgetAppointments(request, { username: 'user1', password: 'user1' })
		expect(otherWidget.map(a => a.name)).not.toContain('Widget Only For Test')
	})

	test('manager sees only audience-targeted appointments, not every appointment in the system', async ({ request }) => {
		// admin is a manager. canUserSeeAppointment would let them see
		// everything via the admin bypass — but the widget should only show
		// what admin is actually a target attendee of. Otherwise busy managers
		// drown in noise on their dashboard.
		await deleteAllAppointments(request)

		await createAppointmentViaAPI(request, {
			name: 'Widget Restricted To Test Not Admin',
			daysFromNow: 4,
			visibleUsers: ['test'],
		})
		await createAppointmentViaAPI(request, {
			name: 'Widget Everyone Includes Admin',
			daysFromNow: 4,
		})

		const adminWidget = await getWidgetAppointments(request, { username: 'admin', password: 'admin' })
		const names = adminWidget.map(a => a.name)
		expect(names).toContain('Widget Everyone Includes Admin')
		expect(names).not.toContain('Widget Restricted To Test Not Admin')
	})
})
