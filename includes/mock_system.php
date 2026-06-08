<?php

function c2cTableExists(mysqli $conn, string $tableName): bool {
    $safeTableName = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTableName}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function c2cColumnExists(mysqli $conn, string $tableName, string $columnName): bool {
    if (!c2cTableExists($conn, $tableName)) {
        return false;
    }

    $safeColumnName = $conn->real_escape_string($columnName);
    $result = $conn->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$safeColumnName}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function c2cIndexExists(mysqli $conn, string $tableName, string $indexName): bool {
    if (!c2cTableExists($conn, $tableName)) {
        return false;
    }

    $safeIndexName = $conn->real_escape_string($indexName);
    $result = $conn->query("SHOW INDEX FROM `{$tableName}` WHERE Key_name = '{$safeIndexName}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function ensureMockSystemSchema(mysqli $conn): void {
    $createTables = [
        "messages" => "
            CREATE TABLE messages (
                message_id INT AUTO_INCREMENT PRIMARY KEY,
                sender_id INT NULL,
                receiver_id INT NULL,
                transaction_id INT NULL,
                message TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ",
        "disputes" => "
            CREATE TABLE disputes (
                dispute_id INT AUTO_INCREMENT PRIMARY KEY,
                transaction_id INT NOT NULL,
                opened_by_user_id INT NOT NULL,
                reason VARCHAR(120) NOT NULL,
                details TEXT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'Open',
                resolution_note TEXT NULL,
                resolved_by_user_id INT NULL,
                resolved_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ",
        "reviews" => "
            CREATE TABLE reviews (
                review_id INT AUTO_INCREMENT PRIMARY KEY,
                transaction_id INT NOT NULL,
                reviewer_user_id INT NOT NULL,
                reviewee_user_id INT NOT NULL,
                rating TINYINT NOT NULL,
                comment VARCHAR(500) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ",
        "suspicious_flags" => "
            CREATE TABLE suspicious_flags (
                flag_id INT AUTO_INCREMENT PRIMARY KEY,
                transaction_id INT NULL,
                user_id INT NULL,
                trigger_source VARCHAR(60) NOT NULL DEFAULT 'system',
                reason VARCHAR(180) NOT NULL,
                severity VARCHAR(20) NOT NULL DEFAULT 'Medium',
                status VARCHAR(20) NOT NULL DEFAULT 'Open',
                notes TEXT NULL,
                resolved_by_user_id INT NULL,
                resolved_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ",
        "support_tickets" => "
            CREATE TABLE support_tickets (
                ticket_id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                category VARCHAR(80) NOT NULL,
                subject VARCHAR(160) NOT NULL,
                details TEXT NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'Open',
                admin_note TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        "
    ];

    foreach ($createTables as $table => $sql) {
        if (!c2cTableExists($conn, $table)) {
            $conn->query($sql);
        }
    }

    $columnMigrations = [
        ["messages", "transaction_id", "ALTER TABLE messages ADD COLUMN transaction_id INT NULL AFTER receiver_id"],
        ["users", "phone_number", "ALTER TABLE users ADD COLUMN phone_number VARCHAR(20) NULL AFTER email"],
        ["users", "is_phone_verified", "ALTER TABLE users ADD COLUMN is_phone_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER phone_number"],
        ["users", "preferred_language", "ALTER TABLE users ADD COLUMN preferred_language VARCHAR(8) NOT NULL DEFAULT 'en' AFTER is_phone_verified"],
        ["users", "default_delivery_method", "ALTER TABLE users ADD COLUMN default_delivery_method VARCHAR(40) NOT NULL DEFAULT 'Meetup' AFTER preferred_language"],
        ["transactions", "payment_status", "ALTER TABLE transactions ADD COLUMN payment_status VARCHAR(30) NOT NULL DEFAULT 'Unpaid' AFTER status"],
        ["transactions", "payment_method", "ALTER TABLE transactions ADD COLUMN payment_method VARCHAR(50) NOT NULL DEFAULT 'Mock Escrow' AFTER payment_status"],
        ["transactions", "payment_amount", "ALTER TABLE transactions ADD COLUMN payment_amount DECIMAL(10,2) NULL AFTER payment_method"],
        ["transactions", "payment_reference", "ALTER TABLE transactions ADD COLUMN payment_reference VARCHAR(60) NULL AFTER payment_amount"],
        ["transactions", "payment_held_at", "ALTER TABLE transactions ADD COLUMN payment_held_at DATETIME NULL AFTER payment_reference"],
        ["transactions", "payment_released_at", "ALTER TABLE transactions ADD COLUMN payment_released_at DATETIME NULL AFTER payment_held_at"],
        ["transactions", "delivery_method", "ALTER TABLE transactions ADD COLUMN delivery_method VARCHAR(40) NOT NULL DEFAULT 'Meetup' AFTER payment_released_at"],
        ["transactions", "pickup_point", "ALTER TABLE transactions ADD COLUMN pickup_point VARCHAR(255) NULL AFTER delivery_method"],
        ["transactions", "dropoff_point", "ALTER TABLE transactions ADD COLUMN dropoff_point VARCHAR(255) NULL AFTER pickup_point"],
        ["transactions", "delivery_status", "ALTER TABLE transactions ADD COLUMN delivery_status VARCHAR(40) NOT NULL DEFAULT 'Not Started' AFTER dropoff_point"],
        ["transactions", "delivery_tracking_code", "ALTER TABLE transactions ADD COLUMN delivery_tracking_code VARCHAR(60) NULL AFTER delivery_status"],
        ["transactions", "delivery_updated_at", "ALTER TABLE transactions ADD COLUMN delivery_updated_at DATETIME NULL AFTER delivery_tracking_code"],
        ["transactions", "dispute_status", "ALTER TABLE transactions ADD COLUMN dispute_status VARCHAR(30) NOT NULL DEFAULT 'None' AFTER delivery_updated_at"]
    ];

    foreach ($columnMigrations as $migration) {
        [$table, $column, $sql] = $migration;
        if (!c2cColumnExists($conn, $table, $column)) {
            $conn->query($sql);
        }
    }

    $indexMigrations = [
        ["messages", "idx_messages_transaction_id", "ALTER TABLE messages ADD INDEX idx_messages_transaction_id (transaction_id)"],
        ["disputes", "idx_disputes_transaction_id", "ALTER TABLE disputes ADD INDEX idx_disputes_transaction_id (transaction_id)"],
        ["disputes", "idx_disputes_status", "ALTER TABLE disputes ADD INDEX idx_disputes_status (status)"],
        ["reviews", "idx_reviews_transaction_id", "ALTER TABLE reviews ADD INDEX idx_reviews_transaction_id (transaction_id)"],
        ["reviews", "idx_reviews_reviewee_user_id", "ALTER TABLE reviews ADD INDEX idx_reviews_reviewee_user_id (reviewee_user_id)"],
        ["reviews", "uq_reviews_pair", "ALTER TABLE reviews ADD UNIQUE INDEX uq_reviews_pair (transaction_id, reviewer_user_id, reviewee_user_id)"],
        ["suspicious_flags", "idx_suspicious_flags_status", "ALTER TABLE suspicious_flags ADD INDEX idx_suspicious_flags_status (status)"],
        ["suspicious_flags", "idx_suspicious_flags_user_id", "ALTER TABLE suspicious_flags ADD INDEX idx_suspicious_flags_user_id (user_id)"],
        ["suspicious_flags", "idx_suspicious_flags_transaction_id", "ALTER TABLE suspicious_flags ADD INDEX idx_suspicious_flags_transaction_id (transaction_id)"],
        ["support_tickets", "idx_support_tickets_user_id", "ALTER TABLE support_tickets ADD INDEX idx_support_tickets_user_id (user_id)"],
        ["support_tickets", "idx_support_tickets_status", "ALTER TABLE support_tickets ADD INDEX idx_support_tickets_status (status)"]
    ];

    foreach ($indexMigrations as $migration) {
        [$table, $indexName, $sql] = $migration;
        if (c2cTableExists($conn, $table) && !c2cIndexExists($conn, $table, $indexName)) {
            $conn->query($sql);
        }
    }

    $conn->query("\n        UPDATE transactions\n        INNER JOIN products ON products.product_id = transactions.product_id\n        SET transactions.payment_amount = products.price\n        WHERE transactions.payment_amount IS NULL\n    ");
}

function c2cAllowedLanguages(): array {
    return [
        "en" => "English",
        "af" => "Afrikaans",
        "xh" => "isiXhosa"
    ];
}

function c2cNormalizeLanguage(?string $language): string {
    $candidate = strtolower(trim((string)$language));
    return array_key_exists($candidate, c2cAllowedLanguages()) ? $candidate : "en";
}

function c2cApplyLanguageFromRequest(mysqli $conn): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (isset($_GET["lang"])) {
        $_SESSION["lang"] = c2cNormalizeLanguage((string)$_GET["lang"]);
    }

    if (!isset($_SESSION["lang"]) && isset($_SESSION["user_id"])) {
        $userId = (int)$_SESSION["user_id"];
        $stmt = $conn->prepare("SELECT preferred_language FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $_SESSION["lang"] = c2cNormalizeLanguage((string)($row["preferred_language"] ?? "en"));
    }

    if (!isset($_SESSION["lang"])) {
        $_SESSION["lang"] = "en";
    }
}

function c2cLang(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return "en";
    }

    return c2cNormalizeLanguage((string)($_SESSION["lang"] ?? "en"));
}

function c2cT(string $key, ?string $fallback = null): string {
    if ($fallback !== null && trim($fallback) !== "") {
        return $fallback;
    }

    return ucwords(str_replace("_", " ", $key));
}

function c2cRenderLanguageSwitcher(string $class = "btn-ghost"): void {
    $languages = c2cAllowedLanguages();
    $current = c2cLang();

    $requestUri = $_SERVER["REQUEST_URI"] ?? "";
    $path = parse_url($requestUri, PHP_URL_PATH) ?? "";
    $query = parse_url($requestUri, PHP_URL_QUERY) ?? "";

    parse_str((string)$query, $queryParams);
    unset($queryParams["lang"]);

    $queryString = http_build_query($queryParams);
    $base = $path . ($queryString !== "" ? "?" . $queryString : "");
    $separator = strpos($base, "?") === false ? "?" : "&";
    ?>
    <div class="lang-switcher">
        <?php foreach ($languages as $code => $label): ?>
            <?php $activeClass = $code === $current ? "btn-dark" : $class; ?>
            <a href="<?php echo htmlspecialchars($base . $separator . "lang=" . urlencode($code)); ?>" class="<?php echo $activeClass; ?>">
                <?php echo htmlspecialchars(strtoupper($code)); ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
}

function c2cGenerateMockReference(string $prefix = "MOCK"): string {
    try {
        $random = strtoupper(bin2hex(random_bytes(4)));
    } catch (Throwable $e) {
        $random = strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 8));
    }

    return $prefix . "-" . date("ymd") . "-" . $random;
}

function c2cDeliveryStatuses(): array {
    return [
        "Not Started",
        "Awaiting Seller",
        "Ready for Pickup",
        "In Transit",
        "Delivered",
        "Collected",
        "Failed"
    ];
}

function c2cPaymentStatuses(): array {
    return ["Unpaid", "Held in Escrow", "Released", "Refunded"];
}

function c2cDeliveryMethods(): array {
    return ["Meetup", "PAXI Pickup", "Locker Dropoff", "Courier"];
}

function c2cNormalizeDeliveryMethod(?string $method): string {
    $value = trim((string)$method);
    return in_array($value, c2cDeliveryMethods(), true) ? $value : "Meetup";
}

function c2cMockVerifyPhone(mysqli $conn, int $userId): array {
    $profileCheck = $conn->prepare("SELECT phone_number FROM users WHERE user_id = ? LIMIT 1");
    $profileCheck->bind_param("i", $userId);
    $profileCheck->execute();
    $row = $profileCheck->get_result()->fetch_assoc();

    $phone = preg_replace("/\\D+/", "", (string)($row["phone_number"] ?? ""));
    if (strlen($phone) < 10) {
        return [
            "success" => false,
            "type" => "warning",
            "message" => "Add a valid phone number before verification."
        ];
    }

    $verify = $conn->prepare("UPDATE users SET is_phone_verified = 1 WHERE user_id = ?");
    $verify->bind_param("i", $userId);
    $verify->execute();

    return [
        "success" => true,
        "type" => "success",
        "message" => "Phone verified via mock verification."
    ];
}

function c2cNormalizeFlagSeverity(?string $severity): string {
    $allowed = ["Low", "Medium", "High", "Critical"];
    $value = ucfirst(strtolower(trim((string)$severity)));
    return in_array($value, $allowed, true) ? $value : "Medium";
}

function c2cNormalizeFlagStatus(?string $status): string {
    $allowed = ["Open", "Investigating", "Resolved", "Dismissed"];
    $value = ucfirst(strtolower(trim((string)$status)));
    return in_array($value, $allowed, true) ? $value : "Open";
}

function c2cCreateSuspiciousFlag(
    mysqli $conn,
    string $reason,
    string $severity = "Medium",
    ?int $transactionId = null,
    ?int $userId = null,
    string $source = "system"
): void {
    if (!c2cTableExists($conn, "suspicious_flags")) {
        return;
    }

    $trimmedReason = trim($reason);
    if ($trimmedReason === "") {
        return;
    }

    $existing = $conn->prepare("\n        SELECT flag_id\n        FROM suspicious_flags\n        WHERE status IN ('Open', 'Investigating')\n          AND reason = ?\n          AND COALESCE(transaction_id, 0) = COALESCE(?, 0)\n          AND COALESCE(user_id, 0) = COALESCE(?, 0)\n        LIMIT 1\n    ");
    $existing->bind_param("sii", $trimmedReason, $transactionId, $userId);
    $existing->execute();
    if ($existing->get_result()->fetch_assoc()) {
        return;
    }

    $normalizedSeverity = c2cNormalizeFlagSeverity($severity);
    $normalizedSource = trim($source) === "" ? "system" : trim($source);

    $insert = $conn->prepare("\n        INSERT INTO suspicious_flags (transaction_id, user_id, trigger_source, reason, severity, status)\n        VALUES (?, ?, ?, ?, ?, 'Open')\n    ");
    $insert->bind_param("iisss", $transactionId, $userId, $normalizedSource, $trimmedReason, $normalizedSeverity);
    $insert->execute();
}

function getStatusBadgeClass(string $status): string {
    $normalized = strtolower(trim($status));

    if ($normalized === "pending") {
        return "status-pending";
    }
    if ($normalized === "accepted") {
        return "status-accepted";
    }
    if ($normalized === "completed") {
        return "status-completed";
    }
    if ($normalized === "rejected") {
        return "status-rejected";
    }
    if ($normalized === "cancelled") {
        return "status-cancelled";
    }

    return "status-default";
}

function formatStatusLabel(?string $status): string {
    $value = trim((string)$status);
    if ($value === "") {
        return "Unknown";
    }

    $normalized = strtolower(str_replace("_", " ", $value));
    $known = [
        "held in escrow" => "Held in Escrow",
        "not started" => "Not Started",
        "ready for pickup" => "Ready for Pickup",
        "in transit" => "In Transit",
        "in progress" => "In Progress",
        "awaiting seller" => "Awaiting Seller"
    ];

    if (isset($known[$normalized])) {
        return $known[$normalized];
    }

    return ucwords($normalized);
}
