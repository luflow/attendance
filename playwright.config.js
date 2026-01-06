import { defineConfig, devices } from '@playwright/test'

/**
 * Playwright configuration for Nextcloud app e2e tests
 * @see https://playwright.dev/docs/test-configuration
 */
export default defineConfig({
	testDir: './tests/e2e',
	
	// Maximum time one test can run for
	timeout: 16 * 1000,
	
	// Global setup to restore database snapshot before tests
	globalSetup: './tests/e2e/setup/global-setup.js',
	
	// Test execution settings
	fullyParallel: false, // Run tests sequentially for Nextcloud server stability
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 2 : 0,
	workers: 1, // Single worker to avoid conflicts with Nextcloud test server
	
	// Reporter configuration
	reporter: [
		['html'],
		['list'],
	],
	
	// Shared settings for all tests
	use: {
		// Base URL for the Nextcloud test server
		baseURL: process.env.NEXTCLOUD_URL || 'http://localhost:8080',
		
		// Collect trace on failure for debugging
		trace: 'on-first-retry',
		
		// Screenshot on failure
		screenshot: 'only-on-failure',
		
		// Video on failure
		video: 'retain-on-failure',
	},
	
	// Configure projects for different browsers
	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] },
		},
		// You can enable additional browsers if needed
		// {
		// 	name: 'firefox',
		// 	use: { ...devices['Desktop Firefox'] },
		// },
	],
	
	// Run local dev server before starting tests
	webServer: process.env.CI ? undefined : {
		command: 'npm run test:e2e:server',
		url: 'http://localhost:8080',
		reuseExistingServer: !process.env.CI,
		timeout: 300 * 1000, // 5 minutes - Give Nextcloud time to start (includes Docker image pull)
		stdout: 'pipe',
		stderr: 'pipe',
	},
})
