<?php
require_once __DIR__ . "/mock_system.php";

if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: 0");
}

function getEnvValue($key) {
    $lines = file(__DIR__ . '/../.env');
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || empty(trim($line))) continue;
        list($envKey, $envValue) = explode('=', trim($line), 2);
        if ($envKey === $key) return $envValue;
    }
    return null;
}

$host = getEnvValue('DB_HOST');
$user = getEnvValue('DB_USER');
$password = getEnvValue('DB_PASS');
$database = getEnvValue('DB_NAME');

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

ensureMockSystemSchema($conn);
c2cApplyLanguageFromRequest($conn);
?>

