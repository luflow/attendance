<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Listener;

use OCA\Attendance\Audit\Verb;
use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AuditEvent;
use OCA\Attendance\Listener\ResponseActivityListener;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\NotificationService;
use OCA\Attendance\Service\PermissionService;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ResponseActivityListenerTest extends TestCase {
	/** @var PermissionService|MockObject */
	private $permissionService;
	/** @var ConfigService|MockObject */
	private $configService;
	/** @var AppointmentMapper|MockObject */
	private $appointmentMapper;
	/** @var NotificationService|MockObject */
	private $notificationService;

	private ResponseActivityListener $listener;

	protected function setUp(): void {
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);
		$this->notificationService = $this->createMock(NotificationService::class);

		$this->listener = new ResponseActivityListener(
			$this->permissionService,
			$this->configService,
			$this->appointmentMapper,
			$this->notificationService,
			$this->createMock(IURLGenerator::class),
		);

		$appointment = new Appointment();
		$appointment->setId(5);
		$appointment->setName('Rehearsal');
		// guest-gary slipped into the organizer list but holds no organizer rights.
		$appointment->setOrganizers(json_encode(['dave', 'guest-gary', 'alice']));
		$this->appointmentMapper->method('find')->with(5)->willReturn($appointment);
		$this->permissionService->method('isOrganizer')
			->willReturnCallback(static fn (Appointment $a, string $userId): bool => $userId !== 'guest-gary');
	}

	private function event(string $verb, array $meta = [], string $source = Verb::SOURCE_WEB): AuditEvent {
		$event = new AuditEvent();
		$event->setAppointmentId(5);
		$event->setVerb($verb);
		$event->setActorId('alice');
		$event->setSubjectId('alice');
		$event->setMetaArray($meta);
		$event->setSource($source);
		return $event;
	}

	public function testACommentEditReachesTheAuditLogReadersButNotTheActor(): void {
		$this->configService->method('getAuditLogVisibility')->willReturn(ConfigService::AUDIT_LOG_VISIBILITY_MANAGERS);
		$this->permissionService->method('getUsersWith')
			->with(PermissionService::PERMISSION_MANAGE_APPOINTMENTS)->willReturn(['carol', 'alice']);

		$this->notificationService->expects($this->once())->method('publishActivity')->with(
			'responses',
			'response_comment_updated',
			$this->callback(static fn (array $params): bool => $params['comment'] === 'Running late'),
			['carol', 'dave'],
		);

		$this->listener->handle($this->event(Verb::RESPONSE_COMMENT_UPDATED, ['response' => 'yes', 'comment_to' => 'Running late']));
	}

	public function testReadersFollowTheAuditLogVisibilitySetting(): void {
		$this->configService->method('getAuditLogVisibility')->willReturn(ConfigService::AUDIT_LOG_VISIBILITY_ALL_WITH_OVERVIEW);
		$this->permissionService->method('getUsersWith')
			->with(PermissionService::PERMISSION_SEE_RESPONSE_OVERVIEW)->willReturn(['erin']);

		$this->notificationService->expects($this->once())->method('publishActivity')
			->with('responses', 'waitlist_promoted', $this->anything(), ['erin', 'dave']);

		$this->listener->handle($this->event(Verb::WAITLIST_PROMOTED));
	}

	public function testWhenEveryoneHoldsThePermissionOnlyOrganizersGetIt(): void {
		$this->configService->method('getAuditLogVisibility')->willReturn(ConfigService::AUDIT_LOG_VISIBILITY_MANAGERS);
		$this->permissionService->method('getModeForPermission')->willReturn(PermissionService::MODE_ALL);
		$this->permissionService->expects($this->never())->method('getUsersWith');

		$this->notificationService->expects($this->once())->method('publishActivity')
			->with('responses', 'response_submitted', $this->anything(), ['dave']);

		$this->listener->handle($this->event(Verb::RESPONSE_SUBMITTED, ['response' => 'yes']));
	}

	public function testVacationAnswersAndOtherVerbsAreLeftOut(): void {
		$this->appointmentMapper->expects($this->never())->method('find');
		$this->notificationService->expects($this->never())->method('publishActivity');

		$this->listener->handle($this->event(Verb::RESPONSE_SUBMITTED, [], Verb::SOURCE_VACATION));
		$this->listener->handle($this->event(Verb::RESPONSE_RESCINDED, [], Verb::SOURCE_AUDIENCE));
		$this->listener->handle($this->event(Verb::CHECKIN_RECORDED));
	}
}
