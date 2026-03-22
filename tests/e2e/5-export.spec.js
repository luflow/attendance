import { test, expect, createAppointmentViaAPI, deleteAllAppointments } from './fixtures/nextcloud.js'

test.describe('Attendance App - Export Functionality', () => {
	test.beforeAll(async ({ request }) => {
		await createAppointmentViaAPI(request, {
			name: 'Export Test Appointment',
			description: 'Testing export functionality',
			daysFromNow: 10,
			durationHours: 1,
		})
	})

	test.afterAll(async ({ request }) => {
		await deleteAllAppointments(request)
	})

	test.beforeEach(async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.waitForLoadState('networkidle')
	})

	test('should export appointments to ODS file', async ({ page }) => {
		await expect(page.getByText('Export Test Appointment').first()).toBeVisible()

		await page.locator('[data-test="button-export"]').click()

		const exportDialog = page.getByRole('dialog', { name: 'Export appointments' })
		await expect(exportDialog).toBeVisible()
		await exportDialog.getByRole('button', { name: 'Export' }).click()

		await page.waitForURL(/.*\/apps\/files.*/, { timeout: 15000 })
		await page.waitForLoadState('networkidle')

		await expect(page).toHaveURL(/dir=.*Attendance/)
		await expect(page.getByText(/attendance_export.*\.ods/).first()).toBeVisible({ timeout: 10000 })
	})

	test('should create ODS file in correct user folder', async ({ page }) => {
		await page.locator('[data-test="button-export"]').click()

		const exportDialog = page.getByRole('dialog', { name: 'Export appointments' })
		await expect(exportDialog).toBeVisible()
		await exportDialog.getByRole('button', { name: 'Export' }).click()

		await page.waitForURL(/.*\/apps\/files.*/, { timeout: 15000 })
		await page.waitForLoadState('networkidle')

		await expect(page).toHaveURL(/dir=.*Attendance/)
		await expect(page.getByText(/attendance_export.*\.ods/).first()).toBeVisible({ timeout: 10000 })
	})
})
