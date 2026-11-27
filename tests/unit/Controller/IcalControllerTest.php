<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Controller;

use OCA\Attendance\Controller\IcalController;
use OCA\Attendance\Db\IcalToken;
use OCA\Attendance\Service\AppointmentService;
use OCA\Attendance\Service\IcalService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IcalControllerTest extends TestCase {
	/** @var IcalService|MockObject */
	private $icalService;

	/** @var AppointmentService|MockObject */
	private $appointmentService;

	private IcalController $controller;

	protected function setUp(): void {
		$this->icalService = $this->createMock(IcalService::class);
		$this->appointmentService = $this->createMock(AppointmentService::class);
		$userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession->method('getUser')->willReturn($user);

		$this->controller = new IcalController(
			'attendance',
			$this->createMock(IRequest::class),
			$this->icalService,
			$userSession,
			$this->appointmentService,
		);
	}

	public function testOrganizerFeedIsRefusedToPlainMembers(): void {
		$this->appointmentService->method('actsAsOrganizer')->with('alice')->willReturn(false);
		$this->icalService->expects($this->never())->method('getOrCreateToken');
		$this->icalService->expects($this->never())->method('regenerateToken');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->getToken(IcalToken::KIND_ORGANIZER)->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->regenerateToken(IcalToken::KIND_ORGANIZER)->getStatus());
	}

	public function testOrganizerFeedGetsItsOwnUrl(): void {
		$token = new IcalToken();
		$this->appointmentService->method('actsAsOrganizer')->with('alice')->willReturn(true);
		$this->icalService->expects($this->once())->method('getOrCreateToken')
			->with('alice', IcalToken::KIND_ORGANIZER)->willReturn($token);
		$this->icalService->method('getFeedUrl')->with($token)->willReturn('https://example.test/ical/abc.ics');

		$response = $this->controller->getToken(IcalToken::KIND_ORGANIZER);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('https://example.test/ical/abc.ics', $response->getData()['feedUrl']);
	}

	/** Older clients send no kind at all. */
	public function testPersonalFeedNeedsNoOrganizerRights(): void {
		$this->appointmentService->method('actsAsOrganizer')->willReturn(false);
		$this->icalService->expects($this->once())->method('getOrCreateToken')
			->with('alice', IcalToken::KIND_PERSONAL)->willReturn(new IcalToken());

		$response = $this->controller->getToken();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertFalse($response->getData()['organizerAvailable']);
	}

	public function testPersonalFeedTellsOrganizersAboutTheirSecondFeed(): void {
		$this->appointmentService->method('actsAsOrganizer')->with('alice')->willReturn(true);
		$this->icalService->method('getOrCreateToken')->willReturn(new IcalToken());

		$this->assertTrue($this->controller->getToken()->getData()['organizerAvailable']);
	}

	public function testUnknownKindIsRejected(): void {
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->getToken('everything')->getStatus());
	}

	public function testFeedIsRenderedByTheKindOfItsToken(): void {
		$token = new IcalToken();
		$token->setKind(IcalToken::KIND_ORGANIZER);
		$this->icalService->method('validateToken')->with('abc')->willReturn($token);
		$this->icalService->expects($this->once())->method('generateFeedFor')->with($token)->willReturn("BEGIN:VCALENDAR\r\n");

		$this->assertSame(Http::STATUS_OK, $this->controller->feed('abc')->getStatus());
	}
}
