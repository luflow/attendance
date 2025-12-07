<?php

declare(strict_types=1);

// Load composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Manually register OCP namespace since nextcloud/ocp doesn't define autoloading
spl_autoload_register(function ($class) {
	if (strpos($class, 'OCP\\') === 0 || strpos($class, 'NCU\\') === 0) {
		$path = __DIR__ . '/../vendor/nextcloud/ocp/' . str_replace('\\', '/', $class) . '.php';
		if (file_exists($path)) {
			require_once $path;
		}
	}
});
