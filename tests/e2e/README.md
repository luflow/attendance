# E2E Tests for Attendance App

This directory contains end-to-end tests for the Attendance Nextcloud app using Playwright and the Nextcloud e2e test server.

## Setup

### First-time setup

1. Install Playwright browsers:
```bash
npm run test:e2e:install
```

This installs Chromium and its system dependencies.

2. (Optional) Create a database snapshot for faster test runs:
```bash
npm run test:e2e:snapshot
```

This creates a clean baseline database state that will be restored before each test run, ensuring consistent test data and faster startup times.

## Running Tests

### Run all tests (headless)
```bash
npm run test:e2e
```

The Nextcloud test server will automatically start before tests run and stop afterwards.

### Run tests with UI mode (recommended for development)
```bash
npm run test:e2e:ui
```

This opens the Playwright UI where you can:
- See all tests
- Run tests selectively
- Watch mode for auto-rerun
- Time travel through test steps
- View traces and screenshots

### Run tests in headed mode (see browser)
```bash
npm run test:e2e:headed
```

### Debug tests
```bash
npm run test:e2e:debug
```

This opens tests in debug mode with Playwright Inspector for step-by-step debugging.

### Run specific test file
```bash
npx playwright test tests/e2e/basic.spec.js
```

### Run tests with specific options
```bash
npx playwright test --grep "should create" # Run tests matching pattern
npx playwright test --project=chromium    # Run on specific browser
npx playwright test --workers=1           # Control parallelization
```

### Reset test server data
```bash
# Clean up Docker containers and reset data (recommended)
npm run test:e2e:cleanup

# Or manually:
docker stop nextcloud-e2e-test-server_attendance
docker rm -f nextcloud-e2e-test-server_attendance
docker volume prune -f
```

**When to reset:**
- Tests are failing due to stale data
- Need a fresh Nextcloud instance
- After major test changes
- When switching between test runs
- Before running full test suite

**What the cleanup does:**
1. Stops the running test server container
2. Removes the container (clears all state)
3. Removes unused Docker volumes (clears database)
4. Next test run will create a completely fresh instance

### Database Snapshots

The test server supports database snapshots for faster and more reliable test runs:

**Create a snapshot:**
```bash
npm run test:e2e:snapshot
```

This creates a clean baseline database snapshot named "init" after:
- Starting a fresh Nextcloud instance
- Installing and configuring the attendance app
- Setting up default test users

**How it works:**
- The snapshot is automatically restored before each test run via Playwright's global setup
- Each test run starts with a clean, consistent database state
- No need to manually clean up data between test runs
- The test server keeps running, but the database is reset to the snapshot state

**When to recreate the snapshot:**
- After changes to the app's database schema
- After updating Nextcloud version
- When test data needs to be refreshed
- If you want to include specific test data in the baseline

**Note:** If no snapshot exists, the test server will start with a fresh database and suggest creating one.

## Test Server

The tests use the `@nextcloud/e2e-test-server` package which provides a pre-configured Nextcloud instance with test data via Docker:

- **URL**: `http://localhost:8080`
- **Users** (default users created by @nextcloud/e2e-test-server):
  - `admin / admin` (administrator)
  - `test / test` (primary regular user for e2e tests)
  - `user1 / user1`, `user2 / user2` (additional test users via setupUsers())
- **Requirements**: Docker must be running on your machine
- **Note**: The e2e tests primarily use `test/test` as the regular user credential
- **Installed apps:**
  - Attendance app (from current directory)

## Writing Tests

### Test Structure

Tests use custom fixtures defined in `fixtures/nextcloud.js`:

```javascript
import { test, expect } from './fixtures/nextcloud.js'

test('my test', async ({ page, loginAsUser, attendanceApp }) => {
  // Login as a user
  await loginAsUser('admin', 'admin')
  
  // Navigate to attendance app
  await attendanceApp()
  
  // Your test code
  await expect(page.locator('#app-content')).toBeVisible()
})
```

### Available Fixtures

- **`loginAsUser(username, password)`** - Login helper
- **`attendanceApp()`** - Navigate to attendance app
- **`adminPage`** - Pre-authenticated admin page context
- **`page`** - Standard Playwright page object

### Best Practices

1. **Use data-test attributes** for stable selectors:
   ```vue
   <button data-test="create-appointment">Create</button>
   ```
   ```javascript
   await page.click('[data-test="create-appointment"]')
   ```

2. **Wait for network idle** after navigation:
   ```javascript
   await page.waitForLoadState('networkidle')
   ```

3. **Use meaningful test descriptions**:
   ```javascript
   test('should allow admin to create appointment with all fields', async ({ ... }) => {
   ```

4. **Organize tests by feature** in separate files

5. **Clean up test data** if tests modify state

## Available Data-Test Attributes

All interactive elements in the app have `data-test` attributes for reliable testing:

### Navigation
- `nav-unanswered` - Unanswered appointments section
- `nav-upcoming` - Upcoming appointments section
- `nav-past` - Past appointments section
- `nav-unanswered-appointment` - Individual unanswered appointment
- `nav-upcoming-appointment` - Individual upcoming appointment
- `nav-past-appointment-{id}` - Individual past appointment
- `button-create-appointment` - Create new appointment button
- `button-export` - Export appointments button

### Appointment Card
- `appointment-card` - Main appointment card container
- `appointment-title` - Appointment title
- `appointment-actions-menu` - Actions dropdown menu
- `action-share-link` - Share link action
- `action-start-checkin` - Start check-in action
- `action-edit` - Edit appointment action
- `action-delete` - Delete appointment action

### Response Section
- `response-section` - Response section container
- `response-yes` - Yes response button
- `response-maybe` - Maybe response button
- `response-no` - No response button
- `response-comment` - Comment textarea

### Appointment Form Modal
- `appointment-form-modal` - Modal container
- `form-title` - Modal title
- `appointment-form` - Form element
- `input-appointment-name` - Name input field
- `input-appointment-description` - Description textarea
- `input-start-datetime` - Start date/time picker
- `input-end-datetime` - End date/time picker
- `button-cancel` - Cancel button
- `button-save` - Save button

### Check-in View
- `checkin-view` - Check-in view container
- `button-back` - Back button
- `input-search` - Search users input
- `select-group-filter` - Group filter dropdown
- `button-bulk-present` - Mark all present button
- `button-bulk-absent` - Mark all absent button
- `user-item-{userId}` - Individual user item
- `button-present` - Mark user present
- `button-absent` - Mark user absent
- `button-add-comment` - Add comment button
- `textarea-checkin-comment` - Check-in comment textarea
- `button-save-comment` - Save comment button
- `button-cancel-comment` - Cancel comment button
- `dialog-confirm-bulk` - Bulk action confirmation dialog
- `button-bulk-cancel` - Cancel bulk action
- `button-bulk-confirm` - Confirm bulk action

### Admin Settings
- `admin-settings` - Admin settings container
- `select-whitelisted-groups` - Whitelisted groups selector
- `select-manage-appointments-roles` - Manage appointments permission selector
- `select-checkin-roles` - Check-in permission selector
- `select-see-response-overview-roles` - See response overview permission selector
- `select-see-comments-roles` - See comments permission selector
- `switch-reminders-enabled` - Enable reminders switch
- `input-reminder-days` - Reminder days input
- `input-reminder-frequency` - Reminder frequency input
- `button-save-settings` - Save settings button

### Widget (Dashboard)
- `widget-container` - Widget container
- `appointment-widget` - Dashboard widget
- `widget-appointment-item` - Individual appointment in widget
- `widget-appointment-title` - Appointment title in widget
- `button-widget-checkin` - Widget check-in button
- `widget-response-yes` - Yes response in widget
- `widget-response-maybe` - Maybe response in widget
- `widget-response-no` - No response in widget
- `button-widget-toggle-comment` - Toggle comment button
- `widget-response-comment` - Comment textarea in widget
- `button-show-all` - Show all appointments button

### Response Summary
- `response-summary` - Response summary container
- `group-summary` - Group-based summary section
- `group-container-{groupId}` - Individual group container
- `group-header` - Group header (clickable to expand)

### Other Views
- `appointment-detail-view` - Appointment detail view
- `loading-state` - Loading state indicator
- `error-state` - Error state display
- `button-back` - Back button

## Debugging

### View test report after failure
```bash
npx playwright show-report
```

### View traces
Traces are automatically captured on first retry. View them in the HTML report or:
```bash
npx playwright show-trace trace.zip
```

### Screenshots and videos
Failed tests automatically capture screenshots and videos in `test-results/` directory.

## CI Integration

For CI environments, set the `CI` environment variable:
```bash
CI=true npm run test:e2e
```

This:
- Enables test retries (2 retries)
- Prevents `--only` tests from running
- Disables server reuse

## Configuration

Test configuration is in `playwright.config.js`. Key settings:

- **testDir**: `./tests/e2e`
- **timeout**: 30 seconds per test
- **workers**: 1 (sequential execution)
- **baseURL**: `http://localhost:8080`
- **browsers**: Chromium (Firefox/Safari can be enabled)

## Troubleshooting

### Port 8080 already in use
Stop any existing Nextcloud servers or change the port in:
- `playwright.config.js` (baseURL and webServer.url)
- `tests/e2e/setup/server.js` (port option)

### Test server doesn't start
Check the server logs in terminal output. Common issues:
- Missing dependencies
- Docker not running (if using Docker mode)
- Insufficient permissions

### Tests are flaky
- Increase timeouts in `playwright.config.js`
- Add explicit waits with `page.waitForLoadState()`
- Use more specific selectors
- Ensure tests are independent (no shared state)

## Resources

- [Playwright Documentation](https://playwright.dev)
- [Nextcloud E2E Test Server](https://www.npmjs.com/package/@nextcloud/e2e-test-server)
- [Nextcloud App Development](https://docs.nextcloud.com/server/latest/developer_manual/)
