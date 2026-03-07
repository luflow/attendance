<?php

declare(strict_types=1);

use OCP\Util;

const appId = OCA\Attendance\AppInfo\Application::APP_ID;
Util::addScript(appId, appId . '-selfcheckin');
Util::addStyle(appId, appId . '-selfcheckin');

?>

<div id="attendance-self-checkin"></div>
