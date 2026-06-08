<?php
session_start();
include("includes/db.php");

$message = "";

if (isset($_GET["registered"]) && $_GET["registered"] === "success") {
    $message = "Registration successful. Please login.";
}

if (isset($_GET["reset"]) && $_GET["reset"] === "success") {
    $message = "Password reset successful. Please login.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"]);
    $password = $_POST["password"];

    $stmt = $conn->prepare("
        SELECT users.user_id, users.name, users.email, users.password, users.preferred_language, roles.role_name
        FROM users
        LEFT JOIN user_roles ON users.user_id = user_roles.user_id
        LEFT JOIN roles ON user_roles.role_id = roles.role_id
        WHERE users.email = ?
        LIMIT 1
    ");

    $stmt->bind_param("s", $email);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user["password"])) {
            $role = $user["role_name"] ?? "Buyer";

            $_SESSION["user_id"] = $user["user_id"];
            $_SESSION["name"] = $user["name"];
            $_SESSION["email"] = $user["email"];
            $_SESSION["role"] = $role;
            $_SESSION["lang"] = c2cNormalizeLanguage((string)($user["preferred_language"] ?? "en"));

            header("Location: dashboard.php");
            exit();
        } else {
            $message = "Invalid email or password.";
        }
    } else {
        $message = "Invalid email or password.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Login - C2C Platform</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css?v=20260522ui2" rel="stylesheet">
</head>
<body>

<div class="auth-page">
    <div class="auth-card">
        <p class="auth-eyebrow">C2C Platform</p>
        <h1 class="auth-title">Welcome Back</h1>
        <p class="auth-subtitle">Log in to continue buying and selling.</p>

        <?php if (!empty($message)): ?>
            <div class="alert <?php echo (isset($_GET["registered"]) || isset($_GET["reset"])) ? 'alert-success' : 'alert-danger'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="mb-3">
                <label class="form-label">Email Address</label>
                <input 
                    type="email" 
                    name="email" 
                    class="form-control" 
                    placeholder="you@example.com" 
                    autocomplete="email"
                    required
                >
            </div>

            <div class="mb-2 password-field">
                <label class="form-label">Password</label>
                <div class="password-input-wrap">
                    <input 
                        type="password" 
                        name="password" 
                        id="loginPassword"
                        class="form-control" 
                        placeholder="Enter your password" 
                        autocomplete="current-password"
                        required
                    >

                    <button 
                        type="button"
                        id="loginPasswordToggle"
                        class="password-toggle-btn"
                        onclick="togglePassword('loginPassword', 'loginPasswordToggle')"
                        data-target="loginPassword"
                        aria-label="Show password"
                        aria-pressed="false"
                        title="Show password"
                    >
                        <span class="password-toggle-icon" aria-hidden="true"></span>
                    </button>
                </div>
            </div>

            <div class="text-end mb-4">
                <a href="forgot_password.php" class="auth-small-link">Forgot password?</a>
            </div>

            <button type="submit" class="btn-auth">Login</button>
        </form>

        <div class="auth-footer">
            Don't have an account?
            <a href="register.php">Sign up</a>
        </div>
    </div>
</div>

<script src="js/script.js?v=20260522pw1"></script>
</body>
</html>









