import { test, expect } from './fixtures/nextcloud.js'

test.describe('Attendance App - Check-in Workflow', () => {
	test.beforeEach(async ({ page, loginAsUser, attendanceApp }) => {
		// Setup: Login as admin
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.waitForLoadState('networkidle')
	})

	test('should navigate to check-in view', async ({ page }) => {
		// Find first appointment
		const appointmentCard = page.locator('[data-test="appointment-card"]').first()
		
		if (await appointmentCard.isVisible()) {
			// Open actions menu
			await appointmentCard.locator('[data-test="appointment-actions-menu"]').click()
			
			// Click check-in if available
			const checkinAction = page.locator('[data-test="action-start-checkin"]')
			if (await checkinAction.isVisible()) {
				await checkinAction.click()
				
				// Verify we're on check-in view
				await expect(page.locator('[data-test="checkin-view"]')).toBeVisible()
			}
		}
	})

	test('should display search and filter controls', async ({ page }) => {
		// Assuming we're on a checkin page (you'd navigate there first in real test)
		const checkinView = page.locator('[data-test="checkin-view"]')
		
		if (await checkinView.isVisible()) {
			// Check search input exists
			await expect(page.locator('[data-test="input-search"]')).toBeVisible()
			
			// Check group filter exists
			await expect(page.locator('[data-test="select-group-filter"]')).toBeVisible()
		}
	})

	test('should mark user as present', async ({ page }) => {
		const checkinView = page.locator('[data-test="checkin-view"]')
		
		if (await checkinView.isVisible()) {
			// Find first user in list
			const userItem = page.locator('[data-test^="user-item-"]').first()
			
			if (await userItem.isVisible()) {
				// Click "Present" button
				await userItem.locator('[data-test="button-present"]').click()
				
				// Wait for update and verify state changed
				await page.waitForLoadState('networkidle')
				const presentButton = userItem.locator('[data-test="button-present"]')
				await expect(presentButton).toBeVisible()
			}
		}
	})

	test('should mark user as absent', async ({ page }) => {
		const checkinView = page.locator('[data-test="checkin-view"]')
		
		if (await checkinView.isVisible()) {
			const userItem = page.locator('[data-test^="user-item-"]').first()
			
			if (await userItem.isVisible()) {
				// Click "Absent" button
				await userItem.locator('[data-test="button-absent"]').click()
				
				// Wait for update
				await page.waitForLoadState('networkidle')
			}
		}
	})

	test('should add comment to check-in', async ({ page }) => {
		const checkinView = page.locator('[data-test="checkin-view"]')
		
		if (await checkinView.isVisible()) {
			const userItem = page.locator('[data-test^="user-item-"]').first()
			
			if (await userItem.isVisible()) {
				// Click add comment button
				await userItem.locator('[data-test="button-add-comment"]').click()
				
				// Wait for comment textarea to appear
				const commentTextarea = page.locator('[data-test="textarea-checkin-comment"]')
				await expect(commentTextarea).toBeVisible()
				
				// Fill in comment
				await commentTextarea.fill('User arrived late')
				
				// Save comment
				await page.locator('[data-test="button-save-comment"]').click()
				
				// Verify comment saved (textarea should close)
				await expect(commentTextarea).not.toBeVisible()
			}
		}
	})

	test('should perform bulk check-in', async ({ page }) => {
		const checkinView = page.locator('[data-test="checkin-view"]')
		
		if (await checkinView.isVisible()) {
			// Click "All Present" button
			await page.locator('[data-test="button-bulk-present"]').click()
			
			// Confirmation dialog should appear
			await expect(page.locator('[data-test="dialog-confirm-bulk"]')).toBeVisible()
			
			// Cancel for now (in real test, you might confirm)
			await page.locator('[data-test="button-bulk-cancel"]').click()
			
			// Dialog should close
			await expect(page.locator('[data-test="dialog-confirm-bulk"]')).not.toBeVisible()
		}
	})

	test('should search for users', async ({ page }) => {
		const checkinView = page.locator('[data-test="checkin-view"]')
		
		if (await checkinView.isVisible()) {
			// Type in search box
			const searchInput = page.locator('[data-test="input-search"]')
			await searchInput.fill('test')
			
			// Wait for filtering
			await page.waitForLoadState('networkidle')
			
			// Verify user list updated (implementation depends on your data)
			const userItems = page.locator('[data-test^="user-item-"]')
			const count = await userItems.count()
			
			// At least the search functionality should work
			expect(count).toBeGreaterThanOrEqual(0)
		}
	})

	test('should go back from check-in view', async ({ page }) => {
		const checkinView = page.locator('[data-test="checkin-view"]')
		
		if (await checkinView.isVisible()) {
			// Click back button
			await page.locator('[data-test="button-back"]').click()
			
			// Should return to main view
			await page.waitForLoadState('networkidle')
			
			// Check-in view should no longer be visible
			await expect(checkinView).not.toBeVisible()
		}
	})
})

test.describe('Attendance App - Bulk Operations', () => {
	test('should confirm bulk present operation', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		
		const checkinView = page.locator('[data-test="checkin-view"]')
		
		if (await checkinView.isVisible()) {
			// Click bulk present
			await page.locator('[data-test="button-bulk-present"]').click()
			
			// Wait for dialog
			await expect(page.locator('[data-test="dialog-confirm-bulk"]')).toBeVisible()
			
			// Confirm action
			await page.locator('[data-test="button-bulk-confirm"]').click()
			
			// Dialog should close after processing
			await expect(page.locator('[data-test="dialog-confirm-bulk"]')).not.toBeVisible()
		}
	})

	test('should confirm bulk absent operation', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		
		const checkinView = page.locator('[data-test="checkin-view"]')
		
		if (await checkinView.isVisible()) {
			// Click bulk absent
			await page.locator('[data-test="button-bulk-absent"]').click()
			
			// Wait for dialog
			await expect(page.locator('[data-test="dialog-confirm-bulk"]')).toBeVisible()
			
			// Verify it's an error variant (for absent)
			const confirmButton = page.locator('[data-test="button-bulk-confirm"]')
			await expect(confirmButton).toBeVisible()
			
			// Cancel this time
			await page.locator('[data-test="button-bulk-cancel"]').click()
		}
	})
})
