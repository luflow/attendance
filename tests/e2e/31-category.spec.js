import { createAppointmentViaAPI, createCategoryViaAPI, deleteCategoryViaAPI, expect, forceWipeAllAppointments, listAppointmentsViaAPI, listCategoriesViaAPI, test } from './fixtures/nextcloud.js'

// Mirrors the storage key in src/views/AllAppointments.vue.
const CATEGORY_FILTER_STORAGE_KEY = 'attendance:list-filters:categories'

function uniqueName(prefix) {
	return `${prefix} ${Math.random().toString(36).slice(2, 8)}`
}

async function pickCategory(page, category) {
	await page.locator('[data-test="input-appointment-category"]').getByRole('combobox').click()
	await page.getByRole('option', { name: category.name, exact: true }).click()
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

	test('admin can add, rename and delete a category', async ({ page, request }) => {
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
		// Editing happens in a dialog with two sections: the category and its template.
		const editor = page.locator('[data-test="category-edit-dialog"]')
		await expect(editor.getByRole('heading', { name: 'Category & icon' })).toBeVisible()
		await expect(editor.getByRole('heading', { name: 'Template for this category' })).toBeVisible()
		await editor.locator('[data-test="input-edit-category-icon"]').click()
		const starOption = page.locator('[data-test="category-icon-option-star"]')
		await starOption.click()
		await expect(starOption).toHaveAttribute('aria-pressed', 'true')
		// Escape would close the whole dialog, so leave the icon popover by clicking away.
		await editor.getByRole('heading', { name: 'Category & icon' }).click()
		await editor.getByRole('textbox', { name: 'Category name', exact: true }).fill(renamedName)
		await editor.locator('[data-test="input-category-template-description"] .CodeMirror').click()
		await page.keyboard.type('Clothing:')
		await editor.locator('[data-test="input-category-template-name"]').fill('Rehearsal tonight')
		await editor.locator('[data-test="input-category-template-location"]').fill('Parish hall')
		await editor.locator('[data-test="input-category-template-deadline-value"]').fill('2')
		await editor.locator('[data-test="select-category-template-deadline-unit"]').selectOption('days')
		await editor.locator('[data-test="input-category-template-limit"]').fill('12')
		await editor.getByText('Offer a waitlist when full').click()
		await editor.getByText(/Offer .Maybe. as an answer/).click()
		await editor.getByRole('button', { name: 'Save', exact: true }).click()
		await expect(editor).toHaveCount(0)

		const renamedItem = section.locator('.category-list__item').filter({ hasText: renamedName })
		await expect(renamedItem).toBeVisible()
		const saved = (await listCategoriesViaAPI(request)).find((category) => category.name === renamedName)
		expect(saved.template).toMatchObject({
			description: 'Clothing:',
			name: 'Rehearsal tonight',
			location: 'Parish hall',
			responseDeadlineValue: 2,
			responseDeadlineUnit: 'days',
			maxAttendees: 12,
			waitlistEnabled: false,
			allowMaybe: false,
			sendNotification: null,
		})
		await expect(section.locator('.category-list__item').filter({ hasText: renamedName }).locator('[data-test="category-has-template"]')).toBeVisible()
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
		await pickCategory(page, categoryA)
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
		await pickCategory(page, categoryB)
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

	// The editor starts like a new appointment does, so a switch left alone says
	// "not preset" without a third state: only a deviation is stored.
	test('the template editor starts with the defaults of a new appointment', async ({ page, request }) => {
		const category = await createCategoryViaAPI(request, uniqueName('Defaults'))
		createdCategoryIds.push(category.id)

		await page.goto('/settings/admin/attendance')
		await page.waitForLoadState('networkidle')
		const section = page.locator('[data-test="section-categories"]')
		await section.locator('.category-list__item').filter({ hasText: category.name })
			.locator('[data-test="button-edit-category"]').click()
		const editor = page.locator('[data-test="category-edit-dialog"]')

		await expect(editor.getByRole('checkbox', { name: /Offer .Maybe. as an answer/ })).toBeChecked()
		await expect(editor.getByRole('checkbox', { name: 'Send notification' })).not.toBeChecked()
		await expect(editor.locator('[data-test="input-category-template-limit"]')).toHaveValue('')

		// Saving untouched stores no template at all — and shows no badge.
		await editor.getByRole('button', { name: 'Save', exact: true }).click()
		await expect(editor).toHaveCount(0)
		const saved = (await listCategoriesViaAPI(request)).find((c) => c.id === category.id)
		expect(saved.template).toBeNull()
		await expect(section.locator('.category-list__item').filter({ hasText: category.name })
			.locator('[data-test="category-has-template"]')).toHaveCount(0)
	})

	test('cancelling the template editor discards what was typed', async ({ page, request }) => {
		const category = await createCategoryViaAPI(request, uniqueName('Cancel'), { template: { name: 'Kept name' } })
		createdCategoryIds.push(category.id)

		await page.goto('/settings/admin/attendance')
		await page.waitForLoadState('networkidle')
		const section = page.locator('[data-test="section-categories"]')
		const row = section.locator('.category-list__item').filter({ hasText: category.name })
		await row.locator('[data-test="button-edit-category"]').click()
		const editor = page.locator('[data-test="category-edit-dialog"]')
		const templateName = editor.locator('[data-test="input-category-template-name"]')

		await expect(templateName).toHaveValue('Kept name')
		await templateName.fill('Thrown away')
		await editor.getByRole('button', { name: 'Cancel', exact: true }).click()
		await expect(editor).toHaveCount(0)

		await row.locator('[data-test="button-edit-category"]').click()
		await expect(page.locator('[data-test="input-category-template-name"]')).toHaveValue('Kept name')
		expect((await listCategoriesViaAPI(request)).find((c) => c.id === category.id).template.name).toBe('Kept name')
	})

	test('emptying every template field removes the template again', async ({ page, request }) => {
		const category = await createCategoryViaAPI(request, uniqueName('Emptied'), {
			template: { name: 'Gone soon', location: 'Hall', maxAttendees: 5, allowMaybe: false },
		})
		createdCategoryIds.push(category.id)

		await page.goto('/settings/admin/attendance')
		await page.waitForLoadState('networkidle')
		const section = page.locator('[data-test="section-categories"]')
		const row = section.locator('.category-list__item').filter({ hasText: category.name })
		await expect(row.locator('[data-test="category-has-template"]')).toBeVisible()
		await row.locator('[data-test="button-edit-category"]').click()
		const editor = page.locator('[data-test="category-edit-dialog"]')

		await expect(editor.getByRole('checkbox', { name: /Offer .Maybe. as an answer/ })).not.toBeChecked()
		await editor.locator('[data-test="input-category-template-name"]').fill('')
		await editor.locator('[data-test="input-category-template-location"]').fill('')
		await editor.locator('[data-test="input-category-template-limit"]').fill('')
		await editor.getByText(/Offer .Maybe. as an answer/).click()
		await editor.getByRole('button', { name: 'Save', exact: true }).click()
		await expect(editor).toHaveCount(0)

		expect((await listCategoriesViaAPI(request)).find((c) => c.id === category.id).template).toBeNull()
		await expect(row.locator('[data-test="category-has-template"]')).toHaveCount(0)
	})

	test('picking a category prefills a new appointment from its template', async ({ page, request }) => {
		const withTemplate = await createCategoryViaAPI(request, uniqueName('Concert'), {
			template: {
				name: 'Concert tonight',
				description: 'Clothing:',
				visibleGroups: ['admin'],
				responseDeadlineValue: 3,
				responseDeadlineUnit: 'days',
				maxAttendees: 20,
				waitlistEnabled: false,
			},
		})
		const plain = await createCategoryViaAPI(request, uniqueName('Rehearsal'))
		createdCategoryIds.push(withTemplate.id, plain.id)

		await page.reload()
		await page.waitForLoadState('networkidle')
		await page.getByRole('button', { name: 'Create Appointment' }).click()
		await page.waitForURL(/.*\/create$/)
		await page.waitForLoadState('networkidle')

		const description = page.locator('[data-test="input-appointment-description"] .CodeMirror')
		const access = page.locator('[data-test="select-visibility"] .vs__selected')
		const applyTemplate = page.locator('[data-test="button-apply-template"]')

		const nameInput = page.getByRole('textbox', { name: 'Appointment Name' })

		await pickCategory(page, withTemplate)
		await expect(description).toContainText('Clothing:')
		await expect(access).toContainText('admin')
		await expect(nameInput).toHaveValue('Concert tonight')
		await expect(page.locator('[data-test="input-deadline-relative-value"]')).toHaveValue('3')
		await expect(page.locator('[data-test="input-max-attendees"]')).toHaveValue('20')
		await expect(page.getByRole('checkbox', { name: 'Offer a waitlist when full' })).not.toBeChecked()
		await expect(applyTemplate).toHaveCount(0)

		// Untouched template content follows the category …
		await pickCategory(page, plain)
		await expect(description).not.toContainText('Clothing:')
		await expect(access).toHaveCount(0)
		await expect(nameInput).toHaveValue('')
		await expect(page.locator('[data-test="input-max-attendees"]')).toHaveValue('')

		// … own content stays, with the template one click away.
		await description.click()
		await page.keyboard.type('Bring snacks')
		await pickCategory(page, withTemplate)
		await expect(description).toContainText('Bring snacks')
		await applyTemplate.click()
		await expect(description).toContainText('Clothing:')
		await expect(description).not.toContainText('Bring snacks')
		await expect(applyTemplate).toHaveCount(0)
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

	// The unanswered view hides the filter bar, so a filter leaking in from the
	// appointment list emptied it with nothing on screen to explain or undo it.
	test('the unanswered view ignores a category filter set on the appointment list', async ({ page, request }) => {
		const category = await createCategoryViaAPI(request, uniqueName('Choir'))
		createdCategoryIds.push(category.id)

		const targetName = uniqueName('Unanswered Without Category')
		await createAppointmentViaAPI(request, { name: targetName, daysFromNow: 8 })
		await createAppointmentViaAPI(request, {
			name: uniqueName('Choir Filter Source'),
			daysFromNow: 8,
			categoryId: category.id,
		})

		await page.reload()
		await page.waitForLoadState('networkidle')

		const cards = page.locator('[data-test="appointment-card"]')
		await page.locator('[data-test="filter-category"]').click()
		await page.getByRole('menuitemcheckbox', { name: category.name }).click()
		await expect(cards.filter({ hasText: targetName })).toHaveCount(0)

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

		await page.goto('/apps/attendance/unanswered')
		await page.waitForLoadState('networkidle')

		await expect(page.locator('[data-test="appointment-filters"]')).toHaveCount(0)
		await expect(cards.filter({ hasText: targetName }).first()).toBeVisible()
	})
})
