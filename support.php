<?php
session_start();
include("includes/db.php");

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

$currentUserId = (int)$_SESSION["user_id"];
$name = $_SESSION["name"] ?? "User";
$message = "";
$messageType = "danger";

$allowedCategories = [
    "Payments",
    "Delivery",
    "Account",
    "Fraud/Safety",
    "Technical"
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = trim((string)($_POST["action"] ?? ""));

    if ($action === "create_ticket") {
        $category = trim((string)($_POST["category"] ?? ""));
        $subject = trim((string)($_POST["subject"] ?? ""));
        $details = trim((string)($_POST["details"] ?? ""));

        if (!in_array($category, $allowedCategories, true)) {
            $message = "Choose a valid support category.";
        } elseif ($subject === "" || strlen($subject) < 5) {
            $message = "Subject should be at least 5 characters.";
        } elseif ($details === "" || strlen($details) < 10) {
            $message = "Please provide more detail (at least 10 characters).";
        } else {
            $insert = $conn->prepare("
                INSERT INTO support_tickets (user_id, category, subject, details, status)
                VALUES (?, ?, ?, ?, 'Open')
            ");
            $insert->bind_param("isss", $currentUserId, $category, $subject, $details);

            if ($insert->execute()) {
                $messageType = "success";
                $message = "Support ticket submitted successfully.";
                $_POST = [];
            } else {
                $message = "Could not submit ticket.";
            }
        }
    }
}

$tickets = [];
$ticketStmt = $conn->prepare("
    SELECT ticket_id, category, subject, status, admin_note, created_at, updated_at
    FROM support_tickets
    WHERE user_id = ?
    ORDER BY ticket_id DESC
");
$ticketStmt->bind_param("i", $currentUserId);
$ticketStmt->execute();
$ticketResult = $ticketStmt->get_result();
while ($row = $ticketResult->fetch_assoc()) {
    $tickets[] = $row;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Support - C2C Platform</title>
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
        <a href="settings.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_settings", "Settings")); ?></a>
        <a href="support.php" class="btn-dark"><?php echo htmlspecialchars(c2cT("nav_support", "Support")); ?></a>
        <a href="logout.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_logout", "Logout")); ?></a>
    </div>
</nav>

<main class="dashboard-page">
    <section class="dashboard-hero">
        <div>
            <p class="eyebrow">Help Center</p>
            <h1>Support & Training</h1>
            <p class="hero-text">Create a ticket and check updates here.</p>
            <p class="hero-text mb-0">Welcome, <strong><?php echo htmlspecialchars($name); ?></strong>.</p>
        </div>

        <div class="role-card">
            <p>Support Line</p>
            <h3>WhatsApp</h3>
            <span>+27 63 000 1111 (Mock)</span>
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
                    <p class="eyebrow">Training</p>
                    <h2>Quick Tutorials</h2>
                </div>
            </div>

            <div class="product-grid">
                <article class="quick-actions-card">
                    <h3>Safe Buying Guide</h3>
                    <p>Use in-app deals, verify seller details, and keep chat records.</p>
                </article>
                <article class="quick-actions-card">
                    <h3>Safe Selling Guide</h3>
                    <p>Hand over items only after payment confirmation.</p>
                </article>
                <article class="quick-actions-card">
                    <h3>Delivery Guide</h3>
                    <p>Keep a tracking reference for each shipment.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="section-block">
        <div class="table-card">
            <div class="section-header">
                <div>
                    <p class="eyebrow">Need Help?</p>
                    <h2>Create Support Ticket</h2>
                </div>
            </div>

            <form method="POST" class="row g-3">
                <input type="hidden" name="action" value="create_ticket">

                <div class="col-md-3">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-control" required>
                        <option value="">Select</option>
                        <?php foreach ($allowedCategories as $category): ?>
                            <option value="<?php echo htmlspecialchars($category); ?>" <?php echo (($_POST["category"] ?? "") === $category) ? "selected" : ""; ?>>
                                <?php echo htmlspecialchars($category); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-9">
                    <label class="form-label">Subject</label>
                    <input type="text" name="subject" class="form-control" value="<?php echo htmlspecialchars((string)($_POST["subject"] ?? "")); ?>" required>
                </div>

                <div class="col-md-12">
                    <label class="form-label">Details</label>
                    <textarea name="details" rows="5" class="form-control" required><?php echo htmlspecialchars((string)($_POST["details"] ?? "")); ?></textarea>
                </div>

                <div class="col-md-3 d-grid">
                    <button type="submit" class="btn-auth">Submit Ticket</button>
                </div>
            </form>
        </div>
    </section>

    <section class="section-block">
        <div class="section-header">
            <div>
                <p class="eyebrow">My Requests</p>
                <h2>Ticket History</h2>
            </div>
        </div>

        <div class="table-card">
            <?php if (count($tickets) > 0): ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Category</th>
                                <th>Subject</th>
                                <th>Status</th>
                                <th>Admin Note</th>
                                <th>Created</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tickets as $ticket): ?>
                                <tr>
                                    <td>#<?php echo (int)$ticket["ticket_id"]; ?></td>
                                    <td><?php echo htmlspecialchars((string)$ticket["category"]); ?></td>
                                    <td><?php echo htmlspecialchars((string)$ticket["subject"]); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo strtolower((string)$ticket["status"]) === "resolved" ? "status-accepted" : "status-default"; ?>">
                                            <?php echo htmlspecialchars(formatStatusLabel((string)$ticket["status"])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)($ticket["admin_note"] ?: "No note yet")); ?></td>
                                    <td><?php echo htmlspecialchars((string)$ticket["created_at"]); ?></td>
                                    <td><?php echo htmlspecialchars((string)$ticket["updated_at"]); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <h3>No support tickets yet.</h3>
                    <p>Submit your first support request above.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<script src="js/mobile-nav.js?v=20260507m"></script>
</body>
</html>

