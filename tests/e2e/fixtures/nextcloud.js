import { test as base } from '@playwright/test'
import { existsSync, mkdirSync, readFileSync } from 'fs'
import { dirname, join } from 'path'
import { fileURLToPath } from 'url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const AUTH_DIR = join(__dirname, '..', '.auth')
const BASE_URL = process.env.NEXTCLOUD_URL || 'http://localhost:8080'
// Nextcloud app API POST requests need /index.php to avoid redirect issues.
// OCS provisioning API may or may not need /index.php depending on mod_rewrite config.
const API_BASE = `${BASE_URL}/index.php`

/**
 * Detect the correct OCS API base URL.
 * Installations with mod_rewrite use /ocs/v2.php directly,
 * installations without mod_rewrite need /index.php/ocs/v2.php.
 */
let _ocsBase = null
async function getOcsBase(request) {
	if (_ocsBase !== null) return _ocsBase
	// Try without /index.php first (mod_rewrite enabled)
	const resp = await request.get(`${BASE_URL}/ocs/v2.php/cloud/capabilities?format=json`, {
		headers: authHeaders('admin', 'admin'),
	})
	if (resp.ok()) {
		_ocsBase = `${BASE_URL}/ocs/v2.php`
	} else {
		_ocsBase = `${BASE_URL}/index.php/ocs/v2.php`
	}
	return _ocsBase
}

/**
 * Get the path to the auth state file for a user
 */
function getAuthStatePath(username) {
	return join(AUTH_DIR, `${username}.json`)
}

/**
 * Ensure the auth directory exists
 */
function ensureAuthDir() {
	if (!existsSync(AUTH_DIR)) {
		mkdirSync(AUTH_DIR, { recursive: true })
	}
}

/**
 * Build Basic Auth headers for API calls
 */
function authHeaders(username = 'admin', password = 'admin') {
	return {
		'Authorization': 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64'),
		'Content-Type': 'application/json',
		'OCS-APIREQUEST': 'true',
	}
}

/**
 * Standalone login helper for use in beforeAll hooks and other contexts
 * where fixtures are not available.
 */
export async function login(page, username, password = null, baseURL = BASE_URL) {
	const pwd = password ?? username

	ensureAuthDir()
	const authStatePath = getAuthStatePath(username)

	// Try to restore cached auth state
	if (existsSync(authStatePath)) {
		try {
			const stateData = JSON.parse(readFileSync(authStatePath, 'utf-8'))
			if (stateData.cookies && stateData.cookies.length > 0) {
				await page.context().addCookies(stateData.cookies)
				await page.goto(`${baseURL}/apps/dashboard/`)
				const currentUrl = page.url()
				if (!currentUrl.includes('/login')) {
					return
				}
			}
		} catch {
			// Failed to restore state, fall through to fresh login
		}
	}

	await page.context().clearCookies()
	await page.goto(`${baseURL}/login`)
	await page.waitForLoadState('networkidle')

	await page.getByRole('textbox', { name: /account name|email/i }).fill(username)
	await page.getByRole('textbox', { name: /password/i }).fill(pwd)
	await page.getByRole('button', { name: 'Log in', exact: true }).click()
	await page.waitForURL(/.*\/apps\/.*/, { timeout: 10000 })

	try {
		await page.context().storageState({ path: authStatePath })
	} catch {
		// Ignore save errors - caching is optional optimization
	}
}

// ---------------------------------------------------------------------------
// API helpers for test data setup/teardown (used in beforeAll/afterAll hooks)
// ---------------------------------------------------------------------------

/**
 * Create an appointment via the REST API
 * @returns {Promise<Object>} The created appointment data (includes id)
 */
export async function createAppointmentViaAPI(request, {
	name,
	description = '',
	daysFromNow = 2,
	durationHours = 1,
	visibleUsers = [],
	visibleGroups = [],
	sendNotification = false,
	username = 'admin',
	password = 'admin',
} = {}) {
	const now = new Date()
	const startDate = new Date(now.getTime() + daysFromNow * 24 * 60 * 60 * 1000)
	const endDate = new Date(startDate.getTime() + durationHours * 60 * 60 * 1000)

	const response = await request.post(`${API_BASE}/apps/attendance/api/appointments`, {
		headers: authHeaders(username, password),
		data: {
			name,
			description,
			startDatetime: startDate.toISOString(),
			endDatetime: endDate.toISOString(),
			visibleUsers,
			visibleGroups,
			sendNotification,
		},
	})
	return response.json()
}

/**
 * Delete a single appointment via the REST API
 */
export async function deleteAppointmentViaAPI(request, id, { username = 'admin', password = 'admin' } = {}) {
	await request.delete(`${API_BASE}/apps/attendance/api/appointments/${id}`, {
		headers: authHeaders(username, password),
	})
}

/**
 * Fetch all appointments via the REST API
 */
export async function listAppointmentsViaAPI(request, { showPast = true, username = 'admin', password = 'admin' } = {}) {
	const response = await request.get(
		`${API_BASE}/apps/attendance/api/appointments?showPastAppointments=${showPast}`,
		{ headers: authHeaders(username, password) },
	)
	return response.json()
}

/**
 * Delete all appointments (useful for afterAll cleanup)
 */
export async function deleteAllAppointments(request, { username = 'admin', password = 'admin' } = {}) {
	const appointments = await listAppointmentsViaAPI(request, { showPast: true, username, password })
	if (!Array.isArray(appointments)) return
	for (const appt of appointments) {
		await deleteAppointmentViaAPI(request, appt.id, { username, password })
	}
}

/**
 * Submit a response (yes/no/maybe) to an appointment via the REST API
 */
export async function respondToAppointmentViaAPI(request, appointmentId, {
	response: vote,
	comment = '',
	username = 'admin',
	password = 'admin',
} = {}) {
	const resp = await request.post(
		`${API_BASE}/apps/attendance/api/appointments/${appointmentId}/respond`,
		{
			headers: authHeaders(username, password),
			data: { response: vote, comment },
		},
	)
	return resp.json()
}

/**
 * Set check-in status for a user via the REST API
 */
export async function checkinUserViaAPI(request, appointmentId, targetUserId, {
	response = 'yes',
	comment = '',
	username = 'admin',
	password = 'admin',
} = {}) {
	const resp = await request.post(
		`${API_BASE}/apps/attendance/api/appointments/${appointmentId}/checkin/${targetUserId}`,
		{
			headers: authHeaders(username, password),
			data: { response, comment },
		},
	)
	return resp.json()
}

/**
 * Save admin settings via the REST API
 */
export async function saveAdminSettings(request, settings = {}) {
	const resp = await request.post(`${API_BASE}/apps/attendance/api/admin/settings`, {
		headers: authHeaders('admin', 'admin'),
		data: settings,
	})
	return resp.json()
}

/**
 * Reset admin settings to permissive defaults
 */
export async function resetAdminSettings(request) {
	return saveAdminSettings(request, {
		whitelistedGroups: [],
		whitelistedTeams: [],
		permissions: {
			manage_appointments: [],
			checkin: [],
			see_response_overview: [],
			see_comments: [],
		},
		reminders: { enabled: false, days_before: 1, frequency_days: 1 },
	})
}

/**
 * Create a Nextcloud group via OCS provisioning API
 */
export async function createGroupViaOCS(request, groupName) {
	const ocsBase = await getOcsBase(request)
	await request.post(`${ocsBase}/cloud/groups?format=json`, {
		headers: {
			...authHeaders('admin', 'admin'),
			'Content-Type': 'application/x-www-form-urlencoded',
		},
		form: { groupid: groupName },
	})
}

/**
 * Add a user to a group via OCS provisioning API
 */
export async function addUserToGroupViaOCS(request, username, groupName) {
	const ocsBase = await getOcsBase(request)
	await request.post(`${ocsBase}/cloud/users/${username}/groups?format=json`, {
		headers: {
			...authHeaders('admin', 'admin'),
			'Content-Type': 'application/x-www-form-urlencoded',
		},
		form: { groupid: groupName },
	})
}

/**
 * Create a file via WebDAV (for attachment tests)
 */
export async function createFileViaWebDAV(request, { filename, content = 'Test content', username = 'admin', password = 'admin' } = {}) {
	const response = await request.put(
		`${API_BASE}/remote.php/dav/files/${username}/${filename}`,
		{
			headers: {
				'Authorization': 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64'),
				'Content-Type': 'text/plain',
			},
			data: content,
		},
	)
	return response.status() === 201 || response.status() === 204
}

// ---------------------------------------------------------------------------
// Playwright test fixtures
// ---------------------------------------------------------------------------

export const test = base.extend({
	loginAsUser: async ({ page, baseURL }, use) => {
		const loginFn = async (username, password = null) => {
			await login(page, username, password, baseURL)
		}
		await use(loginFn)
	},

	attendanceApp: async ({ page, baseURL }, use) => {
		const navigateToApp = async () => {
			await page.goto(`${baseURL}/apps/attendance`)
			await page.waitForLoadState('networkidle')
		}
		await use(navigateToApp)
	},

	adminPage: async ({ browser, baseURL }, use) => {
		const context = await browser.newContext()
		const page = await context.newPage()
		await login(page, 'admin', 'admin', baseURL)
		await use(page)
		await context.close()
	},
})

export { expect } from '@playwright/test'
