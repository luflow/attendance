<?php

declare(strict_types=1);

namespace OCA\Attendance\Activity;

use OCP\Activity\IFilter;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * The "Attendance" entry in the activity app's sidebar: everything this app
 * published, in one place (issue #307).
 */
final class Filter implements IFilter {
	public function __construct(
		private IL10N $l,
		private IURLGenerator $urlGenerator,
	) {
	}

	#[\Override]
	public function getIdentifier(): string {
		return 'attendance';
	}

	#[\Override]
	public function getName(): string {
		return $this->l->t('Attendance');
	}

	#[\Override]
	public function getPriority(): int {
		return 50;
	}

	#[\Override]
	public function getIcon(): string {
		return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('attendance', 'app-dark.svg'));
	}

	#[\Override]
	public function filterTypes(array $types): array {
		return $types;
	}

	#[\Override]
	public function allowedApps(): array {
		return ['attendance'];
	}
}
