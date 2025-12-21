import { test, expect } from './fixtures/nextcloud.js'

test.describe('Attendance App - Basic Navigation', () => {
	test('should load the attendance app', async ({ page, loginAsUser, attendanceApp }) => {
		// Login as admin
		await loginAsUser('admin', 'admin')

		// Navigate to attendance app
		await attendanceApp()

		// Wait for app to fully load
		await page.waitForLoadState('networkidle')

		// Verify the app loaded
		await expect(page).toHaveTitle(/Attendance.*Nextcloud/)
		
		// Wait for Vue app to be mounted
		await expect(page.locator('#app-content-vue')).toBeVisible()
		
		// Check for navigation
		await expect(page.getByRole('link', { name: /upcoming appointments/i })).toBeVisible()
	})

	test('should display navigation sections', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		
		// Wait for app to load
		await page.waitForLoadState('networkidle')
		
		// Check navigation items are visible using data-test attributes
		await expect(page.locator('[data-test="nav-upcoming"]')).toBeVisible()
		await expect(page.locator('[data-test="nav-past"]')).toBeVisible()
	})

	test('should show create appointment button for admin', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		
		// Wait for app to load
		await page.waitForLoadState('networkidle')
		
		// Admin should see create button using data-test attributes
		await expect(page.locator('[data-test="button-create-appointment"]')).toBeVisible()
		await expect(page.locator('[data-test="button-export"]')).toBeVisible()
	})
})

test.describe('Attendance App - User Permissions', () => {
	test('admin should see admin controls', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		
		// Wait for the app to load
		await page.waitForLoadState('networkidle')
		
		// Admin should see create and export buttons using data-test
		await expect(page.locator('[data-test="button-create-appointment"]')).toBeVisible()
		await expect(page.locator('[data-test="button-export"]')).toBeVisible()
	})

	test('regular user should access the app', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('test', 'test')
		await attendanceApp()
		
		// Wait for the app to load
		await page.waitForLoadState('networkidle')
		
		// Verify user can access the app
		await expect(page.locator('#app-content-vue')).toBeVisible()
		
		// Navigation should be visible using data-test
		await expect(page.locator('[data-test="nav-upcoming"]')).toBeVisible()
	})
})
