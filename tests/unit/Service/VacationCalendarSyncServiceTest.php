<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\Vacation;
use OCA\Attendance\Db\VacationMapper;
use OCA\Attendance\Service\CalendarService;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\IcalService;
use OCA\Attendance\Service\VacationCalendarSyncService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class VacationCalendarSyncServiceTest extends TestCase {
	/** @var ConfigService|MockObject */
	private $configService;

	/** @var IUserManager|MockObject */
	private $userManager;

	/** @var VacationMapper|MockObject */
	private $vacationMapper;

	private VacationCalendarSyncService $service;

	protected function setUp(): void {
		$this->configService = $this->createMock(ConfigService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->vacationMapper = $this->createMock(VacationMapper::class);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturn('https://cloud.example.org/');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $params = []) => vsprintf($text, $params));
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->willReturn($l10n);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('en');

		$icalService = $this->createMock(IcalService::class);
		$icalService->method('foldIcalContent')->willReturnArgument(0);

		$this->service = new VacationCalendarSyncService(
			$this->createMock(CalendarService::class),
			$this->vacationMapper,
			$this->configService,
			$this->userManager,
			$urlGenerator,
			$l10nFactory,
			$config,
			$icalService,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testIsOnlyEnabledWhenSwitchedOnAndFullyConfigured(): void {
		$this->configService->method('isVacationCalendarEnabled')->willReturn(true);
		$this->configService->method('getVacationCalendarUri')->willReturn('vacation');
		$this->configService->method('getVacationCalendarUserId')->willReturn('');

		$this->assertFalse($this->service->isEnabled());
	}

	public function testDoesNothingWhileDisabled(): void {
		$this->configService->method('isVacationCalendarEnabled')->willReturn(false);
		$this->vacationMapper->expects($this->never())->method('findOverlapping');

		$this->assertSame(0, $this->service->syncAll());
		$this->assertFalse($this->service->syncVacation(new Vacation()));
	}

	public function testEventUidIsStablePerVacationAndDomain(): void {
		$this->assertSame('attendance-vacation-7@cloud.example.org', $this->service->buildEventUid(7));
	}

	public function testEventIsAWholeDayEndingTheDayAfterWithTheNote(): void {
		$vacation = new Vacation();
		$vacation->setId(3);
		$vacation->setUserId('alice');
		$vacation->setStartDate('2026-12-21');
		$vacation->setEndDate('2026-12-23');
		$vacation->setNote('Skiing, with family');
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Alice Weber');
		$this->userManager->method('get')->with('alice')->willReturn($user);

		$ics = $this->invokeBuildIcs($vacation);

		$this->assertStringContainsString('DTSTART;VALUE=DATE:20261221', $ics);
		$this->assertStringContainsString('DTEND;VALUE=DATE:20261224', $ics);
		$this->assertStringContainsString('SUMMARY:Alice Weber – Vacation', $ics);
		$this->assertStringContainsString('DESCRIPTION:Skiing\, with family', $ics);
		$this->assertStringContainsString('TRANSP:TRANSPARENT', $ics);
	}

	public function testEventWithoutANoteHasNoDescription(): void {
		$vacation = new Vacation();
		$vacation->setId(4);
		$vacation->setUserId('gone');
		$vacation->setStartDate('2026-12-21');
		$vacation->setEndDate('2026-12-21');
		$this->userManager->method('get')->willReturn(null);

		$ics = $this->invokeBuildIcs($vacation);

		$this->assertStringContainsString('SUMMARY:gone – Vacation', $ics);
		$this->assertStringNotContainsString('DESCRIPTION', $ics);
		$this->assertStringContainsString('DTEND;VALUE=DATE:20261222', $ics);
	}

	private function invokeBuildIcs(Vacation $vacation): string {
		$method = new \ReflectionMethod($this->service, 'buildIcs');

		return preg_replace('/\r?\n[ \t]/', '', (string)$method->invoke($this->service, $vacation));
	}
}
