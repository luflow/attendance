import { restoreSnapshot, getContainer } from '@nextcloud/e2e-test-server'

/**
 * Global setup for Playwright tests
 * Restores the database snapshot before each test run to ensure a clean state
 */
export default async function globalSetup() {
	try {
		console.log('\n🔄 Restoring database snapshot for clean test state...')
		const container = getContainer()
		await restoreSnapshot('init', container)
		console.log('✅ Database snapshot restored successfully\n')
	} catch (error) {
		console.log('⚠️  No snapshot found or restore failed - running with current database state')
		console.log('💡 Create a snapshot with: npm run test:e2e:snapshot\n')
	}
}
