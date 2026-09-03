import {
	authHeaders,
	closeAppointmentViaAPI,
	createAppointmentViaAPI,
	deleteAllAppointments,
	expect,
	forceWipeAllAppointments,
	listAppointmentsViaAPI,
	respondToAppointmentViaAPI,
	test,
} from './fixtures/nextcloud.js'

const BASE = `${process.env.NEXTCLOUD_URL || 'http://localhost:8080'}/index.php`

/**
 * Answer yes, saying explicitly whether a place in line is acceptable. The
 * raw request rather than the fixture: what this suite is about is the status
 * code a full appointment answers with.
 */
async function answerYes(request, appointmentId, user, acceptWaitlist = false) {
	return request.post(
		`${BASE}/apps/attendance/api/appointments/${appointmentId}/respond`,
		{
			headers: authHeaders(user, user),
			data: { response: 'yes', comment: '', acceptWaitlist },
		},
	)
}

async function appointmentFor(request, appointmentId, user) {
	const appointments = await listAppointmentsViaAPI(request, {
		showPast: false,
		username: user,
		password: user,
	})
	return appointments.find((a) => a.id === appointmentId)
}

test.describe('Attendance limit and waitlist', () => {
	test.beforeEach(async ({ request }) => {
		await deleteAllAppointments(request)
	})

	test.afterAll(async ({ request }) => {
		await forceWipeAllAppointments(request)
	})

	test('a full appointment queues the next person and promotes them when a spot frees up', async ({ request }) => {
		const appointment = await createAppointmentViaAPI(request, {
			name: 'Limited rehearsal',
			visibleUsers: ['test', 'test1'],
			maxAttendees: 1,
		})
		expect(appointment.id).toBeTruthy()

		// The only spot goes to whoever claims it first.
		expect((await answerYes(request, appointment.id, 'test')).status()).toBe(200)

		const forTest = await appointmentFor(request, appointment.id, 'test')
		expect(forTest.isFull).toBe(true)
		expect(forTest.occupancy).toBe(1)
		expect(forTest.userResponse.waitlisted).toBe(false)

		// A plain yes is refused rather than silently queued — the person asked
		// for a spot, not for a place in line.
		const refused = await answerYes(request, appointment.id, 'test1')
		expect(refused.status()).toBe(400)
		expect((await refused.json()).error).toContain('full')

		// Asking for the queue explicitly works, and reports where they stand.
		expect((await answerYes(request, appointment.id, 'test1', true)).status()).toBe(200)
		const queued = await appointmentFor(request, appointment.id, 'test1')
		expect(queued.userResponse.waitlisted).toBe(true)
		expect(queued.userResponse.waitlistPosition).toBe(1)
		expect(queued.occupancy).toBe(2)

		// Nobody performs the promotion: the spot frees up and the queue moves.
		await respondToAppointmentViaAPI(request, appointment.id, {
			response: 'no',
			username: 'test',
			password: 'test',
		})

		const promoted = await appointmentFor(request, appointment.id, 'test1')
		expect(promoted.userResponse.response).toBe('yes')
		expect(promoted.userResponse.waitlisted).toBe(false)
		expect(promoted.userResponse.waitlistPosition).toBe(null)
		expect(promoted.isFull).toBe(true)
	})

	test('a limit takes "maybe" off the table', async ({ request }) => {
		const appointment = await createAppointmentViaAPI(request, {
			name: 'No maybes here',
			visibleUsers: ['test'],
			maxAttendees: 5,
		})

		const visible = await appointmentFor(request, appointment.id, 'test')
		expect(visible.allowMaybe).toBe(false)

		const maybe = await request.post(
			`${BASE}/apps/attendance/api/appointments/${appointment.id}/respond`,
			{
				headers: authHeaders('test', 'test'),
				data: { response: 'maybe', comment: '' },
			},
		)
		expect(maybe.status()).toBe(400)
	})

	test('without a waitlist a full appointment simply turns people away', async ({ request }) => {
		const appointment = await createAppointmentViaAPI(request, {
			name: 'Hard cap',
			visibleUsers: ['test', 'test1'],
			maxAttendees: 1,
			waitlistEnabled: false,
		})

		expect((await answerYes(request, appointment.id, 'test')).status()).toBe(200)

		// Even somebody willing to wait has nothing to wait for.
		expect((await answerYes(request, appointment.id, 'test1', true)).status()).toBe(400)

		// Declining is never blocked — the organizer wants that answer too.
		const declined = await respondToAppointmentViaAPI(request, appointment.id, {
			response: 'no',
			username: 'test1',
			password: 'test1',
		})
		expect(declined.response).toBe('no')
	})

	test('an appointment without a limit behaves exactly as before', async ({ request }) => {
		const appointment = await createAppointmentViaAPI(request, {
			name: 'Unlimited',
			visibleUsers: ['test', 'test1'],
		})

		for (const user of ['test', 'test1']) {
			expect((await answerYes(request, appointment.id, user)).status()).toBe(200)
		}

		const visible = await appointmentFor(request, appointment.id, 'test')
		expect(visible.maxAttendees).toBe(null)
		expect(visible.isFull).toBe(false)
		expect(visible.allowMaybe).toBe(true)
		expect(visible.userResponse.waitlisted).toBe(false)
	})

	test('closing a full appointment leaves nobody waiting on an answer', async ({ request }) => {
		const appointment = await createAppointmentViaAPI(request, {
			name: 'Closes full',
			visibleUsers: ['test', 'test1'],
			maxAttendees: 1,
		})

		expect((await answerYes(request, appointment.id, 'test')).status()).toBe(200)
		expect((await answerYes(request, appointment.id, 'test1', true)).status()).toBe(200)

		await closeAppointmentViaAPI(request, appointment.id)

		// The queue is frozen with the closed inquiry: still waiting, and no
		// longer able to answer.
		const queued = await appointmentFor(request, appointment.id, 'test1')
		expect(queued.userResponse.waitlisted).toBe(true)
		expect((await answerYes(request, appointment.id, 'test1', true)).status()).toBe(400)
	})
})
