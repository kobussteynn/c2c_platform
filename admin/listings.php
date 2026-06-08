<?php
require_once __DIR__ . "/auth.php";
adminRequirePermission("listings.view");

$canManageListings = adminHasPermission("listings.manage");
$message = "";
$messageType = "danger";
$search = trim($_GET["q"] ?? "");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = trim($_POST["action"] ?? "");

    if (!$canManageListings) {
        $messageType = "warning";
        $message = "Your role can view listings but cannot modify them.";
    } elseif ($action === "delete_listing") {
        $productId = filter_var($_POST["product_id"] ?? "", FILTER_VALIDATE_INT);

        if (!$productId) {
            $message = "Invalid listing selected for deletion.";
        } else {
            $conn->begin_transaction();
            try {
                $deleteMessages = $conn->prepare("DELETE FROM messages WHERE transaction_id IN (SELECT transaction_id FROM transactions WHERE product_id = ?)");
                $deleteMessages->bind_param("i", $productId);
                $deleteMessages->execute();

                $deleteTransactions = $conn->prepare("DELETE FROM transactions WHERE product_id = ?");
                $deleteTransactions->bind_param("i", $productId);
                $deleteTransactions->execute();

                $deleteImages = $conn->prepare("DELETE FROM product_images WHERE product_id = ?");
                $deleteImages->bind_param("i", $productId);
                $deleteImages->execute();

                $deleteProduct = $conn->prepare("DELETE FROM products WHERE product_id = ?");
                $deleteProduct->bind_param("i", $productId);
                $deleteProduct->execute();

                $conn->commit();
                $messageType = "success";
                $message = "Listing removed successfully.";
            } catch (Throwable $e) {
                $conn->rollback();
                $message = "Listing deletion failed: " . $e->getMessage();
            }
        }
    } else {
        $message = "Unsupported listing action.";
    }
}

$listings = [];
if ($search !== "") {
    $like = "%" . $search . "%";
    $stmt = $conn->prepare("
        SELECT
            products.product_id,
            products.title,
            products.price,
            products.image,
            products.created_at,
            categories.category_name,
            users.name AS seller_name,
            users.email AS seller_email,
            (SELECT COUNT(*) FROM transactions WHERE transactions.product_id = products.product_id) AS deal_count
        FROM products
        INNER JOIN users ON users.user_id = products.user_id
        INNER JOIN categories ON categories.category_id = products.category_id
        WHERE products.title LIKE ? OR users.name LIKE ? OR users.email LIKE ?
        ORDER BY products.product_id DESC
    ");
    $stmt->bind_param("sss", $like, $like, $like);
} else {
    $stmt = $conn->prepare("
        SELECT
            products.product_id,
            products.title,
            products.price,
            products.image,
            products.created_at,
            categories.category_name,
            users.name AS seller_name,
            users.email AS seller_email,
            (SELECT COUNT(*) FROM transactions WHERE transactions.product_id = products.product_id) AS deal_count
        FROM products
        INNER JOIN users ON users.user_id = products.user_id
        INNER JOIN categories ON categories.category_id = products.category_id
        ORDER BY products.product_id DESC
    ");
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $listings[] = $row;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Admin Listings - C2C Platform</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/style.css?v=20260514ui1" rel="stylesheet">
</head>
<body class="app-body">

<?php adminRenderNav("listings"); ?>

<main class="dashboard-page">
    <section class="dashboard-hero">
        <div>
            <p class="eyebrow">Marketplace Mod</p>
            <h1>Listing Queue</h1>
            <p class="hero-text">Search listings fast and remove broken/spam rows when required.</p>
        </div>

        <div class="role-card">
            <p>Mod State</p>
            <h3><?php echo $canManageListings ? "Enabled" : "Read-Only"; ?></h3>
            <span><?php echo htmlspecialchars($current_admin_role); ?> panel rights</span>
        </div>
    </section>

    <?php if ($message !== ""): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> mt-4">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <section class="section-block">
        <div class="table-card">
            <form method="GET" class="row g-3">
                <div class="col-md-10">
                    <label class="form-label">Search listings</label>
                    <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" class="form-control" placeholder="Search by product title, seller name, or seller email">
                </div>
                <div class="col-md-2 d-grid">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn-auth">Search</button>
                </div>
            </form>
        </div>
    </section>

    <section class="section-block">
        <div class="section-header">
                <div>
                    <p class="eyebrow">Catalog</p>
                    <h2>Listing Table</h2>
                </div>
            </div>

        <div class="table-card">
            <?php if (count($listings) > 0): ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Seller</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Deals</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($listings as $listing): ?>
                                <tr>
                                    <td><?php echo (int)$listing["product_id"]; ?></td>
                                    <td>
                                        <a href="../product.php?id=<?php echo (int)$listing["product_id"]; ?>" class="table-link" target="_blank">
                                            <?php echo htmlspecialchars((string)$listing["title"]); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars((string)$listing["seller_name"]); ?><br>
                                        <small><?php echo htmlspecialchars((string)$listing["seller_email"]); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)$listing["category_name"]); ?></td>
                                    <td>R<?php echo number_format((float)$listing["price"], 2); ?></td>
                                    <td><?php echo number_format((int)$listing["deal_count"]); ?></td>
                                    <td><?php echo htmlspecialchars((string)$listing["created_at"]); ?></td>
                                    <td>
                                        <div class="deal-actions">
                                            <a href="../product.php?id=<?php echo (int)$listing["product_id"]; ?>" target="_blank" class="btn-action-link">View</a>
                                            <?php if ($canManageListings): ?>
                                                <form method="POST" class="inline-form" onsubmit="return confirm('Delete this listing and linked transactions?');">
                                                    <input type="hidden" name="action" value="delete_listing">
                                                    <input type="hidden" name="product_id" value="<?php echo (int)$listing["product_id"]; ?>">
                                                    <button type="submit" class="btn-action-danger">Delete</button>
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
                    <h3>No listings found.</h3>
                    <p>Try another search value or post a new product first.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<script src="../js/mobile-nav.js?v=20260507m"></script>
</body>
</html>
