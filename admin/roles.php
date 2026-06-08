<?php
require_once __DIR__ . "/auth.php";
adminRequirePermission("roles.view");

$canManageRoles = adminHasPermission("roles.manage");
$message = "";
$messageType = "danger";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = trim($_POST["action"] ?? "");

    if (!$canManageRoles) {
        $messageType = "warning";
        $message = "Only Admin users can change role definitions.";
    } elseif ($action === "create_role") {
        $roleName = trim($_POST["role_name"] ?? "");

        if ($roleName === "") {
            $message = "Role name is required.";
        } elseif (strlen($roleName) > 50) {
            $message = "Role name is too long (max 50 characters).";
        } else {
            $check = $conn->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) = LOWER(?) LIMIT 1");
            $check->bind_param("s", $roleName);
            $check->execute();
            $exists = $check->get_result()->fetch_assoc();

            if ($exists) {
                $message = "That role already exists.";
            } else {
                $insert = $conn->prepare("INSERT INTO roles (role_name) VALUES (?)");
                $insert->bind_param("s", $roleName);

                if ($insert->execute()) {
                    $messageType = "success";
                    $message = "Role created successfully.";
                } else {
                    $message = "Role creation failed.";
                }
            }
        }
    } elseif ($action === "rename_role") {
        $roleId = filter_var($_POST["role_id"] ?? "", FILTER_VALIDATE_INT);
        $newName = trim($_POST["new_role_name"] ?? "");

        if (!$roleId || $newName === "") {
            $message = "Invalid role update request.";
        } elseif (strlen($newName) > 50) {
            $message = "Role name is too long (max 50 characters).";
        } else {
            $roleInfoStmt = $conn->prepare("SELECT role_name FROM roles WHERE role_id = ? LIMIT 1");
            $roleInfoStmt->bind_param("i", $roleId);
            $roleInfoStmt->execute();
            $roleInfo = $roleInfoStmt->get_result()->fetch_assoc();

            if (!$roleInfo) {
                $message = "Role not found.";
            } elseif (adminIsCoreRole((string)$roleInfo["role_name"])) {
                $messageType = "warning";
                $message = "Core roles cannot be renamed.";
            } else {
                $duplicateStmt = $conn->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) = LOWER(?) AND role_id != ? LIMIT 1");
                $duplicateStmt->bind_param("si", $newName, $roleId);
                $duplicateStmt->execute();
                $duplicate = $duplicateStmt->get_result()->fetch_assoc();

                if ($duplicate) {
                    $message = "Another role already uses that name.";
                } else {
                    $update = $conn->prepare("UPDATE roles SET role_name = ? WHERE role_id = ?");
                    $update->bind_param("si", $newName, $roleId);

                    if ($update->execute()) {
                        $messageType = "success";
                        $message = "Role updated successfully.";
                    } else {
                        $message = "Role update failed.";
                    }
                }
            }
        }
    } elseif ($action === "delete_role") {
        $roleId = filter_var($_POST["role_id"] ?? "", FILTER_VALIDATE_INT);

        if (!$roleId) {
            $message = "Invalid role selected for deletion.";
        } else {
            $roleInfoStmt = $conn->prepare("SELECT role_name FROM roles WHERE role_id = ? LIMIT 1");
            $roleInfoStmt->bind_param("i", $roleId);
            $roleInfoStmt->execute();
            $roleInfo = $roleInfoStmt->get_result()->fetch_assoc();

            if (!$roleInfo) {
                $message = "Role not found.";
            } elseif (adminIsCoreRole((string)$roleInfo["role_name"])) {
                $messageType = "warning";
                $message = "Core roles cannot be deleted.";
            } else {
                $usageStmt = $conn->prepare("SELECT COUNT(*) AS total FROM user_roles WHERE role_id = ?");
                $usageStmt->bind_param("i", $roleId);
                $usageStmt->execute();
                $usage = (int)$usageStmt->get_result()->fetch_assoc()["total"];

                if ($usage > 0) {
                    $messageType = "warning";
                    $message = "Cannot delete a role that is still assigned to users.";
                } else {
                    $delete = $conn->prepare("DELETE FROM roles WHERE role_id = ?");
                    $delete->bind_param("i", $roleId);

                    if ($delete->execute()) {
                        $messageType = "success";
                        $message = "Role deleted successfully.";
                    } else {
                        $message = "Role deletion failed.";
                    }
                }
            }
        }
    } else {
        $message = "Unsupported role action.";
    }
}

$roles = [];
$rolesStmt = $conn->prepare("
    SELECT
        roles.role_id,
        roles.role_name,
        COUNT(user_roles.user_id) AS user_count
    FROM roles
    LEFT JOIN user_roles ON user_roles.role_id = roles.role_id
    GROUP BY roles.role_id, roles.role_name
    ORDER BY roles.role_name ASC
");
$rolesStmt->execute();
$rolesResult = $rolesStmt->get_result();
while ($row = $rolesResult->fetch_assoc()) {
    $roles[] = $row;
}

$permissionSummary = adminPermissionMatrix();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Admin Roles - C2C Platform</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/style.css?v=20260514ui1" rel="stylesheet">
</head>
<body class="app-body">

<?php adminRenderNav("roles"); ?>

<main class="dashboard-page">
    <section class="dashboard-hero">
        <div>
            <p class="eyebrow">RBAC</p>
            <h1>Role Setup</h1>
            <p class="hero-text">Maintain role names and what admin sections each role can touch.</p>
        </div>

        <div class="role-card">
            <p>Signed In As</p>
            <h3><?php echo htmlspecialchars($current_admin_role); ?></h3>
            <span><?php echo $canManageRoles ? "can edit role table" : "cannot edit roles"; ?></span>
        </div>
    </section>

    <?php if ($message !== ""): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> mt-4">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <?php if ($canManageRoles): ?>
        <section class="section-block">
            <div class="section-header">
                <div>
                    <p class="eyebrow">Create</p>
                    <h2>Add Role</h2>
                </div>
            </div>

            <div class="table-card">
                <form method="POST" class="row g-3">
                    <input type="hidden" name="action" value="create_role">
                    <div class="col-md-10">
                        <label class="form-label">Role Name</label>
                        <input type="text" name="role_name" class="form-control" maxlength="50" placeholder="Example: Support Agent" required>
                    </div>
                    <div class="col-md-2 d-grid">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn-auth">Create Role</button>
                    </div>
                </form>
            </div>
        </section>
    <?php endif; ?>

    <section class="section-block">
        <div class="section-header">
                <div>
                    <p class="eyebrow">Role Catalog</p>
                    <h2>Role List</h2>
                </div>
            </div>

        <div class="table-card">
            <?php if (count($roles) > 0): ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Role Name</th>
                                <th>Users Assigned</th>
                                <?php if ($canManageRoles): ?><th>Actions</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roles as $role): ?>
                                <?php $isCore = adminIsCoreRole((string)$role["role_name"]); ?>
                                <tr>
                                    <td><?php echo (int)$role["role_id"]; ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $isCore ? "status-accepted" : "status-default"; ?>">
                                            <?php echo htmlspecialchars((string)$role["role_name"]); ?>
                                        </span>
                                    </td>
                                    <td><?php echo number_format((int)$role["user_count"]); ?></td>
                                    <?php if ($canManageRoles): ?>
                                        <td>
                                            <div class="deal-actions">
                                                <form method="POST" class="inline-form d-flex gap-1">
                                                    <input type="hidden" name="action" value="rename_role">
                                                    <input type="hidden" name="role_id" value="<?php echo (int)$role["role_id"]; ?>">
                                                    <input
                                                        type="text"
                                                        name="new_role_name"
                                                        class="form-control control-w-170"
                                                        maxlength="50"
                                                        value="<?php echo htmlspecialchars((string)$role["role_name"]); ?>"
                                                        <?php echo $isCore ? "disabled" : ""; ?>
                                                        required
                                                    >
                                                    <button type="submit" class="btn-action-success" <?php echo $isCore ? "disabled" : ""; ?>>Rename</button>
                                                </form>

                                                <form method="POST" class="inline-form" onsubmit="return confirm('Delete this role?');">
                                                    <input type="hidden" name="action" value="delete_role">
                                                    <input type="hidden" name="role_id" value="<?php echo (int)$role["role_id"]; ?>">
                                                    <button
                                                        type="submit"
                                                        class="btn-action-danger"
                                                        <?php echo ($isCore || (int)$role["user_count"] > 0) ? "disabled" : ""; ?>
                                                    >
                                                        Delete
                                                    </button>
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
                    <h3>No roles found.</h3>
                    <p>Create your first role above.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="section-block">
        <div class="section-header">
            <div>
                <p class="eyebrow">Policy</p>
                <h2>Permission Map</h2>
            </div>
        </div>

        <div class="table-card">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Role</th>
                            <th>Permissions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($permissionSummary as $role => $permissions): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($role); ?></td>
                                <td><?php echo htmlspecialchars(implode(", ", $permissions)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</main>

<script src="../js/mobile-nav.js?v=20260507m"></script>
</body>
</html>
