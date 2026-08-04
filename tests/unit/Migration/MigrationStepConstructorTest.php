<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;

/**
 * MigrationService falls back to `new $class()` without arguments when
 * service resolution fails during an upgrade, so a migration step with
 * required constructor parameters can fatal mid-upgrade and strand the
 * instance in maintenance mode (happened with 1.44.0 in the wild).
 */
class MigrationStepConstructorTest extends TestCase {
	public function testMigrationStepsAreConstructibleWithoutArguments(): void {
		$files = glob(__DIR__ . '/../../../lib/Migration/Version*.php');
		$this->assertNotEmpty($files);

		foreach ($files as $file) {
			$class = 'OCA\\Attendance\\Migration\\' . basename($file, '.php');
			$this->assertTrue(class_exists($class), "$class not autoloadable");

			$constructor = (new \ReflectionClass($class))->getConstructor();
			$required = $constructor === null ? 0 : $constructor->getNumberOfRequiredParameters();
			$this->assertSame(
				0,
				$required,
				"$class must be constructible without arguments — resolve services inside the step instead",
			);
		}
	}
}
