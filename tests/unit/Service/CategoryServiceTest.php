<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\Category;
use OCA\Attendance\Db\CategoryMapper;
use OCA\Attendance\Service\CategoryService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CategoryServiceTest extends TestCase {
	/** @var CategoryMapper|MockObject */
	private $categoryMapper;

	/** @var AppointmentMapper|MockObject */
	private $appointmentMapper;

	private CategoryService $service;

	protected function setUp(): void {
		$this->categoryMapper = $this->createMock(CategoryMapper::class);
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);
		$this->service = new CategoryService($this->categoryMapper, $this->appointmentMapper);
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
