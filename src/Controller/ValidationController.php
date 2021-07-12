<?php

declare(strict_types=1);

namespace App\Controller;

use App\Helper\JsonResponse;
use DateTime;
use DiscordWebhook\Webhook;
use GuzzleHttp\Client;
use Pimple\Psr11\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ValidationController extends AbstractController
{
    function __construct($container)
    {
        parent::__construct($container);
    }

    /**
     * Validating the informations which got sent by the client
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function checkKey(Request $request, Response $response): Response
    {
        $pandaLicenseKey = $request->getParsedBody()["key"];
        $userCDriveID = $request->getParsedBody()["cid"];
        $requestDate = $request->getParsedBody()["requestDate"];
        $clientName = $request->getParsedBody()["client"];
        $pcName = $request->getParsedBody()["pcName"];
        $tokenBase64 = $request->getParsedBody()["token"];

        //If one of the body parts didn't get send, respond with a 404
        if (empty($pandaLicenseKey) || empty($userCDriveID) || empty($requestDate) || empty($clientName) || empty($pcName) || empty($tokenBase64)) {
            return JsonResponse::withJson($response, json_encode("404 not found"), 404);
        }

        $requestTimestamp = date_create_from_format('Y-m-d H:i:s', $requestDate)->getTimestamp();

        if ($this->isRequestInRange($requestTimestamp) == false) {
            return JsonResponse::withJson($response, json_encode("Request timed out"), 404);
        }

        if ($this->isOTPValid($tokenBase64, $userCDriveID, $requestDate) == false) {
            return JsonResponse::withJson($response, json_encode("Token did not match."), 404);
        }

        $statement = $this->db->prepare("SELECT * FROM user_informations WHERE `key` = :licenseKey AND pc_id IS NULL");
        $statement->bindParam("licenseKey", $pandaLicenseKey);
        $statement->execute();
        $podResult = $statement->fetch();

        if ($podResult) {
            //New User registered a key
            $registerKeyStatement = $this->db->prepare("UPDATE user_informations SET `pc_id` = :userCDriveID where `key` = :licenseKey;");
            $registerKeyStatement->bindParam("userCDriveID", $userCDriveID);
            $registerKeyStatement->bindParam("licenseKey", $pandaLicenseKey);
            $registerKeyStatement->execute();

            $expiryDate = date("Y-m-d H:m:s", strtotime("+" . $podResult["duration_hours"] . " hours"));
            $this->addLicenseDateToDatabase($podResult["duration_hours"], $pandaLicenseKey);
            $resultWithNewDriveID = ["pc_id" => $userCDriveID, "expiring_at" => $expiryDate];

            $otpBcrypt = $this->generateOTP($userCDriveID, $requestTimestamp, $pandaLicenseKey);
            $otpBase64 = base64_encode($otpBcrypt);
            $oneTimePassword = ["token" => $otpBase64];

            $podResult = array_replace($podResult, $resultWithNewDriveID);
            $podResult = array_replace($podResult, $oneTimePassword);
            return JsonResponse::withJson($response, json_encode($podResult), 200);
        } else {
            //Not a new user. Check if key+hwid is valid
            $checkIfReturningUserStatement = $this->db->prepare("SELECT * FROM user_informations WHERE `key` = :licenseKey AND pc_id = :userCDriveID");
            $checkIfReturningUserStatement->bindParam("userCDriveID", $userCDriveID);
            $checkIfReturningUserStatement->bindParam("licenseKey", $pandaLicenseKey);
            $checkIfReturningUserStatement->execute();
            $alreadyRegistered = $checkIfReturningUserStatement->fetch();

            if ($alreadyRegistered) {
                //Check if key is expired or not
                $expiryTimestamp =  date_create_from_format('Y-m-d H:i:s', $alreadyRegistered["expiring_at"])->getTimestamp();

                //Key is expired
                if ($requestTimestamp > $expiryTimestamp) {
                    return JsonResponse::withJson($response, json_encode("Key is expired"), 404);
                }

                //Key is valid
                $otpBcrypt = $this->generateOTP($userCDriveID, $requestTimestamp, $pandaLicenseKey);
                $otpBase64 = base64_encode($otpBcrypt);
                $oneTimePassword = ["token" => $otpBase64];
                $alreadyRegistered = array_replace($alreadyRegistered, $oneTimePassword);
                return JsonResponse::withJson($response, json_encode($alreadyRegistered), 200);
            }

            return JsonResponse::withJson($response, json_encode("Invalid key"), 404);
        }

    }

    /**
     * Validate if the timespan in which the data got sent is in an radius of 30 seconds
     * @param $requestTimestamp
     * @return bool
     */
    private function isRequestInRange($requestTimestamp) {
        $date = new DateTime();
        $nowTimestamp = $date->getTimestamp();

        $timespan = 30;
        if ($nowTimestamp >= $requestTimestamp + $timespan || $nowTimestamp <= $requestTimestamp - $timespan) {
            return false;
        }
        return true;
    }

    /**
     * Check if the OTP which got sent by the client matches
     * @param $tokenBase64
     * @param $userCDriveID
     * @param $requestDate
     * @return bool
     */
    private function isOTPValid($tokenBase64, $userCDriveID, $requestDate) {
        $token = base64_decode($tokenBase64);

        $passwordToMatch = $userCDriveID . "panda" . $requestDate . "security";

        if (password_verify($passwordToMatch, $token) === false) {
            return false;
        }

        return true;
    }

    /**
     * Generating the OTP which will get hashed with bcrypt and later base64 encoded
     * @param $userCDriveID
     * @param $requestTimestamp
     * @param $pandaLicenseKey
     * @return false|string|null
     */
    public function generateOTP($userCDriveID, $requestTimestamp, $pandaLicenseKey) {
        $optionen = [
            'cost' => 12,
        ];
        $concatinatedInfos = $userCDriveID . $requestTimestamp . $pandaLicenseKey . "panda";
        return password_hash($concatinatedInfos, PASSWORD_BCRYPT, $optionen);
    }

    /**
     * Check if the launcher version from the client matches with the online one
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function checkLauncherVersion(Request $request, Response $response): Response
    {
        $validation = $request->getParsedBody()["validation"];

        if ($validation !== "PandaUpdater-v1") {
            return JsonResponse::withJson($response, json_encode("404 Not found"), 404);
        }

        $client = new Client();
        $res = $client->request('GET', 'https://localhost.com/files/launcher_version.txt');

        $pandaLauncherVersionClient = (string)$request->getParsedBody()["launcherVersion"];
        $pandaLauncherVersionOnline = (string)$res->getBody()->getContents();

        $responseToLauncher = ["check_launcher_version" => $pandaLauncherVersionOnline, "need_update" => $pandaLauncherVersionClient !== $pandaLauncherVersionOnline];

        return JsonResponse::withJson($response, json_encode($responseToLauncher), 200);
    }

    /**
     * Add an expiry date to the license in the database
     * @param $durationHours
     * @param $licenseKey
     */
    public function addLicenseDateToDatabase($durationHours, $licenseKey) {
        $expiryDate = date("Y-m-d H:m:s", strtotime("+" . $durationHours . " hours"));
        $setExpiryDateStatement = $this->db->prepare("UPDATE user_informations SET `expiring_at` = :expiryDate where `key` = :licenseKey;");
        $setExpiryDateStatement->bindParam("licenseKey", $licenseKey);
        $setExpiryDateStatement->bindParam("expiryDate", $expiryDate);
        $setExpiryDateStatement->execute();
    }
}
