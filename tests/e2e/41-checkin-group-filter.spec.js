import { createAppointmentViaAPI, deleteAllAppointments, expect, goToCheckin, reloadWebWorkers, resetAdminSettings, saveAdminSettings, test } from './fixtures/nextcloud.js'

// The check-in list's group filter follows the response summary grouping
// mode: nothing to pick from under "No grouping" (issue #212).
test.describe('Check-in group filter follows the grouping mode', () => {
	let appointmentId

	test.beforeAll(async ({ request }) => {
		const data = await createAppointmentViaAPI(request, {
			name: 'Group Filter Test Meeting',
			daysFromNow: 3,
			durationHours: 1,
		})
		appointmentId = data.id
	})

	test.afterAll(async ({ request }) => {
		await resetAdminSettings(request)
		await reloadWebWorkers()
		await deleteAllAppointments(request)
	})

	test.beforeEach(async ({ loginAsUser }) => {
		await loginAsUser('admin', 'admin')
	})

	test('offers the group filter once the admin picked groups', async ({ page, request }) => {
		await saveAdminSettings(request, {
			responseSummaryGroupsMode: 'specific',
			whitelistedGroups: ['admin'],
		})
		await reloadWebWorkers()

		await goToCheckin(page, appointmentId)
		await expect(page.locator('[data-test="select-group-filter"]')).toBeVisible()
	})

	test('hides the group filter again under "No grouping"', async ({ page, request }) => {
		await saveAdminSettings(request, {
			responseSummaryGroupsMode: 'none',
			whitelistedGroups: [],
		})
		await reloadWebWorkers()

		await goToCheckin(page, appointmentId)
		await expect(page.locator('[data-test="select-group-filter"]')).toBeHidden()
	})
})
