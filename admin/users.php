<?php
require_once __DIR__ . "/auth.php";
adminRequirePermission("users.view");

$canManageUsers = adminHasPermission("users.manage");
$message = "";
$messageType = "danger";

$roles = [];
$rolesResult = $conn->query("SELECT role_id, role_name FROM roles ORDER BY role_name ASC");
while ($row = $rolesResult->fetch_assoc()) {
    $roles[] = $row;
}

$roleMap = [];
foreach ($roles as $role) {
    $roleMap[(int)$role["role_id"]] = $role["role_name"];
}

$buyerRoleId = 0;
foreach ($roles as $role) {
    if (strtolower((string)$role["role_name"]) === "buyer") {
        $buyerRoleId = (int)$role["role_id"];
        break;
    }
}
if ($buyerRoleId === 0 && count($roles) > 0) {
    $buyerRoleId = (int)$roles[0]["role_id"];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = trim($_POST["action"] ?? "");

    if (!$canManageUsers) {
        $messageType = "warning";
        $message = "Your role can view users but cannot modify them.";
    } elseif ($action === "create_user") {
        $name = trim($_POST["name"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $phone = trim((string)($_POST["phone_number"] ?? ""));
        $preferredLanguage = c2cNormalizeLanguage((string)($_POST["preferred_language"] ?? "en"));
        $password = $_POST["password"] ?? "";
        $roleId = filter_var($_POST["role_id"] ?? "", FILTER_VALIDATE_INT);

        if ($name === "" || $email === "" || $password === "") {
            $message = "Name, email, and password are required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Please enter a valid email address.";
        } else {
            $assignedRoleId = ($roleId && isset($roleMap[(int)$roleId])) ? (int)$roleId : $buyerRoleId;

            $check = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
            $check->bind_param("s", $email);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();

            if ($existing) {
                $message = "A user with that email already exists.";
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);

                $conn->begin_transaction();
                try {
                    $insertUser = $conn->prepare("
                        INSERT INTO users (name, email, phone_number, is_phone_verified, preferred_language, password)
                        VALUES (?, ?, ?, 0, ?, ?)
                    ");
                    $insertUser->bind_param("sssss", $name, $email, $phone, $preferredLanguage, $hashed);
                    $insertUser->execute();
                    $userId = (int)$conn->insert_id;

                    if ($assignedRoleId > 0) {
                        $assignRole = $conn->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                        $assignRole->bind_param("ii", $userId, $assignedRoleId);
                        $assignRole->execute();
                    }

                    $conn->commit();
                    $messageType = "success";
                    $message = "User created successfully.";
                } catch (Throwable $e) {
                    $conn->rollback();
                    $message = "User creation failed: " . $e->getMessage();
                }
            }
        }
    } elseif ($action === "update_role") {
        $userId = filter_var($_POST["user_id"] ?? "", FILTER_VALIDATE_INT);
        $roleId = filter_var($_POST["role_id"] ?? "", FILTER_VALIDATE_INT);

        if (!$userId || !$roleId || !isset($roleMap[(int)$roleId])) {
            $message = "Invalid role update request.";
        } elseif (
            (int)$userId === (int)$current_admin_user_id
            && strtolower($roleMap[(int)$roleId]) !== "admin"
        ) {
            $messageType = "warning";
            $message = "You cannot remove your own Admin role from this screen.";
        } else {
            $conn->begin_transaction();
            try {
                $clear = $conn->prepare("DELETE FROM user_roles WHERE user_id = ?");
                $clear->bind_param("i", $userId);
                $clear->execute();

                $assign = $conn->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                $assign->bind_param("ii", $userId, $roleId);
                $assign->execute();

                $conn->commit();

                if ((int)$userId === (int)$current_admin_user_id) {
                    $_SESSION["role"] = $roleMap[(int)$roleId];
                }

                $messageType = "success";
                $message = "User role updated successfully.";
            } catch (Throwable $e) {
                $conn->rollback();
                $message = "Role update failed: " . $e->getMessage();
            }
        }
    } elseif ($action === "delete_user") {
        $userId = filter_var($_POST["user_id"] ?? "", FILTER_VALIDATE_INT);

        if (!$userId) {
            $message = "Invalid user selected for deletion.";
        } elseif ((int)$userId === (int)$current_admin_user_id) {
            $message = "You cannot delete your own admin account.";
        } else {
            $counts = [
                "products" => 0,
                "transactions" => 0,
                "messages" => 0
            ];

            $productCheck = $conn->prepare("SELECT COUNT(*) AS total FROM products WHERE user_id = ?");
            $productCheck->bind_param("i", $userId);
            $productCheck->execute();
            $counts["products"] = (int)$productCheck->get_result()->fetch_assoc()["total"];

            $txCheck = $conn->prepare("SELECT COUNT(*) AS total FROM transactions WHERE buyer_id = ? OR seller_id = ?");
            $txCheck->bind_param("ii", $userId, $userId);
            $txCheck->execute();
            $counts["transactions"] = (int)$txCheck->get_result()->fetch_assoc()["total"];

            $msgCheck = $conn->prepare("SELECT COUNT(*) AS total FROM messages WHERE sender_id = ? OR receiver_id = ?");
            $msgCheck->bind_param("ii", $userId, $userId);
            $msgCheck->execute();
            $counts["messages"] = (int)$msgCheck->get_result()->fetch_assoc()["total"];

            if ($counts["products"] > 0 || $counts["transactions"] > 0 || $counts["messages"] > 0) {
                $messageType = "warning";
                $message = "Cannot delete user with existing activity. Remove related products/transactions/messages first.";
            } else {
                $conn->begin_transaction();
                try {
                    $deleteRoles = $conn->prepare("DELETE FROM user_roles WHERE user_id = ?");
                    $deleteRoles->bind_param("i", $userId);
                    $deleteRoles->execute();

                    $deleteUser = $conn->prepare("DELETE FROM users WHERE user_id = ?");
                    $deleteUser->bind_param("i", $userId);
                    $deleteUser->execute();

                    $conn->commit();
                    $messageType = "success";
                    $message = "User deleted successfully.";
                } catch (Throwable $e) {
                    $conn->rollback();
                    $message = "User deletion failed: " . $e->getMessage();
                }
            }
        }
    } elseif ($action === "toggle_verification") {
        $userId = filter_var($_POST["user_id"] ?? "", FILTER_VALIDATE_INT);

        if (!$userId) {
            $message = "Invalid verification update request.";
        } else {
            $update = $conn->prepare("
                UPDATE users
                SET is_phone_verified = IF(is_phone_verified = 1, 0, 1)
                WHERE user_id = ?
            ");
            $update->bind_param("i", $userId);

            if ($update->execute()) {
                $messageType = "success";
                $message = "User verification status updated.";
            } else {
                $message = "Could not update verification status.";
            }
        }
    } else {
        $message = "Unsupported users action.";
    }
}

$users = [];
$usersStmt = $conn->prepare("
    SELECT
        users.user_id,
        users.name,
        users.email,
        users.phone_number,
        users.is_phone_verified,
        users.preferred_language,
        users.created_at,
        COALESCE((
            SELECT roles.role_id
            FROM user_roles
            INNER JOIN roles ON roles.role_id = user_roles.role_id
            WHERE user_roles.user_id = users.user_id
            ORDER BY FIELD(roles.role_name, 'Admin', 'Moderator', 'Seller', 'Buyer'), roles.role_id
            LIMIT 1
        ), 0) AS role_id,
        COALESCE((
            SELECT roles.role_name
            FROM user_roles
            INNER JOIN roles ON roles.role_id = user_roles.role_id
            WHERE user_roles.user_id = users.user_id
            ORDER BY FIELD(roles.role_name, 'Admin', 'Moderator', 'Seller', 'Buyer'), roles.role_id
            LIMIT 1
        ), 'Unassigned') AS role_name,
        (SELECT COUNT(*) FROM products WHERE products.user_id = users.user_id) AS product_count,
        (SELECT COUNT(*) FROM transactions WHERE transactions.buyer_id = users.user_id OR transactions.seller_id = users.user_id) AS deal_count
    FROM users
    ORDER BY users.created_at DESC
");
$usersStmt->execute();
$usersResult = $usersStmt->get_result();
while ($row = $usersResult->fetch_assoc()) {
    $users[] = $row;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Admin Users - C2C Platform</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/style.css?v=20260514ui1" rel="stylesheet">
</head>
<body class="app-body">

<?php adminRenderNav("users"); ?>

<main class="dashboard-page">
    <section class="dashboard-hero">
        <div>
            <p class="eyebrow">Users + Roles</p>
            <h1>Account Desk</h1>
            <p class="hero-text">Create accounts, move roles, and tidy users when needed.</p>
        </div>

        <div class="role-card">
            <p>Current Access</p>
            <h3><?php echo htmlspecialchars($current_admin_role); ?></h3>
            <span><?php echo $canManageUsers ? "edit rights on" : "view only"; ?></span>
        </div>
    </section>

    <?php if ($message !== ""): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> mt-4">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <?php if ($canManageUsers): ?>
        <section class="section-block">
            <div class="section-header">
                <div>
                    <p class="eyebrow">Create Account</p>
                    <h2>Add User</h2>
                </div>
            </div>

            <div class="table-card">
                <form method="POST" class="row g-3">
                    <input type="hidden" name="action" value="create_user">

                    <div class="col-md-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone_number" class="form-control" placeholder="+27...">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Language</label>
                        <select name="preferred_language" class="form-control">
                            <option value="en">English</option>
                            <option value="af">Afrikaans</option>
                            <option value="xh">isiXhosa</option>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" minlength="6" required>
                    </div>

                    <div class="col-md-1">
                        <label class="form-label">Role</label>
                        <select name="role_id" class="form-control" required>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo (int)$role["role_id"]; ?>"><?php echo htmlspecialchars($role["role_name"]); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-1 d-grid">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn-auth">Create</button>
                    </div>
                </form>
            </div>
        </section>
    <?php endif; ?>

    <section class="section-block">
        <div class="section-header">
                <div>
                    <p class="eyebrow">Accounts</p>
                    <h2>User List</h2>
                </div>
            </div>

        <div class="table-card">
            <?php if (count($users) > 0): ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Verified</th>
                                <th>Lang</th>
                                <th>Role</th>
                                <th>Listings</th>
                                <th>Deals</th>
                                <th>Created</th>
                                <?php if ($canManageUsers): ?><th>Actions</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo (int)$user["user_id"]; ?></td>
                                    <td><?php echo htmlspecialchars((string)$user["name"]); ?></td>
                                    <td><?php echo htmlspecialchars((string)$user["email"]); ?></td>
                                    <td><?php echo htmlspecialchars((string)($user["phone_number"] ?: "-")); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo (int)$user["is_phone_verified"] === 1 ? "status-accepted" : "status-cancelled"; ?>">
                                            <?php echo (int)$user["is_phone_verified"] === 1 ? "Verified" : "Not Verified"; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars(strtoupper((string)$user["preferred_language"])); ?></td>
                                    <td>
                                        <span class="status-badge status-default"><?php echo htmlspecialchars((string)$user["role_name"]); ?></span>
                                    </td>
                                    <td><?php echo number_format((int)$user["product_count"]); ?></td>
                                    <td><?php echo number_format((int)$user["deal_count"]); ?></td>
                                    <td><?php echo htmlspecialchars((string)$user["created_at"]); ?></td>
                                    <?php if ($canManageUsers): ?>
                                        <td>
                                            <div class="deal-actions">
                                                <form method="POST" class="inline-form d-flex gap-1">
                                                    <input type="hidden" name="action" value="update_role">
                                                    <input type="hidden" name="user_id" value="<?php echo (int)$user["user_id"]; ?>">
                                                    <select name="role_id" class="form-control control-w-140">
                                                        <?php foreach ($roles as $role): ?>
                                                            <?php $selected = ((int)$role["role_id"] === (int)$user["role_id"]) ? "selected" : ""; ?>
                                                            <option value="<?php echo (int)$role["role_id"]; ?>" <?php echo $selected; ?>>
                                                                <?php echo htmlspecialchars($role["role_name"]); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="btn-action-success">Update Role</button>
                                                </form>

                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="action" value="toggle_verification">
                                                    <input type="hidden" name="user_id" value="<?php echo (int)$user["user_id"]; ?>">
                                                    <button type="submit" class="btn-action-link">Toggle Verify</button>
                                                </form>

                                                <form method="POST" class="inline-form" onsubmit="return confirm('Delete this user account?');">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?php echo (int)$user["user_id"]; ?>">
                                                    <button type="submit" class="btn-action-danger">Delete User</button>
                                                </form>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <h3>No users yet.</h3>
                    <p>Create one from the form above.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<script src="../js/mobile-nav.js?v=20260507m"></script>
</body>
</html>
