<?php

declare(strict_types=1);

$app->get('/v1/status', 'App\Controller\Home:getStatus');
$app->get('/v1/authenticateJar', 'App\Controller\FileAuthController:authenticateJar');
$app->post('/v1/checkKey', 'App\Controller\ValidationController:checkKey');
$app->post('/v1/checkLauncherVersion', 'App\Controller\ValidationController:checkLauncherVersion');