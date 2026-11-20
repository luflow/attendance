<?php

declare(strict_types=1);

namespace OCA\Attendance\Notification;

use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Notifications about the mobile-app license, for Nextcloud admins.
 *
 * They run under their own app id on purpose: the mobile app drops every push
 * that is not from "attendance", and a store build must never show a hint
 * about buying outside the store (App Store 3.1.1/3.1.3, Play payments policy).
 */
final class LicenseNotifier implements INotifier {
	public const APP = 'attendance_license';
	public const OBJECT_TYPE = 'license';
	public const SUBJECT_PURCHASE_INTEREST = 'purchase_interest';

	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
	) {
	}

	#[\Override]
	public function getID(): string {
		return self::APP;
	}

	#[\Override]
	public function getName(): string {
		return $this->l10nFactory->get('attendance')->t('Attendance');
	}

	#[\Override]
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== self::APP || $notification->getSubject() !== self::SUBJECT_PURCHASE_INTEREST) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get('attendance', $languageCode);
		$notification->setParsedSubject(
			// TRANSLATORS: Notification subject for the Nextcloud administrator. Someone in their club or team tried the Attendance mobile app and said that buying the license is not their own decision.
			$l->t('A member of your organization would like to use the mobile app')
		);
		$notification->setParsedMessage(
			// TRANSLATORS: Notification body for the Nextcloud administrator, under the subject "A member of your organization would like to use the mobile app". "The license" is the yearly license for the Attendance mobile app; clubs often need an invoice instead of an app store purchase.
			$l->t('Did you know? You can also buy the license for your whole group by invoice. The Attendance settings show the license status and how to order.')
		);
		$notification->setLink(
			$this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'attendance']) . '#mobile-apps'
		);
		$notification->setIcon($this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath('attendance', 'app-dark.svg'),
		));
		return $notification;
	}
}
