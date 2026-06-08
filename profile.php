<?php
session_start();
include("includes/db.php");

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION["user_id"];
$name = $_SESSION["name"] ?? "User";
$email = $_SESSION["email"] ?? "";
$role = $_SESSION["role"] ?? "Buyer";

$message = "";
$messageType = "success";

if (isset($_GET["listed"]) && $_GET["listed"] === "success") {
    $message = "Product listed successfully.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = trim((string)($_POST["action"] ?? ""));

    if (in_array($action, ["save_profile_prefs", "mock_verify_phone"], true)) {
        $profileCheck = $conn->prepare("
            SELECT phone_number, is_phone_verified, preferred_language
            FROM users
            WHERE user_id = ?
            LIMIT 1
        ");
        $profileCheck->bind_param("i", $user_id);
        $profileCheck->execute();
        $existing = $profileCheck->get_result()->fetch_assoc() ?: [];

        $wasPhone = trim((string)($existing["phone_number"] ?? ""));
        $wasVerified = (int)($existing["is_phone_verified"] ?? 0);

        $postedPhone = trim((string)($_POST["phone_number"] ?? ""));
        $postedLanguage = $_POST["preferred_language"] ?? ($existing["preferred_language"] ?? "en");
        $preferredLanguage = c2cNormalizeLanguage((string)$postedLanguage);

        $phoneToStore = $postedPhone;
        if ($action === "mock_verify_phone" && $phoneToStore === "") {
            $phoneToStore = $wasPhone;
        }

        $nextVerified = ($phoneToStore !== "" && $phoneToStore === $wasPhone) ? $wasVerified : 0;

        $updatePrefs = $conn->prepare("
            UPDATE users
            SET phone_number = ?, is_phone_verified = ?, preferred_language = ?
            WHERE user_id = ?
        ");
        $updatePrefs->bind_param("sisi", $phoneToStore, $nextVerified, $preferredLanguage, $user_id);

        if (!$updatePrefs->execute()) {
            $messageType = "danger";
            $message = "Could not save profile preferences.";
        } elseif ($action === "save_profile_prefs") {
            $_SESSION["lang"] = $preferredLanguage;
            $message = "Profile preferences saved.";
        } else {
            $_SESSION["lang"] = $preferredLanguage;
            $verification = c2cMockVerifyPhone($conn, (int)$user_id);
            $messageType = $verification["type"];
            $message = $verification["message"];
        }
    }
}

$profileStmt = $conn->prepare("
    SELECT phone_number, is_phone_verified, preferred_language
    FROM users
    WHERE user_id = ?
    LIMIT 1
");
$profileStmt->bind_param("i", $user_id);
$profileStmt->execute();
$profile = $profileStmt->get_result()->fetch_assoc();
$phoneNumber = (string)($profile["phone_number"] ?? "");
$isPhoneVerified = (int)($profile["is_phone_verified"] ?? 0) === 1;
$preferredLanguage = c2cNormalizeLanguage((string)($profile["preferred_language"] ?? c2cLang()));
$languageOptions = c2cAllowedLanguages();

$listingsStmt = $conn->prepare("
    SELECT 
        products.product_id,
        products.title,
        products.description,
        products.price,
        products.image,
        products.created_at,
        categories.category_name
    FROM products
    INNER JOIN categories ON products.category_id = categories.category_id
    WHERE products.user_id = ?
    ORDER BY products.created_at DESC
");
$listingsStmt->bind_param("i", $user_id);
$listingsStmt->execute();
$myListings = $listingsStmt->get_result();

$buyingStmt = $conn->prepare("
    SELECT 
        transactions.transaction_id,
        transactions.status,
        transactions.created_at,
        products.title,
        products.price,
        products.image,
        users.name AS seller_name
    FROM transactions
    INNER JOIN products ON transactions.product_id = products.product_id
    INNER JOIN users ON transactions.seller_id = users.user_id
    WHERE transactions.buyer_id = ?
    ORDER BY transactions.created_at DESC
");
$buyingStmt->bind_param("i", $user_id);
$buyingStmt->execute();
$buying = $buyingStmt->get_result();

$overallRatingStmt = $conn->prepare("
    SELECT COUNT(*) AS total_reviews, AVG(rating) AS avg_rating
    FROM reviews
    WHERE reviewee_user_id = ?
");
$overallRatingStmt->bind_param("i", $user_id);
$overallRatingStmt->execute();
$overallRating = $overallRatingStmt->get_result()->fetch_assoc();

$sellerRatingStmt = $conn->prepare("
    SELECT COUNT(*) AS total_reviews, AVG(reviews.rating) AS avg_rating
    FROM reviews
    INNER JOIN transactions ON transactions.transaction_id = reviews.transaction_id
    WHERE reviews.reviewee_user_id = ?
      AND transactions.seller_id = ?
");
$sellerRatingStmt->bind_param("ii", $user_id, $user_id);
$sellerRatingStmt->execute();
$sellerRating = $sellerRatingStmt->get_result()->fetch_assoc();

$buyerRatingStmt = $conn->prepare("
    SELECT COUNT(*) AS total_reviews, AVG(reviews.rating) AS avg_rating
    FROM reviews
    INNER JOIN transactions ON transactions.transaction_id = reviews.transaction_id
    WHERE reviews.reviewee_user_id = ?
      AND transactions.buyer_id = ?
");
$buyerRatingStmt->bind_param("ii", $user_id, $user_id);
$buyerRatingStmt->execute();
$buyerRating = $buyerRatingStmt->get_result()->fetch_assoc();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Profile - C2C Platform</title>
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
        <a href="sell.php" class="btn-dark"><?php echo htmlspecialchars(c2cT("nav_sell", "Sell")); ?></a>
        <a href="transactions.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_deals", "Deals")); ?></a>
        <a href="messages.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_chat", "Chat")); ?></a>
        <a href="settings.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_settings", "Settings")); ?></a>
        <a href="support.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_support", "Support")); ?></a>
        <a href="logout.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_logout", "Logout")); ?></a>
    </div>
</nav>

<main class="dashboard-page">
    <section class="dashboard-hero">
        <div>
            <p class="eyebrow">Profile</p>
            <h1><?php echo htmlspecialchars($name); ?></h1>
            <p class="hero-text"><?php echo htmlspecialchars($email); ?></p>
        </div>

        <div class="role-card">
            <p>Account Role</p>
            <h3><?php echo htmlspecialchars($role); ?></h3>
            <span><?php echo $isPhoneVerified ? htmlspecialchars(c2cT("phone_verified", "Phone Verified")) : htmlspecialchars(c2cT("phone_unverified", "Phone Not Verified")); ?></span>
        </div>
    </section>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> mt-4">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <section class="section-block">
        <div class="table-card">
            <div class="section-header">
                <div>
                    <p class="eyebrow">Trust + Language</p>
                    <h2>Verification & Preferences</h2>
                </div>
            </div>

            <form method="POST" class="row g-3">
                <div class="col-md-5">
                    <label class="form-label">Phone Number</label>
                    <input type="text" name="phone_number" class="form-control" value="<?php echo htmlspecialchars($phoneNumber); ?>" placeholder="+27...">
                    <small class="<?php echo $isPhoneVerified ? "text-success" : "text-warning"; ?>">
                        <?php echo $isPhoneVerified ? "Verified for secure trades." : "Not verified yet."; ?>
                    </small>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Preferred Language</label>
                    <select name="preferred_language" class="form-control">
                        <?php foreach ($languageOptions as $code => $label): ?>
                            <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $preferredLanguage === $code ? "selected" : ""; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3 d-grid">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" name="action" value="save_profile_prefs" class="btn-auth">Save Settings</button>
                </div>

                <div class="col-md-12">
                    <button type="submit" name="action" value="mock_verify_phone" class="btn-action-success">
                        <?php echo htmlspecialchars(c2cT("verify_phone", "Verify Phone")); ?>
                    </button>
                </div>
            </form>
        </div>
    </section>

    <section class="section-block">
            <div class="quick-actions-card">
                <h3>Trade Tools</h3>
                <p>Manage buy/sell requests and chat with buyers or sellers from one place.</p>
                <div class="hero-actions">
                    <a href="transactions.php" class="btn-dark">Open Deals</a>
                    <a href="messages.php" class="btn-light-custom">Open Chat</a>
                    <a href="settings.php" class="btn-light-custom">Settings</a>
                    <a href="debug_view.php" class="btn-light-custom">Debug Logs</a>
                </div>
            </div>
        </section>

    <section class="section-block">
        <div class="product-grid">
            <article class="quick-actions-card">
                <p class="eyebrow">Reputation</p>
                <h3><?php echo number_format((float)($overallRating["avg_rating"] ?? 0), 1); ?> / 5</h3>
                <p><?php echo number_format((int)($overallRating["total_reviews"] ?? 0)); ?> total reviews received.</p>
            </article>

            <article class="quick-actions-card">
                <p class="eyebrow">Seller Rating</p>
                <h3><?php echo number_format((float)($sellerRating["avg_rating"] ?? 0), 1); ?> / 5</h3>
                <p><?php echo number_format((int)($sellerRating["total_reviews"] ?? 0)); ?> reviews on seller transactions.</p>
            </article>

            <article class="quick-actions-card">
                <p class="eyebrow">Buyer Rating</p>
                <h3><?php echo number_format((float)($buyerRating["avg_rating"] ?? 0), 1); ?> / 5</h3>
                <p><?php echo number_format((int)($buyerRating["total_reviews"] ?? 0)); ?> reviews on buyer transactions.</p>
            </article>
        </div>
    </section>

    <section class="section-block">
        <div class="section-header">
            <div>
                <p class="eyebrow">Selling</p>
                <h2>My Product Listings</h2>
            </div>
            <a href="sell.php" class="btn-dark">Add Product</a>
        </div>

        <div class="product-grid">
            <?php if ($myListings->num_rows > 0): ?>
                <?php while ($product = $myListings->fetch_assoc()): ?>
                    <div class="product-card">
                        <img 
                            src="uploads/<?php echo htmlspecialchars($product["image"]); ?>" 
                            alt="<?php echo htmlspecialchars($product["title"]); ?>"
                            class="product-image"
                        >

                        <div class="product-content">
                            <div class="product-category">
                                <?php echo htmlspecialchars($product["category_name"]); ?>
                            </div>

                            <h3><?php echo htmlspecialchars($product["title"]); ?></h3>
                            <p><?php echo htmlspecialchars(substr($product["description"], 0, 90)); ?>...</p>

                            <div class="product-meta">
                                <strong>R<?php echo number_format($product["price"], 2); ?></strong>
                            </div>

                            <a href="product.php?id=<?php echo $product["product_id"]; ?>" class="btn-auth mt-3">View</a>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state">
                    <h3>You have not listed products yet.</h3>
                    <p>Start selling by adding your first product.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="section-block">
        <div class="section-header">
            <div>
                <p class="eyebrow">Buying</p>
                <h2>My Purchase Requests</h2>
            </div>
        </div>

        <div class="table-card">
            <?php if ($buying->num_rows > 0): ?>
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Seller</th>
                            <th>Price</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $buying->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row["title"]); ?></td>
                                <td><?php echo htmlspecialchars($row["seller_name"]); ?></td>
                                <td>R<?php echo number_format($row["price"], 2); ?></td>
                                <td>
                                    <span class="status-badge <?php echo getStatusBadgeClass((string)$row["status"]); ?>">
                                        <?php echo htmlspecialchars(formatStatusLabel($row["status"])); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($row["created_at"]); ?></td>
                                <td>
                                    <div class="deal-actions">
                                        <a href="transactions.php" class="btn-action-link">Manage</a>
                                        <a href="messages.php?t=<?php echo (int)$row["transaction_id"]; ?>" class="btn-action-link">Chat</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <h3>No purchase requests yet.</h3>
                    <p>Browse the marketplace and request to buy an item.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<script src="js/mobile-nav.js?v=20260507m"></script>
</body>
</html>








