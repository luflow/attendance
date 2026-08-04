import { createAppointmentViaAPI, createCategoryViaAPI, deleteCategoryViaAPI, expect, forceWipeAllAppointments, listAppointmentsViaAPI, test } from './fixtures/nextcloud.js'

// Mirrors the storage key in src/views/AllAppointments.vue.
const CATEGORY_FILTER_STORAGE_KEY = 'attendance:list-filters:categories'

function uniqueName(prefix) {
	return `${prefix} ${Math.random().toString(36).slice(2, 8)}`
}

test.describe('Attendance App - Categories', () => {
	const createdCategoryIds = []

	test.afterAll(async ({ request }) => {
		// This file lives in the sequential-admin project (workers: 1), so a
		// hard wipe is safe here — unlike deleteAllAppointments, it also
		// clears the upcoming appointments these tests create.
		await forceWipeAllAppointments(request)
		for (const id of createdCategoryIds) {
			await deleteCategoryViaAPI(request, id)
		}
	})

	test.beforeEach(async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.waitForLoadState('networkidle')
	})

	test('admin can add, rename and delete a category', async ({ page }) => {
		await page.goto('/settings/admin/attendance')
		await page.waitForLoadState('networkidle')

		const section = page.locator('[data-test="section-categories"]')
		await expect(section).toBeVisible()

		const createName = uniqueName('Rehearsal')
		await section.locator('[data-test="input-new-category-icon"]').click()
		const microphoneOption = page.locator('[data-test="category-icon-option-microphone"]')
		await microphoneOption.click()
		await expect(microphoneOption).toHaveAttribute('aria-pressed', 'true')
		await page.keyboard.press('Escape')
		await page.getByRole('textbox', { name: 'New category name' }).fill(createName)
		await section.locator('[data-test="button-add-category"]').click()

		const item = section.locator('.category-list__item').filter({ hasText: createName })
		await expect(item).toBeVisible()

		const renamedName = uniqueName('Concert')
		await item.locator('[data-test="button-edit-category"]').click()
		// From here on, target `section` rather than `item`: editing swaps the
		// name into an <input>'s value, which isn't text content, so `item`
		// (still filtered by the old hasText name) stops matching anything.
		// Only one row can be in edit mode at a time, so these stay unambiguous.
		await section.locator('[data-test="input-edit-category-icon"]').click()
		const starOption = page.locator('[data-test="category-icon-option-star"]')
		await starOption.click()
		await expect(starOption).toHaveAttribute('aria-pressed', 'true')
		await page.keyboard.press('Escape')
		// exact: true — "New category name" (the add-category field, still on
		// screen) contains "Category name" as a substring and would otherwise
		// match too.
		const editField = section.getByRole('textbox', { name: 'Category name', exact: true })
		await editField.fill(renamedName)
		await section.getByRole('button', { name: 'Save', exact: true }).click()

		const renamedItem = section.locator('.category-list__item').filter({ hasText: renamedName })
		await expect(renamedItem).toBeVisible()
		await expect(section.locator('.category-list__item').filter({ hasText: createName })).toHaveCount(0)

		await renamedItem.locator('[data-test="button-delete-category"]').click()
		const dialog = page.getByRole('dialog').filter({ hasText: 'Delete category' })
		await expect(dialog).toBeVisible()
		await dialog.getByRole('button', { name: 'Delete', exact: true }).click()

		await expect(section.locator('.category-list__item').filter({ hasText: renamedName })).toHaveCount(0)
	})

	test('should add a category when creating an appointment, then change it when editing', async ({ page, request }) => {
		const categoryA = await createCategoryViaAPI(request, uniqueName('Rehearsal'))
		const categoryB = await createCategoryViaAPI(request, uniqueName('Concert'))
		createdCategoryIds.push(categoryA.id, categoryB.id)

		await page.reload()
		await page.waitForLoadState('networkidle')

		const createLink = page.getByRole('button', { name: 'Create Appointment' })
		await createLink.waitFor({ state: 'visible' })
		await createLink.click()
		await page.waitForURL(/.*\/create$/)
		await page.waitForLoadState('networkidle')

		await page.getByRole('textbox', { name: 'Appointment Name' }).fill('Appointment With Category')

		const now = new Date()
		const startDate = new Date(now.getTime() + 4 * 24 * 60 * 60 * 1000)
		const endDate = new Date(startDate.getTime() + 60 * 60 * 1000)
		await page.getByRole('textbox', { name: 'Start Date & Time' }).fill(startDate.toISOString().slice(0, 16))
		await page.getByRole('textbox', { name: 'End Date & Time' }).fill(endDate.toISOString().slice(0, 16))

		const categoryField = page.locator('[data-test="input-appointment-category"]')
		await categoryField.getByRole('combobox').click()
		await page.getByRole('option', { name: categoryA.name, exact: true }).click()
		await expect(categoryField.locator('.vs__selected').filter({ hasText: categoryA.name })).toBeVisible()

		await page.getByRole('button', { name: 'Save' }).click()
		await page.waitForURL(/.*\/apps\/attendance(?!\/(create|edit|copy))/)
		await page.waitForLoadState('networkidle')

		// Saving lands on the appointment's own detail page, not the list — the
		// detail card renders the category as plain icon + name, not a tooltip.
		const card = page.locator('[data-test="appointment-card"]').filter({ hasText: 'Appointment With Category' }).first()
		await expect(card).toBeVisible()
		await expect(card.locator('[data-test="appointment-category"]')).toContainText(categoryA.name)

		// Edit: switch to a different category.
		await page.getByText('Appointment With Category').first().click()
		await page.waitForLoadState('networkidle')
		await page.getByRole('button', { name: 'Actions' }).first().click()
		await page.getByRole('menuitem', { name: 'Edit' }).click()
		await page.waitForURL(/.*\/edit\/\d+$/)

		const editCategoryField = page.locator('[data-test="input-appointment-category"]')
		await expect(editCategoryField.locator('.vs__selected').filter({ hasText: categoryA.name })).toBeVisible()
		await editCategoryField.getByRole('combobox').click()
		await page.getByRole('option', { name: categoryB.name, exact: true }).click()
		await expect(editCategoryField.locator('.vs__selected').filter({ hasText: categoryB.name })).toBeVisible()

		await page.getByRole('button', { name: 'Save' }).click()
		await page.waitForURL(/.*\/apps\/attendance(?!\/appointment)/)
		await page.waitForLoadState('networkidle')

		const updatedCard = page.locator('[data-test="appointment-card"]').filter({ hasText: 'Appointment With Category' }).first()
		await expect(updatedCard.locator('[data-test="appointment-category"]')).toContainText(categoryB.name)

		// Edit again: clear the category entirely.
		await page.getByText('Appointment With Category').first().click()
		await page.waitForLoadState('networkidle')
		await page.getByRole('button', { name: 'Actions' }).first().click()
		await page.getByRole('menuitem', { name: 'Edit' }).click()
		await page.waitForURL(/.*\/edit\/\d+$/)

		const clearCategoryField = page.locator('[data-test="input-appointment-category"]')
		await expect(clearCategoryField.locator('.vs__selected').filter({ hasText: categoryB.name })).toBeVisible()
		await clearCategoryField.locator('.vs__clear').click()
		await expect(clearCategoryField.locator('.vs__selected')).toHaveCount(0)

		await page.getByRole('button', { name: 'Save' }).click()
		await page.waitForURL(/.*\/apps\/attendance(?!\/appointment)/)
		await page.waitForLoadState('networkidle')

		const clearedCard = page.locator('[data-test="appointment-card"]').filter({ hasText: 'Appointment With Category' }).first()
		await expect(clearedCard.locator('[data-test="appointment-category"]')).toHaveCount(0)
	})

	test('deleting a category clears it from appointments that used it', async ({ page, request }) => {
		const category = await createCategoryViaAPI(request, uniqueName('Workshop'))

		// Unique per run: a retry would otherwise create a second appointment
		// with the same fixed name, and .first() below could pick up the
		// stale one left over from the failed attempt.
		const targetName = uniqueName('Category Deletion Target')
		const appointment = await createAppointmentViaAPI(request, {
			name: targetName,
			daysFromNow: 6,
			categoryId: category.id,
		})
		expect(appointment.categoryId).toBe(category.id)

		await page.reload()
		await page.waitForLoadState('networkidle')

		const card = page.locator('[data-test="appointment-card"]').filter({ hasText: targetName }).first()
		await expect(card.locator('[data-test="appointment-category"]')).toHaveAttribute('aria-label', category.name)

		await page.goto('/settings/admin/attendance')
		await page.waitForLoadState('networkidle')

		const section = page.locator('[data-test="section-categories"]')
		const item = section.locator('.category-list__item').filter({ hasText: category.name })
		await expect(item).toBeVisible()
		await item.locator('[data-test="button-delete-category"]').click()
		const dialog = page.getByRole('dialog').filter({ hasText: 'Delete category' })
		await expect(dialog).toBeVisible()
		await dialog.getByRole('button', { name: 'Delete', exact: true }).click()
		await expect(section.locator('.category-list__item').filter({ hasText: category.name })).toHaveCount(0)

		await page.goto('/apps/attendance')
		await page.waitForLoadState('networkidle')

		const clearedCard = page.locator('[data-test="appointment-card"]').filter({ hasText: targetName }).first()
		await expect(clearedCard).toBeVisible()
		await expect(clearedCard.locator('[data-test="appointment-category"]')).toHaveCount(0)

		// listAppointmentsViaAPI defaults to the past partition — this
		// appointment is upcoming (daysFromNow: 6), so it only shows up when
		// asked for upcoming appointments explicitly.
		const [refetched] = (await listAppointmentsViaAPI(request, { showPast: false })).filter((a) => a.id === appointment.id)
		expect(refetched.categoryId).toBeNull()
	})

	test('category filter narrows the visible appointments and persists', async ({ page, request }) => {
		const categoryA = await createCategoryViaAPI(request, uniqueName('Choir'))
		const categoryB = await createCategoryViaAPI(request, uniqueName('Band'))
		createdCategoryIds.push(categoryA.id, categoryB.id)

		await createAppointmentViaAPI(request, {
			name: 'Filter Choir Meeting',
			daysFromNow: 7,
			categoryId: categoryA.id,
		})
		await createAppointmentViaAPI(request, {
			name: 'Filter Band Meeting',
			daysFromNow: 7,
			categoryId: categoryB.id,
		})
		await page.reload()
		await page.waitForLoadState('networkidle')

		const cards = page.locator('[data-test="appointment-card"]')
		await expect(cards.filter({ hasText: 'Filter Choir Meeting' }).first()).toBeVisible()
		await expect(cards.filter({ hasText: 'Filter Band Meeting' }).first()).toBeVisible()

		await page.locator('[data-test="filter-category"]').click()
		await page.getByRole('menuitemcheckbox', { name: categoryA.name }).click()

		await expect(cards.filter({ hasText: 'Filter Choir Meeting' }).first()).toBeVisible()
		await expect(cards.filter({ hasText: 'Filter Band Meeting' })).toHaveCount(0)

		await page.waitForFunction(
			([key]) => {
				try {
					const stored = JSON.parse(window.localStorage.getItem(key) || '[]')
					return Array.isArray(stored) && stored.length > 0
				} catch {
					return false
				}
			},
			[CATEGORY_FILTER_STORAGE_KEY],
		)
		await page.reload()
		await page.waitForLoadState('networkidle')
		await expect(cards.filter({ hasText: 'Filter Choir Meeting' }).first()).toBeVisible()
		await expect(cards.filter({ hasText: 'Filter Band Meeting' })).toHaveCount(0)
	})
})
