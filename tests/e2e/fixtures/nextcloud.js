import { test as base } from '@playwright/test'
import { existsSync, mkdirSync, readFileSync } from 'fs'
import { dirname, join } from 'path'
import { fileURLToPath } from 'url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const AUTH_DIR = join(__dirname, '..', '.auth')

/**
 * Get the path to the auth state file for a user
 */
function getAuthStatePath(username) {
	return join(AUTH_DIR, `${username}.json`)
}

/**
 * Ensure the auth directory exists
 */
function ensureAuthDir() {
	if (!existsSync(AUTH_DIR)) {
		mkdirSync(AUTH_DIR, { recursive: true })
	}
}

/**
 * Extended test fixture with Nextcloud-specific helpers
 */
export const test = base.extend({
	/**
	 * Login to Nextcloud with given credentials
	 * Uses cached auth state when available for faster test execution
	 */
	loginAsUser: async ({ page, baseURL }, use) => {
		const login = async (username, password = 'admin') => {
			ensureAuthDir()
			const authStatePath = getAuthStatePath(username)

			// Try to restore cached auth state
			if (existsSync(authStatePath)) {
				try {
					// Read the stored state and apply cookies
					const stateData = JSON.parse(readFileSync(authStatePath, 'utf-8'))
					if (stateData.cookies && stateData.cookies.length > 0) {
						await page.context().addCookies(stateData.cookies)

						// Navigate to dashboard to verify session is valid
						await page.goto(`${baseURL}/apps/dashboard/`)

						// Check if we're still logged in (not redirected to login)
						const currentUrl = page.url()
						if (!currentUrl.includes('/login')) {
							// Session is valid, we're done
							return
						}
					}
					// Session expired or no cookies, fall through to fresh login
				} catch {
					// Failed to restore state, fall through to fresh login
				}
			}

			// Clear cookies and storage to ensure clean login state
			await page.context().clearCookies()

			// Navigate to login page
			await page.goto(`${baseURL}/login`)
			await page.waitForLoadState('networkidle')

			// Login with credentials
			await page.getByRole('textbox', { name: /account name|email/i }).fill(username)
			await page.getByRole('textbox', { name: /password/i }).fill(password)
			await page.getByRole('button', { name: /log in/i }).click()

			// Wait for redirect to complete
			await page.waitForURL(/.*\/apps\/.*/, { timeout: 10000 })

			// Save auth state for future reuse
			try {
				await page.context().storageState({ path: authStatePath })
			} catch {
				// Ignore save errors - caching is optional optimization
			}
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
