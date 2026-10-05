<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Service\CapacityService;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\ExportOptions;
use OCA\Attendance\Service\ExportService;
use OCA\Attendance\Service\OdsWriter;
use OCA\Attendance\Service\VisibilityService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ExportServiceTest extends TestCase {
	/** @var AppointmentMapper|MockObject */
	private $appointmentMapper;

	/** @var AttendanceResponseMapper|MockObject */
	private $responseMapper;

	/** @var IRootFolder|MockObject */
	private $rootFolder;

	/** @var IUserManager|MockObject */
	private $userManager;

	/** @var IGroupManager|MockObject */
	private $groupManager;

	/** @var ConfigService|MockObject */
	private $configService;

	/** @var VisibilityService|MockObject */
	private $visibilityService;

	/** @var OdsWriter|MockObject */
	private $odsWriter;

	/** @var CapacityService|MockObject */
	private $capacityService;

	private ExportService $service;

	/** The sheet XML the last exportToOds() run handed to the writer. */
	private string $capturedXml = '';

	protected function setUp(): void {
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);
		$this->responseMapper = $this->createMock(AttendanceResponseMapper::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->configService = $this->createMock(ConfigService::class);

		// Reads the appointment's own columns, like the real service does, so a
		// test only has to set the restriction it is about.
		$this->visibilityService = $this->createMock(VisibilityService::class);
		$this->visibilityService->method('getVisibilitySettings')->willReturnCallback(
			static fn (Appointment $appointment): array => [
				'users' => json_decode((string)$appointment->getVisibleUsers(), true) ?: [],
				'groups' => json_decode((string)$appointment->getVisibleGroups(), true) ?: [],
				'teams' => json_decode((string)$appointment->getVisibleTeams(), true) ?: [],
			],
		);

		// Only write() is stubbed: escape() stays real so the assertions below
		// see the same escaping the shipped file gets.
		$this->odsWriter = $this->getMockBuilder(OdsWriter::class)
			->onlyMethods(['write'])
			->getMock();
		$this->odsWriter->method('write')->willReturnCallback(function (string $xml): string {
			$this->capturedXml = $xml;
			return 'ods-bytes';
		});

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->capacityService = $this->createMock(CapacityService::class);

		$this->service = new ExportService(
			$this->appointmentMapper,
			$this->responseMapper,
			$this->rootFolder,
			$this->userManager,
			$this->groupManager,
			$this->configService,
			$this->visibilityService,
			$this->odsWriter,
			$l10n,
			$this->capacityService,
		);
	}

	public function testFromWireDropsEmptyFilterArrays(): void {
		$options = ExportOptions::fromWire([], null, null, []);

		$this->assertNull($options->appointmentIds);
		$this->assertNull($options->seriesIds);
	}

	public function testDefaultColumnsMatchThePreSwitchExport(): void {
		$options = ExportOptions::fromWire();

		$this->assertTrue($options->includeRsvp);
		$this->assertTrue($options->includeCheckin);
		$this->assertFalse($options->includeComments);
		$this->assertFalse($options->includeOrganizers);
		$this->assertTrue($options->includeLocation);
		$this->assertSame(2, $options->columnsPerAppointment());
	}

	public function testHasAnyColumnIsFalseOnlyWhenAllThreeAreOff(): void {
		$this->assertFalse(ExportOptions::fromWire(null, null, null, null, false, false, false)->hasAnyColumn());
		$this->assertTrue(ExportOptions::fromWire(null, null, null, null, false, false, true)->hasAnyColumn());
	}

	public function testGetSeriesOverviewFormatsDatesAsUtcWireValues(): void {
		$this->appointmentMapper->method('findSeriesOverview')->willReturn([
			$this->seriesRow('series-a', 'Rehearsal', '2026-01-05 18:00:00', '2036-03-30 20:00:00', 12),
		]);

		$overview = $this->service->getSeriesOverview();

		$this->assertSame('2026-01-05T18:00:00Z', $overview[0]['startDatetime']);
		$this->assertSame('2036-03-30T20:00:00Z', $overview[0]['endDatetime']);
		$this->assertSame(12, $overview[0]['appointmentCount']);
	}

	/** The client needs it to read the end as the midnight after the last day. */
	public function testGetSeriesOverviewTellsAllDaySeries(): void {
		$this->appointmentMapper->method('findSeriesOverview')->willReturn([
			$this->seriesRow('days', 'Camp', '2036-07-05 22:00:00', '2036-07-26 22:00:00', 4, true),
			$this->seriesRow('times', 'Rehearsal', '2036-01-05 18:00:00', '2036-03-30 20:00:00', 12),
		]);

		$overview = $this->service->getSeriesOverview();

		$this->assertSame([false, true], array_column($overview, 'isAllDay'));
	}

	public function testSeriesCountAsOngoingUntilTheirLastAppointmentHasPassed(): void {
		$this->appointmentMapper->method('findSeriesOverview')->willReturn([
			$this->seriesRow('past', 'Last year', '2020-01-01 10:00:00', '2020-12-31 12:00:00', 40),
			$this->seriesRow('future', 'Next year', '2036-01-01 10:00:00', '2036-12-31 12:00:00', 40),
		]);

		$overview = $this->service->getSeriesOverview();

		$this->assertSame(['future', 'past'], array_column($overview, 'seriesId'));
		$this->assertTrue($overview[0]['ongoing']);
		$this->assertFalse($overview[1]['ongoing']);
	}

	public function testOngoingSeriesAreOfferedSoonestFirstAndFinishedOnesNewestFirst(): void {
		$this->appointmentMapper->method('findSeriesOverview')->willReturn([
			$this->seriesRow('old', 'Old', '2019-01-01 10:00:00', '2019-06-01 12:00:00', 5),
			$this->seriesRow('later', 'Later', '2036-06-01 10:00:00', '2036-12-01 12:00:00', 5),
			$this->seriesRow('recent', 'Recent', '2024-01-01 10:00:00', '2024-06-01 12:00:00', 5),
			$this->seriesRow('soon', 'Soon', '2036-01-01 10:00:00', '2036-05-01 12:00:00', 5),
		]);

		$overview = $this->service->getSeriesOverview();

		$this->assertSame(['soon', 'later', 'recent', 'old'], array_column($overview, 'seriesId'));
	}

	public function testSeriesFilterReachesTheMapper(): void {
		$this->prepareSingleAppointmentExport();

		$this->appointmentMapper->expects($this->once())
			->method('findForExport')
			->with(null, null, null, ['series-a', 'series-b'])
			->willReturn([$this->appointment()]);

		$this->service->exportToOds('admin', ExportOptions::fromWire(null, null, null, ['series-a', 'series-b']));
	}

	public function testOnlyTheSelectedColumnsAreRendered(): void {
		$this->prepareSingleAppointmentExport();
		$this->appointmentMapper->method('findForExport')->willReturn([$this->appointment()]);

		$this->service->exportToOds('admin', ExportOptions::fromWire(
			null, null, null, null,
			false,
			true,
			true,
		));

		$this->assertStringNotContainsString('<text:p>RSVP</text:p>', $this->capturedXml);
		$this->assertStringContainsString('<text:p>CheckIn</text:p>', $this->capturedXml);
		$this->assertStringContainsString('<text:p>Comment</text:p>', $this->capturedXml);

		// Two columns on means the appointment header spans two, not three.
		$this->assertStringContainsString('table:number-columns-spanned="2"', $this->capturedXml);
		$this->assertStringNotContainsString('table:number-columns-spanned="3"', $this->capturedXml);
	}

	public function testSingleColumnHeaderIsNotWrittenAsAMergeOfOne(): void {
		$this->prepareSingleAppointmentExport();
		$this->appointmentMapper->method('findForExport')->willReturn([$this->appointment()]);

		$this->service->exportToOds('admin', ExportOptions::fromWire(
			null, null, null, null,
			false,
			true,
			false,
		));

		$this->assertStringNotContainsString('table:number-columns-spanned', $this->capturedXml);
		$this->assertStringNotContainsString('<table:covered-table-cell/>', $this->capturedXml);
	}

	public function testCommentColumnStaysOffByDefault(): void {
		$this->prepareSingleAppointmentExport();
		$this->appointmentMapper->method('findForExport')->willReturn([$this->appointment()]);

		$this->service->exportToOds('admin', ExportOptions::fromWire());

		$this->assertStringContainsString('<text:p>RSVP</text:p>', $this->capturedXml);
		$this->assertStringNotContainsString('<text:p>Comment</text:p>', $this->capturedXml);
	}

	public function testAYesWithoutASpotIsExportedAsWaitlist(): void {
		$this->prepareSingleAppointmentExport();
		$this->appointmentMapper->method('findForExport')->willReturn([$this->appointment()]);

		// Somebody else holds the only spot, so carol's yes is a place in line.
		$holder = new AttendanceResponse();
		$holder->setUserId('dave');
		$this->capacityService->method('limitOf')->willReturn(1);
		$this->capacityService->method('split')->willReturn(['confirmed' => [$holder], 'waiting' => []]);

		$this->service->exportToOds('admin', ExportOptions::fromWire());

		$this->assertStringContainsString('<text:p>Waitlist</text:p>', $this->capturedXml);
		$this->assertStringNotContainsString('<text:p>Yes</text:p>', $this->capturedXml);
	}

	public function testAYesHoldingASpotStaysAYes(): void {
		$this->prepareSingleAppointmentExport();
		$this->appointmentMapper->method('findForExport')->willReturn([$this->appointment()]);

		$holder = new AttendanceResponse();
		$holder->setUserId('carol');
		$this->capacityService->method('limitOf')->willReturn(1);
		$this->capacityService->method('split')->willReturn(['confirmed' => [$holder], 'waiting' => []]);

		$this->service->exportToOds('admin', ExportOptions::fromWire());

		$this->assertStringContainsString('<text:p>Yes</text:p>', $this->capturedXml);
		$this->assertStringNotContainsString('<text:p>Waitlist</text:p>', $this->capturedXml);
	}

	public function testOrganizerRowListsDisplayNamesCommaSeparated(): void {
		$this->prepareSingleAppointmentExport(['alice' => 'Alice Adams', 'bob' => 'Bob Brown']);

		$appointment = $this->appointment();
		$appointment->setOrganizers(json_encode(['alice', 'bob']));
		$this->appointmentMapper->method('findForExport')->willReturn([$appointment]);

		$this->service->exportToOds('admin', ExportOptions::fromWire(
			null, null, null, null,
			true, true, false,
			true,
		));

		$this->assertStringContainsString('<text:p>Organizers</text:p>', $this->capturedXml);
		$this->assertStringContainsString('<text:p>Alice Adams, Bob Brown</text:p>', $this->capturedXml);
	}

	public function testOrganizerRowFallsBackToTheUserIdOfADeletedAccount(): void {
		$this->prepareSingleAppointmentExport();

		$appointment = $this->appointment();
		$appointment->setOrganizers(json_encode(['ghost']));
		$this->appointmentMapper->method('findForExport')->willReturn([$appointment]);

		$this->service->exportToOds('admin', ExportOptions::fromWire(
			null, null, null, null,
			true, true, false,
			true,
		));

		$this->assertStringContainsString('<text:p>ghost</text:p>', $this->capturedXml);
	}

	public function testOrganizerRowIsAbsentWhenNotRequested(): void {
		$this->prepareSingleAppointmentExport(['alice' => 'Alice Adams']);

		$appointment = $this->appointment();
		$appointment->setOrganizers(json_encode(['alice']));
		$this->appointmentMapper->method('findForExport')->willReturn([$appointment]);

		$this->service->exportToOds('admin', ExportOptions::fromWire());

		$this->assertStringNotContainsString('<text:p>Organizers</text:p>', $this->capturedXml);
	}

	/**
	 * Regression for issue #252: the group column said which group a person is
	 * in, picked alphabetically from their memberships — so somebody in "alto"
	 * and "board" was exported as "alto" even on an appointment only the board
	 * could see. The column names the groups the response summary would section
	 * that person under, and a restriction decides those.
	 */
	public function testGroupColumnNamesTheGroupThatGrantsAccess(): void {
		$this->prepareSingleAppointmentExport([], ['alto', 'board']);
		$appointment = $this->appointment();
		$appointment->setVisibleGroups(json_encode(['board']));
		$this->appointmentMapper->method('findForExport')->willReturn([$appointment]);

		$this->service->exportToOds('admin', ExportOptions::fromWire());

		$this->assertStringContainsString('<text:p>board</text:p>', $this->capturedXml);
		$this->assertStringNotContainsString('<text:p>alto</text:p>', $this->capturedXml);
	}

	/**
	 * Without a grouping configured the overview puts everybody under "Others",
	 * and an unrestricted appointment gives the column nothing else to go on —
	 * the alphabetically first membership was never an answer.
	 */
	public function testGroupColumnSaysOthersWhenNothingGroupsTheAppointment(): void {
		$this->prepareSingleAppointmentExport([], ['alto', 'board']);
		$this->appointmentMapper->method('findForExport')->willReturn([$this->appointment()]);

		$this->service->exportToOds('admin', ExportOptions::fromWire());

		$this->assertStringContainsString('<text:p>Others</text:p>', $this->capturedXml);
		$this->assertStringNotContainsString('<text:p>alto</text:p>', $this->capturedXml);
	}

	/**
	 * The admin's list wins over the restriction, exactly as it does in the
	 * overview: a group nobody tracks by does not become the answer just
	 * because it is what opened the door.
	 */
	public function testGroupColumnObeysTheConfiguredGroupsOverTheRestriction(): void {
		$this->prepareSingleAppointmentExport([], ['alto', 'board'], ['alto']);
		$appointment = $this->appointment();
		$appointment->setVisibleGroups(json_encode(['board']));
		$this->appointmentMapper->method('findForExport')->willReturn([$appointment]);

		$this->service->exportToOds('admin', ExportOptions::fromWire());

		$this->assertStringContainsString('<text:p>alto</text:p>', $this->capturedXml);
		$this->assertStringNotContainsString('<text:p>board</text:p>', $this->capturedXml);
	}

	/**
	 * "All" sections the overview by every group a person belongs to, so the
	 * one column they get has to name all of them.
	 */
	public function testGroupColumnListsEveryMembershipInAllMode(): void {
		$this->prepareSingleAppointmentExport(
			[],
			['alto', 'board'],
			[],
			ConfigService::RESPONSE_SUMMARY_MODE_ALL,
		);
		$this->appointmentMapper->method('findForExport')->willReturn([$this->appointment()]);

		$this->service->exportToOds('admin', ExportOptions::fromWire());

		$this->assertStringContainsString('<text:p>alto, board</text:p>', $this->capturedXml);
	}

	public function testGroupColumnCanBeSwitchedOff(): void {
		$this->prepareSingleAppointmentExport([], ['alto'], ['alto']);
		$this->appointmentMapper->method('findForExport')->willReturn([$this->appointment()]);

		$this->service->exportToOds('admin', ExportOptions::fromWire(
			null, null, null, null,
			true, true, false, false,
			false,
		));

		$this->assertStringNotContainsString('<text:p>Group</text:p>', $this->capturedXml);
		$this->assertStringNotContainsString('<text:p>alto</text:p>', $this->capturedXml);
		// One identity column left, so the header block starts one cell earlier.
		$this->assertStringContainsString('table:style-name="co1" table:number-columns-repeated="1"', $this->capturedXml);
	}

	public function testLocationIsShownNextToTheDateByDefault(): void {
		$this->prepareSingleAppointmentExport();
		$appointment = $this->appointment();
		$appointment->setLocation('Hall A');
		$this->appointmentMapper->method('findForExport')->willReturn([$appointment]);

		$this->service->exportToOds('admin', ExportOptions::fromWire());

		$this->assertStringContainsString('<text:p>2026-01-05 · Hall A</text:p>', $this->capturedXml);
	}

	public function testLocationCanBeSwitchedOff(): void {
		$this->prepareSingleAppointmentExport();
		$appointment = $this->appointment();
		$appointment->setLocation('Hall A');
		$this->appointmentMapper->method('findForExport')->willReturn([$appointment]);

		$this->service->exportToOds('admin', ExportOptions::fromWire(
			null, null, null, null,
			true, true, false, false, true,
			false,
		));

		$this->assertStringContainsString('<text:p>2026-01-05</text:p>', $this->capturedXml);
		$this->assertStringNotContainsString('Hall A', $this->capturedXml);
	}

	public function testEmptyResultIsRejected(): void {
		$this->appointmentMapper->method('findForExport')->willReturn([]);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('No appointments found to export');

		$this->service->exportToOds('admin', ExportOptions::fromWire());
	}

	/**
	 * One grouped row as the mapper hands it over — without the ongoing flag,
	 * which the service derives.
	 */
	private function seriesRow(string $seriesId, string $name, string $start, string $end, int $count, bool $allDay = false): array {
		return [
			'seriesId' => $seriesId,
			'name' => $name,
			'startDatetime' => $start,
			'endDatetime' => $end,
			'appointmentCount' => $count,
			'isAllDay' => $allDay,
		];
	}

	private function appointment(int $id = 1): Appointment {
		$appointment = new Appointment();
		$appointment->setId($id);
		$appointment->setName('Rehearsal');
		$appointment->setStartDatetime('2026-01-05 18:00:00');
		$appointment->setEndDatetime('2026-01-05 20:00:00');
		return $appointment;
	}

	/**
	 * Wire up the file-writing half of the export plus one responding user, so
	 * each test only has to set up the part it is about.
	 *
	 * @param array<string, string> $extraUsers Further user ID => display name pairs to resolve
	 * @param list<string> $userGroups The groups every resolved user belongs to
	 * @param list<string> $whitelistedGroups The admin's response-summary whitelist
	 * @param ?string $groupsMode Grouping mode; null derives the stored default from the whitelist
	 */
	private function prepareSingleAppointmentExport(
		array $extraUsers = [],
		array $userGroups = ['choir'],
		array $whitelistedGroups = [],
		?string $groupsMode = null,
	): void {
		$response = new AttendanceResponse();
		$response->setUserId('carol');
		$response->setResponse('yes');
		$response->setCheckinState('present');
		$response->setComment('On my way');
		$this->responseMapper->method('findByAppointment')->willReturn([$response]);

		$this->configService->method('getWhitelistedGroups')->willReturn($whitelistedGroups);
		// Same default ConfigService derives: no configured groups, no grouping.
		$this->configService->method('getResponseSummaryGroupsMode')->willReturn(
			$groupsMode ?? ($whitelistedGroups === []
				? ConfigService::RESPONSE_SUMMARY_MODE_NONE
				: ConfigService::RESPONSE_SUMMARY_MODE_SPECIFIC),
		);
		$this->groupManager->method('getUserGroupIds')->willReturn($userGroups);

		$users = ['carol' => 'Carol Clark'] + $extraUsers;
		$this->userManager->method('get')->willReturnCallback(
			function (string $uid) use ($users): ?IUser {
				if (!isset($users[$uid])) {
					return null;
				}
				$user = $this->createMock(IUser::class);
				$user->method('getDisplayName')->willReturn($users[$uid]);
				return $user;
			}
		);

		$attendanceFolder = $this->createMock(Folder::class);
		$attendanceFolder->method('nodeExists')->willReturn(false);
		$attendanceFolder->method('newFile')->willReturn($this->createMock(File::class));

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->willReturn($attendanceFolder);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);
	}
}
