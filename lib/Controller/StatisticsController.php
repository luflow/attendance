<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\Service\PermissionService;
use OCA\Attendance\Service\StatisticsExportService;
use OCA\Attendance\Service\StatisticsFilter;
use OCA\Attendance\Service\StatisticsRangeException;
use OCA\Attendance\Service\StatisticsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * @psalm-import-type AttendanceStatistics from \OCA\Attendance\ResponseDefinitions
 * @psalm-import-type AttendanceStatisticsPersonDetail from \OCA\Attendance\ResponseDefinitions
 * @psalm-import-type AttendanceExportResult from \OCA\Attendance\ResponseDefinitions
 */
class StatisticsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private PermissionService $permissionService,
		private StatisticsService $statisticsService,
		private StatisticsExportService $statisticsExportService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Evaluate responses and attendance across appointments
	 *
	 * Users without the see_statistics permission get their own row plus the
	 * group and overall averages, and no chart series.
	 *
	 * @param ?string $startDate Start of the range (Y-m-d, inclusive)
	 * @param ?string $endDate End of the range (Y-m-d, inclusive)
	 * @param list<int> $categoryIds Categories to include; empty means every category
	 * @param bool $includeUncategorized Also include appointments without a category
	 * @param string $groupBy Section axis: `groups` or `teams`
	 * @return DataResponse<Http::STATUS_OK, AttendanceStatistics, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string, found: int, limit: int}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function index(
		?string $startDate = null,
		?string $endDate = null,
		array $categoryIds = [],
		bool $includeUncategorized = false,
		string $groupBy = StatisticsFilter::GROUP_BY_GROUPS,
	): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$filter = $this->filterFrom($startDate, $endDate, $categoryIds, $includeUncategorized, $groupBy);

		$limitToUserId = $this->permissionService->canSeeStatistics($user->getUID())
			? null
			: $user->getUID();

		try {
			return new DataResponse($this->statisticsService->getStatistics($filter, $limitToUserId));
		} catch (StatisticsRangeException $e) {
			return $this->tooManyAppointments($e);
		}
	}

	/**
	 * List one person's appointments behind their statistics row
	 *
	 * @param string $targetUserId The person to drill into
	 * @param ?string $startDate Start of the range (Y-m-d, inclusive)
	 * @param ?string $endDate End of the range (Y-m-d, inclusive)
	 * @param list<int> $categoryIds Categories to include; empty means every category
	 * @param bool $includeUncategorized Also include appointments without a category
	 * @return DataResponse<Http::STATUS_OK, AttendanceStatisticsPersonDetail, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string, found: int, limit: int}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function person(
		string $targetUserId,
		?string $startDate = null,
		?string $endDate = null,
		array $categoryIds = [],
		bool $includeUncategorized = false,
	): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($targetUserId !== $user->getUID() && !$this->permissionService->canSeeStatistics($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions'], Http::STATUS_FORBIDDEN);
		}

		$filter = $this->filterFrom($startDate, $endDate, $categoryIds, $includeUncategorized);

		try {
			return new DataResponse($this->statisticsService->getPersonDetail($filter, $targetUserId));
		} catch (StatisticsRangeException $e) {
			return $this->tooManyAppointments($e);
		}
	}

	/**
	 * Export the statistics table as an ODS file
	 *
	 * @param ?string $startDate Start of the range (Y-m-d, inclusive)
	 * @param ?string $endDate End of the range (Y-m-d, inclusive)
	 * @param list<int> $categoryIds Categories to include; empty means every category
	 * @param bool $includeUncategorized Also include appointments without a category
	 * @param string $groupBy Section axis: `groups` or `teams`
	 * @return DataResponse<Http::STATUS_OK, AttendanceExportResult, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function export(
		?string $startDate = null,
		?string $endDate = null,
		array $categoryIds = [],
		bool $includeUncategorized = false,
		string $groupBy = StatisticsFilter::GROUP_BY_GROUPS,
	): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// The file holds every person's numbers, so unlike the view it has no
		// reduced shape — without the permission there is nothing to export.
		if (!$this->permissionService->canSeeStatistics($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions'], Http::STATUS_FORBIDDEN);
		}

		$filter = $this->filterFrom($startDate, $endDate, $categoryIds, $includeUncategorized, $groupBy);

		try {
			return new DataResponse($this->statisticsExportService->exportToOds($user->getUID(), $filter));
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * @param list<int> $categoryIds
	 */
	private function filterFrom(
		?string $startDate,
		?string $endDate,
		array $categoryIds,
		bool $includeUncategorized,
		string $groupBy = StatisticsFilter::GROUP_BY_GROUPS,
	): StatisticsFilter {
		return StatisticsFilter::fromWire(
			$startDate,
			$endDate,
			array_map('intval', $categoryIds),
			$includeUncategorized,
			$groupBy,
		);
	}

	/**
	 * @return DataResponse<Http::STATUS_BAD_REQUEST, array{error: string, found: int, limit: int}, array{}>
	 */
	private function tooManyAppointments(StatisticsRangeException $e): DataResponse {
		return new DataResponse([
			'error' => 'Too many appointments in range',
			'found' => $e->getFound(),
			'limit' => $e->getLimit(),
		], Http::STATUS_BAD_REQUEST);
	}
}
