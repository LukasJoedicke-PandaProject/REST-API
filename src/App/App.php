<?php

declare(strict_types=1);
date_default_timezone_set('Europe/Berlin');
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/Container.php';
$customErrorHandler = require __DIR__ . '/ErrorHandler.php';
(require __DIR__ . '/Middlewares.php')($app, $customErrorHandler);
(require __DIR__ . '/Cors.php')($app);
(require __DIR__ . '/Database.php');
(require __DIR__ . '/Services.php');
(require __DIR__ . '/Repositories.php');
(require __DIR__ . '/Routes.php');
(require __DIR__ . '/NotFound.php')($app);

return $app;