<?php

if (!defined("C2C_DEBUG_BOOTSTRAPPED")) {
    define("C2C_DEBUG_BOOTSTRAPPED", true);
    define("C2C_DEBUG_LOG_FILE", __DIR__ . "/../logs/runtime-debug.log");
    define("C2C_NAV_DEBUG_LOG_FILE", __DIR__ . "/../logs/nav-debug.log");

    if (!isset($GLOBALS["c2c_request_id"])) {
        try {
            $GLOBALS["c2c_request_id"] = bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            $GLOBALS["c2c_request_id"] = uniqid("c2c_", true);
        }
    }
}

if (!function_exists("c2c_debug_log")) {
    function c2c_debug_log(string $event, array $data = [], string $filePath = C2C_DEBUG_LOG_FILE): void {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $record = [
            "ts" => date("c"),
            "event" => $event,
            "request_id" => $GLOBALS["c2c_request_id"] ?? null,
            "method" => $_SERVER["REQUEST_METHOD"] ?? null,
            "uri" => $_SERVER["REQUEST_URI"] ?? null,
            "session_id" => session_id() ?: null,
            "user_id" => $_SESSION["user_id"] ?? null,
            "ip" => $_SERVER["REMOTE_ADDR"] ?? null,
            "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
            "data" => $data
        ];

        @file_put_contents($filePath, json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
    }
}

