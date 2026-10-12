import {
	addUserToTeamViaOCS,
	createAppointmentViaAPI,
	createTeamViaOCS,
	deleteTeamViaOCS,
	expect,
	forceWipeAllAppointments,
	PERMISSIVE_PERMISSIONS,
	reloadWebWorkers,
	resetAdminSettings,
	saveAdminSettings,
	teamsAvailable,
	test,
} from './fixtures/nextcloud.js'

const APPOINTMENT_OFFSET_DAYS = -7
// The appointment sits a week back — in January that is already last year.
const YEAR = new Date(Date.now() + APPOINTMENT_OFFSET_DAYS * 24 * 60 * 60 * 1000).getFullYear()

/**
 * @param {import('@playwright/test').APIRequestContext} request - Playwright request context.
 * @param {string[]} whitelistedTeams - Teams the statistics may section by.
 */
async function selectTeamsForGrouping(request, whitelistedTeams) {
	await saveAdminSettings(request, {
		whitelistedGroups: [],
		whitelistedTeams,
		responseSummaryTeamsMode: whitelistedTeams.length > 0 ? 'specific' : 'none',
		permissions: {
			...PERMISSIVE_PERMISSIONS,
			see_statistics: { mode: 'all', groups: [] },
		},
	})
	await reloadWebWorkers()
}

/**
 * "Group by: Teams" in the statistics. Without any team selected for grouping
 * — the default — it used to look like the switch did nothing, so the page now
 * says why. Changes global permission state, so it lives in the
 * sequential-admin project.
 */
test.describe('Attendance App - Statistics grouped by teams (sequential)', () => {
	test.beforeAll(async ({ request }) => {
		await resetAdminSettings(request)
		await reloadWebWorkers()
		await forceWipeAllAppointments(request)
		await createAppointmentViaAPI(request, { name: 'Statistics teams', daysFromNow: APPOINTMENT_OFFSET_DAYS })
	})

	test.afterAll(async ({ request }) => {
		await forceWipeAllAppointments(request)
		await resetAdminSettings(request)
		await reloadWebWorkers()
	})

	test('explains why everyone is under Others while no team is selected', async ({ page, request, loginAsUser }) => {
		await selectTeamsForGrouping(request, [])
		await loginAsUser('admin', 'admin')

		await page.goto(`/apps/attendance/statistics?period=${YEAR}&groupBy=teams`)
		await page.waitForLoadState('networkidle')

		await expect(page.locator('[data-test="statistics-teams-hint"]')).toBeVisible()
		await expect(page.locator('[data-test^="statistics-section-"]')).toHaveCount(1)
		await expect(page.locator('[data-test="statistics-section-__others__"]')).toBeVisible()
	})

	test('says nothing when grouping by groups', async ({ page, request, loginAsUser }) => {
		await selectTeamsForGrouping(request, [])
		await loginAsUser('admin', 'admin')

		await page.goto(`/apps/attendance/statistics?period=${YEAR}`)
		await page.waitForLoadState('networkidle')

		await expect(page.locator('[data-test="statistics-person-row"]').first()).toBeVisible()
		await expect(page.locator('[data-test="statistics-teams-hint"]')).toHaveCount(0)
	})

	test.describe('with a team selected for grouping', () => {
		const teamName = `Statistics team ${Math.random().toString(36).slice(2, 8)}`
		let teamId = null

		test.beforeAll(async ({ request }) => {
			test.skip(!(await teamsAvailable(request)), 'The Teams app is not enabled on this server')

			teamId = await createTeamViaOCS(request, teamName)
			await addUserToTeamViaOCS(request, teamId, 'test3')
			await selectTeamsForGrouping(request, [teamId])
		})

		test.afterAll(async ({ request }) => {
			if (teamId !== null) {
				await deleteTeamViaOCS(request, teamId)
			}
		})

		test('lists the team as a section with its members and the rest under Others', async ({ page, loginAsUser }) => {
			await loginAsUser('admin', 'admin')

			await page.goto(`/apps/attendance/statistics?period=${YEAR}&groupBy=teams`)
			await page.waitForLoadState('networkidle')

			const teamSection = page.locator(`[data-test="statistics-section-${teamId}"]`)
			await expect(teamSection).toContainText(teamName)

			const teamBody = page.locator('tbody', { has: teamSection })
			await expect(teamBody.getByRole('rowheader', { name: 'test3', exact: true })).toBeVisible()
			await expect(teamBody.getByRole('rowheader', { name: 'test', exact: true })).toHaveCount(0)

			// Somebody outside the team still has to land somewhere, and nothing
			// is wrong any more, so there is no hint.
			await expect(page.locator('[data-test="statistics-section-__others__"]')).toBeVisible()
			await expect(page.locator('[data-test="statistics-teams-hint"]')).toHaveCount(0)
		})
	})
})
