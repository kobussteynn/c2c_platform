<?php
require_once __DIR__ . "/auth.php";
adminRequirePermission("flags.view");

$canManageFlags = adminHasPermission("flags.manage");
$message = "";
$messageType = "danger";
$statusFilter = trim((string)($_GET["status"] ?? ""));
$severityFilter = trim((string)($_GET["severity"] ?? ""));
$search = trim((string)($_GET["q"] ?? ""));

$allowedStatuses = ["Open", "Investigating", "Resolved", "Dismissed"];
$allowedSeverities = ["Low", "Medium", "High", "Critical"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = trim((string)($_POST["action"] ?? ""));

    if (!$canManageFlags) {
        $messageType = "warning";
        $message = "Your role can view flags but cannot update them.";
    } elseif ($action === "update_flag") {
        $flagId = filter_var($_POST["flag_id"] ?? "", FILTER_VALIDATE_INT);
        $newStatus = c2cNormalizeFlagStatus((string)($_POST["status"] ?? ""));
        $notes = trim((string)($_POST["notes"] ?? ""));

        if (!$flagId || !in_array($newStatus, $allowedStatuses, true)) {
            $message = "Invalid flag update request.";
        } else {
            $resolvedAt = in_array($newStatus, ["Resolved", "Dismissed"], true) ? date("Y-m-d H:i:s") : null;
            $resolvedBy = in_array($newStatus, ["Resolved", "Dismissed"], true) ? $current_admin_user_id : null;

            $update = $conn->prepare("
                UPDATE suspicious_flags
                SET status = ?,
                    notes = ?,
                    resolved_by_user_id = ?,
                    resolved_at = ?
                WHERE flag_id = ?
            ");
            $update->bind_param("ssisi", $newStatus, $notes, $resolvedBy, $resolvedAt, $flagId);

            if ($update->execute()) {
                $messageType = "success";
                $message = "Flag updated successfully.";
            } else {
                $message = "Could not update flag.";
            }
        }
    } else {
        $message = "Unsupported flag action.";
    }
}

$whereParts = ["1=1"];
$params = [];
$types = "";

if (in_array($statusFilter, $allowedStatuses, true)) {
    $whereParts[] = "suspicious_flags.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

if (in_array($severityFilter, $allowedSeverities, true)) {
    $whereParts[] = "suspicious_flags.severity = ?";
    $params[] = $severityFilter;
    $types .= "s";
}

if ($search !== "") {
    $whereParts[] = "(suspicious_flags.reason LIKE ? OR suspicious_flags.notes LIKE ? OR products.title LIKE ? OR users.name LIKE ?)";
    $like = "%" . $search . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "ssss";
}

$sql = "
    SELECT
        suspicious_flags.flag_id,
        suspicious_flags.transaction_id,
        suspicious_flags.user_id,
        suspicious_flags.trigger_source,
        suspicious_flags.reason,
        suspicious_flags.severity,
        suspicious_flags.status,
        suspicious_flags.notes,
        suspicious_flags.created_at,
        suspicious_flags.resolved_at,
        products.title AS product_title,
        users.name AS user_name
    FROM suspicious_flags
    LEFT JOIN transactions ON transactions.transaction_id = suspicious_flags.transaction_id
    LEFT JOIN products ON products.product_id = transactions.product_id
    LEFT JOIN users ON users.user_id = suspicious_flags.user_id
    WHERE " . implode(" AND ", $whereParts) . "
    ORDER BY suspicious_flags.flag_id DESC
";

$stmt = $conn->prepare($sql);
if ($types !== "") {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$flags = [];
while ($row = $result->fetch_assoc()) {
    $flags[] = $row;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Admin Flags - C2C Platform</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/style.css?v=20260514ui1" rel="stylesheet">
</head>
<body class="app-body">

<?php adminRenderNav("flags"); ?>

<main class="dashboard-page">
    <section class="dashboard-hero">
        <div>
            <p class="eyebrow">Risk Monitoring</p>
            <h1>Suspicious Flags</h1>
            <p class="hero-text">Review high-risk behavior signals raised by mock detection rules.</p>
        </div>

        <div class="role-card">
            <p>Flag Controls</p>
            <h3><?php echo $canManageFlags ? "Enabled" : "Read-Only"; ?></h3>
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
                <div class="col-md-5">
                    <label class="form-label">Search</label>
                    <input type="text" name="q" class="form-control" value="<?php echo htmlspecialchars($search); ?>" placeholder="Reason, note, user, or product">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control">
                        <option value="">All</option>
                        <?php foreach ($allowedStatuses as $status): ?>
                            <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $status === $statusFilter ? "selected" : ""; ?>>
                                <?php echo htmlspecialchars($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Severity</label>
                    <select name="severity" class="form-control">
                        <option value="">All</option>
                        <?php foreach ($allowedSeverities as $severity): ?>
                            <option value="<?php echo htmlspecialchars($severity); ?>" <?php echo $severity === $severityFilter ? "selected" : ""; ?>>
                                <?php echo htmlspecialchars($severity); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-grid">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn-auth">Apply Filters</button>
                </div>
            </form>
        </div>
    </section>

    <section class="section-block">
        <div class="table-card">
            <?php if (count($flags) > 0): ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Severity</th>
                                <th>Status</th>
                                <th>Source</th>
                                <th>Reason</th>
                                <th>User</th>
                                <th>Transaction</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($flags as $flag): ?>
                                <tr>
                                    <td>#<?php echo (int)$flag["flag_id"]; ?></td>
                                    <td>
                                        <span class="status-badge <?php echo strtolower((string)$flag["severity"]) === "critical" ? "status-rejected" : "status-default"; ?>">
                                            <?php echo htmlspecialchars((string)$flag["severity"]); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo strtolower((string)$flag["status"]) === "open" ? "status-pending" : "status-accepted"; ?>">
                                            <?php echo htmlspecialchars((string)$flag["status"]); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)$flag["trigger_source"]); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars((string)$flag["reason"]); ?><br>
                                        <small><?php echo htmlspecialchars((string)($flag["notes"] ?: "No notes")); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)($flag["user_name"] ?: "-")); ?></td>
                                    <td>
                                        <?php if ((int)$flag["transaction_id"] > 0): ?>
                                            #<?php echo (int)$flag["transaction_id"]; ?><br>
                                            <small><?php echo htmlspecialchars((string)($flag["product_title"] ?: "-")); ?></small>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)$flag["created_at"]); ?></td>
                                    <td>
                                        <?php if ($canManageFlags): ?>
                                            <form method="POST" class="d-flex gap-1">
                                                <input type="hidden" name="action" value="update_flag">
                                                <input type="hidden" name="flag_id" value="<?php echo (int)$flag["flag_id"]; ?>">
                                                <select name="status" class="form-control control-w-130">
                                                    <?php foreach ($allowedStatuses as $status): ?>
                                                        <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $status === (string)$flag["status"] ? "selected" : ""; ?>>
                                                            <?php echo htmlspecialchars($status); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <input type="text" name="notes" class="form-control" value="<?php echo htmlspecialchars((string)$flag["notes"]); ?>" placeholder="Admin notes">
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
                    <h3>No flags found.</h3>
                    <p>Suspicious activity flags will appear here once triggered.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<script src="../js/mobile-nav.js?v=20260507m"></script>
</body>
</html>
