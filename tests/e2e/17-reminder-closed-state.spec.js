import {
	test,
	expect,
	createAppointmentViaAPI,
	closeAppointmentViaAPI,
	deleteAllAppointments,
	forceWipeAllAppointments,
} from './fixtures/nextcloud.js'

const BASE = `${process.env.NEXTCLOUD_URL || 'http://localhost:8080'}/index.php`
const auth = (u, p) => 'Basic ' + Buffer.from(`${u}:${p}`).toString('base64')

const adminHeaders = {
	Authorization: auth('admin', 'admin'),
	'Content-Type': 'application/json',
	'OCS-APIREQUEST': 'true',
	Cookie: '',
}

async function getAdminSettings(request) {
	const resp = await request.get(`${BASE}/apps/attendance/api/admin/settings`, {
		headers: adminHeaders,
	})
	return resp.json()
}

async function sendBulkReminder(request, appointmentId, target = 'non_responders') {
	return request.post(
		`${BASE}/apps/attendance/api/appointments/${appointmentId}/remind?target=${target}`,
		{ headers: adminHeaders },
	)
}

async function sendReminderToUser(request, appointmentId, userId) {
	return request.post(
		`${BASE}/apps/attendance/api/appointments/${appointmentId}/remind/${userId}`,
		{ headers: adminHeaders },
	)
}

test.describe('Reminders + closed-state — manual reminder paths refuse closed inquiries', () => {
	test.afterAll(async ({ request }) => {
		await deleteAllAppointments(request)
	})

	test('bulk reminder endpoint returns 400 once the inquiry is closed', async ({ request }) => {
		const apt = await createAppointmentViaAPI(request, {
			name: 'Reminder Closed Bulk',
			daysFromNow: 10,
		})

		// Open: endpoint accepts (we don't care if any reminder actually fires —
		// the notifications app may or may not be enabled in the test server).
		const okResp = await sendBulkReminder(request, apt.id)
		expect(okResp.status()).toBe(200)

		await closeAppointmentViaAPI(request, apt.id)

		const blocked = await sendBulkReminder(request, apt.id)
		expect(blocked.status()).toBe(400)
		const body = await blocked.json()
		expect(body.error).toMatch(/closed/i)
	})

	test('per-user reminder endpoint returns 400 once the inquiry is closed', async ({ request }) => {
		const apt = await createAppointmentViaAPI(request, {
			name: 'Reminder Closed Per-User',
			daysFromNow: 10,
			visibleUsers: ['test'],
		})

		// Open: endpoint accepts for an in-audience user
		const okResp = await sendReminderToUser(request, apt.id, 'test')
		expect(okResp.status()).toBe(200)

		await closeAppointmentViaAPI(request, apt.id)

		const blocked = await sendReminderToUser(request, apt.id, 'test')
		expect(blocked.status()).toBe(400)
		const body = await blocked.json()
		expect(body.error).toMatch(/closed/i)
	})

	test('per-user reminder endpoint rejects out-of-audience targets', async ({ request }) => {
		// Audience: only user "test". admin is a manager but not in the audience —
		// the endpoint must reject reminders aimed at admin without the
		// canUserSeeAppointment admin-bypass leaking through.
		const apt = await createAppointmentViaAPI(request, {
			name: 'Reminder Audience Guard',
			daysFromNow: 10,
			visibleUsers: ['test'],
		})

		const blocked = await sendReminderToUser(request, apt.id, 'admin')
		expect(blocked.status()).toBe(400)
		const body = await blocked.json()
		expect(body.error).toMatch(/not a member/i)

		// Sanity: in-audience target is still accepted while open.
		const ok = await sendReminderToUser(request, apt.id, 'test')
		expect(ok.status()).toBe(200)
	})
})

test.describe('Admin settings — nextAppointment preview skips closed inquiries', () => {
	test.beforeAll(async ({ request }) => {
		await forceWipeAllAppointments(request)
	})

	test.afterAll(async ({ request }) => {
		await deleteAllAppointments(request)
	})

	test('preview hops over closed appointments to the next open one', async ({ request }) => {
		await forceWipeAllAppointments(request)

		// Small daysFromNow keeps these two as the earliest in `findUpcoming`
		// even if a leftover slipped past the wipe.
		const closed = await createAppointmentViaAPI(request, {
			name: 'Admin Preview Closed Soon',
			daysFromNow: 0.01,
		})
		const open = await createAppointmentViaAPI(request, {
			name: 'Admin Preview Open Later',
			daysFromNow: 0.02,
		})
		expect(open.id).toBeTruthy()

		// Before close: preview targets the soonest appointment — that's ours.
		const before = await getAdminSettings(request)
		expect(before.status.nextAppointment?.name).toBe('Admin Preview Closed Soon')

		await closeAppointmentViaAPI(request, closed.id)

		// After close: preview must skip the closed one. With pollution-free
		// state it lands on our open later-one; either way it must NOT be the
		// just-closed appointment.
		const after = await getAdminSettings(request)
		expect(after.status.nextAppointment?.name).not.toBe('Admin Preview Closed Soon')
	})

	test('preview is null when all upcoming appointments are closed', async ({ request }) => {
		await forceWipeAllAppointments(request)

		const apt = await createAppointmentViaAPI(request, {
			name: 'Admin Preview All Closed',
			daysFromNow: 0.01,
		})
		await closeAppointmentViaAPI(request, apt.id)

		const settings = await getAdminSettings(request)
		expect(settings.status.nextAppointment).toBeNull()
	})
})
