import { runOcc, getContainer } from '@nextcloud/e2e-test-server'
import {
	test,
	expect,
	createAppointmentViaAPI,
	deleteAllAppointments,
	resetAdminSettings,
	saveAdminSettings,
} from './fixtures/nextcloud.js'

const BASE_URL = process.env.NEXTCLOUD_URL || 'http://localhost:8080'
const API_BASE = `${BASE_URL}/index.php`

const GUEST_EMAIL_A = 'e2e-guest-a@example.com'
const GUEST_EMAIL_B = 'e2e-guest-b@example.com'

function authHeaders(username = 'admin', password = 'admin') {
	return {
		'Authorization': 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64'),
		'Content-Type': 'application/json',
		'OCS-APIREQUEST': 'true',
	}
}

async function deleteUserViaOcc(uid) {
	const container = getContainer()
	try {
		await runOcc(['user:delete', uid], { container })
	} catch {
		// already gone — fine
	}
}

/**
 * Guests-app integration tests.
 *
 * The default e2e snapshot does not include the Guests app, so this suite
 * enables it once in `beforeAll`. The Guests app stays enabled for the rest
 * of the run; the next snapshot restore (start of next run) wipes it.
 */
test.describe('Attendance App - Guests integration', () => {
	test.beforeAll(async ({ request }) => {
		const container = getContainer()
		const list = await runOcc(['app:list', '--output', 'json'], { container })
		const apps = JSON.parse(list.substring(list.indexOf('{')))
		if (!('guests' in apps.enabled)) {
			if ('guests' in apps.disabled) {
				await runOcc(['app:enable', '--force', 'guests'], { container })
			} else {
				await runOcc(['app:install', '--force', 'guests'], { container })
			}
		}
		// PHP-FPM opcache may serve the previous app state for a beat after
		// `app:enable`. Poll the capabilities endpoint until `guestInvitation`
		// flips to true so the first test sees a consistent server view.
		for (let attempt = 0; attempt < 10; attempt++) {
			const resp = await request.get(`${API_BASE}/apps/attendance/api/capabilities`, {
				headers: authHeaders(),
			})
			if (resp.ok()) {
				const caps = await resp.json()
				if (caps.guestInvitation === true) return
			}
			await new Promise(r => setTimeout(r, 500))
		}
		throw new Error('guestInvitation capability did not become true after enabling Guests app')
	})

	test.afterAll(async ({ request }) => {
		await deleteUserViaOcc(GUEST_EMAIL_A)
		await deleteUserViaOcc(GUEST_EMAIL_B)
		await deleteAllAppointments(request)
		await resetAdminSettings(request)
	})

	test('exposes guestInvitation capability when Guests app is enabled', async ({ request }) => {
		const resp = await request.get(`${API_BASE}/apps/attendance/api/capabilities`, {
			headers: authHeaders(),
		})
		expect(resp.ok()).toBeTruthy()
		const caps = await resp.json()
		expect(caps.guestInvitation).toBe(true)
	})

	test('admin settings reports Guests app status with attendance missing from whitelist', async ({ request }) => {
		const resp = await request.get(`${API_BASE}/apps/attendance/api/admin/settings`, {
			headers: authHeaders(),
		})
		expect(resp.ok()).toBeTruthy()
		const data = await resp.json()
		expect(data.config.guestsApp).toBeDefined()
		expect(data.config.guestsApp.enabled).toBe(true)
		// The Guests app's default whitelist mode is enabled but does not
		// include `attendance`, so the admin warning fires.
		expect(data.config.guestsApp.whitelistEnabled).toBe(true)
		expect(data.config.guestsApp.attendanceInWhitelist).toBe(false)
	})

	test('POST /api/guests creates a new guest account', async ({ request }) => {
		await deleteUserViaOcc(GUEST_EMAIL_A)

		const resp = await request.post(`${API_BASE}/apps/attendance/api/guests`, {
			headers: authHeaders(),
			data: { email: GUEST_EMAIL_A, displayName: 'E2E Guest A' },
		})
		expect(resp.status()).toBe(200)

		const body = await resp.json()
		expect(body.userId).toBe(GUEST_EMAIL_A)
		expect(body.displayName).toBe('E2E Guest A')
		expect(body.email).toBe(GUEST_EMAIL_A)
		expect(body.isGuest).toBe(true)
		expect(body.alreadyExisted).toBe(false)
	})

	test('POST /api/guests is idempotent for an existing email', async ({ request }) => {
		// Re-use the guest from the previous test (or create freshly here too)
		await request.post(`${API_BASE}/apps/attendance/api/guests`, {
			headers: authHeaders(),
			data: { email: GUEST_EMAIL_A, displayName: 'First Display Name' },
		})

		const resp = await request.post(`${API_BASE}/apps/attendance/api/guests`, {
			headers: authHeaders(),
			data: { email: GUEST_EMAIL_A, displayName: 'Ignored On Repeat' },
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body.userId).toBe(GUEST_EMAIL_A)
		expect(body.alreadyExisted).toBe(true)
	})

	test('POST /api/guests rejects invalid email', async ({ request }) => {
		const resp = await request.post(`${API_BASE}/apps/attendance/api/guests`, {
			headers: authHeaders(),
			data: { email: 'not-an-email' },
		})
		expect(resp.status()).toBe(400)
		const body = await resp.json()
		expect(body.error).toMatch(/email/i)
	})

	test('search-users-groups-teams marks the guest with isGuest=true', async ({ request }) => {
		await request.post(`${API_BASE}/apps/attendance/api/guests`, {
			headers: authHeaders(),
			data: { email: GUEST_EMAIL_A },
		})

		const resp = await request.get(
			`${API_BASE}/apps/attendance/api/search/users-groups-teams?search=${encodeURIComponent(GUEST_EMAIL_A)}`,
			{ headers: authHeaders() },
		)
		expect(resp.ok()).toBeTruthy()
		const results = await resp.json()
		const match = results.find(r => r.type === 'user' && r.id === GUEST_EMAIL_A)
		expect(match).toBeDefined()
		expect(match.isGuest).toBe(true)
	})

	test('invited guest appears in others.non_responding_users with isGuest=true', async ({ request }) => {
		await request.post(`${API_BASE}/apps/attendance/api/guests`, {
			headers: authHeaders(),
			data: { email: GUEST_EMAIL_B, displayName: 'E2E Guest B' },
		})

		const appointment = await createAppointmentViaAPI(request, {
			name: 'Guest Visibility Test',
			daysFromNow: 3,
			visibleUsers: [GUEST_EMAIL_B],
		})
		expect(appointment.id).toBeDefined()

		const resp = await request.get(
			`${API_BASE}/apps/attendance/api/appointments/${appointment.id}`,
			{ headers: authHeaders() },
		)
		expect(resp.ok()).toBeTruthy()
		const data = await resp.json()
		const others = data.responseSummary?.others
		expect(others).toBeDefined()
		expect(others.no_response).toBeGreaterThanOrEqual(1)
		const guestEntry = others.non_responding_users.find(u => u.userId === GUEST_EMAIL_B)
		expect(guestEntry).toBeDefined()
		expect(guestEntry.isGuest).toBe(true)
		expect(guestEntry.displayName).toBe('E2E Guest B')

		// `guest_app` itself must NOT show as its own group section, since the
		// admin has not whitelisted it explicitly.
		const groupKeys = Object.keys(data.responseSummary.by_group || {})
		expect(groupKeys).not.toContain('guest_app')
	})

	test('admin settings UI surfaces the Guests app whitelist warning', async ({ page, loginAsUser }) => {
		await loginAsUser('admin', 'admin')
		await page.goto('/settings/admin/attendance')
		await page.waitForLoadState('networkidle')

		const warningSection = page.locator('[data-test="section-guests-warning"]')
		await expect(warningSection).toBeVisible()
		const warningCard = page.locator('[data-test="guests-whitelist-warning"]')
		await expect(warningCard).toBeVisible()
		// occ snippet is rendered as a copyable code block.
		await expect(page.locator('[data-test="input-guests-occ"]')).toContainText('config:app:set guests whitelist')
	})

	test('appointment editor shows the "Enter an email address" hint when capability is on', async ({ page, loginAsUser }) => {
		await loginAsUser('admin', 'admin')
		await page.goto('/apps/attendance/create')
		await page.waitForLoadState('networkidle')

		await expect(page.getByText(/email address.*invite a guest/i)).toBeVisible()
	})

	test('guest_app appears as its own section once whitelisted explicitly', async ({ request }) => {
		await request.post(`${API_BASE}/apps/attendance/api/guests`, {
			headers: authHeaders(),
			data: { email: GUEST_EMAIL_B, displayName: 'E2E Guest B' },
		})

		// Opt the system group in: now it should render like any other tracked group.
		await saveAdminSettings(request, {
			whitelistedGroups: ['guest_app'],
			whitelistedTeams: [],
			permissions: {
				manage_appointments: [],
				checkin: [],
				see_response_overview: [],
				see_comments: [],
			},
			reminders: { enabled: false },
		})

		try {
			const appointment = await createAppointmentViaAPI(request, {
				name: 'Guest Whitelist Opt-In Test',
				daysFromNow: 4,
				visibleUsers: [GUEST_EMAIL_B],
			})

			const resp = await request.get(
				`${API_BASE}/apps/attendance/api/appointments/${appointment.id}`,
				{ headers: authHeaders() },
			)
			expect(resp.ok()).toBeTruthy()
			const data = await resp.json()

			const guestGroupSection = data.responseSummary?.by_group?.guest_app
			expect(guestGroupSection).toBeDefined()
			const inGroup = guestGroupSection.non_responding_users?.find(u => u.userId === GUEST_EMAIL_B)
			expect(inGroup).toBeDefined()
			expect(inGroup.isGuest).toBe(true)

			// Conversely, the guest must no longer appear in the Others bucket.
			const inOthers = data.responseSummary.others?.non_responding_users?.find(u => u.userId === GUEST_EMAIL_B)
			expect(inOthers).toBeUndefined()
		} finally {
			// Roll the whitelist change back so other tests start from defaults.
			await resetAdminSettings(request)
		}
	})
})
