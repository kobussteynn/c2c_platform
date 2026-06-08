<?php
require_once __DIR__ . "/auth.php";
adminRequirePermission("dashboard.view");

$forbidden = trim($_GET["forbidden"] ?? "");

$counts = [
    "users" => 0,
    "verified_users" => 0,
    "products" => 0,
    "transactions" => 0,
    "messages" => 0,
    "open_disputes" => 0,
    "open_flags" => 0,
    "open_support_tickets" => 0,
    "pending" => 0,
    "accepted" => 0,
    "completed" => 0
];

$countQueries = [
    "users" => "SELECT COUNT(*) AS total FROM users",
    "verified_users" => "SELECT COUNT(*) AS total FROM users WHERE is_phone_verified = 1",
    "products" => "SELECT COUNT(*) AS total FROM products",
    "transactions" => "SELECT COUNT(*) AS total FROM transactions",
    "messages" => "SELECT COUNT(*) AS total FROM messages",
    "open_disputes" => "SELECT COUNT(*) AS total FROM disputes WHERE status = 'Open'",
    "open_flags" => "SELECT COUNT(*) AS total FROM suspicious_flags WHERE status IN ('Open', 'Investigating')",
    "open_support_tickets" => "SELECT COUNT(*) AS total FROM support_tickets WHERE status IN ('Open', 'In Progress')",
    "pending" => "SELECT COUNT(*) AS total FROM transactions WHERE status = 'Pending'",
    "accepted" => "SELECT COUNT(*) AS total FROM transactions WHERE status = 'Accepted'",
    "completed" => "SELECT COUNT(*) AS total FROM transactions WHERE status = 'Completed'"
];

foreach ($countQueries as $key => $sql) {
    $row = $conn->query($sql)->fetch_assoc();
    $counts[$key] = (int)($row["total"] ?? 0);
}

$roleBreakdown = [];
$roleSummary = $conn->query("
    SELECT
        roles.role_name,
        COUNT(user_roles.user_id) AS members
    FROM roles
    LEFT JOIN user_roles ON user_roles.role_id = roles.role_id
    GROUP BY roles.role_id, roles.role_name
    ORDER BY roles.role_name ASC
");
while ($row = $roleSummary->fetch_assoc()) {
    $roleBreakdown[] = $row;
}

$recentDeals = [];
$recentStmt = $conn->prepare("
    SELECT
        transactions.transaction_id,
        transactions.status,
        transactions.created_at,
        products.title,
        buyer.name AS buyer_name,
        seller.name AS seller_name
    FROM transactions
    INNER JOIN products ON products.product_id = transactions.product_id
    INNER JOIN users AS buyer ON buyer.user_id = transactions.buyer_id
    INNER JOIN users AS seller ON seller.user_id = transactions.seller_id
    ORDER BY transactions.transaction_id DESC
    LIMIT 8
");
$recentStmt->execute();
$recentResult = $recentStmt->get_result();
while ($row = $recentResult->fetch_assoc()) {
    $recentDeals[] = $row;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - C2C Platform</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/style.css?v=20260514ui1" rel="stylesheet">
</head>
<body class="app-body">

<?php adminRenderNav("dashboard"); ?>

<main class="dashboard-page">
    <section class="dashboard-hero">
        <div>
            <p class="eyebrow">Admin Home</p>
            <h1>Dashboard</h1>
            <p class="hero-text">Current users, listings, and transactions.</p>
            <p class="hero-text mb-0">
                Logged in as <strong><?php echo htmlspecialchars($_SESSION["name"] ?? "Admin"); ?></strong>
                (<?php echo htmlspecialchars($current_admin_role); ?>)
            </p>
        </div>

        <div class="role-card">
            <p>Open Deals</p>
            <h3><?php echo number_format($counts["pending"]); ?></h3>
            <span><?php echo number_format($counts["accepted"]); ?> accepted, <?php echo number_format($counts["completed"]); ?> completed</span>
        </div>
    </section>

    <?php if ($forbidden !== ""): ?>
        <div class="alert alert-warning mt-4">
            Permission denied for action: <?php echo htmlspecialchars($forbidden); ?>
        </div>
    <?php endif; ?>

    <section class="section-block">
        <div class="product-grid">
            <article class="quick-actions-card">
                <p class="eyebrow">Users</p>
                <h3><?php echo number_format($counts["users"]); ?></h3>
                <p>Registered accounts right now.</p>
            </article>

            <article class="quick-actions-card">
                <p class="eyebrow">Verified Users</p>
                <h3><?php echo number_format($counts["verified_users"]); ?></h3>
                <p>Users with phone verification enabled.</p>
            </article>

            <article class="quick-actions-card">
                <p class="eyebrow">Listings</p>
                <h3><?php echo number_format($counts["products"]); ?></h3>
                <p>Active marketplace rows in `products`.</p>
            </article>

            <article class="quick-actions-card">
                <p class="eyebrow">Deals</p>
                <h3><?php echo number_format($counts["transactions"]); ?></h3>
                <p>Tracked requests from buyers/sellers.</p>
            </article>

            <article class="quick-actions-card">
                <p class="eyebrow">Open Disputes</p>
                <h3><?php echo number_format($counts["open_disputes"]); ?></h3>
                <p>Disputes currently waiting for moderation.</p>
            </article>

            <article class="quick-actions-card">
                <p class="eyebrow">Open Flags</p>
                <h3><?php echo number_format($counts["open_flags"]); ?></h3>
                <p>Suspicious activity alerts awaiting review.</p>
            </article>

            <article class="quick-actions-card">
                <p class="eyebrow">Support Tickets</p>
                <h3><?php echo number_format($counts["open_support_tickets"]); ?></h3>
                <p>Open/in-progress support tickets.</p>
            </article>
        </div>
    </section>

    <section class="section-block">
        <div class="section-header">
            <div>
                <p class="eyebrow">Roles</p>
                <h2>Role Distribution</h2>
            </div>
            <a href="roles.php" class="btn-action-link">Manage Roles</a>
        </div>

        <div class="table-card">
            <?php if (count($roleBreakdown) > 0): ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Role</th>
                                <th>Users Assigned</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roleBreakdown as $role): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($role["role_name"]); ?></td>
                                    <td><?php echo number_format((int)$role["members"]); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <h3>No roles found.</h3>
                    <p>Create roles in the Roles page.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="section-block">
        <div class="section-header">
            <div>
                <p class="eyebrow">Latest Activity</p>
                <h2>Recent Transactions</h2>
            </div>
            <a href="transactions.php" class="btn-action-link">Open Transactions</a>
        </div>

        <div class="table-card">
            <?php if (count($recentDeals) > 0): ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Deal #</th>
                                <th>Product</th>
                                <th>Buyer</th>
                                <th>Seller</th>
                                <th>Status</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentDeals as $deal): ?>
                                <tr>
                                    <td><?php echo (int)$deal["transaction_id"]; ?></td>
                                    <td><?php echo htmlspecialchars($deal["title"]); ?></td>
                                    <td><?php echo htmlspecialchars($deal["buyer_name"]); ?></td>
                                    <td><?php echo htmlspecialchars($deal["seller_name"]); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo getStatusBadgeClass((string)$deal["status"]); ?>">
                                            <?php echo htmlspecialchars(formatStatusLabel((string)$deal["status"])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)$deal["created_at"]); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <h3>No transactions yet.</h3>
                    <p>Once buyers send requests, recent activity shows here.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<script src="../js/mobile-nav.js?v=20260507m"></script>
</body>
</html>

