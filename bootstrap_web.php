<?php

declare(strict_types=1);

use App\Bootstrap;

$projectRoot = __DIR__;

require_once $projectRoot . '/src/Bootstrap.php';

Bootstrap::registerAutoload($projectRoot);
Bootstrap::sessionStart();

if (!isset($GLOBALS['COLMENA_CONFIG'])) {
    $GLOBALS['COLMENA_CONFIG'] = require $projectRoot . '/config/config.php';
}

return $GLOBALS['COLMENA_CONFIG'];
