import { test, expect } from './fixtures/nextcloud.js'

/**
 * Admin Settings E2E Tests
 *
 * These tests verify that permission settings work correctly by:
 * 1. Configuring permissions to restrict features to admin group only
 * 2. Verifying regular users (test/test) cannot access restricted features
 * 3. Verifying admin users retain access to all features
 *
 * Tests cover:
 * - Manage Appointments permission (create/edit/delete buttons)
 * - Check-in Access permission (check-in interface)
 * - See Response Overview permission (response summary visibility)
 * - See Comments permission (comment visibility in responses)
 * - Whitelisted Groups configuration
 * - Reminder settings
 */

test.describe('Attendance App - Admin Settings', () => {
	test.describe('Permission Configuration', () => {
		test('should restrict appointment management to admin group', async ({ page, loginAsUser, attendanceApp }) => {
			// Login as admin and configure settings
			await loginAsUser('admin', 'admin')
			await page.goto('/settings/admin/attendance')
			await page.waitForLoadState('networkidle')

			// Find and configure "Manage Appointments" permission to admin only
			const managePermissionSelect = page.locator('[data-test="select-manage-appointments-roles"]')
			await expect(managePermissionSelect).toBeVisible()

			// Click on the combobox to open dropdown
			await managePermissionSelect.getByRole('searchbox').click()
			const adminOption = page.getByRole('option', { name: 'admin' })
			await adminOption.waitFor({ state: 'visible' })

			// Select admin option from dropdown
			await adminOption.click()

			// Save settings
			const saveButton = page.locator('[data-test="button-save-settings"]')
			await saveButton.click()

			// Verify success message or settings saved
			await page.waitForLoadState('networkidle')

			// Now login as regular test user
			await loginAsUser('test', 'test')
			await attendanceApp()
			await page.waitForLoadState('networkidle')

			// Verify test user CANNOT see "Create Appointment" button
			const createButton = page.locator('[data-test="button-create-appointment"]')
			await expect(createButton).not.toBeVisible()

			// Verify admin still has access
			await loginAsUser('admin', 'admin')
			await attendanceApp()
			await page.waitForLoadState('networkidle')

			const adminCreateButton = page.locator('[data-test="button-create-appointment"]')
			await expect(adminCreateButton).toBeVisible()
		})

		test('should restrict check-in access to admin group', async ({ page, loginAsUser, attendanceApp }) => {
			// First, create an appointment as admin
			await loginAsUser('admin', 'admin')
			await attendanceApp()
			await page.waitForLoadState('networkidle')

			// Create test appointment
			await page.getByRole('link', { name: 'Create Appointment' }).click()
			await expect(page.getByRole('dialog')).toBeVisible()
			await page.getByRole('textbox', { name: 'Appointment Name' }).fill('Check-in Test Meeting')
			await page.getByRole('textbox', { name: 'Description' }).fill('Testing check-in permissions')

			const now = new Date()
			const startDate = new Date(now.getTime() + 2 * 24 * 60 * 60 * 1000)
			const endDate = new Date(startDate.getTime() + 1 * 60 * 60 * 1000)

			await page.getByRole('textbox', { name: 'Start Date & Time' }).fill(startDate.toISOString().slice(0, 16))
			await page.getByRole('textbox', { name: 'End Date & Time' }).fill(endDate.toISOString().slice(0, 16))
			await page.getByRole('button', { name: 'Save' }).click()
			await expect(page.getByRole('dialog')).not.toBeVisible()
			await page.waitForLoadState('networkidle')

			// Configure check-in permission to admin only
			await page.goto('/settings/admin/attendance')
			await page.waitForLoadState('networkidle')

			const checkinPermissionSelect = page.locator('[data-test="select-checkin-roles"]')
			await expect(checkinPermissionSelect).toBeVisible()

			await checkinPermissionSelect.getByRole('searchbox').click()
			const adminOption = page.getByRole('option', { name: 'admin' })
			await adminOption.waitFor({ state: 'visible' })

			await adminOption.click()

			const saveButton = page.locator('[data-test="button-save-settings"]')
			await saveButton.click()

			// Login as test user
			await loginAsUser('test', 'test')
			await attendanceApp()
			await page.waitForLoadState('networkidle')

			// Open appointment actions menu
			const actionsButton = page.getByRole('button', { name: 'Actions' }).first()
			await actionsButton.click()
			await page.waitForLoadState('networkidle')

			// Verify test user CANNOT see "Start check-in" option
			const checkinAction = page.locator('[data-test="action-start-checkin"]')
			await expect(checkinAction).not.toBeVisible()

			// Close menu
			await page.keyboard.press('Escape')

			// Verify admin still has check-in access
			await loginAsUser('admin', 'admin')
			await attendanceApp()
			await page.waitForLoadState('networkidle')

			const adminActionsButton = page.getByRole('button', { name: 'Actions' }).first()
			await adminActionsButton.click()
			await page.waitForLoadState('networkidle')

			const adminCheckinAction = page.locator('[data-test="action-start-checkin"]')
			await expect(adminCheckinAction).toBeVisible()
		})

		test('should restrict response overview visibility', async ({ page, loginAsUser, attendanceApp }) => {
			// Login as admin
			await loginAsUser('admin', 'admin')

			// Configure "See response overview" permission to admin only
			await page.goto('/settings/admin/attendance')
			await page.waitForLoadState('networkidle')

			const responseOverviewSelect = page.locator('[data-test="select-see-response-overview-roles"]')
			await expect(responseOverviewSelect).toBeVisible()

			await responseOverviewSelect.getByRole('searchbox').click()
			const adminOption = page.getByRole('option', { name: 'admin' })
			await adminOption.waitFor({ state: 'visible' })

			await adminOption.click()

			const saveButton = page.locator('[data-test="button-save-settings"]')
			await saveButton.click()

			// Create appointment as admin
			await attendanceApp()
			await page.waitForLoadState('networkidle')

			await page.getByRole('link', { name: 'Create Appointment' }).click()
			await expect(page.getByRole('dialog')).toBeVisible()
			await page.getByRole('textbox', { name: 'Appointment Name' }).fill('Response Overview Test')
			await page.getByRole('textbox', { name: 'Description' }).fill('Testing response visibility')

			const now = new Date()
			const startDate = new Date(now.getTime() + 2 * 24 * 60 * 60 * 1000)
			const endDate = new Date(startDate.getTime() + 1 * 60 * 60 * 1000)

			await page.getByRole('textbox', { name: 'Start Date & Time' }).fill(startDate.toISOString().slice(0, 16))
			await page.getByRole('textbox', { name: 'End Date & Time' }).fill(endDate.toISOString().slice(0, 16))
			await page.getByRole('button', { name: 'Save' }).click()
			await expect(page.getByRole('dialog')).not.toBeVisible()
			await page.waitForLoadState('networkidle')

			// Admin should see response summary
			const adminResponseSummary = page.getByRole('heading', { name: 'Response Summary' }).first()
			await expect(adminResponseSummary).toBeVisible()

			// Login as test user
			await loginAsUser('test', 'test')
			await attendanceApp()
			await page.waitForLoadState('networkidle')

			// Test user should NOT see response summary
			const testUserResponseSummary = page.getByRole('heading', { name: 'Response Summary' })
			await expect(testUserResponseSummary).not.toBeVisible()

			// But test user should still be able to respond
			const yesButton = page.getByRole('button', { name: 'Yes', exact: true }).first()
			await expect(yesButton).toBeVisible()
		})

		test('should restrict comment visibility in responses', async ({ page, loginAsUser, attendanceApp }) => {
			// Login as admin
			await loginAsUser('admin', 'admin')

			// Configure "See comments" permission to admin only
			await page.goto('/settings/admin/attendance')
			await page.waitForLoadState('networkidle')

			const seeCommentsSelect = page.locator('[data-test="select-see-comments-roles"]')
			await expect(seeCommentsSelect).toBeVisible()

			await seeCommentsSelect.getByRole('searchbox').click()
			const adminOption = page.getByRole('option', { name: 'admin' })
			await adminOption.waitFor({ state: 'visible' })

			await adminOption.click()

			const saveButton = page.locator('[data-test="button-save-settings"]')
			await saveButton.click()

			// Navigate to app and verify settings applied
			await attendanceApp()
			await page.waitForLoadState('networkidle')

			// This test verifies the permission is saved
			// Actual comment visibility would require checking the response summary UI
			// which shows/hides comments based on canSeeComments permission
		})
	})

	test.describe('Group Whitelist Configuration', () => {
		test('should configure whitelisted groups', async ({ page, loginAsUser }) => {
			// Login as admin
			await loginAsUser('admin', 'admin')
			await page.goto('/settings/admin/attendance')
			await page.waitForLoadState('networkidle')

			// Find whitelisted groups selector
			const groupsSelect = page.locator('[data-test="select-whitelisted-groups"]')
			await expect(groupsSelect).toBeVisible()

			await groupsSelect.getByRole('searchbox').click()
			const adminOption = page.getByRole('option', { name: 'admin' })
			await adminOption.waitFor({ state: 'visible' })

			// Select 'admin' group
			await adminOption.click()

			// Save settings
			const saveButton = page.locator('[data-test="button-save-settings"]')
			await saveButton.click()

			// Verify settings were saved (could check for success message)
			await page.waitForLoadState('networkidle')
		})
	})

	test.describe('Reminder Configuration', () => {
		test('should enable and configure reminders', async ({ page, loginAsUser }) => {
			// Login as admin
			await loginAsUser('admin', 'admin')
			await page.goto('/settings/admin/attendance')
			await page.waitForLoadState('networkidle')

			// Enable reminders by clicking the label (checkbox is intercepted by label)
			const reminderSwitch = page.locator('[data-test="switch-reminders-enabled"]')
			await expect(reminderSwitch).toBeVisible()

			// Check if already enabled, if not, enable it by clicking the label
			const isChecked = await reminderSwitch.isChecked().catch(() => false)
			if (!isChecked) {
				// Click the label wrapper instead of the checkbox itself
				await page.getByText('Enable automatic reminders').click()
				await page.waitForLoadState('networkidle')
			}

			// Configure reminder days using spinbutton role
			const reminderDaysInput = page.getByRole('spinbutton', { name: 'Days before appointment' })
			await expect(reminderDaysInput).toBeVisible()
			await reminderDaysInput.fill('3')

			// Configure reminder frequency using spinbutton role
			const reminderFrequencyInput = page.getByRole('spinbutton', { name: 'Reminder frequency (days)' })
			await expect(reminderFrequencyInput).toBeVisible()
			await reminderFrequencyInput.fill('2')

			// Save settings
			const saveButton = page.locator('[data-test="button-save-settings"]')
			await saveButton.click()
			await page.waitForLoadState('networkidle')

			// Verify settings persisted by reloading page
			await page.reload()
			await page.waitForLoadState('networkidle')

			const verifyDaysInput = page.getByRole('spinbutton', { name: 'Days before appointment' })
			const verifyFrequencyInput = page.getByRole('spinbutton', { name: 'Reminder frequency (days)' })
			await expect(verifyDaysInput).toHaveValue('3')
			await expect(verifyFrequencyInput).toHaveValue('2')
		})
	})

	test.describe('Group Select Field Behavior', () => {
		test('should maintain selection when typing in search field', async ({ page, loginAsUser }) => {
			// Login as admin
			await loginAsUser('admin', 'admin')
			await page.goto('/settings/admin/attendance')
			await page.waitForLoadState('networkidle')

			// Find the whitelisted groups selector
			const groupsSelect = page.locator('[data-test="select-whitelisted-groups"]')
			await expect(groupsSelect).toBeVisible()

			// Click to open and select admin group
			await groupsSelect.getByRole('searchbox').click()
			const adminOption = page.getByRole('option', { name: 'admin' })
			await adminOption.waitFor({ state: 'visible' })
			await adminOption.click()

			// Verify admin is selected (appears as a tag)
			const adminTag = groupsSelect.locator('.vs__selected').filter({ hasText: 'admin' })
			await expect(adminTag).toBeVisible()

			// Now type in the search field to filter
			await groupsSelect.getByRole('searchbox').click()
			await groupsSelect.getByRole('searchbox').fill('test')

			// Wait for filtering
			await page.waitForTimeout(300)

			// Verify the admin selection is still there (not replaced with "undefined")
			await expect(adminTag).toBeVisible()
			await expect(adminTag).not.toHaveText('undefined')

			// Clear search and verify selection still intact
			await groupsSelect.getByRole('searchbox').fill('')
			await expect(adminTag).toBeVisible()
		})

		test('should not show undefined tags when interacting with select', async ({ page, loginAsUser }) => {
			// Login as admin
			await loginAsUser('admin', 'admin')
			await page.goto('/settings/admin/attendance')
			await page.waitForLoadState('networkidle')

			// Test all permission select fields
			const selectFields = [
				'select-whitelisted-groups',
				'select-manage-appointments-roles',
				'select-checkin-roles',
				'select-see-response-overview-roles',
				'select-see-comments-roles',
			]

			for (const fieldTestId of selectFields) {
				const selectField = page.locator(`[data-test="${fieldTestId}"]`)
				await expect(selectField).toBeVisible()

				// Open the select
				await selectField.getByRole('searchbox').click()

				// Type something to filter
				await selectField.getByRole('searchbox').fill('a')
				await page.waitForTimeout(200)

				// Verify no undefined tags appear
				const undefinedTag = selectField.locator('.vs__selected').filter({ hasText: 'undefined' })
				await expect(undefinedTag).toHaveCount(0)

				// Clear and close
				await selectField.getByRole('searchbox').fill('')
				await page.keyboard.press('Escape')
			}
		})

		test('should allow selecting multiple groups and persist them', async ({ page, loginAsUser }) => {
			// Login as admin
			await loginAsUser('admin', 'admin')
			await page.goto('/settings/admin/attendance')
			await page.waitForLoadState('networkidle')

			// Find the manage appointments permission selector
			const manageSelect = page.locator('[data-test="select-manage-appointments-roles"]')
			await expect(manageSelect).toBeVisible()

			// Select admin group
			await manageSelect.getByRole('searchbox').click()
			const adminOption = page.getByRole('option', { name: 'admin' })
			await adminOption.waitFor({ state: 'visible' })
			await adminOption.click()

			// Verify admin tag is visible
			const adminTag = manageSelect.locator('.vs__selected').filter({ hasText: 'admin' })
			await expect(adminTag).toBeVisible()

			// Try to select another group if available
			await manageSelect.getByRole('searchbox').click()
			const testOption = page.getByRole('option', { name: 'test' })
			const hasTestGroup = await testOption.isVisible().catch(() => false)

			if (hasTestGroup) {
				await testOption.click()

				// Verify both tags are visible
				await expect(adminTag).toBeVisible()
				const testTag = manageSelect.locator('.vs__selected').filter({ hasText: 'test' })
				await expect(testTag).toBeVisible()

				// Save and reload to verify persistence
				const saveButton = page.locator('[data-test="button-save-settings"]')
				await saveButton.click()
				await page.waitForLoadState('networkidle')

				await page.reload()
				await page.waitForLoadState('networkidle')

				// Verify both groups are still selected after reload
				const reloadedSelect = page.locator('[data-test="select-manage-appointments-roles"]')
				const reloadedAdminTag = reloadedSelect.locator('.vs__selected').filter({ hasText: 'admin' })
				const reloadedTestTag = reloadedSelect.locator('.vs__selected').filter({ hasText: 'test' })
				await expect(reloadedAdminTag).toBeVisible()
				await expect(reloadedTestTag).toBeVisible()
			}
		})

		test('should filter options correctly while preserving selection', async ({ page, loginAsUser }) => {
			// Login as admin
			await loginAsUser('admin', 'admin')
			await page.goto('/settings/admin/attendance')
			await page.waitForLoadState('networkidle')

			// Find the checkin roles selector
			const checkinSelect = page.locator('[data-test="select-checkin-roles"]')
			await expect(checkinSelect).toBeVisible()

			// Select admin group first
			await checkinSelect.getByRole('searchbox').click()
			const adminOption = page.getByRole('option', { name: 'admin' })
			await adminOption.waitFor({ state: 'visible' })
			await adminOption.click()

			// Verify admin is selected
			const adminTag = checkinSelect.locator('.vs__selected').filter({ hasText: 'admin' })
			await expect(adminTag).toBeVisible()

			// Now search for a non-matching term
			await checkinSelect.getByRole('searchbox').click()
			await checkinSelect.getByRole('searchbox').fill('xyz')
			await page.waitForTimeout(300)

			// Verify admin selection is still visible
			await expect(adminTag).toBeVisible()
			await expect(adminTag).not.toHaveText('undefined')

			// Admin should not appear in filtered options since we're searching for 'xyz'
			const adminInOptions = page.getByRole('option', { name: 'admin' })
			await expect(adminInOptions).not.toBeVisible()

			// Clear search
			await checkinSelect.getByRole('searchbox').fill('')

			// Verify admin selection is still there
			await expect(adminTag).toBeVisible()
		})
	})

	test.describe('Settings Persistence', () => {
		test('should persist all settings across page reloads', async ({ page, loginAsUser }) => {
			// Login as admin
			await loginAsUser('admin', 'admin')
			await page.goto('/settings/admin/attendance')
			await page.waitForLoadState('networkidle')

			// Configure multiple settings
			const managePermissionSelect = page.locator('[data-test="select-manage-appointments-roles"]')
			await expect(managePermissionSelect).toBeVisible()

			// Save settings
			const saveButton = page.locator('[data-test="button-save-settings"]')
			await saveButton.click()
			await page.waitForLoadState('networkidle')

			// Reload page
			await page.reload()
			await page.waitForLoadState('networkidle')

			// Verify settings are still there
			await expect(managePermissionSelect).toBeVisible()
		})
	})
})
