<?php

declare(strict_types=1);

use OCP\Util;

const appId = OCA\Attendance\AppInfo\Application::APP_ID;
Util::addScript(appId, appId . '-settings');
Util::addStyle(appId, appId . '-settings');

?>

<div id="attendance-admin-settings-vue"></div>
