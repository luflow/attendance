import {
	test,
	expect,
	createAppointmentViaAPI,
	closeAppointmentViaAPI,
	reopenAppointmentViaAPI,
	listAppointmentsViaAPI,
	respondToAppointmentViaAPI,
	deleteAllAppointments,
} from './fixtures/nextcloud.js'

const DAY_MS = 24 * 60 * 60 * 1000
// Mirrors the storage key in src/views/AllAppointments.vue. Kept in sync by hand.
const FILTER_STORAGE_KEY = 'attendance:list-filters'

test.describe('Attendance App - Close inquiry (API)', () => {
	test.afterAll(async ({ request }) => {
		await deleteAllAppointments(request)
	})

	test('close sets closedAt; reopen clears it (idempotent)', async ({ request }) => {
		const apt = await createAppointmentViaAPI(request, {
			name: 'API Close Test',
			daysFromNow: 14,
		})

		const closed = await closeAppointmentViaAPI(request, apt.id)
		expect(closed.status).toBe(200)
		expect(closed.body.closedAt).not.toBeNull()
		expect(typeof closed.body.closedAt).toBe('string')

		const reClosed = await closeAppointmentViaAPI(request, apt.id)
		expect(reClosed.status).toBe(200)
		expect(reClosed.body.closedAt).toBe(closed.body.closedAt)

		const reopened = await reopenAppointmentViaAPI(request, apt.id)
		expect(reopened.status).toBe(200)
		expect(reopened.body.closedAt).toBeNull()
	})

	test('responses are rejected once the inquiry is closed', async ({ request }) => {
		const apt = await createAppointmentViaAPI(request, {
			name: 'API Response Block Test',
			daysFromNow: 14,
		})

		const okResp = await respondToAppointmentViaAPI(request, apt.id, { response: 'yes' })
		expect(okResp.response).toBe('yes')

		await closeAppointmentViaAPI(request, apt.id)

		// Service throws RuntimeException → controller returns 400 with error message.
		const blocked = await respondToAppointmentViaAPI(request, apt.id, { response: 'no' })
		expect(blocked.error).toMatch(/closed/i)
	})

	test('unansweredOnly excludes closed and already-answered appointments', async ({ request }) => {
		// Serial setup: parallel POSTs to the same Nextcloud occasionally race
		// on the appointments table during DB writes — flake isn't worth the
		// few hundred ms saved.
		const open = await createAppointmentViaAPI(request, { name: 'Filter Open Unanswered', daysFromNow: 14 })
		const answered = await createAppointmentViaAPI(request, { name: 'Filter Open Answered', daysFromNow: 14 })
		const closed = await createAppointmentViaAPI(request, { name: 'Filter Closed Unanswered', daysFromNow: 14 })
		await respondToAppointmentViaAPI(request, answered.id, { response: 'maybe' })
		await closeAppointmentViaAPI(request, closed.id)

		const all = await listAppointmentsViaAPI(request, { showPast: false })
		const allIds = all.map(a => a.id)
		expect(allIds).toEqual(expect.arrayContaining([open.id, answered.id, closed.id]))

		const onlyUnanswered = await listAppointmentsViaAPI(request, {
			showPast: false,
			unansweredOnly: true,
		})
		const filteredIds = onlyUnanswered.map(a => a.id)
		expect(filteredIds).toContain(open.id)
		expect(filteredIds).not.toContain(answered.id)
		expect(filteredIds).not.toContain(closed.id)
	})

	test('responseDeadline round-trips through create and update', async ({ request }) => {
		const deadline = new Date(Date.now() + 5 * DAY_MS)
		const apt = await createAppointmentViaAPI(request, {
			name: 'Deadline Test',
			daysFromNow: 10,
			responseDeadline: deadline,
		})
		expect(apt.responseDeadline).not.toBeNull()
		// Server stores second-precision UTC; compare to the minute.
		expect(new Date(apt.responseDeadline).toISOString().slice(0, 16))
			.toBe(deadline.toISOString().slice(0, 16))
	})

	test('reopen clears the responseDeadline so cron does not re-close', async ({ request }) => {
		// Without this guard the next auto-close cron tick would close the
		// same appointment again because the deadline is still in the past.
		const apt = await createAppointmentViaAPI(request, {
			name: 'Reopen Clears Deadline',
			daysFromNow: 14,
			responseDeadline: new Date(Date.now() + 1 * DAY_MS),
		})
		expect(apt.responseDeadline).not.toBeNull()

		await closeAppointmentViaAPI(request, apt.id)
		const reopened = await reopenAppointmentViaAPI(request, apt.id)

		expect(reopened.status).toBe(200)
		expect(reopened.body.closedAt).toBeNull()
		expect(reopened.body.responseDeadline).toBeNull()
	})
})

test.describe('Attendance App - Close inquiry (UI)', () => {
	test.describe.configure({ mode: 'serial' })

	const closeMeetingName = 'UI Close Inquiry Test'
	const otherMeetingName = 'UI Other Inquiry Test'

	test.beforeAll(async ({ request }) => {
		await createAppointmentViaAPI(request, {
			name: closeMeetingName,
			description: 'Created for UI close/reopen tests',
			daysFromNow: 12,
		})
		await createAppointmentViaAPI(request, {
			name: otherMeetingName,
			daysFromNow: 13,
		})
	})

	test.afterAll(async ({ request }) => {
		await deleteAllAppointments(request)
	})

	test.beforeEach(async ({ page, loginAsUser, attendanceApp }) => {
		// Persisted filters from prior runs/tests would otherwise hide our cards.
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.evaluate((key) => window.localStorage.removeItem(key), FILTER_STORAGE_KEY)
		await page.reload()
		await page.waitForLoadState('networkidle')
	})

	test('manager can close and reopen via the action menu', async ({ page }) => {
		const card = page.locator('[data-test="appointment-card"]', { hasText: closeMeetingName }).first()
		await expect(card).toBeVisible()

		await card.getByRole('button', { name: 'Actions' }).click()
		await page.getByRole('menuitem', { name: 'Close inquiry' }).click()

		const banner = card.locator('[data-test="closed-banner"]')
		await expect(banner).toBeVisible()
		await expect(banner.getByText('Inquiry closed')).toBeVisible()
		await expect(card.locator('[data-test="response-yes"]')).toHaveCount(0)
		await expect(card.locator('[data-test="response-section-readonly"]')).toBeVisible()

		await banner.getByRole('button', { name: 'Reopen' }).click()

		await expect(card.locator('[data-test="closed-banner"]')).toHaveCount(0)
		await expect(card.locator('[data-test="response-yes"]')).toBeVisible()
	})

	test('search bar narrows the visible appointments and persists', async ({ page }) => {
		const cards = page.locator('[data-test="appointment-card"]')
		await expect(cards.filter({ hasText: closeMeetingName }).first()).toBeVisible()
		await expect(cards.filter({ hasText: otherMeetingName }).first()).toBeVisible()

		const search = page.getByRole('textbox', { name: /Search appointments/ })
		await search.fill(otherMeetingName)

		await expect(cards.filter({ hasText: otherMeetingName }).first()).toBeVisible()
		await expect(cards.filter({ hasText: closeMeetingName })).toHaveCount(0)

		// The Vue side debounces the localStorage write 300ms — wait for the
		// commit before reloading so persistence-after-reload actually exercises
		// the restore path instead of the still-default empty value.
		await page.waitForFunction(
			([key, value]) => {
				const raw = window.localStorage.getItem(key)
				if (!raw) return false
				try { return JSON.parse(raw).search === value } catch { return false }
			},
			[FILTER_STORAGE_KEY, otherMeetingName],
		)
		await page.reload()
		await page.waitForLoadState('networkidle')
		await expect(search).toHaveValue(otherMeetingName)
	})
})
