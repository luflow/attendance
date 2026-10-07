<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Service\DirectorySearchService;
use OCA\Attendance\Service\GuestService;
use OCP\App\IAppManager;
use OCP\Collaboration\Collaborators\ISearch as ICollaboratorSearch;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DirectorySearchServiceTest extends TestCase {
	/** @var ICollaboratorSearch|MockObject */
	private $collaboratorSearch;
	/** @var IUserManager|MockObject */
	private $userManager;
	private DirectorySearchService $service;

	protected function setUp(): void {
		$this->collaboratorSearch = $this->createMock(ICollaboratorSearch::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->service = new DirectorySearchService(
			$this->collaboratorSearch,
			$this->createMock(IAppManager::class),
			$this->userManager,
			$this->createMock(GuestService::class),
		);
	}

	private function searchesAs(string $uid, string $displayName, ?string $email = null): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($displayName);
		$user->method('getEMailAddress')->willReturn($email);
		$this->userManager->method('get')->with($uid)->willReturn($user);
	}

	/** @param list<string> $uids */
	private function shareeSearchFinds(array $uids): void {
		$users = array_map(static fn (string $uid): array => [
			'label' => $uid,
			'value' => ['shareType' => IShare::TYPE_USER, 'shareWith' => $uid],
		], $uids);
		$this->collaboratorSearch->method('search')->willReturn([['users' => $users], false]);
	}

	public function testSearchAddsTheSearchingUserWhenTheQueryFitsThem(): void {
		$this->searchesAs('lars', 'Lars Müller');
		$this->shareeSearchFinds(['larissa']);

		$results = $this->service->search('lar', 'lars');

		$this->assertSame(['larissa', 'lars'], array_column($results, 'id'));
		$this->assertSame('Lars Müller', $results[1]['label']);
		$this->assertSame('user', $results[1]['type']);
		$this->assertFalse($results[1]['isGuest']);
	}

	public function testSearchPutsAnExactHitOnTheSearchingUserFirst(): void {
		$this->searchesAs('lars', 'Lars Müller');
		$this->shareeSearchFinds(['larissa']);

		$results = $this->service->search('Lars Müller', 'lars');

		$this->assertSame(['lars', 'larissa'], array_column($results, 'id'));
	}

	public function testSearchMatchesTheSearchingUserByEmail(): void {
		$this->searchesAs('lars', 'Lars Müller', 'lars@example.org');
		$this->shareeSearchFinds([]);

		$results = $this->service->search('example.org', 'lars');

		$this->assertSame(['lars'], array_column($results, 'id'));
	}

	public function testSearchLeavesTheSearchingUserOutWhenTheQueryDoesNotFit(): void {
		$this->searchesAs('lars', 'Lars Müller', 'lars@example.org');
		$this->shareeSearchFinds(['christel']);

		$results = $this->service->search('chris', 'lars');

		$this->assertSame(['christel'], array_column($results, 'id'));
	}

	public function testSearchDoesNotDuplicateASearchingUserTheShareeSearchAlreadyLists(): void {
		$this->searchesAs('lars', 'Lars Müller');
		$this->shareeSearchFinds(['lars']);

		$results = $this->service->search('lars', 'lars');

		$this->assertSame(['lars'], array_column($results, 'id'));
	}

	public function testSearchIgnoresASearcherTheUserManagerDoesNotKnow(): void {
		$this->shareeSearchFinds(['christel']);

		$results = $this->service->search('chris', 'ghost');

		$this->assertSame(['christel'], array_column($results, 'id'));
	}
}
