<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Migration;

use OCA\Attendance\Migration\Version000027Date20261012120000;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class Version000027Date20261012120000Test extends TestCase {
	private const MODE_KEY = 'permission_export_mode';

	/** @var IAppConfig|MockObject */
	private $appConfig;
	/** @var IConfig|MockObject */
	private $config;
	/** @var IOutput|MockObject */
	private $output;

	private Version000027Date20261012120000 $migration;

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->config = $this->createMock(IConfig::class);
		$this->output = $this->createMock(IOutput::class);

		$this->migration = new Version000027Date20261012120000($this->appConfig, $this->config);
	}

	public function testCarriesOverGroupsThatMayManageAppointments(): void {
		$this->givenSource('groups', ['board', 'office'], targetMode: '');

		$this->config->expects($this->once())
			->method('setAppValue')
			->with('attendance', 'permission_export', '["board","office"]');
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('attendance', self::MODE_KEY, 'groups');

		$this->migrate();
	}

	/**
	 * Export was open to everybody wherever managing was, so an untouched
	 * instance keeps exactly that — no group list to copy.
	 */
	public function testCarriesOverAnUnrestrictedSource(): void {
		$this->givenSource('all', [], targetMode: '');

		$this->config->expects($this->never())->method('setAppValue');
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('attendance', self::MODE_KEY, 'all');

		$this->migrate();
	}

	public function testKeepsNobodyWhenNobodyMayManage(): void {
		$this->givenSource('nobody', [], targetMode: '');

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('attendance', self::MODE_KEY, 'nobody');

		$this->migrate();
	}

	public function testGroupsModeWithoutGroupsGrantsNobody(): void {
		$this->givenSource('groups', [], targetMode: '');

		$this->config->expects($this->never())->method('setAppValue');
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('attendance', self::MODE_KEY, 'nobody');

		$this->migrate();
	}

	/**
	 * A jump from before explicit modes has only the bare group list, which
	 * used to mean "these groups".
	 */
	public function testInfersGroupsModeFromABareGroupList(): void {
		$this->givenSource('', ['board'], targetMode: '');

		$this->config->expects($this->once())
			->method('setAppValue')
			->with('attendance', 'permission_export', '["board"]');
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('attendance', self::MODE_KEY, 'groups');

		$this->migrate();
	}

	public function testInfersEveryoneFromNoStoredSettingAtAll(): void {
		$this->givenSource('', [], targetMode: '');

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('attendance', self::MODE_KEY, 'all');

		$this->migrate();
	}

	public function testLeavesAnExistingTargetSettingAlone(): void {
		$this->givenSource('groups', ['board'], targetMode: 'nobody');

		$this->config->expects($this->never())->method('setAppValue');
		$this->appConfig->expects($this->never())->method('setValueString');

		$this->migrate();
	}

	/**
	 * @param list<string> $roles
	 */
	private function givenSource(string $sourceMode, array $roles, string $targetMode): void {
		$this->appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key) use ($sourceMode, $targetMode): string {
				if ($key === self::MODE_KEY) {
					return $targetMode;
				}
				return $key === 'permission_manage_appointments_mode' ? $sourceMode : '';
			},
		);
		$this->appConfig->method('getValueArray')->willReturn($roles);
	}

	private function migrate(): void {
		$this->migration->postSchemaChange($this->output, static fn () => null, []);
	}
}
