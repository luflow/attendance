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
 * Sends the whitelists too: omitted settings stay untouched, so a whitelist an
 * earlier sequential spec left behind would silently narrow the audience.
 *
 * @param {import('@playwright/test').APIRequestContext} request - Playwright request context.
 * @param {string} mode - One of 'all', 'groups', 'nobody'.
 */
async function setStatisticsPermission(request, mode) {
	await saveAdminSettings(request, {
		whitelistedGroups: [],
		whitelistedTeams: [],
		permissions: { ...PERMISSIVE_PERMISSIONS, see_statistics: { mode, groups: [] } },
	})
	await reloadWebWorkers()
}

test.describe('Attendance App - Statistics', () => {
	let categoryId = null

	test.beforeAll(async ({ request }) => {
		await resetAdminSettings(request)
		await reloadWebWorkers()
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

		await respondToAppointmentViaAPI(request, recorded.id, { response: 'yes', comment: 'Bringing the sheet music' })
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

	test('is reachable from the navigation', async ({ page, request, loginAsUser, attendanceApp }) => {
		await setStatisticsPermission(request, 'all')
		await loginAsUser('admin', 'admin')
		await attendanceApp()

		// Covers the capability reaching the client: the entry is gated on
		// capabilities.statisticsAvailable, which a server-only flag never sets.
		await page.locator('[data-test="nav-statistics"]').click()
		await expect(page).toHaveURL(/\/statistics/)
		await expect(page.locator('[data-test="statistics-table"]')).toBeVisible()
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

		// Person rows live inside their section, so an empty section list looks
		// exactly like an empty audience — assert the sections first.
		await expect(page.locator('[data-test^="statistics-section-"]')).not.toHaveCount(0)

		const adminRow = page.locator('[data-test="statistics-person-row"]').filter({ hasText: 'admin' })
		await expect(adminRow).toHaveCount(1)
		// The viewer's own row is highlighted so it can be found in a long table.
		await expect(adminRow).toHaveClass(/statistics-table__person--self/)
	})

	test('switches between grouped and flat, compact and full', async ({ page, request, loginAsUser }) => {
		await setStatisticsPermission(request, 'all')
		await loginAsUser('admin', 'admin')

		await page.goto(`/apps/attendance/statistics?period=${YEAR}`)
		await page.waitForLoadState('networkidle')

		// Grouped and compact by default: sections present, and the columns the
		// full view adds are not.
		await expect(page.locator('[data-test^="statistics-section-"]')).not.toHaveCount(0)
		await expect(page.locator('[data-test="statistics-sort-noShow"]')).toHaveCount(0)
		await expect(page.locator('[data-test="statistics-sort-yes"]')).toBeVisible()

		await page.locator('[data-test="statistics-detail"]').getByText('Full', { exact: true }).click()
		await expect(page.locator('[data-test="statistics-sort-noShow"]')).toBeVisible()

		await page.locator('[data-test="statistics-grouping"]').getByText('Ungrouped', { exact: true }).click()
		await expect(page.locator('[data-test^="statistics-section-"]')).toHaveCount(0)
		// The people survive the regrouping — each listed once now.
		await expect(page.locator('[data-test="statistics-person-row"]').filter({ hasText: 'admin' })).toHaveCount(1)
		await expect(page.locator('[data-test="statistics-totals"]')).toBeVisible()

		// Both choices are linkable, so a shared URL opens the same view.
		await expect(page).toHaveURL(/grouping=flat/)
		await expect(page).toHaveURL(/detail=full/)
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

		await page.locator('[data-test="statistics-person-row"]').filter({ hasText: 'admin' }).click()

		const sidebar = page.locator('[data-test="statistics-person-sidebar"]')
		await expect(sidebar).toBeVisible()
		await expect(sidebar).toContainText('Statistics recorded')
		// Same shape as the appointment detail page: the answer is an icon, the
		// check-in a labelled one, and the comment comes along.
		await expect(sidebar.locator('.response-dot')).not.toHaveCount(0)
		await expect(sidebar).toContainText('Checked in?')
		await expect(sidebar).toContainText('Bringing the sheet music')
	})

	test('does not open the drill-down without the response overview', async ({ page, request, loginAsUser }) => {
		await saveAdminSettings(request, {
			whitelistedGroups: [],
			whitelistedTeams: [],
			permissions: {
				...PERMISSIVE_PERMISSIONS,
				see_statistics: { mode: 'all', groups: [] },
				manage_appointments: { mode: 'nobody', groups: [] },
				see_response_overview: { mode: 'nobody', groups: [] },
			},
		})
		await reloadWebWorkers()
		await loginAsUser('admin', 'admin')

		await page.goto(`/apps/attendance/statistics?period=${YEAR}`)
		await page.waitForLoadState('networkidle')

		const adminRow = page.locator('[data-test="statistics-person-row"]').filter({ hasText: 'admin' })
		await expect(adminRow).toHaveCount(1)
		// The row carries no tabindex when it is not selectable — asserted before
		// the click, because "the sidebar did not appear" is also true of a
		// sidebar that simply has not rendered yet.
		await expect(adminRow).not.toHaveAttribute('tabindex')

		await adminRow.click()
		await page.waitForLoadState('networkidle')
		await expect(page.locator('[data-test="statistics-person-sidebar"]')).toHaveCount(0)
	})

	test('without the permission only the own row is shown', async ({ page, request, loginAsUser }) => {
		await setStatisticsPermission(request, 'nobody')
		await loginAsUser('admin', 'admin')

		await page.goto(`/apps/attendance/statistics?period=${YEAR}`)
		await page.waitForLoadState('networkidle')

		await expect(page.locator('[data-test="statistics-own-row"]')).toBeVisible()

		// Nothing about anybody else: no other people, no group sections, no
		// totals row, no charts, no export.
		await expect(page.locator('[data-test="statistics-person-row"]')).toHaveCount(0)
		await expect(page.locator('[data-test^="statistics-section-"]')).toHaveCount(0)
		await expect(page.locator('[data-test="statistics-totals"]')).toHaveCount(0)
		await expect(page.locator('.statistics-charts')).toHaveCount(0)
		await expect(page.locator('[data-test="statistics-export"]')).toHaveCount(0)
	})
})
