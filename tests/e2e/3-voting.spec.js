import { test, expect, createAppointmentViaAPI, deleteAllAppointments, openCommentField, waitForRespond } from './fixtures/nextcloud.js'

// The widget lists the next 10 upcoming appointments instance-wide
// (Widget::getItems), so in the parallel project `.first()` is regularly some
// other spec's appointment — and the target moves as they create and delete.
// Every interaction below picks its appointment by name instead.
function widgetItem(page, name) {
	return page.locator('[data-test="widget-appointment-item"]').filter({ hasText: name })
}

test.describe('Attendance App - Dashboard Widget Voting', () => {
	// A day out, so the other specs' appointments (two days and later) cannot
	// push these three out of the widget's ten slots.
	test.beforeAll(async ({ request }) => {
		await createAppointmentViaAPI(request, {
			name: 'Widget Sprint Planning',
			description: 'Plan next sprint goals and tasks',
			daysFromNow: 1,
			durationHours: 2,
		})
		await createAppointmentViaAPI(request, {
			name: 'Widget Code Review',
			description: 'Review PRs from this week',
			daysFromNow: 1,
			durationHours: 1,
		})
		await createAppointmentViaAPI(request, {
			name: 'Widget Team Retro',
			description: 'Discuss what went well and improvements',
			daysFromNow: 1,
			durationHours: 1,
		})
	})

	test.afterAll(async ({ request }) => {
		await deleteAllAppointments(request)
	})

	test.beforeEach(async ({ page, loginAsUser }) => {
		await loginAsUser('admin', 'admin')
	})

	test('should add Attendance widget to dashboard', async ({ page }) => {
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')

		await page.getByRole('button', { name: 'Customize' }).click()
		await expect(page.getByRole('dialog')).toBeVisible()
		await expect(page.getByRole('heading', { name: 'Edit widgets' })).toBeVisible()

		const attendanceLabel = page.getByRole('dialog').getByText('Attendance')
		await attendanceLabel.click()
		await expect(page.getByRole('checkbox', { name: 'Attendance' })).toBeChecked()

		await page.getByRole('button', { name: 'Close' }).click()
		await expect(page.getByRole('dialog')).not.toBeVisible()

		await expect(page.getByRole('heading', { name: 'Attendance', level: 2 })).toBeVisible()
	})

	test('should vote on appointment from dashboard', async ({ page }) => {
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')

		const item = widgetItem(page, 'Widget Sprint Planning')

		await item.getByRole('button', { name: 'Yes', exact: true }).click()
		await page.waitForLoadState('networkidle')

		await expect(item.getByRole('button', { name: 'Yes', exact: true })).toBeVisible()
	})

	test('should add comment on appointment from dashboard', async ({ page }) => {
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')

		const item = widgetItem(page, 'Widget Code Review')

		await item.getByRole('button', { name: 'Yes', exact: true }).click()
		await page.waitForLoadState('networkidle')

		await openCommentField(item.locator('[data-test="button-toggle-comment"]'))

		const commentField = item.locator('[data-test="response-comment"]')
		await expect(commentField).toBeVisible({ timeout: 5000 })
		const commentText = 'Great meeting, looking forward to it!'
		await commentField.fill(commentText)

		// Comments are saved explicitly. Wait for the request itself: the button
		// goes disabled the moment the save starts, so reloading on that signal
		// would cancel the very request under test.
		const saveComment = item.locator('[data-test="button-save-comment"]')
		await expect(saveComment).toBeEnabled({ timeout: 5000 })
		const saved = waitForRespond(page)
		await saveComment.click()
		await saved

		await page.reload()
		await page.waitForLoadState('networkidle')

		const reloadedItem = widgetItem(page, 'Widget Code Review')

		await openCommentField(reloadedItem.locator('[data-test="button-toggle-comment"]'))

		const reloadedCommentField = reloadedItem.locator('[data-test="response-comment"]')
		await expect(reloadedCommentField).toBeVisible({ timeout: 5000 })
		await expect(reloadedCommentField).toHaveValue(commentText)
	})

	test('should open detail view by clicking appointment title', async ({ page }) => {
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')

		const appointmentTitle = widgetItem(page, 'Widget Team Retro').locator('[data-test="widget-appointment-title"]')
		const titleText = await appointmentTitle.textContent()

		await appointmentTitle.click()
		await page.waitForURL(/\/apps\/attendance\/appointment\/\d+/, { timeout: 10000 })

		await expect(page.getByRole('heading', { level: 3, name: titleText })).toBeVisible()
		await expect(page.getByRole('heading', { name: 'Your Response' })).toBeVisible()
		await expect(page.getByRole('heading', { name: 'Response Summary' })).toBeVisible()
	})

	test('should open detail view by clicking appointment description', async ({ page }) => {
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')

		const widget = page.locator('.appointment-widget-container').or(page.getByRole('heading', { name: 'Attendance' }).locator('..'))

		const description = widget.locator('.appointment-description').or(widget.locator('p')).first()
		if (await description.isVisible()) {
			await description.click()
			await page.waitForLoadState('networkidle')
			await expect(page).toHaveURL(/\/apps\/attendance\//)
		}
	})

	test('should navigate to listing page via Show all appointments button', async ({ page }) => {
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')

		await page.getByRole('button', { name: 'Show all appointments' }).click()
		await page.waitForLoadState('networkidle')
		await expect(page).toHaveURL(/\/apps\/attendance/)

		await expect(page.getByRole('link', { name: 'Upcoming Appointments' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Create Appointment' })).toBeVisible()
	})

	test('should vote Maybe on appointment from dashboard', async ({ page }) => {
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')

		const item = widgetItem(page, 'Widget Team Retro')
		await item.getByRole('button', { name: 'Maybe' }).click()
		await page.waitForLoadState('networkidle')
		await expect(item.getByRole('button', { name: 'Maybe' })).toBeVisible()
	})

	test('should vote No on appointment from dashboard', async ({ page }) => {
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')

		const item = widgetItem(page, 'Widget Sprint Planning')
		await item.getByRole('button', { name: 'No', exact: true }).click()
		await page.waitForLoadState('networkidle')
		await expect(item.getByRole('button', { name: 'No', exact: true })).toBeVisible()
	})

	test('should change vote from dashboard', async ({ page }) => {
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')

		const item = widgetItem(page, 'Widget Sprint Planning')

		await item.getByRole('button', { name: 'Yes', exact: true }).click()
		await page.waitForLoadState('networkidle')

		await item.getByRole('button', { name: 'No', exact: true }).click()
		await page.waitForLoadState('networkidle')

		await expect(item.getByRole('button', { name: 'Yes', exact: true })).toBeVisible()
		await expect(item.getByRole('button', { name: 'No', exact: true })).toBeVisible()
	})
})

test.describe('Attendance App - Dashboard Widget Navigation', () => {
	// These tests reuse appointments created by the voting describe's beforeAll
	// Since they run in the same file/worker, the data persists

	test('should show multiple appointments in widget', async ({ page, loginAsUser }) => {
		await loginAsUser('admin', 'admin')
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')

		const widget = page.locator('.appointment-widget-container').or(page.getByRole('heading', { name: 'Attendance' }).locator('..'))
		const appointments = widget.getByRole('heading', { level: 3 })
		const count = await appointments.count()

		expect(count).toBeGreaterThan(0)
	})

	test('should return to dashboard from appointment detail', async ({ page, loginAsUser }) => {
		await loginAsUser('admin', 'admin')
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')

		await page.getByRole('heading', { level: 3 }).first().click()
		await page.waitForLoadState('networkidle')

		await page.getByRole('link', { name: 'Dashboard' }).first().click()
		await page.waitForLoadState('networkidle')

		await expect(page).toHaveURL(/\/apps\/dashboard/)
		await expect(page.getByRole('heading', { name: 'Dashboard', level: 1 })).toBeVisible()
	})
})
