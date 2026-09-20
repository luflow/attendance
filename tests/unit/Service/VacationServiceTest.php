<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\Vacation;
use OCA\Attendance\Db\VacationMapper;
use OCA\Attendance\Service\VacationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class VacationServiceTest extends TestCase {
	/** @var VacationMapper|MockObject */
	private $vacationMapper;

	/** @var IUserManager|MockObject */
	private $userManager;

	private VacationService $service;

	protected function setUp(): void {
		$this->vacationMapper = $this->createMock(VacationMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->service = new VacationService($this->vacationMapper, $this->userManager);
	}

	private function vacation(int $id, string $userId, string $start, string $end, ?string $note = null): Vacation {
		$vacation = new Vacation();
		$vacation->setId($id);
		$vacation->setUserId($userId);
		$vacation->setStartDate($start);
		$vacation->setEndDate($end);
		$vacation->setNote($note);
		return $vacation;
	}

	public function testGetForUserReturnsMapperResult(): void {
		$vacations = [$this->vacation(1, 'alice', '2026-07-01', '2026-07-10')];
		$this->vacationMapper->method('findByUser')->with('alice')->willReturn($vacations);

		$this->assertSame($vacations, $this->service->getForUser('alice'));
	}

	public function testCreateNormalizesNoteAndInserts(): void {
		$this->vacationMapper->expects($this->once())->method('insert')
			->willReturnCallback(fn (Vacation $v) => $v);

		$created = $this->service->create('alice', '2026-07-01', '2026-07-10', '  Beach trip  ');

		$this->assertEquals('alice', $created->getUserId());
		$this->assertEquals('2026-07-01', $created->getStartDate());
		$this->assertEquals('2026-07-10', $created->getEndDate());
		$this->assertEquals('Beach trip', $created->getNote());
	}

	public function testCreateStoresNullForBlankNote(): void {
		$this->vacationMapper->expects($this->once())->method('insert')
			->willReturnCallback(fn (Vacation $v) => $v);

		$created = $this->service->create('alice', '2026-07-01', '2026-07-10', '   ');

		$this->assertNull($created->getNote());
	}

	public function testCreateRejectsEndBeforeStart(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->create('alice', '2026-07-10', '2026-07-01', null);
	}

	public function testCreateRejectsMalformedDate(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->create('alice', 'not-a-date', '2026-07-10', null);
	}

	public function testCreateAllowsSingleDayVacation(): void {
		$this->vacationMapper->expects($this->once())->method('insert')
			->willReturnCallback(fn (Vacation $v) => $v);

		$created = $this->service->create('alice', '2026-07-01', '2026-07-01', null);

		$this->assertEquals('2026-07-01', $created->getStartDate());
		$this->assertEquals('2026-07-01', $created->getEndDate());
	}

	public function testUpdateRewritesOwnVacation(): void {
		$existing = $this->vacation(1, 'alice', '2026-07-01', '2026-07-10');
		$this->vacationMapper->method('find')->with(1)->willReturn($existing);
		$this->vacationMapper->expects($this->once())->method('update')
			->willReturnCallback(fn (Vacation $v) => $v);

		$updated = $this->service->update(1, 'alice', '2026-08-01', '2026-08-05', 'Rescheduled');

		$this->assertEquals('2026-08-01', $updated->getStartDate());
		$this->assertEquals('2026-08-05', $updated->getEndDate());
		$this->assertEquals('Rescheduled', $updated->getNote());
	}

	public function testUpdateRejectsSomeoneElsesVacation(): void {
		$existing = $this->vacation(1, 'alice', '2026-07-01', '2026-07-10');
		$this->vacationMapper->method('find')->with(1)->willReturn($existing);
		$this->vacationMapper->expects($this->never())->method('update');

		$this->expectException(DoesNotExistException::class);
		$this->service->update(1, 'mallory', '2026-08-01', '2026-08-05', null);
	}

	public function testDeleteRemovesOwnVacation(): void {
		$existing = $this->vacation(1, 'alice', '2026-07-01', '2026-07-10');
		$this->vacationMapper->method('find')->with(1)->willReturn($existing);
		$this->vacationMapper->expects($this->once())->method('delete')->with($existing);

		$this->service->delete(1, 'alice');
	}

	public function testDeleteRejectsSomeoneElsesVacation(): void {
		$existing = $this->vacation(1, 'alice', '2026-07-01', '2026-07-10');
		$this->vacationMapper->method('find')->with(1)->willReturn($existing);
		$this->vacationMapper->expects($this->never())->method('delete');

		$this->expectException(DoesNotExistException::class);
		$this->service->delete(1, 'mallory');
	}

	public function testDeleteThrowsWhenVacationMissing(): void {
		$this->vacationMapper->method('find')->with(99)
			->willThrowException(new DoesNotExistException('not found'));

		$this->expectException(DoesNotExistException::class);
		$this->service->delete(99, 'alice');
	}

	public function testFindUsersOnVacationReturnsUniqueUserIds(): void {
		$this->vacationMapper->expects($this->once())->method('findOverlapping')
			->with('2026-06-01', '2026-06-02')
			->willReturn([
				$this->vacation(1, 'bob', '2026-05-20', '2026-06-05'),
				$this->vacation(2, 'bob', '2026-06-02', '2026-06-09'),
			]);

		$this->assertSame(['bob'], $this->service->findUsersOnVacation(
			'2026-06-01T00:00:00Z',
			'2026-06-02T00:00:00Z',
		));
	}

	public function testFindConflictsWithNoCandidatesSkipsTheQuery(): void {
		$this->vacationMapper->expects($this->never())->method('findOverlapping');

		$this->assertSame([], $this->service->findConflicts('2026-06-01T00:00:00Z', '2026-06-02T00:00:00Z', []));
	}

	public function testFindConflictsEnrichesWithDisplayNames(): void {
		$this->vacationMapper->method('findOverlapping')
			->with('2026-06-01', '2026-06-02', ['alice'])
			->willReturn([$this->vacation(1, 'alice', '2026-05-20', '2026-06-05', 'Family visit')]);

		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Alice Example');
		$this->userManager->method('get')->with('alice')->willReturn($user);

		$conflicts = $this->service->findConflicts('2026-06-01T00:00:00Z', '2026-06-02T00:00:00Z', ['alice']);

		$this->assertSame([[
			'userId' => 'alice',
			'displayName' => 'Alice Example',
			'startDate' => '2026-05-20',
			'endDate' => '2026-06-05',
			'note' => 'Family visit',
		]], $conflicts);
	}

	public function testFindConflictsFallsBackToUserIdWhenUserIsGone(): void {
		$this->vacationMapper->method('findOverlapping')
			->willReturn([$this->vacation(1, 'ghost', '2026-05-20', '2026-06-05')]);
		$this->userManager->method('get')->with('ghost')->willReturn(null);

		$conflicts = $this->service->findConflicts('2026-06-01T00:00:00Z', '2026-06-02T00:00:00Z', ['ghost']);

		$this->assertSame('ghost', $conflicts[0]['displayName']);
	}

	public function testGetOverviewQueriesTheGivenRangeAndAudience(): void {
		$this->vacationMapper->expects($this->once())->method('findOverlapping')
			->with('2026-06-01', '2026-12-01', ['alice', 'bob'])
			->willReturn([]);

		$this->assertSame([], $this->service->getOverview('2026-06-01', '2026-12-01', ['alice', 'bob']));
	}
}
