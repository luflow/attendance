<?php

declare(strict_types=1);

use OCP\Util;

const appId = OCA\Attendance\AppInfo\Application::APP_ID;
Util::addScript(appId, appId . '-main');
Util::addStyle(appId, appId . '-main');

// Get Nextcloud major version for CSS compatibility
$ncVersion = \OCP\Util::getVersion();
$ncMajorVersion = $ncVersion[0];

?>

<div id="attendance" data-nc-version="<?php p($ncMajorVersion); ?>"></div>
