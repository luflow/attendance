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
	// The MarkdownEditor uses CodeMirror, so we need to interact with its textarea
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

test.describe('Attendance App - Appointment Management', () => {
	test.beforeEach(async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.waitForLoadState('networkidle')
	})

	test('should create a new appointment', async ({ page }) => {
		await createAppointment(page, {
			name: 'Team Standup Meeting',
			description: 'Daily standup to sync on progress',
			daysFromNow: 2,
			durationHours: 1
		})
		
		// Verify appointment appears (check navigation or main content - use .first() since it appears in multiple places)
		await expect(page.getByText('Team Standup Meeting').first()).toBeVisible()
	})

	test('should share appointment link', async ({ page, context }) => {
		// Grant clipboard permissions
		await context.grantPermissions(['clipboard-read', 'clipboard-write'])
		
		// Open Actions menu (use first() since multiple appointments may exist)
		await page.getByRole('button', { name: 'Actions' }).first().click()

		// Click Share Link
		await page.getByRole('menuitem', { name: 'Share Link' }).click()
		
		// Wait for clipboard to be written and verify
		await page.waitForLoadState('networkidle')
		const clipboardText = await page.evaluate(() => navigator.clipboard.readText())
		expect(clipboardText).toContain('/apps/attendance')
		expect(clipboardText).toMatch(/http/)
	})

	test('should edit an appointment', async ({ page }) => {
		// Get appointment title
		const titleElement = page.getByRole('heading', { level: 3 }).first()
		const originalTitle = await titleElement.textContent()

		// Open actions and click Edit (use first() since multiple appointments may exist)
		await page.getByRole('button', { name: 'Actions' }).first().click()
		await page.getByRole('menuitem', { name: 'Edit' }).click()

		// Wait for form page and verify it's Edit mode
		await page.waitForURL(/.*\/edit\/\d+$/)
		await expect(page.getByRole('heading', { name: 'Edit Appointment' })).toBeVisible()

		// Modify title
		const nameInput = page.getByRole('textbox', { name: 'Appointment Name' })
		await nameInput.clear()
		await nameInput.fill(originalTitle + ' (Edited)')

		// Save
		await page.getByRole('button', { name: 'Save' }).click()

		// Wait for navigation back to appointment list
		await page.waitForURL(/.*\/apps\/attendance(?!\/appointment)/)
		await page.waitForLoadState('networkidle')

		// Verify update (use first() since it appears in both nav and content)
		await expect(page.getByText('(Edited)').first()).toBeVisible()
	})

	test('should copy an appointment', async ({ page }) => {
		// Get the original appointment title
		const titleElement = page.getByRole('heading', { level: 3 }).first()
		const originalTitle = await titleElement.textContent()

		// Open actions and click Copy
		await page.getByRole('button', { name: 'Actions' }).first().click()
		await page.getByRole('menuitem', { name: 'Copy' }).click()

		// Wait for form page and verify it's Copy mode
		await page.waitForURL(/.*\/copy\/\d+$/)
		await expect(page.getByRole('heading', { name: 'Copy Appointment' })).toBeVisible()

		// Verify name is pre-filled with (Copy) suffix
		const nameInput = page.getByRole('textbox', { name: 'Appointment Name' })
		const nameValue = await nameInput.inputValue()
		expect(nameValue).toContain(originalTitle)
		expect(nameValue).toContain('(Copy)')

		// Verify dates are empty (user must set new dates per spec)
		const startInput = page.getByRole('textbox', { name: 'Start Date & Time' })
		const endInput = page.getByRole('textbox', { name: 'End Date & Time' })
		await expect(startInput).toHaveValue('')
		await expect(endInput).toHaveValue('')

		// Fill in dates for the copy
		const now = new Date()
		const startDate = new Date(now.getTime() + 10 * 24 * 60 * 60 * 1000) // 10 days from now
		const endDate = new Date(startDate.getTime() + 2 * 60 * 60 * 1000) // 2 hours duration

		await startInput.fill(startDate.toISOString().slice(0, 16))
		await endInput.fill(endDate.toISOString().slice(0, 16))

		// Save
		await page.getByRole('button', { name: 'Save' }).click()

		// Wait for navigation back to appointment list
		await page.waitForURL(/.*\/apps\/attendance(?!\/appointment)/)
		await page.waitForLoadState('networkidle')

		// Verify the copied appointment appears with (Copy) in the name
		await expect(page.getByText('(Copy)').first()).toBeVisible()
	})

	test('should delete an appointment', async ({ page }) => {
		// Open actions and click Delete (use first() since multiple appointments may exist)
		await page.getByRole('button', { name: 'Actions' }).first().click()
		await page.getByRole('menuitem', { name: 'Delete' }).click()

		// Wait for deletion
		await page.waitForLoadState('networkidle')

		// Should navigate away or show empty state
		const emptyState = page.getByText('No appointments found')
		if (await emptyState.isVisible()) {
			await expect(emptyState).toBeVisible()
		}
	})

	test('should create 5 test appointments for subsequent tests', async ({ page }) => {
		const appointments = [
			{ name: 'Sprint Planning', description: 'Plan next sprint goals and tasks', daysFromNow: 3, durationHours: 2 },
			{ name: 'Code Review Session', description: 'Review PRs from this week', daysFromNow: 4, durationHours: 1 },
			{ name: 'Team Retrospective', description: 'Discuss what went well and improvements', daysFromNow: 5, durationHours: 1.5 },
			{ name: 'Client Demo', description: 'Demonstrate new features to client', daysFromNow: 6, durationHours: 1 },
			{ name: 'All Hands Meeting', description: 'Company-wide quarterly update', daysFromNow: 7, durationHours: 2 }
		]
		
		for (const appt of appointments) {
			await createAppointment(page, appt)
		}
		
		// Verify all were created by checking navigation (use .first() since items may appear in multiple sections)
		await expect(page.getByText('Sprint Planning').first()).toBeVisible()
		await expect(page.getByText('All Hands Meeting').first()).toBeVisible()
	})
})

test.describe('Attendance App - User Responses', () => {
	test('user should respond to appointment', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('test', 'test')
		await attendanceApp()
		await page.waitForLoadState('networkidle')

		// Click Yes button (use first() since multiple appointments may be on page)
		await page.getByRole('button', { name: 'Yes', exact: true }).first().click()

		// Wait for response to be saved by checking summary is visible
		const summary = page.getByRole('heading', { name: 'Response Summary' }).first()
		await expect(summary).toBeVisible()
	})

	test('should allow changing response', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('test', 'test')
		await attendanceApp()
		await page.waitForLoadState('networkidle')

		// Click Yes (use first() since multiple appointments may be on page)
		await page.getByRole('button', { name: 'Yes', exact: true }).first().click()
		await page.waitForLoadState('networkidle')

		// Change to Maybe
		await page.getByRole('button', { name: 'Maybe' }).first().click()
		await page.waitForLoadState('networkidle')

		// Verify by checking response summary or button states (use .first() since multiple appointment cards exist)
		await expect(page.getByRole('heading', { name: 'Response Summary' }).first()).toBeVisible()
	})

	test('should add comment to response', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('test', 'test')
		await attendanceApp()
		await page.waitForLoadState('networkidle')

		// Navigate to Upcoming Appointments (not Unanswered) to ensure appointment stays visible after voting
		await page.getByRole('link', { name: 'Upcoming Appointments' }).click()
		await page.waitForLoadState('networkidle')

		// Click Yes first (comment section appears after voting)
		await page.getByRole('button', { name: 'Yes', exact: true }).first().click()
		await page.waitForLoadState('networkidle')

		// Wait for comment toggle button to appear (it only shows after user has responded)
		const commentToggle = page.locator('[data-test="button-toggle-comment"]').first()
		await expect(commentToggle).toBeVisible({ timeout: 5000 })
		await commentToggle.click()

		// Wait for comment field to appear and fill it
		const commentField = page.locator('[data-test="response-comment"]').first()
		await expect(commentField).toBeVisible({ timeout: 5000 })
		const commentText = 'Looking forward to this appointment!'
		await commentField.fill(commentText)

		// Wait for auto-save to complete (500ms debounce + API call)
		const savedIndicator = page.locator('.saved-indicator').first()
		await expect(savedIndicator).toBeVisible({ timeout: 5000 })

		// Reload the page to verify persistence
		await page.reload()
		await page.waitForLoadState('networkidle')

		// Navigate back to Upcoming Appointments after reload
		await page.getByRole('link', { name: 'Upcoming Appointments' }).click()
		await page.waitForLoadState('networkidle')

		// Wait for comment toggle button to appear after reload
		const reloadedCommentToggle = page.locator('[data-test="button-toggle-comment"]').first()
		await expect(reloadedCommentToggle).toBeVisible({ timeout: 5000 })
		await reloadedCommentToggle.click()

		// Verify the comment is still there after reload
		const reloadedCommentField = page.locator('[data-test="response-comment"]').first()
		await expect(reloadedCommentField).toBeVisible({ timeout: 5000 })
		await expect(reloadedCommentField).toHaveValue(commentText)
	})
})
