<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Server;

/**
 * Make permission access modes explicit. Previously an empty group list
 * meant "everyone" for most permissions but "nobody" for the additive ones
 * (create_appointments, respond_for_others). Each permission now stores an
 * explicit permission_<name>_mode (all|groups|nobody); this backfills the
 * mode every install currently has, so effective access does not change.
 *
 * Deliberately self-contained: when service resolution fails during an
 * upgrade, MigrationService falls back to `new $class()` without arguments,
 * so constructor DI of app services can fatal mid-upgrade. No constructor,
 * no app services — core services are resolved inside the step instead.
 */
class Version000018Date20260803120000 extends SimpleMigrationStep {
	private const APP_ID = 'attendance';

	/** Permission catalogue and default policy as of this release. */
	private const ALL_PERMISSIONS = [
		'manage_appointments',
		'checkin',
		'see_response_overview',
		'see_response_counts',
		'see_comments',
		'self_checkin',
		'create_appointments',
		'respond_for_others',
	];

	private const DEFAULT_NOBODY = ['create_appointments', 'respond_for_others'];

	private const MODES = ['all', 'groups', 'nobody'];

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
		$appConfig = Server::get(IAppConfig::class);

		foreach (self::ALL_PERMISSIONS as $permission) {
			$modeKey = 'permission_' . $permission . '_mode';

			// A stored mode is kept as-is, so the backfill stays idempotent.
			$mode = $appConfig->getValueString(self::APP_ID, $modeKey);
			if (!in_array($mode, self::MODES, true)) {
				$mode = $this->impliedMode($appConfig, $permission);
			}

			$appConfig->setValueString(self::APP_ID, $modeKey, $mode);
		}
	}

	private function impliedMode(IAppConfig $appConfig, string $permission): string {
		// Legacy group lists live on IConfig (typed "mixed"), so the typed
		// array getter reads them without a type conflict.
		$roles = $appConfig->getValueArray(self::APP_ID, 'permission_' . $permission);
		if (array_filter($roles, 'is_string') !== []) {
			return 'groups';
		}
		return in_array($permission, self::DEFAULT_NOBODY, true) ? 'nobody' : 'all';
	}
}
