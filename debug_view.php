<?php
session_start();
require_once __DIR__ . "/includes/debug_tools.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

function tailLines(string $path, int $maxLines = 300): array {
    if (!is_file($path)) {
        return [];
    }

    $content = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($content === false) {
        return [];
    }

    return array_slice($content, -1 * $maxLines);
}

$runtimeLog = tailLines(C2C_DEBUG_LOG_FILE);
$navLog = tailLines(C2C_NAV_DEBUG_LOG_FILE);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Debug Logs - C2C Platform</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: ui-monospace, Menlo, Consolas, monospace; margin: 0; background: #0b1020; color: #dbe3ff; }
        .wrap { width: min(1200px, calc(100% - 24px)); margin: 12px auto 24px; }
        h1, h2 { margin: 8px 0; }
        a { color: #93c5fd; }
        .card { background: #10172a; border: 1px solid #26314d; border-radius: 12px; padding: 12px; margin-top: 12px; }
        pre { margin: 0; white-space: pre-wrap; word-break: break-word; font-size: 12px; line-height: 1.45; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Debug Logs</h1>
    <p><a href="profile.php">Back to app</a></p>

    <div class="card">
        <h2>Runtime Log (latest)</h2>
        <pre><?php echo htmlspecialchars(implode(PHP_EOL, $runtimeLog)); ?></pre>
    </div>

    <div class="card">
        <h2>Nav Beacon Log (latest)</h2>
        <pre><?php echo htmlspecialchars(implode(PHP_EOL, $navLog)); ?></pre>
    </div>
</div>
</body>
</html>

