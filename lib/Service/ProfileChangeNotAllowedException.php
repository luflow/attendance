<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

/**
 * The user backend or an admin setting keeps this profile field read-only.
 */
final class ProfileChangeNotAllowedException extends \RuntimeException {
}
