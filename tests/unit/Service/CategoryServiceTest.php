<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\Category;
use OCA\Attendance\Db\CategoryMapper;
use OCA\Attendance\Service\CategoryService;
use OCA\Attendance\Service\PermissionService;
use OCA\Attendance\Service\VisibilityService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CategoryServiceTest extends TestCase {
	/** @var CategoryMapper|MockObject */
	private $categoryMapper;

	/** @var AppointmentMapper|MockObject */
	private $appointmentMapper;

	/** @var VisibilityService|MockObject */
	private $visibilityService;

	/** @var PermissionService|MockObject */
	private $permissionService;

	private CategoryService $service;

	protected function setUp(): void {
		$this->categoryMapper = $this->createMock(CategoryMapper::class);
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);
		$this->visibilityService = $this->createMock(VisibilityService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->service = new CategoryService($this->categoryMapper, $this->appointmentMapper, $this->visibilityService, $this->permissionService);
	}

	private function category(int $id, string $name, string $icon = 'tag'): Category {
		$category = new Category();
		$category->setId($id);
		$category->setName($name);
		$category->setIcon($icon);
		return $category;
	}

	public function testGetAllReturnsMapperResult(): void {
		$categories = [$this->category(1, 'Rehearsal'), $this->category(2, 'Social event')];
		$this->categoryMapper->method('findAll')->willReturn($categories);

		$this->assertSame($categories, $this->service->getAll());
	}

	public function testCreateTrimsAndInserts(): void {
		$this->categoryMapper->method('findByName')->with('Rehearsal')
			->willThrowException(new DoesNotExistException('not found'));
		$this->categoryMapper->expects($this->once())->method('insert')
			->willReturnCallback(fn (Category $c) => $c);

		$created = $this->service->create('  Rehearsal  ', 'microphone');

		$this->assertEquals('Rehearsal', $created->getName());
		$this->assertEquals('microphone', $created->getIcon());
	}

	public function testCreateRejectsEmptyName(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->create('   ', 'tag');
	}

	public function testCreateRejectsDuplicateName(): void {
		$this->categoryMapper->method('findByName')->with('Rehearsal')
			->willReturn($this->category(1, 'Rehearsal'));

		$this->expectException(\InvalidArgumentException::class);
		$this->service->create('Rehearsal', 'tag');
	}

	public function testCreateRejectsUnknownIcon(): void {
		$this->categoryMapper->method('findByName')->with('Rehearsal')
			->willThrowException(new DoesNotExistException('not found'));

		$this->expectException(\InvalidArgumentException::class);
		$this->service->create('Rehearsal', 'not-a-real-icon');
	}

	public function testCreateStoresANormalizedTemplate(): void {
		$this->categoryMapper->method('findByName')
			->willThrowException(new DoesNotExistException('not found'));
		$this->categoryMapper->method('insert')->willReturnCallback(fn (Category $c) => $c);

		$created = $this->service->create('Concert', 'tag', [
			'description' => "  Clothing:\n<b>Transport:</b>  ",
			'visibleUsers' => ['alice', 'alice', '', 7],
			'visibleGroups' => ['choir'],
		]);

		$this->assertSame([
			'name' => '',
			'description' => "Clothing:\nTransport:",
			'location' => '',
			'responseDeadlineValue' => null,
			'responseDeadlineUnit' => null,
			'maxAttendees' => null,
			'waitlistEnabled' => null,
			'allowMaybe' => null,
			'sendNotification' => null,
			'visibleUsers' => ['alice'],
			'visibleGroups' => ['choir'],
			'visibleTeams' => [],
		], json_decode($created->getTemplate(), true));
	}

	public function testCreateStoresTheOtherTemplateFields(): void {
		$this->categoryMapper->method('findByName')
			->willThrowException(new DoesNotExistException('not found'));
		$this->categoryMapper->method('insert')->willReturnCallback(fn (Category $c) => $c);

		$stored = json_decode($this->service->create('Concert', 'tag', [
			'name' => ' Open air <i>concert</i> ',
			'location' => 'Stadtpark',
			'responseDeadlineValue' => 3,
			'responseDeadlineUnit' => 'days',
			'maxAttendees' => 40,
			'waitlistEnabled' => false,
			'allowMaybe' => false,
			'sendNotification' => true,
		])->getTemplate(), true);

		$this->assertSame('Open air concert', $stored['name']);
		$this->assertSame('Stadtpark', $stored['location']);
		$this->assertSame(3, $stored['responseDeadlineValue']);
		$this->assertSame('days', $stored['responseDeadlineUnit']);
		$this->assertSame(40, $stored['maxAttendees']);
		$this->assertFalse($stored['waitlistEnabled']);
		$this->assertFalse($stored['allowMaybe']);
		$this->assertTrue($stored['sendNotification']);
	}

	public function testATemplateWithOnlyAFalseSwitchIsStillATemplate(): void {
		$this->categoryMapper->method('findByName')
			->willThrowException(new DoesNotExistException('not found'));
		$this->categoryMapper->method('insert')->willReturnCallback(fn (Category $c) => $c);

		$this->assertNotNull($this->service->create('Concert', 'tag', ['allowMaybe' => false])->getTemplate());
	}

	public function testHalfAResponseDeadlineAndAWaitlistWithoutALimitAreDropped(): void {
		$this->categoryMapper->method('findByName')
			->willThrowException(new DoesNotExistException('not found'));
		$this->categoryMapper->method('insert')->willReturnCallback(fn (Category $c) => $c);

		$this->assertNull($this->service->create('Concert', 'tag', [
			'responseDeadlineValue' => 3,
			'maxAttendees' => 0,
			'waitlistEnabled' => true,
		])->getTemplate());
		$this->assertNull($this->service->create('Concert', 'tag', [
			'responseDeadlineValue' => 3,
			'responseDeadlineUnit' => 'years',
		])->getTemplate());
	}

	public function testCreateStoresNoTemplateWhenItWouldPrefillNothing(): void {
		$this->categoryMapper->method('findByName')
			->willThrowException(new DoesNotExistException('not found'));
		$this->categoryMapper->method('insert')->willReturnCallback(fn (Category $c) => $c);

		$this->assertNull($this->service->create('Concert', 'tag')->getTemplate());
		$this->assertNull($this->service->create('Concert', 'tag', ['description' => '  ', 'visibleUsers' => []])->getTemplate());
	}

	public function testUpdateKeepsTheTemplateWhenNoneIsSent(): void {
		$existing = $this->category(1, 'Rehearsal');
		$existing->setTemplate('{"description":"Clothing:"}');
		$this->categoryMapper->method('find')->with(1)->willReturn($existing);
		$this->categoryMapper->method('findByName')->willReturn($existing);
		$this->categoryMapper->method('update')->willReturnCallback(fn (Category $c) => $c);

		$this->assertSame('{"description":"Clothing:"}', $this->service->update(1, 'Rehearsal', 'tag')->getTemplate());
	}

	public function testUpdateRemovesTheTemplateWhenAnEmptyOneIsSent(): void {
		$existing = $this->category(1, 'Rehearsal');
		$existing->setTemplate('{"description":"Clothing:"}');
		$this->categoryMapper->method('find')->with(1)->willReturn($existing);
		$this->categoryMapper->method('findByName')->willReturn($existing);
		$this->categoryMapper->method('update')->willReturnCallback(fn (Category $c) => $c);

		$this->assertNull($this->service->update(1, 'Rehearsal', 'tag', [])->getTemplate());
	}

	public function testSerializeEnrichesTheTemplateAudience(): void {
		$category = $this->category(1, 'Rehearsal', 'star');
		$category->setTemplate('{"description":"Clothing:","visibleUsers":[],"visibleGroups":["choir"],"visibleTeams":[]}');
		$audience = [
			'visibleUsers' => [],
			'visibleGroups' => [['id' => 'choir', 'label' => 'Choir', 'type' => 'group']],
			'visibleTeams' => [],
		];
		$this->visibilityService->expects($this->once())->method('enrichAudience')
			->with([], ['choir'], [])
			->willReturn($audience);

		$this->assertSame(
			['id' => 1, 'name' => 'Rehearsal', 'icon' => 'star', 'template' => [
				'name' => '',
				'description' => 'Clothing:',
				'location' => '',
				'responseDeadlineValue' => null,
				'responseDeadlineUnit' => null,
				'maxAttendees' => null,
				'waitlistEnabled' => null,
				'allowMaybe' => null,
				'sendNotification' => null,
			] + $audience],
			$this->service->serialize($category, true),
		);
	}

	public function testSerializeWithholdsTheTemplate(): void {
		$category = $this->category(1, 'Rehearsal');
		$category->setTemplate('{"description":"Clothing:","visibleUsers":["alice"],"visibleGroups":[],"visibleTeams":[]}');
		$this->visibilityService->expects($this->never())->method('enrichAudience');

		$this->assertNull($this->service->serialize($category, false)['template']);
		$this->assertNull($this->service->serialize($this->category(2, 'Concert'), true)['template']);
	}

	public function testUpdateRenamesWhenNameIsFree(): void {
		$existing = $this->category(1, 'Old name');
		$this->categoryMapper->method('find')->with(1)->willReturn($existing);
		$this->categoryMapper->method('findByName')->with('New name')
			->willThrowException(new DoesNotExistException('not found'));
		$this->categoryMapper->expects($this->once())->method('update')
			->willReturnCallback(fn (Category $c) => $c);

		$updated = $this->service->update(1, 'New name', 'star');

		$this->assertEquals('New name', $updated->getName());
		$this->assertEquals('star', $updated->getIcon());
	}

	public function testUpdateAllowsKeepingItsOwnName(): void {
		$existing = $this->category(1, 'Rehearsal');
		$this->categoryMapper->method('find')->with(1)->willReturn($existing);
		// The name belongs to category 1 itself — not a collision.
		$this->categoryMapper->method('findByName')->with('Rehearsal')->willReturn($existing);
		$this->categoryMapper->expects($this->once())->method('update')
			->willReturnCallback(fn (Category $c) => $c);

		$updated = $this->service->update(1, 'Rehearsal', 'tag');

		$this->assertEquals('Rehearsal', $updated->getName());
	}

	public function testUpdateRejectsNameTakenByAnotherCategory(): void {
		$existing = $this->category(1, 'Rehearsal');
		$other = $this->category(2, 'Social event');
		$this->categoryMapper->method('find')->with(1)->willReturn($existing);
		$this->categoryMapper->method('findByName')->with('Social event')->willReturn($other);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->update(1, 'Social event', 'tag');
	}

	public function testUpdateRejectsUnknownIcon(): void {
		$existing = $this->category(1, 'Rehearsal');
		$this->categoryMapper->method('find')->with(1)->willReturn($existing);
		$this->categoryMapper->method('findByName')->with('Rehearsal')->willReturn($existing);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->update(1, 'Rehearsal', 'not-a-real-icon');
	}

	public function testUpdateThrowsWhenCategoryMissing(): void {
		$this->categoryMapper->method('find')->with(99)
			->willThrowException(new DoesNotExistException('not found'));

		$this->expectException(DoesNotExistException::class);
		$this->service->update(99, 'Anything', 'tag');
	}

	public function testDeleteClearsCategoryFromAppointmentsThenDeletes(): void {
		$existing = $this->category(1, 'Rehearsal');
		$this->categoryMapper->method('find')->with(1)->willReturn($existing);
		$this->appointmentMapper->expects($this->once())->method('clearCategory')->with(1);
		$this->permissionService->expects($this->once())->method('forgetSeeAllCategory')->with(1);
		$this->categoryMapper->expects($this->once())->method('delete')->with($existing);

		$this->service->delete(1);
	}

	public function testDeleteThrowsWhenCategoryMissing(): void {
		$this->categoryMapper->method('find')->with(99)
			->willThrowException(new DoesNotExistException('not found'));
		$this->appointmentMapper->expects($this->never())->method('clearCategory');

		$this->expectException(DoesNotExistException::class);
		$this->service->delete(99);
	}
}
