<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Service for generating and validating quick response tokens.
 * Tokens are used for one-click response links in notifications and emails.
 */
class QuickResponseTokenService {
	private IConfig $config;
	private AppointmentMapper $appointmentMapper;
	private IURLGenerator $urlGenerator;
	private LoggerInterface $logger;

	public function __construct(
		IConfig $config,
		AppointmentMapper $appointmentMapper,
		IURLGenerator $urlGenerator,
		LoggerInterface $logger,
	) {
		$this->config = $config;
		$this->appointmentMapper = $appointmentMapper;
		$this->urlGenerator = $urlGenerator;
		$this->logger = $logger;
	}

	/**
	 * Generate a signed token for a quick response.
	 *
	 * @param string $userId The user ID
	 * @param int $appointmentId The appointment ID
	 * @param string $response The response type (yes, no, maybe)
	 * @return string The signed token
	 */
	public function generateToken(string $userId, int $appointmentId, string $response): string {
		$data = $this->buildTokenData($userId, $appointmentId, $response);
		$secret = $this->getSecret();
		return hash_hmac('sha256', $data, $secret);
	}

	/**
	 * Verify a token is valid for the given parameters.
	 *
	 * @param string $token The token to verify
	 * @param string $userId The user ID
	 * @param int $appointmentId The appointment ID
	 * @param string $response The response type
	 * @return bool True if the token is valid
	 */
	public function verifyToken(string $token, string $userId, int $appointmentId, string $response): bool {
		$expectedToken = $this->generateToken($userId, $appointmentId, $response);
		return hash_equals($expectedToken, $token);
	}

	/**
	 * Check if the quick response link for an appointment has expired.
	 * Links expire at the appointment end time.
	 *
	 * @param int $appointmentId The appointment ID
	 * @return bool True if the link has expired
	 */
	public function isExpired(int $appointmentId): bool {
		try {
			$appointment = $this->appointmentMapper->find($appointmentId);
			$endDatetime = new \DateTime($appointment->getEndDatetime(), new \DateTimeZone('UTC'));
			$now = new \DateTime('now', new \DateTimeZone('UTC'));

			return $now > $endDatetime;
		} catch (DoesNotExistException $e) {
			// Appointment doesn't exist, consider as expired
			return true;
		} catch (\Exception $e) {
			$this->logger->error('Error checking token expiration: ' . $e->getMessage());
			return true;
		}
	}

	/**
	 * Get the appointment for a quick response, or null if not found.
	 *
	 * @param int $appointmentId The appointment ID
	 * @return Appointment|null The appointment or null
	 */
	public function getAppointment(int $appointmentId): ?Appointment {
		try {
			return $this->appointmentMapper->find($appointmentId);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Generate the quick response URL for a specific response.
	 *
	 * @param string $userId The user ID
	 * @param int $appointmentId The appointment ID
	 * @param string $response The response type (yes, no, maybe)
	 * @return string The absolute URL for the quick response
	 */
	public function generateQuickResponseUrl(string $userId, int $appointmentId, string $response): string {
		$token = $this->generateToken($userId, $appointmentId, $response);

		return $this->urlGenerator->linkToRouteAbsolute('attendance.quick_response.showConfirmation', [
			'appointmentId' => $appointmentId,
			'response' => $response,
			'token' => $token,
			'userId' => $userId,
		]);
	}

	/**
	 * Log a quick response attempt for security auditing.
	 *
	 * @param int $appointmentId The appointment ID
	 * @param string $userId The user ID
	 * @param string $response The response type
	 * @param bool $success Whether the response was successful
	 * @param string $reason The reason for failure (if applicable)
	 * @param string|null $ipAddress The IP address of the request
	 */
	public function logQuickResponse(
		int $appointmentId,
		string $userId,
		string $response,
		bool $success,
		string $reason = '',
		?string $ipAddress = null,
	): void {
		$logData = [
			'appointmentId' => $appointmentId,
			'userId' => $userId,
			'response' => $response,
			'success' => $success,
			'reason' => $reason,
			'ip' => $ipAddress ?? 'unknown',
		];

		if ($success) {
			$this->logger->info('Quick response recorded', $logData);
		} else {
			$this->logger->warning('Quick response failed', $logData);
		}
	}

	/**
	 * Build the token data string.
	 */
	private function buildTokenData(string $userId, int $appointmentId, string $response): string {
		return $userId . ':' . $appointmentId . ':' . $response;
	}

	/**
	 * Get the secret key for token generation.
	 */
	private function getSecret(): string {
		return $this->config->getSystemValue('secret', '');
	}
}
