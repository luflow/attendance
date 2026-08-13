<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Service\PermissionService;
use OCA\Attendance\Service\VisibilityService;
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

	/** @var ContainerInterface|MockObject */
	private $container;

	protected function setUp(): void {
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->permissionService = $this->createMock(PermissionService::class);
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
}
