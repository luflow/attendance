<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
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
	 * Regression: a user added only via visibleUsers (and not part of any
	 * whitelisted group) used to be dropped from the summary entirely. The
	 * Others bucket exists precisely so such users still surface.
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

	/**
	 * Regression: members reaching an appointment only through a visibility
	 * group outside the admin whitelist used to vanish — no group section
	 * (correct), but also no Others entry and no global non-responder count.
	 * They must surface under Others like directly invited users do.
	 */
	public function testGetResponseSummaryListsVisibleGroupMembersOutsideWhitelistUnderOthers(): void {
		$appointmentId = 6;
		$appointment = new Appointment();
		$appointment->setId($appointmentId);
		$appointment->setVisibleUsers('[]');
		$appointment->setVisibleGroups(json_encode(['board']));
		$appointment->setVisibleTeams('[]');

		$response = new AttendanceResponse();
		$response->setId(1);
		$response->setAppointmentId($appointmentId);
		$response->setUserId('alice');
		$response->setResponse('yes');

		$this->appointmentMapper->method('find')->with($appointmentId)->willReturn($appointment);
		$this->responseMapper->method('findByAppointment')->with($appointmentId)->willReturn([$response]);

		// The whitelist names a different group than the appointment targets.
		$this->configService->method('getWhitelistedGroups')->willReturn(['staff']);
		$this->configService->method('getWhitelistedTeams')->willReturn([]);

		$this->visibilityService->method('getVisibilitySettings')
			->willReturn(['users' => [], 'groups' => ['board'], 'teams' => []]);
		$this->visibilityService->method('hasRestrictedVisibility')->willReturn(true);
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);

		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$alice->method('getDisplayName')->willReturn('Alice');
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$bob->method('getDisplayName')->willReturn('Bob');

		$boardGroup = $this->createMock(IGroup::class);
		$boardGroup->method('getGID')->willReturn('board');
		$staffGroup = $this->createMock(IGroup::class);
		$staffGroup->method('getGID')->willReturn('staff');
		$staffGroup->method('getUsers')->willReturn([]);

		$this->groupManager->method('get')->with('staff')->willReturn($staffGroup);
		$this->userManager->method('get')->willReturnMap([['alice', $alice], ['bob', $bob]]);
		$this->groupManager->method('getUserGroups')->willReturn([$boardGroup]);

		$this->visibilityService->method('getRelevantUsersForAppointment')
			->willReturn(['alice' => $alice, 'bob' => $bob]);

		$summary = $this->service->getResponseSummary($appointmentId);

		// 'board' is not whitelisted, so no group section renders for it …
		$this->assertSame([], $summary['by_group']);
		// … but Alice's answer and Bob's silence both surface under Others.
		$this->assertSame(1, $summary['others']['yes']);
		$this->assertCount(1, $summary['others']['responses']);
		$this->assertSame('Alice', $summary['others']['responses'][0]['userName']);
		$this->assertSame(1, $summary['no_response']);
		$this->assertSame(1, $summary['others']['no_response']);
		$othersIds = array_map(static fn (array $u): string => $u['userId'], $summary['others']['non_responding_users']);
		$this->assertSame(['bob'], $othersIds);
	}

	/**
	 * Security regression: the free-text comment / checkinComment fields carry
	 * potentially sensitive content and must only be exposed to callers who
	 * hold PERMISSION_SEE_COMMENTS. With $includeComments = false the serialized
	 * responses must omit them entirely, while the rest of the summary is intact.
	 */
	public function testGetResponseSummaryStripsCommentsWhenNotPermitted(): void {
		$appointmentId = 4;
		$appointment = new Appointment();
		$appointment->setId($appointmentId);
		$appointment->setVisibleUsers('[]');
		$appointment->setVisibleGroups('[]');
		$appointment->setVisibleTeams('[]');

		$response = new AttendanceResponse();
		$response->setId(1);
		$response->setAppointmentId($appointmentId);
		$response->setUserId('alice');
		$response->setResponse('yes');
		$response->setComment('Secret personal reason');
		$response->setCheckinComment('Secret checkin note');

		$this->appointmentMapper->method('find')->with($appointmentId)->willReturn($appointment);
		$this->responseMapper->method('findByAppointment')->with($appointmentId)->willReturn([$response]);

		// No whitelist → alice lands in the "others" bucket.
		$this->configService->method('getWhitelistedGroups')->willReturn([]);
		$this->configService->method('getWhitelistedTeams')->willReturn([]);

		$this->visibilityService->method('getVisibilitySettings')
			->willReturn(['users' => [], 'groups' => [], 'teams' => []]);
		$this->visibilityService->method('hasRestrictedVisibility')->willReturn(false);
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);
		$this->visibilityService->method('getRelevantUsersForAppointment')->willReturn([]);

		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$alice->method('getDisplayName')->willReturn('Alice');

		$this->userManager->method('get')->with('alice')->willReturn($alice);
		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->groupManager->method('search')->with('')->willReturn([]);

		// Without permission: comment fields stripped.
		$stripped = $this->service->getResponseSummary($appointmentId, false);
		$this->assertCount(1, $stripped['others']['responses']);
		$strippedResponse = $stripped['others']['responses'][0];
		$this->assertArrayNotHasKey('comment', $strippedResponse);
		$this->assertArrayNotHasKey('checkinComment', $strippedResponse);
		$this->assertSame('yes', $strippedResponse['response']);
		$this->assertSame('Alice', $strippedResponse['userName']);

		// With permission: comment fields retained.
		$full = $this->service->getResponseSummary($appointmentId, true);
		$fullResponse = $full['others']['responses'][0];
		$this->assertSame('Secret personal reason', $fullResponse['comment']);
		$this->assertSame('Secret checkin note', $fullResponse['checkinComment']);
	}

	/**
	 * The counts-only summary is sent to users holding only the counts
	 * permission — it must carry the aggregate numbers and nothing that could
	 * identify a responder.
	 */
	public function testGetResponseCountsCarriesOnlyAggregateNumbers(): void {
		$appointmentId = 5;
		$appointment = new Appointment();
		$appointment->setId($appointmentId);
		$appointment->setVisibleUsers('[]');
		$appointment->setVisibleGroups('[]');
		$appointment->setVisibleTeams('[]');

		$response = new AttendanceResponse();
		$response->setId(1);
		$response->setAppointmentId($appointmentId);
		$response->setUserId('alice');
		$response->setResponse('yes');
		$response->setComment('Secret personal reason');

		$this->appointmentMapper->method('find')->with($appointmentId)->willReturn($appointment);
		$this->responseMapper->method('findByAppointment')->with($appointmentId)->willReturn([$response]);

		$this->configService->method('getWhitelistedGroups')->willReturn([]);
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);

		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$alice->method('getDisplayName')->willReturn('Alice');
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$bob->method('getDisplayName')->willReturn('Bob');

		// Bob never answered → counted as no_response.
		$this->visibilityService->method('getRelevantUsersForAppointment')
			->willReturn(['alice' => $alice, 'bob' => $bob]);

		$counts = $this->service->getResponseCounts($appointmentId);

		$this->assertSame(1, $counts['yes']);
		$this->assertSame(0, $counts['no']);
		$this->assertSame(0, $counts['maybe']);
		$this->assertSame(1, $counts['no_response']);
		$this->assertSame([], $counts['by_group']);
		$this->assertSame([], $counts['by_team']);
		$this->assertSame([], $counts['others']['responses']);
		$this->assertSame([], $counts['others']['non_responding_users']);

		// Nothing in the payload may identify a responder.
		$encoded = json_encode($counts);
		$this->assertStringNotContainsString('alice', $encoded);
		$this->assertStringNotContainsString('Alice', $encoded);
		$this->assertStringNotContainsString('bob', $encoded);
		$this->assertStringNotContainsString('Secret', $encoded);
	}
}
