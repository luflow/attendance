<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Audit\Verb;
use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Service\AuditEventService;
use OCA\Attendance\Service\CapacityService;
use OCA\Attendance\Service\NotificationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CapacityServiceTest extends TestCase {
	/** @var AttendanceResponseMapper|MockObject */
	private $responseMapper;

	/** @var NotificationService|MockObject */
	private $notificationService;

	/** @var AuditEventService|MockObject */
	private $auditEventService;

	private CapacityService $service;

	protected function setUp(): void {
		$this->responseMapper = $this->createMock(AttendanceResponseMapper::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->auditEventService = $this->createMock(AuditEventService::class);
		$this->service = new CapacityService(
			$this->responseMapper,
			$this->notificationService,
			$this->auditEventService,
		);
	}

	private function appointment(?int $limit, bool $waitlist = true): Appointment {
		$appointment = new Appointment();
		$appointment->setId(1);
		$appointment->setMaxAttendees($limit);
		$appointment->setWaitlistEnabled($waitlist);
		return $appointment;
	}

	/**
	 * @param list<string> $userIds in queue order
	 * @param array<string, ?string> $notified per-user notification marker
	 */
	private function queue(array $userIds, array $notified = []): void {
		$rows = [];
		foreach ($userIds as $userId) {
			$response = new AttendanceResponse();
			$response->setUserId($userId);
			$response->setResponse('yes');
			$response->setWaitlistNotifiedStatus($notified[$userId] ?? null);
			$rows[] = $response;
		}
		$this->responseMapper->method('findClaimedSpots')->willReturn($rows);
	}

	public function testNoLimitMeansNoQueryAndNobodyWaiting(): void {
		$this->responseMapper->expects($this->never())->method('findClaimedSpots');
		$appointment = $this->appointment(null);

		$this->assertNull($this->service->limitOf($appointment));
		$this->assertSame(0, $this->service->occupancy($appointment));
		$this->assertFalse($this->service->isFull($appointment));
		$this->assertFalse($this->service->isWaitlistEnabled($appointment));
		$this->assertTrue($this->service->holdsSpot($appointment, 'alice'));
	}

	public function testZeroIsReadAsNoLimit(): void {
		$this->assertNull($this->service->limitOf($this->appointment(0)));
	}

	public function testTheFirstOnesInHoldTheSpotsAndTheRestQueueInOrder(): void {
		$this->queue(['alice', 'bob', 'carol', 'dave']);
		$appointment = $this->appointment(2);

		$this->assertTrue($this->service->holdsSpot($appointment, 'alice'));
		$this->assertTrue($this->service->holdsSpot($appointment, 'bob'));
		$this->assertFalse($this->service->holdsSpot($appointment, 'carol'));

		$this->assertSame(
			['waitlisted' => true, 'waitlistPosition' => 1],
			$this->service->standingOf($appointment, 'carol'),
		);
		$this->assertSame(
			['waitlisted' => true, 'waitlistPosition' => 2],
			$this->service->standingOf($appointment, 'dave'),
		);
		$this->assertSame(
			['waitlisted' => false, 'waitlistPosition' => null],
			$this->service->standingOf($appointment, 'alice'),
		);
	}

	public function testOccupancyMayExceedTheLimitWithoutDemotingAnyone(): void {
		$this->queue(['alice', 'bob', 'carol']);
		// The organizer lowered the limit under what is already taken.
		$appointment = $this->appointment(2);

		$this->assertSame(3, $this->service->occupancy($appointment));
		$this->assertTrue($this->service->isFull($appointment));
		$this->assertTrue($this->service->holdsSpot($appointment, 'alice'));
	}

	public function testClaimingASpotStampsTheOrderingKey(): void {
		$response = new AttendanceResponse();

		$this->service->applySpotClaim($response, null, 'yes');

		$this->assertNotNull($response->getSpotClaimedAt());
	}

	public function testResavingAYesKeepsThePlaceInLine(): void {
		$response = new AttendanceResponse();
		$response->setSpotClaimedAt('2026-01-01 10:00:00');

		$this->service->applySpotClaim($response, 'yes', 'yes');

		$this->assertSame('2026-01-01 10:00:00', $response->getSpotClaimedAt());
	}

	public function testLeavingTheQueueGivesUpThePlaceEntirely(): void {
		$response = new AttendanceResponse();
		$response->setSpotClaimedAt('2026-01-01 10:00:00');
		$response->setWaitlistNotifiedStatus(CapacityService::NOTIFIED_WAITLISTED);

		$this->service->applySpotClaim($response, 'yes', 'no');

		// Answering yes again later joins the back, and can be notified afresh.
		$this->assertNull($response->getSpotClaimedAt());
		$this->assertNull($response->getWaitlistNotifiedStatus());
	}

	public function testPromotionNotifiesOnlySomebodyWhoWasWaiting(): void {
		$this->queue(
			['alice', 'bob'],
			['alice' => null, 'bob' => CapacityService::NOTIFIED_WAITLISTED],
		);
		$appointment = $this->appointment(2);

		// Only bob moved from waiting to holding a spot; alice was never told
		// anything, so there is nothing to tell her now.
		$this->notificationService->expects($this->once())
			->method('sendWaitlistNotification')
			->with($appointment, 'bob', 'waitlist_promoted');
		$this->auditEventService->expects($this->once())
			->method('record')
			->with(Verb::WAITLIST_PROMOTED, 1, null, 'bob');

		$this->service->syncWaitlistNotifications($appointment);
	}

	public function testJoiningTheQueueIsRecordedButNotAnnounced(): void {
		$this->queue(['alice', 'bob']);
		$appointment = $this->appointment(1);

		$this->notificationService->expects($this->never())->method('sendWaitlistNotification');
		$this->responseMapper->expects($this->once())
			->method('update')
			->with($this->callback(
				fn (AttendanceResponse $r): bool => $r->getUserId() === 'bob'
					&& $r->getWaitlistNotifiedStatus() === CapacityService::NOTIFIED_WAITLISTED,
			));

		$this->service->syncWaitlistNotifications($appointment);
	}

	public function testAnAlreadyMarkedWaiterIsNotMarkedAgain(): void {
		$this->queue(
			['alice', 'bob'],
			['bob' => CapacityService::NOTIFIED_WAITLISTED],
		);
		$appointment = $this->appointment(1);

		$this->responseMapper->expects($this->never())->method('update');

		$this->service->syncWaitlistNotifications($appointment);
	}

	public function testClosingTellsEveryoneStillWaitingOnce(): void {
		$this->queue(
			['alice', 'bob', 'carol'],
			[
				'bob' => CapacityService::NOTIFIED_WAITLISTED,
				'carol' => CapacityService::NOTIFIED_NOT_PROMOTED,
			],
		);
		$appointment = $this->appointment(1);

		// carol already heard; alice holds the only spot.
		$this->notificationService->expects($this->once())
			->method('sendWaitlistNotification')
			->with($appointment, 'bob', 'waitlist_not_promoted');

		$this->service->notifyNotPromoted($appointment);
	}
}
