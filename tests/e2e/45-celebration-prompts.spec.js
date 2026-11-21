import {
	authHeaders,
	BASE_URL,
	createAppointmentViaAPI,
	expect,
	forceWipeAllAppointments,
	respondToAppointmentViaAPI,
	test,
} from './fixtures/nextcloud.js'

const API = `${BASE_URL}/index.php/apps/attendance/api`

async function seedAnswered(request, user, count) {
	for (let i = 1; i <= count; i++) {
		const appointment = await createAppointmentViaAPI(request, {
			name: `Answered ${user} ${i}`,
			daysFromNow: 10 + i,
			visibleUsers: [user],
		})
		await respondToAppointmentViaAPI(request, appointment.id, { response: 'yes', username: user, password: user })
	}
}

async function seedOpen(request, user, name) {
	return createAppointmentViaAPI(request, { name, daysFromNow: 30, visibleUsers: [user] })
}

async function ratingDue(request, user) {
	const resp = await request.get(`${API}/user/config`, { headers: authHeaders(user, user) })
	return (await resp.json()).ratingPromptDue
}

async function answerLastOpen(page) {
	await page.goto('/apps/attendance/unanswered')
	await page.locator('[data-test="appointment-card"] [data-test="response-yes"]').click()
	await expect(page.getByText('Hurray! You responded to all appointments.')).toBeVisible()
}

// What rides along with the "Hurray!" banner: the mobile app invitation first,
// the once-per-person rating request otherwise. Wipes every appointment.
test.describe('Requests at the "Hurray!" banner', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ request }) => {
		await forceWipeAllAppointments(request)
	})

	test.afterAll(async ({ request }) => {
		await forceWipeAllAppointments(request)
	})

	test('an open app banner at the top does not hold the rating request back', async ({ page, request, loginAsUser }) => {
		await seedAnswered(request, 'test5', 5)
		await seedOpen(request, 'test5', 'Last open for test5')
		expect(await ratingDue(request, 'test5')).toBe(true)

		await loginAsUser('test5', 'test5')
		await answerLastOpen(page)

		await expect(page.locator('[data-test="mobile-app-banner"]')).toBeVisible()
		await expect(page.locator('[data-test="rating-request"]')).toBeVisible()
		await expect(page.locator('[data-test="rating-request-link"]'))
			.toHaveAttribute('href', 'https://apps.nextcloud.com/apps/attendance#comments')
		await expect(page.locator('[data-test="celebration-invitation"]')).toHaveCount(0)
		await expect.poll(() => ratingDue(request, 'test5')).toBe(false)
	})

	test('the invitation inside "Hurray!" goes first, the rating request follows later', async ({ page, request, loginAsUser }) => {
		await seedAnswered(request, 'test4', 5)
		await loginAsUser('test4', 'test4')

		// Opening an empty list is no answer of the person's own.
		await page.goto('/apps/attendance/unanswered')
		await expect(page.getByText('Hurray! You responded to all appointments.')).toBeVisible()
		await expect(page.locator('[data-test="rating-request"]')).toHaveCount(0)

		await page.locator('[data-test="mobile-app-banner"] [data-test="mobile-app-invitation-dismiss"]').click()
		await seedOpen(request, 'test4', 'First open for test4')
		await answerLastOpen(page)
		await expect(page.locator('[data-test="celebration-invitation"]')).toBeVisible()
		await expect(page.locator('[data-test="rating-request"]')).toHaveCount(0)
		expect(await ratingDue(request, 'test4')).toBe(true)

		// Shown less than 30 days ago, so the next moment belongs to the rating request.
		await seedOpen(request, 'test4', 'Second open for test4')
		await answerLastOpen(page)
		await expect(page.locator('[data-test="rating-request"]')).toBeVisible()
		await expect(page.locator('[data-test="celebration-invitation"]')).toHaveCount(0)
	})

	test('closing the invitation inside "Hurray!" hides it for good', async ({ page, request, loginAsUser }) => {
		const invitation = page.locator('[data-test="celebration-invitation"]')
		const hurray = page.getByText('Hurray! You responded to all appointments.')
		await loginAsUser('test3', 'test3')
		await page.goto('/apps/attendance/unanswered')
		await page.locator('[data-test="mobile-app-banner"] [data-test="mobile-app-invitation-dismiss"]').click()
		await page.reload()
		await expect(invitation).toBeVisible()
		await invitation.locator('[data-test="mobile-app-invitation-dismiss"]').click()

		// The next empty list within the same page load, then on a fresh one.
		await seedOpen(request, 'test3', 'Open for test3')
		await page.locator('[data-test="nav-all"]').click()
		await page.goBack()
		await page.locator('[data-test="appointment-card"] [data-test="response-yes"]').click()
		await expect(hurray).toBeVisible()
		await expect(invitation).toHaveCount(0)
		await page.reload()
		await expect(hurray).toBeVisible()
		await expect(invitation).toHaveCount(0)
	})
})
