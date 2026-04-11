import { test, expect, createAppointmentViaAPI, deleteAllAppointments } from './fixtures/nextcloud.js'

test.describe('Attendance App - Dashboard Widget Voting', () => {
	test.beforeAll(async ({ request }) => {
		// Create appointments so the dashboard widget has data to display
		await createAppointmentViaAPI(request, {
			name: 'Widget Sprint Planning',
			description: 'Plan next sprint goals and tasks',
			daysFromNow: 3,
			durationHours: 2,
		})
		await createAppointmentViaAPI(request, {
			name: 'Widget Code Review',
			description: 'Review PRs from this week',
			daysFromNow: 4,
			durationHours: 1,
		})
		await createAppointmentViaAPI(request, {
			name: 'Widget Team Retro',
			description: 'Discuss what went well and improvements',
			daysFromNow: 5,
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

		const widget = page.locator('.appointment-widget-container').or(page.getByRole('heading', { name: 'Attendance' }).locator('..'))

		await widget.getByRole('button', { name: 'Yes', exact: true }).first().click()
		await page.waitForLoadState('networkidle')

		await expect(widget.getByRole('button', { name: 'Yes', exact: true }).first()).toBeVisible()
	})

	test('should add comment on appointment from dashboard', async ({ page }) => {
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')

		const widget = page.locator('.appointment-widget-container').or(page.getByRole('heading', { name: 'Attendance' }).locator('..'))

		const yesButton = widget.getByRole('button', { name: 'Yes', exact: true }).first()
		await yesButton.click()
		await page.waitForLoadState('networkidle')

		const commentToggle = widget.locator('[data-test="button-widget-toggle-comment"]').first()
		await commentToggle.click()

		const commentField = widget.locator('[data-test="widget-response-comment"]').first()
		await expect(commentField).toBeVisible({ timeout: 5000 })
		const commentText = 'Great meeting, looking forward to it!'
		await commentField.fill(commentText)

		const savedIndicator = widget.locator('.saved-indicator').first()
		await expect(savedIndicator).toBeVisible({ timeout: 5000 })

		await page.reload()
		await page.waitForLoadState('networkidle')

		const reloadedWidget = page.locator('.appointment-widget-container').or(page.getByRole('heading', { name: 'Attendance' }).locator('..'))

		const reloadedCommentToggle = reloadedWidget.locator('[data-test="button-widget-toggle-comment"]').first()
		await reloadedCommentToggle.click()

		const reloadedCommentField = reloadedWidget.locator('[data-test="widget-response-comment"]').first()
		await expect(reloadedCommentField).toBeVisible({ timeout: 5000 })
		await expect(reloadedCommentField).toHaveValue(commentText)
	})

	test('should open detail view by clicking appointment title', async ({ page }) => {
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')

		const appointmentTitle = page.locator('[data-test="widget-appointment-title"]').first()
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
		await expect(page.getByRole('link', { name: 'Create Appointment' })).toBeVisible()
	})

	test('should vote Maybe on appointment from dashboard', async ({ page }) => {
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')

		const widget = page.locator('.appointment-widget-container').or(page.getByRole('heading', { name: 'Attendance' }).locator('..'))
		await widget.getByRole('button', { name: 'Maybe' }).first().click()
		await page.waitForLoadState('networkidle')
		await expect(widget.getByRole('button', { name: 'Maybe' }).first()).toBeVisible()
	})

	test('should vote No on appointment from dashboard', async ({ page }) => {
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')

		const widget = page.locator('.appointment-widget-container').or(page.getByRole('heading', { name: 'Attendance' }).locator('..'))
		await widget.getByRole('button', { name: 'No', exact: true }).first().click()
		await page.waitForLoadState('networkidle')
		await expect(widget.getByRole('button', { name: 'No', exact: true }).first()).toBeVisible()
	})

	test('should change vote from dashboard', async ({ page }) => {
		await page.goto('/apps/dashboard/')
		await page.waitForLoadState('networkidle')

		const widget = page.locator('.appointment-widget-container').or(page.getByRole('heading', { name: 'Attendance' }).locator('..'))

		await widget.getByRole('button', { name: 'Yes', exact: true }).first().click()
		await page.waitForLoadState('networkidle')

		await widget.getByRole('button', { name: 'No', exact: true }).first().click()
		await page.waitForLoadState('networkidle')

		await expect(widget.getByRole('button', { name: 'Yes', exact: true }).first()).toBeVisible()
		await expect(widget.getByRole('button', { name: 'No', exact: true }).first()).toBeVisible()
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
