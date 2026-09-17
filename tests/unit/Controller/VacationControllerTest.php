<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Controller;

use OCA\Attendance\Controller\VacationController;
use OCA\Attendance\Db\Vacation;
use OCA\Attendance\Service\AppointmentService;
use OCA\Attendance\Service\VacationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class VacationControllerTest extends TestCase {
	/** @var VacationService|MockObject */
	private $vacationService;

	/** @var AppointmentService|MockObject */
	private $appointmentService;

	/** @var IUserSession|MockObject */
	private $userSession;

	private VacationController $controller;

	protected function setUp(): void {
		$this->vacationService = $this->createMock(VacationService::class);
		$this->appointmentService = $this->createMock(AppointmentService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new VacationController(
			'attendance',
			$this->createMock(IRequest::class),
			$this->userSession,
			$this->vacationService,
			$this->appointmentService,
		);
	}

	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function vacation(int $id, string $userId): Vacation {
		$vacation = new Vacation();
		$vacation->setId($id);
		$vacation->setUserId($userId);
		$vacation->setStartDate('2026-07-01');
		$vacation->setEndDate('2026-07-10');
		return $vacation;
	}

	public function testIndexReturnsOwnVacations(): void {
		$this->signIn('alice');
		$vacations = [$this->vacation(1, 'alice')];
		$this->vacationService->method('getForUser')->with('alice')->willReturn($vacations);

		$response = $this->controller->index();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($vacations, $response->getData());
	}

	public function testIndexRejectsAnonymousRequest(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->vacationService->expects($this->never())->method('getForUser');

		$response = $this->controller->index();

		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testCreateReturns201OnSuccess(): void {
		$this->signIn('alice');
		$created = $this->vacation(1, 'alice');
		$this->vacationService->method('create')->with('alice', '2026-07-01', '2026-07-10', 'Beach')
			->willReturn($created);

		$response = $this->controller->create('2026-07-01', '2026-07-10', 'Beach');

		$this->assertEquals(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame($created, $response->getData());
	}

	public function testCreateReturns400OnInvalidRange(): void {
		$this->signIn('alice');
		$this->vacationService->method('create')
			->willThrowException(new \InvalidArgumentException('End date must not be before start date.'));

		$response = $this->controller->create('2026-07-10', '2026-07-01', null);

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateReturns404ForSomeoneElsesVacation(): void {
		$this->signIn('mallory');
		$this->vacationService->method('update')
			->willThrowException(new DoesNotExistException('not found'));

		$response = $this->controller->update(1, '2026-07-01', '2026-07-10', null);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testDestroyReturnsSuccessTrue(): void {
		$this->signIn('alice');
		$this->vacationService->expects($this->once())->method('delete')->with(1, 'alice');

		$response = $this->controller->destroy(1);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['success' => true], $response->getData());
	}

	public function testDestroyReturns404WhenMissing(): void {
		$this->signIn('alice');
		$this->vacationService->method('delete')
			->willThrowException(new DoesNotExistException('not found'));

		$response = $this->controller->destroy(99);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testConflictsUsesGivenCandidatesWhenProvided(): void {
		$this->signIn('admin');
		$this->appointmentService->expects($this->never())->method('getAllWhitelistedUsers');
		$this->vacationService->expects($this->once())->method('findConflicts')
			->with('2026-06-01T10:00:00Z', '2026-06-01T12:00:00Z', ['alice', 'bob'])
			->willReturn([['userId' => 'bob', 'displayName' => 'Bob', 'startDate' => '2026-06-01', 'endDate' => '2026-06-02', 'note' => null]]);

		$response = $this->controller->conflicts('2026-06-01T10:00:00Z', '2026-06-01T12:00:00Z', ['alice', 'bob']);

		$this->assertEquals(['count' => 1, 'users' => [['userId' => 'bob', 'displayName' => 'Bob', 'startDate' => '2026-06-01', 'endDate' => '2026-06-02', 'note' => null]]], $response->getData());
	}

	public function testConflictsFallsBackToWhitelistedUsersWhenCandidatesOmitted(): void {
		$this->signIn('admin');
		$this->appointmentService->expects($this->once())->method('getAllWhitelistedUsers')->willReturn(['alice', 'bob']);
		$this->vacationService->expects($this->once())->method('findConflicts')
			->with('2026-06-01T10:00:00Z', '2026-06-01T12:00:00Z', ['alice', 'bob'])
			->willReturn([]);

		$response = $this->controller->conflicts('2026-06-01T10:00:00Z', '2026-06-01T12:00:00Z');

		$this->assertEquals(['count' => 0, 'users' => []], $response->getData());
	}

	public function testOverviewDefaultsToTodayAndWhitelistedUsers(): void {
		$this->signIn('admin');
		$this->appointmentService->method('getAllWhitelistedUsers')->willReturn(['alice']);
		$this->vacationService->expects($this->once())->method('getOverview')
			->with($this->isType('string'), $this->isType('string'), ['alice'])
			->willReturn([]);

		$response = $this->controller->overview();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
	}

	public function testOverviewRejectsAnonymousRequest(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->vacationService->expects($this->never())->method('getOverview');

		$response = $this->controller->overview();

		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}
}
