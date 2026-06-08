<?php
require_once __DIR__ . "/auth.php";
adminRequirePermission("kpi.view");

$stats = [
    "total_transactions" => 0,
    "secured_transactions" => 0,
    "completed_transactions" => 0,
    "verified_completed_transactions" => 0,
    "delivery_eligible_transactions" => 0,
    "successful_delivery_transactions" => 0,
    "open_disputes" => 0,
    "avg_hours_to_first_accept" => 0.0
];

$stats["total_transactions"] = (int)$conn->query("SELECT COUNT(*) AS total FROM transactions")->fetch_assoc()["total"];
$stats["secured_transactions"] = (int)$conn->query("
    SELECT COUNT(*) AS total
    FROM transactions
    WHERE status IN ('Accepted', 'Completed', 'Cancelled')
      AND payment_status IN ('Held in Escrow', 'Released', 'Refunded')
")->fetch_assoc()["total"];
$stats["completed_transactions"] = (int)$conn->query("
    SELECT COUNT(*) AS total
    FROM transactions
    WHERE status = 'Completed'
")->fetch_assoc()["total"];
$stats["verified_completed_transactions"] = (int)$conn->query("
    SELECT COUNT(*) AS total
    FROM transactions
    INNER JOIN users AS buyer ON buyer.user_id = transactions.buyer_id
    INNER JOIN users AS seller ON seller.user_id = transactions.seller_id
    WHERE transactions.status = 'Completed'
      AND buyer.is_phone_verified = 1
      AND seller.is_phone_verified = 1
")->fetch_assoc()["total"];
$stats["delivery_eligible_transactions"] = (int)$conn->query("
    SELECT COUNT(*) AS total
    FROM transactions
    WHERE status IN ('Accepted', 'Completed', 'Cancelled')
")->fetch_assoc()["total"];
$stats["successful_delivery_transactions"] = (int)$conn->query("
    SELECT COUNT(*) AS total
    FROM transactions
    WHERE delivery_status IN ('Delivered', 'Collected')
")->fetch_assoc()["total"];
$stats["open_disputes"] = (int)$conn->query("
    SELECT COUNT(*) AS total
    FROM disputes
    WHERE status = 'Open'
")->fetch_assoc()["total"];

$avgAcceptResult = $conn->query("
    SELECT AVG(hours_to_first_accept) AS avg_hours
    FROM (
        SELECT
            TIMESTAMPDIFF(HOUR, products.created_at, MIN(transactions.created_at)) AS hours_to_first_accept
        FROM products
        INNER JOIN transactions ON transactions.product_id = products.product_id
        WHERE transactions.status IN ('Accepted', 'Completed')
        GROUP BY products.product_id, products.created_at
    ) metrics
");
$stats["avg_hours_to_first_accept"] = (float)($avgAcceptResult->fetch_assoc()["avg_hours"] ?? 0);

$securedRate = $stats["total_transactions"] > 0 ? ($stats["secured_transactions"] / $stats["total_transactions"]) * 100 : 0;
$verifiedRate = $stats["completed_transactions"] > 0 ? ($stats["verified_completed_transactions"] / $stats["completed_transactions"]) * 100 : 0;
$deliveryRate = $stats["delivery_eligible_transactions"] > 0 ? ($stats["successful_delivery_transactions"] / $stats["delivery_eligible_transactions"]) * 100 : 0;
$avgDaysToAccept = $stats["avg_hours_to_first_accept"] / 24;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Admin KPI - C2C Platform</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/style.css?v=20260514ui1" rel="stylesheet">
</head>
<body class="app-body">

<?php adminRenderNav("kpi"); ?>

<main class="dashboard-page">
    <section class="dashboard-hero">
        <div>
            <p class="eyebrow">Goal Tracking</p>
            <h1>KPI Dashboard</h1>
            <p class="hero-text">Progress view against Deliverable 1 measurable goals using mock platform data.</p>
        </div>

        <div class="role-card">
            <p>Total Deals</p>
            <h3><?php echo number_format($stats["total_transactions"]); ?></h3>
            <span><?php echo number_format($stats["open_disputes"]); ?> open disputes</span>
        </div>
    </section>

    <section class="section-block">
        <div class="product-grid">
            <article class="quick-actions-card">
                <p class="eyebrow">Goal 1</p>
                <h3><?php echo number_format($securedRate, 1); ?>%</h3>
                <p>Secured in-app transaction flow target: <strong>70%</strong>.</p>
                <span class="status-badge <?php echo $securedRate >= 70 ? "status-accepted" : "status-pending"; ?>">
                    <?php echo $securedRate >= 70 ? "Target Met" : "Below Target"; ?>
                </span>
            </article>

            <article class="quick-actions-card">
                <p class="eyebrow">Goal 1.2</p>
                <h3><?php echo number_format($verifiedRate, 1); ?>%</h3>
                <p>Completed deals between verified users target: <strong>60%</strong>.</p>
                <span class="status-badge <?php echo $verifiedRate >= 60 ? "status-accepted" : "status-pending"; ?>">
                    <?php echo $verifiedRate >= 60 ? "Target Met" : "Below Target"; ?>
                </span>
            </article>

            <article class="quick-actions-card">
                <p class="eyebrow">Goal 3</p>
                <h3><?php echo number_format($deliveryRate, 1); ?>%</h3>
                <p>Successful delivery target: <strong>80%</strong>.</p>
                <span class="status-badge <?php echo $deliveryRate >= 80 ? "status-accepted" : "status-pending"; ?>">
                    <?php echo $deliveryRate >= 80 ? "Target Met" : "Below Target"; ?>
                </span>
            </article>
        </div>
    </section>

    <section class="section-block">
        <div class="table-card">
            <div class="section-header">
                <div>
                    <p class="eyebrow">Time To Sell</p>
                    <h2>Average Listing-to-Accept Time</h2>
                </div>
            </div>

            <p class="hero-text mb-2">
                Current average: <strong><?php echo number_format($avgDaysToAccept, 1); ?> days</strong>.
                Target: <strong>14 days or less</strong>.
            </p>
            <span class="status-badge <?php echo $avgDaysToAccept > 0 && $avgDaysToAccept <= 14 ? "status-accepted" : "status-pending"; ?>">
                <?php echo $avgDaysToAccept > 0 && $avgDaysToAccept <= 14 ? "Target Met" : "Below Target / Insufficient Data"; ?>
            </span>
        </div>
    </section>

    <section class="section-block">
        <div class="table-card">
            <div class="section-header">
                <div>
                    <p class="eyebrow">Raw Metrics</p>
                    <h2>Metric Details</h2>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Metric</th>
                            <th>Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>Total transactions</td><td><?php echo number_format($stats["total_transactions"]); ?></td></tr>
                        <tr><td>Secured transactions</td><td><?php echo number_format($stats["secured_transactions"]); ?></td></tr>
                        <tr><td>Completed transactions</td><td><?php echo number_format($stats["completed_transactions"]); ?></td></tr>
                        <tr><td>Verified completed transactions</td><td><?php echo number_format($stats["verified_completed_transactions"]); ?></td></tr>
                        <tr><td>Delivery eligible transactions</td><td><?php echo number_format($stats["delivery_eligible_transactions"]); ?></td></tr>
                        <tr><td>Successful delivery transactions</td><td><?php echo number_format($stats["successful_delivery_transactions"]); ?></td></tr>
                        <tr><td>Open disputes</td><td><?php echo number_format($stats["open_disputes"]); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</main>

<script src="../js/mobile-nav.js?v=20260507m"></script>
</body>
</html>
