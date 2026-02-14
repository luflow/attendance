import { test, expect } from './fixtures/nextcloud.js'

/**
 * Navigate to the create appointment form and fill in basic fields + start/end dates.
 * Returns the start date for further use.
 */
async function navigateToCreateForm(page, { name = 'Recurring Test', daysFromNow = 5, durationHours = 1 } = {}) {
	const createLink = page.getByRole('link', { name: 'Create Appointment' })
	await createLink.waitFor({ state: 'visible' })
	await createLink.click()

	await page.waitForURL(/.*\/create$/)
	await page.waitForLoadState('networkidle')
	await expect(page.getByRole('heading', { name: 'Create Appointment' })).toBeVisible()

	// Fill name
	const nameInput = page.getByRole('textbox', { name: 'Appointment Name' })
	await nameInput.waitFor({ state: 'visible' })
	await nameInput.fill(name)

	// Fill dates
	const now = new Date()
	const startDate = new Date(now.getTime() + daysFromNow * 24 * 60 * 60 * 1000)
	const endDate = new Date(startDate.getTime() + durationHours * 60 * 60 * 1000)

	await page.getByRole('textbox', { name: 'Start Date & Time' }).fill(startDate.toISOString().slice(0, 16))
	await page.getByRole('textbox', { name: 'End Date & Time' }).fill(endDate.toISOString().slice(0, 16))

	return startDate
}

/**
 * Enable the recurrence toggle. Requires start date to be set first.
 * Clicks the label text because the checkbox-content span intercepts pointer events.
 */
async function enableRecurrence(page) {
	await page.getByText('Repeat appointment').click()
}

test.describe('Attendance App - Recurrence', () => {
	test.beforeEach(async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.waitForLoadState('networkidle')
	})

	test('recurrence toggle should be disabled without start date', async ({ page }) => {
		const createLink = page.getByRole('link', { name: 'Create Appointment' })
		await createLink.waitFor({ state: 'visible' })
		await createLink.click()

		await page.waitForURL(/.*\/create$/)
		await page.waitForLoadState('networkidle')

		// The switch should exist but be disabled (no start date set)
		const toggle = page.getByRole('checkbox', { name: 'Repeat appointment' })
		await expect(toggle).toBeVisible()
		await expect(toggle).toBeDisabled()

		// Hint text should be shown
		await expect(page.getByText('Set a start date first to enable recurrence.')).toBeVisible()
	})

	test('recurrence toggle should be enabled after setting start date', async ({ page }) => {
		await navigateToCreateForm(page)

		// Hint text should be gone
		await expect(page.getByText('Set a start date first to enable recurrence.')).not.toBeVisible()

		// Enable recurrence
		await enableRecurrence(page)

		// Recurrence config should appear
		await expect(page.locator('[data-test="select-frequency"]')).toBeVisible()
		await expect(page.locator('[data-test="input-interval"]')).toBeVisible()
	})

	test('weekly recurrence should show weekday checkboxes', async ({ page }) => {
		await navigateToCreateForm(page)
		await enableRecurrence(page)

		// Default frequency is Weekly, so weekday checkboxes should be visible
		await expect(page.locator('[data-test="weekday-checkboxes"]')).toBeVisible()

		// Should show preview with occurrences
		await expect(page.locator('[data-test="recurrence-preview"]')).toBeVisible()
	})

	test('daily recurrence should hide weekday checkboxes', async ({ page }) => {
		await navigateToCreateForm(page)
		await enableRecurrence(page)

		// Switch to Daily
		const frequencySelect = page.locator('[data-test="select-frequency"]')
		await frequencySelect.click()
		await page.getByText('Daily', { exact: true }).click()

		// Weekday checkboxes should not be visible
		await expect(page.locator('[data-test="weekday-checkboxes"]')).not.toBeVisible()

		// Preview should be visible
		await expect(page.locator('[data-test="recurrence-preview"]')).toBeVisible()
	})

	test('monthly recurrence should show monthly type options', async ({ page }) => {
		await navigateToCreateForm(page)
		await enableRecurrence(page)

		// Switch to Monthly
		const frequencySelect = page.locator('[data-test="select-frequency"]')
		await frequencySelect.click()
		await page.getByText('Monthly', { exact: true }).click()

		// Monthly type radio buttons should be visible
		await expect(page.locator('[data-test="monthly-type"]')).toBeVisible()
		await expect(page.locator('[data-test="radio-day-of-month"]')).toBeVisible()
		await expect(page.locator('[data-test="radio-weekday-position"]')).toBeVisible()

		// Preview should be visible
		await expect(page.locator('[data-test="recurrence-preview"]')).toBeVisible()
	})

	test('should show warning when no weekday is selected', async ({ page }) => {
		await navigateToCreateForm(page)
		await enableRecurrence(page)

		// Default is weekly with the start date's weekday auto-selected.
		// Deselect by clicking checked weekday labels (span intercepts input clicks)
		const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']
		for (const day of days) {
			const checkbox = page.locator('[data-test="weekday-checkboxes"]').getByRole('checkbox', { name: day })
			if (await checkbox.isChecked()) {
				await page.locator('[data-test="weekday-checkboxes"]').getByText(day).click()
			}
		}

		// Warning should appear
		await expect(page.locator('[data-test="recurrence-warning"]')).toBeVisible()
		await expect(page.getByText('Select at least one day of the week.')).toBeVisible()

		// Preview should not be visible
		await expect(page.locator('[data-test="recurrence-preview"]')).not.toBeVisible()
	})

	test('should show warning when "on date" is selected without end date', async ({ page }) => {
		await navigateToCreateForm(page)
		await enableRecurrence(page)

		// Switch end type to "On date"
		await page.getByText('On date').click()

		// Warning should appear
		await expect(page.locator('[data-test="recurrence-warning"]')).toBeVisible()
		await expect(page.getByText('Please select an end date for recurrence.')).toBeVisible()

		// Preview should not be visible
		await expect(page.locator('[data-test="recurrence-preview"]')).not.toBeVisible()
	})

	test('should show "Show all" button when many occurrences', async ({ page }) => {
		await navigateToCreateForm(page)
		await enableRecurrence(page)

		// Default is 10 weekly occurrences which is > 5 preview limit
		await expect(page.locator('[data-test="recurrence-preview"]')).toBeVisible()
		await expect(page.locator('[data-test="button-show-all"]')).toBeVisible()

		// Click "Show all"
		await page.locator('[data-test="button-show-all"]').click()

		// Button should disappear after clicking
		await expect(page.locator('[data-test="button-show-all"]')).not.toBeVisible()
	})

	test('save button should show appointment count for recurring', async ({ page }) => {
		await navigateToCreateForm(page, { name: 'Recurring Count Test' })
		await enableRecurrence(page)

		// Save button should show count (default 10 weekly occurrences)
		const saveButton = page.getByRole('button', { name: /Create \d+ appointments/ })
		await expect(saveButton).toBeVisible()
	})

	test('should block save when recurrence has validation warning', async ({ page }) => {
		await navigateToCreateForm(page, { name: 'Recurrence Validation Test' })
		await enableRecurrence(page)

		// Switch to "On date" without setting a date
		await page.getByText('On date').click()

		// Warning should be visible
		await expect(page.locator('[data-test="recurrence-warning"]')).toBeVisible()

		// Try to save - should show error
		await page.getByRole('button', { name: 'Save' }).click()

		// Should still be on the create form (not navigated away)
		await expect(page).toHaveURL(/.*\/create$/)
	})

	test('should create recurring weekly appointments', async ({ page }) => {
		await navigateToCreateForm(page, { name: 'Weekly Standup Recurring' })
		await enableRecurrence(page)

		// Set count to 3 for faster test
		const countInput = page.locator('[data-test="input-count"]')
		await countInput.fill('3')

		// Verify preview shows 3 appointments
		await expect(page.getByText('3 appointments will be created')).toBeVisible()

		// Save
		await page.getByRole('button', { name: 'Create 3 appointments' }).click()

		// Wait for navigation back to appointment list
		await page.waitForURL(/.*\/apps\/attendance(?!\/(create|edit|copy))/)
		await page.waitForLoadState('networkidle')

		// Verify exactly 3 appointment cards were created with this name
		const weeklyCards = page.locator('[data-test="appointment-card"]', { hasText: 'Weekly Standup Recurring' })
		await expect(weeklyCards).toHaveCount(3)
	})

	test('should create recurring daily appointments', async ({ page }) => {
		await navigateToCreateForm(page, { name: 'Daily Checkin Recurring' })
		await enableRecurrence(page)

		// Switch to Daily
		const frequencySelect = page.locator('[data-test="select-frequency"]')
		await frequencySelect.click()
		await page.getByText('Daily', { exact: true }).click()

		// Set count to 3
		const countInput = page.locator('[data-test="input-count"]')
		await countInput.fill('3')

		// Verify preview
		await expect(page.getByText('3 appointments will be created')).toBeVisible()

		// Save
		await page.getByRole('button', { name: 'Create 3 appointments' }).click()

		// Wait for navigation back
		await page.waitForURL(/.*\/apps\/attendance(?!\/(create|edit|copy))/)
		await page.waitForLoadState('networkidle')

		// Verify exactly 3 appointment cards were created with this name
		const dailyCards = page.locator('[data-test="appointment-card"]', { hasText: 'Daily Checkin Recurring' })
		await expect(dailyCards).toHaveCount(3)
	})

	test('should create appointments every third Wednesday for 6 occurrences', async ({ page }) => {
		// Find the next Wednesday from now
		const now = new Date()
		const nextWednesday = new Date(now)
		nextWednesday.setDate(now.getDate() + ((3 - now.getDay() + 7) % 7 || 7))
		nextWednesday.setHours(10, 0, 0, 0)
		const endTime = new Date(nextWednesday.getTime() + 2 * 60 * 60 * 1000)

		// Navigate to create form manually (need a Wednesday start date)
		const createLink = page.getByRole('link', { name: 'Create Appointment' })
		await createLink.waitFor({ state: 'visible' })
		await createLink.click()
		await page.waitForURL(/.*\/create$/)
		await page.waitForLoadState('networkidle')

		// Fill name
		const nameInput = page.getByRole('textbox', { name: 'Appointment Name' })
		await nameInput.waitFor({ state: 'visible' })
		await nameInput.fill('Triweekly Wednesday Sync')

		// Set start to next Wednesday
		await page.getByRole('textbox', { name: 'Start Date & Time' }).fill(nextWednesday.toISOString().slice(0, 16))
		await page.getByRole('textbox', { name: 'End Date & Time' }).fill(endTime.toISOString().slice(0, 16))

		// Enable recurrence
		await enableRecurrence(page)

		// Default is Weekly — weekday checkboxes should appear with Wed auto-selected
		await expect(page.locator('[data-test="weekday-checkboxes"]')).toBeVisible()

		// Set interval to 3 (every 3rd week)
		const intervalInput = page.locator('[data-test="input-interval"]')
		await intervalInput.fill('3')

		// Set count to 6
		const countInput = page.locator('[data-test="input-count"]')
		await countInput.fill('6')

		// Verify preview shows 6 appointments
		await expect(page.getByText('6 appointments will be created')).toBeVisible()

		// Save
		await page.getByRole('button', { name: 'Create 6 appointments' }).click()

		// Wait for navigation back to appointment list
		await page.waitForURL(/.*\/apps\/attendance(?!\/(create|edit|copy))/)
		await page.waitForLoadState('networkidle')

		// Verify exactly 6 appointment cards were created with this name
		const triweeklyCards = page.locator('[data-test="appointment-card"]', { hasText: 'Triweekly Wednesday Sync' })
		await expect(triweeklyCards).toHaveCount(6)
	})

	test('should not show recurrence on edit form', async ({ page }) => {
		// Create an appointment first so we have something to edit
		await navigateToCreateForm(page, { name: 'Edit Test Appointment' })
		await page.getByRole('button', { name: 'Save' }).click()
		await page.waitForURL(/.*\/apps\/attendance(?!\/(create|edit|copy))/)
		await page.waitForLoadState('networkidle')

		// Open the appointment for editing
		const card = page.locator('[data-test="appointment-card"]', { hasText: 'Edit Test Appointment' })
		await expect(card).toBeVisible()
		await card.getByRole('button', { name: 'Actions' }).click()
		await page.getByRole('menuitem', { name: 'Edit' }).click()

		await page.waitForURL(/.*\/edit\/\d+$/)
		await page.waitForLoadState('networkidle')

		// Recurrence toggle should not be visible (only on create)
		await expect(page.getByText('Repeat appointment')).not.toBeVisible()
	})
})

test.describe('Attendance App - Date Validation', () => {
	test.beforeEach(async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.waitForLoadState('networkidle')
	})

	test('should block save when end date is before start date', async ({ page }) => {
		const createLink = page.getByRole('link', { name: 'Create Appointment' })
		await createLink.waitFor({ state: 'visible' })
		await createLink.click()

		await page.waitForURL(/.*\/create$/)
		await page.waitForLoadState('networkidle')

		// Fill name
		const nameInput = page.getByRole('textbox', { name: 'Appointment Name' })
		await nameInput.fill('Date Validation Test')

		// Set end date before start date
		const now = new Date()
		const startDate = new Date(now.getTime() + 5 * 24 * 60 * 60 * 1000)
		const endDate = new Date(startDate.getTime() - 2 * 60 * 60 * 1000) // 2 hours BEFORE start

		await page.getByRole('textbox', { name: 'Start Date & Time' }).fill(startDate.toISOString().slice(0, 16))
		await page.getByRole('textbox', { name: 'End Date & Time' }).fill(endDate.toISOString().slice(0, 16))

		// Try to save
		await page.getByRole('button', { name: 'Save' }).click()

		// Should still be on the create form
		await expect(page).toHaveURL(/.*\/create$/)
	})
})
