import { test, expect, createAppointmentViaAPI, deleteAllAppointments } from './fixtures/nextcloud.js'

// Helper function to create an appointment via UI
async function createAppointmentViaUI(page, { name, description, daysFromNow = 2, durationHours = 1 }) {
	const createLink = page.getByRole('link', { name: 'Create Appointment' })
	await createLink.waitFor({ state: 'visible' })
	await createLink.click()

	await page.waitForURL(/.*\/create$/)
	await page.waitForLoadState('networkidle')
	await expect(page.getByRole('heading', { name: 'Create Appointment' })).toBeVisible()

	const nameInput = page.getByRole('textbox', { name: 'Appointment Name' })
	await nameInput.waitFor({ state: 'visible' })
	await nameInput.fill(name)

	const descEditor = page.locator('[data-test="input-appointment-description"] .CodeMirror')
	await descEditor.waitFor({ state: 'visible' })
	await descEditor.click()
	await page.keyboard.type(description)

	const now = new Date()
	const startDate = new Date(now.getTime() + daysFromNow * 24 * 60 * 60 * 1000)
	const endDate = new Date(startDate.getTime() + durationHours * 60 * 60 * 1000)

	await page.getByRole('textbox', { name: 'Start Date & Time' }).fill(startDate.toISOString().slice(0, 16))
	await page.getByRole('textbox', { name: 'End Date & Time' }).fill(endDate.toISOString().slice(0, 16))

	await page.getByRole('button', { name: 'Save' }).click()
	await page.waitForURL(/.*\/apps\/attendance(?!\/(create|edit|copy))/)
	await page.waitForLoadState('networkidle')
}

test.describe('Attendance App - Appointment Management', () => {
	test.beforeAll(async ({ request }) => {
		// Create a pre-existing appointment so share/edit/copy/delete tests have data
		await createAppointmentViaAPI(request, {
			name: 'Pre-existing Test Meeting',
			description: 'Created by API for appointment management tests',
			daysFromNow: 15,
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

	test('should create a new appointment', async ({ page }) => {
		await createAppointmentViaUI(page, {
			name: 'Team Standup Meeting',
			description: 'Daily standup to sync on progress',
			daysFromNow: 2,
			durationHours: 1,
		})

		await expect(page.getByText('Team Standup Meeting').first()).toBeVisible()
	})

	test('should share appointment link', async ({ page, context }) => {
		await context.grantPermissions(['clipboard-read', 'clipboard-write'])

		// Use actions menu from the listing page
		await page.getByRole('button', { name: 'Actions' }).first().click()
		await page.getByRole('menuitem', { name: 'Share Link' }).click()

		// Wait briefly for clipboard write (no network request)
		await page.waitForTimeout(500)
		const clipboardText = await page.evaluate(() => navigator.clipboard.readText())
		expect(clipboardText).toContain('/apps/attendance')
		expect(clipboardText).toMatch(/http/)
	})

	test('should edit an appointment', async ({ page }) => {
		// Find the specific appointment
		await page.getByText('Pre-existing Test Meeting').first().click()
		await page.waitForLoadState('networkidle')

		await page.getByRole('button', { name: 'Actions' }).first().click()
		await page.getByRole('menuitem', { name: 'Edit' }).click()

		await page.waitForURL(/.*\/edit\/\d+$/)
		await expect(page.getByRole('heading', { name: 'Edit Appointment' })).toBeVisible()

		const nameInput = page.getByRole('textbox', { name: 'Appointment Name' })
		await nameInput.clear()
		await nameInput.fill('Pre-existing Test Meeting (Edited)')

		await page.getByRole('button', { name: 'Save' }).click()
		await page.waitForURL(/.*\/apps\/attendance(?!\/appointment)/)
		await page.waitForLoadState('networkidle')

		await expect(page.getByText('(Edited)').first()).toBeVisible()
	})

	test('should copy an appointment', async ({ page }) => {
		await page.getByText('(Edited)').first().click()
		await page.waitForLoadState('networkidle')

		await page.getByRole('button', { name: 'Actions' }).first().click()
		await page.getByRole('menuitem', { name: 'Copy' }).click()

		await page.waitForURL(/.*\/copy\/\d+$/)
		await expect(page.getByRole('heading', { name: 'Copy Appointment' })).toBeVisible()

		const nameInput = page.getByRole('textbox', { name: 'Appointment Name' })
		const nameValue = await nameInput.inputValue()
		expect(nameValue).toContain('(Copy)')

		const startInput = page.getByRole('textbox', { name: 'Start Date & Time' })
		const endInput = page.getByRole('textbox', { name: 'End Date & Time' })
		await expect(startInput).toHaveValue('')
		await expect(endInput).toHaveValue('')

		const now = new Date()
		const startDate = new Date(now.getTime() + 10 * 24 * 60 * 60 * 1000)
		const endDate = new Date(startDate.getTime() + 2 * 60 * 60 * 1000)

		await startInput.fill(startDate.toISOString().slice(0, 16))
		await endInput.fill(endDate.toISOString().slice(0, 16))

		await page.getByRole('button', { name: 'Save' }).click()
		await page.waitForURL(/.*\/apps\/attendance(?!\/appointment)/)
		await page.waitForLoadState('networkidle')

		await expect(page.getByText('(Copy)').first()).toBeVisible()
	})

	test('should delete an appointment', async ({ page }) => {
		await page.getByText('(Copy)').first().click()
		await page.waitForLoadState('networkidle')

		await page.getByRole('button', { name: 'Actions' }).first().click()
		await page.getByRole('menuitem', { name: 'Delete' }).click()

		const deleteDialog = page.getByRole('dialog', { name: 'Delete appointment' })
		await expect(deleteDialog).toBeVisible()
		await expect(deleteDialog.getByText('Do you want to delete this appointment?')).toBeVisible()

		await deleteDialog.getByRole('button', { name: 'Delete' }).click()
		await page.waitForLoadState('networkidle')
	})
})

test.describe('Attendance App - User Responses', () => {
	test.beforeAll(async ({ request }) => {
		// Create appointments for user response tests
		await createAppointmentViaAPI(request, {
			name: 'Response Test Meeting',
			description: 'Appointment for testing user responses',
			daysFromNow: 8,
			durationHours: 1,
		})
	})

	test.afterAll(async ({ request }) => {
		await deleteAllAppointments(request)
	})

	test('user should respond to appointment', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('test', 'test')
		await attendanceApp()
		await page.waitForLoadState('networkidle')

		await page.getByRole('button', { name: 'Yes', exact: true }).first().click()
		const summary = page.getByRole('heading', { name: 'Response Summary' }).first()
		await expect(summary).toBeVisible()
	})

	test('should allow changing response', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('test', 'test')
		await attendanceApp()
		await page.waitForLoadState('networkidle')

		await page.getByRole('button', { name: 'Yes', exact: true }).first().click()
		await page.waitForLoadState('networkidle')
		await page.getByRole('button', { name: 'Maybe' }).first().click()
		await page.waitForLoadState('networkidle')

		await expect(page.getByRole('heading', { name: 'Response Summary' }).first()).toBeVisible()
	})

	test('should add comment to response', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('test', 'test')
		await attendanceApp()
		await page.waitForLoadState('networkidle')

		await page.getByRole('link', { name: 'Upcoming Appointments' }).click()
		await page.waitForLoadState('networkidle')

		await page.getByRole('button', { name: 'Yes', exact: true }).first().click()
		await page.waitForLoadState('networkidle')

		const commentToggle = page.locator('[data-test="button-toggle-comment"]').first()
		await expect(commentToggle).toBeVisible({ timeout: 5000 })
		await commentToggle.click()

		const commentField = page.locator('[data-test="response-comment"]').first()
		await expect(commentField).toBeVisible({ timeout: 5000 })
		const commentText = 'Looking forward to this appointment!'
		await commentField.fill(commentText)

		const savedIndicator = page.locator('.saved-indicator').first()
		await expect(savedIndicator).toBeVisible({ timeout: 5000 })

		await page.reload()
		await page.waitForLoadState('networkidle')

		await page.getByRole('link', { name: 'Upcoming Appointments' }).click()
		await page.waitForLoadState('networkidle')

		const reloadedCommentToggle = page.locator('[data-test="button-toggle-comment"]').first()
		await expect(reloadedCommentToggle).toBeVisible({ timeout: 5000 })
		await reloadedCommentToggle.click()

		const reloadedCommentField = page.locator('[data-test="response-comment"]').first()
		await expect(reloadedCommentField).toBeVisible({ timeout: 5000 })
		await expect(reloadedCommentField).toHaveValue(commentText)
	})
})
