import { test as base, expect } from '@playwright/test'
import { existsSync, mkdirSync, readFileSync } from 'fs'
import { execSync } from 'node:child_process'
import { dirname, join } from 'path'
import { fileURLToPath } from 'url'
import { CONTAINER_NAME } from '../setup/container.js'

const __dirname = dirname(fileURLToPath(import.meta.url))
const AUTH_DIR = join(__dirname, '..', '.auth')
export const BASE_URL = process.env.NEXTCLOUD_URL || 'http://localhost:8080'
// Nextcloud app API POST requests need /index.php to avoid redirect issues.
// OCS provisioning API may or may not need /index.php depending on mod_rewrite config.
const API_BASE = `${BASE_URL}/index.php`

/**
 * Resilient JSON parser with retry — Nextcloud sometimes returns an HTML
 * error page (<!DOCTYPE …) under heavy parallel load instead of JSON.
 * When that happens we wait briefly and replay the request.
 */
async function resilientJson(responseFn, { retries = 3, delay = 1000 } = {}) {
	for (let attempt = 1; attempt <= retries; attempt++) {
		const resp = await responseFn()
		const ct = resp.headers()['content-type'] || ''
		if (ct.includes('application/json') || ct.includes('text/json')) {
			return resp
		}
		// Non-JSON (likely HTML error page) — retry unless last attempt.
		if (attempt < retries) {
			await new Promise((r) => setTimeout(r, delay * attempt))
		} else {
			return resp // let the caller deal with the failure
		}
	}
}

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
export function authHeaders(username = 'admin', password = 'admin') {
	return {
		Authorization: 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64'),
		'Content-Type': 'application/json',
		'OCS-APIREQUEST': 'true',
		Cookie: '',
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
				const probe = await page.request.get(`${baseURL}/apps/dashboard/`, { maxRedirects: 0 })
				if (probe.status() === 200) {
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
 *
 * @param {Date} [opts.responseDeadline] Optional deadline; the cron auto-closes
 *        the inquiry once it passes.
 * @returns {Promise<Object>} The created appointment data (includes id)
 */
export async function createAppointmentViaAPI(request, {
	name,
	description = '',
	daysFromNow = 2,
	durationHours = 1,
	visibleUsers = [],
	visibleGroups = [],
	organizers = [],
	sendNotification = false,
	responseDeadline,
	location,
	categoryId,
	username = 'admin',
	password = 'admin',
} = {}) {
	const now = new Date()
	const startDate = new Date(now.getTime() + daysFromNow * 24 * 60 * 60 * 1000)
	const endDate = new Date(startDate.getTime() + durationHours * 60 * 60 * 1000)

	const data = {
		name,
		description,
		startDatetime: startDate.toISOString(),
		endDatetime: endDate.toISOString(),
		visibleUsers,
		visibleGroups,
		sendNotification,
		organizers,
		...(responseDeadline ? { responseDeadline: responseDeadline.toISOString() } : {}),
		...(location !== undefined ? { location } : {}),
		...(categoryId !== undefined ? { categoryId } : {}),
	}
	const resp = await resilientJson(() => request.post(`${API_BASE}/apps/attendance/api/appointments`, {
		headers: authHeaders(username, password),
		data,
	}))
	return resp.json()
}

/**
 * Delete a single appointment via the REST API
 */
export async function deleteAppointmentViaAPI(request, id, { username = 'admin', password = 'admin' } = {}) {
	await resilientJson(() => request.delete(`${API_BASE}/apps/attendance/api/appointments/${id}`, {
		headers: authHeaders(username, password),
	}))
}

/**
 * Fetch appointments via the REST API.
 *
 * @param {object} opts
 * @param {boolean} [opts.showPast]
 * @param {boolean} [opts.unansweredOnly] Server-side filter: drop closed
 *        inquiries and any appointment the user has already answered.
 */
export async function listAppointmentsViaAPI(request, { showPast = true, unansweredOnly = false, notScheduledOut = false, username = 'admin', password = 'admin' } = {}) {
	const params = new URLSearchParams({
		showPastAppointments: String(showPast),
	})
	if (unansweredOnly) params.set('unansweredOnly', 'true')
	if (notScheduledOut) params.set('notScheduledOut', 'true')
	const resp = await resilientJson(() => request.get(
		`${API_BASE}/apps/attendance/api/appointments?${params.toString()}`,
		{ headers: authHeaders(username, password) },
	))
	return resp.json()
}

/**
 * Close an appointment inquiry. Returns the updated appointment payload.
 */
export async function closeAppointmentViaAPI(request, id, { username = 'admin', password = 'admin' } = {}) {
	const resp = await resilientJson(() => request.post(`${API_BASE}/apps/attendance/api/appointments/${id}/close`, {
		headers: authHeaders(username, password),
	}))
	return { status: resp.status(), body: await resp.json() }
}

/**
 * Re-open a previously closed appointment inquiry.
 */
export async function reopenAppointmentViaAPI(request, id, { username = 'admin', password = 'admin' } = {}) {
	const resp = await resilientJson(() => request.post(`${API_BASE}/apps/attendance/api/appointments/${id}/reopen`, {
		headers: authHeaders(username, password),
	}))
	return { status: resp.status(), body: await resp.json() }
}

async function collectAppointmentIds(request, partitions, auth) {
	const ids = new Set()
	for (const showPast of partitions) {
		const list = await listAppointmentsViaAPI(request, { showPast, ...auth })
		if (!Array.isArray(list)) continue
		for (const appt of list) ids.add(appt.id)
	}
	return ids
}

/**
 * Delete all appointments (useful for afterAll cleanup).
 *
 * Walks only the past partition. Parallel tests share the test server, so
 * deleting upcoming appointments here would wipe rows other workers are still
 * using. Sequential-admin specs that need a known-empty state should use
 * forceWipeAllAppointments() below instead.
 */
export async function deleteAllAppointments(request, { username = 'admin', password = 'admin' } = {}) {
	const ids = await collectAppointmentIds(request, [true], { username, password })
	for (const id of ids) {
		await deleteAppointmentViaAPI(request, id, { username, password })
	}
}

/**
 * Hard cleanup — deletes both past and upcoming appointments. Only safe in
 * sequential-admin tests (workers=1, runs after the parallel project). Retries
 * because workers' afterAll inserts can race with the list call.
 */
export async function forceWipeAllAppointments(request, { username = 'admin', password = 'admin' } = {}) {
	for (let attempt = 0; attempt < 5; attempt++) {
		const ids = await collectAppointmentIds(request, [true, false], { username, password })
		if (ids.size === 0) return
		for (const id of ids) {
			await deleteAppointmentViaAPI(request, id, { username, password })
		}
		await new Promise((r) => setTimeout(r, 200))
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
	const resp = await resilientJson(() => request.post(
		`${API_BASE}/apps/attendance/api/appointments/${appointmentId}/respond`,
		{
			headers: authHeaders(username, password),
			data: { response: vote, comment },
		},
	))
	return resp.json()
}

/**
 * Cancel an appointment via the REST API (event will not take place)
 */
export async function cancelAppointmentViaAPI(request, id, { username = 'admin', password = 'admin' } = {}) {
	const resp = await resilientJson(() => request.post(`${API_BASE}/apps/attendance/api/appointments/${id}/cancel`, {
		headers: authHeaders(username, password),
	}))
	return resp.json()
}

/**
 * Update an appointment via the REST API
 */
export async function updateAppointmentViaAPI(request, id, {
	name,
	description = '',
	startDatetime,
	endDatetime,
	username = 'admin',
	password = 'admin',
} = {}) {
	const resp = await resilientJson(() => request.put(`${API_BASE}/apps/attendance/api/appointments/${id}`, {
		headers: authHeaders(username, password),
		data: { name, description, startDatetime, endDatetime },
	}))
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
	const resp = await resilientJson(() => request.post(
		`${API_BASE}/apps/attendance/api/appointments/${appointmentId}/checkin/${targetUserId}`,
		{
			headers: authHeaders(username, password),
			data: { response, comment },
		},
	))
	return resp.json()
}

/**
 * Set or clear a response on behalf of another user via the REST API
 * (issue #47). Pass response: null to clear the target's answer.
 */
export async function respondForUserViaAPI(request, appointmentId, targetUserId, {
	response = 'yes',
	username = 'admin',
	password = 'admin',
} = {}) {
	const resp = await resilientJson(() => request.post(
		`${API_BASE}/apps/attendance/api/appointments/${appointmentId}/respond/${targetUserId}`,
		{
			headers: authHeaders(username, password),
			data: { response },
		},
	))
	return resp.json()
}

/**
 * Save admin settings via the REST API
 */
export async function saveAdminSettings(request, settings = {}) {
	const resp = await resilientJson(() => request.post(`${API_BASE}/apps/attendance/api/admin/settings`, {
		headers: authHeaders('admin', 'admin'),
		data: settings,
	}))
	return resp.json()
}

/**
 * Create a category via the admin REST API. Returns the created category
 * ({ id, name, icon }).
 */
export async function createCategoryViaAPI(request, name, { icon = 'tag', username = 'admin', password = 'admin' } = {}) {
	const resp = await resilientJson(() => request.post(`${API_BASE}/apps/attendance/api/admin/categories`, {
		headers: authHeaders(username, password),
		data: { name, icon },
	}))
	return resp.json()
}

/**
 * Delete a category via the admin REST API.
 */
export async function deleteCategoryViaAPI(request, id, { username = 'admin', password = 'admin' } = {}) {
	await resilientJson(() => request.delete(`${API_BASE}/apps/attendance/api/admin/categories/${id}`, {
		headers: authHeaders(username, password),
	}))
}

/**
 * List categories via the REST API (available to any logged-in user).
 */
export async function listCategoriesViaAPI(request, { username = 'admin', password = 'admin' } = {}) {
	const resp = await resilientJson(() => request.get(`${API_BASE}/apps/attendance/api/categories`, {
		headers: authHeaders(username, password),
	}))
	return resp.json()
}

/**
 * Trigger the organization calendar backfill via the admin API.
 * Returns { status, body }.
 */
export async function syncOrgCalendarViaAPI(request) {
	const resp = await resilientJson(() => request.post(`${API_BASE}/apps/attendance/api/admin/org-calendar/sync`, {
		headers: authHeaders('admin', 'admin'),
	}))
	return { status: resp.status(), body: await resp.json() }
}

/**
 * Every permission key at the default of a fresh install: open to all users,
 * except the additive ones (create own / respond for others), which grant
 * nobody. Specs that restrict permissions should spread this and override
 * only the keys they are actually about.
 */
export const PERMISSIVE_PERMISSIONS = Object.freeze({
	manage_appointments: { mode: 'all', groups: [] },
	checkin: { mode: 'all', groups: [] },
	see_response_overview: { mode: 'all', groups: [] },
	see_response_counts: { mode: 'all', groups: [] },
	see_comments: { mode: 'all', groups: [] },
	self_checkin: { mode: 'all', groups: [] },
	create_appointments: { mode: 'nobody', groups: [] },
	respond_for_others: { mode: 'nobody', groups: [] },
	see_statistics: { mode: 'nobody', groups: [] },
})

/**
 * Reset admin settings to the defaults of a fresh install
 */
export async function resetAdminSettings(request) {
	return saveAdminSettings(request, {
		whitelistedGroups: [],
		whitelistedTeams: [],
		permissions: { ...PERMISSIVE_PERMISSIONS },
		reminders: { enabled: false, days_before: 1, frequency_days: 1 },
	})
}

/**
 * Force every Apache worker to reload the app config. APCu (the local
 * memcache) is per-worker, so a permission change saved through one worker
 * is not seen by its siblings until they restart — a graceful restart makes
 * permission-sensitive assertions deterministic.
 */
export async function reloadWebWorkers() {
	execSync(
		`docker exec ${CONTAINER_NAME} apachectl graceful`,
		{ stdio: 'pipe' },
	)
	// Give Apache a moment to cycle workers.
	await new Promise((resolve) => setTimeout(resolve, 1000))
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
				Authorization: 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64'),
				'Content-Type': 'text/plain',
			},
			data: content,
		},
	)
	return response.status() === 201 || response.status() === 204
}

// ---------------------------------------------------------------------------
// CalDAV helpers for calendar import/sync tests
// ---------------------------------------------------------------------------

/**
 * Ensure a calendar exists via MKCALENDAR. No-op if it already exists.
 */
export async function ensureCalendarExists(request, { calendarName = 'personal', displayName, username = 'admin', password = 'admin' } = {}) {
	const body = `<?xml version="1.0" encoding="UTF-8"?>
<C:mkcalendar xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
  <D:set>
    <D:prop>
      <D:displayname>${displayName || calendarName}</D:displayname>
      <C:supported-calendar-component-set>
        <C:comp name="VEVENT"/>
      </C:supported-calendar-component-set>
    </D:prop>
  </D:set>
</C:mkcalendar>`

	const resp = await request.fetch(
		`${BASE_URL}/remote.php/dav/calendars/${username}/${calendarName}`,
		{
			method: 'MKCALENDAR',
			headers: {
				...authHeaders(username, password),
				'Content-Type': 'application/xml; charset=utf-8',
			},
			data: body,
		},
	)
	// 201 = created, 405/409 = already exists
	return resp.status() === 201
}

/**
 * Create a calendar event via CalDAV PUT
 * @param {Object} options
 * @param {string} options.uid - Unique event identifier
 * @param {string} options.summary - Event title
 * @param {string} [options.description] - Event description
 * @param {string} options.dtstart - Start time in iCal format (e.g., '20260415T100000Z')
 * @param {string} options.dtend - End time in iCal format
 * @param {string} [options.rrule] - Recurrence rule (e.g., 'FREQ=WEEKLY;COUNT=3')
 * @param {string} [options.calendarName] - Calendar URI (default: 'personal')
 * @returns {Promise<boolean>} True if created/updated successfully
 */
export async function createCalendarEvent(request, {
	uid,
	summary,
	description = '',
	dtstart,
	dtend,
	rrule = null,
	calendarName = 'personal',
	username = 'admin',
	password = 'admin',
} = {}) {
	const lines = [
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'PRODID:-//Attendance E2E Tests//EN',
		'BEGIN:VEVENT',
		`UID:${uid}`,
		`DTSTAMP:${dtstart}`,
		`SUMMARY:${summary}`,
	]
	if (description) lines.push(`DESCRIPTION:${description}`)
	lines.push(`DTSTART:${dtstart}`)
	lines.push(`DTEND:${dtend}`)
	if (rrule) lines.push(`RRULE:${rrule}`)
	lines.push('END:VEVENT', 'END:VCALENDAR')

	const resp = await request.put(
		`${BASE_URL}/remote.php/dav/calendars/${username}/${calendarName}/${uid}.ics`,
		{
			headers: {
				...authHeaders(username, password),
				'Content-Type': 'text/calendar; charset=utf-8',
			},
			data: lines.join('\r\n'),
		},
	)
	return resp.status() === 201 || resp.status() === 204
}

/**
 * Update a calendar event via CalDAV PUT (same function, different name for clarity)
 */
export const updateCalendarEvent = createCalendarEvent

/**
 * Delete a calendar event via CalDAV DELETE
 */
export async function deleteCalendarEvent(request, { uid, calendarName = 'personal', username = 'admin', password = 'admin' } = {}) {
	const resp = await request.delete(
		`${BASE_URL}/remote.php/dav/calendars/${username}/${calendarName}/${uid}.ics`,
		{ headers: authHeaders(username, password) },
	)
	return resp.status() === 204 || resp.status() === 404
}

/**
 * Fetch a calendar object's raw ICS via CalDAV GET.
 * Returns null when the object does not exist (404).
 *
 * @param {string} options.objectUri Full object filename, e.g. 'my-uid.ics'
 */
export async function getCalendarEventIcs(request, { objectUri, calendarName = 'personal', username = 'admin', password = 'admin' } = {}) {
	const resp = await request.get(
		`${BASE_URL}/remote.php/dav/calendars/${username}/${calendarName}/${objectUri}`,
		{ headers: authHeaders(username, password) },
	)
	if (resp.status() !== 200) return null
	return resp.text()
}

/**
 * Delete an entire calendar via CalDAV DELETE
 */
export async function deleteCalendar(request, { calendarName, username = 'admin', password = 'admin' } = {}) {
	const resp = await request.delete(
		`${BASE_URL}/remote.php/dav/calendars/${username}/${calendarName}`,
		{ headers: authHeaders(username, password) },
	)
	return resp.status() === 204 || resp.status() === 404
}

/**
 * Import calendar events as attendance appointments via bulk API
 * @param {Array} events - Array of { name, startDatetime, endDatetime, calendarUri, calendarEventUid }
 * @returns {Promise<Object>} API response
 */
export async function importCalendarEventsViaAPI(request, events, { username = 'admin', password = 'admin' } = {}) {
	const resp = await request.post(`${API_BASE}/apps/attendance/api/appointments/bulk`, {
		headers: authHeaders(username, password),
		data: { appointments: events },
	})
	return resp.json()
}

/**
 * Format a JS Date to iCal UTC format (YYYYMMDDTHHMMSSZ)
 */
export function toICalDate(date) {
	return date.toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '')
}

/**
 * Resolve once a response POST has actually come back.
 *
 * Answering and commenting share one endpoint. Its UI signals (a disabled
 * button, a spinner) flip synchronously when the request *starts*, so a test
 * that reloads on them tears down the request it is trying to verify. Start
 * this before the click and await it after.
 */
export function waitForRespond(page) {
	return page.waitForResponse((response) => /\/appointments\/\d+\/respond$/.test(new URL(response.url()).pathname)
		&& response.request().method() === 'POST'
		&& response.ok())
}

/**
 * Wait for the admin settings auto-save POST. The settings page has no save
 * button — arm this before the change and await it after.
 */
export function waitForSettingsSave(page) {
	return page.waitForResponse((response) => response.url().includes('/api/admin/settings')
		&& response.request().method() === 'POST'
		&& response.ok())
}

/**
 * On the admin settings page: switch a permission row to "Specific groups"
 * and pick a group. Resolves once the auto-save POST went through.
 */
export async function restrictPermissionToGroup(page, permission, groupName) {
	const row = page.locator(`[data-test="permission-${permission}"]`)
	await expect(row).toBeVisible()
	await row.getByText('Specific groups', { exact: true }).click()

	const select = page.locator(`[data-test="permission-${permission}-groups"]`)
	await expect(select).toBeVisible()
	await select.getByRole('combobox').click()
	const option = page.getByRole('option', { name: groupName })
	await option.waitFor({ state: 'visible' })

	const saved = waitForSettingsSave(page)
	await option.click()
	await saved
}

/**
 * Click a comment toggle that a background refresh may pull out from under us.
 *
 * Answering reloads the appointment list, so the button that was just resolved
 * can be detached mid-click. Playwright retries a detached element on its own,
 * but not a click that already began, so retry the whole action.
 */
export async function openCommentField(toggle) {
	await expect(toggle).toBeVisible({ timeout: 5000 })
	await expect(async () => {
		await toggle.click({ timeout: 2000 })
	}).toPass({ timeout: 15000 })
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

export { expect }
