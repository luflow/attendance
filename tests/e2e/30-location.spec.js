import { createAppointmentViaAPI, deleteAllAppointments, expect, test } from './fixtures/nextcloud.js'

// Mirrors the storage key in src/views/AllAppointments.vue.
const LOCATION_FILTER_STORAGE_KEY = 'attendance:list-filters:locations'

test.describe('Attendance App - Location field', () => {
	test.afterAll(async ({ request }) => {
		await deleteAllAppointments(request)
	})

	test.beforeEach(async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.waitForLoadState('networkidle')
	})

	test('should create an appointment with a location picked from autocomplete suggestions', async ({ page, request }) => {
		// Seeds the suggestion the create form will offer.
		await createAppointmentViaAPI(request, {
			name: 'Location Suggestion Seed',
			daysFromNow: 20,
			location: 'Choir Room A',
		})
		await page.reload()
		await page.waitForLoadState('networkidle')

		const createLink = page.getByRole('button', { name: 'Create Appointment' })
		await createLink.waitFor({ state: 'visible' })
		await createLink.click()

		await page.waitForURL(/.*\/create$/)
		await page.waitForLoadState('networkidle')
		await expect(page.getByRole('heading', { name: 'Create Appointment' })).toBeVisible()

		const nameInput = page.getByRole('textbox', { name: 'Appointment Name' })
		await nameInput.waitFor({ state: 'visible' })
		await nameInput.fill('Rehearsal With Autocomplete Location')

		const now = new Date()
		const startDate = new Date(now.getTime() + 3 * 24 * 60 * 60 * 1000)
		const endDate = new Date(startDate.getTime() + 60 * 60 * 1000)
		await page.getByRole('textbox', { name: 'Start Date & Time' }).fill(startDate.toISOString().slice(0, 16))
		await page.getByRole('textbox', { name: 'End Date & Time' }).fill(endDate.toISOString().slice(0, 16))

		// Type a partial match — the pre-loaded suggestion list is filtered
		// locally by vue-select, same as the visibility picker's live search.
		const locationField = page.locator('[data-test="input-appointment-location"]')
		const locationSearch = locationField.getByRole('combobox')
		await locationSearch.click()
		await locationSearch.fill('Choir')

		const suggestionOption = page.getByRole('option', { name: 'Choir Room A', exact: true })
		await suggestionOption.waitFor({ state: 'visible' })
		await suggestionOption.click()

		await expect(locationField.locator('.vs__selected').filter({ hasText: 'Choir Room A' })).toBeVisible()

		await page.getByRole('button', { name: 'Save' }).click()
		await page.waitForURL(/.*\/apps\/attendance(?!\/(create|edit|copy))/)
		await page.waitForLoadState('networkidle')

		const card = page.locator('[data-test="appointment-card"]').filter({ hasText: 'Rehearsal With Autocomplete Location' }).first()
		await expect(card).toBeVisible()
		await expect(card.locator('[data-test="appointment-location"]')).toContainText('Choir Room A')
	})

	test('should edit an appointment\'s location', async ({ page, request }) => {
		const appointment = await createAppointmentViaAPI(request, {
			name: 'Location Edit Target',
			daysFromNow: 5,
			location: 'Old Room',
		})
		expect(appointment.id).toBeTruthy()
		await page.reload()
		await page.waitForLoadState('networkidle')

		const card = page.locator('[data-test="appointment-card"]').filter({ hasText: 'Location Edit Target' }).first()
		await expect(card.locator('[data-test="appointment-location"]')).toContainText('Old Room')

		await page.getByText('Location Edit Target').first().click()
		await page.waitForLoadState('networkidle')

		await page.getByRole('button', { name: 'Actions' }).first().click()
		await page.getByRole('menuitem', { name: 'Edit' }).click()
		await page.waitForURL(/.*\/edit\/\d+$/)
		await expect(page.getByRole('heading', { name: 'Edit Appointment' })).toBeVisible()

		const locationField = page.locator('[data-test="input-appointment-location"]')
		await expect(locationField.locator('.vs__selected').filter({ hasText: 'Old Room' })).toBeVisible()

		// Selecting a new value replaces the single-select current one — no
		// need to clear it first. "New Room" isn't a known suggestion, so
		// vue-select's taggable mode offers the typed text itself as an option.
		const locationSearch = locationField.getByRole('combobox')
		await locationSearch.click()
		await locationSearch.fill('New Room')
		const newOption = page.getByRole('option', { name: 'New Room', exact: true })
		await newOption.waitFor({ state: 'visible' })
		await newOption.click()

		await expect(locationField.locator('.vs__selected').filter({ hasText: 'New Room' })).toBeVisible()

		await page.getByRole('button', { name: 'Save' }).click()
		await page.waitForURL(/.*\/apps\/attendance(?!\/appointment)/)
		await page.waitForLoadState('networkidle')

		const updatedCard = page.locator('[data-test="appointment-card"]').filter({ hasText: 'Location Edit Target' }).first()
		await expect(updatedCard.locator('[data-test="appointment-location"]')).toContainText('New Room')
		await expect(updatedCard.locator('[data-test="appointment-location"]')).not.toContainText('Old Room')
	})

	test('location filter narrows the visible appointments and persists', async ({ page, request }) => {
		await createAppointmentViaAPI(request, {
			name: 'Filter Room A Meeting',
			daysFromNow: 6,
			location: 'Room A',
		})
		await createAppointmentViaAPI(request, {
			name: 'Filter Room B Meeting',
			daysFromNow: 6,
			location: 'Room B',
		})
		await page.reload()
		await page.waitForLoadState('networkidle')

		const cards = page.locator('[data-test="appointment-card"]')
		await expect(cards.filter({ hasText: 'Filter Room A Meeting' }).first()).toBeVisible()
		await expect(cards.filter({ hasText: 'Filter Room B Meeting' }).first()).toBeVisible()

		await page.locator('[data-test="filter-location"]').click()
		await page.getByRole('menuitemcheckbox', { name: 'Room A', exact: true }).click()

		await expect(cards.filter({ hasText: 'Filter Room A Meeting' }).first()).toBeVisible()
		await expect(cards.filter({ hasText: 'Filter Room B Meeting' })).toHaveCount(0)

		// Filters persist (debounced 300ms) — confirm the restore after reload,
		// same as the status/audience filter coverage in 14-close-inquiry.spec.js.
		await page.waitForFunction(
			([key]) => {
				try {
					const stored = JSON.parse(window.localStorage.getItem(key) || '[]')
					return Array.isArray(stored) && stored.includes('Room A')
				} catch {
					return false
				}
			},
			[LOCATION_FILTER_STORAGE_KEY],
		)
		await page.reload()
		await page.waitForLoadState('networkidle')
		await expect(cards.filter({ hasText: 'Filter Room A Meeting' }).first()).toBeVisible()
		await expect(cards.filter({ hasText: 'Filter Room B Meeting' })).toHaveCount(0)
	})
})
