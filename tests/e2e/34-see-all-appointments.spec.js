import {
	test,
	expect,
	addUserToGroupViaOCS,
	createAppointmentViaAPI,
	createGroupViaOCS,
	deleteAllAppointments,
	reloadWebWorkers,
	resetAdminSettings,
	saveAdminSettings,
	PERMISSIVE_PERMISSIONS,
} from './fixtures/nextcloud.js'

/**
 * see_all_appointments lifts the audience restriction on "All appointments"
 * without handing out manage rights (issue #242). Changes global permission
 * state, so it lives in the sequential-admin project.
 */
test.describe('Attendance App - See all appointments permission (sequential)', () => {
	test.describe.configure({ mode: 'serial' })

	const foreignName = 'Restricted To Admin Only'

	test.beforeAll(async ({ request }) => {
		await deleteAllAppointments(request)
		await createGroupViaOCS(request, 'observers')
		await addUserToGroupViaOCS(request, 'test3', 'observers')
		// Managers: admin only. The observers group may see everything without
		// being able to touch it; the plain test user holds neither role.
		await saveAdminSettings(request, {
			whitelistedGroups: [],
			whitelistedTeams: [],
			permissions: {
				...PERMISSIVE_PERMISSIONS,
				manage_appointments: { mode: 'groups', groups: ['admin'] },
				see_all_appointments: { mode: 'groups', groups: ['observers'] },
			},
			reminders: { enabled: false },
		})
		await reloadWebWorkers()
		await createAppointmentViaAPI(request, {
			name: foreignName,
			daysFromNow: 10,
			visibleUsers: ['admin'],
		})
	})

	test.afterAll(async ({ request }) => {
		await resetAdminSettings(request)
		await reloadWebWorkers()
		await deleteAllAppointments(request)
	})

	test('observer without manage rights sees a foreign appointment under "All appointments"', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('test3', 'test3')
		await attendanceApp()
		await page.locator('[data-test="nav-all"]').click()
		await page.waitForLoadState('networkidle')

		const card = page.locator('[data-test="appointment-card"]', { hasText: foreignName })
		await expect(card.first()).toBeVisible()
		// Seeing is not managing — no create button, no actions on a foreign card.
		await expect(page.locator('[data-test="button-create-appointment"]')).toHaveCount(0)
	})

	test('"My appointments" stays personal even with the permission', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('test3', 'test3')
		await attendanceApp()
		await page.locator('[data-test="nav-upcoming"]').click()
		await expect(page.locator('[data-test="page-heading"]')).toHaveText('My appointments')

		await expect(page.locator('[data-test="appointment-card"]', { hasText: foreignName })).toHaveCount(0)
	})

	test('user without the permission does not see the foreign appointment at all', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('test', 'test')
		await attendanceApp()
		await page.locator('[data-test="nav-all"]').click()
		await page.waitForLoadState('networkidle')

		await expect(page.locator('[data-test="appointment-card"]', { hasText: foreignName })).toHaveCount(0)
	})
})
