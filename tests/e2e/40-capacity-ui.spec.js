import {
	authHeaders,
	BASE_URL,
	createAppointmentViaAPI,
	deleteAllAppointments,
	expect,
	forceWipeAllAppointments,
	test,
} from './fixtures/nextcloud.js'

const API = `${BASE_URL}/index.php/apps/attendance/api`

async function answerYes(request, id, user, acceptWaitlist = false) {
	return request.post(`${API}/appointments/${id}/respond`, {
		headers: authHeaders(user, user),
		data: { response: 'yes', comment: '', acceptWaitlist },
	})
}

// What a person sees of the attendance limit, the waitlist and an appointment
// that drops "Maybe" — the API side lives in 33-attendance-limit.
test.describe('Attendance limit and answer options in the UI', () => {
	test.describe.configure({ mode: 'serial' })

	let withWaitlist
	let noWaitlist
	let noMaybe

	test.beforeAll(async ({ request }) => {
		await deleteAllAppointments(request)
		withWaitlist = await createAppointmentViaAPI(request, {
			name: 'UI Waitlist',
			visibleUsers: ['test', 'test1'],
			maxAttendees: 1,
			waitlistEnabled: true,
		})
		noWaitlist = await createAppointmentViaAPI(request, {
			name: 'UI Full',
			visibleUsers: ['test', 'test1'],
			maxAttendees: 1,
			waitlistEnabled: false,
		})
		noMaybe = await createAppointmentViaAPI(request, {
			name: 'UI No Maybe',
			visibleUsers: ['test1'],
			allowMaybe: false,
		})
		await answerYes(request, withWaitlist.id, 'test')
		await answerYes(request, noWaitlist.id, 'test')
	})

	test.afterAll(async ({ request }) => {
		await forceWipeAllAppointments(request)
	})

	test('the form offers the limit, the waitlist and the answer options', async ({ page, loginAsUser }) => {
		await loginAsUser('admin', 'admin')
		await page.goto(`/apps/attendance/edit/${noWaitlist.id}`)

		await expect(page.getByRole('heading', { name: 'Attendance limit & waitlist' })).toBeVisible()
		await expect(page.locator('[data-test="input-max-attendees"]')).toHaveValue('1')
		await expect(page.getByRole('checkbox', { name: 'Offer a waitlist when full' })).not.toBeChecked()

		await page.goto(`/apps/attendance/edit/${noMaybe.id}`)
		await expect(page.getByRole('heading', { name: 'Answer options' })).toBeVisible()
		await expect(page.getByRole('checkbox', { name: /Offer .Maybe. as an answer/ })).not.toBeChecked()
	})

	test('somebody joining the waitlist reads "On the waitlist", not "Yes"', async ({ page, loginAsUser }) => {
		await loginAsUser('test1', 'test1')
		await page.goto(`/apps/attendance/appointment/${withWaitlist.id}`)

		await page.locator('[data-test="response-yes"]').click()
		await expect(page.locator('[data-test="response-yes"]')).toHaveText('On the waitlist')
		await expect(page.locator('[data-test="response-section"]')).toContainText('You are number 1 on the waitlist')
	})

	test('the response summary marks who is waiting', async ({ page, loginAsUser }) => {
		await loginAsUser('admin', 'admin')
		await page.goto(`/apps/attendance/appointment/${withWaitlist.id}`)

		await page.locator('[data-test="response-summary"]').getByText('Others').click()
		await expect(page.locator('[data-test="waitlisted-test1"]')).toHaveText('Waitlist')
		await expect(page.locator('[data-test="waitlisted-test"]')).toHaveCount(0)
	})

	test('a full appointment without a waitlist offers no answer, only the reason', async ({ page, loginAsUser }) => {
		await loginAsUser('test1', 'test1')
		await page.goto(`/apps/attendance/appointment/${noWaitlist.id}`)

		await expect(page.locator('[data-test="response-section"]')).toContainText('No spots available at the moment')
		await expect(page.locator('[data-test="response-yes"]')).toHaveCount(0)
		await expect(page.locator('[data-test="response-no"]')).toHaveCount(0)
	})

	test('an appointment without "Maybe" offers only yes and no', async ({ page, loginAsUser }) => {
		await loginAsUser('test1', 'test1')
		await page.goto(`/apps/attendance/appointment/${noMaybe.id}`)

		await expect(page.locator('[data-test="response-yes"]')).toBeVisible()
		await expect(page.locator('[data-test="response-no"]')).toBeVisible()
		await expect(page.locator('[data-test="response-maybe"]')).toHaveCount(0)
	})
})
