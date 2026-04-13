<?php
/**
 * DubBot GraphQL proxy
 * Forwards authenticated requests to https://api.dubbot.com/graphql
 * Requires DUBBOT_API_KEY to be defined in config.php
 */
session_start();
require_once 'config.php';

if (empty($_SESSION['auth'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!defined('DUBBOT_API_KEY') || DUBBOT_API_KEY === '') {
    http_response_code(500);
    echo json_encode(['error' => 'DUBBOT_API_KEY not set in config.php']);
    exit;
}

$body    = file_get_contents('php://input');
$payload = json_decode($body, true);

if (empty($payload['query'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing GraphQL query']);
    exit;
}

$ch = curl_init('https://api.dubbot.com/graphql');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-API-KEY: ' . DUBBOT_API_KEY,
    ],
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(502);
    echo json_encode(['error' => 'cURL: ' . $curlError]);
    exit;
}

header('Content-Type: application/json');
http_response_code($httpCode);
echo $response;
