<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCA\Attendance\AppInfo\Application;
use OCA\Attendance\Service\PermissionService;
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
 */
class Version000018Date20260803120000 extends SimpleMigrationStep {

	public function __construct(
		private IAppConfig $appConfig,
		private PermissionService $permissionService,
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
		// getModeForPermission() returns the stored mode when one exists, so
		// re-writing it is a no-op and the backfill stays idempotent.
		foreach (PermissionService::ALL_PERMISSIONS as $permission) {
			$this->appConfig->setValueString(
				Application::APP_ID,
				'permission_' . $permission . '_mode',
				$this->permissionService->getModeForPermission($permission),
			);
		}
	}
}
