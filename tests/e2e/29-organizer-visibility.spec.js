import {
	test,
	expect,
	saveAdminSettings,
	resetAdminSettings,
	reloadWebWorkers,
	createAppointmentViaAPI,
	respondToAppointmentViaAPI,
	deleteAllAppointments,
	PERMISSIVE_PERMISSIONS,
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
		// A retry re-runs this hook in a fresh worker; stale A/B copies from
		// the failed attempt would break the strict-mode card locators.
		await deleteAllAppointments(request)
		// Every response-visibility tier is admin-only; test3's only grant is
		// being organizer of appointment A.
		await saveAdminSettings(request, {
			whitelistedGroups: [],
			whitelistedTeams: [],
			permissions: {
				...PERMISSIVE_PERMISSIONS,
				manage_appointments: { mode: 'groups', groups: ['admin'] },
				see_response_overview: { mode: 'groups', groups: ['admin'] },
				see_response_counts: { mode: 'groups', groups: ['admin'] },
				see_comments: { mode: 'groups', groups: ['admin'] },
			},
			reminders: { enabled: false },
		})
		await reloadWebWorkers()

		const organized = await createAppointmentViaAPI(request, {
			name: ORGANIZED,
			daysFromNow: 3,
			organizers: ['test3'],
		})
		const other = await createAppointmentViaAPI(request, {
			name: OTHER,
			daysFromNow: 4,
		})
		for (const apt of [organized, other]) {
			await respondToAppointmentViaAPI(request, apt.id, {
				response: 'yes',
				username: 'test',
				password: 'test',
			})
		}
	})

	/**
	 * Land every viewer on the full list: non-managers default to the
	 * Unanswered view, which is empty for users who already responded.
	 */
	async function openAllAppointments(page) {
		await page.locator('[data-test="nav-all"]').click()
		await page.waitForLoadState('networkidle')
	}

	// .first(): resilientJson may replay a create POST that actually
	// succeeded under load, leaving a duplicate card with the same name.
	const cardFor = (page, name) =>
		page.locator('[data-test="appointment-card"]', { hasText: name }).first()

	test.afterAll(async ({ request }) => {
		await deleteAllAppointments(request)
		await resetAdminSettings(request)
		await reloadWebWorkers()
	})

	test('organizer gets bar and breakdown on their own appointment only', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('test3', 'test3')
		await attendanceApp()
		await page.waitForLoadState('networkidle')
		await openAllAppointments(page)

		const organizedCard = cardFor(page, ORGANIZED)
		const otherCard = cardFor(page, OTHER)
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
		await loginAsUser('test3', 'test3')
		await attendanceApp()
		await page.waitForLoadState('networkidle')
		await openAllAppointments(page)

		// The detail link stays regardless of the bar — the page carries the
		// description and attachments, not just the summary.
		const otherCard = cardFor(page, OTHER)
		await expect(otherCard.locator('[data-test="response-bar"]')).toHaveCount(0)
		await otherCard.locator('[data-test="button-show-details"]').click()
		await page.waitForLoadState('networkidle')

		await expect(page.locator('[data-test="response-summary"]')).toHaveCount(0)
		await expect(page.locator('[data-test="group-summary"]')).toHaveCount(0)
	})

	test('plain user sees neither bar nor breakdown anywhere', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('test', 'test')
		await attendanceApp()
		await page.waitForLoadState('networkidle')
		await openAllAppointments(page)

		const organizedCard = cardFor(page, ORGANIZED)
		await expect(organizedCard).toBeVisible()
		await expect(page.locator('[data-test="response-bar"]')).toHaveCount(0)

		// No bar for this user, but the detail link is still there.
		await organizedCard.locator('[data-test="button-show-details"]').click()
		await page.waitForLoadState('networkidle')
		await expect(page.locator('[data-test="response-summary"]')).toHaveCount(0)
	})

	test('manager keeps the full view everywhere', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.waitForLoadState('networkidle')
		await openAllAppointments(page)

		const organizedCard = cardFor(page, ORGANIZED)
		const otherCard = cardFor(page, OTHER)
		await expect(organizedCard.locator('[data-test="response-bar"]')).toBeVisible()
		await expect(otherCard.locator('[data-test="response-bar"]')).toBeVisible()
	})
})
