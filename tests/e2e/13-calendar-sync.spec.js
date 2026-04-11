import {
	test,
	expect,
	login,
	deleteAllAppointments,
	listAppointmentsViaAPI,
	saveAdminSettings,
	ensureCalendarExists,
	createCalendarEvent,
	updateCalendarEvent,
	deleteCalendarEvent,
	deleteCalendar,
	importCalendarEventsViaAPI,
	toICalDate,
} from './fixtures/nextcloud.js'

const CALENDAR_NAME = 'attendance-e2e-sync'
const CALENDAR_DISPLAY = 'E2E Sync Tests'

/** Build start/end dates for an event N days from now */
function eventDates(daysFromNow, durationHours = 1) {
	const start = new Date()
	start.setDate(start.getDate() + daysFromNow)
	start.setHours(10, 0, 0, 0)
	const end = new Date(start.getTime() + durationHours * 60 * 60 * 1000)
	return { start, end, dtstart: toICalDate(start), dtend: toICalDate(end) }
}

test.describe('Attendance App - Calendar Sync', () => {
	test.beforeAll(async ({ request }) => {
		// Enable calendar sync
		await saveAdminSettings(request, { calendarSync: { enabled: true } })

		// Create test calendar
		await ensureCalendarExists(request, {
			calendarName: CALENDAR_NAME,
			displayName: CALENDAR_DISPLAY,
		})

		await deleteAllAppointments(request)
	})

	test.afterAll(async ({ request }) => {
		await deleteAllAppointments(request)
		await deleteCalendar(request, { calendarName: CALENDAR_NAME })
		await saveAdminSettings(request, { calendarSync: { enabled: false } })
	})

	test.describe('Single event sync', () => {
		const ev = eventDates(20)
		const eventUid = 'e2e-sync-single'

		test.beforeAll(async ({ request }) => {
			await deleteAllAppointments(request)

			// Create CalDAV event
			await createCalendarEvent(request, {
				uid: eventUid,
				summary: 'Sync Single Original',
				description: 'Original description',
				dtstart: ev.dtstart,
				dtend: ev.dtend,
				calendarName: CALENDAR_NAME,
			})

			// Import via bulk API
			await importCalendarEventsViaAPI(request, [{
				name: 'Sync Single Original',
				description: 'Original description',
				startDatetime: ev.start.toISOString(),
				endDatetime: ev.end.toISOString(),
				calendarUri: CALENDAR_NAME,
				calendarEventUid: eventUid,
			}])
		})

		test('update title syncs to appointment', async ({ request }) => {
			// Update event title via CalDAV
			await updateCalendarEvent(request, {
				uid: eventUid,
				summary: 'Sync Single Updated Title',
				description: 'Original description',
				dtstart: ev.dtstart,
				dtend: ev.dtend,
				calendarName: CALENDAR_NAME,
			})

			// Check appointment via API
			const appointments = await listAppointmentsViaAPI(request, { showPast: false })
			const synced = appointments.find(a => a.calendarEventUid === eventUid)
			expect(synced).toBeTruthy()
			expect(synced.name).toBe('Sync Single Updated Title')
		})

		test('update times syncs to appointment', async ({ request }) => {
			// Shift event 2 hours later
			const newStart = new Date(ev.start.getTime() + 2 * 60 * 60 * 1000)
			const newEnd = new Date(ev.end.getTime() + 2 * 60 * 60 * 1000)

			await updateCalendarEvent(request, {
				uid: eventUid,
				summary: 'Sync Single Updated Title',
				description: 'Original description',
				dtstart: toICalDate(newStart),
				dtend: toICalDate(newEnd),
				calendarName: CALENDAR_NAME,
			})

			// Check appointment times
			const appointments = await listAppointmentsViaAPI(request, { showPast: false })
			const synced = appointments.find(a => a.calendarEventUid === eventUid)
			expect(synced).toBeTruthy()

			// Verify the start time changed (compare hours)
			const appointmentStart = new Date(synced.startDatetime)
			expect(appointmentStart.getUTCHours()).toBe(newStart.getUTCHours())
		})

		test('delete calendar event removes single appointment', async ({ page, baseURL, request }) => {
			// Verify appointment exists via API
			let appointments = await listAppointmentsViaAPI(request, { showPast: false })
			expect(appointments.find(a => a.calendarEventUid === eventUid)).toBeTruthy()

			// Verify appointment is visible in the UI
			await login(page, 'admin', 'admin', baseURL)
			await page.goto(`${baseURL}/apps/attendance`)
			await page.waitForLoadState('networkidle')
			await expect(page.locator('[data-test="appointment-card"]', { hasText: 'Sync Single Updated Title' })).toBeVisible()

			// Delete the calendar event
			await deleteCalendarEvent(request, {
				uid: eventUid,
				calendarName: CALENDAR_NAME,
			})

			// Verify appointment is gone via API
			appointments = await listAppointmentsViaAPI(request, { showPast: false })
			expect(appointments.find(a => a.calendarEventUid === eventUid)).toBeUndefined()

			// Verify appointment is gone from the UI
			await page.goto(`${baseURL}/apps/attendance`)
			await page.waitForLoadState('networkidle')
			await expect(page.locator('[data-test="appointment-card"]', { hasText: 'Sync Single Updated Title' })).toHaveCount(0)
		})
	})

	test.describe('Recurring event sync', () => {
		const ev = eventDates(30)
		const eventUid = 'e2e-sync-recurring'

		test.beforeAll(async ({ request }) => {
			await deleteAllAppointments(request)

			// Create recurring CalDAV event (3 weekly occurrences)
			await createCalendarEvent(request, {
				uid: eventUid,
				summary: 'Sync Recurring Original',
				description: 'Recurring description',
				dtstart: ev.dtstart,
				dtend: ev.dtend,
				rrule: 'FREQ=WEEKLY;COUNT=3',
				calendarName: CALENDAR_NAME,
			})

			// Import all 3 occurrences via bulk API
			const occurrences = []
			for (let i = 0; i < 3; i++) {
				const occStart = new Date(ev.start.getTime() + i * 7 * 24 * 60 * 60 * 1000)
				const occEnd = new Date(ev.end.getTime() + i * 7 * 24 * 60 * 60 * 1000)
				occurrences.push({
					name: 'Sync Recurring Original',
					description: 'Recurring description',
					startDatetime: occStart.toISOString(),
					endDatetime: occEnd.toISOString(),
					calendarUri: CALENDAR_NAME,
					calendarEventUid: eventUid,
				})
			}
			await importCalendarEventsViaAPI(request, occurrences)
		})

		test('update master summary syncs to all appointments', async ({ request }) => {
			// Update event summary via CalDAV (master event)
			await updateCalendarEvent(request, {
				uid: eventUid,
				summary: 'Sync Recurring Updated',
				description: 'Recurring description',
				dtstart: ev.dtstart,
				dtend: ev.dtend,
				rrule: 'FREQ=WEEKLY;COUNT=3',
				calendarName: CALENDAR_NAME,
			})

			// All 3 appointments should have the updated name
			const appointments = await listAppointmentsViaAPI(request, { showPast: false })
			const synced = appointments.filter(a => a.calendarEventUid === eventUid)
			expect(synced).toHaveLength(3)

			for (const appt of synced) {
				expect(appt.name).toBe('Sync Recurring Updated')
			}
		})

		test('delete calendar event removes all recurring appointments', async ({ page, baseURL, request }) => {
			// Verify 3 appointments exist via API
			let appointments = await listAppointmentsViaAPI(request, { showPast: false })
			expect(appointments.filter(a => a.calendarEventUid === eventUid)).toHaveLength(3)

			// Verify appointments are visible in the UI
			await login(page, 'admin', 'admin', baseURL)
			await page.goto(`${baseURL}/apps/attendance`)
			await page.waitForLoadState('networkidle')
			await expect(page.locator('[data-test="appointment-card"]', { hasText: 'Sync Recurring Updated' })).toHaveCount(3)

			// Delete the calendar event
			await deleteCalendarEvent(request, {
				uid: eventUid,
				calendarName: CALENDAR_NAME,
			})

			// Verify all appointments are gone via API
			appointments = await listAppointmentsViaAPI(request, { showPast: false })
			expect(appointments.filter(a => a.calendarEventUid === eventUid)).toHaveLength(0)

			// Verify appointments are gone from the UI
			await page.goto(`${baseURL}/apps/attendance`)
			await page.waitForLoadState('networkidle')
			await expect(page.locator('[data-test="appointment-card"]', { hasText: 'Sync Recurring Updated' })).toHaveCount(0)
		})
	})
})
