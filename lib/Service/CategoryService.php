<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\Category;
use OCA\Attendance\Db\CategoryMapper;
use OCA\Attendance\ResponseDefinitions;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Admin-managed category list. Categories carry a name and an icon chosen
 * from a fixed, curated set — no color; the frontend badges use the
 * Nextcloud/Flutter theme accent instead. The icon set is intentionally
 * small and explicitly mapped to a matching icon on both platforms (web:
 * vue-material-design-icons, Flutter: Icons.*), so a chosen icon is
 * guaranteed to exist everywhere without shipping a new icon-font dependency.
 *
 * A category can also carry a template: what a new appointment of that
 * category starts with. Every field is optional; empty means "do not prefill".
 *
 * @psalm-import-type AttendanceCategoryData from ResponseDefinitions
 * @psalm-import-type AttendanceCategoryTemplate from ResponseDefinitions
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
		'bus',
		'cabin',
		'voice',
		'celebration',
	];

	private CategoryMapper $categoryMapper;
	private AppointmentMapper $appointmentMapper;
	private VisibilityService $visibilityService;
	private PermissionService $permissionService;

	public function __construct(
		CategoryMapper $categoryMapper,
		AppointmentMapper $appointmentMapper,
		VisibilityService $visibilityService,
		PermissionService $permissionService,
	) {
		$this->categoryMapper = $categoryMapper;
		$this->appointmentMapper = $appointmentMapper;
		$this->visibilityService = $visibilityService;
		$this->permissionService = $permissionService;
	}

	/**
	 * @return list<Category>
	 */
	public function getAll(): array {
		return $this->categoryMapper->findAll();
	}

	/**
	 * @param bool $withTemplate False drops the template: it names users, groups and teams, which only people who fill in the appointment form need to see
	 * @return AttendanceCategoryData
	 */
	public function serialize(Category $category, bool $withTemplate): array {
		return $category->jsonSerialize() + [
			'template' => $withTemplate ? $this->decodeTemplate($category->getTemplate()) : null,
		];
	}

	/**
	 * @param array<array-key, mixed>|null $template Keys description, visibleUsers, visibleGroups, visibleTeams — all optional
	 * @throws \InvalidArgumentException When the name is empty, already taken, or the icon is not in CategoryService::ICONS
	 */
	public function create(string $name, string $icon, ?array $template = null): Category {
		$name = $this->validateName($name);
		$icon = $this->validateIcon($icon);

		$category = new Category();
		$category->setName($name);
		$category->setIcon($icon);
		$category->setTemplate($this->encodeTemplate($template ?? []));
		return $this->categoryMapper->insert($category);
	}

	/**
	 * @param array<array-key, mixed>|null $template Null keeps the stored template, an empty one removes it
	 * @throws DoesNotExistException When the category does not exist
	 * @throws \InvalidArgumentException When the name is empty, already taken, or the icon is not in CategoryService::ICONS
	 */
	public function update(int $id, string $name, string $icon, ?array $template = null): Category {
		$category = $this->categoryMapper->find($id);
		$category->setName($this->validateName($name, $id));
		$category->setIcon($this->validateIcon($icon));
		if ($template !== null) {
			$category->setTemplate($this->encodeTemplate($template));
		}
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
		$this->permissionService->forgetSeeAllCategory($id);
		$this->categoryMapper->delete($category);
	}

	/** Units a template's relative response deadline may use. */
	private const DEADLINE_UNITS = ['minutes', 'hours', 'days', 'weeks'];

	/** The appointment name and location columns are 255 characters wide. */
	private const SHORT_TEXT_MAX_LENGTH = 255;

	/**
	 * @param array<array-key, mixed> $template
	 * @return array{name: string, description: string, location: string, responseDeadlineValue: ?int, responseDeadlineUnit: ?string, maxAttendees: ?int, waitlistEnabled: ?bool, allowMaybe: ?bool, sendNotification: ?bool, visibleUsers: list<string>, visibleGroups: list<string>, visibleTeams: list<string>}
	 */
	private function normalizeTemplate(array $template): array {
		$deadlineValue = $this->positiveInt($template['responseDeadlineValue'] ?? null);
		$deadlineUnit = $this->deadlineUnit($template['responseDeadlineUnit'] ?? null);
		// A number without a unit, or the other way round, prefills nothing.
		$hasDeadline = $deadlineValue !== null && $deadlineUnit !== null;
		$maxAttendees = $this->positiveInt($template['maxAttendees'] ?? null);

		return [
			'name' => mb_substr($this->plainText($template['name'] ?? null), 0, self::SHORT_TEXT_MAX_LENGTH),
			'description' => $this->plainText($template['description'] ?? null),
			'location' => mb_substr($this->plainText($template['location'] ?? null), 0, self::SHORT_TEXT_MAX_LENGTH),
			'responseDeadlineValue' => $hasDeadline ? $deadlineValue : null,
			'responseDeadlineUnit' => $hasDeadline ? $deadlineUnit : null,
			'maxAttendees' => $maxAttendees,
			// Whether to queue only means something next to a limit.
			'waitlistEnabled' => $maxAttendees !== null ? $this->nullableBool($template['waitlistEnabled'] ?? null) : null,
			'allowMaybe' => $this->nullableBool($template['allowMaybe'] ?? null),
			'sendNotification' => $this->nullableBool($template['sendNotification'] ?? null),
			'visibleUsers' => $this->idList($template['visibleUsers'] ?? null),
			'visibleGroups' => $this->idList($template['visibleGroups'] ?? null),
			'visibleTeams' => $this->idList($template['visibleTeams'] ?? null),
		];
	}

	private function deadlineUnit(mixed $unit): ?string {
		return is_string($unit) && in_array($unit, self::DEADLINE_UNITS, true) ? $unit : null;
	}

	private function positiveInt(mixed $value): ?int {
		return is_int($value) && $value >= 1 ? $value : null;
	}

	private function nullableBool(mixed $value): ?bool {
		return is_bool($value) ? $value : null;
	}

	private function plainText(mixed $text): string {
		// Same rule as an appointment's own description: no raw HTML.
		return is_string($text) ? trim(strip_tags($text)) : '';
	}

	/**
	 * @return list<string>
	 */
	private function idList(mixed $ids): array {
		if (!is_array($ids)) {
			return [];
		}

		return array_values(array_unique(array_filter($ids, fn (mixed $id): bool => is_string($id) && $id !== '')));
	}

	/**
	 * @param array<array-key, mixed> $template
	 * @return ?string The template as stored, null when it would not prefill anything
	 */
	private function encodeTemplate(array $template): ?string {
		$normalized = $this->normalizeTemplate($template);
		// A false switch is a choice, so only null, '' and [] count as empty.
		$filled = array_filter($normalized, fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);

		return $filled === [] ? null : json_encode($normalized, JSON_THROW_ON_ERROR);
	}

	/**
	 * @return ?AttendanceCategoryTemplate
	 */
	private function decodeTemplate(?string $stored): ?array {
		$template = $stored === null ? null : json_decode($stored, true);
		if (!is_array($template)) {
			return null;
		}

		$template = $this->normalizeTemplate($template);

		$fields = $template;
		unset($fields['visibleUsers'], $fields['visibleGroups'], $fields['visibleTeams']);

		return $fields + $this->visibilityService->enrichAudience(
			$template['visibleUsers'],
			$template['visibleGroups'],
			$template['visibleTeams'],
		);
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
