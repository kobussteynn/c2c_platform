<?php
require_once __DIR__ . "/auth.php";
adminRequirePermission("transactions.view");

$canManageTransactions = adminHasPermission("transactions.manage");
$message = "";
$messageType = "danger";
$search = trim($_GET["q"] ?? "");

$allowedStatuses = ["Pending", "Accepted", "Rejected", "Cancelled", "Completed"];
$allowedPaymentStatuses = c2cPaymentStatuses();
$allowedDeliveryStatuses = c2cDeliveryStatuses();
$allowedDisputeStatuses = ["Open", "Resolved", "Rejected"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = trim($_POST["action"] ?? "");

    if (!$canManageTransactions) {
        $messageType = "warning";
        $message = "Your role can view transactions but cannot modify them.";
    } elseif ($action === "update_status") {
        $transactionId = filter_var($_POST["transaction_id"] ?? "", FILTER_VALIDATE_INT);
        $status = trim($_POST["status"] ?? "");

        if (!$transactionId || !in_array($status, $allowedStatuses, true)) {
            $message = "Invalid transaction status update request.";
        } else {
            $update = $conn->prepare("UPDATE transactions SET status = ? WHERE transaction_id = ?");
            $update->bind_param("si", $status, $transactionId);

            if ($update->execute()) {
                $messageType = "success";
                $message = "Transaction status updated.";
            } else {
                $message = "Failed to update transaction status.";
            }
        }
    } elseif ($action === "delete_transaction") {
        $transactionId = filter_var($_POST["transaction_id"] ?? "", FILTER_VALIDATE_INT);

        if (!$transactionId) {
            $message = "Invalid transaction selected for deletion.";
        } else {
            $conn->begin_transaction();
            try {
                $deleteMessages = $conn->prepare("DELETE FROM messages WHERE transaction_id = ?");
                $deleteMessages->bind_param("i", $transactionId);
                $deleteMessages->execute();

                $deleteDisputes = $conn->prepare("DELETE FROM disputes WHERE transaction_id = ?");
                $deleteDisputes->bind_param("i", $transactionId);
                $deleteDisputes->execute();

                $deleteTx = $conn->prepare("DELETE FROM transactions WHERE transaction_id = ?");
                $deleteTx->bind_param("i", $transactionId);
                $deleteTx->execute();

                $conn->commit();
                $messageType = "success";
                $message = "Transaction deleted successfully.";
            } catch (Throwable $e) {
                $conn->rollback();
                $message = "Transaction deletion failed: " . $e->getMessage();
            }
        }
    } elseif ($action === "update_mock_fields") {
        $transactionId = filter_var($_POST["transaction_id"] ?? "", FILTER_VALIDATE_INT);
        $paymentStatus = trim((string)($_POST["payment_status"] ?? ""));
        $deliveryStatus = trim((string)($_POST["delivery_status"] ?? ""));

        if (!$transactionId || !in_array($paymentStatus, $allowedPaymentStatuses, true) || !in_array($deliveryStatus, $allowedDeliveryStatuses, true)) {
            $message = "Invalid mock field update request.";
        } else {
            $update = $conn->prepare("
                UPDATE transactions
                SET payment_status = ?,
                    delivery_status = ?,
                    delivery_updated_at = NOW()
                WHERE transaction_id = ?
            ");
            $update->bind_param("ssi", $paymentStatus, $deliveryStatus, $transactionId);

            if ($update->execute()) {
                $messageType = "success";
                $message = "Mock payment/delivery status updated.";
            } else {
                $message = "Failed to update mock statuses.";
            }
        }
    } elseif ($action === "resolve_dispute") {
        $disputeId = filter_var($_POST["dispute_id"] ?? "", FILTER_VALIDATE_INT);
        $resolutionStatus = trim((string)($_POST["resolution_status"] ?? ""));
        $resolutionNote = trim((string)($_POST["resolution_note"] ?? ""));

        if (!$disputeId || !in_array($resolutionStatus, $allowedDisputeStatuses, true) || $resolutionStatus === "Open") {
            $message = "Invalid dispute resolution request.";
        } else {
            $conn->begin_transaction();
            try {
                $disputeLookup = $conn->prepare("SELECT transaction_id FROM disputes WHERE dispute_id = ? LIMIT 1");
                $disputeLookup->bind_param("i", $disputeId);
                $disputeLookup->execute();
                $disputeRow = $disputeLookup->get_result()->fetch_assoc();

                if (!$disputeRow) {
                    throw new RuntimeException("Dispute not found.");
                }

                $transactionId = (int)$disputeRow["transaction_id"];

                $resolve = $conn->prepare("
                    UPDATE disputes
                    SET status = ?,
                        resolution_note = ?,
                        resolved_by_user_id = ?,
                        resolved_at = NOW()
                    WHERE dispute_id = ?
                ");
                $resolve->bind_param("ssii", $resolutionStatus, $resolutionNote, $current_admin_user_id, $disputeId);
                $resolve->execute();

                $updateTransactionDispute = $conn->prepare("
                    UPDATE transactions
                    SET dispute_status = ?
                    WHERE transaction_id = ?
                ");
                $updateTransactionDispute->bind_param("si", $resolutionStatus, $transactionId);
                $updateTransactionDispute->execute();

                $conn->commit();
                $messageType = "success";
                $message = "Dispute updated successfully.";
            } catch (Throwable $e) {
                $conn->rollback();
                $message = "Failed to resolve dispute: " . $e->getMessage();
            }
        }
    } else {
        $message = "Unsupported transaction action.";
    }
}

$transactions = [];
if ($search !== "") {
    $like = "%" . $search . "%";
    $stmt = $conn->prepare("
        SELECT
            transactions.transaction_id,
            transactions.status,
            transactions.payment_status,
            transactions.payment_reference,
            transactions.delivery_status,
            transactions.delivery_tracking_code,
            transactions.dispute_status,
            transactions.created_at,
            products.product_id,
            products.title,
            products.price,
            buyer.name AS buyer_name,
            buyer.email AS buyer_email,
            seller.name AS seller_name,
            seller.email AS seller_email
        FROM transactions
        INNER JOIN products ON products.product_id = transactions.product_id
        INNER JOIN users AS buyer ON buyer.user_id = transactions.buyer_id
        INNER JOIN users AS seller ON seller.user_id = transactions.seller_id
        WHERE products.title LIKE ?
           OR buyer.name LIKE ?
           OR seller.name LIKE ?
           OR buyer.email LIKE ?
           OR seller.email LIKE ?
        ORDER BY transactions.transaction_id DESC
    ");
    $stmt->bind_param("sssss", $like, $like, $like, $like, $like);
} else {
    $stmt = $conn->prepare("
        SELECT
            transactions.transaction_id,
            transactions.status,
            transactions.payment_status,
            transactions.payment_reference,
            transactions.delivery_status,
            transactions.delivery_tracking_code,
            transactions.dispute_status,
            transactions.created_at,
            products.product_id,
            products.title,
            products.price,
            buyer.name AS buyer_name,
            buyer.email AS buyer_email,
            seller.name AS seller_name,
            seller.email AS seller_email
        FROM transactions
        INNER JOIN products ON products.product_id = transactions.product_id
        INNER JOIN users AS buyer ON buyer.user_id = transactions.buyer_id
        INNER JOIN users AS seller ON seller.user_id = transactions.seller_id
        ORDER BY transactions.transaction_id DESC
    ");
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $transactions[] = $row;
}

$openDisputes = [];
$openDisputeResult = $conn->query("
    SELECT
        disputes.dispute_id,
        disputes.transaction_id,
        disputes.reason,
        disputes.details,
        disputes.status,
        disputes.created_at,
        products.title,
        opener.name AS opened_by_name
    FROM disputes
    INNER JOIN transactions ON transactions.transaction_id = disputes.transaction_id
    INNER JOIN products ON products.product_id = transactions.product_id
    INNER JOIN users AS opener ON opener.user_id = disputes.opened_by_user_id
    WHERE disputes.status = 'Open'
    ORDER BY disputes.created_at DESC
");
while ($row = $openDisputeResult->fetch_assoc()) {
    $openDisputes[] = $row;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Admin Transactions - C2C Platform</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/style.css?v=20260514ui1" rel="stylesheet">
</head>
<body class="app-body">

<?php adminRenderNav("transactions"); ?>

<main class="dashboard-page">
    <section class="dashboard-hero">
        <div>
            <p class="eyebrow">Deal Oversight</p>
            <h1>Deal Desk</h1>
            <p class="hero-text">Update deal status, inspect buyer/seller pairs, or clear bad rows.</p>
        </div>

        <div class="role-card">
            <p>Deal Edit</p>
            <h3><?php echo $canManageTransactions ? "Enabled" : "Read-Only"; ?></h3>
            <span><?php echo htmlspecialchars($current_admin_role); ?> permissions</span>
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
                    <label class="form-label">Search transactions</label>
                    <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" class="form-control" placeholder="Search by product, buyer, or seller">
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
                    <p class="eyebrow">Deals</p>
                    <h2>Deal Table</h2>
                </div>
            </div>

        <div class="table-card">
            <?php if (count($transactions) > 0): ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Product</th>
                                <th>Buyer</th>
                                <th>Seller</th>
                                <th>Price</th>
                                <th>Deal Status</th>
                                <th>Payment</th>
                                <th>Delivery</th>
                                <th>Dispute</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $tx): ?>
                                <tr>
                                    <td><?php echo (int)$tx["transaction_id"]; ?></td>
                                    <td>
                                        <a href="../product.php?id=<?php echo (int)$tx["product_id"]; ?>" class="table-link" target="_blank">
                                            <?php echo htmlspecialchars((string)$tx["title"]); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars((string)$tx["buyer_name"]); ?><br>
                                        <small><?php echo htmlspecialchars((string)$tx["buyer_email"]); ?></small>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars((string)$tx["seller_name"]); ?><br>
                                        <small><?php echo htmlspecialchars((string)$tx["seller_email"]); ?></small>
                                    </td>
                                    <td>R<?php echo number_format((float)$tx["price"], 2); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo getStatusBadgeClass((string)$tx["status"]); ?>">
                                            <?php echo htmlspecialchars(formatStatusLabel((string)$tx["status"])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-default">
                                            <?php echo htmlspecialchars(formatStatusLabel((string)$tx["payment_status"])); ?>
                                        </span>
                                        <br>
                                        <small><?php echo htmlspecialchars((string)($tx["payment_reference"] ?: "No ref")); ?></small>
                                    </td>
                                    <td>
                                        <span class="status-badge status-default">
                                            <?php echo htmlspecialchars(formatStatusLabel((string)$tx["delivery_status"])); ?>
                                        </span>
                                        <?php if (trim((string)$tx["delivery_tracking_code"]) !== ""): ?>
                                            <br><small><?php echo htmlspecialchars((string)$tx["delivery_tracking_code"]); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo strtolower((string)$tx["dispute_status"]) === "open" ? "status-rejected" : "status-default"; ?>">
                                            <?php echo htmlspecialchars(formatStatusLabel((string)$tx["dispute_status"])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)$tx["created_at"]); ?></td>
                                    <td>
                                        <div class="deal-actions">
                                            <a href="../messages.php?t=<?php echo (int)$tx["transaction_id"]; ?>" class="btn-action-link" target="_blank">Chat</a>

                                            <?php if ($canManageTransactions): ?>
                                                <form method="POST" class="inline-form d-flex gap-1">
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="transaction_id" value="<?php echo (int)$tx["transaction_id"]; ?>">
                                                    <select name="status" class="form-control control-w-120">
                                                        <?php foreach ($allowedStatuses as $status): ?>
                                                            <?php $selected = ($status === (string)$tx["status"]) ? "selected" : ""; ?>
                                                            <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($status); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="btn-action-success">Save</button>
                                                </form>

                                                <form method="POST" class="inline-form d-flex gap-1">
                                                    <input type="hidden" name="action" value="update_mock_fields">
                                                    <input type="hidden" name="transaction_id" value="<?php echo (int)$tx["transaction_id"]; ?>">
                                                    <select name="payment_status" class="form-control control-w-130">
                                                        <?php foreach ($allowedPaymentStatuses as $paymentStatus): ?>
                                                            <?php $selectedPayment = ($paymentStatus === (string)$tx["payment_status"]) ? "selected" : ""; ?>
                                                            <option value="<?php echo htmlspecialchars($paymentStatus); ?>" <?php echo $selectedPayment; ?>><?php echo htmlspecialchars($paymentStatus); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <select name="delivery_status" class="form-control control-w-150">
                                                        <?php foreach ($allowedDeliveryStatuses as $deliveryStatus): ?>
                                                            <?php $selectedDelivery = ($deliveryStatus === (string)$tx["delivery_status"]) ? "selected" : ""; ?>
                                                            <option value="<?php echo htmlspecialchars($deliveryStatus); ?>" <?php echo $selectedDelivery; ?>><?php echo htmlspecialchars($deliveryStatus); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="btn-action-success">Update Mock</button>
                                                </form>

                                                <form method="POST" class="inline-form" onsubmit="return confirm('Delete this transaction and chat messages?');">
                                                    <input type="hidden" name="action" value="delete_transaction">
                                                    <input type="hidden" name="transaction_id" value="<?php echo (int)$tx["transaction_id"]; ?>">
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
                    <h3>No deals found.</h3>
                    <p>Use another search keyword.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="section-block">
        <div class="section-header">
            <div>
                <p class="eyebrow">Disputes</p>
                <h2>Open Disputes</h2>
            </div>
        </div>

        <div class="table-card">
            <?php if (count($openDisputes) > 0): ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Dispute #</th>
                                <th>Transaction</th>
                                <th>Opened By</th>
                                <th>Reason</th>
                                <th>Details</th>
                                <th>Created</th>
                                <th>Resolution</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($openDisputes as $dispute): ?>
                                <tr>
                                    <td><?php echo (int)$dispute["dispute_id"]; ?></td>
                                    <td>
                                        #<?php echo (int)$dispute["transaction_id"]; ?><br>
                                        <small><?php echo htmlspecialchars((string)$dispute["title"]); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)$dispute["opened_by_name"]); ?></td>
                                    <td><?php echo htmlspecialchars((string)$dispute["reason"]); ?></td>
                                    <td><?php echo htmlspecialchars((string)($dispute["details"] ?: "No details")); ?></td>
                                    <td><?php echo htmlspecialchars((string)$dispute["created_at"]); ?></td>
                                    <td>
                                        <?php if ($canManageTransactions): ?>
                                            <form method="POST" class="d-flex gap-1">
                                                <input type="hidden" name="action" value="resolve_dispute">
                                                <input type="hidden" name="dispute_id" value="<?php echo (int)$dispute["dispute_id"]; ?>">
                                                <select name="resolution_status" class="form-control control-w-120">
                                                    <option value="Resolved">Resolved</option>
                                                    <option value="Rejected">Rejected</option>
                                                </select>
                                                <input type="text" name="resolution_note" class="form-control" placeholder="Resolution note">
                                                <button type="submit" class="btn-action-success">Apply</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="status-badge status-default">Read-only</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <h3>No open disputes.</h3>
                    <p>When users open disputes, they appear here for moderation.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<script src="../js/mobile-nav.js?v=20260507m"></script>
</body>
</html>
