<?php
session_start();
include("includes/db.php");

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION["user_id"];
$name = $_SESSION["name"] ?? "User";
$role = $_SESSION["role"] ?? "Buyer";
$message = "";
$messageType = "success";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = trim((string)($_POST["action"] ?? ""));

    if (in_array($action, ["save_settings", "mock_verify_phone"], true)) {
        $profileCheck = $conn->prepare("
            SELECT phone_number, is_phone_verified, preferred_language, default_delivery_method
            FROM users
            WHERE user_id = ?
            LIMIT 1
        ");
        $profileCheck->bind_param("i", $user_id);
        $profileCheck->execute();
        $existing = $profileCheck->get_result()->fetch_assoc() ?: [];

        $previousPhone = trim((string)($existing["phone_number"] ?? ""));
        $previousVerified = (int)($existing["is_phone_verified"] ?? 0);

        $postedPhone = trim((string)($_POST["phone_number"] ?? ""));
        $postedLanguage = $_POST["preferred_language"] ?? ($existing["preferred_language"] ?? "en");
        $postedDeliveryMethod = $_POST["default_delivery_method"] ?? ($existing["default_delivery_method"] ?? "Meetup");

        $preferredLanguage = c2cNormalizeLanguage((string)$postedLanguage);
        $defaultDeliveryMethod = c2cNormalizeDeliveryMethod((string)$postedDeliveryMethod);

        $phoneToStore = $postedPhone;
        if ($action === "mock_verify_phone" && $phoneToStore === "") {
            $phoneToStore = $previousPhone;
        }

        $nextVerified = ($phoneToStore !== "" && $phoneToStore === $previousPhone) ? $previousVerified : 0;

        $update = $conn->prepare("
            UPDATE users
            SET phone_number = ?,
                is_phone_verified = ?,
                preferred_language = ?,
                default_delivery_method = ?
            WHERE user_id = ?
        ");
        $update->bind_param("sissi", $phoneToStore, $nextVerified, $preferredLanguage, $defaultDeliveryMethod, $user_id);

        if (!$update->execute()) {
            $messageType = "danger";
            $message = "Could not save settings.";
        } elseif ($action === "save_settings") {
            $_SESSION["lang"] = $preferredLanguage;
            $message = "Settings saved successfully.";
        } else {
            $_SESSION["lang"] = $preferredLanguage;
            $verification = c2cMockVerifyPhone($conn, (int)$user_id);
            $messageType = $verification["type"];
            $message = $verification["message"];
        }
    }
}

$profileStmt = $conn->prepare("
    SELECT email, phone_number, is_phone_verified, preferred_language, default_delivery_method
    FROM users
    WHERE user_id = ?
    LIMIT 1
");
$profileStmt->bind_param("i", $user_id);
$profileStmt->execute();
$profile = $profileStmt->get_result()->fetch_assoc();

$email = (string)($profile["email"] ?? ($_SESSION["email"] ?? ""));
$phoneNumber = (string)($profile["phone_number"] ?? "");
$isPhoneVerified = (int)($profile["is_phone_verified"] ?? 0) === 1;
$preferredLanguage = c2cNormalizeLanguage((string)($profile["preferred_language"] ?? c2cLang()));
$defaultDeliveryMethod = c2cNormalizeDeliveryMethod((string)($profile["default_delivery_method"] ?? "Meetup"));
$languageOptions = c2cAllowedLanguages();
$deliveryOptions = c2cDeliveryMethods();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Settings - C2C Platform</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css?v=20260514ui1" rel="stylesheet">
</head>
<body class="app-body">

<nav class="app-navbar">
    <div class="nav-brand">C2C Marketplace</div>

    <button class="nav-toggle" type="button" aria-label="Toggle navigation" aria-expanded="false">
        <span></span>
        <span></span>
        <span></span>
    </button>

    <div class="nav-actions">
        <a href="dashboard.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_home", "Home")); ?></a>
        <a href="listings.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_marketplace", "Marketplace")); ?></a>
        <a href="sell.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_sell", "Sell")); ?></a>
        <a href="transactions.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_deals", "Deals")); ?></a>
        <a href="messages.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_chat", "Chat")); ?></a>
        <a href="profile.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_profile", "Profile")); ?></a>
        <a href="settings.php" class="btn-dark"><?php echo htmlspecialchars(c2cT("nav_settings", "Settings")); ?></a>
        <a href="support.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_support", "Support")); ?></a>
        <a href="logout.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_logout", "Logout")); ?></a>
    </div>
</nav>

<main class="dashboard-page">
    <section class="dashboard-hero">
        <div>
            <p class="eyebrow">Preferences</p>
            <h1>Settings</h1>
            <p class="hero-text">Set language, delivery defaults, and phone details.</p>
            <p class="hero-text mb-0">Signed in as <strong><?php echo htmlspecialchars($name); ?></strong> (<?php echo htmlspecialchars($role); ?>)</p>
        </div>

        <div class="role-card">
            <p>Verification Status</p>
            <h3><?php echo $isPhoneVerified ? "Verified" : "Pending"; ?></h3>
            <span><?php echo htmlspecialchars($email); ?></span>
        </div>
    </section>

    <?php if ($message !== ""): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> mt-4">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <section class="section-block">
        <div class="table-card">
            <div class="section-header">
                <div>
                    <p class="eyebrow">Account Setup</p>
                    <h2>Language & Delivery</h2>
                </div>
            </div>

            <form method="POST" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Phone Number</label>
                    <input type="text" name="phone_number" class="form-control" value="<?php echo htmlspecialchars($phoneNumber); ?>" placeholder="+27...">
                    <small class="<?php echo $isPhoneVerified ? "text-success" : "text-warning"; ?>">
                        <?php echo $isPhoneVerified ? "Phone verified for secure transactions." : "Phone not verified yet."; ?>
                    </small>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Preferred Language</label>
                    <select name="preferred_language" class="form-control">
                        <?php foreach ($languageOptions as $code => $label): ?>
                            <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $preferredLanguage === $code ? "selected" : ""; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Default Delivery Method</label>
                    <select name="default_delivery_method" class="form-control">
                        <?php foreach ($deliveryOptions as $method): ?>
                            <option value="<?php echo htmlspecialchars($method); ?>" <?php echo $defaultDeliveryMethod === $method ? "selected" : ""; ?>>
                                <?php echo htmlspecialchars($method); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2 d-grid">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" name="action" value="save_settings" class="btn-auth">Save</button>
                </div>

                <div class="col-md-12">
                    <button type="submit" name="action" value="mock_verify_phone" class="btn-action-success">
                        <?php echo htmlspecialchars(c2cT("verify_phone", "Verify Phone")); ?>
                    </button>
                </div>
            </form>
        </div>
    </section>
</main>

<script src="js/mobile-nav.js?v=20260507m"></script>
</body>
</html>

