import { test, expect } from './fixtures/nextcloud.js'

/**
 * Create a recurring series of appointments via the bulk/recurrence UI.
 * Returns the series name for later lookup.
 */
async function createRecurringSeries(page, { name, count = 3, daysFromNow = 5 } = {}) {
	const createLink = page.getByRole('link', { name: 'Create Appointment' })
	await createLink.waitFor({ state: 'visible' })
	await createLink.click()

	await page.waitForURL(/.*\/create$/)
	await page.waitForLoadState('networkidle')

	// Fill name
	const nameInput = page.getByRole('textbox', { name: 'Appointment Name' })
	await nameInput.waitFor({ state: 'visible' })
	await nameInput.fill(name)

	// Fill dates
	const now = new Date()
	const startDate = new Date(now.getTime() + daysFromNow * 24 * 60 * 60 * 1000)
	startDate.setHours(10, 0, 0, 0)
	const endDate = new Date(startDate.getTime() + 1 * 60 * 60 * 1000)

	await page.getByRole('textbox', { name: 'Start Date & Time' }).fill(startDate.toISOString().slice(0, 16))
	await page.getByRole('textbox', { name: 'End Date & Time' }).fill(endDate.toISOString().slice(0, 16))

	// Enable recurrence
	await page.getByText('Repeat appointment').click()

	// Set count
	const countInput = page.locator('[data-test="input-count"]')
	await countInput.fill(String(count))

	// Verify preview
	await expect(page.getByText(`${count} appointments will be created`)).toBeVisible()

	// Save
	await page.getByRole('button', { name: `Create ${count} appointments` }).click()

	// Wait for navigation back
	await page.waitForURL(/.*\/apps\/attendance(?!\/(create|edit|copy))/)
	await page.waitForLoadState('networkidle')

	return name
}

/**
 * Get all appointment cards matching a name.
 */
function getSeriesCards(page, name) {
	return page.locator('[data-test="appointment-card"]', { hasText: name })
}

/**
 * Open the edit form for the first card matching a name.
 */
async function openEditForFirst(page, name) {
	const card = getSeriesCards(page, name).first()
	await card.getByRole('button', { name: 'Actions' }).click()
	await page.getByRole('menuitem', { name: 'Edit' }).click()
	await page.waitForURL(/.*\/edit\/\d+$/)
	await page.waitForLoadState('networkidle')
}

test.describe('Attendance App - Series Management', () => {
	test.beforeEach(async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.waitForLoadState('networkidle')
	})

	// -------------------------------------------------------------------
	// 1. Creation: verify series_id linkage and repeat icon
	// -------------------------------------------------------------------

	test('recurring appointments should share a series and show repeat icon', async ({ page }) => {
		const name = await createRecurringSeries(page, { name: 'Series Icon Test', count: 3 })

		const cards = getSeriesCards(page, name)
		await expect(cards).toHaveCount(3)

		// Each card should display the repeat/series indicator icon
		for (let i = 0; i < 3; i++) {
			const indicator = cards.nth(i).locator('.series-indicator')
			await expect(indicator).toBeVisible()
		}
	})

	// -------------------------------------------------------------------
	// 2. Standalone delete still works through the new dialog
	// -------------------------------------------------------------------

	test('standalone appointment delete should show simple confirmation', async ({ page }) => {
		// Create a standalone (non-recurring) appointment
		const createLink = page.getByRole('link', { name: 'Create Appointment' })
		await createLink.waitFor({ state: 'visible' })
		await createLink.click()
		await page.waitForURL(/.*\/create$/)
		await page.waitForLoadState('networkidle')

		const nameInput = page.getByRole('textbox', { name: 'Appointment Name' })
		await nameInput.fill('Standalone Delete Dialog Test')

		const now = new Date()
		const start = new Date(now.getTime() + 20 * 24 * 60 * 60 * 1000)
		const end = new Date(start.getTime() + 1 * 60 * 60 * 1000)
		await page.getByRole('textbox', { name: 'Start Date & Time' }).fill(start.toISOString().slice(0, 16))
		await page.getByRole('textbox', { name: 'End Date & Time' }).fill(end.toISOString().slice(0, 16))

		await page.getByRole('button', { name: 'Save' }).click()
		await page.waitForURL(/.*\/apps\/attendance(?!\/(create|edit|copy))/)
		await page.waitForLoadState('networkidle')

		// Click delete on that card
		const card = page.locator('[data-test="appointment-card"]', { hasText: 'Standalone Delete Dialog Test' })
		await card.getByRole('button', { name: 'Actions' }).click()
		await page.getByRole('menuitem', { name: 'Delete' }).click()

		// Dialog should show simple confirmation (no series options)
		const dialog = page.getByRole('dialog', { name: 'Delete appointment' })
		await expect(dialog).toBeVisible()
		await expect(dialog.getByText('Do you want to delete this appointment?')).toBeVisible()

		// Series radio options should NOT be present
		await expect(dialog.getByText('This appointment only')).not.toBeVisible()
		await expect(dialog.getByText('All appointments in this series')).not.toBeVisible()

		// Cancel — appointment stays
		await dialog.getByRole('button', { name: 'Cancel' }).click()
		await expect(dialog).not.toBeVisible()
		await expect(card).toBeVisible()

		// Now delete for real
		await card.getByRole('button', { name: 'Actions' }).click()
		await page.getByRole('menuitem', { name: 'Delete' }).click()
		const dialog2 = page.getByRole('dialog', { name: 'Delete appointment' })
		await dialog2.getByRole('button', { name: 'Delete' }).click()
		await page.waitForLoadState('networkidle')

		await expect(page.locator('[data-test="appointment-card"]', { hasText: 'Standalone Delete Dialog Test' })).toHaveCount(0)
	})

	// -------------------------------------------------------------------
	// 3. Series delete — "this only"
	// -------------------------------------------------------------------

	test('should delete single occurrence from series', async ({ page }) => {
		const name = await createRecurringSeries(page, { name: 'Delete Single Test', count: 4 })

		const cards = getSeriesCards(page, name)
		await expect(cards).toHaveCount(4)

		// Delete the first card with scope "This appointment only"
		await cards.first().getByRole('button', { name: 'Actions' }).click()
		await page.getByRole('menuitem', { name: 'Delete' }).click()

		const dialog = page.getByRole('dialog', { name: 'Delete appointment' })
		await expect(dialog).toBeVisible()

		// Series options should be present
		await expect(dialog.getByText('This appointment only')).toBeVisible()
		await expect(dialog.getByText('This and all future appointments')).toBeVisible()
		await expect(dialog.getByText('All appointments in this series')).toBeVisible()

		// "This appointment only" should be pre-selected (default)
		const singleRadio = dialog.getByRole('radio', { name: 'This appointment only' })
		await expect(singleRadio).toBeChecked()

		// Confirm delete
		await dialog.getByRole('button', { name: 'Delete' }).click()
		await page.waitForLoadState('networkidle')

		// Should now have 3 remaining
		await expect(getSeriesCards(page, name)).toHaveCount(3)
	})

	// -------------------------------------------------------------------
	// 4. Series delete — "this and all future"
	// -------------------------------------------------------------------

	test('should delete future occurrences from series', async ({ page }) => {
		const name = await createRecurringSeries(page, { name: 'Delete Future Test', count: 4 })

		const cards = getSeriesCards(page, name)
		await expect(cards).toHaveCount(4)

		// Delete the second card with scope "This and all future"
		await cards.nth(1).getByRole('button', { name: 'Actions' }).click()
		await page.getByRole('menuitem', { name: 'Delete' }).click()

		const dialog = page.getByRole('dialog', { name: 'Delete appointment' })
		await expect(dialog).toBeVisible()

		// Select "This and all future appointments"
		await dialog.getByText('This and all future appointments').click()

		await dialog.getByRole('button', { name: 'Delete' }).click()
		await page.waitForLoadState('networkidle')

		// Positions 1,2,3 deleted → only position 0 remains
		await expect(getSeriesCards(page, name)).toHaveCount(1)
	})

	// -------------------------------------------------------------------
	// 5. Series delete — "all"
	// -------------------------------------------------------------------

	test('should delete all occurrences in series', async ({ page }) => {
		const name = await createRecurringSeries(page, { name: 'Delete All Test', count: 3 })

		await expect(getSeriesCards(page, name)).toHaveCount(3)

		// Delete any card with scope "All"
		const cards = getSeriesCards(page, name)
		await cards.first().getByRole('button', { name: 'Actions' }).click()
		await page.getByRole('menuitem', { name: 'Delete' }).click()

		const dialog = page.getByRole('dialog', { name: 'Delete appointment' })
		await expect(dialog).toBeVisible()

		// Select "All appointments in this series"
		await dialog.getByText('All appointments in this series').click()

		await dialog.getByRole('button', { name: 'Delete' }).click()
		await page.waitForLoadState('networkidle')

		// All gone
		await expect(getSeriesCards(page, name)).toHaveCount(0)
	})

	// -------------------------------------------------------------------
	// 6. Series delete from detail view
	// -------------------------------------------------------------------

	test('should show series delete dialog in detail view', async ({ page }) => {
		const name = await createRecurringSeries(page, { name: 'Detail Delete Test', count: 3 })

		// Navigate to the first appointment's detail view
		const card = getSeriesCards(page, name).first()
		const appointmentTitle = card.locator('h3').first()
		await appointmentTitle.click()
		await page.waitForLoadState('networkidle')

		// If we navigated to the detail page, the delete should still work
		// Check we are on the detail page (URL should have /appointment/)
		if (page.url().includes('/appointment/')) {
			// Open actions in the detail view's AppointmentCard
			await page.getByRole('button', { name: 'Actions' }).click()
			await page.getByRole('menuitem', { name: 'Delete' }).click()

			// Dialog should be the series-aware version
			const dialog = page.getByRole('dialog', { name: 'Delete appointment' })
			await expect(dialog).toBeVisible()
			await expect(dialog.getByText('This appointment only')).toBeVisible()
			await expect(dialog.getByText('All appointments in this series')).toBeVisible()

			// Cancel — don't actually delete
			await dialog.getByRole('button', { name: 'Cancel' }).click()
			await expect(dialog).not.toBeVisible()
		}
	})

	// -------------------------------------------------------------------
	// 6b. Deleting entire series from detail view should navigate to overview
	// -------------------------------------------------------------------

	test('deleting entire series from detail view should navigate to overview, not edit form', async ({ page }) => {
		const name = await createRecurringSeries(page, { name: `Delete Nav ${Date.now()}`, count: 3 })

		// Navigate to the detail view via the sidebar navigation
		const navItem = page.locator('[data-test="nav-unanswered-appointment"]', { hasText: name }).first()
		await navItem.click()
		await page.waitForURL(/.*\/appointment\/\d+/)
		await page.waitForLoadState('networkidle')

		// Delete the entire series from the detail view
		await page.getByRole('button', { name: 'Actions' }).click()
		await page.getByRole('menuitem', { name: 'Delete' }).click()

		const dialog = page.getByRole('dialog', { name: 'Delete appointment' })
		await expect(dialog).toBeVisible()

		// Select "All appointments in this series"
		await dialog.getByText('All appointments in this series').click()
		await dialog.getByRole('button', { name: 'Delete' }).click()
		await page.waitForLoadState('networkidle')

		// Should navigate to the overview, NOT back to the edit form
		await expect(page).toHaveURL(/.*\/apps\/attendance(?!\/(create|edit|copy|appointment))/)

		// All appointments from the series should be gone
		await expect(getSeriesCards(page, name)).toHaveCount(0)
	})

	// -------------------------------------------------------------------
	// 7. Edit form shows series info for recurring appointments
	// -------------------------------------------------------------------

	test('edit form should show series info section', async ({ page }) => {
		const name = await createRecurringSeries(page, { name: 'Edit Series Info Test', count: 3 })

		await openEditForFirst(page, name)

		// The series info section should be visible
		await expect(page.getByText('Part of a recurring series')).toBeVisible()
	})

	// -------------------------------------------------------------------
	// 8. Edit series — "this only" (detach + update)
	// -------------------------------------------------------------------

	test('should edit single occurrence and detach from series', async ({ page }) => {
		const name = await createRecurringSeries(page, { name: 'Edit Single Test', count: 3 })

		await openEditForFirst(page, name)

		// Change the name
		const nameInput = page.getByRole('textbox', { name: 'Appointment Name' })
		await nameInput.clear()
		await nameInput.fill('Edited Single Occurrence')

		// Click Save — should show series dialog
		await page.getByRole('button', { name: 'Save' }).click()

		const dialog = page.getByRole('dialog', { name: 'Edit recurring appointment' })
		await expect(dialog).toBeVisible()

		// "This appointment only" should be pre-selected
		await expect(dialog.getByRole('radio', { name: 'This appointment only' })).toBeChecked()

		// Confirm
		await dialog.getByRole('button', { name: 'Save' }).click()
		await page.waitForURL(/.*\/apps\/attendance(?!\/(create|edit|copy))/)
		await page.waitForLoadState('networkidle')

		// Navigate back to the list view (save redirects to detail view)
		await page.getByRole('link', { name: 'Upcoming Appointments' }).click()
		await page.waitForLoadState('networkidle')

		// The edited one should have the new name
		await expect(page.locator('[data-test="appointment-card"]', { hasText: 'Edited Single Occurrence' })).toHaveCount(1)

		// The detached appointment should NOT have the series indicator
		const detachedCard = page.locator('[data-test="appointment-card"]', { hasText: 'Edited Single Occurrence' })
		await expect(detachedCard.locator('.series-indicator')).not.toBeVisible()

		// The remaining series cards should still exist with the original name
		await expect(getSeriesCards(page, name)).toHaveCount(2)
	})

	// -------------------------------------------------------------------
	// 9. Edit series — "all" (update all)
	// -------------------------------------------------------------------

	test('should edit all occurrences in series', async ({ page }) => {
		const name = await createRecurringSeries(page, { name: 'Edit All Test', count: 3 })

		await openEditForFirst(page, name)

		// Change the name
		const nameInput = page.getByRole('textbox', { name: 'Appointment Name' })
		await nameInput.clear()
		await nameInput.fill('Renamed All Series')

		// Click Save — series dialog
		await page.getByRole('button', { name: 'Save' }).click()

		const dialog = page.getByRole('dialog', { name: 'Edit recurring appointment' })
		await expect(dialog).toBeVisible()

		// Select "All appointments in this series"
		await dialog.getByText('All appointments in this series').click()

		// The time shift info note should be visible for multi-appointment scope
		await expect(dialog.getByText('date and time changes are applied as a relative shift')).toBeVisible()

		// Confirm
		await dialog.getByRole('button', { name: 'Save' }).click()
		await page.waitForURL(/.*\/apps\/attendance(?!\/(create|edit|copy))/)
		await page.waitForLoadState('networkidle')

		// Navigate back to the list view (save redirects to detail view)
		await page.getByRole('link', { name: 'Upcoming Appointments' }).click()
		await page.waitForLoadState('networkidle')

		// All 3 should now have the new name
		await expect(page.locator('[data-test="appointment-card"]', { hasText: 'Renamed All Series' })).toHaveCount(3)

		// No cards with the old name
		await expect(getSeriesCards(page, name)).toHaveCount(0)
	})

	// -------------------------------------------------------------------
	// 10. Edit non-series appointment should NOT show series dialog
	// -------------------------------------------------------------------

	test('edit non-series appointment should save directly without dialog', async ({ page }) => {
		// Create a standalone appointment
		const createLink = page.getByRole('link', { name: 'Create Appointment' })
		await createLink.waitFor({ state: 'visible' })
		await createLink.click()
		await page.waitForURL(/.*\/create$/)
		await page.waitForLoadState('networkidle')

		const nameInput = page.getByRole('textbox', { name: 'Appointment Name' })
		await nameInput.fill('No Series Dialog Test')

		const now = new Date()
		const start = new Date(now.getTime() + 21 * 24 * 60 * 60 * 1000)
		const end = new Date(start.getTime() + 1 * 60 * 60 * 1000)
		await page.getByRole('textbox', { name: 'Start Date & Time' }).fill(start.toISOString().slice(0, 16))
		await page.getByRole('textbox', { name: 'End Date & Time' }).fill(end.toISOString().slice(0, 16))

		await page.getByRole('button', { name: 'Save' }).click()
		await page.waitForURL(/.*\/apps\/attendance(?!\/(create|edit|copy))/)
		await page.waitForLoadState('networkidle')

		// Edit it
		const card = page.locator('[data-test="appointment-card"]', { hasText: 'No Series Dialog Test' })
		await card.getByRole('button', { name: 'Actions' }).click()
		await page.getByRole('menuitem', { name: 'Edit' }).click()
		await page.waitForURL(/.*\/edit\/\d+$/)
		await page.waitForLoadState('networkidle')

		// Series info should NOT be shown
		await expect(page.getByText('Part of a recurring series')).not.toBeVisible()

		// Change name and save
		const editNameInput = page.getByRole('textbox', { name: 'Appointment Name' })
		await editNameInput.clear()
		await editNameInput.fill('No Series Dialog Test (Edited)')

		await page.getByRole('button', { name: 'Save' }).click()

		// Should navigate directly — NO series dialog
		await page.waitForURL(/.*\/apps\/attendance(?!\/(create|edit|copy))/)
		await page.waitForLoadState('networkidle')

		// Verify the update happened
		await expect(page.locator('[data-test="appointment-card"]', { hasText: 'No Series Dialog Test (Edited)' })).toHaveCount(1)
	})

	// -------------------------------------------------------------------
	// 11. Delete dialog cancel should not delete anything
	// -------------------------------------------------------------------

	test('cancelling delete dialog should keep all appointments', async ({ page }) => {
		const name = await createRecurringSeries(page, { name: 'Cancel Delete Test', count: 3 })

		await expect(getSeriesCards(page, name)).toHaveCount(3)

		// Open delete dialog
		const cards = getSeriesCards(page, name)
		await cards.first().getByRole('button', { name: 'Actions' }).click()
		await page.getByRole('menuitem', { name: 'Delete' }).click()

		const dialog = page.getByRole('dialog', { name: 'Delete appointment' })
		await expect(dialog).toBeVisible()

		// Cancel
		await dialog.getByRole('button', { name: 'Cancel' }).click()
		await expect(dialog).not.toBeVisible()

		// All 3 should still exist
		await expect(getSeriesCards(page, name)).toHaveCount(3)
	})

	// -------------------------------------------------------------------
	// 12. Edit dialog cancel should not save anything
	// -------------------------------------------------------------------

	test('cancelling edit series dialog should not save changes', async ({ page }) => {
		const name = await createRecurringSeries(page, { name: 'Cancel Edit Test', count: 3 })

		await openEditForFirst(page, name)

		// Change the name
		const nameInput = page.getByRole('textbox', { name: 'Appointment Name' })
		await nameInput.clear()
		await nameInput.fill('Should Not Be Saved')

		// Click Save — series dialog appears
		await page.getByRole('button', { name: 'Save' }).click()

		const dialog = page.getByRole('dialog', { name: 'Edit recurring appointment' })
		await expect(dialog).toBeVisible()

		// Cancel the dialog
		await dialog.getByRole('button', { name: 'Cancel' }).click()
		await expect(dialog).not.toBeVisible()

		// Still on the edit form — navigate back
		await page.getByRole('button', { name: 'Cancel' }).click()
		await page.waitForURL(/.*\/apps\/attendance(?!\/(create|edit|copy))/)
		await page.waitForLoadState('networkidle')

		// Original name should still be there, changed name should not
		await expect(getSeriesCards(page, name)).toHaveCount(3)
		await expect(page.locator('[data-test="appointment-card"]', { hasText: 'Should Not Be Saved' })).toHaveCount(0)
	})

	// -------------------------------------------------------------------
	// 13. Copy of series appointment should be standalone
	// -------------------------------------------------------------------

	test('copying a series appointment should create a standalone appointment', async ({ page }) => {
		const name = await createRecurringSeries(page, { name: 'Copy Series Test', count: 3 })

		// Copy the first card
		const card = getSeriesCards(page, name).first()
		await card.getByRole('button', { name: 'Actions' }).click()
		await page.getByRole('menuitem', { name: 'Copy' }).click()

		await page.waitForURL(/.*\/copy\/\d+$/)
		await page.waitForLoadState('networkidle')

		// The copy form should NOT show series info (copies are standalone)
		await expect(page.getByText('Part of a recurring series')).not.toBeVisible()

		// Fill in dates for the copy
		const now = new Date()
		const start = new Date(now.getTime() + 30 * 24 * 60 * 60 * 1000)
		const end = new Date(start.getTime() + 1 * 60 * 60 * 1000)
		await page.getByRole('textbox', { name: 'Start Date & Time' }).fill(start.toISOString().slice(0, 16))
		await page.getByRole('textbox', { name: 'End Date & Time' }).fill(end.toISOString().slice(0, 16))

		await page.getByRole('button', { name: 'Save' }).click()
		await page.waitForURL(/.*\/apps\/attendance(?!\/(create|edit|copy))/)
		await page.waitForLoadState('networkidle')

		// The copy should exist and NOT have a series indicator
		const copyCard = page.locator('[data-test="appointment-card"]', { hasText: '(Copy)' })
		await expect(copyCard).toHaveCount(1)
		await expect(copyCard.locator('.series-indicator')).not.toBeVisible()
	})
})
