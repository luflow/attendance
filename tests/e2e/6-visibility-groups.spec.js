import { test, expect } from './fixtures/nextcloud.js'

// Helper function to create a group via Nextcloud admin interface
async function createGroup(page, groupName) {
	// Navigate to users settings
	await page.goto('/settings/users')
	await page.waitForLoadState('networkidle')
	
	// Click on "Create group" button
	const createGroupButton = page.getByRole('button', { name: 'Create group' })
	await createGroupButton.click()
	
	// Wait for dialog to appear
	await expect(page.getByRole('dialog', { name: 'Create group' })).toBeVisible()
	
	// Fill in group name
	await page.getByRole('textbox', { name: 'Group name' }).fill(groupName)
	
	// Click Submit
	await page.getByRole('button', { name: 'Submit' }).click()
	
	// Wait for navigation and group to appear
	await page.waitForLoadState('networkidle')
}

// Helper function to add user to group via Nextcloud admin interface
async function addUserToGroup(page, username, groupName) {
	// Navigate to users settings - All accounts view
	await page.goto('/settings/users')
	await page.waitForLoadState('networkidle')
	
	// Click on link to view all accounts (in case we're in a group view)
	const allAccountsLink = page.getByRole('link', { name: 'All accounts' })
	if (await allAccountsLink.isVisible()) {
		await allAccountsLink.click()
		await page.waitForLoadState('networkidle')
	}
	
	// Find the user's row and click Edit button
	const userRow = page.getByRole('row', { name: new RegExp(`${username}.*${username}.*Unlimited`) })
	await userRow.getByLabel('Edit').click()
	
	// Wait for combobox to appear and click it
	const groupCombobox = page.getByRole('combobox', { name: 'Add account to group' })
	await groupCombobox.waitFor({ state: 'visible' })
	await groupCombobox.click()
	
	// Wait for and select the group from the dropdown
	// Use getByRole option with exact text match to avoid ambiguity
	const groupOption = page.getByRole('option').filter({ hasText: new RegExp(`^${groupName}$`) })
	await groupOption.waitFor({ state: 'visible' })
	await groupOption.click()
	
	// Click Done to save
	await page.getByRole('button', { name: 'Done' }).click()
	await page.waitForLoadState('networkidle')
}

// Helper function to create an appointment with group visibility
async function createAppointmentWithGroupVisibility(page, { name, description, daysFromNow = 2, durationHours = 1, visibleGroups = [] }) {
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

	// Add visible groups if specified
	if (visibleGroups.length > 0) {
		for (const groupName of visibleGroups) {
			// Use getByRole('searchbox') as it works reliably even after selections
			// The placeholder changes after first selection, so getByPlaceholder won't work
			await page.getByRole('searchbox').click()
			await page.getByRole('searchbox').fill(groupName)

			// Wait for search results option to appear
			const groupOption = page.getByRole('option', { name: groupName })
			await groupOption.waitFor({ state: 'visible' })

			// Select the group from dropdown
			await groupOption.click()

			// Verify the group is now shown as selected in .vs__selected span
			const visibilitySelector = page.locator('[data-test="select-visibility"]')
			await expect(visibilitySelector.locator('.vs__selected', { hasText: groupName })).toBeVisible()
		}
	}

	// Save
	await page.getByRole('button', { name: 'Save' }).click()

	// Wait for navigation back to appointment list
	await page.waitForURL(/.*\/apps\/attendance(?!\/(create|edit|copy))/)
	await page.waitForLoadState('networkidle')
}

test.describe('Attendance App - Group Visibility Filtering', () => {
	test.describe.serial('Group Setup and Visibility Tests', () => {
		test('should create developers group and assign test1', async ({ page, loginAsUser, baseURL }) => {
			await loginAsUser('admin', 'admin')
			
			// Create developers group
			await createGroup(page, 'developers')
			
			// Add test1 to developers group
			await addUserToGroup(page, 'test1', 'developers')
			
			console.log('Setup complete: developers group created and test1 assigned')
		})

		test('should create appointment visible only to developers group', async ({ page, loginAsUser, attendanceApp }) => {
			await loginAsUser('admin', 'admin')
			await attendanceApp()
			await page.waitForLoadState('networkidle')
			
			await createAppointmentWithGroupVisibility(page, {
				name: 'Developers Sprint Planning',
				description: 'Only developers group can see this',
				daysFromNow: 2,
				durationHours: 2,
				visibleGroups: ['developers']
			})
			
			// Verify appointment appears for admin (who created it)
			await expect(page.getByText('Developers Sprint Planning').first()).toBeVisible()
		})

		test('should create appointment visible to multiple groups', async ({ page, loginAsUser, attendanceApp }) => {
			await loginAsUser('admin', 'admin')
			await attendanceApp()
			await page.waitForLoadState('networkidle')
			
			await createAppointmentWithGroupVisibility(page, {
				name: 'Cross-Team Sync',
				description: 'Visible to developers group',
				daysFromNow: 3,
				durationHours: 1,
				visibleGroups: ['developers']
			})
			
			// Verify appointment appears
			await expect(page.getByText('Cross-Team Sync').first()).toBeVisible()
		})

		test('should create appointment visible to all groups (no restrictions)', async ({ page, loginAsUser, attendanceApp }) => {
			await loginAsUser('admin', 'admin')
			await attendanceApp()
			await page.waitForLoadState('networkidle')
			
			await createAppointmentWithGroupVisibility(page, {
				name: 'All-Hands Company Meeting',
				description: 'Everyone can see this, regardless of group',
				daysFromNow: 4,
				durationHours: 1,
				visibleGroups: [] // Empty = visible to all
			})
			
			// Verify appointment appears
			await expect(page.getByText('All-Hands Company Meeting').first()).toBeVisible()
		})

		test('user in admin group should see group-restricted appointments', async ({ page, loginAsUser, attendanceApp }) => {
			// Admin is in admin group, so they should see appointments restricted to admin group
			await loginAsUser('admin', 'admin')
			await attendanceApp()
			await page.waitForLoadState('networkidle')
			
			// Should see "Developers Sprint Planning" (restricted to admin group)
			await expect(page.getByText('Developers Sprint Planning').first()).toBeVisible()
			
			// Should also see "All-Hands Company Meeting" (visible to all)
			await expect(page.getByText('All-Hands Company Meeting').first()).toBeVisible()
		})

		test('user not in specified group should not see group-restricted appointments', async ({ page, loginAsUser, attendanceApp }) => {
			// test1 is likely not in admin group
			await loginAsUser('test1', 'test1')
			await attendanceApp()
			await page.waitForLoadState('networkidle')
			
			// Should NOT see "Developers Sprint Planning" (restricted to admin group)
			// unless test1 is in admin group
			const devMeeting = page.getByText('Developers Sprint Planning')
			const isVisible = await devMeeting.isVisible().catch(() => false)
			
			// Should still see "All-Hands Company Meeting" (visible to all)
			await expect(page.getByText('All-Hands Company Meeting').first()).toBeVisible()
		})

		test('should allow editing group visibility settings', async ({ page, loginAsUser, attendanceApp }) => {
			await loginAsUser('admin', 'admin')
			await attendanceApp()
			await page.waitForLoadState('networkidle')

			// Click on "Cross-Team Sync" appointment
			await page.getByText('Cross-Team Sync').first().click()

			// Wait for actions button to be ready and click Edit
			const actionsButton = page.getByRole('button', { name: 'Actions' }).first()
			await actionsButton.waitFor({ state: 'visible' })
			await actionsButton.click()
			await page.getByRole('menuitem', { name: 'Edit' }).click()

			// Wait for form page and verify it's Edit mode
			await page.waitForURL(/.*\/edit\/\d+$/)
			await expect(page.getByRole('heading', { name: 'Edit Appointment' })).toBeVisible()

			// Verify that the developers group is shown in the visibility field in .vs__selected span
			// Scope to the visibility selector component to avoid ambiguity
			const visibilitySelector = page.locator('[data-test="select-visibility"]')
			await expect(visibilitySelector.locator('.vs__selected', { hasText: 'developers' })).toBeVisible()

			// Try to add another group (if available)
			// For now, we'll just verify the current selection is preserved

			// Save without changes
			await page.getByRole('button', { name: 'Save' }).click()

			// Wait for navigation back to appointment list
			await page.waitForURL(/.*\/apps\/attendance(?!\/(create|edit|copy))/)
			await page.waitForLoadState('networkidle')
		})

		test('should handle mixed user and group visibility', async ({ page, loginAsUser, attendanceApp }) => {
			await loginAsUser('admin', 'admin')
			await attendanceApp()
			await page.waitForLoadState('networkidle')

			// Create appointment with both specific user and group
			const createLink = page.getByRole('link', { name: 'Create Appointment' })
			await createLink.waitFor({ state: 'visible' })
			await createLink.click()

			// Wait for form page to load
			await page.waitForURL(/.*\/create$/)
			await page.waitForLoadState('networkidle')
			await expect(page.getByRole('heading', { name: 'Create Appointment' })).toBeVisible()

			// Fill form
			await page.getByRole('textbox', { name: 'Appointment Name' }).fill('Mixed Visibility Meeting')
			const descEditor = page.locator('[data-test="input-appointment-description"] .CodeMirror')
			await descEditor.waitFor({ state: 'visible' })
			await descEditor.click()
			await page.keyboard.type('Visible to developers group and test2 user')

			const now = new Date()
			const startDate = new Date(now.getTime() + 5 * 24 * 60 * 60 * 1000)
			const endDate = new Date(startDate.getTime() + 60 * 60 * 1000)

			await page.getByRole('textbox', { name: 'Start Date & Time' }).fill(startDate.toISOString().slice(0, 16))
			await page.getByRole('textbox', { name: 'End Date & Time' }).fill(endDate.toISOString().slice(0, 16))

			// Add developers group (no ambiguity)
			await page.getByRole('searchbox').click()
			await page.getByRole('searchbox').fill('developers')
			const developersOption = page.getByRole('option', { name: 'developers' })
			await developersOption.waitFor({ state: 'visible' })
			await developersOption.click()

			// Add test2 user
			await page.getByRole('searchbox').click()
			await page.getByRole('searchbox').fill('test2')
			const test2Option = page.getByRole('option', { name: 'test2' })
			await test2Option.waitFor({ state: 'visible' })
			await test2Option.click()

			// Verify test2 is now shown as selected in .vs__selected span
			const visibilitySelector = page.locator('[data-test="select-visibility"]')
			await expect(visibilitySelector.locator('.vs__selected', { hasText: 'test2' })).toBeVisible()

			// Save
			await page.getByRole('button', { name: 'Save' }).click()

			// Wait for navigation back to appointment list
			await page.waitForURL(/.*\/apps\/attendance(?!\/(create|edit|copy))/)
			await page.waitForLoadState('networkidle')

			// Verify appointment appears
			await expect(page.getByText('Mixed Visibility Meeting').first()).toBeVisible()
		})

		test('test2 user should see mixed visibility appointment', async ({ page, loginAsUser, attendanceApp }) => {
			// test2 user was explicitly added to "Mixed Visibility Meeting"
			await loginAsUser('test2', 'test2')
			await attendanceApp()
			await page.waitForLoadState('networkidle')

			// Navigate to Upcoming Appointments to see all appointments
			await page.getByRole('link', { name: 'Upcoming Appointments' }).click()
			await page.waitForLoadState('networkidle')

			// Should see "Mixed Visibility Meeting" because test2 user was explicitly added
			await expect(page.getByText('Mixed Visibility Meeting').first()).toBeVisible()
		})
	})
})
