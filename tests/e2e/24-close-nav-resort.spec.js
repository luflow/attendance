import {
	test,
	expect,
	createAppointmentViaAPI,
	deleteAllAppointments,
} from './fixtures/nextcloud.js'

// Regression for #76: closing an inquiry you never answered must move it out of
// the sidebar "Unanswered" section and into "Upcoming" immediately — a closed
// inquiry can no longer be answered, so it is no longer an unanswered to-do. It
// used to stay under "Unanswered" until a full page reload.
const FILTER_STORAGE_KEY = 'attendance:list-filters'

test.describe('Close inquiry re-buckets it in the nav sidebar (#76)', () => {
	test.describe.configure({ mode: 'serial' })

	const name = 'Nav Resort On Close'

	test.beforeAll(async ({ request }) => {
		await deleteAllAppointments(request)
		// Unrestricted → admin is in the audience; never answered → the sidebar
		// lists it under "Unanswered".
		await createAppointmentViaAPI(request, { name, daysFromNow: 9 })
	})

	test.afterAll(async ({ request }) => {
		await deleteAllAppointments(request)
	})

	test('closing an unanswered inquiry leaves the Unanswered nav section without reload', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.evaluate((key) => window.localStorage.removeItem(key), FILTER_STORAGE_KEY)
		await page.reload()
		await page.waitForLoadState('networkidle')

		const unansweredNav = page.locator('[data-test="nav-unanswered-appointment"]', { hasText: name })
		const upcomingNav = page.locator('[data-test="nav-upcoming-appointment"]', { hasText: name })

		// Precondition: it starts under "Unanswered" in the sidebar.
		await expect(unansweredNav).toBeVisible()

		// Close it via the card's action menu in the main list.
		const card = page.locator('[data-test="appointment-card"]', { hasText: name }).first()
		await expect(card).toBeVisible()
		await card.getByRole('button', { name: 'Actions' }).click()
		await page.getByRole('menuitem', { name: 'Close inquiry' }).click()
		await expect(card.locator('[data-test="closed-banner"]')).toBeVisible()

		// The fix: without a reload it must leave "Unanswered" (the only
		// unanswered item → the whole section disappears) and show up under
		// "Upcoming".
		await expect(unansweredNav).toHaveCount(0)
		await expect(page.locator('[data-test="nav-unanswered"]')).toHaveCount(0)
		await expect(upcomingNav).toHaveCount(1)
	})
})
