import {
	BASE_URL,
	authHeaders,
	deleteCalendar,
	ensureCalendarExists,
	expect,
	forceWipeAllAppointments,
	getCalendarEventIcs,
	listAppointmentsViaAPI,
	saveAdminSettings,
	test,
	updateAppointmentViaAPI,
} from './fixtures/nextcloud.js'

const CALENDAR_NAME = 'attendance-e2e-all-day'
const NAME = 'All Day Outing'

// Two whole days, far enough ahead to never be "today" in any zone.
const firstDay = new Date()
firstDay.setHours(0, 0, 0, 0)
firstDay.setDate(firstDay.getDate() + 20)
const lastDay = new Date(firstDay)
lastDay.setDate(lastDay.getDate() + 1)
const dayAfter = new Date(lastDay)
dayAfter.setDate(dayAfter.getDate() + 1)

const pad = (n) => String(n).padStart(2, '0')
const ymd = (date, sep = '-') => [date.getFullYear(), pad(date.getMonth() + 1), pad(date.getDate())].join(sep)

async function findAppointment(request) {
	const appointments = await listAppointmentsViaAPI(request, { showPast: false })
	return appointments.find((a) => a.name === NAME)
}

test.describe('Attendance App - All-day appointments', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ request }) => {
		await ensureCalendarExists(request, { calendarName: CALENDAR_NAME, displayName: 'E2E All Day' })
		await forceWipeAllAppointments(request)
		await saveAdminSettings(request, {
			orgCalendar: { enabled: true, calendarUri: CALENDAR_NAME },
		})
	})

	test.afterAll(async ({ request }) => {
		await saveAdminSettings(request, { orgCalendar: { enabled: false } })
		await forceWipeAllAppointments(request)
		await deleteCalendar(request, { calendarName: CALENDAR_NAME })
	})

	test('the form creates an appointment of whole days', async ({ page, request, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()

		await page.getByRole('button', { name: 'Create Appointment' }).click()
		await page.waitForURL(/.*\/create$/)
		await page.getByRole('textbox', { name: 'Appointment Name' }).fill(NAME)

		await page.getByText('All day', { exact: true }).click()
		await expect(page.locator('[data-test="checkbox-all-day"]')).toBeChecked()
		const start = page.locator('#start-datetime')
		const end = page.locator('#end-datetime')
		await expect(start).toHaveAttribute('type', 'date')

		await start.fill(ymd(firstDay))
		// A day ends with itself until told otherwise
		await expect(end).toHaveValue(ymd(firstDay))
		await end.fill(ymd(lastDay))

		await page.getByRole('button', { name: 'Save' }).click()
		await page.waitForURL(/.*\/apps\/attendance(?!\/(create|edit|copy))/)

		const appointment = await findAppointment(request)
		expect(appointment.isAllDay).toBe(true)
		// Midnight to the midnight after the last day
		expect(new Date(appointment.startDatetime).getTime()).toBe(firstDay.getTime())
		expect(new Date(appointment.endDatetime).getTime()).toBe(dayAfter.getTime())

		const card = page.locator('[data-test="appointment-card"]', { hasText: NAME })
		await expect(card).toBeVisible()
		await expect(card.locator('.appointment-header')).not.toContainText(/\d{1,2}:\d{2}/)
	})

	test('the organization calendar gets a whole-day event', async ({ request }) => {
		const appointment = await findAppointment(request)
		const ics = await getCalendarEventIcs(request, {
			objectUri: `${appointment.calendarEventUid}.ics`,
			calendarName: CALENDAR_NAME,
		})

		expect(ics).toContain(`DTSTART;VALUE=DATE:${ymd(firstDay, '')}`)
		expect(ics).toContain(`DTEND;VALUE=DATE:${ymd(dayAfter, '')}`)
	})

	test('the calendar feed carries whole days', async ({ request }) => {
		const token = await request.get(`${BASE_URL}/index.php/apps/attendance/api/ical/token`, { headers: authHeaders() })
		const { feedUrl } = await token.json()
		const feed = await (await request.get(feedUrl)).text()

		expect(feed).toContain(`DTSTART;VALUE=DATE:${ymd(firstDay, '')}`)
		expect(feed).toContain(`DTEND;VALUE=DATE:${ymd(dayAfter, '')}`)
	})

	test('the edit form opens on the days', async ({ page, request, loginAsUser }) => {
		const appointment = await findAppointment(request)
		await loginAsUser('admin', 'admin')
		await page.goto(`/apps/attendance/edit/${appointment.id}`)

		await expect(page.locator('[data-test="checkbox-all-day"]')).toBeChecked()
		await expect(page.locator('#start-datetime')).toHaveValue(ymd(firstDay))
		await expect(page.locator('#end-datetime')).toHaveValue(ymd(lastDay))
	})

	test('a client unaware of the flag keeps it only by leaving the times alone', async ({ request }) => {
		const appointment = await findAppointment(request)

		const renamed = await updateAppointmentViaAPI(request, appointment.id, {
			name: NAME,
			description: 'Bring boots',
			startDatetime: appointment.startDatetime,
			endDatetime: appointment.endDatetime,
		})
		expect(renamed.isAllDay).toBe(true)

		const moved = await updateAppointmentViaAPI(request, appointment.id, {
			name: NAME,
			startDatetime: new Date(firstDay.getTime() + 10 * 60 * 60 * 1000).toISOString(),
			endDatetime: new Date(firstDay.getTime() + 12 * 60 * 60 * 1000).toISOString(),
		})
		expect(moved.isAllDay).toBe(false)
	})
})
