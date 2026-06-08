<?php
include("includes/db.php");

$message = "";
$message_type = "";
$reset_link = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"]);

    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $token = bin2hex(random_bytes(32));
        $expires = date("Y-m-d H:i:s", strtotime("+1 hour"));

        $update = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE email = ?");
        $update->bind_param("sss", $token, $expires, $email);
        $update->execute();

        $reset_link = "http://localhost/c2c_platform/reset_password.php?token=$token";
        $message = "We found your account. Use the reset link below to create a new password.";
        $message_type = "success";
    } else {
        $message = "We could not find an account with that email address.";
        $message_type = "danger";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Forgot Password - C2C Platform</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css?v=20260514ui1" rel="stylesheet">
</head>
<body>

<div class="auth-page">
    <div class="auth-card">
        <div class="auth-icon">Account Recovery</div>
        <p class="auth-eyebrow">C2C Platform</p>

        <h1 class="auth-title">Forgot your password?</h1>
        <p class="auth-subtitle">
            No worries. Enter your email address and we will help you reset your password.
        </p>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($reset_link): ?>
            <div class="reset-box">
                <p class="reset-label">Local testing reset link:</p>
                <a href="<?php echo htmlspecialchars($reset_link); ?>" class="reset-link">
                    Reset my password
                </a>
                <small>This link expires in 1 hour.</small>
            </div>
        <?php endif; ?>

        <form method="POST" action="forgot_password.php">
            <div class="mb-4">
                <label class="form-label">Email Address</label>
                <input
                    type="email"
                    name="email"
                    class="form-control"
                    placeholder="Enter your registered email"
                    autocomplete="email"
                    required
                >
            </div>

            <button type="submit" class="btn-auth">Continue</button>
        </form>

        <div class="auth-footer">
            Remembered your password?
            <a href="login.php">Back to login</a>
        </div>
    </div>
</div>

</body>
</html>







