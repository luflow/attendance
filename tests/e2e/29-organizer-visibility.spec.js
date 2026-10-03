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
	const OUTSIDE = 'Organizer Visibility C'
	let outsideId

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
		// test3 organizes this one without being invited to it (issue #251).
		const outside = await createAppointmentViaAPI(request, {
			name: OUTSIDE,
			daysFromNow: 5,
			organizers: ['test3'],
			visibleUsers: ['test'],
		})
		outsideId = outside.id
		for (const apt of [organized, other]) {
			await respondToAppointmentViaAPI(request, apt.id, {
				response: 'yes',
				username: 'test',
				password: 'test',
			})
		}
	})

	/**
	 * Land every viewer on the full list: the default view is role-dependent
	 * and scoped, so neither default is guaranteed to hold both fixtures.
	 */
	async function openAllAppointments(page) {
		await page.locator('[data-test="nav-all"]').click()
		await page.waitForLoadState('networkidle')
	}

	// .first(): resilientJson may replay a create POST that actually
	// succeeded under load, leaving a duplicate card with the same name.
	const cardFor = (page, name) =>
		page.locator('[data-test="appointment-card"]', { hasText: name }).first()

	// The popover stays open after a choice, and a second click on its trigger
	// would close it instead of opening it again — so close it after each pick.
	async function pickRole(page, name) {
		await page.locator('[data-test="filter-role"]').click()
		await page.getByRole('menuitemradio', { name }).click()
		await page.keyboard.press('Escape')
		await expect(page.getByRole('menuitemradio', { name })).toBeHidden()
	}

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

	test('organizer outside the audience gets a note instead of answer buttons', async ({ page, request, loginAsUser, attendanceApp }) => {
		await loginAsUser('test3', 'test3')
		await attendanceApp()
		await page.waitForLoadState('networkidle')
		await openAllAppointments(page)

		// Where they are invited as well, they answer like everybody else.
		await expect(cardFor(page, ORGANIZED).locator('[data-test="response-yes"]')).toBeVisible()

		const outsideCard = cardFor(page, OUTSIDE)
		await expect(outsideCard.locator('[data-test="not-attendee-note"]')).toBeVisible()
		await expect(outsideCard.locator('[data-test="response-yes"]')).toHaveCount(0)

		await outsideCard.locator('[data-test="button-show-details"]').click()
		await page.waitForLoadState('networkidle')
		await expect(page.locator('[data-test="not-attendee-note"]')).toBeVisible()
		await expect(page.locator('[data-test="response-yes"]')).toHaveCount(0)

		// Older mobile clients still show the buttons, so the server refuses too.
		const refused = await respondToAppointmentViaAPI(request, outsideId, {
			response: 'yes',
			username: 'test3',
			password: 'test3',
		})
		expect(refused.error).toMatch(/attendees/i)
	})

	test('role filter tells attending from organizing', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('test3', 'test3')
		await attendanceApp()
		await page.waitForLoadState('networkidle')
		await openAllAppointments(page)

		await pickRole(page, 'I am an organizer')
		await expect(cardFor(page, ORGANIZED)).toBeVisible()
		await expect(cardFor(page, OUTSIDE)).toBeVisible()
		await expect(cardFor(page, OTHER)).toBeHidden()

		await pickRole(page, 'I am an attendee')
		await expect(cardFor(page, ORGANIZED)).toBeVisible()
		await expect(cardFor(page, OTHER)).toBeVisible()
		await expect(cardFor(page, OUTSIDE)).toBeHidden()

		// test3 is part of everything they see, so there is nothing to single out.
		await page.locator('[data-test="filter-role"]').click()
		await expect(page.getByRole('menuitemradio', { name: 'Not involved' })).toHaveCount(0)
	})

	test('role filter singles out what a manager is not involved in', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.waitForLoadState('networkidle')
		await openAllAppointments(page)

		await pickRole(page, 'Not involved')
		await expect(cardFor(page, OUTSIDE)).toBeVisible()
		await expect(cardFor(page, ORGANIZED)).toBeHidden()
		await expect(cardFor(page, OTHER)).toBeHidden()
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
