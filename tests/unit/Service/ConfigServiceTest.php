<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Service\ConfigService;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigServiceTest extends TestCase {
	/** @var IAppConfig|MockObject */
	private $appConfig;

	/** @var IUserConfig|MockObject */
	private $userConfig;

	private ConfigService $service;

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->userConfig = $this->createMock(IUserConfig::class);

		$this->service = new ConfigService($this->appConfig, $this->userConfig);
	}

	/**
	 * @param array<string, string> $values Keyed by the raw config key (no 'attendance' app prefix).
	 */
	private function configValues(array $values): void {
		$this->appConfig->method('getValueString')
			->willReturnCallback(
				fn (string $app, string $key, string $default = '') => $values[$key] ?? $default,
			);
	}

	// --- hide account email ---

	/** It changes what the whole server shows, so it must never be on unasked. */
	public function testAccountEmailsAreShownUnlessAnAdminHidesThem(): void {
		$this->appConfig->expects($this->once())
			->method('getValueBool')
			->with('attendance', 'hide_account_email', false)
			->willReturn(false);

		$this->assertFalse($this->service->isAccountEmailHidden());
	}

	public function testHidingAccountEmailsIsStored(): void {
		$this->appConfig->expects($this->once())
			->method('setValueBool')
			->with('attendance', 'hide_account_email', true);

		$this->service->setAccountEmailHidden(true);
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

	public function testRatingPromptIsPerUserAndOffUntilShown(): void {
		$this->userConfig->method('getValueBool')
			->with('alice', 'attendance', 'rating_prompt_shown')
			->willReturn(false);

		$this->assertFalse($this->service->wasAskedForRating('alice'));
	}

	public function testShowingTheRatingPromptIsRemembered(): void {
		$this->userConfig->expects($this->once())->method('setValueBool')
			->with('alice', 'attendance', 'rating_prompt_shown', true);

		$this->service->setAskedForRating('alice');
	}
}
