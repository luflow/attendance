#!/usr/bin/env node

import { startNextcloud, configureNextcloud, waitOnNextcloud, createSnapshot, getContainer, setupUsers } from '@nextcloud/e2e-test-server'

/**
 * Start Nextcloud test server for e2e tests
 * This script is called by the Playwright webServer configuration
 */
async function main() {
	try {
		console.log('Starting Nextcloud test server...')
		
		// Start Nextcloud container (uses Docker)
		// stable33 branch, mount current app
		const ip = await startNextcloud('stable33', true, {
			exposePort: 8080,
			forceRecreate: true
		})
		
		console.log(`Nextcloud container started with IP: ${ip}`)
		
		// Wait for Nextcloud to be ready
		await waitOnNextcloud(ip)
		
		// Configure Nextcloud with the attendance app
		await configureNextcloud(['notifications', 'attendance'])

		// Create snapshot of clean database state
		const container = getContainer()

		await setupUsers(container)
		await createSnapshot('init', container)
		
		console.log('✅ Snapshot "init" created successfully!')
		console.log('This snapshot will be restored before each test run to ensure a clean state.')		
		console.log('Nextcloud test server started at http://localhost:8080')
		console.log('Test users available:')
		console.log('  - admin / admin (Administrator)')
		console.log('  - test / test (Regular user for e2e tests)')
		console.log('  - test1 / test1 (Regular user for e2e tests)')
		console.log('  - test2 / test2 (Regular user for e2e tests)')
		console.log('  - test3 / test3 (Regular user for e2e tests)')
		console.log('  - test4 / test4 (Regular user for e2e tests)')
		console.log('  - test5 / test5 (Regular user for e2e tests)')
		console.log('\nYou can now run tests with: npm run test:e2e')
		
		// Keep the process running
		await new Promise(() => {})
	} catch (error) {
		console.error('Failed to start Nextcloud test server:', error)
		console.error('Make sure Docker is running!')
		process.exit(1)
	}
}

main()
