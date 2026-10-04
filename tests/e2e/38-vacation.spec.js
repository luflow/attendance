import {
	authHeaders,
	BASE_URL,
	createAppointmentViaAPI,
	deleteAppointmentViaAPI,
	expect,
	listAppointmentsViaAPI,
	PERMISSIVE_PERMISSIONS,
	saveAdminSettings,
	test,
} from './fixtures/nextcloud.js'

// Nobody else logs in as this user: a recorded vacation answers "No" for its
// owner on appointments created meanwhile, which must not reach other files.
const USER = 'test5'
const AUTH = { username: USER, password: USER }
const API = `${BASE_URL}/index.php/apps/attendance/api`
const SETTINGS_URL = '/index.php/settings/user/attendance'

// Beyond anything the other files schedule, yet inside the 180 days the team
// calendar shows by default.
const OFFSET = 120

function dateOnly(daysFromNow) {
	const date = new Date()
	date.setDate(date.getDate() + daysFromNow)
	const month = String(date.getMonth() + 1).padStart(2, '0')
	const day = String(date.getDate()).padStart(2, '0')
	return `${date.getFullYear()}-${month}-${day}`
}

function displayDate(value, options = { dateStyle: 'medium' }) {
	const [year, month, day] = value.split('-').map(Number)
	return new Date(year, month - 1, day).toLocaleDateString('en-US', options)
}

async function listVacations(request) {
	const resp = await request.get(`${API}/vacations`, { headers: authHeaders(USER, USER) })
	return resp.json()
}

async function deleteVacations(request) {
	for (const vacation of await listVacations(request)) {
		await request.delete(`${API}/vacations/${vacation.id}`, { headers: authHeaders(USER, USER) })
	}
}

async function ownResponse(request, appointmentId) {
	const appointments = await listAppointmentsViaAPI(request, { showPast: false, ...AUTH })
	return appointments.find((appointment) => appointment.id === appointmentId).userResponse?.response ?? null
}

// Nobody sees the team's vacations unless the permission says so.
test.beforeAll(async ({ request }) => {
	await saveAdminSettings(request, { permissions: { ...PERMISSIVE_PERMISSIONS, see_team_vacations: { mode: 'all', groups: [] } } })
})

test.afterAll(async ({ request }) => {
	await saveAdminSettings(request, { permissions: { ...PERMISSIVE_PERMISSIONS } })
})

test.describe('Vacations in personal settings', () => {
	test.describe.configure({ mode: 'serial' })
	// Ahead of UTC: a date saved through toISOString() would land a day early.
	test.use({ timezoneId: 'Pacific/Auckland' })

	const start = dateOnly(OFFSET)
	const end = dateOnly(OFFSET + 4)
	const laterEnd = dateOnly(OFFSET + 8)
	let insideId
	let afterId

	test.beforeAll(async ({ request }) => {
		await deleteVacations(request)
		// Created before any vacation exists, so neither starts out at "No".
		insideId = (await createAppointmentViaAPI(request, {
			name: 'Vacation Inside',
			daysFromNow: OFFSET + 2,
			visibleUsers: [USER],
		})).id
		afterId = (await createAppointmentViaAPI(request, {
			name: 'Vacation After',
			daysFromNow: OFFSET + 6,
			visibleUsers: [USER],
		})).id
	})

	test.afterAll(async ({ request }) => {
		await deleteVacations(request)
		await deleteAppointmentViaAPI(request, insideId)
		await deleteAppointmentViaAPI(request, afterId)
	})

	test('adds a vacation and answers its open appointments on request', async ({ page, request, loginAsUser }) => {
		await loginAsUser(USER)
		await page.goto(SETTINGS_URL)

		const section = page.locator('[data-test="section-my-vacations"]')
		await expect(section.getByText('No vacations recorded yet.')).toBeVisible()
		await section.locator('[data-test="button-add-vacation"]').click()

		const form = page.locator('[data-test="vacation-form-dialog"]')
		await form.locator('[data-test="input-vacation-start"]').fill(start)
		await form.locator('[data-test="input-vacation-end"]').fill(end)
		await form.locator('[data-test="input-vacation-note"]').fill('Summer break')
		await form.locator('[data-test="button-save-vacation"]').click()

		const item = section.locator('[data-test="vacation-item"]')
		await expect(item).toHaveCount(1)
		await expect(item).toContainText(`${displayDate(start)} – ${displayDate(end)}`)
		await expect(item).toContainText('Summer break')
		expect(await listVacations(request)).toMatchObject([{ startDate: start, endDate: end, note: 'Summer break' }])

		const question = page.locator('[data-test="vacation-apply-dialog"]')
		await expect(question).toContainText('Set appointments to "No"?')
		await question.locator('[data-test="button-vacation-apply"]').click()
		await expect(page.getByText('1 appointment set to "No"')).toBeVisible()
		await expect(question).toBeHidden()

		expect(await ownResponse(request, insideId)).toBe('no')
		expect(await ownResponse(request, afterId)).toBeNull()

		const teamEntry = page.locator('[data-test="section-team-vacations"] [data-test="team-vacation-entry"]')
		await expect(teamEntry.filter({ hasText: 'Summer break' })).toContainText(USER)
	})

	test('a note-only edit does not ask again', async ({ page, loginAsUser }) => {
		await loginAsUser(USER)
		await page.goto(SETTINGS_URL)

		const item = page.locator('[data-test="vacation-item"]')
		await item.locator('[data-test="button-edit-vacation"]').click()

		const form = page.locator('[data-test="vacation-form-dialog"]')
		await expect(form.locator('[data-test="input-vacation-start"]')).toHaveValue(start)
		await expect(form.locator('[data-test="input-vacation-end"]')).toHaveValue(end)
		await form.locator('[data-test="input-vacation-note"]').fill('Family trip')
		await form.locator('[data-test="button-save-vacation"]').click()

		// The list and the question are updated in the same tick.
		await expect(item).toContainText('Family trip')
		await expect(page.locator('[data-test="vacation-apply-dialog"]')).toHaveCount(0)
	})

	test('changed days ask again, and leaving open answers nothing', async ({ page, request, loginAsUser }) => {
		await loginAsUser(USER)
		await page.goto(SETTINGS_URL)

		const item = page.locator('[data-test="vacation-item"]')
		await item.locator('[data-test="button-edit-vacation"]').click()

		const form = page.locator('[data-test="vacation-form-dialog"]')
		await form.locator('[data-test="input-vacation-end"]').fill(laterEnd)
		await form.locator('[data-test="button-save-vacation"]').click()

		const question = page.locator('[data-test="vacation-apply-dialog"]')
		await question.locator('[data-test="button-vacation-leave-open"]').click()
		await expect(question).toBeHidden()

		await expect(item).toContainText(`${displayDate(start)} – ${displayDate(laterEnd)}`)
		expect(await listVacations(request)).toMatchObject([{ startDate: start, endDate: laterEnd, note: 'Family trip' }])
		expect(await ownResponse(request, afterId)).toBeNull()
	})

	test('deletes a vacation', async ({ page, request, loginAsUser }) => {
		await loginAsUser(USER)
		await page.goto(SETTINGS_URL)

		const section = page.locator('[data-test="section-my-vacations"]')
		await section.locator('[data-test="button-delete-vacation"]').click()

		const confirm = page.locator('[data-test="vacation-delete-dialog"]')
		await expect(confirm).toContainText('Do you want to delete this vacation entry?')
		await confirm.locator('[data-test="button-confirm-delete-vacation"]').click()

		await expect(section.getByText('No vacations recorded yet.')).toBeVisible()
		await expect(page.locator('[data-test="section-team-vacations"]'))
			.toContainText('Nobody has recorded a vacation for the next months.')
		expect(await listVacations(request)).toEqual([])
	})
})

test.describe('Vacation dates behind UTC', () => {
	// Behind UTC: a date-only string parsed as UTC would show the day before.
	test.use({ timezoneId: 'America/Los_Angeles' })

	const start = dateOnly(OFFSET + 20)
	const end = dateOnly(OFFSET + 22)

	test.beforeAll(async ({ request }) => {
		await deleteVacations(request)
		await request.post(`${API}/vacations`, {
			headers: authHeaders(USER, USER),
			data: { startDate: start, endDate: end },
		})
	})

	test.afterAll(async ({ request }) => {
		await deleteVacations(request)
	})

	test('shows and edits the days as recorded', async ({ page, loginAsUser }) => {
		await loginAsUser(USER)
		await page.goto(SETTINGS_URL)

		const item = page.locator('[data-test="vacation-item"]')
		await expect(item).toContainText(`${displayDate(start)} – ${displayDate(end)}`)

		const team = page.locator('[data-test="section-team-vacations"]')
		await expect(team.locator('[data-test="team-vacation-month"]'))
			.toContainText(displayDate(start, { month: 'long', year: 'numeric' }))
		const short = { month: 'short', day: 'numeric' }
		await expect(team.locator('[data-test="team-vacation-entry"]'))
			.toContainText(`${displayDate(start, short)} – ${displayDate(end, short)}`)

		await item.locator('[data-test="button-edit-vacation"]').click()
		const form = page.locator('[data-test="vacation-form-dialog"]')
		await expect(form.locator('[data-test="input-vacation-start"]')).toHaveValue(start)
		await expect(form.locator('[data-test="input-vacation-end"]')).toHaveValue(end)
	})
})

test.describe('Team vacations need the permission', () => {
	test.beforeAll(async ({ request }) => {
		await deleteVacations(request)
		await saveAdminSettings(request, { permissions: { ...PERMISSIVE_PERMISSIONS } })
	})

	test.afterAll(async ({ request }) => {
		await saveAdminSettings(request, { permissions: { ...PERMISSIVE_PERMISSIONS } })
	})

	test('without it the list is closed, with it open', async ({ request, page, loginAsUser }) => {
		const closed = await request.get(`${API}/vacations/team`, { headers: authHeaders(USER, USER) })
		expect(closed.status()).toBe(403)

		await loginAsUser(USER, USER)
		await page.goto(SETTINGS_URL)
		await expect(page.locator('[data-test="section-my-vacations"]')).toBeVisible()
		await expect(page.locator('[data-test="section-team-vacations"]')).toHaveCount(0)

		await saveAdminSettings(request, { permissions: { ...PERMISSIVE_PERMISSIONS, see_team_vacations: { mode: 'all', groups: [] } } })
		const open = await request.get(`${API}/vacations/team`, { headers: authHeaders(USER, USER) })
		expect(open.status()).toBe(200)
	})
})

// The create form of the mobile app asks this endpoint who cannot attend.
test.describe('Vacation conflicts for a new appointment', () => {
	const start = dateOnly(OFFSET + 30)
	const end = dateOnly(OFFSET + 32)
	let vacationId

	test.beforeAll(async ({ request }) => {
		await deleteVacations(request)
		const created = await request.post(`${API}/vacations`, {
			headers: authHeaders(USER, USER),
			data: { startDate: start, endDate: end, note: 'Private reason' },
		})
		vacationId = (await created.json()).id
	})

	test.afterAll(async ({ request }) => {
		await deleteVacations(request)
	})

	test('names who is away in the window and nobody outside it', async ({ request }) => {
		const ask = async (from, to) => (await request.get(
			`${API}/vacations/conflicts?startDatetime=${from}T10:00:00Z&endDatetime=${to}T12:00:00Z&visibleUsers[]=${USER}`,
			{ headers: authHeaders() },
		)).json()

		const inside = await ask(start, start)
		expect(inside.count).toBe(1)
		expect(inside.users[0].userId).toBe(USER)

		const outside = await ask(dateOnly(OFFSET + 40), dateOnly(OFFSET + 40))
		expect(outside.count).toBe(0)
		expect(vacationId).toBeTruthy()
	})
})
