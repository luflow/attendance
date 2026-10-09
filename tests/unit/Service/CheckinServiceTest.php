<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Service\AppointmentSerializer;
use OCA\Attendance\Service\AuditEventService;
use OCA\Attendance\Service\CapacityService;
use OCA\Attendance\Service\CheckinService;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\GuestService;
use OCA\Attendance\Service\VisibilityService;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CheckinServiceTest extends TestCase {
	private AppointmentMapper|MockObject $appointmentMapper;
	private AttendanceResponseMapper|MockObject $responseMapper;
	private ConfigService|MockObject $configService;
	private VisibilityService|MockObject $visibilityService;
	private IGroupManager|MockObject $groupManager;
	private CheckinService $service;

	protected function setUp(): void {
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);
		$this->responseMapper = $this->createMock(AttendanceResponseMapper::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->visibilityService = $this->createMock(VisibilityService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->service = new CheckinService(
			$this->appointmentMapper,
			$this->responseMapper,
			$this->configService,
			$this->visibilityService,
			$this->groupManager,
			$this->createMock(GuestService::class),
			$this->createMock(AuditEventService::class),
			$this->createMock(CapacityService::class),
			$this->createMock(AppointmentSerializer::class),
		);
	}

	private function user(string $uid): IUser|MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	private function checkin(string $userId, string $state): AttendanceResponse {
		$response = new AttendanceResponse();
		$response->setUserId($userId);
		$response->setCheckinState($state);
		return $response;
	}

	/**
	 * @param array<string, list<string>> $groupsByUser The audience and each member's groups
	 */
	private function givenAudience(array $groupsByUser): void {
		$this->appointmentMapper->method('find')->willReturn(new Appointment());

		$attendees = [];
		foreach (array_keys($groupsByUser) as $uid) {
			$attendees[$uid] = $this->user($uid);
		}
		$this->visibilityService->method('getTargetAttendees')->willReturn($attendees);
		$this->groupManager->method('getUserGroupIds')
			->willReturnCallback(fn (IUser $user) => $groupsByUser[$user->getUID()]);
	}

	/**
	 * @param list<string> $whitelist
	 * @param list<string|int> $restrictionGroups The appointment's own visibility groups
	 */
	private function givenGrouping(string $mode, array $whitelist = [], array $restrictionGroups = []): void {
		$this->configService->method('getResponseSummaryGroupsMode')->willReturn($mode);
		$this->configService->method('getWhitelistedGroups')->willReturn($whitelist);
		$this->visibilityService->method('getVisibilitySettings')
			->willReturn(['users' => [], 'groups' => $restrictionGroups, 'teams' => []]);
	}

	/**
	 * @return array<string, list<string>> The groups shipped per user
	 */
	private function shippedGroups(array $checkinData): array {
		return array_column($checkinData['users'], 'groups', 'userId');
	}

	/**
	 * "No grouping" ships no sections at all (issue #212): the clients render
	 * a flat list without a group filter.
	 */
	public function testNoGroupingShipsAFlatList(): void {
		$this->givenGrouping(ConfigService::RESPONSE_SUMMARY_MODE_NONE);
		$this->givenAudience(['alice' => ['choir', 'board'], 'bob' => []]);
		$this->visibilityService->expects($this->never())->method('getAllGroupIds');

		$data = $this->service->getCheckinData(7);

		$this->assertSame([], $data['userGroups']);
		$this->assertSame(['alice' => ['Others'], 'bob' => ['Others']], $this->shippedGroups($data));
	}

	public function testAllGroupsSectionsByEveryGroup(): void {
		$this->givenGrouping(ConfigService::RESPONSE_SUMMARY_MODE_ALL);
		$this->givenAudience(['alice' => ['choir', 'board'], 'bob' => []]);
		$this->visibilityService->method('getAllGroupIds')->willReturn(['board', 'choir', 'guest_app']);

		$data = $this->service->getCheckinData(7);

		$this->assertSame(['board', 'choir', 'Others'], $data['userGroups']);
		$this->assertSame(['alice' => ['choir', 'board'], 'bob' => ['Others']], $this->shippedGroups($data));
	}

	/**
	 * A user carries only the sections they belong to, spelled as the admin's
	 * list spells them, so clients match them without normalising.
	 */
	public function testSpecificGroupsSectionsByTheWhitelist(): void {
		$this->givenGrouping(ConfigService::RESPONSE_SUMMARY_MODE_SPECIFIC, ['Choir']);
		$this->givenAudience(['alice' => ['choir', 'board'], 'bob' => ['board']]);

		$data = $this->service->getCheckinData(7);

		$this->assertSame(['Choir'], $data['userGroups']);
		$this->assertSame(['alice' => ['Choir'], 'bob' => ['Others']], $this->shippedGroups($data));
	}

	/**
	 * A restricted appointment keeps sectioning by the groups that let people
	 * in, as the summary does (issue #199), even with grouping switched off.
	 */
	public function testRestrictedAppointmentSectionsByItsOwnGroups(): void {
		$this->givenGrouping(ConfigService::RESPONSE_SUMMARY_MODE_NONE, [], ['soprano', 42]);
		$this->givenAudience(['alice' => ['soprano'], 'bob' => ['board']]);

		$data = $this->service->getCheckinData(7);

		$this->assertSame(['soprano', '42'], $data['userGroups']);
		$this->assertSame(['alice' => ['soprano'], 'bob' => ['Others']], $this->shippedGroups($data));
	}

	/**
	 * The list hands over the row's entity and its responses; the summary
	 * counts the audience by check-in state without reading anything itself.
	 */
	public function testSummaryCountsTheAudienceByCheckinState(): void {
		$appointment = new Appointment();
		$appointment->setId(7);
		$this->visibilityService->method('getTargetAttendees')->willReturn([
			'alice' => $this->user('alice'),
			'bob' => $this->user('bob'),
			'carol' => $this->user('carol'),
			'dave' => $this->user('dave'),
		]);
		$this->appointmentMapper->expects($this->never())->method('find');
		$this->responseMapper->expects($this->never())->method('findByAppointment');

		$summary = $this->service->getCheckinSummary($appointment, [
			$this->checkin('alice', 'yes'),
			$this->checkin('bob', 'no'),
			$this->checkin('carol', ''),
			// Checked in, but no longer in the audience — not counted.
			$this->checkin('mallory', 'yes'),
		]);

		$this->assertSame(['attended' => 1, 'absent' => 1, 'notCheckedIn' => 2, 'hasCheckins' => true], $summary);
	}
}
