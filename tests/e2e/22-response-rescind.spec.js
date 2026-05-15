import {
	test,
	expect,
	createAppointmentViaAPI,
	respondToAppointmentViaAPI,
	closeAppointmentViaAPI,
	listAppointmentsViaAPI,
	deleteAllAppointments,
} from './fixtures/nextcloud.js'

const FILTER_STORAGE_KEY = 'attendance:list-filters'

test.describe('Response rescind — API', () => {
	test.afterAll(async ({ request }) => {
		await deleteAllAppointments(request)
	})

	test('passing response=null clears the previous answer and re-flags as unanswered', async ({ request }) => {
		const apt = await createAppointmentViaAPI(request, {
			name: 'API Rescind Toggle',
			daysFromNow: 9,
		})

		// Initial submit → upcoming list no longer flags it as unanswered.
		await respondToAppointmentViaAPI(request, apt.id, { response: 'yes', comment: 'in' })
		const afterAnswer = await listAppointmentsViaAPI(request, {
			showPast: false,
			unansweredOnly: true,
		})
		expect(afterAnswer.map(a => a.id)).not.toContain(apt.id)

		// Rescind by submitting response=null. Comment is cleared server-side.
		const rescinded = await respondToAppointmentViaAPI(request, apt.id, { response: null })
		expect(rescinded.response).toBeNull()
		expect(rescinded.comment).toBe('')

		// Back in the unanswered list — visible to reminders again.
		const afterRescind = await listAppointmentsViaAPI(request, {
			showPast: false,
			unansweredOnly: true,
		})
		expect(afterRescind.map(a => a.id)).toContain(apt.id)
	})

	test('rescinding an already-withdrawn response is a no-op (no row churn)', async ({ request }) => {
		// Regression: double-rescind must not refresh respondedAt or create
		// duplicate rows. The service has an early-return for this case.
		const apt = await createAppointmentViaAPI(request, {
			name: 'API Rescind Idempotent',
			daysFromNow: 9,
		})

		await respondToAppointmentViaAPI(request, apt.id, { response: 'maybe' })
		await respondToAppointmentViaAPI(request, apt.id, { response: null })
		const first = await respondToAppointmentViaAPI(request, apt.id, { response: null })
		const second = await respondToAppointmentViaAPI(request, apt.id, { response: null })

		expect(first.response).toBeNull()
		expect(second.response).toBeNull()
		// respondedAt isn't churned on the second rescind — same value as the first.
		expect(second.respondedAt).toBe(first.respondedAt)
	})

	test('rescinding on a closed inquiry is rejected', async ({ request }) => {
		const apt = await createAppointmentViaAPI(request, {
			name: 'API Rescind Closed',
			daysFromNow: 9,
		})
		await respondToAppointmentViaAPI(request, apt.id, { response: 'yes' })
		await closeAppointmentViaAPI(request, apt.id)

		const blocked = await respondToAppointmentViaAPI(request, apt.id, { response: null })
		expect(blocked.error).toMatch(/closed/i)
	})

	test('capability flag responseToggle is exposed', async ({ request }) => {
		const resp = await request.get(
			`${process.env.NEXTCLOUD_URL || 'http://localhost:8080'}/index.php/apps/attendance/api/capabilities`,
			{
				headers: {
					Authorization: 'Basic ' + Buffer.from('admin:admin').toString('base64'),
					'OCS-APIREQUEST': 'true',
				},
			},
		)
		const caps = await resp.json()
		expect(caps.responseToggle).toBe(true)
	})
})

test.describe('Response rescind — UI', () => {
	test.describe.configure({ mode: 'serial' })

	const meetingName = 'UI Rescind Test'

	test.beforeAll(async ({ request }) => {
		await createAppointmentViaAPI(request, {
			name: meetingName,
			daysFromNow: 8,
		})
	})

	test.afterAll(async ({ request }) => {
		await deleteAllAppointments(request)
	})

	test.beforeEach(async ({ page, loginAsUser, attendanceApp, request }) => {
		// Clear any prior response so each test starts at "unanswered".
		await respondToAppointmentViaAPI(request, await findAppointmentId(request, meetingName), {
			response: null,
		}).catch(() => null)

		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.evaluate((key) => window.localStorage.removeItem(key), FILTER_STORAGE_KEY)
		await page.reload()
		await page.waitForLoadState('networkidle')
	})

	test('clicking the active answer button toggles it off', async ({ page }) => {
		const card = page.locator('[data-test="appointment-card"]', { hasText: meetingName }).first()
		await expect(card).toBeVisible()

		const yesButton = card.locator('[data-test="response-yes"]')
		await yesButton.click()
		await expect(yesButton).toHaveClass(/active/)

		// The buttons disable for ~800ms after a click to absorb double-taps.
		// Wait until the cooldown finishes before the toggle-off click.
		await expect(yesButton).toBeEnabled()

		await yesButton.click()
		await expect(yesButton).not.toHaveClass(/active/)
		await expect(card.locator('[data-test="response-maybe"]')).not.toHaveClass(/active/)
		await expect(card.locator('[data-test="response-no"]')).not.toHaveClass(/active/)
	})

	test('cooldown blocks immediate re-click', async ({ page }) => {
		// Spec invariant: a fresh response can't be toggled back to null within
		// the cooldown window — protects against accidental double-taps.
		const card = page.locator('[data-test="appointment-card"]', { hasText: meetingName }).first()
		const maybeButton = card.locator('[data-test="response-maybe"]')

		await maybeButton.click()
		// Immediately try to toggle off — should be disabled.
		await expect(maybeButton).toBeDisabled()
		await expect(maybeButton).toHaveClass(/active/)
	})

	test('switching between answers still works (not just clear)', async ({ page }) => {
		const card = page.locator('[data-test="appointment-card"]', { hasText: meetingName }).first()
		const yes = card.locator('[data-test="response-yes"]')
		const no = card.locator('[data-test="response-no"]')

		await yes.click()
		await expect(yes).toHaveClass(/active/)
		await expect(yes).toBeEnabled()

		await no.click()
		await expect(no).toHaveClass(/active/)
		await expect(yes).not.toHaveClass(/active/)
	})
})

async function findAppointmentId(request, name) {
	const all = await listAppointmentsViaAPI(request, { showPast: false })
	const match = all.find(a => a.name === name)
	if (!match) throw new Error(`Appointment ${name} not found`)
	return match.id
}
