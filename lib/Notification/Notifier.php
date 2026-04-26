<?php

declare(strict_types=1);

namespace OCA\Attendance\Notification;

use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Service\QuickResponseTokenService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
	private IFactory $l10nFactory;
	private IURLGenerator $urlGenerator;
	private QuickResponseTokenService $tokenService;
	private IConfig $config;
	private AttendanceResponseMapper $responseMapper;

	public function __construct(
		IFactory $l10nFactory,
		IURLGenerator $urlGenerator,
		QuickResponseTokenService $tokenService,
		IConfig $config,
		AttendanceResponseMapper $responseMapper,
	) {
		$this->l10nFactory = $l10nFactory;
		$this->urlGenerator = $urlGenerator;
		$this->tokenService = $tokenService;
		$this->config = $config;
		$this->responseMapper = $responseMapper;
	}

	public function getID(): string {
		return 'attendance';
	}

	public function getName(): string {
		return $this->l10nFactory->get('attendance')->t('Attendance');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'attendance') {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get('attendance', $languageCode);

		// For single-appointment notifications, check if the user already responded
		if (in_array($notification->getSubject(), ['appointment_reminder', 'appointment_created'])) {
			$parameters = $notification->getSubjectParameters();
			$appointmentId = $parameters['appointmentId'] ?? 0;
			if ($appointmentId > 0) {
				try {
					$response = $this->responseMapper->findByAppointmentAndUser($appointmentId, $notification->getUser());
					// Only dismiss if user gave a definitive answer (yes or no).
					// Maybe-responders may still receive reminders to decide.
					if ($response->getResponse() !== 'maybe') {
						throw new AlreadyProcessedException();
					}
				} catch (DoesNotExistException $e) {
					// No response yet — continue preparing
				}
			}
		}

		switch ($notification->getSubject()) {
			case 'appointment_reminder':
				$parameters = $notification->getSubjectParameters();
				$appointmentName = $parameters['name'] ?? 'Unknown';
				$appointmentDate = $this->formatDateForUser(
					$parameters['startDatetime'] ?? $parameters['date'] ?? '',
					$notification->getUser()
				);
				$appointmentId = $parameters['appointmentId'] ?? 0;
				$userId = $notification->getUser();

				$notification->setParsedSubject(
					$l->t('Response missing: %1$s on %2$s', [$appointmentName, $appointmentDate])
				);
				$notification->setParsedMessage(
					$l->t('Please respond to the upcoming appointment!')
				);
				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath('attendance', 'app-dark.svg')
				));

				// Add quick response action buttons
				if ($appointmentId > 0) {
					$this->addQuickResponseActions($notification, $l, $appointmentId, $userId);
				}

				return $notification;

			case 'appointment_created':
				$parameters = $notification->getSubjectParameters();
				$appointmentName = $parameters['name'] ?? 'Unknown';
				$appointmentDate = $this->formatDateForUser(
					$parameters['startDatetime'] ?? $parameters['date'] ?? '',
					$notification->getUser()
				);
				$appointmentId = $parameters['appointmentId'] ?? 0;
				$userId = $notification->getUser();

				$notification->setParsedSubject(
					$l->t('New appointment: %1$s on %2$s', [$appointmentName, $appointmentDate])
				);
				$notification->setParsedMessage(
					$l->t('A new appointment has been created. Please respond soon.')
				);
				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath('attendance', 'app-dark.svg')
				));

				// Add quick response action buttons
				if ($appointmentId > 0) {
					$this->addQuickResponseActions($notification, $l, $appointmentId, $userId);
				}

				return $notification;

			case 'appointments_bulk_created':
				$parameters = $notification->getSubjectParameters();
				$count = $parameters['count'] ?? 0;
				$firstName = $parameters['firstName'] ?? '';

				$notification->setParsedSubject(
					$l->n('%1$s new appointment added (e.g. "%2$s")', '%1$s new appointments added (e.g. "%2$s")', $count, [$count, $firstName])
				);
				$notification->setParsedMessage(
					$l->t('Please check the new appointments and respond.')
				);
				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath('attendance', 'app-dark.svg')
				));

				return $notification;

			default:
				throw new UnknownNotificationException();
		}
	}

	/**
	 * Format a UTC datetime string for display in the user's timezone.
	 */
	private function formatDateForUser(string $utcDatetime, string $userId): string {
		if ($utcDatetime === '') {
			return 'Unknown';
		}

		try {
			$userTimezone = $this->config->getUserValue($userId, 'core', 'timezone', '');
			if ($userTimezone === '') {
				$userTimezone = date_default_timezone_get();
			}
			$date = new \DateTime($utcDatetime, new \DateTimeZone('UTC'));
			$date->setTimezone(new \DateTimeZone($userTimezone));
			return $date->format('d.m.Y H:i');
		} catch (\Exception $e) {
			return $utcDatetime;
		}
	}

	/**
	 * Add quick response action buttons to a notification.
	 *
	 * @param INotification $notification The notification to add actions to
	 * @param \OCP\IL10N $l The localization instance
	 * @param int $appointmentId The appointment ID
	 * @param string $userId The user ID
	 */
	private function addQuickResponseActions(
		INotification $notification,
		\OCP\IL10N $l,
		int $appointmentId,
		string $userId,
	): void {
		// Actions are added in reverse order because the frontend displays them reversed
		// No action (added first, displays last/right)
		$noAction = $notification->createAction();
		$noAction->setLabel('no')
			->setParsedLabel($l->t('No'))
			->setLink(
				$this->tokenService->generateQuickResponseUrl($userId, $appointmentId, 'no'),
				IAction::TYPE_WEB
			)
			->setPrimary(false);
		$notification->addParsedAction($noAction);

		// Maybe action (added second, displays middle)
		$maybeAction = $notification->createAction();
		$maybeAction->setLabel('maybe')
			->setParsedLabel($l->t('Maybe'))
			->setLink(
				$this->tokenService->generateQuickResponseUrl($userId, $appointmentId, 'maybe'),
				IAction::TYPE_WEB
			)
			->setPrimary(false);
		$notification->addParsedAction($maybeAction);

		// Yes action (added last, displays first/left)
		$yesAction = $notification->createAction();
		$yesAction->setLabel('yes')
			->setParsedLabel($l->t('Yes'))
			->setLink(
				$this->tokenService->generateQuickResponseUrl($userId, $appointmentId, 'yes'),
				IAction::TYPE_WEB
			)
			->setPrimary(false);
		$notification->addParsedAction($yesAction);
	}
}
