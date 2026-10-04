import {
	authHeaders,
	BASE_URL,
	deleteCalendar,
	ensureCalendarExists,
	expect,
	getCalendarEventIcs,
	login,
	saveAdminSettings,
	test,
} from './fixtures/nextcloud.js'

const CALENDAR_NAME = 'attendance-e2e-vacation'
const USER = 'test4'
const API = `${BASE_URL}/index.php/apps/attendance/api`

function dateOnly(daysFromNow) {
	const date = new Date()
	date.setDate(date.getDate() + daysFromNow)
	const pad = (n) => String(n).padStart(2, '0')
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

const eventUri = (id) => `attendance-vacation-${id}@${new URL(BASE_URL).hostname}.ics`

async function fetchIcs(request, id) {
	const ics = await getCalendarEventIcs(request, { objectUri: eventUri(id), calendarName: CALENDAR_NAME })
	return ics === null ? null : ics.replace(/\r?\n[ \t]/g, '')
}

// Every member's vacation is written into the calendar the admin picked: whole
// days, the member's name in the title and the note as description.
test.describe('Vacation calendar', () => {
	test.describe.configure({ mode: 'serial' })

	const start = dateOnly(70)
	const end = dateOnly(72)
	let vacationId

	test.beforeAll(async ({ request }) => {
		await ensureCalendarExists(request, { calendarName: CALENDAR_NAME, displayName: 'E2E Vacation' })
		await saveAdminSettings(request, { vacationCalendar: { enabled: true, calendarUri: CALENDAR_NAME } })
	})

	test.afterAll(async ({ request }) => {
		if (vacationId) {
			await request.delete(`${API}/vacations/${vacationId}`, { headers: authHeaders(USER, USER) })
		}
		await saveAdminSettings(request, { vacationCalendar: { enabled: false } })
		await deleteCalendar(request, { calendarName: CALENDAR_NAME })
	})

	test('a new vacation becomes a whole-day event with its note', async ({ request }) => {
		const created = await request.post(`${API}/vacations`, {
			headers: authHeaders(USER, USER),
			data: { startDate: start, endDate: end, note: 'Skiing, with family' },
		})
		expect(created.status()).toBe(201)
		vacationId = (await created.json()).id

		const ics = await fetchIcs(request, vacationId)
		expect(ics).not.toBeNull()
		expect(ics).toContain(`DTSTART;VALUE=DATE:${start.replaceAll('-', '')}`)
		// A whole-day end is exclusive: the day after the last day.
		const dayAfter = dateOnly(73).replaceAll('-', '')
		expect(ics).toContain(`DTEND;VALUE=DATE:${dayAfter}`)
		expect(ics).toContain('DESCRIPTION:Skiing\\, with family')
		expect(ics).toContain('TRANSP:TRANSPARENT')
	})

	test('changing the vacation updates the event', async ({ request }) => {
		const laterEnd = dateOnly(75)
		const updated = await request.put(`${API}/vacations/${vacationId}`, {
			headers: authHeaders(USER, USER),
			data: { startDate: start, endDate: laterEnd, note: '' },
		})
		expect(updated.status()).toBe(200)

		const ics = await fetchIcs(request, vacationId)
		expect(ics).toContain(`DTEND;VALUE=DATE:${dateOnly(76).replaceAll('-', '')}`)
		expect(ics).not.toContain('DESCRIPTION')
	})

	test('the manual sync writes events that were missing', async ({ request }) => {
		const synced = await request.post(`${API}/admin/vacation-calendar/sync`, { headers: authHeaders() })
		expect(synced.status()).toBe(200)
		expect((await synced.json()).synced).toBeGreaterThanOrEqual(1)
		expect(await fetchIcs(request, vacationId)).not.toBeNull()
	})

	test('deleting the vacation removes the event', async ({ request }) => {
		const id = vacationId
		const deleted = await request.delete(`${API}/vacations/${id}`, { headers: authHeaders(USER, USER) })
		expect(deleted.status()).toBe(200)
		vacationId = null

		expect(await fetchIcs(request, id)).toBeNull()
	})

	test('the admin settings warn about privacy and show the chosen calendar', async ({ page, baseURL }) => {
		await login(page, 'admin', 'admin', baseURL)
		await page.goto('/settings/admin/attendance')
		await page.waitForLoadState('networkidle')

		const section = page.locator('#vacation-calendar')
		await expect(section.locator('[data-test="vacation-calendar-privacy"]')).toContainText('Privacy')
		await expect(section.locator('[data-test="switch-vacation-calendar-enabled"]')).toBeChecked()
		await expect(section.locator('[data-test="select-vacation-calendar"]')).toContainText('E2E Vacation')
	})
})
