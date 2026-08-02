import {
	closeAppointmentViaAPI,
	createAppointmentViaAPI,
	deleteAllAppointments,
	expect,
	listAppointmentsViaAPI,
	respondForUserViaAPI,
	respondToAppointmentViaAPI,
	saveAdminSettings,
	test,
} from './fixtures/nextcloud.js'

const BASE_URL = process.env.NEXTCLOUD_URL || 'http://localhost:8080'
const API_BASE = `${BASE_URL}/index.php`
const adminAuth = 'Basic ' + Buffer.from('admin:admin').toString('base64')

const FILTER_STORAGE_KEY = 'attendance:list-filters'

async function fetchAudit(request, appointmentId) {
	const resp = await request.get(
		`${API_BASE}/apps/attendance/api/appointments/${appointmentId}/audit`,
		{ headers: { Authorization: adminAuth, 'OCS-APIREQUEST': 'true', Accept: 'application/json' } },
	)
	return { status: resp.status(), body: resp.status() === 200 ? await resp.json() : null }
}

// The respond_for_others permission grants NOBODY when unconfigured — it is
// deliberately not implied by manage rights. Grant it to the admin group for
// the duration of this file (which is why it runs in sequential-admin).
async function grantRespondForOthers(request, groups) {
	await saveAdminSettings(request, {
		permissions: { respond_for_others: groups },
	})
}

test.describe('Respond on behalf — API', () => {
	test.beforeAll(async ({ request }) => {
		await grantRespondForOthers(request, ['admin'])
	})

	test.afterAll(async ({ request }) => {
		await grantRespondForOthers(request, [])
		await deleteAllAppointments(request)
	})

	test('manager sets an answer for a non-responding user', async ({ request }) => {
		const apt = await createAppointmentViaAPI(request, {
			name: 'On-Behalf Set',
			daysFromNow: 9,
		})

		const saved = await respondForUserViaAPI(request, apt.id, 'test', { response: 'yes' })
		expect(saved.userId).toBe('test')
		expect(saved.response).toBe('yes')
		// The row is marked as manager-recorded, not self-answered.
		expect(saved.responseSource).toBe('admin')

		// The target no longer counts as "awaiting a response".
		const unanswered = await listAppointmentsViaAPI(request, {
			showPast: false,
			unansweredOnly: true,
			username: 'test',
			password: 'test',
		})
		expect(unanswered.map((a) => a.id)).not.toContain(apt.id)
	})

	test('overriding an answer keeps the person\'s own comment', async ({ request }) => {
		const apt = await createAppointmentViaAPI(request, {
			name: 'On-Behalf Comment Kept',
			daysFromNow: 9,
		})

		await respondToAppointmentViaAPI(request, apt.id, {
			response: 'maybe',
			comment: 'depends on my shift',
			username: 'test',
			password: 'test',
		})

		const saved = await respondForUserViaAPI(request, apt.id, 'test', { response: 'yes' })
		expect(saved.response).toBe('yes')
		expect(saved.comment).toBe('depends on my shift')
	})

	test('clearing restores the "not yet responded" state', async ({ request }) => {
		const apt = await createAppointmentViaAPI(request, {
			name: 'On-Behalf Clear',
			daysFromNow: 9,
		})

		await respondForUserViaAPI(request, apt.id, 'test', { response: 'no' })
		const cleared = await respondForUserViaAPI(request, apt.id, 'test', { response: null })
		expect(cleared.response).toBeNull()
		expect(cleared.comment).toBe('')

		// Back in the target's unanswered list — reminders pick them up again.
		const unanswered = await listAppointmentsViaAPI(request, {
			showPast: false,
			unansweredOnly: true,
			username: 'test',
			password: 'test',
		})
		expect(unanswered.map((a) => a.id)).toContain(apt.id)
	})

	test('setting an answer on a closed inquiry is rejected', async ({ request }) => {
		const apt = await createAppointmentViaAPI(request, {
			name: 'On-Behalf Closed',
			daysFromNow: 9,
		})
		await closeAppointmentViaAPI(request, apt.id)

		const blocked = await respondForUserViaAPI(request, apt.id, 'test', { response: 'yes' })
		expect(blocked.error).toMatch(/closed/i)
	})

	// NOTE: the audience rejection cannot be exercised here — with the test
	// instance's unconfigured permissions every user counts as a manager and
	// managers always pass the visibility check. It is covered by unit tests
	// (testSubmitResponseForUserRejectsUserOutsideAudience).
	test('setting an answer for an unknown user is rejected', async ({ request }) => {
		const apt = await createAppointmentViaAPI(request, {
			name: 'On-Behalf Unknown User',
			daysFromNow: 9,
		})

		const blocked = await respondForUserViaAPI(request, apt.id, 'does-not-exist', { response: 'yes' })
		expect(blocked.error).toMatch(/not found/i)
	})

	test('audit log records the manager as actor with source admin_response', async ({ request }) => {
		const apt = await createAppointmentViaAPI(request, {
			name: 'On-Behalf Audit',
			daysFromNow: 9,
		})

		await respondForUserViaAPI(request, apt.id, 'test', { response: 'yes' })
		await respondForUserViaAPI(request, apt.id, 'test', { response: null })

		const { status, body } = await fetchAudit(request, apt.id)
		expect(status).toBe(200)

		// Newest-first: the clear, then the set (appointment.created sits below).
		const [rescinded, submitted] = body.items
		expect(rescinded.verb).toBe('response.rescinded')
		expect(submitted.verb).toBe('response.submitted')
		for (const event of [rescinded, submitted]) {
			expect(event.actorId).toBe('admin')
			expect(event.subjectId).toBe('test')
			expect(event.source).toBe('admin_response')
		}
	})

	test('user outside the configured groups is rejected, manage rights notwithstanding', async ({ request }) => {
		const apt = await createAppointmentViaAPI(request, {
			name: 'On-Behalf No Permission',
			daysFromNow: 9,
		})

		// "test" holds manage rights (unconfigured = everyone) but is not in
		// the admin group, which is the only one granted respond_for_others.
		const blocked = await respondForUserViaAPI(request, apt.id, 'test1', {
			response: 'yes',
			username: 'test',
			password: 'test',
		})
		expect(blocked.error).toMatch(/permission/i)
	})

	test('permission is reported via the user permissions endpoint', async ({ request }) => {
		const adminPerms = await request.get(`${API_BASE}/apps/attendance/api/user/permissions`, {
			headers: { Authorization: adminAuth, 'OCS-APIREQUEST': 'true' },
		})
		expect((await adminPerms.json()).canRespondForOthers).toBe(true)

		const userAuth = 'Basic ' + Buffer.from('test:test').toString('base64')
		const userPerms = await request.get(`${API_BASE}/apps/attendance/api/user/permissions`, {
			headers: { Authorization: userAuth, 'OCS-APIREQUEST': 'true' },
		})
		expect((await userPerms.json()).canRespondForOthers).toBe(false)
	})

	test('capability flag respondOnBehalf is exposed', async ({ request }) => {
		const resp = await request.get(`${API_BASE}/apps/attendance/api/capabilities`, {
			headers: { Authorization: adminAuth, 'OCS-APIREQUEST': 'true' },
		})
		const caps = await resp.json()
		expect(caps.respondOnBehalf).toBe(true)
	})
})

test.describe('Respond on behalf — UI', () => {
	test.describe.configure({ mode: 'serial' })

	const meetingName = 'UI On-Behalf Test'

	test.beforeAll(async ({ request }) => {
		await grantRespondForOthers(request, ['admin'])
		// Directly addressing test1 puts them into the "Others" bucket's
		// non-responder list — an unrestricted appointment has no known
		// audience (no whitelisted groups in the default config), so the
		// group summary would stay empty and never render a section.
		await createAppointmentViaAPI(request, {
			name: meetingName,
			daysFromNow: 8,
			visibleUsers: ['test1'],
		})
	})

	test.afterAll(async ({ request }) => {
		await grantRespondForOthers(request, [])
		await deleteAllAppointments(request)
	})

	test.beforeEach(async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.evaluate((key) => window.localStorage.removeItem(key), FILTER_STORAGE_KEY)
		await page.reload()
		await page.waitForLoadState('networkidle')
	})

	test('pencil on a non-responder chip sets the answer, pencil on the row clears it again', async ({ page }) => {
		// Open the detail page — the response summary only renders there.
		const listCard = page.locator('[data-test="appointment-card"]', { hasText: meetingName }).first()
		await listCard.locator('[data-test="appointment-title-link"]').click()
		await expect(page.locator('[data-test="response-summary"]')).toBeVisible()

		// No whitelisted groups in the default config, so everyone sits in
		// the collapsed "Others" bucket.
		await page.locator('[data-test="others-header"]').click()

		// test1 has not answered → pencil on their chip opens the picker.
		await page.locator('.pending-user [data-test="set-answer-test1"]').click()
		await page.locator('[data-test="set-answer-yes-test1"]').click()

		// The silent refetch moves test1 from the chips into the answered rows.
		const row = page.locator('[data-test="response-row-test1"]')
		await expect(row).toBeVisible()
		await expect(page.locator('.pending-user [data-test="set-answer-test1"]')).toHaveCount(0)

		// Clearing from the row's pencil puts the chip back.
		await row.locator('[data-test="set-answer-test1"]').click()
		await page.locator('[data-test="set-answer-clear-test1"]').click()
		await expect(page.locator('[data-test="response-row-test1"]')).toHaveCount(0)
		await expect(page.locator('.pending-user [data-test="set-answer-test1"]')).toBeVisible()
	})
})
