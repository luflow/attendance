import { execSync } from 'node:child_process'
import {
	test,
	expect,
	createAppointmentViaAPI,
	closeAppointmentViaAPI,
	reopenAppointmentViaAPI,
	deleteAllAppointments,
	resetAdminSettings,
} from './fixtures/nextcloud.js'

// Set permission_manage_appointments via the saveSettings HTTP endpoint with
// curl. We have to use the same web SAPI that serves the actual reopen call —
// occ runs in CLI mode and APCu lives in a separate process there, so a CLI
// write would not invalidate the web process's cached app-config and the
// permission check would still see "everyone is manager".
function setManageAppointmentsRoles(roles) {
	const body = JSON.stringify({
		whitelistedGroups: [],
		whitelistedTeams: [],
		permissions: {
			manage_appointments: roles,
			checkin: [],
			see_response_overview: [],
			see_comments: [],
		},
		reminders: { enabled: false },
	})
	execSync(
		`curl -fsS -u admin:admin -H 'OCS-APIREQUEST: true' -H 'Content-Type: application/json' -X POST -d ${JSON.stringify(body)} 'http://localhost:8080/index.php/apps/attendance/api/admin/settings'`,
		{ stdio: 'pipe' },
	)
	// APCu (the local memcache) is per-Apache-worker — a graceful restart
	// forces all workers to reload, so the next request sees the fresh config.
	execSync(
		'docker exec nextcloud-e2e-test-server_attendance apachectl graceful',
		{ stdio: 'pipe' },
	)
	// Give Apache a moment to cycle workers.
	execSync('sleep 1', { stdio: 'pipe' })
}

// Mirrors the storage key in src/views/AllAppointments.vue.
const FILTER_STORAGE_KEY = 'attendance:list-filters'

/**
 * These tests change global admin state (the manage_appointments permission
 * is restricted to the `admin` group) so they live in the sequential-admin
 * project, not the parallel one. Without that restriction every user is
 * implicitly a manager and the negative-case assertions cannot fire.
 */
test.describe('Attendance App - Close inquiry permissions (sequential)', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(() => {
		setManageAppointmentsRoles(['admin'])
	})

	test.afterAll(async ({ request }) => {
		await deleteAllAppointments(request)
		await resetAdminSettings(request)
	})

	test('non-manager non-creator cannot reopen via API (403)', async ({ request }) => {
		// Re-assert the permission right before the call so a flaky beforeAll
		// or sibling test cannot mask the negative case.
		setManageAppointmentsRoles(['admin'])
		const checkPerm = execSync(
			`docker exec -u www-data nextcloud-e2e-test-server_attendance php occ config:app:get attendance permission_manage_appointments`,
			{ stdio: 'pipe' },
		).toString().trim()
		console.log('[debug] permission_manage_appointments =', checkPerm)

		const apt = await createAppointmentViaAPI(request, {
			name: 'API Reopen Permission Test',
			daysFromNow: 14,
		})
		await closeAppointmentViaAPI(request, apt.id)

		const denied = await reopenAppointmentViaAPI(request, apt.id, { username: 'test', password: 'test' })
		console.log('[debug] denied response:', JSON.stringify(denied))
		expect(denied.status).toBe(403)
		expect(denied.body.error).toMatch(/permission/i)

		// Sanity check: admin can still reopen — the appointment was not damaged.
		const reopened = await reopenAppointmentViaAPI(request, apt.id)
		expect(reopened.status).toBe(200)
		expect(reopened.body.closedAt).toBeNull()
	})

	test('non-manager sees minimal closed-info and no Reopen button', async ({ page, request, loginAsUser, attendanceApp }) => {
		const inquiry = await createAppointmentViaAPI(request, {
			name: 'Non-Manager Closed View',
			daysFromNow: 11,
		})
		await closeAppointmentViaAPI(request, inquiry.id)

		await loginAsUser('test', 'test')
		await attendanceApp()
		await page.evaluate((key) => window.localStorage.removeItem(key), FILTER_STORAGE_KEY)
		await page.reload()
		await page.waitForLoadState('networkidle')

		const card = page.locator('[data-test="appointment-card"]', { hasText: 'Non-Manager Closed View' }).first()
		await expect(card).toBeVisible()

		// Minimal closed-info renders inside the read-only response section.
		await expect(card.locator('[data-test="response-section-readonly"]')).toBeVisible()
		await expect(card.locator('[data-test="closed-info"]')).toBeVisible()
		await expect(card.locator('[data-test="closed-info"]')).toContainText('Closed')

		// Banner + Reopen button are reserved for managers/creators.
		await expect(card.locator('[data-test="closed-banner"]')).toHaveCount(0)
		await expect(card.locator('[data-test="banner-reopen-inquiry"]')).toHaveCount(0)
		await expect(card.locator('[data-test="response-yes"]')).toHaveCount(0)
	})
})
