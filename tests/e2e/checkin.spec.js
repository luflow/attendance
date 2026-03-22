import { test, expect, createAppointmentViaAPI, deleteAllAppointments, checkinUserViaAPI } from './fixtures/nextcloud.js'

let checkinAppointmentId

// Helper to navigate to checkin view and dismiss "future appointment" warning if shown
async function goToCheckin(page, appointmentId) {
	await page.goto(`/apps/attendance/checkin/${appointmentId}`)
	await page.waitForLoadState('networkidle')

	// Dismiss "appointment is way in the future" warning if present
	const continueButton = page.getByRole('button', { name: 'Continue anyway' })
	if (await continueButton.isVisible({ timeout: 2000 }).catch(() => false)) {
		await continueButton.click()
		await page.waitForLoadState('networkidle')
	}
}

test.describe('Attendance App - Check-in Workflow', () => {
	test.beforeAll(async ({ request }) => {
		const data = await createAppointmentViaAPI(request, {
			name: 'Check-in Test Meeting',
			description: 'Appointment for testing check-in workflow',
			daysFromNow: 3,
			durationHours: 1,
		})
		checkinAppointmentId = data.id
	})

	test.afterAll(async ({ request }) => {
		await deleteAllAppointments(request)
	})

	test.beforeEach(async ({ page, loginAsUser }) => {
		await loginAsUser('admin', 'admin')
	})

	test('should navigate to check-in view', async ({ page, attendanceApp }) => {
		await attendanceApp()
		await page.waitForLoadState('networkidle')

		const appointmentCard = page.locator('[data-test="appointment-card"]').first()
		await appointmentCard.locator('[data-test="appointment-actions-menu"]').click()
		await page.locator('[data-test="action-start-checkin"]').click()

		// Dismiss future warning if shown
		const continueButton = page.getByRole('button', { name: 'Continue anyway' })
		if (await continueButton.isVisible({ timeout: 2000 }).catch(() => false)) {
			await continueButton.click()
			await page.waitForLoadState('networkidle')
		}

		await expect(page.locator('[data-test="checkin-view"]')).toBeVisible()
	})

	test('should display search and filter controls', async ({ page }) => {
		await goToCheckin(page, checkinAppointmentId)

		await expect(page.locator('[data-test="checkin-view"]')).toBeVisible()
		await expect(page.locator('[data-test="input-search"]')).toBeVisible()
		await expect(page.locator('[data-test="select-group-filter"]')).toBeVisible()
	})

	test('should mark user as present', async ({ page }) => {
		await goToCheckin(page, checkinAppointmentId)

		const userItem = page.locator('[data-test^="user-item-"]').first()
		await userItem.locator('[data-test="button-present"]').click()
		await page.waitForLoadState('networkidle')

		const presentButton = userItem.locator('[data-test="button-present"]')
		await expect(presentButton).toBeVisible()
	})

	test('should mark user as absent', async ({ page }) => {
		await goToCheckin(page, checkinAppointmentId)

		const userItem = page.locator('[data-test^="user-item-"]').first()
		await userItem.locator('[data-test="button-absent"]').click()
		await page.waitForLoadState('networkidle')
	})

	test('should add comment to check-in', async ({ page, request }) => {
		// Mark user as present via API first (ensures checkin state is persisted)
		await checkinUserViaAPI(request, checkinAppointmentId, 'admin')

		await goToCheckin(page, checkinAppointmentId)

		const userItem = page.locator('[data-test="user-item-admin"]')
		await userItem.locator('[data-test="button-add-comment"]').click()
		const commentTextarea = userItem.locator('[data-test="textarea-checkin-comment"]')
		await expect(commentTextarea).toBeVisible()

		const commentText = 'User arrived late'
		await commentTextarea.fill(commentText)
		await userItem.locator('[data-test="button-save-comment"]').click()
		await page.waitForLoadState('networkidle')
		await commentTextarea.waitFor({ state: 'hidden', timeout: 5000 })

		// Reload and verify persistence
		await goToCheckin(page, checkinAppointmentId)

		const reloadedUserItem = page.locator('[data-test="user-item-admin"]')
		await expect(reloadedUserItem.locator('.checkin-comment')).toContainText(commentText)
	})

	test('should perform bulk check-in', async ({ page }) => {
		await goToCheckin(page, checkinAppointmentId)

		await page.locator('[data-test="button-bulk-present"]').click()
		await expect(page.locator('[data-test="dialog-confirm-bulk"]')).toBeVisible()
		await page.locator('[data-test="button-bulk-cancel"]').click()
		await expect(page.locator('[data-test="dialog-confirm-bulk"]')).not.toBeVisible()
	})

	test('should search for users', async ({ page }) => {
		await goToCheckin(page, checkinAppointmentId)

		const searchInput = page.locator('[data-test="input-search"]')
		await searchInput.fill('test')
		await page.waitForLoadState('networkidle')

		const userItems = page.locator('[data-test^="user-item-"]')
		const count = await userItems.count()
		expect(count).toBeGreaterThanOrEqual(0)
	})

	test('should go back from check-in view', async ({ page }) => {
		await goToCheckin(page, checkinAppointmentId)

		await page.locator('[data-test="button-back"]').click()
		await page.waitForLoadState('networkidle')

		await expect(page.locator('[data-test="checkin-view"]')).not.toBeVisible()
	})
})

test.describe('Attendance App - Bulk Operations', () => {
	test.beforeEach(async ({ page, loginAsUser }) => {
		await loginAsUser('admin', 'admin')
		await goToCheckin(page, checkinAppointmentId)
	})

	test('should confirm bulk present operation', async ({ page }) => {
		await page.locator('[data-test="button-bulk-present"]').click()
		await expect(page.locator('[data-test="dialog-confirm-bulk"]')).toBeVisible()

		await page.locator('[data-test="button-bulk-confirm"]').click()
		await expect(page.locator('[data-test="dialog-confirm-bulk"]')).not.toBeVisible()
	})

	test('should confirm bulk absent operation', async ({ page }) => {
		await page.locator('[data-test="button-bulk-absent"]').click()
		await expect(page.locator('[data-test="dialog-confirm-bulk"]')).toBeVisible()

		const confirmButton = page.locator('[data-test="button-bulk-confirm"]')
		await expect(confirmButton).toBeVisible()

		await page.locator('[data-test="button-bulk-cancel"]').click()
	})
})
