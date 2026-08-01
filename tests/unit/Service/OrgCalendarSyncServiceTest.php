<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\IcalService;
use OCA\Attendance\Service\OrgCalendarSyncService;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\IURLGenerator;
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

class OrgCalendarSyncServiceTest extends TestCase {
	/** @var ICalendarManager|MockObject */
	private $calendarManager;

	/** @var AppointmentMapper|MockObject */
	private $appointmentMapper;

	/** @var ConfigService|MockObject */
	private $configService;

	/** @var IcalService|MockObject */
	private $icalService;

	/** @var IURLGenerator|MockObject */
	private $urlGenerator;

	private FakeCalDavBackend $backend;
	private OrgCalendarSyncService $service;

	protected function setUp(): void {
		$this->calendarManager = $this->createMock(ICalendarManager::class);
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->icalService = $this->createMock(IcalService::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->backend = new FakeCalDavBackend();

		$this->icalService->method('escapeIcalText')->willReturnArgument(0);
		$this->icalService->method('foldIcalContent')->willReturnArgument(0);

		$this->urlGenerator->method('getAbsoluteURL')->willReturn('https://cloud.example.com/');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example.com/apps/attendance/');

		$backend = $this->backend;
		$this->service = new class($this->calendarManager, $this->appointmentMapper, $this->configService, $this->icalService, $this->urlGenerator, $this->createMock(LoggerInterface::class), $backend, ) extends OrgCalendarSyncService {
			public function __construct(
				$calendarManager,
				$appointmentMapper,
				$configService,
				$icalService,
				$urlGenerator,
				$logger,
				private object $fakeBackend,
			) {
				parent::__construct($calendarManager, $appointmentMapper, $configService, $icalService, $urlGenerator, $logger);
			}

			protected function getCalDavBackend(): ?object {
				return $this->fakeBackend;
			}
		};
	}

	private function configureEnabled(): void {
		$this->configService->method('isOrgCalendarEnabled')->willReturn(true);
		$this->configService->method('getOrgCalendarUri')->willReturn('org-events');
		$this->configService->method('getOrgCalendarUserId')->willReturn('admin');

		$calendar = new FakeWritableCalendar('42', 'org-events');
		$this->calendarManager->method('getCalendarsForPrincipal')
			->with('principals/users/admin', ['org-events'])
			->willReturn([$calendar]);
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

		$this->assertTrue($this->service->handleAppointmentDeleted($appointment));
		$this->assertSame([[42, 'attendance-org-5@cloud.example.com.ics']], $this->backend->deleted);
	}

	public function testDeleteNeverTouchesForeignEvents(): void {
		$this->configureEnabled();
		$appointment = $this->buildAppointment();
		$appointment->setCalendarEventUid('some-imported-uid@foreign');

		$this->assertFalse($this->service->handleAppointmentDeleted($appointment));
		$this->assertSame([], $this->backend->deleted);
	}

	public function testSyncAllUpcomingCountsOnlyPushedAppointments(): void {
		$this->configureEnabled();
		$own = $this->buildAppointment(5);
		$imported = $this->buildAppointment(6);
		$imported->setCalendarEventUid('imported-uid@foreign');

		$this->appointmentMapper->method('findUpcoming')->willReturn([$own, $imported]);
		$this->appointmentMapper->method('update')->willReturnArgument(0);

		$this->assertSame(1, $this->service->syncAllUpcoming());
		$this->assertCount(1, $this->backend->created);
	}
}
