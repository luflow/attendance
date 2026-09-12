<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Service\ConfigService;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigServiceTest extends TestCase {
	/** @var IConfig|MockObject */
	private $config;

	/** @var IAppConfig|MockObject */
	private $appConfig;

	private ConfigService $service;

	protected function setUp(): void {
		$this->config = $this->createMock(IConfig::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		$this->service = new ConfigService($this->config, $this->appConfig);
	}

	/**
	 * @param array<string, string> $values Keyed by the raw config key (no 'attendance' app prefix).
	 */
	private function configValues(array $values): void {
		$this->config->method('getAppValue')
			->willReturnCallback(
				fn (string $app, string $key, string $default = '') => $values[$key] ?? $default,
			);
		$this->appConfig->method('getValueString')
			->willReturnCallback(
				fn (string $app, string $key, string $default = '') => $values[$key] ?? $default,
			);
	}

	// --- response summary groups mode ---

	public function testGetResponseSummaryGroupsModeReturnsStoredValue(): void {
		$this->configValues(['response_summary_groups_mode' => 'all']);

		$this->assertSame('all', $this->service->getResponseSummaryGroupsMode());
	}

	public function testGetResponseSummaryGroupsModeDefaultsToNoneWhenWhitelistIsEmpty(): void {
		$this->configValues(['whitelisted_groups' => '[]']);

		$this->assertSame('none', $this->service->getResponseSummaryGroupsMode());
	}

	public function testGetResponseSummaryGroupsModeDefaultsToSpecificWhenWhitelistIsNotEmpty(): void {
		$this->configValues(['whitelisted_groups' => '["choir"]']);

		$this->assertSame('specific', $this->service->getResponseSummaryGroupsMode());
	}

	public function testGetResponseSummaryGroupsModeIgnoresInvalidStoredValue(): void {
		$this->configValues(['response_summary_groups_mode' => 'bogus', 'whitelisted_groups' => '["choir"]']);

		$this->assertSame('specific', $this->service->getResponseSummaryGroupsMode());
	}

	public function testSetResponseSummaryGroupsModeWritesTheMode(): void {
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('attendance', 'response_summary_groups_mode', 'all');

		$this->service->setResponseSummaryGroupsMode('all');
	}

	public function testSetResponseSummaryGroupsModeRejectsInvalidValue(): void {
		$this->appConfig->expects($this->never())->method('setValueString');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->setResponseSummaryGroupsMode('bogus');
	}

	// --- response summary teams mode ---

	public function testGetResponseSummaryTeamsModeReturnsStoredValue(): void {
		$this->configValues(['response_summary_teams_mode' => 'specific']);

		$this->assertSame('specific', $this->service->getResponseSummaryTeamsMode());
	}

	public function testGetResponseSummaryTeamsModeDefaultsToNoneWhenWhitelistIsEmpty(): void {
		$this->configValues(['whitelisted_teams' => '[]']);

		$this->assertSame('none', $this->service->getResponseSummaryTeamsMode());
	}

	public function testGetResponseSummaryTeamsModeDefaultsToSpecificWhenWhitelistIsNotEmpty(): void {
		$this->configValues(['whitelisted_teams' => '["choir-team"]']);

		$this->assertSame('specific', $this->service->getResponseSummaryTeamsMode());
	}

	/**
	 * Unlike groups, teams have no "all" mode: there is no cheap way to
	 * enumerate every Nextcloud Team.
	 */
	public function testSetResponseSummaryTeamsModeRejectsAll(): void {
		$this->appConfig->expects($this->never())->method('setValueString');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->setResponseSummaryTeamsMode('all');
	}
}
