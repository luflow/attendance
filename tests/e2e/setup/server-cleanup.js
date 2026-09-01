#!/usr/bin/env node

import { execFileSync } from 'node:child_process'
import { CONTAINER_NAME } from './container.js'

/**
 * Drop the test server container and the `apps_writable` volume, so the next
 * `npm run test:e2e` builds a completely fresh instance. Either may already be
 * gone — that is not an error.
 */
function dockerRemove(...args) {
	try {
		execFileSync('docker', args, { stdio: 'pipe' })
	} catch {
		// Already removed, or Docker is not running.
	}
}

dockerRemove('rm', '-f', CONTAINER_NAME)
dockerRemove('volume', 'rm', '-f', 'apps_writable')

console.log(`Removed container ${CONTAINER_NAME} and volume apps_writable`)
