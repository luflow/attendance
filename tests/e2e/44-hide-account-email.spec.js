import { authHeaders, BASE_URL, expect, getOcsBase, reloadWebWorkers, saveAdminSettings, test, waitForSettingsSave } from './fixtures/nextcloud.js'

/**
 * Hide account email addresses
 *
 * The setting rewrites responses of the server's own controllers, which is
 * outside any public API. These tests read those responses from a real server,
 * so a Nextcloud release that changes them fails here rather than silently
 * showing the addresses again.
 */

const USER = 'test'
const EMAIL = 'hide-account-email@example.org'

const formHeaders = { ...authHeaders('admin', 'admin'), 'Content-Type': 'application/x-www-form-urlencoded' }

async function setEmail(request, value) {
	const ocsBase = await getOcsBase(request)
	const resp = await request.put(`${ocsBase}/cloud/users/${USER}?format=json`, {
		headers: formHeaders,
		form: { key: 'email', value },
	})
	expect(resp.ok(), `setting the email address failed: ${resp.status()} ${await resp.text()}`).toBeTruthy()
}

/** The menu on a profile picture, as Talk and its mobile apps request it. */
async function avatarMenu(request) {
	const ocsBase = await getOcsBase(request)
	const resp = await request.get(`${ocsBase}/hovercard/v1/${USER}?format=json`, { headers: authHeaders('admin', 'admin') })
	expect(resp.ok()).toBeTruthy()
	return (await resp.json()).ocs.data.actions
}

async function accountSearch(request) {
	const ocsBase = await getOcsBase(request)
	const resp = await request.get(`${ocsBase}/core/autocomplete/get?format=json&search=${USER}&shareTypes[]=0`, { headers: authHeaders('admin', 'admin') })
	expect(resp.ok()).toBeTruthy()
	return (await resp.json()).ocs.data.find((result) => result.id === USER)
}

/** The contacts menu in the header of the web interface. */
async function contactsMenu(request) {
	const resp = await request.post(`${BASE_URL}/index.php/contactsmenu/findOne`, {
		headers: formHeaders,
		form: { shareType: 0, shareWith: USER },
	})
	expect(resp.ok()).toBeTruthy()
	return resp.json()
}

const isEmailAction = (action) => action.appId === 'email' || action.hyperlink.startsWith('mailto:')

async function hide(request, hidden) {
	await saveAdminSettings(request, { hideAccountEmail: hidden })
	await reloadWebWorkers()
}

test.describe('Attendance App - Hide account email addresses', () => {
	test.beforeAll(async ({ request }) => {
		await setEmail(request, EMAIL)
		await hide(request, false)
	})

	test.afterAll(async ({ request }) => {
		// No other spec reads these endpoints, so a stale worker does no harm here.
		await saveAdminSettings(request, { hideAccountEmail: false })
		await setEmail(request, '')
	})

	test('the server shows the address while the setting is off', async ({ request }) => {
		expect((await avatarMenu(request)).some(isEmailAction)).toBe(true)
		expect((await accountSearch(request)).shareWithDisplayNameUnique).toBe(EMAIL)
		expect((await contactsMenu(request)).emailAddresses).toEqual([EMAIL])
	})

	test('switching it on removes the address and nothing else', async ({ request }) => {
		const menuBefore = await avatarMenu(request)

		await hide(request, true)

		// Everything but the email entry is still there, in the same order.
		expect(await avatarMenu(request)).toEqual(menuBefore.filter((action) => !isEmailAction(action)))

		expect((await accountSearch(request)).shareWithDisplayNameUnique).toBe(USER)

		const contact = await contactsMenu(request)
		expect(contact.emailAddresses).toEqual([])
		expect([contact.topAction, ...contact.actions].filter(Boolean).some(isEmailAction)).toBe(false)
		expect(JSON.stringify(contact)).not.toContain(EMAIL)
	})

	test('the admin settings show the switch and save it', async ({ page, request, loginAsUser }) => {
		await hide(request, true)

		await loginAsUser('admin', 'admin')
		await page.goto('/settings/admin/attendance')
		await page.waitForLoadState('networkidle')

		const toggle = page.locator('[data-test="switch-hide-account-email"]')
		await expect(toggle).toBeChecked()
		const saved = waitForSettingsSave(page)
		await page.getByText('Hide the email address of accounts').click()
		await saved
		await reloadWebWorkers()

		await page.reload()
		await expect(toggle).not.toBeChecked()
		expect((await avatarMenu(request)).some(isEmailAction)).toBe(true)
	})
})
