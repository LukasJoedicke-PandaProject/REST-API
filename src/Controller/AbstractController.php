<?php

declare(strict_types=1);

namespace App\Controller;

use App\Helper\JsonResponse;
use Pimple\Psr11\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;


abstract class AbstractController {
    /** @var \PDO */
    protected $db;

    public function __construct( $container ) {
        $this->db       = $container->get('db');
    }
}
