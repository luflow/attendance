<?php

declare(strict_types=1);

namespace OCA\Attendance\Notification;

use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class Notifier implements INotifier {
	private IFactory $l10nFactory;
	private IURLGenerator $urlGenerator;

	public function __construct(
		IFactory $l10nFactory,
		IURLGenerator $urlGenerator
	) {
		$this->l10nFactory = $l10nFactory;
		$this->urlGenerator = $urlGenerator;
	}

	public function getID(): string {
		return 'attendance';
	}

	public function getName(): string {
		return $this->l10nFactory->get('attendance')->t('Attendance');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'attendance') {
			throw new \InvalidArgumentException('Unknown app');
		}

		$l = $this->l10nFactory->get('attendance', $languageCode);

		switch ($notification->getSubject()) {
			case 'appointment_reminder':
				$parameters = $notification->getSubjectParameters();
				$appointmentName = $parameters['name'] ?? 'Unknown';
				$appointmentDate = $parameters['date'] ?? 'Unknown';

				$notification->setParsedSubject(
					$l->t('Response missing: %s on %s', [$appointmentName, $appointmentDate])
				);
				$notification->setParsedMessage(
					$l->t('Please respond to the upcoming appointment!')
				);
				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath('attendance', 'app-dark.svg')
				));

				return $notification;

			case 'appointment_created':
				$parameters = $notification->getSubjectParameters();
				$appointmentName = $parameters['name'] ?? 'Unknown';
				$appointmentDate = $parameters['date'] ?? 'Unknown';

				$notification->setParsedSubject(
					$l->t('New appointment: %s on %s', [$appointmentName, $appointmentDate])
				);
				$notification->setParsedMessage(
					$l->t('A new appointment has been created. Please respond soon.')
				);
				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath('attendance', 'app-dark.svg')
				));

				return $notification;

			default:
				throw new \InvalidArgumentException('Unknown subject');
		}
	}
}
