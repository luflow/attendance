<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The server internals HideAccountEmailMiddleware matches on. `nextcloud/ocp`
 * ships only the public namespace, so psalm and PHPUnit need them declared.
 */

namespace OC\AppFramework\OCS {
	use OCP\AppFramework\Http\DataResponse;
	use OCP\AppFramework\Http\Response;

	abstract class BaseResponse extends Response {
		/** @var array */
		protected $data;

		public function __construct(DataResponse $dataResponse) {
			parent::__construct($dataResponse->getStatus());
			$this->data = $dataResponse->getData();
		}
	}

	class V2Response extends BaseResponse {
	}
}

namespace OC\Core\Controller {
	use OCP\AppFramework\Controller;
	use OCP\AppFramework\OCSController;

	class HoverCardController extends OCSController {
	}

	class ContactsMenuController extends Controller {
	}
}

namespace OC\Hooks {
	/** OCP\Files\IRootFolder extends it. */
	interface Emitter {
		/**
		 * @param string $scope
		 * @param string $method
		 */
		public function listen($scope, $method, callable $callback);

		/**
		 * @param string|null $scope
		 * @param string|null $method
		 */
		public function removeListener($scope = null, $method = null, ?callable $callback = null);
	}
}
