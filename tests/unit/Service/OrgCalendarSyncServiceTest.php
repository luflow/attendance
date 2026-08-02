<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Service\CalendarService;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\IcalService;
use OCA\Attendance\Service\OrgCalendarSyncService;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory as IL10NFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Minimal writable calendar double (PHPUnit cannot mock interface intersections
 * in a version-independent way).
 */
class FakeWritableCalendar implements ICalendar, ICreateFromString, ICalendarIsWritable {
	public function __construct(
		private string $key,
		private string $uri,
	) {
	}

	public function getKey(): string {
		return $this->key;
	}

	public function getUri(): string {
		return $this->uri;
	}

	public function getDisplayName(): ?string {
		return 'Org events';
	}

	public function getDisplayColor(): ?string {
		return '#00ff00';
	}

	public function search(string $pattern, array $searchProperties = [], array $options = [], ?int $limit = null, ?int $offset = null): array {
		return [];
	}

	public function getPermissions(): int {
		return 31;
	}

	public function isDeleted(): bool {
		return false;
	}

	public function isWritable(): bool {
		return true;
	}

	public function createFromString(string $name, string $calendarData): void {
	}

	public function createFromStringMinimal(string $name, string $calendarData): void {
	}
}

/**
 * Records CalDavBackend calls; getCalendarObject returns $existingObjects[uri] ?? null.
 */
class FakeCalDavBackend {
	public array $created = [];
	public array $updated = [];
	public array $deleted = [];
	public array $existingObjects = [];

	public function getCalendarById(int $calendarId): ?array {
		return ['id' => $calendarId, 'uri' => 'org-events-owner-uri'];
	}

	public function getCalendarObject($calendarId, $objectUri, int $calendarType = 0): ?array {
		return $this->existingObjects[$objectUri] ?? null;
	}

	public function createCalendarObject($calendarId, $objectUri, $calendarData, $calendarType = 0): string {
		$this->created[] = [$calendarId, $objectUri, $calendarData];
		return 'etag';
	}

	public function updateCalendarObject($calendarId, $objectUri, $calendarData, $calendarType = 0): string {
		$this->updated[] = [$calendarId, $objectUri, $calendarData];
		return 'etag';
	}

	public function deleteCalendarObject($calendarId, $objectUri, $calendarType = 0, bool $forceDeletePermanently = false): void {
		$this->deleted[] = [$calendarId, $objectUri];
	}
}

/**
 * Production service with the CalDAV backend seam replaced by the fake.
 */
class TestableOrgCalendarSyncService extends OrgCalendarSyncService {
	public ?object $fakeBackend = null;

	protected function getCalDavBackend(): ?object {
		return $this->fakeBackend;
	}
}

class OrgCalendarSyncServiceTest extends TestCase {
	/** @var CalendarService|MockObject */
	private $calendarService;

	/** @var AppointmentMapper|MockObject */
	private $appointmentMapper;

	/** @var AttendanceResponseMapper|MockObject */
	private $responseMapper;

	/** @var ConfigService|MockObject */
	private $configService;

	/** @var IcalService|MockObject */
	private $icalService;

	/** @var IURLGenerator|MockObject */
	private $urlGenerator;

	private FakeCalDavBackend $backend;
	private TestableOrgCalendarSyncService $service;

	protected function setUp(): void {
		$this->calendarService = $this->createMock(CalendarService::class);
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);
		$this->responseMapper = $this->createMock(AttendanceResponseMapper::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->icalService = $this->createMock(IcalService::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->backend = new FakeCalDavBackend();

		$this->icalService->method('escapeIcalText')->willReturnArgument(0);
		$this->icalService->method('foldIcalContent')->willReturnArgument(0);
		$this->icalService->method('getAppointmentUrl')
			->willReturnCallback(fn (int $id) => 'https://cloud.example.com/apps/attendance/#/appointment/' . $id);

		$this->urlGenerator->method('getAbsoluteURL')->willReturn('https://cloud.example.com/');

		$this->responseMapper->method('getResponseSummary')->willReturnCallback(
			fn () => $this->responseSummary,
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('n')->willReturnCallback(
			fn (string $singular, string $plural, int $count) => str_replace('%n', (string)$count, $count === 1 ? $singular : $plural),
		);
		$l10nFactory = $this->createMock(IL10NFactory::class);
		$l10nFactory->method('get')->willReturn($l10n);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('en');

		$this->service = new TestableOrgCalendarSyncService(
			$this->calendarService,
			$this->appointmentMapper,
			$this->responseMapper,
			$this->configService,
			$this->icalService,
			$this->urlGenerator,
			$l10nFactory,
			$config,
			$this->createMock(LoggerInterface::class),
		);
		$this->service->fakeBackend = $this->backend;
	}

	/** @var array<string, int> */
	private array $responseSummary = [];

	private function configureEnabled(): void {
		$this->configService->method('isOrgCalendarEnabled')->willReturn(true);
		$this->configService->method('getOrgCalendarUri')->willReturn('org-events');
		$this->configService->method('getOrgCalendarUserId')->willReturn('admin');

		$this->calendarService->method('findWritableCalendar')
			->with('admin', 'org-events')
			->willReturn(new FakeWritableCalendar('42', 'org-events'));
	}

	private function buildAppointment(int $id = 5): Appointment {
		$appointment = new Appointment();
		$appointment->setId($id);
		$appointment->setName('Rehearsal');
		$appointment->setDescription('Bring instruments');
		$appointment->setStartDatetime('2026-09-01 18:00:00');
		$appointment->setEndDatetime('2026-09-01 20:00:00');
		$appointment->setCreatedAt('2026-08-01 10:00:00');
		$appointment->setUpdatedAt('2026-08-01 10:00:00');
		$appointment->setIsActive(1);
		return $appointment;
	}

	public function testSyncSkippedWhenDisabled(): void {
		$this->configService->method('isOrgCalendarEnabled')->willReturn(false);

		$this->assertFalse($this->service->syncAppointment($this->buildAppointment()));
		$this->assertSame([], $this->backend->created);
	}

	public function testSyncSkipsImportedAppointments(): void {
		$this->configureEnabled();
		$appointment = $this->buildAppointment();
		$appointment->setCalendarUri('personal');
		$appointment->setCalendarEventUid('some-imported-uid@foreign');

		$this->assertFalse($this->service->syncAppointment($appointment));
		$this->assertSame([], $this->backend->created);
		$this->assertSame([], $this->backend->updated);
	}

	public function testSyncCreatesEventAndStoresLink(): void {
		$this->configureEnabled();
		$appointment = $this->buildAppointment();

		$this->appointmentMapper->expects($this->once())
			->method('update')
			->with($appointment)
			->willReturnArgument(0);

		$this->assertTrue($this->service->syncAppointment($appointment));

		$this->assertCount(1, $this->backend->created);
		[$calendarId, $objectUri, $ics] = $this->backend->created[0];
		$this->assertSame(42, $calendarId);
		$this->assertSame('attendance-org-5@cloud.example.com.ics', $objectUri);
		$this->assertStringContainsString('UID:attendance-org-5@cloud.example.com', $ics);
		$this->assertStringContainsString('SUMMARY:Rehearsal', $ics);
		$this->assertStringContainsString('DESCRIPTION:Bring instruments', $ics);
		$this->assertStringContainsString('DTSTART:20260901T180000Z', $ics);
		$this->assertStringContainsString('DTEND:20260901T200000Z', $ics);
		$this->assertStringContainsString('STATUS:CONFIRMED', $ics);

		// Link uses the owner-side calendar URI so the update listener matches
		$this->assertSame('org-events-owner-uri', $appointment->getCalendarUri());
		$this->assertSame('attendance-org-5@cloud.example.com', $appointment->getCalendarEventUid());
	}

	public function testSyncUpdatesExistingEventWithoutRelinking(): void {
		$this->configureEnabled();
		$appointment = $this->buildAppointment();
		$appointment->setCalendarUri('org-events-owner-uri');
		$appointment->setCalendarEventUid('attendance-org-5@cloud.example.com');
		$this->backend->existingObjects['attendance-org-5@cloud.example.com.ics'] = ['id' => 1];

		$this->appointmentMapper->expects($this->never())->method('update');

		$this->assertTrue($this->service->syncAppointment($appointment));
		$this->assertSame([], $this->backend->created);
		$this->assertCount(1, $this->backend->updated);
	}

	public function testOwnershipSurvivesDomainChange(): void {
		// UID stored under a previous instance domain: still ours (prefix match),
		// and the stored UID keeps addressing the existing object.
		$this->configureEnabled();
		$appointment = $this->buildAppointment();
		$appointment->setCalendarUri('org-events-owner-uri');
		$appointment->setCalendarEventUid('attendance-org-5@old.example.org');
		$this->backend->existingObjects['attendance-org-5@old.example.org.ics'] = ['id' => 1];

		$this->appointmentMapper->expects($this->never())->method('update');

		$this->assertTrue($this->service->syncAppointment($appointment));
		$this->assertSame([], $this->backend->created);
		$this->assertSame('attendance-org-5@old.example.org.ics', $this->backend->updated[0][1]);
	}

	public function testCancelledAppointmentGetsCancelledStatus(): void {
		$this->configureEnabled();
		$appointment = $this->buildAppointment();
		$appointment->setCancelledAt('2026-08-02 09:00:00');
		$this->appointmentMapper->method('update')->willReturnArgument(0);

		$this->assertTrue($this->service->syncAppointment($appointment));
		$this->assertStringContainsString('STATUS:CANCELLED', $this->backend->created[0][2]);
	}

	public function testInactiveAppointmentIsNotSynced(): void {
		$this->configureEnabled();
		$appointment = $this->buildAppointment();
		$appointment->setIsActive(0);

		$this->assertFalse($this->service->syncAppointment($appointment));
		$this->assertSame([], $this->backend->created);
	}

	public function testDeleteRemovesOwnEvent(): void {
		$this->configureEnabled();
		$appointment = $this->buildAppointment();
		$appointment->setCalendarEventUid('attendance-org-5@cloud.example.com');
		$this->backend->existingObjects['attendance-org-5@cloud.example.com.ics'] = ['id' => 1];

		$this->service->handleAppointmentDeleted($appointment);
		$this->assertSame([[42, 'attendance-org-5@cloud.example.com.ics']], $this->backend->deleted);
	}

	public function testDeleteNeverTouchesForeignEvents(): void {
		$this->configureEnabled();
		$appointment = $this->buildAppointment();
		$appointment->setCalendarEventUid('some-imported-uid@foreign');

		$this->service->handleAppointmentDeleted($appointment);
		$this->assertSame([], $this->backend->deleted);
	}

	public function testPatchPreservesForeignPropertiesAndAlarms(): void {
		$this->configureEnabled();
		$appointment = $this->buildAppointment();
		$appointment->setName('New title');
		$appointment->setCalendarUri('org-events-owner-uri');
		$appointment->setCalendarEventUid('attendance-org-5@cloud.example.com');
		$this->backend->existingObjects['attendance-org-5@cloud.example.com.ics'] = [
			'calendardata' => "BEGIN:VCALENDAR\r\n"
				. "VERSION:2.0\r\n"
				. "BEGIN:VEVENT\r\n"
				. "UID:attendance-org-5@cloud.example.com\r\n"
				. "SUMMARY:Old title\r\n"
				. "DTSTART:20260101T100000Z\r\n"
				. "DTEND:20260101T110000Z\r\n"
				. "LOCATION:Club house\r\n"
				. "BEGIN:VALARM\r\n"
				. "TRIGGER:-PT15M\r\n"
				. "ACTION:DISPLAY\r\n"
				. "DESCRIPTION:Old title\r\n"
				. "END:VALARM\r\n"
				. "END:VEVENT\r\n"
				. "END:VCALENDAR\r\n",
		];

		$this->assertTrue($this->service->syncAppointment($appointment));
		$ics = $this->backend->updated[0][2];

		$this->assertStringContainsString('SUMMARY:New title', $ics);
		$this->assertStringContainsString('DTSTART:20260901T180000Z', $ics);
		// Properties the app does not model survive the patch
		$this->assertStringContainsString('LOCATION:Club house', $ics);
		$this->assertStringContainsString('TRIGGER:-PT15M', $ics);
		// The VALARM's DESCRIPTION is a nested property and must stay untouched
		$this->assertStringContainsString('DESCRIPTION:Old title', $ics);
		// Managed properties missing from the old event get appended
		$this->assertStringContainsString('DESCRIPTION:Bring instruments', $ics);
		$this->assertStringContainsString('STATUS:CONFIRMED', $ics);
		$this->assertStringNotContainsString('SUMMARY:Old title', $ics);
	}

	public function testPatchRemovesDescriptionWhenCleared(): void {
		$this->configureEnabled();
		$appointment = $this->buildAppointment();
		$appointment->setDescription('');
		$appointment->setCalendarUri('org-events-owner-uri');
		$appointment->setCalendarEventUid('attendance-org-5@cloud.example.com');
		$this->backend->existingObjects['attendance-org-5@cloud.example.com.ics'] = [
			'calendardata' => "BEGIN:VCALENDAR\r\n"
				. "BEGIN:VEVENT\r\n"
				. "UID:attendance-org-5@cloud.example.com\r\n"
				. "SUMMARY:Rehearsal\r\n"
				. "DESCRIPTION:Old text\r\n"
				. "END:VEVENT\r\n"
				. "END:VCALENDAR\r\n",
		];

		$this->assertTrue($this->service->syncAppointment($appointment));
		$this->assertStringNotContainsString('DESCRIPTION:', $this->backend->updated[0][2]);
	}

	public function testResponseSummaryIsAppendedBehindMarker(): void {
		$this->configureEnabled();
		$appointment = $this->buildAppointment();
		$this->appointmentMapper->method('update')->willReturnArgument(0);
		$this->responseSummary = ['yes' => 2, 'no' => 1, 'maybe' => 1];

		$this->assertTrue($this->service->syncAppointment($appointment));
		$ics = $this->backend->created[0][2];

		$this->assertStringContainsString(
			"DESCRIPTION:Bring instruments\n\n"
			. OrgCalendarSyncService::SUMMARY_SEPARATOR
			. "\n2 attending, 1 declined, 1 maybe",
			$ics,
		);
	}

	public function testNoSummaryBlockWithoutResponses(): void {
		$this->configureEnabled();
		$appointment = $this->buildAppointment();
		$this->appointmentMapper->method('update')->willReturnArgument(0);
		$this->responseSummary = ['yes' => 0, 'no' => 0, 'maybe' => 0];

		$this->assertTrue($this->service->syncAppointment($appointment));
		$this->assertStringNotContainsString(OrgCalendarSyncService::SUMMARY_SEPARATOR, $this->backend->created[0][2]);
	}

	public function testStripResponseSummary(): void {
		$description = "Bring instruments\n\n" . OrgCalendarSyncService::SUMMARY_SEPARATOR . "\n2 attending, 1 declined, 1 maybe";
		$this->assertSame('Bring instruments', OrgCalendarSyncService::stripResponseSummary($description));

		// Description that is only a summary block collapses to empty
		$onlySummary = OrgCalendarSyncService::SUMMARY_SEPARATOR . "\n2 attending, 0 declined, 0 maybe";
		$this->assertSame('', OrgCalendarSyncService::stripResponseSummary($onlySummary));

		// No marker → unchanged
		$this->assertSame('Plain text', OrgCalendarSyncService::stripResponseSummary('Plain text'));
	}

	public function testSyncAllUpcomingSkipsImportedAppointments(): void {
		$this->configureEnabled();
		$own = $this->buildAppointment(5);
		$imported = $this->buildAppointment(6);
		$imported->setCalendarEventUid('imported-uid@foreign');

		$this->appointmentMapper->method('findUpcoming')->willReturn([$own, $imported]);
		$this->appointmentMapper->method('update')->willReturnArgument(0);

		$this->assertSame(1, $this->service->syncAllUpcoming());
		$this->assertCount(1, $this->backend->created);
	}

	public function testSyncAllUpcomingReturnsZeroWhenDisabled(): void {
		$this->configService->method('isOrgCalendarEnabled')->willReturn(false);

		$this->assertSame(0, $this->service->syncAllUpcoming());
		$this->assertSame([], $this->backend->created);
	}

	public function testApplySettingsBackfillsOnEnable(): void {
		$this->configService->method('isOrgCalendarEnabled')->willReturn(false);
		$this->configService->method('getOrgCalendarUri')->willReturn('org-events');
		$this->configService->method('getOrgCalendarUserId')->willReturn('admin');
		$this->configService->expects($this->once())->method('setOrgCalendarEnabled')->with(true);
		// isEnabled() inside syncAllUpcoming still sees the stale mock (false),
		// so the backfill exits early — asserting the attempt is enough here.
		$this->appointmentMapper->method('findUpcoming')->willReturn([]);

		$this->service->applySettings(['enabled' => true], 'admin');
	}

	public function testApplySettingsStoresActingUserOnCalendarChange(): void {
		$this->configService->method('isOrgCalendarEnabled')->willReturn(false);
		$this->configService->method('getOrgCalendarUri')->willReturn('old-uri');
		$this->configService->method('getOrgCalendarUserId')->willReturn('previous-admin');
		$this->configService->expects($this->once())->method('setOrgCalendarUri')->with('new-uri');
		$this->configService->expects($this->once())->method('setOrgCalendarUserId')->with('acting-admin');

		$this->service->applySettings(['calendarUri' => 'new-uri'], 'acting-admin');
	}

	public function testApplySettingsKeepsStoredUserWhenUriUnchanged(): void {
		$this->configService->method('isOrgCalendarEnabled')->willReturn(true);
		$this->configService->method('getOrgCalendarUri')->willReturn('org-events');
		$this->configService->method('getOrgCalendarUserId')->willReturn('previous-admin');
		$this->configService->expects($this->never())->method('setOrgCalendarUserId');

		$this->service->applySettings(['calendarUri' => 'org-events'], 'other-admin');
	}
}
