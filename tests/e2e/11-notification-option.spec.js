import { test, expect, createAppointmentViaAPI, deleteAllAppointments, resetAdminSettings } from './fixtures/nextcloud.js'

test.describe('Attendance App - Notification option visibility', () => {
	test.beforeAll(async ({ request }) => {
		// Ensure permissive defaults so non-admin users can manage appointments
		await resetAdminSettings(request)
		// Create an appointment for copy test
		await createAppointmentViaAPI(request, {
			name: 'Notification Copy Test',
			daysFromNow: 20,
		})
	})

	test.afterAll(async ({ request }) => {
		await deleteAllAppointments(request)
	})

	test('admin should see send notification option when creating appointment', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.waitForLoadState('networkidle')

		const createLink = page.getByRole('link', { name: 'Create Appointment' })
		await createLink.waitFor({ state: 'visible' })
		await createLink.click()

		await page.waitForURL(/.*\/create$/)
		await page.waitForLoadState('networkidle')
		await expect(page.getByRole('heading', { name: 'Create Appointment' })).toBeVisible()

		const notificationCheckbox = page.locator('[data-test="checkbox-send-notification"]')
		await expect(notificationCheckbox).toBeVisible()
	})

	test('non-admin user should see send notification option when creating appointment', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('test', 'test')
		await attendanceApp()
		await page.waitForLoadState('networkidle')

		const createLink = page.getByRole('link', { name: 'Create Appointment' })
		await createLink.waitFor({ state: 'visible' })
		await createLink.click()

		await page.waitForURL(/.*\/create$/)
		await page.waitForLoadState('networkidle')
		await expect(page.getByRole('heading', { name: 'Create Appointment' })).toBeVisible()

		const notificationCheckbox = page.locator('[data-test="checkbox-send-notification"]')
		await expect(notificationCheckbox).toBeVisible()
	})

	test('should see send notification option when copying appointment', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.waitForLoadState('networkidle')

		await page.getByText('Notification Copy Test').first().click()
		await page.waitForLoadState('networkidle')

		await page.getByRole('button', { name: 'Actions' }).first().click()
		await page.getByRole('menuitem', { name: 'Copy' }).click()

		await page.waitForURL(/.*\/copy\/\d+$/)
		await page.waitForLoadState('networkidle')
		await expect(page.getByRole('heading', { name: 'Copy Appointment' })).toBeVisible()

		const notificationCheckbox = page.locator('[data-test="checkbox-send-notification"]')
		await expect(notificationCheckbox).toBeVisible()
	})
})
