import {
	test,
	expect,
	authHeaders,
	closeAppointmentViaAPI,
	createAppointmentViaAPI,
	deleteAllAppointments,
	forceWipeAllAppointments,
	listAppointmentsViaAPI,
	respondToAppointmentViaAPI,
	saveAdminSettings,
} from './fixtures/nextcloud.js'

const BASE = `${process.env.NEXTCLOUD_URL || 'http://localhost:8080'}/index.php`

async function getWidgetAppointments(request, { username, password }) {
	const resp = await request.get(`${BASE}/apps/attendance/api/appointments/widget`, {
		headers: authHeaders(username, password),
	})
	return resp.json()
}

/** The appointments under test are upcoming, so never ask for the past list. */
async function listAppointments(request, options) {
	return listAppointmentsViaAPI(request, { showPast: false, ...options })
}

async function bookUser(request, appointmentId, userId) {
	const resp = await request.post(
		`${BASE}/apps/attendance/api/appointments/${appointmentId}/book/${userId}`,
		{ headers: authHeaders() },
	)
	return resp.status()
}

/**
 * Set up a closed inquiry where everyone answered yes and only the users in
 * `booked` were given a place.
 */
async function closedInquiryWith(request, name, booked) {
	const appointment = await createAppointmentViaAPI(request, { name, daysFromNow: 0.01 })
	expect(appointment.id).toBeTruthy()

	for (const user of ['test', 'test1']) {
		await respondToAppointmentViaAPI(request, appointment.id, {
			response: 'yes',
			username: user,
			password: user,
		})
	}
	for (const user of booked) {
		expect(await bookUser(request, appointment.id, user)).toBe(200)
	}
	// Scheduling is frozen once the inquiry closes, so book first, close after.
	await closeAppointmentViaAPI(request, appointment.id)

	return appointment
}

test.describe('Scheduling — hiding appointments the user was scheduled out of', () => {
	test.beforeAll(async ({ request }) => {
		await forceWipeAllAppointments(request)
		await saveAdminSettings(request, { bookingEnabled: true })
	})

	test.afterAll(async ({ request }) => {
		await saveAdminSettings(request, { bookingEnabled: false })
		await deleteAllAppointments(request)
	})

	test('widget drops the appointment for whoever did not get a place', async ({ request }) => {
		await forceWipeAllAppointments(request)
		await closedInquiryWith(request, 'Scheduled Out Widget', ['test'])

		const scheduled = await getWidgetAppointments(request, { username: 'test', password: 'test' })
		expect(scheduled.map(a => a.name)).toContain('Scheduled Out Widget')

		const scheduledOut = await getWidgetAppointments(request, { username: 'test1', password: 'test1' })
		expect(scheduledOut.map(a => a.name)).not.toContain('Scheduled Out Widget')
	})

	test('widget keeps appointments nobody was scheduled for', async ({ request }) => {
		// The manager closed without using the feature — nobody was told "you are
		// not scheduled", so nobody loses the appointment.
		await forceWipeAllAppointments(request)
		await closedInquiryWith(request, 'Nobody Scheduled Widget', [])

		for (const user of ['test', 'test1']) {
			const widget = await getWidgetAppointments(request, { username: user, password: user })
			expect(widget.map(a => a.name)).toContain('Nobody Scheduled Widget')
		}
	})

	test('list filter drops the appointment only when asked to', async ({ request }) => {
		await forceWipeAllAppointments(request)
		await closedInquiryWith(request, 'Scheduled Out List', ['test'])

		const unfiltered = await listAppointments(request, { username: 'test1', password: 'test1' })
		expect(unfiltered.map(a => a.name)).toContain('Scheduled Out List')

		const filtered = await listAppointments(request, {
			username: 'test1',
			password: 'test1',
			notScheduledOut: true,
		})
		expect(filtered.map(a => a.name)).not.toContain('Scheduled Out List')

		// The scheduled user keeps it under the same filter.
		const scheduled = await listAppointments(request, {
			username: 'test',
			password: 'test',
			notScheduledOut: true,
		})
		expect(scheduled.map(a => a.name)).toContain('Scheduled Out List')
	})

	test('open inquiries survive the filter — nothing is decided yet', async ({ request }) => {
		await forceWipeAllAppointments(request)
		const appointment = await createAppointmentViaAPI(request, {
			name: 'Still Open',
			daysFromNow: 0.01,
		})
		await respondToAppointmentViaAPI(request, appointment.id, {
			response: 'yes',
			username: 'test',
			password: 'test',
		})
		expect(await bookUser(request, appointment.id, 'test')).toBe(200)

		const filtered = await listAppointments(request, {
			username: 'test1',
			password: 'test1',
			notScheduledOut: true,
		})
		expect(filtered.map(a => a.name)).toContain('Still Open')
	})
})
