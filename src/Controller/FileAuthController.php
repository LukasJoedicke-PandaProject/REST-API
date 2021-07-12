<?php

declare(strict_types=1);

namespace App\Controller;

use App\Helper\JsonResponse;
use DateTime;
use Pimple\Psr11\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class FileAuthController extends AbstractController
{

    /** @var Container */
    private $container;

    function __construct($container)
    {
        parent::__construct($container);
    }

    /**
     * Check if the arguments which got sent by the client are correct
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function authenticateJar(Request $request, Response $response): Response
    {
        $originalUriHeader = $request->getHeaderLine("X-Original-URI");
        $parsedOriginalURL = parse_url($originalUriHeader, PHP_URL_QUERY);
        parse_str($parsedOriginalURL, $getArgument);

        $sendedDriveID = $getArgument["driveID"];
        $sendedTimestamp = $getArgument["timestamp"];
        $sendedTokenBase64 = $getArgument["token"];

        if (empty($sendedDriveID) || empty($sendedTimestamp) || empty($sendedTokenBase64)) {
            return JsonResponse::withJson($response, json_encode("404 not found"), 404);
        }

        $date = new DateTime();
        $nowTimestamp = $date->getTimestamp();

        $timespan = 45;
        if ($nowTimestamp >= $sendedTimestamp + $timespan || $nowTimestamp <= $sendedTimestamp - $timespan) {
            return JsonResponse::withJson($response, json_encode("Invalid"), 404);
        }

        $token = base64_decode($sendedTokenBase64);

        $passwordToMatch = $sendedDriveID . "panda" . $sendedTimestamp . "security";

        if (password_verify($passwordToMatch, $token)) {
            return JsonResponse::withJson($response, json_encode("Everything OK, jar is downloading"), 200);
        } else {
            return JsonResponse::withJson($response, json_encode("Wrong arguments"), 404);
        }
    }

}
