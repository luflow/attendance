<?php

declare(strict_types=1);

/**
 * A handful of OCP interfaces reference classes from the server's private `OC\`
 * namespace in their signatures — `IRootFolder extends \OC\Hooks\Emitter`, for
 * one. `nextcloud/ocp` ships only the public namespace, so PHPUnit cannot build
 * a mock for those interfaces: resolving the parent fails before the mock class
 * is generated.
 *
 * Declaring the referenced shapes here keeps them mockable. Nothing in this
 * file is called; it exists so the reflection PHPUnit does has something to
 * resolve.
 */

namespace OC\Hooks {
	if (!interface_exists(Emitter::class, false)) {
		interface Emitter {
			public function listen(string $scope, string $method, callable $callback): void;

			public function removeListener(?string $scope = null, ?string $method = null, ?callable $callback = null): void;
		}
	}
}

namespace OC\User {
	if (!class_exists(NoUserException::class, false)) {
		class NoUserException extends \Exception {
		}
	}
}
