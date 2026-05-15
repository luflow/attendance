<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Controller;

use OCA\Attendance\Controller\QuickResponseController;
use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Service\QuickResponseTokenService;
use OCA\Attendance\Service\ResponseService;
use OCP\AppFramework\Http;
use OCP\IInitialStateService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class QuickResponseControllerTest extends TestCase {
	/** @var IRequest|MockObject */
	private $request;
	/** @var QuickResponseTokenService|MockObject */
	private $tokenService;
	/** @var ResponseService|MockObject */
	private $responseService;
	/** @var IInitialStateService|MockObject */
	private $initialStateService;
	/** @var IL10N|MockObject */
	private $l;
	/** @var IUserManager|MockObject */
	private $userManager;
	/** @var LoggerInterface|MockObject */
	private $logger;

	private QuickResponseController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->tokenService = $this->createMock(QuickResponseTokenService::class);
		$this->responseService = $this->createMock(ResponseService::class);
		$this->initialStateService = $this->createMock(IInitialStateService::class);
		$this->l = $this->createMock(IL10N::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Pass-through translator — keeps message assertions readable.
		$this->l->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf(
				str_replace('%n', '%s', $text),
				$params,
			),
		);

		$this->controller = new QuickResponseController(
			'attendance',
			$this->request,
			$this->tokenService,
			$this->responseService,
			$this->initialStateService,
			$this->l,
			$this->userManager,
			$this->logger,
		);
	}

	private function makeAppointment(bool $closed): Appointment {
		$apt = new Appointment();
		$apt->setId(42);
		$apt->setName('Closed Banner Test');
		$apt->setStartDatetime('2030-06-01 10:00:00');
		$apt->setEndDatetime('2030-06-01 11:00:00');
		$apt->setClosedAt($closed ? '2030-05-28 09:00:00' : null);
		$apt->setResponseDeadline(null);
		return $apt;
	}

	private function primeValidationDependencies(Appointment $appointment, string $userId = 'alice'): void {
		// Stub out everything the validator checks *before* the isClosed branch.
		$this->tokenService->method('verifyToken')->willReturn(true);
		$this->tokenService->method('isExpired')->willReturn(false);
		$this->tokenService->method('getAppointment')->willReturn($appointment);

		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Alice Example');
		$this->userManager->method('get')->with($userId)->willReturn($user);
	}

	/**
	 * Invoke the private validator via reflection — that's the seam where
	 * closed/open are distinguished. The public showConfirmation path also
	 * touches OCP\Util::getVersion() which needs OC, so we skip it here and
	 * cover the user-visible 400 path through confirmResponse below.
	 */
	private function invokeValidator(int $appointmentId, string $response, string $token, string $userId): array {
		$reflection = new \ReflectionClass($this->controller);
		$method = $reflection->getMethod('validateQuickResponse');
		return $method->invoke($this->controller, $appointmentId, $response, $token, $userId);
	}

	public function testValidatorFlagsClosedAppointmentAsClosedNotError(): void {
		// Regression: previously this branch returned ['error' => true, ...]
		// and the public page rendered a generic error card. Now it returns
		// ['error' => false, 'closed' => true] so the page can show the
		// "Closed on …" banner with appointment context instead.
		$this->primeValidationDependencies($this->makeAppointment(closed: true));

		$result = $this->invokeValidator(42, 'yes', 'sometoken', 'alice');

		$this->assertFalse($result['error']);
		$this->assertTrue($result['closed']);
		$this->assertArrayNotHasKey('errorMessage', $result);
	}

	public function testValidatorPassesThroughForOpenAppointment(): void {
		$this->primeValidationDependencies($this->makeAppointment(closed: false));

		$result = $this->invokeValidator(42, 'yes', 'sometoken', 'alice');

		$this->assertFalse($result['error']);
		$this->assertArrayNotHasKey('closed', $result);
	}

	public function testConfirmResponseOnClosedAppointmentReturnsBadRequest(): void {
		$appointment = $this->makeAppointment(closed: true);
		$this->primeValidationDependencies($appointment);

		// The 400 path logs the failed attempt — assert that the logger sees
		// success=false plus a closed-themed reason. We don't strictly require
		// it but it's evidence the POST really took the closed branch.
		$this->tokenService->expects($this->once())
			->method('logQuickResponse')
			->with(
				42,
				'alice',
				'yes',
				false,
				$this->matchesRegularExpression('/closed/i'),
				$this->anything(),
			);

		$response = $this->controller->confirmResponse(42, 'yes', 'sometoken', 'alice');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$body = $response->getData();
		$this->assertFalse($body['success']);
		$this->assertMatchesRegularExpression('/closed/i', $body['message']);

		// submitResponse must NEVER run for a closed inquiry.
		$this->responseService->expects($this->never())->method('submitResponse');
	}
}
