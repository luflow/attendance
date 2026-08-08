import {
	checkinUserViaAPI,
	createAppointmentViaAPI,
	createCategoryViaAPI,
	deleteCategoryViaAPI,
	expect,
	forceWipeAllAppointments,
	PERMISSIVE_PERMISSIONS,
	reloadWebWorkers,
	resetAdminSettings,
	respondToAppointmentViaAPI,
	saveAdminSettings,
	test,
} from './fixtures/nextcloud.js'

const YEAR = new Date().getFullYear()

/**
 * Grant or revoke see_statistics instance-wide.
 *
 * @param {import('@playwright/test').APIRequestContext} request - Playwright request context.
 * @param {string} mode - One of 'all', 'groups', 'nobody'.
 */
async function setStatisticsPermission(request, mode) {
	await saveAdminSettings(request, {
		permissions: { ...PERMISSIVE_PERMISSIONS, see_statistics: { mode, groups: [] } },
	})
	await reloadWebWorkers()
}

test.describe('Attendance App - Statistics', () => {
	let categoryId = null

	test.beforeAll(async ({ request }) => {
		await forceWipeAllAppointments(request)

		const category = await createCategoryViaAPI(request, `Rehearsal ${Math.random().toString(36).slice(2, 8)}`)
		categoryId = category.id

		// Two appointments that already happened: one with the check-in list
		// worked, one where nobody ever checked anyone in.
		const recorded = await createAppointmentViaAPI(request, {
			name: 'Statistics recorded',
			daysFromNow: -7,
			categoryId,
		})
		const unrecorded = await createAppointmentViaAPI(request, {
			name: 'Statistics unrecorded',
			daysFromNow: -3,
		})

		await respondToAppointmentViaAPI(request, recorded.id, { response: 'yes' })
		await checkinUserViaAPI(request, recorded.id, 'admin', { response: 'yes' })
		await respondToAppointmentViaAPI(request, unrecorded.id, { response: 'yes' })
	})

	test.afterAll(async ({ request }) => {
		await forceWipeAllAppointments(request)
		if (categoryId !== null) {
			await deleteCategoryViaAPI(request, categoryId)
		}
		await resetAdminSettings(request)
		await reloadWebWorkers()
	})

	test('shows the evaluation with group sections and a totals row', async ({ page, request, loginAsUser }) => {
		await setStatisticsPermission(request, 'all')
		await loginAsUser('admin', 'admin')

		await page.goto(`/apps/attendance/statistics?period=${YEAR}`)
		await page.waitForLoadState('networkidle')

		await expect(page.locator('[data-test="statistics-table"]')).toBeVisible()
		await expect(page.locator('[data-test="statistics-totals"]')).toBeVisible()

		// Only the recorded appointment counts towards attendance, so the
		// summary has to name both numbers separately.
		const summary = page.locator('[data-test="statistics-summary"]')
		await expect(summary).toContainText('2 appointments')
		await expect(summary).toContainText('1 of 2')

		const adminRow = page.locator('[data-test="statistics-person-row"]').filter({ hasText: 'admin' })
		await expect(adminRow).toHaveCount(1)
	})

	test('narrows the evaluation to the selected category', async ({ page, request, loginAsUser }) => {
		await setStatisticsPermission(request, 'all')
		await loginAsUser('admin', 'admin')

		await page.goto(`/apps/attendance/statistics?period=${YEAR}&categories=${categoryId}`)
		await page.waitForLoadState('networkidle')

		await expect(page.locator('[data-test="statistics-summary"]')).toContainText('1 appointment')
	})

	test('opens the person detail in the sidebar', async ({ page, request, loginAsUser }) => {
		await setStatisticsPermission(request, 'all')
		await loginAsUser('admin', 'admin')

		await page.goto(`/apps/attendance/statistics?period=${YEAR}`)
		await page.waitForLoadState('networkidle')

		await page.locator('[data-test="statistics-person-row"]').first().click()

		const sidebar = page.locator('[data-test="statistics-person-sidebar"]')
		await expect(sidebar).toBeVisible()
		await expect(sidebar).toContainText('Statistics recorded')
	})

	test('without the permission only the own row and no charts are shown', async ({ page, request, loginAsUser }) => {
		await setStatisticsPermission(request, 'nobody')
		await loginAsUser('admin', 'admin')

		await page.goto(`/apps/attendance/statistics?period=${YEAR}`)
		await page.waitForLoadState('networkidle')

		await expect(page.locator('[data-test="statistics-own-row"]')).toBeVisible()
		await expect(page.locator('[data-test="statistics-person-row"]')).toHaveCount(0)
		await expect(page.locator('.statistics-charts')).toHaveCount(0)
		await expect(page.locator('[data-test="statistics-export"]')).toHaveCount(0)

		// The group and overall averages stay — they are what makes the own
		// numbers mean anything.
		await expect(page.locator('[data-test="statistics-totals"]')).toBeVisible()
	})
})
