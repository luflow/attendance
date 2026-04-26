import { restoreSnapshot, getContainer } from '@nextcloud/e2e-test-server'
import { existsSync, rmSync } from 'fs'
import { dirname, join } from 'path'
import { fileURLToPath } from 'url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const AUTH_DIR = join(__dirname, '..', '.auth')

/**
 * Clear cached auth states
 * This should be called when database is restored to ensure fresh logins
 */
function clearAuthCache() {
	if (existsSync(AUTH_DIR)) {
		rmSync(AUTH_DIR, { recursive: true, force: true })
		console.log('🗑️  Cleared cached auth states')
	}
}

/**
 * Global setup for Playwright tests
 * Restores the database snapshot before each test run to ensure a clean state
 */
export default async function globalSetup() {
	try {
		console.log('\n🔄 Restoring database snapshot for clean test state...')
		const container = getContainer()
		await restoreSnapshot('init', container)
		console.log('✅ Database snapshot restored successfully')

		// Clear auth cache after database restore to ensure fresh logins
		clearAuthCache()

		// Warm up the server so the first parallel workers don't hit a cold start.
		const baseURL = process.env.NEXTCLOUD_URL || 'http://localhost:8080'
		const auth = 'Basic ' + Buffer.from('admin:admin').toString('base64')
		for (let i = 0; i < 5; i++) {
			try {
				const resp = await fetch(`${baseURL}/index.php/apps/attendance/api/appointments?showPastAppointments=true`, {
					headers: { Authorization: auth, 'OCS-APIREQUEST': 'true' },
				})
				if (resp.ok && (resp.headers.get('content-type') || '').includes('json')) {
					console.log('✅ Server warm-up successful')
					break
				}
			} catch { /* retry */ }
			await new Promise(r => setTimeout(r, 2000))
		}

		console.log('')
	} catch (error) {
		console.log('⚠️  No snapshot found or restore failed - running with current database state')
		console.log('💡 Create a snapshot with: npm run test:e2e:snapshot\n')
	}
}
