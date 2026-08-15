<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Db\CategoryMapper;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\GuestService;
use OCA\Attendance\Service\StatisticsFilter;
use OCA\Attendance\Service\StatisticsRangeException;
use OCA\Attendance\Service\StatisticsService;
use OCA\Attendance\Service\VisibilityService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StatisticsServiceTest extends TestCase {
	private const NOW = '2026-06-01 12:00:00';

	/** @var AppointmentMapper|MockObject */
	private $appointmentMapper;
	/** @var AttendanceResponseMapper|MockObject */
	private $responseMapper;
	/** @var CategoryMapper|MockObject */
	private $categoryMapper;
	/** @var ConfigService|MockObject */
	private $configService;
	/** @var VisibilityService|MockObject */
	private $visibilityService;
	/** @var GuestService|MockObject */
	private $guestService;
	/** @var IGroupManager|MockObject */
	private $groupManager;
	/** @var IUserManager|MockObject */
	private $userManager;

	private StatisticsService $service;

	/** @var array<string, IUser> */
	private array $users = [];

	protected function setUp(): void {
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);
		$this->responseMapper = $this->createMock(AttendanceResponseMapper::class);
		$this->categoryMapper = $this->createMock(CategoryMapper::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->visibilityService = $this->createMock(VisibilityService::class);
		$this->guestService = $this->createMock(GuestService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn(new \DateTime(self::NOW, new \DateTimeZone('UTC')));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->categoryMapper->method('findAll')->willReturn([]);
		$this->configService->method('getWhitelistedTeams')->willReturn([]);

		$this->service = new StatisticsService(
			$this->appointmentMapper,
			$this->responseMapper,
			$this->categoryMapper,
			$this->configService,
			$this->visibilityService,
			$this->guestService,
			$this->groupManager,
			$this->userManager,
			$timeFactory,
			$l10n,
		);
	}

	public function testCountsResponsesAndAttendanceForTargetAttendees(): void {
		$this->givenUsers(['alice' => 'Alice', 'bob' => 'Bob']);
		$this->givenUnrestrictedVisibility();
		$this->givenGroupSections();

		$past = $this->appointment(1, '2026-05-01 18:00:00', '2026-05-01 20:00:00');
		$upcoming = $this->appointment(2, '2026-07-01 18:00:00', '2026-07-01 20:00:00');
		$this->appointmentMapper->method('findForStatistics')->willReturn([$past, $upcoming]);

		$this->givenResponses([
			$this->response(1, 'alice', 'yes', 'yes'),
			$this->response(1, 'bob', 'yes', 'no'),
			$this->response(2, 'alice', 'no', ''),
		]);

		$result = $this->service->getStatistics($this->filter());

		$alice = $this->person($result, 'alice');
		$this->assertSame(2, $alice['targetCount']);
		$this->assertSame(1, $alice['yes']);
		$this->assertSame(1, $alice['no']);
		$this->assertSame(1, $alice['present']);
		$this->assertSame(1, $alice['attendanceBase'], 'only the past appointment carries attendance');
		$this->assertSame(1.0, $alice['responseRate']);
		$this->assertSame(1.0, $alice['attendanceRate']);

		$bob = $this->person($result, 'bob');
		$this->assertSame(1, $bob['noResponse'], 'the upcoming appointment counts as unanswered');
		$this->assertSame(1, $bob['absent']);
		$this->assertSame(1, $bob['noShow'], 'said yes, recorded absent');
		$this->assertSame(0.5, $bob['responseRate']);
		$this->assertSame(0.0, $bob['attendanceRate']);
	}

	public function testAppointmentsWithoutAnyCheckinStayOutOfTheAttendanceBasis(): void {
		$this->givenUsers(['alice' => 'Alice']);
		$this->givenUnrestrictedVisibility();
		$this->givenGroupSections();

		$withCheckin = $this->appointment(1, '2026-05-01 18:00:00', '2026-05-01 20:00:00');
		$withoutCheckin = $this->appointment(2, '2026-05-08 18:00:00', '2026-05-08 20:00:00');
		$this->appointmentMapper->method('findForStatistics')->willReturn([$withCheckin, $withoutCheckin]);

		$this->givenResponses([
			$this->response(1, 'alice', 'yes', 'yes'),
			$this->response(2, 'alice', 'yes', ''),
		]);

		$result = $this->service->getStatistics($this->filter());
		$alice = $this->person($result, 'alice');

		$this->assertSame(2, $alice['targetCount']);
		$this->assertSame(1, $alice['attendanceBase']);
		$this->assertSame(1.0, $alice['attendanceRate'], 'the unrecorded appointment must not dilute the rate');
		$this->assertSame(2, $result['pastCount']);
		$this->assertSame(1, $result['attendanceRecordedCount']);
	}

	public function testFilterReachesTheMapperVerbatim(): void {
		$this->givenUsers(['alice' => 'Alice']);
		$this->givenUnrestrictedVisibility();
		$this->givenGroupSections();

		$this->appointmentMapper->expects($this->once())
			->method('findForStatistics')
			->with('2026-01-01', '2026-12-31', [7], true, StatisticsService::MAX_APPOINTMENTS + 1)
			->willReturn([]);
		$this->givenResponses([]);

		$filter = StatisticsFilter::fromWire('2026-01-01', '2026-12-31', [7], true);
		$result = $this->service->getStatistics($filter);

		$this->assertSame(0, $result['appointmentCount']);
	}

	public function testPeopleAppearInEveryWhitelistedGroupButCountOnceInTheTotals(): void {
		$this->givenUsers(['alice' => 'Alice']);
		$this->givenUnrestrictedVisibility();
		$this->givenGroups(['sopranos' => ['alice'], 'board' => ['alice']]);
		$this->givenGroupSections(['sopranos', 'board'], ['sopranos', 'board', 'hidden']);

		$this->appointmentMapper->method('findForStatistics')->willReturn([
			$this->appointment(1, '2026-05-01 18:00:00', '2026-05-01 20:00:00'),
		]);
		$this->givenResponses([
			$this->response(1, 'alice', 'yes', 'yes'),
		]);

		$result = $this->service->getStatistics($this->filter());

		$this->assertSame(['sopranos', 'board'], array_column($result['sections'], 'id'));
		$this->assertSame([1, 1], array_column($result['sections'], 'personCount'));
		$this->assertSame(1, $result['totals']['targetCount'], 'the totals row counts Alice once');
		$this->assertSame(['sopranos', 'board'], $this->person($result, 'alice')['sections']);
	}

	public function testSectionsFollowTheAdminOrderAndPushOthersLast(): void {
		$this->givenUsers(['alice' => 'Alice', 'bob' => 'Bob']);
		$this->givenGroups(['sopranos' => ['alice']]);
		$this->configService->method('getWhitelistedGroups')->willReturn(['basses', 'sopranos']);
		// Bob is addressed directly, so he is in the audience without being in
		// any whitelisted group — that is what the Others bucket is for.
		$this->groupManager->method('getUserGroupIds')->willReturnCallback(
			static fn (IUser $user): array => $user->getUID() === 'alice' ? ['sopranos'] : ['nowhere'],
		);

		$appointment = $this->appointment(1, '2026-05-01 18:00:00', '2026-05-01 20:00:00');
		$this->visibilityService->method('getVisibilitySettings')->willReturn([
			'users' => ['bob'],
			'groups' => ['sopranos'],
			'teams' => [],
		]);

		$this->appointmentMapper->method('findForStatistics')->willReturn([$appointment]);
		$this->givenResponses([]);

		$result = $this->service->getStatistics($this->filter());

		$this->assertSame(['sopranos', StatisticsService::SECTION_OTHERS], array_column($result['sections'], 'id'));
	}

	public function testResponsesOfDeletedUsersAreIgnored(): void {
		$this->givenUsers(['alice' => 'Alice']);
		$this->givenUnrestrictedVisibility();
		$this->givenGroupSections();

		$this->appointmentMapper->method('findForStatistics')->willReturn([
			$this->appointment(1, '2026-05-01 18:00:00', '2026-05-01 20:00:00'),
		]);
		// The row of a deleted account survives in att_responses; it has no
		// audience membership any more, so every rate over it would be wrong.
		$this->givenResponses([
			$this->response(1, 'alice', 'yes', 'yes'),
			$this->response(1, 'ghost', 'yes', 'yes'),
		]);

		$result = $this->service->getStatistics($this->filter());

		$this->assertSame(['alice'], array_column($result['people'], 'userId'));
		$this->assertSame(1, $result['totals']['targetCount']);
	}

	public function testReducedShapeIsTheOwnRowAndNothingElse(): void {
		$this->givenUsers(['alice' => 'Alice', 'bob' => 'Bob']);
		$this->givenUnrestrictedVisibility();
		$this->givenGroupSections();

		$this->appointmentMapper->method('findForStatistics')->willReturn([
			$this->appointment(1, '2026-05-01 18:00:00', '2026-05-01 20:00:00'),
		]);
		$this->givenResponses([
			$this->response(1, 'alice', 'yes', 'yes'),
			$this->response(1, 'bob', 'no', 'no'),
		]);

		$result = $this->service->getStatistics($this->filter(), 'bob');

		$this->assertSame(['bob'], array_column($result['people'], 'userId'));
		$this->assertSame([], $result['sections'], 'no group comparison without the permission');
		$this->assertSame(1, $result['totals']['targetCount'], 'totals cover the own row alone');
		$this->assertSame([], $result['timeline']);
		$this->assertSame([], $result['byCategory']);
	}

	/**
	 * The scheduled rate counts a yes only once the inquiry is closed and
	 * somebody got a place. Alice is scheduled on the closed one, Bob is not;
	 * the open inquiry and the closed one nobody was scheduled for decide
	 * nothing and must stay out of the basis entirely.
	 */
	public function testScheduledRateOnlyCountsDecidedInquiries(): void {
		$this->givenUsers(['alice' => 'Alice', 'bob' => 'Bob']);
		$this->givenUnrestrictedVisibility();
		$this->givenGroupSections();
		$this->configService->method('isBookingEnabled')->willReturn(true);

		$decided = $this->appointment(1, '2026-05-01 18:00:00', '2026-05-01 20:00:00');
		$decided->setClosedAt('2026-04-25 10:00:00');
		$open = $this->appointment(2, '2026-06-01 18:00:00', '2026-06-01 20:00:00');
		$unused = $this->appointment(3, '2026-07-01 18:00:00', '2026-07-01 20:00:00');
		$unused->setClosedAt('2026-06-25 10:00:00');

		$this->appointmentMapper->method('findForStatistics')->willReturn([$decided, $open, $unused]);
		$this->givenResponses([
			$this->response(1, 'alice', 'yes', '', null, 'booked'),
			$this->response(1, 'bob', 'yes', '', null, null),
			$this->response(2, 'alice', 'yes', '', null, null),
			$this->response(2, 'bob', 'yes', '', null, null),
			$this->response(3, 'alice', 'yes', '', null, null),
			$this->response(3, 'bob', 'yes', '', null, null),
		]);

		$result = $this->service->getStatistics($this->filter());
		$people = array_column($result['people'], null, 'userId');

		$this->assertSame(1, $people['alice']['schedulingBase'], 'only the decided inquiry counts');
		$this->assertSame(1, $people['alice']['scheduled']);
		$this->assertSame(0, $people['alice']['notScheduled']);
		$this->assertSame(1.0, $people['alice']['scheduledRate']);

		$this->assertSame(1, $people['bob']['schedulingBase']);
		$this->assertSame(0, $people['bob']['scheduled']);
		$this->assertSame(1, $people['bob']['notScheduled']);
		$this->assertSame(0.0, $people['bob']['scheduledRate']);
	}

	/**
	 * Being told "you are not scheduled" is recorded in bookingNotifiedStatus,
	 * not in bookingStatus — the mapper has to fold the two like
	 * BookingService::effectiveBookingStatus() does.
	 */
	public function testDecliningAnswersStillCountTowardsTheBasis(): void {
		$this->givenUsers(['alice' => 'Alice', 'bob' => 'Bob']);
		$this->givenUnrestrictedVisibility();
		$this->givenGroupSections();
		$this->configService->method('isBookingEnabled')->willReturn(true);

		$closed = $this->appointment(1, '2026-05-01 18:00:00', '2026-05-01 20:00:00');
		$closed->setClosedAt('2026-04-25 10:00:00');
		$this->appointmentMapper->method('findForStatistics')->willReturn([$closed]);
		$this->givenResponses([
			$this->response(1, 'alice', 'yes', '', null, 'booked'),
			$this->response(1, 'bob', 'yes', '', null, 'declined'),
		]);

		$result = $this->service->getStatistics($this->filter());
		$people = array_column($result['people'], null, 'userId');

		$this->assertSame(1, $people['bob']['schedulingBase']);
		$this->assertSame(1, $people['bob']['notScheduled']);
	}

	public function testNothingIsScheduledWhenPlanningIsOff(): void {
		$this->givenUsers(['alice' => 'Alice']);
		$this->givenUnrestrictedVisibility();
		$this->givenGroupSections();
		$this->configService->method('isBookingEnabled')->willReturn(false);

		$closed = $this->appointment(1, '2026-05-01 18:00:00', '2026-05-01 20:00:00');
		$closed->setClosedAt('2026-04-25 10:00:00');
		$this->appointmentMapper->method('findForStatistics')->willReturn([$closed]);
		$this->givenResponses([
			$this->response(1, 'alice', 'yes', '', null, 'booked'),
		]);

		$result = $this->service->getStatistics($this->filter());

		$this->assertSame(0, $result['people'][0]['schedulingBase']);
		$this->assertNull($result['people'][0]['scheduledRate'], 'no basis, no rate');
	}

	/**
	 * Only a yes can be scheduled, so a no or an unanswered row must not widen
	 * the basis — otherwise the rate would punish people for declining.
	 */
	public function testOnlyAcceptancesFormTheSchedulingBasis(): void {
		$this->givenUsers(['alice' => 'Alice', 'bob' => 'Bob']);
		$this->givenUnrestrictedVisibility();
		$this->givenGroupSections();
		$this->configService->method('isBookingEnabled')->willReturn(true);

		$closed = $this->appointment(1, '2026-05-01 18:00:00', '2026-05-01 20:00:00');
		$closed->setClosedAt('2026-04-25 10:00:00');
		$this->appointmentMapper->method('findForStatistics')->willReturn([$closed]);
		$this->givenResponses([
			$this->response(1, 'alice', 'yes', '', null, 'booked'),
			$this->response(1, 'bob', 'no', '', null, null),
		]);

		$result = $this->service->getStatistics($this->filter());
		$people = array_column($result['people'], null, 'userId');

		$this->assertSame(0, $people['bob']['schedulingBase']);
		$this->assertNull($people['bob']['scheduledRate']);
		$this->assertSame(1, $result['totals']['schedulingBase'], 'the totals row agrees');
	}

	/**
	 * Feeds the "longest not attended" card, so it has to be the *latest*
	 * appointment the person turned up for, and null for somebody who never did.
	 */
	public function testRecordsWhenEachPersonWasLastPresent(): void {
		$this->givenUsers(['alice' => 'Alice', 'bob' => 'Bob']);
		$this->givenUnrestrictedVisibility();
		$this->givenGroupSections();

		$this->appointmentMapper->method('findForStatistics')->willReturn([
			$this->appointment(1, '2026-05-01 18:00:00', '2026-05-01 20:00:00'),
			$this->appointment(2, '2026-05-08 18:00:00', '2026-05-08 20:00:00'),
		]);
		$this->givenResponses([
			$this->response(1, 'alice', 'yes', 'yes'),
			$this->response(2, 'alice', 'yes', 'yes'),
			$this->response(1, 'bob', 'yes', 'yes'),
			$this->response(2, 'bob', 'yes', 'no'),
		]);

		$people = array_column($this->service->getStatistics($this->filter())['people'], null, 'userId');

		$this->assertStringStartsWith('2026-05-08', (string)$people['alice']['lastPresentAt'], 'the later of the two');
		$this->assertStringStartsWith('2026-05-01', (string)$people['bob']['lastPresentAt'], 'absent on the later one');
	}

	public function testLastPresentIsNullForSomebodyWhoNeverTurnedUp(): void {
		$this->givenUsers(['alice' => 'Alice']);
		$this->givenUnrestrictedVisibility();
		$this->givenGroupSections();

		$this->appointmentMapper->method('findForStatistics')->willReturn([
			$this->appointment(1, '2026-05-01 18:00:00', '2026-05-01 20:00:00'),
		]);
		$this->givenResponses([
			$this->response(1, 'alice', 'no', 'no'),
		]);

		$this->assertNull($this->service->getStatistics($this->filter())['people'][0]['lastPresentAt']);
	}

	public function testRefusesRangesBeyondTheAppointmentLimit(): void {
		$appointments = [];
		for ($id = 1; $id <= StatisticsService::MAX_APPOINTMENTS + 1; $id++) {
			$appointments[] = $this->appointment($id, '2026-05-01 18:00:00', '2026-05-01 20:00:00');
		}
		$this->appointmentMapper->method('findForStatistics')->willReturn($appointments);

		$this->expectException(StatisticsRangeException::class);
		$this->service->getStatistics($this->filter());
	}

	public function testPersonDetailListsTheAppointmentsBehindTheRow(): void {
		$this->givenUsers(['alice' => 'Alice']);
		$this->givenUnrestrictedVisibility();
		$this->givenGroupSections();

		$this->appointmentMapper->method('findForStatistics')->willReturn([
			$this->appointment(1, '2026-05-01 18:00:00', '2026-05-01 20:00:00'),
		]);
		$this->givenResponses([
			$this->response(1, 'alice', 'no', 'yes'),
		]);

		$detail = $this->service->getPersonDetail($this->filter(), 'alice');

		$this->assertCount(1, $detail['entries']);
		$this->assertSame('no', $detail['entries'][0]['response']);
		$this->assertSame('yes', $detail['entries'][0]['checkinState']);
		$this->assertTrue($detail['entries'][0]['attendanceRecorded']);
	}

	/**
	 * The drill-down reads newest first, while findForStatistics() — which also
	 * feeds the chronological timeline — hands them over oldest first.
	 */
	public function testPersonDetailListsTheNewestAppointmentFirst(): void {
		$this->givenUsers(['alice' => 'Alice']);
		$this->givenUnrestrictedVisibility();
		$this->givenGroupSections();

		$this->appointmentMapper->method('findForStatistics')->willReturn([
			$this->appointment(1, '2026-05-01 18:00:00', '2026-05-01 20:00:00'),
			$this->appointment(2, '2026-06-01 18:00:00', '2026-06-01 20:00:00'),
			$this->appointment(3, '2026-07-01 18:00:00', '2026-07-01 20:00:00'),
		]);
		$this->givenResponses([
			$this->response(1, 'alice', 'yes', 'yes'),
			$this->response(2, 'alice', 'no', ''),
			$this->response(3, 'alice', 'maybe', ''),
		]);

		$detail = $this->service->getPersonDetail($this->filter(), 'alice');

		$this->assertSame([3, 2, 1], array_column($detail['entries'], 'appointmentId'));
	}

	public function testPersonDetailWithholdsCommentsUnlessAsked(): void {
		$this->givenUsers(['alice' => 'Alice']);
		$this->givenUnrestrictedVisibility();
		$this->givenGroupSections();

		$this->appointmentMapper->method('findForStatistics')->willReturn([
			$this->appointment(1, '2026-05-01 18:00:00', '2026-05-01 20:00:00'),
		]);
		$this->givenResponses([
			$this->response(1, 'alice', 'yes', 'yes', 'On my way'),
		]);

		$withheld = $this->service->getPersonDetail($this->filter(), 'alice');
		$this->assertNull($withheld['entries'][0]['comment']);

		$granted = $this->service->getPersonDetail($this->filter(), 'alice', true);
		$this->assertSame('On my way', $granted['entries'][0]['comment']);
	}

	/**
	 * The target set is resolved per appointment rather than per candidate, so
	 * it has to keep agreeing with the shared audience rule.
	 */
	public function testRestrictedVisibilityMatchesTheVisibilityServiceRule(): void {
		$this->givenUsers(['alice' => 'Alice', 'bob' => 'Bob', 'carol' => 'Carol']);

		$appointment = $this->appointment(1, '2026-05-01 18:00:00', '2026-05-01 20:00:00');
		$this->visibilityService->method('getVisibilitySettings')->willReturn([
			'users' => ['carol'],
			'groups' => ['sopranos'],
			'teams' => ['team-1'],
		]);
		$this->visibilityService->method('getTeamMembers')->with('team-1')->willReturn(['bob']);
		$this->givenGroups(['sopranos' => ['alice']]);

		$this->appointmentMapper->method('findForStatistics')->willReturn([$appointment]);
		$this->givenResponses([]);
		$this->givenGroupSections();

		$result = $this->service->getStatistics($this->filter());

		$userIds = array_column($result['people'], 'userId');
		sort($userIds);
		$this->assertSame(['alice', 'bob', 'carol'], $userIds, 'users, groups and teams all form the audience');
	}

	private function filter(): StatisticsFilter {
		return StatisticsFilter::fromWire(null, null);
	}

	/**
	 * @param array<string, string> $users userId → display name
	 */
	private function givenUsers(array $users): void {
		foreach ($users as $userId => $displayName) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
			$user->method('getDisplayName')->willReturn($displayName);
			$this->users[$userId] = $user;
		}

		$mocks = $this->users;
		$this->userManager->method('get')->willReturnCallback(
			static fn (string $userId): ?IUser => $mocks[$userId] ?? null,
		);
		$this->guestService->method('isGuestUser')->willReturn(false);
	}

	/**
	 * Appointment open to everyone: the audience is whatever the whitelist —
	 * or the absence of one — resolves to.
	 */
	private function givenUnrestrictedVisibility(): void {
		$this->visibilityService->method('getVisibilitySettings')
			->willReturn(['users' => [], 'groups' => [], 'teams' => []]);
		$this->visibilityService->method('getRelevantUsersForAppointment')->willReturn($this->users);
	}

	/**
	 * @param list<string> $whitelisted
	 * @param list<string> $memberships Groups every user belongs to
	 */
	private function givenGroupSections(array $whitelisted = [], array $memberships = []): void {
		$this->configService->method('getWhitelistedGroups')->willReturn($whitelisted);
		$this->groupManager->method('getUserGroupIds')->willReturn($memberships);
	}

	/**
	 * @param array<string, list<string>> $groups groupId → member user IDs
	 */
	private function givenGroups(array $groups): void {
		$mocks = [];
		foreach ($groups as $groupId => $memberIds) {
			$members = [];
			foreach ($memberIds as $memberId) {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($memberId);
				$user->method('getDisplayName')->willReturn(ucfirst($memberId));
				$members[] = $user;
			}

			$group = $this->createMock(IGroup::class);
			$group->method('getUsers')->willReturn($members);
			$group->method('getDisplayName')->willReturn((string)$groupId);
			$mocks[(string)$groupId] = $group;
		}

		$this->groupManager->method('get')->willReturnCallback(
			static fn (string $groupId): ?IGroup => $mocks[$groupId] ?? null,
		);
	}

	private function appointment(int $id, string $start, string $end): Appointment {
		$appointment = new Appointment();
		$appointment->setId($id);
		$appointment->setName('Appointment ' . $id);
		$appointment->setStartDatetime($start);
		$appointment->setEndDatetime($end);
		$appointment->setVisibleUsers('[]');
		$appointment->setVisibleGroups('[]');
		$appointment->setVisibleTeams('[]');

		return $appointment;
	}

	/**
	 * @return array{appointmentId: int, userId: string, response: ?string, checkinState: ?string, bookingStatus: ?string}
	 */
	private function response(
		int $appointmentId,
		string $userId,
		string $answer,
		string $checkinState,
		?string $comment = null,
		?string $bookingStatus = null,
	): array {
		return [
			'appointmentId' => $appointmentId,
			'userId' => $userId,
			'response' => $answer,
			'checkinState' => $checkinState === '' ? null : $checkinState,
			'bookingStatus' => $bookingStatus,
			'comment' => $comment,
		];
	}

	/**
	 * Stubs both response queries from one row set, so a test never has to keep
	 * the rows and the "was anyone checked in" flags in step by hand.
	 *
	 * @param list<array{appointmentId: int, userId: string, response: ?string, checkinState: ?string, bookingStatus: ?string, comment: ?string}> $rows
	 */
	private function givenResponses(array $rows): void {
		$this->responseMapper->method('findStatisticsRows')->willReturnCallback(
			// Mirrors the mapper: without $withComments the column is not even
			// selected, so no caller can read a comment it did not ask for.
			static function (array $ids, ?string $userId = null, bool $withComments = false) use ($rows): array {
				$matching = array_filter(
					$rows,
					static fn (array $row): bool => in_array($row['appointmentId'], $ids, true)
						&& ($userId === null || $row['userId'] === $userId),
				);

				return array_values(array_map(
					static fn (array $row): array => [...$row, 'comment' => $withComments ? $row['comment'] : null],
					$matching,
				));
			},
		);

		$withCheckins = [];
		foreach ($rows as $row) {
			if (in_array($row['checkinState'], ['yes', 'no'], true)) {
				$withCheckins[$row['appointmentId']] = true;
			}
		}
		$this->responseMapper->method('findAppointmentIdsWithCheckins')->willReturn(array_keys($withCheckins));
	}

	private function person(array $result, string $userId): array {
		foreach ($result['people'] as $person) {
			if ($person['userId'] === $userId) {
				return $person;
			}
		}

		$this->fail('No statistics row for ' . $userId);
	}
}
