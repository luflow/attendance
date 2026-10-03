import {
	cancelAppointmentViaAPI,
	createAppointmentViaAPI,
	deleteAppointmentViaAPI,
	expect,
	pickFilterOption,
	test,
} from './fixtures/nextcloud.js'

// Regression for #239: a cancelled appointment used to drop out of the list
// entirely, so participants just saw it vanish. The status filter decides now —
// the unfiltered list keeps it, marked as cancelled.
const FILTER_STORAGE_KEY = 'attendance:list-filters'

test.describe('Cancelled appointments stay in the list (#239)', () => {
	test.describe.configure({ mode: 'serial' })

	const cancelledName = 'Cancelled Stays Visible'
	const openName = 'Open Stays Visible'
	const created = []

	test.beforeAll(async ({ request }) => {
		const cancelled = await createAppointmentViaAPI(request, { name: cancelledName, daysFromNow: 11 })
		await cancelAppointmentViaAPI(request, cancelled.id)
		const open = await createAppointmentViaAPI(request, { name: openName, daysFromNow: 12 })
		created.push(cancelled.id, open.id)
	})

	// Runs in the parallel project, so clean up only what this file created.
	test.afterAll(async ({ request }) => {
		for (const id of created) {
			await deleteAppointmentViaAPI(request, id)
		}
	})

	test('unfiltered list shows the cancelled appointment, the status filter sorts it', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.evaluate((key) => window.localStorage.removeItem(key), FILTER_STORAGE_KEY)
		// The entry lists its appointments underneath, so a click on the entry
		// itself would land on one of them — aim at its own link.
		await page.locator('[data-test="nav-upcoming"]').getByRole('link', { name: 'My appointments', exact: true }).click()
		await page.waitForLoadState('networkidle')

		const cards = page.locator('[data-test="appointment-card"]')
		const cancelledCard = cards.filter({ hasText: cancelledName })

		// The fix: it is listed without any filter, and says why it is greyed out.
		await expect(cancelledCard.first()).toBeVisible()
		await expect(cancelledCard.first().locator('[data-test="cancelled-badge"]')).toBeVisible()

		// "Opened" is about inquiries that still take responses — a cancelled one does not.
		await pickFilterOption(page, 'status', 'Opened')
		await expect(cancelledCard).toHaveCount(0)
		await expect(cards.filter({ hasText: openName }).first()).toBeVisible()

		// "Cancelled" used to come up empty on this view because the list dropped
		// cancelled appointments before the filter ever ran.
		await pickFilterOption(page, 'status', 'Cancelled')
		await expect(cancelledCard.first()).toBeVisible()
		await expect(cards.filter({ hasText: openName })).toHaveCount(0)
	})

	test('a cancelled appointment is no unanswered to-do', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.evaluate((key) => window.localStorage.removeItem(key), FILTER_STORAGE_KEY)
		await page.reload()
		await page.waitForLoadState('networkidle')

		// Both are unanswered, but only the open one is still worth answering.
		await expect(page.locator('[data-test="nav-unanswered-appointment"]', { hasText: openName })).toBeVisible()
		await expect(page.locator('[data-test="nav-unanswered-appointment"]', { hasText: cancelledName })).toHaveCount(0)
		// It still has to be reachable, so it buckets under the upcoming section instead.
		await expect(page.locator('[data-test="nav-upcoming-appointment"]', { hasText: cancelledName })).toHaveCount(1)
	})
})
