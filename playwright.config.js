import { defineConfig, devices } from '@playwright/test'

/**
 * Playwright configuration for Nextcloud app e2e tests
 *
 * Two project groups:
 *   - parallel:  tests that do NOT modify global state (run with multiple workers)
 *   - sequential-admin:  tests that change admin settings / groups (1 worker, runs after parallel)
 *
 * @see https://playwright.dev/docs/test-configuration
 */
export default defineConfig({
	testDir: './tests/e2e',

	// Maximum time one test can run for
	timeout: 30 * 1000,

	// Global setup to restore database snapshot before tests
	globalSetup: './tests/e2e/setup/global-setup.js',

	// Test execution settings
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 2 : 0,

	// Reporter configuration
	reporter: [
		['html'],
		['list'],
	],

	// Shared settings for all tests
	use: {
		baseURL: process.env.NEXTCLOUD_URL || 'http://localhost:8080',
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
	},

	projects: [
		{
			name: 'parallel',
			use: { ...devices['Desktop Chrome'] },
			testMatch: [
				'1-basic.spec.js',
				'2-appointment.spec.js',
				'3-voting.spec.js',
				'5-export.spec.js',
				'7-ical-feed.spec.js',
				'8-attachments.spec.js',
				'9-recurrence.spec.js',
				'10-series.spec.js',
				'11-notification-option.spec.js',
				'14-close-inquiry.spec.js',
				'checkin.spec.js',
			],
			fullyParallel: false, // tests within a file stay sequential
			workers: process.env.CI ? 3 : 4,
		},
		{
			name: 'sequential-admin',
			use: { ...devices['Desktop Chrome'] },
			testMatch: [
				'4-admin-settings.spec.js',
				'5-visibility-users.spec.js',
				'6-visibility-groups.spec.js',
				'12-calendar-import.spec.js',
				'13-calendar-sync.spec.js',
			],
			fullyParallel: false,
			workers: 1,
			dependencies: ['parallel'],
		},
	],

	// Run local dev server before starting tests
	webServer: process.env.CI ? undefined : {
		command: 'npm run test:e2e:server',
		url: 'http://localhost:8080',
		reuseExistingServer: !process.env.CI,
		timeout: 300 * 1000,
		stdout: 'pipe',
		stderr: 'pipe',
	},
})
