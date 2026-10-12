<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Seed the new export permission from manage_appointments. Export used to be
 * a manager-only action, so the same mode and groups keep every install's
 * effective access exactly where it was — unlike see_statistics, "all" carries
 * over too, because exporting was open to everyone wherever managing was.
 *
 * Config keys and modes are inlined as a snapshot instead of referencing
 * PermissionService: migrations also run at install and on multi-version
 * jumps, where the service's live logic may no longer match this step.
 */
final class Version000027Date20261012120000 extends SimpleMigrationStep {
	private const APP_ID = 'attendance';
	private const SOURCE = 'manage_appointments';
	private const TARGET = 'export';
	private const MODES = ['all', 'groups', 'nobody'];

	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
	) {
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$targetModeKey = 'permission_' . self::TARGET . '_mode';

		// A stored target mode means this ran before, or an admin already
		// decided — either way the seed must not overwrite it.
		if (in_array($this->appConfig->getValueString(self::APP_ID, $targetModeKey), self::MODES, true)) {
			return;
		}

		$roles = array_values(array_filter(
			$this->appConfig->getValueArray(self::APP_ID, 'permission_' . self::SOURCE),
			'is_string',
		));

		// No stored mode means a jump from before explicit modes, where a bare
		// group list meant "these groups" and an empty one meant "everyone".
		$mode = $this->appConfig->getValueString(self::APP_ID, 'permission_' . self::SOURCE . '_mode');
		if (!in_array($mode, self::MODES, true)) {
			$mode = $roles !== [] ? 'groups' : 'all';
		}

		// Groups mode with no group names nobody.
		if ($mode === 'groups' && $roles === []) {
			$mode = 'nobody';
		}

		if ($mode === 'groups') {
			// Written through IConfig like PermissionService does — the typed
			// setter would mark the key as an array and collide on the next save.
			$this->config->setAppValue(
				self::APP_ID,
				'permission_' . self::TARGET,
				json_encode($roles, JSON_THROW_ON_ERROR),
			);
			$output->info('Seeded export from manage_appointments (' . count($roles) . ' group(s))');
		}

		$this->appConfig->setValueString(self::APP_ID, $targetModeKey, $mode);
	}
}
