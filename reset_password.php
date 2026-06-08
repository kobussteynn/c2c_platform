<?php
include("includes/db.php");

$message = "";
$token = $_GET["token"] ?? "";

if (!$token) {
    die("Invalid token.");
}

$stmt = $conn->prepare("SELECT user_id, reset_expires FROM users WHERE reset_token = ?");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    die("Invalid or expired token.");
}

$user = $result->fetch_assoc();

if (strtotime($user["reset_expires"]) < time()) {
    die("Token expired.");
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $password = $_POST["password"];
    $confirm = $_POST["confirm_password"];

    if ($password !== $confirm) {
        $message = "Passwords do not match.";
    } else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);

        $update = $conn->prepare("
            UPDATE users 
            SET password = ?, reset_token = NULL, reset_expires = NULL 
            WHERE reset_token = ?
        ");
        $update->bind_param("ss", $hashed, $token);
        $update->execute();

        header("Location: login.php?reset=success");
        exit();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reset Password - C2C Platform</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="css/style.css?v=20260522ui2" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<div class="auth-page">
    <div class="auth-card">
        <p class="auth-eyebrow">C2C Platform</p>
        <h1 class="auth-title">Reset Password</h1>
        <p class="auth-subtitle">Set a new password for your account.</p>

        <?php if ($message): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-3 password-field">
                <label class="form-label">New Password</label>
                <div class="password-input-wrap">
                    <input
                        type="password"
                        name="password"
                        id="resetPassword"
                        class="form-control"
                        autocomplete="new-password"
                        required
                    >
                    <button
                        type="button"
                        id="resetPasswordToggle"
                        class="password-toggle-btn"
                        onclick="togglePassword('resetPassword', 'resetPasswordToggle')"
                        data-target="resetPassword"
                        aria-label="Show password"
                        aria-pressed="false"
                        title="Show password"
                    >
                        <span class="password-toggle-icon" aria-hidden="true"></span>
                    </button>
                </div>
            </div>

            <div class="mb-4 password-field">
                <label class="form-label">Confirm Password</label>
                <div class="password-input-wrap">
                    <input
                        type="password"
                        name="confirm_password"
                        id="resetConfirmPassword"
                        class="form-control"
                        autocomplete="new-password"
                        required
                    >
                    <button
                        type="button"
                        id="resetConfirmPasswordToggle"
                        class="password-toggle-btn"
                        onclick="togglePassword('resetConfirmPassword', 'resetConfirmPasswordToggle')"
                        data-target="resetConfirmPassword"
                        aria-label="Show password"
                        aria-pressed="false"
                        title="Show password"
                    >
                        <span class="password-toggle-icon" aria-hidden="true"></span>
                    </button>
                </div>
            </div>

            <button class="btn-auth">Reset Password</button>
        </form>

        <div class="auth-footer">
            Remembered your password?
            <a href="login.php">Back to login</a>
        </div>
    </div>
</div>

<script src="js/script.js?v=20260522pw1"></script>
</body>
</html>








