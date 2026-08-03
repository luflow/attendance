import {
	test,
	expect,
	saveAdminSettings,
	resetAdminSettings,
	reloadWebWorkers,
	createAppointmentViaAPI,
	respondToAppointmentViaAPI,
	deleteAllAppointments,
} from './fixtures/nextcloud.js'

/**
 * Organizer mixed state: a user without any global response-visibility
 * permission who organizes appointment A (but not B) must get the full
 * summary — bar and per-person breakdown — on A only, and nothing on B.
 * Changes global permission state, so it lives in the sequential-admin
 * project.
 */
test.describe('Attendance App - Organizer response visibility (sequential)', () => {
	test.describe.configure({ mode: 'serial' })

	const ORGANIZED = 'Organizer Visibility A'
	const OTHER = 'Organizer Visibility B'

	test.beforeAll(async ({ request }) => {
		// Every response-visibility tier is admin-only; user1's only grant is
		// being organizer of appointment A.
		await saveAdminSettings(request, {
			whitelistedGroups: [],
			whitelistedTeams: [],
			permissions: {
				manage_appointments: ['admin'],
				checkin: [],
				see_response_overview: ['admin'],
				see_response_counts: ['admin'],
				see_comments: ['admin'],
			},
			reminders: { enabled: false },
		})
		reloadWebWorkers()

		const organized = await createAppointmentViaAPI(request, {
			name: ORGANIZED,
			daysFromNow: 3,
			organizers: ['user1'],
		})
		const other = await createAppointmentViaAPI(request, {
			name: OTHER,
			daysFromNow: 4,
		})
		await respondToAppointmentViaAPI(request, organized.id, {
			response: 'yes',
			username: 'test',
			password: 'test',
		})
		await respondToAppointmentViaAPI(request, other.id, {
			response: 'yes',
			username: 'test',
			password: 'test',
		})
	})

	test.afterAll(async ({ request }) => {
		await deleteAllAppointments(request)
		await resetAdminSettings(request)
		reloadWebWorkers()
	})

	test('organizer gets bar and breakdown on their own appointment only', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('user1', 'user1')
		await attendanceApp()
		await page.waitForLoadState('networkidle')

		const organizedCard = page.locator('[data-test="appointment-card"]', { hasText: ORGANIZED })
		const otherCard = page.locator('[data-test="appointment-card"]', { hasText: OTHER })
		await expect(organizedCard).toBeVisible()
		await expect(otherCard).toBeVisible()

		// List: the bar only rides the organized appointment's card.
		await expect(organizedCard.locator('[data-test="response-bar"]')).toBeVisible()
		await expect(otherCard.locator('[data-test="response-bar"]')).toHaveCount(0)

		// Detail of A: full summary including the per-person breakdown.
		await organizedCard.locator('[data-test="button-show-details"]').click()
		await page.waitForLoadState('networkidle')
		await expect(page.locator('[data-test="response-summary"]')).toBeVisible()
		await expect(page.locator('[data-test="group-summary"]').first()).toBeVisible()
	})

	test('organizer sees no summary on appointments they do not organize', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('user1', 'user1')
		await attendanceApp()
		await page.waitForLoadState('networkidle')

		const otherCard = page.locator('[data-test="appointment-card"]', { hasText: OTHER })
		await otherCard.locator('[data-test="button-show-details"]').click()
		await page.waitForLoadState('networkidle')

		await expect(page.locator('[data-test="response-summary"]')).toHaveCount(0)
		await expect(page.locator('[data-test="group-summary"]')).toHaveCount(0)
	})

	test('plain user sees neither bar nor breakdown anywhere', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('test', 'test')
		await attendanceApp()
		await page.waitForLoadState('networkidle')

		await expect(page.locator('[data-test="appointment-card"]', { hasText: ORGANIZED })).toBeVisible()
		await expect(page.locator('[data-test="response-bar"]')).toHaveCount(0)

		const organizedCard = page.locator('[data-test="appointment-card"]', { hasText: ORGANIZED })
		await organizedCard.locator('[data-test="button-show-details"]').click()
		await page.waitForLoadState('networkidle')
		await expect(page.locator('[data-test="response-summary"]')).toHaveCount(0)
	})

	test('manager keeps the full view everywhere', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.waitForLoadState('networkidle')

		const organizedCard = page.locator('[data-test="appointment-card"]', { hasText: ORGANIZED })
		const otherCard = page.locator('[data-test="appointment-card"]', { hasText: OTHER })
		await expect(organizedCard.locator('[data-test="response-bar"]')).toBeVisible()
		await expect(otherCard.locator('[data-test="response-bar"]')).toBeVisible()
	})
})
