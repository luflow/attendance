<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Service\GuestService;
use OCA\Attendance\Service\PermissionService;
use OCA\Attendance\Service\VisibilityService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class VisibilityServiceTest extends TestCase {
	/** @var IGroupManager|MockObject */
	private $groupManager;

	/** @var IUserManager|MockObject */
	private $userManager;

	/** @var PermissionService|MockObject */
	private $permissionService;

	/** @var GuestService|MockObject */
	private $guestService;

	/** @var ContainerInterface|MockObject */
	private $container;

	protected function setUp(): void {
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->guestService = $this->createMock(GuestService::class);
		// No Circles app in unit tests — the service must cope without it.
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willThrowException(new \Exception('Circles not available'));
	}

	/**
	 * @param list<string> $stubbedMethods
	 * @return VisibilityService|MockObject
	 */
	private function partialMock(array $stubbedMethods) {
		return $this->getMockBuilder(VisibilityService::class)
			->setConstructorArgs([
				$this->groupManager,
				$this->userManager,
				$this->permissionService,
				$this->guestService,
				$this->container,
			])
			->onlyMethods($stubbedMethods)
			->getMock();
	}

	/**
	 * @param array<string, list<string>> $membersByTeam
	 * @return VisibilityService|MockObject
	 */
	private function serviceWithTeamMembers(array $membersByTeam) {
		$service = $this->partialMock(['getTeamMembers']);
		$service->method('getTeamMembers')
			->willReturnCallback(static fn (string $teamId): array => $membersByTeam[$teamId] ?? []);
		return $service;
	}

	/**
	 * Regression: the restricted branch loaded visible_users and
	 * visible_groups but not visible_teams, so a team-only restricted
	 * appointment reported an empty audience — no non-responders anywhere.
	 */
	public function testGetRelevantUsersIncludesVisibleTeamMembers(): void {
		$appointment = new Appointment();
		$appointment->setVisibleUsers(json_encode(['carol']));
		$appointment->setVisibleGroups('[]');
		$appointment->setVisibleTeams(json_encode(['team-1']));

		$carol = $this->createMock(IUser::class);
		$dave = $this->createMock(IUser::class);
		$this->userManager->method('get')->willReturnMap([
			['carol', $carol],
			['dave', $dave],
		]);

		$service = $this->serviceWithTeamMembers(['team-1' => ['carol', 'dave']]);
		$relevant = $service->getRelevantUsersForAppointment($appointment);

		// Carol appears once even though she is invited directly AND via team.
		$this->assertSame(['carol', 'dave'], array_keys($relevant));
	}

	/**
	 * getTargetAttendees is the one shared audience definition: relevant
	 * users narrowed by isUserTargetAttendee. Numeric-string UIDs become int
	 * array keys (issue #63); reading the UID from the object must keep the
	 * strict string type hint satisfied instead of every caller doing so.
	 */
	public function testGetTargetAttendeesFiltersNonAttendeesAndSurvivesNumericIds(): void {
		$appointment = new Appointment();
		$appointment->setVisibleUsers(json_encode(['alice', '456']));
		$appointment->setVisibleGroups('[]');
		$appointment->setVisibleTeams('[]');

		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$mallory = $this->createMock(IUser::class);
		$mallory->method('getUID')->willReturn('mallory');
		$numericUser = $this->createMock(IUser::class);
		$numericUser->method('getUID')->willReturn('456');

		$service = $this->partialMock(['getRelevantUsersForAppointment']);
		$service->method('getRelevantUsersForAppointment')
			->willReturn(['alice' => $alice, 'mallory' => $mallory, '456' => $numericUser]);

		$attendees = $service->getTargetAttendees($appointment);

		// Mallory is relevant but no target attendee; 456 survives the
		// int-key round trip without tripping the string type hint.
		$this->assertSame(['alice', 456], array_keys($attendees));
	}

	private function restrictedToAlice(): Appointment {
		$appointment = new Appointment();
		$appointment->setVisibleUsers(json_encode(['alice']));
		$appointment->setVisibleGroups('[]');
		$appointment->setVisibleTeams('[]');
		return $appointment;
	}

	public function testSeeAllAppointmentsLiftsTheAudienceRestriction(): void {
		$this->permissionService->method('canSeeAppointmentViaSeeAll')->willReturn(true);
		$this->permissionService->method('isOrganizer')->willReturn(false);

		$service = $this->partialMock(['getTeamMembers']);

		$this->assertTrue($service->canUserSeeAppointment($this->restrictedToAlice(), 'mallory'));
	}

	public function testWithoutSeeAllAppointmentsOnlyOwnAppointmentsAreVisible(): void {
		$this->permissionService->method('canSeeAppointmentViaSeeAll')->willReturn(false);
		$this->permissionService->method('isOrganizer')->willReturn(false);

		$service = $this->partialMock(['getTeamMembers']);
		$appointment = $this->restrictedToAlice();

		$this->assertTrue($service->canUserSeeAppointment($appointment, 'alice'));
		$this->assertFalse($service->canUserSeeAppointment($appointment, 'mallory'));
	}

	public function testOwnAppointmentCoversOrganizersOutsideTheAudience(): void {
		$this->permissionService->method('canSeeAppointmentViaSeeAll')->willReturn(false);
		$this->permissionService->method('isOrganizer')
			->willReturnCallback(static fn (Appointment $a, string $userId): bool => $userId === 'bob');

		$service = $this->partialMock(['getTeamMembers']);
		$appointment = $this->restrictedToAlice();

		// Bob organizes it without being invited — "My appointments" keeps it.
		$this->assertTrue($service->isUserOwnAppointment($appointment, 'bob'));
		$this->assertFalse($service->isUserTargetAttendee($appointment, 'bob'));
		$this->assertFalse($service->isUserOwnAppointment($appointment, 'mallory'));
	}

	/**
	 * A list resolves every appointment's audience from the same groups: the
	 * group manager is asked once per group, however many appointments.
	 */
	public function testGroupMembersAreReadOncePerRequest(): void {
		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$choir = $this->createMock(IGroup::class);
		$choir->method('getUsers')->willReturn(['alice' => $alice]);
		$this->groupManager->expects($this->once())->method('get')->with('choir')->willReturn($choir);

		$service = $this->partialMock(['getTeamMembers']);

		$first = new Appointment();
		$first->setVisibleUsers('[]');
		$first->setVisibleGroups(json_encode(['choir']));
		$first->setVisibleTeams('[]');
		$second = clone $first;

		$this->assertSame(['alice' => $alice], $service->getRelevantUsersForAppointment($first));
		$this->assertSame(['alice' => $alice], $service->getRelevantUsersForAppointment($second));
		$this->assertSame([$alice], $service->getGroupMembers('choir'));
	}

	/**
	 * "Everyone" and "every group" are the same for every appointment of a
	 * request, so the instance-wide lookups run once.
	 */
	public function testInstanceWideLookupsRunOncePerRequest(): void {
		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$this->userManager->expects($this->once())->method('search')->with('')->willReturn([$alice]);
		$choir = $this->createMock(IGroup::class);
		$choir->method('getGID')->willReturn('choir');
		$this->groupManager->expects($this->once())->method('search')->with('')->willReturn([$choir]);

		$service = $this->partialMock(['getTeamMembers']);
		$open = new Appointment();
		$open->setVisibleUsers('[]');
		$open->setVisibleGroups('[]');
		$open->setVisibleTeams('[]');

		$this->assertSame(['alice' => $alice], $service->getRelevantUsersForAppointment($open));
		$this->assertSame(['alice' => $alice], $service->getRelevantUsersForAppointment(clone $open));
		$this->assertSame(['choir'], $service->getAllGroupIds());
		$this->assertSame(['choir'], $service->getAllGroupIds());
	}

	public function testEnrichAudienceLabelsWhatItKnowsAndFallsBackToTheId(): void {
		$alice = $this->createMock(IUser::class);
		$alice->method('getDisplayName')->willReturn('Alice');
		$this->userManager->method('get')->willReturnMap([['alice', $alice], ['gone', null]]);
		$this->guestService->method('isGuestUser')->willReturnCallback(static fn (string $userId): bool => $userId === 'gone');
		$choir = $this->createMock(IGroup::class);
		$choir->method('getDisplayName')->willReturn('Choir');
		$this->groupManager->method('get')->with('choir')->willReturn($choir);

		$service = $this->partialMock(['getTeamMembers']);

		$this->assertSame([
			'visibleUsers' => [
				['id' => 'alice', 'label' => 'Alice', 'type' => 'user', 'isGuest' => false],
				['id' => 'gone', 'label' => 'gone', 'type' => 'user', 'isGuest' => true],
			],
			'visibleGroups' => [['id' => 'choir', 'label' => 'Choir', 'type' => 'group']],
			// No Circles app: the team keeps its id as the label.
			'visibleTeams' => [['id' => 't1', 'label' => 't1', 'type' => 'team']],
		], $service->enrichAudience(['alice', 'gone'], ['choir'], ['t1']));
	}
}
