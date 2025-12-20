import { test, expect } from './fixtures/nextcloud.js'

// Helper function to create an appointment with visibility settings
async function createAppointmentWithVisibility(page, { name, description, daysFromNow = 2, durationHours = 1, visibleUsers = [] }) {
	// Click create button
	await page.getByRole('link', { name: 'Create Appointment' }).click()
	
	// Wait for modal
	await expect(page.getByRole('dialog')).toBeVisible()
	await expect(page.getByRole('heading', { name: 'Create Appointment' })).toBeVisible()
	
	// Fill form
	await page.getByRole('textbox', { name: 'Appointment Name' }).fill(name)
	await page.getByRole('textbox', { name: 'Description' }).fill(description)
	
	// Calculate dates
	const now = new Date()
	const startDate = new Date(now.getTime() + daysFromNow * 24 * 60 * 60 * 1000)
	const endDate = new Date(startDate.getTime() + durationHours * 60 * 60 * 1000)
	
	await page.getByRole('textbox', { name: 'Start Date & Time' }).fill(startDate.toISOString().slice(0, 16))
	await page.getByRole('textbox', { name: 'End Date & Time' }).fill(endDate.toISOString().slice(0, 16))
	
	// Add visible users if specified
	if (visibleUsers.length > 0) {
		for (const username of visibleUsers) {
			// Use getByRole('searchbox') as it works reliably even after selections
			// The placeholder changes after first selection, so getByPlaceholder won't work
			await page.getByRole('searchbox').click()
			await page.getByRole('searchbox').fill(username)
			
			// Wait for search results option to appear
			const userOption = page.getByRole('option', { name: username })
			await userOption.waitFor({ state: 'visible' })
			
			// Select the user from dropdown
			await userOption.click()
			
			// Verify the user is now shown as selected in .vs__selected span
			const visibilitySelector = page.locator('[data-test="select-visibility"]')
			await expect(visibilitySelector.locator('.vs__selected', { hasText: username })).toBeVisible()
		}
	}
	
	// Save
	await page.getByRole('button', { name: 'Save' }).click()
	
	// Wait for modal to close
	await expect(page.getByRole('dialog')).not.toBeVisible()
	await page.waitForLoadState('networkidle')
}

test.describe('Attendance App - User Visibility Filtering', () => {
	// Configure permissions before running tests - restrict manage_appointments to admin group only
	// This ensures visibility filtering works correctly for regular users
	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()

		// Login as admin
		await page.goto('/login')
		await page.waitForLoadState('networkidle')
		await page.getByRole('textbox', { name: 'Account name or email' }).fill('admin')
		await page.getByRole('textbox', { name: 'Password' }).fill('admin')
		await page.getByRole('button', { name: 'Log in' }).click()
		await page.waitForURL('**/apps/dashboard/**')

		// Go to admin settings
		await page.goto('/settings/admin/attendance')
		await page.waitForLoadState('networkidle')

		// Configure manage_appointments permission to admin only
		const managePermissionSelect = page.locator('[data-test="select-manage-appointments-roles"]')
		await managePermissionSelect.getByRole('searchbox').click()
		const adminOption = page.getByRole('option', { name: 'admin' })
		await adminOption.waitFor({ state: 'visible' })
		await adminOption.click()

		// Save settings
		await page.locator('[data-test="button-save-settings"]').click()
		await page.waitForLoadState('networkidle')

		await page.close()
	})

	test.beforeEach(async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.waitForLoadState('networkidle')
	})

	test('should create appointment visible only to specific user', async ({ page }) => {
		await createAppointmentWithVisibility(page, {
			name: 'Private Meeting - Test1 Only',
			description: 'This appointment should only be visible to test1',
			daysFromNow: 2,
			durationHours: 1,
			visibleUsers: ['test1']
		})
		
		// Verify appointment appears for admin (creator can always see)
		await expect(page.getByText('Private Meeting - Test1 Only').first()).toBeVisible()
	})

	test('should create appointment visible to all users (no restrictions)', async ({ page }) => {
		await createAppointmentWithVisibility(page, {
			name: 'Public Team Meeting',
			description: 'Everyone can see this appointment',
			daysFromNow: 3,
			durationHours: 1,
			visibleUsers: [] // Empty = visible to all
		})
		
		// Verify appointment appears
		await expect(page.getByText('Public Team Meeting').first()).toBeVisible()
	})

	test('should create appointment visible to multiple specific users', async ({ page }) => {
		await createAppointmentWithVisibility(page, {
			name: 'Selective Access Meeting',
			description: 'Only test1 and test2 can see this',
			daysFromNow: 4,
			durationHours: 1,
			visibleUsers: ['test1', 'test2']
		})
		
		// Verify appointment appears
		await expect(page.getByText('Selective Access Meeting').first()).toBeVisible()
	})

	test('test1 should only see appointments visible to them', async ({ page, loginAsUser, attendanceApp }) => {
		// Logout and login as test1
		await loginAsUser('test1', 'test1')
		await attendanceApp()
		await page.waitForLoadState('networkidle')
		
		// Should see: "Private Meeting - Test1 Only", "Public Team Meeting", "Selective Access Meeting"
		await expect(page.getByText('Private Meeting - Test1 Only').first()).toBeVisible()
		await expect(page.getByText('Public Team Meeting').first()).toBeVisible()
		await expect(page.getByText('Selective Access Meeting').first()).toBeVisible()
	})

	test('unauthorized user should not see restricted appointments', async ({ page, loginAsUser, attendanceApp, browser, baseURL }) => {
		// Create an appointment that's NOT visible to test3
		
		// First, as admin, create an appointment visible only to test1
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.waitForLoadState('networkidle')
		
		await createAppointmentWithVisibility(page, {
			name: 'Test1 Only Meeting',
			description: 'Only test1 can see this',
			daysFromNow: 5,
			durationHours: 1,
			visibleUsers: ['test1']
		})
		
		// Verify admin sees it (managers see all)
		await expect(page.getByText('Test1 Only Meeting').first()).toBeVisible()
		
		// Now login as regular test user and verify they DON'T see it
		// (test user doesn't have manage_appointments permission after beforeAll setup)
		await loginAsUser('test', 'test')
		await attendanceApp()
		await page.waitForLoadState('networkidle')

		// Should NOT see "Test1 Only Meeting" in the main content (check heading specifically)
		const restrictedMeetingHeading = page.getByRole('heading', { name: 'Test1 Only Meeting' })
		await expect(restrictedMeetingHeading).not.toBeVisible()
	})

	test('appointment manager should see all appointments regardless of visibility', async ({ page, loginAsUser, attendanceApp }) => {
		// Admin has manage_appointments permission, so they should see everything
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.waitForLoadState('networkidle')

		// Navigate to Upcoming Appointments to see all appointments (not just unanswered)
		await page.getByRole('link', { name: 'Upcoming Appointments' }).click()
		await page.waitForLoadState('networkidle')

		// Wait for loading to complete - wait for Loading text to disappear
		const loadingIndicator = page.getByText('Loading...')
		try {
			await expect(loadingIndicator).not.toBeVisible({ timeout: 10000 })
		} catch {
			// Loading indicator may not appear if content loads quickly
		}

		// Count visible appointments by counting "Response Summary" headings
		const responseSummaryHeadings = page.getByRole('heading', { name: 'Response Summary' })
		const count = await responseSummaryHeadings.count()

		// Should see at least the appointments we created in this test suite
		expect(count).toBeGreaterThanOrEqual(4)
	})

	test('should allow editing visibility settings', async ({ page }) => {
		// Open the "Private Meeting - Test1 Only" for editing
		await page.getByText('Private Meeting - Test1 Only').first().click()
		
		// Wait for actions button to be ready and click Edit
		const actionsButton = page.getByRole('button', { name: 'Actions' }).first()
		await actionsButton.waitFor({ state: 'visible' })
		await actionsButton.click()
		await page.getByRole('menuitem', { name: 'Edit' }).click()
		
		// Wait for modal
		await expect(page.getByRole('dialog')).toBeVisible()
		await expect(page.getByRole('heading', { name: 'Edit Appointment' })).toBeVisible()
		
		// Verify that "test1" is shown in the visibility field
		// The visibility selector should show the selected user(s) in .vs__selected spans
		// Scope to the visibility selector component to avoid ambiguity
		const visibilitySelector = page.locator('[data-test="select-visibility"]')
		await expect(visibilitySelector.locator('.vs__selected', { hasText: 'test1' })).toBeVisible()
		
		// Add another user to visibility
		await page.getByRole('searchbox').click()
		await page.getByRole('searchbox').fill('test2')
		
		// Wait for test2 option to appear and select it
		const test2Option = page.getByRole('option', { name: 'test2' })
		await test2Option.waitFor({ state: 'visible' })
		await test2Option.click()
		
		// Verify test2 is now shown as selected in .vs__selected span
		await expect(visibilitySelector.locator('.vs__selected', { hasText: 'test2' })).toBeVisible()
		
		// Save
		await page.getByRole('button', { name: 'Save' }).click()
		await expect(page.getByRole('dialog')).not.toBeVisible()
		await page.waitForLoadState('networkidle')
	})
})
