import {
	test,
	expect,
	createAppointmentViaAPI,
	respondToAppointmentViaAPI,
	checkinUserViaAPI,
	deleteAllAppointments,
} from './fixtures/nextcloud.js'

const BASE_URL = process.env.NEXTCLOUD_URL || 'http://localhost:8080'
const API_BASE = `${BASE_URL}/index.php`
const adminAuth = 'Basic ' + Buffer.from('admin:admin').toString('base64')

async function fetchAudit(request, appointmentId, params = {}) {
	const url = new URL(`${API_BASE}/apps/attendance/api/appointments/${appointmentId}/audit`)
	for (const [k, v] of Object.entries(params)) {
		url.searchParams.set(k, String(v))
	}
	const resp = await request.get(url.toString(), {
		headers: { Authorization: adminAuth, 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
	})
	return { status: resp.status(), body: resp.status() === 200 ? await resp.json() : null }
}

async function setAuditEnabled(request, enabled) {
	await request.post(`${API_BASE}/apps/attendance/api/admin/settings`, {
		headers: { Authorization: adminAuth, 'OCS-APIREQUEST': 'true' },
		data: { audit: { enabled } },
	})
}

test.describe('Audit history — API', () => {
	test.afterAll(async ({ request }) => {
		await setAuditEnabled(request, true)
		await deleteAllAppointments(request)
	})

	test('records submitted / changed / rescinded verbs in newest-first order', async ({ request }) => {
		await setAuditEnabled(request, true)
		const apt = await createAppointmentViaAPI(request, {
			name: 'Audit history flow',
			daysFromNow: 8,
		})

		await respondToAppointmentViaAPI(request, apt.id, { response: 'maybe', comment: 'tentative' })
		await respondToAppointmentViaAPI(request, apt.id, { response: 'yes', comment: 'tentative' })
		await respondToAppointmentViaAPI(request, apt.id, { response: null })

		const { status, body } = await fetchAudit(request, apt.id)
		expect(status).toBe(200)
		expect(body.items.length).toBeGreaterThanOrEqual(3)

		const recentVerbs = body.items.slice(0, 3).map(e => e.verb)
		expect(recentVerbs).toEqual([
			'response.rescinded',
			'response.changed',
			'response.submitted',
		])

		const rescinded = body.items[0]
		expect(rescinded.subject?.userId).toBe('admin')
		expect(rescinded.meta.from).toBe('yes')

		const changed = body.items[1]
		expect(changed.meta.from).toBe('maybe')
		expect(changed.meta.to).toBe('yes')
	})

	test('records check-in events with admin as actor and target as subject', async ({ request }) => {
		await setAuditEnabled(request, true)
		const apt = await createAppointmentViaAPI(request, {
			name: 'Audit history checkin',
			daysFromNow: 5,
		})
		await respondToAppointmentViaAPI(request, apt.id, { response: 'yes' })
		await checkinUserViaAPI(request, apt.id, 'admin', { response: 'yes' })

		const { body } = await fetchAudit(request, apt.id, { verb: 'checkin.*' })
		expect(body.items.length).toBeGreaterThan(0)
		const event = body.items[0]
		expect(event.verb).toBe('checkin.recorded')
		expect(event.actor?.userId).toBe('admin')
		expect(event.subject?.userId).toBe('admin')
		expect(event.meta.checkinState).toBe('yes')
	})

	test('returns 412 Precondition Failed when the audit log is disabled', async ({ request }) => {
		await setAuditEnabled(request, false)
		const apt = await createAppointmentViaAPI(request, {
			name: 'Audit disabled',
			daysFromNow: 5,
		})
		const { status } = await fetchAudit(request, apt.id)
		expect(status).toBe(412)
		// Re-enable for follow-up tests.
		await setAuditEnabled(request, true)
	})

	test('capability flag auditLog reflects the admin setting', async ({ request }) => {
		await setAuditEnabled(request, true)
		const resp = await request.get(`${API_BASE}/apps/attendance/api/capabilities`, {
			headers: { Authorization: adminAuth, 'OCS-APIREQUEST': 'true' },
		})
		const body = await resp.json()
		expect(body.auditLog).toBe(true)

		await setAuditEnabled(request, false)
		const respOff = await request.get(`${API_BASE}/apps/attendance/api/capabilities`, {
			headers: { Authorization: adminAuth, 'OCS-APIREQUEST': 'true' },
		})
		expect((await respOff.json()).auditLog).toBe(false)
		await setAuditEnabled(request, true)
	})

	test('verb filter narrows results to a single verb family', async ({ request }) => {
		await setAuditEnabled(request, true)
		const apt = await createAppointmentViaAPI(request, {
			name: 'Audit verb filter',
			daysFromNow: 6,
		})
		await respondToAppointmentViaAPI(request, apt.id, { response: 'yes' })
		await respondToAppointmentViaAPI(request, apt.id, { response: 'no' })
		await checkinUserViaAPI(request, apt.id, 'admin', { response: 'yes' })

		const { body } = await fetchAudit(request, apt.id, { verb: 'response.*' })
		expect(body.items.every(e => e.verb.startsWith('response.'))).toBe(true)
	})
})
