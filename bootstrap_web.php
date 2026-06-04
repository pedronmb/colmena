<?php

declare(strict_types=1);

use App\Bootstrap;

$projectRoot = __DIR__;

require_once $projectRoot . '/src/Bootstrap.php';

Bootstrap::registerAutoload($projectRoot);
Bootstrap::sessionStart();

if (!isset($GLOBALS['COLMENA_CONFIG'])) {
    $GLOBALS['COLMENA_CONFIG'] = \App\Support\ConfigLoader::load($projectRoot);
}

return $GLOBALS['COLMENA_CONFIG'];
