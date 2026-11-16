<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Middleware;

use OC\AppFramework\OCS\V2Response;
use OC\Core\Controller\ContactsMenuController;
use OC\Core\Controller\HoverCardController;
use OCA\Attendance\Controller\AdminController;
use OCA\Attendance\Middleware\HideAccountEmailMiddleware;
use OCA\Attendance\Service\ConfigService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Contacts\ContactsMenu\IEntry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HideAccountEmailMiddlewareTest extends TestCase {
	private const EMAIL = ['title' => 'ada@example.org', 'icon' => '', 'hyperlink' => 'mailto:ada@example.org', 'appId' => 'email'];
	private const PROFILE = ['title' => 'View profile', 'icon' => '', 'hyperlink' => 'https://cloud.example.org/u/ada', 'appId' => 'profile'];
	private const CALL = ['title' => 'Call Ada', 'icon' => '', 'hyperlink' => 'https://cloud.example.org/call', 'appId' => 'spreed'];

	private ConfigService|MockObject $configService;
	private LoggerInterface|MockObject $logger;
	private HideAccountEmailMiddleware $middleware;

	protected function setUp(): void {
		$this->configService = $this->createMock(ConfigService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->middleware = new HideAccountEmailMiddleware($this->configService, $this->logger);
	}

	private function hidden(bool $hidden = true): void {
		$this->configService->method('isAccountEmailHidden')->willReturn($hidden);
	}

	private function ocs(array $data, int $status = Http::STATUS_OK): V2Response {
		return new V2Response(new DataResponse($data, $status));
	}

	private function ocsData(Response $response): mixed {
		return (new \ReflectionProperty($response, 'data'))->getValue($response);
	}

	private function hoverCard(): V2Response {
		return $this->ocs(['userId' => 'ada', 'displayName' => 'Ada', 'actions' => [self::CALL, self::EMAIL, self::PROFILE]]);
	}

	/**
	 * @param array<string, mixed> $properties
	 */
	private function entry(array $properties, ?array $topAction, array $actions): IEntry {
		$entry = $this->createMock(IEntry::class);
		$entry->method('getProperty')->willReturnCallback(fn (string $key) => $properties[$key] ?? null);
		$entry->method('jsonSerialize')->willReturn([
			'id' => 'ada',
			'fullName' => 'Ada',
			'topAction' => $topAction,
			'actions' => $actions,
			'emailAddresses' => ['ada@example.org'],
		]);
		return $entry;
	}

	public function testTheAvatarMenuLosesOnlyItsEmailEntry(): void {
		$this->hidden();
		$response = $this->hoverCard();

		$this->middleware->afterController($this->createMock(HoverCardController::class), 'getUser', $response);

		$this->assertSame(
			['userId' => 'ada', 'displayName' => 'Ada', 'actions' => [self::CALL, self::PROFILE]],
			$this->ocsData($response),
		);
	}

	public function testNothingChangesWhileTheSettingIsOff(): void {
		$this->hidden(false);
		$response = $this->hoverCard();

		$this->middleware->afterController($this->createMock(HoverCardController::class), 'getUser', $response);

		$this->assertContains(self::EMAIL, $this->ocsData($response)['actions']);
	}

	/** It runs on every request of every app, so the setting is only read for the three controllers. */
	public function testOtherControllersAreLeftAlone(): void {
		$this->configService->expects($this->never())->method('isAccountEmailHidden');
		$response = $this->hoverCard();

		$returned = $this->middleware->afterController($this->createMock(AdminController::class), 'getSettings', $response);

		$this->assertSame($response, $returned);
		$this->assertContains(self::EMAIL, $this->ocsData($response)['actions']);
	}

	public function testAnAccountNotFoundPassesThrough(): void {
		$this->hidden();
		$response = $this->ocs([], Http::STATUS_NOT_FOUND);
		$this->logger->expects($this->never())->method('error');

		$this->middleware->afterController($this->createMock(HoverCardController::class), 'getUser', $response);

		$this->assertSame([], $this->ocsData($response));
	}

	/** A mail action another app adds has a different app ID but the same kind of link. */
	public function testAMailLinkFromAnotherAppIsRemovedToo(): void {
		$this->hidden();
		$foreign = ['title' => 'Write a mail', 'icon' => '', 'hyperlink' => 'mailto:ada@example.org', 'appId' => 'mail'];
		$response = $this->ocs(['userId' => 'ada', 'displayName' => 'Ada', 'actions' => [$foreign, self::PROFILE]]);

		$this->middleware->afterController($this->createMock(HoverCardController::class), 'getUser', $response);

		$this->assertSame([self::PROFILE], $this->ocsData($response)['actions']);
	}

	public function testTheContactsMenuHidesTheAddressOfAccountsOnly(): void {
		$this->hidden();
		$account = $this->entry(['isLocalSystemBook' => true], self::EMAIL, [self::CALL, self::PROFILE]);
		$ownContact = $this->entry([], self::EMAIL, []);
		$response = new JSONResponse(['contacts' => [$account, $ownContact], 'contactsAppEnabled' => true]);

		$this->middleware->afterController($this->createMock(ContactsMenuController::class), 'index', $response);

		$data = $response->getData();
		$this->assertSame([
			'id' => 'ada',
			'fullName' => 'Ada',
			'topAction' => self::CALL,
			'actions' => [self::PROFILE],
			'emailAddresses' => [],
		], $data['contacts'][0]);
		$this->assertSame($ownContact, $data['contacts'][1]);
		$this->assertTrue($data['contactsAppEnabled']);
	}

	public function testAnAccountWithNothingButAnAddressKeepsNoAction(): void {
		$this->hidden();
		$response = new JSONResponse($this->entry(['isLocalSystemBook' => true], self::EMAIL, []));

		$this->middleware->afterController($this->createMock(ContactsMenuController::class), 'findOne', $response);

		$data = $response->getData();
		$this->assertNull($data['topAction']);
		$this->assertSame([], $data['actions']);
		$this->assertSame([], $data['emailAddresses']);
	}

	/** A core release that reshapes the menu would otherwise show the address again unnoticed. */
	public function testAMenuItDoesNotRecogniseIsLoggedAndStillGoesOut(): void {
		$this->hidden();
		$response = $this->ocs(['userId' => 'ada', 'entries' => [self::EMAIL]]);
		$this->logger->expects($this->once())->method('error');

		$returned = $this->middleware->afterController($this->createMock(HoverCardController::class), 'getUser', $response);

		$this->assertSame($response, $returned);
		$this->assertSame(['userId' => 'ada', 'entries' => [self::EMAIL]], $this->ocsData($response));
	}

	public function testAContactsMenuItDoesNotRecogniseIsLoggedToo(): void {
		$this->hidden();
		$response = new JSONResponse(['people' => []]);
		$this->logger->expects($this->once())->method('error');

		$this->middleware->afterController($this->createMock(ContactsMenuController::class), 'index', $response);

		$this->assertSame(['people' => []], $response->getData());
	}
}
