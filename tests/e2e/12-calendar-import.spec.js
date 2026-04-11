import {
	test,
	expect,
	deleteAllAppointments,
	ensureCalendarExists,
	createCalendarEvent,
	deleteCalendar,
	toICalDate,
} from './fixtures/nextcloud.js'

const CALENDAR_NAME = 'attendance-e2e-import'
const CALENDAR_DISPLAY = 'E2E Import Tests'

/** Build start/end dates for an event N days from now */
function eventDates(daysFromNow, durationHours = 1) {
	const start = new Date()
	start.setDate(start.getDate() + daysFromNow)
	start.setHours(10, 0, 0, 0)
	const end = new Date(start.getTime() + durationHours * 60 * 60 * 1000)
	return { start, end, dtstart: toICalDate(start), dtend: toICalDate(end) }
}

const singleEvent = eventDates(7)
const recurringEvent = eventDates(14)

/** Create test calendar and events (called before each test since snapshot restore may clear CalDAV) */
async function setupCalendarEvents(request) {
	await ensureCalendarExists(request, {
		calendarName: CALENDAR_NAME,
		displayName: CALENDAR_DISPLAY,
	})
	await createCalendarEvent(request, {
		uid: 'e2e-single-event',
		summary: 'E2E Single Meeting',
		description: 'A single test event',
		dtstart: singleEvent.dtstart,
		dtend: singleEvent.dtend,
		calendarName: CALENDAR_NAME,
	})
	await createCalendarEvent(request, {
		uid: 'e2e-recurring-event',
		summary: 'E2E Weekly Standup',
		description: 'A recurring test event',
		dtstart: recurringEvent.dtstart,
		dtend: recurringEvent.dtend,
		rrule: 'FREQ=WEEKLY;COUNT=3',
		calendarName: CALENDAR_NAME,
	})
}

/** Navigate to create form and open the calendar import picker */
async function openCalendarPicker(page) {
	await page.getByRole('link', { name: 'Create Appointment' }).click()
	await page.waitForURL(/.*\/create$/)
	await page.waitForLoadState('networkidle')
	await page.locator('[data-test="button-import-calendar"]').click()
	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible()
	return dialog
}

/** Select a calendar in the picker dialog */
async function selectCalendar(page, dialog, displayName) {
	await dialog.locator('.calendar-item', { hasText: displayName }).click()
	await page.waitForLoadState('networkidle')
}

test.describe('Attendance App - Calendar Import', () => {
	test.beforeEach(async ({ page, loginAsUser, attendanceApp, request }) => {
		await deleteAllAppointments(request)
		await setupCalendarEvents(request)
		await loginAsUser('admin', 'admin')
		await attendanceApp()
		await page.waitForLoadState('networkidle')
	})

	test.afterAll(async ({ request }) => {
		await deleteAllAppointments(request)
		await deleteCalendar(request, { calendarName: CALENDAR_NAME })
	})

	test('calendar picker shows test calendar', async ({ page }) => {
		const dialog = await openCalendarPicker(page)
		await expect(dialog.getByText(CALENDAR_DISPLAY)).toBeVisible()
	})

	test('single event appears and can be imported', async ({ page }) => {
		const dialog = await openCalendarPicker(page)
		await selectCalendar(page, dialog, CALENDAR_DISPLAY)

		// Single event should be visible
		await expect(dialog.getByText('E2E Single Meeting')).toBeVisible()

		// Click the event row (not the inner text, as the li intercepts pointer events)
		const eventItem = dialog.locator('.event-item', { hasText: 'E2E Single Meeting' })
		await eventItem.click()
		await dialog.getByRole('button', { name: /import 1 event/i }).click()

		// Dialog should close, form should be populated
		await expect(dialog).not.toBeVisible()
		const nameInput = page.getByRole('textbox', { name: 'Appointment Name' })
		await expect(nameInput).toHaveValue('E2E Single Meeting')
	})

	test('recurring event shows all occurrences', async ({ page }) => {
		const dialog = await openCalendarPicker(page)
		await selectCalendar(page, dialog, CALENDAR_DISPLAY)

		// All 3 occurrences should appear
		const recurringItems = dialog.locator('.event-item', { hasText: 'E2E Weekly Standup' })
		await expect(recurringItems).toHaveCount(3)

		// Each occurrence should have a different date shown
		const dates = []
		for (let i = 0; i < 3; i++) {
			const dateText = await recurringItems.nth(i).locator('.event-date').textContent()
			dates.push(dateText)
		}
		const uniqueDates = new Set(dates)
		expect(uniqueDates.size).toBe(3)
	})

	test('recurring occurrences can be individually selected', async ({ page }) => {
		const dialog = await openCalendarPicker(page)
		await selectCalendar(page, dialog, CALENDAR_DISPLAY)

		const recurringItems = dialog.locator('.event-item', { hasText: 'E2E Weekly Standup' })
		await expect(recurringItems).toHaveCount(3)

		// Select first occurrence
		await recurringItems.nth(0).click()
		await expect(dialog.getByRole('button', { name: /import 1 event/i })).toBeVisible()

		// Select second occurrence too
		await recurringItems.nth(1).click()
		await expect(dialog.getByRole('button', { name: /import 2 events/i })).toBeVisible()

		// Deselect first
		await recurringItems.nth(0).click()
		await expect(dialog.getByRole('button', { name: /import 1 event/i })).toBeVisible()
	})

	test('bulk import of recurring occurrences creates multiple appointments', async ({ page }) => {
		const dialog = await openCalendarPicker(page)
		await selectCalendar(page, dialog, CALENDAR_DISPLAY)

		// Select all events
		await dialog.getByRole('button', { name: 'Select all', exact: true }).click()

		// Should show 4 total (1 single + 3 recurring)
		await expect(dialog.getByRole('button', { name: /import 4 events/i })).toBeVisible()

		// Import all
		await dialog.getByRole('button', { name: /import 4 events/i }).click()

		// Wait for import to complete and navigate back
		await page.waitForURL(/.*\/apps\/attendance(?!\/(create|edit|copy))/)
		await page.waitForLoadState('networkidle')

		// Verify all appointments were created
		const singleCards = page.locator('[data-test="appointment-card"]', { hasText: 'E2E Single Meeting' })
		await expect(singleCards).toHaveCount(1)

		const recurringCards = page.locator('[data-test="appointment-card"]', { hasText: 'E2E Weekly Standup' })
		await expect(recurringCards).toHaveCount(3)
	})
})
