import { test as base } from '@playwright/test'

/**
 * Extended test fixture with Nextcloud-specific helpers
 */
export const test = base.extend({
	/**
	 * Login to Nextcloud with given credentials
	 */
	loginAsUser: async ({ page, baseURL }, use) => {
		const login = async (username, password = 'admin') => {
			// Clear cookies and storage to ensure clean login state
			await page.context().clearCookies()
			
			// Navigate to login page
			await page.goto(`${baseURL}/login`)
			await page.waitForLoadState('networkidle')
			
			// Login with new credentials
			await page.getByRole('textbox', { name: /account name|email/i }).fill(username)
			await page.getByRole('textbox', { name: /password/i }).fill(password)
			await page.getByRole('button', { name: /log in/i }).click()
			// Wait for redirect to complete
			await page.waitForURL(/.*\/apps\/.*/, { timeout: 10000 })
		}
		await use(login)
	},

	/**
	 * Navigate to the attendance app
	 */
	attendanceApp: async ({ page, baseURL }, use) => {
		const navigateToApp = async () => {
			await page.goto(`${baseURL}/apps/attendance`)
			await page.waitForLoadState('networkidle')
		}
		await use(navigateToApp)
	},

	/**
	 * Admin user context
	 */
	adminPage: async ({ browser, baseURL }, use) => {
		const context = await browser.newContext()
		const page = await context.newPage()
		
		// Login as admin
		await page.goto(`${baseURL}/login`)
		await page.getByRole('textbox', { name: /account name|email/i }).fill('admin')
		await page.getByRole('textbox', { name: /password/i }).fill('admin')
		await page.getByRole('button', { name: /log in/i }).click()
		await page.waitForURL(/.*\/apps\/.*/, { timeout: 10000 })
		
		await use(page)
		
		await context.close()
	},
})

export { expect } from '@playwright/test'
