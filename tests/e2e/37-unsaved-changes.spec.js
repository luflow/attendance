import {
	createAppointmentViaAPI,
	deleteAppointmentViaAPI,
	expect,
	loadWholeAppointmentList,
	test,
} from './fixtures/nextcloud.js'

// #232: the save bar stays in view, and leaving a changed form asks first.
// #233: the detail view has a way back, and the list is where it was left.
test.describe('Unsaved changes and the way back (#232, #233)', () => {
	test.describe.configure({ mode: 'serial' })

	// More than one page of the list, so the last one is only reached by paging.
	const names = Array.from({ length: 25 }, (_, i) => `Leave Guard ${String(i + 1).padStart(2, '0')}`)
	const editedName = 'Leave Guard Edited'
	const created = []

	test.beforeAll(async ({ request }) => {
		for (const [i, name] of names.entries()) {
			const appointment = await createAppointmentViaAPI(request, { name, daysFromNow: 40 + i })
			created.push(appointment.id)
		}
	})

	test.afterAll(async ({ request }) => {
		for (const id of created) {
			await deleteAppointmentViaAPI(request, id)
		}
	})

	const listCard = (page, name) => page.locator('[data-test="appointment-card"]').filter({ hasText: name })

	async function openEditFromDetail(page, name) {
		await loadWholeAppointmentList(page)
		await listCard(page, name).locator('[data-test="appointment-title-link"]').click()
		await page.waitForURL(/\/appointment\/\d+$/)
		await page.getByRole('button', { name: 'Actions' }).first().click()
		await page.locator('[data-test="action-edit"]').click()
		await page.waitForURL(/\/edit\/\d+$/)
		await page.waitForLoadState('networkidle')
	}

	test('the save bar stays in view and leaving a changed form asks first', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await openEditFromDetail(page, names[0])

		const nameInput = page.getByRole('textbox', { name: 'Appointment Name' })
		const unsavedHint = page.locator('[data-test="unsaved-hint"]')
		const dialog = page.getByRole('dialog', { name: 'Unsaved changes' })

		// The form is longer than the window, yet saving takes no scrolling.
		await expect(page.locator('[data-test="button-save"]')).toBeInViewport()
		await expect(unsavedHint).toBeHidden()

		await nameInput.fill(editedName)
		await expect(unsavedHint).toBeVisible()

		await page.locator('[data-test="nav-all"]').click()
		await dialog.getByRole('button', { name: 'Keep editing' }).click()
		await expect(page).toHaveURL(/\/edit\/\d+$/)
		await expect(nameInput).toHaveValue(editedName)

		await page.goBack()
		await dialog.getByRole('button', { name: 'Keep editing' }).click()
		await expect(page).toHaveURL(/\/edit\/\d+$/)
		await expect(nameInput).toHaveValue(editedName)

		// Reloading or closing the tab is the browser's own prompt.
		const browserPrompt = page.waitForEvent('dialog')
		await page.evaluate(() => {
			setTimeout(() => window.location.reload(), 0)
		})
		const beforeUnload = await browserPrompt
		expect(beforeUnload.type()).toBe('beforeunload')
		await beforeUnload.dismiss()
		await expect(nameInput).toHaveValue(editedName)

		// Typing the old name back makes the form clean again.
		await nameInput.fill(names[0])
		await expect(unsavedHint).toBeHidden()

		await nameInput.fill(editedName)
		await page.locator('[data-test="button-cancel"]').click()
		await dialog.getByRole('button', { name: 'Discard changes' }).click()
		await expect(page).toHaveURL(/\/appointment\/\d+$/)
		await expect(page.locator('[data-test="appointment-title"]')).toContainText(names[0])
	})

	// An entry from before an update, still in the tab's history after a reload,
	// says nothing about where it sits. Going back to it has to ask all the same.
	test('browser back asks even when the entry behind the form carries no position', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.evaluate(() => window.history.replaceState({ view: 'all' }, ''))

		await page.locator('[data-test="button-create-appointment"]').click()
		await page.waitForURL(/\/create$/)
		await page.getByRole('textbox', { name: 'Appointment Name' }).fill('Never saved')
		await expect(page.locator('[data-test="unsaved-hint"]')).toBeVisible()

		const dialog = page.getByRole('dialog', { name: 'Unsaved changes' })
		await page.goBack()
		await dialog.getByRole('button', { name: 'Keep editing' }).click()
		await expect(page).toHaveURL(/\/create$/)
		await expect(page.getByRole('textbox', { name: 'Appointment Name' })).toHaveValue('Never saved')

		await page.goBack()
		await dialog.getByRole('button', { name: 'Discard changes' }).click()
		await expect(page).not.toHaveURL(/\/create$/)
		await expect(page.locator('[data-test="page-heading"]')).toHaveText('All appointments')
	})

	// The form is one component for create, edit and copy; coming from an edit
	// it kept showing the old appointment's data in what was meant to be a new one.
	test('a new appointment starts empty after editing another one', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await openEditFromDetail(page, names[1])

		const nameInput = page.getByRole('textbox', { name: 'Appointment Name' })
		await expect(nameInput).toHaveValue(names[1])

		await page.locator('[data-test="button-create-appointment"]').click()
		await page.waitForURL(/\/create$/)
		await expect(nameInput).toHaveValue('')
		await expect(page.locator('[data-test="unsaved-hint"]')).toBeHidden()
	})

	test('saving from the detail view returns to it, and Back leads on to the list', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await openEditFromDetail(page, names[0])

		await page.getByRole('textbox', { name: 'Appointment Name' }).fill(editedName)
		await page.locator('[data-test="button-save"]').click()
		await expect(page).toHaveURL(/\/appointment\/\d+$/)
		await expect(page.locator('[data-test="appointment-title"]')).toContainText(editedName)

		// The form's history entry is used up: one step back is the list.
		await page.locator('[data-test="button-back"]').click()
		await expect(page).not.toHaveURL(/\/(appointment|edit)\//)
		await expect(listCard(page, editedName)).toBeVisible()
	})

	test('Back returns to the list where it was left', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()

		const scroller = page.locator('#app-content-vue')
		const scrollTop = () => scroller.evaluate((el) => el.scrollTop)
		const card = listCard(page, names.at(-1))

		// Several pages deep, so the way back has to bring all of them along.
		await loadWholeAppointmentList(page)
		await card.scrollIntoViewIfNeeded()
		const leftAt = await scrollTop()
		expect(leftAt).toBeGreaterThan(0)

		await card.locator('[data-test="appointment-title-link"]').click()
		await expect(page).toHaveURL(/\/appointment\/\d+$/)
		await expect.poll(scrollTop).toBe(0)

		await page.locator('[data-test="button-back"]').click()
		await expect.poll(scrollTop).toBe(leftAt)
		await expect(card).toBeInViewport()
	})
})
