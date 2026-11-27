<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentAttachment;
use OCA\Attendance\Db\AppointmentAttachmentMapper;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Db\Category;
use OCA\Attendance\Db\CategoryMapper;
use OCA\Attendance\Db\IcalToken;
use OCA\Attendance\Db\IcalTokenMapper;
use OCA\Attendance\Service\AuditEventService;
use OCA\Attendance\Service\BookingService;
use OCA\Attendance\Service\CapacityService;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\IcalService;
use OCA\Attendance\Service\NotificationService;
use OCA\Attendance\Service\PermissionService;
use OCA\Attendance\Service\ResponseSummaryService;
use OCA\Attendance\Service\TalkRoomService;
use OCA\Attendance\Service\TimezoneService;
use OCA\Attendance\Service\VisibilityService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Config\IUserConfig;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\L10N\IFactory as IL10NFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IcalServiceTest extends TestCase {
	private ConfigService $configService;
	private AppointmentAttachmentMapper $attachmentMapper;
	/** @var list<AppointmentAttachment> */
	private array $attachments = [];
	private CategoryMapper $categoryMapper;

	/** @var AttendanceResponseMapper|MockObject */
	private $responseMapper;
	/** @var AttendanceResponseMapper|MockObject */
	private $feedResponseMapper;
	/** @var AppointmentMapper|MockObject */
	private $appointmentMapper;
	/** @var VisibilityService|MockObject */
	private $visibilityService;
	/** @var PermissionService|MockObject */
	private $permissionService;
	/** @var ResponseSummaryService|MockObject */
	private $responseSummaryService;
	private IcalService $service;

	protected function setUp(): void {
		$this->configService = $this->createMock(ConfigService::class);
		$this->attachmentMapper = $this->createMock(AppointmentAttachmentMapper::class);
		$this->attachmentMapper->method('findByAppointment')->willReturnCallback(fn () => $this->attachments);
		$this->categoryMapper = $this->createMock(CategoryMapper::class);
		$this->responseMapper = $this->createMock(AttendanceResponseMapper::class);
		$this->feedResponseMapper = $this->createMock(AttendanceResponseMapper::class);
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);
		$this->visibilityService = $this->createMock(VisibilityService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->responseSummaryService = $this->createMock(ResponseSummaryService::class);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturn('https://example.test/');
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			fn (string $route, array $params = []) => 'https://example.test/' . $route . '/' . implode('/', $params),
		);

		$l10nFactory = $this->createMock(IL10NFactory::class);
		$l10nFactory->method('get')->willReturn($this->createMock(IL10N::class));

		$userConfig = $this->createMock(IUserConfig::class);
		$userConfig->method('getValueString')->willReturn('Europe/Berlin');
		$timezoneService = new TimezoneService($userConfig, $this->createMock(IDateTimeZone::class));

		$this->service = new IcalService(
			$this->createMock(IcalTokenMapper::class),
			$this->appointmentMapper,
			$this->attachmentMapper,
			$this->feedResponseMapper,
			$this->visibilityService,
			$this->configService,
			// The real thing: effectiveBookingStatus() is the rule under test,
			// and it only reads the row it is handed.
			new BookingService(
				$this->createMock(AttendanceResponseMapper::class),
				$this->createMock(AppointmentMapper::class),
				$this->configService,
				$this->createMock(NotificationService::class),
				$this->createMock(TalkRoomService::class),
			),
			$this->createMock(ISecureRandom::class),
			$urlGenerator,
			$l10nFactory,
			$this->createMock(IConfig::class),
			$this->categoryMapper,
			// Real again: whether a yes holds a spot is what decides the title and
			// the busy flag, and it only reads the queue it is handed.
			new CapacityService(
				$this->responseMapper,
				$this->createMock(NotificationService::class),
				$this->createMock(AuditEventService::class),
			),
			$timezoneService,
			$this->permissionService,
			$this->responseSummaryService,
		);
	}

	private function appointment(): Appointment {
		$a = new Appointment();
		$a->setId(5);
		$a->setName('Team sync');
		$a->setDescription('');
		$a->setStartDatetime('2026-07-20 10:00:00');
		$a->setEndDatetime('2026-07-20 11:00:00');
		$a->setCreatedAt('2026-07-01 09:00:00');
		$a->setUpdatedAt('2026-07-01 09:00:00');
		return $a;
	}

	private function l10n(): IL10N&MockObject {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => $params === [] ? $text : vsprintf($text, $params),
		);
		return $l;
	}

	private function generate(Appointment $appointment, ?AttendanceResponse $response): string {
		$l = $this->l10n();
		$ref = new \ReflectionMethod(IcalService::class, 'generateVEvent');
		return $ref->invoke($this->service, $appointment, $response, 'alice', $l, 'example.test', []);
	}

	/**
	 * @param array{yes: list<string>, no: list<string>, maybe: list<string>} $responders
	 * @param list<AttendanceResponse> $responses
	 */
	private function generateForOrganizer(Appointment $appointment, array $responders, array $responses = []): string {
		$this->responseSummaryService->method('getRespondersByAnswer')->willReturn($responders);
		$l = $this->l10n();
		$l->method('n')->willReturnCallback(
			fn (string $singular, string $plural, int $count) => str_replace('%n', (string)$count, $plural),
		);
		$ref = new \ReflectionMethod(IcalService::class, 'generateOrganizerVEvent');
		return $ref->invoke($this->service, $appointment, $responses, $l, 'example.test');
	}

	private function upcoming(int $id): Appointment {
		$a = $this->appointment();
		$a->setId($id);
		$a->setStartDatetime((new \DateTime('+7 days'))->format('Y-m-d H:i:s'));
		$a->setEndDatetime((new \DateTime('+7 days 1 hour'))->format('Y-m-d H:i:s'));
		return $a;
	}

	private function organizerToken(): IcalToken {
		$token = new IcalToken();
		$token->setUserId('alice');
		$token->setKind(IcalToken::KIND_ORGANIZER);
		return $token;
	}

	private function closedAppointment(): Appointment {
		$a = $this->appointment();
		$a->setClosedAt('2026-07-10 12:00:00');
		return $a;
	}

	private function yes(?string $booking, ?string $notified = null): AttendanceResponse {
		$r = new AttendanceResponse();
		$r->setId(1);
		$r->setAppointmentId(5);
		$r->setUserId('alice');
		$r->setResponse('yes');
		$r->setBookingStatus($booking);
		$r->setBookingNotifiedStatus($notified);
		$r->setRespondedAt('2026-07-02 09:00:00');
		return $r;
	}

	/** Issue #269: stored as Berlin midnights, fed as the days they stand for. */
	public function testAllDayAppointmentIsFedAsWholeDays(): void {
		$appointment = $this->appointment();
		$appointment->setStartDatetime('2026-07-19 22:00:00');
		$appointment->setEndDatetime('2026-07-20 22:00:00');
		$appointment->setAllDay(true);

		$out = $this->generate($appointment, null);

		$this->assertStringContainsString("DTSTART;VALUE=DATE:20260720\r\n", $out);
		$this->assertStringContainsString("DTEND;VALUE=DATE:20260721\r\n", $out);
	}

	public function testPlannedInIsBusyWithTitleMarker(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$out = $this->generate($this->closedAppointment(), $this->yes('booked'));
		$this->assertStringContainsString('Scheduled', $out);
		$this->assertStringContainsString('TRANSP:OPAQUE', $out);
	}

	public function testNotPlannedInIsFreeWithTitleMarker(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		// What the close-time wave recorded for somebody who was passed over.
		$out = $this->generate($this->closedAppointment(), $this->yes(null, 'declined'));
		$this->assertStringContainsString('Not scheduled', $out);
		$this->assertStringContainsString('TRANSP:TRANSPARENT', $out);
	}

	public function testOpenInquiryKeepsThePlainAnswer(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		// Nobody has been passed over while people can still answer, so the
		// title must not pass a verdict the organizer has not made yet.
		$out = $this->generate($this->appointment(), $this->yes(null));
		$this->assertStringContainsString('Yes', $out);
		$this->assertStringNotContainsString('scheduled', $out);
		$this->assertStringContainsString('TRANSP:OPAQUE', $out);
	}

	public function testOpenInquiryKeepsThePlainAnswerEvenWhenAlreadyBooked(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		// An organizer may plan people in before closing; until they close, the
		// verdict is not final and the calendar says nothing about it.
		$out = $this->generate($this->appointment(), $this->yes('booked'));
		$this->assertStringContainsString('Yes', $out);
		$this->assertStringNotContainsString('Scheduled', $out);
	}

	public function testClosedWithoutAnyPlanningKeepsThePlainAnswer(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		// The wave never ran because nobody got a place, so there is no verdict
		// to show — not even a "not scheduled" one.
		$out = $this->generate($this->closedAppointment(), $this->yes(null));
		$this->assertStringContainsString('Yes', $out);
		$this->assertStringNotContainsString('scheduled', $out);
	}

	public function testBookingIgnoredWhenFeatureDisabled(): void {
		$this->configService->method('isBookingEnabled')->willReturn(false);
		$out = $this->generate($this->closedAppointment(), $this->yes('booked'));
		// Falls back to the plain response suffix and the yes=busy default.
		$this->assertStringContainsString('Yes', $out);
		$this->assertStringNotContainsString('Scheduled', $out);
		$this->assertStringContainsString('TRANSP:OPAQUE', $out);
	}

	public function testNoColorPropertyEmitted(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$out = $this->generate($this->closedAppointment(), $this->yes('booked'));
		// COLOR is deliberately not used (Google ignores it in subscribed feeds).
		$this->assertStringNotContainsString('COLOR', $out);
	}

	public function testLocationEmittedWhenSet(): void {
		$appointment = $this->appointment();
		$appointment->setLocation('Club house');
		$out = $this->generate($appointment, null);
		$this->assertStringContainsString('LOCATION:Club house', $out);
	}

	public function testNoLocationPropertyEmittedWhenNotSet(): void {
		$out = $this->generate($this->appointment(), null);
		$this->assertStringNotContainsString('LOCATION:', $out);
	}

	public function testCategoriesEmittedWhenSet(): void {
		$category = new Category();
		$category->setId(5);
		$category->setName('Rehearsal');
		$this->categoryMapper->method('find')->with(5)->willReturn($category);

		$appointment = $this->appointment();
		$appointment->setCategoryId(5);
		$out = $this->generate($appointment, null);

		$this->assertStringContainsString('CATEGORIES:Rehearsal', $out);
	}

	public function testNoCategoriesPropertyEmittedWhenNotSet(): void {
		$out = $this->generate($this->appointment(), null);
		$this->assertStringNotContainsString('CATEGORIES:', $out);
	}

	/** Issue #272: the link used a "#/appointment/…" fragment the app never routed. */
	public function testEventLinksToTheAppointmentRoute(): void {
		$out = $this->generate($this->appointment(), null);

		$this->assertStringContainsString("URL:https://example.test/attendance.page.appointment/5\r\n", $out);
		$this->assertStringNotContainsString('#/', $out);
	}

	/** Issue #330: a hand-built "/f/…" path lacks /index.php/ on servers without mod_rewrite. */
	public function testAttachmentsLinkToTheFilesRoute(): void {
		$attachment = new AppointmentAttachment();
		$attachment->setFileId(42);
		$attachment->setFileName('Setlist.pdf');
		$this->attachments = [$attachment];

		$out = $this->generate($this->appointment(), null);

		$this->assertStringContainsString("ATTACH:https://example.test/files.viewcontroller.showFile/42\r\n", $out);
		// Once in ATTACH, once in the attachment list of the DESCRIPTION.
		$this->assertSame(2, substr_count($out, 'https://example.test/files.viewcontroller.showFile/42'));
		$this->assertStringNotContainsString('/f/', $out);
	}

	public function testVtimezoneListsTheClockChangesAroundThePeriod(): void {
		$lines = IcalService::vtimezoneLines(
			new \DateTimeZone('Europe/Berlin'),
			new \DateTimeImmutable('2026-10-05 17:30:00', new \DateTimeZone('UTC')),
			new \DateTimeImmutable('2026-10-05 19:30:00', new \DateTimeZone('UTC')),
		);

		$this->assertSame(['BEGIN:VTIMEZONE', 'TZID:Europe/Berlin'], array_slice($lines, 0, 2));
		$this->assertSame('END:VTIMEZONE', end($lines));
		$observances = array_chunk(array_slice($lines, 2, -1), 6);
		// The change in force on the day: summer time since the last Sunday of March
		$this->assertContains(
			['BEGIN:DAYLIGHT', 'DTSTART:20260329T020000', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200', 'TZNAME:CEST', 'END:DAYLIGHT'],
			$observances,
		);
		$this->assertContains(
			['BEGIN:STANDARD', 'DTSTART:20261025T030000', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'TZNAME:CET', 'END:STANDARD'],
			$observances,
		);
	}

	public function testVtimezoneOfAZoneWithoutClockChanges(): void {
		$when = new \DateTimeImmutable('2026-10-05 12:00:00', new \DateTimeZone('UTC'));

		$this->assertSame([
			'BEGIN:VTIMEZONE',
			'TZID:Asia/Kolkata',
			'BEGIN:STANDARD',
			'DTSTART:19700101T000000',
			'TZOFFSETFROM:+0530',
			'TZOFFSETTO:+0530',
			'TZNAME:IST',
			'END:STANDARD',
			'END:VTIMEZONE',
		], IcalService::vtimezoneLines(new \DateTimeZone('Asia/Kolkata'), $when, $when));
	}

	public function testNoCategoriesPropertyEmittedWhenCategoryDeleted(): void {
		$this->categoryMapper->method('find')->with(5)
			->willThrowException(new DoesNotExistException('not found'));

		$appointment = $this->appointment();
		$appointment->setCategoryId(5);
		$out = $this->generate($appointment, null);

		$this->assertStringNotContainsString('CATEGORIES:', $out);
	}

	public function testOrganizerEventNamesWhoAnsweredWhat(): void {
		$ics = $this->generateForOrganizer($this->appointment(), [
			'yes' => ['Anna', 'Ben'],
			'no' => ['Carl'],
			'maybe' => [],
		]);

		$this->assertStringContainsString("SUMMARY:Team sync (2 ✓ · 1 ✗ · 0 ?)\r\n", $ics);
		$this->assertStringContainsString('2 attending (Anna\\, Ben)\\n1 declined (Carl)\\n0 maybe\\n', $ics);
	}

	public function testOrganizerEventWithoutAnswersKeepsThePlainTitle(): void {
		$ics = $this->generateForOrganizer($this->appointment(), ['yes' => [], 'no' => [], 'maybe' => []]);

		$this->assertStringContainsString("SUMMARY:Team sync\r\n", $ics);
	}

	/** Both feeds may sit in one calendar app: no shared UID, no second busy block, no second alarm. */
	public function testOrganizerEventStaysOutOfThePersonalOnesWay(): void {
		$this->configService->method('getUserIcalReminderTriggers')->willReturn(['PT1H']);
		$ics = $this->generateForOrganizer($this->appointment(), ['yes' => ['Alice'], 'no' => [], 'maybe' => []], [$this->yes(null)]);

		$this->assertStringContainsString("UID:attendance-organizer-5@example.test\r\n", $ics);
		$this->assertStringContainsString("TRANSP:TRANSPARENT\r\n", $ics);
		$this->assertStringNotContainsString('VALARM', $ics);
	}

	public function testOrganizerEventChangesWhenSomebodyAnswers(): void {
		$ics = $this->generateForOrganizer($this->appointment(), ['yes' => ['Alice'], 'no' => [], 'maybe' => []], [$this->yes(null)]);

		$this->assertStringContainsString("LAST-MODIFIED:20260702T090000Z\r\n", $ics);
		$this->assertStringContainsString("SEQUENCE:1\r\n", $ics);
	}

	public function testOrganizerEventMarksACancelledAppointment(): void {
		$appointment = $this->appointment();
		$appointment->setCancelledAt('2026-07-10 12:00:00');
		$ics = $this->generateForOrganizer($appointment, ['yes' => [], 'no' => [], 'maybe' => []]);

		$this->assertStringContainsString("SUMMARY:Cancelled: Team sync\r\n", $ics);
		$this->assertStringContainsString("STATUS:CANCELLED\r\n", $ics);
	}

	public function testCancelledEventOpensWithTheReason(): void {
		$appointment = $this->appointment();
		$appointment->setDescription('Bring sheet music');
		$appointment->setCancelledAt('2026-07-10 12:00:00');
		$appointment->setCancelReason('Venue flooded');

		$this->assertStringContainsString('DESCRIPTION:Reason for cancellation: Venue flooded\\n\\nBring sheet music', $this->generate($appointment, null));
		$this->assertStringContainsString(
			'DESCRIPTION:Reason for cancellation: Venue flooded\\n\\nBring sheet music',
			$this->generateForOrganizer($appointment, ['yes' => [], 'no' => [], 'maybe' => []]),
		);
	}

	public function testCancelledEventWithoutReasonKeepsItsDescription(): void {
		$appointment = $this->appointment();
		$appointment->setCancelledAt('2026-07-10 12:00:00');

		$this->assertStringNotContainsString('Reason for cancellation', $this->generate($appointment, null));
	}

	public function testOrganizerTokenUnlocksOnlyWhatItsHolderManages(): void {
		$managed = $this->upcoming(7);
		$foreign = $this->upcoming(8);
		$this->appointmentMapper->method('findAll')->willReturn([$managed, $foreign]);
		$this->visibilityService->method('getEnabledUser')->with('alice')->willReturn($this->createMock(IUser::class));
		$this->permissionService->method('canManageAppointment')->willReturnCallback(
			fn (string $userId, Appointment $appointment) => $userId === 'alice' && $appointment->getId() === 7,
		);
		$this->feedResponseMapper->expects($this->once())->method('findByAppointments')->with([7])->willReturn([]);
		$this->responseSummaryService->method('getRespondersByAnswer')->willReturn(['yes' => [], 'no' => [], 'maybe' => []]);

		$ics = $this->service->generateFeedFor($this->organizerToken());

		$this->assertStringContainsString('UID:attendance-organizer-7@', $ics);
		$this->assertStringNotContainsString('UID:attendance-organizer-8@', $ics);
		$this->assertStringNotContainsString('UID:attendance-appointment-', $ics);
	}

	public function testOrganizerFeedOfADeactivatedAccountIsEmpty(): void {
		$this->appointmentMapper->method('findAll')->willReturn([$this->upcoming(7)]);
		$this->visibilityService->method('getEnabledUser')->willReturn(null);
		$this->permissionService->method('canManageAppointment')->willReturn(true);

		$ics = $this->service->generateFeedFor($this->organizerToken());

		$this->assertStringNotContainsString('BEGIN:VEVENT', $ics);
	}
}
