import { createGroupViaOCS, deleteAllAppointments, expect, resetAdminSettings, restrictPermissionToGroup, saveAdminSettings, test, waitForSettingsSave } from './fixtures/nextcloud.js'

/**
 * Admin Settings E2E Tests
 *
 * These tests verify that permission settings work correctly by:
 * 1. Configuring permissions to restrict features to admin group only
 * 2. Verifying regular users (test/test) cannot access restricted features
 * 3. Verifying admin users retain access to all features
 *
 * The settings page auto-saves: every change posts a partial payload to the
 * admin settings endpoint, so the tests wait for that POST instead of
 * clicking a save button.
 *
 * Tests cover:
 * - Manage Appointments permission (create/edit/delete buttons)
 * - Check-in Access permission (check-in interface)
 * - See Response Overview permission (response summary visibility)
 * - See Response Counts permission (aggregate bar visibility)
 * - See Comments permission (comment visibility in responses)
 * - Whitelisted Groups configuration
 * - Reminder settings
 */

test.describe('Attendance App - Admin Settings', () => {
	test.afterAll(async ({ request }) => {
		await resetAdminSettings(request)
		await deleteAllAppointments(request)
	})

	test.describe('Permission Configuration', () => {
		test('should restrict appointment management to admin group', async ({ page, loginAsUser, attendanceApp }) => {
			// Login as admin and configure settings
			await loginAsUser('admin', 'admin')
			await page.goto('/settings/admin/attendance')
			await page.waitForLoadState('networkidle')

			await restrictPermissionToGroup(page, 'manage_appointments', 'admin')

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
			await page.getByRole('button', { name: 'Create Appointment' }).click()
			await page.waitForURL(/.*\/create$/)
			await page.waitForLoadState('networkidle')
			await expect(page.getByRole('heading', { name: 'Create Appointment' })).toBeVisible()
			await page.getByRole('textbox', { name: 'Appointment Name' }).fill('Check-in Test Meeting')
			const descEditor1 = page.locator('[data-test="input-appointment-description"] .CodeMirror')
			await descEditor1.waitFor({ state: 'visible' })
			await descEditor1.click()
			await page.keyboard.type('Testing check-in permissions')

			const now = new Date()
			const startDate = new Date(now.getTime() + 2 * 24 * 60 * 60 * 1000)
			const endDate = new Date(startDate.getTime() + 1 * 60 * 60 * 1000)

			await page.getByRole('textbox', { name: 'Start Date & Time' }).fill(startDate.toISOString().slice(0, 16))
			await page.getByRole('textbox', { name: 'End Date & Time' }).fill(endDate.toISOString().slice(0, 16))
			await page.getByRole('button', { name: 'Save' }).click()
			await page.waitForURL(/.*\/apps\/attendance(?!\/(create|edit|copy))/)
			await page.waitForLoadState('networkidle')

			// Configure check-in permission to admin only
			await page.goto('/settings/admin/attendance')
			await page.waitForLoadState('networkidle')

			await restrictPermissionToGroup(page, 'checkin', 'admin')

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

			await restrictPermissionToGroup(page, 'see_response_overview', 'admin')

			// Create appointment as admin
			await attendanceApp()
			await page.waitForLoadState('networkidle')

			await page.getByRole('button', { name: 'Create Appointment' }).click()
			await page.waitForURL(/.*\/create$/)
			await page.waitForLoadState('networkidle')
			await expect(page.getByRole('heading', { name: 'Create Appointment' })).toBeVisible()
			await page.getByRole('textbox', { name: 'Appointment Name' }).fill('Response Overview Test')
			const descEditor2 = page.locator('[data-test="input-appointment-description"] .CodeMirror')
			await descEditor2.waitFor({ state: 'visible' })
			await descEditor2.click()
			await page.keyboard.type('Testing response visibility')

			const now = new Date()
			const startDate = new Date(now.getTime() + 2 * 24 * 60 * 60 * 1000)
			const endDate = new Date(startDate.getTime() + 1 * 60 * 60 * 1000)

			await page.getByRole('textbox', { name: 'Start Date & Time' }).fill(startDate.toISOString().slice(0, 16))
			await page.getByRole('textbox', { name: 'End Date & Time' }).fill(endDate.toISOString().slice(0, 16))
			await page.getByRole('button', { name: 'Save' }).click()
			await page.waitForURL(/.*\/apps\/attendance(?!\/(create|edit|copy))/)
			await page.waitForLoadState('networkidle')

			// Admin should see the response overview (a bar on the list card)
			const adminResponseSummary = page.locator('[data-test="response-bar"]').first()
			await expect(adminResponseSummary).toBeVisible()

			// Login as test user
			await loginAsUser('test', 'test')
			await attendanceApp()
			await page.waitForLoadState('networkidle')

			// Test user still sees the aggregate bar — the counts permission
			// is open to everyone while unconfigured
			const testUserResponseSummary = page.locator('[data-test="response-bar"]')
			await expect(testUserResponseSummary.first()).toBeVisible()

			// And test user should still be able to respond
			const yesButton = page.getByRole('button', { name: 'Yes', exact: true }).first()
			await expect(yesButton).toBeVisible()

			// But the per-person breakdown stays hidden on the detail view
			await page.locator('[data-test="button-show-details"]').first().click()
			await page.waitForLoadState('networkidle')
			await expect(page.locator('[data-test="response-summary"]')).toBeVisible()
			await expect(page.locator('[data-test="group-summary"]')).toHaveCount(0)
		})

		test('should restrict comment visibility in responses', async ({ page, loginAsUser, attendanceApp }) => {
			// Login as admin
			await loginAsUser('admin', 'admin')

			// Configure "See comments" permission to admin only
			await page.goto('/settings/admin/attendance')
			await page.waitForLoadState('networkidle')

			await restrictPermissionToGroup(page, 'see_comments', 'admin')

			// Navigate to app and verify settings applied
			await attendanceApp()
			await page.waitForLoadState('networkidle')

			// This test verifies the permission is saved
			// Actual comment visibility would require checking the response summary UI
			// which shows/hides comments based on canSeeComments permission
		})

		test('should restrict response counts visibility', async ({ page, loginAsUser, attendanceApp }) => {
			// Login as admin
			await loginAsUser('admin', 'admin')

			// Configure "See response counts" permission to admin only — the
			// overview is already admin-only from the spec above, so this
			// takes the last summary tier away from the test user
			await page.goto('/settings/admin/attendance')
			await page.waitForLoadState('networkidle')

			await restrictPermissionToGroup(page, 'see_response_counts', 'admin')

			// Test user now sees no bar at all
			await loginAsUser('test', 'test')
			await attendanceApp()
			await page.waitForLoadState('networkidle')
			await expect(page.locator('[data-test="response-bar"]')).toHaveCount(0)

			// … but still reaches the detail page for description and
			// attachments, which no summary permission gates.
			await expect(page.locator('[data-test="button-show-details"]').first()).toBeVisible()

			// Admin keeps the full view
			await loginAsUser('admin', 'admin')
			await attendanceApp()
			await page.waitForLoadState('networkidle')
			await expect(page.locator('[data-test="response-bar"]').first()).toBeVisible()
		})

		test('should deny a permission to everyone via the nobody mode', async ({ page, loginAsUser, attendanceApp }) => {
			// Login as admin and set check-in access to nobody
			await loginAsUser('admin', 'admin')
			await page.goto('/settings/admin/attendance')
			await page.waitForLoadState('networkidle')

			const row = page.locator('[data-test="permission-checkin"]')
			await expect(row).toBeVisible()
			const saved = waitForSettingsSave(page)
			await row.getByText('Nobody', { exact: true }).click()
			await saved

			// Even the admin loses the check-in entry point now
			await attendanceApp()
			await page.waitForLoadState('networkidle')

			const actionsButton = page.getByRole('button', { name: 'Actions' }).first()
			await actionsButton.click()
			await page.waitForLoadState('networkidle')
			await expect(page.locator('[data-test="action-start-checkin"]')).not.toBeVisible()
			await page.keyboard.press('Escape')
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

			await groupsSelect.getByRole('combobox').click()
			const adminOption = page.getByRole('option', { name: 'admin' })
			await adminOption.waitFor({ state: 'visible' })

			// Select 'admin' group and wait for the auto-save
			const saved = waitForSettingsSave(page)
			await adminOption.click()
			await saved
		})
	})

	test.describe('Group Order Configuration', () => {
		const firstGroup = 'attendance-e2e-order-a'
		const secondGroup = 'attendance-e2e-order-b'

		test.beforeAll(async ({ request }) => {
			await createGroupViaOCS(request, firstGroup)
			await createGroupViaOCS(request, secondGroup)
			await saveAdminSettings(request, { whitelistedGroups: [firstGroup, secondGroup] })
		})

		test.afterAll(async ({ request }) => {
			await resetAdminSettings(request)
		})

		test('should reorder whitelisted groups and persist the new order', async ({ page, loginAsUser }) => {
			await loginAsUser('admin', 'admin')
			await page.goto('/settings/admin/attendance')
			await page.waitForLoadState('networkidle')

			const items = page.locator('[data-test="order-whitelisted-groups-item"]')
			await expect(items).toHaveCount(2)
			await expect(items.first()).toContainText(firstGroup)

			const saved = waitForSettingsSave(page)
			await items.first().locator('[data-test="order-whitelisted-groups-down"]').click()
			await saved

			await page.reload()
			await page.waitForLoadState('networkidle')

			const reloaded = page.locator('[data-test="order-whitelisted-groups-item"]')
			await expect(reloaded.first()).toContainText(secondGroup)
			await expect(reloaded.last()).toContainText(firstGroup)
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

			// Configure reminder frequency using spinbutton role; the two edits
			// collapse into one debounced auto-save
			const reminderFrequencyInput = page.getByRole('spinbutton', { name: 'Reminder frequency (days)' })
			await expect(reminderFrequencyInput).toBeVisible()
			const saved = waitForSettingsSave(page)
			await reminderFrequencyInput.fill('2')
			await saved

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
			await groupsSelect.getByRole('combobox').click()
			const adminOption = page.getByRole('option', { name: 'admin' })
			await adminOption.waitFor({ state: 'visible' })
			await adminOption.click()

			// Verify admin is selected (appears as a tag)
			const adminTag = groupsSelect.locator('.vs__selected').filter({ hasText: 'admin' })
			await expect(adminTag).toBeVisible()

			// Now type in the search field to filter
			await groupsSelect.getByRole('combobox').click()
			await groupsSelect.getByRole('combobox').fill('test')

			// Wait for filtering
			await page.waitForTimeout(300)

			// Verify the admin selection is still there (not replaced with "undefined")
			await expect(adminTag).toBeVisible()
			await expect(adminTag).not.toHaveText('undefined')

			// Clear search and verify selection still intact
			await groupsSelect.getByRole('combobox').fill('')
			await expect(adminTag).toBeVisible()
		})

		test('should not show undefined tags when interacting with select', async ({ page, loginAsUser }) => {
			// Login as admin
			await loginAsUser('admin', 'admin')
			await page.goto('/settings/admin/attendance')
			await page.waitForLoadState('networkidle')

			// Permission selects only render in "Specific groups" mode
			const permissionNames = ['manage_appointments', 'checkin', 'see_response_overview', 'see_comments']
			for (const permission of permissionNames) {
				const row = page.locator(`[data-test="permission-${permission}"]`)
				await row.getByText('Specific groups', { exact: true }).click()
			}

			const selectFields = [
				'select-whitelisted-groups',
				...permissionNames.map((permission) => `permission-${permission}-groups`),
			]

			for (const fieldTestId of selectFields) {
				const selectField = page.locator(`[data-test="${fieldTestId}"]`)
				await expect(selectField).toBeVisible()

				// Open the select
				await selectField.getByRole('combobox').click()

				// Type something to filter
				await selectField.getByRole('combobox').fill('a')
				await page.waitForTimeout(200)

				// Verify no undefined tags appear
				const undefinedTag = selectField.locator('.vs__selected').filter({ hasText: 'undefined' })
				await expect(undefinedTag).toHaveCount(0)

				// Clear and close
				await selectField.getByRole('combobox').fill('')
				await page.keyboard.press('Escape')
			}
		})

		test('should allow selecting multiple groups and persist them', async ({ page, loginAsUser }) => {
			// Login as admin
			await loginAsUser('admin', 'admin')
			await page.goto('/settings/admin/attendance')
			await page.waitForLoadState('networkidle')

			// Switch the manage row to specific groups to reveal the select
			const row = page.locator('[data-test="permission-manage_appointments"]')
			await row.getByText('Specific groups', { exact: true }).click()

			const manageSelect = page.locator('[data-test="permission-manage_appointments-groups"]')
			await expect(manageSelect).toBeVisible()

			// Select admin group
			await manageSelect.getByRole('combobox').click()
			const adminOption = page.getByRole('option', { name: 'admin' })
			await adminOption.waitFor({ state: 'visible' })
			await adminOption.click()

			// Verify admin tag is visible
			const adminTag = manageSelect.locator('.vs__selected').filter({ hasText: 'admin' })
			await expect(adminTag).toBeVisible()

			// Try to select another group if available
			await manageSelect.getByRole('combobox').click()
			const testOption = page.getByRole('option', { name: 'test' })
			const hasTestGroup = await testOption.isVisible().catch(() => false)

			if (hasTestGroup) {
				const saved = waitForSettingsSave(page)
				await testOption.click()

				// Verify both tags are visible
				await expect(adminTag).toBeVisible()
				const testTag = manageSelect.locator('.vs__selected').filter({ hasText: 'test' })
				await expect(testTag).toBeVisible()

				// Wait for the auto-save, then reload to verify persistence
				await saved

				await page.reload()
				await page.waitForLoadState('networkidle')

				// Verify both groups are still selected after reload
				const reloadedSelect = page.locator('[data-test="permission-manage_appointments-groups"]')
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

			// Switch the check-in row to specific groups to reveal the select
			const row = page.locator('[data-test="permission-checkin"]')
			await row.getByText('Specific groups', { exact: true }).click()

			const checkinSelect = page.locator('[data-test="permission-checkin-groups"]')
			await expect(checkinSelect).toBeVisible()

			// Select admin group first
			await checkinSelect.getByRole('combobox').click()
			const adminOption = page.getByRole('option', { name: 'admin' })
			await adminOption.waitFor({ state: 'visible' })
			await adminOption.click()

			// Verify admin is selected
			const adminTag = checkinSelect.locator('.vs__selected').filter({ hasText: 'admin' })
			await expect(adminTag).toBeVisible()

			// Now search for a non-matching term
			await checkinSelect.getByRole('combobox').click()
			await checkinSelect.getByRole('combobox').fill('xyz')
			await page.waitForTimeout(300)

			// Verify admin selection is still visible
			await expect(adminTag).toBeVisible()
			await expect(adminTag).not.toHaveText('undefined')

			// Admin should not appear in filtered options since we're searching for 'xyz'
			const adminInOptions = page.getByRole('option', { name: 'admin' })
			await expect(adminInOptions).not.toBeVisible()

			// Clear search
			await checkinSelect.getByRole('combobox').fill('')

			// Verify admin selection is still there
			await expect(adminTag).toBeVisible()
		})
	})

	test.describe('Settings Persistence', () => {
		test('should persist the selected mode across page reloads', async ({ page, loginAsUser }) => {
			// Login as admin
			await loginAsUser('admin', 'admin')
			await page.goto('/settings/admin/attendance')
			await page.waitForLoadState('networkidle')

			await restrictPermissionToGroup(page, 'manage_appointments', 'admin')

			// Reload page
			await page.reload()
			await page.waitForLoadState('networkidle')

			// The row comes back in "Specific groups" mode with the select visible
			const managePermissionSelect = page.locator('[data-test="permission-manage_appointments-groups"]')
			await expect(managePermissionSelect).toBeVisible()
			const adminTag = managePermissionSelect.locator('.vs__selected').filter({ hasText: 'admin' })
			await expect(adminTag).toBeVisible()
		})
	})
})
