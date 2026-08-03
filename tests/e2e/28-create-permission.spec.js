import {
	test,
	expect,
	saveAdminSettings,
	resetAdminSettings,
	reloadWebWorkers,
	createGroupViaOCS,
	addUserToGroupViaOCS,
	deleteAllAppointments,
	PERMISSIVE_PERMISSIONS,
} from './fixtures/nextcloud.js'

/**
 * The "Create appointment" button in the navigation must only show for
 * users with manage rights or the dedicated create permission. Changes
 * global permission state, so it lives in the sequential-admin project.
 */
test.describe('Attendance App - Create appointment permission (sequential)', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ request }) => {
		await createGroupViaOCS(request, 'creators')
		await addUserToGroupViaOCS(request, 'test3', 'creators')
		// Managers: admin only. Creators: the creators group only. The plain
		// test user holds neither role.
		await saveAdminSettings(request, {
			whitelistedGroups: [],
			whitelistedTeams: [],
			permissions: {
				...PERMISSIVE_PERMISSIONS,
				manage_appointments: { mode: 'groups', groups: ['admin'] },
				create_appointments: { mode: 'groups', groups: ['creators'] },
			},
			reminders: { enabled: false },
		})
		await reloadWebWorkers()
	})

	test.afterAll(async ({ request }) => {
		await resetAdminSettings(request)
		await reloadWebWorkers()
		await deleteAllAppointments(request)
	})

	test('manager sees the create button', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.waitForLoadState('networkidle')

		await expect(page.locator('[data-test="button-create-appointment"]')).toBeVisible()
	})

	test('creator without manage rights sees the button and reaches the form', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('test3', 'test3')
		await attendanceApp()
		await page.waitForLoadState('networkidle')

		const createButton = page.locator('[data-test="button-create-appointment"]')
		await expect(createButton).toBeVisible()

		await createButton.click()
		await page.waitForURL(/.*\/create$/)
		await expect(page.getByRole('heading', { name: 'Create Appointment' })).toBeVisible()
	})

	test('user with neither role sees no create button', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('test', 'test')
		await attendanceApp()
		await page.waitForLoadState('networkidle')

		await expect(page.locator('[data-test="button-create-appointment"]')).toHaveCount(0)
	})
})
