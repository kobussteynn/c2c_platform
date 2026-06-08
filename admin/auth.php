<?php
session_start();

require_once __DIR__ . "/../includes/db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit();
}

if (!function_exists("adminResolvePrimaryRole")) {
    function adminResolvePrimaryRole(mysqli $conn, int $userId): string {
        $stmt = $conn->prepare("
            SELECT roles.role_name
            FROM user_roles
            INNER JOIN roles ON roles.role_id = user_roles.role_id
            WHERE user_roles.user_id = ?
            ORDER BY FIELD(roles.role_name, 'Admin', 'Moderator', 'Seller', 'Buyer'), roles.role_id
            LIMIT 1
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        return $row["role_name"] ?? "Buyer";
    }
}

if (!function_exists("adminPermissionMatrix")) {
    function adminPermissionMatrix(): array {
        return [
            "Admin" => [
                "dashboard.view",
                "users.view",
                "users.manage",
                "roles.view",
                "roles.manage",
                "listings.view",
                "listings.manage",
                "transactions.view",
                "transactions.manage",
                "flags.view",
                "flags.manage",
                "kpi.view",
                "support.view",
                "support.manage"
            ],
            "Moderator" => [
                "dashboard.view",
                "users.view",
                "roles.view",
                "listings.view",
                "listings.manage",
                "transactions.view",
                "transactions.manage",
                "flags.view",
                "flags.manage",
                "kpi.view",
                "support.view"
            ]
        ];
    }
}

if (!function_exists("adminHasPermission")) {
    function adminHasPermission(string $permission): bool {
        $role = $_SESSION["role"] ?? "Buyer";
        $matrix = adminPermissionMatrix();

        if (!isset($matrix[$role])) {
            return false;
        }

        return in_array($permission, $matrix[$role], true);
    }
}

if (!function_exists("adminRequirePermission")) {
    function adminRequirePermission(string $permission): void {
        if (!adminHasPermission($permission)) {
            header("Location: dashboard.php?forbidden=" . urlencode($permission));
            exit();
        }
    }
}

if (!function_exists("adminIsCoreRole")) {
    function adminIsCoreRole(string $roleName): bool {
        return in_array(strtolower(trim($roleName)), ["admin", "moderator", "buyer", "seller"], true);
    }
}

if (!function_exists("adminRenderNav")) {
    function adminRenderNav(string $active = ""): void {
        $activeMap = [
            "dashboard" => $active === "dashboard" ? "btn-dark" : "btn-ghost",
            "users" => $active === "users" ? "btn-dark" : "btn-ghost",
            "roles" => $active === "roles" ? "btn-dark" : "btn-ghost",
            "listings" => $active === "listings" ? "btn-dark" : "btn-ghost",
            "transactions" => $active === "transactions" ? "btn-dark" : "btn-ghost",
            "flags" => $active === "flags" ? "btn-dark" : "btn-ghost",
            "kpi" => $active === "kpi" ? "btn-dark" : "btn-ghost",
            "support" => $active === "support" ? "btn-dark" : "btn-ghost"
        ];
        ?>
        <nav class="app-navbar">
            <div class="nav-brand">C2C Admin</div>

            <button class="nav-toggle" type="button" aria-label="Toggle navigation" aria-expanded="false">
                <span></span>
                <span></span>
                <span></span>
            </button>

            <div class="nav-actions">
                <a href="dashboard.php" class="<?php echo $activeMap["dashboard"]; ?>">Dashboard</a>
                <a href="users.php" class="<?php echo $activeMap["users"]; ?>">Users</a>
                <a href="roles.php" class="<?php echo $activeMap["roles"]; ?>">Roles</a>
                <a href="listings.php" class="<?php echo $activeMap["listings"]; ?>">Listings</a>
                <a href="transactions.php" class="<?php echo $activeMap["transactions"]; ?>">Transactions</a>
                <a href="flags.php" class="<?php echo $activeMap["flags"]; ?>">Flags</a>
                <a href="kpi.php" class="<?php echo $activeMap["kpi"]; ?>">KPI</a>
                <a href="support.php" class="<?php echo $activeMap["support"]; ?>">Support</a>
                <a href="../dashboard.php" class="btn-ghost">Main Site</a>
                <a href="../logout.php" class="btn-light-custom">Logout</a>
            </div>
        </nav>
        <?php
    }
}

$current_admin_user_id = (int)$_SESSION["user_id"];
$current_admin_role = adminResolvePrimaryRole($conn, $current_admin_user_id);
$_SESSION["role"] = $current_admin_role;

if (!in_array($current_admin_role, ["Admin", "Moderator"], true)) {
    header("Location: ../dashboard.php");
    exit();
}
?>
