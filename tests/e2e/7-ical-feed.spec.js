import { test, expect } from './fixtures/nextcloud.js'

test.describe('Attendance App - iCal Feed', () => {
	test('should show Calendar Feed button in navigation', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()

		// Wait for app to load
		await page.waitForLoadState('networkidle')

		// Calendar Feed button should be visible in navigation footer
		await expect(page.locator('[data-test="button-calendar-feed"]')).toBeVisible()
	})

	test('should open Calendar Subscription modal when clicking Calendar Feed', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()

		await page.waitForLoadState('networkidle')

		// Click Calendar Feed button
		await page.locator('[data-test="button-calendar-feed"]').click()

		// Modal should open with title
		await expect(page.getByRole('dialog', { name: /calendar subscription/i })).toBeVisible()

		// Feed URL should be displayed
		await expect(page.locator('[data-test="input-ical-feed-url"]')).toBeVisible()

		// Copy button should be visible
		await expect(page.locator('[data-test="button-copy-url"]')).toBeVisible()

		// Regenerate button should be visible
		await expect(page.locator('[data-test="button-regenerate-url"]')).toBeVisible()
	})

	test('should display a valid feed URL', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()

		await page.waitForLoadState('networkidle')

		// Open modal
		await page.locator('[data-test="button-calendar-feed"]').click()
		await expect(page.getByRole('dialog', { name: /calendar subscription/i })).toBeVisible()

		// Wait for the feed URL to load (API call)
		const feedUrlElement = page.locator('[data-test="input-ical-feed-url"]')
		await expect(feedUrlElement).not.toBeEmpty({ timeout: 5000 })

		const feedUrl = await feedUrlElement.textContent()

		// URL should contain the expected path pattern
		expect(feedUrl).toMatch(/\/apps\/attendance\/ical\/[a-f0-9]{64}\.ics/)
	})

	test('should copy URL to clipboard when clicking copy button', async ({ page, context, loginAsUser, attendanceApp }) => {
		// Grant clipboard permissions
		await context.grantPermissions(['clipboard-read', 'clipboard-write'])

		await loginAsUser('admin', 'admin')
		await attendanceApp()

		await page.waitForLoadState('networkidle')

		// Open modal
		await page.locator('[data-test="button-calendar-feed"]').click()
		await expect(page.getByRole('dialog', { name: /calendar subscription/i })).toBeVisible()

		// Get the feed URL first
		const feedUrlElement = page.locator('[data-test="input-ical-feed-url"]')
		const feedUrl = await feedUrlElement.textContent()

		// Click copy button
		await page.locator('[data-test="button-copy-url"]').click()

		// Verify clipboard contains the URL
		const clipboardContent = await page.evaluate(() => navigator.clipboard.readText())
		expect(clipboardContent).toBe(feedUrl)
	})

	test('should regenerate URL when confirming regeneration', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()

		await page.waitForLoadState('networkidle')

		// Open modal
		await page.locator('[data-test="button-calendar-feed"]').click()
		await expect(page.getByRole('dialog', { name: /calendar subscription/i })).toBeVisible()

		// Get the initial feed URL
		const feedUrlElement = page.locator('[data-test="input-ical-feed-url"]')
		const initialUrl = await feedUrlElement.textContent()

		// Click regenerate button
		await page.locator('[data-test="button-regenerate-url"]').click()

		// Confirmation dialog should appear
		const confirmDialog = page.getByRole('dialog', { name: /regenerate feed url/i })
		await expect(confirmDialog).toBeVisible()

		// Confirm regeneration (click the button inside the confirmation dialog)
		await confirmDialog.getByRole('button', { name: /regenerate/i }).click()

		// Wait for the URL to update
		await page.waitForTimeout(1000)

		// Get the new URL
		const newUrl = await feedUrlElement.textContent()

		// URL should be different
		expect(newUrl).not.toBe(initialUrl)
		expect(newUrl).toMatch(/\/apps\/attendance\/ical\/[a-f0-9]{64}\.ics/)
	})

	test('should cancel regeneration when clicking cancel', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()

		await page.waitForLoadState('networkidle')

		// Open modal
		await page.locator('[data-test="button-calendar-feed"]').click()
		await expect(page.getByRole('dialog', { name: /calendar subscription/i })).toBeVisible()

		// Get the initial feed URL
		const feedUrlElement = page.locator('[data-test="input-ical-feed-url"]')
		const initialUrl = await feedUrlElement.textContent()

		// Click regenerate button
		await page.locator('[data-test="button-regenerate-url"]').click()

		// Confirmation dialog should appear
		await expect(page.getByRole('dialog', { name: /regenerate feed url/i })).toBeVisible()

		// Cancel regeneration
		await page.getByRole('button', { name: /cancel/i }).click()

		// URL should remain the same
		const currentUrl = await feedUrlElement.textContent()
		expect(currentUrl).toBe(initialUrl)
	})

	test('should fetch valid iCal content from feed URL', async ({ page, loginAsUser, attendanceApp, baseURL }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()

		await page.waitForLoadState('networkidle')

		// Open modal
		await page.locator('[data-test="button-calendar-feed"]').click()
		await expect(page.getByRole('dialog', { name: /calendar subscription/i })).toBeVisible()

		// Wait for the feed URL to load
		const feedUrlElement = page.locator('[data-test="input-ical-feed-url"]')
		await expect(feedUrlElement).not.toBeEmpty({ timeout: 5000 })

		const feedUrl = await feedUrlElement.textContent()

		// Ensure we have a valid URL
		expect(feedUrl).toMatch(/\/apps\/attendance\/ical\/[a-f0-9]{64}\.ics/)

		// Fetch the iCal feed directly
		const response = await page.request.get(feedUrl)

		// Should return 200 OK
		expect(response.status()).toBe(200)

		// Content-Type should be text/calendar
		const contentType = response.headers()['content-type']
		expect(contentType).toContain('text/calendar')

		// Body should be valid iCal format
		const body = await response.text()
		expect(body).toContain('BEGIN:VCALENDAR')
		expect(body).toContain('VERSION:2.0')
		expect(body).toContain('END:VCALENDAR')
	})

	test('should return 401 for invalid token', async ({ page, baseURL }) => {
		// Try to fetch with an invalid token
		const invalidUrl = `${baseURL}/apps/attendance/ical/0000000000000000000000000000000000000000000000000000000000000000.ics`

		const response = await page.request.get(invalidUrl)

		// Should return 401 Unauthorized
		expect(response.status()).toBe(401)
	})

	test('regular user should also see Calendar Feed button', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('test', 'test')
		await attendanceApp()

		await page.waitForLoadState('networkidle')

		// Calendar Feed button should be visible for regular users too
		await expect(page.locator('[data-test="button-calendar-feed"]')).toBeVisible()
	})

	test('regular user should be able to get their own feed URL', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('test', 'test')
		await attendanceApp()

		await page.waitForLoadState('networkidle')

		// Open modal
		await page.locator('[data-test="button-calendar-feed"]').click()
		await expect(page.getByRole('dialog', { name: /calendar subscription/i })).toBeVisible()

		// Feed URL should be displayed
		const feedUrlElement = page.locator('[data-test="input-ical-feed-url"]')
		const feedUrl = await feedUrlElement.textContent()

		expect(feedUrl).toMatch(/\/apps\/attendance\/ical\/[a-f0-9]{64}\.ics/)
	})
})
