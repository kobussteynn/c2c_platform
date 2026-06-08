<?php
session_start();
include("includes/db.php");

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

$current_user_id = $_SESSION["user_id"];
$name = $_SESSION["name"] ?? "User";
$role = $_SESSION["role"] ?? "Buyer";

$stmt = $conn->prepare("
    SELECT 
        products.product_id,
        products.title,
        products.description,
        products.price,
        products.image,
        products.created_at,
        users.name AS seller_name,
        categories.category_name
    FROM products
    INNER JOIN users ON products.user_id = users.user_id
    INNER JOIN categories ON products.category_id = categories.category_id
    WHERE products.user_id != ?
      AND NOT EXISTS (
          SELECT 1
          FROM transactions
          WHERE transactions.product_id = products.product_id
            AND transactions.status IN ('Accepted', 'Completed')
      )
    ORDER BY products.created_at DESC
");
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$products = $stmt->get_result();
$availableCount = $products->num_rows;
?>

<!DOCTYPE html>
<html>
<head>
    <title>Home - C2C Platform</title>
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
        <a href="dashboard.php" class="btn-dark"><?php echo htmlspecialchars(c2cT("nav_home", "Home")); ?></a>
        <a href="listings.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_marketplace", "Marketplace")); ?></a>
        <a href="sell.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_sell", "Sell")); ?></a>
        <a href="transactions.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_deals", "Deals")); ?></a>
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
            <p class="eyebrow">Home</p>
            <h1>Welcome back, <?php echo htmlspecialchars($name); ?></h1>
            <p class="hero-text">Products from other users are listed below.</p>

            <div class="hero-actions">
                <a href="sell.php" class="btn-dark">Sell an Item</a>
                <a href="listings.php" class="btn-light-custom">View Full Marketplace</a>
            </div>
        </div>

        <div class="role-card">
            <p>Available to Buy</p>
            <h3><?php echo number_format($availableCount); ?></h3>
            <span><?php echo htmlspecialchars($role); ?> account</span>
        </div>
    </section>

    <section class="mt-4">
        <div class="page-heading mb-4">
            <p class="eyebrow">Just Listed</p>
            <h1>Items You Can Buy</h1>
            <p class="hero-text">Latest items appear here.</p>
        </div>

        <div class="product-grid">
            <?php if ($products->num_rows > 0): ?>
                <?php while ($product = $products->fetch_assoc()): ?>
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

                            <p>
                                <?php echo htmlspecialchars(substr($product["description"], 0, 90)); ?>...
                            </p>

                            <div class="product-meta">
                                <strong>R<?php echo number_format($product["price"], 2); ?></strong>
                                <span>Seller: <?php echo htmlspecialchars($product["seller_name"]); ?></span>
                            </div>

                            <a href="product.php?id=<?php echo $product["product_id"]; ?>" class="btn-auth mt-3">View Product</a>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state">
                    <h3>No products available yet.</h3>
                    <p>Once other users list products, they will appear here.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<script src="js/mobile-nav.js?v=20260507m"></script>
</body>
</html>








