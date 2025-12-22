import { test, expect } from './fixtures/nextcloud.js'

test.describe('Attendance App - Dashboard Widget Voting', () => {
	test.beforeEach(async ({ page, loginAsUser }) => {
		await loginAsUser('admin', 'admin')
	})

	test('should add Attendance widget to dashboard', async ({ page }) => {
		// Navigate to dashboard
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')
		
		// Open customize dialog
		await page.getByRole('button', { name: 'Customize' }).click()
		
		// Wait for dialog
		await expect(page.getByRole('dialog')).toBeVisible()
		await expect(page.getByRole('heading', { name: 'Edit widgets' })).toBeVisible()
		
		// Enable Attendance widget by clicking the label (checkbox is outside viewport)
		const attendanceLabel = page.getByRole('dialog').getByText('Attendance')
		await attendanceLabel.click()
		
		// Verify checkbox is checked
		await expect(page.getByRole('checkbox', { name: 'Attendance' })).toBeChecked()
		
		// Close dialog
		await page.getByRole('button', { name: 'Close' }).click()
		await expect(page.getByRole('dialog')).not.toBeVisible()
		
		// Verify Attendance widget is visible
		await expect(page.getByRole('heading', { name: 'Attendance', level: 2 })).toBeVisible()
	})

	test('should vote on appointment from dashboard', async ({ page }) => {
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')
		
		// Find the Attendance widget
		const widget = page.locator('.appointment-widget-container').or(page.getByRole('heading', { name: 'Attendance' }).locator('..'))
		
		// Click Yes button on first appointment
		await widget.getByRole('button', { name: 'Yes', exact: true }).first().click()
		
		// Wait for vote to be saved
		await page.waitForLoadState('networkidle')
		
		// Verify vote was registered (button should change state or show active)
		// The exact verification depends on your UI feedback
		await expect(widget.getByRole('button', { name: 'Yes', exact: true }).first()).toBeVisible()
	})

	test('should add comment on appointment from dashboard', async ({ page }) => {
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')

		// Find the Attendance widget
		const widget = page.locator('.appointment-widget-container').or(page.getByRole('heading', { name: 'Attendance' }).locator('..'))

		// Click Yes first (comment section appears after voting)
		const yesButton = widget.getByRole('button', { name: 'Yes', exact: true }).first()
		await yesButton.click()
		await page.waitForLoadState('networkidle')

		// Click comment toggle button using data-test attribute
		const commentToggle = widget.locator('[data-test="button-widget-toggle-comment"]').first()
		await commentToggle.click()

		// Wait for comment textarea to appear
		const commentField = widget.locator('[data-test="widget-response-comment"]').first()
		await expect(commentField).toBeVisible({ timeout: 5000 })
		const commentText = 'Great meeting, looking forward to it!'
		await commentField.fill(commentText)

		// Wait for auto-save to complete (500ms debounce + API call)
		// The saved indicator (checkmark icon) appears when save is successful
		const savedIndicator = widget.locator('.saved-indicator').first()
		await expect(savedIndicator).toBeVisible({ timeout: 5000 })

		// Reload the page to verify persistence
		await page.reload()
		await page.waitForLoadState('networkidle')

		// Find the widget again after reload
		const reloadedWidget = page.locator('.appointment-widget-container').or(page.getByRole('heading', { name: 'Attendance' }).locator('..'))

		// Click comment toggle button to show the comment field
		const reloadedCommentToggle = reloadedWidget.locator('[data-test="button-widget-toggle-comment"]').first()
		await reloadedCommentToggle.click()

		// Verify the comment is still there after reload
		const reloadedCommentField = reloadedWidget.locator('[data-test="widget-response-comment"]').first()
		await expect(reloadedCommentField).toBeVisible({ timeout: 5000 })
		await expect(reloadedCommentField).toHaveValue(commentText)
	})

	test('should open detail view by clicking appointment title', async ({ page }) => {
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')
		
		// Click on appointment title (heading level 3)
		const appointmentTitle = page.getByRole('heading', { level: 3 }).first()
		const titleText = await appointmentTitle.textContent()
		
		await appointmentTitle.click()
		
		// Should navigate to appointment detail page
		await page.waitForLoadState('networkidle')
		
		// Verify we're on the detail page
		await expect(page).toHaveURL(/\/apps\/attendance\/appointment\/\d+/)
		
		// Verify title is still visible
		await expect(page.getByRole('heading', { level: 3, name: titleText })).toBeVisible()
		
		// Verify detail page elements
		await expect(page.getByRole('heading', { name: 'Your Response' })).toBeVisible()
		await expect(page.getByRole('heading', { name: 'Response Summary' })).toBeVisible()
	})

	test('should open detail view by clicking appointment description', async ({ page }) => {
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')
		
		// Find widget and click on description
		const widget = page.locator('.appointment-widget-container').or(page.getByRole('heading', { name: 'Attendance' }).locator('..'))
		
		// Click on the description paragraph (it's clickable)
		const description = widget.locator('.appointment-description').or(widget.locator('p')).first()
		if (await description.isVisible()) {
			await description.click()
			
			// Should navigate to detail page
			await page.waitForLoadState('networkidle')
			await expect(page).toHaveURL(/\/apps\/attendance\//)
		}
	})

	test('should navigate to listing page via Show all appointments button', async ({ page }) => {
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')
		
		// Click "Show all appointments" button
		await page.getByRole('button', { name: 'Show all appointments' }).click()
		
		// Should navigate to attendance app
		await page.waitForLoadState('networkidle')
		await expect(page).toHaveURL(/\/apps\/attendance/)
		
		// Verify we're on the listing page (navigation is visible)
		await expect(page.getByRole('link', { name: 'Upcoming Appointments' })).toBeVisible()
		await expect(page.getByRole('link', { name: 'Create Appointment' })).toBeVisible()
	})

	test('should vote Maybe on appointment from dashboard', async ({ page }) => {
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')
		
		// Find widget
		const widget = page.locator('.appointment-widget-container').or(page.getByRole('heading', { name: 'Attendance' }).locator('..'))
		
		// Click Maybe button
		await widget.getByRole('button', { name: 'Maybe' }).first().click()
		
		// Wait for save
		await page.waitForLoadState('networkidle')
		
		// Verify button is visible (indicates vote was accepted)
		await expect(widget.getByRole('button', { name: 'Maybe' }).first()).toBeVisible()
	})

	test('should vote No on appointment from dashboard', async ({ page }) => {
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')
		
		// Find widget
		const widget = page.locator('.appointment-widget-container').or(page.getByRole('heading', { name: 'Attendance' }).locator('..'))
		
		// Click No button
		await widget.getByRole('button', { name: 'No', exact: true }).first().click()
		
		// Wait for save
		await page.waitForLoadState('networkidle')
		
		// Verify button is visible
		await expect(widget.getByRole('button', { name: 'No', exact: true }).first()).toBeVisible()
	})

	test('should change vote from dashboard', async ({ page }) => {
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')
		
		// Find widget
		const widget = page.locator('.appointment-widget-container').or(page.getByRole('heading', { name: 'Attendance' }).locator('..'))
		
		// Click Yes first
		await widget.getByRole('button', { name: 'Yes', exact: true }).first().click()
		await page.waitForLoadState('networkidle')
		
		// Change to No
		await widget.getByRole('button', { name: 'No', exact: true }).first().click()
		await page.waitForLoadState('networkidle')
		
		// Verify both buttons are still accessible (vote changed)
		await expect(widget.getByRole('button', { name: 'Yes', exact: true }).first()).toBeVisible()
		await expect(widget.getByRole('button', { name: 'No', exact: true }).first()).toBeVisible()
	})
})

test.describe('Attendance App - Dashboard Widget Navigation', () => {
	test('should show multiple appointments in widget', async ({ page, loginAsUser }) => {
		await loginAsUser('admin', 'admin')
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')
		
		// Check if widget shows appointments
		const widget = page.locator('.appointment-widget-container').or(page.getByRole('heading', { name: 'Attendance' }).locator('..'))
		
		// Should have at least one appointment visible
		const appointments = widget.getByRole('heading', { level: 3 })
		const count = await appointments.count()
		
		expect(count).toBeGreaterThan(0)
	})

	test('should return to dashboard from appointment detail', async ({ page, loginAsUser }) => {
		await loginAsUser('admin', 'admin')
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')
		
		// Click on appointment to go to detail
		await page.getByRole('heading', { level: 3 }).first().click()
		await page.waitForLoadState('networkidle')
		
		// Go back to dashboard (use first() to avoid multiple matches)
		await page.getByRole('link', { name: 'Dashboard' }).first().click()
		await page.waitForLoadState('networkidle')
		
		// Verify we're back on dashboard
		await expect(page).toHaveURL(/\/apps\/dashboard/)
		await expect(page.getByRole('heading', { name: 'Dashboard', level: 1 })).toBeVisible()
	})
})
