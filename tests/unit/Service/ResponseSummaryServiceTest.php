<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\GuestService;
use OCA\Attendance\Service\ResponseSummaryService;
use OCA\Attendance\Service\VisibilityService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ResponseSummaryServiceTest extends TestCase {
	/** @var AppointmentMapper|MockObject */
	private $appointmentMapper;

	/** @var AttendanceResponseMapper|MockObject */
	private $responseMapper;

	/** @var ConfigService|MockObject */
	private $configService;

	/** @var VisibilityService|MockObject */
	private $visibilityService;

	/** @var IGroupManager|MockObject */
	private $groupManager;

	/** @var IUserManager|MockObject */
	private $userManager;

	/** @var GuestService|MockObject */
	private $guestService;

	private ResponseSummaryService $service;

	protected function setUp(): void {
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);
		$this->responseMapper = $this->createMock(AttendanceResponseMapper::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->visibilityService = $this->createMock(VisibilityService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->guestService = $this->createMock(GuestService::class);

		$this->service = new ResponseSummaryService(
			$this->appointmentMapper,
			$this->responseMapper,
			$this->configService,
			$this->visibilityService,
			$this->groupManager,
			$this->userManager,
			$this->guestService,
		);
	}

	/**
	 * Regression test for issue #63: numeric-string group IDs (e.g. "123") get
	 * coerced to int when used as PHP array keys. With no whitelist configured,
	 * array_keys($cache['groupUsers']) yielded ints that violated the string
	 * type hint on isGroupAllowedCached() and crashed appointment creation.
	 */
	public function testGetResponseSummaryWithNumericGroupIdDoesNotThrowTypeError(): void {
		$appointmentId = 1;
		$appointment = new Appointment();
		$appointment->setId($appointmentId);
		$appointment->setVisibleUsers('[]');
		$appointment->setVisibleGroups('[]');
		$appointment->setVisibleTeams('[]');

		$this->appointmentMapper->method('find')->with($appointmentId)->willReturn($appointment);
		$this->responseMapper->method('findByAppointment')->with($appointmentId)->willReturn([]);

		// No whitelist → allowAllGroups path, which triggers array_keys() on a
		// group-keyed cache where PHP has coerced "123" into int 123.
		$this->configService->method('getWhitelistedGroups')->willReturn([]);
		$this->configService->method('getWhitelistedTeams')->willReturn([]);

		$this->visibilityService->method('getVisibilitySettings')
			->willReturn(['users' => [], 'groups' => [], 'teams' => []]);
		$this->visibilityService->method('hasRestrictedVisibility')->willReturn(false);
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);
		$this->visibilityService->method('getRelevantUsersForAppointment')->willReturn([]);

		$numericGroup = $this->createMock(IGroup::class);
		$numericGroup->method('getGID')->willReturn('123');
		$numericGroup->method('getUsers')->willReturn([]);

		$this->groupManager->method('search')->with('')->willReturn([$numericGroup]);

		$summary = $this->service->getResponseSummary($appointmentId);

		$this->assertIsArray($summary);
		$this->assertArrayHasKey('by_group', $summary);
	}

	/**
	 * Regression test for the second leg of issue #63: iterating $cache['allUsers']
	 * via `as $userId => $user` coerced numeric-string UIDs to int, which then
	 * tripped VisibilityService::isUserTargetAttendee()'s string type hint.
	 */
	public function testGetResponseSummaryWithNumericUserIdDoesNotThrowTypeError(): void {
		$appointmentId = 2;
		$appointment = new Appointment();
		$appointment->setId($appointmentId);
		$appointment->setVisibleUsers('[]');
		$appointment->setVisibleGroups('[]');
		$appointment->setVisibleTeams('[]');

		$this->appointmentMapper->method('find')->with($appointmentId)->willReturn($appointment);
		$this->responseMapper->method('findByAppointment')->with($appointmentId)->willReturn([]);

		$this->configService->method('getWhitelistedGroups')->willReturn(['staff']);
		$this->configService->method('getWhitelistedTeams')->willReturn([]);

		$this->visibilityService->method('getVisibilitySettings')
			->willReturn(['users' => [], 'groups' => [], 'teams' => []]);
		$this->visibilityService->method('hasRestrictedVisibility')->willReturn(false);

		$numericUser = $this->createMock(IUser::class);
		$numericUser->method('getUID')->willReturn('456');
		$numericUser->method('getDisplayName')->willReturn('User 456');

		$staffGroup = $this->createMock(IGroup::class);
		$staffGroup->method('getGID')->willReturn('staff');
		$staffGroup->method('getUsers')->willReturn([$numericUser]);

		$this->groupManager->method('get')->with('staff')->willReturn($staffGroup);
		$this->groupManager->method('getUserGroups')->willReturn([$staffGroup]);

		// Keyed by the numeric-string UID; PHP coerces the key to int 456.
		$this->visibilityService->method('getRelevantUsersForAppointment')
			->willReturn(['456' => $numericUser]);

		// Strict string type — the original bug surfaced here too.
		$this->visibilityService->expects($this->atLeastOnce())
			->method('isUserTargetAttendee')
			->with($this->anything(), $this->isType('string'))
			->willReturn(true);

		$summary = $this->service->getResponseSummary($appointmentId);

		$this->assertIsArray($summary);
		$this->assertSame(1, $summary['no_response']);
	}

	/**
	 * Regression: an admin invites a fresh hire who isn't in any whitelisted
	 * skill group yet — either as the sole attendee or alongside a regular
	 * skill group. The directly-listed user used to be filtered out of the
	 * summary because collectMissingResponders skipped anyone without an
	 * allowed group / team / visible group, leaving the admin to wonder
	 * why "their" invitee never shows up.
	 */
	public function testGetResponseSummaryIncludesDirectlyAddressedUserOutsideAnyWhitelistedGroup(): void {
		$appointmentId = 3;
		$appointment = new Appointment();
		$appointment->setId($appointmentId);
		$appointment->setVisibleUsers(json_encode(['new_hire']));
		$appointment->setVisibleGroups('[]');
		$appointment->setVisibleTeams('[]');

		$this->appointmentMapper->method('find')->with($appointmentId)->willReturn($appointment);
		$this->responseMapper->method('findByAppointment')->with($appointmentId)->willReturn([]);

		$this->configService->method('getWhitelistedGroups')->willReturn(['staff']);
		$this->configService->method('getWhitelistedTeams')->willReturn([]);

		$this->visibilityService->method('getVisibilitySettings')
			->willReturn(['users' => ['new_hire'], 'groups' => [], 'teams' => []]);
		$this->visibilityService->method('hasRestrictedVisibility')->willReturn(true);
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);

		// `new_hire` has not been added to "staff" yet — getUserGroups returns []
		// so hasAllowedGroup/hasVisibleGroup are both false.
		$newHire = $this->createMock(IUser::class);
		$newHire->method('getUID')->willReturn('new_hire');
		$newHire->method('getDisplayName')->willReturn('New Hire');

		$staffGroup = $this->createMock(IGroup::class);
		$staffGroup->method('getGID')->willReturn('staff');
		$staffGroup->method('getUsers')->willReturn([]);

		$this->groupManager->method('get')->with('staff')->willReturn($staffGroup);
		$this->groupManager->method('getUserGroups')->willReturn([]);

		$this->visibilityService->method('getRelevantUsersForAppointment')
			->willReturn(['new_hire' => $newHire]);

		$summary = $this->service->getResponseSummary($appointmentId);

		// The user must appear in the global non-responder count and in the
		// Others bucket (no visible section to render under).
		$this->assertSame(1, $summary['no_response']);
		$this->assertSame(1, $summary['others']['no_response']);
		$othersIds = array_map(static fn (array $u): string => $u['userId'], $summary['others']['non_responding_users']);
		$this->assertContains('new_hire', $othersIds);
	}
}
