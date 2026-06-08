<?php
session_start();
include("includes/db.php");

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

$current_user_id = (int)$_SESSION["user_id"];
$product_id = filter_var($_GET["id"] ?? "", FILTER_VALIDATE_INT);

if (!$product_id) {
    header("Location: listings.php");
    exit();
}

$message = "";
$messageType = "danger";
$selectedDeliveryMethod = trim((string)($_POST["delivery_method"] ?? ""));
$selectedPickupPoint = trim((string)($_POST["pickup_point"] ?? ""));
$selectedDropoffPoint = trim((string)($_POST["dropoff_point"] ?? ""));
$allowedDeliveryMethods = c2cDeliveryMethods();

$viewerStmt = $conn->prepare("
    SELECT phone_number, is_phone_verified, default_delivery_method
    FROM users
    WHERE user_id = ?
    LIMIT 1
");
$viewerStmt->bind_param("i", $current_user_id);
$viewerStmt->execute();
$viewerProfile = $viewerStmt->get_result()->fetch_assoc();
$viewerPhone = trim((string)($viewerProfile["phone_number"] ?? ""));
$viewerIsVerified = (int)($viewerProfile["is_phone_verified"] ?? 0) === 1;
$viewerDefaultDeliveryMethod = c2cNormalizeDeliveryMethod((string)($viewerProfile["default_delivery_method"] ?? "Meetup"));
if ($selectedDeliveryMethod === "") {
    $selectedDeliveryMethod = $viewerDefaultDeliveryMethod;
}

$stmt = $conn->prepare("
    SELECT 
        products.product_id,
        products.user_id AS seller_id,
        products.title,
        products.description,
        products.price,
        products.image,
        products.created_at,
        users.name AS seller_name,
        users.email AS seller_email,
        users.phone_number AS seller_phone_number,
        users.is_phone_verified AS seller_is_verified,
        categories.category_name
    FROM products
    INNER JOIN users ON products.user_id = users.user_id
    INNER JOIN categories ON products.category_id = categories.category_id
    WHERE products.product_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $product_id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

if (!$product) {
    header("Location: listings.php");
    exit();
}

$soldCheck = $conn->prepare("
    SELECT transaction_id, status
    FROM transactions
    WHERE product_id = ?
      AND status IN ('Accepted', 'Completed')
    LIMIT 1
");
$soldCheck->bind_param("i", $product_id);
$soldCheck->execute();
$soldTransaction = $soldCheck->get_result()->fetch_assoc();
$isUnavailable = $soldTransaction ? true : false;

$existingDeal = null;
if ((int)$product["seller_id"] !== (int)$current_user_id) {
    $myDealCheck = $conn->prepare("
        SELECT transaction_id, status
        FROM transactions
        WHERE product_id = ? AND buyer_id = ?
        ORDER BY transaction_id DESC
        LIMIT 1
    ");
    $myDealCheck->bind_param("ii", $product_id, $current_user_id);
    $myDealCheck->execute();
    $existingDeal = $myDealCheck->get_result()->fetch_assoc();
}

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && (isset($_POST["buy_product"]) || isset($_POST["start_chat"]))
) {
    // Create or reuse the deal, then optionally redirect to chat.
    $openChatAfterRequest = isset($_POST["start_chat"]);
    $deliveryMethod = trim((string)($_POST["delivery_method"] ?? $viewerDefaultDeliveryMethod));
    $pickupPoint = trim((string)($_POST["pickup_point"] ?? ""));
    $dropoffPoint = trim((string)($_POST["dropoff_point"] ?? ""));

    if (!in_array($deliveryMethod, $allowedDeliveryMethods, true)) {
        $deliveryMethod = "Meetup";
    }

    if ((int)$product["seller_id"] === (int)$current_user_id) {
        $message = "You cannot buy your own product.";
    } elseif (!$viewerIsVerified) {
        $messageType = "warning";
        $message = "Verify your phone in Profile before requesting to buy.";
    } elseif ($deliveryMethod !== "Meetup" && ($pickupPoint === "" || $dropoffPoint === "")) {
        $messageType = "warning";
        $message = "Pickup and drop-off points are required for non-meetup delivery.";
    } elseif ($isUnavailable) {
        $messageType = "warning";
        $message = "This product already has an accepted/completed deal.";
    } else {
        $seller_id = $product["seller_id"];
        $status = "Pending";

        $check = $conn->prepare("
            SELECT transaction_id, status
            FROM transactions 
            WHERE product_id = ? AND buyer_id = ? AND status IN ('Pending', 'Accepted')
            LIMIT 1
        ");
        $check->bind_param("ii", $product_id, $current_user_id);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();

        if ($existing) {
            $existingDeal = $existing;
            $existingStatus = strtolower((string)$existing["status"]);
            $messageType = "warning";

            if ($existingStatus === "accepted") {
                $message = "You already have an accepted request for this product.";
            } else {
                $message = "You already have a pending request for this product.";
            }

            if ($openChatAfterRequest) {
                header("Location: messages.php?t=" . (int)$existing["transaction_id"]);
                exit();
            }
        } else {
            // Re-check availability before creating a new request.
            $dealLock = $conn->prepare("
                SELECT transaction_id
                FROM transactions
                WHERE product_id = ?
                  AND status IN ('Accepted', 'Completed')
                LIMIT 1
            ");
            $dealLock->bind_param("i", $product_id);
            $dealLock->execute();
            $lockResult = $dealLock->get_result();

            if ($lockResult->num_rows > 0) {
                $messageType = "warning";
                $message = "This product is no longer available for new requests.";
            } else {
                $paymentStatus = "Unpaid";
                $paymentMethod = "Mock Escrow";
                $paymentAmount = (float)$product["price"];
                $deliveryStatus = "Awaiting Seller";
                $disputeStatus = "None";

                $buy = $conn->prepare("
                    INSERT INTO transactions (
                        product_id,
                        buyer_id,
                        seller_id,
                        status,
                        payment_status,
                        payment_method,
                        payment_amount,
                        delivery_method,
                        pickup_point,
                        dropoff_point,
                        delivery_status,
                        dispute_status
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $buy->bind_param(
                    "iiisssdsssss",
                    $product_id,
                    $current_user_id,
                    $seller_id,
                    $status,
                    $paymentStatus,
                    $paymentMethod,
                    $paymentAmount,
                    $deliveryMethod,
                    $pickupPoint,
                    $dropoffPoint,
                    $deliveryStatus,
                    $disputeStatus
                );

                if ($buy->execute()) {
                    $message = "Purchase request sent successfully. Manage it in Deals or open Chat.";
                    $messageType = "success";
                    $existingDeal = [
                        "transaction_id" => $buy->insert_id,
                        "status" => "Pending"
                    ];

                    if ($openChatAfterRequest) {
                        header("Location: messages.php?t=" . (int)$buy->insert_id);
                        exit();
                    }
                } else {
                    $message = "Could not create purchase request.";
                }
            }
        }
    }
}

$viewerDealStatus = "";
if ($existingDeal) {
    $viewerDealStatus = strtolower(trim((string)$existingDeal["status"]));
}
?>

<!DOCTYPE html>
<html>
<head>
    <title><?php echo htmlspecialchars($product["title"]); ?> - C2C Platform</title>
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
        <a href="transactions.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_deals", "Deals")); ?></a>
        <a href="messages.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_chat", "Chat")); ?></a>
        <a href="profile.php" class="btn-light-custom"><?php echo htmlspecialchars(c2cT("nav_profile", "Profile")); ?></a>
        <a href="settings.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_settings", "Settings")); ?></a>
        <a href="support.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_support", "Support")); ?></a>
        <a href="logout.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_logout", "Logout")); ?></a>
    </div>
</nav>

<main class="dashboard-page">
    <div class="product-detail">
        <div>
            <img 
                src="uploads/<?php echo htmlspecialchars($product["image"]); ?>" 
                alt="<?php echo htmlspecialchars($product["title"]); ?>"
                class="product-detail-image"
            >
        </div>

        <div class="product-detail-content">
            <p class="product-category"><?php echo htmlspecialchars($product["category_name"]); ?></p>
            <h1><?php echo htmlspecialchars($product["title"]); ?></h1>
            <h2>R<?php echo number_format($product["price"], 2); ?></h2>

            <p><?php echo nl2br(htmlspecialchars($product["description"])); ?></p>

            <div class="seller-box">
                <strong>Seller</strong>
                <span><?php echo htmlspecialchars($product["seller_name"]); ?></span>
                <small><?php echo htmlspecialchars($product["seller_email"]); ?></small>
                <?php if ((int)$product["seller_is_verified"] === 1): ?>
                    <small class="text-success fw-bold">Verified seller</small>
                <?php else: ?>
                    <small class="text-warning fw-bold">Unverified seller</small>
                <?php endif; ?>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?> mt-3">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if ((int)$product["seller_id"] !== (int)$current_user_id): ?>
                <?php if (!$viewerIsVerified): ?>
                    <div class="alert alert-warning mt-3">
                        <?php echo htmlspecialchars(c2cT("phone_unverified", "Phone Not Verified")); ?>.
                        Please update and verify in profile before submitting requests.
                    </div>
                <?php endif; ?>
                <?php if ($viewerDealStatus === "pending"): ?>
                    <div class="alert alert-info mt-3">You already requested this item and it is pending seller review.</div>
                    <div class="deal-actions mt-3">
                        <a href="messages.php?t=<?php echo (int)$existingDeal["transaction_id"]; ?>" class="btn-action-link">Open Deal Chat</a>
                        <a href="transactions.php" class="btn-action-link">Open Deals</a>
                    </div>
                <?php elseif ($viewerDealStatus === "accepted"): ?>
                    <div class="alert alert-success mt-3">Your request was accepted. Continue in chat to arrange handover.</div>
                    <div class="deal-actions mt-3">
                        <a href="messages.php?t=<?php echo (int)$existingDeal["transaction_id"]; ?>" class="btn-action-link">Open Deal Chat</a>
                        <a href="transactions.php" class="btn-action-link">Open Deals</a>
                    </div>
                <?php elseif ($viewerDealStatus === "completed"): ?>
                    <div class="alert alert-success mt-3">This deal has already been completed.</div>
                <?php elseif ($isUnavailable): ?>
                    <div class="alert alert-warning mt-3">This item is currently unavailable for new requests.</div>
                <?php else: ?>
                    <form method="POST">
                        <div class="seller-box mt-3">
                            <strong>Delivery Setup (Mock)</strong>
                            <label class="form-label mt-2 mb-1">Delivery Method</label>
                            <select name="delivery_method" class="form-control" required>
                                <?php foreach ($allowedDeliveryMethods as $method): ?>
                                    <option value="<?php echo htmlspecialchars($method); ?>" <?php echo $selectedDeliveryMethod === $method ? "selected" : ""; ?>>
                                        <?php echo htmlspecialchars($method); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <label class="form-label mt-2 mb-1">Pickup Point</label>
                            <input
                                type="text"
                                name="pickup_point"
                                class="form-control"
                                value="<?php echo htmlspecialchars($selectedPickupPoint); ?>"
                                placeholder="Example: PAXI Pickup - Bellville"
                            >

                            <label class="form-label mt-2 mb-1">Drop-off Point</label>
                            <input
                                type="text"
                                name="dropoff_point"
                                class="form-control"
                                value="<?php echo htmlspecialchars($selectedDropoffPoint); ?>"
                                placeholder="Example: Locker 14 - Khayelitsha"
                            >
                        </div>
                        <button type="submit" name="buy_product" class="btn-auth mt-3">Request to Buy</button>
                    </form>
                    <form method="POST" class="mt-2">
                        <input type="hidden" name="delivery_method" value="<?php echo htmlspecialchars($selectedDeliveryMethod); ?>">
                        <input type="hidden" name="pickup_point" value="<?php echo htmlspecialchars($selectedPickupPoint); ?>">
                        <input type="hidden" name="dropoff_point" value="<?php echo htmlspecialchars($selectedDropoffPoint); ?>">
                        <button type="submit" name="start_chat" class="btn-action-link">Start Chat with Seller</button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <div class="alert alert-info mt-3">This is your own listing.</div>
            <?php endif; ?>
        </div>
    </div>
</main>

<script src="js/mobile-nav.js?v=20260507m"></script>
</body>
</html>








