<?php
session_start();
include("includes/db.php");

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

$current_user_id = (int)$_SESSION["user_id"];
$feedback = "";
$feedbackType = "danger";

$dealsStmt = $conn->prepare("
    SELECT
        transactions.transaction_id,
        transactions.status,
        transactions.created_at,
        transactions.buyer_id,
        transactions.seller_id,
        products.product_id,
        products.title,
        products.price,
        buyer.user_id AS buyer_user_id,
        buyer.name AS buyer_name,
        seller.user_id AS seller_user_id,
        seller.name AS seller_name,
        CASE
            WHEN transactions.buyer_id = ? THEN seller.user_id
            ELSE buyer.user_id
        END AS other_user_id,
        CASE
            WHEN transactions.buyer_id = ? THEN seller.name
            ELSE buyer.name
        END AS other_user_name
    FROM transactions
    INNER JOIN products ON transactions.product_id = products.product_id
    INNER JOIN users AS buyer ON transactions.buyer_id = buyer.user_id
    INNER JOIN users AS seller ON transactions.seller_id = seller.user_id
    WHERE transactions.buyer_id = ? OR transactions.seller_id = ?
    ORDER BY transactions.created_at DESC
");
$dealsStmt->bind_param("iiii", $current_user_id, $current_user_id, $current_user_id, $current_user_id);
$dealsStmt->execute();
$dealsResult = $dealsStmt->get_result();

$deals = [];
$dealById = [];
while ($row = $dealsResult->fetch_assoc()) {
    $deals[] = $row;
    $dealById[(int)$row["transaction_id"]] = $row;
}

$requestedDealId = filter_var($_GET["t"] ?? $_POST["transaction_id"] ?? "", FILTER_VALIDATE_INT);
$activeDeal = null;

if ($requestedDealId && isset($dealById[(int)$requestedDealId])) {
    $activeDeal = $dealById[(int)$requestedDealId];
} elseif (count($deals) > 0) {
    $activeDeal = $deals[0];
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["send_message"])) {
    $postedDealId = filter_var($_POST["transaction_id"] ?? "", FILTER_VALIDATE_INT);
    $messageText = trim($_POST["message"] ?? "");

    if (!$postedDealId || !isset($dealById[(int)$postedDealId])) {
        $feedback = "Invalid deal selected for chat.";
    } elseif ($messageText === "") {
        $feedback = "Please enter a message before sending.";
    } else {
        $targetDeal = $dealById[(int)$postedDealId];
        $receiverId = (int)$targetDeal["other_user_id"];

        $insert = $conn->prepare("
            INSERT INTO messages (sender_id, receiver_id, transaction_id, message)
            VALUES (?, ?, ?, ?)
        ");
        $insert->bind_param("iiis", $current_user_id, $receiverId, $postedDealId, $messageText);

        if ($insert->execute()) {
            header("Location: messages.php?t=" . (int)$postedDealId);
            exit();
        }

        $feedback = "Message could not be sent.";
    }
}

$messages = [];
if ($activeDeal) {
    $activeDealId = (int)$activeDeal["transaction_id"];
    $otherUserId = (int)$activeDeal["other_user_id"];

    $messagesStmt = $conn->prepare("
        SELECT
            messages.message_id,
            messages.sender_id,
            messages.receiver_id,
            messages.message,
            messages.created_at,
            users.name AS sender_name
        FROM messages
        INNER JOIN users ON users.user_id = messages.sender_id
        WHERE messages.transaction_id = ?
          AND (
                (messages.sender_id = ? AND messages.receiver_id = ?)
                OR
                (messages.sender_id = ? AND messages.receiver_id = ?)
          )
        ORDER BY messages.created_at ASC
    ");
    $messagesStmt->bind_param("iiiii", $activeDealId, $current_user_id, $otherUserId, $otherUserId, $current_user_id);
    $messagesStmt->execute();
    $messagesResult = $messagesStmt->get_result();

    while ($row = $messagesResult->fetch_assoc()) {
        $messages[] = $row;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Chat - C2C Platform</title>
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
        <a href="transactions.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_deals", "Deals")); ?></a>
        <a href="messages.php" class="btn-dark"><?php echo htmlspecialchars(c2cT("nav_chat", "Chat")); ?></a>
        <a href="profile.php" class="btn-light-custom"><?php echo htmlspecialchars(c2cT("nav_profile", "Profile")); ?></a>
        <a href="settings.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_settings", "Settings")); ?></a>
        <a href="support.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_support", "Support")); ?></a>
        <a href="logout.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_logout", "Logout")); ?></a>
    </div>
</nav>

<main class="dashboard-page">
    <?php if ($feedback !== ""): ?>
        <div class="alert alert-<?php echo htmlspecialchars($feedbackType); ?> mb-3">
            <?php echo htmlspecialchars($feedback); ?>
        </div>
    <?php endif; ?>

    <div class="chat-layout">
        <aside class="chat-sidebar">
            <div class="chat-sidebar-head">
                <p class="eyebrow">Deal Chats</p>
                <h2>Conversations</h2>
            </div>

            <div class="chat-deal-list">
                <?php if (count($deals) > 0): ?>
                    <?php foreach ($deals as $deal): ?>
                        <?php
                            $dealId = (int)$deal["transaction_id"];
                            $isActive = $activeDeal && (int)$activeDeal["transaction_id"] === $dealId;
                        ?>
                        <a href="messages.php?t=<?php echo $dealId; ?>" class="chat-deal-item <?php echo $isActive ? "active" : ""; ?>">
                            <div class="chat-deal-top">
                                <strong><?php echo htmlspecialchars($deal["other_user_name"]); ?></strong>
                                <span class="status-badge <?php echo getStatusBadgeClass((string)$deal["status"]); ?>">
                                    <?php echo htmlspecialchars(formatStatusLabel($deal["status"])); ?>
                                </span>
                            </div>
                            <p><?php echo htmlspecialchars($deal["title"]); ?></p>
                            <small>Deal #<?php echo $dealId; ?></small>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state chat-empty">
                        <h3>No deals yet.</h3>
                        <p>Create or receive a buy request to start chatting.</p>
                    </div>
                <?php endif; ?>
            </div>
        </aside>

        <section class="chat-thread">
            <?php if ($activeDeal): ?>
                <div class="chat-thread-head">
                    <div>
                        <p class="eyebrow">Active Deal</p>
                        <h2><?php echo htmlspecialchars($activeDeal["title"]); ?></h2>
                        <p class="chat-subtext">
                            Chat with <?php echo htmlspecialchars($activeDeal["other_user_name"]); ?>
                            about deal #<?php echo (int)$activeDeal["transaction_id"]; ?>.
                        </p>
                    </div>
                    <div class="chat-thread-actions">
                        <a href="transactions.php" class="btn-action-link">Manage Deal</a>
                        <a href="product.php?id=<?php echo (int)$activeDeal["product_id"]; ?>" class="btn-action-link">View Product</a>
                    </div>
                </div>

                <div class="chat-messages" id="chatMessages">
                    <?php if (count($messages) > 0): ?>
                        <?php foreach ($messages as $msg): ?>
                            <?php $isMine = (int)$msg["sender_id"] === $current_user_id; ?>
                            <div class="chat-row <?php echo $isMine ? "mine" : "theirs"; ?>">
                                <div class="chat-bubble <?php echo $isMine ? "mine" : "theirs"; ?>">
                                    <div class="chat-bubble-author">
                                        <?php echo htmlspecialchars($isMine ? "You" : $msg["sender_name"]); ?>
                                    </div>
                                    <div class="chat-bubble-text">
                                        <?php echo nl2br(htmlspecialchars($msg["message"])); ?>
                                    </div>
                                    <div class="chat-bubble-time">
                                        <?php echo htmlspecialchars((string)$msg["created_at"]); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state chat-empty">
                            <h3>No messages yet.</h3>
                            <p>Send the first message to start this deal conversation.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <form method="POST" class="chat-form">
                    <input type="hidden" name="transaction_id" value="<?php echo (int)$activeDeal["transaction_id"]; ?>">
                    <textarea
                        name="message"
                        class="form-control"
                        rows="3"
                        maxlength="1500"
                        placeholder="Type your message about this deal..."
                        required
                    ></textarea>
                    <button type="submit" name="send_message" class="btn-auth chat-send-btn">Send Message</button>
                </form>
            <?php else: ?>
                <div class="empty-state chat-empty-large">
                    <h3>No active conversations</h3>
                    <p>Go to marketplace, request to buy an item, and then start chatting here.</p>
                    <a href="listings.php" class="btn-dark mt-2">Browse Marketplace</a>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<script>
const chatMessages = document.getElementById("chatMessages");
if (chatMessages) {
    chatMessages.scrollTop = chatMessages.scrollHeight;
}
</script>
<script src="js/mobile-nav.js?v=20260507m"></script>
</body>
</html>







