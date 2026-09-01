import {
	cancelAppointmentViaAPI,
	createAppointmentViaAPI,
	deleteAppointmentViaAPI,
	deleteCalendar,
	ensureCalendarExists,
	expect,
	forceWipeAllAppointments,
	getCalendarEventIcs,
	listAppointmentsViaAPI,
	login,
	respondToAppointmentViaAPI,
	saveAdminSettings,
	syncOrgCalendarViaAPI,
	test,
	toICalDate,
	updateAppointmentViaAPI,
	updateCalendarEvent,
} from './fixtures/nextcloud.js'

const CALENDAR_NAME = 'attendance-e2e-org'
const CALENDAR_DISPLAY = 'E2E Org Calendar'

/** Object URI of the pushed event, derived from the appointment API payload */
function objectUriOf(appointment) {
	return `${appointment.calendarEventUid}.ics`
}

async function fetchOrgEventIcs(request, appointment) {
	const ics = await getCalendarEventIcs(request, {
		objectUri: objectUriOf(appointment),
		calendarName: CALENDAR_NAME,
	})
	// Unfold RFC 5545 continuation lines so assertions cannot be split by folding
	return ics === null ? null : ics.replace(/\r?\n[ \t]/g, '')
}

test.describe('Attendance App - Organization calendar', () => {
	test.beforeAll(async ({ request }) => {
		await ensureCalendarExists(request, {
			calendarName: CALENDAR_NAME,
			displayName: CALENDAR_DISPLAY,
		})
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

	test.describe('Admin settings UI', () => {
		test('org calendar section shows toggle and calendar picker', async ({ page, baseURL }) => {
			await login(page, 'admin', 'admin', baseURL)
			await page.goto('/settings/admin/attendance')
			await page.waitForLoadState('networkidle')

			const toggle = page.locator('[data-test="switch-org-calendar-enabled"]')
			await expect(toggle).toBeVisible()
			await expect(toggle).toBeChecked()

			// The configured calendar is selected in the picker
			const picker = page.locator('[data-test="select-org-calendar"]')
			await expect(picker).toBeVisible()
			await expect(picker).toContainText(CALENDAR_DISPLAY)

			// Manual backfill button is offered for the selected calendar
			await expect(page.locator('[data-test="button-sync-org-calendar"]')).toBeVisible()
		})
	})

	test.describe('App to calendar push', () => {
		test('creating an appointment creates a calendar event', async ({ request }) => {
			const appointment = await createAppointmentViaAPI(request, {
				name: 'Org Push Create',
				description: 'Rehearsal in the club house',
				daysFromNow: 15,
			})
			expect(appointment.calendarEventUid).toMatch(/^attendance-org-\d+@/)

			const ics = await fetchOrgEventIcs(request, appointment)
			expect(ics).toBeTruthy()
			expect(ics).toContain('SUMMARY:Org Push Create')
			expect(ics).toContain('DESCRIPTION:Rehearsal in the club house')
			expect(ics).toContain('STATUS:CONFIRMED')

			await deleteAppointmentViaAPI(request, appointment.id)
		})

		test('updating an appointment updates the calendar event', async ({ request }) => {
			const appointment = await createAppointmentViaAPI(request, {
				name: 'Org Push Before Update',
				daysFromNow: 16,
			})

			const start = new Date(Date.now() + 16 * 24 * 60 * 60 * 1000)
			const end = new Date(start.getTime() + 60 * 60 * 1000)
			await updateAppointmentViaAPI(request, appointment.id, {
				name: 'Org Push After Update',
				description: 'Now with description',
				startDatetime: start.toISOString(),
				endDatetime: end.toISOString(),
			})

			const ics = await fetchOrgEventIcs(request, appointment)
			expect(ics).toBeTruthy()
			expect(ics).toContain('SUMMARY:Org Push After Update')
			expect(ics).not.toContain('SUMMARY:Org Push Before Update')

			await deleteAppointmentViaAPI(request, appointment.id)
		})

		test('responses add a summary block to the event description', async ({ request }) => {
			const appointment = await createAppointmentViaAPI(request, {
				name: 'Org Push Summary',
				description: 'Who is coming?',
				daysFromNow: 17,
			})

			await respondToAppointmentViaAPI(request, appointment.id, { response: 'yes' })

			const ics = await fetchOrgEventIcs(request, appointment)
			expect(ics).toBeTruthy()
			expect(ics).toContain('--- Attendance ---')
			expect(ics).toContain('1 attending')
			// The plain description is still there
			expect(ics).toContain('Who is coming?')

			await deleteAppointmentViaAPI(request, appointment.id)
		})

		test('cancelling an appointment marks the event as cancelled', async ({ request }) => {
			const appointment = await createAppointmentViaAPI(request, {
				name: 'Org Push Cancel',
				daysFromNow: 18,
			})

			await cancelAppointmentViaAPI(request, appointment.id)

			const ics = await fetchOrgEventIcs(request, appointment)
			expect(ics).toBeTruthy()
			expect(ics).toContain('STATUS:CANCELLED')

			await deleteAppointmentViaAPI(request, appointment.id)
		})

		test('deleting an appointment removes the calendar event', async ({ request }) => {
			const appointment = await createAppointmentViaAPI(request, {
				name: 'Org Push Delete',
				daysFromNow: 19,
			})
			expect(await fetchOrgEventIcs(request, appointment)).toBeTruthy()

			await deleteAppointmentViaAPI(request, appointment.id)

			expect(await fetchOrgEventIcs(request, appointment)).toBeNull()
		})

		test('manual sync endpoint pushes upcoming appointments', async ({ request }) => {
			const appointment = await createAppointmentViaAPI(request, {
				name: 'Org Manual Sync',
				daysFromNow: 22,
			})

			const { status, body } = await syncOrgCalendarViaAPI(request)
			expect(status).toBe(200)
			expect(body.synced).toBeGreaterThanOrEqual(1)

			expect(await fetchOrgEventIcs(request, appointment)).toContain('SUMMARY:Org Manual Sync')

			await deleteAppointmentViaAPI(request, appointment.id)
		})

		test('enabling the feature backfills existing upcoming appointments', async ({ request }) => {
			// Disable, create an appointment (no push), re-enable (backfill)
			await saveAdminSettings(request, { orgCalendar: { enabled: false } })

			const appointment = await createAppointmentViaAPI(request, {
				name: 'Org Backfill',
				daysFromNow: 20,
			})
			expect(appointment.calendarEventUid).toBeFalsy()

			await saveAdminSettings(request, {
				orgCalendar: { enabled: true, calendarUri: CALENDAR_NAME },
			})

			// The backfill linked the appointment and created the event
			const appointments = await listAppointmentsViaAPI(request, { showPast: false })
			const backfilled = appointments.find((a) => a.id === appointment.id)
			expect(backfilled.calendarEventUid).toMatch(/^attendance-org-\d+@/)

			const ics = await fetchOrgEventIcs(request, backfilled)
			expect(ics).toBeTruthy()
			expect(ics).toContain('SUMMARY:Org Backfill')

			await deleteAppointmentViaAPI(request, appointment.id)
		})
	})

	test.describe('Calendar to app sync of pushed events', () => {
		test('calendar edit syncs back without leaking the summary block', async ({ request }) => {
			await saveAdminSettings(request, { calendarSync: { enabled: true } })

			const appointment = await createAppointmentViaAPI(request, {
				name: 'Org Roundtrip',
				description: 'Original text',
				daysFromNow: 21,
			})
			await respondToAppointmentViaAPI(request, appointment.id, { response: 'yes' })

			// Edit the pushed event in the calendar: new title, and a description
			// that still contains the app-generated summary block (as it would
			// after editing the text above the marker in the Calendar app)
			const start = new Date(Date.now() + 21 * 24 * 60 * 60 * 1000)
			start.setHours(10, 0, 0, 0)
			const end = new Date(start.getTime() + 60 * 60 * 1000)
			await updateCalendarEvent(request, {
				uid: appointment.calendarEventUid,
				summary: 'Org Roundtrip Edited',
				description: 'Edited text\\n\\n--- Attendance ---\\n1 attending\\, 0 declined\\, 0 maybe',
				dtstart: toICalDate(start),
				dtend: toICalDate(end),
				calendarName: CALENDAR_NAME,
			})

			const appointments = await listAppointmentsViaAPI(request, { showPast: false })
			const synced = appointments.find((a) => a.id === appointment.id)
			expect(synced.name).toBe('Org Roundtrip Edited')
			// The summary block was stripped before writing back
			expect(synced.description).toBe('Edited text')

			await deleteAppointmentViaAPI(request, appointment.id)
			await saveAdminSettings(request, { calendarSync: { enabled: false } })
		})
	})
})
