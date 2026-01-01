import { test, expect } from './fixtures/nextcloud.js'

// Helper function to create an appointment
async function createAppointment(page, { name, description, daysFromNow = 2, durationHours = 1 }) {
	// Wait for Create Appointment link to be ready
	const createLink = page.getByRole('link', { name: 'Create Appointment' })
	await createLink.waitFor({ state: 'visible' })

	// Click create button (navigates to form page)
	await createLink.click()

	// Wait for form page to load
	await page.waitForURL(/.*\/create$/)
	await page.waitForLoadState('networkidle')
	await expect(page.getByRole('heading', { name: 'Create Appointment' })).toBeVisible()

	// Wait for name field to be ready and fill it
	const nameInput = page.getByRole('textbox', { name: 'Appointment Name' })
	await nameInput.waitFor({ state: 'visible' })
	await nameInput.fill(name)

	// Wait for markdown editor (description field) to be ready and fill it
	const descEditor = page.locator('[data-test="input-appointment-description"] .CodeMirror')
	await descEditor.waitFor({ state: 'visible' })
	await descEditor.click()
	await page.keyboard.type(description)

	// Calculate dates
	const now = new Date()
	const startDate = new Date(now.getTime() + daysFromNow * 24 * 60 * 60 * 1000)
	const endDate = new Date(startDate.getTime() + durationHours * 60 * 60 * 1000)

	await page.getByRole('textbox', { name: 'Start Date & Time' }).fill(startDate.toISOString().slice(0, 16))
	await page.getByRole('textbox', { name: 'End Date & Time' }).fill(endDate.toISOString().slice(0, 16))

	// Save
	await page.getByRole('button', { name: 'Save' }).click()

	// Wait for navigation back to appointment list
	await page.waitForURL(/.*\/apps\/attendance(?!\/(create|edit|copy))/)
	await page.waitForLoadState('networkidle')
}

test.describe('Attendance App - Export Functionality', () => {
	test.beforeEach(async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.waitForLoadState('networkidle')
	})

	test('should export appointments to ODS file', async ({ page }) => {
		// Create an appointment to ensure there's something to export
		await createAppointment(page, {
			name: 'Export Test Appointment',
			description: 'Testing export functionality',
			daysFromNow: 10,
			durationHours: 1
		})

		// Verify appointment was created
		await expect(page.getByText('Export Test Appointment').first()).toBeVisible()

		// Click the export button - this will redirect to Files app on success
		await page.locator('[data-test="button-export"]').click()

		// Wait for redirect to Files app (export redirects on success)
		await page.waitForURL(/.*\/apps\/files.*/, { timeout: 15000 })
		await page.waitForLoadState('networkidle')

		// Verify we're in the Attendance folder and ODS file exists
		// The URL should contain dir=/Attendance
		await expect(page).toHaveURL(/dir=.*Attendance/)

		// Verify ODS file is visible by looking for text containing attendance_export and .ods
		await expect(page.getByText(/attendance_export.*\.ods/).first()).toBeVisible({ timeout: 10000 })
	})

	test('should create ODS file in correct user folder', async ({ page }) => {
		// Click the export button - this will redirect to Files app on success
		await page.locator('[data-test="button-export"]').click()

		// Wait for redirect to Files app
		await page.waitForURL(/.*\/apps\/files.*/, { timeout: 15000 })
		await page.waitForLoadState('networkidle')

		// Verify we're in the Attendance folder
		await expect(page).toHaveURL(/dir=.*Attendance/)

		// Verify at least one ODS file exists (text contains .ods)
		await expect(page.getByText(/attendance_export.*\.ods/).first()).toBeVisible({ timeout: 10000 })
	})
})
