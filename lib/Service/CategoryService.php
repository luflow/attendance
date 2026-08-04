<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\Category;
use OCA\Attendance\Db\CategoryMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Admin-managed category list. Categories carry a name and an icon chosen
 * from a fixed, curated set — no color; the frontend badges use the
 * Nextcloud/Flutter theme accent instead. The icon set is intentionally
 * small and explicitly mapped to a matching icon on both platforms (web:
 * vue-material-design-icons, Flutter: Icons.*), so a chosen icon is
 * guaranteed to exist everywhere without shipping a new icon-font dependency.
 */
class CategoryService {
	/**
	 * @var list<string>
	 */
	public const ICONS = [
		'tag',
		'music-note',
		'microphone',
		'calendar',
		'group',
		'star',
		'flag',
		'heart',
		'coffee',
		'basketball',
		'soccer',
		'book',
		'car',
		'school',
		'cake',
		'trophy',
		'cocktail',
		'home',
		'camera',
		'theater',
		'airplane',
		'palette',
	];

	private CategoryMapper $categoryMapper;
	private AppointmentMapper $appointmentMapper;

	public function __construct(CategoryMapper $categoryMapper, AppointmentMapper $appointmentMapper) {
		$this->categoryMapper = $categoryMapper;
		$this->appointmentMapper = $appointmentMapper;
	}

	/**
	 * @return list<Category>
	 */
	public function getAll(): array {
		return $this->categoryMapper->findAll();
	}

	/**
	 * @throws \InvalidArgumentException When the name is empty, already taken, or the icon is not in CategoryService::ICONS
	 */
	public function create(string $name, string $icon): Category {
		$name = $this->validateName($name);
		$icon = $this->validateIcon($icon);

		$category = new Category();
		$category->setName($name);
		$category->setIcon($icon);
		return $this->categoryMapper->insert($category);
	}

	/**
	 * @throws DoesNotExistException When the category does not exist
	 * @throws \InvalidArgumentException When the name is empty, already taken, or the icon is not in CategoryService::ICONS
	 */
	public function update(int $id, string $name, string $icon): Category {
		$category = $this->categoryMapper->find($id);
		$category->setName($this->validateName($name, $id));
		$category->setIcon($this->validateIcon($icon));
		return $this->categoryMapper->update($category);
	}

	/**
	 * Deletes the category and clears it from any appointment that
	 * referenced it — appointments never end up pointing at a category that
	 * no longer exists.
	 *
	 * @throws DoesNotExistException When the category does not exist
	 */
	public function delete(int $id): void {
		$category = $this->categoryMapper->find($id);
		$this->appointmentMapper->clearCategory($id);
		$this->categoryMapper->delete($category);
	}

	/**
	 * @throws \InvalidArgumentException When the name is empty or already taken by another category
	 */
	private function validateName(string $name, ?int $ignoreId = null): string {
		$name = trim($name);
		if ($name === '') {
			throw new \InvalidArgumentException('Category name must not be empty.');
		}

		try {
			$existing = $this->categoryMapper->findByName($name);
			if ($existing->getId() !== $ignoreId) {
				throw new \InvalidArgumentException('A category with this name already exists.');
			}
		} catch (DoesNotExistException) {
			// Name is free — nothing to do.
		}

		return $name;
	}

	/**
	 * @throws \InvalidArgumentException When the icon is not in CategoryService::ICONS
	 */
	private function validateIcon(string $icon): string {
		if (!in_array($icon, self::ICONS, true)) {
			throw new \InvalidArgumentException('Unknown category icon.');
		}

		return $icon;
	}
}
