<?php

declare(strict_types=1);

use OCP\Util;

const appId = OCA\Attendance\AppInfo\Application::APP_ID;
Util::addScript(appId, appId . '-personal');
Util::addStyle(appId, appId . '-personal');

?>

<div id="attendance-personal-settings-vue"></div>
