import {
	addUserToGroupViaOCS,
	authHeaders,
	BASE_URL,
	createAppointmentViaAPI,
	createGroupViaOCS,
	deleteAllAppointments,
	expect,
	PERMISSIVE_PERMISSIONS,
	reloadWebWorkers,
	resetAdminSettings,
	restrictPermissionToGroup,
	saveAdminSettings,
	test,
} from './fixtures/nextcloud.js'

const API = `${BASE_URL}/index.php/apps/attendance/api`

/**
 * Exporting the response and check-in lists is a permission of its own, not a
 * side effect of managing appointments: a sports officer can hold it without
 * any manage rights, and a manager without it has no export. Changes global
 * permission state, so it lives in the sequential-admin project.
 */
test.describe('Attendance App - Export permission (sequential)', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ request }) => {
		await deleteAllAppointments(request)
		await createGroupViaOCS(request, 'exporters')
		await addUserToGroupViaOCS(request, 'test3', 'exporters')
		await createAppointmentViaAPI(request, { name: 'Export Permission Appointment', daysFromNow: 5 })
		// Managers: admin only. Export starts closed to everybody; the first
		// test opens it to the exporters group the way an admin would.
		await saveAdminSettings(request, {
			whitelistedGroups: [],
			whitelistedTeams: [],
			permissions: {
				...PERMISSIVE_PERMISSIONS,
				manage_appointments: { mode: 'groups', groups: ['admin'] },
				export: { mode: 'nobody', groups: [] },
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

	test('admin grants the export permission to a group in the settings', async ({ page, loginAsUser }) => {
		await loginAsUser('admin', 'admin')
		await page.goto('/settings/admin/attendance')
		await page.waitForLoadState('networkidle')

		await restrictPermissionToGroup(page, 'export', 'exporters')
		await reloadWebWorkers()
	})

	test('a group member without manage rights gets the export and a file out of it', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('test3', 'test3')
		await attendanceApp()
		await page.waitForLoadState('networkidle')

		// Exporting is not managing — nothing else of the manager UI shows up.
		await expect(page.locator('[data-test="button-create-appointment"]')).toHaveCount(0)
		await page.locator('[data-test="button-export"]').click()

		const exportDialog = page.getByRole('dialog', { name: 'Export appointments' })
		await expect(exportDialog).toBeVisible()
		await exportDialog.getByRole('button', { name: 'Export' }).click()

		await page.waitForURL(/.*\/apps\/files.*/, { timeout: 15000 })
		await page.waitForLoadState('networkidle')
		await expect(page.getByText(/attendance_export.*\.ods/).first()).toBeVisible({ timeout: 10000 })
	})

	test('a manager outside the group has no export — managing does not imply it', async ({ page, request, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.waitForLoadState('networkidle')

		await expect(page.locator('[data-test="button-create-appointment"]')).toBeVisible()
		await expect(page.locator('[data-test="button-export"]')).toHaveCount(0)

		const series = await request.get(`${API}/export/series`, { headers: authHeaders('admin', 'admin') })
		expect(series.status()).toBe(403)
		const exported = await request.post(`${API}/export`, { headers: authHeaders('admin', 'admin'), data: {} })
		expect(exported.status()).toBe(403)
	})

	test('a user with neither right has no export, and the server agrees', async ({ page, request, loginAsUser, attendanceApp }) => {
		await loginAsUser('test', 'test')
		await attendanceApp()
		await page.waitForLoadState('networkidle')

		await expect(page.locator('[data-test="button-export"]')).toHaveCount(0)

		const exported = await request.post(`${API}/export`, { headers: authHeaders('test', 'test'), data: {} })
		expect(exported.status()).toBe(403)

		const holder = await request.get(`${API}/export/series`, { headers: authHeaders('test3', 'test3') })
		expect(holder.status()).toBe(200)
	})
})
