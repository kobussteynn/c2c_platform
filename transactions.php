<?php
session_start();
include("includes/db.php");

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

$current_user_id = (int)$_SESSION["user_id"];
$message = "";
$messageType = "danger";

function loadTransactionForAction(mysqli $conn, int $transactionId): ?array {
    $txQuery = $conn->prepare("\n        SELECT\n            transactions.transaction_id,\n            transactions.product_id,\n            transactions.buyer_id,\n            transactions.seller_id,\n            transactions.status,\n            transactions.payment_status,\n            transactions.payment_amount,\n            transactions.delivery_status,\n            transactions.delivery_tracking_code,\n            transactions.dispute_status,\n            products.price\n        FROM transactions\n        INNER JOIN products ON products.product_id = transactions.product_id\n        WHERE transactions.transaction_id = ?\n        LIMIT 1\n    ");
    $txQuery->bind_param("i", $transactionId);
    $txQuery->execute();
    $row = $txQuery->get_result()->fetch_assoc();
    return $row ?: null;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = trim((string)($_POST["action"] ?? ""));
    $transactionId = filter_var($_POST["transaction_id"] ?? "", FILTER_VALIDATE_INT);

    if (!$transactionId || $action === "") {
        $message = "Invalid transaction action.";
    } else {
        $transaction = loadTransactionForAction($conn, (int)$transactionId);

        if (!$transaction) {
            $message = "Transaction not found.";
        } else {
            $status = strtolower(trim((string)$transaction["status"]));
            $paymentStatus = strtolower(trim((string)$transaction["payment_status"]));
            $deliveryStatus = strtolower(trim((string)$transaction["delivery_status"]));
            $disputeStatus = strtolower(trim((string)$transaction["dispute_status"]));
            $buyerId = (int)$transaction["buyer_id"];
            $sellerId = (int)$transaction["seller_id"];
            $productId = (int)$transaction["product_id"];

            switch ($action) {
                case "seller_accept":
                    if ($current_user_id !== $sellerId) {
                        $message = "You cannot accept this request.";
                        break;
                    }
                    if ($status !== "pending") {
                        $messageType = "warning";
                        $message = "Only pending requests can be accepted.";
                        break;
                    }

                    $takenCheck = $conn->prepare("\n                        SELECT transaction_id\n                        FROM transactions\n                        WHERE product_id = ?\n                          AND transaction_id != ?\n                          AND status IN ('Accepted', 'Completed')\n                        LIMIT 1\n                    ");
                    $takenCheck->bind_param("ii", $productId, $transactionId);
                    $takenCheck->execute();
                    if ($takenCheck->get_result()->num_rows > 0) {
                        $messageType = "warning";
                        $message = "This product already has an accepted/completed deal.";
                        break;
                    }

                    $updateTx = $conn->prepare("\n                        UPDATE transactions\n                        SET status = 'Accepted',\n                            delivery_status = 'Ready for Pickup',\n                            delivery_updated_at = NOW()\n                        WHERE transaction_id = ?\n                    ");
                    $updateTx->bind_param("i", $transactionId);
                    $updateTx->execute();

                    $closeOthers = $conn->prepare("\n                        UPDATE transactions\n                        SET status = 'Rejected'\n                        WHERE product_id = ?\n                          AND transaction_id != ?\n                          AND status = 'Pending'\n                    ");
                    $closeOthers->bind_param("ii", $productId, $transactionId);
                    $closeOthers->execute();

                    $messageType = "success";
                    $message = "Request accepted and moved into delivery preparation.";
                    break;

                case "seller_reject":
                    if ($current_user_id !== $sellerId) {
                        $message = "You cannot reject this request.";
                        break;
                    }
                    if ($status !== "pending") {
                        $messageType = "warning";
                        $message = "Only pending requests can be rejected.";
                        break;
                    }

                    $rejectTx = $conn->prepare("UPDATE transactions SET status = 'Rejected' WHERE transaction_id = ?");
                    $rejectTx->bind_param("i", $transactionId);
                    $rejectTx->execute();

                    $messageType = "success";
                    $message = "Request rejected.";
                    break;

                case "buyer_cancel":
                    if ($current_user_id !== $buyerId) {
                        $message = "You cannot cancel this request.";
                        break;
                    }
                    if (!in_array($status, ["pending", "accepted"], true)) {
                        $messageType = "warning";
                        $message = "Only pending or accepted requests can be cancelled.";
                        break;
                    }

                    $nextPaymentStatus = $paymentStatus === "held in escrow" ? "Refunded" : ucfirst($paymentStatus);

                    $cancelTx = $conn->prepare("\n                        UPDATE transactions\n                        SET status = 'Cancelled',\n                            payment_status = ?,\n                            payment_released_at = IF(? = 'Refunded', NOW(), payment_released_at),\n                            delivery_status = 'Not Started',\n                            delivery_updated_at = NOW()\n                        WHERE transaction_id = ?\n                    ");
                    $cancelTx->bind_param("ssi", $nextPaymentStatus, $nextPaymentStatus, $transactionId);
                    $cancelTx->execute();

                    if ($paymentStatus === "held in escrow") {
                        c2cCreateSuspiciousFlag(
                            $conn,
                            "Transaction cancelled after escrow payment hold.",
                            "Medium",
                            $transactionId,
                            $current_user_id,
                            "buyer_cancel"
                        );
                    }

                    $cancelTrend = $conn->prepare("\n                        SELECT COUNT(*) AS total\n                        FROM transactions\n                        WHERE buyer_id = ?\n                          AND status = 'Cancelled'\n                          AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)\n                    ");
                    $cancelTrend->bind_param("i", $current_user_id);
                    $cancelTrend->execute();
                    $cancelCount = (int)$cancelTrend->get_result()->fetch_assoc()["total"];
                    if ($cancelCount >= 3) {
                        c2cCreateSuspiciousFlag(
                            $conn,
                            "Buyer has high cancellation rate in the last 30 days.",
                            "High",
                            $transactionId,
                            $current_user_id,
                            "cancel_trend"
                        );
                    }

                    $messageType = "success";
                    $message = "Transaction cancelled.";
                    break;

                case "buyer_pay":
                    if ($current_user_id !== $buyerId) {
                        $message = "You cannot pay for this request.";
                        break;
                    }
                    if ($status !== "accepted") {
                        $messageType = "warning";
                        $message = "Payment is only available after seller acceptance.";
                        break;
                    }
                    if ($paymentStatus !== "unpaid") {
                        $messageType = "warning";
                        $message = "This transaction is already paid or released.";
                        break;
                    }

                    $paymentRef = c2cGenerateMockReference("PAY");
                    $paymentAmount = (float)($transaction["payment_amount"] ?? 0);
                    if ($paymentAmount <= 0) {
                        $paymentAmount = (float)($transaction["price"] ?? 0);
                    }

                    $payTx = $conn->prepare("\n                        UPDATE transactions\n                        SET payment_status = 'Held in Escrow',\n                            payment_method = 'Mock Escrow',\n                            payment_amount = ?,\n                            payment_reference = ?,\n                            payment_held_at = NOW()\n                        WHERE transaction_id = ?\n                    ");
                    $payTx->bind_param("dsi", $paymentAmount, $paymentRef, $transactionId);
                    $payTx->execute();

                    $messageType = "success";
                    $message = "Mock payment completed and held in escrow.";
                    break;

                case "seller_dispatch":
                    if ($current_user_id !== $sellerId) {
                        $message = "You cannot update delivery for this request.";
                        break;
                    }
                    if ($status !== "accepted") {
                        $messageType = "warning";
                        $message = "Only accepted requests can be dispatched.";
                        break;
                    }
                    if ($paymentStatus !== "held in escrow") {
                        $messageType = "warning";
                        $message = "Buyer must complete mock escrow payment before dispatch.";
                        break;
                    }

                    $trackingCode = trim((string)$transaction["delivery_tracking_code"]);
                    if ($trackingCode === "") {
                        $trackingCode = c2cGenerateMockReference("TRK");
                    }

                    $dispatchTx = $conn->prepare("\n                        UPDATE transactions\n                        SET delivery_status = 'In Transit',\n                            delivery_tracking_code = ?,\n                            delivery_updated_at = NOW()\n                        WHERE transaction_id = ?\n                    ");
                    $dispatchTx->bind_param("si", $trackingCode, $transactionId);
                    $dispatchTx->execute();

                    $messageType = "success";
                    $message = "Delivery moved to in-transit with mock tracking.";
                    break;

                case "buyer_mark_delivered":
                    if ($current_user_id !== $buyerId) {
                        $message = "You cannot confirm delivery for this request.";
                        break;
                    }
                    if ($status !== "accepted") {
                        $messageType = "warning";
                        $message = "Only accepted requests can be updated.";
                        break;
                    }
                    if (!in_array($deliveryStatus, ["in transit", "ready for pickup"], true)) {
                        $messageType = "warning";
                        $message = "Delivery must be in transit or ready for pickup first.";
                        break;
                    }

                    $markDelivered = $conn->prepare("\n                        UPDATE transactions\n                        SET delivery_status = 'Delivered',\n                            delivery_updated_at = NOW()\n                        WHERE transaction_id = ?\n                    ");
                    $markDelivered->bind_param("i", $transactionId);
                    $markDelivered->execute();

                    $messageType = "success";
                    $message = "Delivery marked as delivered.";
                    break;

                case "seller_mark_collected":
                    if ($current_user_id !== $sellerId) {
                        $message = "You cannot confirm collection for this request.";
                        break;
                    }
                    if ($status !== "accepted") {
                        $messageType = "warning";
                        $message = "Only accepted requests can be updated.";
                        break;
                    }
                    if (!in_array($deliveryStatus, ["in transit", "ready for pickup", "delivered"], true)) {
                        $messageType = "warning";
                        $message = "Move delivery into transit first.";
                        break;
                    }

                    $markCollected = $conn->prepare("\n                        UPDATE transactions\n                        SET delivery_status = 'Collected',\n                            delivery_updated_at = NOW()\n                        WHERE transaction_id = ?\n                    ");
                    $markCollected->bind_param("i", $transactionId);
                    $markCollected->execute();

                    $messageType = "success";
                    $message = "Collection confirmed.";
                    break;

                case "open_dispute":
                    $reason = trim((string)($_POST["dispute_reason"] ?? ""));
                    $details = trim((string)($_POST["dispute_details"] ?? ""));

                    if (!in_array($current_user_id, [$buyerId, $sellerId], true)) {
                        $message = "You cannot open a dispute for this request.";
                        break;
                    }
                    if (!in_array($status, ["accepted", "completed"], true)) {
                        $messageType = "warning";
                        $message = "Disputes can only be opened for accepted or completed requests.";
                        break;
                    }
                    if ($reason === "") {
                        $messageType = "warning";
                        $message = "Select a dispute reason.";
                        break;
                    }
                    if ($disputeStatus === "open") {
                        $messageType = "warning";
                        $message = "A dispute is already open for this transaction.";
                        break;
                    }

                    $conn->begin_transaction();
                    try {
                        $insertDispute = $conn->prepare("\n                            INSERT INTO disputes (transaction_id, opened_by_user_id, reason, details, status)\n                            VALUES (?, ?, ?, ?, 'Open')\n                        ");
                        $insertDispute->bind_param("iiss", $transactionId, $current_user_id, $reason, $details);
                        $insertDispute->execute();

                        $updateTx = $conn->prepare("UPDATE transactions SET dispute_status = 'Open' WHERE transaction_id = ?");
                        $updateTx->bind_param("i", $transactionId);
                        $updateTx->execute();

                        c2cCreateSuspiciousFlag(
                            $conn,
                            "Dispute opened on transaction.",
                            "High",
                            $transactionId,
                            $current_user_id,
                            "dispute_opened"
                        );

                        $recentDisputes = $conn->prepare("\n                            SELECT COUNT(*) AS total\n                            FROM disputes\n                            WHERE opened_by_user_id = ?\n                              AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)\n                        ");
                        $recentDisputes->bind_param("i", $current_user_id);
                        $recentDisputes->execute();
                        $recentDisputeCount = (int)$recentDisputes->get_result()->fetch_assoc()["total"];

                        if ($recentDisputeCount >= 2) {
                            c2cCreateSuspiciousFlag(
                                $conn,
                                "User has multiple disputes in the last 30 days.",
                                "Critical",
                                $transactionId,
                                $current_user_id,
                                "dispute_trend"
                            );
                        }

                        $conn->commit();
                        $messageType = "success";
                        $message = "Dispute opened. Admin review is required.";
                    } catch (Throwable $e) {
                        $conn->rollback();
                        $message = "Could not open dispute: " . $e->getMessage();
                    }
                    break;

                case "buyer_complete":
                case "seller_complete":
                    $actorIsBuyer = $action === "buyer_complete";
                    if (($actorIsBuyer && $current_user_id !== $buyerId) || (!$actorIsBuyer && $current_user_id !== $sellerId)) {
                        $message = "You cannot complete this request.";
                        break;
                    }
                    if ($status !== "accepted") {
                        $messageType = "warning";
                        $message = "Only accepted requests can be completed.";
                        break;
                    }
                    if ($paymentStatus !== "held in escrow") {
                        $messageType = "warning";
                        $message = "Mock escrow payment must be held before completion.";
                        break;
                    }
                    if (!in_array($deliveryStatus, ["delivered", "collected"], true)) {
                        $messageType = "warning";
                        $message = "Mark delivery as delivered or collected before completion.";
                        break;
                    }
                    if ($disputeStatus === "open") {
                        $messageType = "warning";
                        $message = "Resolve the open dispute before completion.";
                        break;
                    }

                    $completeTx = $conn->prepare("\n                        UPDATE transactions\n                        SET status = 'Completed',\n                            payment_status = 'Released',\n                            payment_released_at = NOW()\n                        WHERE transaction_id = ?\n                    ");
                    $completeTx->bind_param("i", $transactionId);
                    $completeTx->execute();

                    $messageType = "success";
                    $message = "Transaction completed and escrow released.";
                    break;

                case "submit_rating":
                    $revieweeUserId = filter_var($_POST["reviewee_user_id"] ?? "", FILTER_VALIDATE_INT);
                    $rating = filter_var($_POST["rating"] ?? "", FILTER_VALIDATE_INT);
                    $comment = trim((string)($_POST["comment"] ?? ""));

                    if (!$revieweeUserId || !$rating || $rating < 1 || $rating > 5) {
                        $messageType = "warning";
                        $message = "Please choose a rating between 1 and 5.";
                        break;
                    }
                    if ($status !== "completed") {
                        $messageType = "warning";
                        $message = "Ratings are only allowed on completed transactions.";
                        break;
                    }

                    $expectedReviewee = 0;
                    if ($current_user_id === $buyerId) {
                        $expectedReviewee = $sellerId;
                    } elseif ($current_user_id === $sellerId) {
                        $expectedReviewee = $buyerId;
                    }

                    if ($expectedReviewee === 0 || (int)$revieweeUserId !== $expectedReviewee) {
                        $messageType = "warning";
                        $message = "Invalid rating target.";
                        break;
                    }

                    if (strlen($comment) > 500) {
                        $comment = substr($comment, 0, 500);
                    }

                    $saveRating = $conn->prepare("\n                        INSERT INTO reviews (transaction_id, reviewer_user_id, reviewee_user_id, rating, comment)\n                        VALUES (?, ?, ?, ?, ?)\n                        ON DUPLICATE KEY UPDATE\n                            rating = VALUES(rating),\n                            comment = VALUES(comment),\n                            updated_at = CURRENT_TIMESTAMP\n                    ");
                    $saveRating->bind_param("iiiis", $transactionId, $current_user_id, $revieweeUserId, $rating, $comment);
                    $saveRating->execute();

                    $messageType = "success";
                    $message = "Rating saved successfully.";
                    break;

                default:
                    $message = "Unsupported transaction action.";
                    break;
            }
        }
    }
}
$buyingStmt = $conn->prepare("
    SELECT
        transactions.transaction_id,
        transactions.status,
        transactions.seller_id,
        transactions.payment_status,
        transactions.payment_reference,
        transactions.delivery_method,
        transactions.delivery_status,
        transactions.delivery_tracking_code,
        transactions.dispute_status,
        transactions.created_at,
        products.product_id,
        products.title,
        products.price,
        users.name AS seller_name
    FROM transactions
    INNER JOIN products ON transactions.product_id = products.product_id
    INNER JOIN users ON transactions.seller_id = users.user_id
    WHERE transactions.buyer_id = ?
    ORDER BY transactions.created_at DESC
");
$buyingStmt->bind_param("i", $current_user_id);
$buyingStmt->execute();
$buyingResult = $buyingStmt->get_result();
$buyingDeals = [];
while ($row = $buyingResult->fetch_assoc()) {
    $buyingDeals[] = $row;
}

$sellingStmt = $conn->prepare("
    SELECT
        transactions.transaction_id,
        transactions.status,
        transactions.buyer_id,
        transactions.payment_status,
        transactions.payment_reference,
        transactions.delivery_method,
        transactions.delivery_status,
        transactions.delivery_tracking_code,
        transactions.dispute_status,
        transactions.created_at,
        products.product_id,
        products.title,
        products.price,
        users.name AS buyer_name
    FROM transactions
    INNER JOIN products ON transactions.product_id = products.product_id
    INNER JOIN users ON transactions.buyer_id = users.user_id
    WHERE transactions.seller_id = ?
    ORDER BY transactions.created_at DESC
");
$sellingStmt->bind_param("i", $current_user_id);
$sellingStmt->execute();
$sellingResult = $sellingStmt->get_result();
$sellingDeals = [];
while ($row = $sellingResult->fetch_assoc()) {
    $sellingDeals[] = $row;
}

$ratingByKey = [];
$allDealIds = [];
foreach ($buyingDeals as $deal) {
    $allDealIds[] = (int)$deal["transaction_id"];
}
foreach ($sellingDeals as $deal) {
    $allDealIds[] = (int)$deal["transaction_id"];
}
$allDealIds = array_values(array_unique(array_filter($allDealIds)));

if (count($allDealIds) > 0) {
    $placeholders = implode(",", array_fill(0, count($allDealIds), "?"));
    $types = str_repeat("i", count($allDealIds));
    $ratingsSql = "
        SELECT transaction_id, reviewer_user_id, reviewee_user_id, rating, comment, created_at
        FROM reviews
        WHERE transaction_id IN ({$placeholders})
    ";
    $ratingsStmt = $conn->prepare($ratingsSql);
    $ratingsStmt->bind_param($types, ...$allDealIds);
    $ratingsStmt->execute();
    $ratingsResult = $ratingsStmt->get_result();
    while ($row = $ratingsResult->fetch_assoc()) {
        $key = (int)$row["transaction_id"] . "_" . (int)$row["reviewer_user_id"] . "_" . (int)$row["reviewee_user_id"];
        $ratingByKey[$key] = $row;
    }
}

$pendingBuying = 0;
$pendingSelling = 0;
foreach ($buyingDeals as $deal) {
    if (strtolower((string)$deal["status"]) === "pending") {
        $pendingBuying++;
    }
}
foreach ($sellingDeals as $deal) {
    if (strtolower((string)$deal["status"]) === "pending") {
        $pendingSelling++;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Transactions - C2C Platform</title>
    <meta charset="UTF-8">
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
        <a href="transactions.php" class="btn-dark"><?php echo htmlspecialchars(c2cT("nav_deals", "Deals")); ?></a>
        <a href="messages.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_chat", "Chat")); ?></a>
        <a href="profile.php" class="btn-light-custom"><?php echo htmlspecialchars(c2cT("nav_profile", "Profile")); ?></a>
        <a href="settings.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_settings", "Settings")); ?></a>
        <a href="support.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_support", "Support")); ?></a>
        <a href="logout.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_logout", "Logout")); ?></a>
    </div>
</nav>

<main class="dashboard-page">
    <section class="dashboard-hero">
        <div>
            <p class="eyebrow">Transactions</p>
            <h1>My Deals</h1>
            <p class="hero-text">Track payment, delivery, and disputes in one place.</p>
            <div class="hero-actions">
                <a href="listings.php" class="btn-dark">Browse Items</a>
                <a href="messages.php" class="btn-light-custom">Open Chat</a>
            </div>
        </div>

        <div class="role-card">
            <p>Pending Actions</p>
            <h3><?php echo number_format($pendingBuying + $pendingSelling); ?></h3>
            <span><?php echo number_format($pendingBuying); ?> buying / <?php echo number_format($pendingSelling); ?> selling</span>
        </div>
    </section>

    <?php if ($message !== ""): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> mt-4">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <section class="section-block">
        <div class="section-header">
            <div>
                <p class="eyebrow">Buyer Side</p>
                <h2>My Buy Requests</h2>
            </div>
        </div>

        <div class="table-card">
            <?php if (count($buyingDeals) > 0): ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Seller</th>
                                <th>Deal</th>
                                <th>Payment</th>
                                <th>Delivery</th>
                                <th>Dispute</th>
                                <th>Rating</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($buyingDeals as $deal): ?>
                                <?php
                                    $status = strtolower((string)$deal["status"]);
                                    $paymentStatus = strtolower((string)$deal["payment_status"]);
                                    $deliveryStatus = strtolower((string)$deal["delivery_status"]);
                                    $disputeStatus = strtolower((string)$deal["dispute_status"]);
                                ?>
                                <tr>
                                    <td>
                                        <a href="product.php?id=<?php echo (int)$deal["product_id"]; ?>" class="table-link">
                                            <?php echo htmlspecialchars($deal["title"]); ?>
                                        </a>
                                        <br>
                                        <small>R<?php echo number_format((float)$deal["price"], 2); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($deal["seller_name"]); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo getStatusBadgeClass((string)$deal["status"]); ?>">
                                            <?php echo htmlspecialchars(formatStatusLabel($deal["status"])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-default"><?php echo htmlspecialchars(formatStatusLabel((string)$deal["payment_status"])); ?></span>
                                        <br>
                                        <small><?php echo htmlspecialchars((string)($deal["payment_reference"] ?: "No ref")); ?></small>
                                    </td>
                                    <td>
                                        <span class="status-badge status-default"><?php echo htmlspecialchars(formatStatusLabel((string)$deal["delivery_status"])); ?></span>
                                        <br>
                                        <small><?php echo htmlspecialchars((string)$deal["delivery_method"]); ?></small>
                                        <?php if (trim((string)$deal["delivery_tracking_code"]) !== ""): ?>
                                            <br><small><?php echo htmlspecialchars((string)$deal["delivery_tracking_code"]); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $disputeStatus === "open" ? "status-rejected" : "status-default"; ?>">
                                            <?php echo htmlspecialchars(formatStatusLabel((string)$deal["dispute_status"])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                            $buyerRatingKey = (int)$deal["transaction_id"] . "_" . $current_user_id . "_" . (int)$deal["seller_id"];
                                            $buyerRating = $ratingByKey[$buyerRatingKey] ?? null;
                                        ?>
                                        <?php if ($buyerRating): ?>
                                            <span class="status-badge status-accepted"><?php echo (int)$buyerRating["rating"]; ?>/5</span>
                                            <br>
                                            <small><?php echo htmlspecialchars((string)($buyerRating["comment"] ?: "No comment")); ?></small>
                                        <?php elseif ($status === "completed"): ?>
                                            <form method="POST" class="inline-form d-flex gap-1">
                                                <input type="hidden" name="transaction_id" value="<?php echo (int)$deal["transaction_id"]; ?>">
                                                <input type="hidden" name="action" value="submit_rating">
                                                <input type="hidden" name="reviewee_user_id" value="<?php echo (int)$deal["seller_id"]; ?>">
                                                <select name="rating" class="form-control control-w-90">
                                                    <option value="5">5/5</option>
                                                    <option value="4">4/5</option>
                                                    <option value="3">3/5</option>
                                                    <option value="2">2/5</option>
                                                    <option value="1">1/5</option>
                                                </select>
                                                <button type="submit" class="btn-action-success">Rate</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="status-badge status-default">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="deal-actions">
                                            <a href="messages.php?t=<?php echo (int)$deal["transaction_id"]; ?>" class="btn-action-link">Chat</a>
                                            <?php if ($status === "accepted" && $paymentStatus === "unpaid"): ?>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="transaction_id" value="<?php echo (int)$deal["transaction_id"]; ?>">
                                                    <input type="hidden" name="action" value="buyer_pay">
                                                    <button type="submit" class="btn-action-success">Mock Pay</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($status === "accepted" && in_array($deliveryStatus, ["in transit", "ready for pickup"], true)): ?>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="transaction_id" value="<?php echo (int)$deal["transaction_id"]; ?>">
                                                    <input type="hidden" name="action" value="buyer_mark_delivered">
                                                    <button type="submit" class="btn-action-success">Mark Delivered</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($status === "accepted" && $paymentStatus === "held in escrow" && in_array($deliveryStatus, ["delivered", "collected"], true)): ?>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="transaction_id" value="<?php echo (int)$deal["transaction_id"]; ?>">
                                                    <input type="hidden" name="action" value="buyer_complete">
                                                    <button type="submit" class="btn-action-success">Complete</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if (in_array($status, ["pending", "accepted"], true)): ?>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="transaction_id" value="<?php echo (int)$deal["transaction_id"]; ?>">
                                                    <input type="hidden" name="action" value="buyer_cancel">
                                                    <button type="submit" class="btn-action-danger">Cancel</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if (in_array($status, ["accepted", "completed"], true) && $disputeStatus !== "open"): ?>
                                                <form method="POST" class="inline-form d-flex gap-1">
                                                    <input type="hidden" name="transaction_id" value="<?php echo (int)$deal["transaction_id"]; ?>">
                                                    <input type="hidden" name="action" value="open_dispute">
                                                    <select name="dispute_reason" class="form-control control-w-130">
                                                        <option value="Item not received">Item not received</option>
                                                        <option value="Wrong item">Wrong item</option>
                                                        <option value="Payment issue">Payment issue</option>
                                                        <option value="Other">Other</option>
                                                    </select>
                                                    <button type="submit" class="btn-action-danger">Open Dispute</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <h3>No buy requests yet.</h3>
                    <p>Open a product and click request to buy to start your first deal.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="section-block">
        <div class="section-header">
            <div>
                <p class="eyebrow">Selling</p>
                <h2>Requests on My Listings</h2>
            </div>
        </div>

        <div class="table-card">
            <?php if (count($sellingDeals) > 0): ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Buyer</th>
                                <th>Deal</th>
                                <th>Payment</th>
                                <th>Delivery</th>
                                <th>Dispute</th>
                                <th>Rating</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sellingDeals as $deal): ?>
                                <?php
                                    $status = strtolower((string)$deal["status"]);
                                    $paymentStatus = strtolower((string)$deal["payment_status"]);
                                    $deliveryStatus = strtolower((string)$deal["delivery_status"]);
                                    $disputeStatus = strtolower((string)$deal["dispute_status"]);
                                ?>
                                <tr>
                                    <td>
                                        <a href="product.php?id=<?php echo (int)$deal["product_id"]; ?>" class="table-link">
                                            <?php echo htmlspecialchars($deal["title"]); ?>
                                        </a>
                                        <br>
                                        <small>R<?php echo number_format((float)$deal["price"], 2); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($deal["buyer_name"]); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo getStatusBadgeClass((string)$deal["status"]); ?>">
                                            <?php echo htmlspecialchars(formatStatusLabel($deal["status"])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-default"><?php echo htmlspecialchars(formatStatusLabel((string)$deal["payment_status"])); ?></span>
                                        <br>
                                        <small><?php echo htmlspecialchars((string)($deal["payment_reference"] ?: "No ref")); ?></small>
                                    </td>
                                    <td>
                                        <span class="status-badge status-default"><?php echo htmlspecialchars(formatStatusLabel((string)$deal["delivery_status"])); ?></span>
                                        <br>
                                        <small><?php echo htmlspecialchars((string)$deal["delivery_method"]); ?></small>
                                        <?php if (trim((string)$deal["delivery_tracking_code"]) !== ""): ?>
                                            <br><small><?php echo htmlspecialchars((string)$deal["delivery_tracking_code"]); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $disputeStatus === "open" ? "status-rejected" : "status-default"; ?>">
                                            <?php echo htmlspecialchars(formatStatusLabel((string)$deal["dispute_status"])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                            $sellerRatingKey = (int)$deal["transaction_id"] . "_" . $current_user_id . "_" . (int)$deal["buyer_id"];
                                            $sellerRating = $ratingByKey[$sellerRatingKey] ?? null;
                                        ?>
                                        <?php if ($sellerRating): ?>
                                            <span class="status-badge status-accepted"><?php echo (int)$sellerRating["rating"]; ?>/5</span>
                                            <br>
                                            <small><?php echo htmlspecialchars((string)($sellerRating["comment"] ?: "No comment")); ?></small>
                                        <?php elseif ($status === "completed"): ?>
                                            <form method="POST" class="inline-form d-flex gap-1">
                                                <input type="hidden" name="transaction_id" value="<?php echo (int)$deal["transaction_id"]; ?>">
                                                <input type="hidden" name="action" value="submit_rating">
                                                <input type="hidden" name="reviewee_user_id" value="<?php echo (int)$deal["buyer_id"]; ?>">
                                                <select name="rating" class="form-control control-w-90">
                                                    <option value="5">5/5</option>
                                                    <option value="4">4/5</option>
                                                    <option value="3">3/5</option>
                                                    <option value="2">2/5</option>
                                                    <option value="1">1/5</option>
                                                </select>
                                                <button type="submit" class="btn-action-success">Rate</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="status-badge status-default">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="deal-actions">
                                            <a href="messages.php?t=<?php echo (int)$deal["transaction_id"]; ?>" class="btn-action-link">Chat</a>
                                            <?php if ($status === "pending"): ?>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="transaction_id" value="<?php echo (int)$deal["transaction_id"]; ?>">
                                                    <input type="hidden" name="action" value="seller_accept">
                                                    <button type="submit" class="btn-action-success">Accept</button>
                                                </form>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="transaction_id" value="<?php echo (int)$deal["transaction_id"]; ?>">
                                                    <input type="hidden" name="action" value="seller_reject">
                                                    <button type="submit" class="btn-action-danger">Reject</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($status === "accepted" && $paymentStatus === "held in escrow" && in_array($deliveryStatus, ["ready for pickup", "awaiting seller", "not started"], true)): ?>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="transaction_id" value="<?php echo (int)$deal["transaction_id"]; ?>">
                                                    <input type="hidden" name="action" value="seller_dispatch">
                                                    <button type="submit" class="btn-action-success">Dispatch</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($status === "accepted" && in_array($deliveryStatus, ["in transit", "ready for pickup", "delivered"], true)): ?>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="transaction_id" value="<?php echo (int)$deal["transaction_id"]; ?>">
                                                    <input type="hidden" name="action" value="seller_mark_collected">
                                                    <button type="submit" class="btn-action-success">Mark Collected</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($status === "accepted" && $paymentStatus === "held in escrow" && in_array($deliveryStatus, ["delivered", "collected"], true)): ?>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="transaction_id" value="<?php echo (int)$deal["transaction_id"]; ?>">
                                                    <input type="hidden" name="action" value="seller_complete">
                                                    <button type="submit" class="btn-action-success">Complete</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if (in_array($status, ["accepted", "completed"], true) && $disputeStatus !== "open"): ?>
                                                <form method="POST" class="inline-form d-flex gap-1">
                                                    <input type="hidden" name="transaction_id" value="<?php echo (int)$deal["transaction_id"]; ?>">
                                                    <input type="hidden" name="action" value="open_dispute">
                                                    <select name="dispute_reason" class="form-control control-w-130">
                                                        <option value="Item not received">Item not received</option>
                                                        <option value="Wrong item">Wrong item</option>
                                                        <option value="Payment issue">Payment issue</option>
                                                        <option value="Other">Other</option>
                                                    </select>
                                                    <button type="submit" class="btn-action-danger">Open Dispute</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <h3>No requests on your listings yet.</h3>
                    <p>When buyers request your items, they will appear here.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<script src="js/mobile-nav.js?v=20260507m"></script>
</body>
</html>


