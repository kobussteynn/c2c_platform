<?php
require_once __DIR__ . "/includes/debug_tools.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$raw = file_get_contents("php://input");
$contentType = $_SERVER["CONTENT_TYPE"] ?? "";
$payload = [];

if (stripos($contentType, "application/json") !== false) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
} elseif (stripos($contentType, "application/x-www-form-urlencoded") !== false) {
    parse_str($raw, $parsed);
    if (is_array($parsed)) {
        $payload = $parsed;
    }
} else {
    $payload = [
        "raw" => $raw
    ];
}

c2c_debug_log("client_beacon", [
    "content_type" => $contentType,
    "payload" => $payload
], C2C_NAV_DEBUG_LOG_FILE);

http_response_code(204);

