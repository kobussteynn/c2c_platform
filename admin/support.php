<?php
require_once __DIR__ . "/auth.php";
adminRequirePermission("support.view");

$canManageSupport = adminHasPermission("support.manage");
$message = "";
$messageType = "danger";
$statusFilter = trim((string)($_GET["status"] ?? ""));

$allowedStatuses = ["Open", "In Progress", "Resolved", "Closed"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = trim((string)($_POST["action"] ?? ""));

    if (!$canManageSupport) {
        $messageType = "warning";
        $message = "Your role can view support tickets but cannot update them.";
    } elseif ($action === "update_ticket") {
        $ticketId = filter_var($_POST["ticket_id"] ?? "", FILTER_VALIDATE_INT);
        $status = trim((string)($_POST["status"] ?? ""));
        $adminNote = trim((string)($_POST["admin_note"] ?? ""));

        if (!$ticketId || !in_array($status, $allowedStatuses, true)) {
            $message = "Invalid support ticket update request.";
        } else {
            $update = $conn->prepare("
                UPDATE support_tickets
                SET status = ?,
                    admin_note = ?
                WHERE ticket_id = ?
            ");
            $update->bind_param("ssi", $status, $adminNote, $ticketId);

            if ($update->execute()) {
                $messageType = "success";
                $message = "Support ticket updated.";
            } else {
                $message = "Could not update support ticket.";
            }
        }
    } else {
        $message = "Unsupported support action.";
    }
}

$whereSql = "";
$params = [];
$types = "";
if (in_array($statusFilter, $allowedStatuses, true)) {
    $whereSql = "WHERE support_tickets.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

$sql = "
    SELECT
        support_tickets.ticket_id,
        support_tickets.category,
        support_tickets.subject,
        support_tickets.details,
        support_tickets.status,
        support_tickets.admin_note,
        support_tickets.created_at,
        support_tickets.updated_at,
        users.name AS user_name,
        users.email AS user_email
    FROM support_tickets
    INNER JOIN users ON users.user_id = support_tickets.user_id
    {$whereSql}
    ORDER BY support_tickets.ticket_id DESC
";

$stmt = $conn->prepare($sql);
if ($types !== "") {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$tickets = [];
while ($row = $result->fetch_assoc()) {
    $tickets[] = $row;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Admin Support - C2C Platform</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/style.css?v=20260514ui1" rel="stylesheet">
</head>
<body class="app-body">

<?php adminRenderNav("support"); ?>

<main class="dashboard-page">
    <section class="dashboard-hero">
        <div>
            <p class="eyebrow">Support Desk</p>
            <h1>Ticket Management</h1>
            <p class="hero-text">Review and manage user support requests from the marketplace.</p>
        </div>

        <div class="role-card">
            <p>Ticket Controls</p>
            <h3><?php echo $canManageSupport ? "Enabled" : "Read-Only"; ?></h3>
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
                <div class="col-md-9">
                    <label class="form-label">Status Filter</label>
                    <select name="status" class="form-control">
                        <option value="">All statuses</option>
                        <?php foreach ($allowedStatuses as $status): ?>
                            <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $statusFilter === $status ? "selected" : ""; ?>>
                                <?php echo htmlspecialchars($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-grid">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn-auth">Apply</button>
                </div>
            </form>
        </div>
    </section>

    <section class="section-block">
        <div class="table-card">
            <?php if (count($tickets) > 0): ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Category</th>
                                <th>Subject</th>
                                <th>Details</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tickets as $ticket): ?>
                                <tr>
                                    <td>#<?php echo (int)$ticket["ticket_id"]; ?></td>
                                    <td>
                                        <?php echo htmlspecialchars((string)$ticket["user_name"]); ?><br>
                                        <small><?php echo htmlspecialchars((string)$ticket["user_email"]); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)$ticket["category"]); ?></td>
                                    <td><?php echo htmlspecialchars((string)$ticket["subject"]); ?></td>
                                    <td><?php echo htmlspecialchars((string)$ticket["details"]); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo strtolower((string)$ticket["status"]) === "resolved" ? "status-accepted" : "status-default"; ?>">
                                            <?php echo htmlspecialchars((string)$ticket["status"]); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)$ticket["created_at"]); ?></td>
                                    <td>
                                        <?php if ($canManageSupport): ?>
                                            <form method="POST" class="d-flex gap-1">
                                                <input type="hidden" name="action" value="update_ticket">
                                                <input type="hidden" name="ticket_id" value="<?php echo (int)$ticket["ticket_id"]; ?>">
                                                <select name="status" class="form-control control-w-120">
                                                    <?php foreach ($allowedStatuses as $status): ?>
                                                        <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $status === (string)$ticket["status"] ? "selected" : ""; ?>>
                                                            <?php echo htmlspecialchars($status); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <input type="text" name="admin_note" class="form-control" value="<?php echo htmlspecialchars((string)($ticket["admin_note"] ?? "")); ?>" placeholder="Admin note">
                                                <button type="submit" class="btn-action-success">Save</button>
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
                    <h3>No support tickets found.</h3>
                    <p>User support requests will appear here.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<script src="../js/mobile-nav.js?v=20260507m"></script>
</body>
</html>
