<?php

declare(strict_types=1);

use OCP\Util;

const appId = OCA\Attendance\AppInfo\Application::APP_ID;
Util::addScript(appId, appId . '-main');
Util::addStyle(appId, appId . '-main');

?>

<div id="attendance"></div>
