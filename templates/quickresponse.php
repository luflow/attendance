<?php

declare(strict_types=1);

use OCP\Util;

const appId = OCA\Attendance\AppInfo\Application::APP_ID;
Util::addScript(appId, appId . '-quickresponse');
Util::addStyle(appId, appId . '-quickresponse');

?>

<div id="attendance-quick-response" class="guest-box"></div>
