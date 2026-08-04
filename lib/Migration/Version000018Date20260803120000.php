<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Make permission access modes explicit. Previously an empty group list
 * meant "everyone" for most permissions but "nobody" for the additive ones
 * (create_appointments, respond_for_others). Each permission now stores an
 * explicit permission_<name>_mode (all|groups|nobody); this backfills the
 * mode every install currently has, so effective access does not change.
 *
 * The permission catalogue is inlined as a snapshot instead of referencing
 * PermissionService: migrations also run at install and on multi-version
 * jumps, where the service's live logic may no longer match the state this
 * step was written for.
 */
class Version000018Date20260803120000 extends SimpleMigrationStep {
	private const APP_ID = 'attendance';

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

	public function __construct(
		private IAppConfig $appConfig,
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
		foreach (self::ALL_PERMISSIONS as $permission) {
			$modeKey = 'permission_' . $permission . '_mode';

			// A stored mode is kept as-is, so the backfill stays idempotent.
			$mode = $this->appConfig->getValueString(self::APP_ID, $modeKey);
			if (!in_array($mode, self::MODES, true)) {
				$mode = $this->impliedMode($permission);
			}

			$this->appConfig->setValueString(self::APP_ID, $modeKey, $mode);
		}
	}

	private function impliedMode(string $permission): string {
		// Legacy group lists live on IConfig (typed "mixed"), so the typed
		// array getter reads them without a type conflict.
		$roles = $this->appConfig->getValueArray(self::APP_ID, 'permission_' . $permission);
		if (array_filter($roles, 'is_string') !== []) {
			return 'groups';
		}
		return in_array($permission, self::DEFAULT_NOBODY, true) ? 'nobody' : 'all';
	}
}
