<?php

declare(strict_types=1);

namespace OCA\Attendance\Middleware;

use OC\AppFramework\OCS\BaseResponse;
use OC\Core\Controller\ContactsMenuController;
use OC\Core\Controller\HoverCardController;
use OCA\Attendance\Service\ConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\Contacts\ContactsMenu\IEntry;
use Psr\Log\LoggerInterface;

/**
 * Strips the email address of accounts from the menus core builds for a
 * contact. Core has no setting or hook for this, hence the rewrite.
 */
final class HideAccountEmailMiddleware extends Middleware {
	public function __construct(
		private ConfigService $configService,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		if (!$controller instanceof HoverCardController && !$controller instanceof ContactsMenuController) {
			return $response;
		}
		// An account that was not found has no menu to strip.
		if ($response->getStatus() !== Http::STATUS_OK || !$this->configService->isAccountEmailHidden()) {
			return $response;
		}

		try {
			if ($response instanceof BaseResponse) {
				// By now the dispatcher has wrapped the DataResponse; the wrapper has no accessors.
				$data = new \ReflectionProperty(BaseResponse::class, 'data');
				$data->setValue($response, $this->stripHoverCard($data->getValue($response)));
			} elseif ($response instanceof JSONResponse) {
				$response->setData($this->stripContactsMenu($response->getData()));
			} else {
				throw new \UnexpectedValueException('Unexpected response ' . $response::class);
			}
		} catch (\Throwable $e) {
			// The response goes out unchanged, so the address is still in it.
			$this->logger->error('Could not hide account email addresses in ' . $controller::class . '::' . $methodName, [
				'app' => 'attendance',
				'exception' => $e,
			]);
		}

		return $response;
	}

	private function stripHoverCard(mixed $data): array {
		if (!is_array($data) || !isset($data['actions']) || !is_array($data['actions'])) {
			throw new \UnexpectedValueException('Hover card without a list of actions');
		}
		$data['actions'] = $this->withoutEmailActions($data['actions']);
		return $data;
	}

	private function stripContactsMenu(mixed $data): array|IEntry {
		if ($data instanceof IEntry) {
			return $this->stripEntry($data);
		}
		if (!is_array($data) || !isset($data['contacts']) || !is_array($data['contacts'])) {
			throw new \UnexpectedValueException('Contacts menu without a list of contacts');
		}
		$data['contacts'] = array_map(fn (mixed $entry): array|IEntry => $this->stripEntry($entry), $data['contacts']);
		return $data;
	}

	private function stripEntry(mixed $entry): array|IEntry {
		if (!$entry instanceof IEntry) {
			throw new \UnexpectedValueException('Contact that is not a menu entry');
		}
		// Contacts from someone's own address books are theirs to see.
		if ($entry->getProperty('isLocalSystemBook') !== true) {
			return $entry;
		}
		return $this->stripSerializedEntry($entry->jsonSerialize());
	}

	private function stripSerializedEntry(mixed $data): array {
		if (!is_array($data)) {
			throw new \UnexpectedValueException('Menu entry that does not serialize to an array');
		}

		$actions = isset($data['actions']) && is_array($data['actions']) ? $data['actions'] : [];
		if (isset($data['topAction'])) {
			array_unshift($actions, $data['topAction']);
		}
		$actions = $this->withoutEmailActions($actions);

		return array_merge($data, [
			'topAction' => $actions[0] ?? null,
			'actions' => array_slice($actions, 1),
			'emailAddresses' => [],
		]);
	}

	/**
	 * @return list<mixed>
	 */
	private function withoutEmailActions(array $actions): array {
		return array_values(array_filter($actions, fn (mixed $action): bool => !$this->isEmailAction($action)));
	}

	private function isEmailAction(mixed $action): bool {
		if (!is_array($action)) {
			return false;
		}
		// Matching the link too catches a mail action another app might add.
		return ($action['appId'] ?? null) === 'email'
			|| (isset($action['hyperlink']) && is_string($action['hyperlink']) && str_starts_with($action['hyperlink'], 'mailto:'));
	}
}
