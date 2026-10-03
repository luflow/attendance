import {
	authHeaders,
	BASE_URL,
	createAppointmentViaAPI,
	deleteAppointmentViaAPI,
	expect,
	loadWholeAppointmentList,
	test,
} from './fixtures/nextcloud.js'

// The list loads 20 appointments at a time, and the server answers search and
// filters over all of them — not the browser over the part it happens to hold.
test.describe('Appointment list pagination', () => {
	test.describe.configure({ mode: 'serial' })

	const prefix = 'Paged List Entry'
	const count = 23
	const created = []

	test.beforeAll(async ({ request }) => {
		for (let i = 1; i <= count; i++) {
			const appointment = await createAppointmentViaAPI(request, {
				name: `${prefix} ${String(i).padStart(2, '0')}`,
				daysFromNow: 60 + i,
			})
			created.push(appointment.id)
		}
	})

	// Runs in the parallel project, so clean up only what this file created.
	test.afterAll(async ({ request }) => {
		for (const id of created) {
			await deleteAppointmentViaAPI(request, id)
		}
	})

	test('shows one page and loads the next on scrolling to the end', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()

		await page.getByRole('searchbox', { name: /Search appointments/ }).fill(prefix)

		const cards = page.locator('[data-test="appointment-card"]', { hasText: prefix })
		const more = page.locator('[data-test="appointments-load-more"]')
		await expect(cards).toHaveCount(20)
		await expect(more).toHaveCount(1)

		await loadWholeAppointmentList(page)
		await expect(cards).toHaveCount(count)
		await expect(more).toHaveCount(0)
	})

	test('search reaches appointments beyond the loaded page', async ({ page, loginAsUser, attendanceApp }) => {
		await loginAsUser('admin', 'admin')
		await attendanceApp()

		// The last one sits behind at least 22 earlier appointments, so no
		// first page holds it — only a search on the server can turn it up.
		const last = `${prefix} ${count}`
		await page.getByRole('searchbox', { name: /Search appointments/ }).fill(last)

		const cards = page.locator('[data-test="appointment-card"]')
		await expect(cards).toHaveCount(1)
		await expect(cards.first()).toContainText(last)
	})

	test('sidebar pages the past appointments', async ({ request }) => {
		const resp = await request.get(`${BASE_URL}/index.php/apps/attendance/api/appointments/navigation?pastLimit=1`, {
			headers: authHeaders(),
		})
		const navigation = await resp.json()

		expect(navigation.past.length).toBeLessThanOrEqual(1)
		expect(typeof navigation.pastHasMore).toBe('boolean')
		// The upcoming entries are never cut — the unanswered count hangs on them.
		expect(navigation.current.length).toBeGreaterThanOrEqual(count)
	})
})
