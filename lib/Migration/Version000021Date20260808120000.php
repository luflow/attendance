<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Seed the new see_statistics permission from manage_appointments, so the
 * people who already administer appointments keep working after the update
 * without an admin having to grant anything.
 *
 * Only a *restrictive* source configuration carries over. Version000018
 * backfilled an explicit mode for every install, so "manage_appointments is
 * stored" no longer distinguishes a configured install from an untouched one —
 * and mode "all" is what an untouched install has. Copying that would hand a
 * personal cross-appointment evaluation to every user on the server, which is
 * the opposite of the permission's "nobody" default.
 *
 * Config keys and modes are inlined as a snapshot instead of referencing
 * PermissionService: migrations also run at install and on multi-version
 * jumps, where the service's live logic may no longer match this step.
 */
class Version000021Date20260808120000 extends SimpleMigrationStep {
	private const APP_ID = 'attendance';
	private const SOURCE = 'manage_appointments';
	private const TARGET = 'see_statistics';
	private const MODES = ['all', 'groups', 'nobody'];

	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		return null;
	}

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

		$mode = $this->appConfig->getValueString(self::APP_ID, 'permission_' . self::SOURCE . '_mode');
		if (!in_array($mode, self::MODES, true)) {
			$mode = $roles !== [] ? 'groups' : 'all';
		}

		if ($mode !== 'groups' || $roles === []) {
			$this->appConfig->setValueString(self::APP_ID, $targetModeKey, 'nobody');
			return;
		}

		// Written through IConfig like PermissionService does — the typed
		// setter would mark the key as an array and collide on the next save.
		$this->config->setAppValue(
			self::APP_ID,
			'permission_' . self::TARGET,
			json_encode($roles, JSON_THROW_ON_ERROR),
		);
		$this->appConfig->setValueString(self::APP_ID, $targetModeKey, 'groups');

		$output->info('Seeded see_statistics from manage_appointments (' . count($roles) . ' group(s))');
	}
}
