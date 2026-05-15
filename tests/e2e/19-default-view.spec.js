import {
	test,
	expect,
	createAppointmentViaAPI,
	deleteAllAppointments,
	saveAdminSettings,
	resetAdminSettings,
} from './fixtures/nextcloud.js'

// Storage key the App.vue currentView-fallback reads. We clear it before each
// test so a stored filter from a previous run doesn't change the landing view.
const FILTER_STORAGE_KEY = 'attendance:list-filters'

async function landOnAttendance(page, attendanceApp) {
	await attendanceApp()
	// Wipe any persisted filters that could mask cards on the landing view.
	await page.evaluate((k) => window.localStorage.removeItem(k), FILTER_STORAGE_KEY)
}

test.describe('Default landing view — role-aware', () => {
	test.beforeAll(async ({ request }) => {
		await deleteAllAppointments(request)
		// Default permission state is empty roles => every user qualifies as
		// "manage_appointments". Restrict it to the `admin` group so the
		// regular test user actually counts as non-admin for this suite.
		await saveAdminSettings(request, {
			whitelistedGroups: [],
			whitelistedTeams: [],
			permissions: {
				manage_appointments: ['admin'],
				checkin: ['admin'],
				see_response_overview: [],
				see_comments: [],
			},
			reminders: { enabled: false },
		})
		// One open appointment addressed to "test" so the Unanswered list isn't
		// empty for the non-admin case.
		await createAppointmentViaAPI(request, {
			name: 'Default View Unanswered For Test',
			daysFromNow: 5,
			visibleUsers: ['test'],
		})
	})

	test.afterAll(async ({ request }) => {
		await deleteAllAppointments(request)
		await resetAdminSettings(request)
	})

	test('admin lands on "All appointments"', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await landOnAttendance(page, attendanceApp)
		await page.reload()
		await page.waitForLoadState('networkidle')

		// The page heading is the authoritative signal for the active view —
		// the underlying sidebar "active" styling lives inside NcAppNavigationItem
		// and is implementation-dependent.
		await expect(page.locator('[data-test="page-heading"]')).toHaveText('All appointments')
	})

	test('regular user lands on "Unanswered"', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('test', 'test')
		await landOnAttendance(page, attendanceApp)
		await page.reload()
		await page.waitForLoadState('networkidle')

		await expect(page.locator('[data-test="page-heading"]')).toHaveText('Unanswered')
	})
})

test.describe('Sidebar order — "All appointments" sits at the top', () => {
	test('admin sidebar lists All before Upcoming and Past', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await landOnAttendance(page, attendanceApp)

		// DOM order check — All must precede Upcoming and Past in the navigation list.
		const navItems = page.locator('[data-test="nav-all"], [data-test="nav-upcoming"], [data-test="nav-past"]')
		await expect(navItems).toHaveCount(3)
		const orderedTestIds = await navItems.evaluateAll((els) =>
			els.map(el => el.getAttribute('data-test')),
		)
		expect(orderedTestIds[0]).toBe('nav-all')
		expect(orderedTestIds).toEqual(['nav-all', 'nav-upcoming', 'nav-past'])
	})
})
