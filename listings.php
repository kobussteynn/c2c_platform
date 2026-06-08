<?php
session_start();
include("includes/db.php");

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

$current_user_id = $_SESSION["user_id"];
$search = trim((string)($_GET["q"] ?? ""));
$categoryId = filter_var($_GET["category_id"] ?? "", FILTER_VALIDATE_INT) ?: 0;
$minPrice = filter_var($_GET["min_price"] ?? "", FILTER_VALIDATE_FLOAT);
$maxPrice = filter_var($_GET["max_price"] ?? "", FILTER_VALIDATE_FLOAT);
$sort = trim((string)($_GET["sort"] ?? "newest"));

$categoryResult = $conn->query("SELECT category_id, category_name FROM categories ORDER BY category_name ASC");
$categories = [];
while ($row = $categoryResult->fetch_assoc()) {
    $categories[] = $row;
}

$whereParts = [
    "products.user_id != ?",
    "NOT EXISTS (
        SELECT 1
        FROM transactions
        WHERE transactions.product_id = products.product_id
          AND transactions.status IN ('Accepted', 'Completed')
    )"
];
$params = [$current_user_id];
$types = "i";

if ($search !== "") {
    $whereParts[] = "(products.title LIKE ? OR products.description LIKE ? OR users.name LIKE ?)";
    $like = "%" . $search . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

if ($categoryId > 0) {
    $whereParts[] = "products.category_id = ?";
    $params[] = $categoryId;
    $types .= "i";
}

if ($minPrice !== false && $minPrice >= 0) {
    $whereParts[] = "products.price >= ?";
    $params[] = (float)$minPrice;
    $types .= "d";
}

if ($maxPrice !== false && $maxPrice >= 0) {
    $whereParts[] = "products.price <= ?";
    $params[] = (float)$maxPrice;
    $types .= "d";
}

$orderBy = "products.created_at DESC";
if ($sort === "price_asc") {
    $orderBy = "products.price ASC";
} elseif ($sort === "price_desc") {
    $orderBy = "products.price DESC";
}

$sql = "
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
    WHERE " . implode(" AND ", $whereParts) . "
    ORDER BY {$orderBy}
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$products = $stmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Marketplace - C2C Platform</title>
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
    <div class="page-heading">
        <p class="eyebrow">Marketplace</p>
        <h1>Buy Second-Hand Items</h1>
        <p class="hero-text">Browse, search, and filter products listed by other customers.</p>
    </div>

    <section class="section-block">
        <div class="table-card">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Search</label>
                    <input type="text" name="q" class="form-control" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search title, seller, or description">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Category</label>
                    <select name="category_id" class="form-control">
                        <option value="">All</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo (int)$category["category_id"]; ?>" <?php echo $categoryId === (int)$category["category_id"] ? "selected" : ""; ?>>
                                <?php echo htmlspecialchars((string)$category["category_name"]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Min Price</label>
                    <input type="number" step="0.01" min="0" name="min_price" class="form-control" value="<?php echo htmlspecialchars((string)($_GET["min_price"] ?? "")); ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Max Price</label>
                    <input type="number" step="0.01" min="0" name="max_price" class="form-control" value="<?php echo htmlspecialchars((string)($_GET["max_price"] ?? "")); ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Sort</label>
                    <select name="sort" class="form-control">
                        <option value="newest" <?php echo $sort === "newest" ? "selected" : ""; ?>>Newest</option>
                        <option value="price_asc" <?php echo $sort === "price_asc" ? "selected" : ""; ?>>Price Low to High</option>
                        <option value="price_desc" <?php echo $sort === "price_desc" ? "selected" : ""; ?>>Price High to Low</option>
                    </select>
                </div>

                <div class="col-md-12 d-flex gap-2">
                    <button type="submit" class="btn-auth btn-auth-inline">Apply Filters</button>
                    <a href="listings.php" class="btn-action-link btn-action-tall">Reset</a>
                </div>
            </form>
        </div>
    </section>

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
</main>

<script src="js/mobile-nav.js?v=20260507m"></script>
</body>
</html>








